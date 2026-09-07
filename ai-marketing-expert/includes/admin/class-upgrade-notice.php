<?php
/**
 * Upgrade to Pro Admin Notice.
 *
 * Renders a WordPress-compliant, high-converting admin notice celebrating the
 * 1,000+ active installs milestone, showcasing feature badges, adjusted pricing ($39/yr),
 * and one-click AJAX dismissal.
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UpgradeNotice {

	/**
	 * Meta key used to store notice dismissal.
	 */
	const DISMISS_META_KEY = 'aime_pro_notice_dismissed';

	/**
	 * AJAX action name.
	 */
	const AJAX_ACTION = 'aime_dismiss_pro_notice';

	/**
	 * Nonce action name.
	 */
	const NONCE_ACTION = 'aime_dismiss_pro_notice_nonce';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_dismiss' ) );
		add_action( 'admin_init', array( $this, 'handle_fallback_dismiss' ) );
	}

	/**
	 * Determine if the notice should be rendered.
	 *
	 * @return bool
	 */
	public function should_display(): bool {
		// Only display to users who can manage options (administrators).
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Never display if Pro is already active.
		if ( aime_has_pro() ) {
			return false;
		}

		// Check if dismissed by the current user.
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META_KEY, true ) ) {
			return false;
		}

		// Screen targeting: main dashboard, plugins screen, or any AI Marketing Expert screen.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		if ( in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return true;
		}

		if ( strpos( $screen->id, 'ai-marketing-expert' ) !== false ) {
			return true;
		}

		return false;
	}

	/**
	 * Enqueue notice stylesheet and dismissal script.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! $this->should_display() ) {
			return;
		}

		$version = aime_asset_version( AIME_VERSION );

		wp_enqueue_style(
			'aime-upgrade-notice',
			AIME_PLUGIN_URL . 'assets/css/upgrade-notice.css',
			array(),
			$version
		);

		wp_enqueue_script(
			'aime-upgrade-notice',
			AIME_PLUGIN_URL . 'assets/js/upgrade-notice.js',
			array( 'jquery' ),
			$version,
			true
		);

		wp_localize_script(
			'aime-upgrade-notice',
			'aimeUpgradeNotice',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'action'     => self::AJAX_ACTION,
				'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
				'dismissUrl' => wp_nonce_url(
					add_query_arg( 'aime_dismiss_notice', '1' ),
					self::NONCE_ACTION,
					'aime_nonce'
				),
			)
		);
	}

	/**
	 * Render the upgrade notice HTML.
	 */
	public function render_notice(): void {
		if ( ! $this->should_display() ) {
			return;
		}

		$quick_buy_url = 'https://wpthemespace.com/product/ai-marketing-expert/?add-to-cart=18144';
		$pricing_url   = apply_filters( 'aime_pro_url', 'https://wpthemespace.com/product/ai-marketing-expert/#aime-pricing' );

		$is_ecommerce = class_exists( 'WooCommerce' ) || class_exists( 'Easy_Digital_Downloads' );

		if ( $is_ecommerce ) {
			$notice_title = __( '10x Your Store Sales, Customers & Business with AI Marketing Expert Pro', 'ai-marketing-expert' );
		} else {
			$notice_title = __( 'Supercharge Your Traffic, Visitors & Sales with AI Marketing Expert Pro', 'ai-marketing-expert' );
		}
		$notice_title = apply_filters( 'aime_upgrade_notice_title', $notice_title, $is_ecommerce );

		$features = array(
			array(
				'icon'      => '⚡',
				'bold'      => __( 'Unlimited', 'ai-marketing-expert' ),
				'text'      => __( 'Workflow Automation', 'ai-marketing-expert' ),
				'highlight' => true,
			),
			array(
				'icon'      => '🧠',
				'bold'      => __( 'Unlimited', 'ai-marketing-expert' ),
				'text'      => __( 'Free AI Fallback', 'ai-marketing-expert' ),
				'highlight' => true,
			),
			array(
				'icon'      => '📬',
				'bold'      => __( '$0 Cost', 'ai-marketing-expert' ),
				'text'      => __( 'SMTP Rotation', 'ai-marketing-expert' ),
				'highlight' => true,
			),
			array(
				'icon'      => '🤖',
				'bold'      => __( 'Custom', 'ai-marketing-expert' ),
				'text'      => __( 'Branded Chatbots', 'ai-marketing-expert' ),
			),
			array(
				'icon'      => '🔍',
				'bold'      => __( 'Deep', 'ai-marketing-expert' ),
				'text'      => __( 'SEO & Rank Tracking', 'ai-marketing-expert' ),
			),
			array(
				'icon'      => '📱',
				'bold'      => __( 'Bulk', 'ai-marketing-expert' ),
				'text'      => __( 'Social Scheduler', 'ai-marketing-expert' ),
			),
			array(
				'icon'      => '📧',
				'bold'      => __( 'Advanced', 'ai-marketing-expert' ),
				'text'      => __( 'Drip & A/B Testing', 'ai-marketing-expert' ),
			),
		);
		?>
		<div class="notice notice-info aime-pro-upgrade-notice is-dismissible" data-notice="aime_pro_upgrade">
			<div class="aime-pro-notice-wrap">
				<div class="aime-pro-notice-header">
					<div class="aime-pro-notice-tags">
						<span class="aime-notice-badge aime-badge-milestone">
							<span class="aime-badge-icon">🎉</span>
							<?php esc_html_e( '1,000+ Active Installs Milestone', 'ai-marketing-expert' ); ?>
						</span>
						<span class="aime-notice-badge aime-badge-deal">
							<span class="aime-badge-pulse"></span>
							<span class="aime-badge-fire">🔥</span>
							<?php
							echo wp_kses(
								sprintf(
									/* translators: 1: original price, 2: discounted price */
									__( 'Special Deal: <del>%1$s</del> <strong>%2$s/yr</strong> <span class="aime-save-pill">Save $10</span>', 'ai-marketing-expert' ),
									'$49',
									'$39'
								),
								array(
									'del'    => array(),
									'strong' => array(),
									'span'   => array(
										'class' => array(),
									),
								)
							);
							?>
						</span>
					</div>

					<h3 class="aime-pro-notice-title">
						<?php echo esc_html( $notice_title ); ?>
					</h3>

					<p class="aime-pro-notice-desc">
						<?php
						echo wp_kses(
							__( 'Unlock multi-step workflow automation, <strong class="aime-desc-highlight">unlimited free AI with auto-fallback &amp; rotation</strong> (Gemini, OpenRouter, OpenCode Zen &amp; all other free AI models), $0 free SMTP email sending, custom chatbots, and deep SEO intelligence to skyrocket your visitors, traffic, and sales on autopilot.', 'ai-marketing-expert' ),
							array(
								'strong' => array(
									'class' => array(),
								),
							)
						);
						?>
					</p>
				</div>

				<div class="aime-pro-notice-features">
					<?php foreach ( $features as $feature ) : ?>
						<span class="aime-feature-pill <?php echo ! empty( $feature['highlight'] ) ? 'aime-feature-pill--hot' : ''; ?>">
							<span class="aime-pill-icon"><?php echo esc_html( $feature['icon'] ); ?></span>
							<strong><?php echo esc_html( $feature['bold'] ); ?></strong>&nbsp;<?php echo esc_html( $feature['text'] ); ?>
						</span>
					<?php endforeach; ?>
				</div>

				<div class="aime-pro-notice-footer">
					<div class="aime-pro-notice-actions">
						<a href="<?php echo esc_url( $quick_buy_url ); ?>" class="button button-primary aime-btn-quickbuy" target="_blank" rel="noopener noreferrer">
							<span class="aime-btn-bolt">⚡</span>
							<?php esc_html_e( 'Quick Buy Single Site - $39', 'ai-marketing-expert' ); ?>
						</a>

						<a href="<?php echo esc_url( $pricing_url ); ?>" class="button button-secondary aime-btn-plans" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'View All Plans & Lifetime Deal →', 'ai-marketing-expert' ); ?>
						</a>

						<button type="button" class="aime-notice-dismiss-link">
							<?php esc_html_e( 'Maybe Later', 'ai-marketing-expert' ); ?>
						</button>
					</div>

					<div class="aime-pro-notice-guarantee">
						<span class="aime-guarantee-item">🛡️ <?php esc_html_e( '30-Day Money-Back Guarantee', 'ai-marketing-expert' ); ?></span>
						<span class="aime-guarantee-dot">&bull;</span>
						<span class="aime-guarantee-item"><?php esc_html_e( 'Instant Activation', 'ai-marketing-expert' ); ?></span>
						<span class="aime-guarantee-dot">&bull;</span>
						<span class="aime-guarantee-item"><?php esc_html_e( 'Zero Risk', 'ai-marketing-expert' ); ?></span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX dismissal.
	 */
	public function ajax_dismiss(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ai-marketing-expert' ) ), 403 );
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META_KEY, 1 );

		wp_send_json_success( array( 'dismissed' => true ) );
	}

	/**
	 * Handle non-AJAX fallback dismissal via query parameter.
	 */
	public function handle_fallback_dismiss(): void {
		if ( ! isset( $_GET['aime_dismiss_notice'] ) || '1' !== $_GET['aime_dismiss_notice'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$nonce = isset( $_GET['aime_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['aime_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META_KEY, 1 );

		$redirect_url = remove_query_arg( array( 'aime_dismiss_notice', 'aime_nonce' ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}
}
