<?php
/**
 * Internal Link Service — contextual internal link injection for published articles.
 *
 * Scans existing published posts on the site, identifies high-relevance topic
 * keywords and phrases, and contextually injects natural internal links into
 * article paragraphs without altering headings, existing links, or code blocks.
 *
 * @package WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InternalLinkService {

	/**
	 * Common stop words and generic tokens to avoid linking as stand-alone anchors.
	 */
	private const GENERIC_WORDS = array(
		'the', 'and', 'for', 'that', 'this', 'with', 'from', 'your', 'have',
		'more', 'will', 'about', 'what', 'when', 'where', 'which', 'their',
		'there', 'these', 'those', 'into', 'just', 'some', 'than', 'them',
		'then', 'also', 'only', 'very', 'here', 'even', 'most', 'make', 'like',
	);

	/**
	 * Inject contextual internal links into article body HTML.
	 *
	 * @param string $html            Sanitized article body HTML.
	 * @param int    $current_post_id Post ID being published (to exclude from linking to itself).
	 * @param int    $max_links       Maximum internal links to insert (default 3, max 5).
	 * @return string Modified HTML with contextual internal links.
	 */
	public function inject_internal_links( string $html, int $current_post_id = 0, int $max_links = 3 ): string {
		if ( '' === trim( $html ) || ! function_exists( 'get_posts' ) ) {
			return $html;
		}

		$max_links = max( 1, min( 5, $max_links ) );

		try {
			$candidates = $this->build_link_candidates( $current_post_id );
			if ( empty( $candidates ) ) {
				return $html;
			}

			return $this->apply_links_to_paragraphs( $html, $candidates, $max_links );
		} catch ( \Throwable $e ) {
			aime_log( 'Internal link injection failed: ' . $e->getMessage(), 'warning', 'content-generator' );
			return $html;
		}
	}

	/**
	 * Build target link candidates from existing published WordPress posts.
	 *
	 * @param int $exclude_id Post ID to exclude.
	 * @return array<int, array{phrase: string, url: string, title: string, length: int}>
	 */
	private function build_link_candidates( int $exclude_id = 0 ): array {
		$posts = get_posts( array(
			'post_type'      => array( 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => 80,
			'exclude'        => $exclude_id > 0 ? array( $exclude_id ) : array(),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );

		if ( empty( $posts ) ) {
			return array();
		}

		$candidates = array();
		$seen_phrases = array();

		foreach ( $posts as $post ) {
			$url   = get_permalink( $post->ID );
			$title = trim( wp_strip_all_tags( get_the_title( $post->ID ) ) );
			if ( empty( $url ) || '' === $title ) {
				continue;
			}

			$phrases = array();

			// 1. Full title or title minus brand separators (e.g. " - WP Space" or " | Blog").
			$clean_title = preg_replace( '/\s*[-|–—:]\s*[^-\|–—:]+$/u', '', $title );
			$phrases[]   = $title;
			if ( $clean_title && $clean_title !== $title ) {
				$phrases[] = $clean_title;
			}

			// 2. Focus keyword from SEO metadata if stored.
			$focus_kw = get_post_meta( $post->ID, 'aime_seo_keyword', true );
			if ( ! $focus_kw ) {
				$focus_kw = get_post_meta( $post->ID, 'rank_math_focus_keyword', true );
			}
			if ( ! $focus_kw ) {
				$focus_kw = get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true );
			}
			if ( is_string( $focus_kw ) && '' !== trim( $focus_kw ) ) {
				$phrases[] = trim( $focus_kw );
			}

			// 3. Multi-word post tags if available.
			$tags = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
			if ( is_array( $tags ) ) {
				foreach ( $tags as $tag_name ) {
					if ( is_string( $tag_name ) && mb_strlen( trim( $tag_name ) ) >= 4 ) {
						$phrases[] = trim( $tag_name );
					}
				}
			}

			foreach ( $phrases as $phrase ) {
				$phrase = trim( $phrase );
				$lower  = mb_strtolower( $phrase );

				// Validate phrase: must be at least 4 characters and not a generic word.
				if ( mb_strlen( $phrase ) < 4 || in_array( $lower, self::GENERIC_WORDS, true ) ) {
					continue;
				}

				if ( isset( $seen_phrases[ $lower ] ) ) {
					continue;
				}
				$seen_phrases[ $lower ] = true;

				$candidates[] = array(
					'phrase' => $phrase,
					'url'    => $url,
					'title'  => $title,
					'length' => mb_strlen( $phrase ),
				);
			}
		}

		// Sort candidates descending by length so longer, more specific phrases match first.
		usort( $candidates, static function ( array $a, array $b ): int {
			return $b['length'] <=> $a['length'];
		} );

		return $candidates;
	}

	/**
	 * Inject links into paragraphs with boundary matching and isolation.
	 *
	 * @param string $html       Original HTML.
	 * @param array  $candidates Available link candidates.
	 * @param int    $max_links  Link limit.
	 * @return string Processed HTML.
	 */
	private function apply_links_to_paragraphs( string $html, array $candidates, int $max_links ): string {
		$links_added = 0;
		$used_urls   = array();

		// Match each <p>...</p> paragraph.
		$html = preg_replace_callback(
			'/<p\b([^>]*)>(.*?)<\/p>/is',
			function ( array $matches ) use ( &$links_added, &$used_urls, $candidates, $max_links ): string {
				$attrs = $matches[1];
				$body  = $matches[2];

				// Stop if link budget reached.
				if ( $links_added >= $max_links ) {
					return $matches[0];
				}

				// Skip if paragraph already contains an anchor tag, or is empty.
				if ( '' === trim( $body ) || false !== stripos( $body, '<a ' ) ) {
					return $matches[0];
				}

				// Skip if paragraph is an FAQ answer or inside a styled notice.
				if ( false !== stripos( $attrs, 'aime-faq-a' ) || false !== stripos( $attrs, 'aime-callout' ) ) {
					return $matches[0];
				}

				foreach ( $candidates as $candidate ) {
					$url = $candidate['url'];

					// Do not link to the same destination URL multiple times.
					if ( isset( $used_urls[ $url ] ) ) {
						continue;
					}

					$phrase  = $candidate['phrase'];
					$pattern = '/(?<![\p{L}\p{N}])(' . preg_quote( $phrase, '/' ) . ')(?![\p{L}\p{N}])/iu';

					if ( preg_match( $pattern, $body ) ) {
						// Ensure match is outside of any inline tags.
						$replaced = preg_replace_callback(
							$pattern,
							static function ( array $m ) use ( $url, $candidate ): string {
								$matched_text = $m[1];
								$target_url   = esc_url( $url );
								$target_title = esc_attr( $candidate['title'] );
								return "<a href=\"{$target_url}\" title=\"{$target_title}\">{$matched_text}</a>";
							},
							$body,
							1
						);

						if ( $replaced && $replaced !== $body ) {
							$body               = $replaced;
							$used_urls[ $url ]  = true;
							$links_added++;
							break; // At most 1 link per paragraph for natural reading flow.
						}
					}
				}

				return "<p{$attrs}>{$body}</p>";
			},
			$html
		);

		return $html;
	}
}
