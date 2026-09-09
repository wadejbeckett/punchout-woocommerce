<?php
/**
 * Ordinary account applications for a company connection.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Partners;

use POW\Audit\Log;
use POW\Installer;
use POW\Sessions\Store;

defined( 'ABSPATH' ) || exit;

final class Registration {

	public function __construct( private Registry $registry, private Store $sessions, private Log $audit ) {}

	/** Approval policy operates on the administrator's saved pending configuration. */
	public static function valid_approval( Partner $p ): bool {
		foreach ( [ $p->name, $p->from_domain, $p->from_identity, $p->sender_domain, $p->sender_identity, $p->to_domain, $p->to_identity ] as $value ) {
			if ( ! self::identity_value( $value ) ) { return false; }
		}
		return $p->is_pending() && '' === $p->secret_current && '' === $p->secret_previous
			&& in_array( $p->mode, [ Partner::MODE_REQUISITION_ONLY, Partner::MODE_DUAL_EXIT ], true )
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
				if ( $this->registry->transition_status( $partner_id, Partner::STATUS_PENDING, [ 'status' => Partner::STATUS_ACTIVE, 'secret_previous' => '' ], $secret ) ) { $issued = $secret; }
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
			$data = [];
			$reason = 'identity';
			foreach ( [ 'from_domain', 'from_identity', 'sender_domain', 'sender_identity', 'to_domain', 'to_identity' ] as $key ) {
				$value = $new_identity[ $key ] ?? null;
				if ( ! is_scalar( $value ) ) { return ''; }
				$value = trim( (string) $value, " \t\r\n\v" );
				if ( ! self::identity_value( $value ) ) { return ''; }
				$data[ $key ] = $value;
			}
			if ( array_key_exists( 'deployment_mode', $new_identity ) ) {
				if ( ! in_array( $new_identity['deployment_mode'], [ 'test', 'production' ], true ) ) { return ''; }
				$data['deployment_mode'] = $new_identity['deployment_mode'];
			}
			$reason = 'lock';
			$this->registry->with_partner_lock( $partner_id, function () use ( $partner_id, $data, &$issued, &$fenced, &$partner, &$reason ) {
				$reason = 'identity_or_state';
				$partner = $this->registry->find( $partner_id );
				if ( ! $partner || ! in_array( $partner->status, [ Partner::STATUS_ACTIVE, Partner::STATUS_DISABLED ], true ) ) { return; }
				$other = $this->registry->find_by_sender( $data['sender_domain'], $data['sender_identity'] );
				if ( $other && $other->id !== $partner_id ) { return; }
				$reason = 'fence_unconfirmed';
				$fenced = Partner::STATUS_DISABLED === $partner->status || $this->registry->transition_status( $partner_id, Partner::STATUS_ACTIVE, [ 'status' => Partner::STATUS_DISABLED ] );
				if ( ! $fenced ) { return; }
				$this->record( 'registration_disabled', get_current_user_id(), $partner_id );
				$reason = 'revocation';
				if ( ! $this->registry->revoke_secret( $partner_id ) ) { return; }
				$reason = 'session_cleanup';
				if ( ! $this->drain_locked( $partner_id ) ) { return; }
				$reason = 'replacement_write';
				$secret = Secrets::generate_secret();
				if ( $this->registry->transition_status( $partner_id, Partner::STATUS_DISABLED, $data + [ 'status' => Partner::STATUS_ACTIVE, 'secret_previous' => '' ], $secret ) ) { $issued = $secret; }
			} );
		} catch ( \Throwable $e ) { /* A failed fenced operation stays recoverable and never issues. */ }
		finally { $this->record( '' !== $issued ? 'registration_reset' : 'registration_reset_failed', get_current_user_id(), $partner_id, '' !== $issued ? '' : $reason ); }
		if ( '' !== $issued && $partner ) { $this->notify_owner( $partner, __( 'Your punchout connection has been reset. Previous sessions and credentials have been revoked. Contact the store to arrange credential handover.', 'punchout-woocommerce' ) ); }
		return $issued;
	}

	public function request_deactivation( int $partner_id, int $user_id ): bool {
		$done = false;
		$fenced = false;
		$partner = null;
		$reason = 'authorization';
		try {
			$user = $user_id > 0 && get_current_user_id() === $user_id ? get_userdata( $user_id ) : false;
			if ( ! $user || ! user_can( $user, 'read' ) || in_array( Installer::ROLE, (array) $user->roles, true ) || get_user_meta( $user_id, '_pow_partner_id', true ) ) { return false; }
			$reason = 'lock';
			$this->registry->with_partner_lock( $partner_id, function () use ( $partner_id, $user_id, &$partner, &$fenced, &$done, &$reason ) {
				$partner = $this->registry->find( $partner_id );
				$reason = 'ownership';
				if ( ! $partner || ! $partner->is_owned_by( $user_id ) ) { return; }
				$reason = 'fence_unconfirmed';
				$fenced = Partner::STATUS_DISABLED === $partner->status || $this->registry->transition_status( $partner_id, $partner->status, [ 'status' => Partner::STATUS_DISABLED ] );
				if ( ! $fenced ) { return; }
				$this->record( 'registration_disabled', $user_id, $partner_id );
				$reason = 'session_cleanup';
				$done = $this->registry->revoke_secret( $partner_id ) && $this->drain_locked( $partner_id );
			} );
		} catch ( \Throwable $e ) { /* Preserve the disabled state on partial cleanup. */ }
		finally { $this->record( $done ? 'registration_deactivated' : 'registration_deactivation_failed', get_current_user_id(), $partner_id, $done ? '' : $reason ); }
		if ( $fenced && $partner ) {
			$message = $done ? __( 'The connection is disabled and its recorded sessions have been revoked.', 'punchout-woocommerce' ) : __( 'The connection is disabled, but session cleanup is incomplete. Administrator recovery is required.', 'punchout-woocommerce' );
			if ( ! $this->send_notice( (string) get_option( 'admin_email', '' ), __( 'Punchout connection disabled', 'punchout-woocommerce' ), $partner->name . "\n" . $message ) ) { $this->record( 'registration_notification_failed', $user_id, $partner_id, 'mail' ); }
		}
		return $done;
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

	/**
	 * Caller additionally enforces POST, feature state and nonce (account handler).
	 * This service always authenticates the actual ordinary management account.
	 */
	public function submit( int $user_id, array $fields ): int|\WP_Error {
		global $wpdb;

		$actor = get_current_user_id();
		try {
			$user = $actor > 0 && $actor === $user_id ? get_userdata( $actor ) : false;
			if ( ! $user || ! user_can( $user, 'read' ) || in_array( Installer::ROLE, (array) $user->roles, true ) || ! empty( get_user_meta( $actor, '_pow_partner_id', true ) ) ) {
				$this->record( 'registration_rejected', $actor, 0, 'actor' );
				return self::refusal();
			}
		} catch ( \Throwable $e ) {
			$this->record( 'registration_failed', $actor, 0, 'authorization' );
			return self::refusal();
		}

		$data = self::normalise( $fields );
		$error = self::validate( $data );
		if ( null !== $error ) {
			$this->record( 'registration_rejected', $actor, 0, 'fields' );
			return $error;
		}

		$id = 0;
		$reason = 'lock';
		try {
			$result = $this->registry->with_owner_lock( $actor, function () use ( $actor, $data, &$id, &$reason, $wpdb ) {
				$reason = 'lookup';
				$owner = $this->registry->find_by_owner( $actor );
				if ( '' !== $wpdb->last_error ) {
					throw new \RuntimeException( 'Registration lookup failed.' );
				}
				$sender = $this->registry->find_by_sender( $data['sender_domain'], $data['sender_identity'] );
				if ( '' !== $wpdb->last_error ) {
					throw new \RuntimeException( 'Registration lookup failed.' );
				}
				if ( null !== $owner || null !== $sender ) {
					$reason = 'conflict';
					return self::refusal();
				}
				$reason = 'insert';
				$row = $data;
				unset( $row['notes'] );
				$row['status'] = Partner::STATUS_PENDING;
				$row['owner_user_id'] = $actor;
				// Already validated as UTF-8 and within the TEXT byte limit.
				$row['company_profile'] = '' !== $data['notes'] ? json_encode( [ 'notes' => $data['notes'] ] ) : null;
				// Registry seals only a separately supplied secret; no secret is supplied.
				$id = $this->registry->insert( $row );
				if ( $id <= 0 ) {
					throw new \RuntimeException( 'Registration insert failed.' );
				}
				$reason = 'release';
				return $id;
			} );
			if ( $result instanceof \WP_Error ) {
				$this->record( 'registration_rejected', $actor, 0, $reason );
				return $result;
			}
		} catch ( \Throwable $e ) {
			if ( $id <= 0 ) {
				$this->record( 'registration_failed', $actor, 0, $reason );
				return self::refusal();
			}
			// A confirmed insert survives a later lock cleanup error. Never invite a duplicate.
			$this->record( 'registration_lock_release_failed', $actor, $id, 'release' );
		}

		$this->record( 'registration_submitted', $actor, $id );
		if ( ! $this->notify_admin( $data ) ) {
			$this->record( 'registration_notification_failed', $actor, $id, 'mail' );
		}
		return $id;
	}

	public static function neutral_message(): string {
		return __( 'Unable to submit this connection. Please contact the store for assistance.', 'punchout-woocommerce' );
	}

	private static function refusal(): \WP_Error {
		return new \WP_Error( 'registration_refused', self::neutral_message() );
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
					'result' => in_array( $event, [ 'registration_submitted', 'registration_approved', 'registration_reset', 'registration_disabled', 'registration_deactivated', 'session_revoked' ], true ) ? 'ok' : 'error',
					'detail' => '' !== $reason ? [ 'reason' => $reason ] : [],
				] );
			} finally {
				$wpdb->suppress_errors( $previous );
			}
		} catch ( \Throwable $e ) {
			// Diagnostics must not change the result of an already confirmed insert.
		}
	}

	private function notify_admin( array $data ): bool {
		try {
			$to = (string) get_option( 'admin_email', '' );
			$subject = __( 'Punchout connection awaiting review', 'punchout-woocommerce' );
			$link = admin_url( 'admin.php?page=punchout-woocommerce&tab=partners' );
			/* translators: 1: connection name, 2: Sender domain, 3: Sender identity, 4: review URL. */
			$plain = sprintf( __( "Connection: %1\$s\nSender domain: %2\$s\nSender identity: %3\$s\nReview: %4\$s", 'punchout-woocommerce' ), $data['name'], $data['sender_domain'], $data['sender_identity'], $link );
			$mailer = null;
			try {
				$woo = function_exists( 'WC' ) ? WC() : null;
				if ( is_object( $woo ) && is_callable( [ $woo, 'mailer' ] ) ) {
					$candidate = $woo->mailer();
					if ( is_object( $candidate ) && is_callable( [ $candidate, 'wrap_message' ] ) && is_callable( [ $candidate, 'send' ] ) ) {
						$html = $candidate->wrap_message( esc_html( $subject ), nl2br( esc_html( $plain ) ) );
						$mailer = $candidate;
					}
				}
			} catch ( \Throwable $e ) {
				// Template initialization failed before transport: use native plain mail.
			}
			return null !== $mailer ? (bool) $mailer->send( $to, $subject, $html ) : wp_mail( $to, $subject, $plain );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** Only applicant-editable fields survive; null marks invalid non-scalar input. */
	public static function normalise( array $fields ): array {
		$out = [];
		foreach ( [ 'name', 'from_domain', 'from_identity', 'sender_domain', 'sender_identity', 'deployment_mode', 'notes' ] as $key ) {
			$default = match ( $key ) {
				'sender_domain' => $out['from_domain'],
				'sender_identity' => $out['from_identity'],
				'deployment_mode' => 'test',
				default => '',
			};
			$value = array_key_exists( $key, $fields ) ? $fields[ $key ] : $default;
			$out[ $key ] = is_scalar( $value ) ? trim( (string) $value, " \t\n\r\v" ) : null;
		}
		if ( null !== $out['deployment_mode'] && ! in_array( $out['deployment_mode'], [ 'test', 'production' ], true ) ) {
			$out['deployment_mode'] = 'test';
		}
		return $out;
	}

	/** Check characters for VARCHAR(190), and encoded bytes for company_profile TEXT. */
	public static function validate( array $fields ): ?\WP_Error {
		$labels = [
			'name' => __( 'Connection name', 'punchout-woocommerce' ),
			'from_domain' => __( 'From domain', 'punchout-woocommerce' ),
			'from_identity' => __( 'From identity', 'punchout-woocommerce' ),
			'sender_domain' => __( 'Sender domain', 'punchout-woocommerce' ),
			'sender_identity' => __( 'Sender identity', 'punchout-woocommerce' ),
			'deployment_mode' => __( 'Deployment mode', 'punchout-woocommerce' ),
			'notes' => __( 'Notes', 'punchout-woocommerce' ),
		];
		foreach ( $labels as $key => $label ) {
			$value = $fields[ $key ] ?? null;
			$valid = is_string( $value ) && 1 === preg_match( '//u', $value ) && ! str_contains( $value, "\0" );
			if ( $valid ) {
				$valid = match ( $key ) {
					'notes' => false !== json_encode( [ 'notes' => $value ] ) && strlen( json_encode( [ 'notes' => $value ] ) ) <= 65535,
					'deployment_mode' => in_array( $value, [ 'test', 'production' ], true ),
					default => '' !== trim( $value ) && 1 === preg_match( '/^.{1,190}$/usD', $value ),
				};
			}
			if ( ! $valid ) {
				/* translators: %s: the submitted field label. */
				return new \WP_Error( 'registration_fields', sprintf( __( 'Please supply a valid %s within the allowed length.', 'punchout-woocommerce' ), $label ) );
			}
		}
		return null;
	}
}
