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
		add_action( 'wp_head', array( $this, 'render_schema' ), 99 );
		add_action( 'wp_head', array( $this, 'render_styles' ), 50 );
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

	/**
	 * Output JSON-LD Schema (e.g. FAQPage for GEO) for singular posts.
	 */
	public function render_schema(): void {
		if ( is_admin() || is_feed() || ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( $post_id <= 0 ) {
			return;
		}

		$faq_data = get_post_meta( $post_id, '_aime_faq_data', true );
		if ( ! is_array( $faq_data ) || empty( $faq_data ) ) {
			return;
		}

		/**
		 * Filter whether to render the FAQ JSON-LD schema on singular posts.
		 *
		 * @param bool  $render   Whether to render. Default true.
		 * @param int   $post_id  Post ID.
		 * @param array $faq_data Structured FAQ items.
		 */
		if ( ! apply_filters( 'aime_render_faq_schema', true, $post_id, $faq_data ) ) {
			return;
		}

		$main_entity = array();
		foreach ( $faq_data as $item ) {
			$q = sanitize_text_field( (string) ( $item['question'] ?? $item['q'] ?? '' ) );
			$a = sanitize_text_field( (string) ( $item['answer'] ?? $item['a'] ?? '' ) );
			if ( '' !== $q && '' !== $a ) {
				$main_entity[] = array(
					'@type'          => 'Question',
					'name'           => $q,
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $a,
					),
				);
			}
		}

		if ( empty( $main_entity ) ) {
			return;
		}

		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $main_entity,
		);

		// JSON_HEX_TAG prevents '</script>' injection per WordPress VIP / Plugin Review security standards.
		$json = wp_json_encode(
			$schema,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		if ( $json ) {
			echo "\n<!-- AI Marketing Expert FAQ Schema (GEO / AEO) -->\n";
			echo '<script type="application/ld+json">' . $json . "</script>\n";
		}
	}

	/**
	 * Output lightweight, theme-neutral styles for Quick Answer and FAQ Accordions.
	 */
	public function render_styles(): void {
		if ( is_admin() || is_feed() || ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post || empty( $post->post_content ) ) {
			return;
		}

		$has_qa  = false !== strpos( $post->post_content, 'aime-quick-answer' );
		$has_faq = false !== strpos( $post->post_content, 'aime-faq' ) || false !== strpos( $post->post_content, 'wp-block-details' );

		if ( ! $has_qa && ! $has_faq ) {
			return;
		}

		echo "\n<!-- AI Marketing Expert Content & GEO Styles -->\n";
		echo "<style id=\"aime-geo-styles\">\n";
		if ( $has_qa ) {
			echo ".aime-quick-answer{background-color:#f8fafc;border-left:4px solid #2563eb;padding:16px 20px;margin:24px 0;border-radius:6px;color:#1e293b !important;font-size:1.02em;line-height:1.6;box-shadow:0 1px 2px rgba(0,0,0,0.04);}\n";
			echo ".aime-quick-answer strong{color:#0f172a !important;}\n";
			echo ".aime-quick-answer p{color:#1e293b !important;margin:0 0 8px 0;}\n";
			echo ".aime-quick-answer ul{margin:8px 0 0 20px;padding:0;}\n";
			echo ".aime-quick-answer li{color:#334155 !important;margin-bottom:4px;}\n";
		}
		if ( $has_faq ) {
			// Native details/accordion styling
			echo "details.aime-faq-item,details.wp-block-details{background-color:#ffffff;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:12px;padding:14px 18px;box-shadow:0 1px 2px rgba(0,0,0,0.03);transition:background-color 0.2s ease,border-color 0.2s ease;}\n";
			echo "details.aime-faq-item[open],details.wp-block-details[open]{background-color:#f8fafc;border-color:#cbd5e1;}\n";
			echo "details.aime-faq-item summary,details.wp-block-details summary{font-weight:600;font-size:1.05em;color:#1e293b !important;cursor:pointer;outline:none;user-select:none;list-style:none;display:flex;justify-content:space-between;align-items:center;}\n";
			echo "details.aime-faq-item summary::-webkit-details-marker,details.wp-block-details summary::-webkit-details-marker{display:none;}\n";
			echo "details.aime-faq-item summary::after,details.wp-block-details summary::after{content:'+';font-size:1.25em;font-weight:600;color:#64748b;line-height:1;margin-left:12px;}\n";
			echo "details.aime-faq-item[open] summary::after,details.wp-block-details[open] summary::after{content:'\\2212';color:#2563eb;}\n";
			echo "details.aime-faq-item:not([open]) > :not(summary),details.wp-block-details:not([open]) > :not(summary){display:none !important;}\n";
			echo "details.aime-faq-item[open] > :not(summary),details.wp-block-details[open] > :not(summary),details.aime-faq-item[open] .aime-faq-a,details.wp-block-details[open] .aime-faq-a{display:block !important;max-height:none !important;overflow:visible !important;opacity:1 !important;margin-top:12px !important;margin-bottom:4px !important;color:#334155 !important;line-height:1.65 !important;font-size:1em !important;}\n";
			// Fallback styling for classic non-accordion FAQ items
			echo ".aime-faq-q,h3.aime-faq-q{color:#1e293b !important;font-weight:600;font-size:1.15em;margin-top:20px;margin-bottom:8px;}\n";
			echo ".aime-faq-a,p.aime-faq-a{color:#334155 !important;line-height:1.65;margin-bottom:16px;max-height:none !important;overflow:visible !important;display:block !important;}\n";
		}
		echo "</style>\n";
	}
}
