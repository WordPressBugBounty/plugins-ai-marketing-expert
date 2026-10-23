<?php
/**
 * SEO Module — Audit Controller (On-page SEO audits).
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Controllers;

use WPSpace\AiMarketingExpert\Modules\Seo\SeoModule;
use WPSpace\AiMarketingExpert\Modules\Seo\Services\OnPageSeoService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AuditController {

	/**
	 * List public post types available for audits.
	 */
	public function post_types( \WP_REST_Request $request ): \WP_REST_Response {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$items      = array();

		foreach ( $post_types as $post_type ) {
			if ( 'attachment' === $post_type->name ) {
				continue;
			}

			$items[] = array(
				'name'  => $post_type->name,
				'label' => $post_type->label,
			);
		}

		return new \WP_REST_Response( array(
			'success' => true,
			'items'   => $items,
		) );
	}

	/**
	 * List posts for a selected post type.
	 */
	public function posts( \WP_REST_Request $request ): \WP_REST_Response {
		$post_type = sanitize_key( $request->get_param( 'post_type' ) ?: 'post' );

		if ( ! post_type_exists( $post_type ) || 'attachment' === $post_type ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Invalid post type.', 'ai-marketing-expert' ),
			), 400 );
		}

		$query = new \WP_Query( array(
			'post_type'      => $post_type,
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => 500,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'        => $post->ID,
				'title'     => get_the_title( $post ),
				'status'    => $post->post_status,
				'url'       => get_permalink( $post ),
				'modified'  => $post->post_modified_gmt,
			);
		}

		return new \WP_REST_Response( array(
			'success' => true,
			'items'   => $items,
		) );
	}

	/**
	 * List audits with pagination.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_seo_audits" );
		$items = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, wp_post_id, url, title, overall_score, keyword_focus, issues_count, warnings_count, passed_count, created_at FROM {$p}aime_seo_audits ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		) );

		return new \WP_REST_Response( array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		) );
	}

	/**
	 * Run a new on-page SEO audit.
	 */
	public function run_audit( \WP_REST_Request $request ): \WP_REST_Response {
		// Free limit check.
		if ( ! aime_has_pro() ) {
			$limit = aime_free_limits()['seo_audits_monthly'] ?? 5;
			if ( SeoModule::get_monthly_audit_count() >= $limit ) {
				return new \WP_REST_Response( array(
					'success' => false,
					'message' => sprintf(
						/* translators: %d: monthly SEO audit limit */
						__( 'Free plan allows %d audits per month. Upgrade to Pro for unlimited.', 'ai-marketing-expert' ),
						$limit
					),
				), 403 );
			}
		}

		$wp_post_id    = absint( $request->get_param( 'wp_post_id' ) );
		$url           = esc_url_raw( $request->get_param( 'url' ) ?? '' );
		$keyword_focus = sanitize_text_field( $request->get_param( 'keyword_focus' ) ?? '' );

		if ( ! $wp_post_id && empty( $url ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'A post ID or URL is required.', 'ai-marketing-expert' ),
			), 400 );
		}

		$service = new OnPageSeoService();
		$result  = $service->run_audit( $wp_post_id, $url, $keyword_focus );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Show single audit.
	 */
	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = (int) $request->get_param( 'id' );

		$audit = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}aime_seo_audits WHERE id = %d",
			$id
		) );

		if ( ! $audit ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Audit not found.', 'ai-marketing-expert' ),
			), 404 );
		}

		// Decode JSON fields.
		$audit->results        = json_decode( $audit->results, true );
		$audit->ai_suggestions = json_decode( $audit->ai_suggestions, true );

		return new \WP_REST_Response( array( 'success' => true, 'data' => $audit ) );
	}

	/**
	 * Delete audit.
	 */
	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = (int) $request->get_param( 'id' );

		$deleted = $wpdb->delete( "{$p}aime_seo_audits", array( 'id' => $id ) );

		if ( ! $deleted ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Audit not found or already deleted.', 'ai-marketing-expert' ),
			), 404 );
		}

		return new \WP_REST_Response( array( 'success' => true ) );
	}
}
