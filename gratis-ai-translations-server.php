<?php
/**
 * Plugin Name: Gratis AI Translations Server
 * Plugin URI: https://translate.ultimatemultisite.com
 * Description: Server-side plugin for serving AI-generated plugin translations via REST API.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Requires Plugins: glotpress
 * Author: Ultimate Multisite
 * Author URI: https://ultimatemultisite.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gratis-ai-translations-server
 * Domain Path: /languages
 * Network: true
 *
 * @package GratisAITranslationsServer
 */

declare(strict_types=1);

namespace GratisAITranslationsServer;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants.
define('GRATIS_AI_TS_VERSION', '1.0.0');
define('GRATIS_AI_TS_FILE', __FILE__);
define('GRATIS_AI_TS_DIR', plugin_dir_path(__FILE__));
define('GRATIS_AI_TS_URL', plugin_dir_url(__FILE__));
define('GRATIS_AI_TS_BASENAME', plugin_basename(__FILE__));

// Storage paths.
define('GRATIS_AI_TS_STORAGE_DIR', WP_CONTENT_DIR . '/gratis-ai-translations');
define('GRATIS_AI_TS_STORAGE_URL', content_url('gratis-ai-translations'));

// Autoloader.
spl_autoload_register(function ($class) {
    $prefix = __NAMESPACE__ . '\\';
    $base_dir = GRATIS_AI_TS_DIR . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . str_replace('\\', '/', strtolower(str_replace('_', '-', $relative_class))) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Initialize the plugin.
 *
 * @return void
 */
function init(): void
{
    // Check PHP version.
    if (version_compare(PHP_VERSION, '8.2', '<')) {
        add_action('admin_notices', function () {
            ?>
            <div class="notice notice-error">
                <p><?php
                    printf(
                        esc_html__('Gratis AI Translations Server requires PHP 8.2 or higher. You are running PHP %s.', 'gratis-ai-translations-server'),
                        esc_html(PHP_VERSION)
                    );
                ?></p>
            </div>
            <?php
        });
        return;
    }

    // Check for GlotPress.
    if (!class_exists('GP') || !defined('GP_VERSION')) {
        add_action('admin_notices', function () {
            ?>
            <div class="notice notice-error">
                <p><?php esc_html_e('Gratis AI Translations Server requires GlotPress to be installed and activated.', 'gratis-ai-translations-server'); ?></p>
            </div>
            <?php
        });
        return;
    }

    // Initialize storage directory.
    init_storage();

    // Load components.
    REST_API::instance()->init();
    Translation_Queue::instance()->init();
    Translation_Generator::instance()->init();
    Package_Builder::instance()->init();
    Admin_Dashboard::instance()->init();

    // WP-CLI commands.
    if (defined('WP_CLI') && WP_CLI) {
        require_once GRATIS_AI_TS_DIR . 'src/class-cli.php';
        \WP_CLI::add_command('gratis-ai-server', CLI::class);
    }
}

add_action('plugins_loaded', __NAMESPACE__ . '\\init', 20);

/**
 * Initialize storage directory structure.
 *
 * @return void
 */
function init_storage(): void
{
    $dirs = [
        GRATIS_AI_TS_STORAGE_DIR,
        GRATIS_AI_TS_STORAGE_DIR . '/temp',
        GRATIS_AI_TS_STORAGE_DIR . '/packages',
        GRATIS_AI_TS_STORAGE_DIR . '/logs',
    ];

    foreach ($dirs as $dir) {
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);

            // Protect with .htaccess.
            $htaccess = $dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(po|mo|zip)$\">\n    Allow from all\n</FilesMatch>\n");
            }
        }
    }
}

/**
 * Activation hook.
 *
 * @return void
 */
function activate(): void
{
    init_storage();

    // Create database tables.
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->base_prefix . 'gratis_ai_translation_jobs';

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        textdomain varchar(100) NOT NULL,
        version varchar(20) NOT NULL,
        locale varchar(10) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        priority int(2) NOT NULL DEFAULT 5,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at datetime DEFAULT NULL,
        completed_at datetime DEFAULT NULL,
        package_url varchar(500) DEFAULT NULL,
        string_count int(10) DEFAULT 0,
        translated_count int(10) DEFAULT 0,
        error_message text DEFAULT NULL,
        UNIQUE KEY unique_job (textdomain, version, locale),
        KEY status_priority (status, priority, created_at),
        PRIMARY KEY (id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Schedule cleanup cron.
    if (!wp_next_scheduled('gratis_ai_ts_cleanup_old_jobs')) {
        wp_schedule_event(time(), 'daily', 'gratis_ai_ts_cleanup_old_jobs');
    }

    // Set default options.
    add_site_option('gratis_ai_ts_max_concurrent_jobs', 3);
    add_site_option('gratis_ai_ts_rate_limit_per_hour', 100);
    add_site_option('gratis_ai_ts_batch_size', 50);
}

register_activation_hook(GRATIS_AI_TS_FILE, __NAMESPACE__ . '\\activate');

/**
 * Deactivation hook.
 *
 * @return void
 */
function deactivate(): void
{
    wp_clear_scheduled_hook('gratis_ai_ts_cleanup_old_jobs');
    wp_clear_scheduled_hook('gratis_ai_ts_process_queue');
}

register_deactivation_hook(GRATIS_AI_TS_FILE, __NAMESPACE__ . '\\deactivate');
