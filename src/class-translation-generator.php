<?php
/**
 * Translation Generator class
 *
 * Handles AI translation generation by delegating to the gp-openai-translate
 * plugin's Translate class. All AI provider configuration (API key, base URL,
 * model) is managed there — this plugin does not duplicate it.
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

        $translator = $this->get_translator();

        if ( ! $translator ) {
            $queue->update_job_status( $job_id, 'failed', [
                'error_message' => 'gp-openai-translate plugin is not active. AI translation requires the GP OpenAI Translate plugin.',
            ] );
            return false;
        }

        try {
            // Step 1: Get or create GlotPress project.
            $project = $this->get_or_create_project( $job['textdomain'], $job['version'] );

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
            $import_ok = $this->import_from_wporg_glotpress( $project, $translation_set, $job['textdomain'], $job['locale'] );
            $this->restore_automation_hooks();

            if ( ! $import_ok ) {
                // Fallback: try the old POT + human import path for non-wp.org plugins.
                $pot_imported = $this->import_pot_file( $project, $job['textdomain'], $job['version'] );
                if ( ! $pot_imported ) {
                    $queue->update_job_status( $job_id, 'failed', [
                        'error_message' => 'Failed to import source strings',
                    ] );
                    return false;
                }
                $this->import_human_translations( $project, $translation_set, $job['textdomain'], $job['locale'] );
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

            // Step 5: Translate strings in batches via gp-openai-translate.
            // Resolve WP locale to GP slug for the translator (e.g. fr_FR -> fr).
            $locale_obj = \GP_Locales::by_field( 'wp_locale', $job['locale'] )
                ?: \GP_Locales::by_slug( $job['locale'] );
            $gp_locale = $locale_obj ? $locale_obj->slug : $job['locale'];

            $batch_size       = (int) get_site_option( 'gratis_ai_ts_batch_size', 50 );
            $batches          = array_chunk( $originals, $batch_size );
            $total_translated = 0;

            // Reset token usage counter before starting this job's translations.
            $translator->reset_usage();

            foreach ( $batches as $batch ) {
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

                if ( ! empty( $translated ) && ! is_wp_error( $translated ) ) {
                    // Map positional results back to originals by index.
                    $this->save_translations( $translation_set, $batch, $translated );
                    $total_translated += count( $translated );

                    // Update progress with token usage so far.
                    $usage = $translator->get_accumulated_usage();
                    $queue->update_job_status( $job_id, 'processing', [
                        'translated_count'  => $total_translated,
                        'prompt_tokens'     => $usage['prompt_tokens'],
                        'completion_tokens' => $usage['completion_tokens'],
                    ] );
                }
            }

            // Step 6: Build package via Traduttore's ZipProvider.
            $zip_provider = new \Required\Traduttore\ZipProvider( $translation_set );
            $zip_provider->generate_zip_file();

            // Step 7: Mark job as completed with final token usage.
            $usage = $translator->get_accumulated_usage();
            $queue->update_job_status( $job_id, 'completed', [
                'package_url'       => $zip_provider->get_zip_url(),
                'string_count'      => count( $originals ),
                'translated_count'  => $total_translated,
                'prompt_tokens'     => $usage['prompt_tokens'],
                'completion_tokens' => $usage['completion_tokens'],
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
     * Stored Automation callback for hook suppression/restoration.
     *
     * @since 1.2.0
     * @var array|null [object, method] or null if not found.
     */
    private ?array $suppressed_automation_callback = null;

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
            add_action( 'gp_originals_imported', $this->suppressed_automation_callback, 10, 5 );
            $this->suppressed_automation_callback = null;
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
     * @param string $textdomain      Plugin textdomain.
     * @param string $wp_locale       WordPress locale (e.g. 'ro_RO').
     * @return bool True if import succeeded, false if the export endpoint is unavailable.
     */
    private function import_from_wporg_glotpress( object $project, object $translation_set, string $textdomain, string $wp_locale ): bool {
        // Map WordPress locale (ro_RO) to GlotPress slug (ro).
        $gp_locale = \GP_Locales::by_field( 'wp_locale', $wp_locale );
        if ( ! $gp_locale ) {
            return false;
        }

        $export_url = sprintf(
            'https://translate.wordpress.org/projects/wp-plugins/%s/stable/%s/default/export-translations/?format=po',
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
                    \GP::$translation->update( $existing, [
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
     * Get the gp-openai-translate Translate instance, or null if unavailable.
     *
     * @since 1.0.0
     * @return \Meloniq\GpOpenaiTranslate\Translate|null
     */
    private function get_translator(): ?\Meloniq\GpOpenaiTranslate\Translate {
        if ( ! class_exists( '\Meloniq\GpOpenaiTranslate\Translate' ) ) {
            return null;
        }
        return \Meloniq\GpOpenaiTranslate\Translate::instance();
    }

    /**
     * Get or create GlotPress project.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @param string $version    Plugin version.
     * @return object|null Project object.
     */
    private function get_or_create_project( string $textdomain, string $version ): ?object {
        $project = \GP::$project->by_path( "plugins/{$textdomain}" );

        if ( $project ) {
            return $project;
        }

        // Ensure parent 'plugins' project exists.
        $parent = \GP::$project->by_path( 'plugins' );

        if ( ! $parent ) {
            $parent = \GP::$project->create( [
                'name'              => 'Plugins',
                'slug'              => 'plugins',
                'description'       => 'WordPress Plugins',
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
            'description'       => "AI Translations for {$textdomain}",
            'parent_project_id' => $parent->id,
            'active'            => 1,
        ] );

        return $project ?: null;
    }

    /**
     * Import POT file from plugin.
     *
     * @since 1.0.0
     * @param object $project    GlotPress project.
     * @param string $textdomain Plugin textdomain.
     * @param string $version    Plugin version.
     * @return bool True on success.
     */
    private function import_pot_file( object $project, string $textdomain, string $version ): bool {
        global $wpdb;

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
        $pot_content = $this->download_plugin_pot( $textdomain, $version );

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
     * @param string $textdomain      Plugin textdomain.
     * @param string $locale          WordPress locale (e.g. 'fr_FR').
     * @return void
     */
    private function import_human_translations( object $project, object $translation_set, string $textdomain, string $locale ): void {
        // Use the shared implementation from gp-openai-translate when available.
        if ( class_exists( '\Meloniq\GpOpenaiTranslate\Automation' ) ) {
            // First replace any existing AI translations with human ones.
            \Meloniq\GpOpenaiTranslate\Automation::replace_ai_with_human( $project, $translation_set, $textdomain, $locale );
            // Then import any remaining new human translations.
            \Meloniq\GpOpenaiTranslate\Automation::import_wporg_translations( $project, $translation_set, $textdomain, $locale );
            return;
        }

        // Fallback: direct implementation if gp-openai-translate is not active.
        $this->import_human_translations_fallback( $project, $translation_set, $textdomain, $locale );
    }

    /**
     * Fallback human translation import when gp-openai-translate is not active.
     *
     * @since 1.2.0
     * @param object $project         GlotPress project.
     * @param object $translation_set GlotPress translation set.
     * @param string $textdomain      Plugin textdomain.
     * @param string $locale          WordPress locale (e.g. 'fr_FR').
     * @return void
     */
    private function import_human_translations_fallback( object $project, object $translation_set, string $textdomain, string $locale ): void {
        // Use WordPress core's translations_api() to get the correct package URL.
        if ( ! function_exists( 'translations_api' ) ) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }

        $api = translations_api( 'plugins', [ 'slug' => $textdomain ] );
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
     * Download plugin POT file from wordpress.org.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @param string $version    Plugin version.
     * @return string|null POT file path or null.
     */
    private function download_plugin_pot( string $textdomain, string $version ): ?string {
        // 1. Check local plugin directory first (handles non-wordpress.org plugins).
        $local_pot = $this->find_local_pot( $textdomain );
        if ( $local_pot ) {
            return $local_pot;
        }

        // 2. Try wordpress.org SVN.
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

        // 3. Try wordpress.org translation export API (PO format has all source strings).
        $export_url = "https://translate.wordpress.org/projects/wp-plugins/{$textdomain}/stable/en/default/export-translations/?format=po";
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
        $translation_po = $this->download_wporg_translation_po( $textdomain, $version );
        if ( $translation_po ) {
            return $translation_po;
        }

        // 5. Fallback: generate POT from local plugin source using wp i18n make-pot.
        return $this->generate_pot_from_source( $textdomain, $version );
    }

    /**
     * Download translation PO files from wp.org and merge them into a complete POT.
     *
     * Individual PO downloads only contain translated entries. To get the full
     * set of source strings (including untranslated ones), this method downloads
     * multiple POs from different locales and merges their unique msgids.
     *
     * @since 1.1.2
     * @param string $textdomain Plugin textdomain.
     * @param string $version    Plugin version.
     * @return string|null Path to merged PO/POT file, or null on failure.
     */
    private function download_wporg_translation_po( string $textdomain, string $version ): ?string {
        // Use WordPress core's translations_api() to get available translations.
        if ( ! function_exists( 'translations_api' ) ) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }

        $api = translations_api( 'plugins', [ 'slug' => $textdomain, 'version' => $version ] );
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
     * Generate a POT file from the local plugin source using wp i18n make-pot.
     *
     * This is the fallback when no .pot file exists in the plugin directory
     * and none can be downloaded from wordpress.org SVN.
     *
     * @since 1.1.1
     * @param string $textdomain Plugin textdomain.
     * @param string $version    Plugin version.
     * @return string|null Path to generated POT file, or null on failure.
     */
    private function generate_pot_from_source( string $textdomain, string $version ): ?string {
        $plugin_dir = WP_PLUGIN_DIR . '/' . $textdomain;

        if ( ! is_dir( $plugin_dir ) ) {
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
            escapeshellarg( $plugin_dir ),
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
     * Find a POT file in the locally installed plugin directory.
     *
     * Checks common locations: languages/, lang/, i18n/, and the plugin root.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @return string|null Absolute path to POT file, or null.
     */
    private function find_local_pot( string $textdomain ): ?string {
        $plugin_dir = WP_PLUGIN_DIR . '/' . $textdomain;

        if ( ! is_dir( $plugin_dir ) ) {
            return null;
        }

        $candidates = [
            $plugin_dir . '/languages/' . $textdomain . '.pot',
            $plugin_dir . '/lang/' . $textdomain . '.pot',
            $plugin_dir . '/i18n/' . $textdomain . '.pot',
            $plugin_dir . '/' . $textdomain . '.pot',
        ];

        foreach ( $candidates as $path ) {
            if ( file_exists( $path ) && filesize( $path ) > 0 ) {
                return $path;
            }
        }

        return null;
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
     * @param object $project         GlotPress project.
     * @param object $translation_set Translation set.
     * @return array Array of originals.
     */
    private function get_untranslated_originals( object $project, object $translation_set ): array {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT o.* FROM {$wpdb->gp_originals} o
            LEFT JOIN {$wpdb->gp_translations} t
                ON o.id = t.original_id AND t.translation_set_id = %d
            WHERE o.project_id = %d
                AND o.status = '+active'
                AND (t.id IS NULL OR t.status != 'current')
            ORDER BY o.priority DESC, o.id ASC",
            $translation_set->id,
            $project->id
        );

        return $wpdb->get_results( $sql );
    }

    /**
     * Save translations to GlotPress.
     *
     * @since 1.0.0
     * @param object $translation_set Translation set.
     * @param array  $originals       Original strings.
     * @param array  $translations    Translated strings keyed by original_id.
     * @return void
     */
    private function save_translations( object $translation_set, array $originals, array $translations ): void {
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

            $existing = \GP::$translation->find_one( [
                'original_id'        => $original->id,
                'translation_set_id' => $translation_set->id,
            ] );

            if ( $existing ) {
                \GP::$translation->update( $existing, $translation_data );
            } else {
                \GP::$translation->create( $translation_data );
            }
        }
    }
}
