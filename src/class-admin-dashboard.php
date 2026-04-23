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

        $status_counts = $queue->get_counts_by_status();
        $locale_summaries = $queue->get_summaries_by_locale();

        $stats = [
            'pending'       => $status_counts['pending'],
            'processing'    => $status_counts['processing'],
            'requested'     => $status_counts['requested'],
            'completed'    => $status_counts['completed'],
            'total_jobs'  => array_sum( $status_counts ),
        ];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php $this->handle_action(); ?>

            <div class="welcome-panel">
                <div class="welcome-panel-content">
                    <h2><?php esc_html_e('Welcome to Gratis AI Translations Server', 'gratis-ai-translations-server'); ?></h2>
                    <p class="about-description">
                        <?php esc_html_e('AI-powered translation queue. Requested translations wait for approval before processing.', 'gratis-ai-translations-server'); ?>
                    </p>
                </div>
            </div>

            <div class="metabox-holder">
                <div class="postbox-container" style="width: 100%;">
                    <div class="meta-box-sortables">
                        <div class="postbox">
                            <h2><?php esc_html_e('Queue Status', 'gratis-ai-translations-server'); ?></h2>
                            <div class="inside">
                                <table class="widefat">
                                    <tbody>
                                        <tr>
                                            <td><?php esc_html_e('Awaiting Approval', 'gratis-ai-translations-server'); ?></td>
                                            <td><strong style="color: #d63638;"><?php echo esc_html($stats['requested']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td><?php esc_html_e('Pending Processing', 'gratis-ai-translations-server'); ?></td>
                                            <td><strong><?php echo esc_html($stats['pending']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td><?php esc_html_e('Processing', 'gratis-ai-translations-server'); ?></td>
                                            <td><strong><?php echo esc_html($stats['processing']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td><?php esc_html_e('Completed Today', 'gratis-ai-translations-server'); ?></td>
                                            <td><strong style="color: #00a32a;"><?php echo esc_html($stats['completed']); ?></strong></td>
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
     * Handle admin actions (approve/reject).
     *
     * @since 1.1.0
     * @return void
     */
    private function handle_action(): void {
        if ( ! isset( $_GET['action'], $_GET['_wpnonce'] ) ) {
            return;
        }

        $action = sanitize_text_field( wp_unslash( $_GET['action'] ) );
        $nonce  = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

        if ( ! wp_verify_nonce( $nonce, 'gratis_ai_ts_action' ) ) {
            return;
        }

        $queue = Translation_Queue::instance();
        $count = 0;

        if ( $action === 'approve_all' ) {
            $count = $queue->approve_all();
            echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('Approved %d jobs.', 'gratis-ai-translations-server'), $count) . '</p></div>';
        } elseif ( $action === 'approve_locale' && ! empty( $_GET['locale'] ) ) {
            $locale = sanitize_text_field( wp_unslash( $_GET['locale'] ) );
            $count = $queue->approve_all_by_locale( $locale );
            echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('Approved %d jobs for %s.', 'gratis-ai-translations-server'), $count, esc_html($locale)) . '</p></div>';
        } elseif ( $action === 'reject_locale' && ! empty( $_GET['locale'] ) ) {
            $locale = sanitize_text_field( wp_unslash( $_GET['locale'] ) );
            $count = $queue->reject_all_by_locale( $locale );
            echo '<div class="notice notice-warning"><p>' . sprintf(esc_html__('Rejected %d jobs for %s.', 'gratis-ai-translations-server'), $count, esc_html($locale)) . '</p></div>';
        } elseif ( $action === 'approve_job' && ! empty( $_GET['job_id'] ) ) {
            $job_id = (int) $_GET['job_id'];
            if ( $queue->approve_job( $job_id ) ) {
                echo '<div class="notice notice-success"><p>' . esc_html__('Job approved.', 'gratis-ai-translations-server') . '</p></div>';
            }
        } elseif ( $action === 'retry' && ! empty( $_GET['job_id'] ) ) {
            $job_id = (int) $_GET['job_id'];
            $queue->retry_job( $job_id );
            echo '<div class="notice notice-success"><p>' . esc_html__('Job queued for retry.', 'gratis-ai-translations-server') . '</p></div>';
        } elseif ( $action === 'delete' && ! empty( $_GET['job_id'] ) ) {
            $job_id = (int) $_GET['job_id'];
            $queue->delete_job( $job_id );
            echo '<div class="notice notice-success"><p>' . esc_html__('Job deleted.', 'gratis-ai-translations-server') . '</p></div>';
        }
    }

    /**
     * Render queue page with locale grouping.
     *
     * @since 1.1.0
     * @return void
     */
    public function render_queue(): void {
        $queue = Translation_Queue::instance();

        $this->handle_action();

        $status_filter = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $locale_filter = isset($_GET['locale']) ? sanitize_text_field(wp_unslash($_GET['locale'])) : '';

        // Get jobs based on filter.
        $jobs = $queue->get_jobs($status_filter, 100);
        if ($locale_filter) {
            $jobs = array_filter($jobs, fn($j) => $j['locale'] === $locale_filter);
        }

        $locale_summaries = $queue->get_summaries_by_locale();

        // Use the correct admin URL — network admin or site admin depending
        // on where the page is being viewed.
        $action_base = is_network_admin()
            ? network_admin_url('admin.php?page=gratis-ai-translations-queue')
            : admin_url('admin.php?page=gratis-ai-translations-queue');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Translation Queue', 'gratis-ai-translations-server'); ?></h1>

            <!-- Locale Summary Cards -->
            <h2><?php esc_html_e('Locales', 'gratis-ai-translations-server'); ?></h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-bottom: 24px;">
                <?php foreach ($locale_summaries as $locale => $summary) : ?>
                    <div class="card" style="padding: 16px; border: 1px solid #ddd; border-radius: 4px;">
                        <h3 style="margin: 0 0 12px;"><?php echo esc_html($locale); ?></h3>
                        <p style="margin: 4px 0;">
                            <strong><?php echo esc_html($summary['requested']); ?></strong> <?php esc_html_e('awaiting approval', 'gratis-ai-translations-server'); ?> |
                            <strong><?php echo esc_html($summary['pending']); ?></strong> <?php esc_html_e('pending', 'gratis-ai-translations-server'); ?> |
                            <strong><?php echo esc_html($summary['completed']); ?></strong> <?php esc_html_e('done', 'gratis-ai-translations-server'); ?>
                        </p>
                        <p style="margin: 4px 0; font-size: 12px; color: #666;">
                            <?php
                            $tokens = $summary['prompt_tokens'] + $summary['completion_tokens'];
                            echo esc_html( count($summary['plugins']) . ' ' . __('plugins', 'gratis-ai-translations-server') );
                            if ($tokens > 0) {
                                echo ' | ' . number_format($tokens) . ' tokens';
                            }
                            ?>
                        </p>
                        <div style="margin-top: 12px;">
                            <?php if ($summary['requested'] > 0) : ?>
                                <a href="<?php echo esc_url(wp_nonce_url($action_base . '&action=approve_locale&locale=' . $locale, 'gratis_ai_ts_action')); ?>" class="button button-primary button-small">
                                    <?php printf(esc_html__('Approve All (%d)', 'gratis-ai-translations-server'), $summary['requested']); ?>
                                </a>
                                <a href="<?php echo esc_url(wp_nonce_url($action_base . '&action=reject_locale&locale=' . $locale, 'gratis_ai_ts_action')); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e('Reject all pending requests for this locale?', 'gratis-ai-translations-server'); ?>');">
                                    <?php esc_html_e('Reject', 'gratis-ai-translations-server'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ( ! empty( $locale_summaries ) ) : ?>
                <p>
                    <a href="<?php echo esc_url(wp_nonce_url($action_base . '&action=approve_all', 'gratis_ai_ts_action')); ?>" class="button button-primary">
                        <?php esc_html_e('Approve All Requested', 'gratis-ai-translations-server'); ?>
                    </a>
                </p>
            <?php endif; ?>

            <!-- Job List -->
            <h2 style="margin-top: 24px;"><?php esc_html_e('All Jobs', 'gratis-ai-translations-server'); ?></h2>

            <ul class="subsubsub">
                <li><a href="<?php echo esc_url($action_base); ?>" class="<?php echo $status_filter === '' ? 'current' : ''; ?>"><?php esc_html_e('All', 'gratis-ai-translations-server'); ?></a> |</li>
                <li><a href="<?php echo esc_url($action_base . '&status=requested'); ?>" class="<?php echo $status_filter === 'requested' ? 'current' : ''; ?>"><?php esc_html_e('Requested', 'gratis-ai-translations-server'); ?></a> |</li>
                <li><a href="<?php echo esc_url($action_base . '&status=pending'); ?>" class="<?php echo $status_filter === 'pending' ? 'current' : ''; ?>"><?php esc_html_e('Pending', 'gratis-ai-translations-server'); ?></a> |</li>
                <li><a href="<?php echo esc_url($action_base . '&status=processing'); ?>" class="<?php echo $status_filter === 'processing' ? 'current' : ''; ?>"><?php esc_html_e('Processing', 'gratis-ai-translations-server'); ?></a> |</li>
                <li><a href="<?php echo esc_url($action_base . '&status=completed'); ?>" class="<?php echo $status_filter === 'completed' ? 'current' : ''; ?>"><?php esc_html_e('Completed', 'gratis-ai-translations-server'); ?></a> |</li>
                <li><a href="<?php echo esc_url($action_base . '&status=failed'); ?>" class="<?php echo $status_filter === 'failed' ? 'current' : ''; ?>"><?php esc_html_e('Failed', 'gratis-ai-translations-server'); ?></a></li>
            </ul>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'gratis-ai-translations-server'); ?></th>
                        <th><?php esc_html_e('Plugin', 'gratis-ai-translations-server'); ?></th>
                        <th><?php esc_html_e('Version', 'gratis-ai-translations-server'); ?></th>
                        <th><?php esc_html_e('Locale', 'gratis-ai-translations-server'); ?></th>
                        <th><?php esc_html_e('Status', 'gratis-ai-translations-server'); ?></th>
                        <th><?php esc_html_e('Source', 'gratis-ai-translations-server'); ?></th>
                        <th><?php esc_html_e('Requested', 'gratis-ai-translations-server'); ?></th>
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
                            <td><?php echo esc_html($job['requested_by'] ?? 'api'); ?></td>
                            <td><?php echo esc_html(human_time_diff(strtotime($job['created_at']), time()) . ' ago'); ?></td>
                            <td>
                                <?php if ($job['status'] === 'requested') : ?>
                                    <a href="<?php echo esc_url(wp_nonce_url($action_base . '&action=approve_job&job_id=' . $job['id'], 'gratis_ai_ts_action')); ?>" class="button button-small button-primary">
                                        <?php esc_html_e('Approve', 'gratis-ai-translations-server'); ?>
                                    </a>
                                <?php endif; ?>
                                <?php if ($job['status'] === 'failed') : ?>
                                    <a href="<?php echo esc_url(wp_nonce_url($action_base . '&action=retry&job_id=' . $job['id'], 'gratis_ai_ts_action')); ?>" class="button button-small">
                                        <?php esc_html_e('Retry', 'gratis-ai-translations-server'); ?>
                                    </a>
                                <?php endif; ?>
                                <a href="<?php echo esc_url(wp_nonce_url($action_base . '&action=delete&job_id=' . $job['id'], 'gratis_ai_ts_action')); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e('Are you sure?', 'gratis-ai-translations-server'); ?>');">
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

                <div class="notice notice-info inline">
                    <p>
                        <?php
                        printf(
                            /* translators: %s: link to GP OpenAI Translate settings */
                            esc_html__('AI provider settings (API key, base URL, model) are managed by the %s plugin.', 'gratis-ai-translations-server'),
                            '<a href="' . esc_url(admin_url('admin.php?page=gp-openai-translate')) . '">GP OpenAI Translate</a>'
                        );
                        ?>
                    </p>
                </div>

                <table class="form-table">
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
