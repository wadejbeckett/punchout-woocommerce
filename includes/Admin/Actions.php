<?php
/**
 * Admin write handlers (admin-post).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Admin;

use POW\Audit\Log;
use POW\Partners\Registry;
use POW\Partners\Secrets;
use POW\SkuMap\SkuMap;

defined( 'ABSPATH' ) || exit;

/**
 * Every write is nonce- and capability-checked, every value sanitised on
 * the way in. Secrets are write-only: a generated or rotated secret is
 * placed in a 60-second, per-user transient and rendered exactly once by
 * Admin\Page.
 */
final class Actions {

	public function __construct(
		private Registry $registry,
		private SkuMap $sku_map,
		private Log $audit,
	) {}

	public function register(): void {
		add_action( 'admin_post_pow_save_partner', [ $this, 'save_partner' ] );
		add_action( 'admin_post_pow_delete_partner', [ $this, 'delete_partner' ] );
		add_action( 'admin_post_pow_rotate_partner', [ $this, 'rotate_partner' ] );
		add_action( 'admin_post_pow_close_rotation', [ $this, 'close_rotation' ] );
		add_action( 'admin_post_pow_import_skumap', [ $this, 'import_skumap' ] );
	}

	public function save_partner(): void {
		$this->authorise( 'pow_save_partner' );

		$posted     = wp_unslash( $_POST );
		$partner_id = absint( $posted['partner'] ?? 0 );

		$data = [
			'name'                    => sanitize_text_field( (string) ( $posted['name'] ?? '' ) ),
			'status'                  => sanitize_key( (string) ( $posted['status'] ?? 'active' ) ),
			'mode'                    => sanitize_key( (string) ( $posted['mode'] ?? '' ) ),
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
			'b2bking_company_user_id' => absint( $posted['b2bking_company_user_id'] ?? 0 ),
			'b2bking_group_id'        => absint( $posted['b2bking_group_id'] ?? 0 ),
			'token_ttl'               => absint( $posted['token_ttl'] ?? 300 ),
			'session_ttl'             => absint( $posted['session_ttl'] ?? 14400 ),
		];

		if ( '' === $data['name'] || '' === $data['sender_domain'] || '' === $data['sender_identity'] ) {
			$this->finish( 'partners', __( 'Name and Sender credential are required.', 'punchout-woocommerce' ), 'error' );
		}

		$generated = '';
		$secret    = (string) ( $posted['secret'] ?? '' );

		if ( isset( $posted['generate_secret'] ) ) {
			$generated = Secrets::generate_secret();
			$secret    = $generated;
		} elseif ( Page::SECRET_MASK === $secret ) {
			$secret = ''; // Unchanged sentinel.
		}

		if ( $partner_id > 0 ) {
			$ok = $this->registry->update( $partner_id, $data, $secret );
		} else {
			$partner_id = $this->registry->insert( $data, $secret );
			$ok         = $partner_id > 0;
		}

		if ( $ok ) {
			$this->audit->write(
				'partner_saved',
				[
					'partner_id' => $partner_id,
					'user_id'    => get_current_user_id(),
					'result'     => 'ok',
				]
			);
		}

		$this->finish(
			'partners',
			$ok ? __( 'Trading partner saved.', 'punchout-woocommerce' ) : __( 'Saving failed — is the Sender identity unique?', 'punchout-woocommerce' ),
			$ok ? 'success' : 'error',
			$generated
		);
	}

	public function delete_partner(): void {
		$partner_id = absint( $_GET['partner'] ?? 0 );
		$this->authorise( 'pow_delete_' . $partner_id, 'get' );

		$ok = $partner_id > 0 && $this->registry->delete( $partner_id );

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

		$this->finish( 'partners', $ok ? __( 'Trading partner deleted.', 'punchout-woocommerce' ) : __( 'Delete failed.', 'punchout-woocommerce' ), $ok ? 'success' : 'error' );
	}

	public function rotate_partner(): void {
		$partner_id = absint( $_GET['partner'] ?? 0 );
		$this->authorise( 'pow_rotate_' . $partner_id, 'get' );

		$new_secret = $partner_id > 0 ? $this->registry->rotate( $partner_id ) : null;

		if ( null !== $new_secret ) {
			$this->audit->write(
				'secret_rotated',
				[
					'partner_id' => $partner_id,
					'user_id'    => get_current_user_id(),
					'result'     => 'ok',
				]
			);
		}

		$this->finish(
			'partners',
			null !== $new_secret
				? __( 'Secret rotated. The previous secret stays valid until you close the rotation window.', 'punchout-woocommerce' )
				: __( 'Rotation failed.', 'punchout-woocommerce' ),
			null !== $new_secret ? 'success' : 'error',
			$new_secret ?? ''
		);
	}

	public function close_rotation(): void {
		$partner_id = absint( $_GET['partner'] ?? 0 );
		$this->authorise( 'pow_close_' . $partner_id, 'get' );

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

	public function import_skumap(): void {
		$this->authorise( 'pow_import_skumap' );

		$partner_id = absint( $_POST['partner'] ?? 0 );

		if ( 0 === $partner_id || null === $this->registry->find( $partner_id ) ) {
			$this->finish( 'skumap', __( 'Unknown trading partner.', 'punchout-woocommerce' ), 'error' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- tmp_name is a server-generated path, validated below.
		$tmp = isset( $_FILES['pow_csv']['tmp_name'] ) ? (string) $_FILES['pow_csv']['tmp_name'] : '';

		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			$this->finish( 'skumap', __( 'No CSV file received.', 'punchout-woocommerce' ), 'error' );
		}

		$handle = fopen( $tmp, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			$this->finish( 'skumap', __( 'Could not read the uploaded file.', 'punchout-woocommerce' ), 'error' );
		}

		$rows  = [];
		$limit = 20000;

		while ( count( $rows ) < $limit && false !== ( $row = fgetcsv( $handle, 4096 ) ) ) {
			$rows[] = array_map( static fn( $v ): string => (string) $v, $row );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$result = $this->sku_map->import_rows( $partner_id, $rows );

		$this->audit->write(
			'skumap_import',
			[
				'partner_id' => $partner_id,
				'user_id'    => get_current_user_id(),
				'result'     => 'ok',
				'detail'     => $result,
			]
		);

		$this->finish(
			'skumap',
			sprintf(
				/* translators: 1: imported row count, 2: skipped row count */
				__( 'SKU map import: %1$d rows imported, %2$d skipped.', 'punchout-woocommerce' ),
				$result['imported'],
				$result['skipped']
			),
			'success',
			'',
			[ 'partner' => $partner_id ]
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Capability + nonce gate for every handler.
	 */
	private function authorise( string $nonce_action, string $source = 'post' ): void {
		if ( ! current_user_can( Page::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'punchout-woocommerce' ) );
		}

		$nonce = 'get' === $source
			? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) )
			: sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) );

		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed — please go back and try again.', 'punchout-woocommerce' ) );
		}
	}

	/**
	 * Store the one-shot notice and bounce back to the admin tab.
	 *
	 * @param array<string, int|string> $extra Extra query args.
	 * @return never
	 */
	private function finish( string $tab, string $text, string $type = 'success', string $secret = '', array $extra = [] ): void {
		set_transient(
			'pow_notice_' . get_current_user_id(),
			[
				'text'   => $text,
				'type'   => $type,
				'secret' => $secret,
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
