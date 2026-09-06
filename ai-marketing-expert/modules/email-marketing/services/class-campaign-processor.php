<?php
/**
 * Campaign Processor – time-boxed cron job that sends queued emails.
 *
 * Modelled after FluentCRM's CampaignProcessor:
 * - Picks campaign_emails rows in status 'pending' with scheduled_at <= now.
 * - Parses body lazily per-subscriber (merge tags, tracking links).
 * - Sends in chunked batches of 30, respecting a 30-second time box.
 *
 * @package WPSpace\AiMarketingExpert\Modules\EmailMarketing\Services
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

namespace WPSpace\AiMarketingExpert\Modules\EmailMarketing\Services;

use WPSpace\AiMarketingExpert\SmtpProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CampaignProcessor {

	/** @var int Maximum send attempts before an email is marked permanently failed. */
	const MAX_SEND_RETRIES = 4;

	/** @var int Seconds before we stop the run. */
	private int $time_limit = 30;

	/** @var int Emails per batch. */
	private int $batch_size = 30;

	/** @var float Run start timestamp. */
	private float $started_at;

	/**
	 * Main entry — called by WP-Cron every minute.
	 */
	public function process( bool $force = false ): void {
		$this->started_at = microtime( true );
		$settings = get_option( 'aime_settings', array() );
		$this->batch_size = max( 1, min( 500, (int) ( $settings['batch_size'] ?? $this->batch_size ) ) );

		// 1. Active campaigns → enqueue subscribers.
		$this->activate_scheduled_campaigns();
		$this->enqueue_campaigns();

		// 2. Process pending email queue.
		$this->process_queue( $force );
	}

	/* ─── Enqueue ──────────────────────────────────────── */

	private function activate_scheduled_campaigns(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		$campaigns_table = $p . 'aime_campaigns';

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
				 SET status = %s, updated_at = %s
				 WHERE status = %s AND type = 'campaign' AND scheduled_at IS NOT NULL AND scheduled_at <= %s",
				$campaigns_table,
				'working',
				current_time( 'mysql', true ),
				'scheduled',
				current_time( 'mysql', true )
			)
		);
	}

	private function enqueue_campaigns(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		$campaigns_table = $p . 'aime_campaigns';

		$campaigns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i
				 WHERE status = %s AND type = 'campaign'
				 AND (scheduled_at IS NULL OR scheduled_at <= %s)
				 LIMIT 5",
				$campaigns_table,
				'working',
				current_time( 'mysql', true )
			)
		);

		foreach ( $campaigns as $campaign ) {
			$this->enqueue_campaign( $campaign );
		}
	}

	private function enqueue_campaign( object $campaign ): void {
		global $wpdb;
		$p = $wpdb->prefix;
		$campaigns_table      = $p . 'aime_campaigns';
		$campaign_emails_table = $p . 'aime_campaign_emails';

		// Decode settings for recipients (list_ids, tag_ids, segments).
		$settings   = json_decode( $campaign->settings ?? '{}', true );
		$send_all   = ! empty( $settings['all'] );
		$list_ids   = $settings['lists'] ?? $settings['list_ids'] ?? array();
		$tag_ids    = $settings['tags']  ?? $settings['tag_ids']  ?? array();
		$segments   = $settings['segments'] ?? array();
		$exclude_lists = $settings['exclude_lists'] ?? array();
		$exclude_tags  = $settings['exclude_tags']  ?? array();

		$subscriber_ids = $this->resolve_recipients( $list_ids, $tag_ids, $send_all, $exclude_lists, $exclude_tags, $segments );
		$offset = isset( $settings['audience_offset'] ) ? max( 0, (int) $settings['audience_offset'] ) : 0;
		$limit  = isset( $settings['audience_limit'] ) ? max( 0, (int) $settings['audience_limit'] ) : 0;
		if ( $offset > 0 || $limit > 0 ) {
			$subscriber_ids = array_slice( $subscriber_ids, $offset, $limit > 0 ? $limit : null );
		}
		if ( empty( $subscriber_ids ) ) {
			$wpdb->update(
				$campaigns_table,
				array(
					'status'     => 'failed',
					'note'       => __( 'No matching subscribers found. Check that your selected lists/tags contain active subscribed contacts.', 'ai-marketing-expert' ),
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $campaign->id )
			);
			return;
		}

		// Filter out already-enqueued.
		$existing = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT subscriber_id FROM %i WHERE campaign_id = %d',
				$campaign_emails_table,
				$campaign->id
			)
		);
		$subscriber_ids = array_diff( $subscriber_ids, $existing );

		if ( empty( $subscriber_ids ) ) {
			// All enqueued already – move to sending status.
			$wpdb->update( $campaigns_table, array( 'status' => 'sending' ), array( 'id' => $campaign->id ) );
			return;
		}

		// Build insert rows. INSERT IGNORE + the unique (campaign_id, subscriber_id) index guarantees
		// no duplicate is enqueued even if two workers race here concurrently.
		$now = current_time( 'mysql', true );
		foreach ( $subscriber_ids as $sid ) {
			$hash = md5( $campaign->id . '_' . $sid . '_' . wp_generate_uuid4() );
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$campaign_emails_table}
						(campaign_id, email_type, subscriber_id, email_subject, status, email_hash, created_at)
					 VALUES (%d, %s, %d, %s, %s, %s, %s)",
					$campaign->id,
					'campaign',
					$sid,
					$campaign->email_subject,
					'pending',
					$hash,
					$now
				)
			);
		}

		// Set recipients_count from the actual distinct enqueued rows, never additively.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$campaign_emails_table} WHERE campaign_id = %d",
				$campaign->id
			)
		);
		$wpdb->update(
			$campaigns_table,
			array(
				'recipients_count' => $count,
				'status'           => 'sending',
			),
			array( 'id' => $campaign->id )
		);
	}

	private function resolve_recipients( array $list_ids, array $tag_ids, bool $send_all = false, array $exclude_lists = array(), array $exclude_tags = array(), array $segments = array() ): array {
		global $wpdb;
		$p = $wpdb->prefix;
		$subscribers_table = $p . 'aime_subscribers';
		$pivot_table       = $p . 'aime_subscriber_pivot';
		$subscriber_ids = array();

		// Safety: require at least one targeting condition to avoid accidental mass sends.
		if ( ! $send_all && empty( $list_ids ) && empty( $tag_ids ) && empty( $segments ) ) {
			return array();
		}

		if ( $send_all || ! empty( $list_ids ) || ! empty( $tag_ids ) ) {
			$query_parts = array( 'SELECT DISTINCT s.id', 'FROM %i s' );
			$join_args   = array();
			$where_args  = array( 'subscribed' );
			$conditions  = array( 's.status = %s' );

			if ( ! $send_all ) {
				if ( ! empty( $list_ids ) ) {
					$safe_list = array_values( array_filter( array_map( 'absint', $list_ids ) ) );
					if ( ! empty( $safe_list ) ) {
						$list_placeholders = implode( ', ', array_fill( 0, count( $safe_list ), '%d' ) );
						$query_parts[]     = 'INNER JOIN %i spL ON spL.subscriber_id = s.id AND spL.object_type = %s AND spL.object_id IN (' . $list_placeholders . ')';
						$join_args[]       = $pivot_table;
						$join_args[]       = 'list';
						array_push( $join_args, ...$safe_list );
					}
				}

				if ( ! empty( $tag_ids ) ) {
					$safe_tags = array_values( array_filter( array_map( 'absint', $tag_ids ) ) );
					if ( ! empty( $safe_tags ) ) {
						$tag_placeholders = implode( ', ', array_fill( 0, count( $safe_tags ), '%d' ) );
						$query_parts[]    = 'INNER JOIN %i spT ON spT.subscriber_id = s.id AND spT.object_type = %s AND spT.object_id IN (' . $tag_placeholders . ')';
						$join_args[]      = $pivot_table;
						$join_args[]      = 'tag';
						array_push( $join_args, ...$safe_tags );
					}
				}
			}

			// Exclude lists.
			if ( ! empty( $exclude_lists ) ) {
				$safe_ex_list = array_values( array_filter( array_map( 'absint', $exclude_lists ) ) );
				if ( ! empty( $safe_ex_list ) ) {
					$ex_list_placeholders = implode( ', ', array_fill( 0, count( $safe_ex_list ), '%d' ) );
					$conditions[]         = 's.id NOT IN ( SELECT ep.subscriber_id FROM %i ep WHERE ep.object_type = %s AND ep.object_id IN (' . $ex_list_placeholders . ') )';
					$where_args[]         = $pivot_table;
					$where_args[]         = 'list';
					array_push( $where_args, ...$safe_ex_list );
				}
			}

			// Exclude tags.
			if ( ! empty( $exclude_tags ) ) {
				$safe_ex_tags = array_values( array_filter( array_map( 'absint', $exclude_tags ) ) );
				if ( ! empty( $safe_ex_tags ) ) {
					$ex_tag_placeholders = implode( ', ', array_fill( 0, count( $safe_ex_tags ), '%d' ) );
					$conditions[]        = 's.id NOT IN ( SELECT ep.subscriber_id FROM %i ep WHERE ep.object_type = %s AND ep.object_id IN (' . $ex_tag_placeholders . ') )';
					$where_args[]        = $pivot_table;
					$where_args[]        = 'tag';
					array_push( $where_args, ...$safe_ex_tags );
				}
			}

			$query_args = array_merge( array( $subscribers_table ), $join_args, $where_args );

			$subscriber_ids = array_merge(
				$subscriber_ids,
				array_map(
					'absint',
					$wpdb->get_col(
						$wpdb->prepare( implode( ' ', $query_parts ) . ' WHERE ' . implode( ' AND ', $conditions ), ...$query_args )
					)
				)
			);
		}

		foreach ( $segments as $segment ) {
			$subscriber_ids = array_merge( $subscriber_ids, $this->resolve_engagement_segment( (array) $segment ) );
		}

		$subscriber_ids = array_values( array_unique( array_filter( array_map( 'absint', $subscriber_ids ) ) ) );
		$excluded_ids   = $this->resolve_excluded_subscribers( $exclude_lists, $exclude_tags );

		return empty( $excluded_ids ) ? $subscriber_ids : array_values( array_diff( $subscriber_ids, $excluded_ids ) );
	}

	private function resolve_excluded_subscribers( array $exclude_lists, array $exclude_tags ): array {
		global $wpdb;
		$p = $wpdb->prefix;
		$pivot_table = $p . 'aime_subscriber_pivot';
		$ids = array();

		if ( ! empty( $exclude_lists ) ) {
			$safe = array_map( 'absint', $exclude_lists );
			$ph   = implode( ',', array_fill( 0, count( $safe ), '%d' ) );
			$ids   = array_merge(
				$ids,
				array_map(
					'absint',
					$wpdb->get_col(
						$wpdb->prepare(
							sprintf( 'SELECT subscriber_id FROM %%i WHERE object_type = %%s AND object_id IN (%s)', $ph ),
							$pivot_table,
							'list',
							...$safe
						)
					)
				)
			);
		}

		if ( ! empty( $exclude_tags ) ) {
			$safe = array_map( 'absint', $exclude_tags );
			$ph   = implode( ',', array_fill( 0, count( $safe ), '%d' ) );
			$ids   = array_merge(
				$ids,
				array_map(
					'absint',
					$wpdb->get_col(
						$wpdb->prepare(
							sprintf( 'SELECT subscriber_id FROM %%i WHERE object_type = %%s AND object_id IN (%s)', $ph ),
							$pivot_table,
							'tag',
							...$safe
						)
					)
				)
			);
		}

		return array_values( array_unique( $ids ) );
	}

	private function resolve_engagement_segment( array $segment ): array {
		global $wpdb;
		$p = $wpdb->prefix;
		$subscribers_table = $p . 'aime_subscribers';
		$metrics_table     = $p . 'aime_campaign_url_metrics';

		$type  = sanitize_key( $segment['type'] ?? '' );
		$days  = min( 365, max( 1, absint( $segment['days'] ?? 90 ) ) );
		$limit = min( 5000, max( 1, absint( $segment['limit'] ?? 500 ) ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		if ( ! in_array( $type, array( 'top_openers', 'top_clickers', 'most_engaged' ), true ) ) {
			return array();
		}

		if ( 'top_openers' === $type ) {
			return array_map( 'absint', $wpdb->get_col( $wpdb->prepare(
				"SELECT s.id
				 FROM %i s
				 INNER JOIN %i m ON m.subscriber_id = s.id
				 WHERE s.status = 'subscribed' AND m.type = 'open' AND m.created_at >= %s
				 GROUP BY s.id
				 ORDER BY SUM(CASE WHEN m.type = 'open' THEN m.counter ELSE 0 END) DESC, MAX(m.created_at) DESC, s.id DESC
				 LIMIT %d",
				$subscribers_table,
				$metrics_table,
				$since,
				$limit
				) ) );
		} elseif ( 'top_clickers' === $type ) {
			return array_map( 'absint', $wpdb->get_col( $wpdb->prepare(
				"SELECT s.id
				 FROM %i s
				 INNER JOIN %i m ON m.subscriber_id = s.id
				 WHERE s.status = 'subscribed' AND m.type = 'click' AND m.created_at >= %s
				 GROUP BY s.id
				 ORDER BY SUM(CASE WHEN m.type = 'click' THEN m.counter ELSE 0 END) DESC, MAX(m.created_at) DESC, s.id DESC
				 LIMIT %d",
				$subscribers_table,
				$metrics_table,
				$since,
				$limit
				) ) );
		} else {
			return array_map( 'absint', $wpdb->get_col( $wpdb->prepare(
				"SELECT s.id
				 FROM %i s
				 INNER JOIN %i m ON m.subscriber_id = s.id
				 WHERE s.status = 'subscribed' AND m.type IN ('open','click') AND m.created_at >= %s
				 GROUP BY s.id
				 ORDER BY (SUM(CASE WHEN m.type = 'open' THEN m.counter ELSE 0 END) + (SUM(CASE WHEN m.type = 'click' THEN m.counter ELSE 0 END) * 3)) DESC, MAX(m.created_at) DESC, s.id DESC
				 LIMIT %d",
				$subscribers_table,
				$metrics_table,
				$since,
				$limit
				) ) );
		}
	}

	/* ─── Process queue ────────────────────────────────── */

	private function process_queue( bool $force = false ): void {
		global $wpdb;
		$p = $wpdb->prefix;
		$campaign_emails_table = $p . 'aime_campaign_emails';
		$subscribers_table     = $p . 'aime_subscribers';
		$campaigns_table       = $p . 'aime_campaigns';
		$settings       = get_option( 'aime_settings', array() );
		$batch_interval = max( 0, (int) ( $settings['batch_interval'] ?? 0 ) );
		$last_run       = (int) get_transient( 'aime_email_queue_last_run' );

		if ( ! $force && $batch_interval > 0 && $last_run > 0 && ( time() - $last_run ) < $batch_interval ) {
			return;
		}

		set_transient( 'aime_email_queue_last_run', time(), max( MINUTE_IN_SECONDS, $batch_interval ) );

		// Global fallback throttle: honour the "emails per second" setting.
		$eps          = max( 1, (int) get_option( 'aime_emails_per_second', 10 ) );
		$global_delay = (int) ( 1000000 / $eps ); // microseconds between emails.

		// Cache per-campaign throttle delays so we only parse settings once per campaign.
		$campaign_delay_cache = array();

		while ( ! $this->time_exceeded() ) {
			$emails = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ce.*, s.email AS to_email, s.first_name, s.last_name, s.hash AS subscriber_hash, s.status AS subscriber_status,
					        c.settings AS campaign_settings
					 FROM %i ce
					 INNER JOIN %i s ON s.id = ce.subscriber_id
					 INNER JOIN %i c ON c.id = ce.campaign_id AND c.status IN ('working','sending')
					 WHERE ce.status = %s
					 AND (ce.scheduled_at IS NULL OR ce.scheduled_at <= %s)
					 ORDER BY ce.id ASC
					 LIMIT %d",
					$campaign_emails_table,
					$subscribers_table,
					$campaigns_table,
					'pending',
					current_time( 'mysql', true ),
					$this->batch_size
				)
			);

			if ( empty( $emails ) ) {
				break;
			}

			foreach ( $emails as $email ) {
				if ( $this->time_exceeded() ) {
					break 2;
				}
				$this->send_single( $email );

				// Resolve per-campaign throttle delay (emails/min → µs per email).
				$cid = (int) $email->campaign_id;
				if ( ! isset( $campaign_delay_cache[ $cid ] ) ) {
					$c_settings  = json_decode( $email->campaign_settings ?? '{}', true );
					$throttle    = isset( $c_settings['send_throttle'] ) ? max( 0, (int) $c_settings['send_throttle'] ) : 0;
					$campaign_delay_cache[ $cid ] = $throttle > 0
						? (int) ( 60_000_000 / $throttle ) // emails/min → µs/email
						: $global_delay;
				}
				usleep( $campaign_delay_cache[ $cid ] );
			}
		}

		// Finalise any campaigns whose queue is exhausted.
		$this->finalise_campaigns();
	}

	private function send_single( object $email ): void {
		global $wpdb;
		$p = $wpdb->prefix;
		$campaigns_table       = $p . 'aime_campaigns';
		$campaign_emails_table = $p . 'aime_campaign_emails';
		$subscribers_table     = $p . 'aime_subscribers';

		// Lazy parse body if not yet parsed.
		if ( ! $email->is_parsed ) {
			$campaign = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $campaigns_table, $email->campaign_id ) );
			if ( ! $campaign ) {
				$wpdb->update( $campaign_emails_table, array( 'status' => 'failed', 'note' => 'Campaign not found' ), array( 'id' => $email->id ) );
				return;
			}
			$body = $this->strip_template_unsubscribe_markup( (string) ( $campaign->email_body ?? '' ) );
			$body = $this->parse_email_body( $body, $email );
			$body = $this->append_footer( $body, $email );
			$body = $this->inject_tracking( $body, $email, $campaign );
			$wpdb->update(
				$campaign_emails_table,
				array(
					'email_body'    => $body,
					'email_address' => $email->to_email,
					'is_parsed'     => 1,
				),
				array( 'id' => $email->id )
			);
			$email->email_body    = $body;
			$email->email_address = $email->to_email;
		}

		// Get FROM settings.
		$from_name  = get_option( 'aime_from_name', get_bloginfo( 'name' ) );
		$from_email = get_option( 'aime_from_email', get_option( 'admin_email' ) );
		$reply_to   = get_option( 'aime_reply_to', '' );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			"From: {$from_name} <{$from_email}>",
			"List-Unsubscribe: <" . $this->get_unsubscribe_url( $email ) . ">",
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		);
		if ( is_email( $reply_to ) ) {
			$headers[] = "Reply-To: {$reply_to}";
		}

		$sent = SmtpProvider::send_with_fallback( $email->email_address, $email->email_subject, $email->email_body, $headers );

		if ( null === $sent ) {
			// Every SMTP connection is at its daily limit. This is throttling, not a
			// delivery failure: keep the row pending and re-check after an hour so the
			// email is sent once capacity frees up. Does not count as a send attempt.
			$wpdb->update(
				$campaign_emails_table,
				array(
					'note'         => SmtpProvider::LIMIT_WAIT_NOTE,
					'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
					'updated_at'   => current_time( 'mysql', true ),
				),
				array( 'id' => $email->id )
			);
			return;
		}

		if ( $sent ) {
			$wpdb->update(
				$campaign_emails_table,
				array(
					'status'     => 'sent',
					'note'       => null,
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $email->id )
			);

			$wpdb->update(
				$subscribers_table,
				array( 'last_activity' => current_time( 'mysql', true ) ),
				array( 'id' => $email->subscriber_id )
			);
			return;
		}

		// Send attempt failed. Retry with exponential backoff up to a fixed cap so a
		// transient SMTP error does not permanently drop the email, and the queue is
		// not stuck retrying forever.
		$attempts = (int) ( $email->retry_count ?? 0 ) + 1;

		if ( $attempts < self::MAX_SEND_RETRIES ) {
			$wpdb->update(
				$campaign_emails_table,
				array(
					'retry_count'  => $attempts,
					'note'         => sprintf( 'Send failed. Retry %d of %d scheduled.', $attempts, self::MAX_SEND_RETRIES ),
					'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + $this->retry_backoff_seconds( $attempts ) ),
					'updated_at'   => current_time( 'mysql', true ),
				),
				array( 'id' => $email->id )
			);
			return;
		}

		// Retries exhausted — mark the email failed. The subscriber is intentionally
		// left subscribed: a wp_mail/SMTP send failure is not a confirmed bounce, so
		// we do not silently lose the contact from the audience.
		$wpdb->update(
			$campaign_emails_table,
			array(
				'status'      => 'failed',
				'retry_count' => $attempts,
				'note'        => sprintf( 'Failed after %d attempts.', $attempts ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $email->id )
		);
	}

	/**
	 * Exponential backoff for transient send failures, capped at 24 hours.
	 *
	 * Attempt 1 → 1h, 2 → 2h, 3 → 4h, … capped at DAY_IN_SECONDS.
	 *
	 * @param int $attempt Current attempt number (1-based).
	 * @return int Delay in seconds.
	 */
	private function retry_backoff_seconds( int $attempt ): int {
		$hours = pow( 2, max( 0, $attempt - 1 ) );
		return (int) min( DAY_IN_SECONDS, $hours * HOUR_IN_SECONDS );
	}

	/* ─── Body parsing & tracking ──────────────────────── */

	private function parse_email_body( string $body, object $email ): string {
		$replace = array(
			'{{first_name}}'      => esc_html( $email->first_name ?? '' ),
			'{{last_name}}'       => esc_html( $email->last_name ?? '' ),
			'{{full_name}}'       => esc_html( trim( ( $email->first_name ?? '' ) . ' ' . ( $email->last_name ?? '' ) ) ),
			'{{email}}'           => esc_html( $email->to_email ?? '' ),
			'{{site_name}}'       => esc_html( get_bloginfo( 'name' ) ),
			'{{site_url}}'        => esc_url( home_url() ),
			'{{unsubscribe}}'     => $this->get_unsubscribe_url( $email ),
			'{{unsubscribe_url}}' => $this->get_unsubscribe_url( $email ),
		);
		return str_replace( array_keys( $replace ), array_values( $replace ), $body );
	}

	private function inject_tracking( string $body, object $email, ?object $campaign = null ): string {
		$settings     = get_option( 'aime_settings', array() );
		$track_opens  = ! array_key_exists( 'track_opens', $settings ) || (bool) $settings['track_opens'];
		$track_clicks = ! array_key_exists( 'track_clicks', $settings ) || (bool) $settings['track_clicks'];

		// Build UTM params from the campaign's dedicated columns when UTM is enabled.
		$utm_params = array();
		if ( $campaign && ! empty( $campaign->utm_status ) ) {
			if ( ! empty( $campaign->utm_source ) )   $utm_params['utm_source']   = $campaign->utm_source;
			if ( ! empty( $campaign->utm_medium ) )   $utm_params['utm_medium']   = $campaign->utm_medium;
			if ( ! empty( $campaign->utm_campaign ) ) $utm_params['utm_campaign'] = $campaign->utm_campaign;
			if ( ! empty( $campaign->utm_term ) )     $utm_params['utm_term']     = $campaign->utm_term;
			if ( ! empty( $campaign->utm_content ) )  $utm_params['utm_content']  = $campaign->utm_content;
		}

		if ( ! $track_opens && ! $track_clicks && empty( $utm_params ) ) {
			return $body;
		}

		// Open tracking pixel.
		$pixel_url = add_query_arg(
			array(
				'aime_track' => 'open',
				'hash'       => $email->email_hash,
				'token'      => \WPSpace\AiMarketingExpert\Modules\EmailMarketing\EmailMarketingModule::create_tracking_hash( (int) $email->campaign_id, (int) $email->subscriber_id ),
			),
			home_url()
		);
		$pixel = '<img src="' . esc_url( $pixel_url ) . '" width="1" height="1" style="display:none" alt="" />';

		if ( $track_clicks || ! empty( $utm_params ) ) {
			// Wrap links for click tracking and/or append UTM params.
			$body = preg_replace_callback(
				'/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i',
				function ( $matches ) use ( $email, $track_clicks, $utm_params ) {
					$original = $matches[1];
					if ( strpos( $original, 'aime_track' ) !== false ) {
						return $matches[0]; // Already tracked.
					}

					// Skip mailto/tel/anchor links for UTM.
					$scheme = strtolower( (string) wp_parse_url( $original, PHP_URL_SCHEME ) );
					$with_utm = $original;
					if ( ! empty( $utm_params ) && in_array( $scheme, array( 'http', 'https', '' ), true ) ) {
						$with_utm = add_query_arg( $utm_params, $original );
					}

					if ( ! $track_clicks ) {
						return str_replace( $original, $with_utm, $matches[0] );
					}

					$tracked = add_query_arg(
						array(
							'aime_track' => 'click',
							'hash'       => $email->email_hash,
							'token'      => \WPSpace\AiMarketingExpert\Modules\EmailMarketing\EmailMarketingModule::create_tracking_hash( (int) $email->campaign_id, (int) $email->subscriber_id ),
							'url'        => rawurlencode( $with_utm ),
							'sig'        => \WPSpace\AiMarketingExpert\Modules\EmailMarketing\EmailMarketingModule::create_url_signature( (int) $email->campaign_id, (int) $email->subscriber_id, $with_utm ),
						),
						home_url()
					);
					return str_replace( $original, $tracked, $matches[0] );
				},
				$body
			);
		}

		if ( ! $track_opens ) {
			return $body;
		}

		// Append pixel before </body> or at end.
		if ( stripos( $body, '</body>' ) !== false ) {
			$body = str_ireplace( '</body>', $pixel . '</body>', $body );
		} else {
			$body .= $pixel;
		}

		return $body;
	}

	private function append_footer( string $body, object $email ): string {
		return self::render_with_footer( $body, $this->get_unsubscribe_url( $email ) );
	}

	/**
	 * Strip any template-supplied unsubscribe markup and append the configured
	 * footer. Shared by the send queue, the template preview, and test mail so
	 * every rendering of an email shows the same footer.
	 *
	 * @param string $body            Email body HTML.
	 * @param string $unsubscribe_url Absolute unsubscribe URL for this recipient.
	 */
	public static function render_with_footer( string $body, string $unsubscribe_url ): string {
		$body             = self::strip_unsubscribe_markup( $body );
		$footer           = wp_kses_post( get_option( 'aime_email_footer', '' ) );
		$company_name     = sanitize_text_field( get_option( 'aime_company_name', '' ) );
		$company_address  = sanitize_textarea_field( get_option( 'aime_company_address', '' ) );
		$unsubscribe_text = sanitize_text_field( get_option( 'aime_unsubscribe_text', 'Unsubscribe' ) );
		$unsubscribe_url  = esc_url( $unsubscribe_url );

		// Build footer parts only from non-empty sources.
		$footer_parts = array();

		// Free tier: always include AIME branding.
		if ( ! aime_has_pro() ) {
			$footer_parts[] = '<p style="margin:0 0 8px;color:#64748b;font-size:12px">Sent with <a href="https://wpthemespace.com/product/ai-marketing-expert/" target="_blank" rel="noopener" style="color:#64748b;text-decoration:underline">AI Marketing Expert</a></p>';
		}

		// Add custom footer if not empty.
		if ( '' !== $footer ) {
			$footer_parts[] = $footer;
		}

		// Add company info if at least one field is populated.
		if ( '' !== $company_name || '' !== $company_address ) {
			$footer_parts[] = '<p style="margin:8px 0 0;color:#64748b;font-size:12px">' . esc_html( $company_name ) . ( $company_name && $company_address ? '<br>' : '' ) . nl2br( esc_html( $company_address ) ) . '</p>';
		}

		// Add unsubscribe link only if text is explicitly set (Pro: may be overridden; Free: always shown).
		if ( '' !== $unsubscribe_text ) {
			$footer_parts[] = '<p style="margin:8px 0 0"><a href="' . $unsubscribe_url . '" style="color:#64748b">' . esc_html( $unsubscribe_text ) . '</a></p>';
		}

		// Bail early if no footer content will be rendered.
		if ( empty( $footer_parts ) ) {
			return $body;
		}

		$footer_html = '<div class="aime-email-footer" style="margin-top:32px;padding-top:16px;border-top:1px solid #e2e8f0;color:#64748b;font-size:12px;text-align:center">' . implode( '', $footer_parts ) . '</div>';

		if ( stripos( $body, '</body>' ) !== false ) {
			return str_ireplace( '</body>', $footer_html . '</body>', $body );
		}

		return $body . $footer_html;
	}

	private function strip_template_unsubscribe_markup( string $body ): string {
		return self::strip_unsubscribe_markup( $body );
	}

	public static function strip_unsubscribe_markup( string $body ): string {
		$patterns = array(
			'#(?:<hr\b[^>]*>\s*)?<p\b[^>]*>\s*(?:[^<]*&bull;\s*)?<a\b[^>]*href=["\'][^"\']*(?:\{\{unsubscribe(?:_url)?\}\}|aime_track=unsubscribe)[^"\']*["\'][^>]*>.*?</a>(?:\s*from these emails?)?\s*</p>#is',
			'#<div\b[^>]*>\s*(?:[^<]*&bull;\s*)?<a\b[^>]*href=["\'][^"\']*(?:\{\{unsubscribe(?:_url)?\}\}|aime_track=unsubscribe)[^"\']*["\'][^>]*>.*?</a>(?:\s*from these emails?)?\s*</div>#is',
			'#<a\b[^>]*href=["\'][^"\']*(?:\{\{unsubscribe(?:_url)?\}\}|aime_track=unsubscribe)[^"\']*["\'][^>]*>.*?</a>#is',
		);

		$body = preg_replace( $patterns, '', $body );
		$body = preg_replace( '#<p\b[^>]*>\s*</p>#i', '', $body );

		return trim( $body );
	}

	private function get_unsubscribe_url( object $email ): string {
		// Bind the unsubscribe token to the subscriber's current status and an
		// issuance timestamp so the link expires after 30 days and cannot be
		// reused after the subscriber has already been unsubscribed.
		$hash = \WPSpace\AiMarketingExpert\Modules\EmailMarketing\EmailMarketingModule::create_unsubscribe_hash(
			(int) ( $email->campaign_id ?? 0 ),
			(int) ( $email->subscriber_id ?? 0 ),
			(string) ( $email->subscriber_status ?? 'subscribed' )
		);
		return add_query_arg(
			array(
				'aime_track' => 'unsubscribe',
				'hash'       => $hash,
			),
			home_url()
		);
	}

	/* ─── Finalise ─────────────────────────────────────── */

	private function finalise_campaigns(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		$campaign_emails_table = $p . 'aime_campaign_emails';
		$campaigns_table       = $p . 'aime_campaigns';

		// Campaigns that still have any pending rows (including future-scheduled ones).
		$still_pending = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT campaign_id FROM %i WHERE status = %s', $campaign_emails_table, 'pending' ) );

		$in_progress = $wpdb->get_col(
			$wpdb->prepare( 'SELECT id FROM %i WHERE status IN (%s, %s)', $campaigns_table, 'working', 'sending' )
		);

		// Campaigns whose entire queue is resolved (no pending rows at all).
		$done = array_diff( $in_progress, $still_pending );

		foreach ( $done as $cid ) {
			$cid = (int) $cid;

			// Count how many rows actually sent vs failed.
			$counts = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT
						SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS sent_count,
						SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed_count,
						COUNT(*) AS total
					FROM %i WHERE campaign_id = %d',
					'sent',
					'failed',
					$campaign_emails_table,
					$cid
				)
			);

			$sent_count   = (int) ( $counts->sent_count ?? 0 );
			$failed_count = (int) ( $counts->failed_count ?? 0 );
			$total        = (int) ( $counts->total ?? 0 );

			if ( $total > 0 && $sent_count === 0 && $failed_count === $total ) {
				// Every email failed — surface this clearly instead of pretending it was sent.
				$sample_note = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT note FROM %i WHERE campaign_id = %d AND status = %s AND note IS NOT NULL ORDER BY id DESC LIMIT 1',
						$campaign_emails_table,
						$cid,
						'failed'
					)
				);
				$wpdb->update(
					$campaigns_table,
					array(
						'status'     => 'failed',
						'note'       => $sample_note ?: __( 'All emails failed to send. Check your SMTP settings.', 'ai-marketing-expert' ),
						'updated_at' => current_time( 'mysql', true ),
					),
					array( 'id' => $cid )
				);
			} elseif ( $total > 0 && $sent_count > 0 && $failed_count > 0 ) {
				// Partial — some sent, some failed.
				$wpdb->update(
					$campaigns_table,
					array(
						'status'     => 'sent',
						'note'       => sprintf(
							/* translators: 1: sent count, 2: failed count */
							__( 'Partially sent: %1$d delivered, %2$d failed.', 'ai-marketing-expert' ),
							$sent_count,
							$failed_count
						),
						'updated_at' => current_time( 'mysql', true ),
					),
					array( 'id' => $cid )
				);
			} else {
				// All sent (or empty queue edge case).
				$wpdb->update(
					$campaigns_table,
					array( 'status' => 'sent', 'note' => null, 'updated_at' => current_time( 'mysql', true ) ),
					array( 'id' => $cid )
				);
			}
		}
	}

	private function time_exceeded(): bool {
		return ( microtime( true ) - $this->started_at ) >= $this->time_limit;
	}
}
