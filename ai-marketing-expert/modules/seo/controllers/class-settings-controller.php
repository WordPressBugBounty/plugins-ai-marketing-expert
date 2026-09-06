<?php
/**
 * SEO Module — Settings Controller.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsController {

	const OPTION_KEY = 'aime_seo_settings';

	const DEFAULTS = array(
		'default_language'   => 'en',
		'default_country'    => 'US',
		'site_niche'         => '',
		'site_domain'        => '',
		'rank_check_engine'  => 'google',
		// Kept in the schema so previously stored values still round-trip, but
		// deliberately absent from $text_fields below: the SERP provider UI is
		// commented out until an integration exists, so nothing may write these.
		// See src/components/modules/Seo/views/SeoSettings.jsx for the full note.
		'serp_provider'      => '',
		'serp_api_key'       => '',
		'search_console_property' => '',
		'enable_schema_suggestions' => false,
		'auto_rank_check'    => false,
		'audit_auto_meta'    => true,
		'content_types'      => array( 'blog_post', 'pillar_page', 'listicle', 'how_to', 'comparison' ),
	);

	public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$settings = get_option( self::OPTION_KEY, array() );
		$settings = wp_parse_args( $settings, self::DEFAULTS );

		return new \WP_REST_Response( array(
			'success'  => true,
			'data'     => $settings,
			'is_pro'   => aime_has_pro(),
		) );
	}

	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$current  = get_option( self::OPTION_KEY, array() );
		$settings = wp_parse_args( $current, self::DEFAULTS );

		// 'serp_provider' and 'serp_api_key' are intentionally omitted — the UI
		// that set them is commented out, so accepting them here would let a
		// crafted request store a credential the plugin never uses. Re-add both
		// when the SERP provider integration ships.
		$text_fields = array( 'default_language', 'default_country', 'site_niche', 'site_domain', 'rank_check_engine', 'search_console_property' );
		foreach ( $text_fields as $field ) {
			$val = $request->get_param( $field );
			if ( $val !== null ) {
				$settings[ $field ] = sanitize_text_field( $val );
			}
		}

		$bool_fields = array( 'auto_rank_check', 'audit_auto_meta', 'enable_schema_suggestions' );
		foreach ( $bool_fields as $field ) {
			$val = $request->get_param( $field );
			if ( $val !== null ) {
				$settings[ $field ] = (bool) $val;
			}
		}

		$types = $request->get_param( 'content_types' );
		if ( is_array( $types ) ) {
			$settings['content_types'] = array_map( 'sanitize_key', $types );
		}

		update_option( self::OPTION_KEY, $settings, false );
		aime_clear_settings_cache( array( self::OPTION_KEY ) );

		return new \WP_REST_Response( array(
			'success' => true,
			'data'    => $settings,
		) );
	}
}
