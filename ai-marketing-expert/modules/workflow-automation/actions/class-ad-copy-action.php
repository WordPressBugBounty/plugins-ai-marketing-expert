<?php
/**
 * Ad Copy action — AI ad-copy variations saved as a draft article.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions;

use WPSpace\AiMarketingExpert\AiProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdCopyAction extends BaseAction {

	public static function run( array $config, array $context ): array {
		// Smart product resolution: config → rotation → WooCommerce products → AI Brain
		$product = self::resolve_product( $config, $context );
		if ( '' === $product ) {
			return self::fail( __( 'No product/offer provided for ad copy.', 'ai-marketing-expert' ) );
		}
		$tone       = self::tone( $context );
		$variations = max( 1, min( 10, (int) ( $config['variations'] ?? 3 ) ) );

		// Brand voice + upstream brief keep ad copy consistent with the batch.
		$voice_prompt = self::brand_voice_system_prompt( $context );
		$brief        = self::resolve_from_context( $context, 'full_output', 'ai_brain' );

		$prompt = sprintf(
			"Write %d distinct advertising copy variations for: \"%s\".\nTone: %s.\n" .
			"Each variation: a punchy headline and 1-2 sentence body. Return valid HTML: an <ol> with one <li> per variation (headline in <strong>). Return ONLY the HTML.",
			$variations,
			$product,
			$tone
		);
		if ( '' !== $brief ) {
			$prompt .= "\n\nContent brief from the AI strategist (context only):\n" . mb_substr( $brief, 0, 1000 );
		}
		if ( '' !== $voice_prompt ) {
			$prompt .= "\n\nBrand voice guidelines:\n" . mb_substr( $voice_prompt, 0, 800 );
		}
		$result = AiProvider::generate( $prompt, 'text', 1500 );
		if ( empty( $result['success'] ) ) {
			return self::fail( $result['message'] ?? __( 'Ad copy generation failed.', 'ai-marketing-expert' ) );
		}

		// Model was asked for pure HTML — strip any reasoning prefix before saving.
		$body  = trim( aime_strip_thinking_from_html( (string) $result['content'] ) );
		if ( '' === $body ) {
			return self::fail( __( 'AI returned an empty response.', 'ai-marketing-expert' ) );
		}
		/* translators: %s: product or service name. */
		$title = sprintf( __( 'Ad Copy: %s', 'ai-marketing-expert' ), wp_trim_words( $product, 8, '' ) );

		$article_id = self::save_article( array(
			'title'   => sanitize_text_field( $title ),
			'slug'    => sanitize_title( $title ),
			'content' => wp_kses_post( $body ),
			'topic'   => $product,
			'tone'    => $tone,
		), $result );

		if ( ! $article_id ) {
			return self::fail( __( 'Ad copy generated but could not be saved.', 'ai-marketing-expert' ) );
		}

		return self::ok(
			sprintf( /* translators: %d: count */ __( 'Generated %d ad-copy variations (saved as draft).', 'ai-marketing-expert' ), $variations ),
			array( 'article_id' => $article_id, 'link' => self::module_link( 'content', 'articles' ) )
		);
	}

	/**
	 * Resolve product for ad copy generation.
	 *
	 * Priority:
	 * 1. Manual product field (if not empty)
	 * 2. WooCommerce product rotation (if products selected and WC active)
	 * 3. Manual products rotation (Pro)
	 * 4. AI Brain selected topic
	 * 5. Workflow topic
	 *
	 * @param array $config  Step config.
	 * @param array $context Workflow context.
	 * @return string Product name.
	 */
	private static function resolve_product( array $config, array $context ): string {
		// 1. Manual product field
		$product = trim( (string) ( $config['product'] ?? '' ) );
		if ( '' !== $product ) {
			return $product;
		}

		// 2. WooCommerce product rotation (if WC active and products selected)
		if ( class_exists( 'WooCommerce' ) ) {
			$wc_product_ids = $config['wc_products'] ?? array();
			$wc_product_ids = is_array( $wc_product_ids )
				? array_filter( array_map( 'intval', $wc_product_ids ) )
				: array();

			if ( ! empty( $wc_product_ids ) && aime_has_pro() ) {
				$state_key = (int) ( $context['workflow_id'] ?? 0 ) . ':' . (int) ( $context['step_id'] ?? 0 );
				$product_id = self::rotate_wc_product( $wc_product_ids, $state_key );
				if ( $product_id ) {
					$wc_product = wc_get_product( $product_id );
					if ( $wc_product ) {
						return self::describe_wc_product( $wc_product );
					}
				}
			} elseif ( ! empty( $wc_product_ids ) && ! aime_has_pro() ) {
				// Make the free-tier skip visible in the run log instead of
				// silently falling through to the next product source.
				aime_log( 'Workflow ad copy: WooCommerce rotation is Pro — using the fallback product source for this run.', 'info', 'workflow-automation' );
			}
		}

		// 3. Manual products rotation (fallback)
		$product = self::rotated_topic( $config, $context, 'product', 'products' );
		if ( '' !== $product ) {
			return $product;
		}

		// 4. AI Brain selected topic (ancestor-aware: works through intermediate steps)
		$topic = self::resolve_from_context( $context, 'selected_topic', 'ai_brain' );
		if ( '' !== $topic ) {
			return $topic;
		}

		// 5. Trigger-event fallback ("post published → write ad for it").
		if ( is_array( $context['event'] ?? null ) ) {
			foreach ( array( 'post_title', 'name', 'title' ) as $event_key ) {
				if ( ! empty( $context['event'][ $event_key ] ) && is_scalar( $context['event'][ $event_key ] ) ) {
					return trim( (string) $context['event'][ $event_key ] );
				}
			}
		}

		// 6. Workflow topic
		return trim( (string) ( $context['topic'] ?? '' ) );
	}

	/**
	 * Describe a WooCommerce product beyond its name — price and short
	 * description give the AI the details good ad copy needs.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string Multi-line product description.
	 */
	private static function describe_wc_product( $product ): string {
		$lines = array( $product->get_name() );

		$price = $product->get_price_html();
		if ( $price ) {
			$lines[] = sprintf( /* translators: %s: product price */ __( 'Price: %s', 'ai-marketing-expert' ), wp_strip_all_tags( $price ) );
		}

		$short = $product->get_short_description();
		if ( $short ) {
			$lines[] = wp_trim_words( wp_strip_all_tags( $short ), 40, '…' );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Rotate WooCommerce products (same logic as topic rotation).
	 *
	 * @param array  $product_ids WooCommerce product IDs.
	 * @param string $state_key   Rotation state key.
	 * @return int Selected product ID (0 if list empty).
	 */
	private static function rotate_wc_product( array $product_ids, string $state_key ): int {
		$product_ids = array_values( array_unique( array_filter( $product_ids ) ) );
		if ( ! $product_ids ) {
			return 0;
		}
		if ( 1 === count( $product_ids ) ) {
			return $product_ids[0];
		}

		$state = get_option( 'aime_wf_wc_product_rotation', array() );
		$state = is_array( $state ) ? $state : array();
		$key   = $state_key . ':' . md5( wp_json_encode( $product_ids ) );

		$used      = isset( $state[ $key ] ) && is_array( $state[ $key ] ) ? array_map( 'intval', $state[ $key ] ) : array();
		$remaining = array_values( array_diff( array_keys( $product_ids ), $used ) );
		if ( ! $remaining ) {
			$used      = array();
			$remaining = array_keys( $product_ids );
		}

		$pick   = $remaining[ array_rand( $remaining ) ];
		$used[] = $pick;

		// Drop stale state for this step (old product lists) before saving.
		foreach ( array_keys( $state ) as $k ) {
			if ( 0 === strpos( (string) $k, $state_key . ':' ) && $k !== $key ) {
				unset( $state[ $k ] );
			}
		}
		$state[ $key ] = $used;
		update_option( 'aime_wf_wc_product_rotation', $state, false );

		return $product_ids[ $pick ];
	}
}
