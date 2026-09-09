<?php
/** Bounded credential recovery outcome, with no database or credential detail. @package POW */
declare( strict_types = 1 );

namespace POW\Partners;

defined( 'ABSPATH' ) || exit;

final class CredentialRecoveryException extends \RuntimeException {
	public function __construct( bool $disabled_confirmed ) {
		parent::__construct( $disabled_confirmed ? 'recovery_confirmed_disabled' : 'recovery_unconfirmed' );
	}
}
