<?php
/**
 * Translation Generator class
 *
 * Handles AI translation generation using OpenAI.
 *
 * @package GratisAITranslationsServer
 */

declare(strict_types=1);

namespace GratisAITranslationsServer;

use Orhanerday\OpenAi\OpenAi;

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
     * OpenAI client instance.
     *
     * @since 1.0.0
     * @var OpenAi|null
     */
    private ?OpenAi $openai = null;

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
        $api_key = get_site_option('gratis_ai_ts_openai_api_key');

        if ($api_key) {
            $this->openai = new OpenAi($api_key);

            $base_url = get_site_option('gratis_ai_ts_openai_base_url');
            if ($base_url) {
                $this->openai->setBaseURL($base_url);
            }
        }
    }

    /**
     * Initialize hooks.
     *
     * @since 1.0.0
     * @return void
     */
    public function init(): void {
        // Register Action Scheduler hook.
        add_action('gratis_ai_ts_generate_translation', [$this, 'generate_translation'], 10, 1);
    }

    /**
     * Generate translation for a job.
     *
     * @since 1.0.0
     * @param int $job_id Job ID.
     * @return bool True on success.
     */
    public function generate_translation(int $job_id): bool {
        $queue = Translation_Queue::instance();
        $job = $queue->get_job_by_id($job_id);

        if (!$job) {
            return false;
        }

        if (!$this->openai) {
            $queue->update_job_status($job_id, 'failed', [
                'error_message' => 'OpenAI API not configured',
            ]);
            return false;
        }

        try {
            // Step 1: Get or create GlotPress project.
            $project = $this->get_or_create_project($job['textdomain'], $job['version']);

            if (!$project) {
                $queue->update_job_status($job_id, 'failed', [
                    'error_message' => 'Failed to create GlotPress project',
                ]);
                return false;
            }

            // Step 2: Import POT file from plugin.
            $pot_imported = $this->import_pot_file($project, $job['textdomain'], $job['version']);

            if (!$pot_imported) {
                $queue->update_job_status($job_id, 'failed', [
                    'error_message' => 'Failed to import POT file',
                ]);
                return false;
            }

            // Step 3: Get or create translation set.
            $translation_set = $this->get_or_create_translation_set($project, $job['locale']);

            if (!$translation_set) {
                $queue->update_job_status($job_id, 'failed', [
                    'error_message' => 'Failed to create translation set',
                ]);
                return false;
            }

            // Step 4: Get untranslated strings.
            $originals = $this->get_untranslated_originals($project, $translation_set);

            if (empty($originals)) {
                // Nothing to translate, mark as complete.
                $queue->update_job_status($job_id, 'completed', [
                    'string_count'     => 0,
                    'translated_count' => 0,
                ]);
                return true;
            }

            // Update job with string count.
            $queue->update_job_status($job_id, 'processing', [
                'string_count' => count($originals),
            ]);

            // Step 5: Translate strings in batches.
            $batch_size = (int) get_site_option('gratis_ai_ts_batch_size', 50);
            $batches = array_chunk($originals, $batch_size);
            $total_translated = 0;

            foreach ($batches as $batch) {
                $translated = $this->translate_batch($batch, $job['locale'], $job['textdomain']);

                if (!empty($translated)) {
                    $this->save_translations($translation_set, $batch, $translated);
                    $total_translated += count($translated);

                    // Update progress.
                    $queue->update_job_status($job_id, 'processing', [
                        'translated_count' => $total_translated,
                    ]);
                }
            }

            // Step 6: Build package.
            $builder = Package_Builder::instance();
            $package_path = $builder->build_package($job['textdomain'], $job['version'], $job['locale']);

            if (is_wp_error($package_path)) {
                $queue->update_job_status($job_id, 'failed', [
                    'error_message' => $package_path->get_error_message(),
                ]);
                return false;
            }

            // Step 7: Mark job as completed.
            $package_url = $this->get_package_url($job['textdomain'], $job['version'], $job['locale']);

            $queue->update_job_status($job_id, 'completed', [
                'package_url'      => $package_url,
                'string_count'     => count($originals),
                'translated_count' => $total_translated,
            ]);

            return true;

        } catch (\Exception $e) {
            $queue->update_job_status($job_id, 'failed', [
                'error_message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get or create GlotPress project.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @param string $version    Plugin version.
     * @return object|null Project object.
     */
    private function get_or_create_project(string $textdomain, string $version): ?object {
        // Check if project exists.
        $project = \GP::$project->by_path("plugins/{$textdomain}");

        if ($project) {
            // Update version meta.
            \GP::$project->update_meta($project->id, 'version', $version);
            return $project;
        }

        // Create new project.
        $parent_project = \GP::$project->by_path('plugins');

        if (!$parent_project) {
            // Create parent 'plugins' project if needed.
            $admin = get_user_by('login', 'admin') ?: wp_get_current_user();

            $parent_project = \GP::$project->create([
                'name'        => 'Plugins',
                'slug'        => 'plugins',
                'description' => 'WordPress Plugins',
                'parent_project_id' => null,
                'active'      => 1,
                'user_id'     => $admin->ID,
            ]);
        }

        if (!$parent_project) {
            return null;
        }

        $admin = get_user_by('login', 'admin') ?: wp_get_current_user();

        $project = \GP::$project->create([
            'name'              => $this->format_project_name($textdomain),
            'slug'              => $textdomain,
            'description'       => "AI Translations for {$textdomain}",
            'parent_project_id' => $parent_project->id,
            'active'            => 1,
            'user_id'           => $admin->ID,
        ]);

        if ($project) {
            \GP::$project->update_meta($project->id, 'version', $version);
            \GP::$project->update_meta($project->id, 'source', 'ai-generated');
        }

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
    private function import_pot_file(object $project, string $textdomain, string $version): bool {
        // Download plugin from wordpress.org if available.
        $pot_content = $this->download_plugin_pot($textdomain, $version);

        if (!$pot_content) {
            // Try to get from local storage or generate from plugin files.
            $pot_content = $this->generate_pot_from_plugin($textdomain);
        }

        if (!$pot_content) {
            return false;
        }

        // Parse POT file.
        $po = new \PO();
        $po->import_from_file($pot_content);

        // Import originals into GlotPress.
        $originals_for_import = new \PO();
        $originals_for_import->entries = $po->entries;

        $stats = \GP::$original->import_for_project($project, $originals_for_import);

        return $stats && $stats['added'] + $stats['updated'] > 0;
    }

    /**
     * Download plugin POT file from wordpress.org.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @param string $version    Plugin version.
     * @return string|null POT file content or null.
     */
    private function download_plugin_pot(string $textdomain, string $version): ?string {
        $url = "https://plugins.svn.wordpress.org/{$textdomain}/trunk/{$textdomain}.pot";

        $response = wp_remote_get($url, ['timeout' => 30]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $content = wp_remote_retrieve_body($response);

        if (empty($content)) {
            return null;
        }

        // Store temp file.
        $temp_file = GRATIS_AI_TS_STORAGE_DIR . '/temp/' . $textdomain . '-' . $version . '.pot';
        file_put_contents($temp_file, $content);

        return $temp_file;
    }

    /**
     * Generate POT from plugin files.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @return string|null POT file path or null.
     */
    private function generate_pot_from_plugin(string $textdomain): ?string {
        // This is a fallback method.
        // In practice, you'd need to have the plugin files available.
        // For now, return null.
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
    private function get_or_create_translation_set(object $project, string $locale): ?object {
        // Check if translation set exists.
        $translation_set = \GP::$translation_set->by_project_id_slug_and_locale(
            $project->id,
            'default',
            $locale
        );

        if ($translation_set) {
            return $translation_set;
        }

        // Create translation set.
        $locale_obj = \GP_Locales::by_field('wp_locale', $locale);

        if (!$locale_obj) {
            // Try by slug.
            $locale_obj = \GP_Locales::by_slug($locale);
        }

        if (!$locale_obj) {
            return null;
        }

        $translation_set = \GP::$translation_set->create([
            'project_id'   => $project->id,
            'name'         => $locale_obj->english_name,
            'slug'         => 'default',
            'locale'       => $locale_obj->slug,
        ]);

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
    private function get_untranslated_originals(object $project, object $translation_set): array {
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

        return $wpdb->get_results($sql);
    }

    /**
     * Translate a batch of strings.
     *
     * @since 1.0.0
     * @param array  $originals Array of original strings.
     * @param string $locale    Target locale.
     * @param string $context   Context (textdomain).
     * @return array Array of translated strings.
     */
    private function translate_batch(array $originals, string $locale, string $context): array {
        if (empty($originals)) {
            return [];
        }

        $locale_obj = \GP_Locales::by_field('wp_locale', $locale);

        if (!$locale_obj) {
            $locale_obj = \GP_Locales::by_slug($locale);
        }

        $target_language = $locale_obj ? $locale_obj->english_name : $locale;

        // Build prompt.
        $strings_to_translate = [];
        foreach ($originals as $original) {
            $strings_to_translate[] = [
                'id'      => $original->id,
                'singular'=> $original->singular,
                'plural'  => $original->plural ?? null,
                'context' => $original->context ?? '',
            ];
        }

        $prompt = $this->build_translation_prompt($strings_to_translate, $target_language, $context);

        // Call OpenAI API.
        $model = get_site_option('gratis_ai_ts_model', 'gpt-4');

        try {
            $chat = $this->openai->chat([
                'model'    => $model,
                'messages' => [
                    [
                        'role'    => 'system',
                        'content' => 'You are a professional translator. Translate WordPress plugin strings accurately, preserving placeholders like %s, %d, and {variable}. Respond in JSON format only.',
                    ],
                    [
                        'role'    => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.3,
                'max_tokens'  => 4000,
            ]);

            $response = json_decode($chat, true);

            if (isset($response['error'])) {
                return [];
            }

            $content = $response['choices'][0]['message']['content'] ?? '';

            // Parse JSON response.
            $translations = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                // Try to extract JSON from markdown.
                if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
                    $translations = json_decode($matches[1], true);
                }
            }

            if (!is_array($translations)) {
                return [];
            }

            return $translations;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Build translation prompt.
     *
     * @since 1.0.0
     * @param array  $strings  Strings to translate.
     * @param string $language Target language.
     * @param string $context  Context.
     * @return string Prompt.
     */
    private function build_translation_prompt(array $strings, string $language, string $context): string {
        $json_strings = wp_json_encode($strings);

        return <<<PROMPT
Translate the following WordPress plugin strings from English to {$language}.
Context: {$context}

Important instructions:
1. Preserve all placeholders exactly as they appear (%s, %d, %1\$s, etc.)
2. Maintain any HTML tags in their original positions
3. Use formal tone unless the context suggests otherwise
4. Keep technical terms in English if they don't have common translations
5. Respond in valid JSON format with the structure: {"translations": [{"id": 123, "translation": "translated text", "plural_translation": "optional"}]}

Strings to translate:
{$json_strings}

Provide only the JSON response without any additional text.
PROMPT;
    }

    /**
     * Save translations to GlotPress.
     *
     * @since 1.0.0
     * @param object $translation_set Translation set.
     * @param array  $originals       Original strings.
     * @param array  $translations    Translated strings.
     * @return void
     */
    private function save_translations(object $translation_set, array $originals, array $translations): void {
        $translations_map = [];

        if (isset($translations['translations'])) {
            $translations_map = $translations['translations'];
        } else {
            $translations_map = $translations;
        }

        foreach ($originals as $original) {
            $translated = null;

            foreach ($translations_map as $t) {
                if ((int) $t['id'] === (int) $original->id) {
                    $translated = $t;
                    break;
                }
            }

            if (!$translated) {
                continue;
            }

            $translation_data = [
                'original_id'        => $original->id,
                'translation_set_id' => $translation_set->id,
                'translation_0'      => $translated['translation'] ?? '',
                'status'             => 'current',
            ];

            if (!empty($original->plural) && !empty($translated['plural_translation'])) {
                $translation_data['translation_1'] = $translated['plural_translation'];
            }

            // Check if translation already exists.
            $existing = \GP::$translation->find_one([
                'original_id'        => $original->id,
                'translation_set_id' => $translation_set->id,
            ]);

            if ($existing) {
                \GP::$translation->update($existing, $translation_data);
            } else {
                \GP::$translation->create($translation_data);
            }
        }
    }

    /**
     * Format project name from textdomain.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @return string Formatted name.
     */
    private function format_project_name(string $textdomain): string {
        return ucwords(str_replace(['-', '_'], ' ', $textdomain));
    }

    /**
     * Get package URL.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @param string $version    Plugin version.
     * @param string $locale     Locale.
     * @return string Package URL.
     */
    private function get_package_url(string $textdomain, string $version, string $locale): string {
        return rest_url("gratis-ai-translations/v1/download/{$textdomain}/{$version}/{$locale}");
    }
}
