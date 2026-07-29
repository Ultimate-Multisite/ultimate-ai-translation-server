<?php
/**
 * WP-CLI Commands for Gratis AI Translations Server
 *
 * @package GratisAITranslationsServer
 */

declare(strict_types=1);

namespace GratisAITranslationsServer;

/**
 * Manage AI translation jobs.
 *
 * @since 1.0.0
 */
class CLI {

    /**
     * Translation queue instance.
     *
     * @var Translation_Queue
     */
    private Translation_Queue $queue;

    /**
     * Translation generator instance.
     *
     * @var Translation_Generator
     */
    private Translation_Generator $generator;

    /**
     * Constructor.
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
     *     wp gratis-ai-server status --check-provider
     *
     * ## OPTIONS
     *
     * [--check-provider]
     * : Run the Superdav remote status check when Superdav is active.
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

        $provider_status = $this->generator->get_provider_status( ! empty( $assoc_args['check-provider'] ) );
        $superdav        = $provider_status['superdav'];

        \WP_CLI::log("Preferred Provider: {$provider_status['preferred_provider']}");
        \WP_CLI::log("Active Provider:    {$provider_status['active_provider']}");

        if ( ! empty( $provider_status['fallback_message'] ) ) {
            \WP_CLI::warning( (string) $provider_status['fallback_message'] );
        }

        \WP_CLI::log('');
        \WP_CLI::log('Superdav AI Service:');
        \WP_CLI::log('  Configured: ' . ( $superdav['configured'] ? 'yes' : 'no' ));
        \WP_CLI::log('  Base URL:   ' . ( $superdav['base_url'] ?: '(not set)' ));
        \WP_CLI::log('  Model:      ' . ( $superdav['model'] ?: '(not set)' ));
        \WP_CLI::log('  Token:      ' . ( $superdav['token_configured'] ? 'configured via ' . $superdav['token_source'] : 'not configured' ));

        if ( isset( $provider_status['superdav_remote'] ) ) {
            if ( $provider_status['superdav_remote']['ok'] ) {
                \WP_CLI::success('Superdav status check: ok');
            } else {
                \WP_CLI::warning('Superdav status check: ' . $provider_status['superdav_remote']['message']);
            }
        }

        $gp = $provider_status['gp_openai_translate'];
        \WP_CLI::log('');
        \WP_CLI::log('GP OpenAI Translate:');
        \WP_CLI::log('  Available: ' . ( $gp['available'] ? 'yes' : 'no' ));
        if ( ! empty( $gp['model'] ) ) {
            \WP_CLI::log('  Model:     ' . $gp['model']);
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
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function list(array $args, array $assoc_args): void {
        $status = $assoc_args['status'] ?? '';
        $limit  = (int) ($assoc_args['limit'] ?? 20);

        $jobs = $this->queue->get_jobs($status, $limit);

        if (empty($jobs)) {
            \WP_CLI::log('No jobs found.');
            return;
        }

        $formatter = new \WP_CLI\Formatter(
            $assoc_args,
            ['id', 'target_type', 'textdomain', 'version', 'locale', 'status', 'priority', 'created_at'],
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
     * : Plugin/theme textdomain or slug.
     *
     * <version>
     * : Plugin/theme version.
     *
     * <locale>
     * : Target locale.
     *
     * [--priority=<priority>]
     * : Job priority (1-10). Default: 5.
     *
     * [--target-type=<target-type>]
     * : Target type: plugin or theme. Default: plugin.
     *
     * [--source=<source>]
     * : Target source/origin. Default: unknown.
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
        $target_type = Translation_Queue::normalize_target_type( (string) ( $assoc_args['target-type'] ?? 'plugin' ) );
        $source      = sanitize_text_field( (string) ( $assoc_args['source'] ?? 'unknown' ) );

        $job_id = $this->queue->add_job($textdomain, $version, $locale, $priority, 'manual', null, $source, $target_type);

        if ($job_id) {
            \WP_CLI::success("{$target_type} job added with ID: {$job_id}");
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
        $limit             = (int) ($assoc_args['limit'] ?? 1);
        $processed_job_ids = [];

        for ($i = 0; $i < $limit; $i++) {
            $job = $this->queue->get_next_pending_job( $processed_job_ids );

            if (!$job) {
                \WP_CLI::log($i === 0 ? 'No pending jobs to process.' : 'No more pending jobs.');
                return;
            }

            $processed_job_ids[] = (int) $job['id'];

            $target_type = $job['target_type'] ?? 'plugin';
            \WP_CLI::log("Processing job {$job['id']}: {$target_type} {$job['textdomain']} {$job['version']} ({$job['locale']})");

            $this->queue->update_job_status((int) $job['id'], 'processing');
            $result = $this->generator->generate_translation((int) $job['id']);
            $updated_job = $this->queue->get_job_by_id( (int) $job['id'] );

            if ($result && $updated_job && 'completed' === $updated_job['status']) {
                \WP_CLI::success("Job {$job['id']} completed successfully");
            } elseif ($result && $updated_job && 'pending' === $updated_job['status']) {
                \WP_CLI::success("Job {$job['id']} processed a chunk and was requeued");
            } elseif ($result && $updated_job && 'retrying' === $updated_job['status']) {
                \WP_CLI::success("Job {$job['id']} hit a transient provider error and was scheduled for retry");
            } else {
                \WP_CLI::error("Job {$job['id']} failed", false);
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
        $job    = $this->queue->get_job_by_id($job_id);

        if (!$job) {
            \WP_CLI::error("Job {$job_id} not found");
            return;
        }

        if ($job['status'] !== 'failed') {
            \WP_CLI::warning("Job {$job_id} is not in failed status (current: {$job['status']})");
            return;
        }

        if ($this->queue->retry_job($job_id)) {
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

        if ($this->queue->delete_job($job_id)) {
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
        $days  = (int) ($assoc_args['days'] ?? 30);
        $count = $this->queue->cleanup_old_jobs($days);

        \WP_CLI::success("Deleted {$count} old jobs");
    }
}
