<?php
/** Private company address editor producer; the admin connection screen embeds it. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );

namespace POW\Addresses;

use POW\Support\Transport;

use POW\Admin\Page;
use POW\Partners\Registry;
use POW\Support\Templates;

defined( 'ABSPATH' ) || exit;

final class Fields {
	private const ADDRESS_KEYS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ];
	private const ACTIONS = [ 'save', 'edit', 'new', 'remove', 'enable', 'disable', 'preview', 'copy' ];
	/** Request-local results only: no session, transient, option, customer or entitlement writes. */
	private array $responses = [];

	public function __construct( private Registry $registry, private CompanyBook $book, private NativeImport $import ) {}

	/** One registration: the editor lives on the admin connection screen, so nothing hooks the front end. */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'handle' ] );
	}

	/** Handle only editor POSTs on the admin connection screen. The same instance must later render markup in that request; no redirect loses failed input. */
	public function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['pow_address_action'] ) || ! $this->route() ) { return; }
		Transport::require_https();
		$this->responses = [];
		$post = wp_unslash( $_POST );
		$partner = self::integer( $post['pow_address_partner'] ?? null );
		if ( null === $partner || $partner < 1 ) { return; }
		$actor = get_current_user_id();
		nocache_headers();
		try {
			$book = $this->book->read( $partner, $actor );
			if ( $book instanceof \WP_Error ) { return; }
			$action = $post['pow_address_action'];
			$key = $post['pow_address_key'] ?? '';
			$type = $post['pow_address_type'] ?? '';
			$revision = self::integer( $post['pow_address_revision'] ?? null );
			$draft = self::draft( $post );
			$this->responses[$partner] = [ 'actor' => $actor, 'notice' => null, 'draft' => 'save' === $action ? $draft : null, 'key' => is_string( $key ) ? $key : '', 'revision' => $revision ?? 0, 'copy' => null, 'preview' => null ];
			if ( ! is_string( $action ) || ! in_array( $action, self::ACTIONS, true ) || ! is_string( $key ) || ! is_string( $type ) || null === $revision || ! self::allowed_post( $post, $action ) ) { $this->fail( $partner, self::invalid() ); return; }
			$nonce = $post['_pow_address_nonce'] ?? null;
			if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::nonce_action( $action, $partner, $key, $type ) ) ) {
				$this->fail( $partner, new \WP_Error( 'address_editor_forbidden', __( 'This request could not be authorised. Reload the delivery editor and try again.', 'punchout-woocommerce' ) ) ); return;
			}
			$is_import = in_array( $action, [ 'preview', 'copy' ], true );
			if ( ( $is_import && ( '' !== $key || ! in_array( $type, [ 'shipping', 'billing' ], true ) ) ) || ( ! $is_import && '' !== $type ) || ( 'new' === $action && '' !== $key ) || ( in_array( $action, [ 'edit', 'remove', 'enable', 'disable' ], true ) && '' === $key ) || ( '' !== $key && ! isset( $book['addresses'][$key] ) ) ) { $this->fail( $partner, self::invalid() ); return; }
			if ( $is_import ) {
				// A preview is always server-derived. Even a failed copy keeps only label/code input, never posted source address data.
				$this->responses[$partner]['copy'] = [ 'type' => $type, 'label' => $post['pow_address_label'] ?? null, 'code' => $post['pow_address_code'] ?? '' ];
				$preview = $this->import->preview( $partner, $actor, $type );
				if ( $preview instanceof \WP_Error ) { $this->fail( $partner, $preview ); return; }
				$this->responses[$partner]['preview'] = $preview;
			}
			if ( $revision !== $book['revision'] ) { $this->fail( $partner, new \WP_Error( 'address_book_stale', __( 'The delivery book changed. Reload it before saving. Your unsaved input is shown below.', 'punchout-woocommerce' ) ) ); return; }
			if ( 'new' === $action || 'edit' === $action ) {
				$this->responses[$partner]['draft'] = 'new' === $action ? self::draft( [] ) : $book['addresses'][$key]; return;
			}
			if ( 'preview' === $action ) { return; }
			if ( 'save' === $action ) {
				if ( '1' === ( $post['pow_address_refresh'] ?? '' ) ) { return; }
				$result = $this->book->save( $partner, $actor, $revision, '' === $key ? null : $key, [ 'label' => $draft['label'], 'code' => $draft['code'], 'address' => $draft['address'] ] );
			} elseif ( 'remove' === $action ) {
				$result = $this->book->remove( $partner, $actor, $revision, $key );
			} elseif ( 'copy' === $action ) {
				if ( '1' !== ( $post['pow_address_confirm'] ?? '' ) ) { $this->fail( $partner, new \WP_Error( 'address_import_confirmation', __( 'Review the normal Woo address and confirm that you want a new disabled copy.', 'punchout-woocommerce' ) ) ); return; }
				$overrides = [];
				foreach ( [ 'label', 'code' ] as $field ) { if ( array_key_exists( 'pow_address_' . $field, $post ) ) { $overrides[$field] = $post['pow_address_' . $field]; } }
				$result = $this->import->copy( $partner, $actor, $revision, $type, $overrides );
			} else {
				$result = $this->book->save( $partner, $actor, $revision, $key, array_replace( $book['addresses'][$key], [ 'use_for_punchout' => 'enable' === $action ] ) );
			}
			if ( $result instanceof \WP_Error ) { $this->fail( $partner, $result ); return; }
			$text = match ( $action ) {
				'remove' => __( 'Delivery address removed. Its issued codes remain retired.', 'punchout-woocommerce' ),
				'enable' => __( 'Delivery address enabled for buyer selection.', 'punchout-woocommerce' ),
				'disable' => __( 'Delivery address disabled. Affected buyers must select an eligible address again.', 'punchout-woocommerce' ),
				'copy' => __( 'The reviewed normal Woo address was copied as a new disabled delivery address.', 'punchout-woocommerce' ),
				default => __( 'Delivery address saved.', 'punchout-woocommerce' ),
			};
			if ( is_array( $result ) && ! $result['changed'] ) { $text = __( 'No changes were needed.', 'punchout-woocommerce' ); }
			$this->responses[$partner] = [ 'actor' => $actor, 'notice' => [ 'type' => 'success', 'text' => $text ], 'draft' => null, 'copy' => null, 'preview' => null ];
		} catch ( \Throwable $error ) { $this->fail( $partner, self::unavailable() ); }
	}

	/** Private management view. Native field callbacks and templates run after the Book lock has released. */
	public function markup( int $partner_id ): string {
		if ( ! Transport::request_allowed() ) { return Transport::notice(); }
		$actor = get_current_user_id();
		$response = $this->responses[$partner_id] ?? null;
		if ( null !== $response && $response['actor'] !== $actor ) { $response = null; }
		try {
			$book = $this->book->read( $partner_id, $actor );
			if ( $book instanceof \WP_Error ) { return self::error_markup( $book, 'address_state_unavailable' === $book->get_error_code() ? ( $response['draft'] ?? $response['copy'] ?? null ) : null ); }
			nocache_headers();
			$draft = $response['draft'] ?? self::draft( [] );
			$key = null !== ( $response['draft'] ?? null ) ? $response['key'] : '';
			$revision = null !== ( $response['draft'] ?? null ) ? $response['revision'] : $book['revision'];
			$country = $draft['address']['country'];
			if ( '' === $country ) { $country = (string) WC()->countries->get_base_country(); }
			$form_fields = $this->native_fields( $partner_id, $country, $draft['address'] );
			$hidden = static function ( string $action, string $key = '', string $type = '', ?int $expected = null ) use ( $partner_id, $book ): string {
				$values = [ 'pow_address_action' => $action, 'pow_address_partner' => $partner_id, 'pow_address_revision' => $expected ?? $book['revision'], 'pow_address_key' => $key, 'pow_address_type' => $type, '_pow_address_nonce' => wp_create_nonce( self::nonce_action( $action, $partner_id, $key, $type ) ) ];
				$html = '';
				foreach ( $values as $name => $value ) { $html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />'; }
				return $html;
			};
			return Templates::render( 'account/delivery-addresses', [ 'partner_id' => $partner_id, 'book' => $book, 'notice' => $response['notice'] ?? null, 'draft' => $draft, 'key' => $key, 'revision' => $revision, 'form_fields' => $form_fields, 'hidden' => $hidden, 'preview' => $response['preview'] ?? null, 'copy' => $response['copy'] ?? null, 'copy_revision' => $response['revision'] ?? $book['revision'] ] );
		} catch ( \Throwable $error ) { return self::error_markup( self::unavailable(), $response['draft'] ?? $response['copy'] ?? null ); }
	}

	/**
	 * The one surface this editor answers on.
	 *
	 * The company delivery book is administrator-managed: the My Account tab
	 * keeps only its read-only setup-XML download, so there is no front-end
	 * route left to accept an editor POST on.
	 */
	private function route(): bool {
		return is_admin() && ( $_GET['page'] ?? null ) === Page::SLUG && current_user_can( Page::CAP );
	}

	private function native_fields( int $partner_id, string $country, array $address ): string {
		return self::address_inputs( 'pow_address_' . $partner_id . '_', $country, $address );
	}

	/**
	 * WooCommerce's own shipping fields for $country, filled from $address, with ids under $id_prefix.
	 *
	 * The one field set both address forms use: the administrator's editor here and a buyer's add form on the delivery review page. Their input names are always shipping_*, so both post the same keys and CompanyBook validates both the same way.
	 *
	 * @param array<string, string> $address The ten address keys.
	 * @throws \RuntimeException When WooCommerce offers no usable field set for the country.
	 */
	public static function address_inputs( string $id_prefix, string $country, array $address ): string {
		$fields = WC()->countries->get_address_fields( $country, 'shipping_' );
		if ( ! is_array( $fields ) || [] === $fields ) { throw new \RuntimeException(); }
		// Woo's normal shipping definition may omit phone, but the canonical shipping address can retain it.
		$fields['shipping_phone'] ??= [ 'label' => __( 'Phone (optional)', 'punchout-woocommerce' ), 'type' => 'tel', 'required' => false, 'autocomplete' => 'shipping tel' ];
		$html = '';
		foreach ( $fields as $name => $args ) {
			if ( ! is_string( $name ) || ! is_array( $args ) ) { throw new \RuntimeException(); }
			$field = str_starts_with( $name, 'shipping_' ) ? substr( $name, 9 ) : '';
			if ( ! in_array( $field, self::ADDRESS_KEYS, true ) ) { if ( ! empty( $args['required'] ) ) { throw new \RuntimeException(); } continue; }
			$args['id'] = $id_prefix . $name;
			$args['return'] = true;
			if ( 'state' === $field ) { $args['country'] = $country; $args['country_field'] = $id_prefix . 'shipping_country'; }
			$html .= woocommerce_form_field( $name, $args, 'country' === $field ? $country : (string) ( $address[$field] ?? '' ) );
		}
		return $html;
	}

	/** Strict form schema: imported source data and connection/entitlement settings never reach commands. */
	private static function allowed_post( array $post, string $action ): bool {
		$allowed = [ 'pow_address_action', 'pow_address_partner', 'pow_address_revision', 'pow_address_key', 'pow_address_type', '_pow_address_nonce' ];
		if ( 'save' === $action ) { $allowed = array_merge( $allowed, [ 'pow_address_label', 'pow_address_code', 'pow_address_refresh' ], array_map( static fn( $key ) => 'shipping_' . $key, self::ADDRESS_KEYS ) ); }
		if ( 'copy' === $action ) { $allowed = array_merge( $allowed, [ 'pow_address_label', 'pow_address_code', 'pow_address_confirm' ] ); }
		if ( array_diff_key( $post, array_flip( $allowed ) ) ) { return false; }
		foreach ( $post as $value ) { if ( ! is_string( $value ) ) { return false; } }
		return ! isset( $post['pow_address_refresh'] ) || '1' === $post['pow_address_refresh'];
	}
	/**
	 * The strict schema of a buyer's add-address POST on the delivery review page.
	 *
	 * Only the review nonce, the add nonce, the action, a label, the ten shipping fields, an optional country refresh, and the review's notes and preferred delivery date, which post with the add because the add fieldset sits inside the review form; every value a string. Chooser drops the review form's other fields (return nonce, digest, choice, rates, acknowledgement) unread before asking. There is no code field, so a buyer never picks a delivery code, and nothing names a key, revision, rate or enablement.
	 */
	public static function buyer_post_allowed( array $post ): bool {
		$allowed = array_merge( [ 'pow_nonce', 'pow_address_nonce', 'pow_delivery_action', 'pow_address_label', 'pow_address_refresh', 'notes', 'preferred_delivery_date' ], array_map( static fn( $key ) => 'shipping_' . $key, self::ADDRESS_KEYS ) );
		if ( array_diff_key( $post, array_flip( $allowed ) ) ) { return false; }
		foreach ( $post as $value ) { if ( ! is_string( $value ) ) { return false; } }
		return ! isset( $post['pow_address_refresh'] ) || '1' === $post['pow_address_refresh'];
	}

	/**
	 * A buyer's label and address as posted; anything missing or not a string reads as ''.
	 *
	 * @return array{label: string, address: array<string, string>}
	 */
	public static function buyer_draft( array $post ): array {
		$draft = self::draft( $post );
		return [ 'label' => $draft['label'], 'address' => $draft['address'] ];
	}

	private static function draft( array $post ): array {
		$address = [];
		foreach ( self::ADDRESS_KEYS as $field ) { $value = $post['shipping_' . $field] ?? ''; $address[$field] = is_string( $value ) ? $value : ''; }
		return [ 'label' => is_string( $post['pow_address_label'] ?? null ) ? $post['pow_address_label'] : '', 'code' => is_string( $post['pow_address_code'] ?? null ) ? $post['pow_address_code'] : '', 'address' => $address ];
	}
	private static function integer( mixed $value ): ?int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) || ( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) > 0 ) ) { return null; }
		return (int) $value;
	}
	private static function nonce_action( string $action, int $partner, string $key, string $type ): string { return 'pow_address_' . $action . '_' . $partner . '_' . $key . '_' . $type; }
	private function fail( int $partner, \WP_Error $error ): void {
		$this->responses[$partner] ??= [ 'actor' => get_current_user_id(), 'draft' => null, 'copy' => null, 'preview' => null ];
		$this->responses[$partner]['notice'] = [ 'type' => 'error', 'text' => $error->get_error_message() ];
	}
	private static function error_markup( \WP_Error $error, ?array $draft = null ): string {
		$html = '<div class="woocommerce-error" role="alert">' . esc_html( $error->get_error_message() ) . '</div>';
		if ( null !== $draft ) { $html .= '<p>' . esc_html__( 'Unsaved input — copy this before reloading:', 'punchout-woocommerce' ) . '</p><pre>' . esc_html( (string) wp_json_encode( $draft, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) . '</pre>'; }
		return $html;
	}
	private static function invalid(): \WP_Error { return new \WP_Error( 'address_editor_invalid', __( 'Supply a valid delivery address action, key, revision and text fields. Reload the editor if needed.', 'punchout-woocommerce' ) ); }
	private static function unavailable(): \WP_Error { return new \WP_Error( 'address_state_unavailable', __( 'The delivery editor could not verify this request. Reload it before trying again.', 'punchout-woocommerce' ) ); }
}
