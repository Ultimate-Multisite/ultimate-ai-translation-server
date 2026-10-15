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
     * : Plugin/theme textdomain or slug, or `wordpress` for core.
     *
     * <version>
     * : Plugin/theme version or the exact WordPress core version.
     *
     * <locale>
     * : Target locale.
     *
     * [--priority=<priority>]
     * : Job priority (1-10). Default: 5.
     *
     * [--target-type=<target-type>]
     * : Target type: plugin, theme, or core. Default: plugin.
     *
     * [--source=<source>]
     * : Target source/origin. Default: unknown.
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-server add woocommerce 8.2.0 es_ES
     *     wp gratis-ai-server add woocommerce 8.2.0 de_DE --priority=10
     *     wp gratis-ai-server add wordpress 7.1 de_DE --target-type=core
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
        $target_type = strtolower( trim( (string) ( $assoc_args['target-type'] ?? 'plugin' ) ) );
        if ( ! Translation_Queue::is_valid_target_type( $target_type ) ) {
            \WP_CLI::error( 'Target type must be plugin, theme, or core.' );
            return;
        }

        $source      = sanitize_text_field( (string) ( $assoc_args['source'] ?? 'unknown' ) );

        if ( 'core' === $target_type ) {
            if ( ! in_array( strtolower( (string) $textdomain ), [ 'core', 'wordpress' ], true ) ) {
                \WP_CLI::error( 'Core jobs must use the wordpress textdomain.' );
                return;
            }

            if ( ! preg_match( '/^[a-z0-9._+-]{1,40}$/i', (string) $version ) ) {
                \WP_CLI::error( 'Core jobs require a valid WordPress version of at most 40 characters.' );
                return;
            }

            $textdomain = 'wordpress';
            $source      = 'wporg';
        }

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
            $job = $this->queue->claim_next_pending_job( $processed_job_ids );

            if (!$job) {
                \WP_CLI::log($i === 0 ? 'No pending jobs to process.' : 'No more pending jobs.');
                return;
            }

            $processed_job_ids[] = (int) $job['id'];

            $target_type = $job['target_type'] ?? 'plugin';
            \WP_CLI::log("Processing job {$job['id']}: {$target_type} {$job['textdomain']} {$job['version']} ({$job['locale']})");

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
     * Repair current plural translations that lack required locale forms.
     *
     * Rows written before complete plural generation only stored the singular
     * form, so the package guard blocks every job for the affected locales.
     * This command regenerates every form with the active provider, demotes the
     * incomplete row to `old` (kept for rollback) and creates a new `current`
     * row with AI provenance. Without --execute it only reports.
     *
     * ## OPTIONS
     *
     * [--project=<path>]
     * : GlotPress project path or slug, e.g. plugins/superdav-ai-agent or superdav-ai-agent.
     *
     * [--locale=<slug>]
     * : GlotPress locale slug, e.g. fj.
     *
     * [--include-human]
     * : Also regenerate imported/reviewed human rows (user_id set). Default: AI rows only.
     *
     * [--limit=<limit>]
     * : Maximum rows to repair in this run. Default: 200.
     *
     * [--execute]
     * : Perform the repair. Requires --backup-file.
     *
     * [--backup-file=<path>]
     * : New JSON file outside the web root for the pre-repair rows. A
     * `<path>.receipt.json` file records previous/replacement row IDs.
     *
     * [--retry-jobs]
     * : After repair, return jobs that failed on the plural guard to pending.
     *
     * [--textdomain=<textdomain>]
     * : Limit --retry-jobs to one textdomain.
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-server repair-plurals --project=superdav-ai-agent
     *     wp gratis-ai-server repair-plurals --project=superdav-ai-agent --execute --backup-file=/home/web/plural-repair.json --retry-jobs --textdomain=superdav-ai-agent
     *
     * @subcommand repair-plurals
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function repair_plurals( array $args, array $assoc_args ): void {
        $project       = (string) ( $assoc_args['project'] ?? '' );
        $locale        = (string) ( $assoc_args['locale'] ?? '' );
        $include_human = ! empty( $assoc_args['include-human'] );
        $limit         = max( 1, (int) ( $assoc_args['limit'] ?? 200 ) );
        $execute       = ! empty( $assoc_args['execute'] );

        $sets = $this->generator->find_incomplete_plural_sets( $project, $locale );
        if ( empty( $sets ) ) {
            \WP_CLI::success( 'No incomplete plural translations found.' );
            $this->maybe_requeue_plural_jobs( $assoc_args );
            return;
        }

        \WP_CLI\Utils\format_items( 'table', $sets, [ 'project', 'locale', 'nplurals', 'ai_rows', 'human_rows' ] );
        $ai_total    = array_sum( array_column( $sets, 'ai_rows' ) );
        $human_total = array_sum( array_column( $sets, 'human_rows' ) );
        \WP_CLI::log( sprintf( '%d sets, %d AI rows, %d human rows incomplete.', count( $sets ), $ai_total, $human_total ) );
        if ( $human_total > 0 && ! $include_human ) {
            \WP_CLI::log( 'Human rows are skipped; pass --include-human to regenerate them too.' );
        }

        if ( ! $execute ) {
            \WP_CLI::log( 'Dry run. Re-run with --execute --backup-file=<path> to repair.' );
            return;
        }

        $backup_file = $this->validate_backup_path( (string) ( $assoc_args['backup-file'] ?? '' ) );

        // Collect and back up every row before changing any of them.
        $work = [];
        foreach ( $sets as $set ) {
            $remaining = $limit - count( $work );
            if ( $remaining <= 0 ) {
                break;
            }
            foreach ( $this->generator->get_incomplete_plural_rows( $set, $include_human, $remaining ) as $row ) {
                $work[] = [ 'set' => $set, 'row' => $row ];
            }
        }
        if ( empty( $work ) ) {
            \WP_CLI::success( 'No repairable rows for the selected author scope.' );
            $this->maybe_requeue_plural_jobs( $assoc_args );
            return;
        }

        $backup = wp_json_encode( [
            'created_at' => gmdate( 'c' ),
            'site'       => home_url(),
            'rows'       => $work,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false === $backup || false === file_put_contents( $backup_file, $backup, LOCK_EX ) ) {
            \WP_CLI::error( 'Could not write the backup file; nothing was changed.' );
        }
        \WP_CLI::log( sprintf( 'Backed up %d rows to %s', count( $work ), $backup_file ) );

        $receipt_file = $backup_file . '.receipt.json';
        $receipts     = [];
        $failures     = [];
        $progress     = \WP_CLI\Utils\make_progress_bar( 'Repairing plural rows', count( $work ) );
        foreach ( $work as $position => $item ) {
            $result = $this->generator->repair_incomplete_plural_row( $item['row'], $item['set'] );
            if ( is_wp_error( $result ) ) {
                $failures[] = [
                    'project'     => $item['set']['project'],
                    'locale'      => $item['set']['locale'],
                    'previous_id' => (int) $item['row']['id'],
                    'error'       => Superdav_AI_Client::redact_error_message( $result->get_error_message() ),
                ];
            } else {
                $receipts[] = $result;
            }
            $progress->tick();
            if ( 0 === ( $position + 1 ) % 25 ) {
                $this->write_receipt( $receipt_file, $receipts, $failures );
            }
        }
        $progress->finish();
        $this->write_receipt( $receipt_file, $receipts, $failures );

        \WP_CLI::log( sprintf( 'Receipt: %s', $receipt_file ) );
        foreach ( array_slice( $failures, 0, 10 ) as $failure ) {
            \WP_CLI::warning( sprintf( '%s %s row %d: %s', $failure['project'], $failure['locale'], $failure['previous_id'], $failure['error'] ) );
        }

        $this->maybe_requeue_plural_jobs( $assoc_args );

        if ( $failures ) {
            \WP_CLI::error( sprintf( 'Repaired %d rows; %d failed (see receipt).', count( $receipts ), count( $failures ) ) );
        }
        \WP_CLI::success( sprintf( 'Repaired %d rows.', count( $receipts ) ) );
    }

    /**
     * Require a new backup path outside the public WordPress tree.
     *
     * @param string $path Requested path.
     * @return string Validated absolute path.
     */
    private function validate_backup_path( string $path ): string {
        if ( '' === $path ) {
            \WP_CLI::error( '--execute requires --backup-file=<path>.' );
        }
        if ( file_exists( $path ) ) {
            \WP_CLI::error( 'The backup file already exists; choose a new path.' );
        }
        $directory = realpath( dirname( $path ) );
        if ( false === $directory || ! is_writable( $directory ) ) {
            \WP_CLI::error( 'The backup directory does not exist or is not writable.' );
        }
        // dirname( WP_CONTENT_DIR ) is the document root on Bedrock (web/) and ABSPATH on standard installs.
        foreach ( [ ABSPATH, WP_CONTENT_DIR, dirname( WP_CONTENT_DIR ) ] as $public_root ) {
            $root = realpath( $public_root );
            if ( false !== $root && str_starts_with( $directory . '/', rtrim( $root, '/' ) . '/' ) ) {
                \WP_CLI::error( 'The backup file must be outside the public WordPress directories.' );
            }
        }

        return $directory . '/' . basename( $path );
    }

    /**
     * Persist repair progress.
     *
     * @param string $file     Receipt path.
     * @param array  $receipts Successful repairs.
     * @param array  $failures Failed rows.
     * @return void
     */
    private function write_receipt( string $file, array $receipts, array $failures ): void {
        $json = wp_json_encode( [
            'updated_at' => gmdate( 'c' ),
            'repaired'   => $receipts,
            'failed'     => $failures,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false === $json || false === file_put_contents( $file, $json, LOCK_EX ) ) {
            \WP_CLI::warning( 'Could not write the repair receipt.' );
        }
    }

    /**
     * Requeue plural-guard failures when --retry-jobs is set.
     *
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    private function maybe_requeue_plural_jobs( array $assoc_args ): void {
        if ( empty( $assoc_args['retry-jobs'] ) ) {
            return;
        }
        $count = $this->queue->requeue_incomplete_plural_failures( (string) ( $assoc_args['textdomain'] ?? '' ) );
        \WP_CLI::log( sprintf( 'Requeued %d jobs that failed on incomplete plurals.', $count ) );
    }

    /**
     * Delete jobs for GlotPress locales that have no WordPress locale.
     *
     * WordPress never requests these locales, and their packages share one
     * locale-less ZIP name. New requests are already refused; this removes
     * jobs queued earlier in any status. Without --execute it only reports.
     *
     * ## OPTIONS
     *
     * [--execute]
     * : Delete the jobs.
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-server purge-unsupported-locales
     *     wp gratis-ai-server purge-unsupported-locales --execute
     *
     * @subcommand purge-unsupported-locales
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function purge_unsupported_locales( array $args, array $assoc_args ): void {
        $execute = ! empty( $assoc_args['execute'] );
        $counts  = $this->queue->purge_unsupported_locale_jobs( ! $execute );

        if ( empty( $counts ) ) {
            \WP_CLI::success( 'No jobs for unsupported locales.' );
            return;
        }

        $items = [];
        foreach ( $counts as $key => $count ) {
            [ $status, $locale ] = explode( ' ', $key, 2 );
            $items[]             = [ 'locale' => $locale, 'status' => $status, 'jobs' => $count ];
        }
        \WP_CLI\Utils\format_items( 'table', $items, [ 'locale', 'status', 'jobs' ] );

        $total = array_sum( $counts );
        if ( $execute ) {
            \WP_CLI::success( sprintf( 'Deleted %d jobs.', $total ) );
        } else {
            \WP_CLI::log( sprintf( 'Dry run: %d jobs would be deleted. Re-run with --execute.', $total ) );
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
