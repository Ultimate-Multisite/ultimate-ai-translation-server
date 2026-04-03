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

            // Step 2: Import POT file from plugin.
            $pot_imported = $this->import_pot_file( $project, $job['textdomain'], $job['version'] );

            if ( ! $pot_imported ) {
                $queue->update_job_status( $job_id, 'failed', [
                    'error_message' => 'Failed to import POT file',
                ] );
                return false;
            }

            // Step 3: Get or create translation set.
            $translation_set = $this->get_or_create_translation_set( $project, $job['locale'] );

            if ( ! $translation_set ) {
                $queue->update_job_status( $job_id, 'failed', [
                    'error_message' => 'Failed to create translation set',
                ] );
                return false;
            }

            // Step 4a: Import existing human translations from wordpress.org.
            // This fills in strings that already have quality human translations,
            // so AI only handles the genuine gaps.
            $this->import_human_translations( $project, $translation_set, $job['textdomain'], $job['locale'] );

            // Step 4b: Get remaining untranslated strings.
            $originals = $this->get_untranslated_originals( $project, $translation_set );

            if ( empty( $originals ) ) {
                $queue->update_job_status( $job_id, 'completed', [
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

                    $queue->update_job_status( $job_id, 'processing', [
                        'translated_count' => $total_translated,
                    ] );
                }
            }

            // Step 6: Build package.
            $builder      = Package_Builder::instance();
            $package_path = $builder->build_package( $job['textdomain'], $job['version'], $job['locale'] );

            if ( is_wp_error( $package_path ) ) {
                $queue->update_job_status( $job_id, 'failed', [
                    'error_message' => $package_path->get_error_message(),
                ] );
                return false;
            }

            // Step 7: Mark job as completed.
            $package_url = rest_url( "gratis-ai-translations/v1/download/{$job['textdomain']}/{$job['version']}/{$job['locale']}" );

            $queue->update_job_status( $job_id, 'completed', [
                'package_url'      => $package_url,
                'string_count'     => count( $originals ),
                'translated_count' => $total_translated,
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
        $pot_content = $this->download_plugin_pot( $textdomain, $version );

        if ( ! $pot_content ) {
            return false;
        }

        $po = new \PO();
        $po->import_from_file( $pot_content );

        $originals_for_import          = new \PO();
        $originals_for_import->entries = $po->entries;

        $stats = \GP::$original->import_for_project( $project, $originals_for_import );

        // Success if strings were added/updated, OR if originals already exist
        // (import returns 0 added when all strings are already present).
        if ( $stats && $stats['added'] + $stats['updated'] > 0 ) {
            return true;
        }

        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->gp_originals} WHERE project_id = %d AND status = '+active'",
            $project->id
        ) );

        return $existing > 0;
    }

    /**
     * Import existing human translations from wordpress.org into GlotPress.
     *
     * Downloads the official .po file for the given locale and imports any
     * current translations into the GlotPress translation set. This ensures
     * AI only fills genuine gaps, not strings already translated by humans.
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
        // wordpress.org uses the WP locale directly in the filename.
        $url = "https://downloads.wordpress.org/translation/plugin/{$textdomain}/stable/{$locale}.zip";

        $response = wp_remote_get( $url, [ 'timeout' => 30 ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return; // No official translation available — that's fine.
        }

        $zip_content = wp_remote_retrieve_body( $response );
        if ( empty( $zip_content ) ) {
            return;
        }

        // Write zip to temp file and extract the .po file.
        $tmp_zip = GRATIS_AI_TS_STORAGE_DIR . '/temp/' . $textdomain . '-' . $locale . '-human.zip';
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

        // Write .po to temp file for PO parser.
        $tmp_po = GRATIS_AI_TS_STORAGE_DIR . '/temp/' . $textdomain . '-' . $locale . '-human.po';
        file_put_contents( $tmp_po, $po_content );

        $po = new \PO();
        if ( ! $po->import_from_file( $tmp_po ) ) {
            @unlink( $tmp_po );
            return;
        }
        @unlink( $tmp_po );

        // Import into GlotPress — only 'current' status entries.
        $imported = 0;
        foreach ( $po->entries as $entry ) {
            if ( empty( $entry->translations[0] ) ) {
                continue;
            }

            // Find the matching original in GlotPress.
            $original = \GP::$original->find_one( [
                'project_id' => $project->id,
                'singular'   => $entry->singular,
                'status'     => '+active',
            ] );

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

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return null;
        }

        $content = wp_remote_retrieve_body( $response );

        if ( empty( $content ) ) {
            return null;
        }

        $temp_file = GRATIS_AI_TS_STORAGE_DIR . '/temp/' . $textdomain . '-' . $version . '.pot';
        file_put_contents( $temp_file, $content );

        return $temp_file;
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
