<?php
/**
 * Social Media Module — Bootstrap.
 *
 * AI-powered social media management with scheduling, multi-platform
 * publishing, and content repurposing.
 *
 * @package WPSpace\AiMarketingExpert\Modules\SocialMedia
 */

namespace WPSpace\AiMarketingExpert\Modules\SocialMedia;

use WPSpace\AiMarketingExpert\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SocialMediaModule extends Module {

	const DB_VERSION = '1.0.0';

	/* ── Module identity ────────────────────────────────── */

	public function get_id(): string {
		return 'social-media';
	}

	public function get_name(): string {
		return __( 'Social Media', 'ai-marketing-expert' );
	}

	public function get_description(): string {
		return __( 'Schedule and manage your social media presence with AI-powered content.', 'ai-marketing-expert' );
	}

	public function get_icon(): string {
		return 'share';
	}

	public function get_version(): string {
		return '1.0.0';
	}

	/* ── Pro features ───────────────────────────────────── */

	public function get_pro_features(): array {
		return array(
			'unlimited_accounts'  => __( 'Unlimited social accounts (free: 2)', 'ai-marketing-expert' ),
			'unlimited_posts'     => __( 'Unlimited posts per month (free: 30/month)', 'ai-marketing-expert' ),
			'unlimited_scheduled' => __( 'Unlimited scheduled posts (free: 3 at a time)', 'ai-marketing-expert' ),
			'visual_calendar'     => __( 'Visual drag-and-drop scheduling calendar', 'ai-marketing-expert' ),
			'ai_repurpose'        => __( 'AI auto-repurpose articles to social posts', 'ai-marketing-expert' ),
			'ai_image_captions'   => __( 'AI-powered image caption suggestions', 'ai-marketing-expert' ),
			'bulk_scheduling'     => __( 'Bulk schedule multiple posts at once', 'ai-marketing-expert' ),
			'cross_posting'       => __( 'Cross-post to multiple accounts simultaneously', 'ai-marketing-expert' ),
			'unlimited_ai'        => __( 'Unlimited AI caption & hashtag generations', 'ai-marketing-expert' ),
		);
	}

	/* ── Initialisation ─────────────────────────────────── */

	public function init(): void {
		$this->maybe_create_tables();

		// Dashboard stats hook.
		add_filter( 'aime_social-media_dashboard_stats', array( $this, 'get_stats' ) );

		// Register the five_minutes interval BEFORE scheduling.
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );

		// Cron hook for publishing scheduled posts.
		add_action( 'aime_publish_scheduled_social_posts', array( $this, 'process_scheduled_posts' ) );

		// Schedule cron if not already scheduled.
		if ( ! wp_next_scheduled( 'aime_publish_scheduled_social_posts' ) ) {
			wp_schedule_event( time(), 'five_minutes', 'aime_publish_scheduled_social_posts' );
		}

		/*
		 * Auto-connect (OAuth) callback bridge — DISABLED for now.
		 *
		 * The manual connection flow is the only one live today. When the
		 * OAuth auto-connect flow is enabled, uncomment the line below AND the
		 * popup handler in src/components/modules/SocialMedia/ConnectAccountModal.jsx.
		 * Both must be enabled together. See render_oauth_bridge() for details.
		 */
		// add_action( 'template_redirect', array( $this, 'render_oauth_bridge' ) );
	}

	/**
	 * OAuth callback bridge (front-end relay page) — for the auto-connect flow.
	 *
	 * After the user authorizes, the OAuth provider/proxy redirects the popup
	 * window here with a top-level GET navigation (`?aime_social_oauth=callback&code=…&state=…`).
	 * REST routes only answer the verbs they register, so the POST-only
	 * `/social/accounts/callback` route cannot receive this redirect directly.
	 *
	 * This page relays the returned `code`/`state` (or `error`) to the opener
	 * window via postMessage, restricted to this site's own origin. The opener
	 * (ConnectAccountModal) then performs the authenticated REST POST to
	 * `/social/accounts/callback`, which exchanges the code for tokens.
	 *
	 * NOTE: This handler is intentionally left UNREGISTERED while auto-connect
	 * is disabled. To enable it, uncomment the `add_action( 'template_redirect', … )`
	 * line in init() and the popup handler in ConnectAccountModal.jsx.
	 */
	public function render_oauth_bridge(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth redirect cannot carry a WP nonce; CSRF is covered by the `state` token, which is verified against a per-user transient in OAuthService::handle_callback().
		if ( ! isset( $_GET['aime_social_oauth'] ) || 'callback' !== sanitize_text_field( wp_unslash( $_GET['aime_social_oauth'] ) ) ) {
			return;
		}

		$code     = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$platform = isset( $_GET['platform'] ) ? sanitize_text_field( wp_unslash( $_GET['platform'] ) ) : '';
		$error    = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		if ( '' === $error && isset( $_GET['error_description'] ) ) {
			$error = sanitize_text_field( wp_unslash( $_GET['error_description'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$origin  = home_url();
		$payload = '' !== $error
			? array( 'type' => 'aime_oauth_error', 'error' => $error )
			: array( 'type' => 'aime_oauth_callback', 'platform' => $platform, 'code' => $code, 'state' => $state );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Finishing connection…', 'ai-marketing-expert' ); ?></title>
</head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;text-align:center;padding:48px 20px;color:#555;">
	<p><?php esc_html_e( 'Finishing connection… You can close this window.', 'ai-marketing-expert' ); ?></p>
	<script>
		( function () {
			var payload = <?php echo wp_json_encode( $payload ); ?>;
			var origin  = <?php echo wp_json_encode( $origin ); ?>;
			try {
				if ( window.opener ) {
					window.opener.postMessage( payload, origin );
				}
			} catch ( e ) {}
			setTimeout( function () {
				try { window.close(); } catch ( e ) {}
			}, 400 );
		} )();
	</script>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Add a five-minute cron interval.
	 */
	public function add_cron_interval( array $schedules ): array {
		if ( ! isset( $schedules['five_minutes'] ) ) {
			$schedules['five_minutes'] = array(
				'interval' => 300,
				'display'  => __( 'Every Five Minutes', 'ai-marketing-expert' ),
			);
		}
		return $schedules;
	}

	/**
	 * Process scheduled social posts via cron.
	 */
	public function process_scheduled_posts(): void {
		$publisher = new Services\SocialPublisherService();
		$publisher->process_queue();
	}

	/* ── REST routes ─────────────────────────────────────── */

	public function register_routes(): void {
		$controller = new SocialRestController();
		$controller->register_routes();
	}

	/* ── Database tables ─────────────────────────────────── */

	private function maybe_create_tables(): void {
		$installed = get_option( 'aime_social_media_db_version', '' );
		if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$this->create_tables( $charset );

		update_option( 'aime_social_media_db_version', self::DB_VERSION );
	}

	public function create_tables( string $charset_collate ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		// Social accounts table.
		dbDelta( "CREATE TABLE {$p}aime_social_accounts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			platform VARCHAR(30) NOT NULL DEFAULT 'facebook',
			platform_user_id VARCHAR(255) NOT NULL DEFAULT '',
			name VARCHAR(255) NOT NULL DEFAULT '',
			avatar_url VARCHAR(1000) DEFAULT NULL,
			access_token TEXT,
			refresh_token TEXT,
			token_expires_at DATETIME DEFAULT NULL,
			meta LONGTEXT,
			status VARCHAR(20) NOT NULL DEFAULT 'connected',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_platform (platform),
			KEY idx_status (status)
		) $charset_collate;" );

		// Social posts table.
		dbDelta( "CREATE TABLE {$p}aime_social_posts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			content LONGTEXT,
			media_urls LONGTEXT,
			hashtags TEXT,
			platform_post_id VARCHAR(255) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			scheduled_at DATETIME DEFAULT NULL,
			published_at DATETIME DEFAULT NULL,
			error_message TEXT,
			ai_generated TINYINT(1) NOT NULL DEFAULT 0,
			source_type VARCHAR(30) NOT NULL DEFAULT 'manual',
			source_id BIGINT UNSIGNED DEFAULT NULL,
			retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_account (account_id),
			KEY idx_status (status),
			KEY idx_scheduled (scheduled_at),
			KEY idx_created (created_at)
		) $charset_collate;" );

		// Social post log table.
		dbDelta( "CREATE TABLE {$p}aime_social_post_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(50) NOT NULL,
			details LONGTEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_post (post_id),
			KEY idx_created (created_at)
		) $charset_collate;" );
	}

	/* ── Dashboard stats ─────────────────────────────────── */

	public function get_stats(): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_social_accounts" ) );
		if ( ! $table_exists ) {
			return array(
				'total_accounts'    => 0,
				'posts_this_month'  => 0,
				'scheduled_posts'   => 0,
				'published_month'   => 0,
			);
		}

		$month_start = gmdate( 'Y-m-01 00:00:00' );
		$accounts_table = $p . 'aime_social_accounts';
		$posts_table    = $p . 'aime_social_posts';

		return array(
			'total_accounts'   => (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s',
				$accounts_table,
				'connected'
			) ),
			'posts_this_month' => (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
				$posts_table,
				$month_start
			) ),
			'scheduled_posts'  => (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s',
				$posts_table,
				'scheduled'
			) ),
			'published_month'  => (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s AND published_at >= %s',
				$posts_table,
				'published',
				$month_start
			) ),
		);
	}

	/* ── Monthly post count (for free limit check) ──────── */

	public static function get_monthly_post_count(): int {
		global $wpdb;
		$p = $wpdb->prefix;
		$posts_table = $p . 'aime_social_posts';

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_social_posts" ) );
		if ( ! $table_exists ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
				$posts_table,
				gmdate( 'Y-m-01 00:00:00' )
			)
		);
	}

	/* ── Monthly AI usage count ─────────────────────────── */

	public static function get_monthly_ai_count(): int {
		$month_key = 'aime_social_ai_' . gmdate( 'Y_m' );
		return (int) get_option( $month_key, 0 );
	}

	/* ── Account count ──────────────────────────────────── */

	public static function get_account_count(): int {
		global $wpdb;
		$p = $wpdb->prefix;
		$accounts_table = $p . 'aime_social_accounts';

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_social_accounts" ) );
		if ( ! $table_exists ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $accounts_table )
		);
	}
}

/* ── Register with Module Manager ──────────────────────── */

add_action( 'aime_load_module_social-media', function ( $manager ) {
	$manager->register( new SocialMediaModule() );
} );
