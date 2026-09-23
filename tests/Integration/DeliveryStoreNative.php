<?php
/**
 * Opt-in native confirmation compare-and-swap acceptance. POW_DELIVERY_STORE_FIXTURE identifies a private AddressProviderNative fixture; only new visit rows and login tokens belonging to its invented bound account are written. No chooser/payment/return integration is claimed. Caller holds the existing partner mutex for every store operation.
 *
 * Every row this suite writes is a VISIT of one customer account, so each one carries its own login token and its own per-visit `wc_session_key` alongside the buyer identity its request named. Those four columns are attribution and addressing, never consent: no delivery write may touch them, and a colleague's visit of the same account may not write this visit's consent.
 * @package POW
 * @license AGPL-3.0-or-later
 */
declare( strict_types = 1 );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || wp_get_environment_type() !== 'local' || ! current_user_can( 'manage_woocommerce' ) ) { throw new RuntimeException( 'Disposable native fixture administrator required.' ); }

require_once dirname( __DIR__ ) . '/Support/native-visits.php';

final class DeliveryStoreNative {
	private POW\Sessions\Store $store;
	private POW\Partners\Registry $registry;
	private array $fixture;
	private int $passed = 0;
	public function __construct() { $this->store = POW\Plugin::instance()->sessions(); $this->registry = POW\Plugin::instance()->registry(); $this->fixture = json_decode( file_get_contents( getenv( 'POW_DELIVERY_STORE_FIXTURE' ) ), true, 512, JSON_THROW_ON_ERROR ); }
	private function check( bool $ok, string $label ): void { if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $label ); } ++$this->passed; echo 'PASS ' . $label . "\n"; }
	/** One live visit of the connection's bound account, with its own login token, cart key and buyer attribution. */
	private function session(): POW\Sessions\Session {
		return pow_native_open_visit(
			(int) $this->fixture['id'],
			$this->account(),
			[ 'payload_id' => 'delivery-store-' . bin2hex( random_bytes( 10 ) ), 'buyer_identity' => 'store-' . bin2hex( random_bytes( 6 ) ) . '@example.invalid', 'buyer_name' => 'Store fixture buyer' ]
		);
	}
	private function account(): int { return (int) ( $this->fixture['account'] ?? 0 ); }
	/** The attribution and addressing columns a delivery write must never touch. @return array<string,mixed> */
	private function attribution( POW\Sessions\Session $s ): array {
		$row = $this->independent( $s );
		return [ 'wc_session_key' => $row->wc_session_key, 'buyer_identity' => $row->buyer_identity, 'buyer_name' => $row->buyer_name, 'buyer_identity_hash' => $row->buyer_identity_hash, 'wp_session_token' => $row->wp_session_token, 'user_id' => $row->user_id ];
	}
	private function confirmation( POW\Sessions\Session $s, ?array $choice = null, string $notes = 'Approved delivery notes' ): array {
		return [ 'schema' => 1, 'session_id' => $s->id, 'buyer_user_id' => $s->user_id, 'choice_hash' => POW\Addresses\DeliveryData::fingerprint( $choice ), 'cart_fingerprint' => str_repeat( 'a', 64 ), 'policy_fingerprint' => str_repeat( 'b', 64 ), 'notes' => $notes, 'confirmed_at' => time(), 'delivery' => [ 'status' => null === $choice ? 'not_required' : 'unknown', 'amount_cents' => null, 'currency' => 'ZAR', 'code' => $choice['code'] ?? '', 'emit' => false, 'rates' => [], 'freight' => [ 'supplier_part_id' => 'DELIVERY', 'uom' => 'EA', 'classification_domain' => 'supplier', 'classification' => 'freight' ] ] ];
	}
	private function save( POW\Sessions\Session $s, array $choice, array $confirmation, ?string $expected_choice = null, ?string $expected_confirmation = null, ?int $user = null, ?string $token = null ): bool {
		return $this->registry->with_partner_lock( $s->partner_id, fn() => $this->store->save_delivery( $s->id, $user ?? $s->user_id, $token ?? $s->wp_session_token, $expected_choice, $expected_confirmation, $choice, $confirmation ) );
	}
	/** Bypass the tested Store readback and request caches using another native connection. */
	private function independent( POW\Sessions\Session $s ): POW\Sessions\Session {
		global $wpdb;
		$db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$db->suppress_errors( true );
		try {
			if ( $db->get_var( 'SELECT CONNECTION_ID()' ) === $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ) { throw new RuntimeException( 'Independent connection required.' ); }
			$row = $db->get_row( $db->prepare( 'SELECT * FROM ' . POW\Installer::sessions_table() . ' WHERE id = %d AND partner_id = %d', $s->id, $this->fixture['id'] ), ARRAY_A );
			if ( '' !== $db->last_error || ! $row || (int) $row['user_id'] !== $this->account() ) { throw new RuntimeException( 'Independent fixture read failed.' ); }
			return POW\Sessions\Session::from_row( $row );
		} finally { $db->close(); }
	}
	private function invalidate( POW\Sessions\Session $s ): bool {
		return $this->registry->with_partner_lock( $s->partner_id, fn() => $this->store->invalidate_delivery( $s->id, $s->user_id, $s->wp_session_token ) );
	}
	/** Install faults only inside the owned lock; capture SQL errors before RELEASE_LOCK clears them. */
	private function fault( POW\Sessions\Session $s, callable $filter, callable $operation ): array {
		return $this->registry->with_partner_lock( $s->partner_id, function () use ( $filter, $operation ): array {
			global $wpdb;
			add_filter( 'query', $filter, 99 );
			try { $ok = $operation(); return [ 'ok' => $ok, 'error' => $wpdb->last_error ]; }
			finally { remove_filter( 'query', $filter, 99 ); }
		} );
	}
	private function fault_cases( array $choice ): void {
		global $wpdb;
		$table = POW\Installer::sessions_table();
		foreach ( [ 'initial_select', 'update', 'readback', 'final_token_read' ] as $stage ) {
			$s = $this->session(); $c = $this->confirmation( $s, $choice ); $reads = 0; $updates = 0; $hits = 0;
			$filter = static function ( string $sql ) use ( $stage, $s, $table, &$reads, &$updates, &$hits, $wpdb ): string {
				$is_read = str_starts_with( $sql, 'SELECT * FROM ' . $table . ' WHERE id = ' . $s->id );
				$is_update = str_starts_with( $sql, 'UPDATE ' . $table . ' SET delivery_choice' );
				if ( $is_read ) { ++$reads; }
				if ( $is_update ) { ++$updates; }
				$token_read = str_starts_with( $sql, 'SELECT user_id, meta_key, meta_value FROM ' . $wpdb->usermeta . ' WHERE user_id IN (' . $s->user_id . ')' );
				$inject = ( 'initial_select' === $stage && $is_read && 1 === $reads ) || ( 'update' === $stage && $is_update ) || ( 'readback' === $stage && $is_read && 2 === $reads ) || ( 'final_token_read' === $stage && $token_read && 1 === $updates );
				if ( ! $inject ) { return $sql; }
				++$hits;
				return ( 'update' === $stage ? 'UPDATE ' . $table . ' SET pow_store_fault_missing_column = 1' : 'SELECT pow_store_fault_missing_column FROM ' . $table ) . ' WHERE id = ' . $s->id;
			};
			$result = $this->fault( $s, $filter, fn() => $this->store->save_delivery( $s->id, $s->user_id, $s->wp_session_token, null, null, $choice, $c ) );
			$this->check( false === $result['ok'] && 1 === $hits && str_contains( $result['error'], 'pow_store_fault_missing_column' ), $stage . ': actual SQL error returns false' );
			echo 'EXPECTED_SQL_ERROR ' . $stage . ': ' . $result['error'] . "\n";
			$fresh = $this->independent( $s );
			if ( in_array( $stage, [ 'initial_select', 'update' ], true ) ) {
				$this->check( null === $fresh->delivery_choice_json && null === $fresh->delivery_confirmation_json, $stage . ': independent read confirms neither column was written' );
			} else {
				$this->check( $choice === $fresh->delivery_choice() && $c === $fresh->delivery_confirmation(), $stage . ': false acknowledges no rollback of the committed complete pair' );
				$this->check( $this->invalidate( $s ), $stage . ': checked recovery invalidates unacknowledged consent' );
				$fresh = $this->independent( $s );
				$this->check( $choice === $fresh->delivery_choice() && null === $fresh->delivery_confirmation_json, $stage . ': independent recovery read preserves choice and removes consent' );
			}
		}
		$s = $this->session(); $c = $this->confirmation( $s, $choice ); $committed = false; $revoked = false;
		$filter = static function ( string $sql ) use ( $s, $table, &$committed, &$revoked ): string {
			if ( str_starts_with( $sql, 'UPDATE ' . $table . ' SET delivery_choice' ) ) { $committed = true; }
			if ( $committed && ! $revoked && str_starts_with( $sql, 'SELECT * FROM ' . $table . ' WHERE id = ' . $s->id ) ) {
				$revoked = true; WP_Session_Tokens::get_instance( $s->user_id )->destroy( $s->wp_session_token );
			}
			return $sql;
		};
		$r = $this->fault( $s, $filter, fn() => $this->store->save_delivery( $s->id, $s->user_id, $s->wp_session_token, null, null, $choice, $c ) );
		$this->check( false === $r['ok'] && $revoked && '' === $r['error'] && $c === $this->independent( $s )->delivery_confirmation(), 'native token revoked after commit refuses acknowledgement without rolling back the pair' );
		$this->check( $this->invalidate( $s ) && null === $this->independent( $s )->delivery_confirmation_json && $choice === $this->independent( $s )->delivery_choice(), 'revoked native token permits exact ACTIVE-row recovery with choice preserved' );
	}
	/** Deliberate raw writes after the preliminary read exercise the SQL predicates, not PHP's precheck. */
	private function byte_fences( array $choice ): void {
		global $wpdb;
		$table = POW\Installer::sessions_table();
		foreach ( [ 'choice_case', 'confirmation_case', 'confirmation_space', 'choice_null', 'confirmation_null', 'login_case' ] as $kind ) {
			$s = $this->session(); $prior_c = null; $prior_choice = null;
			if ( ! in_array( $kind, [ 'choice_null', 'confirmation_null' ], true ) ) {
				$seed = $this->confirmation( $s, $choice, 'Fence Alpha' );
				if ( ! $this->save( $s, $choice, $seed ) ) { throw new RuntimeException( 'Byte-fence fixture failed.' ); }
				$seeded = $this->independent( $s ); $prior_choice = $seeded->delivery_choice_json; $prior_c = $seeded->delivery_confirmation_json;
			}
			$column = match ( $kind ) { 'choice_case', 'choice_null' => 'delivery_choice', 'login_case' => 'wp_session_token', default => 'delivery_confirmation' };
			$mutated = match ( $kind ) {
				'choice_case' => str_replace( 'customer', 'CUSTOMER', $prior_choice ),
				'confirmation_case' => str_replace( 'Fence Alpha', 'fence alpha', $prior_c ),
				'confirmation_space' => $prior_c . ' ',
				'login_case' => strtolower( $s->wp_session_token ) === $s->wp_session_token ? strtoupper( $s->wp_session_token ) : strtolower( $s->wp_session_token ),
				default => '',
			};
			$hits = 0; $equivalent = false;
			$filter = static function ( string $sql ) use ( $s, $column, $mutated, $table, &$hits, &$equivalent, $wpdb ): string {
				if ( $hits || ! str_starts_with( $sql, 'UPDATE ' . $table . ' SET delivery_choice' ) ) { return $sql; }
				++$hits;
				$db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $db->suppress_errors( true );
				try {
					// Record native nonbinary equivalence before replacing the old value; no secret value is printed.
					$equivalent = '1' === (string) $db->get_var( $db->prepare( 'SELECT ' . $column . ' = %s FROM ' . $table . ' WHERE id = %d', $mutated, $s->id ) );
					if ( 1 !== $db->update( $table, [ $column => $mutated ], [ 'id' => $s->id, 'partner_id' => $s->partner_id ] ) || '' !== $db->last_error ) { throw new RuntimeException( 'Byte-fence independent mutation failed.' ); }
				} finally { $db->close(); }
				return $sql;
			};
			$c = $this->confirmation( $s, $choice, 'New rejected confirmation' );
			$r = $this->fault( $s, $filter, fn() => $this->store->save_delivery( $s->id, $s->user_id, $s->wp_session_token, $prior_choice, $prior_c, $choice, $c ) );
			$fresh = $this->independent( $s );
			$actual = match ( $column ) { 'delivery_choice' => $fresh->delivery_choice_json, 'wp_session_token' => $fresh->wp_session_token, default => $fresh->delivery_confirmation_json };
			$this->check( 1 === $hits && false === $r['ok'] && '' === $r['error'] && $actual === $mutated, $kind . ': SQL fence refuses after preliminary read and retains independent mutation' );
			$other_unchanged = match ( $column ) {
				'delivery_choice' => $fresh->delivery_confirmation_json === $prior_c,
				'delivery_confirmation' => $fresh->delivery_choice_json === $prior_choice,
				default => $fresh->delivery_choice_json === $prior_choice && $fresh->delivery_confirmation_json === $prior_c,
			};
			$this->check( $other_unchanged, $kind . ': rejected UPDATE does not partially replace the other column' );
			if ( ! str_ends_with( $kind, '_null' ) ) { $this->check( $equivalent, $kind . ': native collation would equate the distinct bytes without BINARY' ); }
		}
	}
	private function recovery_faults( array $choice ): void {
		$table = POW\Installer::sessions_table();
		foreach ( [ 'initial_select', 'update', 'readback', 'confirmation_cas' ] as $stage ) {
			$s = $this->session(); $c = $this->confirmation( $s, $choice, 'Recovery Alpha' );
			if ( ! $this->save( $s, $choice, $c ) ) { throw new RuntimeException( 'Invalidation fixture failed.' ); }
			$before = $this->independent( $s ); $reads = 0; $hits = 0; $other = str_replace( 'Recovery Alpha', 'recovery alpha', $before->delivery_confirmation_json );
			$filter = static function ( string $sql ) use ( $stage, $s, $table, $other, &$reads, &$hits ): string {
				$is_read = str_starts_with( $sql, 'SELECT * FROM ' . $table . ' WHERE id = ' . $s->id );
				$is_update = str_starts_with( $sql, 'UPDATE ' . $table . ' SET delivery_confirmation = NULL' );
				if ( $is_read ) { ++$reads; }
				if ( 'confirmation_cas' === $stage && $is_update && ! $hits ) {
					++$hits; $db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
					try { if ( 1 !== $db->update( $table, [ 'delivery_confirmation' => $other ], [ 'id' => $s->id, 'partner_id' => $s->partner_id ] ) ) { throw new RuntimeException( 'Recovery CAS mutation failed.' ); } }
					finally { $db->close(); }
					return $sql;
				}
				if ( ( 'initial_select' === $stage && $is_read && 1 === $reads ) || ( 'update' === $stage && $is_update ) || ( 'readback' === $stage && $is_read && 2 === $reads ) ) {
					++$hits;
					return ( 'update' === $stage ? 'UPDATE ' . $table . ' SET pow_store_fault_missing_column = 1' : 'SELECT pow_store_fault_missing_column FROM ' . $table ) . ' WHERE id = ' . $s->id;
				}
				return $sql;
			};
			$r = $this->fault( $s, $filter, fn() => $this->store->invalidate_delivery( $s->id, $s->user_id, $s->wp_session_token ) );
			$fresh = $this->independent( $s );
			$expected = 'readback' === $stage ? null : ( 'confirmation_cas' === $stage ? $other : $before->delivery_confirmation_json );
			$this->check( 1 === $hits && false === $r['ok'] && $fresh->delivery_confirmation_json === $expected && $fresh->delivery_choice_json === $before->delivery_choice_json, 'invalidate ' . $stage . ': false plus independent exact state is truthful' );
			$this->check( 'confirmation_cas' === $stage ? '' === $r['error'] : str_contains( $r['error'], 'pow_store_fault_missing_column' ), 'invalidate ' . $stage . ': intended SQL failure or contested predicate reached' );
			if ( 'confirmation_cas' !== $stage ) { echo 'EXPECTED_SQL_ERROR invalidate ' . $stage . ': ' . $r['error'] . "\n"; }
		}
		foreach ( [ POW\Sessions\Session::RETURNED, POW\Sessions\Session::CLOSED, POW\Sessions\Session::ORDERED, POW\Sessions\Session::EXPIRED ] as $status ) {
			$s = $this->session(); $c = $this->confirmation( $s, $choice );
			if ( ! $this->save( $s, $choice, $c ) || ! $this->store->update( $s->id, [ 'status' => $status ] ) ) { throw new RuntimeException( 'History fixture failed.' ); }
			$this->check( ! $this->invalidate( $s ) && $c === $this->independent( $s )->delivery_confirmation() && $choice === $this->independent( $s )->delivery_choice(), $status . ': invalidation preserves complete non-ACTIVE history' );
		}
	}
	public function run(): void {
		global $wpdb;
		$this->check( method_exists( $this->store, 'save_delivery' ), 'native Store::save_delivery implementation exists' );
		$s = $this->session(); $confirmation = $this->confirmation( $s );
		$this->check( $this->save( $s, [], $confirmation ), 'virtual confirmation saves with genuine SQL NULL destination' );
		$fresh = $this->store->find( $s->id );
		$this->check( null === $fresh->delivery_choice_json && $confirmation === $fresh->delivery_confirmation(), 'both persisted columns decode as the exact accepted values' );
		$this->check( ! $this->save( $s, [], $this->confirmation( $s, null, 'Stale second request' ) ), 'stale NULL confirmation cannot overwrite the winner' );
		$this->check( $this->save( $s, [], $this->confirmation( $s, null, 'Updated notes' ), null, $fresh->delivery_confirmation_json ), 'known raw previous confirmation can be replaced' );
		$attribution = $this->attribution( $s );
		$this->check(
			POW\Cart\SessionKey::is_visit_key( (string) $attribution['wc_session_key'] ) && $this->account() === $attribution['user_id']
			&& '' !== (string) $attribution['buyer_identity'] && 64 === strlen( (string) $attribution['buyer_identity_hash'] ),
			'a visit row carries its own cart key and the buyer identity its request named'
		);
		$this->check( $this->save( $s, [], $this->confirmation( $s, null, 'Attribution check' ), null, $this->independent( $s )->delivery_confirmation_json ), 'a further consent write succeeds on the same visit' );
		$this->check( $attribution === $this->attribution( $s ), 'no delivery write touches the visit cart key, login token or buyer attribution' );
		// A colleague of the same account is a different visit, and consent belongs to the visit.
		$sibling = $this->session();
		$this->check(
			$sibling->id !== $s->id && ! hash_equals( (string) $sibling->wc_session_key, (string) $s->wc_session_key ) && $sibling->wp_session_token !== $s->wp_session_token,
			'a second visit of one bound account is a distinct row with its own key and token'
		);
		$this->check(
			! $this->save( $s, [], $this->confirmation( $s, null, 'Colleague notes' ), null, $this->independent( $s )->delivery_confirmation_json, null, $sibling->wp_session_token )
			&& null === $this->independent( $sibling )->delivery_confirmation_json,
			'a colleague visit of the same account cannot write this visit consent'
		);
		$s = $this->session(); $choice = $this->fixture['choice']; $choice['provider'] = 'customer'; $choice['source'] = 'customer'; $choice['key'] = 'customer'; $choice['book_revision'] = null; $choice['entry_fingerprint'] = null;
		$confirmation = $this->confirmation( $s, $choice );
		$this->check( $this->save( $s, $choice, $confirmation ), 'full physical choice and acknowledgement save in one native UPDATE' );
		$fresh = $this->store->find( $s->id );
		$this->check( $choice === $fresh->delivery_choice() && $confirmation === $fresh->delivery_confirmation(), 'physical snapshot readback retains Unicode and exact schema' );
		$this->check( ! $this->save( $s, $choice, $confirmation, '', $fresh->delivery_confirmation_json ), 'SQL empty string never substitutes for NULL or another raw choice' );
		$this->check( ! $this->save( $s, $choice, $confirmation, $fresh->delivery_choice_json, $fresh->delivery_confirmation_json, (int) $this->fixture['foreign_account'] ), 'another account cannot write this visit' );
		$this->check( ! $this->save( $s, $choice, $confirmation, $fresh->delivery_choice_json, $fresh->delivery_confirmation_json, null, 'wrong-token' ), 'different login token cannot write this session' );
		$invalid = $confirmation; $invalid['choice_hash'] = str_repeat( 'c', 64 );
		$this->check( ! $this->save( $s, $choice, $invalid, $fresh->delivery_choice_json, $fresh->delivery_confirmation_json ), 'mismatched confirmation cannot be persisted' );
		$oversized = $confirmation; $oversized['notes'] = str_repeat( 'x', 60001 );
		$this->check( ! $this->save( $s, $choice, $oversized, $fresh->delivery_choice_json, $fresh->delivery_confirmation_json ), 'oversized snapshots refuse before native TEXT truncation' );
		$this->check( $fresh->delivery_choice_json === $this->store->find( $s->id )->delivery_choice_json && $fresh->delivery_confirmation_json === $this->store->find( $s->id )->delivery_confirmation_json, 'all refusals preserve both previously accepted columns' );
		WP_Session_Tokens::get_instance( $s->user_id )->destroy( $s->wp_session_token );
		$this->check( ! $this->save( $s, $choice, $confirmation, $fresh->delivery_choice_json, $fresh->delivery_confirmation_json ), 'revoked native WordPress login cannot reconfirm' );
		foreach ( [ 'returned', 'expired' ] as $state ) {
			$s = $this->session(); $this->store->update( $s->id, [ 'status' => $state ] );
			$this->check( ! $this->save( $s, [], $this->confirmation( $s ) ), 'terminal ' . $state . ' session cannot confirm' );
		}
		$s = $this->session(); $this->store->update( $s->id, [ 'expires' => '2000-01-01 00:00:00' ] );
		$this->check( ! $this->save( $s, [], $this->confirmation( $s ) ), 'elapsed ACTIVE session cannot confirm' );
		$s = $this->session(); $filter = static fn( string $sql ): string => str_starts_with( $sql, 'UPDATE ' . POW\Installer::sessions_table() ) && str_contains( $sql, 'delivery_confirmation' ) ? 'SELECT 0 WHERE 1=0' : $sql;
		add_filter( 'query', $filter ); try { $this->check( ! $this->save( $s, [], $this->confirmation( $s ) ), 'zero affected-row acknowledgement never invents a confirmation' ); } finally { remove_filter( 'query', $filter ); }
		$this->check( null === $this->store->find( $s->id )->delivery_confirmation_json, 'failed conditional UPDATE leaves no usable half-confirmation' );
		$this->check( method_exists( $this->store, 'invalidate_delivery' ), 'checked confirmation invalidation exists' );
		$s = $this->session(); $c = $this->confirmation( $s, $choice );
		$this->check( $this->save( $s, $choice, $c ), 'recovery fixture has a complete accepted pair' );
		$invalidate = fn( $user, $token ) => $this->registry->with_partner_lock( $s->partner_id, fn() => $this->store->invalidate_delivery( $s->id, $user, $token ) );
		$this->check( ! $invalidate( (int) $this->fixture['foreign_account'], $s->wp_session_token ) && ! $invalidate( $s->user_id, 'wrong-token' ), 'another login cannot invalidate this visit confirmation' );
		$this->check( $invalidate( $s->user_id, $s->wp_session_token ), 'invalidation clears the exact active login confirmation' );
		$fresh = $this->store->find( $s->id );
		$this->check( null === $fresh->delivery_confirmation_json && $fresh->delivery_choice() === $choice, 'invalidation retains the chosen destination and clears only consent' );
		$this->check( $invalidate( $s->user_id, $s->wp_session_token ), 'already invalidated confirmation is a checked no-op' );
		$this->check( $this->save( $fresh, $choice, $c, $fresh->delivery_choice_json, null ), 'buyer may explicitly reconfirm the retained destination' );
		$this->store->update( $s->id, [ 'status' => 'returned' ] );
		$this->check( ! $invalidate( $s->user_id, $s->wp_session_token ) && $this->store->find( $s->id )->delivery_confirmation() === $c, 'invalidation never overwrites a completed historical confirmation' );
		$s = $this->session(); $c = $this->confirmation( $s, $choice );
		if ( ! $this->save( $s, $choice, $c ) ) { throw new RuntimeException( 'No-op fixture failed.' ); }
		$before = $this->independent( $s ); $updates = 0;
		$watch = static function ( string $sql ) use ( &$updates ): string { if ( str_starts_with( $sql, 'UPDATE ' . POW\Installer::sessions_table() . ' SET delivery_' ) ) { ++$updates; } return $sql; };
		add_filter( 'query', $watch );
		try { $ok = $this->save( $s, $choice, $c, $before->delivery_choice_json, $before->delivery_confirmation_json ); }
		finally { remove_filter( 'query', $watch ); }
		$this->check( $ok && 0 === $updates && $this->independent( $s )->delivery_confirmation_json === $before->delivery_confirmation_json, 'exact checked no-op succeeds without an UPDATE or byte changes' );
		$virtual = $this->confirmation( $s );
		$this->check( $this->save( $s, [], $virtual, $before->delivery_choice_json, $before->delivery_confirmation_json ) && null === $this->independent( $s )->delivery_choice_json && $virtual === $this->independent( $s )->delivery_confirmation(), 'physical to virtual replacement writes genuine NULL and its bound confirmation' );
		$this->fault_cases( $choice );
		$this->byte_fences( $choice );
		$this->recovery_faults( $choice );
		echo "Native delivery store: {$this->passed} passed, 0 failed, 0 skipped\n";
	}
}
( new DeliveryStoreNative() )->run();
