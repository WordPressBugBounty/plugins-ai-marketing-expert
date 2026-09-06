<?php
/**
 * Trigger Registry — resolves the shared `aime_workflow_triggers` filter.
 *
 * Mirrors ActionRegistry: the registry is the single, additive seam through
 * which this module (and any third party) exposes event triggers. Each entry:
 *
 *   'trigger_key' => array(
 *       'label'       => string,
 *       'module'      => string,
 *       'description' => string,
 *       'hook'        => string        WordPress action to listen on ('' = pseudo, e.g. schedule),
 *       'hook_args'   => int           Number of hook arguments to forward,
 *       'available'   => callable():bool,
 *       'fields'      => array         Config fields shown on the trigger node,
 *       'match'       => callable(array $config, ...$args): array|false
 *                        Returns the event payload when the workflow should fire.
 *   )
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes
 */

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TriggerRegistry {

	/**
	 * Cached, resolved trigger definitions.
	 *
	 * @var array<string,array>|null
	 */
	private static ?array $cache = null;

	/**
	 * All registered triggers keyed by trigger key.
	 *
	 * @return array<string,array>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$triggers    = apply_filters( 'aime_workflow_triggers', array() );
			self::$cache = is_array( $triggers ) ? $triggers : array();
		}
		return self::$cache;
	}

	/**
	 * Reset the cache (used after module activation changes availability).
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * A single trigger definition, or null if unknown.
	 */
	public static function get( string $key ): ?array {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Whether a trigger exists and its source module is currently available.
	 */
	public static function is_available( string $key ): bool {
		$def = self::get( $key );
		if ( ! $def ) {
			return false;
		}
		$check = $def['available'] ?? null;
		if ( is_callable( $check ) ) {
			return (bool) $check();
		}
		return true;
	}

	/**
	 * Event triggers only (entries with a real hook to listen on).
	 *
	 * @return array<string,array>
	 */
	public static function event_triggers(): array {
		return array_filter(
			self::all(),
			static fn ( array $def ): bool => ! empty( $def['hook'] )
		);
	}

	/**
	 * Run a trigger's match callable against a hook's arguments.
	 *
	 * @param string $key    Trigger key.
	 * @param array  $config Workflow trigger_config.
	 * @param array  $args   Hook arguments.
	 * @return array|false Event payload when matched, false otherwise.
	 */
	public static function match( string $key, array $config, array $args ) {
		$def = self::get( $key );
		if ( ! $def || ! is_callable( $def['match'] ?? null ) ) {
			return false;
		}
		try {
			return call_user_func( $def['match'], $config, ...$args );
		} catch ( \Throwable $e ) {
			aime_log( sprintf( 'Trigger "%s" match failed: %s', $key, $e->getMessage() ), 'warning', 'workflow-automation' );
			return false;
		}
	}

	/**
	 * Triggers formatted for the REST/React builder (callables stripped or
	 * resolved to booleans so the payload is JSON-safe).
	 *
	 * @return array<int,array>
	 */
	public static function for_api(): array {
		$out = array();
		foreach ( self::all() as $key => $def ) {
			$available = true;
			if ( is_callable( $def['available'] ?? null ) ) {
				$available = (bool) call_user_func( $def['available'] );
			}
			$out[] = array(
				'key'            => $key,
				'label'          => $def['label'] ?? $key,
				'module'         => $def['module'] ?? '',
				'description'    => $def['description'] ?? '',
				'available'      => $available,
				'fields'         => ActionRegistry::resolve_fields( $def['fields'] ?? array() ),
				'payload_fields' => array_values( (array) ( $def['payload_fields'] ?? array() ) ),
			);
		}
		return $out;
	}
}
