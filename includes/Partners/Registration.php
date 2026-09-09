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
	private function record( string $event, int $actor, int $partner_id, string $reason = '' ): void {
		global $wpdb;

		try {
			$previous = $wpdb->suppress_errors( true );
			try {
				$this->audit->write_checked( $event, [
					'user_id' => $actor,
					'partner_id' => $partner_id,
					'result' => 'registration_submitted' === $event ? 'ok' : 'error',
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
