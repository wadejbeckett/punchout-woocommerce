<?php
/**
 * One state of a visit's end, drawn by [punchout_state] inside the theme (0.5.0).
 *
 * The classes are the contract a site styles: pow-state plus one modifier (pow-state--sending, --sent, --not-sent,
 * --expired, --ended) and the children pow-state__title, pow-state__text, pow-state__actions and pow-state__button.
 * The visit's login has ended by the time this draws, so nothing here depends on the visit's body class.
 *
 * Override by copying to {theme}/punchout-woocommerce/state.php, or via the pow_template_state filter.
 *
 * Available variables:
 *
 * @var string $state        One of sending, sent, not-sent, expired, ended.
 * @var string $title        The title ('' draws none).
 * @var string $text         The text ('' draws none).
 * @var string $button_url   Where the button leads ('' draws no button).
 * @var string $button_label The button's label (the "State page button label" setting).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="pow-state pow-state--<?php echo esc_attr( $state ); ?>"><?php if ( '' !== $title ) : ?><h2 class="pow-state__title"><?php echo esc_html( $title ); ?></h2><?php endif; ?><?php if ( '' !== $text ) : ?><p class="pow-state__text"><?php echo esc_html( $text ); ?></p><?php endif; ?><?php if ( '' !== $button_url ) : ?><div class="pow-state__actions"><a class="pow-state__button" href="<?php echo esc_url( $button_url ); ?>"><?php echo esc_html( $button_label ); ?></a></div><?php endif; ?></div>
