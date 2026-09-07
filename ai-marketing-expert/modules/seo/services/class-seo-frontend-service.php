<?php
/**
 * SEO Frontend — minimal head output when no SEO plugin is active.
 *
 * Renders title, meta description, canonical and Open Graph tags from the
 * canonical aime keys. Self-disables when any known SEO plugin is detected
 * (see SeoAdapterService::has_seo_plugin) to prevent duplicate tags.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoFrontendService {

	/**
	 * Register rendering hook. Called from SeoModule::init().
	 */
	public function init(): void {
		add_action( 'wp_head', array( $this, 'render' ), 1 );
	}

	/**
	 * Output head tags for singular posts/pages when appropriate.
	 */
	public function render(): void {
		if ( is_admin() || is_feed() || ! is_singular() ) {
			return;
		}
		if ( SeoAdapterService::has_seo_plugin() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( $post_id <= 0 ) {
			return;
		}
		$title = sanitize_text_field( (string) get_post_meta( $post_id, SeoAdapterService::META_TITLE, true ) );
		$desc  = sanitize_textarea_field( (string) get_post_meta( $post_id, SeoAdapterService::META_DESC, true ) );
		if ( '' === $title ) {
			$title = sanitize_text_field( get_the_title( $post_id ) );
		}
		if ( '' === $desc ) {
			$desc = sanitize_textarea_field( get_post_field( 'post_excerpt', $post_id ) );
		}
		if ( '' === $title && '' === $desc ) {
			return;
		}
		$canonical = esc_url( get_permalink( $post_id ) );

		if ( '' !== $desc ) {
			echo '<meta name="description" content="' . esc_attr( mb_substr( $desc, 0, 160 ) ) . "\">\n";
		}
		echo '<link rel="canonical" href="' . $canonical . "\">\n";
		echo '<meta property="og:type" content="article">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( mb_substr( $title, 0, 60 ) ) . "\">\n";
		if ( '' !== $desc ) {
			echo '<meta property="og:description" content="' . esc_attr( mb_substr( $desc, 0, 160 ) ) . "\">\n";
		}
		echo '<meta property="og:url" content="' . $canonical . "\">\n";
		echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
		// Title tag is left to the theme (title-tag support); OG covers sharing.
	}
}
