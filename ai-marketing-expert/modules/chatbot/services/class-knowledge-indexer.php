<?php
/**
 * Knowledge Indexer — content ingestion engine for WordPress posts, pages,
 * WooCommerce products, and custom Q&A pairs.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Chatbot\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\Chatbot\Services;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KnowledgeIndexer {

	/**
	 * Bulk index content for a bot.
	 *
	 * @param int    $bot_id Bot ID.
	 * @param string $type   Content type: wp_content or woo_product.
	 * @return array { indexed: int, skipped: int, errors: array }
	 */
	public function bulk_index( int $bot_id, string $type, string $post_type = '' ): array {
		switch ( $type ) {
			case 'wp_content':
				return $this->index_wp_content( $bot_id, $post_type );
			case 'woo_product':
				return $this->index_woo_products( $bot_id );
			default:
				return array( 'indexed' => 0, 'skipped' => 0, 'errors' => array( __( 'Unknown content type.', 'ai-marketing-expert' ) ) );
		}
	}

	/* ══════════════════════════════════════════════════════
	 *  WordPress Content (posts + pages)
	 * ══════════════════════════════════════════════════════ */

	private function index_wp_content( int $bot_id, string $post_type = '' ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$is_pro = aime_has_pro();
		$limits = aime_free_limits();
		$limit  = $is_pro ? 9999 : ( $limits['chatbot_knowledge_pages'] ?? 50 );

		// Get current index count.
		$current = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}aime_chatbot_knowledge WHERE bot_id = %d AND type = 'wp_content'",
			$bot_id
		) );

		$remaining = max( 0, $limit - $current );
		if ( 0 === $remaining ) {
			return array(
				'indexed' => 0,
				'skipped' => 0,
				'errors'  => array( __( 'Page/post limit reached.', 'ai-marketing-expert' ) ),
			);
		}

		// Get published posts/pages not yet indexed.
		$already_indexed = $wpdb->get_col( $wpdb->prepare(
			"SELECT source_id FROM {$p}aime_chatbot_knowledge WHERE bot_id = %d AND type = 'wp_content' AND source_id IS NOT NULL",
			$bot_id
		) );

		$exclude = ! empty( $already_indexed ) ? implode( ',', array_map( 'absint', $already_indexed ) ) : '0';

		// Filter by post type or default to both posts+pages.
		$post_types = $post_type ? "'" . esc_sql( $post_type ) . "'" : "'post','page'";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_content, post_excerpt, post_type
			 FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type IN ({$post_types})
			   AND ID NOT IN ({$exclude})
			 ORDER BY post_date DESC
			 LIMIT %d",
			$remaining
		) );

		$indexed = 0;
		$errors  = array();
		$now     = current_time( 'mysql', true );

		foreach ( $posts as $post ) {
			$text = $this->extract_text( $post );
			if ( empty( $text ) ) {
				continue;
			}

			$result = $wpdb->insert( "{$p}aime_chatbot_knowledge", array(
				'bot_id'          => $bot_id,
				'type'            => 'wp_content',
				'source_id'       => $post->ID,
				'content'         => $text,
				'status'          => 'active',
				'last_indexed_at' => $now,
				'metadata'        => wp_json_encode( array(
					'post_type'  => $post->post_type,
					'title'      => $post->post_title,
					'url'        => get_permalink( $post->ID ),
				) ),
				'created_at'      => $now,
				'updated_at'      => $now,
			) );

			if ( false !== $result ) {
				$indexed++;
			} else {
				$errors[] = sprintf(
					/* translators: %d: post ID */
					__( 'Failed to index post #%d', 'ai-marketing-expert' ),
					$post->ID
				);
			}
		}

		return array(
			'indexed' => $indexed,
			'skipped' => count( $already_indexed ),
			'errors'  => $errors,
		);
	}

	/* ══════════════════════════════════════════════════════
	 *  WooCommerce Products (Pro only)
	 * ══════════════════════════════════════════════════════ */

	private function index_woo_products( int $bot_id ): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array(
				'indexed' => 0,
				'skipped' => 0,
				'errors'  => array( __( 'WooCommerce is not active.', 'ai-marketing-expert' ) ),
			);
		}

		global $wpdb;
		$p = $wpdb->prefix;

		$already_indexed = $wpdb->get_col( $wpdb->prepare(
			"SELECT source_id FROM {$p}aime_chatbot_knowledge WHERE bot_id = %d AND type = 'woo_product' AND source_id IS NOT NULL",
			$bot_id
		) );

		$exclude = ! empty( $already_indexed ) ? implode( ',', array_map( 'absint', $already_indexed ) ) : '0';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$products = $wpdb->get_results(
			"SELECT ID, post_title, post_content, post_excerpt
			 FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type = 'product'
			   AND ID NOT IN ({$exclude})
			 ORDER BY post_date DESC
			 LIMIT 500"
		);

		$indexed = 0;
		$errors  = array();
		$now     = current_time( 'mysql', true );

		foreach ( $products as $product ) {
			$text = $this->extract_product_text( $product );
			if ( empty( $text ) ) {
				continue;
			}

			$result = $wpdb->insert( "{$p}aime_chatbot_knowledge", array(
				'bot_id'          => $bot_id,
				'type'            => 'woo_product',
				'source_id'       => $product->ID,
				'content'         => $text,
				'status'          => 'active',
				'last_indexed_at' => $now,
				'metadata'        => wp_json_encode( array(
					'title' => $product->post_title,
					'url'   => get_permalink( $product->ID ),
					'price' => get_post_meta( $product->ID, '_price', true ),
					'sku'   => get_post_meta( $product->ID, '_sku', true ),
				) ),
				'created_at'      => $now,
				'updated_at'      => $now,
			) );

			if ( false !== $result ) {
				$indexed++;
			} else {
				$errors[] = sprintf(
					/* translators: %d: product ID */
					__( 'Failed to index product #%d', 'ai-marketing-expert' ),
					$product->ID
				);
			}
		}

		return array(
			'indexed' => $indexed,
			'skipped' => count( $already_indexed ),
			'errors'  => $errors,
		);
	}

	/* ══════════════════════════════════════════════════════
	 *  Single Post Re-index (called via cron on post save)
	 * ══════════════════════════════════════════════════════ */

	/**
	 * Re-index a single post across all bots that have wp_content knowledge.
	 *
	 * @param int $post_id WordPress post ID.
	 */
	public static function index_single_post( int $post_id ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		$indexer = new self();
		$text    = $indexer->extract_text( $post );
		$now     = current_time( 'mysql', true );

		// Find all bots that have this post indexed.
		$existing = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, bot_id FROM {$p}aime_chatbot_knowledge WHERE source_id = %d AND type = 'wp_content'",
			$post_id
		) );

		foreach ( $existing as $entry ) {
			if ( $text ) {
				$wpdb->update(
					"{$p}aime_chatbot_knowledge",
					array(
						'content'         => $text,
						'last_indexed_at' => $now,
						'updated_at'      => $now,
						'metadata'        => wp_json_encode( array(
							'post_type' => $post->post_type,
							'title'     => $post->post_title,
							'url'       => get_permalink( $post_id ),
						) ),
					),
					array( 'id' => $entry->id )
				);
			} else {
				// Post content is empty — deactivate.
				$wpdb->update(
					"{$p}aime_chatbot_knowledge",
					array( 'status' => 'inactive', 'updated_at' => $now ),
					array( 'id' => $entry->id )
				);
			}
		}
	}

	/* ── Text extraction helpers ─────────────────────── */

	private function extract_text( $post ): string {
		$parts = array();

		if ( ! empty( $post->post_title ) ) {
			$parts[] = $post->post_title;
		}

		$content = wp_strip_all_tags( $post->post_content );
		$content = preg_replace( '/\s+/', ' ', $content );
		$content = trim( $content );

		if ( ! empty( $content ) ) {
			// Limit to ~2000 words for knowledge entry.
			$words = explode( ' ', $content );
			if ( count( $words ) > 2000 ) {
				$content = implode( ' ', array_slice( $words, 0, 2000 ) );
			}
			$parts[] = $content;
		}

		if ( ! empty( $post->post_excerpt ) ) {
			$parts[] = 'Summary: ' . wp_strip_all_tags( $post->post_excerpt );
		}

		return implode( "\n\n", $parts );
	}

	private function extract_product_text( $product ): string {
		$parts = array();

		$parts[] = 'Product: ' . $product->post_title;

		$price = get_post_meta( $product->ID, '_price', true );
		if ( $price ) {
			$parts[] = 'Price: ' . $price;
		}

		$sku = get_post_meta( $product->ID, '_sku', true );
		if ( $sku ) {
			$parts[] = 'SKU: ' . $sku;
		}

		$short = wp_strip_all_tags( $product->post_excerpt );
		if ( $short ) {
			$parts[] = $short;
		}

		$long = wp_strip_all_tags( $product->post_content );
		$long = preg_replace( '/\s+/', ' ', trim( $long ) );
		if ( $long ) {
			$words = explode( ' ', $long );
			if ( count( $words ) > 500 ) {
				$long = implode( ' ', array_slice( $words, 0, 500 ) );
			}
			$parts[] = $long;
		}

		// Product categories.
		$terms = wp_get_post_terms( $product->ID, 'product_cat', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $terms ) && $terms ) {
			$parts[] = 'Categories: ' . implode( ', ', $terms );
		}

		return implode( "\n", $parts );
	}
}
