<?php
/**
 * Rate Limiter class
 *
 * Handles API rate limiting per IP address.
 *
 * @package GratisAITranslationsServer
 */

declare(strict_types=1);

namespace GratisAITranslationsServer;

/**
 * Rate Limiter class.
 *
 * @since 1.0.0
 */
class Rate_Limiter {

    /**
     * Cache group name.
     *
     * @since 1.0.0
     * @var string
     */
    private string $cache_group = 'gratis_ai_ts_rate_limit';

    /**
     * Rate limit per hour.
     *
     * @since 1.0.0
     * @var int
     */
    private int $limit_per_hour;

    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->limit_per_hour = (int) get_site_option('gratis_ai_ts_rate_limit_per_hour', 100);
    }

    /**
     * Check if IP is rate limited.
     *
     * @since 1.0.0
     * @param string $ip_address IP address.
     * @return bool True if rate limited.
     */
    public function is_rate_limited(string $ip_address): bool {
        $key = $this->get_cache_key($ip_address);
        $requests = get_site_transient($key);

        if (false === $requests) {
            return false;
        }

        // Clean old requests (older than 1 hour).
        $requests = $this->clean_old_requests($requests);

        // Check if over limit.
        if (count($requests) >= $this->limit_per_hour) {
            return true;
        }

        return false;
    }

    /**
     * Record a request.
     *
     * @since 1.0.0
     * @param string $ip_address IP address.
     * @return void
     */
    public function record_request(string $ip_address): void {
        $key = $this->get_cache_key($ip_address);
        $requests = get_site_transient($key);

        if (false === $requests) {
            $requests = [];
        }

        // Add current request.
        $requests[] = time();

        // Store updated list.
        set_site_transient($key, $requests, HOUR_IN_SECONDS);
    }

    /**
     * Get retry after time in seconds.
     *
     * @since 1.0.0
     * @param string $ip_address IP address.
     * @return int Seconds until retry is allowed.
     */
    public function get_retry_after(string $ip_address): int {
        $key = $this->get_cache_key($ip_address);
        $requests = get_site_transient($key);

        if (false === $requests || empty($requests)) {
            return 0;
        }

        // Find the oldest request in the current window.
        sort($requests);
        $oldest = $requests[0];

        // Calculate when it will expire (1 hour from oldest).
        $expiry = $oldest + HOUR_IN_SECONDS;
        $retry_after = $expiry - time();

        return max(0, $retry_after);
    }

    /**
     * Get current request count for IP.
     *
     * @since 1.0.0
     * @param string $ip_address IP address.
     * @return int Request count.
     */
    public function get_request_count(string $ip_address): int {
        $key = $this->get_cache_key($ip_address);
        $requests = get_site_transient($key);

        if (false === $requests) {
            return 0;
        }

        $requests = $this->clean_old_requests($requests);

        return count($requests);
    }

    /**
     * Get cache key for IP address.
     *
     * @since 1.0.0
     * @param string $ip_address IP address.
     * @return string Cache key.
     */
    private function get_cache_key(string $ip_address): string {
        return 'gratis_ai_ts_rate_' . md5($ip_address);
    }

    /**
     * Clean old requests from the list.
     *
     * @since 1.0.0
     * @param array $requests Array of timestamps.
     * @return array Cleaned array.
     */
    private function clean_old_requests(array $requests): array {
        $one_hour_ago = time() - HOUR_IN_SECONDS;

        return array_filter($requests, function ($timestamp) use ($one_hour_ago) {
            return $timestamp > $one_hour_ago;
        });
    }

    /**
     * Clear rate limit for an IP.
     *
     * @since 1.0.0
     * @param string $ip_address IP address.
     * @return void
     */
    public function clear_limit(string $ip_address): void {
        $key = $this->get_cache_key($ip_address);
        delete_site_transient($key);
    }

    /**
     * Clear all rate limits.
     *
     * @since 1.0.0
     * @return void
     */
    public function clear_all_limits(): void {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                '%_transient_gratis_ai_ts_rate_%'
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                '%_transient_timeout_gratis_ai_ts_rate_%'
            )
        );
    }
}
