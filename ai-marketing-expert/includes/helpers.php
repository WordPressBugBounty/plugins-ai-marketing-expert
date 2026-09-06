<?php
/**
 * Global helper functions for AI Marketing Expert.
 *
 * @package WPSpace\AiMarketingExpert
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if the Pro addon is active and licensed.
 * In dev mode (AIME_DEV_MODE = true), always returns true.
 *
 * @return bool
 */
function aime_has_pro(): bool {
	if ( defined( 'AIME_DEV_MODE' ) && AIME_DEV_MODE ) {
		return true;
	}
	return (bool) apply_filters( 'aime_has_pro', false );
}

/**
 * Check if a specific module has pro features available.
 *
 * @param string $module_id Module identifier.
 * @return bool
 */
function aime_module_has_pro( string $module_id ): bool {
	return (bool) apply_filters( "aime_{$module_id}_has_pro", aime_has_pro() );
}

/**
 * Get a plugin option.
 *
 * @param string $key     Option key.
 * @param mixed  $default Default value.
 * @return mixed
 */
function aime_get_option( string $key, $default = null ) {
	$options = get_option( 'aime_settings', array() );
	return $options[ $key ] ?? $default;
}

/**
 * Update a plugin option.
 *
 * @param string $key   Option key.
 * @param mixed  $value Option value.
 * @return bool
 */
function aime_update_option( string $key, $value ): bool {
	$options         = get_option( 'aime_settings', array() );
	$options[ $key ] = $value;
	$updated = update_option( 'aime_settings', $options, false );
	aime_clear_settings_cache( array( 'aime_settings' ) );
	return $updated;
}

/**
 * Read a plugin option directly from the database, bypassing stale object cache.
 * Use this only for admin-critical settings where persistent object caches can
 * serve stale values after a write.
 *
 * @param string $option_name Option name.
 * @param mixed  $default     Default value.
 * @return mixed
 */
function aime_get_db_option( string $option_name, $default = array() ) {
	global $wpdb;

	$raw = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			$option_name
		)
	);

	if ( null === $raw ) {
		return $default;
	}

	$value = maybe_unserialize( $raw );
	return null === $value ? $default : $value;
}

/**
 * Clear plugin settings caches after writes.
 *
 * Always clears the WordPress options object cache for the given option
 * names. Full page-cache purging (LiteSpeed, WP Rocket, W3TC, …) is opt-in:
 * almost no plugin setting affects public page HTML, so purging the whole
 * site's page cache on every settings save needlessly destroys cache hit
 * rates. Pass $purge_page_cache = true only for settings that change
 * front-end output (e.g. the public chatbot widget's appearance/enabled
 * state, or enabling/disabling a module with a front-end footprint).
 *
 * @param array $option_names     Option names to clear.
 * @param bool  $purge_page_cache Also purge site page caches. Default false.
 * @return void
 */
function aime_clear_settings_cache( array $option_names = array(), bool $purge_page_cache = false ): void {
	$option_names = array_values( array_unique( array_filter( array_merge(
		$option_names,
		array( 'aime_settings' )
	) ) ) );

	foreach ( $option_names as $option_name ) {
		wp_cache_delete( $option_name, 'options' );
	}

	// Note: the 'alloptions' cache entry is deliberately NOT deleted here.
	// WordPress core keeps it in sync on every update_option()/delete_option(),
	// and evicting it wholesale forces every subsequent request to reload all
	// autoloaded options from the database (audit P-2).

	if ( ! $purge_page_cache ) {
		return;
	}

	if ( has_action( 'litespeed_purge_all' ) ) {
		do_action( 'litespeed_purge_all' );
	}

	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}

	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}

	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}

	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
	}

	if ( class_exists( 'autoptimizeCache' ) && is_callable( array( 'autoptimizeCache', 'clearall' ) ) ) {
		autoptimizeCache::clearall();
	}
}

/**
 * Get the plugin's REST API namespace.
 *
 * @return string
 */
function aime_rest_namespace(): string {
	return 'aime/v1';
}

/**
 * Log a message to the plugin log table.
 *
 * @param string $message  Log message.
 * @param string $level    Log level (info, warning, error).
 * @param string $module   Module ID.
 * @param array  $context  Additional context.
 */
function aime_log( string $message, string $level = 'info', string $module = 'core', array $context = array() ): void {
	global $wpdb;

	static $table_exists = null;

	$table = $wpdb->prefix . 'aime_log';

	// Check table existence once per request, not per log call (audit P-3).
	if ( null === $table_exists ) {
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	if ( ! $table_exists ) {
		// Fallback to error_log.
		error_log( sprintf( '[AIME][%s][%s] %s', strtoupper( $level ), $module, $message ) );
		return;
	}

	$wpdb->insert(
		$table,
		array(
			'module_id'  => sanitize_key( $module ),
			'level'      => sanitize_key( $level ),
			'message'    => sanitize_text_field( $message ),
			'context'    => wp_json_encode( $context ),
			'created_at' => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s', '%s', '%s' )
	);
	// Pruning happens on the aime_daily_cleanup cron (aime_prune_logs), not
	// per-write — see audit P-3.
}

/**
 * Prune the plugin log table. Hooked to the aime_daily_cleanup cron.
 *
 * Removes entries older than 30 days, then trims the table to the newest
 * 2,000 rows so the debug-log UI stays useful without unbounded growth.
 */
function aime_prune_logs(): void {
	global $wpdb;

	$table = $wpdb->prefix . 'aime_log';

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return;
	}

	/**
	 * Filter the maximum number of log rows kept after pruning.
	 *
	 * @param int $max_rows Row cap. Default 2000.
	 */
	$max_rows = max( 100, (int) apply_filters( 'aime_log_max_rows', 2000 ) );

	// Remove entries older than 30 days.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE created_at < %s',
			$table,
			gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) )
		)
	);

	$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	if ( $count > $max_rows ) {
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE id NOT IN ( SELECT id FROM ( SELECT id FROM %i ORDER BY created_at DESC LIMIT %d ) AS keep_rows )',
				$table,
				$table,
				$max_rows
			)
		);
	}
}

/**
 * Get free plan limits.
 *
 * @return array
 */
function aime_free_limits(): array {
	return apply_filters( 'aime_free_limits', array(
		'email_subscribers'          => \AIME_FREE_SUBSCRIBER_LIMIT,
		'email_lists'                => 10,
		'email_templates'            => 3,
		'email_template_imports_free' => 4,
		'email_scheduled_campaigns'  => 3,
		'email_funnels'              => 2,
		'email_smtp_connections'     => 2,
		'ai_provider_connections'     => 2,
		'ai_jobs_queued'             => 5,
		'csv_import_rows'            => 100,
		'campaigns_per_month'        => 30,
		'content_articles_per_month' => 20,
		'content_max_words'          => 2000,
		'content_presets'            => 4,
		'content_scheduled_posts'    => 3,
		// Chatbot.
		'chatbot_bots'                    => 1,
		'chatbot_conversations_monthly'   => 100,
		'chatbot_knowledge_qa'            => 50,
		'chatbot_knowledge_pages'         => 50,
		'chatbot_themes'                  => 3,
		'chatbot_history_days'            => 7,
		// Social Media.
		'social_accounts'                 => 2,
		'social_posts_per_month'          => 30,
		'social_scheduled_posts'          => 3,
		'social_ai_captions'              => 30,
		// SEO.
		'seo_keyword_research_monthly'    => 10,
		'seo_keywords_saved'              => 50,
		'seo_audits_monthly'              => 5,
		'seo_rank_keywords'               => 5,
		'seo_niche_analysis_monthly'      => 3,
		'seo_competitor_gap_monthly'      => 3,
		'seo_content_brief_monthly'       => 5,
		'seo_topics'                      => 10,
		'seo_topic_map_generate_monthly'  => 1,
		'seo_calendar_items'              => 10,
		'seo_calendar_generate_monthly'   => 1,
		'seo_backlink_prospects'          => 10,
		'seo_outreach_monthly'            => 3,
		'seo_automation_tasks'            => 1,
		'seo_automation_runs_monthly'     => 10,
		// Workflow Automation.
		'workflows_active'                => 2,
		'workflow_steps'                  => 3,
		'workflow_runs_monthly'           => 30,
	) );
}

/**
 * Check if a free limit has been reached.
 *
 * @param string $limit_key Limit key.
 * @param int    $current   Current count.
 * @return bool True if limit reached.
 */
function aime_limit_reached( string $limit_key, int $current ): bool {
	if ( aime_has_pro() ) {
		return false;
	}

	$limits = aime_free_limits();
	$limit  = $limits[ $limit_key ] ?? PHP_INT_MAX;

	return $current >= $limit;
}

/**
 * Get the number of uses remaining for a free-plan limit.
 *
 * @param string $limit_key Limit key.
 * @param int    $current   Current count.
 * @return int|null Remaining uses, or null when unlimited (Pro).
 */
function aime_limit_remaining( string $limit_key, int $current ): ?int {
	if ( aime_has_pro() ) {
		return null;
	}

	$limit = aime_free_limits()[ $limit_key ] ?? PHP_INT_MAX;

	return max( 0, $limit - $current );
}

/**
 * Build a usage payload for the admin UI.
 *
 * A null limit means unlimited, which the UI renders as no meter at all.
 *
 * @param string $limit_key Limit key.
 * @param int    $current   Current count.
 * @return array{used:int,limit:int|null}
 */
function aime_usage_payload( string $limit_key, int $current ): array {
	return array(
		'used'  => $current,
		'limit' => aime_has_pro() ? null : ( aime_free_limits()[ $limit_key ] ?? null ),
	);
}

/**
 * Sanitize and validate an email address.
 *
 * @param string $email Email address.
 * @return string|false Sanitized email or false.
 */
function aime_sanitize_email( string $email ) {
	$email = sanitize_email( $email );
	return is_email( $email ) ? $email : false;
}

/**
 * Get plugin asset URL.
 *
 * @param string $path Relative path to asset.
 * @return string Full URL.
 */
function aime_asset_url( string $path ): string {
	return AIME_PLUGIN_URL . ltrim( $path, '/' );
}

/**
 * Get the asset version, cache-busted on every dev-mode page load.
 *
 * @param string|null $version Production asset version.
 * @return string
 */
function aime_asset_version( ?string $version = null ): string {
	if ( defined( 'AIME_DEV_MODE' ) && AIME_DEV_MODE ) {
		return (string) time();
	}

	return $version ?: AIME_VERSION;
}

/**
 * Get current user's display name for emails.
 *
 * @return string
 */
function aime_get_sender_name(): string {
	$name = aime_get_option( 'from_name' );
	if ( $name ) {
		return $name;
	}
	return get_bloginfo( 'name' );
}

/**
 * Get sender email address.
 *
 * @return string
 */
function aime_get_sender_email(): string {
	$email = aime_get_option( 'from_email' );
	if ( $email ) {
		return $email;
	}
	return get_option( 'admin_email' );
}
/**
 * Parse a JSON response from an AI model.
 *
 * Handles thinking/reasoning models that inject chain-of-thought text
 * before, after, or even inside JSON output.
 *
 * Stages:
 *  1. Direct json_decode.
 *  2. Extract from fenced code block.
 *  3. Brace-matching to isolate { … } or [ … ] structures.
 *  3b. Repair: strip thinking prefix injected inside a JSON object.
 *  4. Fix unescaped newlines inside JSON string values.
 *
 * @param string $raw The raw AI response text.
 * @return array|null Parsed array or null on failure.
 */

/**
 * Strip chain-of-thought / reasoning text that some AI models prepend
 * before the actual JSON output, plus AI safety-classification lines
 * (e.g. "User Safety: safe", "Category: ...") that some Gemini-family
 * models append to the response.
 *
 * Finds the first plausible JSON start ( { or [ ) and strips everything
 * before it when the leading text looks like natural-language reasoning.
 *
 * Modes:
 * - 'json' (default): full cleanup, including chatty-preamble removal and
 *   the walk-to-first-{/[ prefix strip. Only safe when the caller expects
 *   a JSON (or HTML) payload, never conversational prose.
 * - 'text': conversational output (e.g. chatbot replies). Only strips
 *   reasoning that must never render (<think> blocks, scratchpad tags,
 *   safety-classification lines). The JSON-prefix heuristics are skipped —
 *   a plain-text reply containing "[LEAD_CAPTURE]", a markdown link, or a
 *   "{" would otherwise lose everything before that character.
 *
 * @param string $raw  Raw AI response.
 * @param string $mode 'json' (default) or 'text'.
 * @return string Cleaned text.
 */
function aime_strip_thinking_text( string $raw, string $mode = 'json' ): string {
	$raw = trim( $raw );

	if ( '' === $raw ) {
		return $raw;
	}

	// Strip <think>…</think> blocks (DeepSeek, Qwen, and other open models).
	$raw = preg_replace( '/<think>[\s\S]*?<\/think>\s*/i', '', $raw );
	$raw = trim( $raw );

	// Strip an orphan closing </think> (model emitted reasoning without an
	// opening tag, or the opening tag was truncated). Drop everything up to
	// and including the last closing tag so leaked reasoning never renders.
	if ( false !== stripos( $raw, '</think>' ) ) {
		$raw = preg_replace( '/^[\s\S]*<\/think>\s*/i', '', $raw );
		$raw = trim( $raw );
	}
	// Strip an orphan opening <think> with no close (reasoning ran to the end).
	if ( false !== stripos( $raw, '<think>' ) ) {
		$raw = preg_replace( '/<think>[\s\S]*$/i', '', $raw );
		$raw = trim( $raw );
	}

	// Bracket-style reasoning markers some models emit instead of XML tags:
	// "[think] … [/think]" pairs first.
	$raw = preg_replace( '/\[(think|thinking|reasoning|analysis)\][\s\S]*?\[\/\1\]\s*/i', '', $raw );
	// A standalone "[think]"-style marker line starts a reasoning block that
	// runs until the next standalone "[marker]" line (channel switch — the
	// marker itself is noise and is dropped too) or to the end of the text.
	if ( preg_match( '/^\s*\[(?:think|thinking|reasoning|analysis)\]\s*$/im', $raw ) ) {
		$raw = preg_replace(
			'/^[ \t]*\[(?:think|thinking|reasoning|analysis)\][ \t]*\r?\n[\s\S]*?(?:^[ \t]*\[[a-z0-9_\/-]+\][ \t]*(?:\r?\n|\z)|\z)/im',
			'',
			$raw
		);
	}
	$raw = trim( $raw );

	if ( '' === $raw ) {
		return $raw;
	}

	// Strip standalone AI safety-classification lines that some Gemini-family
	// models append to the response (e.g. "User Safety: safe", "Safety: safe",
	// "Category: ..."). Match the line anywhere — start, middle, or end.
	$raw = preg_replace( '/^[ \t]*(?:User\s+Safety|Safety|Harm\s+Category|Harm_Policy|Category|Severity)[ \t]*:[ \t]*[^\n\r]*[\n\r]?/im', '', $raw );
	$raw = preg_replace( '/(?:\n|\r)[ \t]*(?:User\s+Safety|Safety|Harm\s+Category|Harm_Policy|Category|Severity)[ \t]*:[ \t]*[^\n\r]*/i', '', $raw );
	// Trailing safety line at the very end of the string (no following newline
	// — common in short excerpt-style responses).
	$raw = preg_replace( '/[ \t]+(?:User\s+Safety|Safety|Harm\s+Category|Harm_Policy|Category|Severity)[ \t]*:[ \t]*[^\n\r<]*$/i', '', $raw );
	$raw = trim( $raw );

	if ( '' === $raw ) {
		return $raw;
	}

	// Strip <analysis>...</analysis>, <scratchpad>...</scratchpad> style blocks.
	// Leaked reasoning must never render, so this runs in every mode.
	$raw = preg_replace( '/<(?:analysis|scratchpad|internal_reasoning|reasoning_steps?|cot)[\s\S]*?<\/(?:analysis|scratchpad|internal_reasoning|reasoning_steps?|cot)>\s*/iu', '', $raw );
	$raw = trim( $raw );

	// Conversational mode stops here: everything below assumes the payload is
	// JSON/HTML and would truncate a plain-text reply at its first "{" or "["
	// (e.g. an action tag like [LEAD_CAPTURE] or a markdown link).
	if ( 'text' === $mode || '' === $raw ) {
		return $raw;
	}

	// === GLOBAL REASONING STRIPPERS (Model-Agnostic) ===
	// These patterns work for ANY model, present or future, without needing
	// to know specific thinking markers or prefixes.

	// Strip lines that look like model-reasoning metadata
	// (e.g. "Plan: - Step 1 ...", "Reasoning: ...", "Note: ...", or
	// bullet-point planning paragraphs that some models emit before content).
	$raw = preg_replace( '/^[ \t]*(?:Plan|Reasoning|Reasoning Steps?|Thought Process|Steps?|Approach|Strategy|My approach|My plan|Internal reasoning|Thinking|Analysis)[ \t]*:[ \t]*[^\n\r]*[\n\r]+/im', '', $raw );

	// Strip leading "Here is the JSON:" / "Here is the HTML:" / "Here is the article:" preambles
	// when they precede a JSON or HTML body.
	$raw = preg_replace( '/^[ \t]*Here\s+(?:is|are)\s+(?:the\s+)?(?:JSON|HTML|article|response|output|revised|improved|rewritten|updated|humanized|final)\s*:[ \t]*\n+/i', '', $raw );

	// Strip leading "Sure, ..." / "Of course, ..." / "Certainly, ..." chatty preambles.
	$raw = preg_replace( '/^[ \t]*(?:Sure|Of course|Certainly|Absolutely|Okay|Ok|Alright|Great)[ \t,]+\S.*?\n+/i', '', $raw );

	// AGGRESSIVE: Strip any multi-line prose paragraph that appears before
	// the first JSON/HTML structure. This catches unlabeled reasoning from
	// ANY model without needing to enumerate every possible prefix pattern.
	// Logic: If there's 2+ lines of text before the first { [ or HTML tag,
	// and those lines contain common English words, it's probably reasoning.
	if ( preg_match( '/^([^\{\[<]+)(?=[\{\[<])/s', $raw, $prefix_match ) ) {
		$prefix        = $prefix_match[1];
		$prefix_length = strlen( trim( $prefix ) );
		$line_count    = substr_count( $prefix, "\n" );

		// If prefix is multi-line (2+ \n) OR longer than 100 chars, it's reasoning.
		// AND it must contain word characters (not just symbols/punctuation).
		if ( ( $line_count >= 2 || $prefix_length > 100 ) && preg_match( '/[a-zA-Z]{4,}/', $prefix ) ) {
			$raw = preg_replace( '/^[^\{\[<]+(?=[\{\[<])/s', '', $raw );
		}
	}

	$raw = trim( $raw );

	if ( '' === $raw ) {
		return $raw;
	}

	// Already starts with JSON — nothing to strip.
	$first_char = $raw[0];
	if ( '{' === $first_char || '[' === $first_char ) {
		return $raw;
	}

	// FINAL FALLBACK: If we still haven't found JSON/HTML start, aggressively
	// find the first { or [ and drop everything before it (this is the most
	// aggressive model-agnostic approach — works for any unknown model).
	$brace   = strpos( $raw, '{' );
	$bracket = strpos( $raw, '[' );

	$json_start = false;
	if ( false !== $brace && false !== $bracket ) {
		$json_start = min( $brace, $bracket );
	} elseif ( false !== $brace ) {
		$json_start = $brace;
	} elseif ( false !== $bracket ) {
		$json_start = $bracket;
	}

	if ( false === $json_start || $json_start < 1 ) {
		return $raw;
	}

	// Always strip if there's a significant amount of text before JSON starts.
	// Small prefix (< 50 chars) might be legitimate (e.g., a label), but
	// anything longer is almost certainly reasoning/thinking text.
	$prefix = substr( $raw, 0, $json_start );
	if ( strlen( trim( $prefix ) ) > 50 || preg_match( '/[a-zA-Z]{10,}/', $prefix ) ) {
		return substr( $raw, $json_start );
	}

	return $raw;
}

/**
 * Strip chain-of-thought / reasoning text from HTML output (used by
 * improve_content and generate_section, where the AI returns raw HTML
 * without a JSON wrapper).
 *
 * Strategy: if the response starts with an HTML tag (<p>, <h2>, <div>, etc.)
 * we trust it's pure HTML. Otherwise, we look for the first HTML start tag
 * and strip everything before it when the prefix looks like reasoning.
 *
 * @param string $raw Raw AI HTML response.
 * @return string Cleaned HTML.
 */
function aime_strip_thinking_from_html( string $raw ): string {
	$raw = trim( $raw );

	if ( '' === $raw ) {
		return $raw;
	}

	// First, apply the JSON-aware stripper (handles think tags,
	// safety lines, preambles, JSON-prefix cleanup).
	$raw = aime_strip_thinking_text( $raw );

	if ( '' === $raw ) {
		return $raw;
	}

	// If the response starts with an HTML tag, it's already clean HTML.
	$first_char = $raw[0];
	if ( '<' === $first_char ) {
		return $raw;
	}

	// Otherwise, look for the first HTML opening tag. If found and the prefix
	// looks like natural-language reasoning, drop the prefix.
	$html_start = preg_match( '/<\s*(?:p|h[1-6]|div|section|article|ul|ol|li|blockquote|figure|table|nav|header|footer|main|aside|strong|em|br)\b/i', $raw, $m, PREG_OFFSET_CAPTURE );
	if ( $html_start && isset( $m[0][1] ) && $m[0][1] > 0 ) {
		$prefix = substr( $raw, 0, $m[0][1] );
		// Only strip if the prefix contains natural-language reasoning
		// (at least 20 chars of word characters — avoids stripping short
		// legitimate prefixes).
		$letters = preg_replace( '/[^a-zA-Z]/', '', $prefix );
		if ( strlen( $letters ) >= 20 ) {
			$raw = substr( $raw, $m[0][1] );
			$raw = ltrim( $raw );
		}
	}

	// Strip safety-classification lines from HTML output (e.g. trailing
	// "User Safety: safe" line, or ones embedded anywhere in the body).
	$raw = preg_replace( '/^[ \t]*(?:User\s+Safety|Safety|Harm\s+Category|Harm_Policy|Category|Severity)[ \t]*:[ \t]*[^\n\r]*[\n\r]?/im', '', $raw );
	$raw = preg_replace( '/(?:\n|\r)[ \t]*(?:User\s+Safety|Safety|Harm\s+Category|Harm_Policy|Category|Severity)[ \t]*:[ \t]*[^\n\r]*/i', '', $raw );
	$raw = preg_replace( '/[\s\n\r]*(?:User\s+Safety|Safety|Harm\s+Category|Harm_Policy|Category|Severity)[ \t]*:[ \t]*[^\n\r<]*$/i', '', $raw );
	$raw = trim( $raw );

	return $raw;
}

/**
 * Per-IP rate limiting for public endpoints.
 *
 * Uses a transient counter keyed on a hash of the visitor IP and the action
 * name. Returns true while the caller is within the limit, false once the
 * limit for the current window is exceeded.
 *
 * @param string $action Unique action key (e.g. 'subscribe').
 * @param int    $limit  Max requests allowed per window.
 * @param int    $window Window length in seconds.
 * @return bool True if allowed, false if rate-limited.
 */
function aime_check_ip_rate_limit( string $action, int $limit, int $window ): bool {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( '' === $ip ) {
		// No IP available (CLI/cron context) — do not rate-limit.
		return true;
	}

	/**
	 * Filter the rate limit for a public action.
	 *
	 * @param int    $limit  Max requests per window.
	 * @param string $action Action key.
	 * @param int    $window Window in seconds.
	 */
	$limit = (int) apply_filters( 'aime_ip_rate_limit', $limit, $action, $window );
	if ( $limit <= 0 ) {
		// A non-positive limit disables rate limiting for this action.
		return true;
	}

	$key   = 'aime_rl_' . md5( $action . '|' . $ip );
	$count = (int) get_transient( $key );

	if ( $count >= $limit ) {
		return false;
	}

	if ( 0 === $count ) {
		set_transient( $key, 1, max( 1, $window ) );
	} else {
		// Increment without resetting the window (transient TTL persists).
		$count++;
		$updated = false;
		if ( wp_using_ext_object_cache() ) {
			$updated = wp_cache_set( $key, $count, 'transient', max( 1, $window ) );
		} else {
			$updated = update_option( '_transient_' . $key, $count, false );
		}
		if ( ! $updated ) {
			set_transient( $key, $count, max( 1, $window ) );
		}
	}

	return true;
}

/**
 * Extra tags article bodies need on top of $allowedposttags.
 *
 * kses still enforces wp_allowed_protocols() on src/srcset, so javascript:
 * and data: URLs are rejected; srcdoc and every on* handler stay disallowed.
 *
 * @return array Tag => attribute map.
 */
function aime_embed_kses_tags(): array {
	$common = array(
		'class' => true,
		'id'    => true,
		'style' => true,
		'title' => true,
	);

	return array(
		'iframe' => array_merge(
			$common,
			array(
				'src'             => true,
				'width'           => true,
				'height'          => true,
				'name'            => true,
				'allow'           => true,
				'allowfullscreen' => true,
				'frameborder'     => true,
				'scrolling'       => true,
				'loading'         => true,
				'referrerpolicy'  => true,
				'sandbox'         => true,
			)
		),
		'video'  => array_merge(
			$common,
			array(
				'src'         => true,
				'poster'      => true,
				'width'       => true,
				'height'      => true,
				'controls'    => true,
				'preload'     => true,
				'autoplay'    => true,
				'loop'        => true,
				'muted'       => true,
				'playsinline' => true,
			)
		),
		'audio'  => array_merge(
			$common,
			array(
				'src'      => true,
				'controls' => true,
				'preload'  => true,
				'autoplay' => true,
				'loop'     => true,
				'muted'    => true,
			)
		),
		'source' => array(
			'src'    => true,
			'srcset' => true,
			'type'   => true,
			'media'  => true,
			'sizes'  => true,
		),
		'track'  => array(
			'src'     => true,
			'kind'    => true,
			'srclang' => true,
			'label'   => true,
			'default' => true,
		),
		'picture' => $common,
	);
}

/**
 * Allowed HTML for article bodies.
 *
 * wp_kses_post()'s $allowedposttags has no iframe/video/audio/picture, so
 * oEmbed players and self-hosted media are destroyed on every save and on
 * every AI round trip. This adds them back.
 *
 * @return array Tag => attribute map for wp_kses().
 */
function aime_allowed_article_html(): array {
	$tags = array_merge( wp_kses_allowed_html( 'post' ), aime_embed_kses_tags() );

	/**
	 * Filter the allowed HTML for AI Marketing Expert article bodies.
	 *
	 * @param array $tags Tag => attribute map.
	 */
	return apply_filters( 'aime_allowed_article_html', $tags );
}

/**
 * Sanitize article HTML without destroying embedded media.
 *
 * Drop-in replacement for wp_kses_post() on article bodies. Applies kses
 * unconditionally — no unfiltered_html bypass — because this content passes
 * through AI models and must not be trusted even when an administrator
 * submits it.
 *
 * @param string $content Raw HTML.
 */
function aime_kses_article( $content ): string {
	if ( ! is_string( $content ) || '' === $content ) {
		return '';
	}

	return wp_kses( $content, aime_allowed_article_html() );
}

/**
 * Widen the core post allowlist while our own content is being saved.
 *
 * Hooked only around wp_insert_post()/wp_update_post() calls that write an
 * already-sanitized article body — see aime_allow_embeds_during_save(). Without
 * it, core's content_save_pre kses pass (active for any user or cron run
 * without unfiltered_html) strips the embeds back out.
 *
 * @param array  $tags    Allowed tags.
 * @param string $context Allowlist context.
 * @return array
 */
function aime_filter_kses_allowed_html( $tags, $context ): array {
	if ( 'post' !== $context || ! is_array( $tags ) ) {
		return (array) $tags;
	}

	return array_merge( $tags, aime_embed_kses_tags() );
}

/**
 * Run a post-writing callback with embeds allowed through core kses.
 *
 * @param callable $callback Receives no arguments.
 * @return mixed Callback return value.
 */
function aime_allow_embeds_during_save( callable $callback ) {
	add_filter( 'wp_kses_allowed_html', 'aime_filter_kses_allowed_html', 10, 2 );

	try {
		return $callback();
	} finally {
		remove_filter( 'wp_kses_allowed_html', 'aime_filter_kses_allowed_html', 10 );
	}
}

function aime_parse_ai_json( string $raw ): ?array {
	$raw = trim( $raw );

	// Stage 0 — Strip thinking / reasoning prefix text.
	// Many models output chain-of-thought reasoning before JSON.
	// Remove everything before the first plausible JSON start.
	$raw = aime_strip_thinking_text( $raw );

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

	// Stage 2.5 — GLOBAL FALLBACK: Find first valid JSON by scanning for
	// { or [ and attempting parse from every position. This catches ANY
	// reasoning model output pattern without needing to know specific prefixes.
	// Works for unknown future models and custom reasoning formats.
	$first_brace   = strpos( $raw, '{' );
	$first_bracket = strpos( $raw, '[' );

	if ( false !== $first_brace || false !== $first_bracket ) {
		// Try every position where JSON might start, earliest first.
		$positions = array_filter( array( $first_brace, $first_bracket ), function ( $pos ) {
			return false !== $pos;
		} );
		sort( $positions );

		foreach ( $positions as $try_pos ) {
			$candidate = substr( $raw, $try_pos );
			$decoded   = json_decode( $candidate, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) && ! empty( $decoded ) ) {
				return $decoded;
			}
		}
	}

	// Stage 3 — Brace / bracket matching.
	$openers = array(
		array( '{', '}' ),
		array( '[', ']' ),
	);

	foreach ( $openers as list( $open, $close ) ) {
		$start = strpos( $raw, $open );

		while ( $start !== false ) {
			$depth     = 0;
			$in_string = false;
			$escape    = false;
			$len       = strlen( $raw );

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

				if ( $ch === $open ) {
					$depth++;
				} elseif ( $ch === $close ) {
					$depth--;
					if ( 0 === $depth ) {
						$candidate = substr( $raw, $start, $i - $start + 1 );
						$decoded   = json_decode( $candidate, true );
						if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
							return $decoded;
						}

						// Stage 3b — Repair: thinking models may inject reasoning
						// text inside the JSON object before actual key-value pairs.
						// Find each valid-looking JSON key and try building JSON from there.
						if ( '{' === $open && preg_match_all( '/"([a-z_][a-z0-9_]{0,30})"\s*:/i', $candidate, $keys, PREG_OFFSET_CAPTURE ) ) {
							foreach ( $keys[0] as $km ) {
								$repair_pos = $km[1];
								if ( $repair_pos <= 1 ) {
									continue; // Already tried the full candidate.
								}
								$repaired = '{ ' . substr( $candidate, $repair_pos );
								$decoded  = json_decode( $repaired, true );
								if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
									return $decoded;
								}
							}
						}

						break;
					}
				}
			}

			$start = strpos( $raw, $open, $start + 1 );
		}
	}

	// Stage 4 — Fix unescaped newlines inside JSON string values.
	if ( preg_match( '/[\[{][\s\S]*[\]}]/', $raw, $m ) ) {
		$fixed = preg_replace_callback(
			'/"((?:[^"\\\\]|\\\\.)*?)"/',
			function ( $match ) {
				$inner = $match[1];
				$inner = str_replace(
					array( "\r\n", "\r", "\n", "\t" ),
					array( '\\n', '\\n', '\\n', '\\t' ),
					$inner
				);
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