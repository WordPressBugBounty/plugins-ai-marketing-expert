<?php
/**
 * WooCommerce Abandoned Cart Tracker & Recovery Engine.
 *
 * Captures real-time cart sessions (logged-in and guest checkout), detects
 * cart abandonment via scheduled cron, generates 1-click restoration links,
 * and triggers universal events for Workflow Automation and Email Marketing.
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CartTracker {

	/**
	 * Cart status constants.
	 */
	public const STATUS_IN_PROGRESS = 'in_progress';
	public const STATUS_ABANDONED   = 'abandoned';
	public const STATUS_RECOVERED   = 'recovered';
	public const STATUS_LOST        = 'lost';
	public const STATUS_EXPIRED     = 'expired';

	/**
	 * Cookie name for tracking guest visitors across page reloads.
	 */
	public const COOKIE_NAME = 'aime_cart_token';

	/**
	 * Nonce action for guest email capture.
	 */
	public const NONCE_ACTION = 'aime_capture_cart_nonce';

	/**
	 * Cron hook name.
	 */
	public const CRON_HOOK = 'aime_check_abandoned_carts';

	/**
	 * Default inactivity threshold in minutes before a cart is marked abandoned.
	 */
	public const DEFAULT_INACTIVITY_MINUTES = 30;

	/**
	 * Table name without prefix.
	 */
	public const TABLE_NAME = 'aime_abandoned_carts';

	/**
	 * Initialize cart tracking hooks.
	 */
	public function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Database table creation hook.
		add_action( 'aime_create_tables', array( $this, 'create_tables' ) );

		// Cart update listeners.
		add_action( 'woocommerce_cart_updated', array( $this, 'on_cart_updated' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_cart_updated' ) );

		// Checkout guest capture.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_scripts' ) );
		add_action( 'wp_ajax_aime_capture_guest_cart', array( $this, 'ajax_capture_guest_cart' ) );
		add_action( 'wp_ajax_nopriv_aime_capture_guest_cart', array( $this, 'ajax_capture_guest_cart' ) );

		// Order recovery tracking.
		add_action( 'woocommerce_thankyou', array( $this, 'on_order_completed' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_order_completed' ), 10, 1 );

		// 1-Click Cart Restoration Deep-Link & Checkout Auto-Sync.
		add_action( 'template_redirect', array( $this, 'handle_cart_restore_redirect' ) );
		add_action( 'template_redirect', array( $this, 'maybe_track_checkout_visit' ) );

		// Background Cron Detection.
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
		add_action( self::CRON_HOOK, array( $this, 'process_abandoned_carts' ) );
		add_action( 'aime_minutely_tasks', array( $this, 'process_abandoned_carts' ) );
		$this->schedule_cron();

		// Auto-check table exists on init (migration safety).
		$this->ensure_table_exists();
	}

	/**
	 * Automatically ensure cart is tracked when visitor visits checkout directly.
	 */
	public function maybe_track_checkout_visit(): void {
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
			$this->on_cart_updated();
		}
	}

	/**
	 * Register the 15-minute cron schedule if not already present.
	 *
	 * @param array $schedules Registered schedules.
	 * @return array
	 */
	public function add_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['fifteen_minutes'] ) ) {
			$schedules['fifteen_minutes'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 Minutes', 'ai-marketing-expert' ),
			);
		}
		return $schedules;
	}

	/**
	 * Return full table name with WordPress prefix.
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Ensure the database table exists.
	 */
	public function ensure_table_exists(): void {
		global $wpdb;
		$table = self::get_table_name();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			$this->create_tables();
		}
	}

	/**
	 * Create or update the abandoned carts database table.
	 *
	 * @param string $charset_collate Optional charset collate.
	 */
	public function create_tables( string $charset_collate = '' ): void {
		global $wpdb;

		if ( empty( $charset_collate ) ) {
			$charset_collate = $wpdb->get_charset_collate();
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::get_table_name();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			cart_token varchar(64) NOT NULL,
			recovery_token varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(191) NOT NULL DEFAULT '',
			customer_name varchar(191) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			cart_contents longtext NOT NULL,
			cart_total decimal(10,2) NOT NULL DEFAULT 0.00,
			currency varchar(10) NOT NULL DEFAULT 'USD',
			items_count int(11) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'in_progress',
			recovered_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip_address varchar(45) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			abandoned_at datetime DEFAULT NULL,
			recovered_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY recovery_token (recovery_token),
			KEY cart_token (cart_token),
			KEY email (email),
			KEY status_updated (status, updated_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Schedule the 15-minute inactivity detection cron event.
	 */
	private function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'fifteen_minutes', self::CRON_HOOK );
		}
	}

	/**
	 * Get or generate a persistent cart session token.
	 */
	public function get_cart_token(): string {
		if ( is_user_logged_in() ) {
			return 'usr_' . get_current_user_id();
		}

		if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
			if ( preg_match( '/^[a-zA-Z0-9_-]{20,64}$/', $token ) ) {
				return $token;
			}
		}

		$token = 'gst_' . wp_generate_password( 32, false );
		if ( ! headers_sent() ) {
			// Cookie valid for 30 days.
			setcookie( self::COOKIE_NAME, $token, time() + ( 30 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}
		$_COOKIE[ self::COOKIE_NAME ] = $token;

		return $token;
	}

	/**
	 * Listener: Called whenever WooCommerce updates the cart.
	 */
	public function on_cart_updated(): void {
		if ( function_exists( 'wc_load_cart' ) && function_exists( 'WC' ) && ! WC()->cart ) {
			wc_load_cart();
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart || is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$cart = WC()->cart;
		if ( $cart->is_empty() ) {
			return;
		}

		$cart_token = $this->get_cart_token();
		$user_id    = get_current_user_id();
		$email      = '';
		$name       = '';
		$phone      = '';

		if ( $user_id ) {
			$user  = wp_get_current_user();
			$email = $user->user_email;
			$name  = trim( $user->first_name . ' ' . $user->last_name );
			if ( empty( $name ) ) {
				$name = $user->display_name;
			}
			$billing_phone = get_user_meta( $user_id, 'billing_phone', true );
			if ( ! empty( $billing_phone ) ) {
				$phone = sanitize_text_field( $billing_phone );
			}
		}

		$items        = array();
		$cart_content = $cart->get_cart();
		foreach ( $cart_content as $cart_item_key => $values ) {
			/** @var \WC_Product $product */
			$product = $values['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$image_id = $product->get_image_id();
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';

			$items[] = array(
				'key'          => $cart_item_key,
				'product_id'   => $values['product_id'],
				'variation_id' => $values['variation_id'] ?? 0,
				'quantity'     => $values['quantity'],
				'name'         => $product->get_name(),
				'price'        => (float) wc_get_price_to_display( $product ),
				'line_total'   => (float) ( $values['line_total'] ?? 0 ),
				'image'        => $image_url,
				'permalink'    => $product->get_permalink(),
				'variation'    => $values['variation'] ?? array(),
			);
		}

		if ( empty( $items ) ) {
			return;
		}

		$cart_total  = (float) $cart->get_total( 'edit' );
		$items_count = (int) $cart->get_cart_contents_count();
		$currency    = get_woocommerce_currency();
		$ip_address  = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );

		global $wpdb;
		$table = self::get_table_name();

		// Check if active record exists for this token.
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, email, customer_name, recovery_token, status FROM {$table} WHERE cart_token = %s ORDER BY id DESC LIMIT 1",
			$cart_token
		) );

		$now = current_time( 'mysql', true );

		if ( $existing && self::STATUS_RECOVERED !== $existing->status ) {
			// Maintain captured email if new scan didn't provide one.
			if ( empty( $email ) && ! empty( $existing->email ) ) {
				$email = $existing->email;
			}
			if ( empty( $name ) && ! empty( $existing->customer_name ) ) {
				$name = $existing->customer_name;
			}

			$wpdb->update(
				$table,
				array(
					'user_id'       => $user_id,
					'email'         => $email,
					'customer_name' => $name,
					'phone'         => $phone,
					'cart_contents' => wp_json_encode( $items ),
					'cart_total'    => $cart_total,
					'currency'      => $currency,
					'items_count'   => $items_count,
					'status'        => self::STATUS_IN_PROGRESS,
					'ip_address'    => $ip_address,
					'updated_at'    => $now,
				),
				array( 'id' => $existing->id ),
				array( '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$recovery_token = wp_generate_password( 32, false );
			$wpdb->insert(
				$table,
				array(
					'cart_token'     => $cart_token,
					'recovery_token' => $recovery_token,
					'user_id'        => $user_id,
					'email'          => $email,
					'customer_name'  => $name,
					'phone'          => $phone,
					'cart_contents'  => wp_json_encode( $items ),
					'cart_total'     => $cart_total,
					'currency'       => $currency,
					'items_count'    => $items_count,
					'status'         => self::STATUS_IN_PROGRESS,
					'ip_address'     => $ip_address,
					'created_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Enqueue frontend capture script on WooCommerce checkout page.
	 */
	public function enqueue_checkout_scripts(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}

		$data = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
		);

		$script = "
		(function() {
			function captureGuestEmail() {
				var emailInput = document.getElementById('billing_email')
					|| document.querySelector('input[name=\"billing_email\"]')
					|| document.querySelector('input[type=\"email\"]')
					|| document.querySelector('input[autocomplete=\"email\"]');
				if (!emailInput) return;
				
				var lastSent = '';
				function sendData() {
					var email = (emailInput.value || '').trim();
					if (!email || email === lastSent || !email.includes('@')) return;
					
					var firstNameInput = document.getElementById('billing_first_name')
						|| document.querySelector('input[name=\"billing_first_name\"]')
						|| document.querySelector('input[autocomplete=\"given-name\"]')
						|| document.querySelector('#shipping-first_name');
					var lastNameInput = document.getElementById('billing_last_name')
						|| document.querySelector('input[name=\"billing_last_name\"]')
						|| document.querySelector('input[autocomplete=\"family-name\"]')
						|| document.querySelector('#shipping-last_name');
					var phoneInput = document.getElementById('billing_phone')
						|| document.querySelector('input[name=\"billing_phone\"]')
						|| document.querySelector('input[autocomplete=\"tel\"]');
					
					var name = ((firstNameInput ? firstNameInput.value : '') + ' ' + (lastNameInput ? lastNameInput.value : '')).trim();
					var phone = phoneInput ? phoneInput.value : '';
					
					lastSent = email;
					
					var formData = new URLSearchParams();
					formData.append('action', 'aime_capture_guest_cart');
					formData.append('nonce', '" . esc_js( $data['nonce'] ) . "');
					formData.append('email', email);
					formData.append('name', name);
					formData.append('phone', phone);
					
					fetch('" . esc_url( $data['ajaxUrl'] ) . "', {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: formData
					}).catch(function(err) { /* silent */ });
				}
				
				emailInput.addEventListener('blur', sendData);
				emailInput.addEventListener('change', sendData);
				var debounceTimer = null;
				emailInput.addEventListener('input', function() {
					clearTimeout(debounceTimer);
					debounceTimer = setTimeout(sendData, 2000);
				});
			}
			
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', captureGuestEmail);
			} else {
				captureGuestEmail();
			}
		})();
		";

		// Dedicated handle ensures script is always output regardless of theme or block checkout.
		wp_register_script( 'aime-checkout-cart-tracker', '', array(), AIME_VERSION, true );
		wp_enqueue_script( 'aime-checkout-cart-tracker' );
		wp_add_inline_script( 'aime-checkout-cart-tracker', $script );
	}

	/**
	 * AJAX Handler: Capture guest details typed on checkout before purchase.
	 */
	public function ajax_capture_guest_cart(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( function_exists( 'wc_load_cart' ) && function_exists( 'WC' ) && ! WC()->cart ) {
			wc_load_cart();
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => 'Invalid email' ), 400 );
		}

		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

		$cart_token = $this->get_cart_token();
		global $wpdb;
		$table = self::get_table_name();

		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE cart_token = %s AND status = %s ORDER BY id DESC LIMIT 1",
			$cart_token,
			self::STATUS_IN_PROGRESS
		) );

		if ( ! $existing_id ) {
			// In case cart row wasn't recorded yet (e.g. direct Buy Now redirect), record it now from active session.
			$this->on_cart_updated();
			$existing_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE cart_token = %s AND status = %s ORDER BY id DESC LIMIT 1",
				$cart_token,
				self::STATUS_IN_PROGRESS
			) );
		}

		if ( $existing_id ) {
			$wpdb->update(
				$table,
				array(
					'email'         => $email,
					'customer_name' => $name,
					'phone'         => $phone,
					'updated_at'    => current_time( 'mysql', true ),
				),
				array( 'id' => $existing_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		wp_send_json_success( array( 'captured' => true ) );
	}

	/**
	 * Background Cron: Check for carts that have been inactive and mark them as abandoned.
	 */
	public function process_abandoned_carts(): void {
		global $wpdb;
		$table = self::get_table_name();

		$settings       = get_option( 'aime_settings', array() );
		$cutoff_minutes = isset( $settings['woo_cart_cutoff_minutes'] ) ? (int) $settings['woo_cart_cutoff_minutes'] : (int) get_option( 'aime_abandoned_cart_cutoff_minutes', self::DEFAULT_INACTIVITY_MINUTES );
		$cutoff_minutes = (int) apply_filters( 'aime_abandoned_cart_cutoff_minutes', $cutoff_minutes );
		if ( $cutoff_minutes < 1 ) {
			$cutoff_minutes = self::DEFAULT_INACTIVITY_MINUTES;
		}
		$cutoff_time = gmdate( 'Y-m-d H:i:s', time() - ( $cutoff_minutes * MINUTE_IN_SECONDS ) );

		// Only carts with captured email and status = in_progress are eligible for recovery workflows.
		$abandoned_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE status = %s AND email != '' AND updated_at < %s LIMIT 50",
			self::STATUS_IN_PROGRESS,
			$cutoff_time
		) );

		if ( empty( $abandoned_rows ) ) {
			return;
		}

		$now = current_time( 'mysql', true );

		foreach ( $abandoned_rows as $row ) {
			// Update status to abandoned.
			$wpdb->update(
				$table,
				array(
					'status'       => self::STATUS_ABANDONED,
					'abandoned_at' => $now,
				),
				array( 'id' => $row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			$cart_items = json_decode( (string) $row->cart_contents, true );
			$cart_items = is_array( $cart_items ) ? $cart_items : array();

			$product_names          = array();
			$total_quantity         = 0;
			$distinct_items_count   = count( $cart_items );
			$single_item_links_list = array();

			foreach ( $cart_items as $item ) {
				$p_name  = (string) ( $item['name'] ?? '' );
				$p_qty   = max( 1, absint( $item['quantity'] ?? 1 ) );
				$p_price = (float) ( $item['price'] ?? 0 );
				$p_id    = absint( $item['variation_id'] ?? 0 ) ?: absint( $item['product_id'] ?? 0 );

				if ( ! empty( $p_name ) ) {
					$product_names[] = $p_name;
				}
				$total_quantity += $p_qty;

				if ( $p_id > 0 ) {
					$item_url   = $this->get_recovery_url( (string) $row->recovery_token, array( 'item_id' => $p_id, 'qty' => 1 ) );
					$price_text = $p_price > 0 && function_exists( 'wc_price' ) ? ' (' . wp_strip_all_tags( wc_price( $p_price ) ) . ')' : '';
					$single_item_links_list[] = sprintf(
						'<div style="margin: 8px 0; padding: 10px 14px; background: #f8fafc; border-left: 3px solid #2563eb; border-radius: 4px;"><strong>%s</strong>%s &nbsp;&nbsp; <a href="%s" style="display:inline-block;background-color:#2563eb;color:#ffffff;padding:5px 14px;text-decoration:none;border-radius:4px;font-size:13px;font-weight:600;margin-left:6px;">Buy in 1-Click &raquo;</a></div>',
						esc_html( $p_name ),
						$price_text,
						esc_url( $item_url )
					);
				}
			}

			// Classify cart structure for smart branching & recovery:
			// 1. duplicate_qty: Exactly 1 distinct product, but quantity > 1 (accidental extra quantity)
			// 2. multiple_items: 2 or more distinct products added
			// 3. single_item: Exactly 1 distinct product with quantity 1
			if ( $distinct_items_count === 1 && $total_quantity > 1 ) {
				$cart_type = 'duplicate_qty';
			} elseif ( $distinct_items_count > 1 ) {
				$cart_type = 'multiple_items';
			} else {
				$cart_type = 'single_item';
			}

			$recovery_url   = $this->get_recovery_url( (string) $row->recovery_token );
			$single_qty_url = ( $total_quantity > 1 )
				? $this->get_recovery_url( (string) $row->recovery_token, array( 'qty' => 1 ) )
				: $recovery_url;

			$single_item_links_text = implode( "\n", $single_item_links_list );

			// Build standardized payload for workflows.
			$payload = array(
				'cart_id'                 => (int) $row->id,
				'cart_token'              => (string) $row->cart_token,
				'recovery_token'          => (string) $row->recovery_token,
				'user_id'                 => (int) $row->user_id,
				'customer_email'          => (string) $row->email,
				'email'                   => (string) $row->email,
				'customer_name'           => (string) ( $row->customer_name ?: __( 'Customer', 'ai-marketing-expert' ) ),
				'name'                    => (string) ( $row->customer_name ?: __( 'Customer', 'ai-marketing-expert' ) ),
				'phone'                   => (string) $row->phone,
				'cart_total'              => (float) $row->cart_total,
				'currency'                => (string) $row->currency,
				'items_count'             => (int) $total_quantity,
				'distinct_items_count'    => (int) $distinct_items_count,
				'cart_type'               => (string) $cart_type,
				'has_multiple_quantities' => ( $total_quantity > $distinct_items_count ),
				'has_multiple_items'      => ( $distinct_items_count > 1 ),
				'product_names'           => implode( ', ', $product_names ),
				'recovery_url'            => $recovery_url,
				'single_qty_url'          => $single_qty_url,
				'single_item_links'       => $single_item_links_text,
				'items'                   => $cart_items,
			);

			/**
			 * Fire universal WooCommerce Cart Abandoned action.
			 *
			 * @param array $payload Detailed cart data and recovery tokens.
			 */
			do_action( 'aime_woo_cart_abandoned', $payload );
		}
	}

	/**
	 * Mark cart as recovered upon order completion.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function on_order_completed( int $order_id ): void {
		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$email      = $order->get_billing_email();
		$cart_token = $this->get_cart_token();

		global $wpdb;
		$table = self::get_table_name();

		// Find most recent cart for this user/token/email.
		$cart_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE (cart_token = %s OR email = %s) AND status IN (%s, %s) ORDER BY id DESC LIMIT 1",
			$cart_token,
			$email,
			self::STATUS_IN_PROGRESS,
			self::STATUS_ABANDONED
		) );

		if ( $cart_id ) {
			$wpdb->update(
				$table,
				array(
					'status'             => self::STATUS_RECOVERED,
					'recovered_order_id' => $order_id,
					'recovered_at'       => current_time( 'mysql', true ),
				),
				array( 'id' => $cart_id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Generate 1-Click Cart Restoration Deep-Link URL.
	 *
	 * @param string $recovery_token Unique cart token.
	 * @param array  $extra_args     Optional extra query args (e.g. qty=1, item_id=123).
	 * @return string Full restore URL.
	 */
	public function get_recovery_url( string $recovery_token, array $extra_args = array() ): string {
		$cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : site_url( '/cart/' );
		$args     = array_merge(
			array(
				'aime_restore_cart' => sanitize_text_field( $recovery_token ),
			),
			$extra_args
		);
		return add_query_arg( $args, $cart_url );
	}

	/**
	 * Handle 1-Click Cart Restoration when customer visits the recovery link.
	 * Supports full cart restore, single-quantity purchase (?qty=1), and
	 * single-item purchase (?item_id=PRODUCT_ID).
	 */
	public function handle_cart_restore_redirect(): void {
		if ( ! isset( $_GET['aime_restore_cart'] ) || empty( $_GET['aime_restore_cart'] ) ) {
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_GET['aime_restore_cart'] ) );
		if ( ! preg_match( '/^[a-zA-Z0-9_-]{16,64}$/', $token ) ) {
			return;
		}

		global $wpdb;
		$table = self::get_table_name();

		$cart_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE recovery_token = %s LIMIT 1",
			$token
		) );

		if ( ! $cart_row || empty( $cart_row->cart_contents ) ) {
			return;
		}

		// Check token expiration (default 14 days, filterable via aime_cart_recovery_token_ttl_days).
		$ttl_days = (int) apply_filters( 'aime_cart_recovery_token_ttl_days', 14 );
		if ( $ttl_days > 0 && ! empty( $cart_row->created_at ) ) {
			$created_timestamp = strtotime( (string) $cart_row->created_at . ' UTC' );
			if ( $created_timestamp && ( time() - $created_timestamp ) > ( $ttl_days * DAY_IN_SECONDS ) ) {
				// Mark cart as expired if not already recovered.
				if ( self::STATUS_RECOVERED !== $cart_row->status ) {
					$wpdb->update(
						$table,
						array(
							'status'     => self::STATUS_EXPIRED,
							'updated_at' => current_time( 'mysql', true ),
						),
						array( 'id' => (int) $cart_row->id )
					);
				}

				if ( function_exists( 'wc_add_notice' ) ) {
					wc_add_notice( __( 'Your saved cart link has expired, but you can continue shopping our latest collections below.', 'ai-marketing-expert' ), 'notice' );
				}

				$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url();
				wp_safe_redirect( $shop_url );
				exit;
			}
		}

		$items = json_decode( (string) $cart_row->cart_contents, true );
		if ( ! is_array( $items ) || empty( $items ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		// Optional item filter: if item_id (product or variation ID) is passed, restore only that specific item.
		$filter_item_id = isset( $_GET['item_id'] ) ? absint( $_GET['item_id'] ) : ( isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0 );

		// Optional quantity override: if qty is specified (e.g. qty=1), override quantity.
		$override_qty = isset( $_GET['qty'] ) ? max( 1, absint( $_GET['qty'] ) ) : 0;

		// Empty current cart to prevent duplicate items.
		WC()->cart->empty_cart();

		$added_any = false;
		foreach ( $items as $item ) {
			$product_id   = absint( $item['product_id'] ?? 0 );
			$variation_id = absint( $item['variation_id'] ?? 0 );
			$quantity     = max( 1, absint( $item['quantity'] ?? 1 ) );
			$variation    = is_array( $item['variation'] ?? null ) ? $item['variation'] : array();

			// If single item filter is active, skip items that don't match.
			if ( $filter_item_id > 0 && $product_id !== $filter_item_id && $variation_id !== $filter_item_id ) {
				continue;
			}

			if ( $override_qty > 0 ) {
				$quantity = $override_qty;
			}

			if ( $product_id ) {
				WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
				$added_any = true;
			}
		}

		// Fallback: If filtering left cart empty, restore all items as fallback.
		if ( ! $added_any && WC()->cart->is_empty() ) {
			foreach ( $items as $item ) {
				$product_id   = absint( $item['product_id'] ?? 0 );
				$quantity     = max( 1, absint( $item['quantity'] ?? 1 ) );
				$variation_id = absint( $item['variation_id'] ?? 0 );
				$variation    = is_array( $item['variation'] ?? null ) ? $item['variation'] : array();
				if ( $product_id ) {
					WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
				}
			}
		}

		// Redirect directly to checkout for instant frictionless conversion.
		$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : site_url( '/checkout/' );
		wp_safe_redirect( $checkout_url );
		exit;
	}
}
