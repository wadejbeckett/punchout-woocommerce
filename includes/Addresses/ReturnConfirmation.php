<?php
/** Immutable preparation and the final read-only delivery guard for cart return. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Addresses;

use POW\Partners\Partner;
use POW\Sessions\Session;

defined( 'ABSPATH' ) || exit;

interface ReturnConfirmation {
	public function for_return( Session $session, Partner $partner ): array|\WP_Error;
	/** The caller holds the partner mutex; this method must not calculate rates or select a return winner. */
	public function validate_prepared_locked( Session $session, Partner $partner, array $prepared ): bool|\WP_Error;
}
