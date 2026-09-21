<?php
/**
 * Delay / Wait action — pauses workflow execution for a specified duration.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions
 */

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DelayAction extends BaseAction {

	/**
	 * Run the delay action.
	 *
	 * @param array $config  Step configuration (delay_value, delay_unit).
	 * @param array $context Workflow context.
	 * @return array
	 */
	public static function run( array $config, array $context ): array {
		$value = absint( $config['delay_value'] ?? 5 );
		$unit  = sanitize_key( $config['delay_unit'] ?? 'minutes' );

		if ( $value <= 0 ) {
			$value = 1;
		}

		$seconds = match ( $unit ) {
			'seconds' => $value,
			'hours'   => $value * HOUR_IN_SECONDS,
			'days'    => $value * DAY_IN_SECONDS,
			default   => $value * MINUTE_IN_SECONDS,
		};

		// For pacing and short delays (up to 30 seconds), execute an inline sleep.
		if ( $seconds > 0 && $seconds <= 30 ) {
			sleep( $seconds );
		}

		$duration_label = sprintf( '%d %s', $value, $unit );

		return self::ok(
			sprintf(
				/* translators: %s: duration label */
				__( 'Wait duration of %s observed.', 'ai-marketing-expert' ),
				$duration_label
			),
			array(
				'delay_value'   => $value,
				'delay_unit'    => $unit,
				'delay_seconds' => $seconds,
			)
		);
	}
}