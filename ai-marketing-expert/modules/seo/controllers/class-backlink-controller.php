<?php
/**
 * SEO Module — Backlink Controller (Link building pipeline CRUD + AI outreach).
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Controllers;

use WPSpace\AiMarketingExpert\Modules\Seo\Services\LinkBuildingService;
use WPSpace\AiMarketingExpert\Modules\Seo\SeoModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BacklinkController {

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$status   = sanitize_key( $request->get_param( 'status' ) ?? '' );
		$type     = sanitize_key( $request->get_param( 'link_type' ) ?? '' );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array( '1=1' );
		$values = array();

		if ( $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}
		if ( $type ) {
			$where[]  = 'link_type = %s';
			$values[] = $type;
		}

		$where_sql = implode( ' AND ', $where );

		if ( ! empty( $values ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}aime_seo_backlinks WHERE {$where_sql}",
				...$values
			) );
			$items = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$p}aime_seo_backlinks WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...array_merge( $values, array( $per_page, $offset ) )
			) );
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_seo_backlinks" );
			$items = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$p}aime_seo_backlinks ORDER BY created_at DESC LIMIT %d OFFSET %d",
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
		if ( aime_limit_reached( 'seo_backlink_prospects', SeoModule::get_backlink_count() ) ) {
			$limit = aime_free_limits()['seo_backlink_prospects'] ?? 10;
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => sprintf(
					/* translators: %d: backlink prospect limit on the free plan */
					__( 'Free plan allows %d link prospects. Delete one or upgrade to Pro for an unlimited pipeline.', 'ai-marketing-expert' ),
					$limit
				),
				'limit_reached' => true,
			), 403 );
		}

		global $wpdb;
		$p = $wpdb->prefix;

		$data = array(
			'source_url'        => esc_url_raw( $request->get_param( 'source_url' ) ?? '' ),
			'target_url'        => esc_url_raw( $request->get_param( 'target_url' ) ?? '' ),
			'anchor_text'       => sanitize_text_field( $request->get_param( 'anchor_text' ) ?? '' ),
			'rel_type'          => sanitize_key( $request->get_param( 'rel_type' ) ?? 'dofollow' ),
			'status'            => sanitize_key( $request->get_param( 'status' ) ?? 'prospect' ),
			'domain_authority'  => min( 100, absint( $request->get_param( 'domain_authority' ) ?? 0 ) ),
			'contact_email'     => sanitize_email( $request->get_param( 'contact_email' ) ?? '' ),
			'contact_name'      => sanitize_text_field( $request->get_param( 'contact_name' ) ?? '' ),
			'link_type'         => sanitize_key( $request->get_param( 'link_type' ) ?? 'guest_post' ),
			'response_notes'    => sanitize_textarea_field( $request->get_param( 'response_notes' ) ?? '' ),
		);

		if ( empty( $data['source_url'] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Source URL is required.', 'ai-marketing-expert' ),
			), 400 );
		}

		$wpdb->insert( "{$p}aime_seo_backlinks", $data );
		$id = $wpdb->insert_id;

		if ( ! $id ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Failed to create backlink entry.', 'ai-marketing-expert' ),
			), 500 );
		}

		$item = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}aime_seo_backlinks WHERE id = %d",
			$id
		) );

		return new \WP_REST_Response( array( 'success' => true, 'data' => $item ), 201 );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = (int) $request->get_param( 'id' );

		$item = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}aime_seo_backlinks WHERE id = %d",
			$id
		) );

		if ( ! $item ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Backlink not found.', 'ai-marketing-expert' ),
			), 404 );
		}

		return new \WP_REST_Response( array( 'success' => true, 'data' => $item ) );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = (int) $request->get_param( 'id' );

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$p}aime_seo_backlinks WHERE id = %d",
			$id
		) );

		if ( ! $exists ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Backlink not found.', 'ai-marketing-expert' ),
			), 404 );
		}

		$data = array( 'updated_at' => current_time( 'mysql', true ) );

		$text_fields = array(
			'anchor_text'    => 'sanitize_text_field',
			'rel_type'       => 'sanitize_key',
			'status'         => 'sanitize_key',
			'contact_name'   => 'sanitize_text_field',
			'link_type'      => 'sanitize_key',
			'response_notes' => 'sanitize_textarea_field',
		);

		foreach ( $text_fields as $field => $sanitizer ) {
			$val = $request->get_param( $field );
			if ( $val !== null ) {
				$data[ $field ] = $sanitizer( $val );
			}
		}

		$url_fields = array( 'source_url', 'target_url' );
		foreach ( $url_fields as $field ) {
			$val = $request->get_param( $field );
			if ( $val !== null ) {
				$data[ $field ] = esc_url_raw( $val );
			}
		}

		$email = $request->get_param( 'contact_email' );
		if ( $email !== null ) {
			$data['contact_email'] = sanitize_email( $email );
		}

		$da = $request->get_param( 'domain_authority' );
		if ( $da !== null ) {
			$data['domain_authority'] = min( 100, absint( $da ) );
		}

		$template = $request->get_param( 'outreach_template' );
		if ( $template !== null ) {
			$data['outreach_template'] = wp_kses_post( $template );
		}

		$acquired = $request->get_param( 'acquired_at' );
		if ( $acquired !== null ) {
			$data['acquired_at'] = sanitize_text_field( $acquired ) ?: null;
		}

		$wpdb->update( "{$p}aime_seo_backlinks", $data, array( 'id' => $id ) );

		$item = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}aime_seo_backlinks WHERE id = %d",
			$id
		) );

		return new \WP_REST_Response( array( 'success' => true, 'data' => $item ) );
	}

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = (int) $request->get_param( 'id' );

		$deleted = $wpdb->delete( "{$p}aime_seo_backlinks", array( 'id' => $id ) );

		if ( ! $deleted ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Backlink not found or already deleted.', 'ai-marketing-expert' ),
			), 404 );
		}

		return new \WP_REST_Response( array( 'success' => true ) );
	}

	/**
	 * AI-generate outreach email. Free plan is metered.
	 */
	public function generate_outreach( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			$limit = aime_free_limits()['seo_outreach_monthly'] ?? 3;
			if ( SeoModule::get_monthly_feature_count( 'outreach_generate' ) >= $limit ) {
				return new \WP_REST_Response( array(
					'success' => false,
					'message' => sprintf(
						/* translators: %d: monthly outreach generation limit on the free plan */
						__( 'Free plan allows %d AI outreach emails per month. Upgrade to Pro for unlimited outreach.', 'ai-marketing-expert' ),
						$limit
					),
					'limit_reached' => true,
				), 403 );
			}
		}

		$backlink_id = absint( $request->get_param( 'backlink_id' ) );
		$context     = sanitize_textarea_field( $request->get_param( 'context' ) ?? '' );

		if ( ! $backlink_id ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Backlink ID is required.', 'ai-marketing-expert' ),
			), 400 );
		}

		$service = new LinkBuildingService();
		$result  = $service->generate_outreach_email( $backlink_id, $context );

		if ( $result['success'] && ! aime_has_pro() ) {
			SeoModule::increment_monthly_feature( 'outreach_generate' );
		}

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Link building usage counts and free limits.
	 */
	public function backlink_usage(): \WP_REST_Response {
		return new \WP_REST_Response( array(
			'success' => true,
			'is_pro'  => aime_has_pro(),
			'usage'   => array(
				'prospects' => aime_usage_payload( 'seo_backlink_prospects', SeoModule::get_backlink_count() ),
				'outreach'  => aime_usage_payload( 'seo_outreach_monthly', SeoModule::get_monthly_feature_count( 'outreach_generate' ) ),
			),
		) );
	}
}
