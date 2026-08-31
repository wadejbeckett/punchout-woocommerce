<?php
/**
 * PSR-4-style autoloader with no Composer dependency.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal PSR-4 autoloader.
 *
 * Deliberately dependency-free: the plugin ships to a Plesk-managed WordPress
 * install where running `composer install` on deploy is not guaranteed, so a
 * vendor/ directory cannot be relied upon.
 * Class files map 1:1 onto the namespace under includes/
 * (e.g. POW\Cxml\Parser => includes/Cxml/Parser.php).
 */
final class Autoloader {

	/**
	 * Registered prefix => base directory pairs.
	 *
	 * @var array<string, string>
	 */
	private static array $prefixes = [];

	/**
	 * Whether spl_autoload_register has already been called.
	 */
	private static bool $registered = false;

	/**
	 * Register a namespace prefix against a base directory.
	 *
	 * @param string $prefix   Namespace prefix, e.g. "POW".
	 * @param string $base_dir Absolute directory holding that namespace.
	 */
	public static function register( string $prefix, string $base_dir ): void {
		self::$prefixes[ trim( $prefix, '\\' ) . '\\' ] = rtrim( $base_dir, '/\\' ) . '/';

		if ( self::$registered ) {
			return;
		}

		spl_autoload_register( [ self::class, 'load' ] );
		self::$registered = true;
	}

	/**
	 * Resolve and require a class file.
	 *
	 * @param string $class_name Fully-qualified class name.
	 */
	public static function load( string $class_name ): void {
		foreach ( self::$prefixes as $prefix => $base_dir ) {
			if ( ! str_starts_with( $class_name, $prefix ) ) {
				continue;
			}

			$relative = substr( $class_name, strlen( $prefix ) );
			$path     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

			// realpath() guards against a crafted class name escaping the
			// plugin directory via traversal segments.
			$real = realpath( $path );

			if ( false !== $real && str_starts_with( $real, realpath( $base_dir ) ?: $base_dir ) ) {
				require_once $real;
				return;
			}
		}
	}
}
