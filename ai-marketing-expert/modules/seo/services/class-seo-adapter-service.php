<?php
/**
 * SEO Adapter — canonical store + multi-plugin sync.
 *
 * Strategy for 100+ SEO plugins without hardcoding each one:
 *  1. Always save to our canonical keys (survives plugin switches).
 *  2. Sync to detected major plugins (Yoast, RankMath, AIOSEO, SEOPress,
 *     Slim SEO, The SEO Framework) via their native postmeta keys.
 *  3. Fire `aime_seo_sync` action + `aime_seo_adapter` filter so any other
 *     plugin can hook without core changes.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoAdapterService {

	/**
	 * Canonical meta keys (always written).
	 */
	const META_KEYWORD = 'aime_seo_keyword';
	const META_TITLE   = '_aime_seo_meta_title';
	const META_DESC    = '_aime_seo_meta_description';

	/**
	 * Save canonical SEO package and sync to active SEO plugins.
	 *
	 * @param int    $post_id         WP post ID.
	 * @param string $focus_keyword   Primary keyword.
	 * @param string $meta_title      SEO title (<=60 chars ideally).
	 * @param string $meta_desc       Meta description (<=160 chars ideally).
	 */
	public static function sync( int $post_id, string $focus_keyword = '', string $meta_title = '', string $meta_desc = '' ): void {
		if ( $post_id <= 0 || 'revision' === get_post_type( $post_id ) ) {
			return;
		}
		$focus_keyword = sanitize_text_field( $focus_keyword );
		$meta_title    = sanitize_text_field( $meta_title );
		$meta_desc     = sanitize_textarea_field( $meta_desc );

		if ( '' !== $focus_keyword ) {
			update_post_meta( $post_id, self::META_KEYWORD, $focus_keyword );
		}
		if ( '' !== $meta_title ) {
			update_post_meta( $post_id, self::META_TITLE, $meta_title );
		}
		if ( '' !== $meta_desc ) {
			update_post_meta( $post_id, self::META_DESC, $meta_desc );
		}

		$package = array(
			'focus_keyword'    => $focus_keyword,
			'meta_title'       => $meta_title,
			'meta_description' => $meta_desc,
		);

		// Allow third parties to remap keys for niche SEO plugins.
		$package = apply_filters( 'aime_seo_adapter', $package, $post_id );
		if ( ! is_array( $package ) ) {
			return;
		}
		$focus_keyword = sanitize_text_field( (string) ( $package['focus_keyword'] ?? '' ) );
		$meta_title    = sanitize_text_field( (string) ( $package['meta_title'] ?? '' ) );
		$meta_desc     = sanitize_textarea_field( (string) ( $package['meta_description'] ?? '' ) );

		// Yoast SEO.
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Meta' ) ) {
			if ( '' !== $meta_title ) {
				update_post_meta( $post_id, '_yoast_wpseo_title', $meta_title );
			}
			if ( '' !== $meta_desc ) {
				update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
			}
			if ( '' !== $focus_keyword ) {
				update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus_keyword );
			}
		}

		// Rank Math.
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			if ( '' !== $meta_title ) {
				update_post_meta( $post_id, 'rank_math_title', $meta_title );
			}
			if ( '' !== $meta_desc ) {
				update_post_meta( $post_id, 'rank_math_description', $meta_desc );
			}
			if ( '' !== $focus_keyword ) {
				update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_keyword );
			}
		}

		// All in One SEO.
		if ( defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO\\Plugin\\AIOSEO' ) ) {
			if ( '' !== $meta_title ) {
				update_post_meta( $post_id, '_aioseo_title', $meta_title );
			}
			if ( '' !== $meta_desc ) {
				update_post_meta( $post_id, '_aioseo_description', $meta_desc );
			}
			if ( '' !== $focus_keyword ) {
				update_post_meta( $post_id, '_aioseo_keywords', $focus_keyword );
			}
		}

		// SEOPress.
		if ( defined( 'SEOPRESS_VERSION' ) || class_exists( 'SEOPress' ) ) {
			if ( '' !== $meta_title ) {
				update_post_meta( $post_id, '_seopress_titles_title', $meta_title );
			}
			if ( '' !== $meta_desc ) {
				update_post_meta( $post_id, '_seopress_titles_desc', $meta_desc );
			}
			if ( '' !== $focus_keyword ) {
				update_post_meta( $post_id, '_seopress_analysis_target_kw', $focus_keyword );
			}
		}

		// Slim SEO (+ its meta keys are prefixed with slim_seo_).
		if ( defined( 'SLIM_SEO_VER' ) || class_exists( 'SlimSEO\\Plugin' ) ) {
			if ( '' !== $meta_title ) {
				update_post_meta( $post_id, 'slim_seo_title', $meta_title );
			}
			if ( '' !== $meta_desc ) {
				update_post_meta( $post_id, 'slim_seo_description', $meta_desc );
			}
		}

		// The SEO Framework.
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) || class_exists( 'The_SEO_Framework\\Load' ) ) {
			if ( '' !== $meta_title ) {
				update_post_meta( $post_id, '_genesis_title', $meta_title );
				update_post_meta( $post_id, '_tsf_title_no_blogname', $meta_title );
			}
			if ( '' !== $meta_desc ) {
				update_post_meta( $post_id, '_genesis_description', $meta_desc );
				update_post_meta( $post_id, '_tsf_description', $meta_desc );
			}
		}

		/**
		 * Fires after canonical + major-plugin sync.
		 * Niche SEO plugins hook here to map $package to their own keys.
		 *
		 * @param int   $post_id WP post ID.
		 * @param array $package focus_keyword, meta_title, meta_description.
		 */
		do_action( 'aime_seo_sync', $post_id, $package );
	}

	/**
	 * Read back the effective focus keyword (canonical first, then plugins).
	 */
	public static function get_focus_keyword( int $post_id ): string {
		$kw = (string) get_post_meta( $post_id, self::META_KEYWORD, true );
		if ( '' !== $kw ) {
			return $kw;
		}
		foreach ( array( '_yoast_wpseo_focuskw', 'rank_math_focus_keyword', '_aioseo_keywords', '_seopress_analysis_target_kw' ) as $key ) {
			$v = (string) get_post_meta( $post_id, $key, true );
			if ( '' !== $v ) {
				return $v;
			}
		}
		return '';
	}

	/**
	 * Whether any known SEO plugin is active (frontend output suppresses itself).
	 */
	public static function has_seo_plugin(): bool {
		if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'SLIM_SEO_VER' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			return true;
		}
		if ( class_exists( 'WPSEO_Meta' ) || class_exists( 'RankMath' ) || class_exists( 'SEOPress' ) ) {
			return true;
		}
		// has_action check avoids false negatives when constants are filtered.
		if ( has_action( 'wpseo_head' ) || has_action( 'rank_math/head' ) ) {
			return true;
		}
		return false;
	}
}
