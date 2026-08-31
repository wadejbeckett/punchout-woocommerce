<?php
/**
 * Admin screen: WooCommerce > PunchOut.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Admin;

use POW\Audit\Log;
use POW\Partners\Partner;
use POW\Partners\Registry;
use POW\Settings;
use POW\SkuMap\SkuMap;

defined( 'ABSPATH' ) || exit;

/**
 * Four tabs, core UI only (no custom CSS framework):
 *
 * - Settings: WordPress Settings API over the single pow_settings option —
 *   master switch plus the handful of genuinely needed knobs.
 * - Partners: the trading-partner registry (write-only secrets).
 * - SKU map: per-partner CSV import + listing.
 * - Log: the audit trail, filtered and paged.
 *
 * All writes go through Admin\Actions (admin-post handlers, nonce +
 * capability checked); this class only renders and registers settings.
 */
final class Page {

	public const SLUG = 'punchout-woocommerce';
	public const CAP  = 'manage_woocommerce';

	/** Sentinel so the stored secret never round-trips through the form. */
	public const SECRET_MASK = '__pow_unchanged__';

	public function __construct(
		private Settings $settings,
		private Registry $registry,
		private SkuMap $sku_map,
		private Log $audit,
	) {}

	public function register(): void {
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
			'enabled'             => [ __( 'Enable punchout', 'punchout-woocommerce' ), 'checkbox', __( 'Master switch. Off = the /punchout/* endpoints and all buyer-facing surfaces are inert.', 'punchout-woocommerce' ) ],
			'landing_page_id'     => [ __( 'Landing page', 'punchout-woocommerce' ), 'page', __( 'Where buyers land after auto-login. Default: the shop page.', 'punchout-woocommerce' ) ],
			'token_ttl'           => [ __( 'Login link lifetime (s)', 'punchout-woocommerce' ), 'number', __( 'One-time StartPage token TTL. Default 300.', 'punchout-woocommerce' ) ],
			'session_ttl'         => [ __( 'Session lifetime (s)', 'punchout-woocommerce' ), 'number', __( 'Punchout login TTL. Default 14400 (4 h). Partners can override both TTLs per row.', 'punchout-woocommerce' ) ],
			'rate_limit_per_min'  => [ __( 'Setup rate limit / min', 'punchout-woocommerce' ), 'number', __( 'Requests per minute per partner+IP on /punchout/setup. 0 disables.', 'punchout-woocommerce' ) ],
			'log_retention_days'  => [ __( 'Log retention (days)', 'punchout-woocommerce' ), 'number', __( 'Audit rows older than this are trimmed by the hourly housekeeping job.', 'punchout-woocommerce' ) ],
			'buyer_inactive_days' => [ __( 'Buyer inactivity (days)', 'punchout-woocommerce' ), 'number', __( 'Buyers unseen this long are flagged inactive (never deleted).', 'punchout-woocommerce' ) ],
			'default_unspsc'      => [ __( 'Default UNSPSC code', 'punchout-woocommerce' ), 'text', __( 'Classification fallback for cart lines without a SKU-map row.', 'punchout-woocommerce' ) ],
		];

		foreach ( $fields as $key => [ $label, $type, $help ] ) {
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
		$input = is_array( $input ) ? $input : [];

		return [
			'enabled'             => ( isset( $input['enabled'] ) && 'yes' === $input['enabled'] ) ? 'yes' : 'no',
			'landing_page_id'     => max( 0, (int) ( $input['landing_page_id'] ?? 0 ) ),
			'token_ttl'           => max( 30, (int) ( $input['token_ttl'] ?? 300 ) ),
			'session_ttl'         => max( 300, (int) ( $input['session_ttl'] ?? 14400 ) ),
			'rate_limit_per_min'  => max( 0, (int) ( $input['rate_limit_per_min'] ?? 30 ) ),
			'log_retention_days'  => max( 1, (int) ( $input['log_retention_days'] ?? 400 ) ),
			'buyer_inactive_days' => max( 0, (int) ( $input['buyer_inactive_days'] ?? 90 ) ),
			'default_unspsc'      => sanitize_text_field( (string) ( $input['default_unspsc'] ?? '' ) ),
			'log_level'           => in_array( $input['log_level'] ?? '', [ 'debug', 'info', 'warning', 'error' ], true ) ? (string) $input['log_level'] : 'info',
		];
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	public function render(): void {
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
			case 'skumap':
				$this->render_skumap();
				break;
			case 'log':
				$this->render_log();
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
			'partners' => __( 'Trading partners', 'punchout-woocommerce' ),
			'skumap'   => __( 'SKU map', 'punchout-woocommerce' ),
			'log'      => __( 'Log', 'punchout-woocommerce' ),
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
	 * One-shot notices from Admin\Actions (new secret shown exactly once).
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

		if ( ! empty( $notice['secret'] ) ) {
			echo '<p><strong>' . esc_html__( 'Shared secret (shown once — copy it now):', 'punchout-woocommerce' ) . '</strong> <code>' . esc_html( (string) $notice['secret'] ) . '</code></p>';
		}

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
	 * Partners tab
	 * ------------------------------------------------------------------ */

	private function render_partners(): void {
		$action     = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$partner_id = isset( $_GET['partner'] ) ? absint( $_GET['partner'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'edit' === $action || 'new' === $action ) {
			$this->render_partner_form( $partner_id > 0 ? $this->registry->find( $partner_id ) : null );
			return;
		}

		printf(
			'<p><a href="%s" class="button button-primary">%s</a></p>',
			esc_url( $this->tab_url( 'partners', [ 'action' => 'new' ] ) ),
			esc_html__( 'Add trading partner', 'punchout-woocommerce' )
		);

		$partners = $this->registry->all();

		if ( [] === $partners ) {
			echo '<p>' . esc_html__( 'No trading partners configured yet.', 'punchout-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';

		foreach ( [ __( 'Name', 'punchout-woocommerce' ), __( 'Status', 'punchout-woocommerce' ), __( 'Sender identity', 'punchout-woocommerce' ), __( 'Mode', 'punchout-woocommerce' ), __( 'cXML', 'punchout-woocommerce' ), __( 'Secret', 'punchout-woocommerce' ), __( 'Actions', 'punchout-woocommerce' ) ] as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $partners as $partner ) {
			$rotate_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=pow_rotate_partner&partner=' . $partner->id ),
				'pow_rotate_' . $partner->id
			);
			$delete_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=pow_delete_partner&partner=' . $partner->id ),
				'pow_delete_' . $partner->id
			);

			$secret_state = '' !== $partner->secret_current
				? ( '' !== $partner->secret_previous
					? __( 'set (rotation window open)', 'punchout-woocommerce' )
					: __( 'set', 'punchout-woocommerce' ) )
				: __( 'not set', 'punchout-woocommerce' );

			echo '<tr>';
			echo '<td>' . esc_html( $partner->name ) . '</td>';
			echo '<td>' . esc_html( $partner->status ) . '</td>';
			echo '<td><code>' . esc_html( $partner->sender_domain . ' / ' . $partner->sender_identity ) . '</code></td>';
			echo '<td>' . esc_html( Partner::MODE_DUAL_EXIT === $partner->mode ? __( 'Dual exit', 'punchout-woocommerce' ) : __( 'Requisition only', 'punchout-woocommerce' ) ) . '</td>';
			echo '<td>' . esc_html( $partner->cxml_version ) . '</td>';
			echo '<td>' . esc_html( $secret_state ) . '</td>';
			echo '<td>';
			printf( '<a href="%s">%s</a> | ', esc_url( $this->tab_url( 'partners', [ 'action' => 'edit', 'partner' => $partner->id ] ) ), esc_html__( 'Edit', 'punchout-woocommerce' ) );
			printf( '<a href="%s">%s</a> | ', esc_url( $rotate_url ), esc_html__( 'Rotate secret', 'punchout-woocommerce' ) );

			if ( '' !== $partner->secret_previous ) {
				$close_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=pow_close_rotation&partner=' . $partner->id ),
					'pow_close_' . $partner->id
				);
				printf( '<a href="%s">%s</a> | ', esc_url( $close_url ), esc_html__( 'Close rotation', 'punchout-woocommerce' ) );
			}

			printf(
				'<a href="%s" onclick="return confirm(%s)">%s</a>',
				esc_url( $delete_url ),
				esc_attr( (string) wp_json_encode( __( 'Delete this trading partner? Sessions and log rows are kept.', 'punchout-woocommerce' ) ) ),
				esc_html__( 'Delete', 'punchout-woocommerce' )
			);
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Endpoint for all partners: POST /punchout/setup (raw cXML). Give each partner the setup URL, your To/From identities and their shared secret.', 'punchout-woocommerce' ) . '</p>';
	}

	private function render_partner_form( ?Partner $partner ): void {
		$is_new = null === $partner;

		echo '<h2>' . ( $is_new ? esc_html__( 'Add trading partner', 'punchout-woocommerce' ) : esc_html( $partner->name ) ) . '</h2>';
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
			$this->select( 'status', [ 'active' => __( 'Active', 'punchout-woocommerce' ), 'disabled' => __( 'Disabled', 'punchout-woocommerce' ) ], $partner->status ?? 'active' )
		);

		$this->form_row(
			__( 'Exit mode', 'punchout-woocommerce' ),
			$this->select(
				'mode',
				[
					Partner::MODE_REQUISITION_ONLY => __( 'Requisition only (RFQ exit; checkout blocked in punchout sessions)', 'punchout-woocommerce' ),
					Partner::MODE_DUAL_EXIT        => __( 'Dual exit (RFQ exit + normal checkout)', 'punchout-woocommerce' ),
				],
				$partner->mode ?? Partner::MODE_REQUISITION_ONLY
			)
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
				esc_html__( 'Uppercase every text field pushed back to this partner (SKU, descriptions, unit). The store catalogue is never modified.', 'punchout-woocommerce' )
			)
		);

		$cidrs = null !== $partner && null !== $partner->ip_allowlist ? implode( "\n", $partner->ip_cidrs() ) : '';

		$this->form_row(
			__( 'IP allowlist (CIDR, one per line)', 'punchout-woocommerce' ),
			sprintf( '<textarea name="ip_allowlist" rows="3" class="regular-text" placeholder="203.0.113.0/24">%s</textarea><p class="description">%s</p>', esc_textarea( $cidrs ), esc_html__( 'Optional. Empty = no IP restriction on /punchout/setup for this partner.', 'punchout-woocommerce' ) )
		);

		$this->form_row(
			__( 'B2BKing company account (user ID)', 'punchout-woocommerce' ),
			sprintf( '<input type="number" class="small-text" name="b2bking_company_user_id" value="%d" min="0" /><p class="description">%s</p>', (int) ( $partner->b2bking_company_user_id ?? 0 ), esc_html__( 'Optional. Buyers become B2BKing subaccounts of this company account.', 'punchout-woocommerce' ) )
		);

		$this->form_row(
			__( 'B2BKing customer group (ID)', 'punchout-woocommerce' ),
			sprintf( '<input type="number" class="small-text" name="b2bking_group_id" value="%d" min="0" /><p class="description">%s</p>', (int) ( $partner->b2bking_group_id ?? 0 ), esc_html__( 'Optional. Assigned via B2BKing\'s own helper so pricing and visibility follow the group.', 'punchout-woocommerce' ) )
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
		submit_button( $is_new ? __( 'Add partner', 'punchout-woocommerce' ) : __( 'Save partner', 'punchout-woocommerce' ) );
		echo '</form>';
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
	 * SKU map tab
	 * ------------------------------------------------------------------ */

	private function render_skumap(): void {
		$partners   = $this->registry->all();
		$partner_id = isset( $_GET['partner'] ) ? absint( $_GET['partner'] ) : ( $partners[0]->id ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( [] === $partners ) {
			echo '<p>' . esc_html__( 'Add a trading partner first.', 'punchout-woocommerce' ) . '</p>';
			return;
		}

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="tab" value="skumap" />';
		echo '<label>' . esc_html__( 'Partner:', 'punchout-woocommerce' ) . ' <select name="partner">';

		foreach ( $partners as $partner ) {
			printf( '<option value="%d"%s>%s</option>', (int) $partner->id, selected( $partner_id, $partner->id, false ), esc_html( $partner->name ) );
		}

		echo '</select></label> ';
		submit_button( __( 'Switch', 'punchout-woocommerce' ), 'secondary', '', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Import CSV', 'punchout-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Columns: sku, partner_sku, uom_code, unspsc, active(1/0). Header row optional. Existing rows are updated in place.', 'punchout-woocommerce' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="pow_import_skumap" />';
		printf( '<input type="hidden" name="partner" value="%d" />', $partner_id );
		wp_nonce_field( 'pow_import_skumap' );
		echo '<input type="file" name="pow_csv" accept=".csv,text/csv" required /> ';
		submit_button( __( 'Import', 'punchout-woocommerce' ), 'primary', 'submit', false );
		echo '</form>';

		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result = $this->sku_map->all( $partner_id, $paged );

		if ( [] === $result['rows'] ) {
			echo '<p>' . esc_html__( 'No SKU map rows for this partner yet. Unmapped SKUs fall back to the raw store SKU, unit EA and the default UNSPSC.', 'punchout-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';

		foreach ( [ 'SKU', __( 'Partner SKU', 'punchout-woocommerce' ), __( 'UOM', 'punchout-woocommerce' ), 'UNSPSC', __( 'Active', 'punchout-woocommerce' ) ] as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $result['rows'] as $row ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( (string) $row['sku'] ) . '</code></td>';
			echo '<td><code>' . esc_html( (string) ( $row['partner_sku'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) $row['uom_code'] ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['unspsc'] ?? '' ) ) . '</td>';
			echo '<td>' . ( ! empty( $row['active'] ) ? esc_html__( 'yes', 'punchout-woocommerce' ) : esc_html__( 'no', 'punchout-woocommerce' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$this->pagination( $result['total'], 100, $paged, 'skumap', [ 'partner' => $partner_id ] );
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
		echo '<label>' . esc_html__( 'Partner:', 'punchout-woocommerce' ) . ' <select name="partner"><option value="0">' . esc_html__( 'All', 'punchout-woocommerce' ) . '</option>';

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

		foreach ( [ __( 'Time (UTC)', 'punchout-woocommerce' ), __( 'Event', 'punchout-woocommerce' ), __( 'Dir', 'punchout-woocommerce' ), __( 'Partner', 'punchout-woocommerce' ), __( 'Session', 'punchout-woocommerce' ), __( 'Result', 'punchout-woocommerce' ), __( 'Detail', 'punchout-woocommerce' ) ] as $head ) {
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
