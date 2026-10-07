<?php
/**
 * Cloud ESP Synchronization Service.
 *
 * Provides autonomous API polling to fetch bounces and spam complaints
 * from Cloud Email Service Providers (Brevo, SendGrid, Mailgun, Amazon SES).
 *
 * Runs on WP-Cron `aime_sync_esp_bounces` or manual on-demand trigger.
 *
 * @package WPSpace\AiMarketingExpert\Modules\EmailMarketing\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\EmailMarketing\Services;

use WPSpace\AiMarketingExpert\SmtpProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CloudEspSyncService {

	/**
	 * Run synchronization across all configured and active Cloud ESPs.
	 *
	 * @return array { total_synced: int, total_bounced: int, details: array }
	 */
	public static function sync_all(): array {
		$results = array(
			'total_synced'  => 0,
			'total_bounced' => 0,
			'details'       => array(),
		);

		if ( ! aime_has_pro() ) {
			return $results;
		}

		$connections = SmtpProvider::get_connections();
		$seen_providers = array();

		foreach ( $connections as $conn ) {
			if ( empty( $conn['enabled'] ) ) {
				continue;
			}

			$provider = $conn['provider'] ?? '';
			if ( isset( $seen_providers[ $provider ] ) ) {
				continue;
			}
			$seen_providers[ $provider ] = true;

			switch ( $provider ) {
				case 'brevo':
					$res = self::sync_brevo( $conn );
					$results['details']['brevo'] = $res;
					$results['total_bounced']   += (int) ( $res['bounced'] ?? 0 );
					$results['total_synced']    += (int) ( $res['fetched'] ?? 0 );
					break;

				case 'sendgrid':
					$res = self::sync_sendgrid( $conn );
					$results['details']['sendgrid'] = $res;
					$results['total_bounced']      += (int) ( $res['bounced'] ?? 0 );
					$results['total_synced']       += (int) ( $res['fetched'] ?? 0 );
					break;

				case 'mailgun':
					$res = self::sync_mailgun( $conn );
					$results['details']['mailgun'] = $res;
					$results['total_bounced']     += (int) ( $res['bounced'] ?? 0 );
					$results['total_synced']      += (int) ( $res['fetched'] ?? 0 );
					break;
			}
		}

		// Also scan active per-connection and legacy IMAP bounce mailboxes.
		if ( class_exists( 'WPSpace\AiMarketingExpert\ImapBounceService' ) ) {
			$imap_res = \WPSpace\AiMarketingExpert\ImapBounceService::process_all_mailboxes();
			$results['details']['imap_mailboxes'] = $imap_res;
			$results['total_bounced'] += (int) ( $imap_res['bounced'] ?? 0 );
			$results['total_synced']  += (int) ( $imap_res['processed'] ?? 0 );
		}

		update_option( 'aime_esp_last_sync_timestamp', current_time( 'mysql', true ), false );

		return $results;
	}

	/**
	 * Sync bounces and complaints from Brevo (Sendinblue) REST API.
	 *
	 * @param array $conn Connection settings.
	 * @return array { fetched: int, bounced: int, error: string }
	 */
	public static function sync_brevo( array $conn ): array {
		$api_key = trim( (string) ( $conn['smtp_password'] ?? '' ) );
		if ( empty( $api_key ) ) {
			return array( 'fetched' => 0, 'bounced' => 0, 'error' => __( 'Missing Brevo API key.', 'ai-marketing-expert' ) );
		}

		$url      = 'https://api.brevo.com/v3/smtp/statistics/events?event=bounces,complaints&limit=100';
		$response = wp_remote_get( $url, array(
			'timeout' => 7,
			'headers' => array(
				'api-key' => $api_key,
				'accept'  => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'fetched' => 0, 'bounced' => 0, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array( 'fetched' => 0, 'bounced' => 0, 'error' => sprintf( 'Brevo API responded with code %d', $code ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$events = $body['events'] ?? array();
		$bounced_count = 0;

		foreach ( (array) $events as $evt ) {
			$email = sanitize_email( $evt['email'] ?? '' );
			if ( ! empty( $email ) && is_email( $email ) ) {
				SmtpProvider::record_hard_bounce( $email, 'Brevo API Suppression Sync' );
				$bounced_count++;
			}
		}

		return array(
			'fetched' => count( $events ),
			'bounced' => $bounced_count,
		);
	}

	/**
	 * Sync bounces from Twilio SendGrid REST API.
	 *
	 * @param array $conn Connection settings.
	 * @return array { fetched: int, bounced: int, error: string }
	 */
	public static function sync_sendgrid( array $conn ): array {
		$api_key = trim( (string) ( $conn['smtp_password'] ?? '' ) );
		if ( empty( $api_key ) ) {
			return array( 'fetched' => 0, 'bounced' => 0, 'error' => __( 'Missing SendGrid API key.', 'ai-marketing-expert' ) );
		}

		$url      = 'https://api.sendgrid.com/v3/suppression/bounces?limit=100';
		$response = wp_remote_get( $url, array(
			'timeout' => 7,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'accept'        => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'fetched' => 0, 'bounced' => 0, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array( 'fetched' => 0, 'bounced' => 0, 'error' => sprintf( 'SendGrid API responded with code %d', $code ) );
		}

		$bounces = json_decode( wp_remote_retrieve_body( $response ), true );
		$bounced_count = 0;

		if ( is_array( $bounces ) ) {
			foreach ( $bounces as $b ) {
				$email = sanitize_email( $b['email'] ?? '' );
				if ( ! empty( $email ) && is_email( $email ) ) {
					SmtpProvider::record_hard_bounce( $email, 'SendGrid API Suppression Sync' );
					$bounced_count++;
				}
			}
		}

		return array(
			'fetched' => is_array( $bounces ) ? count( $bounces ) : 0,
			'bounced' => $bounced_count,
		);
	}

	/**
	 * Sync bounces from Mailgun REST API.
	 *
	 * @param array $conn Connection settings.
	 * @return array { fetched: int, bounced: int, error: string }
	 */
	public static function sync_mailgun( array $conn ): array {
		$api_key = trim( (string) ( $conn['smtp_password'] ?? '' ) );
		$domain  = trim( (string) ( $conn['smtp_domain'] ?? '' ) );

		if ( empty( $api_key ) || empty( $domain ) ) {
			return array( 'fetched' => 0, 'bounced' => 0, 'error' => __( 'Missing Mailgun API key or domain.', 'ai-marketing-expert' ) );
		}

		$url      = sprintf( 'https://api.mailgun.net/v3/%s/bounces?limit=100', rawurlencode( $domain ) );
		$response = wp_remote_get( $url, array(
			'timeout' => 7,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( 'api:' . $api_key ),
				'accept'        => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'fetched' => 0, 'bounced' => 0, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array( 'fetched' => 0, 'bounced' => 0, 'error' => sprintf( 'Mailgun API responded with code %d', $code ) );
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$items   = $body['items'] ?? array();
		$bounced_count = 0;

		foreach ( (array) $items as $item ) {
			$email = sanitize_email( $item['address'] ?? '' );
			if ( ! empty( $email ) && is_email( $email ) ) {
				SmtpProvider::record_hard_bounce( $email, 'Mailgun API Suppression Sync' );
				$bounced_count++;
			}
		}

		return array(
			'fetched' => count( $items ),
			'bounced' => $bounced_count,
		);
	}
}
