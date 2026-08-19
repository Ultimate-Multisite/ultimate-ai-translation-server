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
        // Settings are saved directly with update_site_option() so provider
        // tokens never pass through the Settings API or get printed back.
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
     * Handle POST target actions from the grouped queue.
     *
     * @return void
     */
    private function handle_action(): void {
        if ( ! isset( $_POST["gratis_ai_ts_queue_action"] ) ) {
            return;
        }

        if ( ! current_user_can( is_network_admin() ? "manage_network_options" : "manage_options" ) ) {
            return;
        }

        $action = sanitize_text_field( wp_unslash( $_POST["gratis_ai_ts_queue_action"] ) );
        if ( ! in_array( $action, [ "approve_target", "reject_target", "retry_target" ], true ) ) {
            return;
        }

        $textdomain = isset( $_POST["textdomain"] ) ? sanitize_text_field( wp_unslash( $_POST["textdomain"] ) ) : "";
        if ( "" === $textdomain ) {
            return;
        }

        $nonce = isset( $_POST["gratis_ai_ts_queue_nonce"] ) ? sanitize_text_field( wp_unslash( $_POST["gratis_ai_ts_queue_nonce"] ) ) : "";
        if ( ! wp_verify_nonce( $nonce, "gratis_ai_ts_queue_action" ) ) {
            echo "<div class=\"notice notice-error\"><p>";
            esc_html_e( "Queue action failed the security check.", "gratis-ai-translations-server" );
            echo "</p></div>";
            return;
        }

        $target_type = isset( $_POST["target_type"] ) ? Translation_Queue::normalize_target_type( sanitize_text_field( wp_unslash( $_POST["target_type"] ) ) ) : "plugin";
        $queue = Translation_Queue::instance();

        if ( "approve_target" === $action ) {
            $count = $queue->approve_target( $textdomain, $target_type );
        } elseif ( "reject_target" === $action ) {
            $count = $queue->reject_target( $textdomain, $target_type );
        } else {
            $count = $queue->retry_failed_target( $textdomain, $target_type );
        }

        echo "<div class=\"notice notice-success\"><p>";
        printf(
            esc_html__( 'Updated %1$d jobs for %2$s.', 'gratis-ai-translations-server' ),
            $count,
            esc_html( $textdomain )
        );
        echo "</p></div>";
    }

    /**
     * Render the grouped target queue.
     *
     * @return void
     */
    public function render_queue(): void {
        $queue = Translation_Queue::instance();
        $this->handle_action();

        $status = isset( $_GET["status"] ) ? sanitize_text_field( wp_unslash( $_GET["status"] ) ) : "requested";
        $source = isset( $_GET["source"] ) ? sanitize_text_field( wp_unslash( $_GET["source"] ) ) : "";
        if ( "" !== $source ) {
            $source = Translation_Queue::normalize_plugin_source( $source );
        }
        $search = isset( $_GET["s"] ) ? sanitize_text_field( wp_unslash( $_GET["s"] ) ) : "";
        $page = max( 1, isset( $_GET["paged"] ) ? (int) $_GET["paged"] : 1 );
        $per_page = 20;
        $total = $queue->get_target_summary_count( $status, $source, $search );
        $targets = $queue->get_target_summaries( $status, $source, $search, $per_page, ( $page - 1 ) * $per_page );
        $pages = max( 1, (int) ceil( $total / $per_page ) );
        $action_base = is_network_admin() ? network_admin_url( "admin.php?page=gratis-ai-translations-queue" ) : admin_url( "admin.php?page=gratis-ai-translations-queue" );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( "Translation Queue", "gratis-ai-translations-server" ); ?></h1>
            <form method="get">
                <input type="hidden" name="page" value="gratis-ai-translations-queue">
                <p class="search-box">
                    <label class="screen-reader-text" for="queue-search"><?php esc_html_e( "Search targets", "gratis-ai-translations-server" ); ?></label>
                    <input id="queue-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>">
                    <select name="status">
                        <?php foreach ( [ "requested", "pending", "processing", "retrying", "completed", "failed", "" ] as $option ) : ?>
                            <option value="<?php echo esc_attr( $option ); ?>" <?php selected( $status, $option ); ?>><?php echo esc_html( "" === $option ? __( "All statuses", "gratis-ai-translations-server" ) : ucfirst( $option ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="source">
                        <option value=""><?php esc_html_e( "All sources", "gratis-ai-translations-server" ); ?></option>
                        <?php foreach ( $this->get_source_labels() as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $source, $value ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button"><?php esc_html_e( "Filter", "gratis-ai-translations-server" ); ?></button>
                </p>
            </form>
            <table class="wp-list-table widefat striped">
                <thead><tr><th><?php esc_html_e( "Plugin / target", "gratis-ai-translations-server" ); ?></th><th><?php esc_html_e( "Source", "gratis-ai-translations-server" ); ?></th><th><?php esc_html_e( "Requests", "gratis-ai-translations-server" ); ?></th><th><?php esc_html_e( "Versions", "gratis-ai-translations-server" ); ?></th><th><?php esc_html_e( "Requested locales", "gratis-ai-translations-server" ); ?></th><th><?php esc_html_e( "Locale status", "gratis-ai-translations-server" ); ?></th><th><?php esc_html_e( "Last requested", "gratis-ai-translations-server" ); ?></th><th><?php esc_html_e( "Actions", "gratis-ai-translations-server" ); ?></th></tr></thead>
                <tbody>
                    <?php foreach ( $targets as $target ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $target["textdomain"] ); ?></strong><br><span class="description"><?php echo esc_html( $target["target_type"] ); ?></span></td>
                            <td><?php $this->render_target_source_badges( $target ); ?></td>
                            <td><?php echo esc_html( number_format_i18n( (int) $target["request_count"] ) ); ?></td>
                            <td><?php echo esc_html( $target["versions"] ); ?></td>
                            <td><?php echo esc_html( $target["requested_locales"] ?: __( "None", "gratis-ai-translations-server" ) ); ?></td>
                            <td><?php $this->render_target_status_counts( $target ); ?></td>
                            <td><?php $this->render_last_requested( $target ); ?></td>
                            <td><?php $this->render_target_actions( $target ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ( empty( $targets ) ) : ?>
                        <tr><td colspan="8"><?php esc_html_e( "No targets found.", "gratis-ai-translations-server" ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if ( $pages > 1 ) : ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php echo wp_kses_post( paginate_links( [ "base" => add_query_arg( "paged", "%#%", $action_base ), "format" => "", "current" => $page, "total" => $pages, "add_args" => [ "status" => $status, "source" => $source, "s" => $search ] ] ) ); ?>
                </div></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get human-readable source labels.
     *
     * @return array<string,string> Source labels.
     */
    private function get_source_labels(): array {
        return [
            "wporg"   => __( "WordPress.org", "gratis-ai-translations-server" ),
            "premium" => __( "Premium / external", "gratis-ai-translations-server" ),
            "custom"  => __( "Custom / non-WP.org", "gratis-ai-translations-server" ),
            "unknown" => __( "Unknown / unverified", "gratis-ai-translations-server" ),
        ];
    }

    /**
     * Render the relative time for a target's latest request.
     *
     * @param array<string,mixed> $target Target summary.
     * @return void
     */
    private function render_last_requested( array $target ): void {
        $value     = (string) ( $target["last_requested"] ?? "" );
        $requested = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, wp_timezone() );
        $errors    = \DateTimeImmutable::getLastErrors();

        if (
            false === $requested
            || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) )
            || $requested->format( 'Y-m-d H:i:s' ) !== $value
        ) {
            echo "&mdash;";
            return;
        }

        printf(
            esc_html__( '%s ago', 'gratis-ai-translations-server' ),
            esc_html( human_time_diff( $requested->getTimestamp(), time() ) )
        );
    }

    /**
     * Render colored provenance badges for a target.
     *
     * @param array<string,mixed> $target Target summary.
     * @return void
     */
    private function render_target_source_badges( array $target ): void {
        $colors = [
            "wporg"   => "#2271b1",
            "premium" => "#996800",
            "custom"  => "#6b3fa0",
            "unknown" => "#50575e",
        ];
        $labels = $this->get_source_labels();

        $sources = [];
        foreach ( explode( ",", (string) $target["source_values"] ) as $source ) {
            if ( "" === trim( $source ) ) {
                continue;
            }

            $sources[Translation_Queue::normalize_plugin_source( $source )] = true;
        }

        foreach ( array_keys( $sources ) as $source ) {
            $style = "display:inline-block;margin:0 4px 4px 0;padding:2px 6px;border-radius:3px;background:" . esc_attr( $colors[$source] ) . ";color:#fff;font-size:11px;";
            echo "<span style=\"" . $style . "\">" . esc_html( $labels[$source] ) . "</span>";
        }
    }

    /**
     * Render locale status counts for a target.
     *
     * @param array<string,mixed> $target Target summary.
     * @return void
     */
    private function render_target_status_counts( array $target ): void {
        printf(
            esc_html__( 'Requested %1$d, pending %2$d, processing %3$d, retrying %4$d, completed %5$d, failed %6$d', 'gratis-ai-translations-server' ),
            (int) $target["requested_count"],
            (int) $target["pending_count"],
            (int) $target["processing_count"],
            (int) $target["retrying_count"],
            (int) $target["completed_count"],
            (int) $target["failed_count"]
        );
    }

    /**
     * Render target-level POST actions.
     *
     * @param array<string,mixed> $target Target summary.
     * @return void
     */
    private function render_target_actions( array $target ): void {
        $dismiss_message = __( "Dismiss all requested locales for this target?", "gratis-ai-translations-server" );
        ?>
        <?php if ( (int) $target["requested_count"] > 0 ) : ?>
            <form method="post" style="display:inline">
                <?php $this->render_target_action_fields( "approve_target", $target ); ?>
                <button class="button button-primary button-small"><?php printf( esc_html__( "Approve all requested (%d)", "gratis-ai-translations-server" ), (int) $target["requested_count"] ); ?></button>
            </form>
            <form method="post" style="display:inline">
                <?php $this->render_target_action_fields( "reject_target", $target ); ?>
                <button class="button button-small" onclick="return confirm(<?php echo esc_attr( wp_json_encode( $dismiss_message ) ); ?>);"><?php printf( esc_html__( "Dismiss requested (%d)", "gratis-ai-translations-server" ), (int) $target["requested_count"] ); ?></button>
            </form>
        <?php endif; ?>
        <?php if ( (int) $target["failed_count"] > 0 ) : ?>
            <form method="post" style="display:inline">
                <?php $this->render_target_action_fields( "retry_target", $target ); ?>
                <button class="button button-small"><?php printf( esc_html__( "Retry failed (%d)", "gratis-ai-translations-server" ), (int) $target["failed_count"] ); ?></button>
            </form>
        <?php endif; ?>
        <?php
    }

    /**
     * Render nonce and target fields for a queue action form.
     *
     * @param string              $action Queue action.
     * @param array<string,mixed> $target Target summary.
     * @return void
     */
    private function render_target_action_fields( string $action, array $target ): void {
        wp_nonce_field( "gratis_ai_ts_queue_action", "gratis_ai_ts_queue_nonce" );
        ?>
        <input type="hidden" name="gratis_ai_ts_queue_action" value="<?php echo esc_attr( $action ); ?>">
        <input type="hidden" name="textdomain" value="<?php echo esc_attr( $target["textdomain"] ); ?>">
        <input type="hidden" name="target_type" value="<?php echo esc_attr( $target["target_type"] ); ?>">
        <?php
    }

    /**
     * Render settings page.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_settings(): void {
        $this->handle_settings_save();

        $provider_status = Translation_Generator::instance()->get_provider_status( false );
        $superdav_status = $provider_status['superdav'];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form method="post" action="">
                <?php wp_nonce_field( 'gratis_ai_ts_settings_save', 'gratis_ai_ts_settings_nonce' ); ?>
                <input type="hidden" name="gratis_ai_ts_settings_action" value="save">

                <div class="notice notice-info inline">
                    <p>
                        <?php esc_html_e( 'Provider secrets are stored as site options or read from environment variables and are never printed back to the page.', 'gratis-ai-translations-server' ); ?>
                    </p>
                </div>

                <h2><?php esc_html_e('AI Provider', 'gratis-ai-translations-server'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Active Provider', 'gratis-ai-translations-server'); ?></th>
                        <td>
                            <strong><?php echo esc_html($provider_status['active_provider']); ?></strong>
                            <?php if ( ! empty( $provider_status['fallback_message'] ) ) : ?>
                                <p class="description"><?php echo esc_html($provider_status['fallback_message']); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="gratis_ai_ts_ai_provider"><?php esc_html_e('Preferred Provider', 'gratis-ai-translations-server'); ?></label>
                        </th>
                        <td>
                            <select id="gratis_ai_ts_ai_provider" name="gratis_ai_ts_ai_provider">
                                <?php $preferred_provider = (string) get_site_option( 'gratis_ai_ts_ai_provider', 'gp_openai_translate' ); ?>
                                <option value="superdav" <?php selected( $preferred_provider, 'superdav' ); ?>><?php esc_html_e('Superdav AI Service', 'gratis-ai-translations-server'); ?></option>
                                <option value="gp_openai_translate" <?php selected( $preferred_provider, 'gp_openai_translate' ); ?>><?php esc_html_e('GP OpenAI Translate compatibility', 'gratis-ai-translations-server'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('When Superdav is selected but incomplete, the server falls back to gp-openai-translate if available.', 'gratis-ai-translations-server'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="gratis_ai_ts_superdav_base_url"><?php esc_html_e('Superdav Base URL', 'gratis-ai-translations-server'); ?></label>
                        </th>
                        <td>
                            <input type="url" id="gratis_ai_ts_superdav_base_url" name="gratis_ai_ts_superdav_base_url" value="<?php echo esc_attr(Superdav_AI_Client::get_base_url()); ?>" class="regular-text" placeholder="https://example.local/v1">
                            <p class="description"><?php esc_html_e('OpenAI-compatible base URL ending in /v1. Environment variable GRATIS_AI_TS_SUPERDAV_BASE_URL overrides this value.', 'gratis-ai-translations-server'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="gratis_ai_ts_superdav_model"><?php esc_html_e('Superdav Model', 'gratis-ai-translations-server'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="gratis_ai_ts_superdav_model" name="gratis_ai_ts_superdav_model" value="<?php echo esc_attr(Superdav_AI_Client::get_model()); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="gratis_ai_ts_superdav_temperature"><?php esc_html_e('Superdav Temperature', 'gratis-ai-translations-server'); ?></label>
                        </th>
                        <td>
                            <input type="number" step="0.1" min="0" max="2" id="gratis_ai_ts_superdav_temperature" name="gratis_ai_ts_superdav_temperature" value="<?php echo esc_attr((string) Superdav_AI_Client::get_temperature()); ?>" class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="gratis_ai_ts_superdav_site_token"><?php esc_html_e('Superdav Site Token', 'gratis-ai-translations-server'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="gratis_ai_ts_superdav_site_token" name="gratis_ai_ts_superdav_site_token" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $superdav_status['token_configured'] ? esc_attr__('Token configured', 'gratis-ai-translations-server') : esc_attr__('Paste token to save', 'gratis-ai-translations-server'); ?>">
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: token source. */
                                    esc_html__('Token status: %s. Environment variable GRATIS_AI_TS_SUPERDAV_SITE_TOKEN overrides the stored option.', 'gratis-ai-translations-server'),
                                    esc_html((string) $superdav_status['token_source'])
                                );
                                ?>
                            </p>
                            <?php if ( 'site_option' === $superdav_status['token_source'] ) : ?>
                                <label>
                                    <input type="checkbox" name="gratis_ai_ts_clear_superdav_site_token" value="1">
                                    <?php esc_html_e('Clear stored token', 'gratis-ai-translations-server'); ?>
                                </label>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Queue Controls', 'gratis-ai-translations-server'); ?></h2>

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
     * Save site-wide plugin settings from the admin settings form.
     *
     * @since 1.2.0
     * @return void
     */
    private function handle_settings_save(): void {
        $action = isset( $_POST['gratis_ai_ts_settings_action'] )
            ? sanitize_text_field( wp_unslash( $_POST['gratis_ai_ts_settings_action'] ) )
            : '';

        if ( 'save' !== $action ) {
            return;
        }

        if ( ! current_user_can( is_network_admin() ? 'manage_network_options' : 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['gratis_ai_ts_settings_nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['gratis_ai_ts_settings_nonce'] ) )
            : '';

        if ( ! wp_verify_nonce( $nonce, 'gratis_ai_ts_settings_save' ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Settings could not be saved because the security check failed.', 'gratis-ai-translations-server') . '</p></div>';
            return;
        }

        $provider = isset( $_POST['gratis_ai_ts_ai_provider'] )
            ? sanitize_text_field( wp_unslash( $_POST['gratis_ai_ts_ai_provider'] ) )
            : 'gp_openai_translate';
        if ( ! in_array( $provider, [ 'superdav', 'gp_openai_translate' ], true ) ) {
            $provider = 'gp_openai_translate';
        }

        $base_url = isset( $_POST['gratis_ai_ts_superdav_base_url'] )
            ? esc_url_raw( wp_unslash( $_POST['gratis_ai_ts_superdav_base_url'] ) )
            : '';

        $model = isset( $_POST['gratis_ai_ts_superdav_model'] )
            ? sanitize_text_field( wp_unslash( $_POST['gratis_ai_ts_superdav_model'] ) )
            : 'superdav-chat-pro';
        if ( '' === $model ) {
            $model = 'superdav-chat-pro';
        }

        $temperature = isset( $_POST['gratis_ai_ts_superdav_temperature'] )
            ? (float) wp_unslash( $_POST['gratis_ai_ts_superdav_temperature'] )
            : 0.2;
        $temperature = max( 0.0, min( 2.0, $temperature ) );

        $max_concurrent = isset( $_POST['gratis_ai_ts_max_concurrent_jobs'] )
            ? (int) $_POST['gratis_ai_ts_max_concurrent_jobs']
            : 3;
        $max_concurrent = max( 1, min( 10, $max_concurrent ) );

        $batch_size = isset( $_POST['gratis_ai_ts_batch_size'] )
            ? (int) $_POST['gratis_ai_ts_batch_size']
            : 50;
        $batch_size = max( 10, min( 200, $batch_size ) );

        update_site_option( 'gratis_ai_ts_ai_provider', $provider );
        update_site_option( 'gratis_ai_ts_superdav_base_url', $base_url );
        update_site_option( 'gratis_ai_ts_superdav_model', $model );
        update_site_option( 'gratis_ai_ts_superdav_temperature', $temperature );
        update_site_option( 'gratis_ai_ts_max_concurrent_jobs', $max_concurrent );
        update_site_option( 'gratis_ai_ts_batch_size', $batch_size );

        $clear_token = ! empty( $_POST['gratis_ai_ts_clear_superdav_site_token'] );
        $token       = isset( $_POST['gratis_ai_ts_superdav_site_token'] )
            ? trim( sanitize_text_field( wp_unslash( $_POST['gratis_ai_ts_superdav_site_token'] ) ) )
            : '';

        if ( $clear_token ) {
            update_site_option( 'gratis_ai_ts_superdav_site_token', '' );
        } elseif ( '' !== $token ) {
            update_site_option( 'gratis_ai_ts_superdav_site_token', $token );
        }

        echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'gratis-ai-translations-server') . '</p></div>';
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
