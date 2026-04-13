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
    private string $namespace = 'gratis-ai-translations/v1';

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
                'plugins'    => [ 'required' => true, 'type' => 'array' ],
                'locales'    => [ 'required' => true, 'type' => 'array' ],
                'auto_queue' => [ 'type' => 'boolean', 'default' => true ],
                'site_url'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_url' ],
                'wp_version' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
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

        return new \WP_REST_Response( [
            'status'           => 'ok',
            'version'          => GRATIS_AI_TS_VERSION,
            'timestamp'        => current_time( 'c' ),
            'queue_length'     => $queue->get_pending_count(),
            'processing_count' => $queue->get_processing_count(),
            'completed_today'  => $queue->get_completed_count_today(),
        ], 200 );
    }

    /**
     * Queue a translation generation request.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function request_translation( \WP_REST_Request $request ): \WP_REST_Response {
        $textdomain = $request->get_param( 'textdomain' );
        $version    = $request->get_param( 'version' );
        $locales    = $request->get_param( 'locales' );
        $priority   = $request->get_param( 'priority' );

        $queue = Translation_Queue::instance();
        $existing = [];
        $queued   = [];
        $job_id   = 0;

        foreach ( $locales as $locale ) {
            $locale = sanitize_text_field( $locale );
            $job    = $queue->get_job( $textdomain, $version, $locale );

            if ( $job && $job['status'] === 'completed' ) {
                $existing[ $locale ] = [
                    'package_url' => $job['package_url'],
                    'updated'     => $job['completed_at'],
                ];
            } else {
                $job_id   = $queue->add_job( $textdomain, $version, $locale, $priority );
                $queued[] = $locale;
            }
        }

        if ( ! empty( $existing ) && empty( $queued ) ) {
            return new \WP_REST_Response( [
                'status'       => 'exists',
                'translations' => $existing,
            ], 200 );
        }

        do_action( 'gratis_ai_ts_process_queue' );

        return new \WP_REST_Response( [
            'status'         => 'queued',
            'locales'        => $queued,
            'queue_position' => $queue->get_queue_position( $job_id ),
        ], 202 );
    }

    /**
     * Batch check + auto-queue translations for many plugins in one round trip.
     *
     * Request body:
     *   {
     *     "plugins":    [{"textdomain":"akismet","version":"5.6"}, ...],
     *     "locales":    ["es_ES", "fr_FR"],
     *     "auto_queue": true
     *   }
     *
     * Response:
     *   {
     *     "results":      { "akismet": { "es_ES": { "package_url": ..., "updated": ... } } },
     *     "queued":       [ {"textdomain":"my-plugin","locale":"es_ES"}, ... ],
     *     "queue_length": 12
     *   }
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function batch_check_translations( \WP_REST_Request $request ) {
        $plugins    = $request->get_param( 'plugins' );
        $locales    = $request->get_param( 'locales' );
        $auto_queue = (bool) $request->get_param( 'auto_queue' );

        if ( ! is_array( $plugins ) || empty( $plugins ) ) {
            return new \WP_Error( 'invalid_plugins', 'plugins must be a non-empty array', [ 'status' => 400 ] );
        }

        if ( count( $plugins ) > 100 ) {
            return new \WP_Error( 'too_many_plugins', 'maximum 100 plugins per batch', [ 'status' => 400 ] );
        }

        if ( ! is_array( $locales ) || empty( $locales ) ) {
            return new \WP_Error( 'invalid_locales', 'locales must be a non-empty array', [ 'status' => 400 ] );
        }

        if ( count( $locales ) > 20 ) {
            return new \WP_Error( 'too_many_locales', 'maximum 20 locales per batch', [ 'status' => 400 ] );
        }

        $queue   = Translation_Queue::instance();
        $results = [];
        $queued  = [];

        foreach ( $plugins as $plugin ) {
            if ( ! is_array( $plugin ) || empty( $plugin['textdomain'] ) || empty( $plugin['version'] ) ) {
                continue;
            }

            $textdomain = sanitize_text_field( (string) $plugin['textdomain'] );
            $version    = sanitize_text_field( (string) $plugin['version'] );

            if ( ! preg_match( '/^[a-z0-9_-]{1,80}$/i', $textdomain ) ) {
                continue;
            }

            $results[ $textdomain ] = [];

            foreach ( $locales as $locale ) {
                $locale = sanitize_text_field( (string) $locale );

                if ( ! preg_match( '/^[a-z]{2,3}(_[A-Z]{2,3})?$/', $locale ) ) {
                    continue;
                }

                $job    = $queue->get_job( $textdomain, $version, $locale );

                if ( $job && $job['status'] === 'completed' ) {
                    $results[ $textdomain ][ $locale ] = [
                        'package_url' => $job['package_url'],
                        'updated'     => $job['completed_at'],
                        'source'      => 'ai',
                    ];
                    continue;
                }

                if ( $job && in_array( $job['status'], [ 'processing', 'pending' ], true ) ) {
                    $results[ $textdomain ][ $locale ] = [
                        'status'         => $job['status'],
                        'queue_position' => $queue->get_queue_position( (int) $job['id'] ),
                    ];
                    continue;
                }

                if ( $auto_queue ) {
                    $queue->add_job( $textdomain, $version, $locale, 5 );
                    $queued[] = [ 'textdomain' => $textdomain, 'locale' => $locale ];
                }
            }
        }

        if ( ! empty( $queued ) ) {
            do_action( 'gratis_ai_ts_process_queue' );
        }

        return new \WP_REST_Response( [
            'results'      => $results,
            'queued'       => $queued,
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

        $queue = Translation_Queue::instance();
        $job   = $queue->get_job( $textdomain, $version, $locale );

        if ( ! $job ) {
            return new \WP_Error( 'not_found', 'Translation job not found', [ 'status' => 404 ] );
        }

        $data = [
            'status'     => $job['status'],
            'textdomain' => $textdomain,
            'version'    => $version,
            'locale'     => $locale,
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
}
