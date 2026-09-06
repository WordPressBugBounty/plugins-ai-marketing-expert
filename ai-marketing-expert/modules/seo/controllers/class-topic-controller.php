<?php
/**
 * SEO Module — Topic Controller (Topical Authority map CRUD + AI generation).
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Controllers;

use WPSpace\AiMarketingExpert\Modules\Seo\Services\TopicalAuthorityService;
use WPSpace\AiMarketingExpert\Modules\Seo\SeoModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TopicController {

	/* ── Topics CRUD ─────────────────────────────────────── */

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 50 ) ) );
		$search   = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
		$type     = sanitize_key( $request->get_param( 'topic_type' ) ?? '' );
		$status   = sanitize_key( $request->get_param( 'status' ) ?? '' );
		$parent   = $request->get_param( 'parent_id' );

		$where  = array( '1=1' );
		$values = array();

		if ( $search ) {
			$where[]  = 'name LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		if ( $type ) {
			$where[]  = 'topic_type = %s';
			$values[] = $type;
		}
		if ( $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}
		if ( $parent !== null && $parent !== '' ) {
			$where[]  = 'parent_id = %d';
			$values[] = absint( $parent );
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = ( $page - 1 ) * $per_page;

		if ( ! empty( $values ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}aime_seo_topics WHERE {$where_sql}",
				...$values
			) );
			$items = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$p}aime_seo_topics WHERE {$where_sql} ORDER BY priority ASC, created_at DESC LIMIT %d OFFSET %d",
				...array_merge( $values, array( $per_page, $offset ) )
			) );
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_seo_topics" );
			$items = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$p}aime_seo_topics ORDER BY priority ASC, created_at DESC LIMIT %d OFFSET %d",
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
		if ( aime_limit_reached( 'seo_topics', SeoModule::get_topic_count() ) ) {
			$limit = aime_free_limits()['seo_topics'] ?? 10;
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => sprintf(
					/* translators: %d: topic limit on the free plan */
					__( 'Free plan allows %d topics. Delete one or upgrade to Pro for unlimited topics.', 'ai-marketing-expert' ),
					$limit
				),
				'limit_reached' => true,
			), 403 );
		}

		global $wpdb;
		$p = $wpdb->prefix;

		$data = array(
			'name'              => sanitize_text_field( $request->get_param( 'name' ) ?? '' ),
			'description'       => sanitize_textarea_field( $request->get_param( 'description' ) ?? '' ),
			'topic_type'        => sanitize_key( $request->get_param( 'topic_type' ) ?? 'cluster' ),
			'parent_id'         => absint( $request->get_param( 'parent_id' ) ) ?: null,
			'status'            => sanitize_key( $request->get_param( 'status' ) ?? 'planned' ),
			'target_keyword_id' => absint( $request->get_param( 'target_keyword_id' ) ) ?: null,
			'wp_post_id'        => absint( $request->get_param( 'wp_post_id' ) ) ?: null,
			'content_brief'     => wp_kses_post( $request->get_param( 'content_brief' ) ?? '' ),
			'word_count_target' => absint( $request->get_param( 'word_count_target' ) ?: 1500 ),
			'priority'          => min( 5, max( 1, absint( $request->get_param( 'priority' ) ?: 3 ) ) ),
			'notes'             => sanitize_textarea_field( $request->get_param( 'notes' ) ?? '' ),
		);

		if ( empty( $data['name'] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Topic name is required.', 'ai-marketing-expert' ),
			), 400 );
		}

		$wpdb->insert( "{$p}aime_seo_topics", $data );
		$id = $wpdb->insert_id;

		if ( ! $id ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Failed to create topic.', 'ai-marketing-expert' ),
			), 500 );
		}

		$topic = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}aime_seo_topics WHERE id = %d",
			$id
		) );

		return new \WP_REST_Response( array( 'success' => true, 'data' => $topic ), 201 );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = (int) $request->get_param( 'id' );

		$topic = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}aime_seo_topics WHERE id = %d",
			$id
		) );

		if ( ! $topic ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Topic not found.', 'ai-marketing-expert' ),
			), 404 );
		}

		// Fetch child topics.
		$children = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$p}aime_seo_topics WHERE parent_id = %d ORDER BY priority ASC",
			$id
		) );

		// Fetch links.
		$links = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$p}aime_seo_topic_links WHERE source_topic_id = %d OR target_topic_id = %d",
			$id,
			$id
		) );

		return new \WP_REST_Response( array(
			'success'  => true,
			'data'     => $topic,
			'children' => $children,
			'links'    => $links,
		) );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = (int) $request->get_param( 'id' );

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$p}aime_seo_topics WHERE id = %d",
			$id
		) );

		if ( ! $exists ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Topic not found.', 'ai-marketing-expert' ),
			), 404 );
		}

		$data = array( 'updated_at' => current_time( 'mysql', true ) );

		$fields = array(
			'name'              => 'sanitize_text_field',
			'description'       => 'sanitize_textarea_field',
			'topic_type'        => 'sanitize_key',
			'status'            => 'sanitize_key',
			'notes'             => 'sanitize_textarea_field',
		);

		foreach ( $fields as $field => $sanitizer ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$data[ $field ] = $sanitizer( $value );
			}
		}

		$int_fields = array( 'parent_id', 'target_keyword_id', 'wp_post_id', 'word_count_target', 'priority' );
		foreach ( $int_fields as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$data[ $field ] = absint( $value );
			}
		}

		$brief = $request->get_param( 'content_brief' );
		if ( $brief !== null ) {
			$data['content_brief'] = wp_kses_post( $brief );
		}

		$wpdb->update( "{$p}aime_seo_topics", $data, array( 'id' => $id ) );

		$topic = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}aime_seo_topics WHERE id = %d",
			$id
		) );

		return new \WP_REST_Response( array( 'success' => true, 'data' => $topic ) );
	}

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = (int) $request->get_param( 'id' );

		$deleted = $wpdb->delete( "{$p}aime_seo_topics", array( 'id' => $id ) );

		if ( ! $deleted ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Topic not found or already deleted.', 'ai-marketing-expert' ),
			), 404 );
		}

		// Clean up links.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$p}aime_seo_topic_links WHERE source_topic_id = %d OR target_topic_id = %d",
			$id,
			$id
		) );

		return new \WP_REST_Response( array( 'success' => true ) );
	}

	/* ── AI topical authority map generation (Pro) ────────── */

	public function generate_map( \WP_REST_Request $request ): \WP_REST_Response {
		$max_topics = null;

		if ( ! aime_has_pro() ) {
			$gen_limit = aime_free_limits()['seo_topic_map_generate_monthly'] ?? 1;
			if ( SeoModule::get_monthly_feature_count( 'topic_map_generate' ) >= $gen_limit ) {
				return new \WP_REST_Response( array(
					'success' => false,
					'message' => sprintf(
						/* translators: %d: monthly map generation limit on the free plan */
						__( 'Free plan allows %d AI topic map generation per month. Upgrade to Pro for unlimited generations.', 'ai-marketing-expert' ),
						$gen_limit
					),
					'limit_reached' => true,
				), 403 );
			}

			// Generated topics count against the same storage limit as manual ones.
			$max_topics = aime_limit_remaining( 'seo_topics', SeoModule::get_topic_count() );
			if ( ! $max_topics ) {
				return new \WP_REST_Response( array(
					'success' => false,
					'message' => sprintf(
						/* translators: %d: topic limit on the free plan */
						__( 'You have reached the free plan limit of %d topics. Delete some topics or upgrade to Pro before generating a map.', 'ai-marketing-expert' ),
						aime_free_limits()['seo_topics'] ?? 10
					),
					'limit_reached' => true,
				), 403 );
			}
		}

		$niche   = sanitize_text_field( $request->get_param( 'niche' ) ?? '' );
		$pillar  = sanitize_text_field( $request->get_param( 'pillar_topic' ) ?? '' );

		if ( empty( $niche ) && empty( $pillar ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Niche or pillar topic is required.', 'ai-marketing-expert' ),
			), 400 );
		}

		$service = new TopicalAuthorityService();
		$result  = $service->generate_topical_map( $niche, $pillar, $max_topics );

		if ( $result['success'] && ! aime_has_pro() ) {
			SeoModule::increment_monthly_feature( 'topic_map_generate' );
		}

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Topic usage counts and free limits.
	 */
	public function topic_usage(): \WP_REST_Response {
		return new \WP_REST_Response( array(
			'success' => true,
			'is_pro'  => aime_has_pro(),
			'usage'   => array(
				'topics'       => aime_usage_payload( 'seo_topics', SeoModule::get_topic_count() ),
				'generate_map' => aime_usage_payload( 'seo_topic_map_generate_monthly', SeoModule::get_monthly_feature_count( 'topic_map_generate' ) ),
			),
		) );
	}

	/* ── Topic Links CRUD ────────────────────────────────── */

	public function get_links( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		$topic_id = absint( $request->get_param( 'topic_id' ) );

		if ( $topic_id ) {
			$links = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$p}aime_seo_topic_links WHERE source_topic_id = %d OR target_topic_id = %d ORDER BY created_at DESC",
				$topic_id,
				$topic_id
			) );
		} else {
			$links = $wpdb->get_results(
				"SELECT * FROM {$p}aime_seo_topic_links ORDER BY created_at DESC LIMIT 200"
			);
		}

		return new \WP_REST_Response( array( 'items' => $links ) );
	}

	public function store_link( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		$data = array(
			'source_topic_id' => absint( $request->get_param( 'source_topic_id' ) ),
			'target_topic_id' => absint( $request->get_param( 'target_topic_id' ) ),
			'link_type'       => sanitize_key( $request->get_param( 'link_type' ) ?? 'internal_link' ),
			'anchor_text'     => sanitize_text_field( $request->get_param( 'anchor_text' ) ?? '' ),
			'status'          => sanitize_key( $request->get_param( 'status' ) ?? 'planned' ),
		);

		if ( ! $data['source_topic_id'] || ! $data['target_topic_id'] ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Source and target topic IDs are required.', 'ai-marketing-expert' ),
			), 400 );
		}

		$wpdb->insert( "{$p}aime_seo_topic_links", $data );

		return new \WP_REST_Response( array(
			'success' => true,
			'data'    => $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$p}aime_seo_topic_links WHERE id = %d",
				$wpdb->insert_id
			) ),
		), 201 );
	}

	public function destroy_link( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = (int) $request->get_param( 'id' );

		$deleted = $wpdb->delete( "{$p}aime_seo_topic_links", array( 'id' => $id ) );

		if ( ! $deleted ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Link not found.', 'ai-marketing-expert' ),
			), 404 );
		}

		return new \WP_REST_Response( array( 'success' => true ) );
	}
}
