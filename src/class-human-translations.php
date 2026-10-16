<?php
/** Human import and per-form AI gap-fill policy. Keep in sync with gp-openai-translate. */
declare(strict_types=1);

namespace GratisAITranslationsServer;

final class Human_Translations {
    public const HUMAN_FORMS = 'gratis_ai_ts_human_forms';
    public const AI_FORMS = 'gratis_ai_ts_ai_forms';

    public static function count( object $original, object $set ): int {
        $locale = \GP_Locales::by_slug( $set->locale );
        return empty( $original->plural ) ? 1 : ( $locale ? (int) $locale->nplurals : 0 );
    }

    /** Non-empty forms only; the string "0" is a valid translation. */
    public static function fields( object $row ): array {
        $fields = [];
        for ( $i = 0; $i < \GP_Translation::$number_of_plural_translations; $i++ ) {
            $field = 'translation_' . $i;
            $value = $row->$field ?? null;
            if ( is_string( $value ) && '' !== trim( $value ) ) {
                $fields[ $field ] = $value;
            }
        }
        return $fields;
    }

    public static function complete( array $fields, int $count ): bool {
        if ( $count < 1 || $count > \GP_Translation::$number_of_plural_translations ) {
            return false;
        }
        for ( $i = 0; $i < $count; $i++ ) {
            if ( ! isset( $fields[ 'translation_' . $i ] ) || '' === trim( $fields[ 'translation_' . $i ] ) ) {
                return false;
            }
        }
        return true;
    }

    /** Import all locale forms; never downgrade a complete current row. */
    public static function import_entry( object $original, object $set, object $entry, bool $replace = false, ?bool &$changed = null ): bool {
        $changed = false;
        $fields = [];
        $count = self::count( $original, $set );
        if ( $count < 1 || $count > \GP_Translation::$number_of_plural_translations ) {
            return false;
        }
        for ( $i = 0; $i < $count; $i++ ) {
            $value = $entry->translations[ $i ] ?? null;
            if ( is_string( $value ) && '' !== trim( $value ) ) {
                $fields[ 'translation_' . $i ] = $value;
            }
        }
        if ( ! $fields ) {
            return false;
        }
        $identity = [ 'original_id' => $original->id, 'translation_set_id' => $set->id ];
        $current = \GP::$translation->find_one( $identity + [ 'status' => 'current' ] );
        $complete = self::complete( $fields, $count );
        if ( $current && $complete ) {
            if ( self::complete( self::fields( $current ), $count ) && ( ! $replace || ( null !== $current->user_id && (int) $current->user_id > 0 ) ) ) {
                return true;
            }
            if ( ! $replace ) {
                $fields = self::fields( $current ) + $fields;
            }
            $changed = self::fields( $current ) !== $fields;
            if ( $changed && ! $current->save( $fields ) ) {
                return false;
            }
            self::record( $current, $fields, [] );
            gp_clean_translation_set_cache( (int) $set->id );
            return true;
        }
        if ( $current && self::fields( $current ) === $fields ) {
            // A legacy incomplete current row must become a suggestion, not block the gap query.
            if ( ! self::complete( $fields, $count ) ) {
                if ( ! $current->save( [ 'status' => 'waiting' ] ) ) {
                    return false;
                }
                $changed = true;
                self::record( $current, $fields, [] );
                gp_clean_translation_set_cache( (int) $set->id );
            }
            return true;
        }
        $status = $complete ? 'current' : 'waiting';
        // Deduplicate repeated partial imports; a full current row is left untouched.
        foreach ( \GP::$translation->find_many( $identity + [ 'status' => $status ] ) as $row ) {
            if ( self::fields( $row ) === $fields ) {
                return true;
            }
        }
        $row = \GP::$translation->create( $identity + $fields + [ 'status' => $status, 'user_id' => 0 ] );
        if ( ! is_object( $row ) ) {
            return false;
        }
        if ( ! self::record( $row, $fields, [] ) ) {
            $row->delete();
            return false;
        }
        gp_clean_translation_set_cache( (int) $set->id );
        $changed = true;
        return true;
    }

    /** Preserve human forms, preferring current, then waiting, then fuzzy suggestions. */
    public static function human_forms( object $original, object $set ): array {
        $forms = [];
        foreach ( [ 'current', 'waiting', 'fuzzy' ] as $status ) {
            $rows = \GP::$translation->find_many( [ 'original_id' => $original->id, 'translation_set_id' => $set->id, 'status' => $status ] );
            usort( $rows, static fn( $a, $b ) => (int) $b->id <=> (int) $a->id );
            foreach ( $rows as $row ) {
                $human = gp_get_meta( 'translation', (int) $row->id, self::HUMAN_FORMS );
                if ( ! is_array( $human ) ) {
                    $human = null !== $row->user_id && 'ai' !== gp_get_meta( 'translation', (int) $row->id, 'gratis_ai_ts_source' )
                        ? self::fields( $row ) : [];
                }
                $forms += $human;
            }
        }
        return $forms;
    }

    /** Persist form-level provenance: human values and the AI-filled slot indexes. */
    public static function record( object $row, array $human, array $ai ): bool {
        return false !== gp_update_meta( $row->id, self::HUMAN_FORMS, $human, 'translation' )
            && false !== gp_update_meta( $row->id, self::AI_FORMS, $ai, 'translation' )
            && false !== gp_update_meta( $row->id, 'gratis_ai_ts_source', $ai ? ( $human ? 'mixed' : 'ai' ) : 'wporg', 'translation' );
    }
}
