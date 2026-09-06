<?php
/**
 * Email Validator — validates email quality before adding subscribers.
 *
 * Layers:
 *  1. Block obvious fakes (invalid domains, no TLD, localhost, etc.)
 *  2. Block disposable / temporary email domains
 *  3. MX record check (domain has a mail server)
 *
 * Designed to be extensible via filters for Pro features.
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EmailValidator {

	/**
	 * Validate an email address for subscriber quality.
	 *
	 * @param string $email  The email to validate.
	 * @param string $source  Context: 'admin', 'form', 'webhook', 'import'.
	 * @param array  $options Validation options.
	 * @return true|\WP_Error True if valid, WP_Error with reason if not.
	 */
	public static function validate( string $email, string $source = 'admin', array $options = array() ) {
		$options = wp_parse_args( $options, array(
			'skip_invalid_format' => true,
			'skip_disposable'     => true,
			'skip_test_fake'      => true,
			'skip_role_based'     => false,
			'skip_spam_patterns'  => true,
			'check_mx'            => null,
		) );
		$email = strtolower( trim( $email ) );

		// Basic format check (WordPress already does this, but safety first).
		if ( ! is_email( $email ) ) {
			return ! empty( $options['skip_invalid_format'] )
				? new \WP_Error( 'invalid_email', __( 'Invalid email format.', 'ai-marketing-expert' ) )
				: true;
		}

		$parts  = explode( '@', $email );
		$local  = $parts[0];
		$domain = $parts[1] ?? '';

		// ── Layer 1: Block obvious fakes ──────────────────

		// Block domains with no TLD or localhost-style.
		$blocked_domains = array(
			'localhost',
			'local',
			'example.com',
			'example.org',
			'example.net',
			'test.com',
			'test.org',
			'invalid.com',
			'invalid.org',
			'email.test',
			'mail.test',
			'domain.test',
			'foo.bar',
			'sample.com',
			'fake.com',
			'noemail.com',
			'mailinator.com', // Also in disposable list, doubled for safety.
		);

		/**
		 * Filter the blocked domains list.
		 *
		 * @param array  $blocked_domains Blocked domain names.
		 * @param string $source          Entry source.
		 */
		$blocked_domains = apply_filters( 'aime_email_blocked_domains', $blocked_domains, $source );

		if ( ! empty( $options['skip_test_fake'] ) && in_array( $domain, $blocked_domains, true ) ) {
			return new \WP_Error( 'blocked_domain', __( 'This email domain is not accepted.', 'ai-marketing-expert' ) );
		}

		// Block domains without a TLD (e.g. user@local, user@myserver).
		if ( ! empty( $options['skip_test_fake'] ) && strpos( $domain, '.' ) === false ) {
			return new \WP_Error( 'no_tld', __( 'Email domain must have a valid TLD.', 'ai-marketing-expert' ) );
		}

		// Block .local, .test, .invalid, .localhost TLDs.
		$blocked_tlds = array( '.local', '.test', '.invalid', '.localhost', '.example', '.internal' );

		/**
		 * Filter blocked TLDs.
		 *
		 * @param array $blocked_tlds Blocked TLD extensions.
		 */
		$blocked_tlds = apply_filters( 'aime_email_blocked_tlds', $blocked_tlds );

		foreach ( $blocked_tlds as $tld ) {
			if ( ! empty( $options['skip_test_fake'] ) && substr( $domain, -strlen( $tld ) ) === $tld ) {
				return new \WP_Error( 'blocked_tld', __( 'This email domain is not accepted.', 'ai-marketing-expert' ) );
			}
		}

		// Block suspicious local parts (but NOT admin@ — site owners use it).
		$blocked_prefixes = array(
			'noreply',
			'no-reply',
			'no_reply',
			'donotreply',
			'do-not-reply',
			'mailer-daemon',
			'postmaster',
			'nobody',
			'root',
			'abuse',
			'null',
			'void',
			'devnull',
			'asdf',
			'qwerty',
			'test',
			'testing',
			'aaa',
			'xxx',
			'abc',
			'fake',
			'none',
			'temp',
			'tempuser',
			'user',
		);

		/**
		 * Filter blocked local-part prefixes.
		 *
		 * @param array  $blocked_prefixes Blocked email prefixes.
		 * @param string $source           Entry source.
		 */
		$blocked_prefixes = apply_filters( 'aime_email_blocked_prefixes', $blocked_prefixes, $source );

		if ( ! empty( $options['skip_test_fake'] ) && in_array( $local, $blocked_prefixes, true ) ) {
			return new \WP_Error( 'blocked_prefix', __( 'This email address is not accepted.', 'ai-marketing-expert' ) );
		}

		$role_based_prefixes = apply_filters( 'aime_email_role_based_prefixes', array(
			'admin', 'administrator', 'info', 'support', 'sales', 'hello', 'contact', 'office', 'billing', 'accounts', 'team', 'help', 'service', 'customerservice', 'webmaster',
		), $source );

		if ( ! empty( $options['skip_role_based'] ) && in_array( $local, $role_based_prefixes, true ) ) {
			return new \WP_Error( 'role_based_email', __( 'Role-based email addresses are not accepted.', 'ai-marketing-expert' ) );
		}

		if ( ! empty( $options['skip_spam_patterns'] ) && self::is_suspicious_spam_email( $local, $domain ) ) {
			return new \WP_Error( 'spam_pattern', __( 'This email matches a suspicious spam pattern.', 'ai-marketing-expert' ) );
		}

		// ── Layer 2: Block disposable / temp email domains ──

		if ( ! empty( $options['skip_disposable'] ) && self::is_disposable_domain( $domain ) ) {
			return new \WP_Error( 'disposable_email', __( 'Disposable or temporary email addresses are not allowed.', 'ai-marketing-expert' ) );
		}

		// ── Layer 3: MX record check ─────────────────────
		// Skip for admin/import (bulk performance) unless filter overrides.
		$check_mx = null === $options['check_mx'] ? in_array( $source, array( 'form', 'webhook' ), true ) : (bool) $options['check_mx'];

		/**
		 * Filter whether to perform MX record lookup.
		 *
		 * @param bool   $check_mx Whether to check MX records.
		 * @param string $source   Entry source.
		 * @param string $email    Email being validated.
		 */
		$check_mx = apply_filters( 'aime_email_check_mx', $check_mx, $source, $email );

		if ( $check_mx && ! self::has_mailable_domain( $domain ) ) {
			return new \WP_Error( 'no_mx_record', __( 'This email domain does not appear to accept mail.', 'ai-marketing-expert' ) );
		}

		/**
		 * Final filter: allow Pro or third-party to add extra checks.
		 *
		 * Return a WP_Error to reject, or true to accept.
		 *
		 * @param true|WP_Error $result  Current validation result.
		 * @param string        $email   Email being validated.
		 * @param string        $source  Entry source.
		 */
		return apply_filters( 'aime_email_validation_result', true, $email, $source );
	}

	/**
	 * Check if a domain is in the disposable email list.
	 */
	private static function is_disposable_domain( string $domain ): bool {
		/**
		 * Filter the disposable domains list.
		 *
		 * @param array $domains Array of disposable domain names.
		 */
		$disposable = apply_filters( 'aime_disposable_email_domains', self::get_disposable_domains() );

		// Also check parent domain for subdomains (e.g. user@sub.mailinator.com).
		$parts = explode( '.', $domain );
		if ( count( $parts ) > 2 ) {
			$parent = implode( '.', array_slice( $parts, -2 ) );
			if ( in_array( $parent, $disposable, true ) ) {
				return true;
			}
		}

		return in_array( $domain, $disposable, true );
	}

	/**
	 * Detect obvious import spam without blocking normal dotted addresses.
	 */
	private static function is_suspicious_spam_email( string $local, string $domain ): bool {
		$dot_count = substr_count( $local, '.' );
		if ( $dot_count < 4 ) {
			return false;
		}

		$segments = array_values( array_filter( explode( '.', $local ), static function ( $segment ) {
			return '' !== $segment;
		} ) );

		if ( count( $segments ) < 5 ) {
			return false;
		}

		$short_segments = 0;
		foreach ( $segments as $segment ) {
			if ( strlen( $segment ) <= 2 ) {
				$short_segments++;
			}
		}

		$short_ratio = $short_segments / count( $segments );
		$is_gmail    = in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true );

		return $is_gmail ? $short_ratio >= 0.65 : ( $dot_count >= 6 && $short_ratio >= 0.75 );
	}

	public static function normalize_duplicate_email_key( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) || strpos( $email, '@' ) === false ) {
			return $email;
		}

		list( $local, $domain ) = explode( '@', $email, 2 );
		if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) ) {
			$local  = str_replace( '.', '', $local );
			$domain = 'gmail.com';
		}

		return $local . '@' . $domain;
	}

	public static function is_suspicious_import_identity( string $identity ): bool {
		$identity = trim( $identity );
		if ( strlen( $identity ) < 16 || preg_match( '/\s/', $identity ) ) {
			return false;
		}

		if ( ! preg_match( '/^[A-Za-z]{16,}$/', $identity ) ) {
			return false;
		}

		if ( ! preg_match( '/[a-z]/', $identity ) || ! preg_match( '/[A-Z]/', $identity ) ) {
			return false;
		}

		$vowels = preg_match_all( '/[aeiou]/i', $identity );
		return ( $vowels / strlen( $identity ) ) < 0.35;
	}

	/**
	 * Check if a domain has MX records.
	 */
	public static function has_mailable_domain( string $domain ): bool {
		$domain = strtolower( trim( $domain ) );
		if ( '' === $domain ) {
			return false;
		}

		// checkdnsrr can be slow; cache results for 1 hour.
		$cache_key = 'aime_mx_' . md5( $domain );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$has_mx = checkdnsrr( $domain, 'MX' );

		// If no MX, also check for A record (some domains accept mail via A record).
		if ( ! $has_mx ) {
			$has_mx = checkdnsrr( $domain, 'A' );
		}

		set_transient( $cache_key, $has_mx ? 1 : 0, HOUR_IN_SECONDS );

		return $has_mx;
	}

	/**
	 * Curated list of the most common disposable email domains.
	 *
	 * @return array
	 */
	private static function get_disposable_domains(): array {
		return array(
			// ── Top disposable providers ──
			'mailinator.com',
			'guerrillamail.com',
			'guerrillamail.de',
			'guerrillamail.net',
			'guerrillamail.org',
			'guerrillamail.biz',
			'grr.la',
			'sharklasers.com',
			'guerrillamailblock.com',
			'tempmail.com',
			'temp-mail.org',
			'temp-mail.io',
			'tempmailo.com',
			'throwaway.email',
			'throwaway.com',
			'yopmail.com',
			'yopmail.fr',
			'yopmail.net',
			'yopmail.gq',
			'cool.fr.nf',
			'jetable.fr.nf',
			'nospam.ze.tc',
			'nomail.xl.cx',
			'mega.zik.dj',
			'trashmail.com',
			'trashmail.me',
			'trashmail.net',
			'trashmail.org',
			'trashmail.at',
			'trashmail.io',
			'trashemail.de',
			'dispostable.com',
			'getnada.com',
			'getairmail.com',
			'fakeinbox.com',
			'fakemail.net',
			'fakemailgenerator.com',
			'mailnesia.com',
			'maildrop.cc',
			'maildrop.ml',
			'mailexpire.com',
			'mailtemp.info',
			'mailtemp.net',
			'mail-temporaire.fr',
			'10minutemail.com',
			'10minutemail.net',
			'10minutemail.org',
			'10minutemail.co.za',
			'minutemail.com',
			'20minutemail.com',
			'emailondeck.com',
			'spambox.us',
			'spamcero.com',
			'spamex.com',
			'spamfree24.org',
			'spamgourmet.com',
			'spam4.me',
			'spamherelots.com',
			'spaml.com',
			'spamtrail.com',
			'mytemp.email',
			'mytempmail.com',
			'mohmal.com',
			'mohmal.im',
			'mohmal.in',
			'mohmal.tech',
			'discard.email',
			'discardmail.com',
			'discardmail.de',
			'harakirimail.com',
			'33mail.com',
			'emailnator.com',
			'crazymailing.com',
			'deadaddress.com',
			'despammed.com',
			'devnullmail.com',
			'emailisvalid.com',
			'emailwarden.com',
			'enterto.com',
			'fiifke.de',
			'filzmail.com',
			'inboxalias.com',
			'inboxclean.com',
			'inboxclean.org',
			'incognitomail.com',
			'incognitomail.net',
			'incognitomail.org',
			'instant-mail.de',
			'jetable.com',
			'jetable.net',
			'jetable.org',
			'kasmail.com',
			'koszmail.pl',
			'kurzepost.de',
			'lifebyfood.com',
			'lookugly.com',
			'lr78.com',
			'mailcatch.com',
			'mailforspam.com',
			'mailfree.ga',
			'mailfree.gq',
			'mailfree.ml',
			'mailhub.pw',
			'mailimate.com',
			'mailinater.com',
			'mailincubator.com',
			'mailme.ir',
			'mailme.lv',
			'mailmetrash.com',
			'mailmoat.com',
			'mailnator.com',
			'mailnull.com',
			'mailscrap.com',
			'mailshell.com',
			'mailsiphon.com',
			'mailslite.com',
			'mailzilla.com',
			'mailzilla.org',
			'mbx.cc',
			'mega.zik.dj',
			'meltmail.com',
			'mintemail.com',
			'mjukgansen.com',
			'mobi.web.id',
			'nobulk.com',
			'nospam.wins.com.br',
			'no-spam.ws',
			'nowmymail.com',
			'objectmail.com',
			'obobbo.com',
			'odnorazovoe.ru',
			'onewaymail.com',
			'oopi.org',
			'ordinaryamerican.net',
			'owlpic.com',
			'pjjkp.com',
			'plexolan.de',
			'pookmail.com',
			'proxymail.eu',
			'rcpt.at',
			'reallymymail.com',
			'receiveee.com',
			'regbypass.com',
			'rhyta.com',
			'rklips.com',
			'rmqkr.net',
			'royal.net',
			's0ny.net',
			'safe-mail.net',
			'safersignup.de',
			'safetymail.info',
			'sandelf.de',
			'saynotospams.com',
			'scbox.one.pl',
			'selfdestructingmail.com',
			'sharklasers.com',
			'shieldemail.com',
			'shiftmail.com',
			'shortmail.net',
			'shut.name',
			'sinnlos-mail.de',
			'siteposter.net',
			'slaskpost.se',
			'slipry.net',
			'soodonims.com',
			'sogetthis.com',
			'spamavert.com',
			'spambob.com',
			'spambob.net',
			'spambob.org',
			'spambog.com',
			'spambog.de',
			'spambog.ru',
			'spamcannon.com',
			'spamcannon.net',
			'spamcorptastic.com',
			'spamcowboy.com',
			'spamcowboy.net',
			'spamcowboy.org',
			'spamday.com',
			'spameater.com',
			'spameater.org',
			'spamfighter.cf',
			'spamfighter.ga',
			'spamfighter.gq',
			'spamfighter.ml',
			'spamfighter.tk',
			'spamfree.eu',
			'spamhole.com',
			'spaml.de',
			'spammotel.com',
			'spamobox.com',
			'spamoff.de',
			'spamslicer.com',
			'spamspot.com',
			'spamstack.net',
			'spamthis.co.uk',
			'spamtrap.ro',
			'superrito.com',
			'suremail.info',
			'teleworm.us',
			'tempalias.com',
			'tempe4mail.com',
			'tempemail.biz',
			'tempemail.co.za',
			'tempemail.com',
			'tempemail.net',
			'tempinbox.com',
			'tempinbox.co.uk',
			'tempmail.de',
			'tempmail.eu',
			'tempmail.it',
			'tempmail2.com',
			'tempmailer.com',
			'tempmailer.de',
			'tempomail.fr',
			'temporaryemail.net',
			'temporaryemail.us',
			'temporaryforwarding.com',
			'temporaryinbox.com',
			'temporarymailaddress.com',
			'thanksnospam.info',
			'thankyou2010.com',
			'thisisnotmyrealemail.com',
			'throwam.com',
			'tittbit.in',
			'tizi.com',
			'tmailinator.com',
			'tradermail.info',
			'trash-amil.com',
			'trash-mail.at',
			'trash-mail.com',
			'trash-mail.de',
			'trash2009.com',
			'trashdevil.com',
			'trashdevil.de',
			'trashymail.com',
			'trashymail.net',
			'trbvm.com',
			'trbvn.com',
			'turual.com',
			'twinmail.de',
			'tyldd.com',
			'uggsrock.com',
			'umail.net',
			'upliftnow.com',
			'uplipht.com',
			'venompen.com',
			'veryreallyfakemail.com',
			'viditag.com',
			'viewcastmedia.com',
			'vomoto.com',
			'vpn.st',
			'vsimcard.com',
			'vubby.com',
			'wasteland.rfc822.org',
			'webemail.me',
			'weg-werf-email.de',
			'wegwerfadresse.de',
			'wegwerfemail.com',
			'wegwerfemail.de',
			'wegwerfmail.de',
			'wegwerfmail.info',
			'wegwerfmail.net',
			'wegwerfmail.org',
			'wh4f.org',
			'whatpaas.com',
			'whyspam.me',
			'willhackforfood.biz',
			'willselfdestruct.com',
			'winemaven.info',
			'wronghead.com',
			'wuzup.net',
			'wuzupmail.net',
			'wwwnew.eu',
			'xagloo.com',
			'xemaps.com',
			'xents.com',
			'xmaily.com',
			'xoxy.net',
			'yapped.net',
			'yep.it',
			'yogamaven.com',
			'yuurok.com',
			'zehnminutenmail.de',
			'zippymail.info',
			'zoaxe.com',
			'zoemail.org',
			// ── Additional well-known providers ──
			'burnermail.io',
			'tempail.com',
			'tempr.email',
			'internxt.com',
			'simplelogin.com',
			'duck.com',
			'anonaddy.me',
			'anonaddy.com',
			'relay.firefox.com',
			'mozmail.com',
		);
	}
}
