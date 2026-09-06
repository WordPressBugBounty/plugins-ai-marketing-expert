<?php
/**
 * Admin handler - menus, enqueue, dashboard page.
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	/**
	 * Module Manager.
	 *
	 * @var ModuleManager
	 */
	private ModuleManager $modules;

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * All hook suffixes for submenu pages.
	 *
	 * @var array
	 */
	private array $hook_suffixes = array();

	/**
	 * Constructor.
	 *
	 * @param ModuleManager $modules Module manager instance.
	 */
	public function __construct( ModuleManager $modules ) {
		$this->modules = $modules;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'activation_redirect' ) );
		add_action( 'admin_init', array( $this, 'redirect_inactive_module_pages' ) );
		add_filter( 'plugin_action_links_' . AIME_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Register admin menu and submenu pages.
	 */
	public function register_menu(): void {
		// Main menu page — Dashboard.
		$this->hook_suffix = add_menu_page(
			__( 'AI Marketing Expert', 'ai-marketing-expert' ),
			__( 'AI Marketing', 'ai-marketing-expert' ),
			'manage_options',
			'ai-marketing-expert',
			array( $this, 'render_app' ),
			'dashicons-megaphone',
			2
		);

		$this->hook_suffixes[] = $this->hook_suffix;

		// Dashboard submenu (replaces the auto-generated duplicate).
		$this->hook_suffixes[] = add_submenu_page(
			'ai-marketing-expert',
			__( 'Dashboard', 'ai-marketing-expert' ),
			__( 'Dashboard', 'ai-marketing-expert' ),
			'manage_options',
			'ai-marketing-expert',
			array( $this, 'render_app' )
		);

		$this->register_module_submenus();

		// ── AI Providers submenu ─────────────────────────────────────
		$this->hook_suffixes[] = add_submenu_page(
			'ai-marketing-expert',
			__( 'AI Providers', 'ai-marketing-expert' ),
			__( 'AI Providers', 'ai-marketing-expert' ),
			'manage_options',
			'ai-marketing-expert-ai-providers',
			array( $this, 'render_app' )
		);

		// ── Settings submenu ─────────────────────────────────────────
		$this->hook_suffixes[] = add_submenu_page(
			'ai-marketing-expert',
			__( 'Settings', 'ai-marketing-expert' ),
			__( 'Settings', 'ai-marketing-expert' ),
			'manage_options',
			'ai-marketing-expert-settings',
			array( $this, 'render_app' )
		);
	}

	/**
	 * Register only active module submenu pages.
	 *
	 * @return void
	 */
	private function register_module_submenus(): void {
		$module_pages = $this->get_module_admin_pages();

		foreach ( $module_pages as $module_id => $page ) {
			if ( ! $this->modules->is_active( $module_id ) ) {
				continue;
			}

			$menu_title = $page['menu_title'];
			if ( 'chatbot' === $module_id ) {
				$active_count = $this->get_active_conversation_count();
				if ( $active_count > 0 ) {
					$menu_title .= sprintf( ' <span class="awaiting-mod">%d</span>', $active_count );
				}
			}

			$this->hook_suffixes[] = add_submenu_page(
				'ai-marketing-expert',
				$page['page_title'],
				$menu_title,
				'manage_options',
				$page['slug'],
				array( $this, 'render_app' )
			);
		}
	}

	/**
	 * Get module admin page metadata keyed by module ID.
	 *
	 * @return array
	 */
	private function get_module_admin_pages(): array {
		return array(
			'email-marketing'   => array(
				'slug'       => 'ai-marketing-expert-email',
				'page_title' => __( 'Email Marketing', 'ai-marketing-expert' ),
				'menu_title' => __( 'Email Marketing', 'ai-marketing-expert' ),
			),
			'seo'               => array(
				'slug'       => 'ai-marketing-expert-seo',
				'page_title' => __( 'SEO Analyzer', 'ai-marketing-expert' ),
				'menu_title' => __( 'SEO Analyzer', 'ai-marketing-expert' ),
			),
			'content-generator' => array(
				'slug'       => 'ai-marketing-expert-content',
				'page_title' => __( 'Content Generator', 'ai-marketing-expert' ),
				'menu_title' => __( 'Content Generator', 'ai-marketing-expert' ),
			),
			'chatbot'           => array(
				'slug'       => 'ai-marketing-expert-chatbot',
				'page_title' => __( 'Chatbot', 'ai-marketing-expert' ),
				'menu_title' => __( 'Chatbot', 'ai-marketing-expert' ),
			),
			'social-media'      => array(
				'slug'       => 'ai-marketing-expert-social',
				'page_title' => __( 'Social Media', 'ai-marketing-expert' ),
				'menu_title' => __( 'Social Media', 'ai-marketing-expert' ),
			),
			'workflow-automation' => array(
				'slug'       => 'ai-marketing-expert-workflow-automation',
				'page_title' => __( 'Workflow Automation', 'ai-marketing-expert' ),
				'menu_title' => __( 'Workflow Automation', 'ai-marketing-expert' ),
			),
		);
	}

	/**
	 * Redirect direct access to inactive module pages back to Settings > Modules.
	 *
	 * @return void
	 */
	public function redirect_inactive_module_pages(): void {
		if ( wp_doing_ajax() || ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page slug used for route guarding; no state change occurs here.
		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' === $current_page ) {
			return;
		}

		foreach ( $this->get_module_admin_pages() as $module_id => $page ) {
			if ( $current_page === $page['slug'] && ! $this->modules->is_active( $module_id ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=ai-marketing-expert-settings&tab=modules&module_disabled=1' ) );
				exit;
			}
		}
	}

	/**
	 * Enqueue React app and styles.
	 *
	 * @param string $hook_suffix Current page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Only load on our admin pages.
		if ( ! in_array( $hook_suffix, $this->hook_suffixes, true ) ) {
			return;
		}

		$asset_file = AIME_PLUGIN_DIR . 'build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;
		$version = aime_asset_version( $asset['version'] ?? AIME_VERSION );

		// Enqueue React app.
		wp_enqueue_script(
			'aime-dashboard',
			AIME_PLUGIN_URL . 'build/index.js',
			array_merge( $asset['dependencies'], array( 'wp-block-library' ) ),
			$version,
			true
		);

		// Enqueue styles.
		// style-index.css contains vendor CSS (e.g. @xyflow/react) extracted
		// from JS-imported stylesheets – it must be loaded before our own CSS.
		if ( file_exists( AIME_PLUGIN_DIR . 'build/style-index.css' ) ) {
			wp_enqueue_style(
				'aime-dashboard-vendor',
				AIME_PLUGIN_URL . 'build/style-index.css',
				array( 'wp-components' ),
				$version
			);
		}
		if ( file_exists( AIME_PLUGIN_DIR . 'build/index.css' ) ) {
			wp_enqueue_style(
				'aime-dashboard',
				AIME_PLUGIN_URL . 'build/index.css',
				array( 'wp-components', 'aime-dashboard-vendor' ),
				$version
			);
		}

		// Localize script with plugin data.
		wp_localize_script(
			'aime-dashboard',
			'aimeData',
			$this->get_localized_data()
		);

		// WordPress media uploader (for image uploads).
		wp_enqueue_media();

		// ── Gutenberg Block Editor for email composition ─────────────
		// Enqueue core block styles and scripts.
		wp_enqueue_style( 'wp-block-library' );
		wp_enqueue_style( 'wp-block-editor' );
		wp_enqueue_style( 'wp-format-library' );
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'wp-editor' );

		// Editor scripts – declared as dependencies in the build asset file
		// but we explicitly enqueue to ensure they load.
		wp_enqueue_script( 'wp-block-editor' );
		wp_enqueue_script( 'wp-block-library' );
		wp_enqueue_script( 'wp-blocks' );
		wp_enqueue_script( 'wp-data' );
		wp_enqueue_script( 'wp-rich-text' );
		wp_enqueue_script( 'wp-format-library' );
		wp_enqueue_script( 'wp-media-utils' );

		// Also keep TinyMCE available for backwards compatibility / HTML mode.
		wp_enqueue_editor();

		// Animate.css (bundled locally for WordPress.org compliance).
		wp_enqueue_style(
			'animate-css',
			AIME_PLUGIN_URL . 'assets/css/animate.min.css',
			array(),
			aime_asset_version( '4.1.1' )
		);
	}

	/**
	 * Build block-editor settings (colors, typography, spacing, etc.)
	 * so the standalone Gutenberg editor exposes full inspector panels.
	 *
	 * @return array
	 */
	private function get_block_editor_settings(): array {
		$block_editor_context = new \WP_Block_Editor_Context( array( 'name' => 'core/edit-post' ) );

		// get_block_editor_settings() merges theme.json / global-styles into the result.
		$core = function_exists( 'get_block_editor_settings' )
			? get_block_editor_settings( array(), $block_editor_context )
			: array();

		$experimental = isset( $core['__experimentalFeatures'] ) ? $core['__experimentalFeatures'] : array();

		// Guarantee the essentials exist so BlockInspector shows its panels.
		if ( empty( $experimental ) ) {
			$experimental = array(
				'appearanceTools' => true,
				'color'           => array(
					'background'       => true,
					'text'             => true,
					'defaultPalette'   => true,
					'defaultGradients' => false,
				),
				'typography'      => array(
					'fontSizes'       => array(),
					'lineHeight'      => true,
					'dropCap'         => false,
				),
				'spacing'         => array(
					'padding' => true,
					'margin'  => true,
				),
			);
		}

		// Allowed image sizes for the Media block.
		$image_sizes = array();
		foreach ( get_intermediate_image_sizes() as $size ) {
			$image_sizes[] = array(
				'slug' => $size,
				'name' => $size,
			);
		}

		return array(
			'alignWide'                         => false,
			'allowedMimeTypes'                  => get_allowed_mime_types(),
			'imageSizes'                        => $image_sizes,
			'isRTL'                             => is_rtl(),
			'maxUploadFileSize'                 => wp_max_upload_size(),
			'__experimentalBlockPatterns'       => array(),
			'__experimentalFeatures'            => $experimental,
			'disableCustomColors'               => get_theme_support( 'disable-custom-colors' ) ? true : false,
			'disableCustomFontSizes'            => false,
			'disableCustomGradients'            => true,
			'enableCustomLineHeight'            => true,
			'enableCustomSpacing'               => true,
			'enableCustomUnits'                 => false,
			'keepCaretInsideBlock'              => false,
			'colors'                            => isset( $core['colors'] ) ? $core['colors'] : array(),
			'fontSizes'                         => isset( $core['fontSizes'] ) ? $core['fontSizes'] : array(),
			'gradients'                         => array(),
			'styles'                            => isset( $core['styles'] ) ? $core['styles'] : array(),
		);
	}

	/**
	 * Get the localized data for the React app.
	 *
	 * @return array
	 */
	private function get_localized_data(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page slug forwarded to the app for UI state; no privileged action is processed.
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'ai-marketing-expert';

		return array(
			'apiUrl'         => rest_url( aime_rest_namespace() ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'adminUrl'       => admin_url(),
			'pluginUrl'      => AIME_PLUGIN_URL,
			'version'        => AIME_VERSION,
			'hasPro'         => aime_has_pro(),
			'modules'        => $this->modules->get_dashboard_configs(),
			'freeLimits'     => aime_free_limits(),
			'siteUrl'        => home_url(),
			'siteName'       => get_bloginfo( 'name' ),
			'timezone'       => wp_timezone_string(),
			'adminEmail'     => get_option( 'admin_email' ),
			'currentPage'    => $current_page,
			'hasWooCommerce' => class_exists( 'WooCommerce' ),
			'wpRoles'        => wp_roles()->get_names(),
			'smtpProviders'  => SmtpProvider::get_providers(),
			'aiConfigured'   => ! empty( AiProvider::get_active_model( 'text' )['api_key'] ),
			'blockEditorSettings' => $this->get_block_editor_settings(),
			'currentUser'    => array(
				'id'          => get_current_user_id(),
				'displayName' => wp_get_current_user()->display_name,
				'email'       => wp_get_current_user()->user_email,
			),
			'proUrl'         => apply_filters( 'aime_pro_url', 'https://wpthemespace.com/product/ai-marketing-expert/#aime-pricing' ),
			'menuSlug'       => 'ai-marketing-expert',
			'isDebug'        => defined( 'WP_DEBUG' ) && WP_DEBUG,
		);
	}

	/**
	 * Render the React app container.
	 */
	public function render_app(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ai-marketing-expert' ) );
		}

		echo '<div id="aime-app" class="aime-dashboard-wrap"></div>';
	}

	/**
	 * Redirect to dashboard on first activation.
	 */
	public function activation_redirect(): void {
		if ( ! get_transient( 'aime_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'aime_activation_redirect' );

		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Standard WP activation redirect guard; value not used.
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ai-marketing-expert' ) );
		exit;
	}

	/**
	 * Add action links to plugins page.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( array $links ): array {
		$plugin_links = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=ai-marketing-expert' ) ) . '">' .
				esc_html__( 'Dashboard', 'ai-marketing-expert' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=ai-marketing-expert-ai-providers' ) ) . '">' .
				esc_html__( 'Settings', 'ai-marketing-expert' ) . '</a>',
		);

		if ( ! aime_has_pro() ) {
			$plugin_links[] = '<a href="' . esc_url( apply_filters( 'aime_pro_url', 'https://wpthemespace.com/product/ai-marketing-expert/#aime-pricing' ) ) . '" style="color: #FF6B35; font-weight: bold;" target="_blank">' .
				esc_html__( 'Go Pro', 'ai-marketing-expert' ) . '</a>';
		}

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Get count of active / human_takeover chatbot conversations for badge.
	 *
	 * @return int
	 */
	private function get_active_conversation_count(): int {
		global $wpdb;
		$table    = $wpdb->prefix . 'aime_chatbot_conversations';
		$messages = $wpdb->prefix . 'aime_chatbot_messages';

		// Bail if the tables don't exist yet (before activation).
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) )
			|| ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $messages ) ) ) {
			return 0;
		}

		// Count only real chats: the visitor must have written at least one message.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i c
				 WHERE c.status IN (%s, %s)
				 AND EXISTS (
					SELECT 1 FROM %i mv
					WHERE mv.conversation_id = c.id AND mv.sender_type = %s
				 )',
				$table,
				'active',
				'human_takeover',
				$messages,
				'visitor'
			)
		);
	}
}
