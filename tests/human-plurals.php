<?php
/** Focused GlotPress policy regression tests; no WordPress/database/provider required. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/class-human-translations.php';
require_once dirname(__DIR__) . '/src/class-translation-generator.php';

use GratisAITranslationsServer\Human_Translations as Policy;

final class GP_Locales {
    public static function by_slug( $slug ) {
        return new class( $slug ) {
            public $slug;
            public $nplurals;
            public function __construct( $slug ) { $this->slug = $slug; $this->nplurals = 'ar' === $slug ? 6 : 3; }
            public function numbers_for_index( $i ) { return [ [ 1, 21, 31 ], [ 2, 3, 4 ], [ 0, 5, 6 ] ][ $i ] ?? [ 7 ]; }
        };
    }
}
class GP_Translation {
    public static $number_of_plural_translations = 6;
}
#[AllowDynamicProperties]
final class Row {
    public function __construct( $data ) { foreach ( $data as $key => $value ) { $this->$key = $value; } }
    public function save( $data ) { foreach ( $data as $key => $value ) { $this->$key = $value; } return true; }
    public function delete() { unset( GP::$translation->rows[ $this->id ] ); return true; }
}
final class Store {
    public $rows = [];
    public $fail_create = false;
    public function find_many( $where ) {
        return array_values( array_filter( $this->rows, static function ( $row ) use ( $where ) {
            foreach ( $where as $key => $value ) { if ( ( $row->$key ?? null ) != $value ) { return false; } }
            return true;
        } ) );
    }
    public function find_one( $where ) { return $this->find_many( $where )[0] ?? null; }
    public function create( $data ) {
        if ( $this->fail_create ) { return false; }
        $id = count( $this->rows ) + 1;
        return $this->rows[ $id ] = new Row( $data + [ 'id' => $id, 'user_id' => null ] );
    }
}
final class GP { public static $translation; }
$meta = [];
function gp_get_meta( $type, $id, $key ) { return $GLOBALS['meta'][ $id ][ $key ] ?? null; }
function gp_update_meta( $id, $key, $value, $type ) { if ( ! empty( $GLOBALS['fail_meta'] ) ) { return false; } $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
function gp_clean_translation_set_cache( $id ) {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
final class WP_Error { public function __construct( ...$args ) {} }
function check( $condition, $label ) { if ( ! $condition ) { throw new RuntimeException( $label ); } print "PASS $label\n"; }

GP::$translation = new Store();
$original = (object) [ 'id' => 7, 'singular' => '%s day', 'plural' => '%s days' ];
$set = (object) [ 'id' => 3, 'locale' => 'bs' ];
$partial = (object) [ 'translations' => [ '%s ljudski dan', '', '%s ljudskih dana' ] ];
check( Policy::import_entry( $original, $set, $partial ), 'partial import succeeds' );
$waiting = GP::$translation->find_one( [ 'status' => 'waiting' ] );
check( $waiting && ! GP::$translation->find_one( [ 'status' => 'current' ] ), 'partial is a suggestion, never current' );
Policy::import_entry( $original, $set, $partial );
check( 1 === count( GP::$translation->rows ), 'repeated partial sync is idempotent' );
check( [ 'translation_0' => '%s ljudski dan', 'translation_2' => '%s ljudskih dana' ] === Policy::human_forms( $original, $set ), 'all nonempty human slots retained' );

$generator = ( new ReflectionClass( GratisAITranslationsServer\Translation_Generator::class ) )->newInstanceWithoutConstructor();
$save = new ReflectionMethod( $generator, 'save_translations' );
check( $save->invoke( $generator, $set, [ $original ], [ [ '%s AI0', '%s AI1', '%s AI2' ] ], true, true ), 'job fills missing forms despite waiting row' );
$current = GP::$translation->find_one( [ 'status' => 'current' ] );
check( '%s ljudski dan' === $current->translation_0 && '%s AI1' === $current->translation_1 && '%s ljudskih dana' === $current->translation_2, 'only missing human form uses AI output' );
check( [ 1 ] === gp_get_meta( 'translation', $current->id, Policy::AI_FORMS ), 'AI provenance records only filled slot 1' );
Policy::import_entry( $original, $set, (object) [ 'translations' => [ '%s newer partial' ] ], true );
check( '%s AI1' === $current->translation_1 && '%s ljudskih dana' === $current->translation_2, 'partial sync cannot erase repaired forms' );
$human = [ '%s human0', '%s human1', '%s human2' ];
Policy::import_entry( $original, $set, (object) [ 'translations' => $human ], true );
check( '%s human2' === $current->translation_2 && [] === gp_get_meta( 'translation', $current->id, Policy::AI_FORMS ), 'complete human import replaces all three forms and clears AI slots' );
check( $save->invoke( $generator, $set, [ $original ], [ [ '%s overwrite', '%s overwrite', '%s overwrite' ] ], true, true ) && '%s human0' === $current->translation_0, 'complete current human work is never overwritten by AI' );

GP::$translation = new Store();
$meta = [];
$legacy = GP::$translation->create( [ 'original_id' => 7, 'translation_set_id' => 3, 'status' => 'current', 'user_id' => 0, 'translation_0' => '%s legacy' ] );
GP::$translation->fail_create = true;
check( ! $save->invoke( $generator, $set, [ $original ], [ [ '%s AI0', '%s AI1', '%s AI2' ] ], true, true ) && 'current' === $legacy->status, 'failed replacement restores legacy current row' );
GP::$translation->fail_create = false;
check( $save->invoke( $generator, $set, [ $original ], [ [ '%s AI0', '%s AI1', '%s AI2' ] ], true, true ), 'legacy incomplete current row can be filled' );
check( 'old' === $legacy->status && '%s legacy' === GP::$translation->find_one( [ 'status' => 'current' ] )->translation_0, 'legacy human form preserved, previous row retained as history' );

GP::$translation = new Store();
$meta = [];
Policy::import_entry( $original, $set, $partial );
check( ! $save->invoke( $generator, $set, [ $original ], [ [ '%s AI0', '', '%s AI2' ] ], true, true ) && ! GP::$translation->find_one( [ 'status' => 'current' ] ), 'provider missing form cannot publish incomplete replacement' );
check( ! $save->invoke( $generator, $set, [ $original ], [ [ '%s AI0', '%d wrong placeholder', '%s AI2' ] ], true, true ), 'invalid generated placeholder is rejected after merging' );
$fail_meta = true;
check( ! $save->invoke( $generator, $set, [ $original ], [ [ '%s AI0', '%s AI1', '%s AI2' ] ], true, true ) && ! GP::$translation->find_one( [ 'status' => 'current' ] ), 'provenance failure removes replacement and keeps human suggestion' );
$fail_meta = false;

GP::$translation = new Store();
$meta = [];
$arabic = (object) [ 'id' => 5, 'locale' => 'ar' ];
$six = (object) [ 'translations' => [ '0', 'one', 'two', 'few', 'many', 'other' ] ];
Policy::import_entry( $original, $arabic, $six );
$row = GP::$translation->find_one( [ 'status' => 'current' ] );
check( 'other' === $row->translation_5 && '0' === $row->translation_0, 'six-form import and literal zero supported' );
check( ! Policy::complete( [ 'translation_0' => 'one', 'translation_1' => 'two' ], 3 ), 'two forms cannot satisfy a three-form locale' );

// Assert the real gap selector requires complete current plurals, even in preserve mode.
$wpdb = new class {
    public $gp_originals = 'originals'; public $gp_translations = 'translations'; public $sql;
    public function prepare( $sql, ...$args ) { return $sql; }
    public function get_results( $sql ) { $this->sql = $sql; return []; }
};
$query = new ReflectionMethod( $generator, 'get_untranslated_originals' );
$query->invoke( $generator, (object) [ 'id' => 1 ], $set, true );
check( str_contains( $wpdb->sql, "t.status = 'current'" ) && str_contains( $wpdb->sql, 'TRIM(t.translation_2)' ), 'gap selection ignores suggestions and incomplete current plurals' );
print "Human plural regression checks passed.\n";
