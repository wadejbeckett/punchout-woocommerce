<?php
/** Core address-provider boundary. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Addresses;

use POW\Sessions\Session;

defined( 'ABSPATH' ) || exit;

interface Provider {
	/** @return list<array{key:string,label:string,address:array,code:string}> */
	public function list_for_user( int $user_id ): array;
	public function selected_for_session( Session $session ): ?array;
	public function set_code( int $user_id, string $key, string $code ): bool;
}
