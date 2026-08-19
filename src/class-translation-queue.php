<?php
/**
 * Translation Queue class
 *
 * Manages the queue of translation jobs.
 *
 * @package GratisAITranslationsServer
 */

declare(strict_types=1);

namespace GratisAITranslationsServer;

/**
 * Translation Queue class.
 *
 * @since 1.0.0
 */
class Translation_Queue {

    /**
     * Instance of this class.
     *
     * @since 1.0.0
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Database table name.
     *
     * @since 1.0.0
     * @var string
     */
    private string $table_name;

    /**
     * Target request aggregate table name.
     *
     * @var string
     */
    private string $target_requests_table_name;

    /**
     * Maximum age for a processing job before the queue considers it stale.
     *
     * @since 1.1.1
     * @var int
     */
    private const STALE_PROCESSING_SECONDS = 7200;

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
        global $wpdb;
        $this->table_name                 = $wpdb->base_prefix . "gratis_ai_translation_jobs";
        $this->target_requests_table_name = $wpdb->base_prefix . "gratis_ai_translation_target_requests";
    }

    /**
     * Initialize hooks.
     *
     * @since 1.0.0
     * @return void
     */
    public function init(): void {
        add_action( 'gratis_ai_ts_process_queue', [ $this, 'process_queue' ] );
        add_action( 'gratis_ai_ts_cleanup_old_jobs', [ $this, 'cleanup_old_jobs' ] );
        add_action( 'gratis_ai_ts_generate_translation', [ $this, 'handle_generate_translation' ] );
        add_action( 'gratis_ai_ts_retry_transient_job', [ $this, 'handle_transient_retry' ], 10, 1 );

        // Schedule recurring actions after Action Scheduler's DB store initializes
        // (AS registers its store on the 'init' hook at priority 1).
        add_action( 'init', [ $this, 'ensure_scheduled_actions' ], 10 );
    }

    /**
     * Ensure recurring Action Scheduler actions exist.
     *
     * Hooked to 'init' at priority 10 (after AS's DB store at priority 1)
     * so the store is ready when we schedule. Runs on every page load to
     * self-heal after activation, database restores, or accidental deletion.
     *
     * @since 1.1.1
     * @return void
     */
    public function ensure_scheduled_actions(): void {
        if ( false === as_next_scheduled_action( 'gratis_ai_ts_process_queue', [], 'gratis_ai_ts' ) ) {
            as_schedule_recurring_action( time(), MINUTE_IN_SECONDS, 'gratis_ai_ts_process_queue', [], 'gratis_ai_ts' );
        }

        if ( false === as_next_scheduled_action( 'gratis_ai_ts_cleanup_old_jobs', [], 'gratis_ai_ts' ) ) {
            as_schedule_recurring_action( time(), DAY_IN_SECONDS, 'gratis_ai_ts_cleanup_old_jobs', [], 'gratis_ai_ts' );
        }
    }

    /**
     * Handle the async generate_translation action dispatched by Action Scheduler.
     *
     * @since 1.1.1
     * @param int $job_id Job ID.
     * @return void
     */
    public function handle_generate_translation( int $job_id ): void {
        Translation_Generator::instance()->generate_translation( $job_id );
    }

    /**
     * Move a transiently delayed job back to pending.
     *
     * @since 1.2.2
     * @param int $job_id Job ID.
     * @return void
     */
    public function handle_transient_retry( int $job_id ): void {
        global $wpdb;

        $wpdb->update(
            $this->table_name,
            [
                'status'        => 'pending',
                'started_at'    => null,
                'completed_at'  => null,
                'error_message' => null,
            ],
            [
                'id'     => $job_id,
                'status' => 'retrying',
            ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d', '%s' ]
        );
    }

    /**
     * Add a job to the queue.
     *
     * @since 1.0.0
     * @param string      $textdomain    Plugin/theme textdomain or slug.
     * @param string      $version       Plugin/theme version.
     * @param string      $locale        Target locale.
     * @param int         $priority      Job priority (1-10).
     * @param string      $requested_by  Who requested (user_locale, site_locale, manual, api).
     * @param string|null $source_site   Site URL that triggered the request.
     * @param string      $plugin_source Plugin/theme origin: 'wporg', 'premium', or 'unknown'.
     * @param string      $target_type   Target type: 'plugin' or 'theme'.
     * @return int|false Job ID or false on failure.
     */
    public function add_job(string $textdomain, string $version, string $locale, int $priority = 5, string $requested_by = 'api', ?string $source_site = null, string $plugin_source = 'unknown', string $target_type = 'plugin') {
        global $wpdb;

        $target_type   = self::normalize_target_type( $target_type );
        $plugin_source = self::normalize_plugin_source( $plugin_source );

        // Check if job already exists.
        $existing = $this->get_job($textdomain, $version, $locale, $target_type);

        if ($existing) {
            if ('failed' === $existing['status']) {
                $this->reset_failed_job(
                    (int) $existing['id'],
                    $priority,
                    $requested_by,
                    $source_site,
                    $plugin_source,
                    $target_type
                );

                return $existing['id'];
            }

            // Update priority if higher.
            if ($priority > $existing['priority']) {
                $wpdb->update(
                    $this->table_name,
                    ['priority' => $priority],
                    ['id' => $existing['id']],
                    ['%d'],
                    ['%d']
                );
            }

            return $existing['id'];
        }

        // Insert new job as 'requested' (needs approval).
        $result = $wpdb->insert(
            $this->table_name,
            [
                'target_type'  => $target_type,
                'textdomain'   => $textdomain,
                'version'     => $version,
                'locale'      => $locale,
                'priority'    => max(1, min(10, $priority)),
                'status'      => 'requested',
                'requested_by'  => $requested_by,
                'source_site'   => $source_site,
                'plugin_source' => $plugin_source,
                'created_at'   => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        if (false === $result) {
            return false;
        }

        $job_id = $wpdb->insert_id;

        // Note: we no longer auto-schedule on new requests.
        // Jobs wait for approval first.

        return $job_id;
    }

    /**
     * Record one API request for a target/version, independently of locales.
     *
     * @param string      $textdomain    Plugin or theme textdomain.
     * @param string      $version       Plugin or theme version.
     * @param string|null $source_site   Site URL that triggered the request.
     * @param string      $plugin_source Plugin or theme origin.
     * @param string      $target_type   Target type.
     * @return bool True when the aggregate was updated.
     */
    public function record_target_request(
        string $textdomain,
        string $version,
        ?string $source_site = null,
        string $plugin_source = "unknown",
        string $target_type = "plugin"
    ): bool {
        global $wpdb;

        $target_type   = self::normalize_target_type( $target_type );
        $plugin_source = self::normalize_plugin_source( $plugin_source );
        $requested_at  = current_time( "mysql" );

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$this->target_requests_table_name}
                    (target_type, textdomain, version, request_count, source_site, plugin_source, last_requested)
                VALUES (%s, %s, %s, 1, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    request_count = request_count + 1,
                    source_site = VALUES(source_site),
                    plugin_source = CASE
                        WHEN VALUES(plugin_source) = %s THEN plugin_source
                        ELSE VALUES(plugin_source)
                    END,
                    last_requested = VALUES(last_requested)",
                $target_type,
                $textdomain,
                $version,
                $source_site,
                $plugin_source,
                $requested_at,
                "unknown"
            )
        );

        return false !== $result;
    }
    /**
     * Get a job by target type, textdomain, version, and locale.
     *
     * @since 1.0.0
     * @param string $textdomain  Plugin/theme textdomain or slug.
     * @param string $version     Plugin/theme version.
     * @param string $locale      Target locale.
     * @param string $target_type Target type: 'plugin' or 'theme'.
     * @return array|null Job data or null.
     */
    public function get_job(string $textdomain, string $version, string $locale, string $target_type = 'plugin'): ?array {
        global $wpdb;

        $target_type = self::normalize_target_type( $target_type );

        $job = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                WHERE target_type = %s AND textdomain = %s AND version = %s AND locale = %s",
                $target_type,
                $textdomain,
                $version,
                $locale
            ),
            ARRAY_A
        );

        return $job ?: null;
    }

    /**
     * Get job by ID.
     *
     * @since 1.0.0
     * @param int $job_id Job ID.
     * @return array|null Job data or null.
     */
    public function get_job_by_id(int $job_id): ?array {
        global $wpdb;

        $job = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                $job_id
            ),
            ARRAY_A
        );

        return $job ?: null;
    }

    /**
     * Update job status.
     *
     * @since 1.0.0
     * @param int    $job_id Job ID.
     * @param string $status New status.
     * @param array  $data   Additional data to update.
     * @return bool True on success.
     */
    public function update_job_status(int $job_id, string $status, array $data = []): bool {
        global $wpdb;

        if ( 'completed' === $status ) {
            $this->clear_transient_retry_attempts( $job_id );
        }

        $update_data = ['status' => $status];
        $formats = ['%s'];

        if ($status === 'processing') {
            $update_data['started_at'] = current_time('mysql');
            $formats[] = '%s';
        } elseif ($status === 'completed' || $status === 'failed') {
            $update_data['completed_at'] = current_time('mysql');
            $formats[] = '%s';
        }

        // Merge additional data with proper format detection.
        // Integer columns need %d format; everything else uses %s.
        $int_columns = [ 'prompt_tokens', 'completion_tokens', 'string_count', 'translated_count', 'priority' ];

        foreach ( $data as $key => $value ) {
            if ( $key === 'status' ) {
                continue; // Already set above.
            }
            $update_data[ $key ] = $value;
            $formats[] = in_array( $key, $int_columns, true ) ? '%d' : '%s';
        }

        $result = $wpdb->update(
            $this->table_name,
            $update_data,
            ['id' => $job_id],
            $formats,
            ['%d']
        );

        return false !== $result;
    }

    /**
     * Get pending jobs count.
     *
     * @since 1.0.0
     * @return int Count.
     */
    public function get_pending_count(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'pending'"
        );
    }

    /**
     * Get requested jobs count (waiting for approval).
     *
     * @since 1.1.0
     * @return int Count.
     */
    public function get_requested_count(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'requested'"
        );
    }

    /**
     * Get available processing slots.
     *
     * @since 1.1.1
     * @return int Number of available slots.
     */
    public function get_available_slots(): int {
        $max_concurrent = (int) get_site_option( 'gratis_ai_ts_max_concurrent_jobs', 3 );
        $processing     = $this->get_processing_count();

        return max( 0, $max_concurrent - $processing );
    }

    /**
     * Get processing jobs count.
     *
     * @since 1.0.0
     * @return int Count.
     */
    public function get_processing_count(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'processing'"
        );
    }

    /**
     * Get completed jobs count for the current site day.
     *
     * @since 1.1.1
     * @return int Count.
     */
    public function get_completed_count_today(): int {
        global $wpdb;

        $start = new \DateTimeImmutable('today', wp_timezone());
        $end   = $start->modify('+1 day');

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name}
                WHERE status = 'completed'
                AND completed_at >= %s
                AND completed_at < %s",
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s')
            )
        );
    }

    /**
     * Mark long-running processing jobs failed so they do not block slots.
     *
     * @since 1.1.1
     * @param int $max_age_seconds Maximum processing age in seconds.
     * @return int Number of jobs marked failed.
     */
    public function mark_stale_processing_jobs_failed( int $max_age_seconds = self::STALE_PROCESSING_SECONDS ): int {
        global $wpdb;

        $max_age_seconds = max( 300, $max_age_seconds );
        $cutoff          = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $max_age_seconds );
        $completed_at    = current_time( 'mysql' );
        $message         = sprintf(
            'Job timed out after more than %d seconds in processing status and was marked failed automatically.',
            $max_age_seconds
        );

        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name}
                SET status = 'failed', completed_at = %s,
                    error_message = CASE
                        WHEN error_message IS NULL OR error_message = '' THEN %s
                        ELSE error_message
                    END
                WHERE status = 'processing'
                    AND (started_at IS NULL OR started_at < %s)",
                $completed_at,
                $message,
                $cutoff
            )
        );

        return false === $result ? 0 : (int) $result;
    }

    /**
     * Get count by status.
     *
     * @since 1.1.0
     * @return array Status counts.
     */
    public function get_counts_by_status(): array {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$this->table_name} GROUP BY status",
            ARRAY_A
        );

        $counts = [
            'requested' => 0,
            'pending'   => 0,
            'processing' => 0,
            'retrying'   => 0,
            'completed'  => 0,
            'failed'    => 0,
        ];

        foreach ($results as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int) $row['count'];
            }
        }

        return $counts;
    }

    /**
     * Get summaries grouped by locale.
     *
     * @since 1.1.0
     * @param string $status Filter by status, or empty for all.
     * @return array Locale summaries.
     */
    public function get_summaries_by_locale(string $status = ''): array {
        global $wpdb;

        $sql = "SELECT
            locale,
            status,
            COUNT(*) as count,
            SUM(string_count) as strings_total,
            SUM(translated_count) as translated_total,
            SUM(prompt_tokens) as prompt_tokens,
            SUM(completion_tokens) as completion_tokens,
            GROUP_CONCAT(CONCAT(COALESCE(target_type, 'plugin'), ':', textdomain, '@', version) ORDER BY created_at DESC) as plugins
            FROM {$this->table_name}";

        $params = [];
        if ($status) {
            $sql .= ' WHERE status = %s';
            $params[] = $status;
        }

        $sql .= ' GROUP BY locale, status ORDER BY locale, status';

        if ($params) {
            $results = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        } else {
            $results = $wpdb->get_results($sql, ARRAY_A);
        }

        // Group by locale.
        $by_locale = [];
        foreach ($results as $row) {
            $locale = $row['locale'];
            if (!isset($by_locale[$locale])) {
                $by_locale[$locale] = [
                    'locale' => $locale,
                    'requested' => 0,
                    'pending' => 0,
                    'processing' => 0,
                    'retrying' => 0,
                    'completed' => 0,
                    'failed' => 0,
                    'strings_total' => 0,
                    'translated_total' => 0,
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'plugins' => [],
                ];
            }

            $job_status = $row['status'];
            $by_locale[$locale][$job_status] = (int) $row['count'];
            $by_locale[$locale]['strings_total'] += (int) ($row['strings_total'] ?? 0);
            $by_locale[$locale]['translated_total'] += (int) ($row['translated_total'] ?? 0);
            $by_locale[$locale]['prompt_tokens'] += (int) ($row['prompt_tokens'] ?? 0);
            $by_locale[$locale]['completion_tokens'] += (int) ($row['completion_tokens'] ?? 0);

            if (!empty($row['plugins'])) {
                $by_locale[$locale]['plugins'] = array_merge(
                    $by_locale[$locale]['plugins'],
                    explode(',', $row['plugins'])
                );
            }
        }

        // Dedupe plugins.
        foreach ($by_locale as $locale => &$data) {
            $data['plugins'] = array_unique($data['plugins']);
            $data['total_jobs'] = array_sum([
                $data['requested'],
                $data['pending'],
                $data['processing'],
                $data['retrying'],
                $data['completed'],
                $data['failed'],
            ]);
        }

        return $by_locale;
    }

    /**
     * Get queue position for a job.
     *
     * @since 1.0.0
     * @param int $job_id Job ID.
     * @return int Position (1-based).
     */
    public function get_queue_position(int $job_id): int {
        global $wpdb;

        $job = $this->get_job_by_id($job_id);

        if (!$job || $job['status'] !== 'pending') {
            return 0;
        }

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name}
                WHERE status = 'pending'
                AND (priority > %d OR (priority = %d AND id < %d))",
                $job['priority'],
                $job['priority'],
                $job_id
            )
        );

        return (int) $count + 1;
    }

    /**
     * Get next pending job.
     *
     * @since 1.0.0
     * @param array $exclude_job_ids Job IDs to skip for this lookup.
     * @return array|null Job data or null.
     */
    public function get_next_pending_job(array $exclude_job_ids = []): ?array {
        global $wpdb;

        $exclude_job_ids = array_values(
            array_filter(
                array_map(
                    static function ( $job_id ): int {
                        return max( 0, (int) $job_id );
                    },
                    $exclude_job_ids
                )
            )
        );

        $exclude_sql = '';
        if ( ! empty( $exclude_job_ids ) ) {
            $exclude_sql = 'AND id NOT IN (' . implode( ',', $exclude_job_ids ) . ')';
        }

        $job = $wpdb->get_row(
            "SELECT * FROM {$this->table_name}
            WHERE status = 'pending'
            {$exclude_sql}
            ORDER BY priority DESC, created_at ASC
            LIMIT 1",
            ARRAY_A
        );

        return $job ?: null;
    }

    /**
     * Process the queue.
     *
     * @since 1.0.0
     * @return void
     */
    public function process_queue(): void {
        $this->mark_stale_processing_jobs_failed();

        $slots_available = $this->get_available_slots();

        if ( $slots_available <= 0 ) {
            return;
        }

        $processed_job_ids = [];

        for ($i = 0; $i < $slots_available; $i++) {
            $job = $this->get_next_pending_job( $processed_job_ids );

            if (!$job) {
                break;
            }

            $processed_job_ids[] = (int) $job['id'];

            // Mark as processing.
            $this->update_job_status((int) $job['id'], 'processing');

            // Process the job.
            $this->process_job((int) $job['id']);
        }
    }

    /**
     * Process a single job.
     *
     * Calls the translation generator directly instead of scheduling another
     * Action Scheduler hop. Since process_queue() already runs as an AS action,
     * the work executes within that same context — no need for a second action
     * that would require an additional AS run to pick up.
     *
     * @since 1.0.0
     * @param int $job_id Job ID.
     * @return void
     */
    private function process_job(int $job_id): void {
        Translation_Generator::instance()->generate_translation( $job_id );
    }

    /**
     * Requeue a partially processed job for a later Action Scheduler run.
     *
     * @since 1.2.1
     * @param int   $job_id Job ID.
     * @param array $data   Progress data to persist while requeueing.
     * @return bool True on success.
     */
    public function requeue_partial_job( int $job_id, array $data = [] ): bool {
        $data = array_merge(
            [
                'started_at'    => null,
                'completed_at'  => null,
                'error_message' => null,
            ],
            $data
        );

        $updated = $this->update_job_status( $job_id, 'pending', $data );

        if ( $updated && false === as_next_scheduled_action( 'gratis_ai_ts_process_queue', [], 'gratis_ai_ts' ) ) {
            as_schedule_single_action( time() + MINUTE_IN_SECONDS, 'gratis_ai_ts_process_queue', [], 'gratis_ai_ts' );
        }

        return $updated;
    }

    /**
     * Requeue a transient provider failure after a backoff delay.
     *
     * @since 1.2.2
     * @param int    $job_id        Job ID.
     * @param string $error_message Redacted provider error message.
     * @param array  $data          Progress data to persist while retrying.
     * @return bool True when retry was scheduled, false when retry budget is exhausted.
     */
    public function requeue_transient_failure( int $job_id, string $error_message, array $data = [] ): bool {
        $attempt = $this->increment_transient_retry_attempt( $job_id );
        $delay   = $this->get_transient_retry_delay( $attempt );

        if ( $delay <= 0 ) {
            return false;
        }

        $data = array_merge(
            [
                'started_at'    => null,
                'completed_at'  => null,
                'error_message' => sprintf(
                    'Transient provider error; retry %d scheduled in %d seconds: %s',
                    $attempt,
                    $delay,
                    $error_message
                ),
            ],
            $data
        );

        $updated = $this->update_job_status( $job_id, 'retrying', $data );

        if ( $updated ) {
            as_schedule_single_action( time() + $delay, 'gratis_ai_ts_retry_transient_job', [ $job_id ], 'gratis_ai_ts' );
        }

        return $updated;
    }

    /**
     * Increment and persist transient retry attempts for a job.
     *
     * @since 1.2.2
     * @param int $job_id Job ID.
     * @return int Current attempt number.
     */
    private function increment_transient_retry_attempt( int $job_id ): int {
        $attempts = get_site_option( 'gratis_ai_ts_transient_retry_attempts', [] );

        if ( ! is_array( $attempts ) ) {
            $attempts = [];
        }

        $key             = (string) $job_id;
        $attempts[$key] = max( 0, (int) ( $attempts[$key] ?? 0 ) ) + 1;

        update_site_option( 'gratis_ai_ts_transient_retry_attempts', $attempts );

        return (int) $attempts[$key];
    }

    /**
     * Clear persisted transient retry attempts for a job.
     *
     * @since 1.2.2
     * @param int $job_id Job ID.
     * @return void
     */
    private function clear_transient_retry_attempts( int $job_id ): void {
        $attempts = get_site_option( 'gratis_ai_ts_transient_retry_attempts', [] );

        if ( ! is_array( $attempts ) ) {
            return;
        }

        $key = (string) $job_id;

        if ( ! array_key_exists( $key, $attempts ) ) {
            return;
        }

        unset( $attempts[$key] );
        update_site_option( 'gratis_ai_ts_transient_retry_attempts', $attempts );
    }

    /**
     * Get retry delay for a transient provider failure.
     *
     * @since 1.2.2
     * @param int $attempt Attempt number.
     * @return int Delay in seconds, or 0 when retry budget is exhausted.
     */
    private function get_transient_retry_delay( int $attempt ): int {
        $delays = [
            1 => 2 * MINUTE_IN_SECONDS,
            2 => 5 * MINUTE_IN_SECONDS,
            3 => 15 * MINUTE_IN_SECONDS,
            4 => HOUR_IN_SECONDS,
        ];

        return (int) ( $delays[$attempt] ?? 0 );
    }

    /**
     * Get all jobs with status.
     *
     * @since 1.0.0
     * @param string $status    Job status filter.
     * @param int    $limit     Maximum number of jobs.
     * @param int    $offset    Offset for pagination.
     * @return array Array of jobs.
     */
    public function get_jobs(string $status = '', int $limit = 50, int $offset = 0): array {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table_name}";
        $params = [];

        if ($status) {
            $sql .= ' WHERE status = %s';
            $params[] = $status;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;

        if ($params) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Get queue rows grouped by target type and textdomain.
     *
     * @param string $status Job status filter.
     * @param string $source Target source filter.
     * @param string $search Textdomain search query.
     * @param int    $limit Maximum targets to return.
     * @param int    $offset Pagination offset.
     * @return array<int,array<string,mixed>> Target summaries.
     */
    public function get_target_summaries( string $status = "requested", string $source = "", string $search = "", int $limit = 20, int $offset = 0 ): array {
        global $wpdb;

        [ $sql, $params ] = $this->get_target_summary_query( $status, $source, $search );
        $params[] = max( 1, $limit );
        $params[] = max( 0, $offset );
        $sql .= " ORDER BY request_count DESC, last_requested DESC, textdomain ASC LIMIT %d OFFSET %d";

        return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];
    }

    /**
     * Count grouped queue targets for pagination.
     *
     * @param string $status Job status filter.
     * @param string $source Target source filter.
     * @param string $search Textdomain search query.
     * @return int Target summary count.
     */
    public function get_target_summary_count( string $status = "requested", string $source = "", string $search = "" ): int {
        global $wpdb;

        [ $sql, $params ] = $this->get_target_summary_query( $status, $source, $search );
        $count_sql = "SELECT COUNT(*) FROM ({$sql}) AS target_summaries";

        return (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );
    }

    /**
     * Build the query shared by grouped target list and count lookups.
     *
     * @param string $status Job status filter.
     * @param string $source Target source filter.
     * @param string $search Textdomain search query.
     * @return array{0:string,1:array<int,string>} SQL and prepare parameters.
     */
    private function get_target_summary_query( string $status, string $source, string $search ): array {
        global $wpdb;

        $statuses = [ "requested", "pending", "processing", "retrying", "completed", "failed" ];
        $where = [];
        $having = [];
        $params = [ "requested", "requested", "pending", "processing", "retrying", "completed", "failed", "unknown" ];

        if ( "" !== $search ) {
            $where[] = "j.textdomain LIKE %s";
            $params[] = "%" . $wpdb->esc_like( $search ) . "%";
        }

        if ( in_array( $status, $statuses, true ) ) {
            $having[] = "SUM(j.status = %s) > 0";
            $params[] = $status;
        }

        if ( "" !== $source && in_array( $source, [ "wporg", "premium", "custom", "unknown" ], true ) ) {
            $having[] = "FIND_IN_SET(%s, source_values) > 0";
            $params[] = $source;
        }

        $sql = "SELECT j.target_type, j.textdomain, COALESCE(MAX(r.request_count), 0) AS request_count, COALESCE(MAX(r.last_requested), MAX(j.created_at)) AS last_requested, GROUP_CONCAT(DISTINCT j.version ORDER BY j.version) AS versions, GROUP_CONCAT(DISTINCT CASE WHEN j.status = %s THEN j.locale END ORDER BY j.locale) AS requested_locales, SUM(j.status = %s) AS requested_count, SUM(j.status = %s) AS pending_count, SUM(j.status = %s) AS processing_count, SUM(j.status = %s) AS retrying_count, SUM(j.status = %s) AS completed_count, SUM(j.status = %s) AS failed_count, COUNT(*) AS locale_count, COALESCE(MAX(r.source_values), %s) AS source_values FROM {$this->table_name} AS j LEFT JOIN ( SELECT target_type, textdomain, SUM(request_count) AS request_count, MAX(last_requested) AS last_requested, CASE WHEN SUM(plugin_source = \"custom\") > 0 THEN \"custom\" WHEN SUM(plugin_source = \"premium\") > 0 THEN \"premium\" WHEN SUM(plugin_source = \"unknown\") > 0 THEN \"unknown\" ELSE \"wporg\" END AS source_values FROM {$this->target_requests_table_name} GROUP BY target_type, textdomain ) AS r ON r.target_type = j.target_type AND r.textdomain = j.textdomain";

        if ( ! empty( $where ) ) {
            $sql .= " WHERE " . implode( " AND ", $where );
        }

        $sql .= " GROUP BY j.target_type, j.textdomain";

        if ( ! empty( $having ) ) {
            $sql .= " HAVING " . implode( " AND ", $having );
        }

        return [ $sql, $params ];
    }

    /**
     * Cleanup old completed jobs.
     *
     * @since 1.0.0
     * @param int $days_keep Number of days to keep completed jobs.
     * @return int Number of jobs deleted.
     */
    public function cleanup_old_jobs(int $days_keep = 30): int {
        global $wpdb;

        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_keep} days"));

        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name}
                WHERE status = 'completed' AND completed_at < %s",
                $cutoff_date
            )
        );

        return (int) $result;
    }

    /**
     * Retry a failed job.
     *
     * @since 1.0.0
     * @param int $job_id Job ID.
     * @return bool True on success.
     */
    public function retry_job(int $job_id): bool {
        $this->clear_transient_retry_attempts( $job_id );

        return $this->update_job_status($job_id, 'requested', [
            'error_message' => null,
            'started_at'    => null,
            'completed_at'  => null,
        ]);
    }

    /**
     * Reset a failed job so a new API request can queue it again.
     *
     * @since 1.1.1
     * @param int         $job_id        Job ID.
     * @param int         $priority      Requested priority.
     * @param string      $requested_by  Request source.
     * @param string|null $source_site   Site URL that triggered the request.
     * @param string      $plugin_source Plugin/theme source.
     * @param string      $target_type   Target type: 'plugin' or 'theme'.
     * @return bool True on success.
     */
    private function reset_failed_job(
        int $job_id,
        int $priority,
        string $requested_by,
        ?string $source_site,
        string $plugin_source,
        string $target_type
    ): bool {
        global $wpdb;

        $target_type = self::normalize_target_type( $target_type );

        $this->clear_transient_retry_attempts( $job_id );

        $result = $wpdb->update(
            $this->table_name,
            [
                'target_type'       => $target_type,
                'status'            => 'requested',
                'priority'          => max( 1, min( 10, $priority ) ),
                'requested_by'      => $requested_by,
                'source_site'       => $source_site,
                'plugin_source'     => $plugin_source,
                'started_at'        => null,
                'completed_at'      => null,
                'error_message'     => null,
                'translated_count'  => 0,
                'prompt_tokens'     => 0,
                'completion_tokens' => 0,
            ],
            ['id' => $job_id],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d'],
            ['%d']
        );

        return false !== $result;
    }

    /**
     * Approve a requested job, moving it to pending.
     *
     * @since 1.1.0
     * @param int $job_id Job ID.
     * @return bool True on success.
     */
    public function approve_job(int $job_id): bool {
        global $wpdb;

        $result = $wpdb->update(
            $this->table_name,
            ['status' => 'pending'],
            ['id' => $job_id, 'status' => 'requested'],
            ['%s'],
            ['%d', '%s']
        );

        if (false === $result) {
            return false;
        }

        // Trigger queue processing if there's now pending work.
        if ( $this->get_pending_count() > 0 ) {
            if ( false === as_next_scheduled_action( 'gratis_ai_ts_process_queue', [], 'gratis_ai_ts' ) ) {
                as_schedule_single_action( time(), 'gratis_ai_ts_process_queue', [], 'gratis_ai_ts' );
            }
        }

        return true;
    }

    /**
     * Approve all requested jobs for a specific locale.
     *
     * @since 1.1.0
     * @param string $locale Locale to approve.
     * @return int Number of jobs approved.
     */
    public function approve_all_by_locale(string $locale): int {
        global $wpdb;

        $result = $wpdb->update(
            $this->table_name,
            ['status' => 'pending'],
            ['locale' => $locale, 'status' => 'requested'],
            ['%s'],
            ['%s', '%s']
        );

        // Trigger queue processing.
        if ( $this->get_pending_count() > 0 ) {
            if ( false === as_next_scheduled_action( 'gratis_ai_ts_process_queue', [], 'gratis_ai_ts' ) ) {
                as_schedule_single_action( time(), 'gratis_ai_ts_process_queue', [], 'gratis_ai_ts' );
            }
        }

        return $result !== false ? $result : 0;
    }

    /**
     * Approve all requested jobs.
     *
     * @since 1.1.0
     * @return int Number of jobs approved.
     */
    public function approve_all(): int {
        global $wpdb;

        $result = $wpdb->update(
            $this->table_name,
            ['status' => 'pending'],
            ['status' => 'requested'],
            ['%s'],
            ['%s']
        );

        // Trigger queue processing.
        if ( $this->get_pending_count() > 0 ) {
            if ( false === as_next_scheduled_action( 'gratis_ai_ts_process_queue', [], 'gratis_ai_ts' ) ) {
                as_schedule_single_action( time(), 'gratis_ai_ts_process_queue', [], 'gratis_ai_ts' );
            }
        }

        return $result !== false ? $result : 0;
    }

    /**
     * Reject (delete) a requested job.
     *
     * @since 1.1.0
     * @param int $job_id Job ID.
     * @return bool True on success.
     */
    public function reject_job(int $job_id): bool {
        return $this->delete_job($job_id);
    }

    /**
     * Reject all requested jobs for a locale.
     *
     * @since 1.1.0
     * @param string $locale Locale to reject.
     * @return int Number of jobs deleted.
     */
    public function reject_all_by_locale(string $locale): int {
        global $wpdb;

        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE locale = %s AND status = 'requested'",
                $locale
            )
        );

        return (int) $result;
    }

    /**
     * Delete a job.
     *
     * @since 1.0.0
     * @param int $job_id Job ID.
     * @return bool True on success.
     */
    public function delete_job(int $job_id): bool {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table_name,
            ['id' => $job_id],
            ['%d']
        );

        return false !== $result;
    }

    /**
     * Delete all jobs with a given status.
     *
     * @since 1.1.0
     * @param string $status Status to delete.
     * @return int Number of jobs deleted.
     */
    public function delete_all_by_status(string $status): int {
        global $wpdb;

        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE status = %s",
                $status
            )
        );

        return (int) $result;
    }

    /**
     * Approve every requested locale and version for a target.
     *
     * @param string $textdomain Target textdomain.
     * @param string $target_type Target type.
     * @return int Number of jobs approved.
     */
    public function approve_target( string $textdomain, string $target_type = "plugin" ): int {
        global $wpdb;

        $textdomain = trim( $textdomain );
        if ( "" === $textdomain ) {
            return 0;
        }

        $target_type = self::normalize_target_type( $target_type );

        $result = $wpdb->update(
            $this->table_name,
            [ "status" => "pending" ],
            [
                "target_type" => $target_type,
                "textdomain"  => $textdomain,
                "status"      => "requested",
            ],
            [ "%s" ],
            [ "%s", "%s", "%s" ]
        );

        if ( $result > 0 ) {
            $this->schedule_queue_processing();
        }

        return false === $result ? 0 : (int) $result;
    }

    /**
     * Dismiss every requested locale and version for a target.
     *
     * @param string $textdomain Target textdomain.
     * @param string $target_type Target type.
     * @return int Number of jobs dismissed.
     */
    public function reject_target( string $textdomain, string $target_type = "plugin" ): int {
        global $wpdb;

        $textdomain = trim( $textdomain );
        if ( "" === $textdomain ) {
            return 0;
        }

        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE target_type = %s AND textdomain = %s AND status = %s",
                self::normalize_target_type( $target_type ),
                $textdomain,
                "requested"
            )
        );

        return false === $result ? 0 : (int) $result;
    }

    /**
     * Return all failed locales and versions for a target to requested review.
     *
     * @param string $textdomain Target textdomain.
     * @param string $target_type Target type.
     * @return int Number of jobs queued for review.
     */
    public function retry_failed_target( string $textdomain, string $target_type = "plugin" ): int {
        global $wpdb;

        $textdomain = trim( $textdomain );
        if ( "" === $textdomain ) {
            return 0;
        }

        $target_type = self::normalize_target_type( $target_type );
        $job_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE target_type = %s AND textdomain = %s AND status = %s",
                $target_type,
                $textdomain,
                "failed"
            )
        );

        $result = $wpdb->update(
            $this->table_name,
            [
                "status"        => "requested",
                "started_at"    => null,
                "completed_at"  => null,
                "error_message" => null,
            ],
            [
                "target_type" => $target_type,
                "textdomain"  => $textdomain,
                "status"      => "failed",
            ],
            [ "%s", "%s", "%s", "%s" ],
            [ "%s", "%s", "%s" ]
        );

        if ( false !== $result ) {
            foreach ( $job_ids as $job_id ) {
                $this->clear_transient_retry_attempts( (int) $job_id );
            }
        }

        return false === $result ? 0 : (int) $result;
    }

    /**
     * Schedule processing after target-level approval.
     *
     * @return void
     */
    private function schedule_queue_processing(): void {
        if ( false === as_next_scheduled_action( "gratis_ai_ts_process_queue", [], "gratis_ai_ts" ) ) {
            as_schedule_single_action( time(), "gratis_ai_ts_process_queue", [], "gratis_ai_ts" );
        }
    }

    /**
     * Normalize a target type for queue storage and lookup.
     *
     * @since 1.2.0
     * @param string|null $target_type Candidate target type.
     * @return string Normalized target type.
     */
    public static function normalize_target_type( ?string $target_type ): string {
        $target_type = strtolower( trim( (string) $target_type ) );

        return in_array( $target_type, [ 'plugin', 'theme' ], true ) ? $target_type : 'plugin';
    }
    /**
     * Normalize plugin provenance for storage and filtering.
     *
     * @param string|null $plugin_source Candidate source value.
     * @return string Normalized source value.
     */
    public static function normalize_plugin_source( ?string $plugin_source ): string {
        $plugin_source = strtolower( trim( (string) $plugin_source ) );

        return in_array( $plugin_source, [ "wporg", "premium", "custom" ], true ) ? $plugin_source : "unknown";
    }
}
