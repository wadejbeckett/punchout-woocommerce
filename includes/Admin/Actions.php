<?php
/**
 * Admin write handlers (admin-post).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Admin;

use POW\Support\Transport;

use POW\Account\VisitEndpoints;
use POW\Audit\Log;
use POW\Installer;
use POW\Partners\Registry;
use POW\Partners\Registration;
use POW\Partners\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Every write is nonce- and capability-checked, every value sanitised on
 * the way in. Secrets are write-only: a generated or rotated secret is
 * rendered only in the authorized POST response, never a reveal store.
 */
final class Actions {

	public function __construct(
		private Registry $registry,
		private Log $audit,
		private Registration $registration,
	) {}

	public function register(): void {
		add_action( 'admin_post_pow_approve_partner', [ $this, 'approve_partner' ] );
		add_action( 'admin_post_pow_reset_partner', [ $this, 'reset_partner' ] );
		add_action( 'admin_post_pow_associate_partner', [ $this, 'associate_partner' ] );
		add_action( 'admin_post_pow_save_partner', [ $this, 'save_partner' ] );
		add_action( 'admin_post_pow_delete_partner', [ $this, 'delete_partner' ] );
		add_action( 'admin_post_pow_rotate_partner', [ $this, 'rotate_partner' ] );
		add_action( 'admin_post_pow_close_rotation', [ $this, 'close_rotation' ] );
	}

	public function save_partner(): void {
		$this->authorise( 'pow_save_partner' );

		$posted     = wp_unslash( $_POST );
		$partner_id = absint( $posted['partner'] ?? 0 );

		$data = [
			'name'                    => sanitize_text_field( (string) ( $posted['name'] ?? '' ) ),
			'status'                  => sanitize_key( (string) ( $posted['status'] ?? 'active' ) ),
			'sender_domain'           => sanitize_text_field( (string) ( $posted['sender_domain'] ?? '' ) ),
			'sender_identity'         => sanitize_text_field( (string) ( $posted['sender_identity'] ?? '' ) ),
			'from_domain'             => sanitize_text_field( (string) ( $posted['from_domain'] ?? '' ) ),
			'from_identity'           => sanitize_text_field( (string) ( $posted['from_identity'] ?? '' ) ),
			'to_domain'               => sanitize_text_field( (string) ( $posted['to_domain'] ?? '' ) ),
			'to_identity'             => sanitize_text_field( (string) ( $posted['to_identity'] ?? '' ) ),
			'cxml_version'            => sanitize_text_field( (string) ( $posted['cxml_version'] ?? '1.2.008' ) ),
			'deployment_mode'         => sanitize_key( (string) ( $posted['deployment_mode'] ?? 'test' ) ),
			'return_encoding'         => sanitize_key( (string) ( $posted['return_encoding'] ?? 'base64' ) ),
			'allcaps_transform'       => isset( $posted['allcaps_transform'] ) ? 1 : 0,
			'ip_allowlist'            => $this->cidrs_to_json( (string) ( $posted['ip_allowlist'] ?? '' ) ),
			'token_ttl'               => absint( $posted['token_ttl'] ?? 300 ),
			'session_ttl'             => absint( $posted['session_ttl'] ?? 14400 ),
		];

		if ( '' === $data['name'] || '' === $data['sender_domain'] || '' === $data['sender_identity'] ) {
			$this->finish( 'partners', __( 'Name and Sender credential are required.', 'punchout-woocommerce' ), 'error' );
		}

		// Only when the form sent the field, so a form without it never
		// clears a connection's list. A list with any bad entry is refused
		// whole, before anything is written, and the notice names each one
		// and says the rest of the form was not kept either.
		if ( array_key_exists( 'visit_endpoints', $posted ) ) {
			$endpoints = VisitEndpoints::parse( sanitize_text_field( (string) $posted['visit_endpoints'] ) );
			if ( [] !== $endpoints['rejected'] ) {
				$this->finish(
					'partners',
					sprintf(
						/* translators: %s: comma-separated endpoint names that were refused */
						__( 'Not saved, and no other change on the form was kept either. These cannot open in a punchout visit: %s. List lowercase My Account endpoint names only; the dashboard, orders, addresses, downloads, account details, payment methods, password reset, logout, the order-pay and order-received pages and the integration tab always stay closed.', 'punchout-woocommerce' ),
						implode( ', ', $endpoints['rejected'] )
					),
					'error'
				);
			}
			if ( $endpoints['too_long'] ) {
				/* translators: %d: maximum length of the endpoint list */
				$this->finish( 'partners', sprintf( __( 'Not saved, and no other change on the form was kept either. The My Account endpoint list must fit in %d characters.', 'punchout-woocommerce' ), VisitEndpoints::MAX_LENGTH ), 'error' );
			}
			$data['visit_endpoints'] = implode( ',', $endpoints['endpoints'] );
		}

		$issued = '';
		$ok = false;
		try {
			if ( $partner_id > 0 ) {
				$this->registry->with_partner_lock( $partner_id, function () use ( $partner_id, $data, $posted, &$issued, &$ok ) {
					$current = $this->registry->find( $partner_id );
					if ( ! $current ) { return; }
					if ( $current->is_active() && ! in_array( $data['status'], [ 'active', 'disabled' ], true ) ) { return; }
					// Preparation is not approval, even when an old form posts a forged state.
					if ( ! $current->is_active() ) { $data['status'] = $current->status; }
					$secret = $current->is_active() ? $this->posted_secret( $posted ) : '';
					$ok = $this->registry->update( $partner_id, $data, $secret );
					if ( $ok ) { $issued = $secret; }
				} );
			} else {
				$data['status'] = 'disabled' === $data['status'] ? 'disabled' : 'active';
				$secret = $this->posted_secret( $posted );
				$partner_id = $this->registry->insert( $data, $secret );
				$ok = $partner_id > 0;
				if ( $ok ) { $issued = $secret; }
			}
		} catch ( \Throwable $e ) { /* A confirmed write still needs its direct credential handover. */ }

		if ( $ok ) {
			// The endpoint list is what a visit may open, so the trail keeps each saved value.
			$this->record( 'partner_saved', $partner_id, array_key_exists( 'visit_endpoints', $data ) ? [ 'visit_endpoints' => $data['visit_endpoints'] ] : [] );
			if ( '' !== $issued ) { $this->secret_response( __( 'Customer saved.', 'punchout-woocommerce' ), $issued ); }
		}
		$this->finish( 'partners', $ok ? __( 'Customer saved.', 'punchout-woocommerce' ) : $this->save_failed_message(), $ok ? 'success' : 'error' );
	}

	/**
	 * Why a connection save failed, as far as the handler can tell. The form
	 * writes every column, so a schema upgrade that has not landed fails
	 * every save; say that rather than blame the Sender identity.
	 */
	private function save_failed_message(): string {
		try {
			$faults = Installer::partners_schema_faults();
		} catch ( \Throwable $e ) {
			$faults = [];
		}

		if ( [] !== $faults ) {
			return sprintf(
				/* translators: %s: what the connections table is missing, e.g. "column visit_endpoints is missing" */
				__( 'Saving failed: the connections table has not been upgraded yet (%s). The upgrade runs again on every wp-admin page load, and the WooCommerce log (source punchout-woocommerce) says why it has not landed.', 'punchout-woocommerce' ),
				implode( '; ', $faults )
			);
		}

		return __( 'Saving failed — is the Sender identity unique?', 'punchout-woocommerce' );
	}

	public function approve_partner(): void {
		$partner_id = $this->posted_partner();
		$this->authorise( 'pow_approve_' . $partner_id );
		$secret = '';
		try {
			$partner = $this->registry->find( $partner_id );
			// Validate preparation here; the service rechecks under its mutation lock.
			if ( $partner && Registration::valid_approval( $partner ) ) {
				$secret = $this->registration->approve( $partner_id, get_current_user_id() );
			}
		} catch ( \Throwable $e ) { /* No issuance after an unavailable configuration read. */ }
		if ( '' !== $secret ) {
			$this->secret_response( __( 'Company connection approved. Hand the secret to the owner out of band; the notification email contains no secret.', 'punchout-woocommerce' ), $secret );
		}
		$this->finish( 'partners', __( 'Approval failed. Save complete From, Sender and supplier To identities and valid connection entitlements on the pending edit screen, then approve explicitly.', 'punchout-woocommerce' ), 'error' );
	}

	public function reset_partner(): void {
		$partner_id = $this->posted_partner();
		$this->authorise( 'pow_reset_' . $partner_id );
		$secret = '';
		try {
			// Reset the saved configuration, not unreviewed fields posted with the button.
			$this->registry->with_partner_lock( $partner_id, function () use ( $partner_id, &$secret ) {
				$p = $this->registry->find( $partner_id );
				if ( ! $p || $p->is_pending() ) { return; }
				$identity = [];
				foreach ( [ 'from_domain', 'from_identity', 'sender_domain', 'sender_identity', 'to_domain', 'to_identity', 'deployment_mode' ] as $key ) {
					$identity[ $key ] = $p->$key;
				}
				$secret = $this->registration->reset( $partner_id, $identity, get_current_user_id() );
			} );
		} catch ( \Throwable $e ) { /* Retain a confirmed issuance even if lock release failed. */ }
		if ( '' !== $secret ) {
			$this->secret_response( __( 'Connection reset. Previous credentials and recorded sessions have been revoked. Copy the replacement secret now.', 'punchout-woocommerce' ), $secret );
		}
		$this->finish( 'partners', __( 'Reset was not completed. No replacement secret is available. The connection may be disabled with cleanup incomplete; review its state and retry after correcting the failure.', 'punchout-woocommerce' ), 'error' );
	}

	public function associate_partner(): void {
		$partner_id = $this->posted_partner();
		$this->authorise( 'pow_associate_' . $partner_id );
		$owner = absint( $_POST['owner_user_id'] ?? 0 );
		$ok = $this->registry->associate_owner( $partner_id, $owner );
		if ( $ok ) {
			$this->record( 'partner_owner_associated', $partner_id, [ 'old_owner_user_id' => 0, 'new_owner_user_id' => $owner ] );
		}
		$this->finish( 'partners', $ok ? __( 'Company management account associated.', 'punchout-woocommerce' ) : __( 'Association refused. Select an existing ordinary account that owns no connection. An existing nonzero association cannot be transferred or cleared; its company book stays with the current owner.', 'punchout-woocommerce' ), $ok ? 'success' : 'error' );
	}

	public function delete_partner(): void {
		$partner_id = $this->posted_partner();
		$this->authorise( 'pow_delete_' . $partner_id );

		$ok = false;
		try {
			$ok = $this->registry->with_partner_lock( $partner_id, function () use ( $partner_id ) {
				$partner = $this->registry->find( $partner_id );
				$sessions = \POW\Plugin::instance()->sessions();
				if ( ! $partner || ! $sessions ) { return false; }
				if ( \POW\Partners\Partner::STATUS_DISABLED !== $partner->status && ! $this->registry->transition_status( $partner_id, $partner->status, [ 'status' => \POW\Partners\Partner::STATUS_DISABLED ] ) ) { return false; }
				if ( ! $this->registry->revoke_secret( $partner_id ) ) { return false; }
				$after = 0;
				$clean = true;
				while ( $rows = $sessions->revocation_batch( $partner_id, $after ) ) {
					foreach ( $rows as $row ) {
						if ( $row->id <= $after ) { return false; }
						$after = $row->id;
						$clean = $sessions->expire_locked( $row ) && $clean;
					}
				}
				return $clean && [] === $sessions->open_for_partner( $partner_id, 1 ) && $this->registry->delete( $partner_id );
			} );
		} catch ( \Throwable $e ) { $ok = false; }
		if ( $ok ) {
			$this->audit->write(
				'partner_deleted',
				[
					'partner_id' => $partner_id,
					'user_id'    => get_current_user_id(),
					'result'     => 'ok',
				]
			);
		}

		$this->finish( 'partners', $ok ? __( 'Customer deleted.', 'punchout-woocommerce' ) : __( 'Delete failed.', 'punchout-woocommerce' ), $ok ? 'success' : 'error' );
	}

	public function rotate_partner(): void {
		$partner_id = $this->posted_partner();
		$this->authorise( 'pow_rotate_' . $partner_id );

		$new_secret = $partner_id > 0 ? $this->registry->rotate( $partner_id ) : null;

		if ( null !== $new_secret ) {
			$this->record( 'secret_rotated', $partner_id );
			$this->secret_response( __( 'Secret rotated. The previous secret stays valid until you close the rotation window.', 'punchout-woocommerce' ), $new_secret );
		}
		$this->finish( 'partners', __( 'Rotation failed. Only an active connection without an open rotation can rotate.', 'punchout-woocommerce' ), 'error' );
	}

	public function close_rotation(): void {
		$partner_id = $this->posted_partner();
		$this->authorise( 'pow_close_' . $partner_id );

		$ok = $partner_id > 0 && $this->registry->close_rotation( $partner_id );

		if ( $ok ) {
			$this->audit->write(
				'rotation_closed',
				[
					'partner_id' => $partner_id,
					'user_id'    => get_current_user_id(),
					'result'     => 'ok',
				]
			);
		}

		$this->finish( 'partners', $ok ? __( 'Rotation window closed; only the current secret is accepted.', 'punchout-woocommerce' ) : __( 'Close failed.', 'punchout-woocommerce' ), $ok ? 'success' : 'error' );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Capability + nonce gate for every handler.
	 */
	private function authorise( string $nonce_action ): void {
		Transport::require_https();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'This action requires a POST request.', 'punchout-woocommerce' ), '', [ 'response' => 405 ] );
		}
		if ( ! current_user_can( Page::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'punchout-woocommerce' ), '', [ 'response' => 403 ] );
		}
		$nonce = $_POST['_wpnonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed — please go back and try again.', 'punchout-woocommerce' ), '', [ 'response' => 403 ] );
		}
		// Delivery forms post to the partner page and Fields, never to an admin-post credential or entitlement action.
		if ( array_key_exists( 'pow_address_action', $_POST ) ) {
			wp_die( esc_html__( 'Delivery address changes must use the company delivery editor.', 'punchout-woocommerce' ), '', [ 'response' => 400 ] );
		}
		foreach ( $_POST as $value ) {
			if ( ! is_scalar( $value ) ) { wp_die( esc_html__( 'Invalid form value.', 'punchout-woocommerce' ), '', [ 'response' => 400 ] ); }
		}
	}

	private function posted_partner(): int {
		return is_scalar( $_POST['partner'] ?? null ) ? absint( $_POST['partner'] ) : 0;
	}

	private function posted_secret( array $posted ): string {
		if ( isset( $posted['generate_secret'] ) ) { return Secrets::generate_secret(); }
		$secret = (string) ( $posted['secret'] ?? '' );
		return Page::SECRET_MASK === $secret ? '' : $secret;
	}

	private function record( string $event, int $partner_id, array $detail = [] ): void {
		try {
			$this->audit->write( $event, [ 'partner_id' => $partner_id, 'user_id' => get_current_user_id(), 'result' => 'ok', 'detail' => $detail ] );
		} catch ( \Throwable $e ) { /* Diagnostics must not discard a confirmed credential response. */ }
	}

	/** Native WordPress response; plaintext exists only in this authorized POST. */
	private function secret_response( string $text, string $secret ): void {
		// The native wp_die renderer calls nocache_headers again; retain this response's stricter policy.
		$no_store = static function ( array $headers ): array {
			$headers['Cache-Control'] = 'no-store, private, max-age=0';
			return $headers;
		};
		add_filter( 'nocache_headers', $no_store, PHP_INT_MAX );
		nocache_headers();
		header( 'Cache-Control: no-store, private, max-age=0', true );
		header( 'Referrer-Policy: no-referrer', true );
		$html = '<p>' . esc_html( $text ) . '</p><p><strong>' . esc_html__( 'Shared secret (shown once — copy it now):', 'punchout-woocommerce' ) . '</strong></p><p><code>' . esc_html( $secret ) . '</code></p>';
		$html .= '<p><a href="' . esc_url( add_query_arg( [ 'page' => Page::SLUG, 'tab' => 'partners' ], admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Return to customers', 'punchout-woocommerce' ) . '</a></p>';
		try {
			wp_die( $html, esc_html__( 'PunchOut connection credentials', 'punchout-woocommerce' ), [ 'response' => 200 ] );
		} finally {
			remove_filter( 'nocache_headers', $no_store, PHP_INT_MAX );
		}
	}

	/**
	 * Store the one-shot notice and bounce back to the admin tab.
	 *
	 * @param array<string, int|string> $extra Extra query args.
	 * @return never
	 */
	private function finish( string $tab, string $text, string $type = 'success', array $extra = [] ): void {
		set_transient(
			'pow_notice_' . get_current_user_id(),
			[
				'text'   => $text,
				'type'   => $type,
			],
			60
		);

		wp_safe_redirect(
			add_query_arg(
				array_merge(
					[
						'page' => Page::SLUG,
						'tab'  => $tab,
					],
					$extra
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Textarea (one CIDR per line) -> stored JSON, or null when empty.
	 */
	private function cidrs_to_json( string $raw ): ?string {
		$lines = array_values(
			array_filter(
				array_map( 'trim', preg_split( '/[\r\n,]+/', $raw ) ?: [] ),
				static fn( string $line ): bool => '' !== $line && (bool) preg_match( '#^[0-9a-fA-F:./]+$#', $line )
			)
		);

		return [] === $lines ? null : (string) wp_json_encode( $lines );
	}
}
