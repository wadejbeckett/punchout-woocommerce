<?php
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Orders\Attachment;

/** The review page's optional file (0.4.14): type, size and name rules, without WordPress. */
final class AttachmentRulesTest extends TestCase {
	private const ALLOWED = [ 'xlsx', 'csv', 'pdf', 'png' ];
	private const MAX = 10 * MB_IN_BYTES;

	private static function file( string $name, int $size, int $error = UPLOAD_ERR_OK ): array {
		return [ 'name' => $name, 'size' => $size, 'error' => $error, 'tmp_name' => '/tmp/php-upload-x', 'type' => 'application/octet-stream' ];
	}

	public function test_no_file_means_nothing_attached(): void {
		self::assertNull( Attachment::check( null, self::ALLOWED, self::MAX ) );
		self::assertNull( Attachment::check( self::file( '', 0, UPLOAD_ERR_NO_FILE ), self::ALLOWED, self::MAX ) );
	}

	public function test_an_allowed_type_within_the_size_is_accepted_with_a_clean_name(): void {
		$checked = Attachment::check( self::file( 'Delivery split.XLSX', 2048 ), self::ALLOWED, self::MAX );
		self::assertTrue( is_array( $checked ) );
		self::assertSame( 'Delivery split.xlsx', $checked['name'] );
		self::assertSame( 'xlsx', $checked['ext'] );
		self::assertSame( 2048, $checked['size'] );
	}

	public function test_a_disallowed_type_is_refused_with_the_allowed_list(): void {
		$error = Attachment::check( self::file( 'macro.docm', 100 ), self::ALLOWED, self::MAX );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'attachment_type', $error->get_error_code() );
		self::assertStringContainsString( 'xlsx, csv, pdf, png', $error->get_error_message() );
		self::assertSame( 'attachment_type', Attachment::check( self::file( 'no-extension', 100 ), self::ALLOWED, self::MAX )->get_error_code() );
	}

	public function test_the_type_check_is_by_the_last_extension_only(): void {
		self::assertSame( 'attachment_type', Attachment::check( self::file( 'sheet.xlsx.exe', 100 ), self::ALLOWED, self::MAX )->get_error_code() );
		self::assertTrue( is_array( Attachment::check( self::file( 'report.final.pdf', 100 ), self::ALLOWED, self::MAX ) ) );
	}

	public function test_too_large_is_refused_with_the_limit_in_the_message(): void {
		$error = Attachment::check( self::file( 'big.csv', self::MAX + 1 ), self::ALLOWED, self::MAX );
		self::assertSame( 'attachment_too_large', $error->get_error_code() );
		self::assertStringContainsString( '10 MB', $error->get_error_message() );
		self::assertTrue( is_array( Attachment::check( self::file( 'ok.csv', self::MAX ), self::ALLOWED, self::MAX ) ) );
		self::assertSame( 'attachment_too_large', Attachment::check( self::file( 'big.csv', 10, UPLOAD_ERR_INI_SIZE ), self::ALLOWED, self::MAX )->get_error_code() );
		self::assertSame( 'attachment_too_large', Attachment::check( self::file( 'big.csv', 10, UPLOAD_ERR_FORM_SIZE ), self::ALLOWED, self::MAX )->get_error_code() );
	}

	public function test_an_empty_or_broken_upload_is_refused_plainly(): void {
		self::assertSame( 'attachment_empty', Attachment::check( self::file( 'empty.csv', 0 ), self::ALLOWED, self::MAX )->get_error_code() );
		self::assertSame( 'attachment_failed', Attachment::check( self::file( 'part.csv', 10, UPLOAD_ERR_PARTIAL ), self::ALLOWED, self::MAX )->get_error_code() );
		self::assertSame( 'attachment_failed', Attachment::check( [ 'name' => 'x.csv', 'size' => 10, 'error' => UPLOAD_ERR_OK, 'tmp_name' => '' ], self::ALLOWED, self::MAX )->get_error_code() );
	}

	public function test_names_lose_paths_controls_and_excess_length_but_keep_the_extension(): void {
		self::assertSame( '-etc-passwd.csv', Attachment::clean_name( '../etc/passwd.csv' ) );
		self::assertSame( 'C-sites-list.xlsx', Attachment::clean_name( "C:\\sites\\list.xlsx" ) );
		self::assertSame( 'quote (1).pdf', Attachment::clean_name( " quote (1).pdf\n" ) );
		self::assertSame( 'pdf', Attachment::clean_name( '...pdf' ) ); // No stem, no extension: check() then refuses it.
		self::assertSame( '', Attachment::clean_name( "\xff\xfe.pdf" ) );
		$long = Attachment::clean_name( str_repeat( 'a', 200 ) . '.xlsx' );
		self::assertSame( 120, strlen( $long ) );
		self::assertSame( '.xlsx', substr( $long, -5 ) );
	}

	public function test_the_allowed_type_list_is_normalised(): void {
		self::assertSame( [ 'xlsx', 'csv', 'pdf' ], Attachment::normalise_types( [ ' .XLSX', 'csv', 'csv', 'pdf', '', 'tar.gz', 42, 'a b' ] ) );
		self::assertSame( [], Attachment::normalise_types( 'xlsx' ) );
		self::assertSame( [ 'xlsx', 'xls', 'csv', 'pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp' ], Attachment::DEFAULT_TYPES );
		self::assertSame( 10 * MB_IN_BYTES, Attachment::DEFAULT_MAX_BYTES );
	}

	public function test_sizes_read_plainly(): void {
		self::assertSame( '10 MB', Attachment::format_size( 10 * MB_IN_BYTES ) );
		self::assertSame( '2.5 MB', Attachment::format_size( (int) ( 2.5 * MB_IN_BYTES ) ) );
		self::assertSame( '3 KB', Attachment::format_size( 3 * KB_IN_BYTES + 100 ) );
		self::assertSame( '512 B', Attachment::format_size( 512 ) );
	}
	public function test_a_stored_name_is_one_plain_file_name(): void {
		foreach ( [ 'Delivery split.xlsx', 'Bestelling café.pdf', 'a.b.c.csv', '..hidden.png' ] as $name ) {
			self::assertTrue( Attachment::safe_name( $name ), $name );
		}
		foreach ( [ '', '.', '..', '../../wp-config.php', '/etc/passwd', 'sub/file.pdf', '..\\boot.ini', "line\nbreak.pdf", "nul\x00.pdf", "\xff\xfe.pdf", str_repeat( 'a', 256 ) ] as $name ) {
			self::assertFalse( Attachment::safe_name( $name ), var_export( $name, true ) );
		}
		// Every name the upload path stores passes.
		foreach ( [ '../../wp-config.php', 'C:\\x\\y.pdf', " ..\tdots.xlsx ", 'a%2f.csv' ] as $raw ) {
			self::assertTrue( Attachment::safe_name( Attachment::clean_name( $raw ) ), $raw );
		}
	}

	public function test_a_download_resolves_only_inside_its_own_directory(): void {
		$root = sys_get_temp_dir() . '/pow-attach-' . bin2hex( random_bytes( 6 ) );
		$dir = $root . '/' . str_repeat( 'a', 32 );
		mkdir( $dir, 0700, true );
		file_put_contents( $dir . '/split.xlsx', 'x' );
		file_put_contents( $root . '/secret.txt', 'secret' );
		try {
			self::assertSame( realpath( $dir . '/split.xlsx' ), Attachment::contained_path( $dir, 'split.xlsx' ) );
			self::assertNull( Attachment::contained_path( $dir, '../secret.txt' ), 'A path part never leaves the directory' );
			self::assertNull( Attachment::contained_path( $dir, 'missing.pdf' ) );
			self::assertNull( Attachment::contained_path( $dir . '/nowhere', 'split.xlsx' ) );
			if ( function_exists( 'symlink' ) && @symlink( $root . '/secret.txt', $dir . '/link.pdf' ) ) {
				self::assertNull( Attachment::contained_path( $dir, 'link.pdf' ), 'A link out of the directory is refused' );
			}
		} finally {
			foreach ( [ $dir . '/link.pdf', $dir . '/split.xlsx', $root . '/secret.txt' ] as $file ) { if ( is_file( $file ) || is_link( $file ) ) { unlink( $file ); } }
			rmdir( $dir ); rmdir( $root );
		}
	}

	public function test_an_order_whose_stored_name_has_a_path_part_has_no_attachment(): void {
		$order = new WC_Order( 501 );
		$order->update_meta_data( Attachment::META, json_encode( [ 'id' => str_repeat( 'b', 32 ), 'name' => '../../../wp-config.php', 'size' => 1, 'type' => 'text/plain' ] ) );
		self::assertNull( Attachment::for_order( $order ) );
		$order->update_meta_data( Attachment::META, json_encode( [ 'id' => str_repeat( 'b', 32 ), 'name' => 'split.xlsx', 'size' => 1, 'type' => 'text/plain' ] ) );
		self::assertSame( 'split.xlsx', Attachment::for_order( $order )['name'] );
	}
}
