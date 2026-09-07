<?php
/**
 * Chatbot Module — Bootstrap.
 *
 * AI-powered chatbot with knowledge base, lead capture, and human takeover.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Chatbot
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

namespace WPSpace\AiMarketingExpert\Modules\Chatbot;

use WPSpace\AiMarketingExpert\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ChatbotModule extends Module {

	private const CACHE_GROUP = 'aime_chatbot_module';
	private const CACHE_TTL   = 30;

	private static function get_cache_version(): string {
		$version = wp_cache_get( 'version', self::CACHE_GROUP );
		if ( false === $version ) {
			$version = '1';
			wp_cache_set( 'version', $version, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return (string) $version;
	}

	private static function bump_cache_version(): void {
		wp_cache_set( 'version', (string) microtime( true ), self::CACHE_GROUP, self::CACHE_TTL );
	}

	private static function build_cache_key( string $prefix, array $parts = array() ): string {
		return $prefix . ':' . md5( wp_json_encode( $parts ) . ':' . self::get_cache_version() );
	}

	/* ── Module identity ────────────────────────────────── */

	public function get_id(): string {
		return 'chatbot';
	}

	public function get_name(): string {
		return __( 'AI Chatbot', 'ai-marketing-expert' );
	}

	public function get_description(): string {
		return __( 'AI-powered chatbot with knowledge base, lead capture, and human takeover support.', 'ai-marketing-expert' );
	}

	public function get_icon(): string {
		return 'format-chat';
	}

	public function get_version(): string {
		return '1.0.0';
	}

	/* ── Pro features ───────────────────────────────────── */

	public function get_pro_features(): array {
		return array(
			'unlimited_conversations' => __( 'Unlimited conversations (free: 100/month)', 'ai-marketing-expert' ),
			'unlimited_bots'          => __( 'Unlimited chatbot instances (free: 1)', 'ai-marketing-expert' ),
			'human_takeover'          => __( 'Live human agent takeover', 'ai-marketing-expert' ),
			'woocommerce_indexing'    => __( 'WooCommerce product knowledge indexing', 'ai-marketing-expert' ),
			'document_indexing'       => __( 'Custom document & URL indexing', 'ai-marketing-expert' ),
			'custom_css'              => __( 'Custom CSS for widget theming', 'ai-marketing-expert' ),
			'animated_intro'          => __( 'Animated widget intro effects', 'ai-marketing-expert' ),
			'advanced_analytics'      => __( 'Advanced analytics with topics & satisfaction', 'ai-marketing-expert' ),
			'chat_export'             => __( 'Export conversations to CSV', 'ai-marketing-expert' ),
			'file_sharing'            => __( 'File & image sharing in chat', 'ai-marketing-expert' ),
			'business_hours'          => __( 'Business hours & offline messages', 'ai-marketing-expert' ),
			'conversation_rating'     => __( 'Visitor satisfaction rating', 'ai-marketing-expert' ),
			'remove_branding'         => __( 'Remove "Powered by" branding', 'ai-marketing-expert' ),
			'ai_fallback'             => __( 'Multiple AI provider fallback', 'ai-marketing-expert' ),
			'advanced_system_prompt'  => __( 'Advanced system prompt with variables', 'ai-marketing-expert' ),
			'public_discussions'      => __( 'Public discussions page via [aime_discussions] shortcode', 'ai-marketing-expert' ),
		);
	}

	/* ── Initialisation ─────────────────────────────────── */

	public function init(): void {
		$this->maybe_create_tables();

		// Dashboard stats filter.
		add_filter( 'aime_chatbot_dashboard_stats', array( $this, 'get_stats' ) );

		// Frontend widget rendering.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_widget_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_widget' ) );

		// Cron jobs.
		if ( ! wp_next_scheduled( 'aime_chatbot_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'aime_chatbot_daily_cleanup' );
		}
		add_action( 'aime_chatbot_daily_cleanup', array( $this, 'daily_cleanup' ) );

		// Auto-index on post save.
		add_action( 'save_post', array( $this, 'on_post_save' ), 20, 2 );

		// Public discussions shortcode (Pro).
		add_shortcode( 'aime_discussions', array( $this, 'render_discussions_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_discussions_css' ) );
	}

	/* ── REST routes ─────────────────────────────────────── */

	public function register_routes(): void {
		$controller = new ChatbotRestController();
		$controller->register_routes();
	}

	/* ── Database tables ─────────────────────────────────── */

	private function maybe_create_tables(): void {
		$installed = get_option( 'aime_chatbot_db_version', '' );
		if ( version_compare( $installed, AIME_CHATBOT_DB_VERSION, '>=' ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$this->create_tables( $charset );
		update_option( 'aime_chatbot_db_version', AIME_CHATBOT_DB_VERSION );
	}

	public function create_tables( string $charset_collate ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		// Chatbot instances.
		dbDelta( "CREATE TABLE {$p}aime_chatbot_bots (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'inactive',
			system_prompt longtext,
			welcome_message text,
			theme_id varchar(50) NOT NULL DEFAULT 'default',
			theme_config longtext,
			lead_capture_config longtext,
			knowledge_config longtext,
			offline_message text,
			business_hours longtext,
			banned_words text,
			page_rules longtext,
			is_pro tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_status (status)
		) $charset_collate;" );

		// Conversations.
		dbDelta( "CREATE TABLE {$p}aime_chatbot_conversations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bot_id bigint(20) unsigned NOT NULL,
			visitor_id varchar(64) NOT NULL,
			visitor_name varchar(100) DEFAULT NULL,
			visitor_email varchar(255) DEFAULT NULL,
			visitor_ip varchar(45) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			agent_id bigint(20) unsigned DEFAULT NULL,
			lead_captured tinyint(1) NOT NULL DEFAULT 0,
			is_public tinyint(1) NOT NULL DEFAULT 0,
			satisfaction_rating tinyint(3) unsigned DEFAULT NULL,
			source varchar(20) NOT NULL DEFAULT 'widget',
			page_url text,
			user_agent text,
			metadata longtext,
			started_at datetime DEFAULT NULL,
			ended_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_bot (bot_id),
			KEY idx_visitor (visitor_id),
			KEY idx_status (status),
			KEY idx_created (created_at),
			KEY idx_public (is_public)
		) $charset_collate;" );

		// Messages.
		dbDelta( "CREATE TABLE {$p}aime_chatbot_messages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			sender_type varchar(20) NOT NULL,
			sender_id varchar(100) DEFAULT NULL,
			content text NOT NULL,
			content_type varchar(20) NOT NULL DEFAULT 'text',
			metadata longtext,
			is_read tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_conversation (conversation_id),
			KEY idx_sender_type (sender_type),
			KEY idx_created (created_at)
		) $charset_collate;" );

		// Knowledge base.
		dbDelta( "CREATE TABLE {$p}aime_chatbot_knowledge (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bot_id bigint(20) unsigned NOT NULL,
			type varchar(30) NOT NULL,
			source_id bigint(20) unsigned DEFAULT NULL,
			question text,
			answer text,
			content longtext,
			status varchar(20) NOT NULL DEFAULT 'active',
			last_indexed_at datetime DEFAULT NULL,
			metadata longtext,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_bot (bot_id),
			KEY idx_type (type),
			KEY idx_status (status),
			KEY idx_source (source_id)
		) $charset_collate;" );

		// Analytics (aggregated daily).
		dbDelta( "CREATE TABLE {$p}aime_chatbot_analytics (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bot_id bigint(20) unsigned NOT NULL,
			date date NOT NULL,
			total_conversations int(11) NOT NULL DEFAULT 0,
			total_messages int(11) NOT NULL DEFAULT 0,
			ai_messages int(11) NOT NULL DEFAULT 0,
			human_messages int(11) NOT NULL DEFAULT 0,
			visitor_messages int(11) NOT NULL DEFAULT 0,
			leads_captured int(11) NOT NULL DEFAULT 0,
			human_takeovers int(11) NOT NULL DEFAULT 0,
			avg_satisfaction decimal(3,2) DEFAULT NULL,
			avg_response_time_ms int(11) DEFAULT NULL,
			total_tokens_used int(11) NOT NULL DEFAULT 0,
			unique_visitors int(11) NOT NULL DEFAULT 0,
			top_topics longtext,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_bot_date (bot_id, date),
			KEY idx_date (date)
		) $charset_collate;" );

		// Conversations must never be public unless an admin explicitly publishes
		// them. dbDelta does not reliably change an existing column's DEFAULT, so
		// enforce it directly for installs upgrading from the old DEFAULT 1 schema.
		// Existing rows are left untouched to preserve any admin-published state.
		$conversations_table = $p . 'aime_chatbot_conversations';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed schema DDL, table name is internal.
		$wpdb->query( "ALTER TABLE {$conversations_table} MODIFY is_public tinyint(1) NOT NULL DEFAULT 0" );
	}

	/* ── Public Discussions Shortcode (Pro) ───────────────── */

	public function maybe_enqueue_discussions_css(): void {
		global $post;
		if ( ! $post || ! has_shortcode( $post->post_content, 'aime_discussions' ) ) {
			return;
		}
		wp_enqueue_style(
			'aime-discussions',
			AIME_PLUGIN_URL . 'assets/css/discussions.css',
			array(),
			aime_asset_version()
		);
	}

	public function render_discussions_shortcode( $atts ): string {
		if ( ! aime_has_pro() ) {
			return '<p class="aime-discussions-notice">' . esc_html__( 'Public Discussions requires Pro.', 'ai-marketing-expert' ) . '</p>';
		}

		$atts = shortcode_atts( array(
			'per_page' => 10,
			'bot_id'   => 0,
		), $atts, 'aime_discussions' );

		global $wpdb;
		$p = $wpdb->prefix;

		$per_page = max( 1, min( 50, absint( $atts['per_page'] ) ) );
		$bot_id   = absint( $atts['bot_id'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public shortcode pagination parameter for front-end discussion browsing; no state change occurs.
		$paged    = max( 1, absint( $_GET['aime_page'] ?? 1 ) );
		$offset   = ( $paged - 1 ) * $per_page;

		// Check if viewing a single conversation.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public shortcode conversation selector for read-only discussion display.
		$conv_id = absint( $_GET['aime_conv'] ?? 0 );
		if ( $conv_id ) {
			return $this->render_single_discussion( $conv_id );
		}

		$cache_key = self::build_cache_key( 'public_discussions', array(
			'per_page' => $per_page,
			'bot_id'   => $bot_id,
			'paged'    => $paged,
		) );
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$where = 'WHERE c.is_public = 1'
			. " AND EXISTS (SELECT 1 FROM {$p}aime_chatbot_messages vm WHERE vm.conversation_id = c.id AND vm.sender_type = 'visitor')";
		$args  = array();

		if ( $bot_id ) {
			$where .= ' AND c.bot_id = %d';
			$args[] = $bot_id;
		}

		$count_sql = "SELECT COUNT(*) FROM {$p}aime_chatbot_conversations c {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) ) : $wpdb->get_var( $count_sql ) );

		$query_args   = $args;
		$query_args[] = $per_page;
		$query_args[] = $offset;

		$sql = "SELECT c.id, c.visitor_name, c.status, c.created_at,
					b.name AS bot_name,
					(SELECT COUNT(*) FROM {$p}aime_chatbot_messages m WHERE m.conversation_id = c.id AND m.sender_type IN ('visitor','ai','agent')) AS message_count,
					(SELECT m2.content FROM {$p}aime_chatbot_messages m2 WHERE m2.conversation_id = c.id AND m2.sender_type = 'visitor' ORDER BY m2.id ASC LIMIT 1) AS first_message
				FROM {$p}aime_chatbot_conversations c
				LEFT JOIN {$p}aime_chatbot_bots b ON b.id = c.bot_id
				{$where}
				ORDER BY c.created_at DESC
				LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$items      = $wpdb->get_results( $wpdb->prepare( $sql, ...$query_args ) );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$current_url = remove_query_arg( array( 'aime_page', 'aime_conv' ) );

		ob_start();
		?>
		<div class="aime-discussions">
			<div class="aime-discussions__header">
				<h2 class="aime-discussions__title"><?php echo esc_html__( 'Community Discussions', 'ai-marketing-expert' ); ?></h2>
				<p class="aime-discussions__count">
					<?php
					/* translators: %d: number of discussions */
					printf( esc_html__( '%d discussions', 'ai-marketing-expert' ), absint( $total ) );
					?>
				</p>
				<div class="aime-discussions__search">
					<input
						type="text"
						class="aime-discussions__search-input"
						placeholder="<?php esc_attr_e( 'Search discussions…', 'ai-marketing-expert' ); ?>"
						aria-label="<?php esc_attr_e( 'Search discussions', 'ai-marketing-expert' ); ?>"
					/>
					<svg class="aime-discussions__search-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
				</div>
			</div>

			<?php if ( empty( $items ) ) : ?>
				<p class="aime-discussions__empty"><?php echo esc_html__( 'No discussions yet.', 'ai-marketing-expert' ); ?></p>
			<?php else : ?>
				<div class="aime-discussions__list">
					<?php foreach ( $items as $item ) :
						$visitor   = $item->visitor_name ? esc_html( $item->visitor_name ) : esc_html__( 'Anonymous', 'ai-marketing-expert' );
						$date      = date_i18n( get_option( 'date_format' ), strtotime( $item->created_at ) );
						$preview   = $item->first_message ? esc_html( wp_trim_words( $item->first_message, 25, '…' ) ) : '—';
						$link      = esc_url( add_query_arg( 'aime_conv', $item->id, $current_url ) );
						$msg_count = absint( $item->message_count );
						$status_label = 'closed' === $item->status
							? esc_html__( 'Resolved', 'ai-marketing-expert' )
							: esc_html__( 'Open', 'ai-marketing-expert' );
						$status_class = 'closed' === $item->status ? 'resolved' : 'open';
					?>
						<article class="aime-discussions__item">
							<a href="<?php echo esc_url( $link ); ?>" class="aime-discussions__item-link">
								<div class="aime-discussions__item-header">
									<span class="aime-discussions__visitor"><?php echo esc_html( $visitor ); ?></span>
									<span class="aime-discussions__status aime-discussions__status--<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
								</div>
								<p class="aime-discussions__preview"><?php echo esc_html( $preview ); ?></p>
								<div class="aime-discussions__meta">
									<time datetime="<?php echo esc_attr( $item->created_at ); ?>"><?php echo esc_html( $date ); ?></time>
									<span class="aime-discussions__replies">
										<?php
										/* translators: %d: number of messages */
										printf( esc_html( _n( '%d message', '%d messages', $msg_count, 'ai-marketing-expert' ) ), absint( $msg_count ) );
										?>
									</span>
									<?php if ( $item->bot_name ) : ?>
										<span class="aime-discussions__bot"><?php echo esc_html( $item->bot_name ); ?></span>
									<?php endif; ?>
								</div>
							</a>
						</article>
					<?php endforeach; ?>
				</div>

				<?php if ( $total_pages > 1 ) : ?>
					<nav class="aime-discussions__pagination" aria-label="<?php esc_attr_e( 'Discussion pages', 'ai-marketing-expert' ); ?>">
						<?php if ( $paged > 1 ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'aime_page', $paged - 1, $current_url ) ); ?>" class="aime-discussions__page-link aime-discussions__page-link--prev">
								&laquo; <?php esc_html_e( 'Previous', 'ai-marketing-expert' ); ?>
							</a>
						<?php endif; ?>

						<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
							<?php if ( $i === $paged ) : ?>
								<span class="aime-discussions__page-link aime-discussions__page-link--current"><?php echo absint( $i ); ?></span>
							<?php else : ?>
								<a href="<?php echo esc_url( add_query_arg( 'aime_page', $i, $current_url ) ); ?>" class="aime-discussions__page-link"><?php echo absint( $i ); ?></a>
							<?php endif; ?>
						<?php endfor; ?>

						<?php if ( $paged < $total_pages ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'aime_page', $paged + 1, $current_url ) ); ?>" class="aime-discussions__page-link aime-discussions__page-link--next">
								<?php esc_html_e( 'Next', 'ai-marketing-expert' ); ?> &raquo;
							</a>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			<?php endif; ?>

			<script>
			(function(){
				var wrap = document.querySelector('.aime-discussions');
				if (!wrap) return;
				var input = wrap.querySelector('.aime-discussions__search-input');
				var items = wrap.querySelectorAll('.aime-discussions__item');
				var countEl = wrap.querySelector('.aime-discussions__count');
				var totalCount = <?php echo (int) $total; ?>;

				input.addEventListener('input', function(){
					var q = this.value.toLowerCase().trim();
					var visible = 0;
					items.forEach(function(el){
						var text = el.textContent.toLowerCase();
						var show = !q || text.indexOf(q) !== -1;
						el.style.display = show ? '' : 'none';
						if (show) visible++;
					});
					if (countEl) {
						countEl.textContent = q
							? visible + ' / ' + totalCount + ' <?php echo esc_js( __( 'discussions', 'ai-marketing-expert' ) ); ?>'
							: totalCount + ' <?php echo esc_js( __( 'discussions', 'ai-marketing-expert' ) ); ?>';
					}
				});
			})();
			</script>
		</div>
		<?php
		$output = ob_get_clean();
		wp_cache_set( $cache_key, $output, self::CACHE_GROUP, self::CACHE_TTL );

		return $output;
	}

	private function render_single_discussion( int $conv_id ): string {
		global $wpdb;
		$p = $wpdb->prefix;
		$cache_key = self::build_cache_key( 'single_discussion', array( 'conv_id' => $conv_id ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$conversation = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.id, c.visitor_name, c.status, c.created_at,
					b.name AS bot_name
			 FROM {$p}aime_chatbot_conversations c
			 LEFT JOIN {$p}aime_chatbot_bots b ON b.id = c.bot_id
			 WHERE c.id = %d AND c.is_public = 1",
			$conv_id
		) );

		if ( ! $conversation ) {
			return '<p class="aime-discussions__not-found">' . esc_html__( 'Discussion not found.', 'ai-marketing-expert' ) . '</p>';
		}

		$messages = $wpdb->get_results( $wpdb->prepare(
			"SELECT sender_type, content, created_at
			 FROM {$p}aime_chatbot_messages
			 WHERE conversation_id = %d
			   AND sender_type IN ('visitor','ai','agent')
			 ORDER BY created_at ASC",
			$conv_id
		) );

		// Remove the first AI welcome message so thread starts with the visitor.
		if ( ! empty( $messages ) && 'ai' === $messages[0]->sender_type ) {
			array_shift( $messages );
		}

		$visitor  = $conversation->visitor_name ? esc_html( $conversation->visitor_name ) : esc_html__( 'Anonymous', 'ai-marketing-expert' );
		$date     = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $conversation->created_at ) );
		$back_url = esc_url( remove_query_arg( 'aime_conv' ) );

		$sender_labels = array(
			'visitor' => $visitor,
			'ai'      => $conversation->bot_name ? esc_html( $conversation->bot_name ) : esc_html__( 'AI Assistant', 'ai-marketing-expert' ),
			'agent'   => esc_html__( 'Support Agent', 'ai-marketing-expert' ),
		);

		ob_start();
		?>
		<div class="aime-discussions aime-discussions--single">
			<a href="<?php echo esc_url( $back_url ); ?>" class="aime-discussions__back">&laquo; <?php esc_html_e( 'Back to Discussions', 'ai-marketing-expert' ); ?></a>

			<article class="aime-discussions__thread">
				<header class="aime-discussions__thread-header">
					<h2 class="aime-discussions__thread-title">
						<?php
						/* translators: %s: visitor name */
						printf( esc_html__( 'Discussion by %s', 'ai-marketing-expert' ), esc_html( $visitor ) );
						?>
					</h2>
					<time datetime="<?php echo esc_attr( $conversation->created_at ); ?>"><?php echo esc_html( $date ); ?></time>
				</header>

				<div class="aime-discussions__messages">
					<?php foreach ( $messages as $msg ) :
						$sender_class = esc_attr( $msg->sender_type );
						$sender_name  = $sender_labels[ $msg->sender_type ] ?? esc_html__( 'Unknown', 'ai-marketing-expert' );
						$msg_date     = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $msg->created_at ) );
					?>
						<div class="aime-discussions__message aime-discussions__message--<?php echo esc_attr( $sender_class ); ?>">
							<div class="aime-discussions__message-header">
								<strong class="aime-discussions__message-sender"><?php echo esc_html( $sender_name ); ?></strong>
								<time datetime="<?php echo esc_attr( $msg->created_at ); ?>"><?php echo esc_html( $msg_date ); ?></time>
							</div>
							<div class="aime-discussions__message-content">
								<?php echo wp_kses_post( wpautop( $msg->content ) ); ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</article>
		</div>
		<?php
		$output = ob_get_clean();
		wp_cache_set( $cache_key, $output, self::CACHE_GROUP, self::CACHE_TTL );

		return $output;
	}

	/* ── Dashboard stats ─────────────────────────────────── */

	public function get_stats(): array {
		global $wpdb;
		$p = $wpdb->prefix;
		$cache_key = self::build_cache_key( 'stats' );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_chatbot_conversations" ) );
		if ( ! $table_exists ) {
			$empty = array(
				'total_conversations' => 0,
				'active_conversations' => 0,
				'total_messages' => 0,
				'leads_captured' => 0,
				'total_bots' => 0,
			);
			wp_cache_set( $cache_key, $empty, self::CACHE_GROUP, self::CACHE_TTL );

			return $empty;
		}

		// Only real chats count: the visitor must have written at least one message.
		$real = "EXISTS (
			SELECT 1 FROM {$p}aime_chatbot_messages mv
			WHERE mv.conversation_id = c.id AND mv.sender_type = 'visitor'
		)";

		$stats = array(
			'total_conversations'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_chatbot_conversations c WHERE {$real}" ),
			'active_conversations' => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}aime_chatbot_conversations c WHERE c.status = %s AND {$real}",
				'active'
			) ),
			'total_messages'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_chatbot_messages WHERE sender_type != 'system'" ),
			'leads_captured'       => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}aime_chatbot_conversations c WHERE c.lead_captured = %d AND {$real}",
				1
			) ),
			'total_bots'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_chatbot_bots" ),
		);
		wp_cache_set( $cache_key, $stats, self::CACHE_GROUP, self::CACHE_TTL );

		return $stats;
	}

	/* ── Monthly conversation count (for free limit check) ─ */

	public static function get_monthly_conversation_count(): int {
		global $wpdb;
		$p = $wpdb->prefix;
		$cache_key = self::build_cache_key( 'monthly_conversation_count', array( 'month' => gmdate( 'Y-m' ) ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_chatbot_conversations" ) );
		if ( ! $table_exists ) {
			return 0;
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}aime_chatbot_conversations WHERE created_at >= %s",
				gmdate( 'Y-m-01 00:00:00' )
			)
		);
		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/* ── Frontend widget ─────────────────────────────────── */

	public function enqueue_widget_assets(): void {
		// Don't load on admin pages.
		if ( is_admin() ) {
			return;
		}

		// Check if there's an active bot.
		$bot = $this->get_active_frontend_bot();
		if ( ! $bot ) {
			return;
		}

		// Check page rules.
		if ( ! $this->should_show_on_page( $bot ) ) {
			return;
		}

		$asset_file = AIME_PLUGIN_DIR . 'build/chatbot-widget.asset.php';
		$asset      = file_exists( $asset_file ) ? include $asset_file : array(
			'dependencies' => array(),
			'version'      => AIME_VERSION,
		);
		$version = aime_asset_version( $asset['version'] ?? AIME_VERSION );

		wp_enqueue_script(
			'aime-chatbot-widget',
			AIME_PLUGIN_URL . 'build/chatbot-widget.js',
			$asset['dependencies'],
			$version,
			true
		);

		if ( file_exists( AIME_PLUGIN_DIR . 'build/chatbot-widget.css' ) ) {
			wp_enqueue_style(
				'aime-chatbot-widget',
				AIME_PLUGIN_URL . 'build/chatbot-widget.css',
				array(),
				$version
			);
		}

		// Generate or retrieve visitor UUID.
		$visitor_id = '';
		if ( isset( $_COOKIE['aime_visitor_id'] ) ) {
			$visitor_id = sanitize_text_field( wp_unslash( $_COOKIE['aime_visitor_id'] ) );
		}

		$theme_config = json_decode( $bot->theme_config ?: '{}', true ) ?: array();
		$lead_config  = json_decode( $bot->lead_capture_config ?: '{}', true ) ?: array();

		// Merge global chatbot settings (needed for theme fallback and widget settings).
		$global_settings = get_option( 'aime_chatbot_settings', array() );

		// Resolve effective theme: per-bot theme_id takes priority, otherwise fall back
		// to the global default_theme setting from Chatbot → Settings.
		$effective_theme = $bot->theme_id ?: 'default';
		if ( 'default' === $effective_theme && ! empty( $global_settings['default_theme'] ) ) {
			$effective_theme = $global_settings['default_theme'];
		}

		// Resolve theme preset colours and merge with any custom overrides.
		$theme_preset  = Services\WidgetService::get_theme( $effective_theme );
		$theme_config  = array_merge( $theme_preset, $theme_config );
		$global_defaults = array(
			'enable_sound_notification' => true,
			'poll_interval_seconds'     => 5,
			'max_message_length'        => 500,
			'consent_message'           => __( 'We use cookies and chat data to provide you with the best support experience. By starting this chat, you consent to our collection and use of this data in accordance with our Privacy Policy. You can end the chat at any time.', 'ai-marketing-expert' ),
			'gdpr_enabled'              => false,
			'enable_typing_indicator'   => true,
			'enable_read_receipts'      => true,
		);
		$gs = array_merge( $global_defaults, $global_settings );

		// Inject global settings into theme_config (widget reads them from themeConfig).
		$theme_config['sound_enabled']          = ! empty( $gs['enable_sound_notification'] );
		$theme_config['poll_interval']           = (int) ( $gs['poll_interval_seconds'] ?? 5 );
		$theme_config['max_message_length']      = (int) ( $gs['max_message_length'] ?? 500 );
		$theme_config['gdpr_enabled']            = ! empty( $gs['gdpr_enabled'] );
		$theme_config['consent_message']         = $gs['consent_message'] ?? '';
		$theme_config['enable_typing_indicator'] = ! empty( $gs['enable_typing_indicator'] );
		$theme_config['enable_read_receipts']    = ! empty( $gs['enable_read_receipts'] );

		wp_localize_script( 'aime-chatbot-widget', 'aimeChatbot', array(
			'restUrl'       => rest_url( aime_rest_namespace() . '/chatbot/public' ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'botId'         => (int) $bot->id,
			'botName'       => $bot->name,
			'welcomeMsg'    => $bot->welcome_message ?: __( 'Hi! How can I help you today?', 'ai-marketing-expert' ),
			'offlineMsg'    => $bot->offline_message ?: '',
			'themeId'       => $effective_theme,
			'themeConfig'   => $theme_config,
			'leadConfig'    => $lead_config,
			'visitorId'     => $visitor_id,
			'hasPro'        => aime_has_pro(),
			'businessHours' => json_decode( $bot->business_hours ?: '{}', true ) ?: array(),
			'siteUrl'       => home_url(),
		) );
	}

	public function render_widget(): void {
		if ( is_admin() ) {
			return;
		}

		$bot = $this->get_active_frontend_bot();
		if ( ! $bot ) {
			return;
		}

		if ( ! $this->should_show_on_page( $bot ) ) {
			return;
		}

		echo '<div id="aime-chatbot-widget"></div>';
	}

	/* ── Helper: get active bot for frontend ─────────── */

	private function get_active_frontend_bot(): ?object {
		global $wpdb;
		$p = $wpdb->prefix;
		$cache_key = self::build_cache_key( 'active_frontend_bot' );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_chatbot_bots" ) );
		if ( ! $table_exists ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$bot = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}aime_chatbot_bots WHERE status = %s ORDER BY id ASC LIMIT 1",
			'active'
		) );
		wp_cache_set( $cache_key, $bot ?: null, self::CACHE_GROUP, self::CACHE_TTL );

		return $bot ?: null;
	}

	/* ── Helper: check page rules ────────────────────── */

	private function should_show_on_page( object $bot ): bool {
		$rules = json_decode( $bot->page_rules ?: '[]', true );
		if ( empty( $rules ) ) {
			return true; // No rules = show everywhere.
		}

		$current_path = wp_parse_url( home_url( add_query_arg( null, null ) ), PHP_URL_PATH ) ?: '/';

		$is_list = array_keys( $rules ) === range( 0, count( $rules ) - 1 );
		if ( $is_list ) {
			$has_include = false;
			$included    = false;

			foreach ( $rules as $rule ) {
				$type    = $rule['type'] ?? 'include';
				$pattern = $rule['pattern'] ?? '';
				if ( '' === $pattern ) {
					continue;
				}

				$matches = fnmatch( $pattern, $current_path ) || fnmatch( $pattern, '/' . ltrim( $current_path, '/' ) );
				if ( 'exclude' === $type && $matches ) {
					return false;
				}

				if ( 'include' === $type ) {
					$has_include = true;
					if ( $matches ) {
						$included = true;
					}
				}
			}

			return $has_include ? $included : true;
		}

		if ( empty( $rules['mode'] ) ) {
			return true;
		}

		$current_url = home_url( add_query_arg( null, null ) );
		$pages       = $rules['pages'] ?? array();

		if ( 'show_on' === $rules['mode'] ) {
			if ( empty( $pages ) ) {
				return true;
			}
			foreach ( $pages as $page_pattern ) {
				if ( fnmatch( $page_pattern, $current_url ) ) {
					return true;
				}
			}
			return false;
		}

		if ( 'hide_on' === $rules['mode'] ) {
			foreach ( $pages as $page_pattern ) {
				if ( fnmatch( $page_pattern, $current_url ) ) {
					return false;
				}
			}
			return true;
		}

		return true;
	}

	/* ── Post save hook (auto-index content) ─────────── */

	public function on_post_save( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		// Schedule re-index so it doesn't slow down the save.
		wp_schedule_single_event( time() + 10, 'aime_chatbot_index_post', array( $post_id ) );
		add_action( 'aime_chatbot_index_post', array( Services\KnowledgeIndexer::class, 'index_single_post' ) );
	}

	/* ── Daily cleanup cron ──────────────────────────── */

	public function daily_cleanup(): void {
		global $wpdb;
		$p = $wpdb->prefix;

		// Auto-close conversations inactive for >24 hours.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$p}aime_chatbot_conversations
			 SET status = 'closed', ended_at = %s, updated_at = %s
			 WHERE status = 'active'
			   AND updated_at < %s",
			current_time( 'mysql', true ),
			current_time( 'mysql', true ),
			gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) )
		) );

		// Purge old conversations based on data retention setting.
		$settings       = get_option( 'aime_chatbot_settings', array() );
		$retention_days = absint( $settings['data_retention_days'] ?? 90 );

		// Free tier capped at 7 days; Pro uses the setting (0 = keep forever).
		if ( ! aime_has_pro() ) {
			$retention_days = min( $retention_days ?: 7, 7 );
		}

		if ( $retention_days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

			// Get conversation IDs to delete.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$old_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$p}aime_chatbot_conversations WHERE created_at < %s",
				$cutoff
			) );

			if ( $old_ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $old_ids ), '%d' ) );

				// Delete messages first.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$p}aime_chatbot_messages WHERE conversation_id IN ({$placeholders})",
					...$old_ids
				) );

				// Delete conversations.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$p}aime_chatbot_conversations WHERE id IN ({$placeholders})",
					...$old_ids
				) );
			}
		}

		self::bump_cache_version();

		// Aggregate analytics for yesterday.
		Services\AnalyticsService::aggregate_daily( gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
	}
}

/* ── Register with Module Manager ──────────────────────── */
add_action( 'aime_load_module_chatbot', function ( $manager ) {
	$manager->register( new ChatbotModule() );
} );
