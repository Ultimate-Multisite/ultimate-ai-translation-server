<?php
/**
 * Admin Dashboard class
 *
 * Provides admin interface for managing the translation server.
 *
 * @package GratisAITranslationsServer
 */

declare(strict_types=1);

namespace GratisAITranslationsServer;

/**
 * Admin Dashboard class.
 *
 * @since 1.0.0
 */
class Admin_Dashboard {

    /**
     * Instance of this class.
     *
     * @since 1.0.0
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Get the singleton instance.
     *
     * @since 1.0.0
     * @return self
     */
    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize hooks.
     *
     * @since 1.0.0
     * @return void
     */
    public function init(): void {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('network_admin_menu', [$this, 'add_network_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Add admin menu.
     *
     * @since 1.0.0
     * @return void
     */
    public function add_admin_menu(): void {
        add_menu_page(
            __('AI Translations Server', 'gratis-ai-translations-server'),
            __('AI Translations', 'gratis-ai-translations-server'),
            'manage_options',
            'gratis-ai-translations-server',
            [$this, 'render_dashboard'],
            'dashicons-translation',
            30
        );

        add_submenu_page(
            'gratis-ai-translations-server',
            __('Dashboard', 'gratis-ai-translations-server'),
            __('Dashboard', 'gratis-ai-translations-server'),
            'manage_options',
            'gratis-ai-translations-server',
            [$this, 'render_dashboard']
        );

        add_submenu_page(
            'gratis-ai-translations-server',
            __('Queue', 'gratis-ai-translations-server'),
            __('Queue', 'gratis-ai-translations-server'),
            'manage_options',
            'gratis-ai-translations-queue',
            [$this, 'render_queue']
        );

        add_submenu_page(
            'gratis-ai-translations-server',
            __('Settings', 'gratis-ai-translations-server'),
            __('Settings', 'gratis-ai-translations-server'),
            'manage_options',
            'gratis-ai-translations-settings',
            [$this, 'render_settings']
        );
    }

    /**
     * Add network admin menu.
     *
     * @since 1.0.0
     * @return void
     */
    public function add_network_admin_menu(): void {
        add_menu_page(
            __('AI Translations Server', 'gratis-ai-translations-server'),
            __('AI Translations', 'gratis-ai-translations-server'),
            'manage_network_options',
            'gratis-ai-translations-server',
            [$this, 'render_dashboard'],
            'dashicons-translation',
            30
        );
    }

    /**
     * Register settings.
     *
     * @since 1.0.0
     * @return void
     */
    public function register_settings(): void {
        register_setting('gratis_ai_ts_settings', 'gratis_ai_ts_openai_api_key');
        register_setting('gratis_ai_ts_settings', 'gratis_ai_ts_openai_base_url');
        register_setting('gratis_ai_ts_settings', 'gratis_ai_ts_model');
        register_setting('gratis_ai_ts_settings', 'gratis_ai_ts_max_concurrent_jobs');
        register_setting('gratis_ai_ts_settings', 'gratis_ai_ts_rate_limit_per_hour');
        register_setting('gratis_ai_ts_settings', 'gratis_ai_ts_batch_size');
    }

    /**
     * Render dashboard page.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_dashboard(): void {
        $queue = Translation_Queue::instance();

        $stats = [
            'pending'       => $queue->get_pending_count(),
            'processing'    => $queue->get_processing_count(),
            'completed'     => $queue->get_completed_count_today(),
            'total_jobs'    => $this->get_total_jobs(),
        ];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="welcome-panel">
                <div class="welcome-panel-content">
                    <h2><?php esc_html_e('Welcome to Gratis AI Translations Server', 'gratis-ai-translations-server'); ?></h2>
                    <p class="about-description">
                        <?php esc_html_e('This server provides AI-powered translations for WordPress plugins.', 'gratis-ai-translations-server'); ?>
                    </p>
                </div>
            </div>

            <div class="metabox-holder">
                <div class="postbox-container" style="width: 100%;">
                    <div class="meta-box-sortables">
                        <div class="postbox">
                            <h2><?php esc_html_e('Current Statistics', 'gratis-ai-translations-server'); ?></h2>
                            <div class="inside">
                                <table class="widefat">
                                    <tbody>
                                        <tr>
                                            <td><?php esc_html_e('Pending Jobs', 'gratis-ai-translations-server'); ?></td>
                                            <td><strong><?php echo esc_html($stats['pending']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td><?php esc_html_e('Processing', 'gratis-ai-translations-server'); ?></td>
                                            <td><strong><?php echo esc_html($stats['processing']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td><?php esc_html_e('Completed Today', 'gratis-ai-translations-server'); ?></td>
                                            <td><strong><?php echo esc_html($stats['completed']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td><?php esc_html_e('Total Jobs', 'gratis-ai-translations-server'); ?></td>
                                            <td><strong><?php echo esc_html($stats['total_jobs']); ?></strong></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render queue page.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_queue(): void {
        $queue = Translation_Queue::instance();

        // Handle actions.
        if (isset($_GET['action']) && isset($_GET['job_id'])) {
            $job_id = (int) $_GET['job_id'];
            $action = sanitize_text_field(wp_unslash($_GET['action']));

            if (wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'gratis_ai_ts_action')) {
                if ($action === 'retry') {
                    $queue->retry_job($job_id);
                    echo '<div class="notice notice-success"><p>' . esc_html__('Job queued for retry.', 'gratis-ai-translations-server') . '</p></div>';
                } elseif ($action === 'delete') {
                    $queue->delete_job($job_id);
                    echo '<div class="notice notice-success"><p>' . esc_html__('Job deleted.', 'gratis-ai-translations-server') . '</p></div>';
                }
            }
        }

        $status_filter = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $jobs = $queue->get_jobs($status_filter, 50);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Translation Queue', 'gratis-ai-translations-server'); ?></h1>

            <ul class="subsubsub">
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=gratis-ai-translations-queue')); ?>" class="<?php echo $status_filter === '' ? 'current' : ''; ?>"><?php esc_html_e('All', 'gratis-ai-translations-server'); ?></a> |</li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=gratis-ai-translations-queue&status=pending')); ?>" class="<?php echo $status_filter === 'pending' ? 'current' : ''; ?>"><?php esc_html_e('Pending', 'gratis-ai-translations-server'); ?></a> |</li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=gratis-ai-translations-queue&status=processing')); ?>" class="<?php echo $status_filter === 'processing' ? 'current' : ''; ?>"><?php esc_html_e('Processing', 'gratis-ai-translations-server'); ?></a> |</li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=gratis-ai-translations-queue&status=completed')); ?>" class="<?php echo $status_filter === 'completed' ? 'current' : ''; ?>"><?php esc_html_e('Completed', 'gratis-ai-translations-server'); ?></a> |</li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=gratis-ai-translations-queue&status=failed')); ?>" class="<?php echo $status_filter === 'failed' ? 'current' : ''; ?>"><?php esc_html_e('Failed', 'gratis-ai-translations-server'); ?></a></li>
            </ul>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'gratis-ai-translations-server'); ?></th>
                        <th><?php esc_html_e('Plugin', 'gratis-ai-translations-server'); ?></th>
                        <th><?php esc_html_e('Version', 'gratis-ai-translations-server'); ?></th>
                        <th><?php esc_html_e('Locale', 'gratis-ai-translations-server'); ?></th>
                        <th><?php esc_html_e('Status', 'gratis-ai-translations-server'); ?></th>
                        <th><?php esc_html_e('Priority', 'gratis-ai-translations-server'); ?></th>
                        <th><?php esc_html_e('Created', 'gratis-ai-translations-server'); ?></th>
                        <th><?php esc_html_e('Actions', 'gratis-ai-translations-server'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $job) : ?>
                        <tr>
                            <td><?php echo esc_html($job['id']); ?></td>
                            <td><?php echo esc_html($job['textdomain']); ?></td>
                            <td><?php echo esc_html($job['version']); ?></td>
                            <td><?php echo esc_html($job['locale']); ?></td>
                            <td>
                                <span class="status-<?php echo esc_attr($job['status']); ?>">
                                    <?php echo esc_html(ucfirst($job['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($job['priority']); ?></td>
                            <td><?php echo esc_html(human_time_diff(strtotime($job['created_at']), time()) . ' ago'); ?></td>
                            <td>
                                <?php if ($job['status'] === 'failed') : ?>
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=gratis-ai-translations-queue&action=retry&job_id=' . $job['id']), 'gratis_ai_ts_action')); ?>" class="button button-small">
                                        <?php esc_html_e('Retry', 'gratis-ai-translations-server'); ?>
                                    </a>
                                <?php endif; ?>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=gratis-ai-translations-queue&action=delete&job_id=' . $job['id']), 'gratis_ai_ts_action')); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e('Are you sure?', 'gratis-ai-translations-server'); ?>');">
                                    <?php esc_html_e('Delete', 'gratis-ai-translations-server'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($jobs)) : ?>
                        <tr>
                            <td colspan="8"><?php esc_html_e('No jobs found.', 'gratis-ai-translations-server'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render settings page.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_settings(): void {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields('gratis_ai_ts_settings'); ?>
                <?php do_settings_sections('gratis_ai_ts_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="gratis_ai_ts_openai_api_key"><?php esc_html_e('OpenAI API Key', 'gratis-ai-translations-server'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="gratis_ai_ts_openai_api_key" name="gratis_ai_ts_openai_api_key" value="<?php echo esc_attr(get_site_option('gratis_ai_ts_openai_api_key')); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Your OpenAI API key for translation generation.', 'gratis-ai-translations-server'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="gratis_ai_ts_openai_base_url"><?php esc_html_e('OpenAI Base URL', 'gratis-ai-translations-server'); ?></label>
                        </th>
                        <td>
                            <input type="url" id="gratis_ai_ts_openai_base_url" name="gratis_ai_ts_openai_base_url" value="<?php echo esc_attr(get_site_option('gratis_ai_ts_openai_base_url')); ?>" class="regular-text" placeholder="https://api.openai.com/v1">
                            <p class="description"><?php esc_html_e('Leave empty for default OpenAI API. Set for custom OpenAI-compatible endpoints (e.g., Ollama).', 'gratis-ai-translations-server'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="gratis_ai_ts_model"><?php esc_html_e('AI Model', 'gratis-ai-translations-server'); ?></label>
                        </th>
                        <td>
                            <select id="gratis_ai_ts_model" name="gratis_ai_ts_model">
                                <option value="gpt-4" <?php selected(get_site_option('gratis_ai_ts_model', 'gpt-4'), 'gpt-4'); ?>>GPT-4</option>
                                <option value="gpt-4-turbo" <?php selected(get_site_option('gratis_ai_ts_model'), 'gpt-4-turbo'); ?>>GPT-4 Turbo</option>
                                <option value="gpt-3.5-turbo" <?php selected(get_site_option('gratis_ai_ts_model'), 'gpt-3.5-turbo'); ?>>GPT-3.5 Turbo</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="gratis_ai_ts_max_concurrent_jobs"><?php esc_html_e('Max Concurrent Jobs', 'gratis-ai-translations-server'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="gratis_ai_ts_max_concurrent_jobs" name="gratis_ai_ts_max_concurrent_jobs" value="<?php echo esc_attr(get_site_option('gratis_ai_ts_max_concurrent_jobs', 3)); ?>" min="1" max="10" class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="gratis_ai_ts_batch_size"><?php esc_html_e('Batch Size', 'gratis-ai-translations-server'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="gratis_ai_ts_batch_size" name="gratis_ai_ts_batch_size" value="<?php echo esc_attr(get_site_option('gratis_ai_ts_batch_size', 50)); ?>" min="10" max="200" class="small-text">
                            <p class="description"><?php esc_html_e('Number of strings to translate per API request.', 'gratis-ai-translations-server'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Get total jobs count.
     *
     * @since 1.0.0
     * @return int
     */
    private function get_total_jobs(): int {
        global $wpdb;
        $table_name = $wpdb->base_prefix . 'gratis_ai_translation_jobs';

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    }
}
