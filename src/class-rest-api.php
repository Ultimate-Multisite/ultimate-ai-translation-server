<?php
/**
 * REST API class
 *
 * Handles REST API endpoints for the translation server.
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
     * Instance of this class.
     *
     * @since 1.0.0
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * API namespace.
     *
     * @since 1.0.0
     * @var string
     */
    private string $namespace = 'gratis-ai-translations/v1';

    /**
     * Rate limiter instance.
     *
     * @since 1.0.0
     * @var Rate_Limiter|null
     */
    private ?Rate_Limiter $rate_limiter = null;

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
     * Constructor.
     *
     * @since 1.0.0
     */
    private function __construct() {
        $this->rate_limiter = new Rate_Limiter();
    }

    /**
     * Initialize hooks.
     *
     * @since 1.0.0
     * @return void
     */
    public function init(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('rest_api_init', [$this, 'register_cors']);
    }

    /**
     * Register CORS headers.
     *
     * @since 1.0.0
     * @return void
     */
    public function register_cors(): void {
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        add_filter('rest_pre_serve_request', function ($value) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Accept');
            header('Access-Control-Max-Age: 600');
            return $value;
        });
    }

    /**
     * Register REST API routes.
     *
     * @since 1.0.0
     * @return void
     */
    public function register_routes(): void {
        // Health check endpoint.
        register_rest_route(
            $this->namespace,
            '/health',
            [
                'methods'  => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_health'],
                'permission_callback' => '__return_true',
            ]
        );

        // Request translation generation.
        register_rest_route(
            $this->namespace,
            '/request-translation',
            [
                'methods'  => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'request_translation'],
                'permission_callback' => [$this, 'check_rate_limit'],
                'args'     => [
                    'textdomain' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'version' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'locales' => [
                        'required'          => true,
                        'type'              => 'array',
                    ],
                    'site_url' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_url',
                    ],
                    'wp_version' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'priority' => [
                        'type'    => 'integer',
                        'default' => 5,
                    ],
                ],
            ]
        );

        // Get translation status.
        register_rest_route(
            $this->namespace,
            '/translation-status',
            [
                'methods'  => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'get_translation_status'],
                'permission_callback' => [$this, 'check_rate_limit'],
                'args'     => [
                    'textdomain' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'version' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'locale' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // Submit feedback.
        register_rest_route(
            $this->namespace,
            '/feedback',
            [
                'methods'  => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'submit_feedback'],
                'permission_callback' => [$this, 'check_rate_limit'],
                'args'     => [
                    'textdomain' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'version' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'locale' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'feedback' => [
                        'required'          => true,
                        'type'              => 'string',
                        'enum'              => ['good', 'bad', 'report'],
                    ],
                    'details' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ],
                    'site_url' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_url',
                    ],
                ],
            ]
        );

    }

    /**
     * Health check endpoint.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_health(\WP_REST_Request $request): \WP_REST_Response {
        $queue = Translation_Queue::instance();

        $data = [
            'status'            => 'ok',
            'version'           => GRATIS_AI_TS_VERSION,
            'timestamp'         => current_time('c'),
            'supported_locales' => $this->get_supported_locales(),
            'queue_length'      => $queue->get_pending_count(),
            'processing_count'  => $queue->get_processing_count(),
            'completed_today'   => $queue->get_completed_count_today(),
        ];

        return new \WP_REST_Response($data, 200);
    }

    /**
     * Request translation generation endpoint.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function request_translation(\WP_REST_Request $request) {
        $textdomain = $request->get_param('textdomain');
        $version    = $request->get_param('version');
        $locales    = $request->get_param('locales');
        $priority   = $request->get_param('priority');
        $site_url   = $request->get_param('site_url');

        // Log the request.
        $this->log_request('request', $textdomain, $version, $locales, $site_url);

        $queue = Translation_Queue::instance();
        $existing = [];
        $queued = [];

        foreach ($locales as $locale) {
            $locale = sanitize_text_field($locale);

            // Check if already exists.
            $job = $queue->get_job($textdomain, $version, $locale);

            if ($job && $job['status'] === 'completed') {
                $existing[$locale] = [
                    'package_url' => $job['package_url'],
                    'updated'     => $job['completed_at'],
                ];
            } else {
                // Queue new job.
                $job_id = $queue->add_job($textdomain, $version, $locale, $priority);
                $queued[] = $locale;
            }
        }

        if (!empty($existing)) {
            return new \WP_REST_Response([
                'status'       => 'exists',
                'message'      => 'Translations already exist for this plugin version',
                'translations' => $existing,
            ], 200);
        }

        // Trigger queue processing.
        do_action('gratis_ai_ts_process_queue');

        return new \WP_REST_Response([
            'status'         => 'queued',
            'message'        => 'Translation generation has been queued',
            'locales'        => $queued,
            'queue_position' => $queue->get_queue_position($job_id ?? 0),
            'estimated_time' => $this->estimate_queue_time(),
        ], 202);
    }

    /**
     * Get translation status endpoint.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_translation_status(\WP_REST_Request $request) {
        $textdomain = $request->get_param('textdomain');
        $version    = $request->get_param('version');
        $locale     = $request->get_param('locale');

        $queue = Translation_Queue::instance();
        $job   = $queue->get_job($textdomain, $version, $locale);

        if (!$job) {
            return new \WP_Error(
                'not_found',
                'Translation job not found',
                ['status' => 404]
            );
        }

        $data = [
            'status'     => $job['status'],
            'textdomain' => $textdomain,
            'version'    => $version,
            'locale'     => $locale,
        ];

        if ($job['status'] === 'completed') {
            $data['package_url']   = $job['package_url'];
            $data['updated']       = $job['completed_at'];
            $data['completeness']  = $this->calculate_completeness($job);
            $data['string_count']  = $job['string_count'];
        } elseif ($job['status'] === 'processing') {
            $data['progress']        = $this->get_translation_progress($job);
            $data['strings_total']   = $job['string_count'];
            $data['strings_done']    = $job['translated_count'];
            $data['estimated_time']  = $this->estimate_completion_time($job);
        } elseif ($job['status'] === 'pending') {
            $data['queue_position'] = $queue->get_queue_position($job['id']);
            $data['estimated_time'] = $this->estimate_completion_time($job);
        } elseif ($job['status'] === 'failed') {
            $data['error_message'] = $job['error_message'];
        }

        return new \WP_REST_Response($data, 200);
    }

    /**
     * Submit feedback endpoint.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function submit_feedback(\WP_REST_Request $request): \WP_REST_Response {
        $textdomain = $request->get_param('textdomain');
        $version    = $request->get_param('version');
        $locale     = $request->get_param('locale');
        $feedback   = $request->get_param('feedback');
        $details    = $request->get_param('details');
        $site_url   = $request->get_param('site_url');

        // Store feedback.
        $feedback_entry = [
            'textdomain'   => $textdomain,
            'version'      => $version,
            'locale'       => $locale,
            'feedback'     => $feedback,
            'details'      => $details,
            'site_url'     => $site_url,
            'submitted_at' => current_time('mysql'),
            'ip_address'   => $this->get_client_ip(),
        ];

        // Store in database or send to analytics service.
        do_action('gratis_ai_ts_feedback_received', $feedback_entry);

        // Log feedback.
        $this->log_feedback($feedback_entry);

        return new \WP_REST_Response([
            'status'  => 'received',
            'message' => 'Thank you for your feedback',
        ], 200);
    }

    /**
     * Check rate limit permission.
     *
     * @since 1.0.0
     * @return bool|\WP_Error
     */
    public function check_rate_limit() {
        $ip_address = $this->get_client_ip();

        if ($this->rate_limiter->is_rate_limited($ip_address)) {
            $retry_after = $this->rate_limiter->get_retry_after($ip_address);

            return new \WP_Error(
                'rate_limit_exceeded',
                'Too many requests. Please try again later.',
                [
                    'status'      => 429,
                    'retry_after' => $retry_after,
                ]
            );
        }

        $this->rate_limiter->record_request($ip_address);

        return true;
    }

    /**
     * Validate textdomain.
     *
     * @since 1.0.0
     * @param string          $param   Parameter value.
     * @param \WP_REST_Request $request Request object.
     * @param string          $key     Parameter key.
     * @return bool|\WP_Error
     */
    public function validate_textdomain($param, \WP_REST_Request $request, string $key) {
        if (!preg_match('/^[a-z0-9_-]+$/i', $param)) {
            return new \WP_Error(
                'invalid_textdomain',
                'Invalid textdomain format',
                ['status' => 400]
            );
        }

        return true;
    }

    /**
     * Validate locales array.
     *
     * @since 1.0.0
     * @param array           $param   Parameter value.
     * @param \WP_REST_Request $request Request object.
     * @param string          $key     Parameter key.
     * @return bool|\WP_Error
     */
    public function validate_locales($param, \WP_REST_Request $request, string $key) {
        if (!is_array($param) || empty($param)) {
            return new \WP_Error(
                'invalid_locales',
                'Locales must be a non-empty array',
                ['status' => 400]
            );
        }

        foreach ($param as $locale) {
            if (!preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $locale)) {
                return new \WP_Error(
                    'invalid_locale',
                    "Invalid locale format: {$locale}",
                    ['status' => 400]
                );
            }
        }

        return true;
    }

    /**
     * Get supported locales.
     *
     * @since 1.0.0
     * @return array
     */
    private function get_supported_locales(): array {
        $locales = get_site_option('gratis_ai_ts_supported_locales', [
            'es_ES', 'de_DE', 'fr_FR', 'it_IT', 'pt_BR', 'nl_NL',
            'ru_RU', 'pl_PL', 'sv_SE', 'da_DK', 'fi_FI', 'hu_HU',
            'cs_CZ', 'ro_RO', 'tr_TR', 'el', 'zh_CN', 'ja',
        ]);

        return $locales;
    }

    /**
     * Calculate translation completeness.
     *
     * @since 1.0.0
     * @param array $job Job data.
     * @return int
     */
    private function calculate_completeness(array $job): int {
        if (empty($job['string_count']) || (int) $job['string_count'] === 0) {
            return 0;
        }

        return (int) round(((int) $job['translated_count'] / (int) $job['string_count']) * 100);
    }

    /**
     * Get translation progress.
     *
     * @since 1.0.0
     * @param array $job Job data.
     * @return int
     */
    private function get_translation_progress(array $job): int {
        return $this->calculate_completeness($job);
    }

    /**
     * Estimate completion time.
     *
     * @since 1.0.0
     * @param array $job Job data.
     * @return string
     */
    private function estimate_completion_time(array $job): string {
        if ($job['status'] === 'pending') {
            $queue = Translation_Queue::instance();
            $position = $queue->get_queue_position($job['id']);
            $minutes = $position * 2; // Rough estimate: 2 min per job.

            return sprintf('%d minutes', $minutes);
        }

        if ($job['status'] === 'processing') {
            $remaining = $job['string_count'] - $job['translated_count'];
            $rate = 10; // strings per minute.
            $minutes = (int) ceil($remaining / $rate);

            return sprintf('%d minutes', $minutes);
        }

        return '0 minutes';
    }

    /**
     * Estimate queue time for new jobs.
     *
     * @since 1.0.0
     * @return string
     */
    private function estimate_queue_time(): string {
        $queue = Translation_Queue::instance();
        $pending = $queue->get_pending_count();
        $processing = $queue->get_processing_count();
        $max_concurrent = get_site_option('gratis_ai_ts_max_concurrent_jobs', 3);

        $wait_jobs = max(0, $pending - $max_concurrent + $processing);
        $minutes = (int) ceil($wait_jobs * 5 / $max_concurrent);

        return sprintf('%d minutes', max(5, $minutes));
    }

    /**
     * Log API request.
     *
     * @since 1.0.0
     * @param string      $type       Request type.
     * @param string      $textdomain Plugin textdomain.
     * @param string      $version    Plugin version.
     * @param array       $locales    Requested locales.
     * @param string|null $site_url   Site URL.
     * @return void
     */
    private function log_request(string $type, string $textdomain, string $version, array $locales, ?string $site_url): void {
        do_action('gratis_ai_ts_api_request', [
            'type'       => $type,
            'textdomain' => $textdomain,
            'version'    => $version,
            'locales'    => $locales,
            'site_url'   => $site_url,
            'ip_address' => $this->get_client_ip(),
            'timestamp'  => current_time('mysql'),
        ]);
    }

    /**
     * Log feedback.
     *
     * @since 1.0.0
     * @param array $feedback Feedback data.
     * @return void
     */
    private function log_feedback(array $feedback): void {
        $log_dir  = WP_CONTENT_DIR . '/gratis-ai-logs';
        wp_mkdir_p( $log_dir );
        $log_file = $log_dir . '/feedback-' . date('Y-m') . '.jsonl';
        file_put_contents($log_file, wp_json_encode($feedback) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Get client IP address.
     *
     * @since 1.0.0
     * @return string
     */
    private function get_client_ip(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        }

        return sanitize_text_field($ip);
    }
}
