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
        $this->table_name = $wpdb->base_prefix . 'gratis_ai_translation_jobs';
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
            as_schedule_recurring_action( time(), 5 * MINUTE_IN_SECONDS, 'gratis_ai_ts_process_queue', [], 'gratis_ai_ts' );
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
     * Add a job to the queue.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @param string $version    Plugin version.
     * @param string $locale     Target locale.
     * @param int    $priority   Job priority (1-10).
     * @param string $requested_by Who requested (user_locale, site_locale, manual, api).
     * @param string $source_site Site URL that triggered the request.
     * @return int|false Job ID or false on failure.
     */
    public function add_job(string $textdomain, string $version, string $locale, int $priority = 5, string $requested_by = 'api', ?string $source_site = null) {
        global $wpdb;

        // Check if job already exists.
        $existing = $this->get_job($textdomain, $version, $locale);

        if ($existing) {
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
                'textdomain'   => $textdomain,
                'version'     => $version,
                'locale'      => $locale,
                'priority'    => max(1, min(10, $priority)),
                'status'      => 'requested',
                'requested_by' => $requested_by,
                'source_site'  => $source_site,
                'created_at'  => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
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
     * Get a job by textdomain, version, and locale.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @param string $version    Plugin version.
     * @param string $locale     Target locale.
     * @return array|null Job data or null.
     */
    public function get_job(string $textdomain, string $version, string $locale): ?array {
        global $wpdb;

        $job = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} 
                WHERE textdomain = %s AND version = %s AND locale = %s",
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

        $update_data = ['status' => $status];
        $formats = ['%s'];

        if ($status === 'processing') {
            $update_data['started_at'] = current_time('mysql');
            $formats[] = '%s';
        } elseif ($status === 'completed' || $status === 'failed') {
            $update_data['completed_at'] = current_time('mysql');
            $formats[] = '%s';
        }

        // Handle token tracking.
        if (isset($data['prompt_tokens'])) {
            $update_data['prompt_tokens'] = $data['prompt_tokens'];
            $formats[] = '%d';
        }
        if (isset($data['completion_tokens'])) {
            $update_data['completion_tokens'] = $data['completion_tokens'];
            $formats[] = '%d';
        }

        // Merge additional data.
        foreach ($data as $key => $value) {
            $update_data[$key] = $value;
            $formats[] = is_int($value) ? '%d' : '%s';
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
            GROUP_CONCAT(CONCAT(textdomain, '@', version) ORDER BY created_at DESC) as plugins
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
     * @return array|null Job data or null.
     */
    public function get_next_pending_job(): ?array {
        global $wpdb;

        $job = $wpdb->get_row(
            "SELECT * FROM {$this->table_name} 
            WHERE status = 'pending' 
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
        $slots_available = $this->get_available_slots();

        if ( $slots_available <= 0 ) {
            return;
        }

        for ($i = 0; $i < $slots_available; $i++) {
            $job = $this->get_next_pending_job();

            if (!$job) {
                break;
            }

            // Mark as processing.
            $this->update_job_status((int) $job['id'], 'processing');

            // Process the job.
            $this->process_job((int) $job['id']);
        }
    }

    /**
     * Process a single job.
     *
     * @since 1.0.0
     * @param int $job_id Job ID.
     * @return void
     */
    private function process_job(int $job_id): void {
        as_schedule_single_action(
            time(),
            'gratis_ai_ts_generate_translation',
            [ 'job_id' => $job_id ],
            'gratis_ai_ts'
        );
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
        return $this->update_job_status($job_id, 'requested', [
            'error_message' => null,
            'started_at'    => null,
            'completed_at'  => null,
        ]);
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
}
