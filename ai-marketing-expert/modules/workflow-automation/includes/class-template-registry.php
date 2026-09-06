<?php
/**
 * Template Registry — central catalog of workflow blueprints.
 *
 * Templates register through the `aime_workflow_templates` filter. Each entry:
 *
 *   'template_id' => array(
 *       'name'             => __( 'Weekly Blog Engine', ... ),
 *       'description'      => __( '...', ... ),
 *       'icon'             => 'edit',              // client icon key
 *       'is_pro'           => false,
 *       'requires_modules' => array( 'content-marketing' ),
 *       'workflow'         => array( ... ),        // sanitize_workflow()-compatible fields
 *       'steps'            => array(               // blueprint steps, re-keyed on apply
 *           array( 'key' => 'a', 'parent_key' => '', 'branch' => 'default',
 *                  'action_type' => 'generate_blog_post', 'config' => array( ... ) ),
 *       ),
 *   )
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes
 */

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TemplateRegistry {

	private static ?array $cache = null;

	/** All registered templates, keyed by template id. */
	public static function all(): array {
		if ( null === self::$cache ) {
			$templates   = apply_filters( 'aime_workflow_templates', array() );
			self::$cache = is_array( $templates ) ? $templates : array();
		}
		return self::$cache;
	}

	public static function flush(): void {
		self::$cache = null;
	}

	public static function get( string $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/** True when every module the template depends on is active. */
	public static function is_available( array $template ): bool {
		foreach ( (array) ( $template['requires_modules'] ?? array() ) as $module_id ) {
			if ( ! aime()->modules()->is_active( (string) $module_id ) ) {
				return false;
			}
		}
		return true;
	}

	/** Client-safe list for the template picker (no step configs). */
	public static function for_api(): array {
		$out = array();
		foreach ( self::all() as $id => $tpl ) {
			$out[] = array(
				'id'               => $id,
				'name'             => (string) ( $tpl['name'] ?? $id ),
				'description'      => (string) ( $tpl['description'] ?? '' ),
				'icon'             => (string) ( $tpl['icon'] ?? 'settings' ),
				'is_pro'           => ! empty( $tpl['is_pro'] ),
				'requires_modules' => array_values( (array) ( $tpl['requires_modules'] ?? array() ) ),
				'available'        => self::is_available( $tpl ),
				'steps_count'      => count( (array) ( $tpl['steps'] ?? array() ) ),
				'trigger_type'     => (string) ( $tpl['workflow']['trigger_type'] ?? 'schedule' ),
				'trigger_event'    => (string) ( $tpl['workflow']['trigger_event'] ?? '' ),
			);
		}
		return $out;
	}
}
