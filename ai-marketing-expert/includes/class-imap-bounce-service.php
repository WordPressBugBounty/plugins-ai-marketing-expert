<?php
/**
 * IMAP Bounce Mailbox Service — reads and parses bounce emails (NDRs)
 * from a dedicated mailbox (e.g. bounces@yourdomain.com).
 *
 * Runs on the `aime_process_bounce_mailbox` cron hook or manual trigger.
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImapBounceService {

	/**
	 * Process incoming bounce emails across all configured IMAP mailboxes (per-connection + legacy).
	 * Pro-only automated bounce scanner.
	 *
	 * @return array { processed: int, bounced: int, errors: array }
	 */
	public static function process_mailbox(): array {
		return self::process_all_mailboxes();
	}

	/**
	 * Process all active IMAP bounce mailboxes across all SMTP connections.
	 *
	 * @return array { processed: int, bounced: int, errors: array }
	 */
	public static function process_all_mailboxes(): array {
		$result = array(
			'processed' => 0,
			'bounced'   => 0,
			'errors'    => array(),
		);

		$is_pro          = aime_has_pro();
		$processed_count = 0;
		$all_start_time  = microtime( true );
		$max_total_time  = 18; // Maximum 18 seconds across all mailboxes to ensure fast web response

		// 1. Process per-connection IMAP settings from active SMTP connections.
		$connections = SmtpProvider::get_connections();
		foreach ( $connections as $conn ) {
			if ( ( microtime( true ) - $all_start_time ) > $max_total_time ) {
				break;
			}

			if ( empty( $conn['enabled'] ) || empty( $conn['bounce_imap']['enabled'] ) ) {
				continue;
			}

			// In Free tier, limit to 1 primary sending mailbox. Pro users get unlimited multi-account mailboxes.
			if ( ! $is_pro && $processed_count >= 1 ) {
				break;
			}

			$imap_cfg = $conn['bounce_imap'];
			if ( empty( $imap_cfg['host'] ) || empty( $imap_cfg['username'] ) ) {
				continue;
			}

			$sub_res = self::process_single_mailbox( $imap_cfg, 10 );
			$result['processed'] += $sub_res['processed'];
			$result['bounced']   += $sub_res['bounced'];
			if ( ! empty( $sub_res['errors'] ) ) {
				$result['errors'] = array_merge( $result['errors'], $sub_res['errors'] );
			}
			$processed_count++;
		}

		// 2. Backward compatibility: Process legacy global bounce IMAP settings if configured.
		$legacy_settings = get_option( 'aime_bounce_imap_settings', array() );
		if ( ( microtime( true ) - $all_start_time ) <= $max_total_time && ( $is_pro || 0 === $processed_count ) && ! empty( $legacy_settings['enabled'] ) && ! empty( $legacy_settings['host'] ) && ! empty( $legacy_settings['username'] ) ) {
			$sub_res = self::process_single_mailbox( $legacy_settings, 10 );
			$result['processed'] += $sub_res['processed'];
			$result['bounced']   += $sub_res['bounced'];
			if ( ! empty( $sub_res['errors'] ) ) {
				$result['errors'] = array_merge( $result['errors'], $sub_res['errors'] );
			}
		}

		return $result;
	}

	/**
	 * Process a single IMAP mailbox configuration.
	 *
	 * @param array $settings    IMAP settings array.
	 * @param int   $max_seconds Maximum seconds budget for this mailbox scan.
	 * @return array { processed: int, bounced: int, errors: array }
	 */
	public static function process_single_mailbox( array $settings, int $max_seconds = 10 ): array {
		$result = array(
			'processed' => 0,
			'bounced'   => 0,
			'errors'    => array(),
		);

		if ( empty( $settings['host'] ) || empty( $settings['username'] ) ) {
			return $result;
		}

		$host       = sanitize_text_field( $settings['host'] );
		$port       = absint( $settings['port'] ?? 993 );
		$encryption = sanitize_text_field( $settings['encryption'] ?? 'ssl' );
		$username   = sanitize_text_field( $settings['username'] );
		$password   = ! empty( $settings['password'] ) ? Encryption::decrypt( $settings['password'] ) : '';

		if ( empty( $password ) ) {
			$result['errors'][] = sprintf( __( 'IMAP password for %s is missing or cannot be decrypted.', 'ai-marketing-expert' ), $username );
			return $result;
		}

		try {
			$client = new PureImapClient();
			$conn   = $client->connect( $host, $port, $encryption );
			if ( is_wp_error( $conn ) ) {
				$result['errors'][] = sprintf( '[%s] %s', $username, $conn->get_error_message() );
				return $result;
			}

			$login = $client->login( $username, $password );
			if ( is_wp_error( $login ) ) {
				$client->disconnect();
				$result['errors'][] = sprintf( '[%s] %s', $username, $login->get_error_message() );
				return $result;
			}

			$client->select_mailbox( 'INBOX' );
			$emails = $client->search( 'UNSEEN' );

			if ( is_wp_error( $emails ) || empty( $emails ) ) {
				$client->disconnect();
				return $result;
			}

			$delete_after = ! empty( $settings['delete_after_process'] );
			$batch_limit  = 25;
			$count        = 0;
			$start_time   = microtime( true );

			foreach ( $emails as $msg_num ) {
				if ( $count >= $batch_limit || ( microtime( true ) - $start_time ) > $max_seconds ) {
					break;
				}
				$count++;
				$result['processed']++;

				$msg_data = $client->fetch_message( (int) $msg_num );
				if ( is_wp_error( $msg_data ) ) {
					continue;
				}

				$raw_body     = (string) ( $msg_data['raw'] ?? '' );
				$failed_email = self::extract_failed_recipient( null, $raw_body );

				if ( $failed_email && is_email( $failed_email ) ) {
					SmtpProvider::record_hard_bounce( $failed_email, 'Automated IMAP bounce mailbox detection' );
					$result['bounced']++;

					if ( $delete_after ) {
						$client->delete_message( (int) $msg_num );
					} else {
						$client->mark_seen( (int) $msg_num );
					}
				}
			}

			$client->disconnect();
		} catch ( \Throwable $e ) {
			$result['errors'][] = sprintf( '[%s] %s', $username, $e->getMessage() );
		}

		return $result;
	}

	/**
	 * Extract failed recipient email from bounce notice headers and RFC 3464 body.
	 *
	 * @param object|false $header Header object.
	 * @param string       $body   Raw message body.
	 * @return string|null
	 */
	public static function extract_failed_recipient( $header, string $body ): ?string {
		// 1. Standard RFC 3464: Final-Recipient: rfc822; user@domain.com
		if ( preg_match( '/Final-Recipient:\s*rfc822;\s*([^\s;<>]+)/i', $body, $matches ) ) {
			return strtolower( trim( $matches[1] ) );
		}

		// 2. Original-Recipient: rfc822; user@domain.com
		if ( preg_match( '/Original-Recipient:\s*rfc822;\s*([^\s;<>]+)/i', $body, $matches ) ) {
			return strtolower( trim( $matches[1] ) );
		}

		// 3. Postfix / Exim DSN pattern: <user@domain.com>: host ... said: 550 ...
		if ( preg_match( '/<([^@<\s]+@[^@>\s]+)>:\s*(?:host\b|[0-9]{3}|User unknown|Recipient address rejected)/i', $body, $matches ) ) {
			return strtolower( trim( $matches[1] ) );
		}

		// 4. Failed-Recipients header or body line
		if ( preg_match( '/(?:Failed-Recipient|Diagnostic-Code|To):\s*<?([^@<>\s]+@[^@<>\s]+)>?/i', $body, $matches ) ) {
			return strtolower( trim( $matches[1] ) );
		}

		return null;
	}

	/**
	 * Test connection to the IMAP mailbox.
	 *
	 * @param array $settings IMAP settings array.
	 * @return array { success: bool, message: string }
	 */
	public static function test_connection( array $settings ): array {
		return PureImapClient::test_connection( $settings );
	}
}
