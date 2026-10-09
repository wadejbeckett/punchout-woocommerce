<?php
/**
 * The state page: where a visit ends, inside the theme (0.5.0).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http;

use POW\Settings;
use POW\Support\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * One shortcode, [punchout_state], on a page the site chooses in the settings ("State page"), draws how a visit
 * ended: Sent, Not sent, Link expired or Session ended (and Sending, which the plain page that posts the cart also
 * carries). The browser is sent there once its cart has gone and when its link has expired or its session has ended,
 * so those states draw inside the theme instead of on a plain page.
 *
 * - **Stable classes.** `pow-state` plus one modifier (`pow-state--sending`, `--sent`, `--not-sent`, `--expired`,
 *   `--ended`), and the children `pow-state__title`, `pow-state__text`, `pow-state__actions`, `pow-state__button`. By
 *   the time the page draws, the visit's login has ended, so the site styles these classes, never the visit's body
 *   class.
 * - **Words.** Titles and text are neutral, translatable defaults that a layout overrides as shortcode attributes
 *   (`sent_title`, `sent_text`, `not_sent_title`, ...). The only setting is the button's label; the button is drawn
 *   only when the layout gives it somewhere to go (`button_url`).
 * - **Which state.** The address names it (`?pow_state=sent`); nothing else a link can carry shows anything but these
 *   five states. Without one, the `state` attribute (a layout's preview) or Session ended.
 * - **Headers.** No-store, noindex, nosniff and no-referrer go out on template_redirect, before the page renders, for
 *   the chosen page and for any page that draws the shortcode.
 *
 * The page carries no cXML and no secret: the page that posts the cart stays a plain /punchout/ route
 * (Checkout\Handoff). Without a chosen (published) page, url() answers null and every caller keeps its plain page.
 */
final class StatePage {

	public const SHORTCODE = 'punchout_state';

	/** The query argument naming the state. */
	public const QUERY = 'pow_state';

	public const SENDING  = 'sending';
	public const SENT     = 'sent';
	public const NOT_SENT = 'not-sent';
	public const EXPIRED  = 'expired';
	public const ENDED    = 'ended';

	/** Every state, in the order a visit can meet them. */
	public const STATES = [ self::SENDING, self::SENT, self::NOT_SENT, self::EXPIRED, self::ENDED ];

	public function __construct( private Settings $settings ) {}

	/** The site's state page as saved now (a fresh read of the settings). */
	public static function for_site(): self {
		return new self( new Settings() );
	}

	/** Where a browser in this state goes: the chosen page naming the state, or null (keep the plain page). */
	public static function link( string $state ): ?string {
		try {
			return self::for_site()->url( $state );
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	public function register(): void {
		add_shortcode( self::SHORTCODE, [ $this, 'shortcode' ] );
		add_action( 'template_redirect', [ $this, 'send_headers' ], 0 );
		add_filter( 'wp_robots', [ $this, 'robots' ] );
	}

	/** The chosen page while it is published, else 0. */
	public function page_id(): int {
		$id = $this->settings->int( 'state_page_id' );
		return $id > 0 && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	/** The chosen page's address naming $state, or null without a page (or for anything but the five states). */
	public function url( string $state ): ?string {
		if ( ! in_array( $state, self::STATES, true ) ) { return null; }
		$id = $this->page_id();
		if ( 0 === $id ) { return null; }
		$url = get_permalink( $id );
		return is_string( $url ) && '' !== $url ? self::with_query( $url, self::QUERY, $state ) : null;
	}

	/** $url with $key set to $value: any earlier $key is dropped, every other part kept byte for byte. */
	public static function with_query( string $url, string $key, string $value ): string {
		[ $address, $fragment ] = array_pad( explode( '#', $url, 2 ), 2, null );
		[ $path, $query ]       = array_pad( explode( '?', $address, 2 ), 2, '' );
		$kept                   = array_filter( '' === $query ? [] : explode( '&', $query ), static fn( string $part ): bool => '' !== $part && $key !== $part && ! str_starts_with( $part, $key . '=' ) );
		$kept[]                 = rawurlencode( $key ) . '=' . rawurlencode( $value );
		return $path . '?' . implode( '&', $kept ) . ( null !== $fragment ? '#' . $fragment : '' );
	}

	/** Whether this request draws a state: the chosen page, or any single page whose content holds the shortcode. */
	public function is_state_request(): bool {
		$id = $this->page_id();
		if ( $id > 0 && is_page( $id ) ) { return true; }
		if ( ! is_singular() ) { return false; }
		$post = get_queried_object();
		return is_object( $post ) && isset( $post->post_content ) && has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	/**
	 * The headers a state page sends after nocache_headers(): never stored, indexed, sniffed or given away as a
	 * referrer.
	 *
	 * @return list<string>
	 */
	public static function headers(): array {
		return [ ...Router::security_headers(), 'Referrer-Policy: no-referrer' ];
	}

	/** `template_redirect` 0: the state page's headers, before anything renders. */
	public function send_headers(): void {
		if ( ! $this->is_state_request() ) { return; }
		defined( 'DONOTCACHEPAGE' ) || define( 'DONOTCACHEPAGE', true );
		nocache_headers();
		if ( headers_sent() ) { return; }
		foreach ( self::headers() as $header ) {
			header( $header, true );
		}
	}

	/**
	 * `wp_robots`: noindex and nofollow in the page's own robots tag as well.
	 *
	 * @param array<string, mixed> $robots
	 * @return array<string, mixed>
	 */
	public function robots( array $robots ): array {
		if ( $this->is_state_request() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/**
	 * Each state's default title and text, translatable and neutral: nothing about approval, tax or prices.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function copy(): array {
		return [
			self::SENDING  => [ __( 'Sending your cart', 'punchout-woocommerce' ), __( 'Taking you back to your purchasing system.', 'punchout-woocommerce' ) ],
			self::SENT     => [ __( 'Sent', 'punchout-woocommerce' ), __( 'Your cart has been sent to your purchasing system. Return there to continue.', 'punchout-woocommerce' ) ],
			self::NOT_SENT => [ __( 'Not sent', 'punchout-woocommerce' ), __( 'Your cart was not sent to your purchasing system. Return there to continue.', 'punchout-woocommerce' ) ],
			self::EXPIRED  => [ __( 'Link expired', 'punchout-woocommerce' ), __( 'This catalog link has expired. Return to your purchasing system and open the catalog again.', 'punchout-woocommerce' ) ],
			self::ENDED    => [ __( 'Session ended', 'punchout-woocommerce' ), __( 'Your catalog session has ended. Return to your purchasing system and open the catalog again.', 'punchout-woocommerce' ) ],
		];
	}

	/** The state the address names, else a valid `state` attribute, else Session ended. */
	private static function requested( mixed $preview ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which of five fixed states to draw.
		$named = $_GET[ self::QUERY ] ?? null;
		if ( is_string( $named ) && in_array( $named, self::STATES, true ) ) { return $named; }
		return is_string( $preview ) && in_array( $preview, self::STATES, true ) ? $preview : self::ENDED;
	}

	/**
	 * [punchout_state]: the requested state's title, text and (with a `button_url`) the button.
	 *
	 * @param mixed $atts Shortcode attributes ('' when there are none).
	 */
	public function shortcode( mixed $atts = [] ): string {
		$pairs = [ 'state' => '', 'button_url' => '' ];
		foreach ( self::copy() as $state => [ $title, $text ] ) {
			$key                    = str_replace( '-', '_', $state );
			$pairs[ $key . '_title' ] = $title;
			$pairs[ $key . '_text' ]  = $text;
		}
		$atts  = shortcode_atts( $pairs, is_array( $atts ) ? $atts : [], self::SHORTCODE );
		$state = self::requested( $atts['state'] );
		$key   = str_replace( '-', '_', $state );
		$url   = trim( (string) $atts['button_url'] );

		return Templates::render(
			'state',
			[
				'state'        => $state,
				'title'        => trim( (string) $atts[ $key . '_title' ] ),
				'text'         => trim( (string) $atts[ $key . '_text' ] ),
				'button_url'   => 1 === preg_match( '#\A(https?://|/(?!/))#i', $url ) ? $url : '',
				'button_label' => $this->settings->state_button_label(),
			]
		);
	}
}
