<?php
/**
 * Admin screen: WooCommerce > PunchOut.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Admin;

use POW\Support\Transport;

use POW\Addresses\Fields;
use POW\Checkout\ExitPolicy;
use POW\Audit\Log;
use POW\Http\RateLimiter;
use POW\Partners\Partner;
use POW\Partners\Registry;
use POW\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Four tabs, core UI only (no custom CSS framework):
 *
 * - Settings: WordPress Settings API over the single pow_settings option —
 *   master switch plus the handful of genuinely needed knobs.
 * - Customers: the customer-connection registry (write-only secrets).
 * - Log: the audit trail, filtered and paged.
 * - Integration docs: the buyer-facing documentation page, rendered
 *   privileged — the same page [punchout_docs] publishes, plus the
 *   per-connection block and a self-test that names the exact
 *   authentication stage rather than collapsing it.
 *
 * Connection writes go through Admin\Actions (admin-post handlers, nonce +
 * capability checked); delivery forms use the injected Fields same-page handler.
 * This class only renders and registers settings.
 */
final class Page {

	public const SLUG = 'punchout-woocommerce';
	public const CAP  = 'manage_woocommerce';

	/** Sentinel so the stored secret never round-trips through the form. */
	public const SECRET_MASK = '__pow_unchanged__';

	public function __construct(
		private Settings $settings,
		private Registry $registry,
		private Log $audit,
		private ?Fields $addresses = null,
	) {}

	public function register(): void {
		add_filter( 'option_page_capability_pow_settings_group', static fn() => self::CAP );
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'PunchOut', 'punchout-woocommerce' ),
			__( 'PunchOut', 'punchout-woocommerce' ),
			self::CAP,
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/* ---------------------------------------------------------------------
	 * Settings API
	 * ------------------------------------------------------------------ */

	public function register_settings(): void {
		register_setting(
			'pow_settings_group',
			Settings::OPTION_KEY,
			[ 'sanitize_callback' => [ $this, 'sanitize_settings' ] ]
		);

		add_settings_section(
			'pow_main',
			__( 'Punchout', 'punchout-woocommerce' ),
			'__return_null',
			self::SLUG
		);

		$fields = [
			'enabled'              => [ __( 'Enable punchout', 'punchout-woocommerce' ), 'checkbox', __( 'Master switch. Off = the /punchout/* endpoints and all buyer-facing surfaces are inert.', 'punchout-woocommerce' ) ],
			'landing_page_id'      => [ __( 'Landing page', 'punchout-woocommerce' ), 'page', __( 'Where buyers land after auto-login. Default: the shop page.', 'punchout-woocommerce' ) ],
			'return_button_label'  => [ __( 'Punchout button label', 'punchout-woocommerce' ), 'text', __( 'Text on the button that sends the cart back to the buyer\'s purchasing system. Blank uses the default: “Punchout”.', 'punchout-woocommerce' ) ],
			'abandon_button_label' => [ __( 'Cancel button label', 'punchout-woocommerce' ), 'text', __( 'Text on the control that ends the session with no items. Blank uses the default: “Return without a cart”.', 'punchout-woocommerce' ) ],
			'token_ttl'            => [ __( 'Login link lifetime (s)', 'punchout-woocommerce' ), 'number', __( 'One-time StartPage token TTL. Default 300.', 'punchout-woocommerce' ) ],
			'session_ttl'          => [ __( 'Session lifetime (s)', 'punchout-woocommerce' ), 'number', __( 'Punchout login TTL. Default 14400 (4 h). Each customer connection can override both TTLs.', 'punchout-woocommerce' ) ],
			'rate_limit_per_min'   => [ __( 'Setup rate limit / min', 'punchout-woocommerce' ), 'number', __( 'Requests per minute per customer+IP on /punchout/setup. 0 uses the default (30); the public self-test uses 10 when this is 0.', 'punchout-woocommerce' ) ],
			'edge_rate_limit_per_min' => [ __( 'Setup edge limit / min', 'punchout-woocommerce' ), 'number', __( 'Requests per minute per IP on /punchout/setup, counted before the sender is resolved. 0 uses the default (120).', 'punchout-woocommerce' ) ],
			'log_retention_days'   => [ __( 'Log retention (days)', 'punchout-woocommerce' ), 'number', __( 'Audit rows older than this are trimmed by the hourly housekeeping job.', 'punchout-woocommerce' ) ],
			'buyer_inactive_days'  => [ __( 'Buyer inactivity (days)', 'punchout-woocommerce' ), 'number', __( 'Buyers unseen this long are flagged inactive (never deleted).', 'punchout-woocommerce' ) ],
			'default_unspsc'       => [ __( 'Default UNSPSC code', 'punchout-woocommerce' ), 'text', __( 'UNSPSC commodity classification stamped on every returned cart line; procurement systems use it to route requisition lines to a purchasing category. Agree the value with the buyer.', 'punchout-woocommerce' ) ],
			'quote_convert_status' => [
				__( 'Convert quotes to', 'punchout-woocommerce' ),
				'select',
				__( 'Status a Punchout Quote order moves to when you convert it from the order screen.', 'punchout-woocommerce' ),
				[
					'pending'    => __( 'Pending payment', 'punchout-woocommerce' ),
					'processing' => __( 'Processing', 'punchout-woocommerce' ),
					'on-hold'    => __( 'On hold', 'punchout-woocommerce' ),
				],
			],
			'quote_retention_days' => [ __( 'Quote retention (days)', 'punchout-woocommerce' ), 'number', __( 'Punchout Quote orders not converted within this many days are cancelled by the housekeeping job (never deleted). 0 keeps them for ever.', 'punchout-woocommerce' ) ],
		];

		foreach ( $fields as $key => $field ) {
			[ $label, $type, $help ] = $field;

			add_settings_field(
				'pow_' . $key,
				$label,
				[ $this, 'render_field' ],
				self::SLUG,
				'pow_main',
				[
					'key'       => $key,
					'type'      => $type,
					'help'      => $help,
					'options'   => $field[3] ?? [],
					'label_for' => 'pow_' . $key,
				]
			);
		}
	}

	/**
	 * @param array<string, mixed> $args Field args.
	 */
	public function render_field( array $args ): void {
		$key   = (string) $args['key'];
		$value = $this->settings->get( $key );
		$name  = Settings::OPTION_KEY . '[' . $key . ']';
		$id    = 'pow_' . $key;

		switch ( $args['type'] ) {
			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%s" name="%s" value="yes" %s /> %s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( 'yes', (string) $value, false ),
					esc_html__( 'Enabled', 'punchout-woocommerce' )
				);
				break;

			case 'page':
				wp_dropdown_pages(
					[
						'name'              => $name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by wp_dropdown_pages.
						'id'                => $id, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						'selected'          => (int) $value,
						'show_option_none'  => esc_html__( 'Shop page (default)', 'punchout-woocommerce' ),
						'option_none_value' => '0',
					]
				);
				break;

			case 'select':
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );

				foreach ( (array) ( $args['options'] ?? [] ) as $option => $option_label ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( (string) $option ),
						selected( (string) $option, (string) $value, false ),
						esc_html( (string) $option_label )
					);
				}

				echo '</select>';
				break;

			case 'number':
				printf(
					'<input type="number" class="small-text" id="%s" name="%s" value="%s" min="0" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) (int) $value )
				);
				break;

			default:
				printf(
					'<input type="text" class="regular-text" id="%s" name="%s" value="%s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
		}

		if ( '' !== (string) $args['help'] ) {
			printf( '<p class="description">%s</p>', esc_html( (string) $args['help'] ) );
		}
	}

	/**
	 * @param mixed $input Raw option input.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ): array {
		Transport::require_https();
		$input = is_array( $input ) ? $input : [];

		return [
			'enabled'              => ( isset( $input['enabled'] ) && 'yes' === $input['enabled'] ) ? 'yes' : 'no',
			'landing_page_id'      => max( 0, (int) ( $input['landing_page_id'] ?? 0 ) ),
			'return_button_label'  => sanitize_text_field( (string) ( $input['return_button_label'] ?? '' ) ),
			'abandon_button_label' => sanitize_text_field( (string) ( $input['abandon_button_label'] ?? '' ) ),
			'token_ttl'            => max( 30, (int) ( $input['token_ttl'] ?? 300 ) ),
			'session_ttl'          => max( 300, (int) ( $input['session_ttl'] ?? 14400 ) ),
			'rate_limit_per_min'   => max( 0, (int) ( $input['rate_limit_per_min'] ?? 30 ) ),
			'edge_rate_limit_per_min' => max( 0, (int) ( $input['edge_rate_limit_per_min'] ?? 120 ) ),
			'log_retention_days'   => max( 1, (int) ( $input['log_retention_days'] ?? 400 ) ),
			'buyer_inactive_days'  => max( 0, (int) ( $input['buyer_inactive_days'] ?? 90 ) ),
			'default_unspsc'       => sanitize_text_field( (string) ( $input['default_unspsc'] ?? '' ) ),
			// A quote converts into a status an operator can still act on;
			// anything else (completed, refunded, another custom status)
			// falls back to pending rather than skipping the shop's own
			// fulfilment path.
			'quote_convert_status' => in_array( $input['quote_convert_status'] ?? '', \POW\Orders\QuoteOrder::CONVERT_STATUSES, true ) ? (string) $input['quote_convert_status'] : 'pending',
			'quote_retention_days' => max( 0, (int) ( $input['quote_retention_days'] ?? 90 ) ),
			'log_level'            => in_array( $input['log_level'] ?? '', [ 'debug', 'info', 'warning', 'error' ], true ) ? (string) $input['log_level'] : 'info',
		];
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	public function render(): void {
		Transport::require_https();
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'punchout-woocommerce' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab routing only.

		echo '<div class="wrap"><h1>' . esc_html__( 'PunchOut for WooCommerce', 'punchout-woocommerce' ) . '</h1>';

		$this->render_notices();
		$this->render_tabs( $tab );

		switch ( $tab ) {
			case 'partners':
				$this->render_partners();
				break;
			case 'log':
				$this->render_log();
				break;
			case 'docs':
				$this->render_docs();
				break;
			default:
				$this->render_settings();
		}

		echo '</div>';
	}

	private function tab_url( string $tab, array $extra = [] ): string {
		return add_query_arg(
			array_merge(
				[
					'page' => self::SLUG,
					'tab'  => $tab,
				],
				$extra
			),
			admin_url( 'admin.php' )
		);
	}

	private function render_tabs( string $active ): void {
		$tabs = [
			'settings' => __( 'Settings', 'punchout-woocommerce' ),
			'partners' => __( 'Customers', 'punchout-woocommerce' ),
			'log'      => __( 'Log', 'punchout-woocommerce' ),
			'docs'     => __( 'Integration docs', 'punchout-woocommerce' ),
		];

		echo '<nav class="nav-tab-wrapper">';

		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( $this->tab_url( $slug ) ),
				$slug === $active ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * One-shot nonsecret notices from Admin\Actions.
	 */
	private function render_notices(): void {
		$key    = 'pow_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( $key );

		$class = 'error' === ( $notice['type'] ?? '' ) ? 'notice-error' : 'notice-success';

		echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( (string) ( $notice['text'] ?? '' ) ) . '</p>';

		echo '</div>';
	}

	private function render_settings(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( 'pow_settings_group' );
		do_settings_sections( self::SLUG );
		submit_button();
		echo '</form>';
	}

	/* ---------------------------------------------------------------------
	 * Customers tab
	 * ------------------------------------------------------------------ */

	private function render_partners(): void {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$partner_id = isset( $_GET['partner'] ) ? absint( $_GET['partner'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'edit' === $action || 'new' === $action ) {
			$this->render_partner_form( $partner_id > 0 ? $this->registry->find( $partner_id ) : null );
			return;
		}
		printf( '<p><a href="%s" class="button button-primary">%s</a></p>', esc_url( $this->tab_url( 'partners', [ 'action' => 'new' ] ) ), esc_html__( 'Add customer', 'punchout-woocommerce' ) );
		$pending = $this->registry->pending();
		echo '<h2>' . esc_html__( 'Pending requests', 'punchout-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Edit the company identity and entitlements, save them, then approve explicitly. Approval credentials are handed over out of band.', 'punchout-woocommerce' ) . '</p>';
		$this->render_partner_table( $pending );
		echo '<h2>' . esc_html__( 'Configured connections', 'punchout-woocommerce' ) . '</h2>';
		$this->render_partner_table( array_values( array_filter( $this->registry->all(), static fn( Partner $p ): bool => ! $p->is_pending() ) ) );
		echo '<p class="description">' . esc_html__( 'Endpoint for all customers: POST /punchout/setup (raw cXML). Give each customer the setup URL, your To/From identities and their shared secret.', 'punchout-woocommerce' ) . '</p>';
	}

	/** @param list<Partner> $partners */
	private function render_partner_table( array $partners ): void {
		if ( [] === $partners ) {
			echo '<p>' . esc_html__( 'No connections in this section.', 'punchout-woocommerce' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( [ __( 'Name', 'punchout-woocommerce' ), __( 'Status', 'punchout-woocommerce' ), __( 'Sender identity', 'punchout-woocommerce' ), __( 'Mode', 'punchout-woocommerce' ), __( 'cXML', 'punchout-woocommerce' ), __( 'Secret', 'punchout-woocommerce' ), __( 'Actions', 'punchout-woocommerce' ) ] as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $partners as $partner ) {
			$secret_state = '' === $partner->secret_current ? __( 'not set', 'punchout-woocommerce' ) : ( '' !== $partner->secret_previous ? __( 'set (rotation window open)', 'punchout-woocommerce' ) : __( 'set', 'punchout-woocommerce' ) );
			echo '<tr><td>' . esc_html( $partner->name ) . '</td><td>' . esc_html( $partner->status ) . '</td><td><code>' . esc_html( $partner->sender_domain . ' / ' . $partner->sender_identity ) . '</code></td>';
			echo '<td>' . esc_html( ExitPolicy::labels()[ $partner->exit_policy ] ) . '</td><td>' . esc_html( $partner->cxml_version ) . '</td><td>' . esc_html( $secret_state ) . '</td><td>';
			printf( '<p><a href="%s">%s</a></p>', esc_url( $this->tab_url( 'partners', [ 'action' => 'edit', 'partner' => $partner->id ] ) ), esc_html__( 'Edit', 'punchout-woocommerce' ) );
			if ( $partner->is_pending() ) {
				$this->action_form( $partner->id, 'approve_partner', 'approve', __( 'Approve company', 'punchout-woocommerce' ) );
			} elseif ( $partner->is_active() ) {
				if ( '' === $partner->secret_previous ) {
					$this->action_form( $partner->id, 'rotate_partner', 'rotate', __( 'Rotate secret', 'punchout-woocommerce' ) );
				} else {
					$this->action_form( $partner->id, 'close_rotation', 'close', __( 'Close rotation', 'punchout-woocommerce' ) );
				}
			}
			$this->action_form( $partner->id, 'delete_partner', 'delete', __( 'Delete connection', 'punchout-woocommerce' ), __( 'Delete this customer connection? Sessions and log rows are kept.', 'punchout-woocommerce' ) );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Only existing provisioned members appear; this screen cannot create employees. */
	private function render_buyer_restrictions( Partner $partner ): void {
		echo '<h3>' . esc_html__( 'Buyer restrictions', 'punchout-woocommerce' ) . '</h3><p>' . esc_html__( 'Existing company buyers are recognised automatically. Restrictions are managed by shop administrators and cannot exceed the company permission.', 'punchout-woocommerce' ) . '</p>';
		if ( ! $partner->is_active() ) { return; }
		$policy = new ExitPolicy( $this->settings, $this->registry );
		$cap = ExitPolicy::resolve( $this->settings->exit_policy(), $partner->exit_policy, ExitPolicy::INHERIT );
		try {
			foreach ( get_users( [ 'role' => \POW\Installer::ROLE, 'meta_key' => '_pow_partner_id', 'meta_value' => (string) $partner->id, 'orderby' => 'ID' ] ) as $buyer ) {
				if ( ! $policy->member( $partner->id, (int) $buyer->ID ) ) { continue; }
				$value = $policy->buyer_value( $partner->id, (int) $buyer->ID );
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pow_save_buyer_exit" /><input type="hidden" name="partner" value="' . esc_attr( (string) $partner->id ) . '" /><input type="hidden" name="buyer" value="' . esc_attr( (string) $buyer->ID ) . '" />';
				wp_nonce_field( 'pow_buyer_exit_' . $partner->id );
				echo '<p><label>' . esc_html( $buyer->display_name . ' (#' . $buyer->ID . ')' ) . ' <select name="exit_policy">';
				foreach ( ExitPolicy::labels() as $option => $label ) {
					$disabled = ExitPolicy::CHECKOUT === $option && ExitPolicy::CHECKOUT !== $cap ? ' disabled' : '';
					echo '<option value="' . esc_attr( $option ) . '"' . selected( $value, $option, false ) . $disabled . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select></label> <button class="button" type="submit">' . esc_html__( 'Save buyer restriction', 'punchout-woocommerce' ) . '</button></p></form>';
			}
		} catch ( \Throwable $e ) { echo '<p>' . esc_html__( 'Buyer restrictions could not be loaded. Reload before making changes.', 'punchout-woocommerce' ) . '</p>'; }
	}

	private function action_form( int $partner_id, string $action, string $nonce, string $label, string $confirm = '' ): void {
		$confirmation = '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( (string) wp_json_encode( $confirm ) ) . ')"' : '';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $confirmation . '>';
		echo '<input type="hidden" name="action" value="pow_' . esc_attr( $action ) . '" />';
		printf( '<input type="hidden" name="partner" value="%d" />', $partner_id );
		wp_nonce_field( 'pow_' . $nonce . '_' . $partner_id );
		echo '<p><button type="submit" class="button">' . esc_html( $label ) . '</button></p></form>';
	}

	private function render_partner_form( ?Partner $partner ): void {
		$is_new = null === $partner;
		$is_pending = null !== $partner && $partner->is_pending();

		echo '<h2>' . ( $is_new ? esc_html__( 'Add customer', 'punchout-woocommerce' ) : esc_html( $partner->name ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="pow_save_partner" />';
		printf( '<input type="hidden" name="partner" value="%d" />', $is_new ? 0 : (int) $partner->id );
		wp_nonce_field( 'pow_save_partner' );

		echo '<table class="form-table" role="presentation">';

		$this->form_row(
			__( 'Name', 'punchout-woocommerce' ),
			sprintf( '<input type="text" class="regular-text" name="name" value="%s" required />', esc_attr( $partner->name ?? '' ) )
		);

		$this->form_row(
			__( 'Status', 'punchout-woocommerce' ),
			$is_pending ? esc_html__( 'Pending — save configuration, then approve explicitly.', 'punchout-woocommerce' ) : ( null !== $partner && ! $partner->is_active() ? esc_html__( 'Disabled — use Reset connection to recover after checking its configuration.', 'punchout-woocommerce' ) : $this->select( 'status', [ 'active' => __( 'Active', 'punchout-woocommerce' ), 'disabled' => __( 'Disabled', 'punchout-woocommerce' ) ], $partner->status ?? 'active' ) )
		);

		$this->form_row(
			__( 'Checkout access', 'punchout-woocommerce' ),
			'<input type="hidden" name="exit_policy" value="' . esc_attr( ExitPolicy::CHECKOUT ) . '" /><label><input type="checkbox" name="exit_policy" value="' . esc_attr( ExitPolicy::ONLY ) . '" ' . checked( ExitPolicy::ONLY, $partner->exit_policy ?? ExitPolicy::CHECKOUT, false ) . ' /> ' . esc_html__( 'PunchOut only', 'punchout-woocommerce' ) . '</label><p class="description">' . esc_html__( 'Normal checkout is available during a valid PunchOut session when this setting is off. Buyer restrictions may narrow this company permission.', 'punchout-woocommerce' ) . '</p>'
		);

		$identity_help = '<p class="description">' . esc_html__( 'The Sender credential is the authentication key: it must match what the buyer\'s system sends. From = the buyer; To = this store, as they address it.', 'punchout-woocommerce' ) . '</p>';

		$this->form_row(
			__( 'Sender domain / identity', 'punchout-woocommerce' ),
			sprintf(
				'<input type="text" name="sender_domain" placeholder="NetworkID" value="%s" required /> <input type="text" name="sender_identity" placeholder="buyer-sender-id" value="%s" required />%s',
				esc_attr( $partner->sender_domain ?? '' ),
				esc_attr( $partner->sender_identity ?? '' ),
				$identity_help
			)
		);

		$this->form_row(
			__( 'From domain / identity', 'punchout-woocommerce' ),
			sprintf(
				'<input type="text" name="from_domain" placeholder="DUNS" value="%s" /> <input type="text" name="from_identity" placeholder="buyer-id" value="%s" />',
				esc_attr( $partner->from_domain ?? '' ),
				esc_attr( $partner->from_identity ?? '' )
			)
		);

		$this->form_row(
			__( 'To domain / identity (us)', 'punchout-woocommerce' ),
			sprintf(
				'<input type="text" name="to_domain" placeholder="DUNS" value="%s" /> <input type="text" name="to_identity" placeholder="our-id" value="%s" />',
				esc_attr( $partner->to_domain ?? '' ),
				esc_attr( $partner->to_identity ?? '' )
			)
		);

		if ( $is_new || $partner->is_active() ) {
			$secret_value = ( ! $is_new && '' !== $partner->secret_current ) ? self::SECRET_MASK : '';

			$this->form_row(
				__( 'Shared secret', 'punchout-woocommerce' ),
				sprintf(
					'<input type="password" class="regular-text" name="secret" value="%s" autocomplete="new-password" /> <label><input type="checkbox" name="generate_secret" value="1" /> %s</label><p class="description">%s</p>',
					esc_attr( $secret_value ),
					esc_html__( 'Generate a strong secret for me (shown once after saving)', 'punchout-woocommerce' ),
					esc_html__( 'Write-only: the stored secret is never displayed. Leave unchanged to keep it.', 'punchout-woocommerce' )
				)
			);

		}

		$this->form_row(
			__( 'cXML version to emit', 'punchout-woocommerce' ),
			sprintf( '<input type="text" class="small-text" name="cxml_version" value="%s" /> <span class="description">%s</span>', esc_attr( $partner->cxml_version ?? '1.2.008' ), esc_html__( 'Dynamics 365 defaults to 1.2.008.', 'punchout-woocommerce' ) )
		);

		$this->form_row(
			__( 'Deployment mode', 'punchout-woocommerce' ),
			$this->select( 'deployment_mode', [ 'test' => 'test', 'production' => 'production' ], $partner->deployment_mode ?? 'test' )
		);

		$this->form_row(
			__( 'Cart return encoding', 'punchout-woocommerce' ),
			$this->select( 'return_encoding', [ 'base64' => 'cxml-base64', 'urlencoded' => 'cxml-urlencoded' ], $partner->return_encoding ?? 'base64' )
		);

		$this->form_row(
			__( 'ALL CAPS outbound data', 'punchout-woocommerce' ),
			sprintf(
				'<label><input type="checkbox" name="allcaps_transform" value="1" %s /> %s</label>',
				checked( true, (bool) ( $partner->allcaps_transform ?? false ), false ),
				esc_html__( 'Uppercase every text field pushed back to this customer (SKU, descriptions, unit). The store catalogue is never modified.', 'punchout-woocommerce' )
			)
		);

		$cidrs = null !== $partner && null !== $partner->ip_allowlist ? implode( "\n", $partner->ip_cidrs() ) : '';

		$this->form_row(
			__( 'IP allowlist (CIDR, one per line)', 'punchout-woocommerce' ),
			sprintf( '<textarea name="ip_allowlist" rows="3" class="regular-text" placeholder="203.0.113.0/24">%s</textarea><p class="description">%s</p>', esc_textarea( $cidrs ), esc_html__( 'Optional. Empty = no IP restriction on /punchout/setup for this customer.', 'punchout-woocommerce' ) )
		);

		$this->form_row(
			__( 'Token TTL / session TTL (s)', 'punchout-woocommerce' ),
			sprintf(
				'<input type="number" class="small-text" name="token_ttl" value="%d" min="30" /> / <input type="number" class="small-text" name="session_ttl" value="%d" min="300" />',
				(int) ( $partner->token_ttl ?? 300 ),
				(int) ( $partner->session_ttl ?? 14400 )
			)
		);

		echo '</table>';
		submit_button( $is_new ? __( 'Add customer', 'punchout-woocommerce' ) : __( 'Save customer', 'punchout-woocommerce' ) );
		echo '</form>';

		if ( null !== $partner ) {
			$this->render_buyer_restrictions( $partner );
			if ( $is_pending ) {
				$this->action_form( $partner->id, 'approve_partner', 'approve', __( 'Approve company', 'punchout-woocommerce' ) );
			} else {
				echo '<p>' . esc_html__( 'Save identity changes first. Reset immediately revokes both credentials and recorded sessions before issuing a replacement. The company owner and book stay associated.', 'punchout-woocommerce' ) . '</p>';
				$this->action_form( $partner->id, 'reset_partner', 'reset', __( 'Reset connection', 'punchout-woocommerce' ), __( 'Revoke current credentials and sessions, then reset this connection?', 'punchout-woocommerce' ) );
			}
			echo '<h2>' . esc_html__( 'Company management account', 'punchout-woocommerce' ) . '</h2>';
			if ( 0 === $partner->owner_user_id ) {
				echo '<p>' . esc_html__( 'Explicitly select an ordinary WordPress account by user ID. It must own no other connection. This does not copy addresses or sign employees in as the company owner.', 'punchout-woocommerce' ) . '</p>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pow_associate_partner" />';
				printf( '<input type="hidden" name="partner" value="%d" />', $partner->id );
				wp_nonce_field( 'pow_associate_' . $partner->id );
				echo '<p><label>' . esc_html__( 'Owner user ID', 'punchout-woocommerce' ) . ' <input type="number" name="owner_user_id" min="1" required /></label></p>';
				submit_button( __( 'Associate company account', 'punchout-woocommerce' ) );
				echo '</form>';
			} else {
				echo '<p>' . esc_html__( 'Associated owner user ID:', 'punchout-woocommerce' ) . ' ' . esc_html( (string) $partner->owner_user_id ) . '. ' . esc_html__( 'This association cannot be transferred or cleared; the company book stays with its owner.', 'punchout-woocommerce' ) . '</p>';
				// This fragment contains independent same-page POST forms; keep it outside identity, credential and buyer-policy forms.
				echo $this->addresses?->markup( $partner->id ) ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- authorized, escaped Fields producer.
			}
		}
	}

	private function form_row( string $label, string $control_html ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $control_html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- control HTML escaped at build sites above.
	}

	/**
	 * @param array<string, string> $options value => label.
	 */
	private function select( string $name, array $options, string $selected ): string {
		$html = '<select name="' . esc_attr( $name ) . '">';

		foreach ( $options as $value => $label ) {
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $label )
			);
		}

		return $html . '</select>';
	}

	/* ---------------------------------------------------------------------
	 * Integration docs tab
	 * ------------------------------------------------------------------ */

	/**
	 * The buyer-facing documentation page, rendered privileged: the
	 * per-connection block appears and the self-test names the exact
	 * authentication stage instead of collapsing the three into one.
	 */
	private function render_docs(): void {
		echo '<p class="description">';
		printf(
			/* translators: %s: the shortcode that publishes the page. */
			esc_html__( 'This is what buyers see. Publish it by creating a page and adding the shortcode %s to it, then send buyers that page\'s URL. The page renders while punchout is switched off, which is when new buyers usually read it.', 'punchout-woocommerce' ),
			'<code>[punchout_docs]</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal markup.
		);
		echo '</p>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped within templates/docs/.
		echo ( new \POW\Docs\Page(
			$this->settings,
			$this->registry,
			new RateLimiter( \POW\Docs\Page::self_test_limit( $this->settings->int( 'rate_limit_per_min' ) ) ),
			$this->audit
		) )->render( true );
	}

	/* ---------------------------------------------------------------------
	 * Log tab
	 * ------------------------------------------------------------------ */

	private function render_log(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$partner_id = isset( $_GET['partner'] ) ? absint( $_GET['partner'] ) : 0;
		$event      = isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '';
		$paged      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="tab" value="log" />';
		echo '<label>' . esc_html__( 'Customer:', 'punchout-woocommerce' ) . ' <select name="partner"><option value="0">' . esc_html__( 'All', 'punchout-woocommerce' ) . '</option>';

		foreach ( $this->registry->all() as $partner ) {
			printf( '<option value="%d"%s>%s</option>', (int) $partner->id, selected( $partner_id, $partner->id, false ), esc_html( $partner->name ) );
		}

		echo '</select></label> ';
		printf(
			'<label>%s <input type="text" name="event" value="%s" placeholder="setup_ok" /></label> ',
			esc_html__( 'Event:', 'punchout-woocommerce' ),
			esc_attr( $event )
		);
		submit_button( __( 'Filter', 'punchout-woocommerce' ), 'secondary', '', false );
		echo '</form>';

		$filters = array_filter(
			[
				'partner_id' => $partner_id,
				'event'      => $event,
			]
		);

		$result = $this->audit->query( $filters, $paged );

		if ( [] === $result['rows'] ) {
			echo '<p>' . esc_html__( 'No log entries.', 'punchout-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';

		foreach ( [ __( 'Time (UTC)', 'punchout-woocommerce' ), __( 'Event', 'punchout-woocommerce' ), __( 'Dir', 'punchout-woocommerce' ), __( 'Customer', 'punchout-woocommerce' ), __( 'Session', 'punchout-woocommerce' ), __( 'Result', 'punchout-woocommerce' ), __( 'Detail', 'punchout-woocommerce' ) ] as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $result['rows'] as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['ts'] ) . '</td>';
			echo '<td><code>' . esc_html( (string) $row['event'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $row['direction'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['partner_id'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['session_id'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['result'] ) . '</td>';
			echo '<td>';

			if ( ! empty( $row['detail'] ) ) {
				echo '<code>' . esc_html( (string) $row['detail'] ) . '</code>';
			}

			if ( ! empty( $row['xml'] ) ) {
				echo '<details><summary>' . esc_html__( 'XML', 'punchout-woocommerce' ) . '</summary><pre style="white-space:pre-wrap;max-width:60em;overflow:auto">' . esc_html( (string) $row['xml'] ) . '</pre></details>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		$this->pagination( $result['total'], 50, $paged, 'log', array_filter( [ 'partner' => $partner_id, 'event' => $event ] ) );
	}

	private function pagination( int $total, int $per_page, int $paged, string $tab, array $extra = [] ): void {
		$pages = (int) ceil( $total / $per_page );

		if ( $pages < 2 ) {
			return;
		}

		echo '<p>';

		for ( $i = 1; $i <= $pages; $i++ ) {
			if ( $i === $paged ) {
				echo '<strong>' . esc_html( (string) $i ) . '</strong> ';
				continue;
			}

			printf(
				'<a href="%s">%d</a> ',
				esc_url( $this->tab_url( $tab, array_merge( $extra, [ 'paged' => $i ] ) ) ),
				(int) $i
			);
		}

		echo '</p>';
	}
}
