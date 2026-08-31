<?php
/**
 * "View details" modal on the Plugins screen.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress only renders the "View details" thickbox for plugins it can
 * resolve against wordpress.org, so a self-hosted plugin has to supply both
 * halves itself: the row link (plugin_row_meta) and the information payload
 * (plugins_api). The payload is built from the bundled readme.txt so the
 * modal can never drift from the shipped docs — there is no second copy of
 * the description to keep in sync.
 */
final class Details {

	private const SECTION_KEYS = [
		'Description'                => 'description',
		'Installation'               => 'installation',
		'Frequently Asked Questions' => 'faq',
		'Changelog'                  => 'changelog',
	];

	public function register(): void {
		add_filter( 'plugin_row_meta', [ $this, 'row_meta' ], 10, 2 );
		add_filter( 'plugins_api', [ $this, 'plugin_information' ], 10, 3 );
	}

	/**
	 * @param string[] $meta Row meta links.
	 * @return string[]
	 */
	public function row_meta( array $meta, string $plugin_file ): array {
		if ( plugin_basename( POW_PLUGIN_FILE ) !== $plugin_file ) {
			return $meta;
		}

		$url = self_admin_url(
			'plugin-install.php?tab=plugin-information&plugin=' . Page::SLUG . '&TB_iframe=true&width=600&height=550'
		);

		$meta[] = sprintf(
			'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s">%s</a>',
			esc_url( $url ),
			esc_attr__( 'More information about PunchOut for WooCommerce', 'punchout-woocommerce' ),
			esc_html__( 'View details', 'punchout-woocommerce' )
		);

		return $meta;
	}

	/**
	 * @param false|object|array $result Default false; anything else short-circuits the wp.org lookup.
	 * @param string             $action plugins_api action.
	 * @param object             $args   Request args (slug, fields).
	 * @return false|object|array
	 */
	public function plugin_information( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== Page::SLUG ) {
			return $result;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$header = get_plugin_data( POW_PLUGIN_FILE, false, false );

		return (object) [
			'name'          => $header['Name'],
			'slug'          => Page::SLUG,
			'version'       => $header['Version'],
			'author'        => sprintf( '<a href="%s">%s</a>', esc_url( 'https://noiz.co.za/' ), esc_html( $header['Author'] ) ),
			'homepage'      => $header['PluginURI'],
			'requires'      => $header['RequiresWP'],
			'requires_php'  => $header['RequiresPHP'],
			'sections'      => $this->sections(),
			'external'      => true,
		];
	}

	/**
	 * @return array<string,string> Section key => HTML.
	 */
	private function sections(): array {
		$readme = POW_PLUGIN_DIR . 'readme.txt';
		$raw    = is_readable( $readme ) ? (string) file_get_contents( $readme ) : '';

		if ( '' === $raw ) {
			return [ 'description' => '<p>' . esc_html__( 'readme.txt is missing from the plugin package.', 'punchout-woocommerce' ) . '</p>' ];
		}

		$sections = [];
		$parts    = preg_split( '/^==\s*(.+?)\s*==\s*$/m', str_replace( "\r\n", "\n", $raw ), -1, PREG_SPLIT_DELIM_CAPTURE );

		// $parts alternates [preamble, title, body, title, body, ...].
		$count = count( $parts );
		for ( $i = 1; $i + 1 < $count; $i += 2 ) {
			$key = self::SECTION_KEYS[ $parts[ $i ] ] ?? null;
			if ( null !== $key ) {
				$sections[ $key ] = $this->to_html( $parts[ $i + 1 ] );
			}
		}

		return $sections;
	}

	/**
	 * Minimal readme-markdown-to-HTML: headings, lists, bold/em/code,
	 * paragraphs. Everything passes through esc_html before any markup is
	 * added, so readme content can never inject live HTML into the modal.
	 */
	private function to_html( string $text ): string {
		$html    = '';
		$list    = null; // 'ul' | 'ol' | null — the list element currently open.
		$closes  = static function ( ?string &$list, string &$html ): void {
			if ( null !== $list ) {
				$html .= "</{$list}>\n";
				$list  = null;
			}
		};
		$para    = [];
		$flush   = function () use ( &$para, &$html ): void {
			if ( [] !== $para ) {
				$html .= '<p>' . $this->inline( implode( ' ', $para ) ) . "</p>\n";
				$para  = [];
			}
		};

		foreach ( explode( "\n", trim( $text ) ) as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				$flush();
				$closes( $list, $html );
				continue;
			}

			if ( preg_match( '/^=\s*(.+?)\s*=$/', $line, $m ) ) {
				$flush();
				$closes( $list, $html );
				$html .= '<h4>' . $this->inline( $m[1] ) . "</h4>\n";
				continue;
			}

			if ( preg_match( '/^(\*|\d+\.)\s+(.+)$/', $line, $m ) ) {
				$flush();
				$tag = '*' === $m[1] ? 'ul' : 'ol';
				if ( $list !== $tag ) {
					$closes( $list, $html );
					$html .= "<{$tag}>\n";
					$list  = $tag;
				}
				$html .= '<li>' . $this->inline( $m[2] ) . "</li>\n";
				continue;
			}

			$closes( $list, $html );
			$para[] = $line;
		}

		$flush();
		$closes( $list, $html );

		return $html;
	}

	private function inline( string $text ): string {
		$text = esc_html( $text );
		$text = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		$text = (string) preg_replace( '/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $text );
		$text = (string) preg_replace( '/`([^`\n]+)`/', '<code>$1</code>', $text );

		return $text;
	}
}
