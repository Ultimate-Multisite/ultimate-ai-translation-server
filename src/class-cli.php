<?php
/**
 * WP-CLI Commands for Gratis AI Translations Server
 *
 * @package GratisAITranslationsServer
 */

declare(strict_types=1);

namespace GratisAITranslationsServer;

/**
 * WP-CLI commands class.
 *
 * @since 1.0.0
 */
class CLI {

    /**
     * Translation queue instance.
     *
     * @since 1.0.0
     * @var Translation_Queue
     */
    private Translation_Queue $queue;

    /**
     * Translation generator instance.
     *
     * @since 1.0.0
     * @var Translation_Generator
     */
    private Translation_Generator $generator;

    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->queue     = Translation_Queue::instance();
        $this->generator = Translation_Generator::instance();
    }

    /**
     * Display server status and statistics.
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-server status
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function status(array $args, array $assoc_args): void {
        global $wpdb;

        $table_name = $wpdb->base_prefix . 'gratis_ai_translation_jobs';

        $pending    = $this->queue->get_pending_count();
        $processing = $this->queue->get_processing_count();
        $completed  = $this->queue->get_completed_count_today();
        $total      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

        \WP_CLI::log('=== Gratis AI Translations Server ===');
        \WP_CLI::log('');
        \WP_CLI::log("Pending Jobs:    {$pending}");
        \WP_CLI::log("Processing:      {$processing}");
        \WP_CLI::log("Completed Today: {$completed}");
        \WP_CLI::log("Total Jobs:      {$total}");
        \WP_CLI::log('');

        // Check OpenAI configuration.
        $api_key = get_site_option('gratis_ai_ts_openai_api_key');
        if (empty($api_key)) {
            \WP_CLI::warning('OpenAI API key is not configured!');
        } else {
            \WP_CLI::success('OpenAI API key is configured');
        }
    }

    /**
     * List translation jobs.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Filter by status (pending, processing, completed, failed).
     *
     * [--limit=<limit>]
     * : Number of jobs to show. Default: 20.
     *
     * [--format=<format>]
     * : Output format. Accepts: table, json, csv, yaml. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-server list
     *     wp gratis-ai-server list --status=pending
     *     wp gratis-ai-server list --limit=50 --format=json
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function list(array $args, array $assoc_args): void {
        $status = $assoc_args['status'] ?? '';
        $limit  = (int) ($assoc_args['limit'] ?? 20);
        $format = $assoc_args['format'] ?? 'table';

        $jobs = $this->queue->get_jobs($status, $limit);

        if (empty($jobs)) {
            \WP_CLI::log('No jobs found.');
            return;
        }

        $formatter = new \WP_CLI\Formatter(
            $assoc_args,
            ['id', 'textdomain', 'version', 'locale', 'status', 'priority', 'created_at'],
            'jobs'
        );

        $formatter->display_items($jobs);
    }

    /**
     * Add a translation job to the queue.
     *
     * ## OPTIONS
     *
     * <textdomain>
     * : Plugin textdomain.
     *
     * <version>
     * : Plugin version.
     *
     * <locale>
     * : Target locale.
     *
     * [--priority=<priority>]
     * : Job priority (1-10). Default: 5.
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-server add woocommerce 8.2.0 es_ES
     *     wp gratis-ai-server add woocommerce 8.2.0 de_DE --priority=10
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function add(array $args, array $assoc_args): void {
        $textdomain = $args[0];
        $version    = $args[1];
        $locale     = $args[2];
        $priority   = (int) ($assoc_args['priority'] ?? 5);

        $job_id = $this->queue->add_job($textdomain, $version, $locale, $priority);

        if ($job_id) {
            \WP_CLI::success("Job added with ID: {$job_id}");
        } else {
            \WP_CLI::error('Failed to add job');
        }
    }

    /**
     * Process translation jobs from the queue.
     *
     * ## OPTIONS
     *
     * [--limit=<limit>]
     * : Number of jobs to process. Default: 1.
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-server process
     *     wp gratis-ai-server process --limit=5
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function process(array $args, array $assoc_args): void {
        $limit = (int) ($assoc_args['limit'] ?? 1);

        for ($i = 0; $i < $limit; $i++) {
            $job = $this->queue->get_next_pending_job();

            if (!$job) {
                if ($i === 0) {
                    \WP_CLI::log('No pending jobs to process.');
                } else {
                    \WP_CLI::log('No more pending jobs.');
                }
                return;
            }

            \WP_CLI::log("Processing job {$job['id']}: {$job['textdomain']} {$job['version']} ({$job['locale']})");

            $this->queue->update_job_status((int) $job['id'], 'processing');
            $result = $this->generator->generate_translation((int) $job['id']);

            if ($result) {
                \WP_CLI::success("Job {$job['id']} completed successfully");
            } else {
                \WP_CLI::error("Job {$job['id']} failed");
            }
        }
    }

    /**
     * Retry a failed job.
     *
     * ## OPTIONS
     *
     * <job_id>
     * : Job ID to retry.
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-server retry 123
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function retry(array $args, array $assoc_args): void {
        $job_id = (int) $args[0];

        $job = $this->queue->get_job_by_id($job_id);

        if (!$job) {
            \WP_CLI::error("Job {$job_id} not found");
            return;
        }

        if ($job['status'] !== 'failed') {
            \WP_CLI::warning("Job {$job_id} is not in failed status (current: {$job['status']})");
            return;
        }

        $result = $this->queue->retry_job($job_id);

        if ($result) {
            \WP_CLI::success("Job {$job_id} queued for retry");
        } else {
            \WP_CLI::error("Failed to retry job {$job_id}");
        }
    }

    /**
     * Delete a job.
     *
     * ## OPTIONS
     *
     * <job_id>
     * : Job ID to delete.
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-server delete 123
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function delete(array $args, array $assoc_args): void {
        $job_id = (int) $args[0];

        $result = $this->queue->delete_job($job_id);

        if ($result) {
            \WP_CLI::success("Job {$job_id} deleted");
        } else {
            \WP_CLI::error("Failed to delete job {$job_id}");
        }
    }

    /**
     * Clean up old completed jobs.
     *
     * ## OPTIONS
     *
     * [--days=<days>]
     * : Delete jobs older than this many days. Default: 30.
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-server cleanup
     *     wp gratis-ai-server cleanup --days=7
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function cleanup(array $args, array $assoc_args): void {
        $days = (int) ($assoc_args['days'] ?? 30);

        $count = $this->queue->cleanup_old_jobs($days);

        \WP_CLI::success("Deleted {$count} old jobs");
    }

    /**
     * Build a translation package.
     *
     * ## OPTIONS
     *
     * <textdomain>
     * : Plugin textdomain.
     *
     * <version>
     * : Plugin version.
     *
     * <locale>
     * : Target locale.
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-server build woocommerce 8.2.0 es_ES
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function build(array $args, array $assoc_args): void {
        $textdomain = $args[0];
        $version    = $args[1];
        $locale     = $args[2];

        \WP_CLI::log("Building package for {$textdomain} {$version} ({$locale})...");

        $builder = Package_Builder::instance();
        $result  = $builder->build_package($textdomain, $version, $locale);

        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
            return;
        }

        \WP_CLI::success("Package built: {$result}");
    }
}
