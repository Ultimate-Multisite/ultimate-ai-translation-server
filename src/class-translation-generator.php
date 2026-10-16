<?php
/**
 * Translation Generator class
 *
 * Handles AI translation generation through the configured provider. Superdav
 * AI Service is supported natively through an OpenAI-compatible API, while the
 * gp-openai-translate plugin remains available as a compatibility fallback.
 *
 * @package GratisAITranslationsServer
 */

declare(strict_types=1);

namespace GratisAITranslationsServer;

/**
 * Translation Generator class.
 *
 * @since 1.0.0
 */
class Translation_Generator {

    /**
     * Canonical queue textdomain for WordPress core.
     *
     * @var string
     */
    private const CORE_TEXTDOMAIN = 'wordpress';

    /**
     * Core language domains and their Language_Pack_Upgrader file prefixes.
     *
     * @var array<string,array{file_prefix:string,project_slug:string,project_name:string}>
     */
    private const CORE_DOMAINS = [
        'default'           => [ 'file_prefix' => '',                    'project_slug' => 'default',           'project_name' => 'WordPress' ],
        'admin'             => [ 'file_prefix' => 'admin-',              'project_slug' => 'admin',             'project_name' => 'Administration' ],
        'admin-network'     => [ 'file_prefix' => 'admin-network-',      'project_slug' => 'admin-network',     'project_name' => 'Network Administration' ],
        'continents-cities' => [ 'file_prefix' => 'continents-cities-',  'project_slug' => 'continents-cities', 'project_name' => 'Continents and Cities' ],
    ];

    /**
     * GlotPress translation meta key used to distinguish AI gap-fills.
     *
     * @var string
     */
    private const AI_TRANSLATION_SOURCE_META_KEY = 'gratis_ai_ts_source';

    /**
     * Provenance value assigned to server-generated core translations.
     *
     * @var string
     */
    private const AI_TRANSLATION_SOURCE = 'ai';

    /**
     * GlotPress project meta key recording the release whose POT last populated originals.
     *
     * Each WordPress.org target shares one GlotPress project across versions, so
     * originals follow only the newest release; older-version jobs never lower it.
     *
     * @var string
     */
    public const SOURCE_VERSION_META_KEY = 'gratis_ai_ts_source_version';

    /**
     * WordPress.org release metadata cached for the current request.
     *
     * @var array<string,object|null>
     */
    private array $wporg_release_cache = [];

    /**
     * Provider errors caused by a malformed or short model response rather than
     * an unusable request. Retrying the same strings in smaller batches usually
     * succeeds because models drop or merge items more often in long batches.
     *
     * @var string[]
     */
    private const SPLITTABLE_BATCH_ERROR_CODES = [
        'superdav_translation_count_mismatch',
        'superdav_invalid_translation_json',
        'superdav_missing_translations',
        'superdav_invalid_translation_item',
        'superdav_missing_content',
    ];

    /**
     * Maximum times a malformed batch is halved before the job fails.
     * Depth 3 bounds a 50-string batch to at most 15 provider requests.
     *
     * @var int
     */
    private const MAX_BATCH_SPLIT_DEPTH = 3;

    /**
     * Provider attempts for one plural original before it fails validation.
     *
     * @var int
     */
    private const PLURAL_ATTEMPTS = 2;

    /**
     * Error prefix shared by the package guard and bulk retry selection.
     *
     * @var string
     */
    public const INCOMPLETE_PLURALS_ERROR_PREFIX = 'Incomplete plural translations';

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
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Resolve server-controlled metadata for a supported target.
     *
     * Core uses a fixed identity rather than client-supplied plugin metadata so
     * queue rows, GlotPress projects, WordPress.org imports, and package names
     * all agree on the same target.
     *
     * @param string $target_type Candidate target type.
     * @param string $textdomain  Target textdomain or core alias.
     * @param string $version     Target version.
     * @return array<string,mixed>|null Metadata for a valid target, or null.
     */
    public static function resolve_target_metadata( string $target_type, string $textdomain, string $version = '' ): ?array {
        $target_type = strtolower( trim( $target_type ) );
        $textdomain  = trim( $textdomain );

        if ( ! Translation_Queue::is_valid_target_type( $target_type ) ) {
            return null;
        }

        if ( 'core' === $target_type ) {
            if ( ! in_array( strtolower( $textdomain ), [ 'core', self::CORE_TEXTDOMAIN ], true ) ) {
                return null;
            }

            return [
                'target_type'            => 'core',
                'textdomain'             => self::CORE_TEXTDOMAIN,
                'version'                => $version,
                'source'                 => 'wporg',
                'source_authoritative'   => true,
                'project_parent_slug'    => 'core',
                'project_parent_name'    => 'WordPress Core',
                'wporg_project_prefix'   => 'wp',
                'wporg_translation_type' => 'core',
                'domains'                => self::CORE_DOMAINS,
            ];
        }

        $is_theme = 'theme' === $target_type;
        // Ultimate Multisite add-ons have existing GlotPress projects beneath
        // ultimatemultisite/, not plugins/. Keep the main plugin in plugins/.
        $is_ultimate_addon = ! $is_theme && 1 === preg_match( '/\Aultimate-multisite-[a-z0-9-]+\z/', $textdomain );

        return [
            'target_type'            => $target_type,
            'textdomain'             => $textdomain,
            'version'                => $version,
            'source'                 => 'unknown',
            'source_authoritative'   => true,
            'project_parent_slug'    => $is_ultimate_addon ? 'ultimatemultisite' : ( $is_theme ? 'themes' : 'plugins' ),
            'project_parent_name'    => $is_ultimate_addon ? 'Ultimate Multisite' : ( $is_theme ? 'Themes' : 'Plugins' ),
            'wporg_project_prefix'   => $is_theme ? 'wp-themes' : 'wp-plugins',
            'wporg_translation_type' => $is_theme ? 'themes' : 'plugins',
        ];
    }

    /**
     * Initialize hooks.
     *
     * @since 1.0.0
     * @return void
     */
    public function init(): void {
        add_action( 'gratis_ai_ts_generate_translation', [ $this, 'generate_translation' ], 10, 1 );
        // Traduttore regenerates versionless ZIPs after translation changes.
        // Rebind only canonical add-on output to its latest completed job.
        add_action( 'traduttore.zip_generated', [ $this, 'attest_regenerated_addon_package' ], 20, 4 );
    }

    /**
     * Generate translation for a job.
     *
     * @since 1.0.0
     * @param int $job_id Job ID.
     * @return bool True on success.
     */
    public function generate_translation( int $job_id ): bool {
        $queue = Translation_Queue::instance();
        $job   = $queue->get_job_by_id( $job_id );

        if ( ! $job ) {
            return false;
        }

        // Jobs queued before locale validation: reject without provider spend.
        if ( ! Translation_Queue::is_wordpress_locale( (string) $job['locale'] ) ) {
            $queue->reject_job( $job_id );
            return true;
        }

        $target_type = $this->normalize_target_type( $job['target_type'] ?? 'plugin' );

        if ( 'core' === $target_type ) {
            return $this->generate_core_translation( $job, $queue );
        }

        $translator  = $this->get_active_translator();

        if ( ! $translator ) {
            $queue->update_job_status( $job_id, 'failed', [
                'error_message' => $this->get_translator_unavailable_message(),
            ] );
            return false;
        }

        try {
            // Step 1: Get or create GlotPress project.
            $project = $this->get_or_create_project( $target_type, $job['textdomain'], $job['version'] );

            if ( ! $project ) {
                $queue->update_job_status( $job_id, 'failed', [
                    'error_message' => 'Failed to create GlotPress project',
                ] );
                return false;
            }

            // Step 2: Get or create translation set.
            $translation_set = $this->get_or_create_translation_set( $project, $job['locale'] );

            if ( ! $translation_set ) {
                $queue->update_job_status( $job_id, 'failed', [
                    'error_message' => 'Failed to create translation set',
                ] );
                return false;
            }

            // Step 3: Refresh originals from the newest complete source
            // (translate.wordpress.org stable export, or the local POT for
            // non-WordPress.org targets), then import human translations.
            $this->suppress_automation_hooks();
            try {
                $import_ok = $this->import_from_wporg_glotpress( $project, $translation_set, $target_type, $job['textdomain'], $job['version'], $job['locale'] );
            } finally {
                $this->restore_automation_hooks();
            }

            if ( ! $import_ok ) {
                $queue->update_job_status( $job_id, 'failed', [
                    'error_message' => 'Failed to import complete source strings (no translate.wordpress.org stable export or local POT available).',
                ] );
                return false;
            }

            // Step 4b: Get remaining untranslated strings.
            $originals = $this->get_untranslated_originals( $project, $translation_set, true );

            if ( empty( $originals ) ) {
                $this->require_complete_plurals( $project, $translation_set );
                // All strings already covered by human translations — build package via Traduttore.
                $zip_provider = new \Required\Traduttore\ZipProvider( $translation_set );
                if ( ! $zip_provider->generate_zip_file() ) {
                    throw new \RuntimeException( 'Could not build the approved translation package.' );
                }
                $package_url = $this->attest_addon_package( $zip_provider, $job, $project );

                $queue->update_job_status( $job_id, 'completed', [
                    'package_url'      => $package_url,
                    'string_count'     => 0,
                    'translated_count' => 0,
                ] );
                return true;
            }

            $queue->update_job_status( $job_id, 'processing', [
                'string_count' => count( $originals ),
            ] );

            // Step 5: Translate strings in batches via the active provider.
            // Resolve WP locale to GP slug for the translator (e.g. fr_FR -> fr).
            $locale_obj = \GP_Locales::by_field( 'wp_locale', $job['locale'] )
                ?: \GP_Locales::by_slug( $job['locale'] );
            if ( ! $locale_obj ) {
                throw new \RuntimeException( 'The requested locale is not available in GlotPress.' );
            }
            $gp_locale = $locale_obj->slug;

            $batch_size                    = max( 1, (int) get_site_option( 'gratis_ai_ts_batch_size', 50 ) );
            $batches                       = array_chunk( $originals, $batch_size );
            $total_translated              = 0;
            $translated_count_before_run   = max( 0, (int) ( $job['translated_count'] ?? 0 ) );
            $prompt_tokens_before_run      = max( 0, (int) ( $job['prompt_tokens'] ?? 0 ) );
            $completion_tokens_before_run  = max( 0, (int) ( $job['completion_tokens'] ?? 0 ) );
            $total_string_count            = max(
                count( $originals ),
                (int) ( $job['string_count'] ?? 0 ),
                count( $originals ) + $translated_count_before_run
            );
            $max_batches_per_run           = $this->get_max_batches_per_run();
            $run_time_budget_seconds       = $this->get_run_time_budget_seconds();
            $run_started_at                = microtime( true );
            $batches_processed             = 0;

            // Reset token usage counter before starting this job's translations.
            $translator->reset_usage();

            foreach ( $batches as $batch ) {
                if ( $batches_processed > 0
                    && ( $batches_processed >= $max_batches_per_run
                        || microtime( true ) - $run_started_at >= $run_time_budget_seconds )
                ) {
                    break;
                }

                $translated = $this->translate_originals( $translator, $batch, $locale_obj, (int) $project->id );

                if ( is_wp_error( $translated ) ) {
                    $error_message = Superdav_AI_Client::redact_error_message( $translated->get_error_message() );
                    $progress_data = [
                        'string_count'      => $total_string_count,
                        'translated_count'  => $translated_count_before_run + $total_translated,
                        'prompt_tokens'     => $prompt_tokens_before_run + $translator->get_accumulated_usage()['prompt_tokens'],
                        'completion_tokens' => $completion_tokens_before_run + $translator->get_accumulated_usage()['completion_tokens'],
                    ];

                    if ( $this->is_transient_provider_error( $error_message ) && $queue->requeue_transient_failure( $job_id, $error_message, $progress_data ) ) {
                        return true;
                    }

                    $queue->update_job_status( $job_id, 'failed', [
                        'error_message' => $error_message,
                    ] );
                    return false;
                }

                if ( ! is_array( $translated ) || count( $translated ) !== count( $batch ) ) {
                    $queue->update_job_status( $job_id, 'failed', [
                        'error_message' => sprintf(
                            'Translation provider returned %d translations for %d source strings.',
                            is_array( $translated ) ? count( $translated ) : 0,
                            count( $batch )
                        ),
                    ] );
                    return false;
                }

                // Map positional results back to originals by index.
                if ( ! $this->save_translations( $translation_set, $batch, $translated, true, true ) ) {
                    throw new \RuntimeException( 'Could not save the complete translation batch.' );
                }
                $total_translated += count( $translated );
                $batches_processed++;

                // Update progress with token usage so far.
                $usage                = $translator->get_accumulated_usage();
                $current_translated   = $translated_count_before_run + $total_translated;
                $queue->update_job_status( $job_id, 'processing', [
                    'string_count'      => $total_string_count,
                    'translated_count'  => $current_translated,
                    'prompt_tokens'     => $prompt_tokens_before_run + $usage['prompt_tokens'],
                    'completion_tokens' => $completion_tokens_before_run + $usage['completion_tokens'],
                ] );
            }

            if ( $total_translated < count( $originals ) ) {
                $usage = $translator->get_accumulated_usage();
                return $queue->requeue_partial_job( $job_id, [
                    'string_count'      => $total_string_count,
                    'translated_count'  => $translated_count_before_run + $total_translated,
                    'prompt_tokens'     => $prompt_tokens_before_run + $usage['prompt_tokens'],
                    'completion_tokens' => $completion_tokens_before_run + $usage['completion_tokens'],
                ] );
            }

            // Step 6: Build package via Traduttore's ZipProvider.
            $this->require_complete_plurals( $project, $translation_set );
            $zip_provider = new \Required\Traduttore\ZipProvider( $translation_set );
            if ( ! $zip_provider->generate_zip_file() ) {
                throw new \RuntimeException( 'Could not build the approved translation package.' );
            }
            $package_url = $this->attest_addon_package( $zip_provider, $job, $project );

            // Step 7: Mark job as completed with final token usage.
            $usage = $translator->get_accumulated_usage();
            $queue->update_job_status( $job_id, 'completed', [
                'package_url'       => $package_url,
                'string_count'      => $total_string_count,
                'translated_count'  => $translated_count_before_run + $total_translated,
                'prompt_tokens'     => $prompt_tokens_before_run + $usage['prompt_tokens'],
                'completion_tokens' => $completion_tokens_before_run + $usage['completion_tokens'],
            ] );

            return true;

        } catch ( \Exception $e ) {
            $queue->update_job_status( $job_id, 'failed', [
                'error_message' => $e->getMessage(),
            ] );
            return false;
        }
    }

    /**
     * Generate a version-isolated WordPress core language pack.
     *
     * Human translations are imported from the exact WordPress.org core package
     * before the provider sees any missing strings. Core domains live in separate
     * GlotPress projects so their filenames and context/plural data remain intact
     * when the final package is built.
     *
     * @param array<string,mixed> $job   Queue job.
     * @param Translation_Queue   $queue Queue service.
     * @return bool Whether the job completed or was safely requeued.
     */
    private function generate_core_translation( array $job, Translation_Queue $queue ): bool {
        $job_id   = (int) $job['id'];
        $version  = (string) $job['version'];
        $locale   = (string) $job['locale'];
        $metadata = self::resolve_target_metadata( 'core', (string) $job['textdomain'], $version );

        if ( ! $metadata ) {
            $queue->update_job_status( $job_id, 'failed', [
                'error_message' => 'Invalid WordPress core target identity.',
            ] );
            return false;
        }

        try {
            $source_package = $this->download_core_language_package( $version, $locale );
            if ( is_wp_error( $source_package ) ) {
                $queue->update_job_status( $job_id, 'failed', [
                    'error_message' => $source_package->get_error_message(),
                ] );
                return false;
            }

            $domain_jobs = [];
            foreach ( $metadata['domains'] as $domain => $domain_metadata ) {
                $po_content = $source_package['po_contents'][ $domain ] ?? '';
                if ( ! is_string( $po_content ) || '' === $po_content ) {
                    continue;
                }

                $project = $this->get_or_create_core_project( $version, $domain, $domain_metadata );
                if ( ! $project ) {
                    $queue->update_job_status( $job_id, 'failed', [
                        'error_message' => sprintf( 'Failed to create the WordPress core %s project.', $domain ),
                    ] );
                    return false;
                }

                $translation_set = $this->get_or_create_translation_set( $project, $locale );
                if ( ! $translation_set ) {
                    $queue->update_job_status( $job_id, 'failed', [
                        'error_message' => sprintf( 'Failed to create the WordPress core %s translation set.', $domain ),
                    ] );
                    return false;
                }

                $this->suppress_automation_hooks();
                try {
                    $imported = $this->import_core_po( $project, $translation_set, $po_content );
                } finally {
                    $this->restore_automation_hooks();
                }

                if ( ! $imported ) {
                    $queue->update_job_status( $job_id, 'failed', [
                        'error_message' => sprintf( 'Failed to import the official WordPress core %s translations.', $domain ),
                    ] );
                    return false;
                }

                $domain_jobs[] = [
                    'domain'          => $domain,
                    'file_prefix'     => $domain_metadata['file_prefix'],
                    'project'         => $project,
                    'translation_set' => $translation_set,
                    'originals'       => [],
                ];
            }

            if ( empty( $domain_jobs ) ) {
                $queue->update_job_status( $job_id, 'failed', [
                    'error_message' => 'The exact WordPress.org core package did not contain importable PO files.',
                ] );
                return false;
            }

            $remaining = 0;
            foreach ( $domain_jobs as $index => $domain_job ) {
                $domain_jobs[ $index ]['originals'] = $this->get_untranslated_originals(
                    $domain_job['project'],
                    $domain_job['translation_set'],
                    true,
                    true
                );
                $remaining += count( $domain_jobs[ $index ]['originals'] );
            }

            $translated_count_before_run  = max( 0, (int) ( $job['translated_count'] ?? 0 ) );
            $prompt_tokens_before_run     = max( 0, (int) ( $job['prompt_tokens'] ?? 0 ) );
            $completion_tokens_before_run = max( 0, (int) ( $job['completion_tokens'] ?? 0 ) );
            $total_string_count           = max(
                $remaining,
                (int) ( $job['string_count'] ?? 0 ),
                $remaining + $translated_count_before_run
            );
            $total_translated = 0;
            $usage            = [ 'prompt_tokens' => 0, 'completion_tokens' => 0 ];

            if ( $remaining > 0 ) {
                $translator = $this->get_active_translator();
                if ( ! $translator ) {
                    $queue->update_job_status( $job_id, 'failed', [
                        'error_message' => $this->get_translator_unavailable_message(),
                    ] );
                    return false;
                }

                $queue->update_job_status( $job_id, 'processing', [
                    'string_count' => $total_string_count,
                ] );

                $locale_obj = \GP_Locales::by_field( 'wp_locale', $locale )
                    ?: \GP_Locales::by_slug( $locale );
                if ( ! $locale_obj ) {
                    $queue->update_job_status( $job_id, 'failed', [
                        'error_message' => 'The requested WordPress locale is not available in GlotPress.',
                    ] );
                    return false;
                }

                $batch_size              = max( 1, (int) get_site_option( 'gratis_ai_ts_batch_size', 50 ) );
                $max_batches_per_run     = $this->get_max_batches_per_run();
                $run_time_budget_seconds = $this->get_run_time_budget_seconds();
                $run_started_at          = microtime( true );
                $batches_processed       = 0;
                $translator->reset_usage();

                foreach ( $domain_jobs as $domain_job ) {
                    foreach ( array_chunk( $domain_job['originals'], $batch_size ) as $batch ) {
                        if ( $batches_processed > 0
                            && ( $batches_processed >= $max_batches_per_run
                                || microtime( true ) - $run_started_at >= $run_time_budget_seconds )
                        ) {
                            break 2;
                        }

                        $strings      = array_column( $batch, 'singular' );
                        $contexts     = array_column( $batch, 'context' );
                        $original_ids = array_column( $batch, 'id' );
                        $translated   = $this->translate_batch_with_split(
                            $translator,
                            $locale_obj->slug,
                            $strings,
                            $contexts,
                            $original_ids,
                            (int) $domain_job['project']->id
                        );

                        if ( is_wp_error( $translated ) ) {
                            $error_message = Superdav_AI_Client::redact_error_message( $translated->get_error_message() );
                            $progress_data = [
                                'string_count'      => $total_string_count,
                                'translated_count'  => $translated_count_before_run + $total_translated,
                                'prompt_tokens'     => $prompt_tokens_before_run + $translator->get_accumulated_usage()['prompt_tokens'],
                                'completion_tokens' => $completion_tokens_before_run + $translator->get_accumulated_usage()['completion_tokens'],
                            ];

                            if ( $this->is_transient_provider_error( $error_message ) && $queue->requeue_transient_failure( $job_id, $error_message, $progress_data ) ) {
                                return true;
                            }

                            $queue->update_job_status( $job_id, 'failed', [ 'error_message' => $error_message ] );
                            return false;
                        }

                        if ( ! is_array( $translated ) || count( $translated ) !== count( $batch ) ) {
                            $queue->update_job_status( $job_id, 'failed', [
                                'error_message' => sprintf(
                                    'Translation provider returned %d translations for %d source strings.',
                                    is_array( $translated ) ? count( $translated ) : 0,
                                    count( $batch )
                                ),
                            ] );
                            return false;
                        }

                        if ( ! $this->save_translations( $domain_job['translation_set'], $batch, $translated, true, true ) ) {
                            $queue->update_job_status( $job_id, 'failed', [
                                'error_message' => 'Unable to save generated WordPress core translations with provenance.',
                            ] );
                            return false;
                        }
                        $total_translated += count( $translated );
                        $batches_processed++;
                        $usage = $translator->get_accumulated_usage();

                        $queue->update_job_status( $job_id, 'processing', [
                            'string_count'      => $total_string_count,
                            'translated_count'  => $translated_count_before_run + $total_translated,
                            'prompt_tokens'     => $prompt_tokens_before_run + $usage['prompt_tokens'],
                            'completion_tokens' => $completion_tokens_before_run + $usage['completion_tokens'],
                        ] );
                    }
                }

                if ( $total_translated < $remaining ) {
                    return $queue->requeue_partial_job( $job_id, [
                        'string_count'      => $total_string_count,
                        'translated_count'  => $translated_count_before_run + $total_translated,
                        'prompt_tokens'     => $prompt_tokens_before_run + $usage['prompt_tokens'],
                        'completion_tokens' => $completion_tokens_before_run + $usage['completion_tokens'],
                    ] );
                }
            }

            $package_url = $this->build_core_package(
                $domain_jobs,
                $version,
                $locale,
                $source_package['json_contents'] ?? []
            );
            if ( is_wp_error( $package_url ) ) {
                $queue->update_job_status( $job_id, 'failed', [
                    'error_message' => $package_url->get_error_message(),
                ] );
                return false;
            }

            $queue->update_job_status( $job_id, 'completed', [
                'package_url'       => $package_url,
                'string_count'      => $total_string_count,
                'translated_count'  => $translated_count_before_run + $total_translated,
                'prompt_tokens'     => $prompt_tokens_before_run + $usage['prompt_tokens'],
                'completion_tokens' => $completion_tokens_before_run + $usage['completion_tokens'],
            ] );

            return true;
        } catch ( \Throwable $e ) {
            $queue->update_job_status( $job_id, 'failed', [
                'error_message' => Superdav_AI_Client::redact_error_message( $e->getMessage() ),
            ] );
            return false;
        }
    }

    /**
     * Download the exact official core package and retain only expected PO files.
     *
     * The package itself is never extracted, which avoids trusting archive paths.
     * The API entry must report the requested version exactly so a newer or older
     * release cannot seed a version-isolated core project with stale originals.
     *
     * @param string $version WordPress version.
     * @param string $locale  WordPress locale.
     * @return array{po_contents:array<string,string>,json_contents:array<string,string>}|\WP_Error Core source files or an error.
     */
    private function download_core_language_package( string $version, string $locale ): array|\WP_Error {
        if ( ! function_exists( 'translations_api' ) ) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }

        $api = translations_api( 'core', [ 'version' => $version ] );
        if ( is_wp_error( $api ) || ! is_array( $api ) ) {
            return new \WP_Error( 'core_translation_api_failed', 'WordPress.org did not return core translation metadata.' );
        }

        $entry = null;
        foreach ( (array) ( $api['translations'] ?? [] ) as $candidate ) {
            if (
                is_array( $candidate )
                && $locale === (string) ( $candidate['language'] ?? '' )
                && $version === (string) ( $candidate['version'] ?? '' )
                && ! empty( $candidate['package'] )
            ) {
                $entry = $candidate;
                break;
            }
        }

        if ( ! $entry ) {
            return new \WP_Error( 'core_translation_not_found', 'No exact WordPress.org core language pack is available for the requested version and locale.' );
        }

        $response = wp_remote_get( (string) $entry['package'], [ 'timeout' => 30 ] );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return new \WP_Error( 'core_translation_download_failed', 'Unable to download the exact WordPress.org core language pack.' );
        }

        if ( ! class_exists( '\\ZipArchive' ) ) {
            return new \WP_Error( 'core_zip_unavailable', 'The PHP ZipArchive extension is required to import WordPress core translations.' );
        }

        $temporary_zip = $this->temp_file( 'gratis-ai-core-' . md5( $version . $locale ) . '.zip' );
        if ( ! $temporary_zip || false === file_put_contents( $temporary_zip, wp_remote_retrieve_body( $response ) ) ) {
            return new \WP_Error( 'core_translation_write_failed', 'Unable to prepare the downloaded WordPress core language pack.' );
        }

        $zip = new \ZipArchive();
        if ( true !== $zip->open( $temporary_zip ) ) {
            @unlink( $temporary_zip );
            return new \WP_Error( 'core_translation_archive_invalid', 'The downloaded WordPress core language pack is not a valid ZIP archive.' );
        }

        $po_contents = [];
        foreach ( self::CORE_DOMAINS as $domain => $domain_metadata ) {
            $content = $zip->getFromName( $domain_metadata['file_prefix'] . $locale . '.po' );
            if ( false !== $content && '' !== $content ) {
                $po_contents[ $domain ] = $content;
            }
        }

        $json_contents = [];
        $locale_pattern = '/^' . preg_quote( $locale, '/' ) . '-[^\/]+\.json$/';
        for ( $index = 0; $index < $zip->numFiles; $index++ ) {
            $stat = $zip->statIndex( $index );
            $name = is_array( $stat ) ? (string) ( $stat['name'] ?? '' ) : '';
            if ( '' === $name || ! preg_match( $locale_pattern, $name ) ) {
                continue;
            }

            $content = $zip->getFromIndex( $index );
            if ( false === $content ) {
                $zip->close();
                @unlink( $temporary_zip );
                return new \WP_Error( 'core_translation_asset_invalid', 'Unable to read a WordPress core JavaScript translation asset.' );
            }

            $json_contents[ $name ] = $content;
        }

        $zip->close();
        @unlink( $temporary_zip );

        if ( empty( $po_contents['default'] ) ) {
            return new \WP_Error( 'core_translation_default_missing', 'The exact WordPress.org core language pack does not contain its default PO file.' );
        }

        return [
            'po_contents'   => $po_contents,
            'json_contents' => $json_contents,
        ];
    }

    /**
     * Get or create a version- and domain-isolated GlotPress project for core.
     *
     * @param string                                      $version         WordPress version.
     * @param string                                      $domain          Core domain key.
     * @param array{file_prefix:string,project_slug:string,project_name:string} $domain_metadata Domain metadata.
     * @return object|null Core domain project.
     */
    private function get_or_create_core_project( string $version, string $domain, array $domain_metadata ): ?object {
        $parent = \GP::$project->by_path( 'core' );
        if ( ! $parent ) {
            $parent = \GP::$project->create( [
                'name'              => 'WordPress Core',
                'slug'              => 'core',
                'description'       => 'AI gap-fill translations for WordPress core.',
                'parent_project_id' => null,
                'active'            => 1,
            ] );
        }

        if ( ! $parent ) {
            return null;
        }

        $version_slug = 'v-' . hash( 'sha256', $version );
        $version_path = $parent->path . '/' . $version_slug;
        $version_project = \GP::$project->by_path( $version_path );
        if ( ! $version_project ) {
            $version_project = \GP::$project->create( [
                'name'              => 'WordPress ' . $version,
                'slug'              => $version_slug,
                'description'       => 'Version-isolated WordPress core originals for ' . $version . '.',
                'parent_project_id' => $parent->id,
                'active'            => 1,
            ] );
        }

        if ( ! $version_project ) {
            return null;
        }

        $domain_path = $version_project->path . '/' . $domain_metadata['project_slug'];
        $project     = \GP::$project->by_path( $domain_path );
        if ( $project ) {
            return $project;
        }

        return \GP::$project->create( [
            'name'              => 'WordPress ' . $domain_metadata['project_name'] . ' ' . $version,
            'slug'              => $domain_metadata['project_slug'],
            'description'       => 'WordPress core ' . $domain . ' translations for ' . $version . '.',
            'parent_project_id' => $version_project->id,
            'active'            => 1,
        ] ) ?: null;
    }

    /**
     * Import one official core PO without replacing human translations.
     *
     * Every import refreshes originals so an updated official package cannot leave
     * a version project stale. Official entries replace only values explicitly
     * marked as server-generated AI gap-fills; all other non-empty translations
     * remain authoritative human work.
     *
     * @param object $project         Core domain project.
     * @param object $translation_set Core domain translation set.
     * @param string $po_content      Official PO contents.
     * @return bool Whether original strings were available for the project.
     */
    private function import_core_po( object $project, object $translation_set, string $po_content ): bool {
        if ( ! class_exists( 'PO' ) ) {
            require_once ABSPATH . WPINC . '/pomo/po.php';
        }

        $temporary_po = $this->temp_file( 'gratis-ai-core-import.po' );
        if ( ! $temporary_po || false === file_put_contents( $temporary_po, $po_content ) ) {
            return false;
        }

        $po = new \PO();
        $valid_po = $po->import_from_file( $temporary_po );
        @unlink( $temporary_po );

        if ( ! $valid_po || empty( $po->entries ) ) {
            return false;
        }

        $originals_po = new \PO();
        foreach ( $po->entries as $key => $entry ) {
            $original               = clone $entry;
            $original->translations = [];
            $originals_po->entries[ $key ] = $original;
        }
        \GP::$original->import_for_project( $project, $originals_po );

        global $wpdb;
        $existing_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->gp_originals} WHERE project_id = %d AND status = '+active'",
            $project->id
        ) );

        if ( 0 === $existing_count ) {
            return false;
        }

        if ( ! $this->remove_stale_ai_core_translations( $project, $translation_set ) ) {
            return false;
        }

        foreach ( $po->entries as $entry ) {
            $original = \GP::$original->by_project_id_and_entry( $project->id, $entry, '+active' );
            if ( $original && ! empty( $entry->translations ) ) {
                Human_Translations::import_entry( $original, $translation_set, $entry );
            }
        }

        return $this->replace_ai_core_translations_with_official( $project, $translation_set, $po );
    }

    /**
     * Remove non-current AI translations after an official original changed.
     *
     * GlotPress fuzzies translations when it replaces a close original. A fuzzy
     * AI result is no longer valid for the new source string, unlike a human
     * translation which must remain untouched. Removing only marked AI rows lets
     * the normal missing-string query schedule a fresh, current gap-fill.
     *
     * @param object $project         Core domain project.
     * @param object $translation_set Core domain translation set.
     * @return bool Whether stale AI rows were removed safely.
     */
    private function remove_stale_ai_core_translations( object $project, object $translation_set ): bool {
        if ( ! function_exists( 'gp_get_meta' ) ) {
            return false;
        }

        global $wpdb;
        $translation_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT t.id FROM {$wpdb->gp_translations} AS t
            INNER JOIN {$wpdb->gp_originals} AS o ON o.id = t.original_id
            WHERE t.translation_set_id = %d AND o.project_id = %d AND t.status <> %s",
            $translation_set->id,
            $project->id,
            'current'
        ) );

        foreach ( $translation_ids as $translation_id ) {
            $translation = \GP::$translation->find_one( [ 'id' => (int) $translation_id ] );
            if ( ! $translation || ! $this->is_ai_generated_core_translation( $translation ) ) {
                continue;
            }

            if ( false === $translation->delete() ) {
                return false;
            }

            if ( function_exists( 'gp_delete_meta' ) ) {
                gp_delete_meta(
                    $translation->id,
                    self::AI_TRANSLATION_SOURCE_META_KEY,
                    self::AI_TRANSLATION_SOURCE,
                    'translation'
                );
            }
        }

        return true;
    }

    /**
     * Replace prior AI core gap-fills with matching official human translations.
     *
     * Imports first preserve every current value. This pass lets complete official
     * entries replace marked AI rows; incomplete entries remain suggestions.
     *
     * @param object $project         Core domain project.
     * @param object $translation_set Core domain translation set.
     * @param object $po              Parsed official PO data.
     * @return bool Whether all required replacements succeeded.
     */
    private function replace_ai_core_translations_with_official( object $project, object $translation_set, object $po ): bool {
        if ( ! function_exists( 'gp_get_meta' ) || ! function_exists( 'gp_update_meta' ) ) {
            return false;
        }

        foreach ( $po->entries as $entry ) {
            if ( ! array_filter( $entry->translations ?? [], static fn( $form ) => is_string( $form ) && '' !== trim( $form ) ) ) {
                continue;
            }

            $original = \GP::$original->by_project_id_and_entry( $project->id, $entry, '+active' );
            if ( ! $original ) {
                return false;
            }

            $existing = \GP::$translation->find_one( [
                'original_id'        => $original->id,
                'translation_set_id' => $translation_set->id,
                'status'             => 'current',
            ] );
            if ( ! $existing || ! $this->is_ai_generated_core_translation( $existing ) ) {
                continue;
            }

            if ( ! Human_Translations::import_entry( $original, $translation_set, $entry, true ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convert all GlotPress-supported plural slots from a PO entry.
     *
     * @param object $entry Parsed PO entry.
     * @return array<string,string|null> Translation fields.
     */
    private function translation_data_from_po_entry( object $entry ): array {
        $translation_data = [];
        $translations     = is_array( $entry->translations ?? null ) ? $entry->translations : [];

        for ( $index = 0; $index < \GP_Translation::$number_of_plural_translations; $index++ ) {
            $translation_data[ 'translation_' . $index ] = isset( $translations[ $index ] )
                ? (string) $translations[ $index ]
                : null;
        }

        return $translation_data;
    }

    /**
     * Determine whether a current translation is a server-generated core gap-fill.
     *
     * @param object $translation GlotPress translation.
     * @return bool Whether the translation carries AI provenance.
     */
    private function is_ai_generated_core_translation( object $translation ): bool {
        return function_exists( 'gp_get_meta' )
            && self::AI_TRANSLATION_SOURCE === gp_get_meta(
                'translation',
                (int) $translation->id,
                self::AI_TRANSLATION_SOURCE_META_KEY
            );
    }

    /**
     * Collect generated core translations by the JavaScript source that loads them.
     *
     * @param array<int,array<string,mixed>> $domain_jobs Core projects and sets.
     * @param array<int,array<int,object>>   $entries_by_domain Export entries indexed by domain job position.
     * @return array<string,array<string,array<int,string>>> JED values by source path and message key.
     */
    private function collect_ai_core_jed_translations( array $domain_jobs, array &$entries_by_domain ): array {
        $translations_by_reference = [];

        foreach ( $domain_jobs as $index => $domain_job ) {
            $entries_by_domain[ $index ] = \GP::$translation->for_export(
                $domain_job['project'],
                $domain_job['translation_set']
            );

            foreach ( $entries_by_domain[ $index ] as $entry ) {
                if ( empty( $entry->id ) || ! $this->is_ai_generated_core_translation( $entry ) ) {
                    continue;
                }

                $values = $this->jed_translation_values( $entry );
                if ( empty( $values ) ) {
                    continue;
                }

                $key = $this->jed_translation_key( $entry );
                foreach ( (array) ( $entry->references ?? [] ) as $reference ) {
                    $reference = $this->normalize_jed_reference( (string) $reference );
                    if ( '' === $reference || ! str_ends_with( $reference, '.js' ) ) {
                        continue;
                    }

                    $translations_by_reference[ $reference ][ $key ] = $values;
                }
            }
        }

        return $translations_by_reference;
    }

    /**
     * Merge AI core gap-fills into the JSON assets WordPress loads for scripts.
     *
     * Existing WordPress.org assets remain byte-for-byte unchanged unless their
     * source file contains a marked AI gap-fill. New assets use WordPress core's
     * locale-plus-MD5 filename convention, so script loading can discover them.
     *
     * @param array<string,string>                         $json_contents Official JavaScript assets.
     * @param array<string,array<string,array<int,string>>> $translations_by_reference AI translations by script source.
     * @param object                                       $locale_obj GlotPress locale.
     * @param string                                       $locale WordPress locale.
     * @return array<string,string>|\WP_Error Merged JavaScript assets or an error.
     */
    private function merge_ai_core_jed_translations(
        array $json_contents,
        array $translations_by_reference,
        object $locale_obj,
        string $locale
    ): array|\WP_Error {
        foreach ( $translations_by_reference as $reference => $translations ) {
            $filename = $locale . '-' . md5( $reference ) . '.json';
            if ( isset( $json_contents[ $filename ] ) ) {
                $decoded = json_decode( $json_contents[ $filename ], true );
                if ( ! is_array( $decoded ) ) {
                    return new \WP_Error( 'core_json_invalid', 'An official WordPress core JavaScript translation asset is invalid.' );
                }
            } else {
                $decoded = [
                    'generator'   => 'Gratis AI Translations Server',
                    'domain'      => 'messages',
                    'locale_data' => [ 'messages' => [] ],
                    'comment'     => [ 'reference' => $reference ],
                ];
            }

            $messages = $decoded['locale_data']['messages'] ?? null;
            if ( ! is_array( $messages ) ) {
                return new \WP_Error( 'core_json_messages_invalid', 'A WordPress core JavaScript translation asset has no messages catalog.' );
            }

            if ( ! isset( $messages[''] ) || ! is_array( $messages[''] ) ) {
                $messages[''] = [
                    'domain'       => (string) ( $decoded['domain'] ?? 'messages' ),
                    'plural-forms' => 'nplurals=' . (int) $locale_obj->nplurals . '; plural=' . (string) $locale_obj->plural_expression . ';',
                    'lang'         => (string) $locale_obj->slug,
                ];
            }

            foreach ( $translations as $key => $values ) {
                $messages[ $key ] = $values;
            }

            $decoded['locale_data']['messages'] = $messages;
            $encoded = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            if ( ! is_string( $encoded ) ) {
                return new \WP_Error( 'core_json_encode_failed', 'Unable to encode a WordPress core JavaScript translation asset.' );
            }

            $json_contents[ $filename ] = $encoded;
        }

        return $json_contents;
    }

    /**
     * Build the JED message key for an exported GlotPress entry.
     *
     * @param object $entry Exported GlotPress translation entry.
     * @return string JED message key.
     */
    private function jed_translation_key( object $entry ): string {
        $context = (string) ( $entry->context ?? '' );

        return '' === $context
            ? (string) $entry->singular
            : $context . "\004" . (string) $entry->singular;
    }

    /**
     * Get the non-null JED values for an exported GlotPress entry.
     *
     * @param object $entry Exported GlotPress translation entry.
     * @return array<int,string> JED translation values.
     */
    private function jed_translation_values( object $entry ): array {
        $values = [];

        foreach ( (array) ( $entry->translations ?? [] ) as $translation ) {
            if ( null !== $translation ) {
                $values[] = (string) $translation;
            }
        }

        return $values;
    }

    /**
     * Remove line numbers and normalize a GlotPress JavaScript reference path.
     *
     * @param string $reference GlotPress reference.
     * @return string Relative JavaScript source path.
     */
    private function normalize_jed_reference( string $reference ): string {
        $reference = preg_replace( '/:\d+(?::\d+)?$/', '', $reference );
        $reference = is_string( $reference ) ? str_replace( '\\', '/', $reference ) : '';

        return str_ends_with( $reference, '.min.js' )
            ? substr( $reference, 0, -7 ) . '.js'
            : $reference;
    }

    /**
     * Build an atomic core package accepted by Language_Pack_Upgrader.
     *
     * Core packages contain current PO/MO files plus the official JavaScript JSON
     * assets. The upgrader removes prior JSON assets before installing a core
     * package, so omitting them would break translated scripts. Official l10n.php
     * caches are intentionally excluded because they would hide generated MO data.
     *
     * @param array<int,array<string,mixed>> $domain_jobs Core projects and sets.
     * @param string                          $version     WordPress version.
     * @param string                          $locale      WordPress locale.
     * @param array<string,string>            $json_contents Official JavaScript translation assets.
     * @return string|\WP_Error Package URL or an error.
     */
    private function build_core_package( array $domain_jobs, string $version, string $locale, array $json_contents ): string|\WP_Error {
        if ( ! class_exists( '\\ZipArchive' ) || ! isset( \GP::$formats['po'], \GP::$formats['mo'] ) ) {
            return new \WP_Error( 'core_package_unavailable', 'Core package generation requires ZipArchive and GlotPress PO/MO formats.' );
        }

        $locale_obj = \GP_Locales::by_field( 'wp_locale', $locale ) ?: \GP_Locales::by_slug( $locale );
        if ( ! $locale_obj ) {
            return new \WP_Error( 'core_package_locale_invalid', 'The requested WordPress locale is not available in GlotPress.' );
        }

        $entries_by_domain       = [];
        $translations_by_reference = $this->collect_ai_core_jed_translations( $domain_jobs, $entries_by_domain );
        $json_contents             = $this->merge_ai_core_jed_translations(
            $json_contents,
            $translations_by_reference,
            $locale_obj,
            $locale
        );
        if ( is_wp_error( $json_contents ) ) {
            return $json_contents;
        }

        $package_directory = WP_CONTENT_DIR . '/gratis-ai-translations/packages';
        if ( ! wp_mkdir_p( $package_directory ) || ! is_dir( $package_directory ) || ! is_writable( $package_directory ) ) {
            return new \WP_Error( 'core_package_directory_unavailable', 'The core package storage directory is not writable.' );
        }

        $filename       = 'wordpress-core-' . substr( hash( 'sha256', $version ), 0, 16 ) . '-' . sanitize_file_name( $locale ) . '.zip';
        $destination    = $package_directory . '/' . $filename;
        $temporary_path = tempnam( $package_directory, '.core-package-' );
        if ( false === $temporary_path ) {
            return new \WP_Error( 'core_package_temp_failed', 'Unable to create a temporary core package.' );
        }

        $zip = new \ZipArchive();
        if ( true !== $zip->open( $temporary_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
            @unlink( $temporary_path );
            return new \WP_Error( 'core_package_zip_failed', 'Unable to create the core package archive.' );
        }

        foreach ( $domain_jobs as $index => $domain_job ) {
            $entries = $entries_by_domain[ $index ] ?? [];
            $po      = \GP::$formats['po']->print_exported_file( $domain_job['project'], $locale_obj, $domain_job['translation_set'], $entries );
            $mo      = \GP::$formats['mo']->print_exported_file( $domain_job['project'], $locale_obj, $domain_job['translation_set'], $entries );
            $prefix  = (string) $domain_job['file_prefix'];

            if (
                ! $zip->addFromString( $prefix . $locale . '.po', $po )
                || ! $zip->addFromString( $prefix . $locale . '.mo', $mo )
            ) {
                $zip->close();
                @unlink( $temporary_path );
                return new \WP_Error( 'core_package_write_failed', 'Unable to write all WordPress core language files to the package.' );
            }
        }

        foreach ( $json_contents as $filename => $content ) {
            if ( ! $zip->addFromString( $filename, $content ) ) {
                $zip->close();
                @unlink( $temporary_path );
                return new \WP_Error( 'core_package_write_failed', 'Unable to write all WordPress core language files to the package.' );
            }
        }

        if ( ! $zip->close() ) {
            @unlink( $temporary_path );
            return new \WP_Error( 'core_package_close_failed', 'Unable to finalize the WordPress core package.' );
        }

        if ( ! @rename( $temporary_path, $destination ) ) {
            @unlink( $temporary_path );
            return new \WP_Error( 'core_package_publish_failed', 'Unable to publish the completed WordPress core package.' );
        }

        return content_url( 'gratis-ai-translations/packages/' . rawurlencode( $filename ) );
    }

    /**
     * Stored Automation callback for hook suppression/restoration.
     *
     * @since 1.2.0
     * @var array|null [object, method] or null if not found.
     */
    private ?array $suppressed_automation_callback = null;

    /**
     * Stored Automation callback priority for hook restoration.
     *
     * @since 1.2.0
     * @var int
     */
    private int $suppressed_automation_priority = 10;

    /**
     * Suppress gp-openai-translate's Automation hook on gp_originals_imported.
     *
     * Prevents the Automation class from scheduling AI translations for ALL
     * configured locales when the server imports a POT file. The server
     * manages its own per-locale queue with approval flow.
     *
     * @since 1.2.0
     * @return void
     */
    private function suppress_automation_hooks(): void {
        global $wp_filter;

        if ( ! isset( $wp_filter['gp_originals_imported'] ) ) {
            return;
        }

        // Find and remove the Automation::on_originals_imported callback.
        foreach ( $wp_filter['gp_originals_imported']->callbacks as $priority => $callbacks ) {
            foreach ( $callbacks as $key => $callback ) {
                if ( is_array( $callback['function'] )
                    && is_object( $callback['function'][0] )
                    && $callback['function'][0] instanceof \Meloniq\GpOpenaiTranslate\Automation
                ) {
                    $this->suppressed_automation_callback = $callback['function'];
                    $this->suppressed_automation_priority = (int) $priority;
                    remove_action( 'gp_originals_imported', $callback['function'], $priority );
                    return;
                }
            }
        }
    }

    /**
     * Restore the suppressed Automation hook.
     *
     * @since 1.2.0
     * @return void
     */
    private function restore_automation_hooks(): void {
        if ( $this->suppressed_automation_callback ) {
            add_action( 'gp_originals_imported', $this->suppressed_automation_callback, $this->suppressed_automation_priority, 5 );
            $this->suppressed_automation_callback = null;
            $this->suppressed_automation_priority = 10;
        }
    }

    /**
     * Import newest-release originals and optional human translations.
     *
     * @since 1.2.0
     * @param object $project         GlotPress project.
     * @param object $translation_set GlotPress translation set.
     * @param string $target_type     Target type: 'plugin' or 'theme'.
     * @param string $textdomain      Plugin/theme textdomain or slug.
     * @param string $version         Requested plugin/theme version.
     * @param string $wp_locale       WordPress locale (e.g. 'ro_RO').
     * @return bool True if complete originals are available.
     */
    private function import_from_wporg_glotpress( object $project, object $translation_set, string $target_type, string $textdomain, string $version, string $wp_locale ): bool {
        $source = $this->select_source( $project, $target_type, $textdomain, $wp_locale );

        if ( null !== $source ) {
            try {
                $imported = $this->import_originals_file( $project, $source['path'] );
            } finally {
                if ( $source['temporary'] ) {
                    @unlink( $source['path'] );
                }
            }

            if ( ! $imported ) {
                return false;
            }
            $this->record_source_version( $project, $source['version'] );
        } elseif ( ! $this->project_has_active_originals( $project ) ) {
            return false;
        }

        // Translation package exports may be partial, so they can only fill
        // translations after complete originals are in place above.
        $this->import_human_translations( $project, $translation_set, $target_type, $textdomain, $version, $wp_locale );

        return true;
    }

    /**
     * Choose the complete source that should populate the shared project originals.
     *
     * Originals follow only the newest release, independent of the job version:
     * - WordPress.org targets use the translate.wordpress.org stable project
     *   export, which holds every original for the latest release.
     * - Other targets use the locally installed POT (or one generated from the
     *   installed source), versioned by the installed plugin/theme header.
     *
     * A recorded source version that is already current skips the download.
     *
     * @param object $project     GlotPress project.
     * @param string $target_type Target type: 'plugin' or 'theme'.
     * @param string $textdomain  Plugin/theme textdomain or slug.
     * @param string $wp_locale   Job WordPress locale, used to select an export locale.
     * @return array{path:string,version:string,temporary:bool}|null Source to import, or null to keep current originals.
     */
    private function select_source( object $project, string $target_type, string $textdomain, string $wp_locale ): ?array {
        $metadata = self::resolve_target_metadata( $target_type, $textdomain );
        if ( ! $metadata || 'core' === $metadata['target_type'] ) {
            return null;
        }

        $target_type  = (string) $metadata['target_type'];
        $textdomain   = (string) $metadata['textdomain'];
        $recorded     = $this->get_recorded_source_version( $project );
        $has_original = $this->project_has_active_originals( $project );
        $latest       = $this->get_latest_wporg_version( $target_type, $textdomain );

        if ( '' !== $latest ) {
            if ( $has_original && '' !== $recorded && version_compare( $recorded, $latest, '>=' ) ) {
                return null;
            }

            $path = $this->download_wporg_stable_export( (string) $metadata['wporg_project_prefix'], $target_type, $textdomain, $wp_locale );

            return null === $path ? null : [
                'path'      => $path,
                'version'   => $latest,
                'temporary' => true,
            ];
        }

        $local_version = $this->get_local_version( $target_type, $textdomain );
        if ( $has_original && '' !== $recorded && ( '' === $local_version || version_compare( $recorded, $local_version, '>=' ) ) ) {
            return null;
        }

        $path = $this->find_local_pot( $target_type, $textdomain );
        if ( null !== $path ) {
            return [
                'path'      => $path,
                'version'   => $local_version,
                'temporary' => false,
            ];
        }

        $path = $this->generate_pot_from_source( $target_type, $textdomain, '' !== $local_version ? $local_version : 'local' );

        return null === $path ? null : [
            'path'      => $path,
            'version'   => $local_version,
            'temporary' => true,
        ];
    }

    /**
     * Create a unique temporary file.
     *
     * wp_tempnam() lives in wp-admin/includes/file.php, which is not loaded in
     * Action Scheduler, WP-CLI, or REST contexts.
     *
     * @param string $name Base filename hint.
     * @return string Temporary file path, or an empty string on failure.
     */
    private function temp_file( string $name ): string {
        if ( ! function_exists( 'wp_tempnam' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        return (string) wp_tempnam( $name );
    }

    /**
     * Download every original of the latest release from translate.wordpress.org.
     *
     * The stable project export contains all originals, translated or not, for
     * any locale; only singular/plural/context/comments are imported.
     *
     * @param string $project_prefix WordPress.org project prefix (wp-plugins or wp-themes).
     * @param string $target_type    Target type: 'plugin' or 'theme'.
     * @param string $textdomain     Plugin/theme slug.
     * @param string $wp_locale      Job WordPress locale.
     * @return string|null Temporary PO file path, or null when unavailable.
     */
    private function download_wporg_stable_export( string $project_prefix, string $target_type, string $textdomain, string $wp_locale ): ?string {
        $gp_locale = class_exists( '\GP_Locales' )
            ? ( \GP_Locales::by_field( 'wp_locale', $wp_locale ) ?: \GP_Locales::by_slug( $wp_locale ) )
            : null;
        $locale_slug = $gp_locale && ! empty( $gp_locale->slug ) ? (string) $gp_locale->slug : 'de';

        // Plugins have stable/dev sub-projects; themes export from the theme project.
        $export_url = sprintf(
            'https://translate.wordpress.org/projects/%s/%s/%s%s/default/export-translations/?format=po',
            rawurlencode( $project_prefix ),
            rawurlencode( $textdomain ),
            'theme' === $target_type ? '' : 'stable/',
            rawurlencode( $locale_slug )
        );

        $response = wp_remote_get( $export_url, [ 'timeout' => 60 ] );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $content = (string) wp_remote_retrieve_body( $response );
        if ( ! str_contains( $content, 'msgid' ) ) {
            return null;
        }

        $po_file = $this->temp_file( 'gratis-ai-' . $textdomain . '-stable.po' );
        if ( ! $po_file || false === file_put_contents( $po_file, $content ) ) {
            return null;
        }

        return $po_file;
    }

    /**
     * Installed plugin/theme version from its file header.
     *
     * @param string $target_type Target type: 'plugin' or 'theme'.
     * @param string $textdomain  Plugin/theme slug.
     * @return string Installed version, or an empty string when unknown.
     */
    private function get_local_version( string $target_type, string $textdomain ): string {
        if ( 'theme' === $target_type ) {
            if ( ! function_exists( 'wp_get_theme' ) ) {
                return '';
            }
            $theme = wp_get_theme( $textdomain );

            return $theme->exists() ? (string) $theme->get( 'Version' ) : '';
        }

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach ( get_plugins( '/' . $textdomain ) as $data ) {
            if ( ! empty( $data['Version'] ) ) {
                return (string) $data['Version'];
            }
        }

        return '';
    }

    /**
     * Read the release version that last populated a project's originals.
     *
     * @param object $project GlotPress project.
     * @return string Recorded version, or an empty string when unknown.
     */
    public function get_recorded_source_version( object $project ): string {
        if ( ! function_exists( 'gp_get_meta' ) ) {
            return '';
        }

        $recorded = gp_get_meta( 'project', (int) $project->id, self::SOURCE_VERSION_META_KEY );

        return is_string( $recorded ) ? $recorded : '';
    }

    /**
     * Record the release version that populated a project's originals.
     *
     * Never lowers an existing newer record.
     *
     * @param object $project GlotPress project.
     * @param string $version Imported release version.
     * @return void
     */
    private function record_source_version( object $project, string $version ): void {
        if ( ! function_exists( 'gp_update_meta' ) || '' === $version ) {
            return;
        }

        $recorded = $this->get_recorded_source_version( $project );
        if ( '' !== $recorded && version_compare( $recorded, $version, '>' ) ) {
            return;
        }

        gp_update_meta( (int) $project->id, self::SOURCE_VERSION_META_KEY, $version, 'project' );
    }

    /**
     * Whether a project has any active originals.
     *
     * @param object $project GlotPress project.
     * @return bool True when active originals exist.
     */
    private function project_has_active_originals( object $project ): bool {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->gp_originals} WHERE project_id = %d AND status = '+active'",
            $project->id
        ) ) > 0;
    }

    /**
     * Latest release version published on WordPress.org for a target.
     *
     * @param string $target_type Target type: 'plugin' or 'theme'.
     * @param string $textdomain  Plugin/theme textdomain or slug.
     * @return string Latest version, or an empty string when unavailable.
     */
    public function get_latest_wporg_version( string $target_type, string $textdomain ): string {
        $metadata = self::resolve_target_metadata( $target_type, $textdomain );
        if ( ! $metadata || 'core' === $metadata['target_type'] ) {
            return '';
        }

        $release = $this->get_wporg_release_info( (string) $metadata['target_type'], (string) $metadata['textdomain'] );

        return $release && isset( $release->version ) && is_scalar( $release->version ) ? (string) $release->version : '';
    }

    /**
     * Fetch WordPress.org plugin/theme information including release versions.
     *
     * @param string $target_type Target type: 'plugin' or 'theme'.
     * @param string $textdomain  Plugin/theme slug.
     * @return object|null Release information, or null when unavailable.
     */
    private function get_wporg_release_info( string $target_type, string $textdomain ): ?object {
        $cache_key = $target_type . ':' . $textdomain;
        if ( array_key_exists( $cache_key, $this->wporg_release_cache ) ) {
            return $this->wporg_release_cache[ $cache_key ];
        }

        if ( 'theme' === $target_type ) {
            if ( ! function_exists( 'themes_api' ) ) {
                require_once ABSPATH . 'wp-admin/includes/theme.php';
            }
            $release = themes_api( 'theme_information', [
                'slug'   => $textdomain,
                'fields' => [ 'versions' => true ],
            ] );
        } else {
            if ( ! function_exists( 'plugins_api' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            }
            $release = plugins_api( 'plugin_information', [
                'slug'   => $textdomain,
                'fields' => [ 'versions' => true ],
            ] );
        }

        $release = ( ! is_wp_error( $release ) && is_object( $release ) ) ? $release : null;

        // Cache successes only so a transient API failure can be retried later in the request.
        if ( null !== $release ) {
            $this->wporg_release_cache[ $cache_key ] = $release;
        }

        return $release;
    }

    /**
     * Get provider status without exposing secrets.
     *
     * @since 1.2.0
     * @param bool $remote_check Whether to call the provider status endpoint.
     * @return array<string,mixed> Safe provider status.
     */
    public function get_provider_status( bool $remote_check = false ): array {
        $preferred        = $this->get_preferred_provider();
        $gp_translator    = $this->get_gp_openai_translator();
        $superdav_client  = Superdav_AI_Client::instance();
        $superdav_status  = Superdav_AI_Client::get_configuration_status();
        $active_provider  = 'none';
        $fallback_message = '';

        if ( 'superdav' === $preferred && $superdav_client->is_configured() ) {
            $active_provider = 'superdav';
        } elseif ( 'gp_openai_translate' === $preferred && $gp_translator ) {
            $active_provider = 'gp_openai_translate';
        } elseif ( $gp_translator ) {
            $active_provider  = 'gp_openai_translate';
            $fallback_message = 'Superdav is preferred but incomplete; using gp-openai-translate compatibility mode.';
        } elseif ( $superdav_client->is_configured() ) {
            $active_provider = 'superdav';
        }

        $status = [
            'preferred_provider'   => $preferred,
            'active_provider'      => $active_provider,
            'fallback_message'     => $fallback_message,
            'superdav'             => $superdav_status,
            'gp_openai_translate'  => [
                'available' => null !== $gp_translator,
                'model'     => (string) get_option( 'gpoai_model', '' ),
            ],
        ];

        if ( $remote_check && 'superdav' === $active_provider ) {
            $remote_status = $superdav_client->check_status();
            $status['superdav_remote'] = is_wp_error( $remote_status )
                ? [ 'ok' => false, 'message' => $remote_status->get_error_message() ]
                : [ 'ok' => true, 'status' => $remote_status ];
        }

        return $status;
    }

    /**
     * Get the active translator, or null if no provider is available.
     *
     * @since 1.2.0
     * @return object|null Translator object exposing reset_usage(), translate_batch(), and get_accumulated_usage().
     */
    private function get_active_translator(): ?object {
        $preferred       = $this->get_preferred_provider();
        $superdav_client = Superdav_AI_Client::instance();
        $gp_translator   = $this->get_gp_openai_translator();

        if ( 'superdav' === $preferred ) {
            if ( $superdav_client->is_configured() ) {
                return $superdav_client;
            }

            return $gp_translator;
        }

        if ( $gp_translator ) {
            return $gp_translator;
        }

        return $superdav_client->is_configured() ? $superdav_client : null;
    }

    /**
     * Get the preferred provider setting.
     *
     * @since 1.2.0
     * @return string Provider slug.
     */
    private function get_preferred_provider(): string {
        $provider = strtolower( trim( (string) get_site_option( 'gratis_ai_ts_ai_provider', '' ) ) );

        if ( in_array( $provider, [ 'superdav', 'gp_openai_translate' ], true ) ) {
            return $provider;
        }

        return Superdav_AI_Client::instance()->is_configured() ? 'superdav' : 'gp_openai_translate';
    }

    /**
     * Build a provider-unavailable message safe for queue errors.
     *
     * @since 1.2.0
     * @return string Redacted message.
     */
    private function get_translator_unavailable_message(): string {
        $status = $this->get_provider_status();

        if ( 'superdav' === $status['preferred_provider'] ) {
            $missing = $status['superdav']['missing'] ?? [];
            if ( is_array( $missing ) && ! empty( $missing ) ) {
                return 'Superdav AI Service is selected but missing required configuration: ' . implode( ', ', $missing ) . '. gp-openai-translate is not available as a fallback.';
            }
        }

        return 'No AI translation provider is available. Configure Superdav AI Service or activate gp-openai-translate.';
    }

    /**
     * Check whether an AI provider error should be retried after backoff.
     *
     * @since 1.2.2
     * @param string $error_message Redacted provider error message.
     * @return bool True for transient provider/network failures.
     */
    private function is_transient_provider_error( string $error_message ): bool {
        $transient_patterns = [
            '/HTTP 5\\d\\d/i',
            // Timeouts and empty replies.
            '/cURL error 28/i',
            '/cURL error 52/i',
            '/Operation timed out/i',
            '/Empty reply from server/i',
            // Connection-level failures, e.g. while the AI service restarts:
            // 6 = could not resolve host, 7 = connection refused,
            // 35 = TLS connect error, 56 = connection reset while receiving.
            '/cURL error (?:6|7|35|56)\\b/i',
            '/Failed to connect/i',
            '/Couldn\'t connect to server/i',
            '/Connection refused/i',
            '/Connection reset/i',
        ];

        foreach ( $transient_patterns as $pattern ) {
            if ( preg_match( $pattern, $error_message ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the gp-openai-translate Translate instance, or null if unavailable.
     *
     * @since 1.0.0
     * @return \Meloniq\GpOpenaiTranslate\Translate|null
     */
    private function get_gp_openai_translator(): ?\Meloniq\GpOpenaiTranslate\Translate {
        if ( ! class_exists( '\Meloniq\GpOpenaiTranslate\Translate' ) ) {
            return null;
        }
        return \Meloniq\GpOpenaiTranslate\Translate::instance();
    }

    /**
     * Publish an immutable add-on ZIP bound to the canonical GlotPress job.
     *
     * Traduttore replaces versionless ZIPs after translation changes. Canonical
     * add-on jobs therefore return a versioned archive and matching receipt.
     *
     * @param \Required\Traduttore\ZipProvider $provider Built ZIP provider.
     * @param array<string,mixed> $job Completed job identity.
     * @param object $project GlotPress project used to export this package.
     * @return string Published package URL.
     */
    private function attest_addon_package( \Required\Traduttore\ZipProvider $provider, array $job, object $project ): string {
        $path = $provider->get_zip_path();
        $url  = $provider->get_zip_url();
        if ( ! str_starts_with( (string) $project->path, 'ultimatemultisite/' ) ) {
            return $url;
        }
        $hash = is_file( $path ) ? hash_file( 'sha256', $path ) : false;
        if ( ! is_string( $hash ) || ! preg_match( '/\A[a-f0-9]{64}\z/', $hash ) ) {
            throw new \RuntimeException( 'Could not attest the local add-on package.' );
        }
        $filename = sprintf(
            '%s-%s-%s.zip',
            sanitize_file_name( (string) $job['textdomain'] ),
            sanitize_file_name( (string) $job['version'] ),
            sanitize_file_name( (string) $job['locale'] )
        );
        $separator = strrpos( $url, '/' );
        if ( false === $separator ) {
            throw new \RuntimeException( 'Could not determine the add-on package URL.' );
        }
        $archive_path = dirname( $path ) . DIRECTORY_SEPARATOR . $filename;
        $archive_url  = substr( $url, 0, $separator + 1 ) . rawurlencode( $filename );
        $receipt      = [
            'schema'       => 2,
            'job_id'       => (int) $job['id'],
            'project_path' => (string) $project->path,
            'textdomain'   => (string) $job['textdomain'],
            'version'      => (string) $job['version'],
            'locale'       => (string) $job['locale'],
            'sha256'       => $hash,
            'filename'     => $filename,
        ];
        $this->publish_immutable_addon_package( $path, $archive_path, $hash );
        $this->publish_addon_package_receipt( $archive_path . '.job.json', $receipt );

        return $archive_url;
    }

    /**
     * Copy an archive to a version-bound name without replacing an existing one.
     *
     * @param string $source_path Mutable Traduttore archive path.
     * @param string $archive_path Immutable archive destination.
     * @param string $hash Expected SHA-256 hash.
     * @return void
     */
    private function publish_immutable_addon_package( string $source_path, string $archive_path, string $hash ): void {
        if ( is_file( $archive_path ) ) {
            if ( hash_file( 'sha256', $archive_path ) !== $hash ) {
                throw new \RuntimeException( 'A version-bound add-on package already exists with different bytes.' );
            }
            return;
        }
        $temporary = tempnam( dirname( $archive_path ), '.addon-package-' );
        if ( false === $temporary ) {
            throw new \RuntimeException( 'Could not stage the immutable add-on package.' );
        }
        try {
            if ( ! copy( $source_path, $temporary ) || hash_file( 'sha256', $temporary ) !== $hash || ! chmod( $temporary, 0644 ) ) {
                throw new \RuntimeException( 'Could not stage the immutable add-on package.' );
            }
            if ( ! link( $temporary, $archive_path ) && ! is_file( $archive_path ) ) {
                throw new \RuntimeException( 'Could not publish the immutable add-on package.' );
            }
            if ( ! is_file( $archive_path ) || hash_file( 'sha256', $archive_path ) !== $hash ) {
                throw new \RuntimeException( 'The version-bound add-on package does not match its source bytes.' );
            }
        } finally {
            if ( is_file( $temporary ) ) {
                unlink( $temporary );
            }
        }
    }

    /**
     * Publish one immutable receipt or verify the existing receipt identity.
     *
     * @param string              $path Receipt destination.
     * @param array<string,mixed> $receipt Expected receipt fields.
     * @return void
     */
    private function publish_addon_package_receipt( string $path, array $receipt ): void {
        $existing = $this->read_addon_package_receipt( $path );
        if ( is_array( $existing ) ) {
            if ( ! $this->matches_addon_package_receipt( $existing, $receipt ) ) {
                throw new \RuntimeException( 'A version-bound add-on package receipt already exists with different provenance.' );
            }
            return;
        }
        $temporary = tempnam( dirname( $path ), '.addon-job-' );
        if ( false === $temporary ) {
            throw new \RuntimeException( 'Could not stage the add-on package receipt.' );
        }
        try {
            $encoded = wp_json_encode( $receipt );
            if ( ! is_string( $encoded ) || false === file_put_contents( $temporary, $encoded . "\n" ) || ! chmod( $temporary, 0644 ) ) {
                throw new \RuntimeException( 'Could not stage the add-on package receipt.' );
            }
            if ( ! link( $temporary, $path ) && ! is_file( $path ) ) {
                throw new \RuntimeException( 'Could not publish the add-on package receipt.' );
            }
            $published = $this->read_addon_package_receipt( $path );
            if ( ! is_array( $published ) || ! $this->matches_addon_package_receipt( $published, $receipt ) ) {
                throw new \RuntimeException( 'The version-bound add-on package receipt does not match its provenance.' );
            }
        } finally {
            if ( is_file( $temporary ) ) {
                unlink( $temporary );
            }
        }
    }

    /**
     * Read a receipt without treating malformed data as provenance.
     *
     * @param string $path Receipt path.
     * @return array<string,mixed>|null
     */
    private function read_addon_package_receipt( string $path ): ?array {
        if ( ! is_file( $path ) ) {
            return null;
        }
        $decoded = json_decode( (string) file_get_contents( $path ), true );

        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * Compare the complete expected receipt identity without accepting extras.
     *
     * @param array<string,mixed> $actual Actual receipt.
     * @param array<string,mixed> $expected Expected receipt.
     * @return bool Whether the receipt proves the expected package identity.
     */
    private function matches_addon_package_receipt( array $actual, array $expected ): bool {
        return $actual === $expected;
    }

    /**
     * Recover a legacy package only when its existing receipt matches its bytes.
     * Never bind regenerated mutable output to a different completed job.
     *
     * @param string $path Generated local ZIP path.
     * @param string $url Public ZIP URL.
     * @param \GP_Translation_Set $set Exported GlotPress translation set.
     * @param \Required\Traduttore\Project $project Canonical project decorator.
     */
    public function attest_regenerated_addon_package(
        string $path,
        string $url,
        \GP_Translation_Set $set,
        \Required\Traduttore\Project $project
    ): void {
        $source = $project->get_project();
        $slug   = $project->get_slug();
        if ( ! preg_match( '/\Aultimate-multisite-[a-z0-9-]+\z/', $slug )
            || (string) $source->path !== 'ultimatemultisite/' . $slug
            || (int) $set->project_id !== (int) $source->id ) {
            return;
        }
        $locale = \GP_Locales::by_slug( $set->locale );
        if ( ! $locale || ! is_string( $locale->wp_locale ) ) {
            return;
        }
        $provider = new \Required\Traduttore\ZipProvider( $set );
        if ( $provider->get_zip_path() !== $path ) {
            return;
        }
        $receipt = $this->read_addon_package_receipt( $path . '.job.json' );
        $hash    = is_file( $path ) ? hash_file( 'sha256', $path ) : false;
        if ( ! is_array( $receipt ) || 1 !== (int) ( $receipt['schema'] ?? 0 ) || ! is_string( $hash )
            || ! isset( $receipt['sha256'], $receipt['job_id'], $receipt['project_path'], $receipt['textdomain'], $receipt['version'], $receipt['locale'] )
            || $hash !== $receipt['sha256'] || (string) $source->path !== $receipt['project_path']
            || $slug !== $receipt['textdomain'] || $locale->wp_locale !== $receipt['locale'] ) {
            return;
        }
        $job = Translation_Queue::instance()->get_job_by_id( (int) $receipt['job_id'] );
        if ( ! $job || 'completed' !== $job['status'] || 'plugin' !== $job['target_type']
            || $slug !== $job['textdomain'] || $receipt['version'] !== $job['version']
            || $locale->wp_locale !== $job['locale'] || $url !== $job['package_url'] ) {
            return;
        }
        try {
            $archive_url = $this->attest_addon_package( $provider, $job, $source );
        } catch ( \RuntimeException $e ) {
            error_log( 'Gratis AI Translations Server could not safely recover legacy add-on package job ' . (int) $job['id'] . ': ' . $e->getMessage() );
            return;
        }
        global $wpdb;
        $wpdb->update(
            $wpdb->base_prefix . 'gratis_ai_translation_jobs',
            [ 'package_url' => $archive_url ],
            [ 'id' => (int) $job['id'], 'status' => 'completed', 'package_url' => $url ],
            [ '%s' ],
            [ '%d', '%s', '%s' ]
        );
    }

    /**
     * Get or create GlotPress project.
     *
     * @since 1.0.0
     * @param string $target_type Target type: 'plugin' or 'theme'.
     * @param string $textdomain  Plugin/theme textdomain or slug.
     * @param string $version     Plugin/theme version.
     * @return object|null Project object.
     */
    private function get_or_create_project( string $target_type, string $textdomain, string $version ): ?object {
        $metadata = self::resolve_target_metadata( $target_type, $textdomain, $version );
        if ( ! $metadata || 'core' === $metadata['target_type'] ) {
            return null;
        }

        $target_type = (string) $metadata['target_type'];
        $textdomain  = (string) $metadata['textdomain'];
        $parent_slug = (string) $metadata['project_parent_slug'];
        $parent_name = (string) $metadata['project_parent_name'];

        $project = \GP::$project->by_path( "{$parent_slug}/{$textdomain}" );

        if ( $project ) {
            return $project;
        }

        // Ensure parent project exists.
        $parent = \GP::$project->by_path( $parent_slug );

        if ( ! $parent ) {
            $parent = \GP::$project->create( [
                'name'              => $parent_name,
                'slug'              => $parent_slug,
                'description'       => "WordPress {$parent_name}",
                'parent_project_id' => null,
                'active'            => 1,
            ] );
        }

        if ( ! $parent ) {
            return null;
        }

        $project = \GP::$project->create( [
            'name'              => ucwords( str_replace( [ '-', '_' ], ' ', $textdomain ) ),
            'slug'              => $textdomain,
            'description'       => "AI Translations for {$target_type} {$textdomain}",
            'parent_project_id' => $parent->id,
            'active'            => 1,
        ] );

        return $project ?: null;
    }

    /**
     * Import a complete originals file (POT or full PO export) into a project.
     *
     * Translations are stripped first: import_for_project() would otherwise
     * create translations and skip untranslated entries. The file must contain
     * every original because import_for_project() obsoletes absent ones.
     *
     * @since 1.0.0
     * @param object $project GlotPress project.
     * @param string $path    POT/PO file path.
     * @return bool True on success.
     */
    private function import_originals_file( object $project, string $path ): bool {
        global $wpdb;

        if ( ! class_exists( 'PO' ) ) {
            require_once ABSPATH . WPINC . '/pomo/po.php';
        }

        $po = new \PO();
        if ( ! $po->import_from_file( $path ) || empty( $po->entries ) ) {
            return false;
        }

        $originals_for_import = new \PO();
        foreach ( $po->entries as $key => $entry ) {
            $original                              = clone $entry;
            $original->translations                = [];
            $originals_for_import->entries[ $key ] = $original;
        }

        $stats = \GP::$original->import_for_project( $project, $originals_for_import );

        // import_for_project returns [$added, $existing, $fuzzied, $obsoleted, $error].
        // Success if any strings were added or existing ones found.
        if ( is_array( $stats ) && ( $stats[0] > 0 || $stats[1] > 0 ) ) {
            return true;
        }

        $new_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->gp_originals} WHERE project_id = %d AND status = '+active'",
            $project->id
        ) );

        return $new_count > 0;
    }

    /**
     * Import missing human translations from an exact WordPress.org release.
     *
     * Silently no-ops if the plugin isn't on wordpress.org or has no
     * translations for the requested locale.
     *
     * @since 1.0.0
     * @param object $project         GlotPress project.
     * @param object $translation_set GlotPress translation set.
     * @param string $target_type     Target type: 'plugin' or 'theme'.
     * @param string $textdomain      Plugin/theme textdomain or slug.
     * @param string $version         Requested plugin/theme version.
     * @param string $locale          WordPress locale (e.g. 'fr_FR').
     * @return void
     */
    private function import_human_translations( object $project, object $translation_set, string $target_type, string $textdomain, string $version, string $locale ): void {
        $target_type = $this->normalize_target_type( $target_type );
        $this->import_human_translations_fallback( $project, $translation_set, $target_type, $textdomain, $version, $locale );
    }

    /**
     * Fallback human translation import when gp-openai-translate is not active.
     *
     * @since 1.2.0
     * @param object $project         GlotPress project.
     * @param object $translation_set GlotPress translation set.
     * @param string $target_type     Target type: 'plugin' or 'theme'.
     * @param string $textdomain      Plugin/theme textdomain or slug.
     * @param string $version         Requested plugin/theme version.
     * @param string $locale          WordPress locale (e.g. 'fr_FR').
     * @return void
     */
    private function import_human_translations_fallback( object $project, object $translation_set, string $target_type, string $textdomain, string $version, string $locale ): void {
        $metadata = self::resolve_target_metadata( $target_type, $textdomain );
        if ( ! $metadata || 'core' === $metadata['target_type'] ) {
            return;
        }

        // Use WordPress core's translations_api() to get the correct package URL.
        if ( ! function_exists( 'translations_api' ) ) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }

        $api_type = (string) $metadata['wporg_translation_type'];
        $api      = translations_api( $api_type, [ 'slug' => $textdomain, 'version' => $version ] );
        if ( is_wp_error( $api ) || empty( $api['translations'] ) ) {
            return;
        }

        $package_url = null;
        foreach ( $api['translations'] as $entry ) {
            if ( ( $entry['language'] ?? '' ) === $locale
                && ( $entry['version'] ?? '' ) === $version
                && ! empty( $entry['package'] ) ) {
                $package_url = $entry['package'];
                break;
            }
        }

        if ( ! $package_url ) {
            return;
        }

        $response = wp_remote_get( $package_url, [ 'timeout' => 30 ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return;
        }

        $zip_content = wp_remote_retrieve_body( $response );
        if ( empty( $zip_content ) ) {
            return;
        }

        $tmp_zip = get_temp_dir() . $textdomain . '-' . $locale . '-human.zip';
        file_put_contents( $tmp_zip, $zip_content );

        $zip = new \ZipArchive();
        if ( $zip->open( $tmp_zip ) !== true ) {
            @unlink( $tmp_zip );
            return;
        }

        $po_content = null;
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = $zip->getNameIndex( $i );
            if ( substr( $name, -3 ) === '.po' ) {
                $po_content = $zip->getFromIndex( $i );
                break;
            }
        }
        $zip->close();
        @unlink( $tmp_zip );

        if ( empty( $po_content ) ) {
            return;
        }

        $tmp_po = get_temp_dir() . $textdomain . '-' . $locale . '-human.po';
        file_put_contents( $tmp_po, $po_content );

        $po = new \PO();
        if ( ! $po->import_from_file( $tmp_po ) ) {
            @unlink( $tmp_po );
            return;
        }
        @unlink( $tmp_po );

        $imported = 0;
        foreach ( $po->entries as $entry ) {
            if ( empty( $entry->translations ) ) {
                continue;
            }

            $original = \GP::$original->by_project_id_and_entry( $project->id, $entry, '+active' );

            if ( ! $original ) {
                continue;
            }

            if ( Human_Translations::import_entry( $original, $translation_set, $entry ) ) {
                $imported++;
            }
        }
    }

    /**
     * Generate a POT file from local plugin/theme source using wp i18n make-pot.
     *
     * This is the fallback for non-WordPress.org targets that are installed
     * locally without a bundled .pot file.
     *
     * @since 1.1.1
     * @param string $target_type Target type: 'plugin' or 'theme'.
     * @param string $textdomain  Plugin/theme textdomain or slug.
     * @param string $version     Plugin/theme version.
     * @return string|null Path to generated POT file, or null on failure.
     */
    private function generate_pot_from_source( string $target_type, string $textdomain, string $version ): ?string {
        $target_type = $this->normalize_target_type( $target_type );
        $source_dir  = 'theme' === $target_type
            ? $this->get_theme_directory( $textdomain )
            : WP_PLUGIN_DIR . '/' . $textdomain;

        if ( ! is_dir( $source_dir ) || ! function_exists( 'exec' ) ) {
            return null;
        }

        $temp_file = $this->temp_file( 'gratis-ai-' . $textdomain . '-' . $version . '.pot' );
        if ( ! $temp_file ) {
            return null;
        }

        // Use WP-CLI's i18n make-pot command.
        $wp_cli = $this->find_wp_cli();

        if ( ! $wp_cli ) {
            return null;
        }

        $command = sprintf(
            '%s i18n make-pot %s %s --domain=%s 2>&1',
            escapeshellarg( $wp_cli ),
            escapeshellarg( $source_dir ),
            escapeshellarg( $temp_file ),
            escapeshellarg( $textdomain )
        );

        exec( $command, $output, $return_code );

        if ( $return_code !== 0 || ! file_exists( $temp_file ) || filesize( $temp_file ) === 0 ) {
            @unlink( $temp_file );
            return null;
        }

        return $temp_file;
    }

    /**
     * Find the WP-CLI binary path.
     *
     * @since 1.1.1
     * @return string|null Path to wp-cli binary, or null if not found.
     */
    private function find_wp_cli(): ?string {
        $candidates = [
            '/usr/local/bin/wp',
            '/usr/bin/wp',
            ABSPATH . 'wp',
            ABSPATH . '../vendor/bin/wp',
        ];

        // Check if wp is in PATH.
        $which = function_exists( 'shell_exec' ) ? trim( (string) shell_exec( 'which wp 2>/dev/null' ) ) : '';
        if ( ! empty( $which ) && is_executable( $which ) ) {
            return $which;
        }

        foreach ( $candidates as $path ) {
            if ( file_exists( $path ) && is_executable( $path ) ) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Find a POT file in the locally installed plugin/theme directory.
     *
     * Checks common locations: languages/, lang/, i18n/, and the plugin root.
     *
     * @since 1.0.0
     * @param string $target_type Target type: 'plugin' or 'theme'.
     * @param string $textdomain  Plugin/theme textdomain or slug.
     * @return string|null Absolute path to POT file, or null.
     */
    private function find_local_pot( string $target_type, string $textdomain ): ?string {
        $target_type = $this->normalize_target_type( $target_type );
        $source_dir  = 'theme' === $target_type
            ? $this->get_theme_directory( $textdomain )
            : WP_PLUGIN_DIR . '/' . $textdomain;

        if ( ! is_dir( $source_dir ) ) {
            return null;
        }

        $candidates = [
            $source_dir . '/languages/' . $textdomain . '.pot',
            $source_dir . '/lang/' . $textdomain . '.pot',
            $source_dir . '/i18n/' . $textdomain . '.pot',
            $source_dir . '/' . $textdomain . '.pot',
        ];

        foreach ( $candidates as $path ) {
            if ( file_exists( $path ) && filesize( $path ) > 0 ) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get the local theme directory for a theme slug.
     *
     * @since 1.2.0
     * @param string $stylesheet Theme stylesheet/slug.
     * @return string Theme directory path.
     */
    private function get_theme_directory( string $stylesheet ): string {
        if ( function_exists( 'get_theme_root' ) ) {
            return trailingslashit( get_theme_root( $stylesheet ) ) . $stylesheet;
        }

        return WP_CONTENT_DIR . '/themes/' . $stylesheet;
    }

    /**
     * Normalize a target type.
     *
     * @since 1.2.0
     * @param string|null $target_type Candidate target type.
     * @return string Normalized target type.
     */
    private function normalize_target_type( ?string $target_type ): string {
        return Translation_Queue::normalize_target_type( $target_type );
    }

    /**
     * Get or create translation set.
     *
     * @since 1.0.0
     * @param object $project GlotPress project.
     * @param string $locale  Target locale.
     * @return object|null Translation set object.
     */
    private function get_or_create_translation_set( object $project, string $locale ): ?object {
        // Resolve the GlotPress locale slug from the WordPress locale.
        // e.g. 'fr_FR' (WP) -> 'fr' (GP slug).
        $locale_obj = \GP_Locales::by_field( 'wp_locale', $locale )
            ?: \GP_Locales::by_slug( $locale );

        $gp_locale = $locale_obj ? $locale_obj->slug : $locale;

        $translation_set = \GP::$translation_set->by_project_id_slug_and_locale(
            $project->id,
            'default',
            $gp_locale
        );

        if ( $translation_set ) {
            return $translation_set;
        }

        if ( ! $locale_obj ) {
            return null;
        }

        $translation_set = \GP::$translation_set->create( [
            'project_id' => $project->id,
            'name'       => $locale_obj->english_name,
            'slug'       => 'default',
            'locale'     => $gp_locale,
        ] );

        return $translation_set ?: null;
    }

    /**
     * Translate singulars together and plural originals with every required form.
     * Do not save any partial response from a malformed batch.
     *
     * @return array<int,string|array<int,string>>|\WP_Error
     */
    private function translate_originals( object $translator, array $batch, object $locale, int $project_id ) {
        $singulars = [];
        $contexts = [];
        $ids = [];
        foreach ( $batch as $index => $original ) {
            if ( ! $original->plural ) {
                $singulars[ $index ] = $original->singular;
                $contexts[]          = $original->context;
                $ids[]               = $original->id;
            }
        }

        $result = [];
        if ( $singulars ) {
            $translated = $this->translate_batch_with_split( $translator, (string) $locale->slug, array_values( $singulars ), $contexts, $ids, $project_id );
            if ( is_wp_error( $translated ) ) {
                return $translated;
            }
            if ( ! is_array( $translated ) || count( $translated ) !== count( $singulars ) ) {
                return new \WP_Error( 'invalid_singular_batch', 'The provider returned an incomplete singular batch.' );
            }
            foreach ( array_keys( $singulars ) as $offset => $index ) {
                // Items rejected by repair_invalid_batch_items() arrive as ''. Keep the
                // position but leave the string untranslated (save_translations() skips
                // empty values) so one bad item cannot fail the whole job.
                $result[ $index ] = isset( $translated[ $offset ] ) && is_string( $translated[ $offset ] ) && '' !== trim( $translated[ $offset ] )
                    ? $translated[ $offset ]
                    : '';
            }
        }

        $nplurals = (int) $locale->nplurals;
        foreach ( $batch as $index => $original ) {
            if ( ! $original->plural ) {
                continue;
            }
            if ( $nplurals < 1 || $nplurals > 6 || ! method_exists( $translator, 'translate_plural' ) ) {
                return new \WP_Error( 'plural_provider_unavailable', 'This provider cannot translate all required plural forms.' );
            }
            $context = trim( (string) $original->context . ' ' . (string) $original->comment );
            // When a form serves n=1 and other numbers (bs "1, 21, 31"; fr/fa "0, 1") but the
            // singular source lacks the plural's placeholders ("day" vs "%s days"), translators
            // keep the plural's placeholders in that shared form ("%s dan"). validate_plural_forms()
            // already checks shared forms against the plural source; tell the model explicitly.
            if ( ! self::placeholders_match( $original->singular, $original->plural ) ) {
                $shared = [];
                for ( $form_index = 0; $form_index < $nplurals; $form_index++ ) {
                    $examples = $locale->numbers_for_index( $form_index );
                    if ( in_array( 1, $examples, true ) && count( $examples ) > 1 ) {
                        $shared[] = sprintf( 'form%d (used for %s)', $form_index, implode( ', ', array_slice( $examples, 0, 3 ) ) );
                    }
                }
                if ( $shared ) {
                    $context = trim( $context . ' ' . sprintf(
                        'In this locale %s also covers numbers other than 1, so it must keep every placeholder from the plural source (%s), e.g. "%%s days" -> a form containing %%s. Still inflect each form grammatically for its own example numbers; forms usually differ.',
                        implode( ' and ', $shared ),
                        $original->plural
                    ) );
                }
            }
            $forms = null;
            for ( $attempt = 1; $attempt <= self::PLURAL_ATTEMPTS; $attempt++ ) {
                $forms = $translator->translate_plural(
                    $original->singular, $original->plural, $locale->slug, $nplurals,
                    $context, (int) $original->id, $project_id
                );
                $forms = $this->validate_plural_forms( $forms, $original, $locale, $nplurals );
                // Only malformed model output is worth another attempt.
                if ( ! is_wp_error( $forms )
                    || ! in_array( $forms->get_error_code(), [ 'superdav_invalid_plural', 'invalid_plural_forms', 'invalid_plural_placeholders' ], true )
                ) {
                    break;
                }
            }
            if ( is_wp_error( $forms ) ) {
                return $forms;
            }
            $result[ $index ] = $forms;
        }

        ksort( $result );
        return array_values( $result );
    }

    /**
     * Validate provider plural output against the locale's required forms.
     *
     * @param mixed  $forms    Provider result.
     * @param object $original GlotPress original with singular/plural.
     * @param object $locale   GP_Locale.
     * @param int    $nplurals Required form count.
     * @return array<int,string>|\WP_Error Complete forms or error.
     */
    private function validate_plural_forms( $forms, object $original, object $locale, int $nplurals ) {
        if ( is_wp_error( $forms ) ) {
            return $forms;
        }
        if ( ! is_array( $forms ) || array_keys( $forms ) !== range( 0, $nplurals - 1 ) ) {
            return new \WP_Error( 'invalid_plural_forms', 'The provider returned an incomplete plural translation.' );
        }
        foreach ( $forms as $form_index => $form ) {
            if ( ! is_string( $form ) || '' === trim( $form ) ) {
                return new \WP_Error( 'invalid_plural_placeholders', 'A plural form is empty or changes source placeholders.' );
            }
            // Every form may use the plural source's placeholders: _n() callers always pass
            // the arguments the plural needs, so "Show %d more" renders "1" for n=1. A form
            // used only for n=1 may alternatively match the singular ("Show 1 more").
            $valid = self::placeholders_match( $original->plural, $form )
                || ( [ 1 ] === $locale->numbers_for_index( $form_index ) && self::placeholders_match( $original->singular, $form ) );
            if ( ! $valid ) {
                return new \WP_Error( 'invalid_plural_placeholders', 'A plural form is empty or changes source placeholders.' );
            }
        }

        return $forms;
    }

    /**
     * Translate a singular batch, halving it when the model returns a short or
     * malformed response instead of failing the whole job.
     *
     * Order is preserved: each half is translated independently and the results
     * are concatenated. A one-string batch is retried once. Depth is bounded by
     * MAX_BATCH_SPLIT_DEPTH so a persistently failing provider still fails fast.
     *
     * @param object   $translator   Translator exposing translate_batch().
     * @param string   $gp_locale    GlotPress locale slug.
     * @param string[] $strings      Source strings.
     * @param array    $contexts     Contexts aligned with $strings.
     * @param array    $original_ids Original IDs aligned with $strings.
     * @param int      $project_id   GlotPress project ID.
     * @param int      $depth        Current split depth.
     * @return array<int,string>|\WP_Error Ordered translations or error.
     */
    private function translate_batch_with_split(
        object $translator,
        string $gp_locale,
        array $strings,
        array $contexts,
        array $original_ids,
        int $project_id,
        int $depth = 0
    ) {
        $strings      = array_values( $strings );
        $contexts     = array_values( $contexts );
        $original_ids = array_values( $original_ids );
        $count        = count( $strings );

        $translated = $translator->translate_batch( $gp_locale, $strings, $contexts, $original_ids, $project_id );
        if ( ! is_wp_error( $translated ) && is_array( $translated ) && count( $translated ) === $count ) {
            return $this->repair_invalid_batch_items( $translator, $gp_locale, $strings, $contexts, $original_ids, $project_id, array_values( $translated ) );
        }

        if ( is_wp_error( $translated ) && ! in_array( $translated->get_error_code(), self::SPLITTABLE_BATCH_ERROR_CODES, true ) ) {
            return $translated;
        }

        $error = is_wp_error( $translated )
            ? $translated
            : new \WP_Error(
                'translation_count_mismatch',
                sprintf(
                    'Translation provider returned %d translations for %d source strings.',
                    is_array( $translated ) ? count( $translated ) : 0,
                    $count
                )
            );

        if ( $depth >= self::MAX_BATCH_SPLIT_DEPTH ) {
            return $error;
        }

        if ( $count <= 1 ) {
            // Retry a single string once; it cannot be split further.
            return $this->translate_batch_with_split( $translator, $gp_locale, $strings, $contexts, $original_ids, $project_id, self::MAX_BATCH_SPLIT_DEPTH );
        }

        $middle = intdiv( $count, 2 );
        $merged = [];
        foreach ( [ [ 0, $middle ], [ $middle, $count - $middle ] ] as [ $offset, $length ] ) {
            $part = $this->translate_batch_with_split(
                $translator,
                $gp_locale,
                array_slice( $strings, $offset, $length ),
                array_slice( $contexts, $offset, $length ),
                array_slice( $original_ids, $offset, $length ),
                $project_id,
                $depth + 1
            );
            if ( is_wp_error( $part ) ) {
                return $part;
            }
            $merged = array_merge( $merged, $part );
        }

        return $merged;
    }

    /**
     * Validate each item of a count-matched batch and re-request invalid ones.
     *
     * Providers occasionally reorder items, echo the English source, or leak a
     * neighbour's placeholders. Invalid items are retried individually; items
     * that remain invalid are returned as '' so they stay untranslated instead
     * of being saved.
     *
     * @param object   $translator   Translator exposing translate_batch().
     * @param string   $gp_locale    GlotPress locale slug.
     * @param string[] $strings      Source strings.
     * @param array    $contexts     Contexts aligned with $strings.
     * @param array    $original_ids Original IDs aligned with $strings.
     * @param int      $project_id   GlotPress project ID.
     * @param array    $translated   Provider results aligned with $strings.
     * @return array<int,string> Validated translations ('' for rejected items).
     */
    private function repair_invalid_batch_items(
        object $translator,
        string $gp_locale,
        array $strings,
        array $contexts,
        array $original_ids,
        int $project_id,
        array $translated
    ): array {
        foreach ( $translated as $index => $translation ) {
            if ( self::is_valid_batch_translation( (string) $strings[ $index ], $translation, $gp_locale ) ) {
                continue;
            }

            $retry = $translator->translate_batch(
                $gp_locale,
                [ $strings[ $index ] ],
                [ $contexts[ $index ] ?? '' ],
                [ $original_ids[ $index ] ?? 0 ],
                $project_id
            );
            $candidate = ( ! is_wp_error( $retry ) && is_array( $retry ) && 1 === count( $retry ) ) ? reset( $retry ) : null;

            $translated[ $index ] = self::is_valid_batch_translation( (string) $strings[ $index ], $candidate, $gp_locale )
                ? $candidate
                : '';
        }

        return $translated;
    }

    /**
     * Whether a single-string translation is safe to save.
     *
     * @param string $source    Source string.
     * @param mixed  $candidate Provider result.
     * @param string $gp_locale GlotPress locale slug.
     * @return bool True when non-empty, placeholder-equivalent, and not an English echo.
     */
    private static function is_valid_batch_translation( string $source, $candidate, string $gp_locale ): bool {
        if ( ! is_string( $candidate ) || '' === trim( $candidate ) || ! self::placeholders_match( $source, $candidate ) ) {
            return false;
        }

        // An unchanged English sentence is an untranslated echo. Proper names
        // ("London Borough of Barnet", "AWS Secret Access Key"), URLs, emails and
        // CSS classes are legitimately unchanged.
        if ( ! str_starts_with( $gp_locale, 'en' ) && trim( $candidate ) === trim( $source )
            && self::looks_like_english_sentence( $source ) ) {
            return false;
        }

        return true;
    }

    /**
     * Whether text reads as an English sentence: at least three whitespace-separated
     * plain lowercase words of 3+ letters (optionally ending in punctuation).
     *
     * @param string $source Source string.
     * @return bool True for sentences such as "Please try again.", false for names, URLs, emails.
     */
    private static function looks_like_english_sentence( string $source ): bool {
        $words = 0;
        foreach ( preg_split( '/\s+/u', trim( wp_strip_all_tags( $source ) ) ) ?: [] as $token ) {
            if ( preg_match( '/\A[a-z]{3,}[.,;:!?]?\z/', $token ) ) {
                $words++;
            }
        }

        return $words >= 3;
    }

    /**
     * Compare the printf placeholders without requiring their order in a sentence.
     *
     * The space flag is excluded so literal percentages ("20% on", "49% of")
     * are not mistaken for "% o" conversions.
     */
    private static function placeholders_match( string $source, string $translated ): bool {
        $pattern = '/(?<!%)%(?!%)(?:[1-9][0-9]*\$)?[-+0#]*[0-9]*(?:\.[0-9]+)?[bcdeEfFgGosuxX]/';
        preg_match_all( $pattern, $source, $source_matches );
        preg_match_all( $pattern, $translated, $translated_matches );
        $expected = $source_matches[0];
        $actual   = $translated_matches[0];
        sort( $expected );
        sort( $actual );
        return $expected === $actual;
    }

    /**
     * Refuse to publish a package with absent or incomplete plural translations.
     * Existing incomplete rows must be repaired separately, never overwritten as
     * if they were newly untranslated human strings.
     */
    private function require_complete_plurals( object $project, object $translation_set ): void {
        global $wpdb;

        $locale = \GP_Locales::by_slug( (string) $translation_set->locale );
        $count = $locale ? (int) $locale->nplurals : 0;
        if ( $count < 1 || $count > 6 ) {
            throw new \RuntimeException( 'Cannot verify plural forms for the requested locale.' );
        }

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->gp_originals} o
             LEFT JOIN {$wpdb->gp_translations} t ON t.original_id = o.id
                AND t.translation_set_id = %d AND t.status = 'current'
             WHERE o.project_id = %d
             AND o.status = '+active' AND o.plural IS NOT NULL AND o.plural <> ''
             AND (t.id IS NULL OR " . self::missing_plural_forms_sql( $count ) . ')',
            $translation_set->id,
            $project->id
        );
        $incomplete = $wpdb->get_var( $sql );
        if ( null === $incomplete || (int) $incomplete > 0 ) {
            throw new \RuntimeException( sprintf(
                '%s block package publication (%s affected); repair with `wp gratis-ai-server repair-plurals --project=%s --locale=%s`.',
                self::INCOMPLETE_PLURALS_ERROR_PREFIX,
                null === $incomplete ? 'unknown' : (string) (int) $incomplete,
                (string) ( $project->path ?? $project->id ),
                (string) $translation_set->locale
            ) );
        }
    }

    /**
     * SQL condition matching a current row that lacks any required plural form.
     *
     * @param int $nplurals Locale form count (validated 1–6 by callers).
     * @return string Condition over alias `t`.
     */
    private static function missing_plural_forms_sql( int $nplurals ): string {
        $missing = [];
        for ( $index = 0; $index < $nplurals; $index++ ) {
            $field     = 't.translation_' . $index;
            $missing[] = "({$field} IS NULL OR {$field} = '')";
        }

        return '(' . implode( ' OR ', $missing ) . ')';
    }

    /**
     * List translation sets whose current plural rows lack required forms.
     *
     * AI rows (`user_id IS NULL`) are repairable by default. Imported or
     * reviewed human rows (`user_id` set) are reported separately and only
     * repaired with explicit opt-in.
     *
     * @param string $project_path Optional GlotPress project path filter.
     * @param string $locale       Optional GlotPress locale slug filter.
     * @return array<int,array<string,mixed>> One entry per affected set.
     */
    public function find_incomplete_plural_sets( string $project_path = '', string $locale = '' ): array {
        global $wpdb;

        $where  = [ '1=1' ];
        $params = [];
        if ( '' !== $project_path ) {
            // Accept the full path (plugins/foo) or the bare slug (foo).
            $where[]  = '(p.path = %s OR p.slug = %s)';
            $params[] = $project_path;
            $params[] = $project_path;
        }
        if ( '' !== $locale ) {
            $where[]  = 'ts.locale = %s';
            $params[] = $locale;
        }

        $sql = "SELECT ts.id, ts.locale, ts.slug, ts.project_id, p.path AS project_path
            FROM {$wpdb->gp_translation_sets} ts
            JOIN {$wpdb->gp_projects} p ON p.id = ts.project_id
            WHERE " . implode( ' AND ', $where ) . "
            AND EXISTS (
                SELECT 1 FROM {$wpdb->gp_originals} o
                WHERE o.project_id = ts.project_id AND o.status = '+active'
                AND o.plural IS NOT NULL AND o.plural <> ''
            )
            ORDER BY p.path, ts.locale";
        $sets = $wpdb->get_results( $params ? $wpdb->prepare( $sql, ...$params ) : $sql );

        $affected = [];
        foreach ( (array) $sets as $set ) {
            $gp_locale = \GP_Locales::by_slug( (string) $set->locale );
            $nplurals  = $gp_locale ? (int) $gp_locale->nplurals : 0;
            if ( $nplurals < 1 || $nplurals > 6 ) {
                continue;
            }

            $counts = $wpdb->get_row( $wpdb->prepare(
                "SELECT SUM(t.user_id IS NULL) AS ai_rows, SUM(t.user_id IS NOT NULL) AS human_rows
                 FROM {$wpdb->gp_translations} t
                 JOIN {$wpdb->gp_originals} o ON o.id = t.original_id
                 WHERE t.translation_set_id = %d AND t.status = 'current'
                 AND o.project_id = %d AND o.status = '+active'
                 AND o.plural IS NOT NULL AND o.plural <> ''
                 AND " . self::missing_plural_forms_sql( $nplurals ),
                $set->id,
                $set->project_id
            ) );

            $ai_rows    = (int) ( $counts->ai_rows ?? 0 );
            $human_rows = (int) ( $counts->human_rows ?? 0 );
            if ( $ai_rows + $human_rows > 0 ) {
                $affected[] = [
                    'translation_set_id' => (int) $set->id,
                    'project_id'         => (int) $set->project_id,
                    'project'            => (string) $set->project_path,
                    'locale'             => (string) $set->locale,
                    'nplurals'           => $nplurals,
                    'ai_rows'            => $ai_rows,
                    'human_rows'         => $human_rows,
                ];
            }
        }

        return $affected;
    }

    /**
     * Fetch the full incomplete rows for one set, for backup before repair.
     *
     * @param array<string,mixed> $set           Entry from find_incomplete_plural_sets().
     * @param bool                $include_human Whether human rows are included.
     * @param int                 $limit         Maximum rows.
     * @return array<int,array<string,mixed>> Rows with original source fields.
     */
    public function get_incomplete_plural_rows( array $set, bool $include_human, int $limit ): array {
        global $wpdb;

        $author = $include_human ? '' : ' AND t.user_id IS NULL';
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.*, o.singular AS original_singular, o.plural AS original_plural,
                o.context AS original_context, o.comment AS original_comment
             FROM {$wpdb->gp_translations} t
             JOIN {$wpdb->gp_originals} o ON o.id = t.original_id
             WHERE t.translation_set_id = %d AND t.status = 'current'
             AND o.project_id = %d AND o.status = '+active'
             AND o.plural IS NOT NULL AND o.plural <> ''
             AND " . self::missing_plural_forms_sql( (int) $set['nplurals'] ) . "{$author}
             ORDER BY t.id
             LIMIT %d",
            $set['translation_set_id'],
            $set['project_id'],
            max( 1, $limit )
        ), ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Replace one incomplete plural row with a complete AI translation.
     *
     * The previous row is demoted to `old` (kept for audit/rollback), never
     * deleted. A new `current` row with every locale form and AI provenance is
     * created; on any failure the previous row is restored to `current`.
     *
     * @param array<string,mixed> $row Row from get_incomplete_plural_rows().
     * @param array<string,mixed> $set Entry from find_incomplete_plural_sets().
     * @return array<string,mixed>|\WP_Error Receipt or error.
     */
    public function repair_incomplete_plural_row( array $row, array $set ) {
        if ( ! function_exists( 'gp_update_meta' ) ) {
            return new \WP_Error( 'glotpress_unavailable', 'GlotPress meta API is unavailable.' );
        }

        $translator = $this->get_active_translator();
        if ( ! $translator ) {
            return new \WP_Error( 'translator_unavailable', $this->get_translator_unavailable_message() );
        }

        $locale = \GP_Locales::by_slug( (string) $set['locale'] );
        if ( ! $locale ) {
            return new \WP_Error( 'locale_unavailable', 'The locale is not available in GlotPress.' );
        }

        $original = (object) [
            'id'       => (int) $row['original_id'],
            'singular' => (string) $row['original_singular'],
            'plural'   => (string) $row['original_plural'],
            'context'  => (string) ( $row['original_context'] ?? '' ),
            'comment'  => (string) ( $row['original_comment'] ?? '' ),
        ];

        $translated = $this->translate_originals( $translator, [ $original ], $locale, (int) $set['project_id'] );
        if ( is_wp_error( $translated ) ) {
            return $translated;
        }
        $forms = $translated[0] ?? null;
        if ( ! is_array( $forms ) || count( $forms ) !== (int) $set['nplurals'] ) {
            return new \WP_Error( 'invalid_plural_forms', 'The provider returned an incomplete plural translation.' );
        }

        $previous = \GP::$translation->get( (int) $row['id'] );
        if ( ! $previous || 'current' !== $previous->status ) {
            return new \WP_Error( 'row_changed', 'The row is no longer the current translation; skipped.' );
        }
        if ( ! $previous->save( [ 'status' => 'old' ] ) ) {
            return new \WP_Error( 'demote_failed', 'Could not demote the incomplete row.' );
        }

        $data = [
            'original_id'        => (int) $row['original_id'],
            'translation_set_id' => (int) $set['translation_set_id'],
            'status'             => 'current',
        ];
        foreach ( array_values( $forms ) as $form_index => $form ) {
            $data[ 'translation_' . $form_index ] = $form;
        }

        $replacement = \GP::$translation->create( $data );
        if ( ! is_object( $replacement ) ) {
            $previous->save( [ 'status' => 'current' ] );
            return new \WP_Error( 'create_failed', 'Could not create the repaired row; previous row restored.' );
        }
        if ( false === gp_update_meta( $replacement->id, self::AI_TRANSLATION_SOURCE_META_KEY, self::AI_TRANSLATION_SOURCE, 'translation' ) ) {
            $replacement->delete();
            $previous->save( [ 'status' => 'current' ] );
            return new \WP_Error( 'provenance_failed', 'Could not record AI provenance; previous row restored.' );
        }

        return [
            'project'            => (string) $set['project'],
            'locale'             => (string) $set['locale'],
            'translation_set_id' => (int) $set['translation_set_id'],
            'original_id'        => (int) $row['original_id'],
            'previous_id'        => (int) $row['id'],
            'replacement_id'     => (int) $replacement->id,
        ];
    }

    /**
     * Get untranslated originals.
     *
     * @since 1.0.0
     * @param object $project                       GlotPress project.
     * @param object $translation_set               Translation set.
     * @param bool   $preserve_any_existing         Preserve singular suggestions; plurals require complete current coverage.
     * @param bool   $exclude_plural_originals      Whether plural originals are unsafe for this provider.
     * @return array Array of originals.
     */
    private function get_untranslated_originals(
        object $project,
        object $translation_set,
        bool $preserve_any_existing = false,
        bool $exclude_plural_originals = false
    ): array {
        global $wpdb;

        $translation_status_condition = $preserve_any_existing ? '' : " AND t.status = 'current'";
        $locale = \GP_Locales::by_slug( $translation_set->locale );
        if ( ! $locale || $locale->nplurals < 1 || $locale->nplurals > 6 ) {
            throw new \RuntimeException( 'Cannot determine required plural forms for gap selection.' );
        }
        $complete = [];
        for ( $i = 0; $i < (int) $locale->nplurals; $i++ ) {
            $complete[] = "t.translation_{$i} IS NOT NULL AND TRIM(t.translation_{$i}) <> ''";
        }
        // Suggestions and incomplete current rows cannot satisfy a plural original.
        $coverage = "AND (((o.plural IS NULL OR o.plural = ''){$translation_status_condition}) OR (o.plural IS NOT NULL AND o.plural <> '' AND t.status = 'current' AND " . implode( ' AND ', $complete ) . '))';
        $plural_condition             = $exclude_plural_originals ? " AND ( o.plural IS NULL OR o.plural = '' )" : '';

        $sql = $wpdb->prepare(
            "SELECT o.* FROM {$wpdb->gp_originals} o
            LEFT JOIN {$wpdb->gp_translations} t
                ON o.id = t.original_id AND t.translation_set_id = %d {$coverage}
            WHERE o.project_id = %d
                AND o.status = '+active'
                {$plural_condition}
                AND t.id IS NULL
            ORDER BY o.priority DESC, o.id ASC",
            $translation_set->id,
            $project->id
        );

        return $wpdb->get_results( $sql );
    }

    /**
     * Get the maximum number of provider batches a single queue run may process.
     *
     * @since 1.2.1
     * @return int Maximum batches per queue run.
     */
    private function get_max_batches_per_run(): int {
        $value = (int) get_site_option( 'gratis_ai_ts_max_batches_per_run', 2 );

        return max( 1, min( 20, $value ) );
    }

    /**
     * Get the soft runtime budget for one queue run.
     *
     * @since 1.2.1
     * @return int Runtime budget in seconds.
     */
    private function get_run_time_budget_seconds(): int {
        $value = (int) get_site_option( 'gratis_ai_ts_run_time_budget_seconds', 75 );

        return max( 15, min( 300, $value ) );
    }

    /**
     * Save translations to GlotPress.
     *
     * @since 1.0.0
     * @param object $translation_set    Translation set.
     * @param array  $originals          Original strings.
     * @param array  $translations       Positional translations aligned with originals.
     * @param bool   $preserve_existing  Whether existing translations must not change.
     * @param bool   $mark_ai_generated  Whether new values require core AI provenance.
     * @return bool Whether all translations were written safely.
     */
    private function save_translations(
        object $translation_set,
        array $originals,
        array $translations,
        bool $preserve_existing = false,
        bool $mark_ai_generated = false
    ): bool {
        if ( $mark_ai_generated && ! function_exists( 'gp_update_meta' ) ) {
            return false;
        }

        // translate_batch returns a positional array matching the $originals order.
        $originals = array_values( $originals );

        foreach ( $originals as $index => $original ) {
            $translated_text = $translations[ $index ] ?? null;

            if ( null === $translated_text || '' === $translated_text ) {
                continue;
            }

            $translation_data = [
                'original_id'        => $original->id,
                'translation_set_id' => $translation_set->id,
                'status'             => 'current',
            ];
            $forms = is_array( $translated_text ) ? $translated_text : [ $translated_text ];
            foreach ( $forms as $form_index => $form ) {
                $translation_data[ 'translation_' . $form_index ] = $form;
            }

            $existing_args = [
                'original_id'        => $original->id,
                'translation_set_id' => $translation_set->id,
            ];
            if ( ! $preserve_existing || ! empty( $original->plural ) ) {
                $existing_args['status'] = 'current';
            }

            $existing = \GP::$translation->find_one( $existing_args );

            if ( $existing && ( empty( $original->plural ) || Human_Translations::complete( Human_Translations::fields( $existing ), Human_Translations::count( $original, $translation_set ) ) ) ) {
                continue;
            }

            $human = [];
            $ai = [];
            if ( ! empty( $original->plural ) ) {
                $human = Human_Translations::human_forms( $original, $translation_set );
                $locale = \GP_Locales::by_slug( $translation_set->locale );
                $count = Human_Translations::count( $original, $translation_set );
                $merged = [];
                for ( $i = 0; $i < $count; $i++ ) {
                    $field = 'translation_' . $i;
                    $merged[] = $human[ $field ] ?? ( $forms[ $i ] ?? '' );
                    $translation_data[ $field ] = $merged[ $i ];
                    if ( ! isset( $human[ $field ] ) ) {
                        $ai[] = $i;
                    }
                }
                if ( is_wp_error( $this->validate_plural_forms( $merged, $original, $locale, $count ) ) ) {
                    return false;
                }
            }
            if ( $existing && ! $existing->save( [ 'status' => 'old' ] ) ) {
                return false;
            }
            $translation = \GP::$translation->create( $translation_data );
            if ( ! is_object( $translation ) ) {
                if ( $existing ) {
                    $existing->save( [ 'status' => 'current' ] );
                }
                return false;
            }

            if ( $mark_ai_generated && false === gp_update_meta(
                $translation->id,
                self::AI_TRANSLATION_SOURCE_META_KEY,
                self::AI_TRANSLATION_SOURCE,
                'translation'
            ) ) {
                $translation->delete();
                if ( $existing ) {
                    $existing->save( [ 'status' => 'current' ] );
                }
                return false;
            }
            if ( ! empty( $original->plural ) && ! Human_Translations::record( $translation, $human, $ai ) ) {
                $translation->delete();
                if ( $existing ) {
                    $existing->save( [ 'status' => 'current' ] );
                }
                return false;
            }
            gp_clean_translation_set_cache( (int) $translation_set->id );
        }

        return true;
    }
}
