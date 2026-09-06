<?php
/**
 * Context Knowledge Store — URL fetch + transient cache service.
 *
 * Fetches URL content once, caches it as a WordPress transient (7-day default),
 * returns plain text. Used by AI Brain and Custom Prompt actions to inject
 * external context without re-fetching on every workflow run.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes
 */

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContextKnowledgeStore {

	/**
	 * Transient prefix for URL caches.
	 */
	private const CACHE_PREFIX = 'aime_urlcache_';

	/**
	 * Default TTL: 7 days.
	 */
	private const DEFAULT_TTL = 604800;

	/**
	 * Get plain text for a URL, from cache or by fetching.
	 *
	 * @param string $url URL to fetch.
	 * @param int    $ttl Cache TTL in seconds (default 7 days).
	 * @return string Plain text excerpt (empty string on any failure).
	 */
	public static function get( string $url, int $ttl = self::DEFAULT_TTL ): string {
		$url = trim( $url );
		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		$key    = self::cache_key( $url );
		$cached = get_transient( $key );

		if ( false !== $cached && is_string( $cached ) ) {
			return $cached;
		}

		// Cache miss — fetch and store.
		$text = self::fetch( $url );
		if ( '' !== $text ) {
			set_transient( $key, $text, $ttl );
		}

		return $text;
	}

	/**
	 * Force re-fetch a URL and update the cache.
	 *
	 * @param string $url URL to fetch.
	 * @return string Plain text excerpt.
	 */
	public static function refresh( string $url ): string {
		self::flush( $url );
		return self::get( $url );
	}

	/**
	 * Delete one cached URL entry.
	 *
	 * @param string $url URL whose cache to delete.
	 */
	public static function flush( string $url ): void {
		$url = trim( $url );
		if ( '' !== $url ) {
			delete_transient( self::cache_key( $url ) );
		}
	}

	/**
	 * Delete all URL cache transients.
	 *
	 * Useful for a settings "Clear workflow URL cache" action.
	 */
	public static function flush_all(): void {
		global $wpdb;
		$prefix = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$prefix
		) );
		// Also delete timeout transients.
		$timeout_prefix = $wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$timeout_prefix
		) );
	}

	/**
	 * Compute the transient cache key for a URL.
	 *
	 * @param string $url URL.
	 * @return string Transient key.
	 */
	public static function cache_key( string $url ): string {
		return self::CACHE_PREFIX . md5( $url );
	}

	/**
	 * Fetch a URL and return plain text excerpt (fail-soft).
	 *
	 * SSRF guard: only public http(s) URLs are fetched. Credentials in the
	 * URL, non-http schemes, unresolved hosts and private/reserved/loopback
	 * IP targets are rejected before any request leaves the server.
	 *
	 * @param string $url URL to fetch.
	 * @return string Plain text (empty on failure).
	 */
	private static function fetch( string $url ): string {
		if ( ! self::is_safe_url( $url ) ) {
			aime_log(
				sprintf( 'Knowledge Store blocked unsafe URL fetch: %s', esc_url_raw( $url ) ),
				'warning',
				'workflow-automation'
			);
			return '';
		}

		$response = wp_remote_get( $url, array(
			'timeout'     => 15,
			'redirection' => 2,
			'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
		) );

		if ( is_wp_error( $response ) ) {
			aime_log(
				'Knowledge Store URL fetch failed (' . esc_url_raw( $url ) . '): ' . $response->get_error_message(),
				'warning',
				'workflow-automation'
			);
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			aime_log(
				sprintf( 'Knowledge Store URL fetch HTTP %d for %s', $code, esc_url_raw( $url ) ),
				'warning',
				'workflow-automation'
			);
			return '';
		}

		$body = wp_remote_retrieve_body( $response );

		// Strip non-content blocks before tag removal.
		$body = preg_replace( '/<(script|style|noscript|nav|header|footer|aside)[^>]*>.*?<\/\1>/is', ' ', $body );

		// Remove all HTML tags.
		$text = wp_strip_all_tags( $body );

		// Collapse whitespace.
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );
		$text = trim( $text );

		// Cap at 4000 characters.
		if ( mb_strlen( $text ) > 4000 ) {
			$text = mb_substr( $text, 0, 4000 );
		}

		return $text;
	}

	/**
	 * Whether a URL is safe to fetch server-side.
	 *
	 * Blocks: non-http(s) schemes, embedded credentials, hosts that do not
	 * resolve, IP literals and resolved addresses in private/reserved ranges
	 * (loopback, RFC1918, link-local, cloud metadata endpoints).
	 *
	 * @param string $url URL to vet.
	 * @return bool
	 */
	public static function is_safe_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $host ) {
			return false;
		}

		// Already an IP literal? Vet it directly.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_public_ip( $host );
		}

		// Resolve the hostname and vet every returned address.
		$ip = gethostbyname( $host );
		if ( '' === $ip || $ip === $host ) {
			return false; // Unresolvable host.
		}

		$addresses = array_filter( array_map( 'trim', explode( ',', $ip ) ), 'strlen' );
		foreach ( $addresses as $address ) {
			if ( ! self::is_public_ip( $address ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * True only for public, globally routable IPs.
	 *
	 * @param string $ip IPv4/IPv6 address.
	 * @return bool
	 */
	private static function is_public_ip( string $ip ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		// NO_PRIV_RANGE covers RFC1918 + fc00::/7; NO_RES_RANGE covers
		// loopback 127/8 + ::1, 0/8, link-local 169.254/16 + fe80::/10,
		// multicast and reserved blocks — including the 169.254.169.254
		// metadata endpoint used for credential theft on cloud hosts.
		return false !== filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
}
