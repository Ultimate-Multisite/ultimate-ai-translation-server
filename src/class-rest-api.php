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
            'provider'         => Translation_Generator::instance()->get_provider_status( false ),
            'timestamp'        => current_time( 'c' ),
            'requested'        => $counts['requested'],
            'queue_length'     => $counts['pending'],
            'processing_count' => $counts['processing'],
            'completed'        => $counts['completed'],
            'failed'           => $counts['failed'],
        ], 200 );
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
        $textdomain = $request->get_param( 'textdomain' );
        $version    = $request->get_param( 'version' );
        $locales    = $request->get_param( 'locales' );
        $target_type = Translation_Queue::normalize_target_type( (string) $request->get_param( 'target_type' ) );
        $priority   = $request->get_param( 'priority' );
        $auto_approve = (bool) $request->get_param( 'auto_approve' );
        $site_url   = $request->get_param( 'site_url' );

        $queue = Translation_Queue::instance();
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
                $job_id = $queue->add_job( $textdomain, $version, $locale, $priority, 'api', $site_url, 'unknown', $target_type );
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
        $targets = array_merge(
            $this->prepare_batch_targets( $plugins, 'plugin' ),
            $this->prepare_batch_targets( $themes, 'theme' )
        );

        if ( empty( $targets ) ) {
            return new \WP_Error( 'invalid_targets', 'plugins or themes must contain at least one valid target', [ 'status' => 400 ] );
        }

        if ( count( $targets ) > 100 ) {
            return new \WP_Error( 'too_many_targets', 'maximum 100 plugins/themes per batch', [ 'status' => 400 ] );
        }

        if ( ! is_array( $locales ) || empty( $locales ) ) {
            return new \WP_Error( 'invalid_locales', 'locales must be a non-empty array', [ 'status' => 400 ] );
        }

        if ( count( $locales ) > 20 ) {
            return new \WP_Error( 'too_many_locales', 'maximum 20 locales per batch', [ 'status' => 400 ] );
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
            $result_key    = $target_type . ':' . $textdomain;

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
                $job_id = $queue->add_job( $textdomain, $version, $locale, 5, 'api', $site_url, $plugin_source, $target_type );
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
     * @param array  $items       Raw target list from the REST request.
     * @param string $target_type Target type to apply to each item.
     * @return array<int,array{target_type:string,textdomain:string,version:string,source:string}>
     */
    private function prepare_batch_targets( array $items, string $target_type ): array {
        $target_type = Translation_Queue::normalize_target_type( $target_type );
        $targets     = [];

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || empty( $item['textdomain'] ) || empty( $item['version'] ) ) {
                continue;
            }

            $textdomain = sanitize_text_field( (string) $item['textdomain'] );
            $version    = sanitize_text_field( (string) $item['version'] );
            $source     = sanitize_text_field( (string) ( $item['source'] ?? 'unknown' ) );

            if ( ! preg_match( '/^[a-z0-9_-]{1,80}$/i', $textdomain ) ) {
                continue;
            }

            $targets[] = [
                'target_type' => $target_type,
                'textdomain'  => $textdomain,
                'version'     => $version,
                'source'      => $source,
            ];
        }

        return $targets;
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
