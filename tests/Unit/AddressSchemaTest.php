<?php
/** Partner delivery configuration contracts; SQL doubles do not prove native migration. @package POW */
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Partners\{Partner, Registry, Secrets};

/** Only the registry SQL boundary is doubled; validation and row hydration are real. */
final class AddressSchemaDatabase {
	public string $prefix = 'fixture_';
	public string $last_error = '';
	public int $insert_id = 12;
	public array $row = [];
	public bool $held = false;
	public bool $drop_write = false;
	private array $prepared = [];
	public function prepare( string $sql, mixed ...$args ): string {
		$key = $sql . ' /* ' . count( $this->prepared ) . ' */';
		$this->prepared[ $key ] = [ $sql, $args ]; return $key;
	}
	public function suppress_errors( bool $value = true ): bool { return false; }
	public function get_var( string $key ): string {
		[ $sql ] = $this->prepared[ $key ];
		if ( str_contains( $sql, 'RELEASE_LOCK(' ) ) { $this->held = false; return '1'; }
		if ( ! str_contains( $sql, 'GET_LOCK(' ) ) { throw new LogicException( 'Unexpected scalar SQL.' ); }
		$this->held = true; return '1';
	}
	public function get_row( string $key, string $format ): ?array {
		[ $sql, $args ] = $this->prepared[ $key ];
		if ( ! str_contains( $sql, 'WHERE id = %d' ) || [12] !== $args || ARRAY_A !== $format ) { throw new LogicException( 'Unexpected row SQL.' ); }
		return $this->row ?: null;
	}
	public function insert( string $table, array $data ): int {
		if ( 'fixture_pow_partners' !== $table ) { throw new LogicException( 'Unexpected table.' ); }
		$this->row = [ 'id' => 12 ] + $data; return 1;
	}
	public function update( string $table, array $data, array $where ): int {
		if ( ! $this->held || 'fixture_pow_partners' !== $table || [ 'id' => 12 ] !== $where ) { throw new LogicException( 'Unexpected update.' ); }
		if ( ! $this->drop_write ) { $this->row = array_replace( $this->row, $data ); }
		return 1;
	}
}

final class AddressSchemaTest extends TestCase {
	private mixed $saved_db;
	private bool $had_db;
	private AddressSchemaDatabase $db;
	private Registry $registry;
	protected function setUp(): void {
		$this->had_db = array_key_exists( 'wpdb', $GLOBALS ); $this->saved_db = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $this->db = new AddressSchemaDatabase();
		$this->registry = new Registry( new Secrets( str_repeat( 'k', 32 ) ) );
		$this->db->row = [ 'id' => 12, 'name' => 'Example', 'status' => 'active', 'mode' => 'dual_exit', 'owner_user_id' => 7, 'secret_current' => 'sealed-current', 'secret_previous' => 'sealed-previous', 'secret_rotated_at' => '2026-01-01 00:00:00', 'company_profile' => '{"book":"preserve"}' ];
	}
	protected function tearDown(): void {
		if ( $this->had_db ) { $GLOBALS['wpdb'] = $this->saved_db; } else { unset( $GLOBALS['wpdb'] ); }
	}
	private function defaults( Partner $p ): void {
		self::assertSame( '', $p->delivery_code_prefix );
		self::assertSame( 'DeliveryAddressCode', $p->delivery_code_extrinsic_name );
		foreach ( [ 'emit_ship_to', 'emit_delivery_code', 'emit_delivery_line' ] as $key ) { self::assertFalse( $p->$key ); }
		self::assertSame( 'require_rate', $p->delivery_unknown_policy ); self::assertSame( 'off', $p->delivery_notes_policy );
		self::assertSame( 'DELIVERY', $p->freight_supplier_part_id ); self::assertSame( 'EA', $p->freight_uom );
		self::assertSame( 'supplier', $p->freight_classification_domain ); self::assertSame( 'freight', $p->freight_classification );
	}
	public function test_legacy_rows_and_positional_calls_have_safe_defaults(): void {
		$this->defaults( Partner::from_row( $this->db->row ) );
		$p = new Partner( 12, 'Example', 'active', '', '', 'NetworkID', 'BUYER', '', '', 'old-current', 'old-previous', null, '1.2.008', 'test', 'base64', 'dual_exit', false, false, null, null, null, 14400, 300, 7 );
		$this->defaults( $p ); self::assertSame( 7, $p->owner_user_id ); self::assertSame( 'old-current', $p->secret_current );
	}
	private function config(): array {
		return [ 'delivery_code_prefix' => ' abc_1 ', 'delivery_code_extrinsic_name' => 'DeliveryAddressCode', 'emit_ship_to' => '1', 'emit_delivery_code' => true, 'emit_delivery_line' => 1, 'delivery_unknown_policy' => 'quote_separately', 'delivery_notes_policy' => 'item_detail_extrinsic', 'freight_supplier_part_id' => 'Shipping & handling', 'freight_uom' => 'EA', 'freight_classification_domain' => 'supplier', 'freight_classification' => 'Livraison' ];
	}
	public function test_configuration_updates_persist_and_hydrate_without_erasing_legacy_fields(): void {
		$before = $this->db->row;
		self::assertTrue( $this->registry->update( 12, $this->config() + [ 'unknown_column' => 'ignored', 'secret_current' => 'forged' ] ) );
		$p = $this->registry->find( 12 ); self::assertSame( 'ABC_1', $p->delivery_code_prefix );
		foreach ( $this->config() as $key => $value ) {
			if ( 'delivery_code_prefix' === $key ) { continue; }
			self::assertSame( str_starts_with( $key, 'emit_' ) ? true : $value, $p->$key );
		}
		foreach ( $before as $key => $value ) { self::assertSame( $value, $this->db->row[ $key ] ); }
		self::assertFalse( isset( $this->db->row['unknown_column'] ) ); self::assertFalse( $this->db->held );
	}
	public function test_insert_keeps_new_fields_and_seals_only_explicit_secret(): void {
		self::assertSame( 12, $this->registry->insert( $this->config() + [ 'name' => 'Example', 'sender_domain' => 'NetworkID', 'sender_identity' => 'BUYER', 'secret_current' => 'forged' ], 'explicit-secret' ) );
		$p = $this->registry->find( 12 ); self::assertSame( 'ABC_1', $p->delivery_code_prefix ); self::assertTrue( $p->emit_delivery_line );
		self::assertSame( Secrets::SLOT_CURRENT, $this->registry->verify_secret( $p, 'explicit-secret' ) );
	}
	public function test_ordinary_partial_save_preserves_previously_configured_delivery_fields(): void {
		self::assertTrue( $this->registry->update( 12, $this->config() ) );
		self::assertTrue( $this->registry->update( 12, [ 'name' => 'Renamed' ] ) );
		self::assertSame( 'quote_separately', $this->registry->find( 12 )->delivery_unknown_policy );
		self::assertTrue( $this->registry->find( 12 )->emit_delivery_line );
	}
	public function test_only_explicit_boolean_one_enables_wire_on_write_and_hydration(): void {
		foreach ( [ true, 1, '1', false, 0, '0', 'true', 'yes', 'on', 'false', 'garbage', 2, -1, 1.0, [], [1], new stdClass(), null ] as $value ) {
			$want = in_array( $value, [ true, 1, '1' ], true );
			foreach ( [ 'emit_ship_to', 'emit_delivery_code', 'emit_delivery_line' ] as $key ) {
				self::assertTrue( $this->registry->update( 12, [ $key => $value ] ) );
				self::assertSame( $want, $this->registry->find( 12 )->$key );
				self::assertSame( $want, Partner::from_row( [ $key => $value ] )->$key );
			}
		}
	}
	public function test_unknown_and_malformed_policies_default_closed_on_write_and_hydration(): void {
		foreach ( [ 'invalid', '', 'QUOTE_SEPARATELY', true, 1, [], new stdClass(), null ] as $value ) {
			$config = [ 'delivery_unknown_policy' => $value, 'delivery_notes_policy' => $value ];
			self::assertTrue( $this->registry->update( 12, $config ) );
			foreach ( [ $this->registry->find( 12 ), Partner::from_row( $config ) ] as $p ) {
				self::assertSame( 'require_rate', $p->delivery_unknown_policy ); self::assertSame( 'off', $p->delivery_notes_policy );
			}
		}
	}
	public function test_prefix_uses_existing_ascii_code_rules_and_refuses_invalid_input_atomically(): void {
		foreach ( [ str_repeat( 'A', 25 ), 'é', "A\n", '<xml>', [], 12, true, null ] as $value ) {
			$before = $this->db->row;
			self::assertFalse( $this->registry->update( 12, [ 'name' => 'Must not save', 'delivery_code_prefix' => $value ] ) );
			self::assertSame( $before, $this->db->row );
		}
		self::assertTrue( $this->registry->update( 12, [ 'delivery_code_prefix' => str_repeat( 'a', 24 ) ] ) );
		self::assertSame( str_repeat( 'A', 24 ), $this->registry->find( 12 )->delivery_code_prefix );
		self::assertTrue( $this->registry->update( 12, [ 'delivery_code_prefix' => '' ] ) );
		self::assertSame( '', $this->registry->find( 12 )->delivery_code_prefix );
	}
	public function test_text_limits_count_unicode_characters_and_reject_non_scalar_or_invalid_text(): void {
		foreach ( [ 'delivery_code_extrinsic_name' => 64, 'freight_supplier_part_id' => 190, 'freight_uom' => 8, 'freight_classification_domain' => 64, 'freight_classification' => 64 ] as $key => $limit ) {
			$valid = str_repeat( 'é', $limit );
			self::assertTrue( $this->registry->update( 12, [ $key => $valid ] ) ); self::assertSame( $valid, $this->registry->find( 12 )->$key );
			foreach ( [ $valid . 'é', [], new stdClass(), 12, true, null, '', '   ', "bad\0", "bad\n", "bad\x7f", "\xff", "\u{FFFE}" ] as $invalid ) {
				$before = $this->db->row;
				self::assertFalse( $this->registry->update( 12, [ $key => $invalid ] ) ); self::assertSame( $before, $this->db->row );
			}
		}
	}
	public function test_malformed_persisted_text_is_refused_without_disclosing_the_value(): void {
		foreach ( [ 'delivery_code_prefix' => '<private-input>', 'freight_uom' => 'TOO-LONG-PRIVATE' ] as $key => $value ) {
			try { Partner::from_row( [ $key => $value ] ); }
			catch ( InvalidArgumentException $e ) { self::assertStringNotContainsString( $value, $e->getMessage() ); continue; }
			self::fail( 'Malformed persisted configuration must refuse hydration.' );
		}
	}
	public function test_unconfirmed_config_write_is_not_reported_successful(): void {
		$this->db->drop_write = true;
		self::assertFalse( $this->registry->update( 12, $this->config() ) );
	}
}
