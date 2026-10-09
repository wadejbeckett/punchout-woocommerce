<?php
/**
 * The two per-connection settings schema ten adds for the cXML built from the order (0.5.0): the line description
 * mode (`line_description`: the product name, or the name and its short description) and the "Order details sent"
 * (`order_details`: order meta keys, each with a label and a format, added to DeliveryInstructions).
 *
 * Every writer goes through the same normalisers, so a hand-edited or malformed row never reaches the message.
 *
 * @package POW
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Partners\OrderDetails;
use POW\Partners\Partner;

final class ConnectionMessageSettingsTest extends TestCase {

	public function test_a_row_from_before_schema_ten_sends_the_name_and_no_order_details(): void {
		$partner = Partner::from_row( [ 'id' => 5, 'owner_user_id' => 9, 'status' => 'active' ] );
		self::assertSame( 'name', $partner->line_description );
		self::assertSame( '', $partner->order_details );
		self::assertSame( [], $partner->order_detail_list() );
	}

	public function test_the_line_description_reads_back_and_anything_unknown_is_the_name(): void {
		self::assertSame( [ 'name', 'name_short_description' ], Partner::LINE_DESCRIPTIONS );
		self::assertSame( 'name_short_description', Partner::from_row( [ 'id' => 5, 'line_description' => 'name_short_description' ] )->line_description );
		foreach ( [ '', 'NAME_SHORT_DESCRIPTION', 'short', 'name ', 'description' ] as $raw ) {
			self::assertSame( 'name', Partner::from_row( [ 'id' => 5, 'line_description' => $raw ] )->line_description, $raw );
			self::assertSame( 'name', Partner::normalise_line_description( $raw ), $raw );
		}
		self::assertSame( 'name', Partner::normalise_line_description( [ 'name_short_description' ] ) );
	}

	public function test_the_order_details_read_back_as_a_list(): void {
		$stored  = '[{"meta_key":"_delivery_timestamp","label":"Preferred delivery date","format":"unix_date"},{"meta_key":"_attachment_name","label":"Attachment","format":"text"}]';
		$partner = Partner::from_row( [ 'id' => 5, 'order_details' => $stored ] );
		self::assertSame( $stored, $partner->order_details );
		self::assertSame(
			[
				[ 'meta_key' => '_delivery_timestamp', 'label' => 'Preferred delivery date', 'format' => 'unix_date' ],
				[ 'meta_key' => '_attachment_name', 'label' => 'Attachment', 'format' => 'text' ],
			],
			$partner->order_detail_list()
		);
	}

	public function test_a_malformed_or_hand_edited_list_keeps_only_well_formed_entries(): void {
		foreach ( [ 'not json', '{"meta_key":"x"}', '"x"', '[1,2]', '[[]]', 'null' ] as $raw ) {
			self::assertSame( [], Partner::from_row( [ 'id' => 5, 'order_details' => $raw ] )->order_detail_list(), $raw );
			self::assertSame( '', Partner::from_row( [ 'id' => 5, 'order_details' => $raw ] )->order_details, $raw );
		}
		$list = OrderDetails::normalise(
			[
				[ 'meta_key' => '_ok', 'label' => 'Kept', 'format' => 'text' ],
				[ 'meta_key' => '', 'label' => 'No key', 'format' => 'text' ],
				[ 'meta_key' => "_bad\x01", 'label' => 'Control character', 'format' => 'text' ],
				[ 'meta_key' => '_pipe|key', 'label' => 'Pipe', 'format' => 'text' ],
				[ 'meta_key' => str_repeat( 'k', 191 ), 'label' => 'Too long', 'format' => 'text' ],
				[ 'meta_key' => '_unknown_format', 'label' => 'Unknown', 'format' => 'datetime' ],
				[ 'meta_key' => '_ok', 'label' => 'Second of one key', 'format' => 'unix_date' ],
				[ 'meta_key' => '  _trimmed  ', 'label' => '  Spaced <b>label</b>  ', 'format' => 'unix_date' ],
				[ 'meta_key' => '_no_label', 'label' => '', 'format' => 'text' ],
				[ 'meta_key' => '_array_label', 'label' => [ 'x' ], 'format' => 'text' ],
				'not an entry',
			]
		);
		self::assertSame(
			[
				[ 'meta_key' => '_ok', 'label' => 'Kept', 'format' => 'text' ],
				[ 'meta_key' => '_trimmed', 'label' => 'Spaced label', 'format' => 'unix_date' ],
				[ 'meta_key' => '_no_label', 'label' => '_no_label', 'format' => 'text' ],
			],
			$list,
			'A missing label reads as the key; an unknown format, a bad key or a repeated key is dropped'
		);
	}

	public function test_the_list_holds_at_most_ten_entries_and_labels_at_most_one_hundred_characters(): void {
		$many = array_map( static fn( int $i ): array => [ 'meta_key' => '_k' . $i, 'label' => 'L' . $i, 'format' => 'text' ], range( 1, 12 ) );
		self::assertCount( OrderDetails::MAX_ENTRIES, OrderDetails::normalise( $many ) );
		self::assertSame( 10, OrderDetails::MAX_ENTRIES );
		$long = OrderDetails::normalise( [ [ 'meta_key' => '_k', 'label' => str_repeat( 'é', 150 ), 'format' => 'text' ] ] );
		self::assertSame( 100, mb_strlen( $long[0]['label'] ) );
	}

	public function test_the_stored_form_is_canonical_json_and_empty_is_empty(): void {
		self::assertSame( '', OrderDetails::encode( [] ) );
		self::assertSame( '', Partner::normalise_order_details( '[]' ) );
		self::assertSame( '', Partner::normalise_order_details( [] ) );
		self::assertSame( '', Partner::normalise_order_details( 5 ) );
		$json = Partner::normalise_order_details( [ [ 'meta_key' => 'Preferred delivery date', 'label' => 'Datum / Date', 'format' => 'text' ] ] );
		self::assertSame( '[{"meta_key":"Preferred delivery date","label":"Datum / Date","format":"text"}]', $json );
		self::assertSame( $json, Partner::normalise_order_details( $json ), 'A stored value normalises to itself' );
	}

	public function test_the_admin_lines_parse_into_entries(): void {
		$parsed = OrderDetails::parse_lines( "_delivery_timestamp | Preferred delivery date | unix_date\r\n\n  _attachment_name|Attachment|text  \n_note_key | Reference\n_bare_key\n" );
		self::assertSame( [], $parsed['rejected'] );
		self::assertFalse( $parsed['too_many'] );
		self::assertSame(
			[
				[ 'meta_key' => '_delivery_timestamp', 'label' => 'Preferred delivery date', 'format' => 'unix_date' ],
				[ 'meta_key' => '_attachment_name', 'label' => 'Attachment', 'format' => 'text' ],
				[ 'meta_key' => '_note_key', 'label' => 'Reference', 'format' => 'text' ],
				[ 'meta_key' => '_bare_key', 'label' => '_bare_key', 'format' => 'text' ],
			],
			$parsed['entries'],
			'The format defaults to text and the label to the key'
		);
		self::assertSame( "_delivery_timestamp | Preferred delivery date | unix_date\n_attachment_name | Attachment | text\n_note_key | Reference | text\n_bare_key | _bare_key | text", OrderDetails::lines( $parsed['entries'] ) );
		self::assertSame( $parsed['entries'], OrderDetails::parse_lines( OrderDetails::lines( $parsed['entries'] ) )['entries'], 'What the form shows reads back the same' );
	}

	public function test_a_line_the_admin_cannot_mean_is_refused_and_named(): void {
		$parsed = OrderDetails::parse_lines( "_ok | Fine | text\n_k | Label | date\n | No key | text\n_a | b | c | d\n_ok | Again | text" );
		self::assertSame( [ '_k | Label | date', '| No key | text', '_a | b | c | d', '_ok | Again | text' ], $parsed['rejected'] );
		$too_many = OrderDetails::parse_lines( implode( "\n", array_map( static fn( int $i ): string => '_k' . $i . ' | L' . $i, range( 1, 11 ) ) ) );
		self::assertTrue( $too_many['too_many'] );
		self::assertSame( [], $too_many['rejected'] );
		self::assertSame( [ 'entries' => [], 'rejected' => [], 'too_many' => false ], OrderDetails::parse_lines( " \n\t\n" ) );
	}
}
