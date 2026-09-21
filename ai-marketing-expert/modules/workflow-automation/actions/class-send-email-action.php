<?php
/**
 * Send Email action — dispatches an email directly using SMTP rotation and fallback.
 *
 * Supports workflow tokens in recipient, subject, and body, with HTML formatting
 * and multi-connection SMTP failover via SmtpProvider::send_with_fallback().
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions
 */

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions;

use WPSpace\AiMarketingExpert\SmtpProvider;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\WorkflowTokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendEmailAction extends BaseAction {

	/**
	 * @param array $config  Step config: to, subject, body, from_name, from_email, reply_to.
	 * @param array $context Workflow context.
	 * @return array
	 */
	public static function run( array $config, array $context ): array {
		$raw_to = (string) ( $config['to'] ?? '' );
		if ( '' === trim( $raw_to ) ) {
			// Fallback: check event for email
			if ( ! empty( $context['event']['email'] ) ) {
				$raw_to = (string) $context['event']['email'];
			} elseif ( ! empty( $context['event']['user_email'] ) ) {
				$raw_to = (string) $context['event']['user_email'];
			} elseif ( ! empty( $context['event']['billing_email'] ) ) {
				$raw_to = (string) $context['event']['billing_email'];
			}
		}

		$to = sanitize_email( WorkflowTokens::replace( $raw_to, $context ) );
		if ( ! is_email( $to ) ) {
			return self::fail( __( 'Invalid or missing recipient email address.', 'ai-marketing-expert' ) );
		}

		$subject = WorkflowTokens::replace( (string) ( $config['subject'] ?? '' ), $context );
		if ( '' === trim( $subject ) ) {
			$subject = sprintf(
				/* translators: %s: workflow name */
				__( 'Update from %s', 'ai-marketing-expert' ),
				(string) ( $context['workflow_name'] ?? get_bloginfo( 'name' ) )
			);
		}

		$body = WorkflowTokens::replace( (string) ( $config['body'] ?? '' ), $context );
		if ( '' === trim( $body ) ) {
			return self::fail( __( 'Email body cannot be empty.', 'ai-marketing-expert' ) );
		}

		// Prepare headers
		$from_name  = ! empty( $config['from_name'] ) ? sanitize_text_field( $config['from_name'] ) : get_option( 'aime_from_name', get_bloginfo( 'name' ) );
		$from_email = ! empty( $config['from_email'] ) ? sanitize_email( $config['from_email'] ) : get_option( 'aime_from_email', get_option( 'admin_email' ) );
		$reply_to   = ! empty( $config['reply_to'] ) ? sanitize_email( $config['reply_to'] ) : get_option( 'aime_reply_to', '' );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			"From: {$from_name} <{$from_email}>",
		);

		if ( is_email( $reply_to ) ) {
			$headers[] = "Reply-To: {$reply_to}";
		}

		// If body is already a full document template (<!DOCTYPE, <html, or <table), preserve as-is;
		// otherwise run wpautop() so paragraphs, line breaks, and <div> button containers render flawlessly.
		$is_full_doc    = (bool) preg_match( '/^\s*(<!DOCTYPE|<html|<table)/i', $body );
		$formatted_body = $is_full_doc ? $body : wpautop( $body );

		// Send via SmtpProvider with multi-connection fallback
		$sent = SmtpProvider::send_with_fallback( $to, $subject, $formatted_body, $headers );

		if ( null === $sent ) {
			return self::fail( __( 'SMTP daily limit reached on all configured connections. Email held.', 'ai-marketing-expert' ) );
		}

		if ( ! $sent ) {
			return self::fail( __( 'Failed to dispatch email via SMTP provider.', 'ai-marketing-expert' ) );
		}

		return self::ok(
			sprintf(
				/* translators: 1: recipient, 2: subject */
				__( 'Email sent to %1$s: "%2$s"', 'ai-marketing-expert' ),
				$to,
				$subject
			),
			array(
				'recipient' => $to,
				'subject'   => $subject,
			)
		);
	}
}
