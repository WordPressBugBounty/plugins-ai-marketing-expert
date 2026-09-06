<?php
/**
 * WP Admin Dashboard Widget for AI Marketing Expert.
 *
 * Renders a single summary widget on wp-admin/index.php showing key stats
 * from all active modules. Chatbot section auto-refreshes via AJAX when
 * there are active conversations; all other sections refresh on page load only.
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard widget handler.
 *
 * Registered by the Plugin class when is_admin() is true.
 */
class DashboardWidget {

	/**
	 * Module manager instance.
	 *
	 * @var ModuleManager
	 */
	private ModuleManager $modules;

	/**
	 * Constructor.
	 *
	 * @param ModuleManager $modules Module manager instance.
	 */
	public function __construct( ModuleManager $modules ) {
		$this->modules = $modules;

		add_action( 'wp_dashboard_setup', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_aime_dashboard_widget_refresh', array( $this, 'ajax_refresh' ) );
		add_action( 'wp_ajax_aime_chatbot_realtime', array( $this, 'ajax_chatbot_realtime' ) );

		// Force widget to top of dashboard regardless of saved user order.
		add_filter( 'get_user_option_meta-box-order_dashboard', array( $this, 'force_widget_order' ) );

		// Reorder registration order for users with no saved widget order.
		add_action( 'wp_dashboard_setup', array( $this, 'reorder_registered_widget' ), 100 );
	}

	/**
	 * Enqueue widget assets on the main WP dashboard only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Only load on the main WP dashboard.
		if ( 'index.php' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'aime-dashboard-widget',
			AIME_PLUGIN_URL . 'assets/css/dashboard-widget.css',
			array(),
			AIME_VERSION
		);

		wp_enqueue_script(
			'aime-dashboard-widget',
			AIME_PLUGIN_URL . 'assets/js/dashboard-widget.js',
			array(),
			AIME_VERSION,
			true
		);
	}

	/**
	 * Register the dashboard widget.
	 */
	public function register(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'aime_dashboard_widget',
			'AI Marketing Expert',
			array( $this, 'render' )
		);
	}

	/**
	 * Get stats for a specific module via its registered filter.
	 *
	 * @param string $module_id Module identifier.
	 * @return array Module stats array.
	 */
	private function get_module_stats( string $module_id ): array {
		if ( ! $this->modules->is_active( $module_id ) ) {
			return array();
		}

		return apply_filters( "aime_{$module_id}_dashboard_stats", array() );
	}

	/**
	 * Get chatbot real-time data for the active conversations feed.
	 *
	 * @return array Associative array with active_count, messages_today,
	 *               leads_today, and recent conversations.
	 */
	private function get_chatbot_realtime(): array {
		global $wpdb;

		$p              = $wpdb->prefix;
		$conversations  = "{$p}aime_chatbot_conversations";
		$messages       = "{$p}aime_chatbot_messages";
		$bots           = "{$p}aime_chatbot_bots";
		$today          = gmdate( 'Y-m-d 00:00:00' );

		// Check table existence.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $conversations ) ) ) {
			return array(
				'active_count'   => 0,
				'messages_today' => 0,
				'leads_today'    => 0,
				'total_bots'     => 0,
				'recent'         => array(),
			);
		}

		// Only count conversations where the visitor has sent at least one message.
		// A session with only a bot greeting (system/ai sender) is not truly "active".
		$active_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$conversations} c
				WHERE c.status IN (%s, %s)
				AND EXISTS (
					SELECT 1 FROM {$messages} m
					WHERE m.conversation_id = c.id AND m.sender_type = %s
				)",
				'active',
				'human_takeover',
				'visitor'
			)
		);

		// Only count visitor messages so welcome/system/AI messages don't inflate the total.
		$messages_today = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$messages} WHERE created_at >= %s AND sender_type = %s",
				$today,
				'visitor'
			)
		);

		$leads_today = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$conversations} WHERE lead_captured = %d AND created_at >= %s",
				1,
				$today
			)
		);

		$total_bots = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bots}" );

		// Fetch recent conversations (last 5) with last message preview.
		$recent = array();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $messages ) ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table/column names interpolated.
			// Only show conversations where the visitor has actually written something;
			// msg_count reflects visitor messages only so "1 msg" always means real engagement.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.id, c.visitor_name, c.page_url, c.status, c.created_at,
							(
								SELECT SUBSTRING(m.content, 1, 60)
								FROM {$messages} m
								WHERE m.conversation_id = c.id
								ORDER BY m.id DESC
								LIMIT 1
							) AS last_message,
							(
								SELECT COUNT(*)
								FROM {$messages} m2
								WHERE m2.conversation_id = c.id
								AND m2.sender_type = %s
							) AS msg_count
					FROM {$conversations} c
					WHERE c.created_at >= DATE_SUB(%s, INTERVAL 24 HOUR)
					AND EXISTS (
						SELECT 1 FROM {$messages} mv
						WHERE mv.conversation_id = c.id AND mv.sender_type = %s
					)
					ORDER BY c.created_at DESC
					LIMIT 5",
					'visitor',
					$today,
					'visitor'
				)
			);
		}

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$recent[] = array(
					'id'           => (int) $row->id,
					'visitor_name' => $row->visitor_name ? $row->visitor_name : __( 'Visitor', 'ai-marketing-expert' ),
					'page_url'     => $row->page_url ? $row->page_url : '',
					'status'       => $row->status,
					'last_message' => $row->last_message ? $row->last_message : '',
					'msg_count'    => (int) $row->msg_count,
					'created_at'   => $row->created_at,
					'time_ago'     => sprintf(
						/* translators: %s: human-readable time difference, e.g. "15 hours". */
						__( '%s ago', 'ai-marketing-expert' ),
						human_time_diff( strtotime( $row->created_at . ' UTC' ), time() )
					),
				);
			}
		}

		return array(
			'active_count'   => $active_count,
			'messages_today' => $messages_today,
			'leads_today'    => $leads_today,
			'total_bots'     => $total_bots,
			'recent'         => $recent,
		);
	}

	/**
	 * Render the widget contents.
	 *
	 * @param string|array $args         Widget arguments (WP may pass empty string).
	 * @param mixed|null   $callback_args Callback arguments.
	 */
	public function render( $args = '', $callback_args = null ): void {
		$chatbot = $this->get_chatbot_realtime();
		$email   = $this->get_module_stats( 'email-marketing' );
		$seo     = $this->get_module_stats( 'seo' );
		$content = $this->get_module_stats( 'content-generator' );
		$social  = $this->get_module_stats( 'social-media' );
		$auto    = $this->get_module_stats( 'workflow-automation' );

		wp_localize_script(
			'aime-dashboard-widget',
			'aimeWidgetData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'aime_dashboard_widget' ),
				'hasActive' => $chatbot['active_count'] > 0,
			)
		);
		?>
		<div class="aime-dw" data-has-active="<?php echo esc_attr( $chatbot['active_count'] > 0 ? '1' : '0' ); ?>">

			<?php $this->render_chatbot_section( $chatbot ); ?>
			<?php $this->render_automation_section( $auto ); ?>
			<?php $this->render_email_section( $email ); ?>
			<?php $this->render_compact_row( 'seo', __( 'SEO Analyzer', 'ai-marketing-expert' ), $this->get_compact_seo( $seo ), 'ai-marketing-expert-seo' ); ?>
			<?php $this->render_compact_row( 'content', __( 'Content Generator', 'ai-marketing-expert' ), $this->get_compact_content( $content ), 'ai-marketing-expert-content' ); ?>
			<?php $this->render_compact_row( 'social', __( 'Social Media', 'ai-marketing-expert' ), $this->get_compact_social( $social ), 'ai-marketing-expert-social' ); ?>

			<div class="aime-dw__footer">
				<span class="aime-dw__updated" id="aime-dw-timestamp">
					<?php
					printf(
						/* translators: %s: human-readable time difference */
						esc_html__( 'Updated %s ago', 'ai-marketing-expert' ),
						esc_html( human_time_diff( time(), time() ) )
					);
					?>
				</span>
				<span class="aime-dw__footer-links">
					<a
						class="aime-dw__ext-link aime-dw__ext-link--help"
						href="<?php echo esc_url( 'https://wpthemespace.com/ai-marketing-expert-documentation/' ); ?>"
						target="_blank"
						rel="noopener noreferrer"
					>
						<?php esc_html_e( 'Help', 'ai-marketing-expert' ); ?>
						<span class="dashicons dashicons-external"></span>
					</a>
					<?php if ( ! aime_has_pro() ) : ?>
						<span class="aime-dw__footer-sep" aria-hidden="true"></span>
						<a
							class="aime-dw__ext-link aime-dw__ext-link--pro"
							href="<?php echo esc_url( apply_filters( 'aime_pro_url', 'https://wpthemespace.com/product/ai-marketing-expert/#aime-pricing' ) ); ?>"
							target="_blank"
							rel="noopener noreferrer"
						>
							<?php esc_html_e( 'Go Pro', 'ai-marketing-expert' ); ?>
							<span class="dashicons dashicons-external"></span>
						</a>
					<?php endif; ?>
				</span>
				<button type="button" class="aime-dw__refresh" id="aime-dw-refresh-btn" aria-label="<?php esc_attr_e( 'Refresh', 'ai-marketing-expert' ); ?>">
					<span class="dashicons dashicons-update"></span>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Chatbot section.
	 *
	 * @param array $data Chatbot realtime data.
	 */
	private function render_chatbot_section( array $data ): void {
		$active  = $data['active_count'] ?? 0;
		$messages = $data['messages_today'] ?? 0;
		$leads   = $data['leads_today'] ?? 0;
		$recent  = $data['recent'] ?? array();
		?>
		<div class="aime-dw__section aime-dw__section--chatbot" id="aime-dw-chatbot">
			<div class="aime-dw__section-header">
				<span class="aime-dw__icon">&#x1F4AC;</span>
				<span class="aime-dw__title"><?php esc_html_e( 'Chatbot', 'ai-marketing-expert' ); ?></span>
				<?php if ( $active > 0 ) : ?>
					<span class="aime-dw__live-badge" id="aime-dw-live-badge">
						<span class="aime-dw__live-dot"></span>
						<?php
						printf(
							/* translators: %d: number of active conversations */
							esc_html( _n( '%d active', '%d active', $active, 'ai-marketing-expert' ) ),
							absint( $active )
						);
						?>
					</span>
				<?php endif; ?>
			</div>
			<div class="aime-dw__cards" id="aime-dw-chatbot-cards">
				<div class="aime-dw__card">
					<span class="aime-dw__card-value" id="aime-dw-active-count"><?php echo esc_html( $active ); ?></span>
					<span class="aime-dw__card-label"><?php esc_html_e( 'Active Now', 'ai-marketing-expert' ); ?></span>
				</div>
				<div class="aime-dw__card">
					<span class="aime-dw__card-value" id="aime-dw-messages-today"><?php echo esc_html( $messages ); ?></span>
					<span class="aime-dw__card-label"><?php esc_html_e( 'Messages Today', 'ai-marketing-expert' ); ?></span>
				</div>
				<div class="aime-dw__card">
					<span class="aime-dw__card-value" id="aime-dw-leads-today"><?php echo esc_html( $leads ); ?></span>
					<span class="aime-dw__card-label"><?php esc_html_e( 'Leads Today', 'ai-marketing-expert' ); ?></span>
				</div>
			</div>
			<?php if ( ! empty( $recent ) ) : ?>
				<div class="aime-dw__feed" id="aime-dw-chatbot-feed">
					<?php foreach ( $recent as $conv ) : ?>
						<div class="aime-dw__feed-item <?php echo 'human_takeover' === $conv['status'] ? 'aime-dw__feed-item--attention' : ''; ?>">
							<span class="aime-dw__feed-dot <?php echo 'active' === $conv['status'] ? 'aime-dw__feed-dot--green' : 'aime-dw__feed-dot--orange'; ?>"></span>
							<span class="aime-dw__feed-name"><?php echo esc_html( $conv['visitor_name'] ); ?></span>
							<?php if ( $conv['page_url'] ) : ?>
								<span class="aime-dw__feed-page"><?php echo esc_html( wp_parse_url( $conv['page_url'], PHP_URL_PATH ) ?: '/' ); ?></span>
							<?php endif; ?>
							<span class="aime-dw__feed-meta">
								<?php
								printf(
									/* translators: %d: number of messages */
									esc_html( _n( '%d msg', '%d msgs', $conv['msg_count'], 'ai-marketing-expert' ) ),
									absint( $conv['msg_count'] )
								);
								?>
							</span>
							<span class="aime-dw__feed-time"><?php echo esc_html( $conv['time_ago'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="aime-dw__section-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-marketing-expert-chatbot#conversations' ) ); ?>" class="aime-dw__link">
					<?php esc_html_e( 'View Live Dashboard', 'ai-marketing-expert' ); ?> &rarr;
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Automation section.
	 *
	 * @param array $data Automation stats.
	 */
	private function render_automation_section( array $data ): void {
		$active_w = $data['active_workflows'] ?? 0;
		$runs     = $data['runs_this_month'] ?? 0;
		$limit    = $data['runs_monthly_limit'] ?? null;
		$next_run = $data['next_run_at'] ?? null;
		?>
		<div class="aime-dw__section aime-dw__section--automation">
			<div class="aime-dw__section-header">
				<span class="aime-dw__icon">&#x2699;&#xFE0F;</span>
				<span class="aime-dw__title"><?php esc_html_e( 'Workflow Automation', 'ai-marketing-expert' ); ?></span>
			</div>
			<div class="aime-dw__cards">
				<div class="aime-dw__card">
					<span class="aime-dw__card-value" id="aime-dw-auto-active"><?php echo esc_html( $active_w ); ?></span>
					<span class="aime-dw__card-label"><?php esc_html_e( 'Active Workflows', 'ai-marketing-expert' ); ?></span>
				</div>
				<div class="aime-dw__card">
					<span class="aime-dw__card-value">
						<span id="aime-dw-auto-runs"><?php echo esc_html( $runs ); ?></span><span class="aime-dw__card-limit" id="aime-dw-auto-runs-limit"><?php
						if ( null !== $limit ) {
							echo '/' . esc_html( $limit );
						}
						?></span>
					</span>
					<span class="aime-dw__card-label"><?php esc_html_e( 'Runs This Month', 'ai-marketing-expert' ); ?></span>
				</div>
				<div class="aime-dw__card">
					<span class="aime-dw__card-value aime-dw__card-value--small" id="aime-dw-auto-next">
						<?php
						if ( $next_run ) {
							$next_ts = strtotime( $next_run );
							if ( $next_ts && $next_ts > time() ) {
								echo esc_html( human_time_diff( time(), $next_ts ) );
							} else {
								esc_html_e( 'Overdue', 'ai-marketing-expert' );
							}
						} else {
							esc_html_e( '--', 'ai-marketing-expert' );
						}
						?>
					</span>
					<span class="aime-dw__card-label"><?php esc_html_e( 'Next Run', 'ai-marketing-expert' ); ?></span>
				</div>
			</div>
			<div class="aime-dw__section-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-marketing-expert-workflow-automation' ) ); ?>" class="aime-dw__link">
					<?php esc_html_e( 'Manage', 'ai-marketing-expert' ); ?> &rarr;
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Email Marketing section.
	 *
	 * @param array $data Email stats.
	 */
	private function render_email_section( array $data ): void {
		$contacts  = $data['active_contacts'] ?? 0;
		$campaigns = $data['total_campaigns'] ?? 0;
		$open_rate = $data['open_rate'] ?? 0;
		?>
		<div class="aime-dw__section aime-dw__section--email">
			<div class="aime-dw__section-header">
				<span class="aime-dw__icon">&#x2709;&#xFE0F;</span>
				<span class="aime-dw__title"><?php esc_html_e( 'Email Marketing', 'ai-marketing-expert' ); ?></span>
			</div>
			<div class="aime-dw__cards">
				<div class="aime-dw__card">
					<span class="aime-dw__card-value" id="aime-dw-email-contacts"><?php echo esc_html( number_format_i18n( $contacts ) ); ?></span>
					<span class="aime-dw__card-label"><?php esc_html_e( 'Active Contacts', 'ai-marketing-expert' ); ?></span>
				</div>
				<div class="aime-dw__card">
					<span class="aime-dw__card-value" id="aime-dw-email-campaigns"><?php echo esc_html( $campaigns ); ?></span>
					<span class="aime-dw__card-label"><?php esc_html_e( 'Campaigns', 'ai-marketing-expert' ); ?></span>
				</div>
				<div class="aime-dw__card">
					<span class="aime-dw__card-value" id="aime-dw-email-openrate">
						<?php echo esc_html( $open_rate ); ?><span class="aime-dw__card-unit">%</span>
					</span>
					<span class="aime-dw__card-label"><?php esc_html_e( 'Open Rate', 'ai-marketing-expert' ); ?></span>
				</div>
			</div>
			<div class="aime-dw__section-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-marketing-expert-email' ) ); ?>" class="aime-dw__link">
					<?php esc_html_e( 'Manage', 'ai-marketing-expert' ); ?> &rarr;
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Build compact display data for SEO.
	 *
	 * @param array $data SEO stats.
	 * @return array Formatted items.
	 */
	private function get_compact_seo( array $data ): array {
		return array(
			array(
				'label' => __( 'Tracked Keywords', 'ai-marketing-expert' ),
				'value' => $data['tracked_keywords'] ?? 0,
			),
			array(
				'label' => __( 'Avg Score', 'ai-marketing-expert' ),
				'value' => ( $data['avg_audit_score'] ?? 0 ) . '%',
			),
			array(
				'label' => __( 'Backlinks', 'ai-marketing-expert' ),
				'value' => $data['total_backlinks'] ?? 0,
			),
		);
	}

	/**
	 * Build compact display data for Content Generator.
	 *
	 * @param array $data Content stats.
	 * @return array Formatted items.
	 */
	private function get_compact_content( array $data ): array {
		return array(
			array(
				'label' => __( 'Published', 'ai-marketing-expert' ),
				'value' => $data['published_articles'] ?? 0,
			),
			array(
				'label' => __( 'Drafts', 'ai-marketing-expert' ),
				'value' => $data['draft_articles'] ?? 0,
			),
			array(
				'label' => __( 'Avg SEO Score', 'ai-marketing-expert' ),
				'value' => ( $data['avg_seo_score'] ?? 0 ) . '%',
			),
		);
	}

	/**
	 * Build compact display data for Social Media.
	 *
	 * @param array $data Social stats.
	 * @return array Formatted items.
	 */
	private function get_compact_social( array $data ): array {
		return array(
			array(
				'label' => __( 'Connected', 'ai-marketing-expert' ),
				'value' => $data['total_accounts'] ?? 0,
			),
			array(
				'label' => __( 'Posts This Month', 'ai-marketing-expert' ),
				'value' => $data['posts_this_month'] ?? 0,
			),
			array(
				'label' => __( 'Scheduled', 'ai-marketing-expert' ),
				'value' => $data['scheduled_posts'] ?? 0,
			),
		);
	}

	/**
	 * Render a compact single-line row for smaller modules.
	 *
	 * @param string $id    Module identifier.
	 * @param string $title Module display name.
	 * @param array  $items Metric items (label/value pairs).
	 * @param string $slug  Admin page slug.
	 */
	private function render_compact_row( string $id, string $title, array $items, string $slug ): void {
		?>
		<div class="aime-dw__section aime-dw__section--compact aime-dw__section--<?php echo esc_attr( $id ); ?>">
			<div class="aime-dw__section-header">
				<span class="aime-dw__title"><?php echo esc_html( $title ); ?></span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" class="aime-dw__link aime-dw__link--inline">
					<?php esc_html_e( 'Manage', 'ai-marketing-expert' ); ?> &rarr;
				</a>
			</div>
			<div class="aime-dw__compact-stats">
				<?php foreach ( $items as $idx => $item ) : ?>
					<span class="aime-dw__compact-stat">
						<strong id="aime-dw-<?php echo esc_attr( $id ); ?>-<?php echo esc_attr( $idx ); ?>"><?php echo esc_html( $item['value'] ); ?></strong>
						<?php echo esc_html( $item['label'] ); ?>
					</span>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler: full widget refresh.
	 */
	public function ajax_refresh(): void {
		check_ajax_referer( 'aime_dashboard_widget', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$chatbot = $this->get_chatbot_realtime();
		$email   = $this->get_module_stats( 'email-marketing' );
		$auto    = $this->get_module_stats( 'workflow-automation' );
		$seo     = $this->get_module_stats( 'seo' );
		$content = $this->get_module_stats( 'content-generator' );
		$social  = $this->get_module_stats( 'social-media' );

		wp_send_json_success(
			array(
				'chatbot' => $chatbot,
				'email'   => $email,
				'auto'    => $auto,
				'seo'     => $this->get_compact_seo( $seo ),
				'content' => $this->get_compact_content( $content ),
				'social'  => $this->get_compact_social( $social ),
				'time'    => time(),
			)
		);
	}

	/**
	 * AJAX handler: chatbot real-time refresh only.
	 */
	public function ajax_chatbot_realtime(): void {
		check_ajax_referer( 'aime_dashboard_widget', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		wp_send_json_success( $this->get_chatbot_realtime() );
	}

	/**
	 * Force our widget to the top of the normal column.
	 *
	 * WordPress caches per-user widget order. This filter moves our widget
	 * to position 2 in the normal column regardless of saved order.
	 *
	 * @param mixed $result Saved order option value.
	 * @return mixed Modified order.
	 */
	public function force_widget_order( $result ) {
		if ( ! is_array( $result ) || empty( $result['normal'] ) || ! is_string( $result['normal'] ) ) {
			return $result;
		}

		$normal = $result['normal'];

		// If our widget isn't in the list, bail.
		if ( false === strpos( $normal, 'aime_dashboard_widget' ) ) {
			return $result;
		}

		// Parse the comma-separated widget IDs.
		$widgets = array_map( 'trim', explode( ',', $normal ) );

		// Remove our widget from current position.
		$widgets = array_diff( $widgets, array( 'aime_dashboard_widget' ) );
		$widgets = array_values( $widgets );

		// Insert at position 1 (0-indexed) — 2nd slot.
		array_splice( $widgets, 1, 0, array( 'aime_dashboard_widget' ) );

		$result['normal'] = implode( ',', $widgets );

		return $result;
	}

	/**
	 * Move our widget to the 2nd slot in the registered meta boxes.
	 *
	 * Applies for users who have never dragged widgets (no saved order),
	 * where WordPress falls back to registration order.
	 */
	public function reorder_registered_widget(): void {
		global $wp_meta_boxes;

		if ( empty( $wp_meta_boxes['dashboard']['normal']['core']['aime_dashboard_widget'] ) ) {
			return;
		}

		$core   = $wp_meta_boxes['dashboard']['normal']['core'];
		$widget = $core['aime_dashboard_widget'];
		unset( $core['aime_dashboard_widget'] );

		// Rebuild with our widget in the 2nd slot.
		$reordered = array();
		$position  = 0;
		foreach ( $core as $id => $box ) {
			if ( 1 === $position ) {
				$reordered['aime_dashboard_widget'] = $widget;
			}
			$reordered[ $id ] = $box;
			$position++;
		}

		// Fewer than 2 widgets registered — append at the end.
		if ( ! isset( $reordered['aime_dashboard_widget'] ) ) {
			$reordered['aime_dashboard_widget'] = $widget;
		}

		$wp_meta_boxes['dashboard']['normal']['core'] = $reordered;
	}
}
