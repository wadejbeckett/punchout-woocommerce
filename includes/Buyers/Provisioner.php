<?php
/**
 * Per-buyer user provisioning.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Buyers;

use POW\Audit\Log;
use POW\Cxml\SetupMessage;
use POW\Installer;
use POW\Logger;
use POW\Partners\Partner;
use POW\Sessions\Session;
use POW\Sessions\Store;

defined( 'ABSPATH' ) || exit;

/**
 * One WP user per (partner, buyer identity) — decisive because WooCommerce
 * keys the session (and therefore the cart) on the user id: a shared user
 * would merge every concurrent buyer into one cart (scope §5.1).
 *
 * Identity chain: agreed extrinsics (UserEmail, then UniqueUsername) ->
 * Contact/Email -> ephemeral per-session identity, flagged in the log.
 * The email extrinsic is user-editable on the buyer side, so it is a
 * mapping hint, never proof of identity.
 */
final class Provisioner {

	public function __construct(
		private Store $sessions,
		private Log $audit,
		private Logger $logger,
	) {}

	/**
	 * Locate or create the buyer user. 0 on failure.
	 */
	public function provision( Partner $partner, SetupMessage $message ): int {
		$identity  = $this->resolve_identity( $partner, $message );
		$ephemeral = null === $identity;

		if ( $ephemeral ) {
			// No usable identity: an ephemeral per-session user still lets
			// the punchout proceed; the log makes the gap visible so the
			// extrinsic agreement can be fixed (scope §5.1).
			$identity = 'ephemeral:' . $message->payload_id . ':' . wp_generate_password( 8, false );
			$this->logger->warning( 'No buyer identity in setup request; provisioning ephemeral user', [ 'partner' => $partner->id ] );
		}

		$username = $this->username( $partner, $identity );
		$existing = get_user_by( 'login', $username );

		// The login embeds a slug of the customer's (mutable) name; only
		// the hash half is identity-stable. If the name was edited since
		// this buyer's first punchout, find them by the stable meta pair
		// instead of minting an orphan twin.
		if ( false === $existing && ! $ephemeral ) {
			$found = get_users(
				[
					'number'     => 1,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'meta_query' => [
						[
							'key'   => '_pow_partner_id',
							'value' => (string) $partner->id,
						],
						[
							'key'   => '_pow_identity',
							'value' => $identity,
						],
					],
				]
			);

			$existing = $found[0] ?? false;
		}

		if ( false !== $existing && $existing instanceof \WP_User ) {
			update_user_meta( $existing->ID, '_pow_last_seen', time() );
			delete_user_meta( $existing->ID, '_pow_deactivated' );

			/** This hook is documented below, at the new-user call site. */
			do_action( 'pow_buyer_provisioned', $existing->ID, $partner, false );

			return $existing->ID;
		}

		$email = ( is_email( $identity ) && ! email_exists( $identity ) )
			? $identity
			: $username . '@punchout.invalid';

		$display = $message->extrinsic( 'UserPrintableName' )
			?? $message->extrinsic( 'UserFullName' )
			?? $message->extrinsic( 'UniqueUsername' )
			?? $message->extrinsic( 'User' );

		$user_id = wp_insert_user(
			[
				'user_login'   => $username,
				// Never used: punchout logins only ever happen through the
				// one-time StartPage token.
				'user_pass'    => wp_generate_password( 32 ),
				'user_email'   => $email,
				'display_name' => null !== $display && '' !== $display ? $display : $username,
				'role'         => Installer::ROLE,
			]
		);

		if ( is_wp_error( $user_id ) ) {
			$this->logger->error( 'Buyer provisioning failed', [ 'error' => $user_id->get_error_message(), 'partner' => $partner->id ] );

			return 0;
		}

		update_user_meta( $user_id, '_pow_partner_id', $partner->id );
		update_user_meta( $user_id, '_pow_identity', $identity );
		update_user_meta( $user_id, '_pow_last_seen', time() );

		if ( $ephemeral ) {
			update_user_meta( $user_id, '_pow_ephemeral', 1 );
		}

		/**
		 * Fires every time a punchout buyer is provisioned — on first
		 * creation and on every later punchout by the same buyer, so site
		 * glue can (re-)apply pricing-group or membership mapping for any
		 * B2B/marketplace plugin. The plugin itself is pricing-agnostic.
		 *
		 * @param int     $user_id Buyer user ID (role punchout_buyer).
		 * @param Partner $partner Trading partner the buyer belongs to.
		 * @param bool    $is_new  True only on first creation.
		 */
		do_action( 'pow_buyer_provisioned', $user_id, $partner, true );

		$this->audit->write(
			'buyer_provisioned',
			[
				'partner_id' => $partner->id,
				'user_id'    => $user_id,
				'result'     => $ephemeral ? 'ephemeral' : 'ok',
			]
		);

		return $user_id;
	}

	/**
	 * Latest punchout wins (scope §5.1): a new create setup expires the
	 * user's open sessions and destroys their recorded logins, so a stale
	 * tab can neither shop nor punch back.
	 */
	public function latest_wins( int $user_id, int $except_session_id = 0 ): void {
		foreach ( $this->sessions->open_for_user( $user_id ) as $open ) {
			if ( $open->id === $except_session_id ) {
				continue;
			}

			$expired = $this->sessions->transition( $open->id, $open->status, Session::EXPIRED );

			if ( $expired && '' !== $open->wp_session_token ) {
				\WP_Session_Tokens::get_instance( $user_id )->destroy( $open->wp_session_token );
			}

			if ( $expired ) {
				$this->audit->write(
					'session_expired',
					[
						'partner_id' => $open->partner_id,
						'session_id' => $open->id,
						'user_id'    => $user_id,
						'result'     => 'superseded',
					]
				);
			}
		}
	}

	/**
	 * Identity extraction chain; filterable so a partner with different
	 * extrinsic names needs configuration, not code.
	 */
	private function resolve_identity( Partner $partner, SetupMessage $message ): ?string {
		$identity = $message->extrinsic( 'UserEmail' )
			?? $message->extrinsic( 'UniqueUsername' )
			?? $message->extrinsic( 'UniqueName' )
			?? $message->contact_email;

		if ( null !== $identity ) {
			$identity = strtolower( trim( $identity ) );

			if ( '' === $identity ) {
				$identity = null;
			}
		}

		/**
		 * Filter the resolved buyer identity for user provisioning.
		 *
		 * @param string|null  $identity   Resolved identity (null = ephemeral).
		 * @param SetupMessage $message    Parsed setup request.
		 * @param Partner      $partner    Trading partner.
		 */
		$identity = apply_filters( 'pow_buyer_identity', $identity, $message, $partner );

		return is_string( $identity ) && '' !== $identity ? $identity : null;
	}

	/**
	 * Deterministic namespaced username: po-{partner}-{hash12}. The hash
	 * covers partner id + identity, so the same buyer maps to the same
	 * user on every punchout and identities never collide across partners.
	 */
	private function username( Partner $partner, string $identity ): string {
		$slug = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '', $partner->name ) );
		$slug = '' !== $slug ? substr( $slug, 0, 12 ) : 'p' . $partner->id;

		return 'po-' . $slug . '-' . substr( hash( 'sha256', $partner->id . '|' . $identity ), 0, 12 );
	}
}
