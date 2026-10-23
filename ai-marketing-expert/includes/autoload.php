<?php
/**
 * Autoloader for AI Marketing Expert.
 *
 * @package WPSpace\AiMarketingExpert
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register( function ( $class ) {
	$prefix = 'WPSpace\\AiMarketingExpert\\';
	$len    = strlen( $prefix );

	// Check if the class uses our namespace.
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	// Get relative class name.
	$relative_class = substr( $class, $len );

	// Convert namespace separators to directory separators.
	$path = str_replace( '\\', '/', $relative_class );

	// Split into parts.
	$parts    = explode( '/', $path );
	$classname = array_pop( $parts );

	// Convert CamelCase class name to kebab-case filename.
	$kebab = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $classname ) );
	// Try class- prefix first, then trait- prefix.
	$prefixes = array( 'class-', 'trait-' );

	// Convert namespace parts from CamelCase to kebab-case (lowercase).
	$dir_parts = array_map( function ( $part ) {
		return strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $part ) );
	}, $parts );

	// Also keep lowercase versions of the original namespace parts as a fallback
	// for case-sensitive filesystems (Linux) when directories use PascalCase.
	$dir_parts_lower = array_map( 'strtolower', $parts );

	// Build all directory-part variants to try (kebab-case first, then lowercase original).
	$dir_variants = array( $dir_parts );
	if ( $dir_parts_lower !== $dir_parts ) {
		$dir_variants[] = $dir_parts_lower;
	}

	foreach ( $prefixes as $file_prefix ) {
		$filename = $file_prefix . $kebab . '.php';

		foreach ( $dir_variants as $current_dir_parts ) {
			// Try includes/ directory first.
			if ( ! empty( $current_dir_parts ) ) {
				$subdir = implode( '/', $current_dir_parts );
				$file   = AIME_PLUGIN_DIR . 'includes/' . $subdir . '/' . $filename;
			} else {
				$file = AIME_PLUGIN_DIR . 'includes/' . $filename;
			}

			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}

			// Try modules/ directory (for Modules\EmailMarketing\* namespace).
			if ( ! empty( $current_dir_parts ) && 'modules' === $current_dir_parts[0] ) {
				$module_parts = array_slice( $current_dir_parts, 1 );
				if ( ! empty( $module_parts ) ) {
					$subdir = implode( '/', $module_parts );
					$file   = AIME_PLUGIN_DIR . 'modules/' . $subdir . '/' . $filename;
				} else {
					$file = AIME_PLUGIN_DIR . 'modules/' . $filename;
				}

				if ( file_exists( $file ) ) {
					require_once $file;
					return;
				}
			}

			// Fallback: try modules/ directory directly with all parts.
			if ( ! empty( $current_dir_parts ) ) {
				$subdir = implode( '/', $current_dir_parts );
				$file   = AIME_PLUGIN_DIR . 'modules/' . $subdir . '/' . $filename;
			} else {
				$file = AIME_PLUGIN_DIR . 'modules/' . $filename;
			}

			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
} );
