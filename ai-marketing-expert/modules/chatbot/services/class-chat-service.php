<?php
/**
 * Chat Service — core AI conversation logic.
 *
 * Handles message processing, knowledge context retrieval, prompt building,
 * and action intent parsing.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Chatbot\Services

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
 */

namespace WPSpace\AiMarketingExpert\Modules\Chatbot\Services;

use WPSpace\AiMarketingExpert\AiProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ChatService {

	/**
	 * Process a visitor message and generate an AI response.
	 *
	 * @param object $conversation Conversation row (with bot columns joined).
	 * @param string $visitor_message The visitor's message text.
	 * @return array { success, content, message_id, created_at, actions, metadata }
	 */
	public function process_message( object $conversation, string $visitor_message ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$knowledge_config = json_decode( $conversation->knowledge_config ?? '{}', true ) ?: array();
		$history_limit    = max( 1, min( 100, absint( $knowledge_config['max_history_messages'] ?? 20 ) ) );

		// 1. Retrieve recent conversation history.
		$history = $wpdb->get_results( $wpdb->prepare(
			"SELECT sender_type, content
			 FROM {$p}aime_chatbot_messages
			 WHERE conversation_id = %d
			 ORDER BY created_at DESC
			 LIMIT %d",
			$conversation->id,
			$history_limit
		) );
		$history = array_reverse( $history );

		// 2. Find relevant knowledge.
		$knowledge_entries = $this->find_relevant_knowledge( $conversation->bot_id, $visitor_message );

		// 3. Build the full prompt.
		$prompt = $this->build_prompt( $conversation, $history, $visitor_message, $knowledge_entries );

		// 4. Call AI provider.
		$start_time = microtime( true );
		$ai_result  = AiProvider::generate( $prompt, 'text', 1024 );
		$response_ms = (int) ( ( microtime( true ) - $start_time ) * 1000 );

		if ( ! $ai_result['success'] ) {
			aime_log( 'Chatbot AI generation failed: ' . ( $ai_result['message'] ?? 'unknown' ), 'error', 'chatbot' );
			return array( 'success' => false );
		}

		// 'text' mode: chat replies are conversational prose — only leaked
		// reasoning/safety lines are stripped, never JSON-prefix heuristics
		// (those would truncate a reply at its first "[" or "{").
		$ai_content = trim( aime_strip_thinking_text( $ai_result['content'], 'text' ) );

		// 5. Parse action intents from AI response.
		$actions    = $this->parse_actions( $ai_content );
		$ai_content = $this->strip_action_tags( $ai_content );

		// Never store/show an empty bubble. If the model replied with only an
		// action tag, substitute a short lead-in; with no actions either, treat
		// it as a failed generation so the widget shows its error state.
		if ( '' === $ai_content ) {
			if ( empty( $actions ) ) {
				aime_log( 'Chatbot AI returned empty content after stripping.', 'error', 'chatbot' );
				return array( 'success' => false );
			}
			$ai_content = __( 'Please share your details below and we\'ll get back to you.', 'ai-marketing-expert' );
		}

		// 6. Save AI response to DB.
		$now = current_time( 'mysql', true );
		$wpdb->insert( "{$p}aime_chatbot_messages", array(
			'conversation_id' => $conversation->id,
			'sender_type'     => 'ai',
			'content'         => $ai_content,
			'content_type'    => 'text',
			'metadata'        => wp_json_encode( array(
				'provider'     => $ai_result['provider'] ?? '',
				'model'        => $ai_result['model'] ?? '',
				'response_ms'  => $response_ms,
				// Persist actions (e.g. product cards) so they survive
				// polling and history reloads in the widget.
				'actions'      => $actions,
			) ),
			'created_at'      => $now,
		) );

		$message_id = (int) $wpdb->insert_id;

		// 7. Update conversation timestamp.
		$wpdb->update(
			"{$p}aime_chatbot_conversations",
			array( 'updated_at' => $now ),
			array( 'id' => $conversation->id )
		);

		return array(
			'success'    => true,
			'content'    => $ai_content,
			'message_id' => $message_id,
			'created_at' => $now,
			'actions'    => $actions,
			'metadata'   => array(
				'provider'    => $ai_result['provider'] ?? '',
				'model'       => $ai_result['model'] ?? '',
				'response_ms' => $response_ms,
			),
		);
	}

	/* ── Build the full AI prompt ────────────────────── */

	private function build_prompt( object $conversation, array $history, string $visitor_message, array $knowledge ): string {
		$parts = array();
		$knowledge_config = json_decode( $conversation->knowledge_config ?? '{}', true ) ?: array();

		// System instructions.
		$system_prompt = $conversation->system_prompt ?? '';
		if ( empty( $system_prompt ) ) {
			$system_prompt = $this->get_default_system_prompt();
		}

		// Replace variables (Pro feature, but we support basic ones for free too).
		$system_prompt = str_replace(
			array( '{site_name}', '{bot_name}', '{page_url}' ),
			array(
				get_bloginfo( 'name' ),
				$conversation->bot_name ?? __( 'Assistant', 'ai-marketing-expert' ),
				$conversation->page_url ?? home_url(),
			),
			$system_prompt
		);

		// Visitor context (sanitize to prevent prompt injection via stored visitor data).
		if ( ! empty( $conversation->visitor_name ) ) {
			$safe_name = sanitize_text_field( $conversation->visitor_name );
			$system_prompt .= sprintf( "\n\nThe visitor's name is %s.", $safe_name );
		}
		if ( ! empty( $conversation->visitor_email ) ) {
			$safe_email = sanitize_email( $conversation->visitor_email );
			$system_prompt .= sprintf( " Their email is %s.", $safe_email );
		}

		$parts[] = $system_prompt;

		// Knowledge context.
		if ( ! empty( $knowledge ) ) {
			$parts[] = "\n--- KNOWLEDGE BASE (use this to answer questions) ---";
			foreach ( $knowledge as $entry ) {
				if ( 'qa_pair' === $entry->type && ! empty( $entry->question ) ) {
					$parts[] = sprintf( "Q: %s\nA: %s", $entry->question, $entry->answer );
				} elseif ( 'woo_product' === $entry->type ) {
					$product_context = $this->format_product_knowledge_entry( $entry );
					if ( $product_context ) {
						$parts[] = $product_context;
					}
				} else {
					$content_preview = mb_substr( $entry->content ?: $entry->answer ?: '', 0, 500 );
					if ( $content_preview ) {
						$parts[] = sprintf( "Info: %s", $content_preview );
					}
				}
			}
			$parts[] = "--- END KNOWLEDGE BASE ---\n";
		}

		// Conversation history.
		if ( ! empty( $history ) ) {
			$parts[] = "--- CONVERSATION HISTORY ---";
			foreach ( $history as $msg ) {
				$role = 'visitor' === $msg->sender_type ? 'Visitor' : 'Assistant';
				if ( 'system' === $msg->sender_type ) {
					$role = 'System';
				}
				$parts[] = sprintf( "%s: %s", $role, $msg->content );
			}
			$parts[] = "--- END HISTORY ---\n";
		}

		// Current message (with safety delimiters).
		$parts[] = "[USER MESSAGE START]";
		$parts[] = $visitor_message;
		$parts[] = "[USER MESSAGE END]";

		$parts[] = $this->build_response_style_instructions( $knowledge_config );

		$parts[] = "\nRespond helpfully and concisely. Use the knowledge base as the preferred source when it contains relevant information. "
				. "When knowledge-base data is relevant, answer confidently and naturally from that data instead of apologizing or saying it is unavailable. "
				. "For product questions, carefully match product names, SKUs, categories, prices, descriptions, and product metadata in the knowledge base before deciding the information is missing. "
				. "When recommending or describing products, include Product page, Add to cart, or Buy now links when those links are present in the product knowledge. "
				. "Keep any AI-generated suggestions, assumptions, or extra notes separate at the bottom under 'Additional note:' so the main answer stays grounded in the knowledge base. "
				. "If the knowledge base does not cover a common or general topic, answer using reliable general knowledge and avoid saying you lack knowledge-base information. "
				. "Only say you do not have enough information when the question asks for specific details about this business, website, product, order, account, pricing, policy, availability, or other private/local information that is not in the knowledge base. "
				. "Do not make up specific business details. "
				. "If the visitor seems interested in your service/product but hasn't shared contact info, you may include [LEAD_CAPTURE] at the end of your response. "
				. "If the question requires a human agent, include [HUMAN_HANDOFF] at the end.";

		return implode( "\n", $parts );
	}

	/* ── Default system prompt ───────────────────────── */

	private function get_default_system_prompt(): string {
		return sprintf(
			/* translators: %s: site name */
			__(
				'You are a helpful AI assistant for %s. Answer visitors\' questions naturally and confidently. Prefer the provided knowledge base when it contains relevant information, especially for product and business-specific questions. For common or general questions not covered by the knowledge base, use reliable general knowledge. Put AI-generated suggestions or extra notes separately at the bottom when they are not directly from the knowledge base. For questions that require specific business, website, product, order, account, pricing, policy, or availability details not found in the knowledge base, say you do not have enough specific information and suggest contacting the site directly. Do not make up specific business details. Keep responses focused and under 200 words.',
				'ai-marketing-expert'
			),
			get_bloginfo( 'name' )
		);
	}

	/* ── Knowledge retrieval (full bot knowledge) ───── */

	private function find_relevant_knowledge( int $bot_id, string $query ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_chatbot_knowledge" ) );
		if ( ! $table_exists ) {
			return array();
		}

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, type, source_id, question, answer, content, metadata
			 FROM {$p}aime_chatbot_knowledge
			 WHERE bot_id = %d AND status = 'active'
			 ORDER BY
			 	CASE WHEN type = 'woo_product' THEN 0 WHEN type = 'qa_pair' THEN 1 ELSE 2 END,
			 	updated_at DESC,
			 	id DESC
			 LIMIT %d",
			$bot_id,
			300
		) );

		return $this->rank_knowledge_entries( $results ?: array(), $query );
	}

	private function rank_knowledge_entries( array $entries, string $query ): array {
		$query_terms = $this->extract_query_terms( $query );
		if ( empty( $query_terms ) || empty( $entries ) ) {
			return $entries;
		}

		foreach ( $entries as $entry ) {
			$entry->_match_score = $this->score_knowledge_entry( $entry, $query_terms );
		}

		usort( $entries, function ( $a, $b ) {
			$score_compare = ( $b->_match_score ?? 0 ) <=> ( $a->_match_score ?? 0 );
			if ( 0 !== $score_compare ) {
				return $score_compare;
			}

			$type_weight = array( 'woo_product' => 0, 'qa_pair' => 1 );
			return ( $type_weight[ $a->type ] ?? 2 ) <=> ( $type_weight[ $b->type ] ?? 2 );
		} );

		foreach ( $entries as $entry ) {
			unset( $entry->_match_score );
		}

		return $entries;
	}

	private function score_knowledge_entry( object $entry, array $query_terms ): int {
		$metadata = json_decode( $entry->metadata ?? '{}', true ) ?: array();
		$title    = strtolower( (string) ( $metadata['title'] ?? $entry->question ?? '' ) );
		$sku      = strtolower( (string) ( $metadata['sku'] ?? '' ) );
		$content  = strtolower( trim( implode( ' ', array_filter( array(
			$entry->question ?? '',
			$entry->answer ?? '',
			$entry->content ?? '',
			wp_json_encode( $metadata ),
		) ) ) ) );
		$score    = 0;

		foreach ( $query_terms as $term ) {
			if ( $title && false !== strpos( $title, $term ) ) {
				$score += 'woo_product' === $entry->type ? 12 : 8;
			}
			if ( $sku && $term === $sku ) {
				$score += 20;
			}
			if ( $content && false !== strpos( $content, $term ) ) {
				$score += 2;
			}
		}

		if ( 'woo_product' === $entry->type && $score > 0 ) {
			$score += 5;
		}

		return $score;
	}

	private function extract_query_terms( string $query ): array {
		$words = preg_split( '/[^a-z0-9]+/i', strtolower( $query ) );
		$words = array_filter( $words, function ( $word ) {
			return strlen( $word ) >= 2 && ! in_array( $word, array( 'the', 'and', 'for', 'with', 'about', 'show', 'tell', 'what', 'which', 'please' ), true );
		} );

		return array_values( array_unique( $words ) );
	}

	private function format_product_knowledge_entry( object $entry ): string {
		$metadata   = json_decode( $entry->metadata ?? '{}', true ) ?: array();
		$product_id = absint( $entry->source_id ?? 0 );
		$title      = sanitize_text_field( $metadata['title'] ?? '' );
		$url        = esc_url_raw( $metadata['url'] ?? ( $product_id ? get_permalink( $product_id ) : '' ) );
		$cart_url   = $product_id ? add_query_arg( 'add-to-cart', $product_id, home_url( '/' ) ) : '';
		$buy_url    = function_exists( 'wc_get_checkout_url' ) && $product_id ? add_query_arg( 'add-to-cart', $product_id, wc_get_checkout_url() ) : $cart_url;
		$lines      = array();

		$lines[] = 'Product Knowledge:';
		if ( $title ) {
			$lines[] = 'Product name: ' . $title;
		}
		if ( ! empty( $metadata['sku'] ) ) {
			$lines[] = 'SKU: ' . sanitize_text_field( $metadata['sku'] );
		}
		if ( '' !== (string) ( $metadata['price'] ?? '' ) ) {
			$lines[] = 'Price: ' . sanitize_text_field( (string) $metadata['price'] );
		}
		if ( $entry->content ) {
			$lines[] = 'Details: ' . mb_substr( $entry->content, 0, 900 );
		}
		if ( $url ) {
			$lines[] = 'Product page link: ' . $url;
		}
		if ( $cart_url ) {
			$lines[] = 'Add to cart link: ' . esc_url_raw( $cart_url );
		}
		if ( $buy_url ) {
			$lines[] = 'Buy now link: ' . esc_url_raw( $buy_url );
		}
		if ( $product_id ) {
			$lines[] = 'Use [PRODUCT:' . $product_id . '] only when this exact product is recommended.';
		}

		return implode( "\n", $lines );
	}

	private function build_response_style_instructions( array $knowledge_config ): string {
		$tone     = sanitize_key( $knowledge_config['response_tone'] ?? 'friendly' );
		$length   = sanitize_key( $knowledge_config['response_length'] ?? 'short' );
		$style    = sanitize_key( $knowledge_config['response_style'] ?? 'confident' );
		$custom   = sanitize_textarea_field( $knowledge_config['custom_response_style'] ?? '' );
		$sales    = ! empty( $knowledge_config['sales_focused'] );
		$allowed_tones   = array( 'friendly', 'professional', 'neutral' );
		$allowed_lengths = array( 'short', 'balanced', 'detailed' );
		$allowed_styles  = array( 'confident', 'careful', 'sales' );

		if ( ! in_array( $tone, $allowed_tones, true ) ) {
			$tone = 'friendly';
		}
		if ( ! in_array( $length, $allowed_lengths, true ) ) {
			$length = 'short';
		}
		if ( ! in_array( $style, $allowed_styles, true ) ) {
			$style = 'confident';
		}

		$instructions = array( "\n--- RESPONSE STYLE ---" );
		$instructions[] = 'Tone: ' . $tone . '.';
		$instructions[] = 'Length: ' . $length . '.';
		$instructions[] = 'Behavior: ' . $style . '.';
		if ( $sales ) {
			$instructions[] = 'When products are relevant, be sales-focused but still accurate. Highlight benefits and include purchase links when available.';
		}
		if ( $custom ) {
			$instructions[] = 'Custom style instructions: ' . $custom;
		}
		$instructions[] = '--- END RESPONSE STYLE ---';

		return implode( "\n", $instructions );
	}

	/* ── Parse action intents from AI response ───────── */

	private function parse_actions( string $content ): array {
		$actions = array();

		if ( false !== strpos( $content, '[LEAD_CAPTURE]' ) ) {
			$actions[] = array( 'type' => 'lead_capture' );
		}

		if ( false !== strpos( $content, '[HUMAN_HANDOFF]' ) ) {
			$actions[] = array( 'type' => 'human_handoff' );
		}

		// Product recommendation: [PRODUCT:42]
		if ( preg_match_all( '/\[PRODUCT:(\d+)\]/', $content, $matches ) ) {
			foreach ( array_unique( $matches[1] ) as $product_id ) {
				$product = $this->hydrate_product_action( (int) $product_id );
				if ( $product ) {
					$actions[] = $product;
				}
			}
		}

		return $actions;
	}

	/**
	 * Build a product action payload with everything the widget needs to
	 * render a product card: title, price, image, product page link, and a
	 * Buy Now (add-to-cart → checkout) link.
	 *
	 * @param int $product_id Product post ID.
	 * @return array|null Null when the ID doesn't resolve to a published product.
	 */
	private function hydrate_product_action( int $product_id ): ?array {
		$post = get_post( $product_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}

		$action = array(
			'type'       => 'product',
			'product_id' => $product_id,
			'title'      => get_the_title( $product_id ),
			'url'        => get_permalink( $product_id ),
			'image'      => get_the_post_thumbnail_url( $product_id, 'medium' ) ?: '',
			'price_html' => '',
			'buy_url'    => '',
		);

		// WooCommerce enrichment when available.
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || ! $product->is_visible() ) {
				return null;
			}
			$action['title']      = $product->get_name();
			$action['url']        = $product->get_permalink();
			$action['price_html'] = wp_strip_all_tags( wc_price( (float) $product->get_price() ) );

			// Buy Now: add to cart and jump straight to checkout. Variable
			// products need option selection, so link to the product page.
			if ( $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock() ) {
				$action['buy_url'] = add_query_arg( 'add-to-cart', $product_id, wc_get_checkout_url() );
			} else {
				$action['buy_url'] = $product->get_permalink();
			}
		} else {
			// Non-Woo fallback: Buy Now just opens the product/page link.
			$action['buy_url'] = $action['url'];
		}

		return $action;
	}

	/* ── Strip action tags from displayed response ─── */

	private function strip_action_tags( string $content ): string {
		$content = str_replace( array( '[LEAD_CAPTURE]', '[HUMAN_HANDOFF]' ), '', $content );
		$content = preg_replace( '/\[PRODUCT:\d+\]/', '', $content );
		return trim( $content );
	}
}
