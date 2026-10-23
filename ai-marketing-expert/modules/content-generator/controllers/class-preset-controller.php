<?php
/**
 * Preset Controller — CRUD for generation presets.
 *
 * @package WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers;

use WPSpace\AiMarketingExpert\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PresetController {

	/* ── LIST presets ────────────────────────────────── */

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p             = $wpdb->prefix;
		$presets_table = $p . 'aime_content_presets';

		$presets = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY is_default DESC, name ASC', $presets_table ) );

		return new \WP_REST_Response( $presets ?: array() );
	}

	/* ── CREATE preset ───────────────────────────────── */

	public function store( \WP_REST_Request $request ): \WP_REST_Response {
		// Custom presets are PRO.
		$check = Pro::gate( 'Custom Presets' );
		if ( is_wp_error( $check ) ) {
			return new \WP_REST_Response( array(
				'message'      => $check->get_error_message(),
				'pro_required' => true,
			), 403 );
		}

		global $wpdb;
		$p             = $wpdb->prefix;
		$presets_table = $p . 'aime_content_presets';
		$now           = current_time( 'mysql', true );

		$data = array(
			'name'                => sanitize_text_field( $request->get_param( 'name' ) ),
			'description'         => sanitize_textarea_field( $request->get_param( 'description' ) ?: '' ),
			'tone'                => sanitize_text_field( $request->get_param( 'tone' ) ?: 'professional' ),
			'style'               => sanitize_text_field( $request->get_param( 'style' ) ?: 'blog_post' ),
			'language'            => sanitize_text_field( $request->get_param( 'language' ) ?: 'en' ),
			'word_count'          => absint( $request->get_param( 'word_count' ) ?: 1000 ),
			'prompt_template'     => sanitize_textarea_field( $request->get_param( 'prompt_template' ) ?: '' ),
			'system_instructions' => sanitize_textarea_field( $request->get_param( 'system_instructions' ) ?: '' ),
			'default_category_id' => absint( $request->get_param( 'default_category_id' ) ) ?: null,
			'is_default'          => 0,
			'is_pro'              => 1,
			'created_at'          => $now,
			'updated_at'          => $now,
		);

		$result = $wpdb->insert( $presets_table, $data );

		if ( false === $result ) {
			return new \WP_REST_Response( array( 'message' => __( 'Failed to create preset.', 'ai-marketing-expert' ) ), 500 );
		}

		return new \WP_REST_Response( array(
			'id'      => (int) $wpdb->insert_id,
			'message' => __( 'Preset created.', 'ai-marketing-expert' ),
		), 201 );
	}

	/* ── UPDATE preset ───────────────────────────────── */

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p             = $wpdb->prefix;
		$presets_table = $p . 'aime_content_presets';
		$id            = absint( $request->get_param( 'id' ) );

		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $presets_table, $id ) );
		if ( ! $existing ) {
			return new \WP_REST_Response( array( 'message' => __( 'Preset not found.', 'ai-marketing-expert' ) ), 404 );
		}

		// Editing non-default presets requires PRO.
		if ( ! $existing->is_default ) {
			$check = Pro::gate( 'Custom Presets' );
			if ( is_wp_error( $check ) ) {
				return new \WP_REST_Response( array( 'message' => $check->get_error_message(), 'pro_required' => true ), 403 );
			}
		}

		$data = array( 'updated_at' => current_time( 'mysql', true ) );

		$text_fields = array( 'name', 'tone', 'style', 'language' );
		foreach ( $text_fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = sanitize_text_field( $request->get_param( $field ) );
			}
		}

		$textarea_fields = array( 'description', 'prompt_template', 'system_instructions' );
		foreach ( $textarea_fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = sanitize_textarea_field( $request->get_param( $field ) );
			}
		}

		if ( $request->has_param( 'word_count' ) ) {
			$data['word_count'] = absint( $request->get_param( 'word_count' ) );
		}

		if ( $request->has_param( 'default_category_id' ) ) {
			$data['default_category_id'] = absint( $request->get_param( 'default_category_id' ) ) ?: null;
		}

		$wpdb->update( $presets_table, $data, array( 'id' => $id ) );

		return new \WP_REST_Response( array( 'message' => __( 'Preset updated.', 'ai-marketing-expert' ) ) );
	}

	/* ── DELETE preset ───────────────────────────────── */

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p             = $wpdb->prefix;
		$presets_table = $p . 'aime_content_presets';
		$id            = absint( $request->get_param( 'id' ) );

		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT is_default FROM %i WHERE id = %d', $presets_table, $id ) );
		if ( ! $existing ) {
			return new \WP_REST_Response( array( 'message' => __( 'Preset not found.', 'ai-marketing-expert' ) ), 404 );
		}

		// Prevent deletion of default presets.
		if ( $existing->is_default ) {
			return new \WP_REST_Response( array( 'message' => __( 'Cannot delete default presets.', 'ai-marketing-expert' ) ), 400 );
		}

		$wpdb->delete( $presets_table, array( 'id' => $id ) );

		return new \WP_REST_Response( array( 'message' => __( 'Preset deleted.', 'ai-marketing-expert' ) ) );
	}
}
