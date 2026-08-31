<?php
/**
 * Parse failure carrying its cXML Status code.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Cxml;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the Parser; the endpoint translates it into a cXML Status
 * document inside an HTTP 200 (never a bare HTTP 4xx — scope §3, gotcha 6).
 *
 * Codes used: 406 unparseable / structurally invalid, 450 unsupported
 * request name or operation.
 */
final class ParseException extends \RuntimeException {

	public function __construct(
		string $message,
		public readonly int $cxml_status = 406
	) {
		parent::__construct( $message );
	}
}
