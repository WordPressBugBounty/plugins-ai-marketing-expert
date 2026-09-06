<?php
/**
 * AI Provider Manager.
 *
 * Handles multiple AI provider connections (Google AI Studio, OpenAI, OpenRouter, Claude),
 * model selection, fallback logic, and API key management.
 *
 * Connection-based repeater pattern (like SMTP connections).

// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AiProvider {

	/**
	 * Option key for AI connections.
	 */
	private const OPTION_KEY = 'aime_ai_connections';
	private const HEALTH_OPTION_KEY = 'aime_ai_provider_health';
	private const TEMPORARY_COOLDOWN = 300;
	private const RATE_LIMIT_COOLDOWN = 3600;
	private const QUOTA_COOLDOWN = 86400;

	/**
	 * Available provider presets (definitions only — not user data).
	 *
	 * Model lists are intentionally empty: models change monthly, so the UI
	 * loads the live list from each provider's API (fetch-models endpoint,
	 * cached 6h) instead of shipping hardcoded lists that rot between
	 * releases.
	 *
	 * @return array
	 */
	public static function get_providers(): array {
		$no_models = array( 'text' => array(), 'image' => array() );
		return array(
			'google_ai'  => array(
				'id'          => 'google_ai',
				'name'        => __( 'Google AI Studio', 'ai-marketing-expert' ),
				'description' => __( 'Google Gemini models for text, image, and multimodal tasks.', 'ai-marketing-expert' ),
				'api_url'     => 'https://generativelanguage.googleapis.com/v1beta',
				'docs_url'    => 'https://ai.google.dev/',
				'models'      => $no_models,
			),
			'openai'     => array(
				'id'          => 'openai',
				'name'        => __( 'OpenAI (ChatGPT)', 'ai-marketing-expert' ),
				'description' => __( 'GPT text and image models from OpenAI.', 'ai-marketing-expert' ),
				'api_url'     => 'https://api.openai.com/v1',
				'docs_url'    => 'https://platform.openai.com/docs',
				'models'      => $no_models,
			),
			'openrouter' => array(
				'id'          => 'openrouter',
				'name'        => __( 'OpenRouter', 'ai-marketing-expert' ),
				'description' => __( 'Access multiple AI models through one API — Claude, GPT, Llama, and more.', 'ai-marketing-expert' ),
				'api_url'     => 'https://openrouter.ai/api/v1',
				'docs_url'    => 'https://openrouter.ai/docs',
				'models'      => $no_models,
			),
			'anthropic'  => array(
				'id'          => 'anthropic',
				'name'        => __( 'Anthropic (Claude)', 'ai-marketing-expert' ),
				'description' => __( 'Claude models for advanced reasoning, writing, and analysis.', 'ai-marketing-expert' ),
				'api_url'     => 'https://api.anthropic.com/v1',
				'docs_url'    => 'https://docs.anthropic.com/',
				'models'      => $no_models,
			),
			'custom'     => array(
				'id'          => 'custom',
				'name'        => __( 'Custom Provider', 'ai-marketing-expert' ),
				'description' => __( 'Connect any OpenAI-compatible or Anthropic-compatible API endpoint.', 'ai-marketing-expert' ),
				'api_url'     => '',
				'docs_url'    => '',
				'is_custom'   => true,
				'models'      => $no_models,
			),
		);
	}

	/**
	 * Decrypt a value that may be plaintext (migration safety for base_url).
	 *
	 * @param string $value Possibly encrypted value.
	 * @return string Decrypted or original plaintext.
	 */
	public static function decrypt_maybe( string $value ): string {
		if ( '' === $value || empty( trim( $value ) ) ) {
			return '';
		}
		// Only attempt decryption on prefixed ciphertexts.
		if ( 0 === strpos( $value, 'v3:' ) || 0 === strpos( $value, 'v2:' ) ) {
			$decrypted = Encryption::decrypt( $value );
			return '' !== $decrypted ? $decrypted : $value;
		}
		// Not encrypted — return as-is (plaintext from before migration).
		return $value;
	}

	/**
	 * Timeout (seconds) for fetching generated images from provider URLs.
	 *
	 * Image generation is slow; allow plenty of headroom but keep it filterable.
	 *
	 * @return int
	 */
	public static function image_fetch_timeout(): int {
		$timeout = (int) apply_filters( 'aime_image_fetch_timeout', 120 );

		return $timeout > 0 ? $timeout : 120;
	}

	/**
	 * Check if a model has a specific capability.
	 *
	 * @param string $provider_id Provider ID.
	 * @param string $model_id    Model ID.
	 * @param string $capability  Capability to check ('text' or 'image').
	 * @return bool
	 */
	public static function model_has_capability( string $provider_id, string $model_id, string $capability ): bool {
		$providers = self::get_providers();
		$provider  = $providers[ $provider_id ] ?? null;
		if ( ! $provider ) {
			return false;
		}

		// Search across both text and image model lists.
		foreach ( array( 'text', 'image' ) as $type ) {
			$models = $provider['models'][ $type ] ?? array();
			foreach ( $models as $m ) {
				if ( $m['id'] === $model_id ) {
					return in_array( $capability, $m['capabilities'] ?? array(), true );
				}
			}
		}

		// Model lists are no longer shipped hardcoded, so capability metadata
		// is unknown here — assume capable and let the provider API reject
		// unsupported requests.
		return true;
	}

	/**
	 * Normalize primary_for to a valid array.
	 *
	 * @param mixed $value Raw input value.
	 * @return array
	 */
	private static function normalize_primary_for( $value ): array {
		if ( is_array( $value ) ) {
			return array_values( array_intersect( $value, array( 'text', 'image' ) ) );
		}
		return array();
	}

	/* ================================================================
	 *  CONNECTIONS CRUD
	 * ============================================================= */

	/**
	 * Get all AI connections.
	 *
	 * @return array
	 */
	public static function get_connections(): array {
		$connections = \aime_get_db_option( self::OPTION_KEY, array() );
		$connections = is_array( $connections ) ? $connections : array();

		// Migration: convert old fixed-provider settings into connections.
		if ( empty( $connections ) ) {
			$connections = self::maybe_migrate_old();
		}

		// Migration: convert is_primary (bool) → primary_for (array).
		$needs_save = false;
		foreach ( $connections as &$c ) {
			if ( ! isset( $c['primary_for'] ) ) {
				$c['primary_for'] = ! empty( $c['is_primary'] ) ? array( 'text', 'image' ) : array();
				unset( $c['is_primary'] );
				$needs_save = true;
			}
		}
		unset( $c );

		if ( $needs_save && ! empty( $connections ) ) {
			// Ensure every task type has at least one primary.
			foreach ( array( 'text', 'image' ) as $task ) {
				$has = false;
				foreach ( $connections as $c ) {
					if ( in_array( $task, $c['primary_for'] ?? array(), true ) ) {
						$has = true;
						break;
					}
				}
				if ( ! $has ) {
					$connections[0]['primary_for'] = array_unique( array_merge( $connections[0]['primary_for'] ?? array(), array( $task ) ) );
					$connections[0]['primary_for'] = array_values( $connections[0]['primary_for'] );
				}
			}
			update_option( self::OPTION_KEY, $connections, false );
			\aime_clear_settings_cache( array( self::OPTION_KEY ) );
		}

		return $connections;
	}

	/**
	 * Get connections for API response (masks API keys).
	 *
	 * @return array
	 */
	public static function get_connections_for_api(): array {
		return array_map( function ( $c ) {
			$health = self::get_connection_health( $c['id'] ?? '' );
			$c['has_key'] = ! empty( $c['api_key'] );
			$c['key_decrypt_failed'] = false;
			if ( ! empty( $c['api_key'] ) ) {
				$decrypted = Encryption::decrypt( $c['api_key'] );
				if ( ! empty( $decrypted ) ) {
					$c['api_key'] = '••••••••' . substr( $decrypted, -4 );
				} else {
					// Stored key exists but no longer decrypts (AUTH_KEY changed?).
					$c['api_key']            = '';
					$c['key_decrypt_failed'] = true;
				}
			}
			// Decrypt base_url for display (custom providers need to see/edit the endpoint).
			if ( ! empty( $c['base_url'] ) ) {
				$c['base_url'] = self::decrypt_maybe( $c['base_url'] );
			}
			// Ensure primary_for is always present.
			$c['primary_for'] = $c['primary_for'] ?? array();
			// Legacy compat: is_primary = true if primary for any task.
			$c['is_primary'] = ! empty( $c['primary_for'] );
			$c['health_status'] = $health['status'];
			$c['health_reason'] = $health['reason'];
			$c['retry_after'] = $health['retry_after'];
			$c['last_error'] = $health['last_error'];
			return $c;
		}, self::get_connections() );
	}

	/**
	 * Save (create or update) a single connection.
	 *
	 * @param array $data Connection data.
	 * @return array The saved connection.
	 */
	public static function save_connection( array $data ): array {
		$connections = self::get_connections();
		$id          = ! empty( $data['id'] ) ? sanitize_key( $data['id'] ) : 'ai_' . substr( md5( wp_generate_uuid4() ), 0, 10 );

		$provider_id = sanitize_key( $data['provider'] ?? 'google_ai' );

		// Handle primary_for — support both new array format and legacy is_primary bool.
		$primary_for = array();
		if ( isset( $data['primary_for'] ) ) {
			$primary_for = self::normalize_primary_for( $data['primary_for'] );
		} elseif ( ! empty( $data['is_primary'] ) ) {
			// Legacy compat: is_primary=true → primary for both.
			$primary_for = array( 'text', 'image' );
		}

		$conn = array(
			'id'                => $id,
			'name'              => sanitize_text_field( $data['name'] ?? '' ),
			'provider'          => $provider_id,
			'text_model'        => sanitize_text_field( $data['text_model'] ?? '' ),
			'image_model'       => sanitize_text_field( $data['image_model'] ?? '' ),
			'custom_text_model' => sanitize_text_field( $data['custom_text_model'] ?? '' ),
			'custom_image_model'=> sanitize_text_field( $data['custom_image_model'] ?? '' ),
			'primary_for'       => $primary_for,
			'enabled'           => (bool) ( $data['enabled'] ?? true ),
			'api_format'        => in_array( $data['api_format'] ?? '', array( 'openai', 'anthropic' ), true ) ? $data['api_format'] : 'openai',
			'base_url'          => '',
		);

		// API key handling — encrypt if new, keep old if editing and not changed.
		if ( ! empty( $data['api_key'] ) && 0 !== strpos( $data['api_key'], '••' ) ) {
			$conn['api_key'] = Encryption::encrypt( $data['api_key'] );
		} else {
			$existing       = self::find_connection( $connections, $id );
			$conn['api_key'] = $existing ? ( $existing['api_key'] ?? '' ) : '';
		}

		// Base URL handling — encrypt if new, keep old if editing and not changed.
		// For custom providers only; other providers don't use base_url.
		if ( 'custom' === $provider_id ) {
			$raw_url = trim( $data['base_url'] ?? '' );
			if ( ! empty( $raw_url ) && 0 !== strpos( $raw_url, '••' ) ) {
				$conn['base_url'] = Encryption::encrypt( $raw_url );
			} else {
				$existing         = self::find_connection( $connections, $id );
				$conn['base_url'] = $existing ? ( $existing['base_url'] ?? '' ) : '';
			}
		}

		// If this connection claims primary for a task, remove that task from all other connections.
		if ( ! empty( $primary_for ) ) {
			foreach ( $connections as &$c ) {
				if ( $c['id'] === $id ) {
					continue;
				}
				$c['primary_for'] = array_values( array_diff( $c['primary_for'] ?? array(), $primary_for ) );
			}
			unset( $c );
		}

		// Insert or update.
		$found = false;
		foreach ( $connections as $i => $c ) {
			if ( $c['id'] === $id ) {
				$connections[ $i ] = $conn;
				$found             = true;
				break;
			}
		}
		if ( ! $found ) {
			// First connection gets primary for both tasks.
			if ( empty( $connections ) ) {
				$conn['primary_for'] = array( 'text', 'image' );
			}
			$connections[] = $conn;
		}

		// Ensure every task type has at least one primary connection.
		foreach ( array( 'text', 'image' ) as $task ) {
			$has_primary = false;
			foreach ( $connections as $c ) {
				if ( in_array( $task, $c['primary_for'] ?? array(), true ) ) {
					$has_primary = true;
					break;
				}
			}
			if ( ! $has_primary && ! empty( $connections ) ) {
				$connections[0]['primary_for']   = array_unique( array_merge( $connections[0]['primary_for'] ?? array(), array( $task ) ) );
				$connections[0]['primary_for']   = array_values( $connections[0]['primary_for'] );
			}
		}

		update_option( self::OPTION_KEY, $connections, false );
		\aime_clear_settings_cache( array( self::OPTION_KEY ) );
		self::clear_connection_health( $id );

		aime_log( sprintf( 'AI connection "%s" saved.', $conn['name'] ), 'info', 'ai' );

		return $conn;
	}

	/**
	 * Delete a connection by ID.
	 *
	 * @param string $id Connection ID.
	 * @return bool
	 */
	public static function delete_connection( string $id ): bool {
		$connections = self::get_connections();

		// Collect primary_for tasks from the connection being deleted.
		$orphaned_tasks = array();
		foreach ( $connections as $c ) {
			if ( $c['id'] === $id ) {
				$orphaned_tasks = $c['primary_for'] ?? array();
				break;
			}
		}

		$connections = array_values( array_filter( $connections, function ( $c ) use ( $id ) {
			return $c['id'] !== $id;
		} ) );

		// Reassign orphaned primary tasks to the first enabled connection.
		if ( ! empty( $orphaned_tasks ) && ! empty( $connections ) ) {
			foreach ( $orphaned_tasks as $task ) {
				$has_primary = false;
				foreach ( $connections as $c ) {
					if ( in_array( $task, $c['primary_for'] ?? array(), true ) ) {
						$has_primary = true;
						break;
					}
				}
				if ( ! $has_primary ) {
					$connections[0]['primary_for']   = array_unique( array_merge( $connections[0]['primary_for'] ?? array(), array( $task ) ) );
					$connections[0]['primary_for']   = array_values( $connections[0]['primary_for'] );
				}
			}
		}

		update_option( self::OPTION_KEY, $connections, false );
		\aime_clear_settings_cache( array( self::OPTION_KEY ) );
		self::clear_connection_health( $id );

		aime_log( sprintf( 'AI connection "%s" deleted.', $id ), 'info', 'ai' );

		return true;
	}

	/**
	 * Find a connection by ID.
	 */
	private static function find_connection( array $connections, string $id ): ?array {
		foreach ( $connections as $c ) {
			if ( ( $c['id'] ?? '' ) === $id ) {
				return $c;
			}
		}
		return null;
	}

	/* ================================================================
	 *  PROVIDER HEALTH / CIRCUIT BREAKER
	 * ============================================================= */

	private static function get_health_bucket(): array {
		$health = get_option( self::HEALTH_OPTION_KEY, array() );
		return is_array( $health ) ? $health : array();
	}

	private static function get_connection_health( string $conn_id ): array {
		$defaults = array(
			'status'       => 'available',
			'reason'       => '',
			'retry_after'  => 0,
			'last_error'   => '',
			'failed_count' => 0,
		);

		if ( '' === $conn_id ) {
			return $defaults;
		}

		$health = self::get_health_bucket();
		$item = wp_parse_args( $health[ $conn_id ] ?? array(), $defaults );
		$item['retry_after'] = absint( $item['retry_after'] ?? 0 );

		if ( in_array( $item['status'], array( 'rate_limited', 'cooldown' ), true ) && $item['retry_after'] > 0 && time() >= $item['retry_after'] ) {
			self::clear_connection_health( $conn_id );
			return $defaults;
		}

		return $item;
	}

	private static function is_connection_available( array $conn ): bool {
		$health = self::get_connection_health( $conn['id'] ?? '' );
		return 'available' === $health['status'];
	}

	private static function clear_connection_health( string $conn_id ): void {
		if ( '' === $conn_id ) {
			return;
		}

		$health = self::get_health_bucket();
		if ( isset( $health[ $conn_id ] ) ) {
			unset( $health[ $conn_id ] );
			update_option( self::HEALTH_OPTION_KEY, $health, false );
		}
	}

	private static function mark_connection_health( array $conn, array $classification, string $message ): void {
		$conn_id = $conn['id'] ?? '';
		if ( '' === $conn_id || 'bad_request' === $classification['type'] ) {
			return;
		}

		$health = self::get_health_bucket();
		$previous = $health[ $conn_id ] ?? array();
		$status = 'temporary_error' === $classification['type'] ? 'cooldown' : $classification['type'];
		$retry_after = 'auth_error' === $classification['type'] ? 0 : time() + absint( $classification['cooldown'] ?? self::TEMPORARY_COOLDOWN );

		$health[ $conn_id ] = array(
			'status'       => $status,
			'reason'       => $classification['reason'] ?? '',
			'retry_after'  => $retry_after,
			'last_error'   => sanitize_text_field( substr( $message, 0, 500 ) ),
			'failed_count' => absint( $previous['failed_count'] ?? 0 ) + 1,
			'updated_at'   => time(),
		);

		update_option( self::HEALTH_OPTION_KEY, $health, false );

		// Alert the admin when a key stops working or quota runs out.
		if ( 'auth_error' === $classification['type'] || 'quota_exceeded' === ( $classification['reason'] ?? '' ) ) {
			self::maybe_notify_key_failure( $conn, $classification, $message );
		}
	}

	/**
	 * Send a throttled (max one per day per connection+type) admin email
	 * when an API key fails with an auth or quota error.
	 */
	private static function maybe_notify_key_failure( array $conn, array $classification, string $message ): void {
		/**
		 * Filter to disable AI key health email notifications.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'aime_key_health_notifications', true ) ) {
			return;
		}

		$conn_id = $conn['id'] ?? '';
		if ( '' === $conn_id ) {
			return;
		}

		$throttle_key = 'aime_keyhealth_mail_' . md5( $conn_id . '|' . $classification['type'] );
		if ( get_transient( $throttle_key ) ) {
			return;
		}

		$to = get_option( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}

		set_transient( $throttle_key, 1, DAY_IN_SECONDS );

		$conn_name = $conn['name'] ?? ( $conn['provider'] ?? 'AI' );
		$is_auth   = 'auth_error' === $classification['type'];

		if ( $is_auth ) {
			/* translators: 1: site name, 2: connection name */
			$subject_tpl = __( '[%1$s] AI provider key problem: %2$s', 'ai-marketing-expert' );
		} else {
			/* translators: 1: site name, 2: connection name */
			$subject_tpl = __( '[%1$s] AI provider quota exceeded: %2$s', 'ai-marketing-expert' );
		}
		$subject = sprintf(
			$subject_tpl,
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$conn_name
		);

		$lines   = array();
		$lines[] = $is_auth
			? __( 'An AI provider connection is failing with an authentication error. Generations that rely on it will not work until the API key is fixed.', 'ai-marketing-expert' )
			: __( 'An AI provider connection has run out of quota or hit a billing limit. Generations fall back to other connections until it recovers.', 'ai-marketing-expert' );
		$lines[] = '';
		/* translators: %s: connection name. */
		$lines[] = sprintf( __( 'Connection: %s', 'ai-marketing-expert' ), $conn_name );
		/* translators: %s: AI provider slug (e.g. openai). */
		$lines[] = sprintf( __( 'Provider: %s', 'ai-marketing-expert' ), $conn['provider'] ?? '' );
		/* translators: %s: API error message. */
		$lines[] = sprintf( __( 'Error: %s', 'ai-marketing-expert' ), self::shorten_api_error( $message ) );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: admin URL of the AI providers settings page. */
			__( 'Review it here: %s', 'ai-marketing-expert' ),
			admin_url( 'admin.php?page=ai-marketing-expert-ai-providers' )
		);

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Build a standard failure array from an HTTP response.
	 *
	 * Attaches the HTTP status code (and Retry-After header when present) so
	 * classify_failure() can classify by status instead of matching provider
	 * error strings (audit B-3).
	 *
	 * @param array|\WP_Error $response     wp_remote_* response.
	 * @param string          $message      Human-readable error message.
	 * @param bool            $with_content Include an empty 'content' key (text-generation shape).
	 * @return array
	 */
	private static function http_failure( $response, string $message, bool $with_content = true ): array {
		$failure = array(
			'success'     => false,
			'message'     => $message,
			'status_code' => (int) wp_remote_retrieve_response_code( $response ),
		);
		if ( $with_content ) {
			$failure['content'] = '';
		}
		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( is_numeric( $retry_after ) && (int) $retry_after > 0 ) {
			$failure['retry_after'] = (int) $retry_after;
		}
		return $failure;
	}

	/**
	 * Classify a failed provider result for circuit-breaker cooldowns.
	 *
	 * Classifies by HTTP status code when the failure carries one (audit
	 * B-3) — provider error strings change wording between releases, status
	 * codes don't. String matching remains only as a fallback for transport
	 * failures (WP_Error: timeout, DNS, TLS), which have no status code.
	 */
	private static function classify_failure( array $result ): array {
		$code    = (int) ( $result['status_code'] ?? 0 );
		$message = strtolower( $result['message'] ?? '' );

		if ( 401 === $code || 403 === $code ) {
			return array( 'type' => 'auth_error', 'reason' => 'invalid_key', 'cooldown' => 0 );
		}

		if ( 402 === $code ) {
			return array( 'type' => 'rate_limited', 'reason' => 'quota_exceeded', 'cooldown' => self::QUOTA_COOLDOWN );
		}

		if ( 429 === $code ) {
			// OpenAI reports hard quota/billing exhaustion as 429 too — the
			// message is the only way to tell it apart from a burst limit.
			if ( false !== strpos( $message, 'quota' ) || false !== strpos( $message, 'billing' ) ) {
				return array( 'type' => 'rate_limited', 'reason' => 'quota_exceeded', 'cooldown' => self::QUOTA_COOLDOWN );
			}
			$cooldown = ! empty( $result['retry_after'] )
				? max( 60, min( (int) $result['retry_after'], self::QUOTA_COOLDOWN ) )
				: self::parse_provider_retry_after( $result['message'] ?? '' );
			return array( 'type' => 'rate_limited', 'reason' => 'rate_limited', 'cooldown' => $cooldown );
		}

		if ( in_array( $code, array( 500, 502, 503, 504, 529 ), true ) ) {
			// 529 = Anthropic "overloaded".
			return array( 'type' => 'temporary_error', 'reason' => 'provider_unavailable', 'cooldown' => self::TEMPORARY_COOLDOWN );
		}

		if ( $code >= 400 && $code < 500 ) {
			return array( 'type' => 'bad_request', 'reason' => 'request_or_model_error', 'cooldown' => 0 );
		}

		// No status code — transport failure or legacy caller. Fall back to
		// string matching.
		if ( false !== strpos( $message, 'invalid api key' ) || false !== strpos( $message, 'incorrect api key' ) || false !== strpos( $message, 'unauthorized' ) || false !== strpos( $message, '401' ) ) {
			return array( 'type' => 'auth_error', 'reason' => 'invalid_key', 'cooldown' => 0 );
		}

		if ( false !== strpos( $message, 'insufficient quota' ) || false !== strpos( $message, 'quota exceeded' ) || false !== strpos( $message, 'billing' ) || false !== strpos( $message, 'resource exhausted' ) ) {
			return array( 'type' => 'rate_limited', 'reason' => 'quota_exceeded', 'cooldown' => self::QUOTA_COOLDOWN );
		}

		if ( false !== strpos( $message, '429' ) || false !== strpos( $message, 'rate limit' ) || false !== strpos( $message, 'too many requests' ) ) {
			return array( 'type' => 'rate_limited', 'reason' => 'rate_limited', 'cooldown' => self::parse_provider_retry_after( $result['message'] ?? '' ) );
		}

		if ( false !== strpos( $message, '503' ) || false !== strpos( $message, '502' ) || false !== strpos( $message, '504' ) || false !== strpos( $message, 'timeout' ) || false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'overloaded' ) || false !== strpos( $message, 'temporarily unavailable' ) ) {
			return array( 'type' => 'temporary_error', 'reason' => 'provider_unavailable', 'cooldown' => self::TEMPORARY_COOLDOWN );
		}

		if ( false !== strpos( $message, 'model not found' ) || false !== strpos( $message, 'does not exist' ) || false !== strpos( $message, 'context length' ) || false !== strpos( $message, 'invalid request' ) ) {
			return array( 'type' => 'bad_request', 'reason' => 'request_or_model_error', 'cooldown' => 0 );
		}

		return array( 'type' => 'temporary_error', 'reason' => 'unknown_error', 'cooldown' => 60 );
	}

	private static function parse_provider_retry_after( string $message ): int {
		if ( preg_match( '/retry\s+(?:in|after)\s+([\d.]+)\s*(s|sec|second|seconds|m|min|minute|minutes)?/i', $message, $m ) ) {
			$value = (float) $m[1];
			$unit  = strtolower( $m[2] ?? 's' );
			$seconds = false !== strpos( $unit, 'm' ) ? (int) ceil( $value * 60 ) : (int) ceil( $value );
			return max( 60, min( $seconds, self::QUOTA_COOLDOWN ) );
		}

		return self::RATE_LIMIT_COOLDOWN;
	}

	/**
	 * Migrate old fixed-provider settings into connections format.
	 *
	 * @return array
	 */
	private static function maybe_migrate_old(): array {
		$old = get_option( 'aime_ai_settings', array() );
		if ( empty( $old ) || empty( $old['providers'] ) ) {
			return array();
		}

		$connections = array();
		$providers   = self::get_providers();

		foreach ( $old['providers'] as $provider_id => $settings ) {
			if ( empty( $settings['api_key'] ) && empty( $settings['enabled'] ) ) {
				continue;
			}
			$p = $providers[ $provider_id ] ?? null;
			if ( ! $p ) {
				continue;
			}

			$is_primary = ( $old['primary_provider'] ?? '' ) === $provider_id;

			$connections[] = array(
				'id'                 => 'ai_' . substr( md5( $provider_id ), 0, 10 ),
				'name'               => $p['name'],
				'provider'           => $provider_id,
				'api_key'            => $settings['api_key'] ?? '',
				'text_model'         => $settings['text_model'] ?? '',
				'image_model'        => $settings['image_model'] ?? '',
				'custom_text_model'  => $settings['custom_text_model'] ?? '',
				'custom_image_model' => $settings['custom_image_model'] ?? '',
				'primary_for'        => $is_primary ? array( 'text', 'image' ) : array(),
				'enabled'            => ! empty( $settings['enabled'] ),
			);
		}

		// If we migrated any, persist them and clean up old option.
		if ( ! empty( $connections ) ) {
			// Ensure every task type has at least one primary.
			foreach ( array( 'text', 'image' ) as $task ) {
				$has_primary = false;
				foreach ( $connections as $c ) {
					if ( in_array( $task, $c['primary_for'] ?? array(), true ) ) {
						$has_primary = true;
						break;
					}
				}
				if ( ! $has_primary ) {
					$connections[0]['primary_for'] = array_unique( array_merge( $connections[0]['primary_for'] ?? array(), array( $task ) ) );
					$connections[0]['primary_for'] = array_values( $connections[0]['primary_for'] );
				}
			}

			update_option( self::OPTION_KEY, $connections, false );
			\aime_clear_settings_cache( array( self::OPTION_KEY ) );
		}

		return $connections;
	}

	/* ================================================================
	 *  API RESPONSE
	 * ============================================================= */

	/**
	 * Move a fallback connection up or down in the trying order.
	 *
	 * Primary connections (any primary_for assignment) always run first for
	 * their task, so only pure-fallback connections can be reordered.
	 *
	 * @param string $id        Connection ID.
	 * @param string $direction 'up' or 'down'.
	 * @return array|null Reordered connections, or null if the move is invalid.
	 */
	public static function move_connection( string $id, string $direction ): ?array {
		if ( ! in_array( $direction, array( 'up', 'down' ), true ) ) {
			return null;
		}

		$connections = self::get_connections();
		$primaries   = array();
		$fallbacks   = array();

		foreach ( $connections as $connection ) {
			if ( ! empty( $connection['primary_for'] ) ) {
				$primaries[] = $connection;
				continue;
			}

			$fallbacks[] = $connection;
		}

		$index = null;
		foreach ( $fallbacks as $fallback_index => $connection ) {
			if ( ( $connection['id'] ?? '' ) === $id ) {
				$index = $fallback_index;
				break;
			}
		}

		if ( null === $index ) {
			return null;
		}

		$target = 'up' === $direction ? $index - 1 : $index + 1;
		if ( $target < 0 || $target >= count( $fallbacks ) ) {
			return null;
		}

		$temp                 = $fallbacks[ $target ];
		$fallbacks[ $target ] = $fallbacks[ $index ];
		$fallbacks[ $index ]  = $temp;

		$reordered = array_values( array_merge( $primaries, $fallbacks ) );

		update_option( self::OPTION_KEY, $reordered, false );
		\aime_clear_settings_cache( array( self::OPTION_KEY ) );

		aime_log( sprintf( 'AI connection "%s" moved %s.', $id, $direction ), 'info', 'ai' );

		return $reordered;
	}

	/**
	 * Get data for the REST API settings response.
	 *
	 * @return array
	 */
	public static function get_settings_for_api(): array {
		return array(
			'connections' => self::get_connections_for_api(),
			'providers'   => self::get_providers(),
		);
	}

	/* ================================================================
	 *  TEST CONNECTION
	 * ============================================================= */

	/**
	 * Test an AI connection by ID.
	 *
	 * @param string $id Connection ID.
	 * @return array [ 'success' => bool, 'message' => string ]
	 */
	public static function test_connection( string $id ): array {
		$connections = self::get_connections();
		$conn        = self::find_connection( $connections, $id );

		if ( ! $conn ) {
			return array(
				'success' => false,
				'message' => __( 'Connection not found.', 'ai-marketing-expert' ),
			);
		}

		$api_key = ! empty( $conn['api_key'] ) ? Encryption::decrypt( $conn['api_key'] ) : '';

		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'No API key configured for this connection.', 'ai-marketing-expert' ),
			);
		}

		$provider_id = $conn['provider'] ?? '';

		switch ( $provider_id ) {
			case 'google_ai':
				$result = self::test_google_ai( $api_key );
				break;
			case 'openai':
				$result = self::test_openai( $api_key );
				break;
			case 'openrouter':
				$result = self::test_openrouter( $api_key );
				break;
			case 'anthropic':
				$result = self::test_anthropic( $api_key );
				break;
			case 'custom':
				$result = self::test_custom( $conn, $api_key );
				break;
			default:
				return array( 'success' => false, 'message' => __( 'Test not available for this provider.', 'ai-marketing-expert' ) );
		}

		if ( ! empty( $result['success'] ) ) {
			self::clear_connection_health( $id );
		} else {
			self::mark_connection_health( $conn, self::classify_failure( $result ), $result['message'] ?? '' );
		}

		return $result;
	}

	private static function test_google_ai( string $api_key ): array {
		// API key is sent via header (never in the URL) so it cannot leak into
		// server / proxy / CDN access logs.
		$response = wp_remote_get(
			'https://generativelanguage.googleapis.com/v1beta/models',
			array(
				'timeout' => 15,
				'headers' => array( 'x-goog-api-key' => $api_key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return array( 'success' => true, 'message' => __( 'Google AI Studio connected successfully!', 'ai-marketing-expert' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return self::http_failure( $response, $body['error']['message'] ?? __( 'Connection failed.', 'ai-marketing-expert' ), false );
	}

	private static function test_openrouter( string $api_key ): array {
		$response = wp_remote_get(
			'https://openrouter.ai/api/v1/models',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return array( 'success' => true, 'message' => __( 'OpenRouter connected successfully!', 'ai-marketing-expert' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return self::http_failure( $response, $body['error']['message'] ?? __( 'Connection failed.', 'ai-marketing-expert' ), false );
	}

	private static function test_openai( string $api_key ): array {
		// GET /models verifies the key without running a paid completion (audit P-5).
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return array( 'success' => true, 'message' => __( 'OpenAI (ChatGPT) connected successfully!', 'ai-marketing-expert' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return self::http_failure( $response, $body['error']['message'] ?? __( 'Connection failed.', 'ai-marketing-expert' ), false );
	}

	private static function test_anthropic( string $api_key ): array {
		// GET /models verifies the key without running a paid completion (audit P-5).
		$response = wp_remote_get(
			'https://api.anthropic.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version'  => '2023-06-01',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return array( 'success' => true, 'message' => __( 'Anthropic (Claude) connected successfully!', 'ai-marketing-expert' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return self::http_failure( $response, $body['error']['message'] ?? __( 'Anthropic error.', 'ai-marketing-expert' ), false );
	}

	/* ================================================================
	 *  FETCH REMOTE MODELS FROM PROVIDER API
	 * ============================================================= */

	/**
	 * Fetch available models from a provider's API.
	 *
	 * @param string $provider_id Provider ID.
	 * @param string $api_key     Decrypted API key.
	 * @return array { success: bool, message?: string, models?: { text: array[], image: array[] } }
	 */
	public static function fetch_remote_models( string $provider_id, string $api_key, string $base_url = '', string $api_format = '' ): array {
		switch ( $provider_id ) {
			case 'google_ai':
				return self::fetch_google_ai_models( $api_key );
			case 'openai':
				return self::fetch_openai_models( $api_key );
			case 'openrouter':
				return self::fetch_openrouter_models( $api_key );
			case 'anthropic':
				return self::fetch_anthropic_models( $api_key );
			case 'custom':
				return self::fetch_custom_models( $api_format, $api_key, $base_url );
			default:
				return array(
					'success' => false,
					'message' => __( 'Fetching models is not supported for this provider.', 'ai-marketing-expert' ),
				);
		}
	}

	private static function fetch_google_ai_models( string $api_key ): array {
		$response = wp_remote_get(
			'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000',
			array(
				'timeout' => 20,
				'headers' => array( 'x-goog-api-key' => $api_key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return array( 'success' => false, 'message' => $body['error']['message'] ?? __( 'Failed to fetch models.', 'ai-marketing-expert' ) );
		}

		$text_models  = array();
		$image_models = array();

		foreach ( $body['models'] ?? array() as $m ) {
			$id      = str_replace( 'models/', '', $m['name'] ?? '' );
			$name    = $m['displayName'] ?? $id;
			$methods = $m['supportedGenerationMethods'] ?? array();

			// Skip models that can't generate content or images.
			if ( ! in_array( 'generateContent', $methods, true ) && ! in_array( 'generateImages', $methods, true ) ) {
				continue;
			}

			$entry = array( 'id' => $id, 'name' => $name );

			// Image generation models.
			if ( in_array( 'generateImages', $methods, true )
				|| false !== strpos( strtolower( $id ), 'imagen' )
				|| false !== strpos( strtolower( $id ), '-image' )
			) {
				$entry['type'] = 'image';
				$image_models[] = $entry;
			}

			// Text/content models.
			if ( in_array( 'generateContent', $methods, true ) ) {
				$entry['type'] = 'text';
				$text_models[] = $entry;
			}
		}

		// Reverse: Google typically lists older models first.
		$text_models  = array_reverse( $text_models );
		$image_models = array_reverse( $image_models );

		return array(
			'success' => true,
			'models'  => array( 'text' => array_values( $text_models ), 'image' => array_values( $image_models ) ),
		);
	}

	private static function fetch_openai_models( string $api_key ): array {
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return array( 'success' => false, 'message' => $body['error']['message'] ?? __( 'Failed to fetch models.', 'ai-marketing-expert' ) );
		}

		$text_models  = array();
		$image_models = array();

		foreach ( $body['data'] ?? array() as $m ) {
			$id      = $m['id'] ?? '';
			$created = $m['created'] ?? 0;

			// Image generation model.
			if ( preg_match( '/^(dall-e|gpt-image)/i', $id ) ) {
				$image_models[] = array( 'id' => $id, 'name' => $id, 'type' => 'image', 'created' => $created );
				continue;
			}

			// Chat / text model.
			if ( preg_match( '/^(gpt-|o[0-9]|chatgpt)/i', $id ) ) {
				$text_models[] = array( 'id' => $id, 'name' => $id, 'type' => 'text', 'created' => $created );
			}
		}

		// Sort by created date, newest first.
		usort( $text_models, function ( $a, $b ) {
			return ( $b['created'] ?? 0 ) <=> ( $a['created'] ?? 0 );
		} );
		usort( $image_models, function ( $a, $b ) {
			return ( $b['created'] ?? 0 ) <=> ( $a['created'] ?? 0 );
		} );

		return array(
			'success' => true,
			'models'  => array( 'text' => array_values( $text_models ), 'image' => array_values( $image_models ) ),
		);
	}

	private static function fetch_openrouter_models( string $api_key ): array {
		$response = wp_remote_get(
			'https://openrouter.ai/api/v1/models',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return array( 'success' => false, 'message' => $body['error']['message'] ?? __( 'Failed to fetch models.', 'ai-marketing-expert' ) );
		}

		$text_models  = array();
		$image_models = array();

		foreach ( $body['data'] ?? array() as $m ) {
			$id       = $m['id'] ?? '';
			$name     = $m['name'] ?? $id;
			$created  = $m['created'] ?? 0;
			$modality = $m['architecture']['modality'] ?? '';

			$entry = array( 'id' => $id, 'name' => $name, 'created' => $created );

			// Models that can generate images.
			if ( false !== strpos( $modality, '->image' ) || false !== strpos( $modality, '->text+image' ) ) {
				$entry['type'] = 'image';
				$image_models[] = $entry;
			}

			// All models that accept text input can serve as text models.
			if ( 0 === strpos( $modality, 'text' ) ) {
				$entry['type'] = 'text';
				$text_models[] = $entry;
			}
		}

		// Sort by created date, newest first.
		usort( $text_models, function ( $a, $b ) {
			return ( $b['created'] ?? 0 ) <=> ( $a['created'] ?? 0 );
		} );
		usort( $image_models, function ( $a, $b ) {
			return ( $b['created'] ?? 0 ) <=> ( $a['created'] ?? 0 );
		} );

		return array(
			'success' => true,
			'models'  => array( 'text' => array_values( $text_models ), 'image' => array_values( $image_models ) ),
		);
	}

	private static function fetch_anthropic_models( string $api_key ): array {
		$response = wp_remote_get(
			'https://api.anthropic.com/v1/models?limit=100',
			array(
				'timeout' => 20,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version'  => '2023-06-01',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return array( 'success' => false, 'message' => $body['error']['message'] ?? __( 'Failed to fetch models.', 'ai-marketing-expert' ) );
		}

		$text_models = array();

		foreach ( $body['data'] ?? array() as $m ) {
			$id         = $m['id'] ?? '';
			$name       = $m['display_name'] ?? $id;
			$created_at = $m['created_at'] ?? '';

			$text_models[] = array(
				'id'      => $id,
				'name'    => $name,
				'type'    => 'text',
				'created' => $created_at ? strtotime( $created_at ) : 0,
			);
		}

		// Sort by creation, newest first.
		usort( $text_models, function ( $a, $b ) {
			return ( $b['created'] ?? 0 ) <=> ( $a['created'] ?? 0 );
		} );

		// Anthropic models are text-only; no image generation.
		return array(
			'success' => true,
			'models'  => array( 'text' => array_values( $text_models ), 'image' => array() ),
		);
	}

	/* ================================================================
	 *  GENERATE CONTENT
	 * ============================================================= */

	/**
	 * Get the active model for a specific task type with smart fallback.
	 *
	 * Fallback chain:
	 * 1. Connection primary for this task → use its dedicated model (text_model or image_model)
	 * 2. Connection primary for this task → use its text_model if it has this capability (e.g., text model that can generate images)
	 * 3. Any enabled connection → use its dedicated model for this task
	 * 4. Any enabled connection → use its text_model if it has this capability
	 *
	 * @param string $task_type 'text' or 'image'.
	 * @return array [ 'provider' => string, 'model' => string, 'api_key' => string, 'conn_id' => string ]
	 */
	public static function get_active_model( string $task_type = 'text' ): array {
		$connections = self::get_connections();

		// Sort: primary for this task first, then other primaries, then the rest.
		usort( $connections, function ( $a, $b ) use ( $task_type ) {
			$a_primary = in_array( $task_type, $a['primary_for'] ?? array(), true );
			$b_primary = in_array( $task_type, $b['primary_for'] ?? array(), true );
			if ( $a_primary && ! $b_primary ) return -1;
			if ( ! $a_primary && $b_primary ) return 1;
			return 0;
		} );

		// Pass 1: Try the dedicated model for this task type (text_model or image_model).
		foreach ( $connections as $conn ) {
			if ( empty( $conn['enabled'] ) || empty( $conn['api_key'] ) || ! self::is_connection_available( $conn ) ) {
				continue;
			}

			$model_key = $task_type . '_model';
			$model     = $conn[ $model_key ] ?? '';

			if ( 'custom' === $model ) {
				$model = $conn[ 'custom_' . $model_key ] ?? '';
			}

			if ( ! empty( $model ) ) {
				return array(
					'provider' => $conn['provider'],
					'model'    => $model,
					'api_key'  => Encryption::decrypt( $conn['api_key'] ),
					'conn_id'  => $conn['id'],
				);
			}
		}

		// Pass 2 (image fallback): If looking for image and no image_model found,
		// try text_model from connections if it has image capability.
		if ( 'image' === $task_type ) {
			foreach ( $connections as $conn ) {
				if ( empty( $conn['enabled'] ) || empty( $conn['api_key'] ) || ! self::is_connection_available( $conn ) ) {
					continue;
				}

				$model = $conn['text_model'] ?? '';
				if ( 'custom' === $model ) {
					$model = $conn['custom_text_model'] ?? '';
				}

				if ( ! empty( $model ) && self::model_has_capability( $conn['provider'], $model, 'image' ) ) {
					return array(
						'provider' => $conn['provider'],
						'model'    => $model,
						'api_key'  => Encryption::decrypt( $conn['api_key'] ),
						'conn_id'  => $conn['id'],
					);
				}
			}
		}

		return array( 'provider' => '', 'model' => '', 'api_key' => '' );
	}

	/* ================================================================
	 *  RETRY WITH BACKOFF
	 * ============================================================= */

	/**
	 * Maximum number of retry attempts for rate-limited requests.
	 */
	private const MAX_RETRIES = 2;

	/**
	 * Check if an API result indicates a rate-limit / quota error worth retrying.
	 *
	 * @param array $result Provider result array.
	 * @return bool
	 */
	private static function is_retryable( array $result ): bool {
		if ( $result['success'] ) {
			return false;
		}
		$classification = self::classify_failure( $result );
		return 'temporary_error' === $classification['type'];
	}

	/**
	 * Extract retry-after seconds from an API error message.
	 *
	 * Many providers include "retry in N.NNs" or "retry after N" in the body.
	 *
	 * @param string $message Error message.
	 * @return int Seconds to wait (capped at 30, minimum 5).
	 */
	private static function parse_retry_after( string $message ): int {
		if ( preg_match( '/retry\s+(?:in|after)\s+([\d.]+)/i', $message, $m ) ) {
			$seconds = (int) ceil( (float) $m[1] );
			return max( 5, min( $seconds, 30 ) );
		}
		return 10; // sensible default
	}

	/**
	 * Execute a callable with automatic retry on rate-limit errors.
	 *
	 * @param callable $fn        Function returning an array with 'success' and 'message'.
	 * @param string   $label     Label for logging (e.g. "Google AI / model-id").
	 * @return array The last result from $fn.
	 */
	/**
	 * Whether we're running in a background context (cron / WP-CLI / queue),
	 * where long waits and retries are safe. In an interactive web request,
	 * blocking a PHP worker with sleep() or multi-minute cURL calls risks
	 * exhausting the worker pool and taking the whole site down.
	 */
	private static function is_background_context(): bool {
		return wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Compute a sensible HTTP timeout for an AI request based on max_tokens.
	 *
	 * Background context (cron/CLI): large completions (full article, keyword
	 * research) need far more than 60 s — allow up to 300 s.
	 * Web context: hard-cap at 60 s so a slow provider degrades the feature,
	 * not the site.
	 */
	private static function compute_http_timeout( int $max_tokens ): int {
		if ( self::is_background_context() ) {
			// ~30 tokens per second is conservative for large models.
			return max( 120, min( 300, (int) ( $max_tokens / 30 ) ) );
		}

		/**
		 * Filter the AI HTTP timeout (seconds) used for interactive web requests.
		 *
		 * @param int $timeout    Timeout in seconds (capped at 120).
		 * @param int $max_tokens Requested max tokens.
		 */
		$timeout = (int) apply_filters( 'aime_web_http_timeout', 60, $max_tokens );
		return max( 15, min( 120, $timeout ) );
	}

	private static function with_retry( callable $fn, string $label = '' ): array {
		$background = self::is_background_context();

		if ( $background && function_exists( 'set_time_limit' ) ) {
			// Only in cron/CLI: large AI generations can take 2-5 minutes, and
			// without this the PHP watchdog kills the process mid-cURL.
			// Never in web requests — see is_background_context().
			set_time_limit( 0 );
		}

		$result = $fn();

		// Retries with sleep() are reserved for background context. In a web
		// request we return the failure immediately: the caller's multi-
		// connection fallback still runs, and the user can simply retry.
		if ( ! $background ) {
			return $result;
		}

		for ( $attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			if ( ! self::is_retryable( $result ) ) {
				return $result;
			}

			$wait = self::parse_retry_after( $result['message'] ?? '' );
			aime_log( sprintf( 'Rate limited (%s). Retry %d/%d after %ds.', $label, $attempt, self::MAX_RETRIES, $wait ), 'info', 'ai' );

			sleep( $wait );

			$result = $fn();
		}

		return $result;
	}

	/**
	 * Minimum requested max_tokens before automatic continuation kicks in.
	 * Short calls (excerpts, meta, chat replies) are never stitched.
	 */
	private const CONTINUATION_MIN_TOKENS = 1500;

	/**
	 * Connections for a task, primary-for-task first.
	 */
	private static function sorted_connections( string $task ): array {
		$connections = self::get_connections();

		usort( $connections, function ( $a, $b ) use ( $task ) {
			$a_primary = in_array( $task, $a['primary_for'] ?? array(), true );
			$b_primary = in_array( $task, $b['primary_for'] ?? array(), true );
			if ( $a_primary && ! $b_primary ) return -1;
			if ( ! $a_primary && $b_primary ) return 1;
			return 0;
		} );

		return $connections;
	}

	/**
	 * Resolve the decrypted API key and model for a connection + task.
	 *
	 * @return array{api_key:string,model:string,error:string}
	 */
	private static function resolve_connection( array $conn, string $task ): array {
		$model_key = $task . '_model';
		$model     = $conn[ $model_key ] ?? '';
		if ( 'custom' === $model ) {
			$model = $conn[ 'custom_' . $model_key ] ?? '';
		}

		$name    = $conn['name'] ?? ( $conn['provider'] ?? '' );
		$api_key = Encryption::decrypt( $conn['api_key'] ?? '' );

		if ( empty( $api_key ) ) {
			return array(
				'api_key' => '',
				'model'   => '',
				'error'   => sprintf(
					/* translators: %s: connection name */
					__( '[%s] Stored API key could not be decrypted — security keys may have changed. Re-enter the API key in Settings → AI Providers.', 'ai-marketing-expert' ),
					$name
				),
			);
		}

		if ( empty( $model ) ) {
			return array(
				'api_key' => '',
				'model'   => '',
				'error'   => sprintf(
					/* translators: 1: connection name, 2: task type */
					__( '[%1$s] No %2$s model selected for this connection.', 'ai-marketing-expert' ),
					$name,
					$task
				),
			);
		}

		return array( 'api_key' => $api_key, 'model' => $model, 'error' => '' );
	}

	/**
	 * Dispatch one text call to the adapter for this connection's provider.
	 *
	 * @return array|null Adapter result, or null for an unknown provider id.
	 */
	private static function dispatch_text( array $conn, string $api_key, string $model, string $prompt, int $max_tokens, array $options ): ?array {
		$label = sprintf( '%s / %s', $conn['name'] ?? $conn['provider'], $model );

		switch ( $conn['provider'] ) {
			case 'google_ai':
				return self::with_retry( fn() => self::generate_google( $api_key, $model, $prompt, $max_tokens, $options ), $label );
			case 'openai':
				return self::with_retry( fn() => self::generate_openai( $api_key, $model, $prompt, $max_tokens, $options ), $label );
			case 'openrouter':
				return self::with_retry( fn() => self::generate_openrouter( $api_key, $model, $prompt, $max_tokens, $options ), $label );
			case 'anthropic':
				return self::with_retry( fn() => self::generate_anthropic( $api_key, $model, $prompt, $max_tokens, $options ), $label );
			case 'custom':
				return self::with_retry( fn() => self::generate_custom( $conn, $api_key, $model, $prompt, $max_tokens, $options ), $label );
		}

		return null;
	}

	/**
	 * How many continuation rounds are allowed in the current context.
	 *
	 * Cron / WP-CLI can afford several extra HTTP calls (and a backoff sleep);
	 * a web request gets exactly one so a PHP worker is never tied up.
	 */
	private static function continuation_rounds_allowed(): int {
		$rounds = self::is_background_context() ? 3 : 1;

		/**
		 * Filter the maximum number of continuation rounds per generation.
		 *
		 * @param int  $rounds     Default rounds.
		 * @param bool $background Whether this is a cron / CLI request.
		 */
		return max( 0, (int) apply_filters( 'aime_ai_max_continuation_rounds', $rounds, self::is_background_context() ) );
	}

	/**
	 * Join a truncated completion with its continuation, removing any overlap
	 * and whatever preamble the continuing model insisted on adding.
	 *
	 * @param string $head Text generated so far.
	 * @param string $tail Continuation text.
	 * @return string
	 */
	public static function stitch_text( string $head, string $tail ): string {
		$tail = function_exists( 'aime_strip_thinking_text' ) ? aime_strip_thinking_text( $tail ) : $tail;
		$tail = (string) preg_replace( '/^\s*```(?:json|html)?\s*/i', '', $tail );
		$tail = (string) preg_replace( '/\s*```\s*$/', '', $tail );
		// Drop conversational preambles ("Sure!", "Continuing where it left off:").
		// Two passes so "Sure!\nContinuing:\n<h2>" is fully cleared, and never
		// so far that the whole continuation disappears.
		for ( $i = 0; $i < 2; $i++ ) {
			$stripped = (string) preg_replace(
				'/^\s*(?:sure|certainly|okay|ok|of course|absolutely|got it|here(?:\'s| is)\b|continuing\b|continued\b)[^\n]*\R+/i',
				'',
				$tail,
				1
			);
			if ( $stripped === $tail || '' === trim( $stripped ) ) {
				break;
			}
			$tail = $stripped;
		}
		$tail = ltrim( $tail );

		if ( '' === $tail ) {
			return $head;
		}
		if ( '' === $head ) {
			return $tail;
		}

		// Strip the longest suffix of $head that the continuation repeated.
		$max = min( 300, strlen( $head ), strlen( $tail ) );
		for ( $len = $max; $len >= 24; $len-- ) {
			if ( 0 === substr_compare( $head, substr( $tail, 0, $len ), -$len, $len ) ) {
				$tail = ltrim( substr( $tail, $len ) );
				break;
			}
		}

		if ( '' === $tail ) {
			return $head;
		}

		$head_trimmed = rtrim( $head );
		$block_edge   = '<' === $tail[0] || '>' === substr( $head_trimmed, -1 );

		return $head_trimmed . ( $block_edge ? "\n" : ' ' ) . $tail;
	}

	/**
	 * Build the prompt that asks a (possibly different) provider to finish
	 * a response another provider started.
	 */
	private static function build_continuation_prompt( string $original_prompt, string $partial, string $format_hint = '' ): string {
		$tail = function_exists( 'mb_substr' ) ? mb_substr( $partial, -1500 ) : substr( $partial, -1500 );
		$hint = '' !== $format_hint ? $format_hint : 'exactly the same format as the text above';

		return "You are finishing a response that another model started but could not complete "
			. "because it ran out of output budget.\n\n"
			. "=== ORIGINAL TASK ===\n{$original_prompt}\n\n"
			. "=== RESPONSE SO FAR (this is the ending of it; it may stop mid-sentence) ===\n...{$tail}\n\n"
			. "=== YOUR JOB ===\n"
			. "Continue from exactly where the text above stops, in {$hint}.\n"
			. "- Do NOT repeat any text that already exists.\n"
			. "- Do NOT restate the beginning, summarize, or add a preamble, apology, or commentary.\n"
			. "- If the text stops mid-sentence or mid-word, resume mid-sentence so the halves join seamlessly.\n"
			. "- Bring the response to a proper close once the task is fully covered.\n"
			. "Output only the missing remainder.";
	}

	/**
	 * Choose the connection that should write the next chunk.
	 *
	 * Prefers a healthy connection that has not been used yet; falls back to
	 * the most recently used one so single-connection sites still continue.
	 */
	private static function pick_continuation_connection( array $connections, array $used_ids, bool $allow_reuse ): ?array {
		foreach ( $connections as $conn ) {
			if ( empty( $conn['enabled'] ) || empty( $conn['api_key'] ) || ! self::is_connection_available( $conn ) ) {
				continue;
			}
			if ( in_array( $conn['id'] ?? '', $used_ids, true ) ) {
				continue;
			}
			return $conn;
		}

		if ( ! $allow_reuse || empty( $used_ids ) ) {
			return null;
		}

		$last = (string) end( $used_ids );
		foreach ( $connections as $conn ) {
			if ( ( $conn['id'] ?? '' ) === $last && ! empty( $conn['enabled'] ) && ! empty( $conn['api_key'] ) ) {
				return $conn;
			}
		}

		return null;
	}

	/**
	 * Seconds to wait before hitting the same connection again.
	 *
	 * @return int Seconds (0 = go now), or -1 when reuse is pointless
	 *             (quota exhausted / bad key — waiting will not help).
	 */
	private static function reuse_wait_seconds( string $conn_id ): int {
		$health = self::get_connection_health( $conn_id );

		if ( in_array( $health['reason'] ?? '', array( 'quota_exceeded', 'invalid_key' ), true ) ) {
			return -1;
		}

		$retry_after = absint( $health['retry_after'] ?? 0 );
		if ( $retry_after <= 0 ) {
			return 0;
		}

		return max( 0, min( 60, $retry_after - time() ) );
	}

	/**
	 * Run a single continuation round against the next eligible connection.
	 *
	 * @param string $partial         Text produced so far.
	 * @param string $instructions    Original task prompt.
	 * @param int    $max_tokens      Token budget for this round.
	 * @param array  $options {
	 *     @type string   $task                 Task type. Default 'text'.
	 *     @type string[] $used_connection_ids  Connections already used.
	 *     @type string   $format_hint          e.g. "HTML only (p, h2, h3, ul, li)".
	 * }
	 * @return array { success, content, provider, model, connection_id, truncated, finish, message }
	 */
	public static function continue_text( string $partial, string $instructions, int $max_tokens = 2048, array $options = array() ): array {
		$task = (string) ( $options['task'] ?? 'text' );

		return self::continue_once(
			$partial,
			$instructions,
			$task,
			$max_tokens,
			$options,
			self::sorted_connections( $task ),
			array_values( array_filter( (array) ( $options['used_connection_ids'] ?? array() ) ) ),
			(string) ( $options['format_hint'] ?? '' )
		);
	}

	private static function continue_once( string $partial, string $instructions, string $task, int $max_tokens, array $options, array $connections, array $used_ids, string $format_hint ): array {
		$failure = array(
			'success'       => false,
			'content'       => '',
			'provider'      => '',
			'model'         => '',
			'connection_id' => '',
			'truncated'     => false,
			'finish'        => 'unknown',
			'message'       => '',
		);

		$conn = self::pick_continuation_connection( $connections, $used_ids, true );
		if ( ! $conn ) {
			$failure['message'] = __( 'No AI connection is available to continue the response.', 'ai-marketing-expert' );
			return $failure;
		}

		$conn_id = (string) ( $conn['id'] ?? '' );

		if ( in_array( $conn_id, $used_ids, true ) ) {
			// Single-connection site: reuse the same provider, but only if
			// waiting can actually help.
			$wait = self::reuse_wait_seconds( $conn_id );

			if ( $wait < 0 ) {
				$failure['message'] = __( 'The only available AI connection is out of quota — cannot continue.', 'ai-marketing-expert' );
				return $failure;
			}

			if ( $wait > 0 ) {
				if ( ! self::is_background_context() ) {
					// Never sleep() in a web request — a blocked PHP worker is
					// worse than a shorter article.
					$failure['message'] = __( 'Rate limited and no second provider available. Continue this generation from the background queue.', 'ai-marketing-expert' );
					return $failure;
				}
				aime_log( sprintf( 'Continuation: reusing the same connection after a %ds cooldown.', $wait ), 'info', 'ai' );
				sleep( $wait );
			}
		}

		$resolved = self::resolve_connection( $conn, $task );
		if ( '' !== $resolved['error'] ) {
			$failure['message'] = $resolved['error'];
			return $failure;
		}

		// A continuation is a fragment, never a standalone JSON document.
		$round_options = $options;
		unset( $round_options['json_mode'], $round_options['continuation'] );

		$result = self::dispatch_text(
			$conn,
			$resolved['api_key'],
			$resolved['model'],
			self::build_continuation_prompt( $instructions, $partial, $format_hint ),
			$max_tokens,
			$round_options
		);

		if ( ! $result || empty( $result['success'] ) ) {
			if ( $result ) {
				self::mark_connection_health( $conn, self::classify_failure( $result ), $result['message'] ?? '' );
				UsageTracker::record( $conn, $resolved['model'], $task, $result['usage'] ?? array(), false );
				$failure['message'] = self::shorten_api_error( $result['message'] ?? '' );
			}
			return $failure;
		}

		self::clear_connection_health( $conn_id );
		UsageTracker::record( $conn, $resolved['model'], $task, $result['usage'] ?? array(), true );

		return array(
			'success'       => true,
			'content'       => (string) $result['content'],
			'provider'      => (string) $conn['provider'],
			'model'         => $resolved['model'],
			'connection_id' => $conn_id,
			'truncated'     => ! empty( $result['truncated'] ),
			'finish'        => (string) ( $result['finish'] ?? 'unknown' ),
			'message'       => '',
			'usage'         => $result['usage'] ?? array(),
		);
	}

	/**
	 * Stitch continuation rounds onto a truncated result until it is complete
	 * or the round budget runs out.
	 */
	private static function maybe_continue( array $result, string $prompt, string $task, int $max_tokens, array $options, array $connections, float $started_at ): array {
		$mode = (string) ( $options['continuation'] ?? 'auto' );

		if ( 'none' === $mode || 'text' !== $task || empty( $result['truncated'] ) ) {
			return $result;
		}
		if ( 'auto' === $mode && $max_tokens < self::CONTINUATION_MIN_TOKENS ) {
			return $result;
		}

		$rounds   = self::continuation_rounds_allowed();
		$used_ids = array_filter( array( (string) ( $result['connection_id'] ?? '' ) ) );

		for ( $round = 1; $round <= $rounds; $round++ ) {
			if ( ! self::is_background_context() && ( microtime( true ) - $started_at ) > 45 ) {
				aime_log( 'Continuation skipped: web request already past 45s.', 'warning', 'ai' );
				break;
			}

			$next = self::continue_once(
				(string) $result['content'],
				$prompt,
				$task,
				$max_tokens,
				$options,
				$connections,
				$used_ids,
				(string) ( $options['format_hint'] ?? '' )
			);

			if ( empty( $next['success'] ) ) {
				if ( '' !== $next['message'] ) {
					aime_log( 'Continuation stopped: ' . $next['message'], 'warning', 'ai' );
				}
				break;
			}

			$before            = strlen( (string) $result['content'] );
			$result['content'] = self::stitch_text( (string) $result['content'], $next['content'] );
			$added             = strlen( (string) $result['content'] ) - $before;

			$used_ids[]            = $next['connection_id'];
			$result['providers'][] = array( 'provider' => $next['provider'], 'model' => $next['model'], 'round' => $round );
			$result['continued']   = $round;
			$result['truncated']   = ! empty( $next['truncated'] );
			$result['finish']      = $next['finish'];

			aime_log( sprintf(
				'Continuation round %d written by %s / %s (+%d chars, still truncated: %s).',
				$round,
				$next['provider'],
				$next['model'],
				$added,
				$result['truncated'] ? 'yes' : 'no'
			), 'info', 'ai' );

			if ( ! $result['truncated'] || $added < 50 ) {
				break;
			}
		}

		return $result;
	}

	/**
	 * Generate text content using AI connections (tries primary, then fallbacks).
	 *
	 * @param string $prompt     The prompt to send.
	 * @param string $task       Task type (text, image).
	 * @param int    $max_tokens Maximum tokens.
	 * @return array { success: bool, content: string, provider: string, model: string }
	 */
	public static function generate( string $prompt, string $task = 'text', int $max_tokens = 2048, array $options = array() ): array {
		/**
		 * Filter the max output tokens requested from the provider.
		 * Lowering this is the simplest way to reproduce truncation in testing.
		 *
		 * @param int    $max_tokens Requested tokens.
		 * @param string $task       Task type.
		 * @param array  $options    Call options.
		 */
		$max_tokens  = max( 1, (int) apply_filters( 'aime_ai_max_tokens', $max_tokens, $task, $options ) );
		$connections = self::sorted_connections( $task );
		$started_at  = microtime( true );

		$errors = array();
		$tried  = 0;

		foreach ( $connections as $conn ) {
			if ( empty( $conn['enabled'] ) || empty( $conn['api_key'] ) || ! self::is_connection_available( $conn ) ) {
				continue;
			}

			$resolved = self::resolve_connection( $conn, $task );
			if ( '' !== $resolved['error'] ) {
				$errors[] = $resolved['error'];
				continue;
			}

			++$tried;

			$provider_id = $conn['provider'];
			$model       = $resolved['model'];
			$result      = self::dispatch_text( $conn, $resolved['api_key'], $model, $prompt, $max_tokens, $options );

			if ( $result && $result['success'] ) {
				self::clear_connection_health( $conn['id'] ?? '' );
				UsageTracker::record( $conn, $model, $task, $result['usage'] ?? array(), true );

				$result['provider']      = $provider_id;
				$result['model']         = $model;
				$result['connection_id'] = (string) ( $conn['id'] ?? '' );
				$result['continued']     = 0;
				$result['providers']     = array(
					array( 'provider' => $provider_id, 'model' => $model, 'round' => 0 ),
				);

				// Truncated mid-answer? Let another provider (or this one after a
				// backoff) write the rest instead of throwing the work away.
				return self::maybe_continue( $result, $prompt, $task, $max_tokens, $options, $connections, $started_at );
			}

			if ( $result && ! $result['success'] ) {
				$raw_message    = $result['message'] ?? 'Unknown error';
				$classification = self::classify_failure( $result );
				self::mark_connection_health( $conn, $classification, $raw_message );
				UsageTracker::record( $conn, $model, $task, $result['usage'] ?? array(), false );
				$errors[] = sprintf( '[%s / %s] %s', $conn['name'] ?? $provider_id, $model, self::shorten_api_error( $raw_message ) );
			}

			// Log fallback.
			aime_log( sprintf( 'AI connection "%s" failed, trying next...', $conn['name'] ), 'warning', 'ai' );
		}

		if ( 0 === $tried && empty( $errors ) ) {
			return array(
				'success' => false,
				'content' => '',
				'message' => __( 'No AI provider is configured. Please add an AI provider connection in Settings → AI Providers.', 'ai-marketing-expert' ),
			);
		}

		return array(
			'success' => false,
			'content' => '',
			'message' => ! empty( $errors )
				? __( 'All AI providers failed:', 'ai-marketing-expert' ) . "\n" . implode( "\n", $errors )
				: __( 'No AI provider is configured or all providers failed. Please configure an AI provider in Settings.', 'ai-marketing-expert' ),
		);
	}

	/**
	 * Generate an image using AI and save it to the WordPress media library.
	 *
	 * @param string $prompt  Image description / prompt.
	 * @param string $title   Image title for WP media.
	 * @param int    $post_id Optional article / post ID for attachment linkage.
	 * @return array { success: bool, image_url: string, attachment_id: int, provider: string }
	 */
	public static function generate_image( string $prompt, string $title = '', int $post_id = 0 ): array {
		$connections = self::get_connections();

		// Sort: primary for image first.
		usort( $connections, function ( $a, $b ) {
			$a_primary = in_array( 'image', $a['primary_for'] ?? array(), true );
			$b_primary = in_array( 'image', $b['primary_for'] ?? array(), true );
			if ( $a_primary && ! $b_primary ) return -1;
			if ( ! $a_primary && $b_primary ) return 1;
			return 0;
		} );

		$errors = array();
		$tried  = 0;

		// Pass 1: connections with an explicit image model.
		// Pass 2: connections without an image model — fall back to provider default image model.
		foreach ( array( 'explicit', 'fallback' ) as $pass ) {
		foreach ( $connections as $conn ) {
			if ( empty( $conn['enabled'] ) || empty( $conn['api_key'] ) || ! self::is_connection_available( $conn ) ) {
				continue;
			}

			$api_key     = Encryption::decrypt( $conn['api_key'] );
			$provider_id = $conn['provider'];

			$model = $conn['image_model'] ?? '';
			if ( 'custom' === $model ) {
				$model = $conn['custom_image_model'] ?? '';
			}

			// On the first pass only process connections with an image model set.
			// On the fallback pass only process connections that were skipped (empty model).
			if ( 'explicit' === $pass && empty( $model ) ) {
				continue;
			}
			if ( 'fallback' === $pass && ! empty( $model ) ) {
				continue; // already tried in pass 1
			}

			// If no image model set, fallback to text model (multimodal support).
			if ( empty( $model ) ) {
				$model = $conn['text_model'] ?? '';
			}

			if ( empty( $api_key ) ) {
				$errors[] = sprintf(
					/* translators: %s: connection name */
					__( '[%s] Stored API key could not be decrypted — security keys may have changed. Re-enter the API key in Settings → AI Providers.', 'ai-marketing-expert' ),
					$conn['name'] ?? $provider_id
				);
				continue;
			}
			if ( empty( $model ) ) {
				continue;
			}

			++$tried;
			$result     = null;
			$call_label = sprintf( '%s / %s', $conn['name'] ?? $provider_id, $model );

			switch ( $provider_id ) {
				case 'openai':
					$result = self::with_retry( fn() => self::generate_image_openai( $api_key, $model, $prompt ), $call_label );
					break;
				case 'google_ai':
					$result = self::with_retry( fn() => self::generate_image_google( $api_key, $model, $prompt ), $call_label );
					break;
				case 'openrouter':
					$result = self::with_retry( fn() => self::generate_image_openrouter( $api_key, $model, $prompt ), $call_label );
					break;
				case 'custom':
					if ( 'anthropic' === ( $conn['api_format'] ?? 'openai' ) ) {
						continue 2; // Anthropic-compatible endpoints have no image generation.
					}
					$result = self::with_retry( fn() => self::generate_image_custom_openai( $api_key, $model, $prompt, self::decrypt_maybe( $conn['base_url'] ?? '' ) ), $call_label );
					break;
				default:
					continue 2; // skip providers without image generation
			}

			if ( $result && $result['success'] && ! empty( $result['image_data'] ) ) {
				self::clear_connection_health( $conn['id'] ?? '' );
				// Save to WP media library.
				$saved = self::save_image_to_media( $result['image_data'], $result['mime_type'] ?? 'image/png', $title, $post_id );
				if ( $saved ) {
					return array(
						'success'       => true,
						'image_url'     => $saved['url'],
						'attachment_id' => $saved['id'],
						'provider'      => $provider_id,
						'model'         => $model,
					);
				}
			}

			if ( $result && ! $result['success'] ) {
				$raw_message = $result['message'] ?? 'Unknown error';
				$classification = self::classify_failure( $result );
				self::mark_connection_health( $conn, $classification, $raw_message );
				$full_error  = sprintf( '[%s / %s] %s', $conn['name'] ?? $provider_id, $model, $raw_message );

				// Log full technical detail for debugging.
				aime_log( 'Image generation failed: ' . $full_error, 'warning', 'ai' );

				// Build short user-facing error.
				$short = self::shorten_api_error( $raw_message );
				$errors[] = sprintf( '[%s / %s] %s', $conn['name'] ?? $provider_id, $model, $short );
			}
		} // end inner foreach (connections)
		} // end outer foreach (pass)

		if ( 0 === $tried && empty( $errors ) ) {
			return array(
				'success' => false,
				'message' => __( 'No AI provider is configured with an image model. Please select an image model in Settings → AI Providers.', 'ai-marketing-expert' ),
			);
		}

		return array(
			'success' => false,
			'message' => ! empty( $errors )
				? implode( "\n", $errors )
				: __( 'Image generation failed. Ensure your AI provider supports image generation.', 'ai-marketing-expert' ),
		);
	}

	/**
	 * Shorten a verbose API error message to a user-friendly one-liner.
	 *
	 * @param string $message Raw error from the API.
	 * @return string
	 */
	private static function shorten_api_error( string $message ): string {
		$lower = strtolower( $message );

		if ( false !== strpos( $lower, 'quota' ) || false !== strpos( $lower, 'rate' ) ) {
			return __( 'API quota exceeded. Please check your plan or try again later.', 'ai-marketing-expert' );
		}
		if ( false !== strpos( $lower, 'invalid' ) && false !== strpos( $lower, 'key' ) ) {
			return __( 'Invalid API key. Please check your credentials.', 'ai-marketing-expert' );
		}
		if ( false !== strpos( $lower, 'not found' ) || false !== strpos( $lower, 'does not exist' ) ) {
			return __( 'Model not found. Please select a different model.', 'ai-marketing-expert' );
		}
		if ( false !== strpos( $lower, 'permission' ) || false !== strpos( $lower, 'forbidden' ) ) {
			return __( 'Permission denied. Check your API key permissions.', 'ai-marketing-expert' );
		}
		if ( false !== strpos( $lower, 'timeout' ) || false !== strpos( $lower, 'timed out' ) ) {
			return __( 'Request timed out. Please try again.', 'ai-marketing-expert' );
		}
		if ( false !== strpos( $lower, 'safety' ) || false !== strpos( $lower, 'blocked' ) || false !== strpos( $lower, 'content filter' ) ) {
			return __( 'Content blocked by safety filters. Try a different prompt.', 'ai-marketing-expert' );
		}

		// Truncate anything else to keep it short.
		if ( strlen( $message ) > 100 ) {
			// Take the first sentence.
			$dot = strpos( $message, '.' );
			if ( false !== $dot && $dot < 120 ) {
				return substr( $message, 0, $dot + 1 );
			}
			return substr( $message, 0, 100 ) . '…';
		}

		return $message;
	}

	/* ── Image generation per provider ──────────────── */

	private static function generate_image_openai( string $api_key, string $model, string $prompt ): array {
		$payload = array(
			'model'  => $model,
			'prompt' => $prompt,
			'n'      => 1,
			'size'   => '1024x1024',
		);

		// gpt-image-* models always return base64 and reject `response_format`;
		// DALL·E models default to URL responses, so request base64 explicitly.
		if ( false === strpos( strtolower( $model ), 'gpt-image' ) ) {
			$payload['response_format'] = 'b64_json';
		}

		$response = wp_remote_post( 'https://api.openai.com/v1/images/generations', array(
			'timeout' => 120,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return self::http_failure( $response, $body['error']['message'] ?? "OpenAI error (HTTP {$code})", false );
		}

		$b64 = $body['data'][0]['b64_json'] ?? '';
		if ( empty( $b64 ) ) {
			// Fallback: check for url response.
			$url = $body['data'][0]['url'] ?? '';
			if ( $url ) {
				// URL comes from the AI provider response — block private/loopback targets (SSRF).
				$img = wp_remote_get( $url, array( 'timeout' => self::image_fetch_timeout(), 'reject_unsafe_urls' => true ) );
				if ( ! is_wp_error( $img ) ) {
					return array(
						'success'    => true,
						'image_data' => wp_remote_retrieve_body( $img ),
						'mime_type'  => wp_remote_retrieve_header( $img, 'content-type' ) ?: 'image/png',
					);
				}
			}
			return array( 'success' => false, 'message' => 'No image data returned.' );
		}

		return array(
			'success'    => true,
			'image_data' => base64_decode( $b64 ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			'mime_type'  => 'image/png',
		);
	}

	private static function generate_image_google( string $api_key, string $model, string $prompt ): array {
		// Imagen models use a different endpoint and payload structure.
		$is_imagen = ( false !== strpos( $model, 'imagen' ) );

		if ( $is_imagen ) {
			return self::generate_image_google_imagen( $api_key, $model, $prompt );
		}

		// Gemini image models — try IMAGE-only first, fall back to TEXT+IMAGE.
		$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

		$modality_sets = array(
			array( 'IMAGE' ),
			array( 'TEXT', 'IMAGE' ),
		);

		$last_error = '';

		foreach ( $modality_sets as $modalities ) {
			$body_payload = array(
				'contents'         => array(
					array( 'parts' => array( array( 'text' => $prompt ) ) ),
				),
				'generationConfig' => array(
					'responseModalities' => $modalities,
				),
			);

			$response = wp_remote_post( $url, array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode( $body_payload ),
			) );

			if ( is_wp_error( $response ) ) {
				return array( 'success' => false, 'message' => $response->get_error_message() );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$code = wp_remote_retrieve_response_code( $response );

			if ( 200 !== $code ) {
				$last_error = $body['error']['message'] ?? "Google AI error (HTTP {$code})";
				aime_log( "Gemini image attempt [{$model}] modalities=" . implode( ',', $modalities ) . " failed: {$last_error}", 'warning', 'ai' );
				continue; // try next modality set
			}

			// Look for inline image data in the response parts.
			$parts = $body['candidates'][0]['content']['parts'] ?? array();
			foreach ( $parts as $part ) {
				if ( isset( $part['inlineData'] ) ) {
					$b64  = $part['inlineData']['data'] ?? '';
					$mime = $part['inlineData']['mimeType'] ?? 'image/png';
					if ( $b64 ) {
						return array(
							'success'    => true,
							'image_data' => base64_decode( $b64 ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
							'mime_type'  => $mime,
						);
					}
				}
			}

			$last_error = 'Response received but no image data found.';
		}

		return array( 'success' => false, 'message' => $last_error ?: 'No image data in Google AI response.' );
	}

	/**
	 * Google Imagen models — use the predict endpoint.
	 */
	private static function generate_image_google_imagen( string $api_key, string $model, string $prompt ): array {
		$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:predict";

		$body_payload = array(
			'instances' => array(
				array( 'prompt' => $prompt ),
			),
			'parameters' => array(
				'sampleCount'  => 1,
			),
		);

		$response = wp_remote_post( $url, array(
			'timeout' => 120,
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'body'    => wp_json_encode( $body_payload ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return self::http_failure( $response, $body['error']['message'] ?? "Imagen error (HTTP {$code})", false );
		}

		// Imagen returns predictions[].bytesBase64Encoded.
		$predictions = $body['predictions'] ?? array();
		if ( ! empty( $predictions[0]['bytesBase64Encoded'] ) ) {
			$b64  = $predictions[0]['bytesBase64Encoded'];
			$mime = $predictions[0]['mimeType'] ?? 'image/png';
			return array(
				'success'    => true,
				'image_data' => base64_decode( $b64 ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				'mime_type'  => $mime,
			);
		}

		return array( 'success' => false, 'message' => 'No image data in Imagen response.' );
	}

	private static function generate_image_openrouter( string $api_key, string $model, string $prompt ): array {
		// OpenRouter image-capable models (currently the Gemini "Nano Banana"
		// family) are accessed via the standard chat/completions endpoint.
		// `response_format: { type: 'image' }` is not a documented OpenRouter
		// parameter and has been observed to either be rejected or ignored;
		// the response body still carries either an inline `data:image/...`
		// URI or a direct image URL inside `choices[0].message.content`,
		// both of which the parsing below already handles.
		$response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
			'timeout' => 120,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
				'HTTP-Referer'  => home_url(),
			),
			'body'    => wp_json_encode( array(
				'model'    => $model,
				'messages' => array(
					array( 'role' => 'user', 'content' => $prompt ),
				),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return self::http_failure( $response, $body['error']['message'] ?? "OpenRouter error (HTTP {$code})", false );
		}

		// OpenRouter may return image data inline or as a URL depending on the model.
		$content = $body['choices'][0]['message']['content'] ?? '';
		if ( preg_match( '/data:image\/([a-z]+);base64,([A-Za-z0-9+\/=]+)/s', $content, $m ) ) {
			return array(
				'success'    => true,
				'image_data' => base64_decode( $m[2] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				'mime_type'  => 'image/' . $m[1],
			);
		}

		// Check for URL in response.
		if ( preg_match( '/https?:\/\/[^\s"]+\.(?:png|jpg|jpeg|webp)/i', $content, $m ) ) {
			// URL parsed from AI-generated content — block private/loopback targets (SSRF).
			$img = wp_remote_get( $m[0], array( 'timeout' => self::image_fetch_timeout(), 'reject_unsafe_urls' => true ) );
			if ( ! is_wp_error( $img ) && 200 === wp_remote_retrieve_response_code( $img ) ) {
				return array(
					'success'    => true,
					'image_data' => wp_remote_retrieve_body( $img ),
					'mime_type'  => wp_remote_retrieve_header( $img, 'content-type' ) ?: 'image/png',
				);
			}
		}

		return array( 'success' => false, 'message' => 'No image data in OpenRouter response.' );
	}

	/**
	 * Save raw image binary data to the WordPress media library.
	 */
	private static function save_image_to_media( string $image_data, string $mime_type, string $title, int $post_id = 0 ): ?array {
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$ext_map = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		);
		$ext = $ext_map[ $mime_type ] ?? 'png';

		$slug     = sanitize_title( $title ?: 'ai-image' );
		$filename = $slug . '-' . wp_generate_password( 6, false ) . '.' . $ext;

		$upload = wp_upload_bits( $filename, null, $image_data );
		if ( ! empty( $upload['error'] ) ) {
			aime_log( 'Image upload error: ' . $upload['error'], 'error', 'ai' );
			return null;
		}

		$attachment = array(
			'post_title'     => $title ?: 'AI Generated Image',
			'post_content'   => '',
			'post_mime_type' => $mime_type,
			'guid'           => $upload['url'],
		);

		$attach_id = wp_insert_attachment( $attachment, $upload['file'], $post_id );
		if ( is_wp_error( $attach_id ) ) {
			return null;
		}

		$metadata = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $metadata );

		return array(
			'id'  => $attach_id,
			'url' => $upload['url'],
		);
	}

	/* ================================================================
	 *  GENERATE METHODS
	 * ============================================================= */

	/**
	 * Extract normalized token usage from a provider response body.
	 *
	 * @param array  $body   Decoded JSON response body.
	 * @param string $format 'openai' | 'google' | 'anthropic'.
	 * @return array { prompt_tokens: int, completion_tokens: int }
	 */
	/**
	 * Normalize a provider-specific finish / stop reason into a shared vocabulary.
	 *
	 * @param string $raw    Raw reason from the API ('' when the provider omits it).
	 * @param string $format Provider family: google | anthropic | openai.
	 * @return string One of: stop, length, filter, unknown.
	 */
	private static function normalize_finish( string $raw, string $format ): string {
		$raw = strtoupper( trim( $raw ) );
		if ( '' === $raw ) {
			return 'unknown';
		}

		switch ( $format ) {
			case 'google':
				if ( 'MAX_TOKENS' === $raw ) {
					return 'length';
				}
				if ( 'STOP' === $raw ) {
					return 'stop';
				}
				if ( in_array( $raw, array( 'SAFETY', 'RECITATION', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII' ), true ) ) {
					return 'filter';
				}
				return 'unknown';

			case 'anthropic':
				if ( 'MAX_TOKENS' === $raw ) {
					return 'length';
				}
				if ( in_array( $raw, array( 'END_TURN', 'STOP_SEQUENCE' ), true ) ) {
					return 'stop';
				}
				if ( 'REFUSAL' === $raw ) {
					return 'filter';
				}
				return 'unknown';

			default: // openai / openrouter / custom-openai.
				if ( 'LENGTH' === $raw ) {
					return 'length';
				}
				if ( 'STOP' === $raw ) {
					return 'stop';
				}
				if ( 'CONTENT_FILTER' === $raw ) {
					return 'filter';
				}
				return 'unknown';
		}
	}

	/**
	 * Heuristic truncation check, used only when a provider omits its finish
	 * reason. A completion that was cut off mid-flight almost always ends
	 * inside an unclosed JSON object, inside an HTML tag, or mid-sentence.
	 *
	 * @param string $text Completion text.
	 * @return bool
	 */
	private static function looks_truncated( string $text ): bool {
		$text = rtrim( $text );
		if ( '' === $text ) {
			return false;
		}

		// Unbalanced JSON envelope — cut off mid-object.
		if ( '{' === $text[0] && substr_count( $text, '{' ) > substr_count( $text, '}' ) ) {
			return true;
		}

		// Ends inside an unfinished HTML tag.
		if ( preg_match( '/<[a-z][^>]*$/i', $text ) ) {
			return true;
		}

		// Ends on a character no complete sentence or block ever ends with.
		return ! preg_match( '/(?:[.!?;:)\]}"\']|>)$/u', $text );
	}

	/**
	 * Build a successful text-generation result with normalized completion state.
	 *
	 * @param string $text       Completion text.
	 * @param array  $body       Decoded API response body (for usage extraction).
	 * @param string $format     Provider family: google | anthropic | openai.
	 * @param string $raw_finish Raw finish / stop reason from the API.
	 * @return array
	 */
	private static function text_result( string $text, array $body, string $format, string $raw_finish ): array {
		$finish = self::normalize_finish( $raw_finish, $format );

		if ( 'unknown' === $finish && self::looks_truncated( $text ) ) {
			$finish = 'length';
		}

		return array(
			'success'   => true,
			'content'   => $text,
			'usage'     => self::extract_usage( $body, $format ),
			'finish'    => $finish,
			'truncated' => 'length' === $finish,
		);
	}

	private static function extract_usage( array $body, string $format ): array {
		switch ( $format ) {
			case 'google':
				return array(
					'prompt_tokens'     => absint( $body['usageMetadata']['promptTokenCount'] ?? 0 ),
					'completion_tokens' => absint( $body['usageMetadata']['candidatesTokenCount'] ?? 0 ),
				);
			case 'anthropic':
				return array(
					'prompt_tokens'     => absint( $body['usage']['input_tokens'] ?? 0 ),
					'completion_tokens' => absint( $body['usage']['output_tokens'] ?? 0 ),
				);
			default: // openai / openrouter / custom-openai.
				return array(
					'prompt_tokens'     => absint( $body['usage']['prompt_tokens'] ?? 0 ),
					'completion_tokens' => absint( $body['usage']['completion_tokens'] ?? 0 ),
				);
		}
	}

	private static function generate_google( string $api_key, string $model, string $prompt, int $max_tokens, array $options = array() ): array {
		$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

		$generation_config = array(
			'maxOutputTokens' => $max_tokens,
			'temperature'     => 0.7,
			'thinkingConfig'  => array( 'thinkingBudget' => 0 ),
		);

		if ( ! empty( $options['json_mode'] ) ) {
			$generation_config['responseMimeType'] = 'application/json';
		}

		$response = wp_remote_post( $url, array(
			'timeout' => self::compute_http_timeout( $max_tokens ),
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'body'    => wp_json_encode( array(
				'contents'         => array(
					array( 'parts' => array( array( 'text' => $prompt ) ) ),
				),
				'generationConfig' => $generation_config,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'content' => '', 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return self::http_failure( $response, $body['error']['message'] ?? __( 'Google AI error.', 'ai-marketing-expert' ) );
		}

		$candidate  = $body['candidates'][0] ?? array();
		$raw_finish = (string) ( $candidate['finishReason'] ?? '' );

		// Gemini can split a single answer across several parts.
		$text = '';
		foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
			if ( isset( $part['text'] ) ) {
				$text .= (string) $part['text'];
			}
		}

		if ( '' === trim( $text ) ) {
			if ( 'filter' === self::normalize_finish( $raw_finish, 'google' ) ) {
				return array(
					'success' => false,
					'content' => '',
					'message' => __( 'The model blocked this request (safety filter). Try rewording the topic or switching model.', 'ai-marketing-expert' ),
				);
			}
			return array(
				'success' => false,
				'content' => '',
				'message' => sprintf(
					/* translators: %s: the finishReason value returned by the Google AI API. */
					__( 'Google AI returned no content (finish reason: %s).', 'ai-marketing-expert' ),
					'' !== $raw_finish ? $raw_finish : 'none'
				),
			);
		}

		return self::text_result( $text, (array) $body, 'google', $raw_finish );
	}

	private static function generate_openrouter( string $api_key, string $model, string $prompt, int $max_tokens, array $options = array() ): array {
		$request = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'messages'   => array(
				array( 'role' => 'user', 'content' => $prompt ),
			),
			'provider' => array(
				'require_parameters' => true,
			),
		);

		// Structured output (json_schema) takes precedence over basic json_mode.
		if ( ! empty( $options['json_schema'] ) ) {
			$request['response_format'] = array(
				'type'        => 'json_schema',
				'json_schema' => $options['json_schema'],
			);
		} elseif ( ! empty( $options['json_mode'] ) ) {
			$request['response_format'] = array( 'type' => 'json_object' );
		}

		$response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
			'timeout' => self::compute_http_timeout( $max_tokens ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
				'HTTP-Referer'  => home_url(),
			),
			'body'    => wp_json_encode( $request ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'content' => '', 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return self::http_failure( $response, $body['error']['message'] ?? __( 'OpenRouter error.', 'ai-marketing-expert' ) );
		}

		$choice        = $body['choices'][0] ?? array();
		$finish_reason = $choice['finish_reason'] ?? '';

		// Extract content — reasoning models may return thinking in reasoning_content.
		// Prefer content over reasoning_content (content is the actual answer).
		$message = $choice['message'] ?? array();
		$text    = isset( $message['content'] ) ? (string) $message['content'] : null;

		// Fallback: if content is empty but reasoning_content exists, extract it.
		if ( ( null === $text || '' === $text ) && ! empty( $message['reasoning_content'] ) ) {
			$text = (string) $message['reasoning_content'];
		}

		if ( null === $text || '' === trim( $text ) ) {
			if ( 'content_filter' === $finish_reason ) {
				$reason = __( 'The model blocked this request due to its content filter.', 'ai-marketing-expert' );
			} elseif ( 'stop' === $finish_reason || '' === $finish_reason ) {
				$reason = __( 'Model returned empty content. Try a different model.', 'ai-marketing-expert' );
			} else {
				/* translators: %s: the finish_reason value returned by the AI API. */
				$reason = sprintf( __( 'Model returned no content (finish_reason: %s).', 'ai-marketing-expert' ), $finish_reason );
			}
			return array( 'success' => false, 'content' => '', 'message' => $reason );
		}

		return self::text_result( $text, (array) $body, 'openai', $finish_reason );
	}

	/* ================================================================
	 *  OPENAI REQUEST PARAMETER HELPERS
	 * ============================================================= */

	/**
	 * Whether an OpenAI model is a reasoning model (o-series / GPT-5 family).
	 * Only these models accept the `reasoning_effort` parameter.
	 */
	private static function is_openai_reasoning_model( string $model ): bool {
		return (bool) preg_match( '/^(o\d|gpt-5)/i', $model );
	}

	/**
	 * Build the chat/completions request body for OpenAI with
	 * model-appropriate parameters:
	 * - `max_completion_tokens` (current param; `max_tokens` is rejected by
	 *   reasoning models and deprecated on the rest).
	 * - `reasoning_effort` only for reasoning models, using 'low' which is
	 *   valid across the o-series and the GPT-5 family ('none' is not).
	 */
	private static function build_openai_chat_body( string $model, string $prompt, int $max_tokens, array $options = array() ): array {
		$body = array(
			'model'                 => $model,
			'max_completion_tokens' => $max_tokens,
			'messages'              => array(
				array( 'role' => 'user', 'content' => $prompt ),
			),
		);

		// Structured output (json_schema) takes precedence over basic json_mode.
		// This is how coding agents (Copilot, Cursor, Claude Code) get reliable
		// JSON with thinking separated automatically by the model.
		if ( ! empty( $options['json_schema'] ) ) {
			$body['response_format'] = array(
				'type'        => 'json_schema',
				'json_schema' => $options['json_schema'],
			);
		} elseif ( ! empty( $options['json_mode'] ) ) {
			// Fallback: basic json_object mode (model returns JSON but structure
			// not enforced; may include thinking text around it).
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		if ( self::is_openai_reasoning_model( $model ) ) {
			// Keep generations fast/cheap by default; filterable for users
			// who want deeper reasoning.
			$body['reasoning_effort'] = apply_filters( 'aime_openai_reasoning_effort', 'low', $model );
		}

		return $body;
	}

	private static function generate_openai( string $api_key, string $model, string $prompt, int $max_tokens, array $options = array() ): array {
		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => self::compute_http_timeout( $max_tokens ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( self::build_openai_chat_body( $model, $prompt, $max_tokens, $options ) ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'content' => '', 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return self::http_failure( $response, $body['error']['message'] ?? __( 'OpenAI error.', 'ai-marketing-expert' ) );
		}

		$choice     = $body['choices'][0] ?? array();
		$raw_finish = (string) ( $choice['finish_reason'] ?? '' );

		// Extract content — reasoning models may return thinking in reasoning_content.
		// Prefer content over reasoning_content (content is the actual answer).
		$message = $choice['message'] ?? array();
		$text    = isset( $message['content'] ) ? (string) $message['content'] : '';

		// Fallback: if content is empty but reasoning_content exists, extract it.
		if ( '' === $text && ! empty( $message['reasoning_content'] ) ) {
			$text = (string) $message['reasoning_content'];
		}

		if ( '' === trim( $text ) ) {
			return array(
				'success' => false,
				'content' => '',
				'message' => 'content_filter' === $raw_finish
					? __( 'The model blocked this request due to its content filter.', 'ai-marketing-expert' )
					: __( 'Model returned empty content. Try a different model.', 'ai-marketing-expert' ),
			);
		}

		return self::text_result( $text, (array) $body, 'openai', $raw_finish );
	}

	private static function generate_anthropic( string $api_key, string $model, string $prompt, int $max_tokens, array $options = array() ): array {
		// Anthropic has no response_format parameter; enforce JSON via instruction.
		if ( ! empty( $options['json_mode'] ) ) {
			$prompt .= "\n\nRespond with a single valid JSON object only. No markdown, no code fences, no explanation.";
		}

		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => self::compute_http_timeout( $max_tokens ),
			'headers' => array(
				'x-api-key'        => $api_key,
				'anthropic-version' => '2023-06-01',
				'Content-Type'     => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'      => $model,
				'max_tokens' => $max_tokens,
				'messages'   => array(
					array( 'role' => 'user', 'content' => $prompt ),
				),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'content' => '', 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return self::http_failure( $response, $body['error']['message'] ?? __( 'Anthropic error.', 'ai-marketing-expert' ) );
		}

		$text = $body['content'][0]['text'] ?? '';
		return self::text_result( (string) $text, (array) $body, 'anthropic', (string) ( $body['stop_reason'] ?? '' ) );
	}

	/* ================================================================
	 *  CUSTOM PROVIDER (OpenAI-compatible / Anthropic-compatible)
	 * ============================================================= */

	/**
	 * Normalize a user-supplied base URL (trim, strip trailing slash).
	 */
	private static function normalize_base_url( string $base_url ): string {
		return rtrim( trim( $base_url ), '/' );
	}

	/**
	 * Whether private/local egress is explicitly allowed for a custom base URL.
	 *
	 * Sites running a local LLM (Ollama, LM Studio, LocalAI) can opt in:
	 *   add_filter( 'aime_allow_private_ai_base_url', '__return_true' );
	 */
	private static function allow_private_base_url( string $url ): bool {
		return (bool) apply_filters( 'aime_allow_private_ai_base_url', false, $url );
	}

	/**
	 * SSRF guard for custom-provider base URLs (audit S-3).
	 *
	 * A custom base_url is admin-supplied, but on multi-admin or managed
	 * hosting it must not become a bridge to internal services (cloud
	 * metadata endpoints, intranet APIs). Uses WordPress' own guard,
	 * wp_http_validate_url(), which blocks loopback/private hosts and
	 * non-standard ports unless `http_request_host_is_external` allows them.
	 *
	 * @param string $url Base URL to check.
	 * @return true|string True if allowed, otherwise an error message.
	 */
	private static function guard_custom_base_url( string $url ) {
		if ( self::allow_private_base_url( $url ) ) {
			return true;
		}

		$blocked = __( 'This base URL points to a private or local network address, which is blocked for security reasons. Please use a public API endpoint.', 'ai-marketing-expert' );

		if ( ! wp_http_validate_url( $url ) ) {
			return $blocked;
		}

		// wp_http_validate_url() misses link-local/reserved ranges (e.g.
		// 169.254.169.254 — cloud metadata endpoints). Resolve the host and
		// re-check with PHP's stricter private+reserved range flags.
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$ip   = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return $blocked;
		}

		return true;
	}

	/**
	 * Dispatch text generation for a custom connection based on its API format.
	 */
	private static function generate_custom( array $conn, string $api_key, string $model, string $prompt, int $max_tokens, array $options = array() ): array {
		$base_url = self::normalize_base_url( self::decrypt_maybe( $conn['base_url'] ?? '' ) );
		if ( empty( $base_url ) ) {
			return array( 'success' => false, 'content' => '', 'message' => __( 'Custom provider base URL is not configured.', 'ai-marketing-expert' ) );
		}
		$format = ( 'anthropic' === ( $conn['api_format'] ?? 'openai' ) ) ? 'anthropic' : 'openai';
		return 'anthropic' === $format
			? self::generate_custom_anthropic( $api_key, $model, $prompt, $max_tokens, $base_url, $options )
			: self::generate_custom_openai( $api_key, $model, $prompt, $max_tokens, $base_url, $options );
	}

	private static function generate_custom_openai( string $api_key, string $model, string $prompt, int $max_tokens, string $base_url, array $options = array() ): array {
		$base_url = self::normalize_base_url( $base_url );
		$guard    = self::guard_custom_base_url( $base_url );
		if ( true !== $guard ) {
			return array( 'success' => false, 'content' => '', 'message' => $guard );
		}

		$request = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'messages'   => array(
				array( 'role' => 'user', 'content' => $prompt ),
			),
		);

		if ( ! empty( $options['json_schema'] ) ) {
			$request['response_format'] = array(
				'type'        => 'json_schema',
				'json_schema' => $options['json_schema'],
			);
		} elseif ( ! empty( $options['json_mode'] ) ) {
			$request['response_format'] = array( 'type' => 'json_object' );
		}

		$response = wp_remote_post( $base_url . '/chat/completions', array(
			'timeout' => self::compute_http_timeout( $max_tokens ),
			'reject_unsafe_urls' => ! self::allow_private_base_url( $base_url ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $request ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'content' => '', 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return self::http_failure( $response, $body['error']['message'] ?? __( 'Custom provider error.', 'ai-marketing-expert' ) );
		}

		// Extract content — reasoning models (muse-spark, deepseek-r1, etc.) may
		// return thinking in a separate reasoning_content field. Prefer content
		// over reasoning_content (reasoning is internal, content is the answer).
		$message = $body['choices'][0]['message'] ?? array();
		$text    = $message['content'] ?? '';

		// Fallback: if content is empty but reasoning_content exists, some models
		// put everything in reasoning_content when json_mode is enabled.
		if ( '' === $text && ! empty( $message['reasoning_content'] ) ) {
			$text = (string) $message['reasoning_content'];
		}

		return self::text_result( (string) $text, (array) $body, 'openai', (string) ( $body['choices'][0]['finish_reason'] ?? '' ) );
	}

	private static function generate_custom_anthropic( string $api_key, string $model, string $prompt, int $max_tokens, string $base_url, array $options = array() ): array {
		$base_url = self::normalize_base_url( $base_url );
		$guard    = self::guard_custom_base_url( $base_url );
		if ( true !== $guard ) {
			return array( 'success' => false, 'content' => '', 'message' => $guard );
		}

		if ( ! empty( $options['json_mode'] ) ) {
			$prompt .= "\n\nRespond with a single valid JSON object only. No markdown, no code fences, no explanation.";
		}

		$response = wp_remote_post( $base_url . '/messages', array(
			'timeout' => self::compute_http_timeout( $max_tokens ),
			'reject_unsafe_urls' => ! self::allow_private_base_url( $base_url ),
			'headers' => array(
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
				'Content-Type'      => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'      => $model,
				'max_tokens' => $max_tokens,
				'messages'   => array(
					array( 'role' => 'user', 'content' => $prompt ),
				),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'content' => '', 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return self::http_failure( $response, $body['error']['message'] ?? __( 'Custom provider error.', 'ai-marketing-expert' ) );
		}

		$text = $body['content'][0]['text'] ?? '';
		return self::text_result( (string) $text, (array) $body, 'anthropic', (string) ( $body['stop_reason'] ?? '' ) );
	}

	private static function generate_image_custom_openai( string $api_key, string $model, string $prompt, string $base_url ): array {
		$base_url = self::normalize_base_url( $base_url );
		if ( empty( $base_url ) ) {
			return array( 'success' => false, 'message' => __( 'Custom provider base URL is not configured.', 'ai-marketing-expert' ) );
		}
		$guard = self::guard_custom_base_url( $base_url );
		if ( true !== $guard ) {
			return array( 'success' => false, 'message' => $guard );
		}

		$response = wp_remote_post( $base_url . '/images/generations', array(
			'timeout' => 120,
			'reject_unsafe_urls' => ! self::allow_private_base_url( $base_url ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'           => $model,
				'prompt'          => $prompt,
				'n'               => 1,
				'size'            => '1024x1024',
				'response_format' => 'b64_json',
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return self::http_failure( $response, $body['error']['message'] ?? "Custom provider error (HTTP {$code})", false );
		}

		$b64 = $body['data'][0]['b64_json'] ?? '';
		if ( empty( $b64 ) ) {
			return array( 'success' => false, 'message' => __( 'Custom provider returned no image data.', 'ai-marketing-expert' ) );
		}

		return array(
			'success'    => true,
			'image_data' => base64_decode( $b64 ),
			'mime_type'  => 'image/png',
		);
	}

	/**
	 * Test a custom connection. Resolves the connection's text model and pings the endpoint.
	 */
	private static function test_custom( array $conn, string $api_key ): array {
		$base_url = self::normalize_base_url( self::decrypt_maybe( $conn['base_url'] ?? '' ) );
		if ( empty( $base_url ) ) {
			return array( 'success' => false, 'message' => __( 'Enter a base URL before testing.', 'ai-marketing-expert' ) );
		}

		$model = $conn['text_model'] ?? '';
		if ( 'custom' === $model ) {
			$model = $conn['custom_text_model'] ?? '';
		}
		if ( empty( $model ) ) {
			return array( 'success' => false, 'message' => __( 'Select a main model before testing.', 'ai-marketing-expert' ) );
		}

		$result = ( 'anthropic' === ( $conn['api_format'] ?? 'openai' ) )
			? self::generate_custom_anthropic( $api_key, $model, 'Hi', 10, $base_url )
			: self::generate_custom_openai( $api_key, $model, 'Hi', 10, $base_url );

		if ( ! empty( $result['success'] ) ) {
			return array( 'success' => true, 'message' => __( 'Custom provider connected successfully!', 'ai-marketing-expert' ) );
		}
		return array( 'success' => false, 'message' => $result['message'] ?? __( 'Connection failed.', 'ai-marketing-expert' ) );
	}

	/**
	 * Fetch available models from a custom endpoint's /models route.
	 */
	private static function fetch_custom_models( string $api_format, string $api_key, string $base_url ): array {
		$base_url = self::normalize_base_url( $base_url );
		if ( empty( $base_url ) ) {
			return array( 'success' => false, 'message' => __( 'Enter a base URL before fetching models.', 'ai-marketing-expert' ) );
		}
		$guard = self::guard_custom_base_url( $base_url );
		if ( true !== $guard ) {
			return array( 'success' => false, 'message' => $guard );
		}

		$headers = ( 'anthropic' === $api_format )
			? array( 'x-api-key' => $api_key, 'anthropic-version' => '2023-06-01' )
			: array( 'Authorization' => 'Bearer ' . $api_key );

		$response = wp_remote_get( $base_url . '/models', array(
			'timeout' => 20,
			'reject_unsafe_urls' => ! self::allow_private_base_url( $base_url ),
			'headers' => $headers,
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return array( 'success' => false, 'message' => $body['error']['message'] ?? __( 'Failed to fetch models.', 'ai-marketing-expert' ) );
		}

		$text_models  = array();
		$image_models = array();

		foreach ( $body['data'] ?? array() as $m ) {
			$id = $m['id'] ?? '';
			if ( empty( $id ) ) {
				continue;
			}
			$entry = array( 'id' => $id, 'name' => $id, 'created' => $m['created'] ?? 0 );
			if ( preg_match( '/(image|dall-e|imagen|sdxl|stable-diffusion|flux)/i', $id ) ) {
				$entry['type']  = 'image';
				$image_models[] = $entry;
			} else {
				$entry['type'] = 'text';
				$text_models[] = $entry;
			}
		}

		usort( $text_models, fn( $a, $b ) => ( $b['created'] ?? 0 ) <=> ( $a['created'] ?? 0 ) );
		usort( $image_models, fn( $a, $b ) => ( $b['created'] ?? 0 ) <=> ( $a['created'] ?? 0 ) );

		return array(
			'success' => true,
			'models'  => array( 'text' => array_values( $text_models ), 'image' => array_values( $image_models ) ),
		);
	}

	/* ================================================================
	 *  LEGACY COMPAT — keep old get_api_key working
	 * ============================================================= */

	/**
	 * Get decrypted API key for the primary connection of a given provider.
	 *
	 * @param string $provider_id Provider ID.
	 * @return string Decrypted API key.
	 */
	public static function get_api_key( string $provider_id ): string {
		$connections = self::get_connections();

		foreach ( $connections as $conn ) {
			if ( $conn['provider'] === $provider_id && ! empty( $conn['enabled'] ) && ! empty( $conn['api_key'] ) ) {
				return Encryption::decrypt( $conn['api_key'] );
			}
		}

		return '';
	}
}
