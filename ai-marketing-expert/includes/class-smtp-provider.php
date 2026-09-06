<?php
/**
 * SMTP Provider — Multi-connection SMTP with fallback support.
 *
 * Supports Gmail, Outlook/Microsoft 365, Amazon SES, SendGrid,
 * Mailgun, SparkPost, custom SMTP, and WordPress default wp_mail.
 * Multiple connections can be configured — one primary and one or more fallbacks.
 *

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SmtpProvider {

	/**
	 * Option key for SMTP connections.
	 */
	const OPTION_KEY = 'aime_smtp_connections';
	const DEFAULT_DAILY_LIMIT = 90;

	/**
	 * Stable marker stored in aime_campaign_emails.note when a queued email is
	 * waiting because every SMTP connection has hit its daily limit. Used as a
	 * single source of truth so producers and consumers stay in sync regardless
	 * of translation/UI changes.
	 */
	const LIMIT_WAIT_NOTE = 'SMTP daily sending limit reached. Waiting for fallback availability.';
	const USAGE_OPTION_KEY = 'aime_smtp_connection_usage';
	const SITE_MAIL_OPTION_KEY = 'aime_smtp_site_mail_enabled';
	const LAST_SITE_MAIL_ERROR_OPTION_KEY = 'aime_smtp_last_site_mail_error';
	const SITE_MAIL_ERROR_LOG_LIMIT = 50;
	const PASSWORD_MASK = '********************';

	/**
	 * Original sender values seen before site-wide SMTP normalization.
	 *
	 * @var array{email:string,name:string}
	 */
	private static array $site_mail_original_from = array(
		'email' => '',
		'name'  => '',
	);

	/**
	 * Available provider presets.
	 *
	 * @return array
	 */
	public static function get_providers(): array {
		return array(
			'wp_mail'    => array(
				'name'        => __( 'WordPress Default (wp_mail)', 'ai-marketing-expert' ),
				'description' => __( 'Uses your server\'s default mail configuration.', 'ai-marketing-expert' ),
				'fields'      => array(),
			),
			'gmail'      => array(
				'name'        => __( 'Gmail / Google Workspace', 'ai-marketing-expert' ),
				'description' => __( 'Send via Gmail SMTP. Requires an App Password (2FA must be enabled).', 'ai-marketing-expert' ),
				'fields'      => array( 'smtp_username', 'smtp_password' ),
				'host'        => 'smtp.gmail.com',
				'port'        => 587,
				'encryption'  => 'tls',
				'docs_url'    => 'https://support.google.com/accounts/answer/185833',
			),
			'outlook'    => array(
				'name'        => __( 'Outlook / Microsoft 365', 'ai-marketing-expert' ),
				'description' => __( 'Send via Outlook.com, Hotmail, or Microsoft 365 SMTP.', 'ai-marketing-expert' ),
				'fields'      => array( 'smtp_username', 'smtp_password' ),
				'host'        => 'smtp-mail.outlook.com',
				'port'        => 587,
				'encryption'  => 'tls',
				'docs_url'    => 'https://support.microsoft.com/en-us/office/pop-imap-and-smtp-settings-for-outlook-com-d088b986-291d-42b8-9564-9c414e2aa040',
			),
			'amazon_ses' => array(
				'name'        => __( 'Amazon SES', 'ai-marketing-expert' ),
				'description' => __( 'High-volume sending via Amazon Simple Email Service.', 'ai-marketing-expert' ),
				'fields'      => array( 'smtp_host', 'smtp_username', 'smtp_password' ),
				'host'        => 'email-smtp.us-east-1.amazonaws.com',
				'port'        => 587,
				'encryption'  => 'tls',
				'docs_url'    => 'https://docs.aws.amazon.com/ses/latest/dg/smtp-credentials.html',
			),
			'sendgrid'   => array(
				'name'        => __( 'SendGrid', 'ai-marketing-expert' ),
				'description' => __( 'Send via SendGrid SMTP relay.', 'ai-marketing-expert' ),
				'fields'      => array( 'smtp_password' ),
				'host'        => 'smtp.sendgrid.net',
				'port'        => 587,
				'encryption'  => 'tls',
				'username'    => 'apikey',
				'docs_url'    => 'https://docs.sendgrid.com/for-developers/sending-email/integrationguide',
			),
			'mailgun'    => array(
				'name'        => __( 'Mailgun', 'ai-marketing-expert' ),
				'description' => __( 'Send via Mailgun SMTP.', 'ai-marketing-expert' ),
				'fields'      => array( 'smtp_host', 'smtp_username', 'smtp_password' ),
				'host'        => 'smtp.mailgun.org',
				'port'        => 587,
				'encryption'  => 'tls',
				'docs_url'    => 'https://documentation.mailgun.com/en/latest/quickstart-sending.html',
			),
			'sparkpost'  => array(
				'name'        => __( 'SparkPost', 'ai-marketing-expert' ),
				'description' => __( 'Send via SparkPost SMTP.', 'ai-marketing-expert' ),
				'fields'      => array( 'smtp_password' ),
				'host'        => 'smtp.sparkpostmail.com',
				'port'        => 587,
				'encryption'  => 'tls',
				'username'    => 'SMTP_Injection',
				'docs_url'    => 'https://developers.sparkpost.com/api/smtp/',
			),
			'brevo'      => array(
				'name'        => __( 'Brevo (Sendinblue)', 'ai-marketing-expert' ),
				'description' => __( 'Send via Brevo (formerly Sendinblue) SMTP relay.', 'ai-marketing-expert' ),
				'fields'      => array( 'smtp_username', 'smtp_password' ),
				'host'        => 'smtp-relay.brevo.com',
				'port'        => 587,
				'encryption'  => 'tls',
				'docs_url'    => 'https://developers.brevo.com/docs/send-a-transactional-email',
			),
			'sendlayer'  => array(
				'name'        => __( 'SendLayer', 'ai-marketing-expert' ),
				'description' => __( 'Send via SendLayer SMTP relay.', 'ai-marketing-expert' ),
				'fields'      => array( 'smtp_username', 'smtp_password' ),
				'host'        => 'smtp.sendlayer.net',
				'port'        => 587,
				'encryption'  => 'tls',
				'docs_url'    => 'https://sendlayer.com/docs/',
			),
			'smtpcom'    => array(
				'name'        => __( 'SMTP.com', 'ai-marketing-expert' ),
				'description' => __( 'Send via SMTP.com relay service.', 'ai-marketing-expert' ),
				'fields'      => array( 'smtp_username', 'smtp_password' ),
				'host'        => 'send.smtp.com',
				'port'        => 587,
				'encryption'  => 'tls',
				'docs_url'    => 'https://www.smtp.com/resources/',
			),
			'postmark'   => array(
				'name'        => __( 'Postmark', 'ai-marketing-expert' ),
				'description' => __( 'Send via Postmark SMTP. Use your Server API Token as both username and password.', 'ai-marketing-expert' ),
				'fields'      => array( 'smtp_username', 'smtp_password' ),
				'host'        => 'smtp.postmarkapp.com',
				'port'        => 587,
				'encryption'  => 'tls',
				'docs_url'    => 'https://postmarkapp.com/developer/user-guide/send-email-with-smtp',
			),
			'resend'     => array(
				'name'        => __( 'Resend', 'ai-marketing-expert' ),
				'description' => __( 'Send via Resend SMTP. Use "resend" as username and your API key as password.', 'ai-marketing-expert' ),
				'fields'      => array( 'smtp_password' ),
				'host'        => 'smtp.resend.com',
				'port'        => 465,
				'encryption'  => 'ssl',
				'username'    => 'resend',
				'docs_url'    => 'https://resend.com/docs/send-with-smtp',
			),
			'custom'     => array(
				'name'        => __( 'Custom SMTP', 'ai-marketing-expert' ),
				'description' => __( 'Configure any SMTP server manually.', 'ai-marketing-expert' ),
				'fields'      => array( 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password' ),
			),
		);
	}

	/* ================================================================
	 *  CONNECTIONS CRUD
	 * ============================================================= */

	/**
	 * Get all SMTP connections.
	 *
	 * @return array
	 */
	public static function get_connections(): array {
		$connections = \aime_get_db_option( self::OPTION_KEY, array() );

		if ( empty( $connections ) ) {
			$connections = self::maybe_migrate_old();
		}

		return self::sort_connections( self::normalize_connections( $connections ) );
	}

	/**
	 * Normalize SMTP connection ordering.
	 *
	 * @param array $connections Stored connections.
	 * @return array
	 */
	private static function normalize_connections( array $connections ): array {
		$sorted = array_values( $connections );

		usort(
			$sorted,
			static function ( array $a, array $b ): int {
				$a_order = isset( $a['sort_order'] ) ? absint( $a['sort_order'] ) : PHP_INT_MAX;
				$b_order = isset( $b['sort_order'] ) ? absint( $b['sort_order'] ) : PHP_INT_MAX;

				if ( $a_order === $b_order ) {
					return 0;
				}

				return $a_order < $b_order ? -1 : 1;
			}
		);

		foreach ( $sorted as $index => &$connection ) {
			$connection['sort_order'] = $index;
		}
		unset( $connection );

		return $sorted;
	}

	/**
	 * Assign sequential sort_order values using the current array order.
	 *
	 * @param array $connections Connections in the desired order.
	 * @return array
	 */
	private static function reindex_connections( array $connections ): array {
		$connections = array_values( $connections );

		foreach ( $connections as $index => &$connection ) {
			$connection['sort_order'] = $index;
		}
		unset( $connection );

		return $connections;
	}

	/**
	 * Sort connections for UI and sending.
	 *
	 * @param array $connections Connections.
	 * @return array
	 */
	private static function sort_connections( array $connections ): array {
		$connections = array_values( $connections );

		usort(
			$connections,
			static function ( array $a, array $b ): int {
				$a_primary = ! empty( $a['is_primary'] );
				$b_primary = ! empty( $b['is_primary'] );

				if ( $a_primary !== $b_primary ) {
					return $a_primary ? -1 : 1;
				}

				$a_order = absint( $a['sort_order'] ?? 0 );
				$b_order = absint( $b['sort_order'] ?? 0 );

				if ( $a_order === $b_order ) {
					return 0;
				}

				return $a_order < $b_order ? -1 : 1;
			}
		);

		return $connections;
	}

	/**
	 * Get connections with passwords masked (for API responses).
	 *
	 * @return array
	 */
	public static function get_connections_for_api(): array {
		return array_map( function ( $c ) {
			$usage = self::get_connection_usage( $c );
			$c['has_password'] = ! empty( $c['smtp_password'] );
			$c['smtp_password'] = $c['has_password'] ? self::PASSWORD_MASK : '';
			$c['sending_limit'] = max( 1, absint( $c['sending_limit'] ?? self::DEFAULT_DAILY_LIMIT ) );
			$c['sent_last_24h'] = $usage['count'];
			$c['limit_reached'] = $usage['count'] >= $c['sending_limit'];
			$c['limit_reset_at'] = $usage['reset_at'];
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
		$id          = ! empty( $data['id'] ) ? sanitize_key( $data['id'] ) : 'conn_' . substr( md5( wp_generate_uuid4() ), 0, 10 );
		$existing    = self::find_connection( $connections, $id );

		if ( $existing ) {
			$data = array_merge( $existing, $data );
		}

		$conn = array(
			'id'              => $id,
			'name'            => sanitize_text_field( $data['name'] ?? '' ),
			'provider'        => sanitize_key( $data['provider'] ?? 'wp_mail' ),
			'smtp_host'         => sanitize_text_field( $data['smtp_host'] ?? '' ),
			'smtp_account_type' => in_array( $data['smtp_account_type'] ?? '', array( 'personal', 'business' ), true )
				? $data['smtp_account_type'] : '',
			'smtp_port'         => absint( $data['smtp_port'] ?? 587 ),
			'smtp_encryption' => in_array( $data['smtp_encryption'] ?? 'tls', array( 'none', 'ssl', 'tls' ), true )
				? $data['smtp_encryption'] : 'tls',
			'smtp_username'   => sanitize_text_field( $data['smtp_username'] ?? '' ),
			'from_name'       => sanitize_text_field( $data['from_name'] ?? '' ),
			'from_email'      => sanitize_email( $data['from_email'] ?? '' ),
			'sending_limit'   => max( 1, absint( $data['sending_limit'] ?? self::DEFAULT_DAILY_LIMIT ) ),
			'sort_order'      => isset( $data['sort_order'] ) ? absint( $data['sort_order'] ) : absint( $existing['sort_order'] ?? count( $connections ) ),
			'is_primary'      => (bool) ( $data['is_primary'] ?? false ),
			'enabled'         => (bool) ( $data['enabled'] ?? true ),
		);

		// Password handling — encrypt if new, keep old if editing and not changed.
		$password = isset( $data['smtp_password'] ) ? (string) $data['smtp_password'] : '';

		if ( self::PASSWORD_MASK === $password && $existing ) {
			$conn['smtp_password'] = $existing['smtp_password'] ?? '';
		} elseif ( '' !== $password ) {
			if ( class_exists( __NAMESPACE__ . '\\Encryption' ) ) {
				$conn['smtp_password'] = Encryption::encrypt( $password );
			} else {
				$conn['smtp_password'] = $password;
			}
		} else {
			$conn['smtp_password'] = $existing ? ( $existing['smtp_password'] ?? '' ) : '';
		}

		// If setting this as primary, clear other primaries.
		if ( $conn['is_primary'] ) {
			foreach ( $connections as &$c ) {
				$c['is_primary'] = false;
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
			if ( empty( $connections ) ) {
				$conn['is_primary'] = true;
			}
			$connections[] = $conn;
		}

		$connections = self::normalize_connections( $connections );
		$connections = self::sort_connections( $connections );

		if ( ! self::persist_connections( $connections, $id ) ) {
			throw new \RuntimeException( esc_html__( 'SMTP connection could not be saved. Please check database permissions or object cache on this site.', 'ai-marketing-expert' ) );
		}

		self::sync_legacy_settings( $connections );
		self::release_limit_waiting_emails();
		return $conn;
	}

	/**
	 * Persist SMTP connections and verify the requested connection is readable.
	 *
	 * @param array  $connections Connections to store.
	 * @param string $expected_id  Connection ID expected after save.
	 * @return bool
	 */
	private static function persist_connections( array $connections, string $expected_id = '' ): bool {
		\aime_clear_settings_cache( array( self::OPTION_KEY ) );

		update_option( self::OPTION_KEY, $connections, false );

		\aime_clear_settings_cache( array( self::OPTION_KEY ) );

		$stored = \aime_get_db_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			return false;
		}

		if ( '' !== $expected_id && ! self::find_connection( $stored, $expected_id ) ) {
			return false;
		}

		return $stored === $connections;
	}

	/**
	 * Keep old global settings aligned so legacy fallbacks cannot restore wp_mail.
	 *
	 * @param array $connections SMTP connections.
	 * @return void
	 */
	private static function sync_legacy_settings( array $connections ): void {
		$primary = null;
		foreach ( $connections as $connection ) {
			if ( ! empty( $connection['is_primary'] ) ) {
				$primary = $connection;
				break;
			}
		}

		if ( ! $primary ) {
			\aime_clear_settings_cache( array( self::OPTION_KEY, 'aime_settings' ) );
			return;
		}

		$settings                    = get_option( 'aime_settings', array() );
		$settings['sending_method']  = $primary['provider'] ?? 'wp_mail';
		$settings['smtp_host']       = $primary['smtp_host'] ?? '';
		$settings['smtp_port']       = $primary['smtp_port'] ?? 587;
		$settings['smtp_encryption'] = $primary['smtp_encryption'] ?? 'tls';
		$settings['smtp_username']   = $primary['smtp_username'] ?? '';
		$settings['from_name']       = $primary['from_name'] ?? '';
		$settings['from_email']      = $primary['from_email'] ?? '';

		if ( ! empty( $primary['smtp_password'] ) ) {
			$settings['smtp_password'] = $primary['smtp_password'];
		}

		update_option( 'aime_settings', $settings, false );
		\aime_clear_settings_cache( array( self::OPTION_KEY, 'aime_settings' ) );
	}

	private static function release_limit_waiting_emails(): void {
		global $wpdb;
		$p = $wpdb->prefix;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$p}aime_campaign_emails
				 SET scheduled_at = NULL, updated_at = %s
				 WHERE status = 'pending' AND note = %s",
				current_time( 'mysql', true ),
				self::LIMIT_WAIT_NOTE
			)
		);

		if ( ! wp_next_scheduled( 'aime_run_email_queue' ) ) {
			wp_schedule_single_event( time() + 5, 'aime_run_email_queue' );
		}
	}

	/**
	 * Delete a connection by ID.
	 *
	 * @param string $id Connection ID.
	 * @return bool
	 */
	public static function delete_connection( string $id ): bool {
		$connections = self::get_connections();
		$connections = array_values( array_filter( $connections, function ( $c ) use ( $id ) {
			return $c['id'] !== $id;
		} ) );

		// Ensure one primary remains.
		$has_primary = false;
		foreach ( $connections as $c ) {
			if ( ! empty( $c['is_primary'] ) ) {
				$has_primary = true;
				break;
			}
		}
		if ( ! $has_primary && ! empty( $connections ) ) {
			$connections[0]['is_primary'] = true;
		}

		$connections = self::normalize_connections( $connections );
		$connections = self::sort_connections( $connections );

		if ( ! self::persist_connections( $connections ) ) {
			return false;
		}

		self::sync_legacy_settings( $connections );
		return true;
	}

	/**
	 * Find a connection by ID.
	 *
	 * @param array  $connections Connection list.
	 * @param string $id          Connection ID.
	 * @return array|null
	 */
	private static function find_connection( array $connections, string $id ): ?array {
		foreach ( $connections as $c ) {
			if ( ( $c['id'] ?? '' ) === $id ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Move a non-primary SMTP connection up or down in fallback order.
	 *
	 * @param string $id        Connection ID.
	 * @param string $direction Move direction.
	 * @return array|null Updated connection list or null when no move is possible.
	 */
	public static function move_connection( string $id, string $direction ): ?array {
		if ( ! in_array( $direction, array( 'up', 'down' ), true ) ) {
			return null;
		}

		$connections = self::get_connections();
		$primary     = null;
		$fallbacks   = array();

		foreach ( $connections as $connection ) {
			if ( ! empty( $connection['is_primary'] ) && null === $primary ) {
				$primary = $connection;
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

		$temp                = $fallbacks[ $target ];
		$fallbacks[ $target ] = $fallbacks[ $index ];
		$fallbacks[ $index ]  = $temp;

		$reordered = array();
		if ( $primary ) {
			$reordered[] = $primary;
		}
		$reordered = array_merge( $reordered, $fallbacks );
		$reordered = self::reindex_connections( $reordered );
		$reordered = self::sort_connections( $reordered );

		if ( ! self::persist_connections( $reordered, $id ) ) {
			throw new \RuntimeException( esc_html__( 'SMTP connection order could not be updated. Please try again.', 'ai-marketing-expert' ) );
		}

		self::sync_legacy_settings( $reordered );

		return $reordered;
	}

	/* ================================================================
	 *  TEST CONNECTION
	 * ============================================================= */

	/**
	 * Test a saved connection by ID.
	 *
	 * @param string $id Connection ID.
	 * @param string $to Test email recipient.
	 * @return array { success: bool, message: string }
	 */
	public static function test_connection_by_id( string $id, string $to = '' ): array {
		$connections = self::get_connections();
		$conn        = self::find_connection( $connections, $id );

		if ( ! $conn ) {
			return array(
				'success' => false,
				'message' => __( 'Connection not found.', 'ai-marketing-expert' ),
			);
		}

		return self::test_single_connection( $conn, $to );
	}

	/**
	 * Test a single connection.
	 *
	 * @param array  $conn Connection data (with encrypted password).
	 * @param string $to   Recipient email (defaults to admin email).
	 * @return array
	 */
	public static function test_single_connection( array $conn, string $to = '' ): array {
		if ( empty( $to ) ) {
			$to = get_option( 'admin_email' );
		}

		$test_id = strtoupper( substr( md5( wp_generate_uuid4() ), 0, 8 ) );
		$subject = sprintf(
			/* translators: %s: unique test ID */
			__( 'Email delivery check %s', 'ai-marketing-expert' ),
			$test_id
		);
		$body    = sprintf(
			/* translators: 1: connection name, 2: site name, 3: date/time, 4: test ID */
			__( '<p>This message confirms that the SMTP connection <strong>%1$s</strong> can send email from <strong>%2$s</strong>.</p><p>Sent at: %3$s<br>Test ID: %4$s</p><p>If you requested this test, no further action is needed.</p>', 'ai-marketing-expert' ),
			esc_html( $conn['name'] ?? __( 'Unnamed connection', 'ai-marketing-expert' ) ),
			esc_html( get_bloginfo( 'name' ) ),
			esc_html( current_time( 'mysql' ) ),
			esc_html( $test_id )
		);

		if ( 'wp_mail' === ( $conn['provider'] ?? '' ) ) {
			$result = wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
			return array(
				'success' => $result,
				'message' => $result
					? __( 'Test email sent successfully!', 'ai-marketing-expert' )
					: __( 'Failed to send test email via wp_mail.', 'ai-marketing-expert' ),
			);
		}

		// Direct SMTP test via PHPMailer.
		if ( ! class_exists( 'PHPMailer\\PHPMailer\\PHPMailer' ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		}

		$resolved = self::resolve_smtp_config( $conn );

		$from_name  = $conn['from_name'] ?: ( get_option( 'aime_from_name' ) ?: get_bloginfo( 'name' ) );
		$from_email = self::get_from_email_for_connection( $conn, $resolved );

		try {
			$mail            = new \PHPMailer\PHPMailer\PHPMailer( true );
			$mail->isSMTP();
			$mail->Host       = $resolved['host'];
			$mail->Port       = $resolved['port'];
			$mail->SMTPSecure = 'none' === $resolved['encryption'] ? '' : $resolved['encryption'];
			$mail->SMTPAuth   = true;
			$mail->Username   = $resolved['username'];
			$mail->Password   = $resolved['password'];
			$mail->Timeout    = 10;
			$mail->CharSet    = 'UTF-8';
			$mail->setFrom( $from_email, $from_name );
			$mail->addAddress( $to );
			$mail->Subject = $subject;
			$mail->Body    = $body;
			$mail->isHTML( true );
			$mail->send();

			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: SMTP host */
					__( 'SMTP connection to %s successful! Test email sent.', 'ai-marketing-expert' ),
					$resolved['host']
				),
			);
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			$error_message = $e->getMessage();
			$diagnostic    = self::get_test_failure_diagnostic( $conn, $error_message );

			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: error message, 2: diagnostic hint */
					__( 'SMTP connection failed: %1$s %2$s', 'ai-marketing-expert' ),
					$error_message,
					$diagnostic
				),
			);
		}
	}

	/**
	 * Explain common provider-side SMTP test failures.
	 *
	 * @param array  $conn          SMTP connection.
	 * @param string $error_message PHPMailer error message.
	 * @return string
	 */
	private static function get_test_failure_diagnostic( array $conn, string $error_message ): string {
		$error    = strtolower( $error_message );
		$provider = $conn['provider'] ?? '';

		if ( false !== strpos( $error, 'data not accepted' ) ) {
			if ( 'gmail' === $provider ) {
				return __( 'Authentication worked, but Gmail rejected the message at the sending stage. This often happens after repeated quick tests, sender mismatch, or temporary Gmail anti-spam throttling. Wait a few minutes and test again.', 'ai-marketing-expert' );
			}

			if ( 'amazon_ses' === $provider ) {
				return __( 'Authentication worked, but Amazon SES rejected the message. Check that your SMTP host matches the SES region where the SMTP credentials were created, and that both sender and recipient are verified if your account is still in sandbox mode.', 'ai-marketing-expert' );
			}

			return __( 'Authentication worked, but the mail provider rejected the message content or rate. Wait a few minutes, then try again with a real recipient address.', 'ai-marketing-expert' );
		}

		if ( false !== strpos( $error, 'could not authenticate' ) ) {
			return __( 'Check the SMTP username and password. Gmail requires an App Password, and Amazon SES requires SES SMTP credentials, not the AWS console password.', 'ai-marketing-expert' );
		}

		return '';
	}

	/* ================================================================
	 *  SEND WITH FALLBACK
	 * ============================================================= */

	/**
	 * Send email trying primary then fallbacks.
	 *
	 * @param string       $to          Recipient.
	 * @param string       $subject     Subject.
	 * @param string       $body        HTML body.
	 * @param string|array $headers     Optional headers.
	 * @param array        $attachments Optional attachments.
	 * @return bool|null Null means every configured connection has reached its daily limit.
	 */
	public static function send_with_fallback( string $to, string $subject, string $body, $headers = '', array $attachments = array() ): ?bool {
		$connections = self::get_connections();

		$enabled_connections = array_filter( $connections, function ( $c ) {
			return ! empty( $c['enabled'] );
		} );

		$enabled = array_filter( $enabled_connections, function ( $c ) {
			return ! self::has_reached_daily_limit( $c );
		} );

		if ( empty( $connections ) ) {
			return wp_mail( $to, $subject, $body, $headers, $attachments );
		}

		if ( empty( $enabled ) ) {
			return empty( $enabled_connections ) ? false : null;
		}

		foreach ( $enabled as $conn ) {
			if ( 'wp_mail' === ( $conn['provider'] ?? '' ) ) {
				// Temporarily remove our hook.
				self::remove_site_mail_hooks();
				$result = wp_mail( $to, $subject, $body, $headers, $attachments );
				self::init();
				if ( $result ) {
					self::increment_daily_usage( $conn );
					return true;
				}
				continue;
			}

			try {
				if ( self::send_single( $conn, $to, $subject, $body, $headers, $attachments ) ) {
					self::increment_daily_usage( $conn );
					return true;
				}
			} catch ( \Exception $e ) {
				if ( function_exists( 'aime_log' ) ) {
					aime_log( sprintf( "SMTP '%s' failed: %s", $conn['name'], $e->getMessage() ), 'warning' );
				}
				continue;
			}
		}

		return false;
	}

	/**
	 * Send a single email via a specific SMTP connection.
	 *
	 * @param array        $conn        Connection config.
	 * @param string       $to          Recipient.
	 * @param string       $subject     Subject.
	 * @param string       $body        HTML body.
	 * @param string|array $headers     Optional headers.
	 * @param array        $attachments Optional attachments.
	 * @return bool
	 */
	private static function send_single( array $conn, string $to, string $subject, string $body, $headers = '', array $attachments = array() ): bool {
		if ( ! class_exists( 'PHPMailer\\PHPMailer\\PHPMailer' ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		}

		$resolved   = self::resolve_smtp_config( $conn );
		$from_name  = $conn['from_name'] ?: ( get_option( 'aime_from_name' ) ?: get_bloginfo( 'name' ) );
		$from_email = self::get_from_email_for_connection( $conn, $resolved );

		$mail            = new \PHPMailer\PHPMailer\PHPMailer( true );
		$mail->isSMTP();
		$mail->Host       = $resolved['host'];
		$mail->Port       = $resolved['port'];
		$mail->SMTPSecure = 'none' === $resolved['encryption'] ? '' : $resolved['encryption'];
		$mail->SMTPAuth   = true;
		$mail->Username   = $resolved['username'];
		$mail->Password   = $resolved['password'];
		$mail->Timeout    = 15;
		$mail->CharSet    = 'UTF-8';
		$mail->setFrom( $from_email, $from_name );
		$mail->addAddress( $to );
		$mail->Subject = $subject;
		$mail->isHTML( true );
		$mail->Body = $body;

		// Parse additional headers.
		$header_list = is_array( $headers ) ? $headers : explode( "\n", (string) $headers );
		foreach ( $header_list as $h ) {
			$h = trim( $h );
			if ( stripos( $h, 'Reply-To:' ) === 0 ) {
				$mail->addReplyTo( trim( str_ireplace( 'Reply-To:', '', $h ) ) );
			} elseif ( stripos( $h, 'Cc:' ) === 0 ) {
				$mail->addCC( trim( str_ireplace( 'Cc:', '', $h ) ) );
			} elseif ( stripos( $h, 'Bcc:' ) === 0 ) {
				$mail->addBCC( trim( str_ireplace( 'Bcc:', '', $h ) ) );
			}
		}

		// Attachments.
		foreach ( (array) $attachments as $att ) {
			if ( is_file( $att ) ) {
				$mail->addAttachment( $att );
			}
		}

		$mail->send();
		return true;
	}

	/* ================================================================
	 *  RESOLVE CONFIG + PHPMAILER HOOK
	 * ============================================================= */

	/**
	 * Resolve SMTP host/port/encryption/username/password from a connection config.
	 *
	 * @param array $conn Connection array.
	 * @return array { host, port, encryption, username, password }
	 */
	private static function resolve_smtp_config( array $conn ): array {
		$providers  = self::get_providers();
		$provider   = $providers[ $conn['provider'] ] ?? null;

		$host       = ! empty( $conn['smtp_host'] ) ? $conn['smtp_host'] : ( $provider['host'] ?? '' );
		$port       = $provider['port'] ?? absint( $conn['smtp_port'] ?? 587 );
		$encryption = $provider['encryption'] ?? ( $conn['smtp_encryption'] ?? 'tls' );
		$username   = $provider['username'] ?? ( $conn['smtp_username'] ?? '' );
		$password   = $conn['smtp_password'] ?? '';

		// Amazon SES: allow user-specified host (region-specific).
		if ( 'amazon_ses' === ( $conn['provider'] ?? '' ) && ! empty( $conn['smtp_host'] ) ) {
			$host = $conn['smtp_host'];
		}

		// Decrypt password.
		if ( ! empty( $password ) && class_exists( __NAMESPACE__ . '\\Encryption' ) ) {
			$decrypted = Encryption::decrypt( $password );
			if ( false !== $decrypted ) {
				$password = $decrypted;
			}
		}

		return compact( 'host', 'port', 'encryption', 'username', 'password' );
	}

	/**
	 * Resolve the sender email for a connection.
	 *
	 * @param array $conn     Connection array.
	 * @param array $resolved Resolved SMTP credentials.
	 * @return string
	 */
	private static function get_from_email_for_connection( array $conn, array $resolved ): string {
		if ( ! empty( $conn['from_email'] ) ) {
			return $conn['from_email'];
		}

		if ( ! empty( $resolved['username'] ) && is_email( $resolved['username'] ) ) {
			return $resolved['username'];
		}

		return get_option( 'aime_from_email' ) ?: get_option( 'admin_email' );
	}

	/**
	 * Configure PHPMailer before sending (primary connection).
	 * Hooked on 'phpmailer_init'.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 */
	public static function configure_phpmailer( $phpmailer ): void {
		$primary = self::get_primary_site_mail_connection();

		if ( ! $primary || 'wp_mail' === $primary['provider'] ) {
			return;
		}

		$resolved   = self::resolve_smtp_config( $primary );
		$from_email = self::get_from_email_for_connection( $primary, $resolved );
		$from_name  = $primary['from_name'] ?: \get_bloginfo( 'name' );

		$phpmailer->isSMTP();
		$phpmailer->Host        = $resolved['host'];
		$phpmailer->Port        = $resolved['port'];
		$phpmailer->SMTPSecure  = 'none' === $resolved['encryption'] ? '' : $resolved['encryption'];
		$phpmailer->SMTPAutoTLS = 'none' !== $resolved['encryption'];
		$phpmailer->SMTPAuth    = true;
		$phpmailer->Username    = $resolved['username'];
		$phpmailer->Password    = $resolved['password'];
		$phpmailer->Timeout     = 15;
		$phpmailer->CharSet     = 'UTF-8';
		$phpmailer->XMailer     = 'AI Marketing Expert';
		$phpmailer->Sender      = $from_email;

		try {
			$phpmailer->setFrom( $from_email, $from_name, false );
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			$phpmailer->From     = $from_email;
			$phpmailer->FromName = $from_name;
		}

		$original_email = self::$site_mail_original_from['email'];
		$original_name  = self::$site_mail_original_from['name'];
		if ( $original_email && strtolower( $original_email ) !== strtolower( $from_email ) && empty( $phpmailer->getReplyToAddresses() ) ) {
			$phpmailer->addReplyTo( $original_email, $original_name );
		}
	}

	/**
	 * Normalize WordPress From email for site-wide SMTP delivery.
	 *
	 * @param string $email Filtered WordPress sender email.
	 * @return string
	 */
	public static function filter_site_mail_from_email( string $email ): string {
		self::$site_mail_original_from['email'] = \is_email( $email ) ? $email : '';

		$primary = self::get_primary_site_mail_connection();
		if ( ! $primary || 'wp_mail' === $primary['provider'] ) {
			return $email;
		}

		$resolved   = self::resolve_smtp_config( $primary );
		$from_email = self::get_from_email_for_connection( $primary, $resolved );

		return \is_email( $from_email ) ? $from_email : $email;
	}

	/**
	 * Normalize WordPress From name for site-wide SMTP delivery.
	 *
	 * @param string $name Filtered WordPress sender name.
	 * @return string
	 */
	public static function filter_site_mail_from_name( string $name ): string {
		self::$site_mail_original_from['name'] = \sanitize_text_field( $name );

		$primary = self::get_primary_site_mail_connection();
		if ( ! $primary || 'wp_mail' === $primary['provider'] || empty( $primary['from_name'] ) ) {
			return $name;
		}

		return $primary['from_name'];
	}

	/**
	 * Store the last site-wide wp_mail failure for support diagnostics.
	 *
	 * @param \WP_Error $error Mail failure error.
	 * @return void
	 */
	public static function capture_site_mail_failure( \WP_Error $error ): void {
		$data = $error->get_error_data();
		$logs = self::get_site_mail_error_logs();

		array_unshift(
			$logs,
			array(
				'time'    => \current_time( 'mysql', true ),
				'code'    => \sanitize_key( $error->get_error_code() ),
				'message' => \sanitize_text_field( $error->get_error_message() ),
				'to'      => isset( $data['to'] ) ? self::sanitize_mail_debug_value( $data['to'] ) : '',
				'subject' => isset( $data['subject'] ) ? \sanitize_text_field( \wp_strip_all_tags( (string) $data['subject'] ) ) : '',
			)
		);

		$logs = array_slice( $logs, 0, self::SITE_MAIL_ERROR_LOG_LIMIT );

		\update_option(
			self::LAST_SITE_MAIL_ERROR_OPTION_KEY,
			$logs,
			false
		);
	}

	/**
	 * Get recent site-wide SMTP mail failures.
	 *
	 * @return array<int, array{time:string,code:string,message:string,to:string,subject:string}>
	 */
	public static function get_site_mail_error_logs(): array {
		$logs = \get_option( self::LAST_SITE_MAIL_ERROR_OPTION_KEY, array() );

		if ( ! is_array( $logs ) || empty( $logs ) ) {
			return array();
		}

		if ( isset( $logs['message'] ) ) {
			$logs = array( $logs );
		}

		$logs = array_values( array_filter( $logs, 'is_array' ) );

		return array_slice( $logs, 0, self::SITE_MAIL_ERROR_LOG_LIMIT );
	}

	/**
	 * Clear recent site-wide SMTP mail failures.
	 *
	 * @return bool
	 */
	public static function clear_site_mail_error_logs(): bool {
		return \delete_option( self::LAST_SITE_MAIL_ERROR_OPTION_KEY );
	}

	/**
	 * Get the enabled primary connection used for site-wide mail.
	 *
	 * @return array|null
	 */
	private static function get_primary_site_mail_connection(): ?array {
		foreach ( self::get_connections() as $connection ) {
			if ( ! empty( $connection['is_primary'] ) && ! empty( $connection['enabled'] ) ) {
				return $connection;
			}
		}

		return null;
	}

	/**
	 * Sanitize diagnostic mail values that can be strings or arrays.
	 *
	 * @param mixed $value Debug value.
	 * @return string
	 */
	private static function sanitize_mail_debug_value( $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_map( '\sanitize_text_field', $value ) );
		}

		return \sanitize_text_field( (string) $value );
	}

	/**
	 * Get current SMTP settings (legacy backwards compat).
	 *
	 * @return array
	 */
	public static function get_settings(): array {
		$defaults = array(
			'sending_method'  => 'wp_mail',
			'smtp_host'       => '',
			'smtp_port'       => 587,
			'smtp_encryption' => 'tls',
			'smtp_username'   => '',
			'smtp_password'   => '',
			'from_name'       => get_bloginfo( 'name' ),
			'from_email'      => get_option( 'admin_email' ),
		);

		// Try connections first.
		$connections = self::get_connections();
		foreach ( $connections as $c ) {
			if ( ! empty( $c['is_primary'] ) ) {
				return array(
					'sending_method'  => $c['provider'] ?? 'wp_mail',
					'smtp_host'       => $c['smtp_host'] ?? '',
					'smtp_port'       => $c['smtp_port'] ?? 587,
					'smtp_encryption' => $c['smtp_encryption'] ?? 'tls',
					'smtp_username'   => $c['smtp_username'] ?? '',
					'smtp_password'   => $c['smtp_password'] ?? '',
					'from_name'       => $c['from_name'] ?: $defaults['from_name'],
					'from_email'      => $c['from_email'] ?: $defaults['from_email'],
				);
			}
		}

		$settings = get_option( 'aime_settings', array() );
		return wp_parse_args( $settings, $defaults );
	}

	/* ================================================================
	 *  MIGRATION
	 * ============================================================= */

	/**
	 * Migrate old flat settings to connections array.
	 *
	 * @return array Connections array (possibly empty).
	 */
	private static function maybe_migrate_old(): array {
		$settings = get_option( 'aime_settings', array() );
		$method   = $settings['sending_method'] ?? '';

		if ( empty( $method ) ) {
			return array();
		}

		$providers = self::get_providers();
		$provider  = $providers[ $method ] ?? null;
		$name      = $provider ? $provider['name'] : ucfirst( str_replace( '_', ' ', $method ) );

		$conn = array(
			'id'              => 'conn_migrated',
			'name'            => $name,
			'provider'        => $method,
			'smtp_host'       => $settings['smtp_host'] ?? '',
			'smtp_port'       => absint( $settings['smtp_port'] ?? 587 ),
			'smtp_encryption' => $settings['smtp_encryption'] ?? 'tls',
			'smtp_username'   => $settings['smtp_username'] ?? '',
			'smtp_password'   => $settings['smtp_password'] ?? '',
			'from_name'       => $settings['from_name'] ?? '',
			'from_email'      => $settings['from_email'] ?? '',
			'sending_limit'   => self::DEFAULT_DAILY_LIMIT,
			'sort_order'      => 0,
			'is_primary'      => true,
			'enabled'         => true,
		);

		$connections = array( $conn );
		update_option( self::OPTION_KEY, $connections, false );
		\aime_clear_settings_cache( array( self::OPTION_KEY ) );

		return $connections;
	}

	/* ================================================================
	 *  INIT
	 * ============================================================= */

	/**
	 * Initialize SMTP hook if needed.
	 */
	public static function init(): void {
		if ( ! self::is_site_mail_enabled() ) {
			self::remove_site_mail_hooks();
			return;
		}

		$primary = self::get_primary_site_mail_connection();

		if ( ! $primary || 'wp_mail' === $primary['provider'] ) {
			self::remove_site_mail_hooks();
			return;
		}

		if ( $primary && 'wp_mail' !== $primary['provider'] ) {
			if ( ! \has_action( 'phpmailer_init', array( __CLASS__, 'configure_phpmailer' ) ) ) {
				\add_action( 'phpmailer_init', array( __CLASS__, 'configure_phpmailer' ), 99, 1 );
			}

			if ( ! \has_filter( 'wp_mail_from', array( __CLASS__, 'filter_site_mail_from_email' ) ) ) {
				\add_filter( 'wp_mail_from', array( __CLASS__, 'filter_site_mail_from_email' ), PHP_INT_MAX, 1 );
			}

			if ( ! \has_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_site_mail_from_name' ) ) ) {
				\add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_site_mail_from_name' ), PHP_INT_MAX, 1 );
			}

			if ( ! \has_action( 'wp_mail_failed', array( __CLASS__, 'capture_site_mail_failure' ) ) ) {
				\add_action( 'wp_mail_failed', array( __CLASS__, 'capture_site_mail_failure' ), 10, 1 );
			}
		}
	}

	/**
	 * Remove all site-wide mail hooks owned by this provider.
	 *
	 * @return void
	 */
	private static function remove_site_mail_hooks(): void {
		\remove_action( 'phpmailer_init', array( __CLASS__, 'configure_phpmailer' ), 99 );
		\remove_filter( 'wp_mail_from', array( __CLASS__, 'filter_site_mail_from_email' ), PHP_INT_MAX );
		\remove_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_site_mail_from_name' ), PHP_INT_MAX );
		\remove_action( 'wp_mail_failed', array( __CLASS__, 'capture_site_mail_failure' ), 10 );
	}

	/**
	 * Check whether the primary SMTP connection should handle all site emails.
	 *
	 * @return bool
	 */
	public static function is_site_mail_enabled(): bool {
		if ( empty( self::get_detected_smtp_plugins() ) ) {
			return true;
		}

		return (bool) get_option( self::SITE_MAIL_OPTION_KEY, true );
	}

	/**
	 * Get active third-party SMTP plugins that may also control wp_mail.
	 *
	 * @return array<int, array{plugin:string,name:string}>
	 */
	public static function get_detected_smtp_plugins(): array {
		$known_plugins = array(
			'wp-mail-smtp/wp_mail_smtp.php'                       => 'WP Mail SMTP',
			'easy-wp-smtp/easy-wp-smtp.php'                       => 'Easy WP SMTP',
			'post-smtp/postman-smtp.php'                          => 'Post SMTP',
			'fluent-smtp/fluent-smtp.php'                         => 'FluentSMTP',
			'wp-smtp/wp-smtp.php'                                 => 'WP SMTP',
			'gmail-smtp/main.php'                                 => 'Gmail SMTP',
			'smtp-mailer/main.php'                                => 'SMTP Mailer',
			'wp-offload-ses-lite/wp-offload-ses.php'              => 'WP Offload SES Lite',
			'mailgun/mailgun.php'                                 => 'Mailgun for WordPress',
			'sendgrid-email-delivery-simplified/wpsendgrid.php'   => 'SendGrid',
			'brevo/sendinblue.php'                                => 'Brevo',
			'postmark-approved-wordpress-plugin/postmark.php'     => 'Postmark',
		);

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$network_plugins = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
		$active_plugins = array_unique( array_merge( $active_plugins, $network_plugins ) );
		$detected = array();

		foreach ( $known_plugins as $plugin => $name ) {
			if ( in_array( $plugin, $active_plugins, true ) ) {
				$detected[] = array(
					'plugin' => $plugin,
					'name'   => $name,
				);
			}
		}

		return $detected;
	}

	/**
	 * Update whether the primary SMTP connection should handle all site emails.
	 *
	 * @param bool $enabled Whether site-wide SMTP handling is enabled.
	 * @return bool
	 */
	public static function update_site_mail_enabled( bool $enabled ): bool {
		$current_value = get_option( self::SITE_MAIL_OPTION_KEY, null );

		if ( null === $current_value ) {
			$result = add_option( self::SITE_MAIL_OPTION_KEY, $enabled, '', false );
		} else {
			$result = update_option( self::SITE_MAIL_OPTION_KEY, $enabled, false );
		}

		if ( $enabled ) {
			self::init();
		} else {
			self::remove_site_mail_hooks();
		}

		return $result;
	}

	private static function get_usage_key( array $conn ): string {
		return sanitize_key( $conn['id'] ?? '' );
	}

	private static function get_usage_bucket(): array {
		$usage = get_option( self::USAGE_OPTION_KEY, array() );

		if ( ! is_array( $usage ) ) {
			$usage = array();
		}

		return $usage;
	}

	private static function has_reached_daily_limit( array $conn ): bool {
		$limit = max( 1, absint( $conn['sending_limit'] ?? self::DEFAULT_DAILY_LIMIT ) );
		$usage = self::get_connection_usage( $conn );
		$count = $usage['count'];

		return $count >= $limit;
	}

	private static function get_connection_usage( array $conn ): array {
		$key = self::get_usage_key( $conn );
		if ( '' === $key ) {
			return array(
				'count'    => 0,
				'reset_at' => 0,
			);
		}

		$usage = self::get_usage_bucket();
		$item  = $usage[ $key ] ?? array();
		$reset_at = absint( $item['reset_at'] ?? 0 );

		if ( $reset_at > 0 && time() >= $reset_at ) {
			return array(
				'count'    => 0,
				'reset_at' => 0,
			);
		}
		return array(
			'count'    => absint( $item['count'] ?? 0 ),
			'reset_at' => $reset_at,
		);
	}

	private static function increment_daily_usage( array $conn ): void {
		$key = self::get_usage_key( $conn );
		if ( '' === $key ) {
			return;
		}

		$usage = self::get_usage_bucket();
		$item = $usage[ $key ] ?? array();
		$reset_at = absint( $item['reset_at'] ?? 0 );

		if ( $reset_at < time() ) {
			$item = array(
				'count'    => 0,
				'reset_at' => time() + DAY_IN_SECONDS,
			);
		}

		$item['count'] = absint( $item['count'] ?? 0 ) + 1;
		$usage[ $key ] = $item;
		update_option( self::USAGE_OPTION_KEY, $usage, false );
	}
}
