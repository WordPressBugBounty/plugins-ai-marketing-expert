<?php
/**
 * Stock Image Service — free stock photo search (Pexels / Pixabay) and
 * import into the WordPress media library.
 *
 * API keys are stored encrypted in the content-generator settings option,
 * which is deliberately excluded from settings export (same rule as the
 * webhook API key and AI connections — secrets never leave the site).
 *
 * @package WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services;

use WPSpace\AiMarketingExpert\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StockImageService {

	private const OPTION_KEY = 'aime_content-generator_settings';

	public const PROVIDERS = array( 'pexels', 'pixabay' );

	/**
	 * Hosts we allow images to be sideloaded from. Import requests carry a
	 * user-supplied URL, so this allowlist prevents fetching arbitrary hosts.
	 */
	private const ALLOWED_IMAGE_HOSTS = array(
		'images.pexels.com',
		'pixabay.com',
		'cdn.pixabay.com',
	);

	/* ── Configuration ───────────────────────────────── */

	public static function get_provider(): string {
		$settings = get_option( self::OPTION_KEY, array() );
		$provider = sanitize_key( (string) ( $settings['stock_provider'] ?? 'pexels' ) );

		return in_array( $provider, self::PROVIDERS, true ) ? $provider : 'pexels';
	}

	/**
	 * Decrypted API key for a provider ('' when not set).
	 */
	public static function get_api_key( string $provider = '' ): string {
		$provider = $provider ?: self::get_provider();
		$settings = get_option( self::OPTION_KEY, array() );
		$stored   = (string) ( $settings[ $provider . '_api_key' ] ?? '' );

		return '' !== $stored ? Encryption::decrypt( $stored ) : '';
	}

	public static function is_configured( string $provider = '' ): bool {
		return '' !== self::get_api_key( $provider );
	}

	/* ── Search ──────────────────────────────────────── */

	/**
	 * Search the configured provider. Returns a normalized result:
	 * array{success:bool, provider:string, images:array<array{id,provider,thumb,preview,full,width,height,photographer,source_url,alt}>, error?:string}
	 */
	public function search( string $query, int $per_page = 12, int $page = 1 ): array {
		$query    = trim( $query );
		$per_page = max( 1, min( 30, $per_page ) );
		$page     = max( 1, $page );

		if ( '' === $query ) {
			return array( 'success' => false, 'error' => __( 'Search query is empty.', 'ai-marketing-expert' ) );
		}

		$provider = self::get_provider();
		$key      = self::get_api_key( $provider );
		if ( '' === $key ) {
			return array(
				'success'        => false,
				'not_configured' => true,
				'error'          => __( 'No stock image API key configured. Add one under Content → Settings → Images.', 'ai-marketing-expert' ),
			);
		}

		return 'pixabay' === $provider
			? $this->search_pixabay( $query, $per_page, $page, $key )
			: $this->search_pexels( $query, $per_page, $page, $key );
	}

	/**
	 * Top search result, or null (used by workflow cron auto-pick).
	 */
	public function first( string $query ): ?array {
		$result = $this->search( $query, 3, 1 );

		return ( ! empty( $result['success'] ) && ! empty( $result['images'] ) ) ? $result['images'][0] : null;
	}

	/**
	 * Random image from search results — intelligently picks variety instead of
	 * always the first result. Fetches more results and randomly selects from them
	 * to ensure different posts get different images even with similar queries.
	 *
	 * @param string $query Stock search query.
	 * @return ?array Image array or null if search fails.
	 */
	public function random( string $query ): ?array {
		// Fetch more results to have variety to choose from (5-8 images).
		$result = $this->search( $query, 8, 1 );

		if ( empty( $result['success'] ) || empty( $result['images'] ) ) {
			return null;
		}

		// Randomly pick one from available results, weighted toward earlier results
		// (they tend to be more relevant) but avoiding always picking the first.
		$images = $result['images'];
		$count  = count( $images );

		// Use a weighted random: favor the first 3-4 results but allow variety.
		// This gives 60% chance to top 3, rest distributed across remaining images.
		$rand = wp_rand( 0, 99 );
		if ( $rand < 25 && $count > 0 ) {
			$index = 0; // Top result (25% chance)
		} elseif ( $rand < 50 && $count > 1 ) {
			$index = 1; // Second result (25% chance)
		} elseif ( $rand < 75 && $count > 2 ) {
			$index = 2; // Third result (25% chance)
		} else {
			// Remaining results (25% chance spread across them)
			$index = wp_rand( 3, $count - 1 );
			if ( $index >= $count ) {
				$index = $count - 1;
			}
		}

		return $images[ $index ] ?? null;
	}

	private function search_pexels( string $query, int $per_page, int $page, string $key ): array {
		$url = add_query_arg(
			array(
				'query'    => rawurlencode( $query ),
				'per_page' => $per_page,
				'page'     => $page,
			),
			'https://api.pexels.com/v1/search'
		);

		$response = wp_remote_get( $url, array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => $key ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			return array(
				'success' => false,
				'error'   => 401 === $code || 403 === $code
					? __( 'Pexels rejected the API key. Check it under Content → Settings → Images.', 'ai-marketing-expert' )
					/* translators: %d: HTTP status code */
					: sprintf( __( 'Pexels API error (HTTP %d).', 'ai-marketing-expert' ), $code ),
			);
		}

		$images = array();
		foreach ( (array) ( $body['photos'] ?? array() ) as $photo ) {
			$src      = (array) ( $photo['src'] ?? array() );
			$images[] = array(
				'id'           => (string) ( $photo['id'] ?? '' ),
				'provider'     => 'pexels',
				'thumb'        => esc_url_raw( (string) ( $src['medium'] ?? '' ) ),
				'preview'      => esc_url_raw( (string) ( $src['large'] ?? '' ) ),
				'full'         => esc_url_raw( (string) ( $src['large2x'] ?? $src['large'] ?? $src['original'] ?? '' ) ),
				'width'        => (int) ( $photo['width'] ?? 0 ),
				'height'       => (int) ( $photo['height'] ?? 0 ),
				'photographer' => sanitize_text_field( (string) ( $photo['photographer'] ?? '' ) ),
				'source_url'   => esc_url_raw( (string) ( $photo['url'] ?? '' ) ),
				'alt'          => sanitize_text_field( (string) ( $photo['alt'] ?? '' ) ),
			);
		}

		return array(
			'success'  => true,
			'provider' => 'pexels',
			'total'    => (int) ( $body['total_results'] ?? count( $images ) ),
			'images'   => array_values( array_filter( $images, static fn ( array $i ): bool => '' !== $i['full'] ) ),
		);
	}

	private function search_pixabay( string $query, int $per_page, int $page, string $key ): array {
		$url = add_query_arg(
			array(
				'key'        => rawurlencode( $key ),
				'q'          => rawurlencode( $query ),
				'image_type' => 'photo',
				'safesearch' => 'true',
				'per_page'   => max( 3, $per_page ), // Pixabay minimum is 3.
				'page'       => $page,
			),
			'https://pixabay.com/api/'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			return array(
				'success' => false,
				'error'   => 400 === $code || 401 === $code || 403 === $code
					? __( 'Pixabay rejected the API key. Check it under Content → Settings → Images.', 'ai-marketing-expert' )
					/* translators: %d: HTTP status code */
					: sprintf( __( 'Pixabay API error (HTTP %d).', 'ai-marketing-expert' ), $code ),
			);
		}

		$images = array();
		foreach ( (array) ( $body['hits'] ?? array() ) as $hit ) {
			$images[] = array(
				'id'           => (string) ( $hit['id'] ?? '' ),
				'provider'     => 'pixabay',
				'thumb'        => esc_url_raw( (string) ( $hit['webformatURL'] ?? '' ) ),
				'preview'      => esc_url_raw( (string) ( $hit['webformatURL'] ?? '' ) ),
				'full'         => esc_url_raw( (string) ( $hit['largeImageURL'] ?? $hit['webformatURL'] ?? '' ) ),
				'width'        => (int) ( $hit['imageWidth'] ?? 0 ),
				'height'       => (int) ( $hit['imageHeight'] ?? 0 ),
				'photographer' => sanitize_text_field( (string) ( $hit['user'] ?? '' ) ),
				'source_url'   => esc_url_raw( (string) ( $hit['pageURL'] ?? '' ) ),
				'alt'          => sanitize_text_field( (string) ( $hit['tags'] ?? '' ) ),
			);
		}

		return array(
			'success'  => true,
			'provider' => 'pixabay',
			'total'    => (int) ( $body['totalHits'] ?? count( $images ) ),
			'images'   => array_values( array_filter( $images, static fn ( array $i ): bool => '' !== $i['full'] ) ),
		);
	}

	/* ── In-body images ──────────────────────────────── */

	/**
	 * Placeholder the AI embeds in article bodies: <!--aime-img:search query-->
	 */
	private const PLACEHOLDER_REGEX = '/<!--\s*aime-img:\s*([^>]{1,120}?)\s*-->/i';

	/**
	 * Replace AI-inserted image placeholders with imported stock photos.
	 *
	 * Fail-soft: if the provider is not configured, a search comes up empty,
	 * or an import fails, the placeholder is simply removed — the article
	 * text is never harmed. Never throws.
	 *
	 * @param string $body           Article body HTML containing placeholders.
	 * @param int    $max            Maximum number of images to embed (0 = strip all placeholders).
	 * @param string $fallback_query Used when a placeholder has an empty query.
	 * @return string Body with placeholders replaced (or stripped).
	 */
	public function embed_inline_images( string $body, int $max = 3, string $fallback_query = '' ): string {
		if ( ! preg_match_all( self::PLACEHOLDER_REGEX, $body, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $body;
		}

		$configured = self::is_configured();
		$used_ids   = array();
		$embedded   = 0;

		// Replace from the end so offsets stay valid.
		for ( $i = count( $matches[0] ) - 1; $i >= 0; $i-- ) {
			$placeholder = $matches[0][ $i ][0];
			$offset      = (int) $matches[0][ $i ][1];
			$query       = sanitize_text_field( $matches[1][ $i ][0] );
			$replacement = '';

			// Count from the top of the article: only the first $max
			// placeholders (in document order) become images.
			$rank = $i + 1;

			if ( $configured && $rank <= $max && $embedded < $max ) {
				try {
					$replacement = $this->placeholder_to_figure( '' !== $query ? $query : $fallback_query, $used_ids );
					if ( '' !== $replacement ) {
						$embedded++;
					}
				} catch ( \Throwable $e ) {
					aime_log( 'Inline stock image failed: ' . $e->getMessage(), 'warning', 'content-generator' );
					$replacement = '';
				}
			}

			$body = substr_replace( $body, $replacement, $offset, strlen( $placeholder ) );
		}

		return $body;
	}

	/**
	 * Search + import one image for a placeholder and return <figure> markup
	 * ('' when nothing suitable was found).
	 *
	 * @param string $query    Stock search query.
	 * @param array  $used_ids Attachment ids already embedded (by reference — avoids repeats).
	 */
	private function placeholder_to_figure( string $query, array &$used_ids ): string {
		$query = trim( $query );
		if ( '' === $query ) {
			return '';
		}

		$result = $this->search( $query, 5, 1 );
		if ( empty( $result['success'] ) || empty( $result['images'] ) ) {
			return '';
		}

		foreach ( $result['images'] as $image ) {
			$import = $this->import_to_media_library( $image['full'], '' !== $image['alt'] ? $image['alt'] : $query );
			if ( empty( $import['success'] ) ) {
				continue;
			}
			$attachment_id = (int) $import['attachment_id'];
			if ( in_array( $attachment_id, $used_ids, true ) ) {
				continue;
			}
			$used_ids[] = $attachment_id;

			// Embed size is a site setting (Content → Settings → Images).
			$settings = get_option( self::OPTION_KEY, array() );
			$size     = sanitize_key( (string) ( $settings['inline_image_size'] ?? 'large' ) );
			if ( ! in_array( $size, array( 'medium', 'medium_large', 'large', 'full' ), true ) ) {
				$size = 'large';
			}

			$img = wp_get_attachment_image( $attachment_id, $size, false, array(
				'class'   => 'aime-inline-image',
				'loading' => 'lazy',
			) );
			if ( '' === $img ) {
				continue;
			}

			return '<figure class="wp-block-image size-' . esc_attr( $size ) . ' aime-inline-figure">' . $img . '</figure>';
		}

		return '';
	}

	/* ── Import ──────────────────────────────────────── */

	/**
	 * Only stock-CDN hosts may be sideloaded (the URL arrives from the client).
	 */
	public static function is_allowed_image_url( string $url ): bool {
		$url = esc_url_raw( $url );
		if ( ! $url || ! preg_match( '#^https://#i', $url ) ) {
			return false;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return in_array( $host, self::ALLOWED_IMAGE_HOSTS, true );
	}

	/**
	 * Download a stock image into the media library.
	 *
	 * @return array{success:bool, attachment_id?:int, url?:string, error?:string}
	 */
	public function import_to_media_library( string $image_url, string $alt = '', int $post_id = 0 ): array {
		if ( ! self::is_allowed_image_url( $image_url ) ) {
			return array( 'success' => false, 'error' => __( 'Image URL is not from a supported stock provider.', 'ai-marketing-expert' ) );
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( esc_url_raw( $image_url ), $post_id, sanitize_text_field( $alt ), 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			return array( 'success' => false, 'error' => $attachment_id->get_error_message() );
		}

		if ( '' !== $alt ) {
			update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		return array(
			'success'       => true,
			'attachment_id' => (int) $attachment_id,
			'url'           => (string) wp_get_attachment_url( (int) $attachment_id ),
		);
	}
}
