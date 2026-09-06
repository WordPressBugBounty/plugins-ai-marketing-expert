<?php
/**
 * Platform API Service — unified API interface for publishing to social platforms.
 *
 * @package WPSpace\AiMarketingExpert\Modules\SocialMedia\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\SocialMedia\Services;

use WPSpace\AiMarketingExpert\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PlatformApiService {

	/**
	 * Publish content to a specific platform.
	 *
	 * @param object $account   Account row from database.
	 * @param string $content   Post content.
	 * @param array  $media_urls Array of media URLs to attach.
	 * @return array { success: bool, platform_post_id?: string, message?: string }
	 */
	public function publish( object $account, string $content, array $media_urls = array() ): array {
		$access_token = Encryption::decrypt( $account->access_token );
		if ( ! $access_token ) {
			return array( 'success' => false, 'message' => __( 'Invalid access token. Please reconnect the account.', 'ai-marketing-expert' ) );
		}

		switch ( $account->platform ) {
			case 'facebook':
				return $this->publish_facebook( $access_token, $account->platform_user_id, $content, $media_urls );
			case 'instagram':
				return $this->publish_instagram( $access_token, $account->platform_user_id, $content, $media_urls );
			case 'x':
				return $this->publish_x( $account, $content, $media_urls );
			default:
				return array( 'success' => false, 'message' => __( 'Unsupported platform.', 'ai-marketing-expert' ) );
		}
	}

	/**
	 * Validate an account's token is still active.
	 *
	 * @param object $account Account row.
	 * @return array { valid: bool, message?: string }
	 */
	public function validate_token( object $account ): array {
		$access_token = Encryption::decrypt( $account->access_token );
		if ( ! $access_token ) {
			return array( 'valid' => false, 'message' => __( 'Invalid token.', 'ai-marketing-expert' ) );
		}

		switch ( $account->platform ) {
			case 'facebook':
			case 'instagram':
				return $this->validate_facebook_token( $access_token );
			case 'x':
				return $this->validate_x_token( $account );
			default:
				return array( 'valid' => false, 'message' => __( 'Unsupported platform.', 'ai-marketing-expert' ) );
		}
	}

	/**
	 * Fetch profile info for an account.
	 *
	 * @param string $platform
	 * @param string $access_token Decrypted token.
	 * @return array { success: bool, name?: string, avatar_url?: string, platform_user_id?: string }
	 */
	public function get_profile( string $platform, string $access_token ): array {
		switch ( $platform ) {
			case 'facebook':
				$response = wp_remote_get(
					'https://graph.facebook.com/v25.0/me?fields=id,name,picture.width(100).height(100)&access_token=' . $access_token,
					array( 'timeout' => 15 )
				);
				if ( is_wp_error( $response ) ) {
					return array( 'success' => false, 'message' => $response->get_error_message() );
				}
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( empty( $body['id'] ) ) {
					return array( 'success' => false, 'message' => $body['error']['message'] ?? __( 'Failed to fetch profile.', 'ai-marketing-expert' ) );
				}
				return array(
					'success'          => true,
					'platform_user_id' => (string) $body['id'],
					'name'             => $body['name'] ?? '',
					'avatar_url'       => $body['picture']['data']['url'] ?? '',
				);

			case 'instagram':
				// New Instagram API with Instagram Login — no Facebook Page required.
				// Token obtained from App Dashboard → Instagram → API setup with Instagram business login.
				$response = wp_remote_get(
					'https://graph.instagram.com/v25.0/me?fields=user_id,username,name,profile_picture_url&access_token=' . $access_token,
					array( 'timeout' => 15 )
				);
				if ( is_wp_error( $response ) ) {
					return array( 'success' => false, 'message' => $response->get_error_message() );
				}
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! empty( $body['error'] ) ) {
					return array( 'success' => false, 'message' => $body['error']['message'] ?? __( 'Invalid Instagram access token.', 'ai-marketing-expert' ) );
				}
				$ig_user_id = (string) ( $body['user_id'] ?? $body['id'] ?? '' );
				if ( empty( $ig_user_id ) ) {
					return array( 'success' => false, 'message' => __( 'Could not retrieve Instagram user ID from token.', 'ai-marketing-expert' ) );
				}
				return array(
					'success'          => true,
					'platform_user_id' => $ig_user_id,
					'name'             => $body['name'] ?? $body['username'] ?? '',
					'avatar_url'       => $body['profile_picture_url'] ?? '',
				);

			case 'x':
				// We'd need the full account object for X / Twitter API v2.
				return array( 'success' => false, 'message' => __( 'Use validate_token for X accounts.', 'ai-marketing-expert' ) );

			default:
				return array( 'success' => false );
		}
	}

	/**
	 * Resolve a Facebook page connection from either a page token or a user token.
	 *
	 * @param string $access_token Facebook page or user token.
	 * @param string $provided_page_id Optional page ID when a user token manages multiple pages.
	 * @return array { success: bool, platform_user_id?: string, name?: string, avatar_url?: string, access_token?: string, message?: string }
	 */
	public function resolve_facebook_page_connection( string $access_token, string $provided_page_id = '' ): array {
		// First try direct page-style resolution for a page access token.
		$direct = wp_remote_get(
			'https://graph.facebook.com/v25.0/me?fields=id,name,picture.width(100).height(100)&access_token=' . rawurlencode( $access_token ),
			array( 'timeout' => 15 )
		);

		if ( ! is_wp_error( $direct ) ) {
			$body = json_decode( wp_remote_retrieve_body( $direct ), true );
			if ( ! empty( $body['id'] ) && empty( $body['error'] ) ) {
				return array(
					'success'          => true,
					'platform_user_id' => (string) $body['id'],
					'name'             => $body['name'] ?? '',
					'avatar_url'       => $body['picture']['data']['url'] ?? '',
					'access_token'     => $access_token,
				);
			}
		}

		// If that did not resolve a usable page, treat it as a user token and fetch pages.
		$response = wp_remote_get(
			'https://graph.facebook.com/v25.0/me/accounts?fields=id,name,access_token,picture.width(100).height(100),tasks&access_token=' . rawurlencode( $access_token ),
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$pages = $body['data'] ?? array();

		if ( ! empty( $body['error'] ) ) {
			return array( 'success' => false, 'message' => $body['error']['message'] ?? __( 'Failed to fetch Facebook pages.', 'ai-marketing-expert' ) );
		}

		if ( empty( $pages ) ) {
			return array( 'success' => false, 'message' => __( 'No Facebook Pages were found for this token. Make sure the token has pages_show_list, pages_read_engagement, and pages_manage_posts permissions, and that the app is authorized for the Page.', 'ai-marketing-expert' ) );
		}

		$page = null;
		if ( $provided_page_id ) {
			foreach ( $pages as $candidate ) {
				if ( (string) ( $candidate['id'] ?? '' ) === (string) $provided_page_id ) {
					$page = $candidate;
					break;
				}
			}

			if ( ! $page ) {
				return array( 'success' => false, 'message' => __( 'The provided Facebook Page ID was not found for this token.', 'ai-marketing-expert' ) );
			}
		} elseif ( 1 === count( $pages ) ) {
			$page = $pages[0];
		} else {
			return array( 'success' => false, 'message' => __( 'This token can access multiple Facebook Pages. Paste the specific Facebook Page ID in the optional Page ID field, or use a Page Access Token for just one Page.', 'ai-marketing-expert' ) );
		}

		return array(
			'success'          => true,
			'platform_user_id' => (string) ( $page['id'] ?? '' ),
			'name'             => $page['name'] ?? '',
			'avatar_url'       => $page['picture']['data']['url'] ?? '',
			'access_token'     => $page['access_token'] ?? $access_token,
		);
	}

	/* ------------------------------------------------------------------
	 * FACEBOOK
	 * ----------------------------------------------------------------*/

	private function publish_facebook( string $token, string $page_id, string $content, array $media_urls ): array {
		$endpoint = "https://graph.facebook.com/v25.0/{$page_id}/feed";

		$body = array(
			'message'      => $content,
			'access_token' => $token,
		);

		// If there's a link in the content, attach it.
		if ( preg_match( '/(https?:\/\/[^\s]+)/', $content, $matches ) ) {
			$body['link'] = $matches[1];
		}

		// Handle photo upload.
		if ( ! empty( $media_urls ) ) {
			return $this->publish_facebook_photo( $token, $page_id, $content, $media_urls );
		}

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 30,
			'body'    => $body,
		) );

		return $this->parse_facebook_publish_response( $response );
	}

	private function publish_facebook_photo( string $token, string $page_id, string $content, array $media_urls ): array {
		$photo_ids = array();

		foreach ( $media_urls as $url ) {
			$response = wp_remote_post( "https://graph.facebook.com/v25.0/{$page_id}/photos", array(
				'timeout' => 60,
				'body'    => array(
					'url'           => $url,
					'published'     => false,
					'access_token'  => $token,
				),
			) );

			if ( ! is_wp_error( $response ) ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! empty( $body['id'] ) ) {
					$photo_ids[] = $body['id'];
				}
			}
		}

		if ( empty( $photo_ids ) ) {
			// Fallback to text-only.
			return $this->publish_facebook( $token, $page_id, $content, array() );
		}

		// Publish with attached photos.
		$post_body = array(
			'message'      => $content,
			'access_token' => $token,
		);
		foreach ( $photo_ids as $i => $pid ) {
			$post_body[ "attached_media[{$i}]" ] = wp_json_encode( array( 'media_fbid' => $pid ) );
		}

		$response = wp_remote_post( "https://graph.facebook.com/v25.0/{$page_id}/feed", array(
			'timeout' => 30,
			'body'    => $post_body,
		) );

		return $this->parse_facebook_publish_response( $response );
	}

	private function parse_facebook_publish_response( $response ): array {
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['id'] ) ) {
			return array( 'success' => true, 'platform_post_id' => (string) $body['id'] );
		}

		$error_msg = $body['error']['message'] ?? __( 'Facebook publish failed.', 'ai-marketing-expert' );
		$error_msg = preg_replace( '/\\n/', ' ', $error_msg );

		return array(
			'success' => false,
			'message' => $error_msg,
		);
	}

	private function validate_facebook_token( string $token ): array {
		$response = wp_remote_get(
			'https://graph.facebook.com/v25.0/me?access_token=' . $token,
			array( 'timeout' => 10 )
		);

		if ( is_wp_error( $response ) ) {
			return array( 'valid' => false, 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return array( 'valid' => ! empty( $body['id'] ) );
	}

	/* ------------------------------------------------------------------
	 * INSTAGRAM
	 * ----------------------------------------------------------------*/

	private function publish_instagram( string $token, string $ig_user_id, string $content, array $media_urls ): array {
		if ( empty( $media_urls ) ) {
			return array( 'success' => false, 'message' => __( 'Instagram requires at least one image.', 'ai-marketing-expert' ) );
		}

		$image_url = $media_urls[0];

		// Instagram's servers must be able to fetch the image URL — local/private URLs won't work.
		$host = wp_parse_url( $image_url, PHP_URL_HOST );
		if ( $host && in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return array(
				'success' => false,
				'message' => __( 'Instagram cannot access local (localhost) image URLs. Upload your images to a publicly accessible server first.', 'ai-marketing-expert' ),
			);
		}

		$base = 'https://graph.instagram.com/v25.0';

		// Step 1: Create media container.
		$response = wp_remote_post( "{$base}/{$ig_user_id}/media", array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'media_type'   => 'IMAGE',
				'image_url'    => $image_url,
				'caption'      => $content,
				'access_token' => $token,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['id'] ) ) {
			return array( 'success' => false, 'message' => $body['error']['message'] ?? __( 'Instagram container creation failed.', 'ai-marketing-expert' ) );
		}

		$creation_id = $body['id'];

		// Step 2: Wait briefly for processing.
		sleep( 2 );

		// Step 3: Publish the container.
		$pub_response = wp_remote_post( "{$base}/{$ig_user_id}/media_publish", array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'creation_id'  => $creation_id,
				'access_token' => $token,
			) ),
		) );

		if ( is_wp_error( $pub_response ) ) {
			return array( 'success' => false, 'message' => $pub_response->get_error_message() );
		}

		$pub_body = json_decode( wp_remote_retrieve_body( $pub_response ), true );
		if ( ! empty( $pub_body['id'] ) ) {
			return array( 'success' => true, 'platform_post_id' => (string) $pub_body['id'] );
		}

		return array(
			'success' => false,
			'message' => $pub_body['error']['message'] ?? __( 'Instagram publish failed.', 'ai-marketing-expert' ),
		);
	}

	/* ------------------------------------------------------------------
	 * X (TWITTER)
	 * ----------------------------------------------------------------*/

	private function publish_x( object $account, string $content, array $media_urls ): array {
		$access_token  = Encryption::decrypt( $account->access_token );
		$access_secret = Encryption::decrypt( $account->refresh_token ); // Stored as "refresh" but it's the token secret.
		$credentials   = $this->get_x_account_credentials( $account );
		$api_key       = $credentials['api_key'];
		$api_secret    = $credentials['api_secret'];

		if ( empty( $access_token ) || empty( $access_secret ) || empty( $api_key ) || empty( $api_secret ) ) {
			return array( 'success' => false, 'message' => __( 'X API credentials not configured.', 'ai-marketing-expert' ) );
		}

		// Upload media first if present.
		$media_ids = array();
		foreach ( $media_urls as $url ) {
			$media_id = $this->upload_x_media( $api_key, $api_secret, $access_token, $access_secret, $url );
			if ( $media_id ) {
				$media_ids[] = $media_id;
			}
		}

		// Build tweet payload.
		$payload = array( 'text' => $content );
		if ( ! empty( $media_ids ) ) {
			$payload['media'] = array( 'media_ids' => $media_ids );
		}

		$url    = 'https://api.x.com/2/tweets';
		$method = 'POST';

		$oauth_header = $this->build_x_oauth_header( $method, $url, array(), $api_key, $api_secret, $access_token, $access_secret );

		$response = wp_remote_post( $url, array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => $oauth_header,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['data']['id'] ) ) {
			return array( 'success' => true, 'platform_post_id' => (string) $body['data']['id'] );
		}

		return array(
			'success' => false,
			'message' => $this->parse_x_error_message( $body, __( 'X publish failed.', 'ai-marketing-expert' ) ),
		);
	}

	private function validate_x_token( object $account ): array {
		$access_token  = Encryption::decrypt( $account->access_token );
		$access_secret = Encryption::decrypt( $account->refresh_token );
		$credentials   = $this->get_x_account_credentials( $account );

		if ( empty( $access_token ) || empty( $access_secret ) || empty( $credentials['api_key'] ) || empty( $credentials['api_secret'] ) ) {
			return array( 'valid' => false, 'message' => __( 'X API credentials not configured.', 'ai-marketing-expert' ) );
		}

		$url          = 'https://api.x.com/2/users/me';
		$oauth_header = $this->build_x_oauth_header( 'GET', $url, array(), $credentials['api_key'], $credentials['api_secret'], $access_token, $access_secret );

		$response = wp_remote_get( $url, array(
			'timeout' => 10,
			'headers' => array( 'Authorization' => $oauth_header ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'valid' => false, 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['data']['id'] ) ) {
			return array( 'valid' => true, 'message' => __( 'X account connection verified.', 'ai-marketing-expert' ) );
		}

		return array(
			'valid'   => false,
			'message' => $this->parse_x_error_message( $body, __( 'X account validation failed.', 'ai-marketing-expert' ) ),
		);
	}

	private function parse_x_error_message( array $body, string $fallback ): string {
		if ( ! empty( $body['detail'] ) ) {
			return (string) $body['detail'];
		}

		if ( ! empty( $body['title'] ) ) {
			return (string) $body['title'];
		}

		if ( ! empty( $body['errors'] ) && is_array( $body['errors'] ) ) {
			$messages = array();
			foreach ( $body['errors'] as $error ) {
				if ( ! is_array( $error ) ) {
					continue;
				}
				$messages[] = $error['message'] ?? $error['detail'] ?? $error['title'] ?? '';
			}
			$messages = array_filter( array_map( 'trim', $messages ) );
			if ( ! empty( $messages ) ) {
				return implode( ' ', $messages );
			}
		}

		return $fallback;
	}

	/**
	 * Validate that a remote URL is safe to fetch (SSRF protection).
	 *
	 * Only allows http/https schemes and rejects hosts that resolve to
	 * private, reserved, or loopback IP ranges (e.g. cloud metadata endpoints).
	 * Resolves all A/AAAA records so a host with both public and private
	 * addresses cannot bypass the check.
	 *
	 * @param string $url URL to validate.
	 * @return bool True if the URL is safe to request.
	 */
	private function is_safe_remote_url( string $url ): bool {
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['scheme'] ) || ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		$host = $parsed['host'] ?? '';
		if ( '' === $host ) {
			return false;
		}

		// Resolve every A/AAAA record and reject if any address is private
		// or reserved. Single-A lookups (gethostbyname) miss hosts that have
		// both public and private addresses in DNS.
		$ips = filter_var( $host, FILTER_VALIDATE_IP )
			? array( $host )
			: ( function () use ( $host ) {
				$records = @gethostbynamel( $host );
				return is_array( $records ) ? $records : array();
			} )();

		if ( empty( $ips ) ) {
			return false;
		}

		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return false;
			}
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}

		return true;
	}

	private function upload_x_media( string $api_key, string $api_secret, string $token, string $token_secret, string $image_url ): ?string {
		// X (Twitter) v1.1 media upload caps uploads at 5 MB. We enforce this
		// both as a URL-side pre-check and a downloaded-file size cap so a
		// malicious server cannot exhaust memory even if the SSRF check
		// somehow passes.
		$max_bytes = 5 * 1024 * 1024;

		// Reject unsafe URLs (SSRF protection) before downloading.
		if ( ! $this->is_safe_remote_url( $image_url ) ) {
			return null;
		}

		// Download image to local.
		$tmp = download_url( $image_url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return null;
		}

		// Enforce the size cap on the downloaded file.
		$size = filesize( $tmp );
		if ( false === $size || $size > $max_bytes ) {
			wp_delete_file( $tmp );
			return null;
		}

		$file_data  = file_get_contents( $tmp );
		$media_data = base64_encode( $file_data );
		wp_delete_file( $tmp );

		$url          = 'https://upload.twitter.com/1.1/media/upload.json';
		$oauth_header = $this->build_x_oauth_header( 'POST', $url, array(), $api_key, $api_secret, $token, $token_secret );

		$response = wp_remote_post( $url, array(
			'timeout' => 60,
			'headers' => array( 'Authorization' => $oauth_header ),
			'body'    => array( 'media_data' => $media_data ),
		) );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return $body['media_id_string'] ?? null;
	}

	/**
	 * Build OAuth 1.0a Authorization header for X.
	 */
	private function build_x_oauth_header( string $method, string $url, array $extra_params, string $consumer_key, string $consumer_secret, string $token, string $token_secret ): string {
		$oauth = array(
			'oauth_consumer_key'     => $consumer_key,
			'oauth_nonce'            => wp_generate_password( 32, false ),
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp'        => (string) time(),
			'oauth_token'            => $token,
			'oauth_version'          => '1.0',
		);

		$all_params = array_merge( $oauth, $extra_params );
		ksort( $all_params );

		$base_parts = array(
			strtoupper( $method ),
			rawurlencode( $url ),
			rawurlencode( http_build_query( $all_params, '', '&', PHP_QUERY_RFC3986 ) ),
		);

		$base_string = implode( '&', $base_parts );
		$signing_key = rawurlencode( $consumer_secret ) . '&' . rawurlencode( $token_secret );

		$oauth['oauth_signature'] = base64_encode( hash_hmac( 'sha1', $base_string, $signing_key, true ) );

		$header_parts = array();
		foreach ( $oauth as $key => $value ) {
			$header_parts[] = rawurlencode( $key ) . '="' . rawurlencode( $value ) . '"';
		}

		return 'OAuth ' . implode( ', ', $header_parts );
	}

	private function get_social_settings( array $defaults ): array {
		$settings = wp_parse_args( get_option( 'aime_social-media_settings', array() ), $defaults );

		foreach ( array( 'facebook_app_secret', 'instagram_app_secret', 'x_api_secret', 'x_access_token', 'x_access_secret' ) as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$decrypted = Encryption::decrypt( $settings[ $key ] );
				$settings[ $key ] = '' !== $decrypted ? $decrypted : $settings[ $key ];
			}
		}

		return $settings;
	}

	private function get_x_account_credentials( object $account ): array {
		$meta = json_decode( $account->meta ?: '{}', true );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$api_key    = '';
		$api_secret = '';

		if ( ! empty( $meta['api_key'] ) ) {
			$api_key = Encryption::decrypt( $meta['api_key'] );
		}
		if ( ! empty( $meta['api_secret'] ) ) {
			$api_secret = Encryption::decrypt( $meta['api_secret'] );
		}

		if ( empty( $api_key ) || empty( $api_secret ) ) {
			$settings = $this->get_social_settings( array(
				'x_api_key'    => '',
				'x_api_secret' => '',
			) );
			$api_key    = $api_key ?: $settings['x_api_key'];
			$api_secret = $api_secret ?: $settings['x_api_secret'];
		}

		return array(
			'api_key'    => $api_key,
			'api_secret' => $api_secret,
		);
	}
}
