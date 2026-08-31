<?php
/**
 * Parsed inbound cXML request.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Cxml;

defined( 'ABSPATH' ) || exit;

/**
 * Value object produced by the Parser for the two request kinds the plugin
 * services: PunchOutSetupRequest and ProfileRequest.
 *
 * Everything is a plain scalar/array so the object can be logged, JSON-
 * serialised onto the session row, and asserted in tests without WordPress.
 */
final class SetupMessage {

	public const KIND_SETUP   = 'setup';
	public const KIND_PROFILE = 'profile';

	public function __construct(
		public readonly string $kind,
		public readonly string $payload_id,
		public readonly string $timestamp,
		public readonly string $version,
		public readonly string $lang,
		public readonly string $deployment_mode,
		public readonly string $from_domain,
		public readonly string $from_identity,
		public readonly string $to_domain,
		public readonly string $to_identity,
		public readonly string $sender_domain,
		public readonly string $sender_identity,
		public readonly ?string $shared_secret,
		public readonly string $user_agent,
		// Setup-only fields; empty/neutral defaults for ProfileRequest.
		public readonly string $operation = 'create',
		public readonly string $buyer_cookie = '',
		public readonly string $browser_form_post = '',
		/** @var array<string, string> */
		public readonly array $extrinsics = [],
		public readonly ?string $contact_email = null,
		public readonly ?string $ship_to_xml = null,
		/** @var array{supplier_part_id: string, aux_id: string}|null */
		public readonly ?array $selected_item = null,
		/** @var list<array{quantity: string, supplier_part_id: string, aux_id: string}> */
		public readonly array $item_out = [],
	) {}

	/**
	 * A case-insensitive extrinsic lookup, since D365 admins type the names.
	 */
	public function extrinsic( string $name ): ?string {
		foreach ( $this->extrinsics as $key => $value ) {
			if ( 0 === strcasecmp( $key, $name ) ) {
				return $value;
			}
		}

		return null;
	}
}
