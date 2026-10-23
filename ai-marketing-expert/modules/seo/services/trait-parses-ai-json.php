<?php
/**
 * Trait ParsesAiJson — shared JSON parsing for AI responses (SEO module copy).
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait ParsesAiJson {

	/**
	 * Attempt to parse a JSON string returned by an AI model.
	 *
	 * Stages:
	 *  1. Direct json_decode.
	 *  2. Extract from fenced code block.
	 *  3. Extract first { … } or [ … ] top-level structure.
	 *  4. Fix unescaped newlines inside JSON string values, then re-try.
	 *
	 * @param string $raw The raw response string.
	 * @return array|null Decoded array or null on failure.
	 */
	private function parse_json_response( string $raw ): ?array {
		return aime_parse_ai_json( $raw );
	}
}
