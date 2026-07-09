<?php
/**
 * Plugin Name: Gratis AI Translations Server
 * Plugin URI: https://translate.ultimatemultisite.com
 * Description: AI translation job queue for GlotPress. Manages translation requests, translates via Superdav AI Service or gp-openai-translate, and builds packages via Traduttore.
 * Version: 1.3.0
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author: Ultimate Multisite
 * Author URI: https://ultimatemultisite.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gratis-ai-translations-server
 *
 * @package GratisAITranslationsServer
 */

declare(strict_types=1);

namespace GratisAITranslationsServer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GRATIS_AI_TS_VERSION', '1.3.0' );
define( 'GRATIS_AI_TS_SCHEMA_VERSION', '1.3.0' );
define( 'GRATIS_AI_TS_FILE', __FILE__ );
define( 'GRATIS_AI_TS_DIR', plugin_dir_path( __FILE__ ) );

// Autoloader.
spl_autoload_register( function ( $class ) {
    $prefix   = __NAMESPACE__ . '\\';
    $base_dir = GRATIS_AI_TS_DIR . 'src/';
    $len      = strlen( $prefix );

    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $file           = $base_dir . 'class-' . str_replace(
        [ '\\', '_' ],
        [ '/', '-' ],
        strtolower( $relative_class )
    ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
} );

/**
 * Initialize the plugin.
 *
 * @return void
 */
function init(): void {
    if ( ! class_exists( 'GP' ) || ! defined( 'GP_VERSION' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            esc_html_e( 'Gratis AI Translations Server requires GlotPress.', 'gratis-ai-translations-server' );
            echo '</p></div>';
        } );
        return;
    }

    maybe_update_schema();

    REST_API::instance()->init();
    Translation_Queue::instance()->init();
    Translation_Generator::instance()->init();
    Admin_Dashboard::instance()->init();

    // Serve Traduttore packages via the server's own URL so clients can
    // download them without hitting SSRF blocks on private-IP content_url.
    // In multisite, content_url() returns the main site's domain; packages
    // need to be reachable from external client sites.
    add_filter( 'traduttore.content_url', function ( string $url ): string {
        return home_url( '/app/traduttore' );
    } );

    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        require_once GRATIS_AI_TS_DIR . 'src/class-cli.php';
        \WP_CLI::add_command( 'gratis-ai-server', CLI::class );
    }
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\init', 20 );

/**
 * Ensure the jobs table matches the current plugin schema.
 *
 * The table predates several columns used by the current queue code. Running
 * dbDelta behind a schema-version option self-heals production installs after
 * code deploys and avoids relying on activation hooks for upgrades.
 *
 * @return void
 */
function maybe_update_schema(): void {
    $installed = (string) get_site_option( 'gratis_ai_ts_schema_version', '' );

    if ( GRATIS_AI_TS_SCHEMA_VERSION === $installed ) {
        return;
    }

    install_schema();
    update_site_option( 'gratis_ai_ts_schema_version', GRATIS_AI_TS_SCHEMA_VERSION );
}

/**
 * Create or update the translation jobs table.
 *
 * @return void
 */
function install_schema(): void {
    global $wpdb;

    $table   = $wpdb->base_prefix . 'gratis_ai_translation_jobs';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        target_type varchar(20) NOT NULL DEFAULT 'plugin',
        textdomain varchar(100) NOT NULL,
        version varchar(20) NOT NULL,
        locale varchar(10) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'requested',
        priority int(2) NOT NULL DEFAULT 5,
        requested_by varchar(255) DEFAULT NULL,
        source_site varchar(255) DEFAULT NULL,
        plugin_source varchar(20) DEFAULT 'unknown',
        string_count int(10) DEFAULT 0,
        prompt_tokens int(10) DEFAULT 0,
        completion_tokens int(10) DEFAULT 0,
        translated_count int(10) DEFAULT 0,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at datetime DEFAULT NULL,
        completed_at datetime DEFAULT NULL,
        package_url varchar(500) DEFAULT NULL,
        error_message text DEFAULT NULL,
        UNIQUE KEY unique_job (target_type, textdomain, version, locale),
        KEY status_priority (status, priority, created_at),
        PRIMARY KEY (id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    ensure_target_type_unique_key( $table );
}

/**
 * Ensure existing installs use the target-aware unique queue key.
 *
 * dbDelta can add columns but is conservative about changing existing indexes.
 * Existing rows default to target_type=plugin, so replacing the legacy key is
 * safe and allows a plugin and theme with the same slug to queue independently.
 *
 * @param string $table Jobs table name.
 * @return void
 */
function ensure_target_type_unique_key( string $table ): void {
    global $wpdb;

    $index_rows = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'unique_job'", ARRAY_A );
    if ( ! is_array( $index_rows ) ) {
        return;
    }

    usort( $index_rows, static function ( array $a, array $b ): int {
        return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'];
    } );

    $columns = array_map( static function ( array $row ): string {
        return (string) $row['Column_name'];
    }, $index_rows );

    $temporary_index = 'unique_job_target_type';
    $temporary_rows  = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = '{$temporary_index}'", ARRAY_A );

    $expected = [ 'target_type', 'textdomain', 'version', 'locale' ];
    if ( $columns === $expected ) {
        if ( ! empty( $temporary_rows ) ) {
            $wpdb->query( "ALTER TABLE {$table} DROP INDEX {$temporary_index}" );
        }
        return;
    }

    if ( empty( $temporary_rows ) ) {
        $added_temporary = $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY {$temporary_index} (target_type, textdomain, version, locale)" );
        if ( false === $added_temporary ) {
            error_log( 'Gratis AI Translations Server could not add the target-aware temporary unique queue index.' );
            return;
        }
    }

    if ( ! empty( $columns ) ) {
        $dropped_legacy = $wpdb->query( "ALTER TABLE {$table} DROP INDEX unique_job" );
        if ( false === $dropped_legacy ) {
            error_log( 'Gratis AI Translations Server could not drop the legacy unique queue index.' );
            return;
        }
    }

    $added_named = $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY unique_job (target_type, textdomain, version, locale)" );
    if ( false === $added_named ) {
        error_log( 'Gratis AI Translations Server could not add the target-aware named unique queue index.' );
        return;
    }

    $wpdb->query( "ALTER TABLE {$table} DROP INDEX {$temporary_index}" );
}

/**
 * Activation hook — create the jobs table and set defaults.
 *
 * @return void
 */
function activate(): void {
    install_schema();
    update_site_option( 'gratis_ai_ts_schema_version', GRATIS_AI_TS_SCHEMA_VERSION );

    // Recurring Action Scheduler events are registered in Translation_Queue::init()
    // which runs on plugins_loaded — after AS is fully initialized.

    add_site_option( 'gratis_ai_ts_max_concurrent_jobs', 3 );
    add_site_option( 'gratis_ai_ts_batch_size', 50 );
    add_site_option( 'gratis_ai_ts_ai_provider', 'gp_openai_translate' );
    add_site_option( 'gratis_ai_ts_superdav_base_url', '' );
    add_site_option( 'gratis_ai_ts_superdav_model', 'superdav-chat-pro' );
    add_site_option( 'gratis_ai_ts_superdav_temperature', 0.2 );
}

register_activation_hook( GRATIS_AI_TS_FILE, __NAMESPACE__ . '\\activate' );

/**
 * Deactivation hook.
 *
 * @return void
 */
function deactivate(): void {
    as_unschedule_all_actions( 'gratis_ai_ts_cleanup_old_jobs', [], 'gratis_ai_ts' );
    as_unschedule_all_actions( 'gratis_ai_ts_process_queue', [], 'gratis_ai_ts' );
    as_unschedule_all_actions( 'gratis_ai_ts_generate_translation', [], 'gratis_ai_ts' );
}

register_deactivation_hook( GRATIS_AI_TS_FILE, __NAMESPACE__ . '\\deactivate' );
