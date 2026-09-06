<?php
/**
 * SEO Module — Calendar Controller (Content calendar CRUD + AI generation).
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Controllers;

use WPSpace\AiMarketingExpert\Modules\Seo\Services\ContentCalendarService;
use WPSpace\AiMarketingExpert\Modules\Seo\SeoModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CalendarController {

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 50 ) ) );
		$status   = sanitize_key( $request->get_param( 'status' ) ?? '' );
		$month    = sanitize_text_field( $request->get_param( 'month' ) ?? '' );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array( '1=1' );
		$values = array();

		if ( $status ) {
			$where[]  = 'c.status = %s';
			$values[] = $status;
		}
		if ( $month && preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$where[]  = 'c.planned_date >= %s';
			$values[] = $month . '-01';
			$where[]  = 'c.planned_date < DATE_ADD(%s, INTERVAL 1 MONTH)';
			$values[] = $month . '-01';
		}

		$where_sql = implode( ' AND ', $where );

		if ( ! empty( $values ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}aime_seo_calendar c WHERE {$where_sql}",
				...$values
			) );
			$items = $wpdb->get_results( $wpdb->prepare(
				"SELECT c.*, k.keyword as keyword_name FROM {$p}aime_seo_calendar c LEFT JOIN {$p}aime_seo_keywords k ON c.keyword_id = k.id WHERE {$where_sql} ORDER BY c.planned_date ASC, c.created_at DESC LIMIT %d OFFSET %d",
				...array_merge( $values, array( $per_page, $offset ) )
			) );
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_seo_calendar" );
			$items = $wpdb->get_results( $wpdb->prepare(
				"SELECT c.*, k.keyword as keyword_name FROM {$p}aime_seo_calendar c LEFT JOIN {$p}aime_seo_keywords k ON c.keyword_id = k.id ORDER BY c.planned_date ASC, c.created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			) );
		}

		return new \WP_REST_Response( array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		) );
	}

	public function store( \WP_REST_Request $request ): \WP_REST_Response {
		if ( aime_limit_reached( 'seo_calendar_items', SeoModule::get_calendar_count() ) ) {
			$limit = aime_free_limits()['seo_calendar_items'] ?? 10;
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => sprintf(
					/* translators: %d: calendar item limit on the free plan */
					__( 'Free plan allows %d calendar items. Delete one or upgrade to Pro for an unlimited calendar.', 'ai-marketing-expert' ),
					$limit
				),
				'limit_reached' => true,
			), 403 );
		}

		global $wpdb;
		$p = $wpdb->prefix;

		$data = array(
			'title'        => sanitize_text_field( $request->get_param( 'title' ) ?? '' ),
			'keyword_id'   => absint( $request->get_param( 'keyword_id' ) ) ?: null,
			'topic_id'     => absint( $request->get_param( 'topic_id' ) ) ?: null,
			'content_type' => sanitize_key( $request->get_param( 'content_type' ) ?? 'blog_post' ),
			'assigned_to'  => absint( $request->get_param( 'assigned_to' ) ) ?: null,
			'status'       => sanitize_key( $request->get_param( 'status' ) ?? 'planned' ),
			'planned_date' => sanitize_text_field( $request->get_param( 'planned_date' ) ?? '' ) ?: null,
			'wp_post_id'   => absint( $request->get_param( 'wp_post_id' ) ) ?: null,
			'article_id'   => absint( $request->get_param( 'article_id' ) ) ?: null,
			'notes'        => sanitize_textarea_field( $request->get_param( 'notes' ) ?? '' ),
		);

		if ( empty( $data['title'] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Title is required.', 'ai-marketing-expert' ),
			), 400 );
		}

		$wpdb->insert( "{$p}aime_seo_calendar", $data );
		$id = $wpdb->insert_id;

		if ( ! $id ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Failed to create calendar item.', 'ai-marketing-expert' ),
			), 500 );
		}

		$item = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}aime_seo_calendar WHERE id = %d",
			$id
		) );

		return new \WP_REST_Response( array( 'success' => true, 'data' => $item ), 201 );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = (int) $request->get_param( 'id' );

		$item = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.*, k.keyword as keyword_name, t.name as topic_name FROM {$p}aime_seo_calendar c LEFT JOIN {$p}aime_seo_keywords k ON c.keyword_id = k.id LEFT JOIN {$p}aime_seo_topics t ON c.topic_id = t.id WHERE c.id = %d",
			$id
		) );

		if ( ! $item ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Calendar item not found.', 'ai-marketing-expert' ),
			), 404 );
		}

		return new \WP_REST_Response( array( 'success' => true, 'data' => $item ) );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = (int) $request->get_param( 'id' );

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$p}aime_seo_calendar WHERE id = %d",
			$id
		) );

		if ( ! $exists ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Calendar item not found.', 'ai-marketing-expert' ),
			), 404 );
		}

		$data = array( 'updated_at' => current_time( 'mysql', true ) );

		$text_fields = array(
			'title'        => 'sanitize_text_field',
			'content_type' => 'sanitize_key',
			'status'       => 'sanitize_key',
			'notes'        => 'sanitize_textarea_field',
		);

		foreach ( $text_fields as $field => $sanitizer ) {
			$val = $request->get_param( $field );
			if ( $val !== null ) {
				$data[ $field ] = $sanitizer( $val );
			}
		}

		$int_fields = array( 'keyword_id', 'topic_id', 'assigned_to', 'wp_post_id', 'article_id' );
		foreach ( $int_fields as $field ) {
			$val = $request->get_param( $field );
			if ( $val !== null ) {
				$data[ $field ] = absint( $val ) ?: null;
			}
		}

		$date_fields = array( 'planned_date', 'published_date' );
		foreach ( $date_fields as $field ) {
			$val = $request->get_param( $field );
			if ( $val !== null ) {
				$data[ $field ] = sanitize_text_field( $val ) ?: null;
			}
		}

		$wpdb->update( "{$p}aime_seo_calendar", $data, array( 'id' => $id ) );

		$item = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}aime_seo_calendar WHERE id = %d",
			$id
		) );

		return new \WP_REST_Response( array( 'success' => true, 'data' => $item ) );
	}

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = (int) $request->get_param( 'id' );

		$deleted = $wpdb->delete( "{$p}aime_seo_calendar", array( 'id' => $id ) );

		if ( ! $deleted ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Calendar item not found.', 'ai-marketing-expert' ),
			), 404 );
		}

		return new \WP_REST_Response( array( 'success' => true ) );
	}

	/**
	 * AI-generate content calendar. Free plan is metered.
	 */
	public function generate_calendar( \WP_REST_Request $request ): \WP_REST_Response {
		$max_items = null;

		if ( ! aime_has_pro() ) {
			$gen_limit = aime_free_limits()['seo_calendar_generate_monthly'] ?? 1;
			if ( SeoModule::get_monthly_feature_count( 'calendar_generate' ) >= $gen_limit ) {
				return new \WP_REST_Response( array(
					'success' => false,
					'message' => sprintf(
						/* translators: %d: monthly calendar generation limit on the free plan */
						__( 'Free plan allows %d AI calendar generation per month. Upgrade to Pro for unlimited generations.', 'ai-marketing-expert' ),
						$gen_limit
					),
					'limit_reached' => true,
				), 403 );
			}

			$max_items = aime_limit_remaining( 'seo_calendar_items', SeoModule::get_calendar_count() );
			if ( ! $max_items ) {
				return new \WP_REST_Response( array(
					'success' => false,
					'message' => sprintf(
						/* translators: %d: calendar item limit on the free plan */
						__( 'You have reached the free plan limit of %d calendar items. Delete some items or upgrade to Pro before generating a calendar.', 'ai-marketing-expert' ),
						aime_free_limits()['seo_calendar_items'] ?? 10
					),
					'limit_reached' => true,
				), 403 );
			}
		}

		$niche     = sanitize_text_field( $request->get_param( 'niche' ) ?? '' );
		$weeks     = min( 12, max( 1, absint( $request->get_param( 'weeks' ) ?: 4 ) ) );
		$frequency = sanitize_key( $request->get_param( 'frequency' ) ?? 'twice_weekly' );

		if ( empty( $niche ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Niche or topic area is required.', 'ai-marketing-expert' ),
			), 400 );
		}

		$service = new ContentCalendarService();
		$result  = $service->generate_calendar( $niche, $weeks, $frequency, $max_items );

		if ( $result['success'] && ! aime_has_pro() ) {
			SeoModule::increment_monthly_feature( 'calendar_generate' );
		}

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Calendar usage counts and free limits.
	 */
	public function calendar_usage(): \WP_REST_Response {
		return new \WP_REST_Response( array(
			'success' => true,
			'is_pro'  => aime_has_pro(),
			'usage'   => array(
				'items'    => aime_usage_payload( 'seo_calendar_items', SeoModule::get_calendar_count() ),
				'generate' => aime_usage_payload( 'seo_calendar_generate_monthly', SeoModule::get_monthly_feature_count( 'calendar_generate' ) ),
			),
		) );
	}
}
