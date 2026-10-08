<?php
/** One optional buyer file kept with the Punchout Quote order. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Orders;

use POW\Cart\SessionKey;
use POW\Sessions\Session;
use POW\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The review page offers one optional file beside the delivery notes ("Attachment" by default;
 * the label and help text are settings, filterable). The file is checked by extension, by what
 * the bytes actually are, and by size; a refused file never touches the buyer's notes or date.
 *
 * An accepted file is moved to a private directory under the uploads folder
 * (`punchout-woocommerce/attachments/<32 random hex>/<name>`, directory mode 0700, `.htaccess`
 * and index files on the parent, so neither a guessed URL nor a directory listing reaches it)
 * and remembered in the visit's own WooCommerce session until the cart returns. When the
 * Punchout Quote order is created the pending file is claimed by that order (meta
 * `_pow_attachment`, an order note, a line on the order screen and a link in the "PunchOut order
 * received" e-mail). Downloads go through admin-post.php, require `manage_woocommerce`, and name
 * the order and the attachment id. Nothing is sent to the purchasing system.
 *
 * Files whose visit never returned are swept by the hourly housekeeping job after two days.
 */
final class Attachment {
	public const META = '_pow_attachment';
	public const FIELD = 'pow_attachment';
	public const REMOVE_FIELD = 'pow_attachment_remove';
	public const DOWNLOAD_ACTION = 'pow_attachment';
	/** Where the visit's own WooCommerce session keeps the pending file: {visit, key, id, name, size, type}; key is the visit's own basket key. */
	private const CARRY = 'pow_attachment_pending';
	private const DIRECTORY = 'punchout-woocommerce/attachments';
	private const PENDING_MARKER = 'pending';
	private const PENDING_TTL = 2 * DAY_IN_SECONDS;
	public const DEFAULT_TYPES = [ 'xlsx', 'xls', 'csv', 'pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp' ];
	public const DEFAULT_MAX_BYTES = 10 * MB_IN_BYTES;
	private const MAX_NAME = 120;

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( 'woocommerce_order_status_' . Status::SLUG, [ $this, 'claim' ], 5, 2 );
	}

	/** The order-screen line and the download route; registered whether or not new visits are enabled, like the status itself. */
	public function register_admin(): void {
		add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'render_admin' ] );
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, [ $this, 'download' ] );
	}

	/* ---------------------------------------------------------------- rules (pure) */

	/**
	 * The allowed extensions: lower-case, deduplicated, letters and digits only. Filter `punchout_attachment_types`.
	 *
	 * @return list<string>
	 */
	public static function normalise_types( mixed $types ): array {
		$out = [];
		foreach ( is_array( $types ) ? $types : [] as $type ) {
			if ( ! is_string( $type ) ) { continue; }
			$type = strtolower( ltrim( trim( $type ), '.' ) );
			if ( '' === $type || 1 !== preg_match( '/\A[a-z0-9]{1,10}\z/', $type ) || in_array( $type, $out, true ) ) { continue; }
			$out[] = $type;
		}
		return $out;
	}

	/**
	 * Decide one `$_FILES` entry against the rules. null: nothing was attached. An array: {name, ext, size, tmp_name}
	 * with the name cleaned for storage. A WP_Error: a plain refusal the review shows beside the buyer's notes.
	 *
	 * Pure apart from translation: the bytes themselves are checked by store(), which has the WordPress file-type sniff.
	 *
	 * @param list<string> $allowed Lower-case extensions.
	 */
	public static function check( mixed $file, array $allowed, int $max_bytes ): array|\WP_Error|null {
		if ( ! is_array( $file ) ) { return null; }
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_NO_FILE === $error ) { return null; }
		if ( in_array( $error, [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ], true ) ) { return self::error( 'attachment_too_large', $max_bytes ); }
		if ( UPLOAD_ERR_OK !== $error || ! is_string( $file['tmp_name'] ?? null ) || '' === $file['tmp_name'] ) { return self::error( 'attachment_failed' ); }
		$name = self::clean_name( (string) ( $file['name'] ?? '' ) );
		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( '' === $name || '' === $ext || ! in_array( $ext, $allowed, true ) ) { return self::error( 'attachment_type', 0, $allowed ); }
		$size = (int) ( $file['size'] ?? 0 );
		if ( $size <= 0 ) { return self::error( 'attachment_empty' ); }
		if ( $size > $max_bytes ) { return self::error( 'attachment_too_large', $max_bytes ); }
		return [ 'name' => $name, 'ext' => $ext, 'size' => $size, 'tmp_name' => $file['tmp_name'] ];
	}

	/** A file name safe to store and to show: its basename, controls and path characters removed, bounded, extension kept. */
	public static function clean_name( string $name ): string {
		$name = str_replace( [ '\\', '/' ], '-', $name );
		$name = (string) preg_replace( '/[\x00-\x1f\x7f<>:"|?*%]+/u', '', $name );
		if ( 1 !== preg_match( '//u', $name ) ) { return ''; }
		$name = trim( $name, " .\t" );
		if ( '' === $name ) { return ''; }
		$ext = (string) pathinfo( $name, PATHINFO_EXTENSION );
		$stem = '' === $ext ? $name : substr( $name, 0, -strlen( $ext ) - 1 );
		$limit = self::MAX_NAME - ( '' === $ext ? 0 : strlen( $ext ) + 1 );
		if ( strlen( $stem ) > $limit ) { $stem = (string) mb_strcut( $stem, 0, max( 1, $limit ) ); }
		$stem = trim( $stem, " ." );
		if ( '' === $stem ) { $stem = 'attachment'; }
		return '' === $ext ? $stem : $stem . '.' . strtolower( $ext );
	}

	/** "2.3 MB" style, without WordPress. */
	public static function format_size( int $bytes ): string {
		if ( $bytes >= MB_IN_BYTES ) { return rtrim( rtrim( number_format( $bytes / MB_IN_BYTES, 1, '.', '' ), '0' ), '.' ) . ' MB'; }
		if ( $bytes >= KB_IN_BYTES ) { return (string) (int) round( $bytes / KB_IN_BYTES ) . ' KB'; }
		return $bytes . ' B';
	}

	private static function error( string $code, int $max_bytes = 0, array $allowed = [] ): \WP_Error {
		$message = match ( $code ) {
			'attachment_too_large' => sprintf( /* translators: %s: size such as "10 MB" */ __( 'The attachment is too large. Files up to %s can be attached.', 'punchout-woocommerce' ), self::format_size( $max_bytes ) ),
			'attachment_type' => sprintf( /* translators: %s: comma-separated file extensions */ __( 'That file type cannot be attached. Allowed: %s.', 'punchout-woocommerce' ), implode( ', ', $allowed ) ),
			'attachment_empty' => __( 'The attachment is empty. Choose another file or leave the field blank.', 'punchout-woocommerce' ),
			default => __( 'The attachment could not be uploaded. Try again or leave the field blank.', 'punchout-woocommerce' ),
		};
		return new \WP_Error( $code, $message );
	}

	/* ---------------------------------------------------------------- settings */

	public function enabled(): bool {
		return (bool) apply_filters( 'punchout_attachment_enabled', 'no' !== (string) $this->settings->get( 'attachment_enabled', 'yes' ) );
	}

	/** @return list<string> */
	public static function allowed_types(): array {
		return self::normalise_types( apply_filters( 'punchout_attachment_types', self::DEFAULT_TYPES ) ) ?: self::DEFAULT_TYPES;
	}

	/** The smaller of the plugin's limit (filter `punchout_attachment_max_bytes`) and what PHP accepts. */
	public static function max_bytes(): int {
		$max = (int) apply_filters( 'punchout_attachment_max_bytes', self::DEFAULT_MAX_BYTES );
		if ( $max <= 0 ) { $max = self::DEFAULT_MAX_BYTES; }
		$php = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
		return $php > 0 ? min( $max, $php ) : $max;
	}

	public function label(): string {
		return (string) apply_filters( 'punchout_attachment_label', $this->settings->button_label( 'attachment_label', __( 'Attachment', 'punchout-woocommerce' ) ) );
	}

	public function help(): string {
		return (string) apply_filters( 'punchout_attachment_help', $this->settings->button_label( 'attachment_help', __( 'Optional: a sheet or document for this order.', 'punchout-woocommerce' ) ) );
	}

	/* ---------------------------------------------------------------- review page */

	/**
	 * The template's `attachment` variables for this visit, or null when the field is off.
	 *
	 * @return array{label: string, help: string, accept: string, limit: string, pending: ?array{name: string, size: string}}|null
	 */
	public function view_vars( Session $session ): ?array {
		if ( ! $this->enabled() ) { return null; }
		$pending = $this->carry( $session );
		return [
			'label' => $this->label(),
			'help' => $this->help(),
			'accept' => implode( ',', array_map( static fn( string $ext ): string => '.' . $ext, self::allowed_types() ) ),
			'limit' => sprintf( /* translators: 1: file extensions, 2: size such as "10 MB" */ __( 'Allowed: %1$s. Up to %2$s.', 'punchout-woocommerce' ), implode( ', ', self::allowed_types() ), self::format_size( self::max_bytes() ) ),
			'pending' => null === $pending ? null : [ 'name' => (string) $pending['name'], 'size' => self::format_size( (int) $pending['size'] ) ],
		];
	}

	/**
	 * Apply a review POST's attachment input for this visit: a "remove" tick drops the pending file; a new file replaces it.
	 *
	 * null on success (including no input at all). A WP_Error names the refusal; the pending file, the notes and the date are untouched.
	 */
	public function take_upload( Session $session, array $files, array $post ): ?\WP_Error {
		if ( ! $this->enabled() ) { return null; }
		if ( '1' === ( $post[ self::REMOVE_FIELD ] ?? '' ) ) { $this->drop( $session ); }
		$checked = self::check( $files[ self::FIELD ] ?? null, self::allowed_types(), self::max_bytes() );
		if ( null === $checked ) { return null; }
		if ( $checked instanceof \WP_Error ) { return $checked; }
		$stored = $this->store( $checked );
		if ( $stored instanceof \WP_Error ) { return $stored; }
		$this->drop( $session );
		if ( ! $this->set_carry( $session, [ 'visit' => $session->id, 'key' => SessionKey::for_session( $session ) ] + $stored ) ) {
			self::remove_dir( self::dir( $stored['id'] ) );
			return self::error( 'attachment_failed' );
		}
		return null;
	}

	/**
	 * Sniff the bytes and move the upload into its own private directory.
	 *
	 * @param array{name: string, ext: string, size: int, tmp_name: string} $checked
	 * @return array{id: string, name: string, size: int, type: string}|\WP_Error
	 */
	private function store( array $checked ): array|\WP_Error {
		if ( ! is_uploaded_file( $checked['tmp_name'] ) ) { return self::error( 'attachment_failed' ); }
		$mimes = [];
		foreach ( wp_get_mime_types() as $pattern => $mime ) {
			foreach ( explode( '|', $pattern ) as $ext ) { if ( in_array( $ext, self::allowed_types(), true ) ) { $mimes[ $pattern ] = $mime; break; } }
		}
		$type = wp_check_filetype_and_ext( $checked['tmp_name'], $checked['name'], $mimes );
		$real_ext = strtolower( (string) ( $type['ext'] ?? '' ) );
		if ( '' === $real_ext || ! in_array( $real_ext, self::allowed_types(), true ) || empty( $type['type'] ) ) { return self::error( 'attachment_type', 0, self::allowed_types() ); }
		$name = ! empty( $type['proper_filename'] ) ? self::clean_name( (string) $type['proper_filename'] ) : $checked['name'];
		if ( ! self::ensure_root() ) { return self::error( 'attachment_failed' ); }
		$id = bin2hex( random_bytes( 16 ) );
		$dir = self::dir( $id );
		if ( file_exists( $dir ) || ! wp_mkdir_p( $dir ) ) { return self::error( 'attachment_failed' ); }
		@chmod( $dir, 0700 );
		$target = $dir . '/' . $name;
		if ( ! @move_uploaded_file( $checked['tmp_name'], $target ) ) { self::remove_dir( $dir ); return self::error( 'attachment_failed' ); }
		@chmod( $target, 0600 );
		if ( false === @file_put_contents( $dir . '/' . self::PENDING_MARKER, (string) time() ) ) { self::remove_dir( $dir ); return self::error( 'attachment_failed' ); }
		@chmod( $dir . '/' . self::PENDING_MARKER, 0600 );
		return [ 'id' => $id, 'name' => $name, 'size' => (int) filesize( $target ), 'type' => (string) $type['type'] ];
	}

	/** Forget and delete this visit's pending file, if any. */
	public function drop( Session $session ): void {
		$carry = $this->carry( $session );
		if ( null !== $carry ) { self::remove_dir( self::dir( (string) $carry['id'] ) ); }
		$this->set_carry( $session, null );
	}

	/* ---------------------------------------------------------------- the order */

	/**
	 * An order has just become a Punchout Quote in the request that returned the cart: the pending file of
	 * this request's visit becomes that order's attachment. Before the e-mail (priority 5), so it is linked there.
	 *
	 * @param int|string $order_id
	 */
	public function claim( $order_id, $order = null ): void {
		try {
			if ( ! $order instanceof \WC_Order ) { $order = wc_get_order( (int) $order_id ); }
			if ( ! $order instanceof \WC_Order || '' !== (string) $order->get_meta( self::META ) ) { return; }
			$native = function_exists( 'WC' ) ? ( WC()->session ?? null ) : null;
			if ( ! $native ) { return; }
			$carry = $native->get( self::CARRY );
			if ( ! is_array( $carry ) || ! self::carry_shape( $carry ) ) { return; }
			$visit = (int) $order->get_meta( QuoteOrder::META_SESSION_ID );
			// The pending file belongs to the visit that attached it and to no other order.
			if ( $visit <= 0 || (int) $carry['visit'] !== $visit || ! hash_equals( (string) $carry['key'], (string) $native->get_customer_id() ) ) { return; }
			$dir = self::dir( (string) $carry['id'] );
			$path = $dir . '/' . $carry['name'];
			if ( ! is_file( $path ) ) { $native->set( self::CARRY, null ); return; }
			$record = [ 'id' => (string) $carry['id'], 'name' => (string) $carry['name'], 'size' => (int) $carry['size'], 'type' => (string) $carry['type'], 'attached_at' => gmdate( 'c' ) ];
			$order->update_meta_data( self::META, wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			$order->save_meta_data();
			@unlink( $dir . '/' . self::PENDING_MARKER );
			$native->set( self::CARRY, null );
			$order->add_order_note( sprintf( /* translators: 1: file name, 2: size */ __( 'Buyer attachment: %1$s (%2$s).', 'punchout-woocommerce' ), $record['name'], self::format_size( $record['size'] ) ) );
		} catch ( \Throwable $error ) { /* An attachment is optional; a failure here must never fail the return. */ }
	}

	/** The order's attachment record, or null. @return array{id: string, name: string, size: int, type: string, attached_at: string}|null */
	public static function for_order( \WC_Order $order ): ?array {
		$json = (string) $order->get_meta( self::META );
		if ( '' === $json ) { return null; }
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! is_string( $data['id'] ?? null ) || 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $data['id'] ) || ! is_string( $data['name'] ?? null ) ) { return null; }
		return [ 'id' => $data['id'], 'name' => $data['name'], 'size' => (int) ( $data['size'] ?? 0 ), 'type' => (string) ( $data['type'] ?? '' ), 'attached_at' => (string) ( $data['attached_at'] ?? '' ) ];
	}

	/** The admin download link for an order's attachment (signed with a nonce when drawn for a signed-in administrator). */
	public static function download_url( \WC_Order $order, array $attachment, bool $nonce = false ): string {
		$url = add_query_arg( [ 'action' => self::DOWNLOAD_ACTION, 'order' => $order->get_id(), 'id' => $attachment['id'] ], admin_url( 'admin-post.php' ) );
		return $nonce ? wp_nonce_url( $url, self::DOWNLOAD_ACTION . '-' . $order->get_id() ) : $url;
	}

	/** The order screen's "Attachment" line under the shipping address. */
	public function render_admin( mixed $order ): void {
		if ( ! $order instanceof \WC_Order ) { return; }
		$attachment = self::for_order( $order );
		if ( null === $attachment ) { return; }
		echo '<p class="pow-attachment"><strong>' . esc_html( $this->label() ) . '</strong><br /><a href="' . esc_url( self::download_url( $order, $attachment, true ) ) . '">' . esc_html( $attachment['name'] ) . '</a> (' . esc_html( self::format_size( $attachment['size'] ) ) . ')</p>';
	}

	/** admin-post.php?action=pow_attachment&order=…&id=…: store staff only; the id must be the order's own. */
	public function download(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You are not allowed to download this file.', 'punchout-woocommerce' ), '', [ 'response' => 403 ] ); }
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability-gated read; the nonce is checked below when present.
		$id = isset( $_GET['id'] ) && is_string( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['_wpnonce'] ) && ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::DOWNLOAD_ACTION . '-' . $order_id ) ) { wp_die( esc_html__( 'This link has expired. Open the order and use its attachment link.', 'punchout-woocommerce' ), '', [ 'response' => 403 ] ); }
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		$attachment = $order instanceof \WC_Order ? self::for_order( $order ) : null;
		$path = null !== $attachment ? self::dir( $attachment['id'] ) . '/' . $attachment['name'] : '';
		if ( null === $attachment || '' === $id || ! hash_equals( $attachment['id'], $id ) || ! is_file( $path ) ) { wp_die( esc_html__( 'No such attachment.', 'punchout-woocommerce' ), '', [ 'response' => 404 ] ); }
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . ( '' !== $attachment['type'] ? $attachment['type'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $attachment['name'] ) . '"; filename*=UTF-8\'\'' . rawurlencode( $attachment['name'] ) );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a private file.
		exit;
	}

	/* ---------------------------------------------------------------- housekeeping */

	/** Delete pending directories (never claimed by an order) older than two days. Returns how many were removed. */
	public static function sweep(): int {
		$root = self::root();
		if ( '' === $root || ! is_dir( $root ) ) { return 0; }
		$removed = 0;
		foreach ( glob( $root . '/*', GLOB_ONLYDIR ) ?: [] as $dir ) {
			$marker = $dir . '/' . self::PENDING_MARKER;
			if ( ! is_file( $marker ) ) { continue; }
			$since = (int) file_get_contents( $marker ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( $since > 0 && time() - $since > self::PENDING_TTL ) { self::remove_dir( $dir ); ++$removed; }
		}
		return $removed;
	}

	/* ---------------------------------------------------------------- storage helpers */

	private static function root(): string {
		if ( ! function_exists( 'wp_upload_dir' ) ) { return ''; }
		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || ! is_string( $uploads['basedir'] ?? null ) || '' === $uploads['basedir'] ) { return ''; }
		return rtrim( $uploads['basedir'], '/' ) . '/' . self::DIRECTORY;
	}

	private static function dir( string $id ): string {
		if ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $id ) ) { throw new \DomainException( 'Invalid attachment id.' ); }
		return self::root() . '/' . $id;
	}

	/** The private parent: not listable, not served by Apache, not readable by other system users. */
	private static function ensure_root(): bool {
		$root = self::root();
		if ( '' === $root ) { return false; }
		$parent = dirname( $root );
		if ( ! wp_mkdir_p( $root ) ) { return false; }
		@chmod( $parent, 0700 );
		@chmod( $root, 0700 );
		foreach ( [ $parent, $root ] as $dir ) {
			if ( ! is_file( $dir . '/.htaccess' ) ) { @file_put_contents( $dir . '/.htaccess', "# Private plugin files: never served directly.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); }
			if ( ! is_file( $dir . '/index.html' ) ) { @file_put_contents( $dir . '/index.html', '' ); }
		}
		return is_dir( $root ) && is_writable( $root );
	}

	private static function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) { return; }
		foreach ( glob( $dir . '/{,.}*', GLOB_BRACE ) ?: [] as $entry ) { if ( is_file( $entry ) ) { @unlink( $entry ); } }
		@rmdir( $dir );
	}

	/* ---------------------------------------------------------------- the visit's carry */

	private static function carry_shape( array $carry ): bool {
		return is_int( $carry['visit'] ?? null ) && is_string( $carry['key'] ?? null ) && SessionKey::is_visit_key( $carry['key'] ) && is_string( $carry['id'] ?? null ) && 1 === preg_match( '/\A[a-f0-9]{32}\z/', $carry['id'] ) && is_string( $carry['name'] ?? null ) && '' !== $carry['name'] && is_int( $carry['size'] ?? null ) && is_string( $carry['type'] ?? null );
	}

	/** The pending file of this visit, read only from the WooCommerce session this request holds and only when that is the visit's own basket. */
	private function carry( Session $session ): ?array {
		try {
			$native = WC()->session ?? null;
			if ( ! $native || ! hash_equals( SessionKey::for_session( $session ), (string) $native->get_customer_id() ) ) { return null; }
			$carry = $native->get( self::CARRY );
			if ( ! is_array( $carry ) || ! self::carry_shape( $carry ) || $carry['visit'] !== $session->id ) { return null; }
			return $carry;
		} catch ( \Throwable $error ) { return null; }
	}

	private function set_carry( Session $session, ?array $carry ): bool {
		try {
			$native = WC()->session ?? null;
			if ( ! $native || ! hash_equals( SessionKey::for_session( $session ), (string) $native->get_customer_id() ) ) { return false; }
			$native->set( self::CARRY, $carry );
			return true;
		} catch ( \Throwable $error ) { return false; }
	}
}
