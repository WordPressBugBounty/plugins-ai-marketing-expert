<?php
/**
 * Template Controller — email templates CRUD.
 *
 * @package WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TemplateController {
	private const DEFAULT_TEMPLATE_LAYOUT_VERSION = '2026-07-table-layout-dedupe';

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$this->maybe_sync_default_templates();

		global $wpdb;
		$p        = $wpdb->prefix;
		$per_page = absint( $request->get_param( 'per_page' ) ?: 20 );
		$page     = absint( $request->get_param( 'page' ) ?: 1 );
		$offset   = ( $page - 1 ) * $per_page;
		$category = sanitize_text_field( $request->get_param( 'category' ) ?? '' );
		$search   = sanitize_text_field( $request->get_param( 'search' ) ?? '' );

		$where  = array( '1=1' );
		$params = array();

		if ( $category ) {
			$where[]  = 'category = %s';
			$params[] = $category;
		}
		if ( $search ) {
			$where[]  = 'name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_sql = implode( ' AND ', $where );

		$count_params = $params;
		$params[]     = $per_page;
		$params[]     = $offset;

		$total = (int) ( empty( $count_params )
			? $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_templates WHERE {$where_sql}" )
			: $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_templates WHERE {$where_sql}", ...$count_params ) ) ); // phpcs:ignore

		// Defaults first (oldest id first, so the built-in order is stable), then
		// custom templates newest first. Written without unary minus: `-id` on a
		// bigint unsigned column can raise ER_DATA_OUT_OF_RANGE, which would blank
		// the whole Templates screen.
		$items = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$p}aime_templates WHERE {$where_sql} ORDER BY is_default DESC, CASE WHEN is_default = 1 THEN id END ASC, id DESC LIMIT %d OFFSET %d",
			...$params
		) ); // phpcs:ignore
		$items = array_map( array( $this, 'normalize_template_content' ), $items );

		return new \WP_REST_Response( array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		) );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		$this->maybe_sync_default_templates();

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aime_templates WHERE id = %d", absint( $request->get_param( 'id' ) ) ) );
		if ( ! $row ) {
			return new \WP_REST_Response( array( 'message' => __( 'Template not found.', 'ai-marketing-expert' ) ), 404 );
		}
		$row = $this->normalize_template_content( $row );

		// Preview must show the same footer a real send appends. Kept in a
		// separate field so importing the template ("Use This Template") still
		// copies the bare content — the footer is added at send time.
		$row->preview_content = \WPSpace\AiMarketingExpert\Modules\EmailMarketing\Services\CampaignProcessor::render_with_footer(
			(string) ( $row->content ?? '' ),
			home_url( '/' )
		);

		return new \WP_REST_Response( $row );
	}

	public function store( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$data = $this->sanitize( $request );
		$data['created_at'] = current_time( 'mysql', true );
		$wpdb->insert( "{$wpdb->prefix}aime_templates", $data );
		return new \WP_REST_Response( array( 'id' => (int) $wpdb->insert_id, 'message' => __( 'Template created.', 'ai-marketing-expert' ) ), 201 );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$id  = absint( $request->get_param( 'id' ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aime_templates WHERE id = %d", $id ) );
		if ( ! $row ) {
			return new \WP_REST_Response( array( 'message' => __( 'Template not found.', 'ai-marketing-expert' ) ), 404 );
		}
		$data = $this->sanitize( $request );
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( "{$wpdb->prefix}aime_templates", $data, array( 'id' => $id ) );
		return new \WP_REST_Response( array( 'message' => __( 'Template updated.', 'ai-marketing-expert' ) ) );
	}

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}aime_templates", array( 'id' => absint( $request->get_param( 'id' ) ) ) );
		return new \WP_REST_Response( array( 'message' => __( 'Template deleted.', 'ai-marketing-expert' ) ) );
	}

	public function duplicate( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p   = $wpdb->prefix;
		$id  = absint( $request->get_param( 'id' ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}aime_templates WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new \WP_REST_Response( array( 'message' => __( 'Template not found.', 'ai-marketing-expert' ) ), 404 );
		}
		unset( $row['id'] );
		$row['name']       = $row['name'] . ' (Copy)';
		$row['is_default'] = 0;
		$row['content']    = $this->strip_unsubscribe_markup( (string) ( $row['content'] ?? '' ) );
		$row['created_at'] = current_time( 'mysql', true );
		$wpdb->insert( "{$p}aime_templates", $row );
		return new \WP_REST_Response( array( 'id' => (int) $wpdb->insert_id, 'message' => __( 'Template duplicated.', 'ai-marketing-expert' ) ), 201 );
	}

	public function get_categories( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( array(
			'general'       => __( 'General', 'ai-marketing-expert' ),
			'newsletter'    => __( 'Newsletter', 'ai-marketing-expert' ),
			'promotional'   => __( 'Promotional', 'ai-marketing-expert' ),
			'transactional' => __( 'Transactional', 'ai-marketing-expert' ),
			'welcome'       => __( 'Welcome', 'ai-marketing-expert' ),
			'notification'  => __( 'Notification', 'ai-marketing-expert' ),
			'followup'      => __( 'Follow-up', 'ai-marketing-expert' ),
			'event'         => __( 'Event', 'ai-marketing-expert' ),
		) );
	}

	public function install_defaults(): \WP_REST_Response {
		$this->seed_defaults( true );
		return new \WP_REST_Response( array( 'message' => __( 'Default templates installed.', 'ai-marketing-expert' ) ) );
	}

	/**
	 * Seed default templates.
	 *
	 * @param bool $force When true, delete existing defaults and re-insert.
	 */
	public function seed_defaults( bool $force = false ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		// Cross-request mutex. add_option() relies on the UNIQUE index on
		// option_name, so only one concurrent request can claim the lock.
		$lock = 'aime_seeding_email_templates';
		if ( ! add_option( $lock, time(), '', false ) ) {
			$claimed_at = (int) get_option( $lock );
			// Reclaim a stale lock left behind by a fatal error mid-seed.
			if ( $claimed_at > time() - 5 * MINUTE_IN_SECONDS ) {
				return;
			}
			update_option( $lock, time(), false );
		}

		try {
			if ( $force ) {
				$wpdb->query( "DELETE FROM {$p}aime_templates WHERE is_default = 1" ); // phpcs:ignore
			}

			// Insert only the defaults that are missing, so a repeated run can
			// never duplicate a template that already exists.
			$existing = $wpdb->get_col( "SELECT name FROM {$p}aime_templates WHERE is_default = 1" ); // phpcs:ignore
			$existing = array_map( 'strval', is_array( $existing ) ? $existing : array() );

			$defaults = $this->get_default_templates();

			$now = current_time( 'mysql', true );
			foreach ( $defaults as $tpl ) {
				if ( in_array( (string) $tpl['name'], $existing, true ) ) {
					continue;
				}
				$tpl['content']    = $this->strip_unsubscribe_markup( $tpl['content'] );
				$tpl['created_at'] = $now;
				$wpdb->insert( "{$p}aime_templates", $tpl );
				$existing[] = (string) $tpl['name'];
			}
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * Remove duplicate default templates, keeping the lowest id per name.
	 *
	 * Repairs installs seeded more than once by concurrent first-load requests.
	 */
	private function dedupe_default_templates(): void {
		global $wpdb;
		$p = $wpdb->prefix;

		$wpdb->query( // phpcs:ignore
			"DELETE dup FROM {$p}aime_templates dup
			 INNER JOIN {$p}aime_templates keep
			 ON dup.name = keep.name
			 AND dup.is_default = 1
			 AND keep.is_default = 1
			 AND dup.id > keep.id"
		);
	}

	/**
	 * The default template definitions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_default_templates(): array {
		return array(
			/*
			 * ── FREE TEMPLATES (7) ────────────────────────────
			 */
			array(
				'name'            => __( 'Simple Text', 'ai-marketing-expert' ),
				'description'     => __( 'A clean, minimal plain-text style email.', 'ai-marketing-expert' ),
				'category'        => 'general',
				'type'            => 'html',
				'design_template' => 'simple',
				'is_default'      => 1,
				'content'         => $this->get_simple_text_template_content(),
			),
			array(
				'name'            => __( 'Modern Card', 'ai-marketing-expert' ),
				'description'     => __( 'White card on a soft gray background with rounded corners.', 'ai-marketing-expert' ),
				'category'        => 'newsletter',
				'type'            => 'html',
				'design_template' => 'card',
				'is_default'      => 1,
				'content'         => $this->get_modern_card_template_content(),
			),
			array(
				'name'            => __( 'Welcome Email', 'ai-marketing-expert' ),
				'description'     => __( 'Greet new subscribers with a warm, personal message.', 'ai-marketing-expert' ),
				'category'        => 'welcome',
				'type'            => 'html',
				'design_template' => 'simple',
				'is_default'      => 1,
				'content'         => $this->get_welcome_email_template_content(),
			),
			array(
				'name'            => __( 'Promotional Offer', 'ai-marketing-expert' ),
				'description'     => __( 'Eye-catching gradient design with a bold CTA button.', 'ai-marketing-expert' ),
				'category'        => 'promotional',
				'type'            => 'html',
				'design_template' => 'card',
				'is_default'      => 1,
				'content'         => $this->get_promotional_offer_template_content(),
			),
			array(
				'name'            => __( 'Newsletter Classic', 'ai-marketing-expert' ),
				'description'     => __( 'Traditional newsletter layout with a header banner area.', 'ai-marketing-expert' ),
				'category'        => 'newsletter',
				'type'            => 'html',
				'design_template' => 'simple',
				'is_default'      => 1,
				'content'         => $this->get_newsletter_classic_template_content(),
			),
			array(
				'name'            => __( 'Transactional Receipt', 'ai-marketing-expert' ),
				'description'     => __( 'Clean order confirmation / receipt layout.', 'ai-marketing-expert' ),
				'category'        => 'transactional',
				'type'            => 'html',
				'design_template' => 'simple',
				'is_default'      => 1,
				'content'         => $this->get_transactional_receipt_template_content(),
			),
			array(
				'name'            => __( 'Notification Alert', 'ai-marketing-expert' ),
				'description'     => __( 'Attention-grabbing notification with an icon header.', 'ai-marketing-expert' ),
				'category'        => 'notification',
				'type'            => 'html',
				'design_template' => 'card',
				'is_default'      => 1,
				'content'         => $this->get_notification_alert_template_content(),
			),

			/*
			 * ── PRO TEMPLATES (7) ─────────────────────────────
			 */
			array(
				'name'            => __( 'Product Launch', 'ai-marketing-expert' ),
				'description'     => __( 'PRO — Stylish product launch template with hero image area and dual CTA.', 'ai-marketing-expert' ),
				'category'        => 'promotional',
				'type'            => 'html',
				'design_template' => 'card',
				'is_default'      => 1,
				'content'         => $this->get_product_launch_template_content(),
			),
			array(
				'name'            => __( 'Event Invitation', 'ai-marketing-expert' ),
				'description'     => __( 'PRO — Elegant event/webinar invitation with date and RSVP button.', 'ai-marketing-expert' ),
				'category'        => 'event',
				'type'            => 'html',
				'design_template' => 'card',
				'is_default'      => 1,
				'content'         => $this->get_event_invitation_template_content(),
			),
			array(
				'name'            => __( 'Re-engagement', 'ai-marketing-expert' ),
				'description'     => __( 'PRO — Win back inactive subscribers with a personal touch.', 'ai-marketing-expert' ),
				'category'        => 'followup',
				'type'            => 'html',
				'design_template' => 'card',
				'is_default'      => 1,
				'content'         => $this->get_reengagement_template_content(),
			),
			array(
				'name'            => __( 'Feedback Request', 'ai-marketing-expert' ),
				'description'     => __( 'PRO — Ask for customer feedback or reviews after purchase.', 'ai-marketing-expert' ),
				'category'        => 'followup',
				'type'            => 'html',
				'design_template' => 'card',
				'is_default'      => 1,
				'content'         => $this->get_feedback_request_template_content(),
			),
			array(
				'name'            => __( 'Seasonal Sale', 'ai-marketing-expert' ),
				'description'     => __( 'PRO — Bold seasonal / holiday sale template with countdown feel.', 'ai-marketing-expert' ),
				'category'        => 'promotional',
				'type'            => 'html',
				'design_template' => 'card',
				'is_default'      => 1,
				'content'         => $this->get_seasonal_sale_template_content(),
			),
			array(
				'name'            => __( 'Digest / Roundup', 'ai-marketing-expert' ),
				'description'     => __( 'PRO — Weekly/monthly content digest with multi-article layout.', 'ai-marketing-expert' ),
				'category'        => 'newsletter',
				'type'            => 'html',
				'design_template' => 'simple',
				'is_default'      => 1,
				'content'         => $this->get_digest_roundup_template_content(),
			),
			array(
				'name'            => __( 'Minimal Dark', 'ai-marketing-expert' ),
				'description'     => __( 'PRO — Sleek dark-mode email for modern brands.', 'ai-marketing-expert' ),
				'category'        => 'general',
				'type'            => 'html',
				'design_template' => 'card',
				'is_default'      => 1,
				'content'         => $this->get_minimal_dark_template_content(),
			),
		);
	}

	private function get_simple_text_template_content(): string {
		$body = <<<'HTML'
<h1 style="margin:0 0 20px;color:#1a1a2e;font-size:24px;line-height:1.3;">Your Weekly Update from Our Team</h1>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#333333;">Hi there,</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#333333;">We hope you're having a great week. Here are a few things we wanted to share with you:</p>
<ul style="margin:0 0 16px 20px;padding:0;color:#333333;font-size:15px;line-height:1.7;">
	<li style="margin-bottom:10px;">Our latest blog post on productivity tips is live - <a href="#" style="color:#3858e9;text-decoration:underline;">read it here</a>.</li>
	<li style="margin-bottom:10px;">We've updated our pricing page with new plans that better fit your needs.</li>
	<li>Join our community forum to connect with other users.</li>
</ul>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#333333;">As always, feel free to reply to this email if you have any questions. We're here to help.</p>
<p style="margin:0;font-size:15px;line-height:1.7;color:#333333;">Cheers,<br>The Team</p>
HTML;

		return $this->build_email_template(
			array(
				'outer_background' => '#ffffff',
				'inner_background' => '#ffffff',
				'font_family'      => 'Arial,Helvetica,sans-serif',
				'text_color'       => '#333333',
				'padding'          => '24px 16px',
				'inner_padding'    => '24px',
				'body'             => $body,
			)
		);
	}

	private function get_modern_card_template_content(): string {
		$body = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate;background-color:#ffffff;border-radius:12px;">
	<tr>
		<td style="padding:36px;">
			<h1 style="margin:0 0 18px;color:#1a1a2e;font-size:22px;line-height:1.3;">Monthly Product Highlights</h1>
			<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#444444;">Hello!</p>
			<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#444444;">Here's what's new this month at our store. We've been working hard to bring you exciting features and improvements.</p>
			<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#444444;"><strong>New Feature:</strong> Smart Dashboard - track your metrics in real time with our redesigned analytics panel.</p>
			<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#444444;"><strong>Improvement:</strong> Faster load times across all pages, with up to 40% speed improvement.</p>
			<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#444444;"><strong>Coming Soon:</strong> Integrations with Slack, Zapier, and more. Stay tuned.</p>
			<p style="margin:0;font-size:15px;line-height:1.7;color:#444444;">Thank you for being part of our journey.</p>
		</td>
	</tr>
</table>
HTML;

		return $this->build_email_template(
			array(
				'outer_background' => '#f4f5f7',
				'inner_background' => '#f4f5f7',
				'font_family'      => 'Arial,Helvetica,sans-serif',
				'text_color'       => '#444444',
				'padding'          => '40px 20px',
				'inner_padding'    => '0',
				'body'             => $body,
			)
		);
	}

	private function get_welcome_email_template_content(): string {
		$body = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
	<tr>
		<td align="center" style="padding-bottom:20px;font-size:48px;line-height:1;">👋</td>
	</tr>
	<tr>
		<td>
			<h1 style="margin:0 0 16px;text-align:center;color:#1a1a2e;font-size:26px;line-height:1.3;">Welcome, Sarah!</h1>
			<p style="margin:0 0 20px;text-align:center;color:#555555;font-size:16px;line-height:1.7;">Thank you for joining Starter Hub. We're thrilled to have you on board. Here's what you can expect from us:</p>
			<ul style="margin:0 0 24px 20px;padding:0;color:#333333;font-size:15px;line-height:1.7;">
				<li style="margin-bottom:10px;"><strong>Weekly insights</strong> - curated tips and industry news delivered every Tuesday.</li>
				<li style="margin-bottom:10px;"><strong>Exclusive offers</strong> - members-only discounts and early access to new products.</li>
				<li><strong>Community access</strong> - connect with 5,000+ like-minded professionals.</li>
			</ul>
		</td>
	</tr>
	<tr>
		<td align="center" style="padding-top:4px;">
			{{button}}
		</td>
	</tr>
</table>
HTML;

		$body = str_replace(
			'{{button}}',
			$this->build_button( 'Explore Your Dashboard', '#', '#3858e9', '#ffffff', '12px 28px', '6px' ),
			$body
		);

		return $this->build_email_template(
			array(
				'outer_background' => '#ffffff',
				'inner_background' => '#ffffff',
				'font_family'      => 'Arial,Helvetica,sans-serif',
				'text_color'       => '#333333',
				'padding'          => '24px 16px',
				'inner_padding'    => '24px',
				'body'             => $body,
			)
		);
	}

	private function get_promotional_offer_template_content(): string {
		$body = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate;background-color:#ffffff;border-radius:16px;">
	<tr>
		<td align="center" style="padding:44px 36px;">
			<h1 style="margin:0 0 18px;color:#3858e9;font-size:28px;line-height:1.3;">Flash Sale - 40% Off Everything!</h1>
			<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#444444;">For a limited time only, enjoy <strong>40% off</strong> our entire collection. Whether you're upgrading your toolkit or trying something new, now is the perfect time.</p>
			<p style="margin:0 0 16px;font-size:24px;line-height:1.4;font-weight:800;color:#3858e9;">Use code: <span style="display:inline-block;background-color:#f0f0ff;padding:4px 12px;border-radius:6px;">FLASH40</span></p>
			<p style="margin:0 0 24px;font-size:13px;line-height:1.6;color:#666666;">Offer valid through March 15, 2026. Cannot be combined with other discounts.</p>
			{{button}}
		</td>
	</tr>
</table>
HTML;

		$body = str_replace(
			'{{button}}',
			$this->build_button( 'Shop the Sale', '#', '#3858e9', '#ffffff', '14px 36px', '8px' ),
			$body
		);

		return $this->build_email_template(
			array(
				'outer_background' => '#4f46e5',
				'inner_background' => '#4f46e5',
				'font_family'      => 'Arial,Helvetica,sans-serif',
				'text_color'       => '#444444',
				'padding'          => '40px 20px',
				'inner_padding'    => '0',
				'body'             => $body,
			)
		);
	}

	private function get_newsletter_classic_template_content(): string {
		$body = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;background-color:#ffffff;">
	<tr>
		<td align="center" style="padding:28px 32px;background-color:#1a1a2e;">
			<h1 style="margin:0;color:#ffffff;font-size:22px;line-height:1.3;letter-spacing:1px;">The Daily Digest</h1>
		</td>
	</tr>
	<tr>
		<td style="padding:32px;">
			<h2 style="margin:0 0 18px;color:#1a1a2e;font-size:20px;line-height:1.3;">This Week in Tech: AI, Growth &amp; Strategy</h2>
			<p style="margin:0 0 16px;font-size:15px;line-height:1.8;color:#444444;">Good morning, readers!</p>
			<p style="margin:0 0 16px;font-size:15px;line-height:1.8;color:#444444;">This week has been packed with exciting developments. From breakthrough AI models to new marketing strategies, here's everything you need to know:</p>
			<h3 style="margin:24px 0 10px;color:#1a1a2e;font-size:17px;line-height:1.4;">AI Update</h3>
			<p style="margin:0 0 16px;font-size:15px;line-height:1.8;color:#444444;">New research shows that AI-assisted content creation has increased productivity by 35% across marketing teams. <a href="#" style="color:#3858e9;text-decoration:underline;">Read more</a></p>
			<h3 style="margin:24px 0 10px;color:#1a1a2e;font-size:17px;line-height:1.4;">Growth Tip</h3>
			<p style="margin:0 0 16px;font-size:15px;line-height:1.8;color:#444444;">Email segmentation can boost your open rates by up to 50%. Learn our 5-step framework for better targeting. <a href="#" style="color:#3858e9;text-decoration:underline;">Learn more</a></p>
			<p style="margin:0;font-size:15px;line-height:1.8;color:#444444;">Until next time,<br><em>The Editorial Team</em></p>
		</td>
	</tr>
</table>
HTML;

		return $this->build_email_template(
			array(
				'outer_background' => '#ffffff',
				'inner_background' => '#ffffff',
				'font_family'      => 'Georgia,serif',
				'text_color'       => '#444444',
				'padding'          => '0',
				'inner_padding'    => '0',
				'body'             => $body,
			)
		);
	}

	private function get_transactional_receipt_template_content(): string {
		$body = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
	<tr>
		<td style="padding-bottom:16px;border-bottom:3px solid #3858e9;">
			<h1 style="margin:0;color:#1a1a2e;font-size:22px;line-height:1.3;">Order Confirmation - #ORD-2847</h1>
		</td>
	</tr>
	<tr>
		<td style="padding-top:24px;">
			<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#333333;">Hi Alex,</p>
			<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#333333;">Thank you for your purchase. Your order has been confirmed and is being processed. Here's a summary of what you ordered:</p>
		</td>
	</tr>
	<tr>
		<td style="padding:8px 0 24px;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate;background-color:#f8f9fa;border-radius:8px;">
				<tr>
					<td style="padding:20px;font-size:14px;line-height:1.6;color:#555555;">
						<strong>Order Summary</strong>
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin-top:12px;border-collapse:collapse;">
							<tr>
								<td style="padding:8px 0;border-bottom:1px solid #e0e0e0;color:#333333;">Pro Plan (Annual)</td>
								<td align="right" style="padding:8px 0;border-bottom:1px solid #e0e0e0;color:#333333;font-weight:600;">$199.00</td>
							</tr>
							<tr>
								<td style="padding:8px 0;border-bottom:1px solid #e0e0e0;color:#333333;">Priority Support Add-on</td>
								<td align="right" style="padding:8px 0;border-bottom:1px solid #e0e0e0;color:#333333;font-weight:600;">$49.00</td>
							</tr>
							<tr>
								<td style="padding:8px 0;color:#333333;font-weight:700;">Total</td>
								<td align="right" style="padding:8px 0;color:#3858e9;font-weight:700;">$248.00</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</td>
	</tr>
	<tr>
		<td>
			<p style="margin:0;font-size:14px;line-height:1.7;color:#666666;">You'll receive a shipping notification once your order is on its way. If you have any questions, reply to this email or visit our <a href="#" style="color:#3858e9;text-decoration:underline;">Help Center</a>.</p>
		</td>
	</tr>
</table>
HTML;

		return $this->build_email_template(
			array(
				'outer_background' => '#ffffff',
				'inner_background' => '#ffffff',
				'font_family'      => 'Arial,Helvetica,sans-serif',
				'text_color'       => '#333333',
				'padding'          => '24px 16px',
				'inner_padding'    => '24px',
				'body'             => $body,
			)
		);
	}

	private function get_notification_alert_template_content(): string {
		$body = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate;background-color:#ffffff;border-top:4px solid #f59e0b;border-radius:12px;">
	<tr>
		<td align="center" style="padding:36px;">
			<div style="font-size:40px;line-height:1;margin-bottom:12px;">🔔</div>
			<h1 style="margin:0 0 18px;color:#1a1a2e;font-size:22px;line-height:1.3;">Action Required: Verify Your Email</h1>
			<p style="margin:0 0 16px;text-align:left;font-size:15px;line-height:1.7;color:#444444;">Hi there,</p>
			<p style="margin:0 0 24px;text-align:left;font-size:15px;line-height:1.7;color:#444444;">We noticed that your email address hasn't been verified yet. To keep your account secure and ensure you receive all notifications, please verify your email by clicking the button below.</p>
			{{button}}
			<p style="margin:24px 0 0;text-align:left;font-size:13px;line-height:1.7;color:#888888;">If you didn't create an account, you can safely ignore this email. This link will expire in 24 hours.</p>
		</td>
	</tr>
</table>
HTML;

		$body = str_replace(
			'{{button}}',
			$this->build_button( 'Verify My Email', '#', '#f59e0b', '#ffffff', '12px 28px', '6px' ),
			$body
		);

		return $this->build_email_template(
			array(
				'outer_background' => '#f4f5f7',
				'inner_background' => '#f4f5f7',
				'font_family'      => 'Arial,Helvetica,sans-serif',
				'text_color'       => '#444444',
				'padding'          => '40px 20px',
				'inner_padding'    => '0',
				'body'             => $body,
			)
		);
	}

	private function get_product_launch_template_content(): string {
		$body = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
	<tr>
		<td align="center" style="padding:48px 32px;background-color:#1a1a3e;">
			<h1 style="margin:0 0 8px;color:#ffffff;font-size:32px;line-height:1.25;font-weight:800;">Introducing ProSuite 3.0</h1>
			<p style="margin:0;color:#c7c9e8;font-size:14px;line-height:1.6;">By Starter Hub</p>
		</td>
	</tr>
	<tr>
		<td style="padding:0 20px 20px;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate;background-color:#ffffff;border-radius:12px;">
				<tr>
					<td style="padding:36px;">
						<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#444444;">We're excited to announce the launch of <strong>ProSuite 3.0</strong> - our most powerful release yet.</p>
						<p style="margin:0 0 12px;font-size:15px;line-height:1.7;color:#444444;"><strong>What's new:</strong></p>
						<ul style="margin:0 0 20px 20px;padding:0;color:#444444;font-size:15px;line-height:1.7;">
							<li style="margin-bottom:10px;">Redesigned interface with dark mode support</li>
							<li style="margin-bottom:10px;">3x faster performance with our new engine</li>
							<li style="margin-bottom:10px;">50+ new integrations including Notion, Figma and Linear</li>
							<li>Enterprise-grade security with SOC 2 compliance</li>
						</ul>
						<p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#444444;">Early adopters get <strong>25% off</strong> for the first year. Don't miss out.</p>
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
							<tr>
								<td style="padding-right:12px;">{{primary_button}}</td>
								<td>{{secondary_button}}</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
HTML;

		$body = str_replace(
			array( '{{primary_button}}', '{{secondary_button}}' ),
			array(
				$this->build_button( 'Get Started', '#', '#3858e9', '#ffffff', '14px 32px', '8px' ),
				$this->build_button( 'Learn More', '#', '#e8ecff', '#3858e9', '14px 32px', '8px', '1px solid #c8d4ff' ),
			),
			$body
		);

		return $this->build_email_template(
			array(
				'outer_background' => '#0f0f1a',
				'inner_background' => '#0f0f1a',
				'font_family'      => 'Arial,Helvetica,sans-serif',
				'text_color'       => '#444444',
				'padding'          => '0 0 24px',
				'inner_padding'    => '0',
				'body'             => $body,
			)
		);
	}

	private function get_event_invitation_template_content(): string {
		$body = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate;background-color:#ffffff;border-radius:16px;">
	<tr>
		<td align="center" style="padding:36px;background-color:#efe2cb;border-radius:16px 16px 0 0;">
			<div style="font-size:48px;line-height:1;">🎉</div>
			<h1 style="margin:12px 0 4px;color:#2d2013;font-size:26px;line-height:1.3;">You're Invited!</h1>
			<p style="margin:0;color:#6b5a42;font-size:14px;line-height:1.6;">Design &amp; Growth Summit 2026</p>
		</td>
	</tr>
	<tr>
		<td style="padding:32px;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate;background-color:#faf7f4;border-radius:8px;">
				<tr>
					<td align="center" style="padding:16px;font-size:14px;line-height:1.6;color:#6b5a42;">
						<strong>Date:</strong> April 18, 2026 &nbsp;&bull;&nbsp; <strong>Time:</strong> 2:00 PM EST
					</td>
				</tr>
			</table>
			<p style="margin:24px 0 16px;font-size:15px;line-height:1.7;color:#444444;">Join 500+ designers and marketers for an afternoon of inspiration, workshops, and networking.</p>
			<p style="margin:0 0 12px;font-size:15px;line-height:1.7;color:#444444;"><strong>Featured sessions:</strong></p>
			<ul style="margin:0 0 24px 20px;padding:0;color:#444444;font-size:15px;line-height:1.7;">
				<li style="margin-bottom:10px;">Keynote: "The Future of Design Systems" by Jane Cooper</li>
				<li style="margin-bottom:10px;">Workshop: Building High-Converting Landing Pages</li>
				<li>Panel: Scaling Your Creative Team in 2026</li>
			</ul>
			<p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#444444;">Virtual attendance is free. Limited in-person seats available.</p>
			<div style="text-align:center;">{{button}}</div>
		</td>
	</tr>
</table>
HTML;

		$body = str_replace(
			'{{button}}',
			$this->build_button( 'RSVP Now', '#', '#2d2013', '#ffffff', '14px 36px', '8px' ),
			$body
		);

		return $this->build_email_template(
			array(
				'outer_background' => '#f8f4f0',
				'inner_background' => '#f8f4f0',
				'font_family'      => 'Arial,Helvetica,sans-serif',
				'text_color'       => '#444444',
				'padding'          => '40px 20px',
				'inner_padding'    => '0',
				'body'             => $body,
			)
		);
	}

	private function get_reengagement_template_content(): string {
		$body = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate;background-color:#ffffff;border-radius:12px;">
	<tr>
		<td align="center" style="padding:40px;">
			<div style="font-size:48px;line-height:1;margin-bottom:12px;">💔</div>
			<h1 style="margin:0 0 12px;color:#1a1a2e;font-size:24px;line-height:1.3;">We Miss You, Sarah!</h1>
			<p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#666666;">It's been a while since we heard from you. We'd love to have you back.</p>
			<p style="margin:0 0 12px;text-align:left;font-size:15px;line-height:1.7;color:#444444;">A lot has changed since your last visit:</p>
			<ul style="margin:0 0 20px 20px;padding:0;text-align:left;color:#444444;font-size:15px;line-height:1.7;">
				<li style="margin-bottom:10px;">Brand new reporting dashboard</li>
				<li style="margin-bottom:10px;">20+ new email templates added</li>
				<li>Improved deliverability engine</li>
			</ul>
			<p style="margin:0 0 24px;text-align:left;font-size:15px;line-height:1.7;color:#444444;">As a special welcome-back offer, here's <strong>20% off</strong> any plan for the next 7 days.</p>
			{{button}}
		</td>
	</tr>
</table>
HTML;

		$body = str_replace(
			'{{button}}',
			$this->build_button( 'Come Back &amp; Save 20%', '#', '#ef4444', '#ffffff', '14px 36px', '8px' ),
			$body
		);

		return $this->build_email_template(
			array(
				'outer_background' => '#fef2f2',
				'inner_background' => '#fef2f2',
				'font_family'      => 'Arial,Helvetica,sans-serif',
				'text_color'       => '#444444',
				'padding'          => '40px 20px',
				'inner_padding'    => '0',
				'body'             => $body,
			)
		);
	}

	private function get_feedback_request_template_content(): string {
		$body = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate;background-color:#ffffff;border-radius:12px;">
	<tr>
		<td style="padding:40px;">
			<div style="text-align:center;font-size:48px;line-height:1;margin-bottom:12px;">⭐</div>
			<h1 style="margin:0 0 12px;text-align:center;color:#1a1a2e;font-size:24px;line-height:1.3;">How Did We Do?</h1>
			<p style="margin:0 0 20px;text-align:center;color:#555555;font-size:15px;line-height:1.7;">Hi Alex, we'd love to hear your thoughts. Your feedback helps us improve.</p>
			<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#444444;">You recently purchased the <strong>Pro Plan</strong> and we want to make sure everything is meeting your expectations.</p>
			<p style="margin:0 0 12px;font-size:15px;line-height:1.7;color:#444444;">It only takes 2 minutes - here are a few things we'd love to know:</p>
			<ul style="margin:0 0 24px 20px;padding:0;color:#444444;font-size:15px;line-height:1.7;">
				<li style="margin-bottom:10px;">How easy was the setup process?</li>
				<li style="margin-bottom:10px;">Is the product meeting your needs?</li>
				<li>What can we do better?</li>
			</ul>
			<div style="text-align:center;">{{button}}</div>
		</td>
	</tr>
</table>
HTML;

		$body = str_replace(
			'{{button}}',
			$this->build_button( 'Leave a Review', '#', '#16a34a', '#ffffff', '14px 36px', '8px' ),
			$body
		);

		return $this->build_email_template(
			array(
				'outer_background' => '#f0fdf4',
				'inner_background' => '#f0fdf4',
				'font_family'      => 'Arial,Helvetica,sans-serif',
				'text_color'       => '#444444',
				'padding'          => '40px 20px',
				'inner_padding'    => '0',
				'body'             => $body,
			)
		);
	}

	private function get_seasonal_sale_template_content(): string {
		$body = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
	<tr>
		<td align="center" style="padding:40px 32px;background-color:#dc2626;">
			<h1 style="margin:0;color:#ffffff;font-size:36px;line-height:1.2;font-weight:900;text-transform:uppercase;letter-spacing:2px;">Spring Sale Is Here!</h1>
			<p style="margin:8px 0 0;color:#fbd5d5;font-size:18px;line-height:1.6;">Limited time - don't miss out!</p>
		</td>
	</tr>
	<tr>
		<td style="padding:0 20px 20px;background-color:#dc2626;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate;background-color:#ffffff;border-radius:16px;">
				<tr>
					<td align="center" style="padding:40px;">
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;background-color:#fef2f2;border-radius:40px;">
							<tr>
								<td align="center" style="width:80px;height:80px;font-size:36px;line-height:80px;font-weight:900;color:#dc2626;">%</td>
							</tr>
						</table>
						<p style="margin:20px 0 16px;text-align:left;font-size:15px;line-height:1.7;color:#444444;">Our biggest sale of the season is live. Enjoy up to <strong>60% off</strong> across all categories.</p>
						<p style="margin:0 0 12px;text-align:left;font-size:15px;line-height:1.7;color:#444444;"><strong>Top Deals:</strong></p>
						<ul style="margin:0 0 20px 20px;padding:0;text-align:left;color:#444444;font-size:15px;line-height:1.7;">
							<li style="margin-bottom:10px;">Starter Plan - Was $29/mo, now <strong>$12/mo</strong></li>
							<li style="margin-bottom:10px;">Pro Plan - Was $79/mo, now <strong>$32/mo</strong></li>
							<li>Enterprise - Custom pricing, <strong>extra 15% off</strong></li>
						</ul>
						<p style="margin:0 0 24px;text-align:left;font-size:13px;line-height:1.7;color:#888888;">Sale ends March 31, 2026. Prices revert after the promotional period.</p>
						{{button}}
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
HTML;

		$body = str_replace(
			'{{button}}',
			$this->build_button( 'Shop Now', '#', '#dc2626', '#ffffff', '16px 40px', '8px' ),
			$body
		);

		return $this->build_email_template(
			array(
				'outer_background' => '#dc2626',
				'inner_background' => '#dc2626',
				'font_family'      => 'Arial,Helvetica,sans-serif',
				'text_color'       => '#444444',
				'padding'          => '0 0 20px',
				'inner_padding'    => '0',
				'body'             => $body,
			)
		);
	}

	private function get_digest_roundup_template_content(): string {
		$body = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;background-color:#ffffff;">
	<tr>
		<td style="padding:28px 32px;border-bottom:1px solid #e5e7eb;">
			<h1 style="margin:0;color:#1a1a2e;font-size:20px;line-height:1.3;">Starter Hub Weekly Digest</h1>
			<p style="margin:4px 0 0;color:#888888;font-size:13px;line-height:1.6;">Your top stories for the week of March 10, 2026</p>
		</td>
	</tr>
	<tr>
		<td style="padding:24px 24px 8px;background-color:#f4f5f7;">
			{{card_one}}
			{{card_two}}
			{{card_three}}
		</td>
	</tr>
</table>
HTML;

		$cards = array(
			$this->build_digest_card( 'How to Build an Email List from Scratch', 'Learn the proven strategies top marketers use to grow their subscriber base from zero to 10,000. Includes lead magnet ideas and opt-in form best practices.' ),
			$this->build_digest_card( '5 Automation Workflows That Save Hours', 'Set up these five email automation sequences and watch your engagement rates soar while saving 10+ hours per week.' ),
			$this->build_digest_card( 'Case Study: 300% ROI with Segmented Campaigns', 'See how a mid-size e-commerce brand tripled their email ROI using smart segmentation and personalized content.' ),
		);

		$body = str_replace(
			array( '{{card_one}}', '{{card_two}}', '{{card_three}}' ),
			$cards,
			$body
		);

		return $this->build_email_template(
			array(
				'outer_background' => '#f4f5f7',
				'inner_background' => '#ffffff',
				'font_family'      => 'Arial,Helvetica,sans-serif',
				'text_color'       => '#444444',
				'padding'          => '0',
				'inner_padding'    => '0',
				'body'             => $body,
			)
		);
	}

	private function get_minimal_dark_template_content(): string {
		$body = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate;background-color:#111827;border-radius:12px;">
	<tr>
		<td style="padding:40px 24px;">
			<h1 style="margin:0 0 18px;color:#ffffff;font-size:26px;line-height:1.3;font-weight:700;">Your Monthly Security Report</h1>
			<p style="margin:0 0 16px;font-size:15px;line-height:1.8;color:#d1d5db;">Hi there,</p>
			<p style="margin:0 0 16px;font-size:15px;line-height:1.8;color:#d1d5db;">Here's your security report for February 2026. Everything is looking good - your account is fully protected.</p>
			<p style="margin:0 0 12px;font-size:15px;line-height:1.8;color:#ffffff;"><strong>Summary:</strong></p>
			<ul style="margin:0 0 20px 20px;padding:0;color:#d1d5db;font-size:15px;line-height:1.8;">
				<li style="margin-bottom:10px;">Login attempts blocked: <strong style="color:#10b981;">12</strong></li>
				<li style="margin-bottom:10px;">Two-factor authentication: <strong style="color:#10b981;">Enabled</strong></li>
				<li style="margin-bottom:10px;">Last password change: <strong style="color:#fbbf24;">45 days ago</strong></li>
				<li>Active sessions: <strong style="color:#ffffff;">2 devices</strong></li>
			</ul>
			<p style="margin:0 0 24px;font-size:15px;line-height:1.8;color:#d1d5db;">We recommend updating your password every 60 days for maximum security.</p>
			{{button}}
		</td>
	</tr>
</table>
HTML;

		$body = str_replace(
			'{{button}}',
			$this->build_button( 'Review Account Settings', '#', '#3b82f6', '#ffffff', '12px 28px', '6px' ),
			$body
		);

		return $this->build_email_template(
			array(
				'outer_background' => '#111827',
				'inner_background' => '#111827',
				'font_family'      => 'Arial,Helvetica,sans-serif',
				'text_color'       => '#d1d5db',
				'padding'          => '24px 16px',
				'inner_padding'    => '0',
				'body'             => $body,
			)
		);
	}

	private function build_email_template( array $args ): string {
		$outer_background = $args['outer_background'] ?? '#ffffff';
		$inner_background = $args['inner_background'] ?? '#ffffff';
		$font_family      = $args['font_family'] ?? 'Arial,Helvetica,sans-serif';
		$text_color       = $args['text_color'] ?? '#333333';
		$padding          = $args['padding'] ?? '24px 16px';
		$inner_padding    = $args['inner_padding'] ?? '24px';
		$body             = $args['body'] ?? '';

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;background-color:' . esc_attr( $outer_background ) . ';margin:0;padding:0;">'
			. '<tr>'
			. '<td align="center" style="padding:' . esc_attr( $padding ) . ';background-color:' . esc_attr( $outer_background ) . ';">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-collapse:collapse;background-color:' . esc_attr( $inner_background ) . ';">'
			. '<tr>'
			. '<td style="padding:' . esc_attr( $inner_padding ) . ';font-family:' . esc_attr( $font_family ) . ';color:' . esc_attr( $text_color ) . ';">'
			. $body
			. '</td>'
			. '</tr>'
			. '</table>'
			. '</td>'
			. '</tr>'
			. '</table>';
	}

	private function build_button( string $label, string $href, string $background, string $color, string $padding, string $radius, string $border = '' ): string {
		$border_style = $border ? 'border:' . $border . ';' : '';

		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;">'
			. '<tr>'
			. '<td align="center" style="background-color:' . esc_attr( $background ) . ';border-radius:' . esc_attr( $radius ) . ';' . esc_attr( $border_style ) . '">'
			. '<a href="' . esc_attr( $href ) . '" style="display:inline-block;padding:' . esc_attr( $padding ) . ';font-size:15px;line-height:1.2;font-weight:700;color:' . esc_attr( $color ) . ';text-decoration:none;border-radius:' . esc_attr( $radius ) . ';">' . $label . '</a>'
			. '</td>'
			. '</tr>'
			. '</table>';
	}

	private function build_digest_card( string $title, string $description ): string {
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin-bottom:16px;border-collapse:separate;background-color:#ffffff;border:1px solid #e5e7eb;border-radius:8px;">'
			. '<tr>'
			. '<td style="padding:20px;">'
			. '<h3 style="margin:0 0 8px;color:#1a1a2e;font-size:16px;line-height:1.4;">' . esc_html( $title ) . '</h3>'
			. '<p style="margin:0;color:#666666;font-size:14px;line-height:1.6;">' . esc_html( $description ) . ' <a href="#" style="color:#3858e9;text-decoration:underline;">Read more</a></p>'
			. '</td>'
			. '</tr>'
			. '</table>';
	}

	private function normalize_template_content( $template ) {
		if ( is_object( $template ) && isset( $template->content ) ) {
			$template->content = $this->strip_unsubscribe_markup( (string) $template->content );
		}

		return $template;
	}

	private function strip_unsubscribe_markup( string $content ): string {
		$patterns = array(
			'#(?:<hr\b[^>]*>\s*)?<p\b[^>]*>\s*(?:[^<]*&bull;\s*)?<a\b[^>]*href=["\'][^"\']*\{\{unsubscribe(?:_url)?\}\}[^"\']*["\'][^>]*>.*?</a>(?:\s*from these emails?)?\s*</p>#is',
			'#<div\b[^>]*>\s*(?:[^<]*&bull;\s*)?<a\b[^>]*href=["\'][^"\']*\{\{unsubscribe(?:_url)?\}\}[^"\']*["\'][^>]*>.*?</a>(?:\s*from these emails?)?\s*</div>#is',
			'#<a\b[^>]*href=["\'][^"\']*\{\{unsubscribe(?:_url)?\}\}[^"\']*["\'][^>]*>.*?</a>#is',
		);

		$content = preg_replace( $patterns, '', $content );
		$content = preg_replace( '#<p\b[^>]*>\s*</p>#i', '', $content );

		return trim( $content );
	}

	private function sanitize( \WP_REST_Request $r ): array {
		$data = array();
		foreach ( array( 'name', 'category', 'type', 'design_template' ) as $f ) {
			if ( $r->has_param( $f ) ) {
				$data[ $f ] = sanitize_text_field( $r->get_param( $f ) );
			}
		}
		if ( $r->has_param( 'content' ) ) {
			$data['content'] = $this->strip_unsubscribe_markup( wp_kses( $r->get_param( 'content' ), $this->get_allowed_template_html() ) );
		}
		if ( $r->has_param( 'description' ) ) {
			$data['description'] = sanitize_textarea_field( $r->get_param( 'description' ) );
		}
		if ( $r->has_param( 'thumbnail' ) ) {
			$data['thumbnail'] = esc_url_raw( $r->get_param( 'thumbnail' ) );
		}
		return $data;
	}

	private function get_allowed_template_html(): array {
		$allowed = wp_kses_allowed_html( 'post' );

		foreach ( array( 'table', 'tbody', 'thead', 'tfoot', 'tr', 'td', 'th', 'colgroup', 'col' ) as $tag ) {
			if ( ! isset( $allowed[ $tag ] ) ) {
				$allowed[ $tag ] = array();
			}

			$allowed[ $tag ] = array_merge(
				$allowed[ $tag ],
				array(
					'align'       => true,
					'bgcolor'     => true,
					'border'      => true,
					'cellpadding' => true,
					'cellspacing' => true,
					'colspan'     => true,
					'height'      => true,
					'role'        => true,
					'style'       => true,
					'valign'      => true,
					'width'       => true,
				)
			);
		}

		return $allowed;
	}

	private function maybe_sync_default_templates(): void {
		if ( get_option( 'aime_default_template_layout_version' ) === self::DEFAULT_TEMPLATE_LAYOUT_VERSION ) {
			return;
		}

		global $wpdb;

		$this->dedupe_default_templates();

		$table_name = "{$wpdb->prefix}aime_templates";
		$templates  = $wpdb->get_results( "SELECT id, name FROM {$table_name} WHERE is_default = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $templates as $template ) {
			$content = $this->get_default_template_content_by_name( (string) $template->name );

			if ( null === $content ) {
				continue;
			}

			$wpdb->update(
				$table_name,
				array(
					'content' => $this->strip_unsubscribe_markup( $content ),
				),
				array(
					'id' => (int) $template->id,
				)
			);
		}

		update_option( 'aime_default_template_layout_version', self::DEFAULT_TEMPLATE_LAYOUT_VERSION );
	}

	private function get_default_template_content_by_name( string $name ): ?string {
		switch ( $name ) {
			case __( 'Simple Text', 'ai-marketing-expert' ):
				return $this->get_simple_text_template_content();

			case __( 'Modern Card', 'ai-marketing-expert' ):
				return $this->get_modern_card_template_content();

			case __( 'Welcome Email', 'ai-marketing-expert' ):
				return $this->get_welcome_email_template_content();

			case __( 'Promotional Offer', 'ai-marketing-expert' ):
				return $this->get_promotional_offer_template_content();

			case __( 'Newsletter Classic', 'ai-marketing-expert' ):
				return $this->get_newsletter_classic_template_content();

			case __( 'Transactional Receipt', 'ai-marketing-expert' ):
				return $this->get_transactional_receipt_template_content();

			case __( 'Notification Alert', 'ai-marketing-expert' ):
				return $this->get_notification_alert_template_content();

			case __( 'Product Launch', 'ai-marketing-expert' ):
				return $this->get_product_launch_template_content();

			case __( 'Event Invitation', 'ai-marketing-expert' ):
				return $this->get_event_invitation_template_content();

			case __( 'Re-engagement', 'ai-marketing-expert' ):
				return $this->get_reengagement_template_content();

			case __( 'Feedback Request', 'ai-marketing-expert' ):
				return $this->get_feedback_request_template_content();

			case __( 'Seasonal Sale', 'ai-marketing-expert' ):
				return $this->get_seasonal_sale_template_content();

			case __( 'Digest / Roundup', 'ai-marketing-expert' ):
				return $this->get_digest_roundup_template_content();

			case __( 'Minimal Dark', 'ai-marketing-expert' ):
				return $this->get_minimal_dark_template_content();
		}

		return null;
	}
}
