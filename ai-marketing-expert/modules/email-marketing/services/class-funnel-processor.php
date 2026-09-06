<?php
/**
 * Funnel Processor – executes automation sequences on subscribed contacts.
 *
 * Modelled after FluentCRM's FunnelProcessor:
 * - Picks funnel_subscribers whose next_execution_time <= now and status = 'active'.
 * - Executes the next sequence action (send_email, add_tag, remove_tag, wait, condition, webhook).
 * - Advances the pointer or ends the funnel.
 *
 * @package WPSpace\AiMarketingExpert\Modules\EmailMarketing\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\EmailMarketing\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FunnelProcessor {

	/** @var int Seconds before we stop. */
	private int $time_limit = 30;

	/** @var float */
	private float $started_at;

	/**
	 * Process pending funnel follow-ups. Called by cron every minute.
	 */
	public function followUpActions(): void {
		$this->started_at = microtime( true );

		global $wpdb;
		$p = $wpdb->prefix;

		while ( ! $this->time_exceeded() ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT fs.*, s.email, s.first_name, s.last_name, s.status AS sub_status
					 FROM {$p}aime_funnel_subscribers fs
					 INNER JOIN {$p}aime_subscribers s ON s.id = fs.subscriber_id
					 WHERE fs.status = 'active'
					 AND fs.next_execution_time IS NOT NULL
					 AND fs.next_execution_time <= %s
					 ORDER BY fs.next_execution_time ASC
					 LIMIT 50",
					current_time( 'mysql', true )
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				if ( $this->time_exceeded() ) {
					break 2;
				}

				// Claim the row before doing any work. The send happens before the
				// pointer advances, so an overlapping worker that read the same row
				// would otherwise send the step's email a second time. Only the
				// worker whose UPDATE matches the row it read proceeds.
				$claimed = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$p}aime_funnel_subscribers
						 SET next_execution_time = %s
						 WHERE id = %d AND status = 'active' AND next_execution_time = %s",
						gmdate( 'Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS ),
						$row->id,
						$row->next_execution_time
					)
				);
				if ( ! $claimed ) {
					continue;
				}

				$this->execute_for_subscriber( $row );
			}
		}
	}

	/**
	 * Trigger a funnel for a subscriber (entry point from hooks).
	 */
	public function trigger( int $funnel_id, int $subscriber_id ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		// Don't duplicate.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$p}aime_funnel_subscribers WHERE funnel_id = %d AND subscriber_id = %d",
				$funnel_id,
				$subscriber_id
			)
		);
		if ( $exists ) {
			return;
		}

		$this->ensure_sequences_published( $funnel_id );

		// Re-check the funnel's own status before triggering. The caller
		// already filtered on `status = 'published'` for trigger-driven
		// entry points, but a directly-invoked trigger (e.g. from another
		// module or test code) must also respect a draft/paused funnel.
		$funnel_status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$p}aime_funnels WHERE id = %d",
				$funnel_id
			)
		);
		if ( 'published' !== $funnel_status ) {
			return;
		}

		// Find the first sequence.
		$first = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, delay FROM {$p}aime_funnel_sequences WHERE funnel_id = %d AND status = 'published' ORDER BY sequence ASC LIMIT 1",
				$funnel_id
			)
		);
		if ( ! $first ) {
			return;
		}

		$now  = current_time( 'mysql', true );
		$next = $first->delay > 0
			? gmdate( 'Y-m-d H:i:s', strtotime( $now ) + $first->delay )
			: $now;

		$wpdb->insert(
			"{$p}aime_funnel_subscribers",
			array(
				'funnel_id'            => $funnel_id,
				'starting_sequence_id' => $first->id,
				'subscriber_id'        => $subscriber_id,
				'next_sequence_id'     => $first->id,
				'status'               => 'active',
				'next_execution_time'  => $next,
				'created_at'           => $now,
			)
		);
	}

	/* ─── Sequence execution ───────────────────────────── */

	private function execute_for_subscriber( object $row ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		$sequence = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$p}aime_funnel_sequences WHERE id = %d", $row->next_sequence_id )
		);

		if ( ! $sequence ) {
			$this->complete_funnel( $row );
			return;
		}

		if ( 'published' !== $sequence->status ) {
			$this->ensure_sequences_published( (int) $row->funnel_id );
			$sequence = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$p}aime_funnel_sequences WHERE id = %d", $row->next_sequence_id )
			);
			if ( ! $sequence || 'published' !== $sequence->status ) {
				$this->complete_funnel( $row );
				return;
			}
		}

		// Check if subscriber is still subscribed.
		if ( 'subscribed' !== $row->sub_status ) {
			$this->complete_funnel( $row );
			return;
		}

		$result = $this->execute_action( $sequence, $row );

		if ( null === $result ) {
			$wpdb->update(
				"{$p}aime_funnel_subscribers",
				array(
					'last_sequence_status' => 'waiting',
					'next_execution_time'  => gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS ),
					'updated_at'           => current_time( 'mysql', true ),
				),
				array( 'id' => $row->id )
			);
			return;
		}

		// Log metric.
		$wpdb->insert(
			"{$p}aime_funnel_metrics",
			array(
				'funnel_id'     => $row->funnel_id,
				'sequence_id'   => $sequence->id,
				'subscriber_id' => $row->subscriber_id,
				'status'        => $result ? 'completed' : 'failed',
				'created_at'    => current_time( 'mysql', true ),
			)
		);

		// Advance to next sequence.
		$next = $this->get_next_sequence( $sequence );
		if ( $next ) {
			$now  = current_time( 'mysql', true );
			$exec = $next->delay > 0
				? gmdate( 'Y-m-d H:i:s', strtotime( $now ) + $next->delay )
				: $now;

			$wpdb->update(
				"{$p}aime_funnel_subscribers",
				array(
					'last_sequence_id'     => $sequence->id,
					'next_sequence_id'     => $next->id,
					'last_sequence_status' => $result ? 'completed' : 'failed',
					'last_executed_time'   => $now,
					'next_execution_time'  => $exec,
					'updated_at'           => $now,
				),
				array( 'id' => $row->id )
			);
		} else {
			$this->complete_funnel( $row, $sequence->id );
		}
	}

	private function execute_action( object $sequence, object $subscriber_row ): ?bool {
		$action   = $sequence->action_name;
		$settings = json_decode( $sequence->settings ?? '{}', true );

		switch ( $action ) {
			case 'send_email':
				return $this->action_send_email( $sequence, $subscriber_row, $settings );

			case 'add_tag':
				return $this->action_modify_pivot( $subscriber_row->subscriber_id, $this->resolve_pivot_ids( $settings, 'tag', true ), 'tag', 'add' );

			case 'remove_tag':
				return $this->action_modify_pivot( $subscriber_row->subscriber_id, $this->resolve_pivot_ids( $settings, 'tag', false ), 'tag', 'remove' );

			case 'add_to_list':
				return $this->action_modify_pivot( $subscriber_row->subscriber_id, $this->resolve_pivot_ids( $settings, 'list', false ), 'list', 'add' );

			case 'remove_from_list':
				return $this->action_modify_pivot( $subscriber_row->subscriber_id, $this->resolve_pivot_ids( $settings, 'list', false ), 'list', 'remove' );

			case 'update_contact':
				return $this->action_update_contact( $subscriber_row->subscriber_id, $settings );

			case 'webhook':
				return $this->action_webhook( $subscriber_row, $settings );

			case 'condition':
				return $this->action_condition( $subscriber_row, $settings );

			case 'wait':
				return true; // Delay already handled by next_execution_time.

			default:
				do_action( 'aime_funnel_action_' . $action, $sequence, $subscriber_row, $settings );
				return true;
		}
	}

	/* ─── Individual actions ───────────────────────────── */

	private function action_send_email( object $sequence, object $row, array $settings ): ?bool {
		global $wpdb;
		$p = $wpdb->prefix;

		// Exactly one source builds the email. email_source is authoritative;
		// steps saved before it existed fall back to "campaign selected wins",
		// which is what the editor now shows them as.
		$campaign_id = absint( $settings['campaign_id'] ?? 0 );
		$source      = (string) ( $settings['email_source'] ?? '' );
		if ( '' === $source ) {
			$source = $campaign_id ? 'campaign' : 'plain_text';
		}

		if ( 'campaign' === $source ) {
			if ( ! $campaign_id ) {
				// Configured to use a campaign but none picked — nothing to send.
				return false;
			}
			$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT email_subject, email_body FROM {$p}aime_campaigns WHERE id = %d", $campaign_id ) );
			if ( ! $campaign || '' === trim( (string) $campaign->email_body ) ) {
				// Campaign deleted or empty — permanent failure, do not retry.
				return false;
			}
			$subject = (string) $campaign->email_subject;
			$body    = (string) $campaign->email_body;
		} else {
			$subject = (string) ( $settings['email_subject'] ?? $sequence->title ?? '' );
			$body    = (string) ( $settings['email_body'] ?? '' );
		}

		if ( '' === trim( $body ) ) {
			// No body from either source — a blank email is never worth sending.
			return false;
		}

		// Generate a unique hash for tracking.
		$email_hash = md5( $row->subscriber_id . '_automation_' . $sequence->id . '_' . wp_generate_uuid4() );

		// Build a signed unsubscribe URL bound to the subscriber's current status,
		// mirroring the campaign path. Automation emails are not tied to a campaign,
		// so campaign_id is 0.
		$unsub_url = add_query_arg(
			array(
				'aime_track' => 'unsubscribe',
				'hash'       => \WPSpace\AiMarketingExpert\Modules\EmailMarketing\EmailMarketingModule::create_unsubscribe_hash(
					0,
					(int) $row->subscriber_id,
					(string) ( $row->sub_status ?? 'subscribed' )
				),
			),
			home_url()
		);

		// Merge tags (escape values for HTML context).
		$replace = array(
			'{{first_name}}'      => esc_html( $row->first_name ?? '' ),
			'{{last_name}}'       => esc_html( $row->last_name ?? '' ),
			'{{full_name}}'       => esc_html( trim( ( $row->first_name ?? '' ) . ' ' . ( $row->last_name ?? '' ) ) ),
			'{{email}}'           => esc_html( $row->email ?? '' ),
			'{{site_name}}'       => esc_html( get_bloginfo( 'name' ) ),
			'{{site_url}}'        => esc_url( home_url() ),
			'{{unsubscribe}}'     => esc_url( $unsub_url ),
			'{{unsubscribe_url}}' => esc_url( $unsub_url ),
		);
		$subject = str_replace( array_keys( $replace ), array_values( $replace ), $subject );
		$body    = str_replace( array_keys( $replace ), array_values( $replace ), $body );

		$email_context = (object) array(
			'campaign_id'       => 0,
			'subscriber_id'     => $row->subscriber_id,
			'subscriber_status' => $row->sub_status ?? 'subscribed',
			'email_hash'        => $email_hash,
		);
		$body = $this->append_footer( $body, $email_context );
		$body = $this->inject_tracking( $body, $email_context );

		$from_name  = get_option( 'aime_from_name', get_bloginfo( 'name' ) );
		$from_email = get_option( 'aime_from_email', get_option( 'admin_email' ) );
		$reply_to   = get_option( 'aime_reply_to', '' );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			"From: {$from_name} <{$from_email}>",
			'List-Unsubscribe: <' . esc_url( $unsub_url ) . '>',
		);
		if ( is_email( $reply_to ) ) {
			$headers[] = "Reply-To: {$reply_to}";
		}

		$sent = \WPSpace\AiMarketingExpert\SmtpProvider::send_with_fallback( $row->email, $subject, $body, $headers );

		if ( null === $sent ) {
			return null;
		}

		// Log in campaign_emails for analytics.
		$wpdb->insert(
			"{$p}aime_campaign_emails",
			array(
				'campaign_id'   => 0,
				'email_type'    => 'automation',
				'subscriber_id' => $row->subscriber_id,
				'email_address' => $row->email,
				'email_subject' => $subject,
				'email_body'    => $body,
				'status'        => $sent ? 'sent' : 'failed',
				'email_hash'    => $email_hash,
				'created_at'    => current_time( 'mysql', true ),
			)
		);

		return $sent;
	}

	private function inject_tracking( string $body, object $email ): string {
		$settings     = get_option( 'aime_settings', array() );
		$track_opens  = ! array_key_exists( 'track_opens', $settings ) || (bool) $settings['track_opens'];
		$track_clicks = ! array_key_exists( 'track_clicks', $settings ) || (bool) $settings['track_clicks'];

		if ( ! $track_opens && ! $track_clicks ) {
			return $body;
		}

		$token = \WPSpace\AiMarketingExpert\Modules\EmailMarketing\EmailMarketingModule::create_tracking_hash( (int) $email->campaign_id, (int) $email->subscriber_id );
		if ( $track_clicks ) {
			$body = preg_replace_callback(
				'/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i',
				function ( $matches ) use ( $email, $token ) {
					$original = $matches[1];
					if ( strpos( $original, 'aime_track' ) !== false ) {
						return $matches[0];
					}
					$tracked = add_query_arg(
						array(
							'aime_track' => 'click',
							'hash'       => $email->email_hash,
							'token'      => $token,
							'url'        => rawurlencode( $original ),
							'sig'        => \WPSpace\AiMarketingExpert\Modules\EmailMarketing\EmailMarketingModule::create_url_signature( (int) $email->campaign_id, (int) $email->subscriber_id, $original ),
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

		$pixel_url = add_query_arg(
			array(
				'aime_track' => 'open',
				'hash'       => $email->email_hash,
				'token'      => $token,
			),
			home_url()
		);
		$pixel = '<img src="' . esc_url( $pixel_url ) . '" width="1" height="1" style="display:none" alt="" />';

		return false !== stripos( $body, '</body>' ) ? str_ireplace( '</body>', $pixel . '</body>', $body ) : $body . $pixel;
	}

	private function append_footer( string $body, object $email ): string {
		$body             = $this->strip_template_unsubscribe_markup( $body );
		$footer           = wp_kses_post( get_option( 'aime_email_footer', '' ) );
		$company_name     = sanitize_text_field( get_option( 'aime_company_name', '' ) );
		$company_address  = sanitize_textarea_field( get_option( 'aime_company_address', '' ) );
		$unsubscribe_text = sanitize_text_field( get_option( 'aime_unsubscribe_text', 'Unsubscribe' ) );
		$unsubscribe_url  = esc_url( $this->get_unsubscribe_url( $email ) );

		if ( '' === $footer && '' === $company_name && '' === $company_address && '' === $unsubscribe_text ) {
			return $body;
		}

		$footer_parts = array();
		if ( '' !== $footer ) {
			$footer_parts[] = $footer;
		}
		if ( '' !== $company_name || '' !== $company_address ) {
			$footer_parts[] = '<p style="margin:8px 0 0;color:#64748b;font-size:12px">' . esc_html( $company_name ) . ( $company_name && $company_address ? '<br>' : '' ) . nl2br( esc_html( $company_address ) ) . '</p>';
		}
		if ( '' !== $unsubscribe_text ) {
			$footer_parts[] = '<p style="margin:8px 0 0"><a href="' . $unsubscribe_url . '" style="color:#64748b">' . esc_html( $unsubscribe_text ) . '</a></p>';
		}

		$footer_html = '<div class="aime-email-footer" style="margin-top:32px;padding-top:16px;border-top:1px solid #e2e8f0;color:#64748b;font-size:12px;text-align:center">' . implode( '', $footer_parts ) . '</div>';

		return false !== stripos( $body, '</body>' ) ? str_ireplace( '</body>', $footer_html . '</body>', $body ) : $body . $footer_html;
	}

	private function strip_template_unsubscribe_markup( string $body ): string {
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
		// Use the signed unsubscribe hash the front-end decoder expects
		// ({@see EmailMarketingModule::decode_unsubscribe_hash()}); a plain
		// tracking hash would be rejected as an invalid link.
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

	private function action_modify_pivot( int $subscriber_id, array $object_ids, string $type, string $op ): bool {
		global $wpdb;
		$p   = $wpdb->prefix;
		$now = current_time( 'mysql', true );

		if ( empty( $object_ids ) ) {
			return false;
		}

		foreach ( $object_ids as $oid ) {
			$oid = absint( $oid );
			if ( 'add' === $op ) {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$p}aime_subscriber_pivot (subscriber_id, object_id, object_type, status, created_at)
						 VALUES (%d, %d, %s, 'active', %s)",
						$subscriber_id,
						$oid,
						$type,
						$now
					)
				);
			} else {
				$wpdb->delete(
					"{$p}aime_subscriber_pivot",
					array(
						'subscriber_id' => $subscriber_id,
						'object_id'     => $oid,
						'object_type'   => $type,
					)
				);
			}
		}
		return true;
	}

	private function resolve_pivot_ids( array $settings, string $type, bool $create_missing = false ): array {
		$id_key  = $type . '_id';
		$ids_key = $type . '_ids';
		$ids     = array_filter( array_map( 'absint', (array) ( $settings[ $ids_key ] ?? array() ) ) );

		if ( empty( $ids ) && ! empty( $settings[ $id_key ] ) ) {
			$ids[] = absint( $settings[ $id_key ] );
		}

		if ( ! empty( $ids ) || 'tag' !== $type ) {
			return array_values( array_unique( $ids ) );
		}

		$tag_name = sanitize_text_field( $settings['tag'] ?? $settings['tag_name'] ?? $settings['title'] ?? '' );
		if ( '' === $tag_name ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'aime_tags';
		$slug  = sanitize_title( $tag_name );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug ) );

		if ( $id || ! $create_missing ) {
			return $id ? array( $id ) : array();
		}

		$wpdb->insert(
			$table,
			array(
				'title'      => $tag_name,
				'slug'       => $slug,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);

		return $wpdb->insert_id ? array( (int) $wpdb->insert_id ) : array();
	}

	private function action_update_contact( int $subscriber_id, array $settings ): bool {
		global $wpdb;
		$allowed = array( 'first_name', 'last_name', 'status', 'contact_type', 'company_id', 'phone', 'country', 'city', 'state' );
		$data    = array_intersect_key( $settings['fields'] ?? array(), array_flip( $allowed ) );
		if ( empty( $data ) ) {
			return false;
		}
		// Sanitize all values before storing.
		foreach ( $data as $k => $v ) {
			$data[ $k ] = 'company_id' === $k ? absint( $v ) : sanitize_text_field( $v );
		}
		$data['updated_at'] = current_time( 'mysql', true );
		return (bool) $wpdb->update( "{$wpdb->prefix}aime_subscribers", $data, array( 'id' => $subscriber_id ) );
	}

	private function action_webhook( object $row, array $settings ): bool {
		$url = $settings['url'] ?? '';
		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return false;
		}
		$payload = array(
			'subscriber_id' => $row->subscriber_id,
			'email'         => $row->email,
			'first_name'    => $row->first_name,
			'last_name'     => $row->last_name,
			'funnel_id'     => $row->funnel_id,
		);
		$resp = wp_remote_post( $url, array(
			'body'    => wp_json_encode( $payload ),
			'headers' => array( 'Content-Type' => 'application/json' ),
			'timeout' => 10,
		) );
		return ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) < 400;
	}

	private function action_condition( object $row, array $settings ): bool {
		// Evaluate condition and choose yes/no branch (handled by branching logic).
		$field    = $settings['field'] ?? '';
		$operator = $settings['operator'] ?? 'equals';
		$value    = $settings['value'] ?? '';

		if ( ! $field ) {
			return true;
		}

		global $wpdb;
		$subscriber = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aime_subscribers WHERE id = %d", $row->subscriber_id ) );
		if ( ! $subscriber ) {
			return false;
		}

		$actual = $subscriber->$field ?? '';

		switch ( $operator ) {
			case 'equals':
				return $actual == $value;
			case 'not_equals':
				return $actual != $value;
			case 'contains':
				return stripos( $actual, $value ) !== false;
			case 'not_contains':
				return stripos( $actual, $value ) === false;
			case 'greater_than':
				return (float) $actual > (float) $value;
			case 'less_than':
				return (float) $actual < (float) $value;
			case 'is_empty':
				return empty( $actual );
			case 'is_not_empty':
				return ! empty( $actual );
			default:
				return true;
		}
	}

	/* ─── Helpers ──────────────────────────────────────── */

	private function get_next_sequence( object $current ): ?object {
		global $wpdb;
		$p = $wpdb->prefix;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$p}aime_funnel_sequences
				 WHERE funnel_id = %d AND sequence > %d AND status = 'published'
				 ORDER BY sequence ASC LIMIT 1",
				$current->funnel_id,
				$current->sequence
			)
		);
	}

	private function complete_funnel( object $row, ?int $last_seq_id = null ): void {
		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}aime_funnel_subscribers",
			array(
				'status'               => 'completed',
				'last_sequence_id'     => $last_seq_id ?? $row->last_sequence_id,
				'last_sequence_status' => 'completed',
				'last_executed_time'   => current_time( 'mysql', true ),
				'next_execution_time'  => null,
				'updated_at'           => current_time( 'mysql', true ),
			),
			array( 'id' => $row->id )
		);
	}

	private function ensure_sequences_published( int $funnel_id ): void {
		global $wpdb;
		$p      = $wpdb->prefix;
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}aime_funnels WHERE id = %d", $funnel_id ) );

		if ( 'published' !== $status ) {
			return;
		}

		$wpdb->update(
			"{$p}aime_funnel_sequences",
			array(
				'status'     => 'published',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'funnel_id' => $funnel_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	private function time_exceeded(): bool {
		return ( microtime( true ) - $this->started_at ) >= $this->time_limit;
	}
}
