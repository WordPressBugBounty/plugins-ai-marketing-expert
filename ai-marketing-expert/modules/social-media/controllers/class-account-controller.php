<?php
/**
 * Account Controller — manage connected social accounts.
 *
 * @package WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers;

use WPSpace\AiMarketingExpert\Encryption;
use WPSpace\AiMarketingExpert\Modules\SocialMedia\Services\OAuthService;
use WPSpace\AiMarketingExpert\Modules\SocialMedia\Services\PlatformApiService;
use WPSpace\AiMarketingExpert\Modules\SocialMedia\SocialMediaModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AccountController {

	/**
	 * List all connected social accounts.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;
		$accounts_table = $p . 'aime_social_accounts';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin listing is a fresh read and intentionally not cached.
		$accounts = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, platform, platform_user_id, name, avatar_url, status, token_expires_at, meta, created_at, updated_at
				 FROM %i
				 ORDER BY created_at DESC',
				$accounts_table
			)
		);

		foreach ( $accounts as &$account ) {
			$account->meta            = json_decode( $account->meta ?: '{}', true );
			if ( ! is_array( $account->meta ) ) {
				$account->meta = array();
			}
			if ( is_array( $account->meta ) ) {
				foreach ( array( 'api_key', 'api_secret' ) as $key ) {
					if ( ! empty( $account->meta[ $key ] ) ) {
						$account->meta[ $key . '_set' ] = true;
						unset( $account->meta[ $key ] );
					}
				}
			}
			$account->token_expired   = $account->token_expires_at && strtotime( $account->token_expires_at ) < time();
			$account->connection_type = $account->meta['connection_type'] ?? $this->infer_connection_type( $account );
			$account->can_refresh     = 'oauth' === $account->connection_type;
			$account->last_tested_at  = $account->meta['last_tested_at'] ?? '';
			$account->last_test_valid = array_key_exists( 'last_test_valid', $account->meta ) ? (bool) $account->meta['last_test_valid'] : null;
			$account->last_test_message = $account->meta['last_test_message'] ?? '';
		}

		return new \WP_REST_Response( array(
			'items' => $accounts ?: array(),
			'total' => count( $accounts ?: array() ),
		) );
	}

	/**
	 * Initiate OAuth connection — returns OAuth URL.
	 */
	public function connect( \WP_REST_Request $request ): \WP_REST_Response {
		// Free limit check.
		if ( ! aime_has_pro() ) {
			$count = SocialMediaModule::get_account_count();
			if ( aime_limit_reached( 'social_accounts', $count ) ) {
				return new \WP_REST_Response( array(
					'message'       => __( 'Account limit reached. Upgrade to Pro for unlimited accounts.', 'ai-marketing-expert' ),
					'limit_reached' => true,
				), 403 );
			}
		}

		$platform = sanitize_text_field( $request->get_param( 'platform' ) );

		$oauth  = new OAuthService();
		$result = $oauth->get_auth_url( $platform );

		if ( empty( $result['success'] ) ) {
			return new \WP_REST_Response( array( 'message' => $result['message'] ?? __( 'Failed to generate OAuth URL.', 'ai-marketing-expert' ) ), 400 );
		}

		return new \WP_REST_Response( array(
			'url'      => $result['url'],
			'platform' => $platform,
		) );
	}

	/**
	 * Handle OAuth callback with authorization code.
	 */
	public function callback( \WP_REST_Request $request ): \WP_REST_Response {
		$platform = sanitize_text_field( $request->get_param( 'platform' ) );
		$code     = sanitize_text_field( $request->get_param( 'code' ) );
		$state    = sanitize_text_field( $request->get_param( 'state' ) );

		$oauth  = new OAuthService();
		$result = $oauth->handle_callback( $platform, $code, $state );

		if ( empty( $result['success'] ) ) {
			return new \WP_REST_Response( array( 'message' => $result['message'] ?? __( 'OAuth callback failed.', 'ai-marketing-expert' ) ), 400 );
		}

		$tokens  = $result['tokens'] ?? array();
		$profile = $result['profile'] ?? array();

		// Calculate token expiration datetime.
		$expires_at = null;
		if ( ! empty( $tokens['expires_in'] ) ) {
			$expires_at = gmdate( 'Y-m-d H:i:s', time() + (int) $tokens['expires_in'] );
		}

		// Store the account.
		global $wpdb;
		$p   = $wpdb->prefix;
		$now = current_time( 'mysql', true );

		$data = array(
			'platform'         => $platform,
			'platform_user_id' => sanitize_text_field( $profile['platform_user_id'] ?? '' ),
			'name'             => sanitize_text_field( $profile['name'] ?? ucfirst( $platform ) ),
			'avatar_url'       => esc_url_raw( $profile['avatar_url'] ?? '' ),
			'access_token'     => Encryption::encrypt( $tokens['access_token'] ?? '' ),
			'refresh_token'    => Encryption::encrypt( $tokens['refresh_token'] ?? '' ),
			'token_expires_at' => $expires_at,
			'meta'             => wp_json_encode( array( 'connection_type' => 'oauth' ) ),
			'status'           => 'connected',
			'created_at'       => $now,
			'updated_at'       => $now,
		);

		$wpdb->insert( "{$p}aime_social_accounts", $data );

		if ( ! $wpdb->insert_id ) {
			return new \WP_REST_Response( array( 'message' => __( 'Failed to save account.', 'ai-marketing-expert' ) ), 500 );
		}

		aime_log( sprintf( 'Social account connected: %s (%s)', $data['name'], $platform ), 'info', 'social-media' );

		return new \WP_REST_Response( array(
			'id'      => (int) $wpdb->insert_id,
			'message' => __( 'Account connected successfully.', 'ai-marketing-expert' ),
		), 201 );
	}

	/**
	 * Connect account manually with access token.
	 */
	public function connect_manual( \WP_REST_Request $request ): \WP_REST_Response {
		// Free limit check.
		if ( ! aime_has_pro() ) {
			$count = SocialMediaModule::get_account_count();
			if ( aime_limit_reached( 'social_accounts', $count ) ) {
				return new \WP_REST_Response( array(
					'message'       => __( 'Account limit reached. Upgrade to Pro for unlimited accounts.', 'ai-marketing-expert' ),
					'limit_reached' => true,
				), 403 );
			}
		}

		$platform         = sanitize_text_field( $request->get_param( 'platform' ) );
		$api_key          = sanitize_text_field( $request->get_param( 'api_key' ) ?: '' );
		$api_secret       = sanitize_text_field( $request->get_param( 'api_secret' ) ?: '' );
		$access_token     = sanitize_text_field( $request->get_param( 'access_token' ) );
		$access_secret    = sanitize_text_field( $request->get_param( 'access_secret' ) ?: '' );
		$name             = sanitize_text_field( $request->get_param( 'name' ) ) ?: ucfirst( $platform ) . ' Account';
		$provided_user_id = sanitize_text_field( $request->get_param( 'platform_user_id' ) ?: '' );

		if ( 'x' === $platform && ( empty( $api_key ) || empty( $api_secret ) || empty( $access_secret ) ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'X API key, API secret, access token, and access token secret are required.', 'ai-marketing-expert' ) ), 400 );
		}

		// Validate the token by fetching user info.
		$api = new PlatformApiService();

		if ( 'x' === $platform ) {
			$temp_account = (object) array(
				'platform'      => 'x',
				'access_token'  => Encryption::encrypt( $access_token ),
				'refresh_token' => Encryption::encrypt( $access_secret ),
				'meta'          => wp_json_encode( array(
					'api_key'    => Encryption::encrypt( $api_key ),
					'api_secret' => Encryption::encrypt( $api_secret ),
				) ),
			);
			$validation = $api->validate_token( $temp_account );
			$result     = ! empty( $validation['valid'] )
				? array( 'success' => true, 'name' => $name )
				: array( 'success' => false, 'message' => $validation['message'] ?? __( 'Invalid X credentials.', 'ai-marketing-expert' ) );
		} elseif ( 'facebook' === $platform ) {
			$result = $api->resolve_facebook_page_connection( $access_token, $provided_user_id );
			if ( ! empty( $result['success'] ) ) {
				if ( ! empty( $result['access_token'] ) ) {
					$access_token = $result['access_token'];
				}
				if ( empty( $result['name'] ) ) {
					$result['name'] = $name;
				}
			}
		} elseif ( 'instagram' === $platform && ! empty( $provided_user_id ) ) {
			// User supplied the Instagram Business Account ID directly — skip auto-discovery.
			$result = array( 'success' => true, 'platform_user_id' => $provided_user_id, 'name' => $name );
		} else {
			$result = $api->get_profile( $platform, $access_token );
		}

		if ( empty( $result['success'] ) ) {
			return new \WP_REST_Response( array( 'message' => $result['message'] ?? __( 'Invalid access token.', 'ai-marketing-expert' ) ), 400 );
		}

		global $wpdb;
		$p   = $wpdb->prefix;
		$now = current_time( 'mysql', true );

		$meta = array();
		$meta['connection_type'] = 'manual';
		if ( 'x' === $platform ) {
			$meta['api_key']    = Encryption::encrypt( $api_key );
			$meta['api_secret'] = Encryption::encrypt( $api_secret );
		}

		$data = array(
			'platform'         => $platform,
			'platform_user_id' => sanitize_text_field( $result['platform_user_id'] ?? '' ),
			'name'             => sanitize_text_field( $result['name'] ?? $name ),
			'avatar_url'       => esc_url_raw( $result['avatar_url'] ?? '' ),
			'access_token'     => Encryption::encrypt( $access_token ),
			'refresh_token'    => $access_secret ? Encryption::encrypt( $access_secret ) : '',
			'token_expires_at' => null,
			'meta'             => wp_json_encode( $meta ),
			'status'           => 'connected',
			'created_at'       => $now,
			'updated_at'       => $now,
		);

		$wpdb->insert( "{$p}aime_social_accounts", $data );

		if ( ! $wpdb->insert_id ) {
			return new \WP_REST_Response( array( 'message' => __( 'Failed to save account.', 'ai-marketing-expert' ) ), 500 );
		}

		aime_log( sprintf( 'Social account connected manually: %s (%s)', $data['name'], $platform ), 'info', 'social-media' );

		return new \WP_REST_Response( array(
			'id'      => (int) $wpdb->insert_id,
			'message' => __( 'Account connected successfully.', 'ai-marketing-expert' ),
		), 201 );
	}

	/**
	 * Update a connected account.
	 */
	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$accounts_table = $p . 'aime_social_accounts';
		$id = absint( $request->get_param( 'id' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off admin edit lookup.
		$account = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			$accounts_table,
			$id
		) );

		if ( ! $account ) {
			return new \WP_REST_Response( array( 'message' => __( 'Account not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$name             = sanitize_text_field( $request->get_param( 'name' ) ?: $account->name );
		$provided_token   = sanitize_text_field( $request->get_param( 'access_token' ) ?: '' );
		$provided_secret  = sanitize_text_field( $request->get_param( 'access_secret' ) ?: '' );
		$provided_api_key = sanitize_text_field( $request->get_param( 'api_key' ) ?: '' );
		$provided_api_sec = sanitize_text_field( $request->get_param( 'api_secret' ) ?: '' );
		$provided_user_id = sanitize_text_field( $request->get_param( 'platform_user_id' ) ?: '' );
		$meta             = json_decode( $account->meta ?: '{}', true );

		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$connection_type = $meta['connection_type'] ?? $this->infer_connection_type( $account );
		$data            = array(
			'name'       => $name,
			'updated_at' => current_time( 'mysql', true ),
		);

		if ( 'manual' === $connection_type ) {
			$result = $this->build_manual_account_update( $account, $request, $meta );
			if ( empty( $result['success'] ) ) {
				return new \WP_REST_Response( array( 'message' => $result['message'] ?? __( 'Failed to update account.', 'ai-marketing-expert' ) ), 400 );
			}

			if ( ! empty( $result['data'] ) && is_array( $result['data'] ) ) {
				$data = array_merge( $data, $result['data'] );
			}
		} else {
			if ( $provided_user_id ) {
				$data['platform_user_id'] = $provided_user_id;
			}
		}

		$updated = $wpdb->update( "{$p}aime_social_accounts", $data, array( 'id' => $id ) );
		if ( false === $updated ) {
			return new \WP_REST_Response( array( 'message' => __( 'Failed to update account.', 'ai-marketing-expert' ) ), 500 );
		}

		aime_log( sprintf( 'Social account updated: %s (%s)', $name, $account->platform ), 'info', 'social-media' );

		return new \WP_REST_Response( array( 'message' => __( 'Account updated successfully.', 'ai-marketing-expert' ) ) );
	}

	/**
	 * Disconnect / remove an account.
	 */
	public function disconnect( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$accounts_table = $p . 'aime_social_accounts';
		$posts_table    = $p . 'aime_social_posts';
		$logs_table     = $p . 'aime_social_post_log';
		$id = absint( $request->get_param( 'id' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off admin disconnect lookup.
		$account = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, name, platform FROM %i WHERE id = %d',
			$accounts_table,
			$id
		) );

		if ( ! $account ) {
			return new \WP_REST_Response( array( 'message' => __( 'Account not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$wpdb->delete( $accounts_table, array( 'id' => $id ) );

		// Clean up related posts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off admin cleanup query during disconnect.
		$wpdb->query( $wpdb->prepare(
			'DELETE l FROM %i l
			 INNER JOIN %i p ON l.post_id = p.id
			 WHERE p.account_id = %d',
			$logs_table,
			$posts_table,
			$id
		) );
		$wpdb->delete( $posts_table, array( 'account_id' => $id ) );

		aime_log( sprintf( 'Social account disconnected: %s (%s)', $account->name, $account->platform ), 'info', 'social-media' );

		return new \WP_REST_Response( array( 'message' => __( 'Account disconnected.', 'ai-marketing-expert' ) ) );
	}

	/**
	 * Refresh an expired token.
	 */
	public function refresh( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$accounts_table = $p . 'aime_social_accounts';
		$id = absint( $request->get_param( 'id' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off admin refresh lookup.
		$account = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			$accounts_table,
			$id
		) );

		if ( ! $account ) {
			return new \WP_REST_Response( array( 'message' => __( 'Account not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$meta = json_decode( $account->meta ?: '{}', true );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		$connection_type = $meta['connection_type'] ?? $this->infer_connection_type( $account );

		if ( 'oauth' !== $connection_type ) {
			return new \WP_REST_Response( array( 'message' => __( 'This account was connected manually. Refresh is not available; reconnect the account with a new token when needed.', 'ai-marketing-expert' ) ), 400 );
		}

		$oauth  = new OAuthService();
		$result = $oauth->refresh_token( $account->platform, $account->refresh_token );

		if ( empty( $result['success'] ) ) {
			$wpdb->update( $accounts_table, array(
				'status'     => 'expired',
				'updated_at' => current_time( 'mysql', true ),
			), array( 'id' => $id ) );

			return new \WP_REST_Response( array( 'message' => $result['message'] ?? __( 'Token refresh failed.', 'ai-marketing-expert' ) ), 400 );
		}

		$tokens     = $result['tokens'] ?? array();
		$expires_at = null;
		if ( ! empty( $tokens['expires_in'] ) ) {
			$expires_at = gmdate( 'Y-m-d H:i:s', time() + (int) $tokens['expires_in'] );
		}

		$wpdb->update( $accounts_table, array(
			'access_token'     => Encryption::encrypt( $tokens['access_token'] ?? '' ),
			'refresh_token'    => ! empty( $tokens['refresh_token'] ) ? Encryption::encrypt( $tokens['refresh_token'] ) : $account->refresh_token,
			'token_expires_at' => $expires_at,
			'status'           => 'connected',
			'updated_at'       => current_time( 'mysql', true ),
		), array( 'id' => $id ) );

		return new \WP_REST_Response( array( 'message' => __( 'Token refreshed successfully.', 'ai-marketing-expert' ) ) );
	}

	/**
	 * Test whether a connected account can still be reached by the platform API.
	 */
	public function test( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$accounts_table = $p . 'aime_social_accounts';
		$id = absint( $request->get_param( 'id' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off admin test lookup.
		$account = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			$accounts_table,
			$id
		) );

		if ( ! $account ) {
			return new \WP_REST_Response( array( 'message' => __( 'Account not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$api        = new PlatformApiService();
		$validation = $api->validate_token( $account );
		$is_valid   = ! empty( $validation['valid'] );
		$message    = $validation['message'] ?? ( $is_valid ? __( 'Account connection verified.', 'ai-marketing-expert' ) : __( 'Account connection test failed.', 'ai-marketing-expert' ) );
		$meta       = json_decode( $account->meta ?: '{}', true );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		$meta['last_tested_at']    = current_time( 'mysql', true );
		$meta['last_test_valid']   = $is_valid;
		$meta['last_test_message'] = sanitize_text_field( $message );

		$wpdb->update( $accounts_table, array(
			'status'     => $is_valid ? 'connected' : 'expired',
			'meta'       => wp_json_encode( $meta ),
			'updated_at' => current_time( 'mysql', true ),
		), array( 'id' => $id ) );

		return new \WP_REST_Response( array(
			'valid'   => $is_valid,
			'message' => $message,
		), $is_valid ? 200 : 400 );
	}

	private function infer_connection_type( object $account ): string {
		if ( ! empty( $account->token_expires_at ) ) {
			return 'oauth';
		}

		$meta = json_decode( $account->meta ?: '{}', true );
		if ( is_array( $meta ) && ( ! empty( $meta['api_key'] ) || ! empty( $meta['api_key_set'] ) ) ) {
			return 'manual';
		}

		if ( 'x' === ( $account->platform ?? '' ) ) {
			return 'manual';
		}

		return empty( $account->refresh_token ) ? 'manual' : 'oauth';
	}

	private function build_manual_account_update( object $account, \WP_REST_Request $request, array $meta ): array {
		$platform         = $account->platform;
		$provided_token   = sanitize_text_field( $request->get_param( 'access_token' ) ?: '' );
		$provided_secret  = sanitize_text_field( $request->get_param( 'access_secret' ) ?: '' );
		$provided_api_key = sanitize_text_field( $request->get_param( 'api_key' ) ?: '' );
		$provided_api_sec = sanitize_text_field( $request->get_param( 'api_secret' ) ?: '' );
		$provided_user_id = sanitize_text_field( $request->get_param( 'platform_user_id' ) ?: '' );

		$current_token      = Encryption::decrypt( $account->access_token );
		$current_secret     = Encryption::decrypt( $account->refresh_token );
		$current_api_key    = ! empty( $meta['api_key'] ) ? Encryption::decrypt( $meta['api_key'] ) : '';
		$current_api_secret = ! empty( $meta['api_secret'] ) ? Encryption::decrypt( $meta['api_secret'] ) : '';

		$access_token  = $provided_token ?: $current_token;
		$access_secret = '' !== $provided_secret ? $provided_secret : $current_secret;
		$api_key       = '' !== $provided_api_key ? $provided_api_key : $current_api_key;
		$api_secret    = '' !== $provided_api_sec ? $provided_api_sec : $current_api_secret;

		$profile_data = array();
		$api          = new PlatformApiService();
		$meta['connection_type'] = 'manual';

		if ( 'facebook' === $platform && ( $provided_token || $provided_user_id ) ) {
			$profile_data = $api->resolve_facebook_page_connection( $access_token, $provided_user_id ?: $account->platform_user_id );
			if ( empty( $profile_data['success'] ) ) {
				return $profile_data;
			}
			$access_token = $profile_data['access_token'] ?? $access_token;
		}

		if ( 'instagram' === $platform && $provided_token ) {
			if ( $provided_user_id ) {
				$profile_data = array( 'success' => true, 'platform_user_id' => $provided_user_id );
			} else {
				$profile_data = $api->get_profile( 'instagram', $access_token );
			}
			if ( empty( $profile_data['success'] ) ) {
				return $profile_data;
			}
		}

		if ( 'x' === $platform ) {
			$temp_account = (object) array(
				'platform'      => 'x',
				'access_token'  => Encryption::encrypt( $access_token ),
				'refresh_token' => Encryption::encrypt( $access_secret ),
				'meta'          => wp_json_encode( array(
					'api_key'    => Encryption::encrypt( $api_key ),
					'api_secret' => Encryption::encrypt( $api_secret ),
				) ),
			);
			$validation = $api->validate_token( $temp_account );
			if ( ( $provided_token || $provided_secret || $provided_api_key || $provided_api_sec ) && empty( $validation['valid'] ) ) {
				return array( 'success' => false, 'message' => $validation['message'] ?? __( 'Invalid X credentials.', 'ai-marketing-expert' ) );
			}
			$meta['api_key']    = Encryption::encrypt( $api_key );
			$meta['api_secret'] = Encryption::encrypt( $api_secret );
		}

		$data = array(
			'meta' => wp_json_encode( $meta ),
		);

		if ( $provided_token ) {
			$data['access_token'] = Encryption::encrypt( $access_token );
		}
		if ( 'x' === $platform && '' !== $provided_secret ) {
			$data['refresh_token'] = Encryption::encrypt( $access_secret );
		}
		if ( 'facebook' === $platform && ! empty( $profile_data['platform_user_id'] ) ) {
			$data['platform_user_id'] = sanitize_text_field( $profile_data['platform_user_id'] );
			$data['avatar_url']       = esc_url_raw( $profile_data['avatar_url'] ?? $account->avatar_url );
		}
		if ( 'instagram' === $platform && $provided_user_id ) {
			$data['platform_user_id'] = sanitize_text_field( $provided_user_id );
		}

		return array( 'success' => true, 'data' => $data );
	}
}
