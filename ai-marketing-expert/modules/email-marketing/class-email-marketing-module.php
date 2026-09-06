<?php
/**
 * Email Marketing CRM Module — Bootstrap (v2 rewrite).
 *
 * @package WPSpace\AiMarketingExpert\Modules\EmailMarketing
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

namespace WPSpace\AiMarketingExpert\Modules\EmailMarketing;

use WPSpace\AiMarketingExpert\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EmailMarketingModule extends Module {

	private bool $front_tracking_handled = false;

	/* ── Module identity ────────────────────────────────── */

	public function get_id(): string {
		return 'email-marketing';
	}

	public function get_name(): string {
		return __( 'Email Marketing', 'ai-marketing-expert' );
	}

	public function get_description(): string {
		return __( 'Full-featured email marketing CRM with campaigns, automations, contacts, and AI-powered intelligence.', 'ai-marketing-expert' );
	}

	public function get_icon(): string {
		return 'email-alt';
	}

	public function get_version(): string {
		return '2.0.0';
	}

	/* ── Initialisation ─────────────────────────────────── */

	public function init(): void {
		$this->maybe_create_tables();

		// Front-end tracking handler (query-string based, runs before templates).
		add_action( 'init', array( $this, 'handle_front_tracking' ) );
		if ( ! empty( $_GET['aime_track'] ) ) {
			$this->handle_front_tracking();
		}

		// Cron handlers.
		add_action( 'aime_process_email_queue', array( $this, 'handle_email_queue' ) );
		add_action( 'aime_run_email_queue', array( $this, 'handle_email_queue_runner' ) );
		add_action( 'aime_process_automations', array( $this, 'handle_automations' ) );
		add_action( 'aime_daily_cleanup', array( $this, 'daily_cleanup' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
		add_filter( 'aime_email-marketing_dashboard_stats', array( $this, 'get_stats' ) );
		$this->ensure_cron_events();

		// Public subscribe shortcode.
		add_shortcode( 'aime_subscribe', array( $this, 'render_subscribe_form' ) );

		// Fire automation triggers when a subscriber is created.
		add_action( 'aime_subscriber_created', array( $this, 'fire_subscriber_trigger' ), 10, 2 );
		add_action( 'aime_subscriber_status_change', array( $this, 'fire_status_trigger' ), 10, 3 );
		add_action( 'aime_subscriber_tag_added', array( $this, 'fire_pivot_trigger' ), 10, 3 );
		add_action( 'aime_subscriber_tag_removed', array( $this, 'fire_pivot_trigger' ), 10, 3 );
		add_action( 'aime_subscriber_list_added', array( $this, 'fire_pivot_trigger' ), 10, 3 );
		add_action( 'aime_subscriber_list_removed', array( $this, 'fire_pivot_trigger' ), 10, 3 );
		add_action( 'aime_email_opened', array( $this, 'fire_email_opened_trigger' ), 10, 2 );
		add_action( 'aime_link_clicked', array( $this, 'fire_link_clicked_trigger' ), 10, 3 );
		add_action( 'user_register', array( $this, 'fire_user_registered_trigger' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'fire_woocommerce_order_completed_trigger' ), 10, 1 );
	}

	/* ── Automation trigger wiring ───────────────────────── */

	/**
	 * Fires all published automations whose trigger_name matches 'subscriber_created'.
	 *
	 * @param int   $subscriber_id Subscriber ID.
	 * @param array $data          Subscriber data.
	 */
	public function fire_subscriber_trigger( int $subscriber_id, array $data ): void {
		$this->fire_automation_trigger( 'subscriber_created', $subscriber_id );
	}

	public function fire_status_trigger( int $subscriber_id, string $old_status, string $new_status ): void {
		$this->fire_automation_trigger( 'subscriber_status_change', $subscriber_id, array( 'status' => $new_status, 'old_status' => $old_status ) );
	}

	public function fire_pivot_trigger( int $subscriber_id, int $object_id, string $type ): void {
		$hook = current_filter();
		$map  = array(
			'aime_subscriber_tag_added'    => 'tag_added',
			'aime_subscriber_tag_removed'  => 'tag_removed',
			'aime_subscriber_list_added'   => 'list_added',
			'aime_subscriber_list_removed' => 'list_removed',
		);
		if ( isset( $map[ $hook ] ) ) {
			$this->fire_automation_trigger( $map[ $hook ], $subscriber_id, array( 'object_id' => $object_id, 'type' => $type ) );
		}
	}

	public function fire_user_registered_trigger( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}
		$subscriber_id = $this->find_subscriber_id_by_email( $user->user_email );
		if ( $subscriber_id ) {
			$this->fire_automation_trigger( 'user_registered', $subscriber_id );
		}
	}

	public function fire_email_opened_trigger( int $subscriber_id, int $campaign_id ): void {
		$this->fire_automation_trigger( 'email_opened', $subscriber_id, array( 'campaign_id' => $campaign_id ) );
	}

	public function fire_link_clicked_trigger( int $subscriber_id, int $campaign_id, string $url ): void {
		$this->fire_automation_trigger( 'link_clicked', $subscriber_id, array( 'campaign_id' => $campaign_id, 'url' => $url ) );
	}

	public function fire_woocommerce_order_completed_trigger( int $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$subscriber_id = $this->find_subscriber_id_by_email( $order->get_billing_email() );
		if ( $subscriber_id ) {
			$this->fire_automation_trigger( 'woo_order_completed', $subscriber_id );
		}
	}

	private function fire_automation_trigger( string $trigger_name, int $subscriber_id, array $context = array() ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		$funnels = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, settings FROM {$p}aime_funnels WHERE trigger_name = %s AND status = %s",
			$trigger_name,
			'published'
		) );

		if ( empty( $funnels ) ) {
			return;
		}

		$processor = new Services\FunnelProcessor();
		foreach ( $funnels as $funnel ) {
			$settings = json_decode( (string) ( $funnel->settings ?? '' ), true );
			$settings = is_array( $settings ) ? $settings : array();
			if ( ! $this->trigger_context_matches( $settings, $context ) ) {
				continue;
			}
			$processor->trigger( (int) $funnel->id, $subscriber_id );
		}
	}

	private function trigger_context_matches( array $settings, array $context ): bool {
		if ( ! empty( $settings['tag_id'] ) && (int) $settings['tag_id'] !== (int) ( $context['object_id'] ?? 0 ) ) {
			return false;
		}
		if ( ! empty( $settings['list_id'] ) && (int) $settings['list_id'] !== (int) ( $context['object_id'] ?? 0 ) ) {
			return false;
		}
		if ( ! empty( $settings['url'] ) && ! empty( $context['url'] ) && untrailingslashit( (string) $settings['url'] ) !== untrailingslashit( (string) $context['url'] ) ) {
			return false;
		}
		return true;
	}

	private function find_subscriber_id_by_email( string $email ): int {
		if ( ! is_email( $email ) ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aime_subscribers WHERE email = %s LIMIT 1", sanitize_email( $email ) ) );
	}

	/* ── REST routes ─────────────────────────────────────── */

	public function register_routes(): void {
		$controller = new EmailRestController();
		$controller->register_routes();
	}

	/* ── Cron callbacks ──────────────────────────────────── */

	public function handle_email_queue(): void {
		$lock_key = 'aime_email_queue_runner_lock';
		if ( get_transient( $lock_key ) ) {
			// Another worker (browser tick or single-event runner) is processing. Skip to avoid concurrent enqueue/send.
			if ( $this->has_active_email_queue() ) {
				$this->schedule_email_queue_runner();
			}
			return;
		}

		set_transient( $lock_key, 1, MINUTE_IN_SECONDS );

		try {
			( new Services\CampaignProcessor() )->process();
		} finally {
			delete_transient( $lock_key );
		}

		if ( $this->has_active_email_queue() ) {
			$this->schedule_email_queue_runner();
		}
	}

	public function handle_email_queue_runner(): void {
		$lock_key = 'aime_email_queue_runner_lock';
		if ( get_transient( $lock_key ) ) {
			$this->schedule_email_queue_runner();
			return;
		}

		set_transient( $lock_key, 1, MINUTE_IN_SECONDS );

		try {
			( new Services\CampaignProcessor() )->process( true );
		} finally {
			delete_transient( $lock_key );
		}

		if ( $this->has_active_email_queue() ) {
			$this->schedule_email_queue_runner();
		}
	}

	private function schedule_email_queue_runner(): void {
		if ( ! wp_next_scheduled( 'aime_run_email_queue' ) ) {
			wp_schedule_single_event( time() + 15, 'aime_run_email_queue' );
		}
	}

	private function has_active_email_queue(): bool {
		global $wpdb;
		$p = $wpdb->prefix;

		$active_campaigns = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$p}aime_campaigns WHERE type = 'campaign' AND status IN ('working', 'sending')"
		);
		if ( $active_campaigns > 0 ) {
			return true;
		}

		$pending_emails = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$p}aime_campaign_emails WHERE status = 'pending'"
		);

		return $pending_emails > 0;
	}

	public function handle_automations(): void {
		// Same guard the email queue uses. Without it an overlapping cron tick
		// could pick up a funnel_subscribers row that another worker had already
		// sent but not yet advanced, delivering the step's email twice.
		$lock_key = 'aime_automations_runner_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}

		set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

		try {
			( new Services\FunnelProcessor() )->followUpActions();
		} finally {
			delete_transient( $lock_key );
		}
	}

	public function add_cron_schedules( array $schedules ): array {
		$schedules['every_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every Minute', 'ai-marketing-expert' ),
		);
		return $schedules;
	}

	private function ensure_cron_events(): void {
		// Queue + automations run from the consolidated core dispatcher
		// (aime_minutely_tasks, see Plugin::run_minutely_tasks — audit P-4).
		if ( ! wp_next_scheduled( 'aime_minutely_tasks' ) ) {
			wp_schedule_event( time(), 'every_minute', 'aime_minutely_tasks' );
		}

		if ( ! wp_next_scheduled( 'aime_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'aime_daily_cleanup' );
		}
	}

	public function daily_cleanup(): void {
		global $wpdb;
		// Reclaim storage from old processed emails by clearing the rendered body,
		// but KEEP the rows. These rows hold the status, open, and click counters
		// that power campaign performance reports, so deleting them would zero out
		// historical analytics. Only the heavy email_body content is purged.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}aime_campaign_emails SET email_body = '' WHERE status IN ('sent','failed') AND email_body <> '' AND created_at < %s",
				gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}aime_activity_log WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) )
			)
		);
	}

	/* ── Dashboard stats ─────────────────────────────────── */

	public function get_stats(): array {
		global $wpdb;
		$p = $wpdb->prefix;

		return array(
			'total_contacts'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_subscribers" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'active_contacts'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_subscribers WHERE status = %s", 'subscribed' ) ),
			'total_campaigns'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_campaigns WHERE type = %s", 'campaign' ) ),
			'emails_sent'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_campaign_emails WHERE status = %s", 'sent' ) ),
			'total_automations'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_funnels WHERE status = %s", 'published' ) ),
			'total_tags'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_tags" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'total_lists'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_lists" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'total_templates'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_templates" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'open_rate'           => $this->calc_open_rate(),
		);
	}

	private function calc_open_rate(): float {
		global $wpdb;
		$p    = $wpdb->prefix;
		$sent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_campaign_emails WHERE status = %s", 'sent' ) );
		if ( $sent < 1 ) {
			return 0;
		}
		$opens = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_campaign_url_metrics WHERE type = %s", 'open' ) );
		return round( ( $opens / $sent ) * 100, 1 );
	}

	/**
	 * Per-recipient tracking-pixels arrive from mail-client proxies (Gmail,
	 * Outlook, etc.) where the IP WordPress sees is the proxy, not the
	 * recipient. Bucket the limit on (ip, email_hash) so one recipient's
	 * repeat-loads cannot lock out another recipient at the same proxy.
	 *
	 * @return bool True when the request should proceed; false when the
	 *              rate limit has been hit (caller has already sent a 429).
	 */
	private function check_tracking_rate_limit( string $action, string $bucket_key ): bool {
		$limits = array(
			'open'  => array( 30, MINUTE_IN_SECONDS ),
			'click' => array( 60, MINUTE_IN_SECONDS ),
		);
		if ( ! isset( $limits[ $action ] ) ) {
			return true;
		}
		list( $limit, $window ) = $limits[ $action ];

		// REMOTE_ADDR only: proxy headers (X-Forwarded-For etc.) are client-controlled,
		// so trusting them lets an attacker rotate fake IPs to bypass the rate limit.
		$ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$key  = 'aime_track_' . $action . '_' . md5( $ip . '|' . $bucket_key );
		$hit  = (int) get_transient( $key );

		if ( $hit >= $limit ) {
			nocache_headers();
			status_header( 429 );
			return false;
		}

		set_transient( $key, $hit + 1, $window );
		return true;
	}

	/* ── Tracking hash helpers (static, URL-safe base64) ── */

	public static function create_tracking_hash( int $campaign_id, int $subscriber_id ): string {
		$data = $campaign_id . '|' . $subscriber_id;
		$raw  = $data . '|' . hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
		return strtr( base64_encode( $raw ), '+/=', '-_~' );
	}

	public static function decode_tracking_hash( string $hash ): ?array {
		$decoded = base64_decode( strtr( $hash, '-_~', '+/=' ), true );
		if ( ! $decoded ) {
			return null;
		}
		$parts = explode( '|', $decoded );
		if ( count( $parts ) !== 3 ) {
			return null;
		}
		$campaign_id   = absint( $parts[0] );
		$subscriber_id = absint( $parts[1] );
		$expected      = hash_hmac( 'sha256', $campaign_id . '|' . $subscriber_id, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $parts[2] ) ) {
			return null;
		}
		return compact( 'campaign_id', 'subscriber_id' );
	}

	public static function create_url_signature( int $campaign_id, int $subscriber_id, string $url ): string {
		$data = $campaign_id . '|' . $subscriber_id . '|' . $url;
		return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
	}

	/**
	 * Create a signed unsubscribe URL bound to the subscriber's current status
	 * and an issuance timestamp. The link is rejected by
	 * {@see decode_unsubscribe_hash()} once it is older than 30 days or if the
	 * subscriber's status has changed in a way that invalidates the link
	 * (e.g. the user re-subscribed but an old unsubscribe link is reused).
	 *
	 * Payload: campaign_id|subscriber_id|status_at_signing|issued_at|hmac.
	 */
	public static function create_unsubscribe_hash( int $campaign_id, int $subscriber_id, string $subscriber_status ): string {
		$issued_at = time();
		$data      = $campaign_id . '|' . $subscriber_id . '|' . $subscriber_status . '|' . $issued_at;
		$mac       = hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
		$raw       = $data . '|' . $mac;
		return strtr( base64_encode( $raw ), '+/=', '-_~' );
	}

	/**
	 * Decode + verify a signed unsubscribe hash.
	 *
	 * @return array{ campaign_id: int, subscriber_id: int, status_at_signing: string, issued_at: int }|null
	 */
	public static function decode_unsubscribe_hash( string $hash ): ?array {
		$decoded = base64_decode( strtr( $hash, '-_~', '+/=' ), true );
		if ( ! $decoded ) {
			return null;
		}
		$parts = explode( '|', $decoded );
		if ( count( $parts ) !== 5 ) {
			return null;
		}

		$campaign_id        = absint( $parts[0] );
		$subscriber_id      = absint( $parts[1] );
		$status_at_signing  = (string) $parts[2];
		$issued_at          = absint( $parts[3] );
		$expected           = hash_hmac(
			'sha256',
			$campaign_id . '|' . $subscriber_id . '|' . $status_at_signing . '|' . $issued_at,
			wp_salt( 'auth' )
		);

		if ( ! hash_equals( $expected, (string) $parts[4] ) ) {
			return null;
		}

		// 30-day expiry. Hashes older than this must be re-issued.
		if ( $issued_at <= 0 || ( time() - $issued_at ) > 30 * DAY_IN_SECONDS ) {
			return null;
		}

		return array(
			'campaign_id'       => $campaign_id,
			'subscriber_id'     => $subscriber_id,
			'status_at_signing' => $status_at_signing,
			'issued_at'         => $issued_at,
		);
	}

	/* ── Front-end tracking handler ──────────────────────── */

	public function handle_front_tracking(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public email tracking/unsubscribe links use signed hashes or tokens instead of WP admin nonces.
		if ( $this->front_tracking_handled ) {
			return;
		}

		if ( empty( $_GET['aime_track'] ) ) {
			return;
		}

		$this->front_tracking_handled = true;

		$action = sanitize_text_field( wp_unslash( $_GET['aime_track'] ) );
		$hash   = sanitize_text_field( wp_unslash( $_GET['hash'] ?? '' ) );
		$token  = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );

		if ( ! $hash && ! $token ) {
			return;
		}

		switch ( $action ) {
			case 'open':
				$this->front_track_open( $hash, $token );
				break;
			case 'click':
				$this->front_track_click( $hash, $token, sanitize_text_field( wp_unslash( $_GET['url'] ?? '' ) ), sanitize_text_field( wp_unslash( $_GET['sig'] ?? '' ) ) );
				break;
			case 'unsubscribe':
				$this->front_unsubscribe( $hash );
				break;
			case 'resubscribe':
				$this->front_resubscribe( $hash );
				break;
			case 'confirm':
				$this->front_confirm( $hash );
				break;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Open tracking: look up campaign_email by MD5 email_hash, mark opened, serve pixel.
	 */
	private function front_track_open( string $email_hash, string $token = '' ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		// Rate limit on (ip, email_hash) before any DB work.
		$bucket = $email_hash ?: substr( $token, 0, 32 );
		if ( ! $this->check_tracking_rate_limit( 'open', $bucket ) ) {
			exit;
		}

		$email = $email_hash ? $wpdb->get_row( $wpdb->prepare(
			"SELECT id, campaign_id, subscriber_id FROM {$p}aime_campaign_emails WHERE email_hash = %s LIMIT 1",
			$email_hash
		) ) : null;

		if ( ! $email && $token ) {
			$data = self::decode_tracking_hash( $token );
			if ( $data ) {
				$email = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, campaign_id, subscriber_id FROM {$p}aime_campaign_emails WHERE campaign_id = %d AND subscriber_id = %d LIMIT 1",
					(int) $data['campaign_id'],
					(int) $data['subscriber_id']
				) );
			}
		}

		if ( $email ) {
			$wpdb->update( "{$p}aime_campaign_emails", array( 'is_open' => 1 ), array( 'id' => $email->id ) );

			// Record only the first open per recipient so the open metric reflects
			// unique opens and repeated pixel loads do not inflate the count.
			$already_opened = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}aime_campaign_url_metrics WHERE campaign_id = %d AND subscriber_id = %d AND type = 'open'",
				(int) $email->campaign_id,
				(int) $email->subscriber_id
			) );

			if ( 0 === $already_opened ) {
				$wpdb->insert( "{$p}aime_campaign_url_metrics", array(
					'url_id'        => 0,
					'campaign_id'   => $email->campaign_id,
					'subscriber_id' => $email->subscriber_id,
					'type'          => 'open',
					'ip_address'    => $this->get_tracking_ip(),
					'country'       => '',
					'city'          => '',
					'created_at'    => current_time( 'mysql', true ),
				) );
			}

			do_action( 'aime_email_opened', (int) $email->subscriber_id, (int) $email->campaign_id );
		}

		nocache_headers();
		header( 'Content-Type: image/gif' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
		exit;
	}

	/**
	 * Click tracking: look up campaign_email, store URL, record metric, redirect.
	 */
	private function front_track_click( string $email_hash, string $token, string $url, string $signature = '' ): void {
		global $wpdb;
		$p   = $wpdb->prefix;
		$url = rawurldecode( $url );

		// Rate limit on (ip, email_hash) before any DB work.
		$bucket = $email_hash ?: substr( $token, 0, 32 );
		if ( ! $this->check_tracking_rate_limit( 'click', $bucket ) ) {
			exit;
		}

		if ( ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		$email = $email_hash ? $wpdb->get_row( $wpdb->prepare(
			"SELECT id, campaign_id, subscriber_id FROM {$p}aime_campaign_emails WHERE email_hash = %s LIMIT 1",
			$email_hash
		) ) : null;

		if ( ! $email && $token ) {
			$data = self::decode_tracking_hash( $token );
			if ( $data ) {
				$email = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, campaign_id, subscriber_id FROM {$p}aime_campaign_emails WHERE campaign_id = %d AND subscriber_id = %d LIMIT 1",
					(int) $data['campaign_id'],
					(int) $data['subscriber_id']
				) );
			}
		}

		// No matching campaign email means the request is unauthenticated: the
		// off-site redirect below is only safe for signed campaign links, so
		// bail to a local URL rather than honouring an attacker-supplied target.
		if ( ! $email ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		$expected_signature = self::create_url_signature( (int) $email->campaign_id, (int) $email->subscriber_id, $url );
		if ( ! $signature || ! hash_equals( $expected_signature, $signature ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		// Store URL if not exists.
		$url_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}aime_url_stores WHERE url = %s LIMIT 1", $url ) );
		if ( ! $url_id ) {
			$wpdb->insert( "{$p}aime_url_stores", array( 'url' => $url, 'short' => '', 'created_at' => current_time( 'mysql', true ) ) );
			$url_id = (int) $wpdb->insert_id;
		}

		// Record only the first click per (campaign, subscriber, url) so the click
		// metric reflects unique clicks. Mail-security scanners and link
		// prefetchers (Gmail, Outlook Safe Links, corporate filters) fetch *every*
		// link in a message, which otherwise registers one click per link in the
		// email for a recipient who clicked once — or none at all.
		$existing_click_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$p}aime_campaign_url_metrics
			 WHERE campaign_id = %d AND subscriber_id = %d AND url_id = %d AND type = 'click'
			 LIMIT 1",
			(int) $email->campaign_id,
			(int) $email->subscriber_id,
			$url_id
		) );

		if ( $existing_click_id ) {
			// Repeat click on the same link: bump the raw counter, leave the
			// unique-click metric (one row per link) untouched.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$p}aime_campaign_url_metrics SET counter = counter + 1, updated_at = %s WHERE id = %d",
				current_time( 'mysql', true ),
				$existing_click_id
			) );
		} else {
			$wpdb->insert( "{$p}aime_campaign_url_metrics", array(
				'url_id'        => $url_id,
				'campaign_id'   => $email->campaign_id,
				'subscriber_id' => $email->subscriber_id,
				'type'          => 'click',
				'ip_address'    => $this->get_tracking_ip(),
				'country'       => '',
				'city'          => '',
				'created_at'    => current_time( 'mysql', true ),
			) );

			$wpdb->query( $wpdb->prepare(
				"UPDATE {$p}aime_campaign_emails SET click_counter = click_counter + 1 WHERE id = %d",
				$email->id
			) );
		}

		// Also mark as opened.
		$wpdb->update( "{$p}aime_campaign_emails", array( 'is_open' => 1 ), array( 'id' => $email->id, 'is_open' => 0 ) );
		do_action( 'aime_link_clicked', (int) $email->subscriber_id, (int) $email->campaign_id, $url );

		// Signature verified above: a signed campaign URL may intentionally point off-site.
		wp_redirect( esc_url_raw( $url ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Signed campaign URLs may intentionally point off-site.
		exit;
	}

	/**
	 * Unsubscribe: decode HMAC hash, update subscriber, serve HTML.
	 */
	private function front_unsubscribe( string $hash ): void {
		$data = self::decode_unsubscribe_hash( $hash );
		if ( ! $data || empty( $data['subscriber_id'] ) ) {
			wp_die( esc_html__( 'Invalid unsubscribe link.', 'ai-marketing-expert' ), esc_html__( 'Error', 'ai-marketing-expert' ), array( 'response' => 400 ) );
		}

		global $wpdb;
		$p             = $wpdb->prefix;
		$subscriber_id = absint( $data['subscriber_id'] );
		$campaign_id   = absint( $data['campaign_id'] ?? 0 );

		// Reject double-firing: if the subscriber is already unsubscribed,
		// the link is stale or was already used. Render the success page
		// instead of touching the DB.
		$current_status = $wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$p}aime_subscribers WHERE id = %d",
			$subscriber_id
		) );
		if ( 'unsubscribed' === $current_status ) {
			$labels = $this->get_unsubscribe_page_labels();
			$this->serve_tracking_page(
				$labels['title'],
				__( 'Already Unsubscribed', 'ai-marketing-expert' ),
				__( 'You are already unsubscribed from our emails.', 'ai-marketing-expert' ),
				$this->render_resubscribe_button( $campaign_id, $subscriber_id, $labels['resubscribe_button'] )
			);
			return;
		}

		$wpdb->update(
			"{$p}aime_subscribers",
			array( 'status' => 'unsubscribed', 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $subscriber_id )
		);

		$wpdb->insert( "{$p}aime_campaign_url_metrics", array(
			'url_id'        => 0,
			'campaign_id'   => $campaign_id,
			'subscriber_id' => $subscriber_id,
			'type'          => 'unsubscribe',
			'ip_address'    => $this->get_tracking_ip(),
			'country'       => '',
			'city'          => '',
			'created_at'    => current_time( 'mysql', true ),
		) );

		$campaign_label = '';
		if ( $campaign_id ) {
			$campaign_title = $wpdb->get_var( $wpdb->prepare(
				"SELECT title FROM {$p}aime_campaigns WHERE id = %d",
				$campaign_id
			) );
			$campaign_label = ( null !== $campaign_title && '' !== $campaign_title )
				? $campaign_title
				: "campaign #{$campaign_id}";
		}

		$wpdb->insert( "{$p}aime_activity_log", array(
			'object_type' => 'subscriber',
			'object_id'   => $subscriber_id,
			'action'      => 'unsubscribed',
			'description' => $campaign_label ? "Unsubscribed from {$campaign_label}" : 'Unsubscribed via link',
			'created_at'  => current_time( 'mysql', true ),
		) );

		$labels = $this->get_unsubscribe_page_labels();
		$this->serve_tracking_page(
			$labels['title'],
			$labels['heading'],
			$labels['message'],
			$this->render_resubscribe_button( $campaign_id, $subscriber_id, $labels['resubscribe_button'] )
		);
	}

	/**
	 * Re-subscribe handler: decode the signed hash, restore the subscriber to
	 * 'subscribed', and confirm. Reached from the unsubscribe page's button.
	 */
	private function front_resubscribe( string $hash ): void {
		$data = self::decode_unsubscribe_hash( $hash );
		if ( ! $data || empty( $data['subscriber_id'] ) ) {
			wp_die( esc_html__( 'Invalid re-subscribe link.', 'ai-marketing-expert' ), esc_html__( 'Error', 'ai-marketing-expert' ), array( 'response' => 400 ) );
		}

		global $wpdb;
		$p             = $wpdb->prefix;
		$subscriber_id = absint( $data['subscriber_id'] );

		$current_status = $wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$p}aime_subscribers WHERE id = %d",
			$subscriber_id
		) );

		if ( null === $current_status ) {
			wp_die( esc_html__( 'Subscriber not found.', 'ai-marketing-expert' ), esc_html__( 'Error', 'ai-marketing-expert' ), array( 'response' => 404 ) );
		}

		if ( 'subscribed' !== $current_status ) {
			$wpdb->update(
				"{$p}aime_subscribers",
				array( 'status' => 'subscribed', 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => $subscriber_id )
			);

			$wpdb->insert( "{$p}aime_activity_log", array(
				'object_type' => 'subscriber',
				'object_id'   => $subscriber_id,
				'action'      => 'resubscribed',
				'description' => 'Re-subscribed via unsubscribe page',
				'created_at'  => current_time( 'mysql', true ),
			) );

			do_action( 'aime_subscriber_status_change', $subscriber_id, (string) $current_status, 'subscribed' );
		}

		$this->serve_tracking_page(
			__( 'Subscribed', 'ai-marketing-expert' ),
			__( 'You\'re subscribed again', 'ai-marketing-expert' ),
			__( 'Welcome back! You will continue to receive our emails.', 'ai-marketing-expert' )
		);
	}

	/**
	 * Build the unsubscribe-page copy. Free installs always show the fixed
	 * thank-you text and a re-subscribe button; Pro installs may override the
	 * heading, message, and button label from the email settings.
	 *
	 * @return array{title:string,heading:string,message:string,resubscribe_button:string}
	 */
	private function get_unsubscribe_page_labels(): array {
		$defaults = array(
			'title'              => __( 'Unsubscribed', 'ai-marketing-expert' ),
			'heading'            => __( 'You\'ve been unsubscribed', 'ai-marketing-expert' ),
			'message'            => __( 'Thank you. You will no longer receive emails from us. Changed your mind? You can subscribe again below.', 'ai-marketing-expert' ),
			'resubscribe_button' => __( 'Subscribe again', 'ai-marketing-expert' ),
		);

		if ( ! aime_has_pro() ) {
			return $defaults;
		}

		$heading = trim( (string) get_option( 'aime_unsubscribe_heading', '' ) );
		$message = trim( (string) get_option( 'aime_unsubscribe_message', '' ) );
		$button  = trim( (string) get_option( 'aime_resubscribe_button_text', '' ) );

		return array(
			'title'              => $defaults['title'],
			'heading'            => '' !== $heading ? $heading : $defaults['heading'],
			'message'            => '' !== $message ? $message : $defaults['message'],
			'resubscribe_button' => '' !== $button ? $button : $defaults['resubscribe_button'],
		);
	}

	/**
	 * Render the signed re-subscribe button shown on the unsubscribe page.
	 */
	private function render_resubscribe_button( int $campaign_id, int $subscriber_id, string $button_text ): string {
		$resub_url = add_query_arg(
			array(
				'aime_track' => 'resubscribe',
				'hash'       => self::create_unsubscribe_hash( $campaign_id, $subscriber_id, 'unsubscribed' ),
			),
			home_url()
		);

		return '<p style="margin:1.5rem 0 0"><a href="' . esc_url( $resub_url )
			. '" style="display:inline-block;padding:10px 22px;background:#3858e9;color:#fff;border-radius:6px;text-decoration:none;font-weight:600">'
			. esc_html( $button_text ) . '</a></p>';
	}

	/**
	 * Confirm double opt-in: decode HMAC hash, update subscriber, serve HTML.
	 */
	private function front_confirm( string $hash ): void {
		$data = self::decode_tracking_hash( $hash );
		if ( ! $data || empty( $data['subscriber_id'] ) ) {
			wp_die( esc_html__( 'Invalid confirmation link.', 'ai-marketing-expert' ), esc_html__( 'Error', 'ai-marketing-expert' ), array( 'response' => 400 ) );
		}

		global $wpdb;
		$p             = $wpdb->prefix;
		$subscriber_id = absint( $data['subscriber_id'] );

		$sub = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}aime_subscribers WHERE id = %d", $subscriber_id ) );
		if ( ! $sub ) {
			wp_die( esc_html__( 'Subscriber not found.', 'ai-marketing-expert' ), esc_html__( 'Error', 'ai-marketing-expert' ), array( 'response' => 404 ) );
		}

		if ( 'pending' !== $sub->status ) {
			$msg = 'subscribed' === $sub->status
				? __( 'already confirmed', 'ai-marketing-expert' )
				: __( 'no longer active', 'ai-marketing-expert' );
			$this->serve_tracking_page(
				__( 'Subscription', 'ai-marketing-expert' ),
				__( 'Subscription Status', 'ai-marketing-expert' ),
				/* translators: %s: subscription status message */
				sprintf( __( 'Your subscription is %s.', 'ai-marketing-expert' ), $msg )
			);
		}

		$wpdb->update(
			"{$p}aime_subscribers",
			array( 'status' => 'subscribed', 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $subscriber_id )
		);

		$wpdb->insert( "{$p}aime_activity_log", array(
			'object_type' => 'subscriber',
			'object_id'   => $subscriber_id,
			'action'      => 'confirmed',
			'description' => 'Double opt-in confirmed',
			'created_at'  => current_time( 'mysql', true ),
		) );

		do_action( 'aime_subscriber_confirmed', $subscriber_id );

		$this->serve_tracking_page(
			__( 'Subscription Confirmed', 'ai-marketing-expert' ),
			__( '&#10003; Confirmed', 'ai-marketing-expert' ),
			__( 'Your subscription has been confirmed! Thank you for subscribing.', 'ai-marketing-expert' )
		);
	}

	private function serve_tracking_page( string $title, string $heading, string $message, string $extra_html = '' ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html( $title ) . '</title>'
			. '<style>body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f8fafc;color:#334155;}'
			. '.card{background:#fff;padding:3rem;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.1);text-align:center;max-width:440px}'
			. 'h1{font-size:1.5rem;margin-bottom:.5rem;color:#10b981}p{color:#64748b;line-height:1.6}</style></head>'
			. '<body><div class="card"><h1>' . wp_kses_post( $heading ) . '</h1>'
			. '<p>' . esc_html( $message ) . '</p>'
			. wp_kses_post( $extra_html )
			. '</div></body></html>';
		exit;
	}

	private function get_tracking_ip(): string {
		// REMOTE_ADDR only: proxy headers are client-controlled and trivially spoofed,
		// which would poison per-IP tracking analytics.
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '';
	}

	/* ── Pro features list ───────────────────────────────── */

	public function get_pro_features(): array {
		return array(
			'unlimited_contacts'    => __( 'Unlimited contacts', 'ai-marketing-expert' ),
			'email_automations'     => __( 'Visual automation builder', 'ai-marketing-expert' ),
			'advanced_segmentation' => __( 'Advanced audience segmentation', 'ai-marketing-expert' ),
			'ab_testing'            => __( 'A/B testing for campaigns', 'ai-marketing-expert' ),
			'custom_fields'         => __( 'Custom contact fields', 'ai-marketing-expert' ),
			'ai_intelligence'       => __( 'AI-powered email intelligence', 'ai-marketing-expert' ),
			'advanced_analytics'    => __( 'Advanced analytics & reports', 'ai-marketing-expert' ),
		);
	}

	/* ── Database — 22 table schema ─────────────────────── */

	private function maybe_create_tables(): void {
		$ver_key     = 'aime_email_db_version';
		$current_ver = AIME_EMAIL_DB_VERSION;
		if ( get_option( $ver_key, '0' ) === $current_ver ) {
			return;
		}
		global $wpdb;
		$this->create_tables( $wpdb->get_charset_collate() );
		update_option( $ver_key, $current_ver );
	}

	public function create_tables( string $cc = '' ): void {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! $cc ) {
			$cc = $wpdb->get_charset_collate();
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tables = array();

		// ── Subscribers ─────────────────────────────────────
		$tables[] = "CREATE TABLE {$p}aime_subscribers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned DEFAULT NULL,
			hash varchar(90) NOT NULL,
			contact_owner bigint(20) unsigned DEFAULT NULL,
			prefix varchar(50) DEFAULT NULL,
			first_name varchar(192) DEFAULT NULL,
			last_name varchar(192) DEFAULT NULL,
			email varchar(190) NOT NULL,
			timezone varchar(100) DEFAULT NULL,
			address_line_1 varchar(192) DEFAULT NULL,
			address_line_2 varchar(192) DEFAULT NULL,
			postal_code varchar(50) DEFAULT NULL,
			city varchar(100) DEFAULT NULL,
			state varchar(100) DEFAULT NULL,
			country varchar(100) DEFAULT NULL,
			ip varchar(45) DEFAULT NULL,
			phone varchar(50) DEFAULT NULL,
			total_points int(10) unsigned NOT NULL DEFAULT 0,
			life_time_value int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(50) NOT NULL DEFAULT 'subscribed',
			contact_type varchar(50) NOT NULL DEFAULT 'lead',
			source varchar(50) DEFAULT 'manual',
			avatar varchar(500) DEFAULT NULL,
			date_of_birth date DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			last_activity datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_email (email),
			KEY idx_user_id (user_id),
			KEY idx_status (status),
			KEY idx_hash (hash)
		) {$cc};";

		// ── Subscriber meta (custom fields, options) ────────
		$tables[] = "CREATE TABLE {$p}aime_subscriber_meta (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			subscriber_id bigint(20) unsigned NOT NULL,
			object_type varchar(50) NOT NULL DEFAULT 'option',
			meta_key varchar(192) NOT NULL,
			meta_value longtext,
			created_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_subscriber (subscriber_id),
			KEY idx_type (object_type)
		) {$cc};";

		// ── Polymorphic pivot (tags, lists) ──────────────────
		$tables[] = "CREATE TABLE {$p}aime_subscriber_pivot (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			subscriber_id bigint(20) unsigned NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			object_type varchar(50) NOT NULL,
			status varchar(50) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_unique_pivot (subscriber_id, object_id, object_type),
			KEY idx_object (object_id, object_type),
			KEY idx_subscriber (subscriber_id)
		) {$cc};";

		// ── Subscriber notes ────────────────────────────────
		$tables[] = "CREATE TABLE {$p}aime_subscriber_notes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			subscriber_id bigint(20) unsigned NOT NULL,
			parent_id bigint(20) unsigned DEFAULT NULL,
			created_by bigint(20) unsigned DEFAULT NULL,
			status varchar(50) NOT NULL DEFAULT 'open',
			type varchar(50) NOT NULL DEFAULT 'note',
			is_private tinyint(1) NOT NULL DEFAULT 1,
			title varchar(192) DEFAULT NULL,
			description longtext,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_subscriber (subscriber_id),
			KEY idx_type (type)
		) {$cc};";

		// ── Lists ───────────────────────────────────────────
		$tables[] = "CREATE TABLE {$p}aime_lists (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(192) NOT NULL,
			slug varchar(192) DEFAULT NULL,
			description tinytext,
			is_public tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_slug (slug)
		) {$cc};";

		// ── Tags ────────────────────────────────────────────
		$tables[] = "CREATE TABLE {$p}aime_tags (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(192) NOT NULL,
			slug varchar(192) DEFAULT NULL,
			description tinytext,
			color varchar(20) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_slug (slug)
		) {$cc};"; 

		// ── Custom field definitions ────────────────────────
		$tables[] = "CREATE TABLE {$p}aime_custom_fields (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(50) NOT NULL DEFAULT 'contact',
			field_key varchar(192) NOT NULL,
			label varchar(192) NOT NULL,
			field_type varchar(50) NOT NULL DEFAULT 'text',
			options text,
			is_required tinyint(1) NOT NULL DEFAULT 0,
			position int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_key_type (field_key, object_type)
		) {$cc};";

		// ── Campaigns ───────────────────────────────────────
		$tables[] = "CREATE TABLE {$p}aime_campaigns (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			parent_id bigint(20) unsigned DEFAULT NULL,
			type varchar(50) NOT NULL DEFAULT 'campaign',
			title varchar(192) NOT NULL,
			slug varchar(192) DEFAULT NULL,
			status varchar(50) NOT NULL DEFAULT 'draft',
			template_id bigint(20) unsigned DEFAULT NULL,
			email_subject varchar(192) DEFAULT NULL,
			email_pre_header varchar(192) DEFAULT NULL,
			email_body longtext,
			recipients_count int(11) NOT NULL DEFAULT 0,
			delay int(11) NOT NULL DEFAULT 0,
			utm_status tinyint(1) NOT NULL DEFAULT 0,
			utm_source varchar(192) DEFAULT NULL,
			utm_medium varchar(192) DEFAULT NULL,
			utm_campaign varchar(192) DEFAULT NULL,
			utm_term varchar(192) DEFAULT NULL,
			utm_content varchar(192) DEFAULT NULL,
			design_template varchar(50) NOT NULL DEFAULT 'simple',
			note text,
			scheduled_at datetime DEFAULT NULL,
			settings longtext,
			created_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_type (type),
			KEY idx_status (status),
			KEY idx_parent (parent_id)
		) {$cc};";

		// ── Campaign emails (per-subscriber queue) ──────────
		$tables[] = "CREATE TABLE {$p}aime_campaign_emails (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned NOT NULL,
			email_type varchar(50) NOT NULL DEFAULT 'campaign',
			subscriber_id bigint(20) unsigned NOT NULL,
			email_subject_id bigint(20) unsigned DEFAULT NULL,
			email_address varchar(192) DEFAULT NULL,
			email_subject varchar(192) DEFAULT NULL,
			email_body longtext,
			email_headers text,
			is_open tinyint(1) NOT NULL DEFAULT 0,
			is_parsed tinyint(1) NOT NULL DEFAULT 0,
			click_counter int(11) NOT NULL DEFAULT 0,
			retry_count int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(50) NOT NULL DEFAULT 'draft',
			note text,
			scheduled_at datetime DEFAULT NULL,
			email_hash varchar(192) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_unique_campaign_subscriber (campaign_id, subscriber_id),
			KEY idx_campaign (campaign_id),
			KEY idx_subscriber (subscriber_id),
			KEY idx_status (status),
			KEY idx_hash (email_hash),
			KEY idx_scheduled_status (scheduled_at, status)
		) {$cc};";

		// ── URL click/open metrics ──────────────────────────
		$tables[] = "CREATE TABLE {$p}aime_campaign_url_metrics (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_id bigint(20) unsigned DEFAULT NULL,
			campaign_id bigint(20) unsigned NOT NULL,
			subscriber_id bigint(20) unsigned NOT NULL,
			type varchar(50) NOT NULL DEFAULT 'click',
			ip_address varchar(45) DEFAULT NULL,
			country varchar(50) DEFAULT NULL,
			city varchar(50) DEFAULT NULL,
			counter int(10) unsigned NOT NULL DEFAULT 1,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_campaign (campaign_id),
			KEY idx_subscriber (subscriber_id),
			KEY idx_type (type),
			KEY idx_url (url_id)
		) {$cc};";

		// ── URL shortener store ─────────────────────────────
		$tables[] = "CREATE TABLE {$p}aime_url_stores (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url text NOT NULL,
			short varchar(50) NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_short (short)
		) {$cc};";

		// ── Templates ───────────────────────────────────────
		$tables[] = "CREATE TABLE {$p}aime_templates (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			description text,
			category varchar(50) NOT NULL DEFAULT 'general',
			type varchar(20) NOT NULL DEFAULT 'html',
			content longtext,
			design_template varchar(50) DEFAULT 'simple',
			thumbnail varchar(500) DEFAULT NULL,
			is_default tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_category (category)
		) {$cc};";

		// ── Funnels (automations) ───────────────────────────
		$tables[] = "CREATE TABLE {$p}aime_funnels (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(50) NOT NULL DEFAULT 'funnel',
			title varchar(192) NOT NULL,
			trigger_name varchar(150) DEFAULT NULL,
			status varchar(50) NOT NULL DEFAULT 'draft',
			conditions text,
			settings text,
			created_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_status (status),
			KEY idx_trigger (trigger_name)
		) {$cc};";

		// ── Funnel sequences (steps) ────────────────────────
		$tables[] = "CREATE TABLE {$p}aime_funnel_sequences (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			funnel_id bigint(20) unsigned NOT NULL,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action_name varchar(192) NOT NULL,
			condition_type varchar(50) DEFAULT NULL,
			type varchar(50) NOT NULL DEFAULT 'sequence',
			title varchar(192) DEFAULT NULL,
			description varchar(192) DEFAULT NULL,
			status varchar(50) NOT NULL DEFAULT 'draft',
			conditions text,
			settings text,
			note text,
			delay int(10) unsigned NOT NULL DEFAULT 0,
			c_delay int(10) unsigned NOT NULL DEFAULT 0,
			sequence int(10) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_funnel (funnel_id),
			KEY idx_parent (parent_id),
			KEY idx_sequence (sequence)
		) {$cc};";

		// ── Funnel subscribers (progress tracking) ──────────
		$tables[] = "CREATE TABLE {$p}aime_funnel_subscribers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			funnel_id bigint(20) unsigned NOT NULL,
			starting_sequence_id bigint(20) unsigned DEFAULT NULL,
			subscriber_id bigint(20) unsigned NOT NULL,
			last_sequence_id bigint(20) unsigned DEFAULT NULL,
			next_sequence_id bigint(20) unsigned DEFAULT NULL,
			last_sequence_status varchar(50) NOT NULL DEFAULT 'pending',
			status varchar(50) NOT NULL DEFAULT 'active',
			type varchar(50) NOT NULL DEFAULT 'funnel',
			last_executed_time datetime DEFAULT NULL,
			next_execution_time datetime DEFAULT NULL,
			notes text,
			source_trigger_name varchar(192) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_funnel (funnel_id),
			KEY idx_subscriber (subscriber_id),
			KEY idx_status (status),
			KEY idx_next_exec (next_execution_time, status)
		) {$cc};";

		// ── Funnel metrics (per-step analytics) ─────────────
		$tables[] = "CREATE TABLE {$p}aime_funnel_metrics (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			funnel_id bigint(20) unsigned NOT NULL,
			sequence_id bigint(20) unsigned NOT NULL,
			subscriber_id bigint(20) unsigned NOT NULL,
			benchmark_value bigint(20) unsigned NOT NULL DEFAULT 0,
			benchmark_currency varchar(10) DEFAULT 'USD',
			status varchar(50) NOT NULL DEFAULT 'completed',
			notes text,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_funnel_seq (funnel_id, sequence_id),
			KEY idx_subscriber (subscriber_id)
		) {$cc};";

		// ── Labels (for campaigns & funnels) ────────────────
		$tables[] = "CREATE TABLE {$p}aime_labels (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			parent_id bigint(20) unsigned DEFAULT NULL,
			slug varchar(100) DEFAULT NULL,
			title varchar(192) NOT NULL,
			color varchar(20) DEFAULT '#3858e9',
			description longtext,
			settings longtext,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_slug (slug)
		) {$cc};";

		// ── Label relations (polymorphic) ───────────────────
		$tables[] = "CREATE TABLE {$p}aime_label_relations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			label_id bigint(20) unsigned NOT NULL,
			object_type varchar(50) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_label (label_id),
			KEY idx_object (object_type, object_id)
		) {$cc};";

		// ── Generic meta ────────────────────────────────────
		$tables[] = "CREATE TABLE {$p}aime_meta (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(50) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			meta_key varchar(192) NOT NULL,
			meta_value longtext,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_object (object_type, object_id)
		) {$cc};";

		// ── Activity log ────────────────────────────────────
		$tables[] = "CREATE TABLE {$p}aime_activity_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(192) DEFAULT NULL,
			object_id bigint(20) unsigned DEFAULT NULL,
			action varchar(192) NOT NULL,
			source varchar(50) NOT NULL DEFAULT 'wp_admin',
			description text,
			activity_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_object (object_type, object_id),
			KEY idx_action (action),
			KEY idx_created (created_at)
		) {$cc};";

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}

		$this->migrate_legacy_meta_columns();
		$this->migrate_campaign_note_column();
		$this->migrate_campaign_emails_dedupe_and_index();
	}

	private function migrate_legacy_meta_columns(): void {
		global $wpdb;

		$tables = array(
			"{$wpdb->prefix}aime_subscriber_meta",
			"{$wpdb->prefix}aime_meta",
		);

		foreach ( $tables as $table ) {
			$columns = $wpdb->get_col( "DESC `{$table}`", 0 );
			if ( empty( $columns ) ) {
				continue;
			}

			if ( in_array( 'key', $columns, true ) && ! in_array( 'meta_key', $columns, true ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` CHANGE `key` `meta_key` varchar(192) NOT NULL" );
			}

			if ( in_array( 'value', $columns, true ) && ! in_array( 'meta_value', $columns, true ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` CHANGE `value` `meta_value` longtext" );
			}
		}
	}

	private function migrate_campaign_note_column(): void {
		global $wpdb;

		$table = "{$wpdb->prefix}aime_campaigns";
		$columns = $wpdb->get_col( "DESC `{$table}`", 0 );
		if ( empty( $columns ) || in_array( 'note', $columns, true ) ) {
			return;
		}

		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `note` text NULL AFTER `design_template`" );
	}

	/**
	 * De-duplicate aime_campaign_emails and enforce a unique (campaign_id, subscriber_id) index.
	 *
	 * Historically the queue had no unique constraint, so a concurrent enqueue could insert the
	 * same audience twice — doubling recipient counts and sending duplicate emails. This removes
	 * existing duplicates (keeping the earliest row) then adds the unique index if it is absent.
	 */
	private function migrate_campaign_emails_dedupe_and_index(): void {
		global $wpdb;

		$table = "{$wpdb->prefix}aime_campaign_emails";

		// Bail if the table does not exist yet (fresh install already created it with the index).
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return;
		}

		// Already indexed? Nothing to do.
		$has_index = $wpdb->get_var( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_unique_campaign_subscriber'" );
		if ( $has_index ) {
			return;
		}

		// Remove duplicate rows, keeping the lowest id per (campaign_id, subscriber_id).
		$wpdb->query(
			"DELETE ce1 FROM `{$table}` ce1
			 INNER JOIN `{$table}` ce2
			   ON ce1.campaign_id = ce2.campaign_id
			  AND ce1.subscriber_id = ce2.subscriber_id
			  AND ce1.id > ce2.id"
		);

		// Add the unique index now that duplicates are gone.
		$wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY idx_unique_campaign_subscriber (campaign_id, subscriber_id)" );
	}

	/* ── Public Subscribe Shortcode ──────────────────────── */

	/**
	 * Render the [aime_subscribe] shortcode form.
	 *
	 * Attributes:
	 *  list_id      – attach subscriber to this list (0 = none).
	 *  title        – heading text.
	 *  description  – paragraph below heading.
	 *  button_text  – submit button label.
	 *  show_name    – whether to show first/last name fields (1|0).
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_subscribe_form( $atts = array() ): string {
		$a = shortcode_atts( array(
			'list_id'     => 0,
			'title'       => __( 'Subscribe to our newsletter', 'ai-marketing-expert' ),
			'description' => '',
			'button_text' => __( 'Subscribe', 'ai-marketing-expert' ),
			'show_name'   => '1',
		), $atts, 'aime_subscribe' );

		$uid       = 'aime-sf-' . wp_unique_id();
		$rest_url  = esc_url( rest_url( 'aime/v1/email/subscribe' ) );
		$timestamp = time();
		$form_token = hash_hmac( 'sha256', absint( $a['list_id'] ) . '|' . $timestamp, wp_salt( 'nonce' ) );
		$show_name = ( '1' === $a['show_name'] || 'true' === $a['show_name'] );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $uid ); ?>" class="aime-subscribe-form" style="max-width:480px;margin:1.5rem auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif">
			<?php if ( $a['title'] ) : ?>
				<h3 style="margin:0 0 .5rem;font-size:1.25rem;color:#1e293b"><?php echo esc_html( $a['title'] ); ?></h3>
			<?php endif; ?>
			<?php if ( $a['description'] ) : ?>
				<p style="margin:0 0 1rem;color:#64748b;font-size:.9rem"><?php echo esc_html( $a['description'] ); ?></p>
			<?php endif; ?>

			<form class="aime-sf" style="display:flex;flex-direction:column;gap:.75rem" onsubmit="return false;">
				<?php if ( $show_name ) : ?>
				<div style="display:flex;gap:.5rem">
					<input type="text" name="first_name" placeholder="<?php esc_attr_e( 'First name', 'ai-marketing-expert' ); ?>"
						   style="flex:1;padding:10px 14px;border:1px solid #cbd5e1;border-radius:6px;font-size:.9rem" />
					<input type="text" name="last_name" placeholder="<?php esc_attr_e( 'Last name', 'ai-marketing-expert' ); ?>"
						   style="flex:1;padding:10px 14px;border:1px solid #cbd5e1;border-radius:6px;font-size:.9rem" />
				</div>
				<?php endif; ?>
				<input type="email" name="email" required
					   placeholder="<?php esc_attr_e( 'Email address', 'ai-marketing-expert' ); ?>"
					   style="padding:10px 14px;border:1px solid #cbd5e1;border-radius:6px;font-size:.9rem" />
				<!-- Honeypot -->
				<div style="position:absolute;left:-9999px" aria-hidden="true">
					<input type="text" name="website" tabindex="-1" autocomplete="off" />
				</div>
				<input type="hidden" name="list_id" value="<?php echo absint( $a['list_id'] ); ?>" />
				<input type="hidden" name="aime_ts" value="<?php echo absint( $timestamp ); ?>" />
				<input type="hidden" name="aime_token" value="<?php echo esc_attr( $form_token ); ?>" />
				<button type="submit"
						style="padding:10px 20px;background:#3858e9;color:#fff;border:none;border-radius:6px;font-size:.95rem;font-weight:600;cursor:pointer;transition:background .2s">
					<?php echo esc_html( $a['button_text'] ); ?>
				</button>
				<div class="aime-sf-msg" style="display:none;padding:10px 14px;border-radius:6px;font-size:.9rem" role="alert"></div>
			</form>
		</div>

		<script>
		(function(){
			var wrap = document.getElementById('<?php echo esc_js( $uid ); ?>');
			if(!wrap) return;
			var form = wrap.querySelector('.aime-sf');
			var btn  = form.querySelector('button[type="submit"]');
			var msg  = wrap.querySelector('.aime-sf-msg');

			form.addEventListener('submit', function(e){
				e.preventDefault();
				btn.disabled = true;
				btn.textContent = '<?php echo esc_js( __( 'Sending…', 'ai-marketing-expert' ) ); ?>';
				msg.style.display = 'none';

				var body = {
					email: form.querySelector('[name="email"]').value,
					list_id: parseInt(form.querySelector('[name="list_id"]').value, 10) || 0,
					aime_ts: parseInt(form.querySelector('[name="aime_ts"]').value, 10) || 0,
					aime_token: form.querySelector('[name="aime_token"]').value,
					website: form.querySelector('[name="website"]').value
				};

				var fn = form.querySelector('[name="first_name"]');
				var ln = form.querySelector('[name="last_name"]');
				if(fn) body.first_name = fn.value;
				if(ln) body.last_name = ln.value;

				fetch('<?php echo esc_url( $rest_url ); ?>', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify(body)
				})
				.then(function(r){ return r.json().then(function(d){ return {ok:r.ok, data:d}; }); })
				.then(function(res){
					msg.style.display = 'block';
					if(res.ok){
						msg.style.background = '#f0fdf4';
						msg.style.color = '#166534';
						msg.textContent = res.data.message || '<?php echo esc_js( __( 'Thank you for subscribing!', 'ai-marketing-expert' ) ); ?>';
						form.reset();
					} else {
						msg.style.background = '#fef2f2';
						msg.style.color = '#991b1b';
						msg.textContent = res.data.message || '<?php echo esc_js( __( 'Something went wrong. Please try again.', 'ai-marketing-expert' ) ); ?>';
					}
				})
				.catch(function(){
					msg.style.display = 'block';
					msg.style.background = '#fef2f2';
					msg.style.color = '#991b1b';
					msg.textContent = '<?php echo esc_js( __( 'Network error. Please try again.', 'ai-marketing-expert' ) ); ?>';
				})
				.finally(function(){
					btn.disabled = false;
					btn.textContent = '<?php echo esc_js( $a['button_text'] ); ?>';
				});
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}
}

// Register the module.
add_action( 'aime_load_module_email-marketing', function ( $manager ) {
	$manager->register( new EmailMarketingModule() );
} );
