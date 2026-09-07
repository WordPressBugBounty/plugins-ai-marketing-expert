<?php
/**
 * Skill Registry — reusable AI Brain instruction blocks.
 *
 * Skills are small, composable prompt fragments (SEO, readability, image
 * direction, link planning) that merge into the AI Brain system prompt.
 * Built-ins ship in code; custom skills live in the
 * `aime_brain_skills` option so users can create their own without
 * touching files. Exposed to the builder via the workflow actions API.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes
 */

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SkillRegistry {

	const OPTION_KEY = 'aime_brain_skills';

	/**
	 * Built-in skills. IDs are stable and namespaced with `aime-` to avoid
	 * collisions with user-created skill IDs (which use `custom-` prefix
	 * plus a uuid fragment).
	 *
	 * Freemium: SEO Strategist + Readability Coach are free (the RankMath
	 * baseline every post needs). The rest + custom skills are Pro —
	 * see resolve() which drops them silently on the free tier.
	 *
	 * @return array<string,array{id:string,title:string,description:string,instructions:string,outputs:string[],is_pro:bool}>
	 */
	public static function builtins(): array {
		return array(
			'aime-seo-strategist' => array(
				'id'           => 'aime-seo-strategist',
				'title'        => __( 'SEO Strategist', 'ai-marketing-expert' ),
				'description'  => __( 'Outputs focus keyword, SEO title, meta description, slug and heading plan meeting RankMath/Yoast standards.', 'ai-marketing-expert' ),
				'instructions' => 'SEO rules: pick exactly ONE focus keyword (2-4 words). SEO title max 60 chars with keyword near the start, include one number and one power word. Meta description 140-160 chars with keyword naturally. Slug 3-5 lowercase words containing the keyword slug. Plan 3-5 H2 headings, at least one containing the keyword or a close variant. Keyword must fit naturally in the first 100 words with ~1% density target.',
				'outputs'      => array( 'focus_keyword', 'seo_title', 'meta_description', 'slug' ),
				'is_pro'       => false,
			),
			'aime-readability'    => array(
				'id'           => 'aime-readability',
				'title'        => __( 'Readability Coach', 'ai-marketing-expert' ),
				'description'  => __( 'Short paragraphs, lists, TOC and FAQ for Content Readability green.', 'ai-marketing-expert' ),
				'instructions' => 'Readability rules: short paragraphs (max 4 lines), one idea per section, include one bulleted or numbered list, add a 4-question FAQ section, end with 3 key takeaways. For articles over 800 words include a Table of Contents plan with anchor ids.',
				'outputs'      => array( 'outline' ),
				'is_pro'       => false,
			),
			'aime-image-director' => array(
				'id'           => 'aime-image-director',
				'title'        => __( 'Image Director', 'ai-marketing-expert' ),
				'description'  => __( 'Generates 3 distinct concrete stock-photo queries to avoid duplicate images.', 'ai-marketing-expert' ),
				'instructions' => 'Image rules: propose exactly 3 DISTINCT stock-photo search queries (2-4 English words each, concrete nouns, no punctuation, no repeats). Each query must match a different section of the article. Never reuse generic single words like "marketing" or "business" alone.',
				'outputs'      => array( 'image_queries' ),
				'is_pro'       => true,
			),
			'aime-link-planner'   => array(
				'id'           => 'aime-link-planner',
				'title'        => __( 'Link Planner', 'ai-marketing-expert' ),
				'description'  => __( 'Plans internal and external links so posts never ship with zero links.', 'ai-marketing-expert' ),
				'instructions' => 'Link rules: suggest 2 internal link topics (existing site content the article should reference) and 1-2 authoritative external sources (docs, studies, official guides). Use descriptive anchor text, never "click here". Mark external links as dofollow-worthy references.',
				'outputs'      => array( 'internal_link_hints', 'external_source_hints' ),
				'is_pro'       => true,
			),
			'aime-wordpress-expert' => array(
				'id'           => 'aime-wordpress-expert',
				'title'        => __( 'WordPress Expert', 'ai-marketing-expert' ),
				'description'  => __( 'Keeps topics practical for WordPress users: how-tos, listicles, product-led angles.', 'ai-marketing-expert' ),
				'instructions' => 'WordPress angle: prefer practical how-to guides, listicles and product-led topics useful to WordPress site owners. Mention concrete WP screens, plugins or settings where relevant. Avoid generic advice that ignores WordPress context.',
				'outputs'      => array(),
				'is_pro'       => true,
			),
			'aime-social-hook'    => array(
				'id'           => 'aime-social-hook',
				'title'        => __( 'Social Hook Writer', 'ai-marketing-expert' ),
				'description'  => __( 'One-line social hook + CTA for downstream social/email steps.', 'ai-marketing-expert' ),
				'instructions' => 'Social rules: also output a one-line social hook (max 120 chars, curiosity or benefit led) and a short call to action (max 12 words) suitable for promoting this topic on social and email.',
				'outputs'      => array( 'social_hook', 'cta' ),
				'is_pro'       => true,
			),
		);
	}

	/**
	 * All skills: built-ins merged with user custom skills.
	 *
	 * @return array<string,array>
	 */
	public static function all(): array {
		$skills = self::builtins();
		foreach ( self::custom() as $id => $skill ) {
			$skills[ $id ] = $skill;
		}
		return $skills;
	}

	/**
	 * User-created skills from options table. Fail-soft, fully sanitized.
	 *
	 * @return array<string,array>
	 */
	public static function custom(): array {
		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $id => $skill ) {
			if ( ! is_array( $skill ) ) {
				continue;
			}
			$id = sanitize_key( (string) $id );
			if ( '' === $id || isset( self::builtins()[ $id ] ) ) {
				continue;
			}
			$title        = sanitize_text_field( (string) ( $skill['title'] ?? '' ) );
			$instructions = sanitize_textarea_field( (string) ( $skill['instructions'] ?? '' ) );
			if ( '' === $title || '' === $instructions ) {
				continue;
			}
			$out[ $id ] = array(
				'id'           => $id,
				'title'        => $title,
				'description'  => sanitize_text_field( (string) ( $skill['description'] ?? '' ) ),
				'instructions' => $instructions,
				'outputs'      => array_values( array_filter( array_map( 'sanitize_key', (array) ( $skill['outputs'] ?? array() ) ) ) ),
				'custom'       => true,
			);
		}
		return $out;
	}

	/**
	 * Get one skill by ID (built-in or custom).
	 */
	public static function get( string $id ): ?array {
		$id  = sanitize_key( $id );
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/**
	 * Resolve a list of requested skill IDs to valid skill arrays.
	 * Unknown IDs are silently dropped so renamed/deleted skills never break runs.
	 * Pro skills (and all custom skills) are dropped on the free tier so free
	 * workflows keep their free baseline instead of failing.
	 *
	 * @param mixed $ids Array or comma-separated string from step config.
	 * @return array<int,array>
	 */
	public static function resolve( $ids ): array {
		if ( is_string( $ids ) ) {
			$ids = array_filter( array_map( 'trim', explode( ',', $ids ) ) );
		}
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$has_pro = function_exists( 'aime_has_pro' ) ? aime_has_pro() : false;
		$out     = array();
		foreach ( $ids as $id ) {
			$skill = self::get( (string) $id );
			if ( null === $skill ) {
				continue;
			}
			if ( ! $has_pro && ( ! empty( $skill['is_pro'] ) || ! empty( $skill['custom'] ) ) ) {
				continue;
			}
			$out[] = $skill;
		}
		return $out;
	}

	/**
	 * Merge skill instructions into one prompt block.
	 */
	public static function merge_instructions( array $skills ): string {
		$parts = array();
		foreach ( $skills as $skill ) {
			$instructions = trim( (string) ( $skill['instructions'] ?? '' ) );
			if ( '' !== $instructions ) {
				$parts[] = '- ' . $skill['title'] . ': ' . $instructions;
			}
		}
		if ( ! $parts ) {
			return '';
		}
		return "Active skills (follow all):\n" . implode( "\n", $parts ) . "\n";
	}

	/**
	 * Create a custom skill. Returns new ID or WP_Error.
	 *
	 * @param array $data title, instructions, description, outputs.
	 * @return string|\WP_Error
	 */
	public static function create( array $data ) {
		$title        = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		$instructions = sanitize_textarea_field( (string) ( $data['instructions'] ?? '' ) );
		if ( '' === $title || '' === $instructions ) {
			return new \WP_Error( 'invalid_skill', __( 'Title and instructions are required.', 'ai-marketing-expert' ) );
		}
		$id = 'custom-' . substr( wp_generate_uuid4(), 0, 8 );
		$id = sanitize_key( $id );

		$skills        = self::custom();
		$skills[ $id ] = array(
			'title'        => $title,
			'description'  => sanitize_text_field( (string) ( $data['description'] ?? '' ) ),
			'instructions' => $instructions,
			'outputs'      => array_values( array_filter( array_map( 'sanitize_key', (array) ( $data['outputs'] ?? array() ) ) ) ),
		);
		update_option( self::OPTION_KEY, $skills, false );
		return $id;
	}

	/**
	 * Delete a custom skill. Built-ins cannot be deleted.
	 *
	 * @return bool True when deleted.
	 */
	public static function delete( string $id ): bool {
		$id = sanitize_key( $id );
		if ( isset( self::builtins()[ $id ] ) ) {
			return false;
		}
		$skills = self::custom();
		if ( ! isset( $skills[ $id ] ) ) {
			return false;
		}
		unset( $skills[ $id ] );
		update_option( self::OPTION_KEY, $skills, false );
		return true;
	}

	/**
	 * JSON-safe payload for the builder (no instructions truncated).
	 *
	 * @return array<int,array>
	 */
	public static function for_api(): array {
		$out = array();
		foreach ( self::all() as $skill ) {
			$is_pro = ! empty( $skill['is_pro'] ) || ! empty( $skill['custom'] );
			$out[]  = array(
				'id'          => $skill['id'],
				'title'       => $skill['title'],
				'description' => $skill['description'] ?? '',
				'outputs'     => array_values( (array) ( $skill['outputs'] ?? array() ) ),
				'is_pro'      => (bool) $is_pro,
				'custom'      => ! empty( $skill['custom'] ),
			);
		}
		return $out;
	}
}
