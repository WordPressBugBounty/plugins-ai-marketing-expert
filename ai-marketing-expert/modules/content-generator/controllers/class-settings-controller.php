<?php
/**
 * Settings Controller — Content Generator module settings & WP taxonomy helper.
 *
 * @package WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsController {

	private const OPTION_KEY = 'aime_content-generator_settings';

	private const DEFAULTS = array(
		'default_tone'          => 'professional',
		'default_language'      => 'en',
		'default_word_count'    => 1000,
		'default_category_id'   => 0,
		'default_post_type'     => 'post',
		'default_post_status'   => 'publish',
		'auto_seo_optimize'     => true,
		'auto_generate_meta'    => true,
		'auto_generate_excerpt' => true,
		'stock_provider'        => 'pexels',
		'inline_images'         => 0,
		'inline_image_size'     => 'large',
	);

	/* ── GET settings ────────────────────────────────── */

	public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$settings = get_option( self::OPTION_KEY, array() );
		$merged   = array_merge( self::DEFAULTS, $settings );

		// Never expose stock API keys (stored encrypted) — only report presence.
		$merged['has_pexels_key']  = ! empty( $merged['pexels_api_key'] );
		$merged['has_pixabay_key'] = ! empty( $merged['pixabay_api_key'] );
		unset( $merged['pexels_api_key'], $merged['pixabay_api_key'] );

		if ( ! aime_has_pro() ) {
			$merged['auto_seo_optimize']     = false;
			$merged['auto_generate_meta']    = false;
			$merged['auto_generate_excerpt'] = false;
		}

		return new \WP_REST_Response( $merged );
	}

	/* ── SAVE settings ───────────────────────────────── */

	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$current = get_option( self::OPTION_KEY, array() );
		$params  = $request->get_json_params();

		$text_fields = array( 'default_tone', 'default_language', 'default_post_type', 'default_post_status' );
		foreach ( $text_fields as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$current[ $field ] = sanitize_text_field( $params[ $field ] );
			}
		}

		if ( isset( $current['default_post_status'] ) && ! in_array( $current['default_post_status'], array( 'draft', 'publish', 'pending', 'private' ), true ) ) {
			$current['default_post_status'] = self::DEFAULTS['default_post_status'];
		}

		if ( isset( $current['default_post_type'] ) && ! post_type_exists( $current['default_post_type'] ) ) {
			$current['default_post_type'] = self::DEFAULTS['default_post_type'];
		}

		if ( isset( $params['default_word_count'] ) ) {
			$current['default_word_count'] = absint( $params['default_word_count'] );
		}

		if ( isset( $params['default_category_id'] ) ) {
			$current['default_category_id'] = absint( $params['default_category_id'] );
		}

		// Image settings. The old image_source select is gone from the UI —
		// stale stored values are simply ignored on read.
		if ( isset( $params['stock_provider'] ) ) {
			$provider = sanitize_key( $params['stock_provider'] );
			$current['stock_provider'] = in_array( $provider, \WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services\StockImageService::PROVIDERS, true ) ? $provider : 'pexels';
		}

		if ( isset( $params['inline_images'] ) ) {
			$current['inline_images'] = min( 3, absint( $params['inline_images'] ) );
		}

		if ( isset( $params['inline_image_size'] ) ) {
			$size = sanitize_key( $params['inline_image_size'] );
			$current['inline_image_size'] = in_array( $size, array( 'medium', 'medium_large', 'large', 'full' ), true ) ? $size : 'large';
		}

		// Stock API keys: stored encrypted, never exported, never echoed back.
		// Empty string clears the key; omitted param leaves it untouched.
		foreach ( array( 'pexels_api_key', 'pixabay_api_key' ) as $key_field ) {
			if ( ! isset( $params[ $key_field ] ) || ! is_string( $params[ $key_field ] ) ) {
				continue;
			}
			$raw = trim( sanitize_text_field( $params[ $key_field ] ) );
			if ( '' === $raw ) {
				unset( $current[ $key_field ] );
			} else {
				$current[ $key_field ] = \WPSpace\AiMarketingExpert\Encryption::encrypt( $raw );
			}
		}

		$bool_fields = array( 'auto_seo_optimize', 'auto_generate_meta', 'auto_generate_excerpt' );
		foreach ( $bool_fields as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$current[ $field ] = aime_has_pro() ? (bool) $params[ $field ] : false;
			}
		}

		if ( ! aime_has_pro() ) {
			$current['auto_seo_optimize']     = false;
			$current['auto_generate_meta']    = false;
			$current['auto_generate_excerpt'] = false;
		}

		update_option( self::OPTION_KEY, $current, false );
		aime_clear_settings_cache( array( self::OPTION_KEY ) );

		$response = array_merge( self::DEFAULTS, $current );
		$response['has_pexels_key']  = ! empty( $response['pexels_api_key'] );
		$response['has_pixabay_key'] = ! empty( $response['pixabay_api_key'] );
		unset( $response['pexels_api_key'], $response['pixabay_api_key'] );

		return new \WP_REST_Response( array(
			'message'  => __( 'Settings saved.', 'ai-marketing-expert' ),
			'settings' => $response,
		) );
	}

	/* ── WP Taxonomies helper ────────────────────────── */

	public function get_wp_taxonomies( \WP_REST_Request $request ): \WP_REST_Response {
		// Categories.
		$categories = get_categories( array(
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		) );

		$cats = array();
		foreach ( $categories as $cat ) {
			$cats[] = array(
				'id'     => $cat->term_id,
				'name'   => $cat->name,
				'slug'   => $cat->slug,
				'parent' => $cat->parent,
				'count'  => $cat->count,
			);
		}

		// Tags.
		$tags_list = get_tags( array(
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		) );

		$tags = array();
		if ( $tags_list && ! is_wp_error( $tags_list ) ) {
			foreach ( $tags_list as $tag ) {
				$tags[] = array(
					'id'    => $tag->term_id,
					'name'  => $tag->name,
					'slug'  => $tag->slug,
					'count' => $tag->count,
				);
			}
		}

		// Post types.
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$types      = array();
		foreach ( $post_types as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			$types[] = array(
				'name'  => $pt->name,
				'label' => $pt->label,
			);
		}

		return new \WP_REST_Response( array(
			'categories' => $cats,
			'tags'       => $tags,
			'post_types' => $types,
		) );
	}
}
