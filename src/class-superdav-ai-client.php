<?php
/**
 * Superdav AI Service client.
 *
 * Provides a native OpenAI-compatible translation provider for the first-party
 * Superdav AI Service without exposing customer or provider secrets.
 *
 * @package GratisAITranslationsServer
 */

declare(strict_types=1);

namespace GratisAITranslationsServer;

/**
 * Superdav AI Service client.
 *
 * @since 1.2.0
 */
class Superdav_AI_Client {

    /**
     * Singleton instance.
     *
     * @since 1.2.0
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Accumulated prompt tokens for the current job.
     *
     * @since 1.2.0
     * @var int
     */
    private int $prompt_tokens = 0;

    /**
     * Accumulated completion tokens for the current job.
     *
     * @since 1.2.0
     * @var int
     */
    private int $completion_tokens = 0;

    /**
     * Get the singleton instance.
     *
     * @since 1.2.0
     * @return self
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Reset accumulated token usage.
     *
     * @since 1.2.0
     * @return void
     */
    public function reset_usage(): void {
        $this->prompt_tokens     = 0;
        $this->completion_tokens = 0;
    }

    /**
     * Get accumulated token usage.
     *
     * @since 1.2.0
     * @return array{prompt_tokens:int,completion_tokens:int}
     */
    public function get_accumulated_usage(): array {
        return [
            'prompt_tokens'     => $this->prompt_tokens,
            'completion_tokens' => $this->completion_tokens,
        ];
    }

    /**
     * Determine whether Superdav is configured enough to translate.
     *
     * @since 1.2.0
     * @return bool
     */
    public function is_configured(): bool {
        return '' !== self::get_base_url()
            && '' !== self::get_site_token()
            && '' !== self::get_model();
    }

    /**
     * Get redacted configuration status suitable for admin, CLI, REST, or logs.
     *
     * @since 1.2.0
     * @return array<string,mixed>
     */
    public static function get_configuration_status(): array {
        $missing = [];

        if ( '' === self::get_base_url() ) {
            $missing[] = 'base_url';
        }

        if ( '' === self::get_site_token() ) {
            $missing[] = 'site_token';
        }

        if ( '' === self::get_model() ) {
            $missing[] = 'model';
        }

        return [
            'configured'       => empty( $missing ),
            'missing'          => $missing,
            'base_url'         => self::get_redacted_base_url(),
            'model'            => self::get_model(),
            'temperature'      => self::get_temperature(),
            'token_configured' => '' !== self::get_site_token(),
            'token_source'     => self::get_token_source(),
        ];
    }

    /**
     * Translate an ordered batch of strings through Superdav.
     *
     * @since 1.2.0
     * @param string $gp_locale    GlotPress locale slug.
     * @param array  $strings      Ordered source strings.
     * @param array  $contexts     Ordered GlotPress contexts.
     * @param array  $original_ids Ordered GlotPress original IDs.
     * @param int    $project_id   GlotPress project ID.
     * @return array<int,string>|\WP_Error Ordered translated strings or error.
     */
    public function translate_batch( string $gp_locale, array $strings, array $contexts, array $original_ids, int $project_id ) {
        if ( ! $this->is_configured() ) {
            return new \WP_Error(
                'superdav_not_configured',
                $this->build_missing_configuration_message()
            );
        }

        $payload = $this->build_chat_completion_payload( $gp_locale, $strings, $contexts, $original_ids, $project_id );
        $url     = trailingslashit( self::get_base_url() ) . 'chat/completions';

        $response = wp_remote_post( $url, [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . self::get_site_token(),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            return new \WP_Error(
                'superdav_request_failed',
                'Superdav AI Service request failed: ' . self::redact_error_message( $response->get_error_message() )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );

        if ( $status_code < 200 || $status_code >= 300 ) {
            return new \WP_Error(
                'superdav_http_error',
                sprintf(
                    'Superdav AI Service returned HTTP %d for translation request.',
                    (int) $status_code
                )
            );
        }

        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return new \WP_Error(
                'superdav_invalid_response',
                'Superdav AI Service returned invalid JSON.'
            );
        }

        $this->accumulate_usage( $decoded );

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if ( ! is_string( $content ) || '' === trim( $content ) ) {
            return new \WP_Error(
                'superdav_missing_content',
                'Superdav AI Service response did not include translation content.'
            );
        }

        return $this->parse_translation_content( $content, count( $strings ) );
    }

    /**
     * Parse translation JSON content from an OpenAI-compatible response.
     *
     * This method is intentionally isolated so a test harness can exercise JSON
     * parsing and count-mismatch handling without calling the remote service.
     *
     * @since 1.2.0
     * @param string $content        Message content from choices[0].message.content.
     * @param int    $expected_count Expected number of ordered translations.
     * @return array<int,string>|\WP_Error Ordered translated strings or error.
     */
    public function parse_translation_content( string $content, int $expected_count ) {
        $decoded = json_decode( trim( $content ), true );

        if ( ! is_array( $decoded ) ) {
            return new \WP_Error(
                'superdav_invalid_translation_json',
                'Superdav AI Service returned translation content that was not valid JSON.'
            );
        }

        $translations = $decoded['translations'] ?? $decoded['results'] ?? $decoded;

        if ( isset( $translations['items'] ) && is_array( $translations['items'] ) ) {
            $translations = $translations['items'];
        }

        if ( ! is_array( $translations ) ) {
            return new \WP_Error(
                'superdav_missing_translations',
                'Superdav AI Service response did not contain a translations array.'
            );
        }

        if ( count( $translations ) !== $expected_count ) {
            return new \WP_Error(
                'superdav_translation_count_mismatch',
                sprintf(
                    'Superdav AI Service returned %d translations for %d source strings.',
                    count( $translations ),
                    $expected_count
                )
            );
        }

        $ordered = [];
        foreach ( array_values( $translations ) as $translation ) {
            if ( is_array( $translation ) ) {
                $translation = $translation['translation'] ?? $translation['text'] ?? $translation['value'] ?? null;
            }

            if ( ! is_scalar( $translation ) ) {
                return new \WP_Error(
                    'superdav_invalid_translation_item',
                    'Superdav AI Service returned a non-scalar translation item.'
                );
            }

            $ordered[] = (string) $translation;
        }

        return $ordered;
    }

    /**
     * Run a safe remote status check without exposing the site token.
     *
     * @since 1.2.0
     * @return array<string,mixed>|\WP_Error Safe status summary or error.
     */
    public function check_status() {
        if ( ! $this->is_configured() ) {
            return new \WP_Error(
                'superdav_not_configured',
                $this->build_missing_configuration_message()
            );
        }

        $base_url   = preg_replace( '#/v1/?$#', '', self::get_base_url() );
        $status_url = trailingslashit( (string) $base_url ) . 'site/status';

        $response = wp_remote_get( $status_url, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . self::get_site_token(),
                'Accept'        => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return new \WP_Error(
                'superdav_status_failed',
                'Superdav AI Service status check failed: ' . self::redact_error_message( $response->get_error_message() )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code < 200 || $status_code >= 300 ) {
            return new \WP_Error(
                'superdav_status_http_error',
                sprintf( 'Superdav AI Service status check returned HTTP %d.', (int) $status_code )
            );
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $decoded ) ) {
            return [ 'status' => 'ok' ];
        }

        return $this->redact_status_payload( $decoded );
    }

    /**
     * Build the OpenAI-compatible chat completion payload.
     *
     * @since 1.2.0
     * @param string $gp_locale    GlotPress locale slug.
     * @param array  $strings      Ordered source strings.
     * @param array  $contexts     Ordered GlotPress contexts.
     * @param array  $original_ids Ordered GlotPress original IDs.
     * @param int    $project_id   GlotPress project ID.
     * @return array<string,mixed>
     */
    private function build_chat_completion_payload( string $gp_locale, array $strings, array $contexts, array $original_ids, int $project_id ): array {
        $items = [];

        foreach ( array_values( $strings ) as $index => $string ) {
            $items[] = [
                'index'       => $index,
                'original_id' => (int) ( $original_ids[ $index ] ?? 0 ),
                'context'     => (string) ( $contexts[ $index ] ?? '' ),
                'source'      => (string) $string,
            ];
        }

        return [
            'model'           => self::get_model(),
            'temperature'     => self::get_temperature(),
            'response_format' => [ 'type' => 'json_object' ],
            'messages'        => [
                [
                    'role'    => 'system',
                    'content' => implode( "\n", [
                        'You translate WordPress plugin and theme strings.',
                        'Return JSON only. Do not include Markdown or commentary.',
                        'Return an object with a translations array in the same order as the input items.',
                        'Preserve placeholders, printf tokens, HTML tags, entities, whitespace significance, and GlotPress context meaning.',
                    ] ),
                ],
                [
                    'role'    => 'user',
                    'content' => wp_json_encode( [
                        'locale'     => $gp_locale,
                        'project_id' => $project_id,
                        'items'      => $items,
                    ] ),
                ],
            ],
        ];
    }

    /**
     * Accumulate token usage when the service returns OpenAI-compatible usage.
     *
     * @since 1.2.0
     * @param array<string,mixed> $response Decoded response body.
     * @return void
     */
    private function accumulate_usage( array $response ): void {
        $usage = $response['usage'] ?? [];

        if ( ! is_array( $usage ) ) {
            return;
        }

        $this->prompt_tokens     += (int) ( $usage['prompt_tokens'] ?? 0 );
        $this->completion_tokens += (int) ( $usage['completion_tokens'] ?? 0 );
    }

    /**
     * Build a redacted missing-configuration message.
     *
     * @since 1.2.0
     * @return string
     */
    private function build_missing_configuration_message(): string {
        $status  = self::get_configuration_status();
        $missing = $status['missing'];

        if ( empty( $missing ) ) {
            return 'Superdav AI Service is not configured.';
        }

        return 'Superdav AI Service is missing required configuration: ' . implode( ', ', $missing ) . '.';
    }

    /**
     * Get the configured Superdav base URL.
     *
     * @since 1.2.0
     * @return string
     */
    public static function get_base_url(): string {
        $env_value = getenv( 'GRATIS_AI_TS_SUPERDAV_BASE_URL' );
        $value     = is_string( $env_value ) && '' !== trim( $env_value )
            ? $env_value
            : (string) get_site_option( 'gratis_ai_ts_superdav_base_url', '' );

        $base_url = untrailingslashit( trim( $value ) );
        if ( '' === $base_url ) {
            return '';
        }

        return preg_match( '#/v1$#', $base_url ) ? $base_url : $base_url . '/v1';
    }

    /**
     * Get the configured Superdav model.
     *
     * @since 1.2.0
     * @return string
     */
    public static function get_model(): string {
        $model = (string) get_site_option( 'gratis_ai_ts_superdav_model', 'superdav-chat-pro' );

        return '' !== trim( $model ) ? trim( $model ) : 'superdav-chat-pro';
    }

    /**
     * Get the configured Superdav temperature.
     *
     * @since 1.2.0
     * @return float
     */
    public static function get_temperature(): float {
        $temperature = (float) get_site_option( 'gratis_ai_ts_superdav_temperature', 0.2 );

        return max( 0.0, min( 2.0, $temperature ) );
    }

    /**
     * Get the Superdav site token from env override or stored option.
     *
     * @since 1.2.0
     * @return string
     */
    private static function get_site_token(): string {
        $env_value = getenv( 'GRATIS_AI_TS_SUPERDAV_SITE_TOKEN' );
        if ( is_string( $env_value ) && '' !== trim( $env_value ) ) {
            return trim( $env_value );
        }

        return trim( (string) get_site_option( 'gratis_ai_ts_superdav_site_token', '' ) );
    }

    /**
     * Identify where the site token is configured without exposing the value.
     *
     * @since 1.2.0
     * @return string
     */
    private static function get_token_source(): string {
        $env_value = getenv( 'GRATIS_AI_TS_SUPERDAV_SITE_TOKEN' );
        if ( is_string( $env_value ) && '' !== trim( $env_value ) ) {
            return 'environment';
        }

        return '' !== trim( (string) get_site_option( 'gratis_ai_ts_superdav_site_token', '' ) )
            ? 'site_option'
            : 'none';
    }

    /**
     * Get a display-safe base URL without userinfo.
     *
     * @since 1.2.0
     * @return string
     */
    private static function get_redacted_base_url(): string {
        $base_url = self::get_base_url();
        if ( '' === $base_url ) {
            return '';
        }

        $parts = wp_parse_url( $base_url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return $base_url;
        }

        $scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
        $port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
        $path   = isset( $parts['path'] ) ? $parts['path'] : '';

        return $scheme . $parts['host'] . $port . $path;
    }

    /**
     * Redact secrets from an error message before it is stored or displayed.
     *
     * @since 1.2.0
     * @param string $message Error message.
     * @return string Redacted message.
     */
    public static function redact_error_message( string $message ): string {
        $token = self::get_site_token();
        if ( '' !== $token ) {
            $message = str_replace( $token, '[redacted]', $message );
        }

        $message = preg_replace( '/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [redacted]', $message );

        return is_string( $message ) ? $message : 'An unknown Superdav AI Service error occurred.';
    }

    /**
     * Redact and narrow a remote status payload to safe fields.
     *
     * @since 1.2.0
     * @param array<string,mixed> $payload Remote status payload.
     * @return array<string,mixed> Safe status payload.
     */
    private function redact_status_payload( array $payload ): array {
        $safe_keys = [
            'status',
            'tier',
            'plan',
            'usage',
            'quota',
            'wallet',
            'model',
            'models',
        ];

        $safe = [];
        foreach ( $safe_keys as $key ) {
            if ( array_key_exists( $key, $payload ) ) {
                $safe[ $key ] = $this->redact_status_value( $payload[ $key ] );
            }
        }

        return ! empty( $safe ) ? $safe : [ 'status' => 'ok' ];
    }

    /**
     * Recursively redact status values.
     *
     * @since 1.2.0
     * @param mixed $value Status value.
     * @return mixed Redacted value.
     */
    private function redact_status_value( $value ) {
        if ( is_array( $value ) ) {
            $redacted = [];
            foreach ( $value as $key => $item ) {
                if ( is_string( $key ) && preg_match( '/token|secret|key|credential|auth/i', $key ) ) {
                    $redacted[ $key ] = '[redacted]';
                    continue;
                }

                $redacted[ $key ] = $this->redact_status_value( $item );
            }

            return $redacted;
        }

        if ( is_string( $value ) ) {
            return self::redact_error_message( $value );
        }

        return $value;
    }
}
