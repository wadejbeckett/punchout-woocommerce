<?php
/** The file an old Punchout Quote keeps: read-only display and download (0.5.0). @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Until 0.4.23 the plugin's review page offered one optional file, moved into a private directory under the
 * uploads folder (`punchout-woocommerce/attachments/<32 random hex>/<name>`, directory mode 0700, `.htaccess`
 * and index files on the parent) and claimed by the Punchout Quote as meta `_pow_attachment`. 0.5.0 retired
 * the field with the review page: a checkout attachment belongs to the shop's checkout, and the cXML carries
 * its file name through the connection's "Order details sent".
 *
 * What stays, for history: the order screen's "Attachment" line with its download link for the Quotes that
 * carry one, the download itself (admin-post.php, `manage_woocommerce`, the id must be the order's own, the
 * stored name must be a plain file name inside the attachment's own directory), the link in the "PunchOut
 * order received" e-mail, and the housekeeping sweep of pending directories a visit never claimed. Nothing
 * uploads, stores or claims a new file.
 */
final class Attachment {
	public const META = '_pow_attachment';
	public const DOWNLOAD_ACTION = 'pow_attachment';
	private const DIRECTORY = 'punchout-woocommerce/attachments';
	private const PENDING_MARKER = 'pending';
	private const PENDING_TTL = 2 * DAY_IN_SECONDS;

	/** The order-screen line and the download route; registered whether or not new visits are enabled, like the status itself. */
	public function register_admin(): void {
		add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'render_admin' ] );
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, [ $this, 'download' ] );
	}

	/* ---------------------------------------------------------------- rules (pure) */

	/**
	 * Whether a stored name is one plain file name (0.4.22): not empty, valid UTF-8, no directory separator, not "."
	 * or "..", no control character. Every name the old review stored passes; a name edited into order meta to
	 * reach another file does not.
	 */
	public static function safe_name( string $name ): bool {
		return '' !== $name && strlen( $name ) <= 255 && 1 === preg_match( '//u', $name ) && ! in_array( $name, [ '.', '..' ], true ) && 1 !== preg_match( '#[/\\\\\x00-\x1f\x7f]#', $name );
	}

	/**
	 * The real path of the file $name directly inside $dir, or null when the name is not a plain file name or the
	 * file resolves anywhere else (a link out of the directory, a missing file) (0.4.22).
	 */
	public static function contained_path( string $dir, string $name ): ?string {
		if ( ! self::safe_name( $name ) ) { return null; }
		$real_dir = realpath( $dir );
		if ( false === $real_dir || ! is_dir( $real_dir ) ) { return null; }
		$real = realpath( $real_dir . DIRECTORY_SEPARATOR . $name );
		if ( false === $real || ! is_file( $real ) || dirname( $real ) !== $real_dir ) { return null; }
		return $real;
	}

	/** "2.3 MB" style, without WordPress. */
	public static function format_size( int $bytes ): string {
		if ( $bytes >= MB_IN_BYTES ) { return rtrim( rtrim( number_format( $bytes / MB_IN_BYTES, 1, '.', '' ), '0' ), '.' ) . ' MB'; }
		if ( $bytes >= KB_IN_BYTES ) { return (string) (int) round( $bytes / KB_IN_BYTES ) . ' KB'; }
		return $bytes . ' B';
	}

	/* ---------------------------------------------------------------- the order */

	/** The order's attachment record, or null. @return array{id: string, name: string, size: int, type: string, attached_at: string}|null */
	public static function for_order( \WC_Order $order ): ?array {
		$json = (string) $order->get_meta( self::META );
		if ( '' === $json ) { return null; }
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! is_string( $data['id'] ?? null ) || 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $data['id'] ) || ! is_string( $data['name'] ?? null ) || ! self::safe_name( $data['name'] ) ) { return null; }
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
		echo '<p class="pow-attachment"><strong>' . esc_html__( 'Attachment', 'punchout-woocommerce' ) . '</strong><br /><a href="' . esc_url( self::download_url( $order, $attachment, true ) ) . '">' . esc_html( $attachment['name'] ) . '</a> (' . esc_html( self::format_size( $attachment['size'] ) ) . ')</p>';
	}

	/** admin-post.php?action=pow_attachment&order=…&id=…: store staff only; the id must be the order's own. */
	public function download(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You are not allowed to download this file.', 'punchout-woocommerce' ), '', [ 'response' => 403 ] ); }
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability-gated read; the nonce is checked below when present.
		$id = isset( $_GET['id'] ) && is_string( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['_wpnonce'] ) && ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::DOWNLOAD_ACTION . '-' . $order_id ) ) { wp_die( esc_html__( 'This link has expired. Open the order and use its attachment link.', 'punchout-woocommerce' ), '', [ 'response' => 403 ] ); }
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		$attachment = $order instanceof \WC_Order ? self::for_order( $order ) : null;
		// 0.4.22: the stored name must be a plain file name that resolves inside the attachment's own directory.
		$path = null !== $attachment ? self::contained_path( self::dir( $attachment['id'] ), $attachment['name'] ) : null;
		if ( null === $attachment || '' === $id || ! hash_equals( $attachment['id'], $id ) || null === $path ) { wp_die( esc_html__( 'No such attachment.', 'punchout-woocommerce' ), '', [ 'response' => 404 ] ); }
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

	private static function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) { return; }
		foreach ( glob( $dir . '/{,.}*', GLOB_BRACE ) ?: [] as $entry ) { if ( is_file( $entry ) ) { wp_delete_file( $entry ); } }
		@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- removes the plugin's own emptied directory; a failure leaves an empty directory behind.
	}
}
