<?php
/**
 * OAuth Service — manages OAuth 2.0 flows for social platforms.
 *
 * Supports two modes:
 * - Proxy: Routes through WPSpace OAuth proxy server.
 * - Manual: Uses admin-supplied app credentials directly.
 *
 * @package WPSpace\AiMarketingExpert\Modules\SocialMedia\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\SocialMedia\Services;

use WPSpace\AiMarketingExpert\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OAuthService {

	/**
	 * OAuth proxy base URL.
	 */
	const PROXY_URL = 'https://oauth.wpthemespace.com/v1';

	/**
	 * Get the OAuth authorization URL for a platform.
	 *
	 * @param string $platform facebook|instagram|x
	 * @return array { success: bool, url?: string, message?: string }
	 */
	public function get_auth_url( string $platform ): array {
		$settings = $this->get_settings();
		$mode     = $settings['oauth_mode'] ?? 'proxy';
		$callback = $this->get_callback_url();

		// Generate and store a state token.
		$state = wp_generate_password( 32, false );
		$transient_key = 'aime_social_oauth_state_' . get_current_user_id();
		set_transient( $transient_key, array(
			'state'    => $state,
			'platform' => $platform,
			'mode'     => $mode,
		), 600 ); // 10 minutes.

		if ( 'proxy' === $mode ) {
			return $this->get_proxy_auth_url( $platform, $state, $callback );
		}

		return $this->get_manual_auth_url( $platform, $state, $callback, $settings );
	}

	/**
	 * Handle OAuth callback and exchange code for tokens.
	 *
	 * @param string $platform
	 * @param string $code Authorization code.
	 * @param string $state State parameter to verify.
	 * @return array { success: bool, tokens?: array, profile?: array, message?: string }
	 */
	public function handle_callback( string $platform, string $code, string $state ): array {
		// Verify state.
		$transient_key = 'aime_social_oauth_state_' . get_current_user_id();
		$stored = get_transient( $transient_key );
		delete_transient( $transient_key );

		if ( ! $stored || $stored['state'] !== $state || $stored['platform'] !== $platform ) {
			return array( 'success' => false, 'message' => __( 'Invalid OAuth state. Please try again.', 'ai-marketing-expert' ) );
		}

		$mode     = $stored['mode'];
		$settings = $this->get_settings();
		$callback = $this->get_callback_url();

		if ( 'proxy' === $mode ) {
			return $this->exchange_proxy_token( $platform, $code, $callback );
		}

		return $this->exchange_manual_token( $platform, $code, $callback, $settings );
	}

	/**
	 * Refresh an access token using the stored refresh token.
	 *
	 * @param string $platform
	 * @param string $encrypted_refresh_token
	 * @return array { success: bool, access_token?: string, expires_at?: string, message?: string }
	 */
	public function refresh_token( string $platform, string $encrypted_refresh_token ): array {
		$refresh_token = Encryption::decrypt( $encrypted_refresh_token );
		if ( ! $refresh_token ) {
			return array( 'success' => false, 'message' => __( 'Invalid refresh token.', 'ai-marketing-expert' ) );
		}

		$settings = $this->get_settings();
		$mode     = $settings['oauth_mode'] ?? 'proxy';

		if ( 'proxy' === $mode ) {
			return $this->refresh_proxy_token( $platform, $refresh_token );
		}

		return $this->refresh_manual_token( $platform, $refresh_token, $settings );
	}

	/* ------------------------------------------------------------------
	 * PROXY MODE
	 * ----------------------------------------------------------------*/

	private function get_proxy_auth_url( string $platform, string $state, string $callback ): array {
		$url = add_query_arg( array(
			'platform' => $platform,
			'state'    => $state,
			'callback' => rawurlencode( $callback ),
			'site'     => rawurlencode( home_url() ),
		), self::PROXY_URL . '/authorize' );

		return array( 'success' => true, 'url' => $url );
	}

	private function exchange_proxy_token( string $platform, string $code, string $callback ): array {
		$response = wp_remote_post( self::PROXY_URL . '/token', array(
			'timeout' => 30,
			'body'    => array(
				'platform' => $platform,
				'code'     => $code,
				'callback' => $callback,
				'site'     => home_url(),
			),
		) );

		return $this->parse_token_response( $response );
	}

	private function refresh_proxy_token( string $platform, string $refresh_token ): array {
		$response = wp_remote_post( self::PROXY_URL . '/refresh', array(
			'timeout' => 30,
			'body'    => array(
				'platform'      => $platform,
				'refresh_token' => $refresh_token,
				'site'          => home_url(),
			),
		) );

		return $this->parse_token_response( $response );
	}

	/* ------------------------------------------------------------------
	 * MANUAL MODE
	 * ----------------------------------------------------------------*/

	private function get_manual_auth_url( string $platform, string $state, string $callback, array $settings ): array {
		switch ( $platform ) {
			case 'facebook':
			case 'instagram':
				$app_id = $settings[ $platform . '_app_id' ] ?? '';
				if ( empty( $app_id ) ) {
					return array( 'success' => false, 'message' => __( 'Facebook/Instagram App ID not configured.', 'ai-marketing-expert' ) );
				}
				$scopes = 'facebook' === $platform
					? 'pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_metadata'
					: 'instagram_business_basic,instagram_business_content_publish';
				$url = add_query_arg( array(
					'client_id'    => $app_id,
					'redirect_uri' => rawurlencode( $callback ),
					'scope'        => $scopes,
					'state'        => $state,
					'response_type' => 'code',
				), 'https://www.facebook.com/v25.0/dialog/oauth' );
				return array( 'success' => true, 'url' => $url );

			case 'x':
				// X (Twitter) uses OAuth 1.0a or OAuth 2.0 PKCE.
				// For manual mode with API keys, we use direct token auth.
				return array(
					'success' => false,
					'message' => __( 'X (Twitter) manual mode uses API keys directly. Use the manual connect form.', 'ai-marketing-expert' ),
				);

			default:
				return array( 'success' => false, 'message' => __( 'Unsupported platform.', 'ai-marketing-expert' ) );
		}
	}

	private function exchange_manual_token( string $platform, string $code, string $callback, array $settings ): array {
		switch ( $platform ) {
			case 'facebook':
			case 'instagram':
				$app_id     = $settings[ $platform . '_app_id' ] ?? '';
				$app_secret = $settings[ $platform . '_app_secret' ] ?? '';
				$response   = wp_remote_post( 'https://graph.facebook.com/v25.0/oauth/access_token', array(
					'timeout' => 30,
					'body'    => array(
						'client_id'     => $app_id,
						'client_secret' => $app_secret,
						'redirect_uri'  => $callback,
						'code'          => $code,
					),
				) );
				return $this->parse_facebook_token_response( $response, $platform );

			default:
				return array( 'success' => false, 'message' => __( 'Unsupported platform for manual token exchange.', 'ai-marketing-expert' ) );
		}
	}

	private function refresh_manual_token( string $platform, string $refresh_token, array $settings ): array {
		switch ( $platform ) {
			case 'facebook':
			case 'instagram':
				$app_id     = $settings[ $platform . '_app_id' ] ?? '';
				$app_secret = $settings[ $platform . '_app_secret' ] ?? '';
				$response   = wp_remote_get( add_query_arg( array(
					'grant_type'    => 'fb_exchange_token',
					'client_id'     => $app_id,
					'client_secret' => $app_secret,
					'fb_exchange_token' => $refresh_token,
				), 'https://graph.facebook.com/v25.0/oauth/access_token' ), array( 'timeout' => 30 ) );
				return $this->parse_facebook_token_response( $response, $platform );

			default:
				return array( 'success' => false, 'message' => __( 'Token refresh not supported for this platform in manual mode.', 'ai-marketing-expert' ) );
		}
	}

	/* ------------------------------------------------------------------
	 * HELPERS
	 * ----------------------------------------------------------------*/

	private function parse_token_response( $response ): array {
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 || empty( $body ) ) {
			return array( 'success' => false, 'message' => $body['error'] ?? __( 'Token exchange failed.', 'ai-marketing-expert' ) );
		}

		return array(
			'success' => true,
			'tokens'  => array(
				'access_token'  => $body['access_token'] ?? '',
				'refresh_token' => $body['refresh_token'] ?? '',
				'expires_in'    => $body['expires_in'] ?? 3600,
			),
			'profile' => array(
				'platform_user_id' => (string) ( $body['user_id'] ?? $body['id'] ?? '' ),
				'name'             => $body['name'] ?? '',
				'avatar_url'       => $body['avatar_url'] ?? $body['picture'] ?? '',
			),
		);
	}

	private function parse_facebook_token_response( $response, string $platform ): array {
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$error = $body['error']['message'] ?? __( 'Facebook token exchange failed.', 'ai-marketing-expert' );
			return array( 'success' => false, 'message' => $error );
		}

		// Fetch profile info.
		$profile_response = wp_remote_get( 'https://graph.facebook.com/v25.0/me?fields=id,name,picture&access_token=' . $body['access_token'], array( 'timeout' => 15 ) );
		$profile = json_decode( wp_remote_retrieve_body( $profile_response ), true );

		return array(
			'success' => true,
			'tokens'  => array(
				'access_token'  => $body['access_token'],
				'refresh_token' => $body['access_token'], // FB long-lived tokens are also refresh tokens.
				'expires_in'    => $body['expires_in'] ?? 5184000,
			),
			'profile' => array(
				'platform_user_id' => (string) ( $profile['id'] ?? '' ),
				'name'             => $profile['name'] ?? '',
				'avatar_url'       => $profile['picture']['data']['url'] ?? '',
			),
		);
	}

	private function get_callback_url(): string {
		/*
		 * This is the OAuth provider's redirect_uri: the popup lands here via a
		 * browser GET after the user authorizes. It must be the front-end bridge
		 * page (SocialMediaModule::render_oauth_bridge()), NOT the REST route —
		 * the REST `/social/accounts/callback` endpoint only answers POST and is
		 * called separately by the opener once the bridge relays the code/state.
		 *
		 * Used for both the authorize step and the token-exchange step, so the
		 * redirect_uri matches exactly (required by Facebook/Instagram).
		 */
		return add_query_arg( 'aime_social_oauth', 'callback', home_url( '/' ) );
	}

	private function get_settings(): array {
		$defaults = array(
			'oauth_mode'           => 'proxy',
			'facebook_app_id'      => '',
			'facebook_app_secret'  => '',
			'instagram_app_id'     => '',
			'instagram_app_secret' => '',
			'x_api_key'            => '',
			'x_api_secret'         => '',
			'x_access_token'       => '',
			'x_access_secret'      => '',
		);
		$settings = wp_parse_args( get_option( 'aime_social-media_settings', array() ), $defaults );

		foreach ( array( 'facebook_app_secret', 'instagram_app_secret', 'x_api_secret', 'x_access_token', 'x_access_secret' ) as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$decrypted = Encryption::decrypt( $settings[ $key ] );
				$settings[ $key ] = '' !== $decrypted ? $decrypted : $settings[ $key ];
			}
		}

		return $settings;
	}
}
