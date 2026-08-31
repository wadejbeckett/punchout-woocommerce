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
	 * Run housekeeping now (session expiry, buyer deactivation, log trim).
	 *
	 * @subcommand gc
	 */
	public function gc(): void {
		do_action( \POW\Cron::HOOK );
		WP_CLI::success( 'Housekeeping run complete.' );
	}
}
