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
	 * Process incoming bounce emails from the configured IMAP mailbox.
	 *
	 * @return array { processed: int, bounced: int, errors: array }
	 */
	public static function process_mailbox(): array {
		$result = array(
			'processed' => 0,
			'bounced'   => 0,
			'errors'    => array(),
		);

		if ( ! function_exists( 'imap_open' ) ) {
			$result['errors'][] = __( 'PHP IMAP extension is not installed or enabled on this server.', 'ai-marketing-expert' );
			return $result;
		}

		$settings = get_option( 'aime_bounce_imap_settings', array() );
		if ( empty( $settings['enabled'] ) || empty( $settings['host'] ) || empty( $settings['username'] ) ) {
			return $result;
		}

		$host       = sanitize_text_field( $settings['host'] );
		$port       = absint( $settings['port'] ?? 993 );
		$encryption = sanitize_text_field( $settings['encryption'] ?? 'ssl' );
		$username   = sanitize_text_field( $settings['username'] );
		$password   = ! empty( $settings['password'] ) ? Encryption::decrypt( $settings['password'] ) : '';

		if ( empty( $password ) ) {
			$result['errors'][] = __( 'IMAP password is missing or cannot be decrypted.', 'ai-marketing-expert' );
			return $result;
		}

		// Build connection string.
		$flags = '/imap';
		if ( 'ssl' === $encryption ) {
			$flags .= '/ssl/novalidate-cert';
		} elseif ( 'tls' === $encryption ) {
			$flags .= '/tls/novalidate-cert';
		} else {
			$flags .= '/notls';
		}

		$mailbox_string = sprintf( '{%s:%d%s}INBOX', $host, $port, $flags );

		// Suppress warnings from imap_open with error handler.
		imap_timeout( 1, 15 );
		imap_timeout( 2, 15 );

		$inbox = @imap_open( $mailbox_string, $username, $password, 0, 1 );
		if ( ! $inbox ) {
			$errors  = imap_errors();
			$err_msg = is_array( $errors ) ? implode( '; ', $errors ) : __( 'Unknown connection error.', 'ai-marketing-expert' );
			$result['errors'][] = sprintf( 'IMAP connection failed: %s', $err_msg );
			return $result;
		}

		// Search for unread bounce messages.
		$emails = imap_search( $inbox, 'UNSEEN' );
		if ( empty( $emails ) ) {
			imap_close( $inbox );
			return $result;
		}

		$delete_after = ! empty( $settings['delete_after_process'] );
		$batch_limit  = 100;
		$count        = 0;

		foreach ( $emails as $msg_num ) {
			if ( $count >= $batch_limit ) {
				break;
			}
			$count++;
			$result['processed']++;

			$header = imap_headerinfo( $inbox, $msg_num );
			$body   = imap_body( $inbox, $msg_num );

			$failed_email = self::extract_failed_recipient( $header, $body );
			if ( $failed_email && is_email( $failed_email ) ) {
				SmtpProvider::record_hard_bounce( $failed_email, 'Automated IMAP bounce mailbox detection' );
				$result['bounced']++;
			}

			// Mark as seen or delete.
			if ( $delete_after ) {
				imap_delete( $inbox, $msg_num );
			} else {
				imap_setflag_full( $inbox, (string) $msg_num, '\\Seen' );
			}
		}

		imap_close( $inbox, $delete_after ? CL_EXPUNGE : 0 );

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
		if ( ! function_exists( 'imap_open' ) ) {
			return array(
				'success' => false,
				'message' => __( 'PHP IMAP extension is not installed or enabled on your server.', 'ai-marketing-expert' ),
			);
		}

		$host       = sanitize_text_field( $settings['host'] ?? '' );
		$port       = absint( $settings['port'] ?? 993 );
		$encryption = sanitize_text_field( $settings['encryption'] ?? 'ssl' );
		$username   = sanitize_text_field( $settings['username'] ?? '' );
		$password   = sanitize_text_field( $settings['password'] ?? '' );

		if ( empty( $host ) || empty( $username ) || empty( $password ) ) {
			return array(
				'success' => false,
				'message' => __( 'Host, username, and password are required.', 'ai-marketing-expert' ),
			);
		}

		$flags = '/imap';
		if ( 'ssl' === $encryption ) {
			$flags .= '/ssl/novalidate-cert';
		} elseif ( 'tls' === $encryption ) {
			$flags .= '/tls/novalidate-cert';
		} else {
			$flags .= '/notls';
		}

		$mailbox_string = sprintf( '{%s:%d%s}INBOX', $host, $port, $flags );

		imap_timeout( 1, 10 );
		imap_timeout( 2, 10 );

		$inbox = @imap_open( $mailbox_string, $username, $password, 0, 1 );
		if ( ! $inbox ) {
			$errors  = imap_errors();
			$err_msg = is_array( $errors ) ? implode( '; ', $errors ) : __( 'Connection failed. Please check host, port, credentials, and SSL settings.', 'ai-marketing-expert' );
			return array(
				'success' => false,
				'message' => $err_msg,
			);
		}

		$check = imap_check( $inbox );
		$msg_count = $check ? $check->Nmsgs : 0;
		imap_close( $inbox );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of messages in inbox */
				__( 'Connected successfully! Found %d messages in INBOX.', 'ai-marketing-expert' ),
				$msg_count
			),
		);
	}
}
