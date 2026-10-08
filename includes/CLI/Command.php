<?php
/**
 * WP-CLI commands.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\CLI;

use POW\Partners\Secrets;
use POW\Plugin;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp punchout <command>` — operator plumbing that belongs on the shell:
 * key generation for wp-config, secret rotation with the show-once rule,
 * and an on-demand housekeeping run.
 */
final class Command {

	public function __construct( private Plugin $plugin ) {}

	public static function register( Plugin $plugin ): void {
		WP_CLI::add_command( 'punchout', new self( $plugin ) );
	}

	/**
	 * List customers.
	 *
	 * ## EXAMPLES
	 *
	 *     wp punchout partners
	 *
	 * @subcommand partners
	 */
	public function partners(): void {
		$registry = $this->plugin->registry();

		if ( null === $registry ) {
			WP_CLI::error( 'WooCommerce is not active.' );
		}

		$rows = [];

		foreach ( $registry->all() as $partner ) {
			$rows[] = [
				'id'       => $partner->id,
				'name'     => $partner->name,
				'status'   => $partner->status,
				'sender'   => $partner->sender_domain . '/' . $partner->sender_identity,
				'mode'     => $partner->mode,
				'cxml'     => $partner->cxml_version,
				'secret'   => '' !== $partner->secret_current ? 'set' : 'missing',
				'rotation' => '' !== $partner->secret_previous ? 'open' : '-',
			];
		}

		if ( [] === $rows ) {
			WP_CLI::log( 'No customers configured.' );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'name', 'status', 'sender', 'mode', 'cxml', 'secret', 'rotation' ] );
	}

	/**
	 * Rotate a partner's shared secret (dual-slot: the previous secret
	 * stays valid until close-rotation). The new secret prints ONCE.
	 *
	 * ## OPTIONS
	 *
	 * <partner-id>
	 * : The partner row id (see `wp punchout partners`).
	 *
	 * ## EXAMPLES
	 *
	 *     wp punchout rotate-secret 1
	 *
	 * @subcommand rotate-secret
	 *
	 * @param array<int, string> $args Positional args.
	 */
	public function rotate_secret( array $args ): void {
		$registry = $this->plugin->registry();

		if ( null === $registry ) {
			WP_CLI::error( 'WooCommerce is not active.' );
		}

		$partner_id = (int) ( $args[0] ?? 0 );
		$new_secret = $registry->rotate( $partner_id );

		if ( null === $new_secret ) {
			WP_CLI::error( "Rotation failed — no partner with id {$partner_id}?" );
		}

		$this->plugin->audit()?->write(
			'secret_rotated',
			[
				'partner_id' => $partner_id,
				'result'     => 'ok',
				'detail'     => [ 'via' => 'wp-cli' ],
			]
		);

		WP_CLI::success( 'Secret rotated. Shown once — copy it now:' );
		WP_CLI::log( $new_secret );
		WP_CLI::log( 'The previous secret stays valid until: wp punchout close-rotation ' . $partner_id );
	}

	/**
	 * Close a partner's rotation window (drop the previous secret).
	 *
	 * ## OPTIONS
	 *
	 * <partner-id>
	 * : The partner row id.
	 *
	 * @subcommand close-rotation
	 *
	 * @param array<int, string> $args Positional args.
	 */
	public function close_rotation( array $args ): void {
		$registry = $this->plugin->registry();

		if ( null === $registry ) {
			WP_CLI::error( 'WooCommerce is not active.' );
		}

		$partner_id = (int) ( $args[0] ?? 0 );

		if ( ! $registry->close_rotation( $partner_id ) ) {
			WP_CLI::error( "Close failed — no partner with id {$partner_id}?" );
		}

		$this->plugin->audit()?->write(
			'rotation_closed',
			[
				'partner_id' => $partner_id,
				'result'     => 'ok',
				'detail'     => [ 'via' => 'wp-cli' ],
			]
		);

		WP_CLI::success( 'Rotation window closed; only the current secret is accepted.' );
	}

	/**
	 * Generate sealing-key material for wp-config.php.
	 *
	 * ## EXAMPLES
	 *
	 *     wp punchout generate-key
	 *
	 * @subcommand generate-key
	 */
	public function generate_key(): void {
		WP_CLI::log( "define( 'POW_SECRET_KEY', '" . Secrets::generate_key() . "' );" );
		WP_CLI::log( '' );
		WP_CLI::warning( 'Add this to wp-config.php BEFORE storing partner secrets. Changing the key later invalidates every stored secret.' );
	}

	/**
	 * Copy a connection's enabled company-book addresses into its account's saved addresses (the site's
	 * address-book API) and carry their delivery codes over. Reruns skip addresses the account already holds.
	 *
	 * ## OPTIONS
	 *
	 * <connection-id>
	 * : The connection row id (see `wp punchout partners`).
	 *
	 * [--dry-run]
	 * : Show what would be copied without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp punchout migrate-addresses 1 --dry-run
	 *     wp punchout migrate-addresses 1
	 *
	 * @subcommand migrate-addresses
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function migrate_addresses( array $args, array $assoc_args = [] ): void {
		$registry = $this->plugin->registry();
		if ( null === $registry ) { WP_CLI::error( 'WooCommerce is not active.' ); }
		if ( ! \POW\Addresses\AccountBook::available() ) { WP_CLI::error( 'No address-book API on this site (a get_address_book( WC_Customer, type ) function): nothing to migrate into.' ); }
		$partner_id = (int) ( $args[0] ?? 0 );
		$partner = $registry->find( $partner_id );
		if ( ! $partner ) { WP_CLI::error( "No connection with id {$partner_id}." ); }
		if ( $partner->owner_user_id <= 0 ) { WP_CLI::error( 'That connection has no bound account.' ); }
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$audit = $this->plugin->audit(); $sessions = $this->plugin->sessions();
		if ( null === $audit || null === $sessions ) { WP_CLI::error( 'The plugin is not fully booted.' ); }
		$company = new \POW\Addresses\CompanyBook( $registry, $audit, new \POW\Sessions\Current( $sessions ) );
		$account = new \POW\Addresses\AccountBook( $registry );
		$report = $registry->with_partner_lock( $partner->id, function () use ( $registry, $partner, $company, $account, $dry_run ) {
			$fresh = $registry->find( $partner->id );
			if ( ! $fresh || $fresh->owner_user_id !== $partner->owner_user_id ) { return new \WP_Error( 'address_state_unavailable', 'The connection changed while migrating.' ); }
			$book = $company->read_for_partner_locked( $fresh );
			if ( $book instanceof \WP_Error ) { return $book; }
			return $account->migrate_locked( $fresh, $book['addresses'], $dry_run );
		} );
		if ( $report instanceof \WP_Error ) { WP_CLI::error( $report->get_error_message() ); }
		$rows = [];
		foreach ( $report['copied'] as $row ) { $rows[] = [ 'company_key' => $row['from'], 'account_key' => $row['to'], 'label' => $row['label'], 'code' => $row['code'], 'result' => $dry_run ? 'would copy' : 'copied' ]; }
		foreach ( $report['skipped'] as $row ) { $rows[] = [ 'company_key' => $row['from'], 'account_key' => $row['to'], 'label' => $row['label'], 'code' => '', 'result' => 'already there' ]; }
		if ( [] === $rows ) { WP_CLI::log( 'No enabled company-book entries to migrate.' ); return; }
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'company_key', 'account_key', 'label', 'code', 'result' ] );
		if ( ! $dry_run && [] !== $report['copied'] ) {
			$this->plugin->audit()?->write( 'address_book_migrated', [ 'partner_id' => $partner->id, 'user_id' => $partner->owner_user_id, 'result' => 'ok', 'detail' => [ 'via' => 'wp-cli', 'copied' => count( $report['copied'] ), 'skipped' => count( $report['skipped'] ), 'default' => $report['default'] ] ] );
			WP_CLI::success( sprintf( '%d address(es) copied into the account’s saved addresses%s; %d already there. Visits now choose from the account’s saved addresses.', count( $report['copied'] ), null !== $report['default'] ? ' (default: ' . $report['default'] . ')' : '', count( $report['skipped'] ) ) );
		} else {
			WP_CLI::success( sprintf( 'Dry run: %d to copy, %d already there.', count( $report['copied'] ), count( $report['skipped'] ) ) );
		}
	}

	/**
	 * Run housekeeping now (visit expiry, log trim, quote retention).
	 *
	 * @subcommand gc
	 */
	public function gc(): void {
		do_action( \POW\Cron::HOOK );
		WP_CLI::success( 'Housekeeping run complete.' );
	}
}
