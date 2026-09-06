<?php
/**
 * Campaign Controller — campaigns CRUD, send, duplicate, A/B test.
 *
 * @package WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

use WPSpace\AiMarketingExpert\Modules\EmailMarketing\Services\CampaignProcessor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CampaignController {

	/* ── LIST ─────────────────────────────────────────── */

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		$per_page = absint( $request->get_param( 'per_page' ) ?: 20 );
		$page     = absint( $request->get_param( 'page' ) ?: 1 );
		$offset   = ( $page - 1 ) * $per_page;
		$status   = sanitize_text_field( $request->get_param( 'status' ) ?? '' );
		$search   = sanitize_text_field( $request->get_param( 'search' ) ?? '' );

		$where  = array( "type = 'campaign'", 'parent_id IS NULL' );
		$params = array();

		if ( $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = 'title LIKE %s';
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$p}aime_campaigns WHERE {$where_sql}";
		$data_sql  = "SELECT * FROM {$p}aime_campaigns WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

		$params_count = $params;
		$params[] = $per_page;
		$params[] = $offset;

		$total = (int) $wpdb->get_var( empty( $params_count ) ? $count_sql : $wpdb->prepare( $count_sql, ...$params_count ) ); // phpcs:ignore
		$items = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$params ) ); // phpcs:ignore

		// Attach metrics summary.
		$items = array_map( array( $this, 'attach_metrics' ), $items );

		return new \WP_REST_Response( array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		) );
	}

	/* ── SHOW ─────────────────────────────────────────── */

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aime_campaigns WHERE id = %d", absint( $request->get_param( 'id' ) ) ) );
		if ( ! $row ) {
			return new \WP_REST_Response( array( 'message' => __( 'Campaign not found.', 'ai-marketing-expert' ) ), 404 );
		}
		$row = $this->attach_metrics( $row );
		$row->ab_variants = $this->get_ab_variants( $row->id );
		return new \WP_REST_Response( $row );
	}

	/* ── CREATE ───────────────────────────────────────── */

	public function store( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$data = $this->sanitize( $request );
		$data['created_by'] = get_current_user_id();
		$data['created_at'] = current_time( 'mysql', true );

		// If the user didn't enter a campaign name, fall back to a default
		// so the campaign doesn't appear blank in the list. We rename after
		// insert so the generated name can include the new campaign ID.
		$needs_default_title = empty( trim( (string) ( $data['title'] ?? '' ) ) );

		$wpdb->insert( "{$wpdb->prefix}aime_campaigns", $data );
		$new_id = (int) $wpdb->insert_id;

		if ( $needs_default_title && $new_id ) {
			$wpdb->update(
				"{$wpdb->prefix}aime_campaigns",
				/* translators: %d: new campaign draft ID. */
			array( 'title' => sprintf( __( 'Draft #%d', 'ai-marketing-expert' ), $new_id ) ),
				array( 'id' => $new_id )
			);
		}

		return new \WP_REST_Response( array( 'id' => $new_id, 'message' => __( 'Campaign created.', 'ai-marketing-expert' ) ), 201 );
	}

	/* ── UPDATE ───────────────────────────────────────── */

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$id = absint( $request->get_param( 'id' ) );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aime_campaigns WHERE id = %d", $id ) ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Campaign not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$data = $this->sanitize( $request );
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( "{$wpdb->prefix}aime_campaigns", $data, array( 'id' => $id ) );

		return new \WP_REST_Response( array( 'message' => __( 'Campaign updated.', 'ai-marketing-expert' ) ) );
	}

	/* ── DELETE ────────────────────────────────────────── */

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );

		$wpdb->delete( "{$p}aime_campaigns", array( 'id' => $id ) );
		$wpdb->delete( "{$p}aime_campaigns", array( 'parent_id' => $id ) ); // A/B variants.
		$wpdb->delete( "{$p}aime_campaign_emails", array( 'campaign_id' => $id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}aime_campaign_url_metrics WHERE campaign_id = %d", $id ) );

		return new \WP_REST_Response( array( 'message' => __( 'Campaign deleted.', 'ai-marketing-expert' ) ) );
	}

	/* ── DUPLICATE ────────────────────────────────────── */

	public function duplicate( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p   = $wpdb->prefix;
		$id  = absint( $request->get_param( 'id' ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}aime_campaigns WHERE id = %d", $id ), ARRAY_A );

		if ( ! $row ) {
			return new \WP_REST_Response( array( 'message' => __( 'Campaign not found.', 'ai-marketing-expert' ) ), 404 );
		}

		unset( $row['id'] );
		$row['title']      = $row['title'] . ' (Copy)';
		$row['status']     = 'draft';
		$row['created_at'] = current_time( 'mysql', true );
		$row['updated_at'] = null;
		$row['recipients_count'] = 0;

		$wpdb->insert( "{$p}aime_campaigns", $row );
		return new \WP_REST_Response( array( 'id' => (int) $wpdb->insert_id, 'message' => __( 'Campaign duplicated.', 'ai-marketing-expert' ) ), 201 );
	}

	/* ── SEND / SCHEDULE ─────────────────────────────── */

	/**
	 * Number of campaigns sent or scheduled this calendar month (free-tier metering).
	 */
	private function get_monthly_send_count(): int {
		$counts = get_option( 'aime_campaign_send_counts', array() );
		$month  = gmdate( 'Ym' );
		return (int) ( $counts[ $month ] ?? 0 );
	}

	/**
	 * Increment the monthly send counter, pruning past months.
	 */
	private function increment_monthly_send_count(): void {
		global $wpdb;

		$month = gmdate( 'Ym' );

		// Read-modify-write on the option raced: two concurrent sends both read
		// N and both wrote N+1, so one campaign was free. An atomic UPDATE with
		// the old value in the WHERE clause makes the loser retry against the
		// value the winner actually committed.
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$raw = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					'aime_campaign_send_counts'
				)
			);

			if ( null === $raw ) {
				// No row yet — add_option is atomic on the unique option_name
				// index, so a concurrent writer losing this race just retries.
				if ( add_option( 'aime_campaign_send_counts', array( $month => 1 ), '', false ) ) {
					return;
				}
				continue;
			}

			$counts = maybe_unserialize( $raw );
			$counts = is_array( $counts ) ? $counts : array();
			// Only the current month is kept; past months are pruned on write.
			$next = array( $month => (int) ( $counts[ $month ] ?? 0 ) + 1 );

			$updated = $wpdb->update(
				$wpdb->options,
				array( 'option_value' => maybe_serialize( $next ) ),
				array(
					'option_name'  => 'aime_campaign_send_counts',
					'option_value' => $raw,
				)
			);

			if ( $updated ) {
				wp_cache_delete( 'aime_campaign_send_counts', 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				return;
			}
		}

		// Five contended attempts is far past any real concurrency here; fall
		// back rather than silently skipping the increment entirely.
		$counts = get_option( 'aime_campaign_send_counts', array() );
		$counts = is_array( $counts ) ? $counts : array();
		update_option( 'aime_campaign_send_counts', array( $month => (int) ( $counts[ $month ] ?? 0 ) + 1 ), false );
	}

	/**
	 * Free-plan usage snapshot for the Email Marketing UI.
	 *
	 * Surfaced so a free user sees the wall before they hit it — previously the
	 * only signal was a 403 after clicking Send.
	 *
	 * @return \WP_REST_Response
	 */
	public function usage(): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		$has_pro = aime_has_pro();
		$limits  = aime_free_limits();

		$campaigns_used = $this->get_monthly_send_count();
		$campaigns_max  = (int) ( $limits['campaigns_per_month'] ?? 30 );
		$scheduled_used = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_campaigns WHERE status = 'scheduled'" );
		$scheduled_max  = (int) ( $limits['email_scheduled_campaigns'] ?? 3 );
		$funnels_used   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_funnels" );
		$funnels_max    = (int) ( $limits['email_funnels'] ?? 2 );

		// `remaining` is null on Pro, which the UI reads as "unlimited" — a
		// large number would render as a countdown to a wall that isn't there.
		$block = static function ( int $used, int $max ) use ( $has_pro ): array {
			return array(
				'used'      => $used,
				'limit'     => $has_pro ? null : $max,
				'remaining' => $has_pro ? null : max( 0, $max - $used ),
			);
		};

		return new \WP_REST_Response( array(
			'has_pro'             => $has_pro,
			'campaigns_per_month' => $block( $campaigns_used, $campaigns_max ),
			'scheduled_campaigns' => $block( $scheduled_used, $scheduled_max ),
			'automations'         => $block( $funnels_used, $funnels_max ),
		) );
	}

	public function send( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );

		$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}aime_campaigns WHERE id = %d", $id ) );
		if ( ! $campaign ) {
			return new \WP_REST_Response( array( 'message' => __( 'Campaign not found.', 'ai-marketing-expert' ) ), 404 );
		}

		if ( ! in_array( $campaign->status, array( 'draft', 'scheduled' ), true ) ) {
			// Send Now on an already-running campaign is idempotent: keep the queue
			// runner scheduled and report success so the UI moves to the progress
			// screen instead of showing a confusing error (which previously caused
			// users to keep clicking Send Now).
			if ( in_array( $campaign->status, array( 'working', 'sending' ), true ) ) {
				$this->schedule_queue_runner();
				return new \WP_REST_Response( array( 'message' => __( 'Campaign is now sending.', 'ai-marketing-expert' ) ) );
			}

			$status_messages = array(
				'paused' => __( 'This campaign is paused. Use the Resume button to continue sending.', 'ai-marketing-expert' ),
				'sent'   => __( 'This campaign has already been sent. Duplicate it to send again, or use Retry Failed to resend failed emails.', 'ai-marketing-expert' ),
				'failed' => __( 'This campaign failed to send. Use Retry Failed to attempt resending, or check your SMTP settings.', 'ai-marketing-expert' ),
			);
			$msg = $status_messages[ $campaign->status ] ?? __( 'Campaign cannot be sent in its current status.', 'ai-marketing-expert' );
			return new \WP_REST_Response( array( 'message' => $msg ), 400 );
		}

		$scheduled_at = sanitize_text_field( $request->get_param( 'scheduled_at' ) ?? '' );

		// Free-tier monthly campaign metering (draft → send/schedule transitions only,
		// so resume/retry on the same campaign is never double-counted).
		if ( ! aime_has_pro() ) {
			$limits    = aime_free_limits();
			$max_month = (int) ( $limits['campaigns_per_month'] ?? 30 );
			if ( $this->get_monthly_send_count() >= $max_month ) {
				return new \WP_REST_Response( array(
					'message'       => sprintf(
						/* translators: %d: number of campaigns allowed per month on the free plan. */
						__( 'Free sites can send %d campaigns per month. Upgrade to Pro for unlimited campaigns.', 'ai-marketing-expert' ),
						$max_month
					),
					'limit_reached' => true,
				), 403 );
			}
		}

		if ( $scheduled_at ) {
			if ( ! aime_has_pro() ) {
				$limits    = aime_free_limits();
				$max_free  = (int) ( $limits['email_scheduled_campaigns'] ?? 3 );
				$scheduled = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_campaigns WHERE status = 'scheduled'" );
				if ( $scheduled >= $max_free ) {
					return new \WP_REST_Response( array(
						'message'       => sprintf(
							/* translators: %d: number of campaigns that may be scheduled at once on the free plan. */
							__( 'Free sites can schedule up to %d campaigns. Upgrade to Pro for unlimited scheduled campaigns.', 'ai-marketing-expert' ),
							$max_free
						),
						'limit_reached' => true,
					), 403 );
				}
			}
			$wpdb->update( "{$p}aime_campaigns", array(
				'status'       => 'scheduled',
				'scheduled_at' => $scheduled_at,
				'updated_at'   => current_time( 'mysql', true ),
			), array( 'id' => $id ) );
			if ( 'scheduled' !== $campaign->status ) {
				$this->increment_monthly_send_count();
			}
			return new \WP_REST_Response( array( 'message' => __( 'Campaign scheduled.', 'ai-marketing-expert' ) ) );
		}

		// Immediate send — mark the campaign as 'working' and let the background
		// queue runner plus the progress-screen polling (process_tick) resolve the
		// audience and send the emails. Processing is intentionally NOT run inline
		// here: doing so blocked the response for up to the 30s time box, which made
		// the UI appear stuck and could trip PHP's max_execution_time.
		$wpdb->update( "{$p}aime_campaigns", array(
			'status'     => 'working',
			'updated_at' => current_time( 'mysql', true ),
		), array( 'id' => $id ) );

		// Scheduled campaigns were already counted when they were scheduled.
		if ( 'draft' === $campaign->status ) {
			$this->increment_monthly_send_count();
		}

		$this->schedule_queue_runner();

		return new \WP_REST_Response( array( 'message' => __( 'Campaign is now sending.', 'ai-marketing-expert' ) ) );
	}

	/* ── SEND TEST ────────────────────────────────────── */

	public function send_test( \WP_REST_Request $request ): \WP_REST_Response {
		$id    = absint( $request->get_param( 'id' ) );
		$email = sanitize_email( $request->get_param( 'email' ) );

		if ( ! is_email( $email ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Invalid email address.', 'ai-marketing-expert' ) ), 400 );
		}

		global $wpdb;
		$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aime_campaigns WHERE id = %d", $id ) );
		if ( ! $campaign ) {
			return new \WP_REST_Response( array( 'message' => __( 'Campaign not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$from_name  = get_option( 'aime_from_name', get_bloginfo( 'name' ) );
		$from_email = get_option( 'aime_from_email', get_option( 'admin_email' ) );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			"From: {$from_name} <{$from_email}>",
		);

		$campaign_subject = $campaign->email_subject ?: $campaign->title;
		$subject          = sprintf(
			/* translators: %s: Campaign subject or title. */
			__( '[TEST] %s', 'ai-marketing-expert' ),
			$campaign_subject
		);
		$body             = $campaign->email_body ?: sprintf( '<p>%s</p>', esc_html__( 'No content', 'ai-marketing-expert' ) );

		// Test mail must look exactly like a real send, footer included. There is
		// no subscriber behind a test, so the unsubscribe link points at the
		// preview-safe site URL rather than a signed per-subscriber token.
		$body = \WPSpace\AiMarketingExpert\Modules\EmailMarketing\Services\CampaignProcessor::render_with_footer( $body, home_url( '/' ) );

		$sent = wp_mail( $email, $subject, $body, $headers );

		return new \WP_REST_Response( array(
			'success' => $sent,
			'message' => $sent ? __( 'Test email sent.', 'ai-marketing-expert' ) : __( 'Failed to send test email.', 'ai-marketing-expert' ),
		) );
	}

	/* ── A/B VARIANT ──────────────────────────────────── */

	public function create_variant( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array( 'message' => __( 'A/B testing is available in Pro.', 'ai-marketing-expert' ) ), 403 );
		}

		global $wpdb;
		$p         = $wpdb->prefix;
		$parent_id = absint( $request->get_param( 'id' ) );
		$parent    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}aime_campaigns WHERE id = %d", $parent_id ), ARRAY_A );

		if ( ! $parent ) {
			return new \WP_REST_Response( array( 'message' => __( 'Parent campaign not found.', 'ai-marketing-expert' ) ), 404 );
		}

		unset( $parent['id'] );
		$parent['parent_id']     = $parent_id;
		$parent['title']         = $parent['title'] . ' (Variant)';
		$parent['email_subject'] = sanitize_text_field( $request->get_param( 'email_subject' ) ?? $parent['email_subject'] );
		$parent['status']        = 'draft';
		$parent['created_at']    = current_time( 'mysql', true );
		$parent['updated_at']    = null;
		$parent['recipients_count'] = 0;

		$wpdb->insert( "{$p}aime_campaigns", $parent );
		return new \WP_REST_Response( array( 'id' => (int) $wpdb->insert_id, 'message' => __( 'A/B variant created.', 'ai-marketing-expert' ) ), 201 );
	}

	/* ── PROGRESS ─────────────────────────────────────── */

	public function progress( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );

		$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}aime_campaigns WHERE id = %d", $id ) );
		if ( ! $campaign ) {
			return new \WP_REST_Response( array( 'message' => __( 'Campaign not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
					SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
					SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
					SUM(CASE WHEN is_open = 1 THEN 1 ELSE 0 END) AS opened
				 FROM {$p}aime_campaign_emails WHERE campaign_id = %d",
				$id
			)
		);

		// Unique clickers, not click events: a recipient who clicks 3 different
		// links is one clicker. Counting events makes click-to-open exceed 100%.
		$clicks = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT subscriber_id) FROM {$p}aime_campaign_url_metrics WHERE campaign_id = %d AND type = 'click'", $id )
		);

		$unsubscribes = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT subscriber_id) FROM {$p}aime_campaign_url_metrics WHERE campaign_id = %d AND type = 'unsubscribe'", $id )
		);
		$settings = get_option( 'aime_settings', array() );
		$batch_size = max( 1, (int) ( $settings['batch_size'] ?? 50 ) );

		$sent    = (int) ( $stats->sent ?? 0 );
		$failed  = (int) ( $stats->failed ?? 0 );
		$pending = (int) ( $stats->pending ?? 0 );
		$expected_total = $this->count_expected_recipients( $campaign );
		$total          = $expected_total > 0 ? $expected_total : max(
			(int) ( $stats->total ?? 0 ),
			(int) ( $campaign->recipients_count ?? 0 )
		);
		if ( 0 === $pending && in_array( $campaign->status, array( 'working', 'sending' ), true ) ) {
			$pending = max( 0, $total - $sent - $failed );
		}

		$display_status = $this->derive_display_status( $campaign->status, $sent, $failed, $pending, $total );

		return new \WP_REST_Response( array(
			'campaign' => $campaign,
			'status'   => $display_status,
			'scheduled_at' => $campaign->scheduled_at,
			'time_remaining' => $this->get_time_remaining( $campaign->scheduled_at ),
			'total'    => $total,
			'sent'     => $sent,
			'failed'   => $failed,
			'pending'  => $pending,
			'opened'   => (int) ( $stats->opened ?? 0 ),
			'clicks'   => $clicks,
			'unsubscribes' => $unsubscribes,
			'open_rate' => ( (int) ( $stats->sent ?? 0 ) ) > 0 ? round( ( (int) ( $stats->opened ?? 0 ) / (int) $stats->sent ) * 100, 1 ) : 0,
			'click_rate' => ( (int) ( $stats->sent ?? 0 ) ) > 0 ? round( ( $clicks / (int) $stats->sent ) * 100, 1 ) : 0,
			'batch_size' => $batch_size,
		) );
	}

	/* ── PROCESS QUEUE TICK ───────────────────────────── */

	public function process_tick( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p         = $wpdb->prefix;
		$id        = absint( $request->get_param( 'id' ) );
		$action    = sanitize_key( $request->get_param( 'action' ) ?? '' );
		$lock_key = 'aime_email_queue_runner_lock';

		if ( 'pause' === $action ) {
			// Match both 'working' (audience still enqueuing) and 'sending' so pause never silently fails.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$p}aime_campaigns
					 SET status = 'paused', updated_at = %s
					 WHERE id = %d AND status IN ('working','sending')",
					current_time( 'mysql', true ),
					$id
				)
			);
			return $this->progress( $request );
		}

		if ( 'end' === $action ) {
			// Terminal stop: cancel every pending row and mark the campaign finished so the UI shows final status.
			$now = current_time( 'mysql', true );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$p}aime_campaign_emails
					 SET status = 'cancelled', note = 'Campaign ended by user', updated_at = %s
					 WHERE campaign_id = %d AND status = 'pending'",
					$now,
					$id
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$p}aime_campaigns
					 SET status = 'sent', note = 'Campaign ended manually.', updated_at = %s
					 WHERE id = %d AND status IN ('working','sending','paused')",
					$now,
					$id
				)
			);
			$this->release_smtp_limit_waits( $id );
			return $this->progress( $request );
		}

		if ( 'resume' === $action ) {
			$wpdb->update(
				"{$p}aime_campaigns",
				array( 'status' => 'sending', 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => $id, 'status' => 'paused' )
			);
			$this->release_smtp_limit_waits( $id );
		}

		if ( 'retry_failed' === $action ) {
			// Reset failed queue rows back to pending so the processor picks them up.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$p}aime_campaign_emails
					 SET status = 'pending', note = NULL, scheduled_at = NULL, updated_at = %s
					 WHERE campaign_id = %d AND status = 'failed'",
					current_time( 'mysql', true ),
					$id
				)
			);
			// Re-open the campaign so the processor will process it.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$p}aime_campaigns
					 SET status = 'sending', note = NULL, updated_at = %s
					 WHERE id = %d AND status IN ('sent','failed')",
					current_time( 'mysql', true ),
					$id
				)
			);
		}

		if ( get_transient( $lock_key ) ) {
			$this->schedule_queue_runner();
			return $this->progress( $request );
		}

		$before = $this->get_completed_queue_count( $id );

		set_transient( $lock_key, 1, MINUTE_IN_SECONDS );

		try {
			( new CampaignProcessor() )->process( true );
		} finally {
			delete_transient( $lock_key );
		}
		$this->schedule_queue_runner();

		$response = $this->progress( $request );
		$data     = $response->get_data();
		$data['processed_now'] = max( 0, $this->get_completed_queue_count( $id ) - $before );
		$response->set_data( $data );

		return $response;
	}

	/* ── PRIVATE HELPERS ──────────────────────────────── */

	private function get_completed_queue_count( int $campaign_id ): int {
		global $wpdb;
		$p = $wpdb->prefix;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}aime_campaign_emails WHERE campaign_id = %d AND status IN ('sent','failed')",
				$campaign_id
			)
		);
	}

	private function release_smtp_limit_waits( int $campaign_id ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$p}aime_campaign_emails
				 SET scheduled_at = NULL, updated_at = %s
				 WHERE campaign_id = %d AND status = 'pending' AND note = %s",
				current_time( 'mysql', true ),
				$campaign_id,
				\WPSpace\AiMarketingExpert\SmtpProvider::LIMIT_WAIT_NOTE
			)
		);
	}

	/**
	 * Statuses a plain create/update request is allowed to set.
	 *
	 * Everything else — scheduled, working, sending, sent, paused, failed — is a
	 * lifecycle state owned by send()/pause()/stop() and the queue processor.
	 * `status` used to be an ordinary text field here, which meant a PUT could
	 * set 'working' directly; CampaignProcessor claims rows by status alone, so
	 * that walked straight past the free-tier campaign metering in send() and
	 * never incremented the counter either. It also let any campaign be flipped
	 * to 'sent' out of band, which corrupts the progress screen.
	 */
	private const EDITABLE_STATUSES = array( 'draft' );

	private function sanitize( \WP_REST_Request $r ): array {
		$data = array();
		$text_fields = array( 'title', 'slug', 'email_subject', 'email_pre_header', 'design_template', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' );
		foreach ( $text_fields as $f ) {
			if ( $r->has_param( $f ) ) {
				$data[ $f ] = sanitize_text_field( $r->get_param( $f ) );
			}
		}
		if ( $r->has_param( 'status' ) ) {
			$status = sanitize_text_field( $r->get_param( 'status' ) );
			// Silently dropped rather than rejected: the editor posts the whole
			// row back on save, so erroring on an unchanged 'sent' would make
			// every edit of a delivered campaign fail.
			if ( in_array( $status, self::EDITABLE_STATUSES, true ) ) {
				$data['status'] = $status;
			}
		}
		if ( $r->has_param( 'email_body' ) ) {
			$data['email_body'] = wp_kses_post( $r->get_param( 'email_body' ) );
		}
		if ( $r->has_param( 'template_id' ) ) {
			$data['template_id'] = absint( $r->get_param( 'template_id' ) );
		}
		if ( $r->has_param( 'utm_status' ) ) {
			$data['utm_status'] = $r->get_param( 'utm_status' ) ? 1 : 0;
		}
		if ( $r->has_param( 'scheduled_at' ) ) {
			$data['scheduled_at'] = sanitize_text_field( $r->get_param( 'scheduled_at' ) );
		}
		if ( $r->has_param( 'settings' ) ) {
			$settings = map_deep( $r->get_param( 'settings' ), 'sanitize_text_field' );
			if ( ! aime_has_pro() && is_array( $settings ) ) {
				unset( $settings['segments'], $settings['audience_offset'], $settings['audience_limit'], $settings['utm_source'], $settings['utm_medium'], $settings['utm_campaign'], $settings['send_throttle'] );
			}
			$data['settings'] = wp_json_encode( $settings );
		}
		return $data;
	}

	private function schedule_queue_runner(): void {
		if ( ! wp_next_scheduled( 'aime_run_email_queue' ) ) {
			wp_schedule_single_event( time() + 15, 'aime_run_email_queue' );
		}
	}

	private function attach_metrics( object $campaign ): object {
		global $wpdb;
		$p = $wpdb->prefix;

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_emails,
					SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
					SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
					SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
					SUM(CASE WHEN is_open = 1 THEN 1 ELSE 0 END) AS opened,
					SUM(CASE WHEN click_counter > 0 THEN 1 ELSE 0 END) AS total_clicks
				 FROM {$p}aime_campaign_emails WHERE campaign_id = %d",
				$campaign->id
			)
		);

		$sent    = (int) ( $stats->sent ?? 0 );
		$failed  = (int) ( $stats->failed ?? 0 );
		$pending = (int) ( $stats->pending ?? 0 );
		$total   = (int) ( $stats->total_emails ?? 0 );

		$campaign->metrics = array(
			'total_emails' => $total,
			'sent'         => $sent,
			'opened'       => (int) ( $stats->opened ?? 0 ),
			'total_clicks' => (int) ( $stats->total_clicks ?? 0 ),
			'open_rate'    => $sent > 0 ? round( ( (int) ( $stats->opened ?? 0 ) / $sent ) * 100, 1 ) : 0,
			'click_rate'   => $sent > 0 ? round( ( (int) ( $stats->total_clicks ?? 0 ) / $sent ) * 100, 1 ) : 0,
		);
		$campaign->sent_count     = $sent;
		$campaign->open_rate      = $campaign->metrics['open_rate'];
		$campaign->click_rate     = $campaign->metrics['click_rate'];
		$campaign->time_remaining = $this->get_time_remaining( $campaign->scheduled_at ?? null );
		$campaign->status         = $this->derive_display_status( $campaign->status, $sent, $failed, $pending, $total );

		return $campaign;
	}

	/**
	 * Derives the correct display status from queue counts.
	 * Handles the timing window where all emails are processed but
	 * finalise_campaigns() hasn't updated the campaign row yet.
	 */
	private function derive_display_status( string $db_status, int $sent, int $failed, int $pending, int $total ): string {
		if (
			in_array( $db_status, array( 'working', 'sending' ), true ) &&
			$total > 0 &&
			$pending === 0 &&
			( $sent + $failed ) >= $total
		) {
			return ( $sent === 0 && $failed > 0 ) ? 'failed' : 'sent';
		}
		return $db_status;
	}

	private function get_time_remaining( ?string $scheduled_at ): int {
		if ( empty( $scheduled_at ) ) {
			return 0;
		}

		$scheduled_timestamp = strtotime( $scheduled_at . ' UTC' );
		if ( false === $scheduled_timestamp ) {
			return 0;
		}

		return max( 0, $scheduled_timestamp - time() );
	}

	private function count_expected_recipients( object $campaign ): int {
		global $wpdb;
		$p = $wpdb->prefix;

		$settings = json_decode( $campaign->settings ?? '{}', true );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$send_all      = ! empty( $settings['all'] );
		$list_ids      = array_filter( array_map( 'absint', $settings['lists'] ?? $settings['list_ids'] ?? array() ) );
		$tag_ids       = array_filter( array_map( 'absint', $settings['tags'] ?? $settings['tag_ids'] ?? array() ) );
		$exclude_lists = array_filter( array_map( 'absint', $settings['exclude_lists'] ?? array() ) );
		$exclude_tags  = array_filter( array_map( 'absint', $settings['exclude_tags'] ?? array() ) );

		if ( ! $send_all && empty( $list_ids ) && empty( $tag_ids ) ) {
			return 0;
		}

		$conditions = array( 's.status = %s' );
		$joins      = '';
		$args       = array();

		if ( ! $send_all ) {
			if ( ! empty( $list_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $list_ids ), '%d' ) );
				$joins       .= " INNER JOIN {$p}aime_subscriber_pivot spL ON spL.subscriber_id = s.id AND spL.object_type = 'list' AND spL.object_id IN ({$placeholders})";
				$args         = array_merge( $args, $list_ids );
			}

			if ( ! empty( $tag_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $tag_ids ), '%d' ) );
				$joins       .= " INNER JOIN {$p}aime_subscriber_pivot spT ON spT.subscriber_id = s.id AND spT.object_type = 'tag' AND spT.object_id IN ({$placeholders})";
				$args         = array_merge( $args, $tag_ids );
			}
		}

		// The s.status placeholder appears in the WHERE clause, after any JOIN
		// placeholders and before the exclusion sub-queries, so its argument is
		// inserted here to keep positional order correct.
		$args[] = 'subscribed';

		if ( ! empty( $exclude_lists ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $exclude_lists ), '%d' ) );
			$conditions[] = "s.id NOT IN (SELECT ep.subscriber_id FROM {$p}aime_subscriber_pivot ep WHERE ep.object_type = 'list' AND ep.object_id IN ({$placeholders}))";
			$args         = array_merge( $args, $exclude_lists );
		}

		if ( ! empty( $exclude_tags ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $exclude_tags ), '%d' ) );
			$conditions[] = "s.id NOT IN (SELECT ep.subscriber_id FROM {$p}aime_subscriber_pivot ep WHERE ep.object_type = 'tag' AND ep.object_id IN ({$placeholders}))";
			$args         = array_merge( $args, $exclude_tags );
		}

		$query = "SELECT COUNT(DISTINCT s.id) FROM {$p}aime_subscribers s {$joins} WHERE " . implode( ' AND ', $conditions );
		$query = $wpdb->prepare( $query, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$total  = (int) $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$offset = isset( $settings['audience_offset'] ) ? max( 0, (int) $settings['audience_offset'] ) : 0;
		$limit  = isset( $settings['audience_limit'] ) ? max( 0, (int) $settings['audience_limit'] ) : 0;

		if ( $offset > 0 || $limit > 0 ) {
			$total = max( 0, $total - $offset );
			if ( $limit > 0 ) {
				$total = min( $total, $limit );
			}
		}

		return $total;
	}

	private function get_ab_variants( int $parent_id ): array {
		global $wpdb;
		$variants = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aime_campaigns WHERE parent_id = %d", $parent_id )
		);
		return array_map( array( $this, 'attach_metrics' ), $variants ?: array() );
	}
}
