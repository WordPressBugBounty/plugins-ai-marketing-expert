<?php
/**
 * Send Notification action — emails a templated message with workflow tokens.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions
 */

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions;

use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\WorkflowTokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendNotificationAction extends BaseAction {

	/**
	 * @param array $config  Step config: to, subject, body.
	 * @param array $context Workflow context.
	 * @return array
	 */
	public static function run( array $config, array $context ): array {
		$to = sanitize_email( WorkflowTokens::replace( (string) ( $config['to'] ?? '' ), $context ) );
		if ( '' === $to ) {
			$to = (string) get_option( 'admin_email' );
		}
		if ( ! is_email( $to ) ) {
			return self::fail( __( 'Invalid notification recipient.', 'ai-marketing-expert' ) );
		}

		$subject = self::replace_tokens( (string) ( $config['subject'] ?? '' ), $context );
		$body    = self::replace_tokens( (string) ( $config['body'] ?? '' ), $context );

		if ( '' === trim( $subject ) ) {
			$subject = sprintf(
				/* translators: %s: workflow name */
				__( 'Workflow notification: %s', 'ai-marketing-expert' ),
				(string) ( $context['workflow_name'] ?? '' )
			);
		}

		$sent = wp_mail( $to, $subject, $body );
		if ( ! $sent ) {
			return self::fail( __( 'wp_mail() failed to send the notification.', 'ai-marketing-expert' ) );
		}

		return self::ok(
			sprintf(
				/* translators: 1: recipient, 2: subject */
				__( 'Notification sent to %1$s: %2$s', 'ai-marketing-expert' ),
				$to,
				$subject
			)
		);
	}

	/**
	 * Replace workflow tokens in a template string.
	 *
	 * Delegates to the shared WorkflowTokens engine so every step resolves the
	 * same vocabulary: {topic} {workflow_name} {previous_preview}
	 * {event.dot.path} {previous.reference_field} {action_type.reference_field}.
	 */
	private static function replace_tokens( string $template, array $context ): string {
		return WorkflowTokens::replace( $template, $context );
	}
}
