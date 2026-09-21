<?php
/**
 * B2B Lead Discovery & Scraping Service.
 *
 * Provides hybrid lead prospecting with persistent pagination,
 * pre-ingestion domain deduplication, on-site contact extraction,
 * and live DNS MX deliverability verification.
 *
 * @package WPSpace\AiMarketingExpert\Modules\EmailMarketing\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\EmailMarketing\Services;

use WPSpace\AiMarketingExpert\AiProvider;
use WPSpace\AiMarketingExpert\EmailValidator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B2bScraperService {

	/**
	 * Default timeout for external HTTP scraping requests.
	 */
	public const SCRAPE_TIMEOUT_SECONDS = 4;

	/**
	 * User agent for web requests to prevent bot blocking.
	 */
	public const SCRAPER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

	/**
	 * Domains to ignore during crawling/extraction (social networks, common directories, CDNs).
	 */
	public const IGNORED_DOMAINS = array(
		'facebook.com',
		'instagram.com',
		'twitter.com',
		'x.com',
		'linkedin.com',
		'youtube.com',
		'pinterest.com',
		'tiktok.com',
		'google.com',
		'yahoo.com',
		'bing.com',
		'wikipedia.org',
		'yelp.com',
		'yellowpages.com',
		'clutch.co',
		'goodfirms.co',
		'zoominfo.com',
		'apollo.io',
		'bbb.org',
		'glassdoor.com',
		'indeed.com',
		'sentry.io',
		'wixpress.com',
		'wordpress.org',
		'schema.org',
		'gravatar.com',
		'cloudflare.com',
	);

	/**
	 * Check if a company domain already exists in the subscriber CRM.
	 *
	 * @param string $domain Root domain (e.g. acme-tech.com).
	 * @return bool True if already present in wp_aime_subscribers.
	 */
	public static function is_domain_in_crm( string $domain ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'aime_subscribers';
		$domain = strtolower( trim( $domain ) );
		$domain = preg_replace( '#^https?://#i', '', $domain );
		$domain = trim( $domain, '/' );

		if ( empty( $domain ) || ! str_contains( $domain, '.' ) ) {
			return false;
		}

		$like = '%' . $wpdb->esc_like( '@' . $domain );
		$id   = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email LIKE %s LIMIT 1", $like ) );

		return ! empty( $id );
	}

	/**
	 * Discover prospective B2B leads using hybrid discovery & persistent pagination.
	 *
	 * @param array $criteria        Search parameters (industry, role, location, company_size, keyword).
	 * @param int   $page            Current pagination page (1, 2, 3...).
	 * @param int   $limit           Leads per page (default 10).
	 * @param array $exclude_domains Optional array of domains to explicitly exclude.
	 * @return array
	 */
	public static function discover_leads( array $criteria, int $page = 1, int $limit = 10, array $exclude_domains = array() ): array {
		$page  = max( 1, $page );
		$limit = min( 50, max( 3, $limit ) );

		$industry     = sanitize_text_field( $criteria['industry'] ?? '' );
		$role         = sanitize_text_field( $criteria['role'] ?? '' );
		$location     = sanitize_text_field( $criteria['location'] ?? '' );
		$company_size = sanitize_text_field( $criteria['company_size'] ?? '' );
		$keyword      = sanitize_text_field( $criteria['keyword'] ?? '' );

		$offset_start = ( ( $page - 1 ) * $limit ) + 1;
		$offset_end   = $page * $limit;

		// 1. Build domain exclusion list from CRM + previously passed exclusions
		$active_exclusions = array_filter( array_unique( array_map( 'strtolower', (array) $exclude_domains ) ) );

		// 2. Formulate paginated prompt with tier stratification
		$tier_guidance = match ( $page ) {
			1       => 'Page 1 Tier: Top-tier established industry leaders and widely-recognized corporate brands.',
			2       => 'Page 2 Tier: Established mid-market companies, premier agencies, and specialized providers.',
			3       => 'Page 3 Tier: Boutique specialized firms, regional innovators, and fast-growing mid-sized companies.',
			default => sprintf( 'Page %d Tier: High-growth emerging businesses, local market leaders, and independent specialized firms (Results #%d to #%d).', $page, $offset_start, $offset_end ),
		};

		$prompt_criteria = array();
		if ( ! empty( $criteria['prompt'] ) && ( 'prompt' === ( $criteria['mode'] ?? '' ) || empty( $criteria['industry'] ) ) ) {
			$prompt_criteria[] = "Custom Target Specification:\n" . sanitize_textarea_field( $criteria['prompt'] );
		} else {
			if ( $industry ) {
				$prompt_criteria[] = "Industry: {$industry}";
			}
			if ( $role ) {
				$prompt_criteria[] = "Target Role / Decision Maker: {$role}";
			}
			if ( $location ) {
				$prompt_criteria[] = "Target Geographic Market / Country: {$location}";
			}
			if ( $company_size ) {
				$prompt_criteria[] = "Company Headcount: {$company_size}";
			}
			if ( $keyword ) {
				$prompt_criteria[] = "Focus Specialization: {$keyword}";
			}
		}

		$exclude_clause = '';
		if ( ! empty( $active_exclusions ) ) {
			$sample = array_slice( $active_exclusions, -25 );
			$exclude_clause = "\nCRITICAL EXCLUSION: Do NOT return any companies or domains from this list:\n" . implode( ', ', $sample ) . "\n";
		}

		$prompt = sprintf(
			"You are an expert B2B Prospect Intelligence Engine.
Your task is to identify and compile exactly %d REAL, VERIFIABLE B2B companies and decision makers matching:
%s

TARGET PAGE: Page %d (%s)
%s
MANDATORY QUALITY RULES:
1. ONLY return REAL, currently operating commercial businesses. Do NOT hallucinate or fabricate non-existent company domains (e.g. avoid synthetic domains like 'nexus-tech-cloud.io').
2. Every domain MUST be a genuine, registered corporate website.
3. For emails, use standard corporate email conventions (e.g. first.last@domain, contact@domain, or first@domain).
4. Return ONLY a valid JSON object matching this structure:
{
  \"leads\": [
    {
      \"first_name\": \"string\",
      \"last_name\": \"string\",
      \"company\": \"string (real company name)\",
      \"title\": \"string (decision maker title, e.g. CEO, Founder, Director)\",
      \"domain\": \"string (clean domain without http/www, e.g. acme.com)\",
      \"email\": \"string (valid business email)\",
      \"location\": \"string (city, country)\",
      \"website\": \"https://domain\",
      \"phone\": \"string or empty\",
      \"confidence\": \"string (e.g. 94%%)\"
    }
  ]
}
Return ONLY the raw JSON object with no markdown code fences or conversational text.",
			$limit,
			implode( "\n", $prompt_criteria ),
			$page,
			$tier_guidance,
			$exclude_clause
		);

		$ai_res = AiProvider::generate( $prompt, 'text', 6000, array( 'json_mode' => true ) );
		if ( empty( $ai_res['success'] ) ) {
			return array(
				'items'      => array(),
				'total'      => 0,
				'pagination' => array(
					'current_page' => $page,
					'per_page'     => $limit,
					'has_more'     => false,
				),
				'message'    => $ai_res['message'] ?? __( 'AI lead search failed.', 'ai-marketing-expert' ),
			);
		}

		$raw_json = (string) ( $ai_res['content'] ?? '' );
		$parsed   = aime_parse_ai_json( $raw_json );
		if ( ! is_array( $parsed ) ) {
			$parsed = json_decode( $raw_json, true );
		}
		if ( isset( $parsed['leads'] ) && is_array( $parsed['leads'] ) ) {
			$parsed = $parsed['leads'];
		} elseif ( isset( $parsed['prospects'] ) && is_array( $parsed['prospects'] ) ) {
			$parsed = $parsed['prospects'];
		} elseif ( isset( $parsed['data'] ) && is_array( $parsed['data'] ) ) {
			$parsed = $parsed['data'];
		}

		if ( ! is_array( $parsed ) || empty( $parsed ) ) {
			// Fallback pattern extraction
			if ( preg_match_all( '/\{[^{}]*?"email"\s*:\s*"[^"]+?"[^{}]*?\}/s', $raw_json, $matches ) ) {
				$recovered = array();
				foreach ( $matches[0] as $match ) {
					$item = json_decode( $match, true );
					if ( is_array( $item ) && ! empty( $item['email'] ) ) {
						$recovered[] = $item;
					}
				}
				if ( ! empty( $recovered ) ) {
					$parsed = $recovered;
				}
			}
		}

		if ( ! is_array( $parsed ) || empty( $parsed ) ) {
			return array(
				'items'      => array(),
				'total'      => 0,
				'pagination' => array(
					'current_page' => $page,
					'per_page'     => $limit,
					'has_more'     => false,
				),
			);
		}

		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';
		$leads             = array();

		foreach ( $parsed as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$email = sanitize_email( $item['email'] ?? '' );
			if ( ! is_email( $email ) ) {
				continue;
			}

			$domain = strtolower( trim( (string) ( $item['domain'] ?? '' ) ) );
			if ( empty( $domain ) && false !== strpos( $email, '@' ) ) {
				$parts  = explode( '@', $email );
				$domain = (string) end( $parts );
			}
			$domain = preg_replace( '#^https?://#i', '', $domain );
			$domain = trim( $domain, '/' );

			// Filter ignored common portals
			if ( in_array( $domain, self::IGNORED_DOMAINS, true ) ) {
				continue;
			}

			// Validate DNS / MX deliverability
			$has_mx = EmailValidator::has_mailable_domain( $domain );

			// Check if already in local CRM
			$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$subscribers_table} WHERE email = %s", $email ) );
			$is_in_crm   = ( $existing_id > 0 ) || self::is_domain_in_crm( $domain );

			$leads[] = array(
				'first_name'    => sanitize_text_field( $item['first_name'] ?? '' ),
				'last_name'     => sanitize_text_field( $item['last_name'] ?? '' ),
				'company'       => sanitize_text_field( $item['company'] ?? '' ),
				'title'         => sanitize_text_field( $item['title'] ?? '' ),
				'domain'        => sanitize_text_field( $domain ),
				'email'         => $email,
				'location'      => sanitize_text_field( $item['location'] ?? '' ),
				'website'       => esc_url_raw( $item['website'] ?? ( $domain ? "https://{$domain}" : '' ) ),
				'phone'         => sanitize_text_field( $item['phone'] ?? '' ),
				'confidence'    => sanitize_text_field( $item['confidence'] ?? ( $has_mx ? '92%' : '65%' ) ),
				'mx_verified'   => $has_mx,
				'is_in_crm'     => $is_in_crm,
				'subscriber_id' => $existing_id,
				'source_tier'   => "Page {$page}",
			);
		}

		return array(
			'items'      => $leads,
			'total'      => count( $leads ),
			'pagination' => array(
				'current_page' => $page,
				'per_page'     => $limit,
				'has_more'     => ( count( $leads ) >= $limit ),
			),
		);
	}

	/**
	 * Attempt to scrape published corporate contact email from a company's website.
	 *
	 * @param string $domain Root domain (e.g. acmedigital.com).
	 * @return array Array of valid, unique emails found on homepage or /contact.
	 */
	public static function scrape_company_emails( string $domain ): array {
		$domain = strtolower( trim( $domain ) );
		$domain = preg_replace( '#^https?://#i', '', $domain );
		$domain = trim( $domain, '/' );

		if ( empty( $domain ) || ! str_contains( $domain, '.' ) ) {
			return array();
		}

		$urls_to_try = array(
			"https://{$domain}",
			"https://{$domain}/contact",
			"https://{$domain}/contact-us",
			"https://{$domain}/about",
		);

		$extracted_emails = array();

		foreach ( $urls_to_try as $url ) {
			$response = wp_remote_get( $url, array(
				'timeout'    => self::SCRAPE_TIMEOUT_SECONDS,
				'user-agent' => self::SCRAPER_USER_AGENT,
				'sslverify'  => false,
			) );

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}

			$html = wp_remote_retrieve_body( $response );
			if ( empty( $html ) ) {
				continue;
			}

			// 1. Extract mailto: links
			if ( preg_match_all( '/mailto:([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i', $html, $matches ) ) {
				foreach ( $matches[1] as $found_email ) {
					$clean = strtolower( trim( $found_email ) );
					if ( is_email( $clean ) && str_contains( $clean, $domain ) ) {
						$extracted_emails[] = $clean;
					}
				}
			}

			// 2. Extract general email patterns
			if ( preg_match_all( '/\b([a-zA-Z0-9._%+-]+@' . preg_quote( $domain, '/' ) . ')\b/i', $html, $matches ) ) {
				foreach ( $matches[1] as $found_email ) {
					$clean = strtolower( trim( $found_email ) );
					if ( is_email( $clean ) ) {
						$extracted_emails[] = $clean;
					}
				}
			}

			// If we found at least 1 verified on-site email, stop probing further paths
			if ( ! empty( $extracted_emails ) ) {
				break;
			}
		}

		return array_values( array_unique( $extracted_emails ) );
	}

	/**
	 * Compute option key for the persistent Autopilot page pointer.
	 *
	 * @param array $config Autopilot configuration array.
	 * @return string Option key.
	 */
	public static function get_autopilot_pointer_key( array $config ): string {
		$hash = md5(
			(string) ( $config['industry'] ?? '' ) . '_' .
			(string) ( $config['location'] ?? '' ) . '_' .
			(string) ( $config['role'] ?? '' ) . '_' .
			(string) ( $config['keyword'] ?? '' ) . '_' .
			(string) ( $config['company_size'] ?? '' ) . '_' .
			(string) ( $config['prompt'] ?? '' )
		);

		return 'aime_lead_autopilot_page_' . $hash;
	}

	/**
	 * Retrieve current persistent Autopilot page pointer for given criteria.
	 *
	 * @param array $config Autopilot configuration.
	 * @return int Current page pointer (>= 1).
	 */
	public static function get_autopilot_page_pointer( array $config ): int {
		$key = self::get_autopilot_pointer_key( $config );
		return max( 1, (int) get_option( $key, 1 ) );
	}

	/**
	 * Advance persistent Autopilot page pointer to the next page.
	 *
	 * @param array $config       Autopilot configuration.
	 * @param int   $current_page Current page.
	 * @return int New page number.
	 */
	public static function advance_autopilot_page_pointer( array $config, int $current_page ): int {
		$key      = self::get_autopilot_pointer_key( $config );
		$new_page = max( 1, $current_page ) + 1;
		update_option( $key, $new_page );
		return $new_page;
	}

	/**
	 * Reset persistent Autopilot page pointer back to Page 1.
	 *
	 * @param array $config Autopilot configuration.
	 * @return bool
	 */
	public static function reset_autopilot_page_pointer( array $config ): bool {
		$key = self::get_autopilot_pointer_key( $config );
		return update_option( $key, 1 );
	}
}
