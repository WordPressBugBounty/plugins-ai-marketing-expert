<?php
/**
 * Funnel Controller — automations CRUD, sequences, triggers.
 *
 * @package WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FunnelController {

	/* ── LIST ─────────────────────────────────────────── */

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		$per_page = absint( $request->get_param( 'per_page' ) ?: 20 );
		$page     = absint( $request->get_param( 'page' ) ?: 1 );
		$offset   = ( $page - 1 ) * $per_page;
		$status   = sanitize_text_field( $request->get_param( 'status' ) ?? '' );

		$where  = array( '1=1' );
		$params = array();
		if ( $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$where_sql = implode( ' AND ', $where );

		$params_c = $params;
		$params[] = $per_page;
		$params[] = $offset;

		$total = (int) $wpdb->get_var( empty( $params_c ) ? "SELECT COUNT(*) FROM {$p}aime_funnels WHERE {$where_sql}" : $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_funnels WHERE {$where_sql}", ...$params_c ) ); // phpcs:ignore
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}aime_funnels WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", ...$params ) ); // phpcs:ignore

		// Attach subscriber count per funnel.
		foreach ( $items as &$item ) {
			$item->subscribers_count = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_funnel_subscribers WHERE funnel_id = %d", $item->id )
			);
			$item->sequences_count = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_funnel_sequences WHERE funnel_id = %d", $item->id )
			);
		}

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
		$p   = $wpdb->prefix;
		$id  = absint( $request->get_param( 'id' ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}aime_funnels WHERE id = %d", $id ) );

		if ( ! $row ) {
			return new \WP_REST_Response( array( 'message' => __( 'Automation not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$row->sequences   = $this->get_sequences( $id );
		$row->subscribers  = $this->get_funnel_subscribers( $id );
		$row->metrics      = $this->get_funnel_metrics( $id );
		$row->settings     = $this->decode_json_field( $row->settings ?? '' );
		$row->conditions   = $this->decode_json_field( $row->conditions ?? '' );

		return new \WP_REST_Response( $row );
	}

	/* ── CREATE ───────────────────────────────────────── */

	public function store( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		// Free tier: limited number of automation funnels.
		if ( ! aime_has_pro() ) {
			$limits   = aime_free_limits();
			$max_free = (int) ( $limits['email_funnels'] ?? 2 );
			$count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aime_funnels" );
			if ( $count >= $max_free ) {
				return new \WP_REST_Response( array(
					'message'       => sprintf(
						/* translators: %d: number of automation funnels allowed on the free plan. */
						__( 'Free sites can create %d automation funnels. Upgrade to Pro for unlimited automations.', 'ai-marketing-expert' ),
						$max_free
					),
					'limit_reached' => true,
				), 403 );
			}
		}

		$data = $this->sanitize( $request );
		$data['created_by'] = get_current_user_id();
		$data['created_at'] = current_time( 'mysql', true );

		$wpdb->insert( "{$wpdb->prefix}aime_funnels", $data );
		return new \WP_REST_Response( array( 'id' => (int) $wpdb->insert_id, 'message' => __( 'Automation created.', 'ai-marketing-expert' ) ), 201 );
	}

	/* ── UPDATE ───────────────────────────────────────── */

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$id  = absint( $request->get_param( 'id' ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aime_funnels WHERE id = %d", $id ) );
		if ( ! $row ) {
			return new \WP_REST_Response( array( 'message' => __( 'Automation not found.', 'ai-marketing-expert' ) ), 404 );
		}
		$data = $this->sanitize( $request );
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( "{$wpdb->prefix}aime_funnels", $data, array( 'id' => $id ) );
		if ( isset( $data['status'] ) && 'published' === $data['status'] ) {
			$wpdb->update(
				"{$wpdb->prefix}aime_funnel_sequences",
				array( 'status' => 'published', 'updated_at' => current_time( 'mysql', true ) ),
				array( 'funnel_id' => $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
		return new \WP_REST_Response( array( 'message' => __( 'Automation updated.', 'ai-marketing-expert' ) ) );
	}

	/* ── DELETE ────────────────────────────────────────── */

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$p}aime_funnels WHERE id = %d", $id ) );
		if ( ! $row ) {
			return new \WP_REST_Response( array( 'message' => __( 'Automation not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$wpdb->delete( "{$p}aime_funnels", array( 'id' => $id ) );
		$wpdb->delete( "{$p}aime_funnel_sequences", array( 'funnel_id' => $id ) );
		$wpdb->delete( "{$p}aime_funnel_subscribers", array( 'funnel_id' => $id ) );
		$wpdb->delete( "{$p}aime_funnel_metrics", array( 'funnel_id' => $id ) );

		return new \WP_REST_Response( array( 'message' => __( 'Automation deleted.', 'ai-marketing-expert' ) ) );
	}

	/* ── CLONE ─────────────────────────────────────────── */

	public function duplicate( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );

		// Duplicating creates a new funnel — same free-tier limit as store().
		if ( ! aime_has_pro() ) {
			$limits   = aime_free_limits();
			$max_free = (int) ( $limits['email_funnels'] ?? 2 );
			$count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_funnels" );
			if ( $count >= $max_free ) {
				return new \WP_REST_Response( array(
					'message'       => sprintf(
						/* translators: %d: number of automation funnels allowed on the free plan. */
						__( 'Free sites can create %d automation funnels. Upgrade to Pro for unlimited automations.', 'ai-marketing-expert' ),
						$max_free
					),
					'limit_reached' => true,
				), 403 );
			}
		}

		$funnel = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}aime_funnels WHERE id = %d", $id ), ARRAY_A );
		if ( ! $funnel ) {
			return new \WP_REST_Response( array( 'message' => __( 'Automation not found.', 'ai-marketing-expert' ) ), 404 );
		}

		unset( $funnel['id'] );
		$funnel['title']      = $funnel['title'] . ' (Copy)';
		$funnel['status']     = 'draft';
		$funnel['created_at'] = current_time( 'mysql', true );

		$wpdb->insert( "{$p}aime_funnels", $funnel );
		$new_id = (int) $wpdb->insert_id;

		// Clone sequences.
		$sequences = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}aime_funnel_sequences WHERE funnel_id = %d ORDER BY sequence ASC", $id ), ARRAY_A );
		foreach ( $sequences as $seq ) {
			unset( $seq['id'] );
			$seq['funnel_id']  = $new_id;
			$seq['status']     = 'draft';
			$seq['created_at'] = current_time( 'mysql', true );
			$wpdb->insert( "{$p}aime_funnel_sequences", $seq );
		}

		return new \WP_REST_Response( array( 'id' => $new_id, 'message' => __( 'Automation duplicated.', 'ai-marketing-expert' ) ), 201 );
	}

	/* ─── SEQUENCES ────────────────────────────────────── */

	public function save_sequences( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p         = $wpdb->prefix;
		$funnel_id = absint( $request->get_param( 'id' ) );
		$sequences = $request->get_param( 'sequences' ) ?? array();

		// Delete existing and recreate.
		$wpdb->delete( "{$p}aime_funnel_sequences", array( 'funnel_id' => $funnel_id ) );

		// Check if parent funnel is published (auto-publish sequences).
		$funnel_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}aime_funnels WHERE id = %d", $funnel_id ) );
		$default_status = 'published' === $funnel_status ? 'published' : 'draft';

		$now = current_time( 'mysql', true );
		foreach ( $sequences as $i => $seq ) {
			$seq      = $this->normalize_sequence( (array) $seq );
			$settings = $this->sanitize_settings( $seq['settings'] ?? array() );
			$status   = 'published' === $funnel_status ? 'published' : sanitize_text_field( $seq['status'] ?? $default_status );
			$wpdb->insert( "{$p}aime_funnel_sequences", array(
				'funnel_id'      => $funnel_id,
				'parent_id'      => absint( $seq['parent_id'] ?? 0 ),
				'action_name'    => sanitize_text_field( $seq['action_name'] ?? '' ),
				'condition_type' => sanitize_text_field( $seq['condition_type'] ?? '' ),
				'type'           => sanitize_text_field( $seq['type'] ?? 'sequence' ),
				'title'          => sanitize_text_field( $seq['title'] ?? '' ),
				'description'    => sanitize_text_field( $seq['description'] ?? '' ),
				'status'         => $status,
				'conditions'     => wp_json_encode( map_deep( $seq['conditions'] ?? array(), 'sanitize_text_field' ) ),
				'settings'       => wp_json_encode( $settings ),
				'delay'          => absint( $seq['delay'] ?? 0 ),
				'c_delay'        => absint( $seq['c_delay'] ?? 0 ),
				'sequence'       => $i,
				'created_by'     => get_current_user_id(),
				'created_at'     => $now,
			) );
		}

		return new \WP_REST_Response( array( 'message' => __( 'Sequences saved.', 'ai-marketing-expert' ), 'count' => count( $sequences ) ) );
	}

	/* ─── FUNNEL SUBSCRIBERS ──────────────────────────── */

	public function get_subscribers( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p         = $wpdb->prefix;
		$funnel_id = absint( $request->get_param( 'id' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fs.*, s.email, s.first_name, s.last_name
				 FROM {$p}aime_funnel_subscribers fs
				 INNER JOIN {$p}aime_subscribers s ON s.id = fs.subscriber_id
				 WHERE fs.funnel_id = %d
				 ORDER BY fs.created_at DESC LIMIT 200",
				$funnel_id
			)
		);

		return new \WP_REST_Response( $rows );
	}

	/* ─── AVAILABLE TRIGGERS ─────────────────────────── */

	public function get_triggers( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( array(
			array( 'name' => 'subscriber_created',      'label' => __( 'Contact Created', 'ai-marketing-expert' ),          'category' => 'contacts' ),
			array( 'name' => 'subscriber_status_change', 'label' => __( 'Contact Status Changed', 'ai-marketing-expert' ),   'category' => 'contacts' ),
			array( 'name' => 'tag_added',                'label' => __( 'Tag Added to Contact', 'ai-marketing-expert' ),     'category' => 'contacts' ),
			array( 'name' => 'tag_removed',              'label' => __( 'Tag Removed from Contact', 'ai-marketing-expert' ), 'category' => 'contacts' ),
			array( 'name' => 'list_added',               'label' => __( 'Added to List', 'ai-marketing-expert' ),            'category' => 'contacts' ),
			array( 'name' => 'list_removed',             'label' => __( 'Removed from List', 'ai-marketing-expert' ),        'category' => 'contacts' ),
			array( 'name' => 'form_submitted',           'label' => __( 'Form Submitted', 'ai-marketing-expert' ),           'category' => 'events' ),
			array( 'name' => 'link_clicked',             'label' => __( 'Email Link Clicked', 'ai-marketing-expert' ),       'category' => 'campaigns' ),
			array( 'name' => 'email_opened',             'label' => __( 'Email Opened', 'ai-marketing-expert' ),             'category' => 'campaigns' ),
			array( 'name' => 'woo_order_completed',      'label' => __( 'WooCommerce Order Done', 'ai-marketing-expert' ),   'category' => 'woocommerce' ),
			array( 'name' => 'user_registered',          'label' => __( 'WordPress User Registered', 'ai-marketing-expert' ),'category' => 'wordpress' ),
		) );
	}

	/* ─── AVAILABLE ACTIONS ──────────────────────────── */

	public function get_actions( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( array(
			array( 'name' => 'send_email',       'label' => __( 'Send Email', 'ai-marketing-expert' ),         'category' => 'email' ),
			array( 'name' => 'wait',             'label' => __( 'Wait / Delay', 'ai-marketing-expert' ),       'category' => 'timing' ),
			array( 'name' => 'add_tag',          'label' => __( 'Add Tag', 'ai-marketing-expert' ),            'category' => 'contacts' ),
			array( 'name' => 'remove_tag',       'label' => __( 'Remove Tag', 'ai-marketing-expert' ),         'category' => 'contacts' ),
			array( 'name' => 'add_to_list',      'label' => __( 'Add to List', 'ai-marketing-expert' ),        'category' => 'contacts' ),
			array( 'name' => 'remove_from_list', 'label' => __( 'Remove from List', 'ai-marketing-expert' ),   'category' => 'contacts' ),
			array( 'name' => 'update_contact',   'label' => __( 'Update Contact', 'ai-marketing-expert' ),     'category' => 'contacts' ),
			array( 'name' => 'webhook',          'label' => __( 'Send Webhook', 'ai-marketing-expert' ),       'category' => 'integration' ),
			array( 'name' => 'end',              'label' => __( 'End Automation', 'ai-marketing-expert' ),     'category' => 'logic' ),
		) );
	}

	/* ─── PRIVATE HELPERS ─────────────────────────────── */

	private function sanitize( \WP_REST_Request $r ): array {
		$data = array();
		foreach ( array( 'title', 'trigger_name', 'status', 'type' ) as $f ) {
			if ( $r->has_param( $f ) ) {
				$data[ $f ] = sanitize_text_field( $r->get_param( $f ) );
			}
		}
		if ( $r->has_param( 'conditions' ) ) {
			$data['conditions'] = wp_json_encode( map_deep( $r->get_param( 'conditions' ), 'sanitize_text_field' ) );
		}
		if ( $r->has_param( 'settings' ) ) {
			$data['settings'] = wp_json_encode( $this->sanitize_settings( (array) $r->get_param( 'settings' ) ) );
		}
		return $data;
	}

	private function get_sequences( int $funnel_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aime_funnel_sequences WHERE funnel_id = %d ORDER BY sequence ASC", $funnel_id )
		) ?: array();

		foreach ( $rows as &$row ) {
			$row->settings     = $this->decode_json_field( $row->settings ?? '' );
			$row->conditions   = $this->decode_json_field( $row->conditions ?? '' );
			$row->delay_value  = $this->delay_value_from_seconds( (int) ( $row->delay ?? 0 ) );
			$row->delay_unit   = $this->delay_unit_from_seconds( (int) ( $row->delay ?? 0 ) );
		}

		return $rows;
	}

	private function normalize_sequence( array $seq ): array {
		$settings = (array) ( $seq['settings'] ?? array() );
		$action   = sanitize_key( $seq['action_name'] ?? '' );

		if ( 'send_email' === $action ) {
			if ( empty( $settings['email_subject'] ) && ! empty( $settings['subject'] ) ) {
				$settings['email_subject'] = $settings['subject'];
			}
			if ( empty( $settings['email_body'] ) && ! empty( $settings['body'] ) ) {
				$settings['email_body'] = $settings['body'];
			}

			// One source only. Whatever the client sent, the unused source's
			// fields are dropped here so a step can never carry both.
			$source = (string) ( $settings['email_source'] ?? '' );
			if ( ! in_array( $source, array( 'plain_text', 'campaign' ), true ) ) {
				$source = ! empty( $settings['campaign_id'] ) ? 'campaign' : 'plain_text';
			}
			$settings['email_source'] = $source;

			if ( 'campaign' === $source ) {
				unset( $settings['email_subject'], $settings['email_body'], $settings['subject'], $settings['body'] );
			} else {
				unset( $settings['campaign_id'] );
			}
		}

		if ( in_array( $action, array( 'add_tag', 'remove_tag' ), true ) && empty( $settings['tag_ids'] ) && ! empty( $settings['tag_id'] ) ) {
			$settings['tag_ids'] = array( $settings['tag_id'] );
		}

		if ( in_array( $action, array( 'add_to_list', 'remove_from_list' ), true ) && empty( $settings['list_ids'] ) && ! empty( $settings['list_id'] ) ) {
			$settings['list_ids'] = array( $settings['list_id'] );
		}

		if ( 'update_contact' === $action && empty( $settings['fields'] ) && ! empty( $settings['field'] ) ) {
			$settings['fields'] = array( $settings['field'] => $settings['value'] ?? '' );
		}

		if ( empty( $seq['delay'] ) ) {
			$seq['delay'] = $this->delay_to_seconds( absint( $seq['delay_value'] ?? 0 ), sanitize_key( $seq['delay_unit'] ?? 'minutes' ) );
		}

		$seq['settings'] = $settings;
		return $seq;
	}

	private function sanitize_settings( array $settings ): array {
		$clean = array();
		foreach ( $settings as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( is_array( $value ) ) {
				$clean[ $key ] = $this->sanitize_settings( $value );
			} elseif ( 'email_body' === $key ) {
				$clean[ $key ] = wp_kses_post( (string) $value );
			} elseif ( in_array( $key, array( 'tag_ids', 'list_ids' ), true ) ) {
				$clean[ $key ] = array_map( 'absint', (array) $value );
			} else {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $clean;
	}

	private function decode_json_field( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function delay_to_seconds( int $value, string $unit ): int {
		switch ( $unit ) {
			case 'days':
				return $value * DAY_IN_SECONDS;
			case 'hours':
				return $value * HOUR_IN_SECONDS;
			case 'minutes':
			default:
				return $value * MINUTE_IN_SECONDS;
		}
	}

	private function delay_unit_from_seconds( int $seconds ): string {
		if ( $seconds > 0 && 0 === $seconds % DAY_IN_SECONDS ) {
			return 'days';
		}
		if ( $seconds > 0 && 0 === $seconds % HOUR_IN_SECONDS ) {
			return 'hours';
		}
		return 'minutes';
	}

	private function delay_value_from_seconds( int $seconds ): int {
		$unit = $this->delay_unit_from_seconds( $seconds );
		if ( 'days' === $unit ) {
			return (int) ( $seconds / DAY_IN_SECONDS );
		}
		if ( 'hours' === $unit ) {
			return (int) ( $seconds / HOUR_IN_SECONDS );
		}
		return (int) ( $seconds / MINUTE_IN_SECONDS );
	}

	private function get_funnel_subscribers( int $funnel_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fs.*, s.email, s.first_name, s.last_name
				 FROM {$p}aime_funnel_subscribers fs
				 INNER JOIN {$p}aime_subscribers s ON s.id = fs.subscriber_id
				 WHERE fs.funnel_id = %d ORDER BY fs.created_at DESC LIMIT 100",
				$funnel_id
			)
		) ?: array();
	}

	private function get_funnel_metrics( int $funnel_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		return array(
			'total_subscribers' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_funnel_subscribers WHERE funnel_id = %d", $funnel_id ) ),
			'active'            => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_funnel_subscribers WHERE funnel_id = %d AND status = 'active'", $funnel_id ) ),
			'completed'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_funnel_subscribers WHERE funnel_id = %d AND status = 'completed'", $funnel_id ) ),
			'steps_completed'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_funnel_metrics WHERE funnel_id = %d AND status = 'completed'", $funnel_id ) ),
		);
	}
}
