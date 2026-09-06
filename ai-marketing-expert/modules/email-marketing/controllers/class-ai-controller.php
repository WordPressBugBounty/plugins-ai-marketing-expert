<?php
/**
 * AI Controller — AI-powered marketing features.
 *
 * Uses \WPSpace\AiMarketingExpert\AiProvider::generate() for all AI calls.
 *
 * @package WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

namespace WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers;

use WPSpace\AiMarketingExpert\AiProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AiController {

	/* ── GENERATE EMAIL TEMPLATE ─────────────────────── */

	public function generate_template( \WP_REST_Request $request ): \WP_REST_Response {
		$prompt      = sanitize_textarea_field( $request->get_param( 'prompt' ) );
		$style       = sanitize_text_field( $request->get_param( 'style' ) ?: 'professional' );
		$layout_mode = sanitize_text_field( $request->get_param( 'layout_mode' ) ?: 'table-safe' );
		if ( ! aime_has_pro() && ( 'table-safe' === $layout_mode || in_array( $style, array( 'formal', 'humorous', 'minimalist' ), true ) ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Advanced AI tones and table-safe email layout are available in Pro.', 'ai-marketing-expert' ) ), 403 );
		}

		$layout_instruction = 'Use table-based email HTML only. Build the layout with email-safe tables, rows, and cells. Avoid div-based page structure. Use inline CSS only. Make it compatible with Gmail, Outlook, and mobile email clients.';

		if ( 'simple-html' === $layout_mode ) {
			$layout_instruction = 'Use simple HTML with clean structure and inline CSS. A lightweight div-based layout is allowed, but keep it email-friendly and avoid advanced CSS that commonly breaks in email clients.';
		}

		$system = "You are an expert email marketing designer. Generate a complete HTML email template based on the user request. "
			. "Style: {$style}. Layout mode: {$layout_mode}. {$layout_instruction} Make it responsive. Include placeholder merge tags: {{first_name}}, {{last_name}}, {{email}}, {{unsubscribe_url}}. "
			. "STRICT OUTPUT RULES: Do NOT use <style> tags, CSS classes, or ids — every style must be an inline style=\"...\" attribute on the element itself. "
			. "Do NOT include <!DOCTYPE>, <html>, <head>, <body>, or <meta> tags — return only the inner body markup. "
			. "Do not include any footer, copyright block, company address, unsubscribe section, sent-to line, preference center, legal disclaimer, or social/footer area. The application appends its own required footer automatically. "
			. "Return ONLY the raw HTML, no explanations.";

		$result = AiProvider::generate( $system . "\n\nUser request: " . $prompt, 'text', 4096 );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ), 'error' => $result['content'] ?? '' ), 500 );
		}

		return new \WP_REST_Response( array(
			'html'     => $this->strip_generated_footer( $this->clean_html_response( (string) $result['content'] ) ),
			'provider' => $result['provider'] ?? '',
			'model'    => $result['model'] ?? '',
		) );
	}

	/**
	 * Strip thinking/reasoning text and markdown fences from a raw HTML response.
	 * Handles: <think> blocks, plain-text CoT prefix, and ```html fences.
	 */
	private function clean_html_response( string $raw ): string {
		$raw = trim( $raw );

		// Remove <think>…</think> blocks (DeepSeek, Qwen, some MiniMax variants).
		$raw = preg_replace( '/<think>[\s\S]*?<\/think>\s*/i', '', $raw ) ?? $raw;
		$raw = trim( $raw );

		// Extract HTML from markdown code fences: ```html … ``` or ``` … ```.
		if ( preg_match( '/^```(?:html)?\s*([\s\S]*?)```\s*$/i', $raw, $m ) ) {
			return trim( $m[1] );
		}
		// Fences anywhere in the string (model prefixed with reasoning text).
		if ( preg_match( '/```(?:html)?\s*([\s\S]*?)```/i', $raw, $m ) ) {
			return trim( $m[1] );
		}

		// Strip any plain-text prefix before the first HTML tag.
		$html_start = strpos( $raw, '<' );
		if ( false !== $html_start && $html_start > 0 ) {
			$prefix = substr( $raw, 0, $html_start );
			// Only strip if the prefix looks like natural language, not an attribute or entity.
			if ( preg_match( '/[a-zA-Z]{4,}/', $prefix ) ) {
				$raw = substr( $raw, $html_start );
			}
		}

		// Unwrap <style> blocks that the model (or a prior wpautop pass) wrapped in <p>.
		$raw = preg_replace( '#<p[^>]*>\s*(<style\b[\s\S]*?</style>)\s*</p>#i', '$1', $raw ) ?? $raw;

		// Unwrap full-document output: keep only the <body> inner HTML but
		// preserve any <head> styles so the inliner below can apply them.
		if ( preg_match( '#<body\b[^>]*>([\s\S]*?)</body>#i', $raw, $m ) ) {
			$head_styles = '';
			if ( preg_match_all( '#<style\b[^>]*>[\s\S]*?</style>#i', $raw, $sm ) ) {
				$head_styles = implode( "\n", $sm[0] );
			}
			$raw = $head_styles . $m[1];
		}

		// Strip leading empty paragraphs (<p></p>, <p> </p>, <p>&nbsp;</p>)
		// before inlining so they don't get trapped inside the wrapper div.
		$raw = preg_replace( '#^(\s*<p[^>]*>[\s\xc2\xa0]*(?:&nbsp;)?[\s\xc2\xa0]*</p>\s*)+#i', '', $raw ) ?? $raw;

		// Convert <style> blocks to inline styles — TinyMCE cannot apply
		// document-level CSS inside its content area and most email clients
		// strip <style>, so class/element rules must live on the elements.
		$raw = $this->inline_styles( $raw );

		return trim( $raw );
	}

	/**
	 * Inline simple CSS rules from <style> blocks onto matching elements.
	 *
	 * Email clients widely strip <style> blocks and the campaign TinyMCE
	 * editor renders them as an inert "CSS" box, so class/id/element rules
	 * must be converted to style="" attributes. Handles simple selectors
	 * (.class, #id, element, element.class and comma lists); complex
	 * selectors and @media blocks are dropped. Pre-existing inline styles
	 * win over injected rules.
	 */
	private function inline_styles( string $html ): string {
		if ( false === stripos( $html, '<style' ) ) {
			return $html;
		}

		// Collect CSS, then remove the <style> blocks from the markup.
		$css = '';
		if ( preg_match_all( '#<style\b[^>]*>([\s\S]*?)</style>#i', $html, $m ) ) {
			$css = implode( "\n", $m[1] );
		}
		$html = preg_replace( '#<style\b[^>]*>[\s\S]*?</style>#i', '', $html ) ?? $html;

		// Strip comments and @media / other at-rule blocks (unusable inline).
		$css = preg_replace( '#/\*[\s\S]*?\*/#', '', $css ) ?? $css;
		$css = preg_replace( '/@[^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/s', '', $css ) ?? $css;

		if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) || ! class_exists( 'DOMDocument' ) ) {
			return trim( $html );
		}

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?><div id="aime-inline-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		$xpath = new \DOMXPath( $dom );
		$root  = $dom->getElementById( 'aime-inline-root' );
		if ( ! $root ) {
			return trim( $html );
		}

		// Bucket rules by specificity so later-applied buckets override
		// earlier ones (element < class < id), mirroring CSS precedence.
		$buckets = array( array(), array(), array() );
		foreach ( $rules as $rule ) {
			$decl = trim( $rule[2] );
			if ( '' === $decl ) {
				continue;
			}
			foreach ( explode( ',', $rule[1] ) as $sel ) {
				$sel = trim( $sel );
				if ( '' === $sel || preg_match( '/[\s>+~\[:]/', $sel ) ) {
					// Skip complex selectors — except treat body/html as root.
					if ( ! in_array( strtolower( $sel ), array( 'body', 'html' ), true ) ) {
						continue;
					}
				}
				if ( in_array( strtolower( $sel ), array( 'body', 'html' ), true ) ) {
					$buckets[0][] = array( 'root', '', $decl );
				} elseif ( preg_match( '/^#([\w-]+)$/', $sel, $sm ) ) {
					$buckets[2][] = array( 'id', $sm[1], $decl );
				} elseif ( preg_match( '/^([a-zA-Z][\w-]*)?\.([\w-]+)$/', $sel, $sm ) ) {
					$buckets[1][] = array( 'class', array( strtolower( $sm[1] ), $sm[2] ), $decl );
				} elseif ( preg_match( '/^[a-zA-Z][\w-]*$/', $sel ) ) {
					$buckets[0][] = array( 'tag', strtolower( $sel ), $decl );
				}
			}
		}

		$apply = function ( \DOMElement $el, string $decl ): void {
			$existing = trim( (string) $el->getAttribute( 'style' ) );
			// Injected rules go first so pre-existing inline styles win.
			$merged = rtrim( $decl, '; ' ) . ';' . ( $existing ? ' ' . $existing : '' );
			$el->setAttribute( 'style', preg_replace( '/\s+/', ' ', $merged ) );
		};

		foreach ( $buckets as $bucket ) {
			foreach ( $bucket as list( $type, $arg, $decl ) ) {
				if ( 'root' === $type ) {
					// body/html rules: apply font/color/background to the wrapper div.
					$apply( $root, $decl );
					continue;
				}
				if ( 'id' === $type ) {
					$nodes = $xpath->query( ".//*[@id='" . $arg . "']", $root );
				} elseif ( 'class' === $type ) {
					list( $tag, $class ) = $arg;
					$q     = ( $tag ? './/' . $tag : './/*' ) . "[contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')]";
					$nodes = $xpath->query( $q, $root );
				} else {
					$nodes = $xpath->query( './/' . $arg, $root );
				}
				if ( $nodes ) {
					foreach ( $nodes as $node ) {
						if ( $node instanceof \DOMElement ) {
							$apply( $node, $decl );
						}
					}
				}
			}
		}

		// Serialize: keep the wrapper div only when body/html rules landed on it.
		$root_style = trim( (string) $root->getAttribute( 'style' ) );
		$inner      = '';
		foreach ( $root->childNodes as $child ) {
			$inner .= $dom->saveHTML( $child );
		}
		if ( '' !== $root_style ) {
			return '<div style="' . htmlspecialchars( $root_style, ENT_QUOTES ) . '">' . $inner . '</div>';
		}
		return trim( $inner );
	}

	private function strip_generated_footer( string $html ): string {
		$patterns = array(
			'#<footer\b[^>]*>.*?</footer>#is',
			'#<tr\b[^>]*>\s*<td\b[^>]*>[^<]*(?:unsubscribe|sent to|copyright|all rights reserved|company address|preferences?)[\s\S]*?</td>\s*</tr>#is',
			'#<div\b[^>]*>[^<]*(?:unsubscribe|sent to|copyright|all rights reserved|company address|preferences?)[\s\S]*?</div>#is',
			'#<p\b[^>]*>[^<]*(?:unsubscribe|sent to|copyright|all rights reserved|company address|preferences?)[\s\S]*?</p>#is',
		);

		foreach ( $patterns as $pattern ) {
			$html = preg_replace( $pattern, '', $html ) ?? $html;
		}

		return trim( $html );
	}

	/* ── OPTIMIZE SUBJECT LINE ───────────────────────── */

	public function optimize_subject( \WP_REST_Request $request ): \WP_REST_Response {
		$subject       = sanitize_text_field( $request->get_param( 'subject' ) ?: '' );
		$audience      = sanitize_text_field( $request->get_param( 'audience' ) ?: 'general' );
		$campaign_name = sanitize_text_field( $request->get_param( 'campaign_name' ) ?: '' );
		$content       = wp_strip_all_tags( wp_kses_post( $request->get_param( 'content' ) ?: '' ) );
		$content       = mb_substr( trim( preg_replace( '/\s+/', ' ', $content ) ), 0, 1500 );

		// Two jobs behind one button. With a subject typed, the email body is
		// context for rewriting the line the user already has — their angle is
		// the brief. With the field empty, the body IS the brief and the AI
		// writes the first line from scratch, which is what a writer would do
		// rather than refusing until you type something to improve.
		$brief = ( $campaign_name ? "Campaign name: {$campaign_name}\n" : '' )
			. ( $content ? "Email body:\n{$content}\n" : '' );

		if ( $subject ) {
			$prompt = "You are an email marketing expert specializing in subject lines. "
				. "Improve this email subject line for maximum open rates. Audience: {$audience}.\n\n"
				. "Original subject: \"{$subject}\"\n"
				. ( $brief ? "\n{$brief}" : '' )
				. "\nKeep the angle and promise of the original subject, and make sure every "
				. "suggestion is true to the email body above.\n\n"
				. "Return a JSON object with these keys:\n"
				. "- \"suggestions\": array of 5 improved subject lines\n"
				. "- \"analysis\": brief analysis of the original (2-3 sentences)\n"
				. "- \"score\": 1-100 rating of the original\n"
				. "- \"best_pick\": index (0-based) of your top recommendation\n"
				. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";
		} else {
			if ( ! $brief ) {
				return new \WP_REST_Response(
					array( 'message' => __( 'Write the email body (or a subject line) first so the AI has something to work from.', 'ai-marketing-expert' ) ),
					400
				);
			}
			$prompt = "You are an email marketing expert specializing in subject lines. "
				. "Write subject lines for this email from scratch. Audience: {$audience}.\n\n"
				. $brief
				. "\nEach subject line must describe what is actually in the email body above. "
				. "Under 60 characters, no clickbait that the body does not deliver.\n\n"
				. "Return a JSON object with these keys:\n"
				. "- \"suggestions\": array of 5 subject lines\n"
				. "- \"analysis\": brief note on the angle you took (2-3 sentences)\n"
				. "- \"best_pick\": index (0-based) of your top recommendation\n"
				. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";
		}

		$result = AiProvider::generate( $prompt, 'text', 2048 );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ) ), 500 );
		}

		$parsed = $this->parse_json_response( $result['content'] );

		return new \WP_REST_Response( $parsed ?: array( 'raw' => $result['content'] ) );
	}

	/* ── GENERATE PREVIEW TEXT ────────────────────────── */

	public function generate_preview_text( \WP_REST_Request $request ): \WP_REST_Response {
		$subject       = sanitize_text_field( $request->get_param( 'subject' ) ?: '' );
		$campaign_name = sanitize_text_field( $request->get_param( 'campaign_name' ) ?: '' );
		$current       = sanitize_text_field( $request->get_param( 'current' ) ?: '' );
		$content       = wp_strip_all_tags( wp_kses_post( $request->get_param( 'content' ) ?: '' ) );
		$content       = mb_substr( trim( preg_replace( '/\s+/', ' ', $content ) ), 0, 1200 );

		$prompt = "You are an email marketing copywriter. Write inbox preview text snippets that complement the subject line without repeating it exactly.\n\n"
			. ( $campaign_name ? "Campaign name: {$campaign_name}\n" : '' )
			. ( $subject ? "Subject line: \"{$subject}\"\n" : '' )
			. ( $content ? "Email content excerpt: {$content}\n" : '' )
			// Text already in the field is the user's angle, not a draft to
			// discard — rewrite around it instead of replacing it wholesale.
			. ( $current ? "The user already wrote this preview text: \"{$current}\". Keep its angle and wording where you can; every option must still match the email content above.\n" : '' )
			. "\nReturn a JSON object with these keys:\n"
			. "- \"suggestions\": array of 5 preview text options, each 35-90 characters\n"
			. "- \"best_pick\": index (0-based) of your top recommendation\n"
			. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";

		$result = AiProvider::generate( $prompt, 'text', 1024 );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ) ), 500 );
		}

		$parsed = $this->parse_json_response( $result['content'] );

		return new \WP_REST_Response( $parsed ?: array( 'raw' => $result['content'] ) );
	}

	/* ── SCORE EMAIL CONTENT ─────────────────────────── */

	public function score_content( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array( 'message' => __( 'Content Scorer is available in Pro.', 'ai-marketing-expert' ) ), 403 );
		}

		$content = wp_kses_post( $request->get_param( 'content' ) );
		$subject = sanitize_text_field( $request->get_param( 'subject' ) ?: '' );

		$prompt = "You are an email deliverability and content expert. Score this email for marketing effectiveness.\n\n"
			. ( $subject ? "Subject: \"{$subject}\"\n\n" : '' )
			. "Email HTML:\n" . mb_substr( wp_strip_all_tags( $content ), 0, 3000 ) . "\n\n"
			. "Return a JSON object:\n"
			. "- \"overall_score\": 1-100\n"
			. "- \"spam_score\": 1-100 (lower = better, likelihood of hitting spam)\n"
			. "- \"readability_score\": 1-100\n"
			. "- \"engagement_score\": 1-100\n"
			. "- \"issues\": array of strings (problems found)\n"
			. "- \"improvements\": array of strings (specific suggestions)\n"
			. "- \"summary\": 2-sentence overview\n"
			. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";

		$result = AiProvider::generate( $prompt, 'text', 2048 );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ) ), 500 );
		}

		$parsed = $this->parse_json_response( $result['content'] );

		return new \WP_REST_Response( $parsed ?: array( 'raw' => $result['content'] ) );
	}

	/* ── SEND TIME OPTIMIZER ─────────────────────────── */

	public function optimize_send_time( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array( 'message' => __( 'Send Time Optimizer is available in Pro.', 'ai-marketing-expert' ) ), 403 );
		}

		global $wpdb;
		$p = $wpdb->prefix;
		$industry = sanitize_text_field( $request->get_param( 'industry' ) ?: 'general' );

		// Gather historical data for the AI.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$opens_by_hour = $wpdb->get_results(
			"SELECT HOUR(ce.updated_at) AS hour, COUNT(*) AS opens
			 FROM {$p}aime_campaign_emails ce
			 WHERE ce.is_open = 1
			 GROUP BY HOUR(ce.updated_at)
			 ORDER BY hour ASC"
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$opens_by_day = $wpdb->get_results(
			"SELECT DAYNAME(ce.updated_at) AS day_name, COUNT(*) AS opens
			 FROM {$p}aime_campaign_emails ce
			 WHERE ce.is_open = 1
			 GROUP BY DAYNAME(ce.updated_at)
			 ORDER BY opens DESC"
		);

		$has_data = ! empty( $opens_by_hour ) || ! empty( $opens_by_day );

		if ( $has_data ) {
			$data = "Historical email open data:\n"
				. "Opens by hour of day: " . wp_json_encode( $opens_by_hour ) . "\n"
				. "Opens by day of week: " . wp_json_encode( $opens_by_day ) . "\n";

			$prompt = "You are an email marketing data analyst. Based on this historical open data, recommend the optimal send times.\n\n"
				. "Industry: {$industry}\n\n"
				. $data . "\n";
		} else {
			$prompt = "You are an email marketing data analyst. There is no historical email data yet. "
				. "Based on industry best practices and research for the \"{$industry}\" industry, recommend the optimal email send times.\n\n";
		}

		$prompt .= "Return a JSON object:\n"
			. "- \"best_day\": string (best day of the week, e.g. \"Tuesday\")\n"
			. "- \"best_hour\": number (0-23, best hour)\n"
			. "- \"best_times\": array of {day, hour, confidence} top 5 slots\n"
			. "- \"avoid_times\": array of {day, hour} worst 3 slots\n"
			. "- \"analysis\": 3-sentence analysis" . ( ! $has_data ? ' mentioning these are industry benchmarks, not based on user data' : '' ) . "\n"
			. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";

		$result = AiProvider::generate( $prompt, 'text', 2048 );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ) ), 500 );
		}

		$parsed = $this->parse_json_response( $result['content'] );

		return new \WP_REST_Response( array_merge(
			$parsed ?: array( 'raw' => $result['content'] ),
			array(
				'has_data'      => $has_data,
				'opens_by_hour' => $opens_by_hour,
				'opens_by_day'  => $opens_by_day,
			)
		) );
	}

	/* ── SUBSCRIBER INSIGHTS ─────────────────────────── */

	public function subscriber_insights( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );

		$sub = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}aime_subscribers WHERE id = %d", $id ) );
		if ( ! $sub ) {
			return new \WP_REST_Response( array( 'message' => __( 'Subscriber not found.', 'ai-marketing-expert' ) ), 404 );
		}

		// Gather engagement data.
		$emails_sent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_campaign_emails WHERE subscriber_id = %d", $id ) );
		$emails_opened = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_campaign_emails WHERE subscriber_id = %d AND is_open = 1", $id ) );
		$total_clicks = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_campaign_url_metrics WHERE subscriber_id = %d AND type = 'click'", $id ) );

		$tags = $wpdb->get_col( $wpdb->prepare(
			"SELECT t.title FROM {$p}aime_tags t INNER JOIN {$p}aime_subscriber_pivot sp ON sp.object_id = t.id AND sp.object_type = 'tag' WHERE sp.subscriber_id = %d", $id
		) );

		$data = "Subscriber data:\n"
			. "Email: {$sub->email}, Name: {$sub->first_name} {$sub->last_name}\n"
			. "Status: {$sub->status}, Source: {$sub->source}\n"
			. "Created: {$sub->created_at}, Last activity: {$sub->last_activity}\n"
			. "Emails sent: {$emails_sent}, Opened: {$emails_opened}, Clicks: {$total_clicks}\n"
			. "Tags: " . implode( ', ', $tags ) . "\n";

		$prompt = "You are an email marketing analyst. Analyze this subscriber and provide insights.\n\n"
			. $data . "\n"
			. "Return a JSON object:\n"
			. "- \"engagement_level\": 'high' | 'medium' | 'low' | 'inactive'\n"
			. "- \"engagement_score\": 1-100\n"
			. "- \"churn_risk\": 'low' | 'medium' | 'high'\n"
			. "- \"recommended_actions\": array of 3-5 specific action strings\n"
			. "- \"suggested_tags\": array of tag names to add\n"
			. "- \"best_content_type\": string (what content type would engage them)\n"
			. "- \"summary\": 2-sentence profile summary\n"
			. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";

		$result = AiProvider::generate( $prompt, 'text', 2048 );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ) ), 500 );
		}

		$parsed = $this->parse_json_response( $result['content'] );

		return new \WP_REST_Response( array_merge(
			$parsed ?: array( 'raw' => $result['content'] ),
			array(
				'emails_sent'   => $emails_sent,
				'emails_opened' => $emails_opened,
				'total_clicks'  => $total_clicks,
			)
		) );
	}

	/* ── CAMPAIGN RECOMMENDATIONS ────────────────────── */

	public function campaign_recommendations( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		// Gather recent campaign performance.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recent = $wpdb->get_results(
			"SELECT c.id, c.title, c.status,
				COUNT(ce.id) AS sent,
				SUM(CASE WHEN ce.is_open = 1 THEN 1 ELSE 0 END) AS opened,
				SUM(ce.click_counter) AS clicks
			 FROM {$p}aime_campaigns c
			 LEFT JOIN {$p}aime_campaign_emails ce ON ce.campaign_id = c.id
			 WHERE c.status IN ('sent', 'working')
			 GROUP BY c.id
			 ORDER BY c.created_at DESC LIMIT 10"
		);

		$sub_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_subscribers WHERE status = 'subscribed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tags      = $wpdb->get_col( "SELECT title FROM {$p}aime_tags ORDER BY id DESC LIMIT 20" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$lists     = $wpdb->get_col( "SELECT title FROM {$p}aime_lists ORDER BY id DESC LIMIT 20" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$data = "Recent campaigns: " . wp_json_encode( $recent ) . "\n"
			. "Active subscribers: {$sub_count}\n"
			. "Tags: " . implode( ', ', $tags ) . "\n"
			. "Lists: " . implode( ', ', $lists ) . "\n";

		$prompt = "You are a senior email marketing strategist. Based on this data, recommend next campaigns to create.\n\n"
			. $data . "\n"
			. "Return a JSON object:\n"
			. "- \"recommendations\": array of 3-5 objects with {title, description, target_segment, subject_line, goal}\n"
			. "- \"performance_summary\": 2-sentence summary of recent campaign performance\n"
			. "- \"key_insight\": one actionable insight\n"
			. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";

		$result = AiProvider::generate( $prompt, 'text', 2048 );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ) ), 500 );
		}

		return new \WP_REST_Response( $this->parse_json_response( $result['content'] ) ?: array( 'raw' => $result['content'] ) );
	}

	/* ── SPAM CHECK ──────────────────────────────────── */

	public function spam_check( \WP_REST_Request $request ): \WP_REST_Response {
		$subject = sanitize_text_field( $request->get_param( 'subject' ) ?: '' );
		$content = wp_kses_post( $request->get_param( 'content' ) ?: '' );
		$from    = sanitize_text_field( $request->get_param( 'from_name' ) ?: '' );

		$prompt = "You are an email deliverability expert. Analyze this email for spam triggers and deliverability issues.\n\n"
			. "From: {$from}\n"
			. "Subject: {$subject}\n"
			. "Content (text):\n" . mb_substr( wp_strip_all_tags( $content ), 0, 3000 ) . "\n\n"
			. "Return a JSON object:\n"
			. "- \"spam_score\": 0-10 (0 = clean, 10 = definite spam)\n"
			. "- \"verdict\": 'clean' | 'warning' | 'risky' | 'spam'\n"
			. "- \"triggers\": array of {issue, severity, recommendation} spam triggers found\n"
			. "- \"word_flags\": array of spam-trigger words found in the content\n"
			. "- \"improvements\": array of specific suggestions to improve deliverability\n"
			. "- \"authentication_tips\": array of SPF/DKIM/DMARC tips\n"
			. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";

		$result = AiProvider::generate( $prompt, 'text', 2048 );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ) ), 500 );
		}

		return new \WP_REST_Response( $this->parse_json_response( $result['content'] ) ?: array( 'raw' => $result['content'] ) );
	}

	/* ── REWRITE WITHOUT SPAM WORDS ──────────────────── */

	public function rewrite_without_spam( \WP_REST_Request $request ): \WP_REST_Response {
		$subject    = sanitize_text_field( $request->get_param( 'subject' ) ?: '' );
		$content    = wp_kses_post( $request->get_param( 'content' ) ?: '' );
		$spam_words = $request->get_param( 'spam_words' );
		if ( ! is_array( $spam_words ) ) {
			$spam_words = array();
		}
		$spam_words = array_map( 'sanitize_text_field', $spam_words );

		$word_list = implode( ', ', $spam_words );

		$prompt = "You are an email copywriter. Rewrite this email to remove or replace all spam trigger words while keeping the same meaning, tone, and length.\n\n"
			. "Spam words to remove: {$word_list}\n\n"
			. "Subject: {$subject}\n"
			. "Content:\n" . mb_substr( $content, 0, 3000 ) . "\n\n"
			. "Return a JSON object:\n"
			. "- \"subject\": rewritten subject line\n"
			. "- \"content\": rewritten email content\n"
			. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";

		$result = AiProvider::generate( $prompt, 'text', 4096 );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ) ), 500 );
		}

		return new \WP_REST_Response( $this->parse_json_response( $result['content'] ) ?: array( 'raw' => $result['content'] ) );
	}

	/* ── AUTOMATION SUGGESTIONS ───────────────────────── */

	public function automation_suggestions( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		$existing = $wpdb->get_col( "SELECT title FROM {$p}aime_funnels LIMIT 20" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tags     = $wpdb->get_col( "SELECT title FROM {$p}aime_tags ORDER BY id DESC LIMIT 20" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$lists    = $wpdb->get_col( "SELECT title FROM {$p}aime_lists ORDER BY id DESC LIMIT 20" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$instruction = sanitize_textarea_field( $request->get_param( 'instruction' ) ?? '' );
		$goal        = sanitize_text_field( $request->get_param( 'goal' ) ?? '' );
		$trigger     = sanitize_key( $request->get_param( 'trigger' ) ?? '' );

		if ( '' === $instruction ) {
			$instruction = $goal ?: __( 'Create a practical email automation sequence.', 'ai-marketing-expert' );
		}

		$prompt = "You are an email automation expert. Create one practical linear email automation based on the user's instruction.\n\n"
			. "User instruction: {$instruction}\n"
			. "Current automation title/goal: {$goal}\n"
			. "Selected trigger, if any: {$trigger}\n"
			. "Existing automations: " . implode( ', ', $existing ) . "\n"
			. "Available tags: " . implode( ', ', $tags ) . "\n"
			. "Available lists: " . implode( ', ', $lists ) . "\n"
			. "Supported triggers: subscriber_created, subscriber_status_change, tag_added, tag_removed, list_added, list_removed, form_submitted, link_clicked, email_opened, woo_order_completed, user_registered.\n"
			. "Supported v1 actions: send_email, wait, add_tag, remove_tag, add_to_list, remove_from_list, update_contact, webhook, end. Do not use condition or if/else branching.\n"
			. "Use the selected trigger if it fits the instruction. Keep the automation linear and realistic for WordPress/email marketing.\n\n"
			. "Return a JSON object:\n"
			. "- \"suggestions\": array containing exactly 1 object with {title, description, trigger, steps (array of {action, delay_value, delay_unit, details, settings}), expected_impact}\n"
			. "For send_email steps, include settings.email_subject and settings.email_body. For add_tag/remove_tag/add_to_list/remove_from_list steps, only suggest the action and details unless an exact available tag/list clearly matches.\n"
			. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";

		$result = AiProvider::generate( $prompt, 'text', 3072 );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ) ), 500 );
		}

		return new \WP_REST_Response( $this->parse_json_response( $result['content'] ) ?: array( 'raw' => $result['content'] ) );
	}

	/* ── SEGMENT SUGGESTIONS ─────────────────────────── */

	public function segment_suggestions( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array( 'message' => __( 'Segment Suggestions are available in Pro.', 'ai-marketing-expert' ) ), 403 );
		}

		global $wpdb;
		$p = $wpdb->prefix;

		$sub_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_subscribers" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tags       = $wpdb->get_col( "SELECT title FROM {$p}aime_tags" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$lists      = $wpdb->get_col( "SELECT title FROM {$p}aime_lists" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$statuses   = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM {$p}aime_subscribers GROUP BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cf         = $wpdb->get_col( "SELECT label FROM {$p}aime_custom_fields" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$prompt = "You are a CRM segmentation expert. Suggest useful subscriber segments.\n\n"
			. "Total subscribers: {$sub_count}\n"
			. "Tags: " . implode( ', ', $tags ) . "\n"
			. "Lists: " . implode( ', ', $lists ) . "\n"
			. "Statuses: " . wp_json_encode( $statuses ) . "\n"
			. "Custom fields: " . implode( ', ', $cf ) . "\n\n"
			. "Return a JSON object:\n"
			. "- \"segments\": array of 5 objects with {name, description, criteria (human-readable), estimated_size, use_case}\n"
			. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";

		$result = AiProvider::generate( $prompt, 'text', 2048 );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ) ), 500 );
		}

		return new \WP_REST_Response( $this->parse_json_response( $result['content'] ) ?: array( 'raw' => $result['content'] ) );
	}

	/* ── A/B TEST ANALYSIS ───────────────────────────── */

	public function ab_test_analysis( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array( 'message' => __( 'A/B test analysis is available in Pro.', 'ai-marketing-expert' ) ), 403 );
		}

		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );

		// Get parent + variants.
		$parent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}aime_campaigns WHERE id = %d", $id ) );
		if ( ! $parent ) {
			return new \WP_REST_Response( array( 'message' => __( 'Campaign not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$variants = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$p}aime_campaigns WHERE parent_id = %d", $id )
		);

		$all = array_merge( array( $parent ), $variants );
		$data = array();

		foreach ( $all as $c ) {
			$stats = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS sent,
					SUM(CASE WHEN is_open = 1 THEN 1 ELSE 0 END) AS opened,
					SUM(CASE WHEN click_counter > 0 THEN 1 ELSE 0 END) AS clicks
				 FROM {$p}aime_campaign_emails WHERE campaign_id = %d AND status = 'sent'",
				$c->id
			) );

			$sent = (int) ( $stats->sent ?? 0 );
			$data[] = array(
				'id'         => $c->id,
				'title'      => $c->title,
				'subject'    => $c->email_subject,
				'sent'       => $sent,
				'opened'     => (int) ( $stats->opened ?? 0 ),
				'clicks'     => (int) ( $stats->clicks ?? 0 ),
				'open_rate'  => $sent > 0 ? round( ( (int) $stats->opened / $sent ) * 100, 1 ) : 0,
				'click_rate' => $sent > 0 ? round( ( (int) $stats->clicks / $sent ) * 100, 1 ) : 0,
			);
		}

		$prompt = "You are a data-driven email marketing analyst. Analyze this A/B test result and declare a winner.\n\n"
			. "Variants: " . wp_json_encode( $data ) . "\n\n"
			. "Return a JSON object:\n"
			. "- \"winner_id\": number (campaign id of the winner)\n"
			. "- \"confidence\": 'low' | 'medium' | 'high'\n"
			. "- \"reason\": 2-sentence explanation\n"
			. "- \"recommendations\": array of 2-3 action items\n"
			. "- \"statistical_note\": string (note about sample size or significance)\n"
			. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";

		$result = AiProvider::generate( $prompt, 'text', 2048 );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ) ), 500 );
		}

		$analysis = $this->parse_json_response( $result['content'] );

		return new \WP_REST_Response( array(
			'variants' => $data,
			'analysis' => $analysis ?: array( 'raw' => $result['content'] ),
		) );
	}

	/* ── HELPERS ─────────────────────────────────────── */

	private function parse_json_response( string $raw ): ?array {
		// Stage 0 — Strip extended-thinking blocks before any JSON parsing.
		$raw = trim( aime_strip_thinking_text( $raw ) );

		// Stage 1 — Direct decode.
		$decoded = json_decode( $raw, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
			return $decoded;
		}

		// Stage 2 — Extract from fenced code block.
		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $raw, $m ) ) {
			$decoded = json_decode( trim( $m[1] ), true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				return $decoded;
			}
		}

		// Stage 3 — Find matching braces to extract the actual JSON object.
		// This handles AI models that output "thinking" text before/after the JSON.
		$start = strpos( $raw, '{' );
		while ( $start !== false ) {
			$depth = 0;
			$in_string = false;
			$escape = false;
			$len = strlen( $raw );
			for ( $i = $start; $i < $len; $i++ ) {
				$ch = $raw[ $i ];
				if ( $escape ) {
					$escape = false;
					continue;
				}
				if ( '\\' === $ch && $in_string ) {
					$escape = true;
					continue;
				}
				if ( '"' === $ch ) {
					$in_string = ! $in_string;
					continue;
				}
				if ( $in_string ) {
					continue;
				}
				if ( '{' === $ch ) {
					$depth++;
				} elseif ( '}' === $ch ) {
					$depth--;
					if ( 0 === $depth ) {
						$candidate = substr( $raw, $start, $i - $start + 1 );
						$decoded   = json_decode( $candidate, true );
						if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
							return $decoded;
						}
						break;
					}
				}
			}
			// Try next '{' if this one failed.
			$start = strpos( $raw, '{', $start + 1 );
		}

		// Stage 4 — Fix unescaped newlines inside JSON string values.
		if ( preg_match( '/\{[\s\S]*\}/', $raw, $m ) ) {
			$fixed = preg_replace_callback(
				'/"((?:[^"\\\\]|\\\\.)*?)"/',
				function ( $match ) {
					$inner = $match[1];
					$inner = str_replace( array( "\r\n", "\r", "\n", "\t" ), array( '\\n', '\\n', '\\n', '\\t' ), $inner );
					return '"' . $inner . '"';
				},
				$m[0]
			);
			if ( $fixed ) {
				$decoded = json_decode( $fixed, true );
				if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		return null;
	}
}
