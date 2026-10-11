<?php
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Orders\Attachment;

/** The file an old Punchout Quote keeps (0.4.14, read-only since 0.5.0): the name and path rules of its display and download, without WordPress. */
final class AttachmentRulesTest extends TestCase {
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
