<?php
/**
 * Administrator lifecycle for a company connection.
 *
 * Approval and identity reset: the self-service application, the owner's
 * own deactivation and the applicant field validator went with the
 * front-end surface, so approve() and reset() are reached from the admin
 * screens and authenticate a real shop administrator.
 *
 * One account-holder entry point exists, and only by opt-in:
 * reset_by_owner(), which an administrator switches on per connection
 * (owner_settings `reset_connection`, off by default). It is a full reset
 * with the saved identity, never a rotation, and it re-checks under the
 * partner lock that the caller is the bound account, the connection is
 * active and still grants the action, and the request is not inside a
 * punchout visit.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Partners;

use POW\Audit\Log;
use POW\Sessions\Store;

defined( 'ABSPATH' ) || exit;

final class Registration {

	public function __construct( private Registry $registry, private Store $sessions, private Log $audit ) {}

	/** Approval policy operates on the administrator's saved pending configuration. */
	public static function valid_approval( Partner $p ): bool {
		foreach ( [ $p->name, $p->from_domain, $p->from_identity, $p->sender_domain, $p->sender_identity, $p->to_domain, $p->to_identity ] as $value ) {
			if ( ! self::identity_value( $value ) ) { return false; }
		}
		// The legacy mode and the exit entitlement are retired columns with a
		// single value each, so neither is an approval condition any more.
		return $p->is_pending() && '' === $p->secret_current && '' === $p->secret_previous
			&& in_array( $p->deployment_mode, [ 'test', 'production' ], true )
			&& in_array( $p->return_encoding, [ 'base64', 'urlencoded' ], true )
			&& 1 === preg_match( '/^\d+\.\d+\.\d+$/D', $p->cxml_version );
	}

	private static function identity_value( mixed $value ): bool {
		return is_string( $value ) && '' !== trim( $value ) && ! str_contains( $value, "\0" ) && 1 === preg_match( '/^.{1,190}$/usD', $value );
	}

	private function admin_actor( int $actor ): bool {
		$user = $actor > 0 && get_current_user_id() === $actor ? get_userdata( $actor ) : false;
		return $user && user_can( $user, 'manage_woocommerce' );
	}

	public function approve( int $partner_id, int $admin_user_id ): string {
		$issued = '';
		$partner = null;
		$reason = 'authorization';
		try {
			if ( ! $this->admin_actor( $admin_user_id ) ) { return ''; }
			$reason = 'lock';
			$this->registry->with_partner_lock( $partner_id, function () use ( $partner_id, &$issued, &$partner, &$reason ) {
				$reason = 'pending_configuration';
				$partner = $this->registry->find( $partner_id );
				if ( ! $partner || ! self::valid_approval( $partner ) ) { return; }
				$reason = 'approval_write';
				$secret = Secrets::generate_secret();
				try {
					if ( $this->registry->transition_status( $partner_id, Partner::STATUS_PENDING, [ 'status' => Partner::STATUS_ACTIVE, 'secret_previous' => '' ], $secret ) ) { $issued = $secret; }
				} catch ( CredentialRecoveryException $e ) {
					// Capture inside the callback so a later release exception cannot mask recovery state.
					$reason = $e->getMessage();
				}
			} );
		} catch ( \Throwable $e ) { /* Only a confirmed transition may issue, including after release failure. */ }
		finally { $this->record( '' !== $issued ? 'registration_approved' : 'registration_approval_failed', get_current_user_id(), $partner_id, '' !== $issued ? '' : $reason ); }
		if ( '' !== $issued && $partner ) { $this->notify_owner( $partner, __( 'Your punchout connection has been approved. Contact the store to arrange credential handover.', 'punchout-woocommerce' ) ); }
		return $issued;
	}

	/** Reset replaces identity only; ownership, entitlements and company information survive. */
	public function reset( int $partner_id, array $new_identity, int $admin_user_id ): string {
		$issued = '';
		$fenced = false;
		$partner = null;
		$reason = 'authorization';
		try {
			if ( ! $this->admin_actor( $admin_user_id ) ) { return ''; }
			$reason = 'identity';
			$data = self::identity_data( $new_identity );
			if ( null === $data ) { return ''; }
			$reason = 'lock';
			$this->registry->with_partner_lock( $partner_id, function () use ( $partner_id, $data, &$issued, &$fenced, &$partner, &$reason ) {
				$issued = $this->reset_locked( $partner_id, $data, null, $partner, $reason, $fenced );
			} );
		} catch ( \Throwable $e ) { /* Only confirmed issuance survives release failure; recovery may be unconfirmed. */ }
		finally { $this->record( '' !== $issued ? 'registration_reset' : 'registration_reset_failed', get_current_user_id(), $partner_id, '' !== $issued ? '' : $reason ); }
		if ( '' !== $issued && $partner ) { $this->notify_owner( $partner, __( 'Your punchout connection has been reset. Previous sessions and credentials have been revoked. Contact the store to arrange credential handover.', 'punchout-woocommerce' ) ); }
		return $issued;
	}

	/**
	 * The account holder's own reset, offered only when an administrator
	 * ticked it for this connection.
	 *
	 * A full reset with the saved identity, never a rotation: the same
	 * fence, revoke, drain and issue sequence as reset(), so every open
	 * visit of the connection ends and the old secret stops working. The
	 * gate runs again under the partner lock: the bound account, the
	 * granted action, an active connection and no live visit for this
	 * request. An unanswerable visit lookup refuses.
	 */
	public function reset_by_owner( int $partner_id, int $owner_user_id ): string {
		$issued = '';
		$fenced = false;
		$partner = null;
		$reason = 'authorization';
		try {
			if ( $owner_user_id <= 0 || get_current_user_id() !== $owner_user_id ) { return ''; }
			$user = get_userdata( $owner_user_id );
			if ( ! $user || ! user_can( $user, 'read' ) ) { return ''; }
			$gate = function ( Partner $p ) use ( $owner_user_id ): bool {
				return Partner::STATUS_ACTIVE === $p->status && $p->is_owned_by( $owner_user_id ) && $p->owner_may( 'reset_connection' )
					&& null === ( new \POW\Sessions\Current( $this->sessions ) )->visit();
			};
			$reason = 'lock';
			$this->registry->with_partner_lock( $partner_id, function () use ( $partner_id, $gate, &$issued, &$fenced, &$partner, &$reason ) {
				$issued = $this->reset_locked( $partner_id, null, $gate, $partner, $reason, $fenced );
			} );
		} catch ( \Throwable $e ) { /* A throwing gate or lookup refuses; only confirmed issuance survives. */ }
		finally { $this->record( '' !== $issued ? 'registration_owner_reset' : 'registration_owner_reset_failed', get_current_user_id(), $partner_id, '' !== $issued ? '' : $reason ); }
		if ( '' !== $issued && $partner ) { $this->notify_owner( $partner, __( 'Your punchout connection was reset from your store account. The previous shared secret and every open punchout visit have been revoked. The new secret was shown once on the Punchout integration page; it is not in this email.', 'punchout-woocommerce' ) ); }
		return $issued;
	}

	/**
	 * Validated identity columns, or null when any is unusable.
	 *
	 * @param array<string,mixed> $source
	 * @return array<string,string>|null
	 */
	private static function identity_data( array $source ): ?array {
		$data = [];
		foreach ( [ 'from_domain', 'from_identity', 'sender_domain', 'sender_identity', 'to_domain', 'to_identity' ] as $key ) {
			$value = $source[ $key ] ?? null;
			if ( ! is_scalar( $value ) ) { return null; }
			$value = trim( (string) $value, " \t\r\n\v" );
			if ( ! self::identity_value( $value ) ) { return null; }
			$data[ $key ] = $value;
		}
		if ( array_key_exists( 'deployment_mode', $source ) ) {
			if ( ! in_array( $source['deployment_mode'], [ 'test', 'production' ], true ) ) { return null; }
			$data['deployment_mode'] = $source['deployment_mode'];
		}
		return $data;
	}

	/**
	 * The reset sequence, run by the caller under the partner lock.
	 *
	 * $data null takes the identity from the saved connection. $gate, when
	 * given, is asked about the freshly read connection and refuses the
	 * whole reset by answering false.
	 *
	 * @return string The issued secret, or '' when nothing was issued.
	 */
	private function reset_locked( int $partner_id, ?array $data, ?\Closure $gate, ?Partner &$partner, string &$reason, bool &$fenced ): string {
		$reason = 'identity_or_state';
		$partner = $this->registry->find( $partner_id );
		if ( ! $partner || ! in_array( $partner->status, [ Partner::STATUS_ACTIVE, Partner::STATUS_DISABLED ], true ) ) { return ''; }
		if ( null !== $gate ) {
			$reason = 'owner_gate';
			if ( ! $gate( $partner ) ) { return ''; }
			$reason = 'identity_or_state';
		}
		if ( null === $data ) {
			$data = self::identity_data( [ 'from_domain' => $partner->from_domain, 'from_identity' => $partner->from_identity, 'sender_domain' => $partner->sender_domain, 'sender_identity' => $partner->sender_identity, 'to_domain' => $partner->to_domain, 'to_identity' => $partner->to_identity, 'deployment_mode' => $partner->deployment_mode ] );
			if ( null === $data ) { return ''; }
		}
		$other = $this->registry->find_by_sender( $data['sender_domain'], $data['sender_identity'] );
		if ( $other && $other->id !== $partner_id ) { return ''; }
		$reason = 'fence_unconfirmed';
		$fenced = Partner::STATUS_DISABLED === $partner->status || $this->registry->transition_status( $partner_id, Partner::STATUS_ACTIVE, [ 'status' => Partner::STATUS_DISABLED ] );
		if ( ! $fenced ) { return ''; }
		$this->record( 'registration_disabled', get_current_user_id(), $partner_id );
		$reason = 'revocation';
		if ( ! $this->registry->revoke_secret( $partner_id ) ) { return ''; }
		$reason = 'session_cleanup';
		if ( ! $this->drain_locked( $partner_id ) ) { return ''; }
		$reason = 'replacement_write';
		$secret = Secrets::generate_secret();
		try {
			if ( $this->registry->transition_status( $partner_id, Partner::STATUS_DISABLED, $data + [ 'status' => Partner::STATUS_ACTIVE, 'secret_previous' => '' ], $secret ) ) { return $secret; }
		} catch ( CredentialRecoveryException $e ) {
			$reason = $e->getMessage();
		}
		return '';
	}

	private function drain_locked( int $partner_id ): bool {
		$after = 0;
		$ok = true;
		while ( $rows = $this->sessions->revocation_batch( $partner_id, $after ) ) {
			foreach ( $rows as $row ) {
				if ( $row->id <= $after ) { return false; }
				$after = $row->id;
				$clean = $this->sessions->expire_locked( $row );
				$ok = $clean && $ok;
				$this->record( $clean ? 'session_revoked' : 'session_revocation_failed', get_current_user_id(), $partner_id, $clean ? '' : 'cleanup', $row->id );
			}
		}
		return [] === $this->sessions->open_for_partner( $partner_id, 1 ) && $ok;
	}

	private function notify_owner( Partner $partner, string $message ): void {
		try {
			$owner = get_userdata( $partner->owner_user_id );
			$ok = $owner && $this->send_notice( (string) $owner->user_email, __( 'Punchout connection updated', 'punchout-woocommerce' ), $partner->name . "\n" . $message );
		} catch ( \Throwable $e ) { $ok = false; }
		if ( ! $ok ) { $this->record( 'registration_notification_failed', get_current_user_id(), $partner->id, 'mail' ); }
	}

	private function send_notice( string $to, string $subject, string $body ): bool {
		try {
			$woo = function_exists( 'WC' ) ? WC() : null;
			if ( $woo && is_callable( [ $woo, 'mailer' ] ) ) {
				$mailer = $woo->mailer();
				return (bool) $mailer->send( $to, $subject, $mailer->wrap_message( esc_html( $subject ), nl2br( esc_html( $body ) ) ) );
			}
			return wp_mail( $to, $subject, $body );
		} catch ( \Throwable $e ) { return false; }
	}

	/** Only bounded reasons and actual IDs; no submitted credentials or exception text. */
	private function record( string $event, int $actor, int $partner_id, string $reason = '', int $session_id = 0 ): void {
		global $wpdb;

		try {
			$previous = $wpdb->suppress_errors( true );
			try {
				$this->audit->write_checked( $event, [
					'user_id' => $actor,
					'partner_id' => $partner_id,
					'session_id' => $session_id,
					'result' => in_array( $event, [ 'registration_approved', 'registration_reset', 'registration_owner_reset', 'registration_disabled', 'session_revoked' ], true ) ? 'ok' : 'error',
					'detail' => '' !== $reason ? [ 'reason' => $reason ] : [],
				] );
			} finally {
				$wpdb->suppress_errors( $previous );
			}
		} catch ( \Throwable $e ) {
			// Diagnostics must not change the result of an already confirmed write.
		}
	}
}
