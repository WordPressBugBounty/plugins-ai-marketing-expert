<?php
/**
 * Encryption utility for API keys and sensitive data.
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Encryption {

	/**
	 * Current encryption method — AES-256-GCM (authenticated encryption).
	 * The GCM auth tag makes ciphertext tamper-evident: a bit-flipped value
	 * fails decryption instead of silently producing garbage.
	 */
	private const METHOD = 'aes-256-gcm';

	/**
	 * Legacy (pre-v3) encryption method, kept for decrypting existing rows.
	 */
	private const LEGACY_METHOD = 'aes-256-cbc';

	/**
	 * GCM auth tag length in bytes.
	 */
	private const TAG_LENGTH = 16;

	/**
	 * Prefix on every freshly-encrypted value (v3 = AES-256-GCM).
	 * Decryption also accepts the older 'v2:' (AES-256-CBC) format and the
	 * legacy un-prefixed CBC format, so existing rows continue to decrypt.
	 */
	private const CIPHERTEXT_PREFIX = 'v3:';

	/**
	 * Prefix used by the previous CBC format.
	 */
	private const LEGACY_PREFIX = 'v2:';

	/**
	 * Get the encryption key derived from WordPress salts.
	 *
	 * @return string
	 */
	private static function get_key(): string {
		$salt = ( defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ) ? AUTH_KEY : self::get_fallback_secret( 'key' );
		return hash( 'sha256', $salt . 'aime_encryption', true );
	}

	/**
	 * Get a persistent, per-site fallback secret.
	 *
	 * Used only when the AUTH_KEY salt is unavailable, so encryption never
	 * relies on a publicly-known default string. Limitation: the secret lives
	 * in the same database as the ciphertext, so for a DB-dump attacker this
	 * is obfuscation, not encryption — define real salts in wp-config.php.
	 *
	 * @param string $suffix Secret identifier.
	 * @return string
	 */
	private static function get_fallback_secret( string $suffix = 'key' ): string {
		$option = 'aime_enc_fallback_' . $suffix;
		$secret = get_option( $option );
		if ( empty( $secret ) ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( $option, $secret, false );
		}
		return (string) $secret;
	}

	/**
	 * Encrypt a value.
	 *
	 * Output format: 'v3:' + base64( random_iv . auth_tag . ciphertext ) using
	 * AES-256-GCM. The prefix lets future migrations distinguish ciphertext
	 * versions without losing access to data written by older code.
	 *
	 * @param string $value Plain text value.
	 * @return string Encoded ciphertext.
	 */
	public static function encrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$iv        = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::METHOD ) );
		$tag       = '';
		$encrypted = openssl_encrypt(
			$value,
			self::METHOD,
			self::get_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LENGTH
		);

		if ( false === $encrypted ) {
			return '';
		}

		return self::CIPHERTEXT_PREFIX . base64_encode( $iv . $tag . $encrypted );
	}

	/**
	 * Decrypt a value.
	 *
	 * Accepts the current 'v3:' GCM format and, for backward compatibility,
	 * the older 'v2:' CBC format plus the legacy un-prefixed CBC format
	 * produced before the version tag was added. The fallbacks exist only so
	 * existing rows continue to decrypt; new code should always call
	 * encrypt() above (values re-saved by the user are upgraded to v3).
	 *
	 * @param string $value Encoded ciphertext.
	 * @return string Decrypted plain text value, or '' on failure.
	 */
	public static function decrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		// Current format: v3:base64( iv . tag . ciphertext ), AES-256-GCM.
		if ( 0 === strpos( $value, self::CIPHERTEXT_PREFIX ) ) {
			$payload = base64_decode( substr( $value, strlen( self::CIPHERTEXT_PREFIX ) ), true );
			if ( ! is_string( $payload ) || '' === $payload ) {
				return '';
			}

			$iv_len = openssl_cipher_iv_length( self::METHOD );
			if ( strlen( $payload ) < $iv_len + self::TAG_LENGTH ) {
				return '';
			}

			$iv  = substr( $payload, 0, $iv_len );
			$tag = substr( $payload, $iv_len, self::TAG_LENGTH );
			$raw = substr( $payload, $iv_len + self::TAG_LENGTH );

			$result = openssl_decrypt( $raw, self::METHOD, self::get_key(), OPENSSL_RAW_DATA, $iv, $tag );
			return false !== $result ? $result : '';
		}

		// Older format: v2:base64( iv . ciphertext ), AES-256-CBC.
		if ( 0 === strpos( $value, self::LEGACY_PREFIX ) ) {
			return self::decrypt_cbc( substr( $value, strlen( self::LEGACY_PREFIX ) ) );
		}

		// Legacy format: base64( iv . ciphertext ), CBC, no prefix.
		return self::decrypt_cbc( $value );
	}

	/**
	 * Decrypt a legacy AES-256-CBC payload (v2 or un-prefixed).
	 *
	 * @param string $encoded Base64 payload without version prefix.
	 * @return string Decrypted value, or '' on failure.
	 */
	private static function decrypt_cbc( string $encoded ): string {
		$decoded = base64_decode( $encoded, true );
		if ( ! is_string( $decoded ) || '' === $decoded ) {
			return '';
		}

		$iv_len = openssl_cipher_iv_length( self::LEGACY_METHOD );
		if ( strlen( $decoded ) < $iv_len ) {
			return '';
		}

		$iv  = substr( $decoded, 0, $iv_len );
		$raw = substr( $decoded, $iv_len );

		$result = openssl_decrypt( $raw, self::LEGACY_METHOD, self::get_key(), OPENSSL_RAW_DATA, $iv );
		return false !== $result ? $result : '';
	}
}
