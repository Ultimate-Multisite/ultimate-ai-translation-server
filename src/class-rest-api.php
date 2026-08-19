<?php
/**
 * REST API class
 *
 * Endpoints for translation job management. Translation packages are served
 * by Traduttore via its GlotPress API route — this plugin only handles
 * job queuing, status, health, and feedback.
 *
 * @package GratisAITranslationsServer
 */

declare(strict_types=1);

namespace GratisAITranslationsServer;

/**
 * REST API class.
 *
 * @since 1.0.0
 */
class REST_API {

    /**
     * WordPress.org Plugin Information API endpoint.
     *
     * @var string
     */
    private const WPORG_PLUGIN_API_URL = 'https://api.wordpress.org/plugins/info/1.2/';

    /**
     * Site-transient prefix for WordPress.org plugin existence checks.
     *
     * @var string
     */
    private const WPORG_PLUGIN_CACHE_PREFIX = 'gratis_ai_ts_wporg_plugin_';

    /**
     * Database lock prefix serializing uncached lookups for the same slug.
     *
     * @var string
     */
    private const WPORG_PLUGIN_SLUG_LOCK_PREFIX = 'gratis_ai_ts_wporg_slug_';

    /**
     * Database lock protecting the network-wide rate counter.
     *
     * @var string
     */
    private const WPORG_PLUGIN_RATE_LOCK = 'gratis_ai_ts_wporg_plugin_rate';

    /**
     * Network option tracking the uncached lookup rate window.
     *
     * @var string
     */
    private const WPORG_PLUGIN_RATE_OPTION = 'gratis_ai_ts_wporg_plugin_rate';

    /**
     * Maximum uncached WordPress.org lookups per minute across the network.
     *
     * @var int
     */
    private const WPORG_PLUGIN_RATE_LIMIT = 100;

    /**
     * Maximum uncached WordPress.org lookups per network peer each minute.
     *
     * @var int
     */
    private const WPORG_PLUGIN_CALLER_RATE_LIMIT = 25;

    /**
     * Maximum uncached lookups initiated by one REST request.
     *
     * @var int
     */
    private const WPORG_PLUGIN_REQUEST_LIMIT = 25;

    /**
     * Maximum wall-clock time spent on uncached lookups in one REST request.
     *
     * @var float
     */
    private const WPORG_PLUGIN_REQUEST_BUDGET = 10.0;

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * API namespace.
     *
     * @var string
     */
    private string $namespace = 'sd-ai-lang-pack/v1';

    /**
     * Stop additional uncached lookups after an upstream failure in one request.
     *
     * @var bool
     */
    private bool $wporg_api_unavailable = false;

    /**
     * Uncached WordPress.org lookups initiated by the current REST request.
     *
     * @var int
     */
    private int $wporg_uncached_lookups = 0;

    /**
     * Hashed TCP peer identity used for the current request's rate budget.
     *
     * @var string
     */
    private string $wporg_rate_caller = 'unknown';

    /**
     * Wall-clock deadline for the current REST request.
     *
     * @var float
     */
    private float $wporg_lookup_deadline = 0.0;

    /**
     * Get the singleton instance.
     *
     * @return self
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize hooks.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route( $this->namespace, '/health', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_health' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $this->namespace, '/request-translation', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'request_translation' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'textdomain' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'version'    => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'locales'    => [ 'required' => true, 'type' => 'array' ],
                'target_type' => [ 'type' => 'string', 'default' => 'plugin', 'enum' => [ 'plugin', 'theme' ], 'sanitize_callback' => 'sanitize_text_field' ],
                'site_url'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_url' ],
                'wp_version' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'priority'   => [ 'type' => 'integer', 'default' => 5 ],
            ],
        ] );

        register_rest_route( $this->namespace, '/translation-status', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'get_translation_status' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'textdomain' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'version'    => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'locale'     => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'target_type' => [ 'type' => 'string', 'default' => 'plugin', 'enum' => [ 'plugin', 'theme' ], 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/feedback', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'submit_feedback' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'textdomain' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'version'    => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'locale'     => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'target_type' => [ 'type' => 'string', 'default' => 'plugin', 'enum' => [ 'plugin', 'theme' ], 'sanitize_callback' => 'sanitize_text_field' ],
                'feedback'   => [ 'required' => true, 'type' => 'string', 'enum' => [ 'good', 'bad', 'report' ] ],
                'details'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
                'site_url'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_url' ],
            ],
        ] );

        // Batch check + auto-queue translations for many plugins at once.
        register_rest_route( $this->namespace, '/batch-check-translations', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'batch_check_translations' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'plugins'    => [ 'type' => 'array' ],
                'themes'     => [ 'type' => 'array' ],
                'locales'    => [ 'required' => true, 'type' => 'array' ],
                'auto_approve' => [ 'type' => 'boolean', 'default' => false ],
                'auto_queue' => [ 'type' => 'boolean', 'default' => false ], // Deprecated.
                'site_url'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_url' ],
                'wp_version' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // Approve requested translations.
        register_rest_route( $this->namespace, '/approve', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'approve_translations' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'locale' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'job_ids' => [ 'type' => 'array' ],
            ],
        ] );

        // Reject (delete) requested translations.
        register_rest_route( $this->namespace, '/reject', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'reject_translations' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'locale' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'job_ids' => [ 'type' => 'array' ],
            ],
        ] );

    }

    /**
     * Health check.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_health( \WP_REST_Request $request ): \WP_REST_Response {
        $queue = Translation_Queue::instance();

        $counts = $queue->get_counts_by_status();

        return new \WP_REST_Response( [
            'status'           => 'ok',
            'version'          => GRATIS_AI_TS_VERSION,
            'provider'         => $this->get_health_provider_status(),
            'timestamp'        => current_time( 'c' ),
            'requested'        => $counts['requested'],
            'queue_length'     => $counts['pending'],
            'processing_count' => $counts['processing'],
            'completed'        => $counts['completed'],
            'failed'           => $counts['failed'],
        ], 200 );
    }

    /**
     * Get provider status for the public health response.
     *
     * Anonymous health checks only receive the active provider name. Detailed
     * provider configuration, including base URL and token-source metadata, is
     * restricted to administrators.
     *
     * @return array<string,mixed> Safe provider health status.
     */
    private function get_health_provider_status(): array {
        $status = Translation_Generator::instance()->get_provider_status( false );

        if ( current_user_can( 'manage_network_options' ) || current_user_can( 'manage_options' ) ) {
            return $status;
        }

        return [
            'active_provider' => $status['active_provider'] ?? 'none',
        ];
    }

    /**
     * Queue a translation generation request.
     *
     * Creates jobs in 'requested' status (waiting for approval).
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function request_translation( \WP_REST_Request $request ): \WP_REST_Response {
        $this->wporg_api_unavailable = false;
        $this->wporg_uncached_lookups = 0;
        $this->wporg_rate_caller = $this->get_wporg_rate_caller();
        $this->wporg_lookup_deadline = microtime( true ) + self::WPORG_PLUGIN_REQUEST_BUDGET;

        $textdomain = $request->get_param( 'textdomain' );
        $version    = $request->get_param( 'version' );
        $locales    = $request->get_param( 'locales' );
        $target_type = Translation_Queue::normalize_target_type( (string) $request->get_param( 'target_type' ) );
        $priority   = $request->get_param( 'priority' );
        $auto_approve = (bool) $request->get_param( 'auto_approve' );
        $site_url   = $request->get_param( 'site_url' );

        $source_resolution = $this->resolve_target_source( (string) $textdomain, $target_type );
        $plugin_source     = $source_resolution['source'];
        $queue             = Translation_Queue::instance();
        $queue->record_target_request(
            $textdomain,
            $version,
            $site_url,
            $plugin_source,
            $target_type,
            $source_resolution['authoritative']
        );
        $existing = [];
        $queued   = [];
        $job_id   = 0;

        foreach ( $locales as $locale ) {
            $locale = sanitize_text_field( $locale );
            $job    = $queue->get_job( $textdomain, $version, $locale, $target_type );

            if ( $job && $job['status'] === 'completed' ) {
                $existing[ $locale ] = [
                    'package_url' => $job['package_url'],
                    'updated'     => $job['completed_at'],
                    'target_type' => $target_type,
                ];
            } else {
                // Create/reset as requested, then approve when requested by caller.
                $job_id = $queue->add_job(
                    $textdomain,
                    $version,
                    $locale,
                    $priority,
                    'api',
                    $site_url,
                    $plugin_source,
                    $target_type,
                    $source_resolution['authoritative']
                );
                if ( $auto_approve && $job_id ) {
                    $queue->approve_job( (int) $job_id );
                }
                $queued[] = $locale;

                // Trigger processing immediately if auto-approved.
                if ( $auto_approve ) {
                    do_action( 'gratis_ai_ts_process_queue' );
                }
            }
        }

        if ( ! empty( $existing ) && empty( $queued ) ) {
            return new \WP_REST_Response( [
                'status'       => 'exists',
                'translations' => $existing,
            ], 200 );
        }

        return new \WP_REST_Response( [
            'status'         => $auto_approve ? 'queued' : 'requested',
            'target_type'    => $target_type,
            'locales'        => $queued,
            'requires_approval' => ! $auto_approve,
            'queue_position' => $job_id ? $queue->get_queue_position( $job_id ) : 0,
        ], 202 );
    }

    /**
     * Batch check + auto-queue translations for many plugins/themes at once.
     *
     * Request body:
     *   {
     *     "plugins":    [{"textdomain":"akismet","version":"5.6"}, ...],
     *     "themes":     [{"textdomain":"twentytwentyfour","version":"1.3"}, ...],
     *     "locales":    ["es_ES", "fr_FR"],
     *     "auto_queue": true (deprecated, use auto_approve)
     *     "auto_approve": true/false (default false - jobs need approval first)
     *     "site_url"   => "https://example.com"
     *   }
     *
     * Response:
     *   {
     *     "results":      { "plugin:akismet": { "es_ES": { "package_url": ..., "updated": ... } } },
     *     "requested":    [ {"target_type":"plugin","textdomain":"my-plugin","locale":"es_ES"}, ... ],
     *     "approved":   [ {"target_type":"theme","textdomain":"my-theme","locale":"es_ES"}, ... ],
     *     "queue_length": 12
     *   }
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function batch_check_translations( \WP_REST_Request $request ) {
        $this->wporg_api_unavailable = false;
        $this->wporg_uncached_lookups = 0;
        $this->wporg_rate_caller = $this->get_wporg_rate_caller();
        $this->wporg_lookup_deadline = microtime( true ) + self::WPORG_PLUGIN_REQUEST_BUDGET;

        $plugins      = $request->get_param( 'plugins' );
        $themes       = $request->get_param( 'themes' );
        $locales      = $request->get_param( 'locales' );
        $auto_approve = (bool) $request->get_param( 'auto_approve' );
        $site_url     = $request->get_param( 'site_url' );

        // Backwards compat: auto_queue maps to auto_approve.
        if ( $request->get_param( 'auto_queue' ) && ! $request->get_param( 'auto_approve' ) ) {
            $auto_approve = (bool) $request->get_param( 'auto_queue' );
        }

        $plugins = is_array( $plugins ) ? $plugins : [];
        $themes  = is_array( $themes ) ? $themes : [];

        if ( count( $plugins ) + count( $themes ) > 100 ) {
            return new \WP_Error( 'too_many_targets', 'maximum 100 plugins/themes per batch', [ 'status' => 400 ] );
        }

        if ( $this->has_conflicting_batch_versions( $plugins ) || $this->has_conflicting_batch_versions( $themes ) ) {
            return new \WP_Error(
                'conflicting_target_versions',
                'a batch cannot contain multiple versions of the same plugin or theme',
                [ 'status' => 400 ]
            );
        }

        if ( ! is_array( $locales ) || empty( $locales ) ) {
            return new \WP_Error( 'invalid_locales', 'locales must be a non-empty array', [ 'status' => 400 ] );
        }

        if ( count( $locales ) > 20 ) {
            return new \WP_Error( 'too_many_locales', 'maximum 20 locales per batch', [ 'status' => 400 ] );
        }

        $targets = array_merge(
            $this->prepare_batch_targets( $plugins, 'plugin' ),
            $this->prepare_batch_targets( $themes, 'theme' )
        );

        if ( empty( $targets ) ) {
            return new \WP_Error( 'invalid_targets', 'plugins or themes must contain at least one valid target', [ 'status' => 400 ] );
        }

        $queue   = Translation_Queue::instance();
        $results = [];
        $requested = [];
        $approved = [];

        foreach ( $targets as $target ) {
            $target_type   = $target['target_type'];
            $textdomain    = $target['textdomain'];
            $version       = $target['version'];
            $plugin_source = $target['source'];
            $result_key    = $target_type . ":" . $textdomain;
            $queue->record_target_request(
                $textdomain,
                $version,
                $site_url,
                $plugin_source,
                $target_type,
                $target['source_authoritative']
            );

            $results[ $result_key ] = [];

            foreach ( $locales as $locale ) {
                $locale = sanitize_text_field( (string) $locale );

                if ( ! preg_match( '/^[a-z]{2,3}(_[A-Z]{2,3})?$/', $locale ) ) {
                    continue;
                }

                $job = $queue->get_job( $textdomain, $version, $locale, $target_type );

                if ( $job && $job['status'] === 'completed' ) {
                    $results[ $result_key ][ $locale ] = [
                        'package_url' => $job['package_url'],
                        'updated'     => $job['completed_at'],
                        'source'      => 'ai',
                        'target_type' => $target_type,
                    ];
                    continue;
                }

                if ( $job && in_array( $job['status'], [ 'processing', 'pending' ], true ) ) {
                    $results[ $result_key ][ $locale ] = [
                        'status'         => $job['status'],
                        'queue_position' => $queue->get_queue_position( (int) $job['id'] ),
                        'target_type'    => $target_type,
                    ];
                    continue;
                }

                if ( $job && $job['status'] === 'requested' ) {
                    if ( $auto_approve ) {
                        $queue->approve_job( (int) $job['id'] );
                        $approved[] = [ 'target_type' => $target_type, 'textdomain' => $textdomain, 'locale' => $locale ];
                        $results[ $result_key ][ $locale ] = [
                            'status'         => 'pending',
                            'queue_position' => $queue->get_queue_position( (int) $job['id'] ),
                            'target_type'    => $target_type,
                        ];
                        continue;
                    }

                    $results[ $result_key ][ $locale ] = [
                        'status'            => 'requested',
                        'awaiting_approval' => true,
                        'target_type'       => $target_type,
                    ];
                    continue;
                }

                // Create/reset as requested, then approve when requested by caller.
                $job_id = $queue->add_job(
                    $textdomain,
                    $version,
                    $locale,
                    5,
                    'api',
                    $site_url,
                    $plugin_source,
                    $target_type,
                    $target['source_authoritative']
                );
                if ( $auto_approve && $job_id ) {
                    $queue->approve_job( (int) $job_id );
                    $approved[] = [ 'target_type' => $target_type, 'textdomain' => $textdomain, 'locale' => $locale ];
                } else {
                    $requested[] = [ 'target_type' => $target_type, 'textdomain' => $textdomain, 'locale' => $locale ];
                }
            }
        }

        if ( ! empty( $approved ) ) {
            do_action( 'gratis_ai_ts_process_queue' );
        }

        return new \WP_REST_Response( [
            'results'      => $results,
            'requested'    => $requested,
            'approved'   => $approved,
            'queue_length' => $queue->get_pending_count(),
        ], 200 );
    }

    /**
     * Get translation job status.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_translation_status( \WP_REST_Request $request ) {
        $textdomain = $request->get_param( 'textdomain' );
        $version    = $request->get_param( 'version' );
        $locale     = $request->get_param( 'locale' );
        $target_type = Translation_Queue::normalize_target_type( (string) $request->get_param( 'target_type' ) );

        $queue = Translation_Queue::instance();
        $job   = $queue->get_job( $textdomain, $version, $locale, $target_type );

        if ( ! $job ) {
            return new \WP_Error( 'not_found', 'Translation job not found', [ 'status' => 404 ] );
        }

        $data = [
            'status'     => $job['status'],
            'textdomain' => $textdomain,
            'version'    => $version,
            'locale'     => $locale,
            'target_type' => $target_type,
        ];

        switch ( $job['status'] ) {
            case 'completed':
                $data['package_url']  = $job['package_url'];
                $data['updated']      = $job['completed_at'];
                $data['string_count'] = (int) $job['string_count'];
                break;
            case 'processing':
                $data['strings_total'] = (int) $job['string_count'];
                $data['strings_done']  = (int) $job['translated_count'];
                break;
            case 'pending':
                $data['queue_position'] = $queue->get_queue_position( (int) $job['id'] );
                break;
            case 'failed':
                $data['error_message'] = $job['error_message'];
                break;
        }

        return new \WP_REST_Response( $data, 200 );
    }

    /**
     * Submit translation quality feedback.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function submit_feedback( \WP_REST_Request $request ): \WP_REST_Response {
        $entry = [
            'target_type'  => Translation_Queue::normalize_target_type( (string) $request->get_param( 'target_type' ) ),
            'textdomain'   => $request->get_param( 'textdomain' ),
            'version'      => $request->get_param( 'version' ),
            'locale'       => $request->get_param( 'locale' ),
            'feedback'     => $request->get_param( 'feedback' ),
            'details'      => $request->get_param( 'details' ),
            'site_url'     => $request->get_param( 'site_url' ),
            'submitted_at' => current_time( 'mysql' ),
            'ip_address'   => $this->get_client_ip(),
        ];

        do_action( 'gratis_ai_ts_feedback_received', $entry );

        $log_dir = WP_CONTENT_DIR . '/gratis-ai-logs';
        wp_mkdir_p( $log_dir );
        $log_file = $log_dir . '/feedback-' . gmdate( 'Y-m' ) . '.jsonl';
        file_put_contents( $log_file, wp_json_encode( $entry ) . "\n", FILE_APPEND | LOCK_EX );

        return new \WP_REST_Response( [ 'status' => 'received' ], 200 );
    }

    /**
     * Normalize a batch target list into plugin/theme queue targets.
     *
     * Client-provided source metadata is intentionally ignored because callers
     * are outside the server's trust boundary.
     *
     * @param array  $items       Raw target list from the REST request.
     * @param string $target_type Target type to apply to each item.
     * @return array<int,array{target_type:string,textdomain:string,version:string,source:string,source_authoritative:bool}>
     */
    private function prepare_batch_targets( array $items, string $target_type ): array {
        $target_type = Translation_Queue::normalize_target_type( $target_type );
        $targets     = [];

        foreach ( $items as $item ) {
            if (
                ! is_array( $item )
                || ! is_string( $item['textdomain'] ?? null )
                || ! is_string( $item['version'] ?? null )
            ) {
                continue;
            }

            $textdomain = sanitize_text_field( (string) $item['textdomain'] );
            $version    = sanitize_text_field( (string) $item['version'] );

            if (
                ! preg_match( '/^[a-z0-9_-]{1,80}$/i', $textdomain )
                || ! preg_match( '/^[a-z0-9._+-]{1,40}$/i', $version )
            ) {
                continue;
            }

            $target_key = $target_type . "\0" . $textdomain . "\0" . $version;
            if ( isset( $targets[$target_key] ) ) {
                continue;
            }

            $source_resolution = $this->resolve_target_source( $textdomain, $target_type );

            $targets[$target_key] = [
                'target_type'          => $target_type,
                'textdomain'           => $textdomain,
                'version'              => $version,
                'source'               => $source_resolution['source'],
                'source_authoritative' => $source_resolution['authoritative'],
            ];
        }

        return array_values( $targets );
    }

    /**
     * Detect targets that would collide in the versionless response key.
     *
     * The existing client response contract keys results by target type and
     * textdomain. Reject conflicting versions rather than silently replacing
     * one result or introducing a backward-incompatible response key.
     *
     * @param array $items Raw target list from the REST request.
     * @return bool Whether one textdomain contains multiple valid versions.
     */
    private function has_conflicting_batch_versions( array $items ): bool {
        $versions = [];

        foreach ( $items as $item ) {
            if (
                ! is_array( $item )
                || ! is_string( $item['textdomain'] ?? null )
                || ! is_string( $item['version'] ?? null )
            ) {
                continue;
            }

            $textdomain = sanitize_text_field( (string) $item['textdomain'] );
            $version    = sanitize_text_field( (string) $item['version'] );

            if (
                ! preg_match( '/^[a-z0-9_-]{1,80}$/i', $textdomain )
                || ! preg_match( '/^[a-z0-9._+-]{1,40}$/i', $version )
            ) {
                continue;
            }

            if ( isset( $versions[$textdomain] ) && $versions[$textdomain] !== $version ) {
                return true;
            }

            $versions[$textdomain] = $version;
        }

        return false;
    }

    /**
     * Resolve target provenance using server-controlled data.
     *
     * WordPress.org currently provides a plugin-information lookup suitable
     * for plugin slugs. Theme and failed plugin lookups remain unknown rather
     * than trusting metadata supplied by a customer site.
     *
     * @param string $textdomain Target textdomain or slug.
     * @param string $target_type Target type.
     * @return array{source:string,authoritative:bool} Source and whether the server verified it.
     */
    private function resolve_target_source( string $textdomain, string $target_type ): array {
        if ( 'plugin' !== Translation_Queue::normalize_target_type( $target_type ) ) {
            return [ 'source' => 'unknown', 'authoritative' => true ];
        }

        $slug = strtolower( trim( $textdomain ) );
        if ( ! preg_match( '/^[a-z0-9_-]{1,80}$/', $slug ) ) {
            return [ 'source' => 'unknown', 'authoritative' => true ];
        }

        $status = $this->get_wporg_plugin_status( $slug );

        return [
            'source'        => 'wporg' === $status ? 'wporg' : 'unknown',
            'authoritative' => 'error' !== $status,
        ];
    }

    /**
     * Get a cached WordPress.org plugin existence status.
     *
     * Successful lookups are stable and cached for a week. Definitive 404s
     * are cached for a day so newly published plugins are eventually found.
     * Transport and upstream failures are cached briefly to avoid hammering
     * WordPress.org while still recovering quickly.
     *
     * @param string $slug Plugin slug.
     * @return string One of wporg, not_found, or error.
     */
    private function get_wporg_plugin_status( string $slug ): string {
        $cache_key = self::WPORG_PLUGIN_CACHE_PREFIX . md5( $slug );
        $cached    = get_site_transient( $cache_key );

        if ( is_string( $cached ) && in_array( $cached, [ 'wporg', 'not_found', 'error' ], true ) ) {
            return $cached;
        }

        if ( $this->wporg_api_unavailable ) {
            return 'error';
        }

        if ( $this->wporg_uncached_lookups >= self::WPORG_PLUGIN_REQUEST_LIMIT ) {
            return 'error';
        }

        if ( $this->get_wporg_lookup_time_remaining() <= 0.0 ) {
            return 'error';
        }

        if ( ! $this->acquire_wporg_slug_lock( $slug ) ) {
            return 'error';
        }

        try {
            $cached = get_site_transient( $cache_key );
            if ( is_string( $cached ) && in_array( $cached, [ 'wporg', 'not_found', 'error' ], true ) ) {
                return $cached;
            }
        } finally {
            $this->release_wporg_slug_lock( $slug );
        }

        // Keep named locks non-overlapping for MySQL versions that permit only
        // one advisory lock per connection. Reacquire and recheck after the
        // atomic rate reservation so another worker cannot duplicate the call.
        if ( $this->get_wporg_lookup_time_remaining() <= 0.0 ) {
            return 'error';
        }

        $reservation = $this->consume_wporg_lookup_budget();
        if ( false === $reservation ) {
            return 'error';
        }

        $time_remaining = $this->get_wporg_lookup_time_remaining();
        if ( $time_remaining <= 0.0 || ! $this->acquire_wporg_slug_lock( $slug ) ) {
            $this->refund_wporg_lookup_budget( $reservation );
            return 'error';
        }

        $refund_reservation = false;
        $status             = 'error';

        try {
            $cached = get_site_transient( $cache_key );
            if ( is_string( $cached ) && in_array( $cached, [ 'wporg', 'not_found', 'error' ], true ) ) {
                $status             = $cached;
                $refund_reservation = true;
            } else {
                $time_remaining = $this->get_wporg_lookup_time_remaining();
                if ( $time_remaining <= 0.0 ) {
                    $refund_reservation = true;
                } else {
                    $this->wporg_uncached_lookups++;
                    $status = $this->request_wporg_plugin_status( $slug, min( 5.0, $time_remaining ) );
                    if ( 'error' === $status ) {
                        $this->wporg_api_unavailable = true;
                    }
                    $ttl = match ( $status ) {
                        'wporg'     => WEEK_IN_SECONDS,
                        'not_found' => DAY_IN_SECONDS,
                        default     => 15 * MINUTE_IN_SECONDS,
                    };

                    set_site_transient( $cache_key, $status, $ttl );
                }
            }
        } finally {
            $this->release_wporg_slug_lock( $slug );
        }

        if ( $refund_reservation ) {
            $this->refund_wporg_lookup_budget( $reservation );
        }

        return $status;
    }

    /**
     * Acquire the database lock for one plugin slug.
     *
     * @param string $slug Plugin slug.
     * @return bool Whether the lock was acquired immediately.
     * @phpstan-impure Reads and changes database advisory-lock state.
     */
    private function acquire_wporg_slug_lock( string $slug ): bool {
        return $this->acquire_wporg_database_lock( self::WPORG_PLUGIN_SLUG_LOCK_PREFIX . md5( $slug ) );
    }

    /**
     * Release the database lock for one plugin slug.
     *
     * @param string $slug Plugin slug.
     * @return void
     */
    private function release_wporg_slug_lock( string $slug ): void {
        $this->release_wporg_database_lock( self::WPORG_PLUGIN_SLUG_LOCK_PREFIX . md5( $slug ) );
    }

    /**
     * Acquire a named database lock without waiting.
     *
     * @param string $lock_name Database lock name.
     * @return bool Whether the lock was acquired immediately.
     */
    private function acquire_wporg_database_lock( string $lock_name ): bool {
        global $wpdb;

        return 1 === (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name )
        );
    }

    /**
     * Release a named database lock.
     *
     * @param string $lock_name Database lock name.
     * @return void
     */
    private function release_wporg_database_lock( string $lock_name ): void {
        global $wpdb;

        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
    }

    /**
     * Consume one network-wide uncached lookup from the current minute window.
     *
     * @return array{window_started:int,caller:string}|false Reservation metadata, or false when limited.
     */
    private function consume_wporg_lookup_budget(): array|false {
        if ( ! $this->acquire_wporg_database_lock( self::WPORG_PLUGIN_RATE_LOCK ) ) {
            return false;
        }

        try {
            $now   = time();
            $state = get_site_option( self::WPORG_PLUGIN_RATE_OPTION, [] );

            if ( ! is_array( $state ) || $now - (int) ( $state['window_started'] ?? 0 ) >= MINUTE_IN_SECONDS ) {
                $state = [
                    'window_started' => $now,
                    'count'          => 0,
                ];
            }

            $callers      = is_array( $state['callers'] ?? null ) ? $state['callers'] : [];
            $caller_count = (int) ( $callers[$this->wporg_rate_caller] ?? 0 );

            if (
                (int) $state['count'] >= self::WPORG_PLUGIN_RATE_LIMIT
                || $caller_count >= self::WPORG_PLUGIN_CALLER_RATE_LIMIT
            ) {
                return false;
            }

            $state['count']                    = (int) $state['count'] + 1;
            $callers[$this->wporg_rate_caller] = $caller_count + 1;
            $state['callers']                  = $callers;

            if ( ! update_site_option( self::WPORG_PLUGIN_RATE_OPTION, $state ) ) {
                return false;
            }

            return [
                'window_started' => (int) $state['window_started'],
                'caller'         => $this->wporg_rate_caller,
            ];
        } finally {
            $this->release_wporg_database_lock( self::WPORG_PLUGIN_RATE_LOCK );
        }
    }

    /**
     * Refund a reservation when no upstream request was initiated.
     *
     * Refunds apply only to the original minute window. A failed refund remains
     * conservative by leaving the counters charged rather than risking an
     * over-limit outbound request.
     *
     * @param array{window_started:int,caller:string} $reservation Reservation metadata.
     * @return void
     */
    private function refund_wporg_lookup_budget( array $reservation ): void {
        if ( ! $this->acquire_wporg_database_lock( self::WPORG_PLUGIN_RATE_LOCK ) ) {
            return;
        }

        try {
            $state = get_site_option( self::WPORG_PLUGIN_RATE_OPTION, [] );
            if (
                ! is_array( $state )
                || (int) ( $state['window_started'] ?? 0 ) !== $reservation['window_started']
            ) {
                return;
            }

            $callers      = is_array( $state['callers'] ?? null ) ? $state['callers'] : [];
            $caller_count = (int) ( $callers[$reservation['caller']] ?? 0 );

            $state['count'] = max( 0, (int) ( $state['count'] ?? 0 ) - 1 );
            if ( $caller_count <= 1 ) {
                unset( $callers[$reservation['caller']] );
            } else {
                $callers[$reservation['caller']] = $caller_count - 1;
            }
            $state['callers'] = $callers;

            update_site_option( self::WPORG_PLUGIN_RATE_OPTION, $state );
        } finally {
            $this->release_wporg_database_lock( self::WPORG_PLUGIN_RATE_LOCK );
        }
    }

    /**
     * Get a non-spoofable caller key for WordPress.org lookup rate limiting.
     *
     * The TCP peer address is used directly rather than forwarded headers or
     * caller-provided site metadata. The hash keeps raw addresses out of the
     * network option while grouping requests arriving through the same proxy.
     *
     * @return string Hashed network peer identity.
     */
    private function get_wporg_rate_caller(): string {
        $address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );

        if ( false === filter_var( $address, FILTER_VALIDATE_IP ) ) {
            $address = 'unknown';
        }

        return wp_hash( $address );
    }

    /**
     * Get the remaining wall-clock lookup budget for the current request.
     *
     * A zero deadline permits direct internal calls outside a REST request while
     * retaining the normal five-second timeout for tests and administrative use.
     *
     * @return float Remaining seconds.
     */
    private function get_wporg_lookup_time_remaining(): float {
        if ( $this->wporg_lookup_deadline <= 0.0 ) {
            return 5.0;
        }

        return max( 0.0, $this->wporg_lookup_deadline - microtime( true ) );
    }

    /**
     * Query the WordPress.org Plugin Information API for an exact slug.
     *
     * @param string $slug Plugin slug.
     * @param float  $timeout Maximum HTTP request duration in seconds.
     * @return string One of wporg, not_found, or error.
     */
    private function request_wporg_plugin_status( string $slug, float $timeout ): string {
        global $wp_version;

        $url = add_query_arg(
            [
                'action'  => 'plugin_information',
                'request' => [
                    'slug'   => $slug,
                    'fields' => [
                        'short_description' => false,
                        'description'       => false,
                        'sections'          => false,
                        'tested'            => false,
                        'requires'          => false,
                        'requires_php'      => false,
                        'rating'            => false,
                        'ratings'           => false,
                        'downloaded'        => false,
                        'downloadlink'      => false,
                        'last_updated'      => false,
                        'added'             => false,
                        'tags'              => false,
                        'compatibility'     => false,
                        'homepage'          => false,
                        'versions'          => false,
                        'donate_link'       => false,
                        'reviews'           => false,
                        'banners'           => false,
                        'icons'             => false,
                        'active_installs'   => false,
                        'contributors'      => false,
                    ],
                ],
            ],
            self::WPORG_PLUGIN_API_URL
        );

        $response = wp_remote_get(
            $url,
            [
                'timeout'     => $timeout,
                'redirection' => 2,
                'user-agent'  => 'WordPress/' . ( function_exists( 'wp_get_wp_version' ) ? wp_get_wp_version() : (string) $wp_version ) . '; ' . home_url( '/' ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return 'error';
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( 404 === $response_code ) {
            return 'not_found';
        }

        if ( 200 !== $response_code ) {
            return 'error';
        }

        $plugin = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $plugin ) || $slug !== strtolower( (string) ( $plugin['slug'] ?? '' ) ) ) {
            return 'error';
        }

        return 'wporg';
    }

    /**
     * Get client IP address.
     *
     * @return string
     */
    private function get_client_ip(): string {
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ips = explode( ',', sanitize_text_field( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
            return trim( $ips[0] );
        }

        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
    }

    /**
     * Approve requested translation jobs.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function approve_translations( \WP_REST_Request $request ): \WP_REST_Response {
        $locale  = $request->get_param( 'locale' );
        $job_ids = $request->get_param( 'job_ids' );

        $queue = Translation_Queue::instance();
        $approved = 0;

        if ( ! empty( $job_ids ) && is_array( $job_ids ) ) {
            // Approve specific jobs.
            foreach ( $job_ids as $job_id ) {
                if ( $queue->approve_job( (int) $job_id ) ) {
                    $approved++;
                }
            }
        } elseif ( ! empty( $locale ) ) {
            // Approve all for locale.
            $approved = $queue->approve_all_by_locale( $locale );
        } else {
            // Approve all.
            $approved = $queue->approve_all();
        }

        return new \WP_REST_Response( [
            'status'   => 'approved',
            'approved' => $approved,
        ], 200 );
    }

    /**
     * Reject (delete) requested translation jobs.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function reject_translations( \WP_REST_Request $request ): \WP_REST_Response {
        $locale  = $request->get_param( 'locale' );
        $job_ids = $request->get_param( 'job_ids' );

        $queue = Translation_Queue::instance();
        $rejected = 0;

        if ( ! empty( $job_ids ) && is_array( $job_ids ) ) {
            // Reject specific jobs.
            foreach ( $job_ids as $job_id ) {
                if ( $queue->reject_job( (int) $job_id ) ) {
                    $rejected++;
                }
            }
        } elseif ( ! empty( $locale ) ) {
            // Reject all for locale.
            $rejected = $queue->reject_all_by_locale( $locale );
        }

        return new \WP_REST_Response( [
            'status'   => 'rejected',
            'rejected' => $rejected,
        ], 200 );
    }
}
