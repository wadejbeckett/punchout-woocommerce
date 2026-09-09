<?php
/**
 * Template location.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Locates plugin templates with theme override support: a theme (or child
 * theme) file at punchout-woocommerce/{name}.php wins over the plugin's
 * templates/{name}.php, and the resolved path is filterable per template
 * (`pow_template_{name}`), so builders can re-skin every buyer-facing
 * surface without touching the plugin.
 */
final class Templates {

	/**
	 * Path-safe template name: one or more segments of [A-Za-z0-9._-],
	 * traversal segments dropped. Subdirectories are allowed (templates
	 * live under templates/docs/ as well as templates/); the old
	 * sanitize_file_name() call ate the separator, so "docs/page"
	 * resolved to the file "docspage.php" and never loaded.
	 */
	public static function normalise_name( string $name ): string {
		$parts = array_map(
			static fn( string $part ): string => (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $part ),
			preg_split( '#/+#', $name ) ?: []
		);

		$parts = array_filter( $parts, static fn( string $part ): bool => '' !== $part && '.' !== $part && '..' !== $part );

		return implode( '/', $parts );
	}

	/**
	 * Resolve a template path by name (e.g. "return-button", "docs/page").
	 */
	public static function locate( string $name ): string {
		$name  = self::normalise_name( $name );
		$theme = locate_template( [ 'punchout-woocommerce/' . $name . '.php' ] );
		$file  = '' !== $theme ? $theme : POW_PLUGIN_DIR . 'templates/' . $name . '.php';

		/**
		 * Filter the resolved path of a plugin template.
		 *
		 * @param string $file Absolute template path.
		 */
		return (string) apply_filters( "pow_template_{$name}", $file );
	}

	/**
	 * Render a template to a string with extracted variables.
	 *
	 * @param string               $name Template base name.
	 * @param array<string, mixed> $vars Variables exposed to the template.
	 */
	public static function render( string $name, array $vars = [] ): string {
		$file = self::locate( $name );

		if ( ! is_readable( $file ) ) {
			return '';
		}

		ob_start();
		( static function ( string $__pow_template_file, array $__pow_template_vars ): void {
			extract( $__pow_template_vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- scoped closure, template convention.
			include $__pow_template_file;
		} )( $file, $vars );

		return (string) ob_get_clean();
	}
}
