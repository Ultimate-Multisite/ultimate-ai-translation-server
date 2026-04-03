<?php
/**
 * Package Builder class
 *
 * Handles building .mo/.po/.zip translation packages.
 *
 * @package GratisAITranslationsServer
 */

declare(strict_types=1);

namespace GratisAITranslationsServer;

/**
 * Package Builder class.
 *
 * @since 1.0.0
 */
class Package_Builder {

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
    }

    /**
     * Initialize hooks.
     *
     * @since 1.0.0
     * @return void
     */
    public function init(): void {
    }

    /**
     * Build translation package.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @param string $version    Plugin version.
     * @param string $locale     Target locale.
     * @return string|\WP_Error Package path or error.
     */
    public function build_package(string $textdomain, string $version, string $locale) {
        // Get GlotPress project and translation set.
        $project = \GP::$project->by_path("plugins/{$textdomain}");

        if (!$project) {
            return new \WP_Error('project_not_found', 'GlotPress project not found');
        }

        // Resolve WP locale (e.g. 'fr_FR') to GlotPress slug (e.g. 'fr').
        $locale_obj = \GP_Locales::by_field('wp_locale', $locale) ?: \GP_Locales::by_slug($locale);
        $gp_locale  = $locale_obj ? $locale_obj->slug : $locale;

        $translation_set = \GP::$translation_set->by_project_id_slug_and_locale(
            $project->id,
            'default',
            $gp_locale
        );

        if (!$translation_set) {
            return new \WP_Error('translation_set_not_found', 'Translation set not found');
        }

        // Get translations from GlotPress.
        $translations = $this->get_translations($translation_set);

        if (empty($translations)) {
            return new \WP_Error('no_translations', 'No translations found');
        }

        // Generate PO content.
        $po_content = $this->generate_po_content($project, $translation_set, $translations);

        if (empty($po_content)) {
            return new \WP_Error('po_generation_failed', 'Failed to generate PO content');
        }

        // Build package.
        $package_path = $this->create_package($textdomain, $version, $locale, $po_content);

        if (is_wp_error($package_path)) {
            return $package_path;
        }

        return $package_path;
    }

    /**
     * Get translations from GlotPress.
     *
     * @since 1.0.0
     * @param object $translation_set Translation set.
     * @return array Array of translations.
     */
    private function get_translations(object $translation_set): array {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT t.*, o.singular, o.plural, o.context
            FROM {$wpdb->gp_translations} t
            JOIN {$wpdb->gp_originals} o ON t.original_id = o.id
            WHERE t.translation_set_id = %d
                AND t.status = 'current'
                AND o.status = '+active'
            ORDER BY o.priority DESC, o.id ASC",
            $translation_set->id
        );

        return $wpdb->get_results($sql);
    }

    /**
     * Generate PO file content.
     *
     * @since 1.0.0
     * @param object $project         GlotPress project.
     * @param object $translation_set Translation set.
     * @param array  $translations    Array of translations.
     * @return string PO content.
     */
    private function generate_po_content(object $project, object $translation_set, array $translations): string {
        $locale_obj = \GP_Locales::by_slug($translation_set->locale);

        if (!$locale_obj) {
            $locale_obj = \GP_Locales::by_field('wp_locale', $translation_set->locale);
        }

        $language = $locale_obj ? $locale_obj->native_name : $translation_set->locale;
        $lang_code = $locale_obj ? $locale_obj->wp_locale : $translation_set->locale;

        $po = new \PO();
        $po->set_header('Project-Id-Version', $project->name);
        $po->set_header('Report-Msgid-Bugs-To', '');
        $po->set_header('POT-Creation-Date', current_time('Y-m-d H:i:s+00:00'));
        $po->set_header('PO-Revision-Date', current_time('Y-m-d H:i:s+00:00'));
        $po->set_header('Last-Translator', 'AI Translator <ai@ultimatemultisite.com>');
        $po->set_header('Language-Team', $language);
        $po->set_header('Language', $lang_code);
        $po->set_header('MIME-Version', '1.0');
        $po->set_header('Content-Type', 'text/plain; charset=UTF-8');
        $po->set_header('Content-Transfer-Encoding', '8bit');
        $po->set_header('X-Generator', 'Gratis AI Translations Server ' . GRATIS_AI_TS_VERSION);
        $po->set_header('X-Translation-Source', 'ai');

        foreach ($translations as $t) {
            $entry = new \Translation_Entry([
                'singular'     => $t->singular,
                'plural'       => $t->plural,
                'context'      => $t->context,
                'translations' => $this->build_translations_array($t),
            ]);

            $po->add_entry($entry);
        }

        return $po->export();
    }

    /**
     * Build translations array from database row.
     *
     * @since 1.0.0
     * @param object $translation Translation row.
     * @return array Translations array.
     */
    private function build_translations_array(object $translation): array {
        $translations = [$translation->translation_0];

        if (!empty($translation->plural)) {
            if (!empty($translation->translation_1)) {
                $translations[1] = $translation->translation_1;
            }
            if (!empty($translation->translation_2)) {
                $translations[2] = $translation->translation_2;
            }
        }

        return $translations;
    }

    /**
     * Create package files.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @param string $version    Plugin version.
     * @param string $locale     Locale.
     * @param string $po_content PO file content.
     * @return string|\WP_Error Package path or error.
     */
    private function create_package(string $textdomain, string $version, string $locale, string $po_content) {
        $package_dir = GRATIS_AI_TS_STORAGE_DIR . '/packages';

        if (!file_exists($package_dir)) {
            wp_mkdir_p($package_dir);
        }

        // Create temp directory.
        $temp_dir = GRATIS_AI_TS_STORAGE_DIR . '/temp/' . uniqid('package_', true);
        wp_mkdir_p($temp_dir);

        $base_filename = "{$textdomain}-{$locale}-gratis-ai";

        // Write PO file.
        $po_path = $temp_dir . '/' . $base_filename . '.po';
        file_put_contents($po_path, $po_content);

        // Generate MO file.
        $mo_path = $temp_dir . '/' . $base_filename . '.mo';
        $mo_generated = $this->generate_mo_file($po_path, $mo_path);

        if (!$mo_generated) {
            $this->cleanup_temp($temp_dir);
            return new \WP_Error('mo_generation_failed', 'Failed to generate MO file');
        }

        // Generate PHP file (optional, for performance).
        $php_path = $temp_dir . '/' . $base_filename . '.l10n.php';
        $this->generate_php_file($po_path, $php_path);

        // Create ZIP package.
        $zip_path = $package_dir . '/' . $base_filename . '.zip';
        $zip_created = $this->create_zip_package($temp_dir, $zip_path, $base_filename);

        // Cleanup temp files.
        $this->cleanup_temp($temp_dir);

        if (!$zip_created) {
            return new \WP_Error('zip_creation_failed', 'Failed to create ZIP package');
        }

        return $zip_path;
    }

    /**
     * Generate MO file from PO file.
     *
     * @since 1.0.0
     * @param string $po_path Path to PO file.
     * @param string $mo_path Path to output MO file.
     * @return bool True on success.
     */
    private function generate_mo_file(string $po_path, string $mo_path): bool {
        $po = new \PO();

        if (!$po->import_from_file($po_path)) {
            return false;
        }

        $mo = new \MO();

        foreach ($po->entries as $entry) {
            $mo->add_entry($entry);
        }

        foreach ($po->headers as $header => $value) {
            $mo->set_header($header, $value);
        }

        return $mo->export_to_file($mo_path);
    }

    /**
     * Generate PHP file from PO file.
     *
     * @since 1.0.0
     * @param string $po_path  Path to PO file.
     * @param string $php_path Path to output PHP file.
     * @return bool True on success.
     */
    private function generate_php_file(string $po_path, string $php_path): bool {
        $po = new \PO();

        if (!$po->import_from_file($po_path)) {
            return false;
        }

        $translations = [];

        foreach ($po->entries as $entry) {
            $key = $entry->context ? $entry->context . "\4" . $entry->singular : $entry->singular;

            if ($entry->is_plural) {
                $translations[$key] = $entry->translations;
            } else {
                $translations[$key] = $entry->translations[0] ?? '';
            }
        }

        $php_content = "<?php\n";
        $php_content .= "/* THIS IS A GENERATED FILE. DO NOT EDIT DIRECTLY. */\n";
        $php_content .= "return " . var_export($translations, true) . ";\n";

        return file_put_contents($php_path, $php_content) !== false;
    }

    /**
     * Create ZIP package.
     *
     * @since 1.0.0
     * @param string $source_dir  Source directory.
     * @param string $zip_path    Path to ZIP file.
     * @param string $base_name   Base filename.
     * @return bool True on success.
     */
    private function create_zip_package(string $source_dir, string $zip_path, string $base_name): bool {
        $zip = new \ZipArchive();

        if ($zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $files = glob($source_dir . '/*');

        foreach ($files as $file) {
            if (is_file($file)) {
                $zip->addFile($file, basename($file));
            }
        }

        return $zip->close();
    }

    /**
     * Cleanup temporary directory.
     *
     * @since 1.0.0
     * @param string $temp_dir Temp directory path.
     * @return void
     */
    private function cleanup_temp(string $temp_dir): void {
        if (!is_dir($temp_dir)) {
            return;
        }

        $files = glob($temp_dir . '/*');

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($temp_dir);
    }

    /**
     * Delete package files for a translation.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @param string $version    Plugin version.
     * @param string $locale     Locale.
     * @return bool True on success.
     */
    public function delete_package(string $textdomain, string $version, string $locale): bool {
        $package_dir = GRATIS_AI_TS_STORAGE_DIR . '/packages';
        $base_filename = "{$textdomain}-{$locale}-gratis-ai";
        $zip_path = $package_dir . '/' . $base_filename . '.zip';

        if (file_exists($zip_path)) {
            return unlink($zip_path);
        }

        return true;
    }
}
