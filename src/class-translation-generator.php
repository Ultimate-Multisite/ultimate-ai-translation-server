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

        return [
            'target_type'            => $target_type,
            'textdomain'             => $textdomain,
            'version'                => $version,
            'source'                 => 'unknown',
            'source_authoritative'   => true,
            'project_parent_slug'    => $is_theme ? 'themes' : 'plugins',
            'project_parent_name'    => $is_theme ? 'Themes' : 'Plugins',
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

            // Step 3: Import originals + human translations from wordpress.org.
            //
            // For wp.org plugins, the GlotPress export endpoint at
            // translate.wordpress.org exports ALL source strings (translated +
            // untranslated) for a given locale in one request. This single
            // download replaces the old 3-step flow (POT download + separate
            // human translation import).
            //
            // Suppress gp-openai-translate's Automation hook during originals
            // import — without this, Automation::on_originals_imported() fires
            // and schedules AI translations for ALL configured locales.
            $this->suppress_automation_hooks();
            try {
                $import_ok = $this->import_from_wporg_glotpress( $project, $translation_set, $target_type, $job['textdomain'], $job['locale'] );
            } finally {
                $this->restore_automation_hooks();
            }

            if ( ! $import_ok ) {
                // Fallback: try the old POT + human import path for non-wp.org plugins.
                $this->suppress_automation_hooks();
                try {
                    $pot_imported = $this->import_pot_file( $project, $target_type, $job['textdomain'], $job['version'] );
                } finally {
                    $this->restore_automation_hooks();
                }

                if ( ! $pot_imported ) {
                    $queue->update_job_status( $job_id, 'failed', [
                        'error_message' => 'Failed to import source strings',
                    ] );
                    return false;
                }
                $this->import_human_translations( $project, $translation_set, $target_type, $job['textdomain'], $job['locale'] );
            }

            // Step 4b: Get remaining untranslated strings.
            $originals = $this->get_untranslated_originals( $project, $translation_set );

            if ( empty( $originals ) ) {
                // All strings already covered by human translations — build package via Traduttore.
                $zip_provider = new \Required\Traduttore\ZipProvider( $translation_set );
                $zip_provider->generate_zip_file();

                $queue->update_job_status( $job_id, 'completed', [
                    'package_url'      => $zip_provider->get_zip_url(),
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
            $gp_locale = $locale_obj ? $locale_obj->slug : $job['locale'];

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

                $strings      = array_column( $batch, 'singular' );
                $contexts     = array_column( $batch, 'context' );
                $original_ids = array_column( $batch, 'id' );

                // translate_batch returns a positional array of translated strings.
                $translated = $translator->translate_batch(
                    $gp_locale,
                    $strings,
                    $contexts,
                    $original_ids,
                    $project->id
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
                $this->save_translations( $translation_set, $batch, $translated );
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
            $zip_provider = new \Required\Traduttore\ZipProvider( $translation_set );
            $zip_provider->generate_zip_file();

            // Step 7: Mark job as completed with final token usage.
            $usage = $translator->get_accumulated_usage();
            $queue->update_job_status( $job_id, 'completed', [
                'package_url'       => $zip_provider->get_zip_url(),
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
                $error_message = $source_package->get_error_message();
                $error_data    = $source_package->get_error_data();
                if (
                    is_array( $error_data )
                    && ! empty( $error_data['transient'] )
                    && $queue->requeue_transient_failure( $job_id, $error_message )
                ) {
                    return true;
                }

                $queue->update_job_status( $job_id, 'failed', [
                    'error_message' => $error_message,
                ] );
                return false;
            }

            $available_domains = array_keys(
                array_filter(
                    $source_package['po_contents'],
                    static function ( $po_content ): bool {
                        return is_string( $po_content ) && '' !== $po_content;
                    }
                )
            );
            $missing_domains   = array_diff( array_keys( $metadata['domains'] ), $available_domains );
            if ( ! empty( $missing_domains ) ) {
                $queue->update_job_status( $job_id, 'failed', [
                    'error_message' => sprintf(
                        'The exact WordPress.org core package is missing required PO domains: %s.',
                        implode( ', ', $missing_domains )
                    ),
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
                        $translated   = $translator->translate_batch(
                            $locale_obj->slug,
                            $strings,
                            $contexts,
                            $original_ids,
                            $domain_job['project']->id
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
        if ( is_wp_error( $api ) ) {
            return new \WP_Error(
                'core_translation_api_failed',
                'WordPress.org did not return core translation metadata.',
                [ 'transient' => 'http_request_failed' === $api->get_error_code() ]
            );
        }
        if ( ! is_array( $api ) ) {
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
        if ( is_wp_error( $response ) ) {
            return new \WP_Error(
                'core_translation_download_failed',
                'Unable to download the exact WordPress.org core language pack.',
                [ 'transient' => 'http_request_failed' === $response->get_error_code() ]
            );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $response_code ) {
            return new \WP_Error(
                'core_translation_download_failed',
                'Unable to download the exact WordPress.org core language pack.',
                [
                    'http_status' => $response_code,
                    'transient'   => 429 === $response_code || $response_code >= 500,
                ]
            );
        }

        if ( ! class_exists( '\\ZipArchive' ) ) {
            return new \WP_Error( 'core_zip_unavailable', 'The PHP ZipArchive extension is required to import WordPress core translations.' );
        }

        $temporary_zip = wp_tempnam( 'gratis-ai-core-' . md5( $version . $locale ) . '.zip' );
        if ( ! $temporary_zip || false === file_put_contents( $temporary_zip, wp_remote_retrieve_body( $response ) ) ) {
            return new \WP_Error(
                'core_translation_write_failed',
                'Unable to prepare the downloaded WordPress core language pack.'
            );
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

        $temporary_po = wp_tempnam( 'gratis-ai-core-import.po' );
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

        $prevent_overwrite = static function( bool $import_over ): bool {
            return false;
        };
        add_filter( 'gp_translation_set_import_over_existing', $prevent_overwrite, PHP_INT_MAX, 1 );
        try {
            $translation_set->import( $po, 'current' );
        } finally {
            remove_filter( 'gp_translation_set_import_over_existing', $prevent_overwrite, PHP_INT_MAX );
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
     * GlotPress' import-over-existing filter does not expose the existing
     * translation, so imports first preserve every value and this pass replaces
     * only translations carrying this server's explicit AI provenance marker.
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
            if ( empty( $entry->translations ) ) {
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

            if ( ! $existing->save( $this->translation_data_from_po_entry( $entry ) ) ) {
                return false;
            }

            if ( false === gp_update_meta(
                $existing->id,
                self::AI_TRANSLATION_SOURCE_META_KEY,
                'wporg',
                'translation'
            ) ) {
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

        $package_filename = 'wordpress-core-' . substr( hash( 'sha256', $version ), 0, 16 ) . '-' . sanitize_file_name( $locale ) . '.zip';
        $destination      = $package_directory . '/' . $package_filename;
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

        foreach ( $json_contents as $asset_filename => $content ) {
            if ( ! $zip->addFromString( $asset_filename, $content ) ) {
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

        return $this->get_public_core_package_url( $package_filename );
    }

    /**
     * Build a package URL on the server application host.
     *
     * Multisite can give content_url() the network primary host while the REST
     * API is served from a mapped application host. Clients deliberately trust
     * only the configured API host, so retain the content path but use the
     * current server home origin.
     *
     * @param string $filename Published package filename.
     * @return string Public package URL.
     */
    private function get_public_core_package_url( string $filename ): string {
        $content_url   = content_url( 'gratis-ai-translations/packages/' . rawurlencode( $filename ) );
        $content_parts = wp_parse_url( $content_url );
        $home_parts    = wp_parse_url( home_url( '/' ) );

        if (
            ! is_array( $content_parts )
            || ! is_array( $home_parts )
            || empty( $content_parts['path'] )
            || empty( $home_parts['scheme'] )
            || empty( $home_parts['host'] )
        ) {
            return $content_url;
        }

        $authority = $home_parts['scheme'] . '://' . $home_parts['host'];
        if ( isset( $home_parts['port'] ) ) {
            $authority .= ':' . $home_parts['port'];
        }

        return $authority . $content_parts['path'];
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
     * Import originals and human translations from wordpress.org's GlotPress.
     *
     * Uses the translate.wordpress.org export endpoint which returns ALL source
     * strings (translated + untranslated) for a locale in one PO file. This
     * single request replaces the old multi-step flow:
     * - Originals import (was: download POT from SVN, fallback to merged POs)
     * - Human translation import (was: download zip from translations API)
     *
     * @since 1.2.0
     * @param object $project         GlotPress project.
     * @param object $translation_set GlotPress translation set.
     * @param string $target_type     Target type: 'plugin' or 'theme'.
     * @param string $textdomain      Plugin/theme textdomain or slug.
     * @param string $wp_locale       WordPress locale (e.g. 'ro_RO').
     * @return bool True if import succeeded, false if the export endpoint is unavailable.
     */
    private function import_from_wporg_glotpress( object $project, object $translation_set, string $target_type, string $textdomain, string $wp_locale ): bool {
        // Map WordPress locale (ro_RO) to GlotPress slug (ro).
        $gp_locale = \GP_Locales::by_field( 'wp_locale', $wp_locale );
        if ( ! $gp_locale ) {
            return false;
        }

        $metadata = self::resolve_target_metadata( $target_type, $textdomain );
        if ( ! $metadata || 'core' === $metadata['target_type'] ) {
            return false;
        }

        $project_prefix = (string) $metadata['wporg_project_prefix'];

        $export_url = sprintf(
            'https://translate.wordpress.org/projects/%s/%s/stable/%s/default/export-translations/?format=po',
            $project_prefix,
            $textdomain,
            $gp_locale->slug
        );

        $response = wp_remote_get( $export_url, [ 'timeout' => 30 ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false; // Not a wp.org plugin, or endpoint unavailable.
        }

        $content = wp_remote_retrieve_body( $response );
        if ( empty( $content ) || strpos( $content, 'msgid' ) === false ) {
            return false;
        }

        // Parse the PO export.
        if ( ! class_exists( 'PO' ) ) {
            require_once ABSPATH . WPINC . '/pomo/po.php';
        }

        $tmp_po = get_temp_dir() . $textdomain . '-' . $wp_locale . '-wporg-export.po';
        file_put_contents( $tmp_po, $content );

        $po = new \PO();
        if ( ! $po->import_from_file( $tmp_po ) ) {
            @unlink( $tmp_po );
            return false;
        }
        @unlink( $tmp_po );

        // Import originals if the project doesn't have any yet.
        global $wpdb;
        $existing_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->gp_originals} WHERE project_id = %d AND status = '+active'",
            $project->id
        ) );

        if ( 0 === $existing_count ) {
            // Strip translations from entries before passing to import_for_project().
            // import_for_project creates originals AND translations if msgstr is
            // non-empty, but we want to control translation import separately
            // (with proper user_id tracking). Passing entries with translations
            // also causes import_for_project to skip untranslated entries.
            $originals_po = new \PO();
            foreach ( $po->entries as $entry ) {
                $clean                 = clone $entry;
                $clean->translations   = [];
                $originals_po->entries[] = $clean;
            }

            \GP::$original->import_for_project( $project, $originals_po );
        }

        // Import human translations (entries with non-empty msgstr).
        // Match by both singular AND context to handle entries like
        // "User Switching" which appear twice with different contexts.
        $imported = 0;
        foreach ( $po->entries as $entry ) {
            if ( empty( $entry->translations[0] ) ) {
                continue;
            }

            $find_args = [
                'project_id' => $project->id,
                'singular'   => $entry->singular,
                'status'     => '+active',
            ];
            if ( ! empty( $entry->context ) ) {
                $find_args['context'] = $entry->context;
            }

            $original = \GP::$original->find_one( $find_args );

            if ( ! $original ) {
                continue;
            }

            // Skip if already has a current translation.
            $existing = \GP::$translation->find_one( [
                'original_id'        => $original->id,
                'translation_set_id' => $translation_set->id,
                'status'             => 'current',
            ] );

            if ( $existing ) {
                // Replace AI translations (user_id = 0) with human ones if different.
                if ( (int) $existing->user_id === 0 && $existing->translation_0 !== $entry->translations[0] ) {
                    $existing->save( [
                        'translation_0' => $entry->translations[0],
                        'translation_1' => ! empty( $entry->translations[1] ) ? $entry->translations[1] : null,
                    ] );
                }
                continue;
            }

            $data = [
                'original_id'        => $original->id,
                'translation_set_id' => $translation_set->id,
                'translation_0'      => $entry->translations[0],
                'status'             => 'current',
                'user_id'            => 0,
            ];

            if ( ! empty( $entry->translations[1] ) ) {
                $data['translation_1'] = $entry->translations[1];
            }

            \GP::$translation->create( $data );
            ++$imported;
        }

        return true;
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
            '/cURL error 28/i',
            '/cURL error 52/i',
            '/Operation timed out/i',
            '/Empty reply from server/i',
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
     * Import POT file from plugin.
     *
     * @since 1.0.0
     * @param object $project     GlotPress project.
     * @param string $target_type Target type: 'plugin' or 'theme'.
     * @param string $textdomain  Plugin/theme textdomain or slug.
     * @param string $version     Plugin/theme version.
     * @return bool True on success.
     */
    private function import_pot_file( object $project, string $target_type, string $textdomain, string $version ): bool {
        global $wpdb;

        $target_type = $this->normalize_target_type( $target_type );

        // Check if the project already has active originals. If so, skip
        // re-importing the POT. The fallback POT sources (translated POs from
        // wp.org) only contain translated entries — re-importing them marks
        // untranslated originals as obsolete, which is data loss. The originals
        // from the first import are the correct baseline.
        $existing_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->gp_originals} WHERE project_id = %d AND status = '+active'",
            $project->id
        ) );

        if ( $existing_count > 0 ) {
            return true; // Originals already exist — no re-import needed.
        }

        // New project — import originals from the best available POT source.
        $pot_content = $this->download_source_pot( $target_type, $textdomain, $version );

        if ( ! $pot_content ) {
            return false;
        }

        $po = new \PO();
        $po->import_from_file( $pot_content );

        $originals_for_import          = new \PO();
        $originals_for_import->entries = $po->entries;

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
     * Import existing human translations from wordpress.org into GlotPress.
     *
     * Delegates to gp-openai-translate's Automation::import_wporg_translations()
     * when available (shared implementation). Falls back to a local implementation
     * if gp-openai-translate is not active.
     *
     * Also replaces AI-translated strings (user_id = 0) with human translations
     * when they become available on wordpress.org.
     *
     * Silently no-ops if the plugin isn't on wordpress.org or has no
     * translations for the requested locale.
     *
     * @since 1.0.0
     * @param object $project         GlotPress project.
     * @param object $translation_set GlotPress translation set.
     * @param string $target_type     Target type: 'plugin' or 'theme'.
     * @param string $textdomain      Plugin/theme textdomain or slug.
     * @param string $locale          WordPress locale (e.g. 'fr_FR').
     * @return void
     */
    private function import_human_translations( object $project, object $translation_set, string $target_type, string $textdomain, string $locale ): void {
        $target_type = $this->normalize_target_type( $target_type );

        // Use the shared implementation from gp-openai-translate when available.
        if ( 'plugin' === $target_type && class_exists( '\Meloniq\GpOpenaiTranslate\Automation' ) ) {
            // First replace any existing AI translations with human ones.
            \Meloniq\GpOpenaiTranslate\Automation::replace_ai_with_human( $project, $translation_set, $textdomain, $locale );
            // Then import any remaining new human translations.
            \Meloniq\GpOpenaiTranslate\Automation::import_wporg_translations( $project, $translation_set, $textdomain, $locale );
            return;
        }

        // Fallback: direct implementation if gp-openai-translate is not active.
        $this->import_human_translations_fallback( $project, $translation_set, $target_type, $textdomain, $locale );
    }

    /**
     * Fallback human translation import when gp-openai-translate is not active.
     *
     * @since 1.2.0
     * @param object $project         GlotPress project.
     * @param object $translation_set GlotPress translation set.
     * @param string $target_type     Target type: 'plugin' or 'theme'.
     * @param string $textdomain      Plugin/theme textdomain or slug.
     * @param string $locale          WordPress locale (e.g. 'fr_FR').
     * @return void
     */
    private function import_human_translations_fallback( object $project, object $translation_set, string $target_type, string $textdomain, string $locale ): void {
        $metadata = self::resolve_target_metadata( $target_type, $textdomain );
        if ( ! $metadata || 'core' === $metadata['target_type'] ) {
            return;
        }

        // Use WordPress core's translations_api() to get the correct package URL.
        if ( ! function_exists( 'translations_api' ) ) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }

        $api_type = (string) $metadata['wporg_translation_type'];
        $api      = translations_api( $api_type, [ 'slug' => $textdomain ] );
        if ( is_wp_error( $api ) || empty( $api['translations'] ) ) {
            return;
        }

        $package_url = null;
        foreach ( $api['translations'] as $entry ) {
            if ( ( $entry['language'] ?? '' ) === $locale && ! empty( $entry['package'] ) ) {
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
            if ( empty( $entry->translations[0] ) ) {
                continue;
            }

            $original = \GP::$original->find_one( [
                'project_id' => $project->id,
                'singular'   => $entry->singular,
                'status'     => '+active',
            ] );

            if ( ! $original ) {
                continue;
            }

            $existing = \GP::$translation->find_one( [
                'original_id'        => $original->id,
                'translation_set_id' => $translation_set->id,
                'status'             => 'current',
            ] );

            if ( $existing ) {
                continue;
            }

            $data = [
                'original_id'        => $original->id,
                'translation_set_id' => $translation_set->id,
                'translation_0'      => $entry->translations[0],
                'status'             => 'current',
            ];

            if ( ! empty( $entry->translations[1] ) ) {
                $data['translation_1'] = $entry->translations[1];
            }

            \GP::$translation->create( $data );
            $imported++;
        }
    }

    /**
     * Download plugin/theme POT file from wordpress.org or local source.
     *
     * @since 1.0.0
     * @param string $target_type Target type: 'plugin' or 'theme'.
     * @param string $textdomain  Plugin/theme textdomain or slug.
     * @param string $version     Plugin/theme version.
     * @return string|null POT file path or null.
     */
    private function download_source_pot( string $target_type, string $textdomain, string $version ): ?string {
        $metadata = self::resolve_target_metadata( $target_type, $textdomain, $version );
        if ( ! $metadata || 'core' === $metadata['target_type'] ) {
            return null;
        }

        $target_type = (string) $metadata['target_type'];
        $textdomain  = (string) $metadata['textdomain'];

        // 1. Check local plugin/theme directory first (handles non-wordpress.org targets).
        $local_pot = $this->find_local_pot( $target_type, $textdomain );
        if ( $local_pot ) {
            return $local_pot;
        }

        // 2. Try wordpress.org SVN for plugins.
        if ( 'plugin' === $target_type ) {
            $url      = "https://plugins.svn.wordpress.org/{$textdomain}/trunk/{$textdomain}.pot";
            $response = wp_remote_get( $url, [ 'timeout' => 30 ] );

            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $content = wp_remote_retrieve_body( $response );

                if ( ! empty( $content ) ) {
                    $temp_file = get_temp_dir() . $textdomain . '-' . $version . '.pot';
                    file_put_contents( $temp_file, $content );
                    return $temp_file;
                }
            }
        }

        // 3. Try wordpress.org translation export API (PO format has all source strings).
        $project_prefix = (string) $metadata['wporg_project_prefix'];
        $export_url     = "https://translate.wordpress.org/projects/{$project_prefix}/{$textdomain}/stable/en/default/export-translations/?format=po";
        $response   = wp_remote_get( $export_url, [ 'timeout' => 30 ] );

        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            $content = wp_remote_retrieve_body( $response );

            if ( ! empty( $content ) && strpos( $content, 'msgid' ) !== false ) {
                $temp_file = get_temp_dir() . $textdomain . '-' . $version . '.pot';
                file_put_contents( $temp_file, $content );
                return $temp_file;
            }
        }

        // 4. Download an existing translation PO from wp.org. Any locale's PO
        //    contains all msgids (source strings) — works as a POT substitute
        //    even though the msgstr values are non-empty. GlotPress
        //    import_for_project reads only the entries' singular/plural/context.
        $translation_po = $this->download_wporg_translation_po( $target_type, $textdomain, $version );
        if ( $translation_po ) {
            return $translation_po;
        }

        // 5. Fallback: generate POT from local plugin source using wp i18n make-pot.
        return $this->generate_pot_from_source( $target_type, $textdomain, $version );
    }

    /**
     * Download translation PO files from wp.org and merge them into a complete POT.
     *
     * Individual PO downloads only contain translated entries. To get the full
     * set of source strings (including untranslated ones), this method downloads
     * multiple POs from different locales and merges their unique msgids.
     *
     * @since 1.1.2
     * @param string $target_type Target type: 'plugin' or 'theme'.
     * @param string $textdomain  Plugin/theme textdomain or slug.
     * @param string $version     Plugin/theme version.
     * @return string|null Path to merged PO/POT file, or null on failure.
     */
    private function download_wporg_translation_po( string $target_type, string $textdomain, string $version ): ?string {
        $metadata = self::resolve_target_metadata( $target_type, $textdomain, $version );
        if ( ! $metadata || 'core' === $metadata['target_type'] ) {
            return null;
        }

        $textdomain = (string) $metadata['textdomain'];

        // Use WordPress core's translations_api() to get available translations.
        if ( ! function_exists( 'translations_api' ) ) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }

        $api_type = (string) $metadata['wporg_translation_type'];
        $api      = translations_api( $api_type, [ 'slug' => $textdomain, 'version' => $version ] );
        if ( is_wp_error( $api ) || empty( $api['translations'] ) ) {
            return null;
        }

        $translations = $api['translations'];

        if ( ! class_exists( 'PO' ) ) {
            require_once ABSPATH . WPINC . '/pomo/po.php';
        }

        // Download up to 3 POs from different locales and merge their entries.
        // Each PO only contains translated strings, so a single PO may be
        // incomplete. Merging entries from multiple locales maximises coverage
        // of the original string set.
        $merged_entries = []; // keyed by singular to deduplicate
        $any_success    = false;

        foreach ( array_slice( $translations, 0, 3 ) as $entry ) {
            if ( empty( $entry['package'] ) ) {
                continue;
            }

            $zip_response = wp_remote_get( $entry['package'], [ 'timeout' => 30 ] );
            if ( is_wp_error( $zip_response ) || wp_remote_retrieve_response_code( $zip_response ) !== 200 ) {
                continue;
            }

            $zip_body = wp_remote_retrieve_body( $zip_response );
            if ( empty( $zip_body ) ) {
                continue;
            }

            $tmp_zip = get_temp_dir() . $textdomain . '-source.zip';
            file_put_contents( $tmp_zip, $zip_body );

            $zip = new \ZipArchive();
            if ( $zip->open( $tmp_zip ) !== true ) {
                @unlink( $tmp_zip );
                continue;
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

            if ( empty( $po_content ) || strpos( $po_content, 'msgid' ) === false ) {
                continue;
            }

            $tmp_po = get_temp_dir() . $textdomain . '-source-merge.po';
            file_put_contents( $tmp_po, $po_content );

            $po = new \PO();
            if ( $po->import_from_file( $tmp_po ) ) {
                $any_success = true;
                foreach ( $po->entries as $po_entry ) {
                    // Use singular + context as the dedup key.
                    $key = $po_entry->singular . chr(4) . ( $po_entry->context ?? '' );
                    if ( ! isset( $merged_entries[ $key ] ) ) {
                        // Store as a POT entry (clear translations so GlotPress
                        // treats them as untranslated originals).
                        $pot_entry               = clone $po_entry;
                        $pot_entry->translations  = [];
                        $merged_entries[ $key ]   = $pot_entry;
                    }
                }
            }
            @unlink( $tmp_po );
        }

        if ( ! $any_success || empty( $merged_entries ) ) {
            return null;
        }

        // Build a merged PO object and write it out.
        $merged_po          = new \PO();
        $merged_po->entries = array_values( $merged_entries );

        $temp_file = get_temp_dir() . $textdomain . '-' . $version . '.pot';
        $merged_po->export_to_file( $temp_file );

        return $temp_file;
    }

    /**
     * Generate a POT file from local plugin/theme source using wp i18n make-pot.
     *
     * This is the fallback when no .pot file exists in the plugin directory
     * and none can be downloaded from wordpress.org SVN.
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

        if ( ! is_dir( $source_dir ) ) {
            return null;
        }

        $temp_file = get_temp_dir() . $textdomain . '-' . $version . '.pot';

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
        $which = trim( (string) shell_exec( 'which wp 2>/dev/null' ) );
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
     * Get untranslated originals.
     *
     * @since 1.0.0
     * @param object $project                       GlotPress project.
     * @param object $translation_set               Translation set.
     * @param bool   $preserve_any_existing         Whether any non-empty status blocks AI.
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
        $plural_condition             = $exclude_plural_originals ? " AND ( o.plural IS NULL OR o.plural = '' )" : '';

        $sql = $wpdb->prepare(
            "SELECT o.* FROM {$wpdb->gp_originals} o
            LEFT JOIN {$wpdb->gp_translations} t
                ON o.id = t.original_id AND t.translation_set_id = %d{$translation_status_condition}
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
     * @param array  $translations       Translated strings keyed by original ID.
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
                'translation_0'      => $translated_text,
                'status'             => 'current',
            ];

            $existing_args = [
                'original_id'        => $original->id,
                'translation_set_id' => $translation_set->id,
            ];
            if ( ! $preserve_existing ) {
                $existing_args['status'] = 'current';
            }

            $existing = \GP::$translation->find_one( $existing_args );

            if ( $existing ) {
                continue;
            }

            $translation = \GP::$translation->create( $translation_data );
            if ( ! is_object( $translation ) ) {
                return false;
            }

            if ( $mark_ai_generated && false === gp_update_meta(
                $translation->id,
                self::AI_TRANSLATION_SOURCE_META_KEY,
                self::AI_TRANSLATION_SOURCE,
                'translation'
            ) ) {
                $translation->delete();

                return false;
            }
        }

        return true;
    }
}
