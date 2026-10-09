<?php
/**
 * Validate one cXML document against the exact official DTD it declares.
 *
 *   php tests/E2E/dtd.php < message.xml
 *
 * Prints {"valid":bool,"errors":[...]} and exits 0 when valid, 1 when not.
 * The DTDs are the repository's own unchanged copies (tests/fixtures/cxml),
 * resolved locally by ExactCxmlDtd; nothing is fetched.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

require_once dirname( __DIR__ ) . '/Support/ExactCxmlDtd.php';

$xml    = (string) stream_get_contents( STDIN );
$result = ExactCxmlDtd::validate( $xml );
echo json_encode( [ 'valid' => $result['valid'], 'errors' => array_values( $result['errors'] ) ] ), "\n";
exit( $result['valid'] ? 0 : 1 );
