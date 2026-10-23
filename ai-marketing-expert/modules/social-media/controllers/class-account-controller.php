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

		// Auto-heal: Ensure table exists if missing
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $accounts_table ) );
		if ( ! $table_exists ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$mod = new SocialMediaModule();
			$mod->create_tables( $wpdb->get_charset_collate() );
		}

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

		$response = new \WP_REST_Response( array(
			'items' => $accounts ?: array(),
			'total' => count( $accounts ?: array() ),
		) );

		// Aggressive cache-busting headers to prevent LiteSpeed/Cloudflare/browser caching of live account list
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', '0' );
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );

		return $response;
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

		global $wpdb;
		$p   = $wpdb->prefix;
		$now = current_time( 'mysql', true );

		// ══ FACEBOOK: Discover managed Facebook Pages ══
		if ( 'facebook' === $platform ) {
			$user_token = $tokens['access_token'] ?? '';
			$pages_resp = wp_remote_get(
				'https://graph.facebook.com/v21.0/me/accounts?fields=id,name,access_token,category,picture.width(100).height(100)&access_token=' . rawurlencode( $user_token ),
				array( 'timeout' => 20 )
			);

			$pages_data = array();
			if ( ! is_wp_error( $pages_resp ) ) {
				$pages_body = json_decode( wp_remote_retrieve_body( $pages_resp ), true );
				$pages_data = $pages_body['data'] ?? array();
			}

			if ( ! empty( $pages_data ) ) {
				$candidates = array();
				foreach ( $pages_data as $page ) {
					$page_id = sanitize_text_field( $page['id'] ?? '' );
					if ( empty( $page_id ) ) {
						continue;
					}

					$existing = $wpdb->get_row( $wpdb->prepare(
						"SELECT id FROM {$p}aime_social_accounts WHERE platform = %s AND platform_user_id = %s",
						'facebook',
						$page_id
					) );

					$candidates[] = array(
						'id'                => $page_id,
						'name'              => sanitize_text_field( $page['name'] ?? 'Facebook Page' ),
						'category'          => sanitize_text_field( $page['category'] ?? '' ),
						'avatar_url'        => esc_url_raw( $page['picture']['data']['url'] ?? '' ),
						'access_token'      => $page['access_token'] ?? $user_token,
						'refresh_token'     => $tokens['refresh_token'] ?? ( $page['access_token'] ?? $user_token ),
						'user_token'        => $user_token,
						'token_expires_at'  => null,
						'already_connected' => (bool) $existing,
					);
				}

				if ( empty( $candidates ) ) {
					return new \WP_REST_Response( array(
						'message' => __( 'No valid Facebook Pages found.', 'ai-marketing-expert' ),
					), 400 );
				}

				$session_id = wp_generate_password( 32, false );
				set_transient( 'aime_oauth_pending_' . $session_id, array(
					'platform'   => 'facebook',
					'user_id'    => get_current_user_id(),
					'candidates' => $candidates,
				), 15 * MINUTE_IN_SECONDS );

				$has_pro         = aime_has_pro();
				$current_count   = SocialMediaModule::get_account_count();
				$available_slots = $has_pro ? null : max( 0, 2 - $current_count );

				$ui_accounts = array_map( function( $c ) {
					return array(
						'id'                => $c['id'],
						'name'              => $c['name'],
						'category'          => $c['category'],
						'avatar_url'        => $c['avatar_url'],
						'already_connected' => $c['already_connected'],
					);
				}, $candidates );

				return new \WP_REST_Response( array(
					'requires_selection' => true,
					'session_id'         => $session_id,
					'platform'           => 'facebook',
					'accounts'           => $ui_accounts,
					'current_count'      => $current_count,
					'available_slots'    => $available_slots,
					'has_pro'            => $has_pro,
				), 200 );
			}

			return new \WP_REST_Response( array(
				'message' => __( 'No Facebook Pages found. Make sure you are an Admin of a Facebook Page and selected it during authorization.', 'ai-marketing-expert' ),
			), 400 );
		}

		// ══ INSTAGRAM: Discover linked Instagram Business Accounts ══
		if ( 'instagram' === $platform ) {
			$user_token = $tokens['access_token'] ?? '';
			if ( empty( $user_token ) ) {
				return new \WP_REST_Response( array(
					'message' => __( 'Missing user access token from Meta.', 'ai-marketing-expert' ),
				), 400 );
			}

			// Clean, official Graph API query — only request valid fields
			$pages_resp = wp_remote_get(
				'https://graph.facebook.com/v21.0/me/accounts?fields=id,name,access_token,instagram_business_account{id,username,name,profile_picture_url}&access_token=' . rawurlencode( $user_token ),
				array( 'timeout' => 20 )
			);

			$ig_accounts    = array();
			$pages_found    = array();
			$meta_api_error = null;

			if ( is_wp_error( $pages_resp ) ) {
				$meta_api_error = $pages_resp->get_error_message();
			} else {
				$pages_body = json_decode( wp_remote_retrieve_body( $pages_resp ), true );
				if ( ! empty( $pages_body['error']['message'] ) ) {
					$meta_api_error = $pages_body['error']['message'];
				} else {
					$pages_data = $pages_body['data'] ?? array();
					foreach ( $pages_data as $page ) {
						$page_id       = sanitize_text_field( $page['id'] ?? '' );
						$page_name     = $page['name'] ?? 'Facebook Page';
						$page_token    = $page['access_token'] ?? $user_token;
						$pages_found[] = $page_name;

						$ig_biz = $page['instagram_business_account'] ?? null;

						// Fallback: If not returned in nested me/accounts, query Page node directly using Page token
						if ( empty( $ig_biz['id'] ) && ! empty( $page_id ) && ! empty( $page_token ) ) {
							$page_detail_resp = wp_remote_get(
								'https://graph.facebook.com/v21.0/' . rawurlencode( $page_id ) . '?fields=id,name,instagram_business_account{id,username,name,profile_picture_url}&access_token=' . rawurlencode( $page_token ),
								array( 'timeout' => 15 )
							);
							if ( ! is_wp_error( $page_detail_resp ) ) {
								$page_detail = json_decode( wp_remote_retrieve_body( $page_detail_resp ), true );
								if ( ! empty( $page_detail['instagram_business_account']['id'] ) ) {
									$ig_biz = $page_detail['instagram_business_account'];
								}
							}
						}

						if ( ! empty( $ig_biz['id'] ) ) {
							$ig_accounts[ (string) $ig_biz['id'] ] = array(
								'id'         => (string) $ig_biz['id'],
								'name'       => $ig_biz['name'] ?: $ig_biz['username'] ?: 'Instagram Business',
								'avatar_url' => $ig_biz['profile_picture_url'] ?? '',
								'token'      => $page_token,
							);
						}
					}
				}
			}

			// ══ ULTIMATE FALLBACK: Query debug_token granular_scopes to find Instagram accounts directly ══
			if ( empty( $ig_accounts ) ) {
				$app_id     = '1910940886538330';
				$app_secret = '43d3c8dd340e2b923b16d31ef61430cd';
				$app_token  = $app_id . '|' . $app_secret;

				$debug_resp = wp_remote_get(
					'https://graph.facebook.com/v21.0/debug_token?input_token=' . rawurlencode( $user_token ) . '&access_token=' . rawurlencode( $app_token ),
					array( 'timeout' => 15 )
				);

				if ( ! is_wp_error( $debug_resp ) ) {
					$debug_body      = json_decode( wp_remote_retrieve_body( $debug_resp ), true );
					$granular_scopes = $debug_body['data']['granular_scopes'] ?? array();

					$target_ig_ids = array();
					foreach ( $granular_scopes as $gs ) {
						if ( 'instagram_basic' === ( $gs['scope'] ?? '' ) && ! empty( $gs['target_ids'] ) ) {
							foreach ( $gs['target_ids'] as $tid ) {
								$target_ig_ids[] = (string) $tid;
							}
						}
					}

					foreach ( array_unique( $target_ig_ids ) as $ig_id ) {
						$ig_info_resp = wp_remote_get(
							'https://graph.facebook.com/v21.0/' . rawurlencode( $ig_id ) . '?fields=id,username,name,profile_picture_url&access_token=' . rawurlencode( $user_token ),
							array( 'timeout' => 15 )
						);
						if ( ! is_wp_error( $ig_info_resp ) ) {
							$ig_info = json_decode( wp_remote_retrieve_body( $ig_info_resp ), true );
							if ( ! empty( $ig_info['id'] ) ) {
								$ig_accounts[ (string) $ig_info['id'] ] = array(
									'id'         => (string) $ig_info['id'],
									'name'       => $ig_info['name'] ?: $ig_info['username'] ?: 'Instagram Business',
									'avatar_url' => $ig_info['profile_picture_url'] ?? '',
									'token'      => $user_token,
								);
							}
						}
					}
				}
			}

			$ig_accounts = array_values( $ig_accounts );

			if ( ! empty( $ig_accounts ) ) {
				$candidates = array();
				foreach ( $ig_accounts as $ig_acc ) {
					$existing = $wpdb->get_row( $wpdb->prepare(
						"SELECT id FROM {$p}aime_social_accounts WHERE platform = %s AND platform_user_id = %s",
						'instagram',
						$ig_acc['id']
					) );

					$candidates[] = array(
						'id'                => $ig_acc['id'],
						'name'              => $ig_acc['name'],
						'category'          => 'Instagram Business',
						'avatar_url'        => $ig_acc['avatar_url'],
						'access_token'      => $ig_acc['token'],
						'refresh_token'     => $tokens['refresh_token'] ?? $ig_acc['token'],
						'user_token'        => $user_token,
						'token_expires_at'  => $expires_at ?: gmdate( 'Y-m-d H:i:s', time() + 60 * DAY_IN_SECONDS ),
						'already_connected' => (bool) $existing,
					);
				}

				$session_id = wp_generate_password( 32, false );
				set_transient( 'aime_oauth_pending_' . $session_id, array(
					'platform'   => 'instagram',
					'user_id'    => get_current_user_id(),
					'candidates' => $candidates,
				), 15 * MINUTE_IN_SECONDS );

				$has_pro         = aime_has_pro();
				$current_count   = SocialMediaModule::get_account_count();
				$available_slots = $has_pro ? null : max( 0, 2 - $current_count );

				$ui_accounts = array_map( function( $c ) {
					return array(
						'id'                => $c['id'],
						'name'              => $c['name'],
						'category'          => $c['category'],
						'avatar_url'        => $c['avatar_url'],
						'already_connected' => $c['already_connected'],
					);
				}, $candidates );

				return new \WP_REST_Response( array(
					'requires_selection' => true,
					'session_id'         => $session_id,
					'platform'           => 'instagram',
					'accounts'           => $ui_accounts,
					'current_count'      => $current_count,
					'available_slots'    => $available_slots,
					'has_pro'            => $has_pro,
				), 200 );
			}

			if ( ! empty( $meta_api_error ) ) {
				return new \WP_REST_Response( array(
					'message' => sprintf( __( 'Meta API Error: %s', 'ai-marketing-expert' ), $meta_api_error ),
				), 400 );
			}

			if ( empty( $pages_found ) ) {
				return new \WP_REST_Response( array(
					'message' => __( 'No Facebook Pages found. Make sure you are an Admin of a Facebook Page and that you selected it during Facebook authorization.', 'ai-marketing-expert' ),
				), 400 );
			}

			$found_names = implode( ', ', $pages_found );
			return new \WP_REST_Response( array(
				'message' => sprintf(
					__( 'No Instagram Business account was found linked to your Facebook Page(s) ("%s"). Make sure your Instagram account is switched to Business/Creator and connected to your Page. When connecting via Facebook, click "Edit settings" in the popup to ensure both your Page and Instagram account are checked.', 'ai-marketing-expert' ),
					$found_names
				),
			), 400 );
		}

		// ══ DEFAULT / OTHER PLATFORMS (LinkedIn, X, direct user profile) ══
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$p}aime_social_accounts WHERE platform = %s AND platform_user_id = %s",
			$platform,
			$profile['platform_user_id'] ?? ''
		) );

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
			'updated_at'       => $now,
		);

		if ( $existing ) {
			$wpdb->update( "{$p}aime_social_accounts", $data, array( 'id' => $existing->id ) );
			$account_id = $existing->id;
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( "{$p}aime_social_accounts", $data );
			$account_id = $wpdb->insert_id;
		}

		if ( ! $account_id ) {
			return new \WP_REST_Response( array( 'message' => __( 'Failed to save account.', 'ai-marketing-expert' ) ), 500 );
		}

		aime_log( sprintf( 'Social account connected: %s (%s)', $data['name'], $platform ), 'info', 'social-media' );

		return new \WP_REST_Response( array(
			'id'      => (int) $account_id,
			'message' => __( 'Account connected successfully.', 'ai-marketing-expert' ),
		), 200 );
	}

	/**
	 * Save user-selected accounts from OAuth candidates list.
	 */
	public function save_selected( \WP_REST_Request $request ): \WP_REST_Response {
		$session_id   = sanitize_text_field( $request->get_param( 'session_id' ) );
		$selected_ids = (array) $request->get_param( 'selected_ids' );

		if ( empty( $session_id ) || empty( $selected_ids ) ) {
			return new \WP_REST_Response( array(
				'message' => __( 'No accounts selected.', 'ai-marketing-expert' ),
			), 400 );
		}

		$data = get_transient( 'aime_oauth_pending_' . $session_id );
		if ( ! $data || empty( $data['candidates'] ) ) {
			return new \WP_REST_Response( array(
				'message' => __( 'Session expired or invalid. Please try connecting again.', 'ai-marketing-expert' ),
			), 400 );
		}

		$platform   = $data['platform'];
		$candidates = $data['candidates'];
		$has_pro    = aime_has_pro();

		$candidates_by_id = array();
		foreach ( $candidates as $c ) {
			$candidates_by_id[ (string) $c['id'] ] = $c;
		}

		$to_save            = array();
		$new_accounts_count = 0;
		foreach ( $selected_ids as $id ) {
			$str_id = (string) $id;
			if ( isset( $candidates_by_id[ $str_id ] ) ) {
				$candidate = $candidates_by_id[ $str_id ];
				$to_save[] = $candidate;
				if ( empty( $candidate['already_connected'] ) ) {
					$new_accounts_count++;
				}
			}
		}

		if ( empty( $to_save ) ) {
			return new \WP_REST_Response( array(
				'message' => __( 'None of the selected accounts could be found.', 'ai-marketing-expert' ),
			), 400 );
		}

		// Enforce Free Limit for new accounts
		if ( ! $has_pro ) {
			$current_count = SocialMediaModule::get_account_count();
			if ( ( $current_count + $new_accounts_count ) > 2 ) {
				return new \WP_REST_Response( array(
					'message'       => __( 'Account limit reached. Free plan includes 2 social accounts. Upgrade to Pro for unlimited accounts.', 'ai-marketing-expert' ),
					'limit_reached' => true,
				), 403 );
			}
		}

		global $wpdb;
		$p   = $wpdb->prefix;
		$accounts_table = $p . 'aime_social_accounts';

		// Auto-heal: Ensure table exists before inserting
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $accounts_table ) );
		if ( ! $table_exists ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$mod = new SocialMediaModule();
			$mod->create_tables( $wpdb->get_charset_collate() );
		}

		$now = current_time( 'mysql', true );
		$saved_names = array();

		foreach ( $to_save as $acc ) {
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT id FROM {$accounts_table} WHERE platform = %s AND platform_user_id = %s",
				$platform,
				$acc['id']
			) );

			$meta = array( 'connection_type' => 'oauth' );
			if ( ! empty( $acc['user_token'] ) ) {
				$meta['user_token'] = Encryption::encrypt( $acc['user_token'] );
			}

			$account_data = array(
				'platform'         => $platform,
				'platform_user_id' => $acc['id'],
				'name'             => $acc['name'],
				'avatar_url'       => $acc['avatar_url'] ?? '',
				'access_token'     => Encryption::encrypt( $acc['access_token'] ),
				'refresh_token'    => Encryption::encrypt( $acc['refresh_token'] ),
				'token_expires_at' => $acc['token_expires_at'] ?? null,
				'meta'             => wp_json_encode( $meta ),
				'status'           => 'connected',
				'updated_at'       => $now,
			);

			if ( $existing ) {
				$res = $wpdb->update( $accounts_table, $account_data, array( 'id' => $existing->id ) );
			} else {
				$account_data['created_at'] = $now;
				$res = $wpdb->insert( $accounts_table, $account_data );
			}

			if ( false === $res ) {
				aime_log( sprintf( 'Database error saving %s account %s: %s', $platform, $acc['name'], $wpdb->last_error ), 'error', 'social-media' );
				return new \WP_REST_Response( array(
					'message' => sprintf( __( 'Database error saving %s: %s', 'ai-marketing-expert' ), $acc['name'], $wpdb->last_error ),
				), 500 );
			}

			$saved_names[] = $acc['name'];
			aime_log( sprintf( '%s account connected: %s (ID: %s)', ucfirst( $platform ), $acc['name'], $acc['id'] ), 'info', 'social-media' );
		}

		// Invalidate object cache & LiteSpeed cache
		wp_cache_flush();
		if ( has_action( 'litespeed_purge' ) ) {
			do_action( 'litespeed_purge' );
		}
		if ( class_exists( '\LiteSpeed\Purge' ) && method_exists( '\LiteSpeed\Purge', 'purge_all' ) ) {
			\LiteSpeed\Purge::purge_all();
		}

		// Clean up transient
		delete_transient( 'aime_oauth_pending_' . $session_id );

		$count = count( $saved_names );
		$msg   = 1 === $count
			? sprintf( __( '"%s" connected successfully!', 'ai-marketing-expert' ), $saved_names[0] )
			: sprintf( __( '%d accounts connected: %s', 'ai-marketing-expert' ), $count, implode( ', ', $saved_names ) );

		$response = new \WP_REST_Response( array(
			'message'   => $msg,
			'connected' => $saved_names,
		), 200 );

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', '0' );
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );

		return $response;
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

		wp_cache_flush();
		if ( has_action( 'litespeed_purge' ) ) {
			do_action( 'litespeed_purge' );
		}
		if ( class_exists( '\LiteSpeed\Purge' ) && method_exists( '\LiteSpeed\Purge', 'purge_all' ) ) {
			\LiteSpeed\Purge::purge_all();
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
			"SELECT * FROM {$accounts_table} WHERE id = %d",
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

		// Invalidate object cache & LiteSpeed cache
		wp_cache_flush();
		if ( has_action( 'litespeed_purge' ) ) {
			do_action( 'litespeed_purge' );
		}
		if ( class_exists( '\LiteSpeed\Purge' ) && method_exists( '\LiteSpeed\Purge', 'purge_all' ) ) {
			\LiteSpeed\Purge::purge_all();
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
			"SELECT id, name, platform FROM {$accounts_table} WHERE id = %d",
			$id
		) );

		if ( ! $account ) {
			return new \WP_REST_Response( array( 'message' => __( 'Account not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$wpdb->delete( $accounts_table, array( 'id' => $id ) );

		// Clean up related posts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off admin cleanup query during disconnect.
		$wpdb->query( $wpdb->prepare(
			"DELETE l FROM {$logs_table} l
			 INNER JOIN {$posts_table} p ON l.post_id = p.id
			 WHERE p.account_id = %d",
			$id
		) );
		$wpdb->delete( $posts_table, array( 'account_id' => $id ) );

		// Invalidate object cache & LiteSpeed cache
		wp_cache_flush();
		if ( has_action( 'litespeed_purge' ) ) {
			do_action( 'litespeed_purge' );
		}
		if ( class_exists( '\LiteSpeed\Purge' ) && method_exists( '\LiteSpeed\Purge', 'purge_all' ) ) {
			\LiteSpeed\Purge::purge_all();
		}

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
