<?php
/** Theme-overridable delivery confirmation; all choices and amounts are prepared server-side. @package POW @license AGPL-3.0-or-later */
defined( 'ABSPATH' ) || exit;
$money = static fn( int $cents ): string => \POW\Addresses\ReviewFormat::money( $cents, (string) ( $view['currency'] ?? 'ZAR' ) );
$logo = ! empty( $document ) ? \POW\Addresses\ReviewFormat::logo_html() : '';
$error = $view['error'] ?? null;
$selected = $view['selected_choice'] ?? null;
$selected_id = $selected ? $selected['provider'] . ':' . $selected['key'] : '';
$delivery = $view['delivery'] ?? null;
$disabled = empty( $view['can_confirm'] ) || $error instanceof \WP_Error;
$collection = ! empty( $view['collection'] );
if ( $document ) : ?>
<!doctype html><html <?php language_attributes(); ?>><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?php echo esc_html__( 'Review your cart', 'punchout-woocommerce' ); ?> — <?php echo esc_html( $shop_name ); ?></title></head><body>
<?php endif; ?>
<link rel="stylesheet" href="<?php echo esc_url( $stylesheet_url ); ?>">
<?php if ( $document ) : ?><main class="pow-confirmation woocommerce" aria-labelledby="pow-review-title"><?php else : ?><section class="pow-confirmation woocommerce" aria-labelledby="pow-review-title"><?php endif; ?>
	<header class="pow-confirmation__header"><span class="pow-confirmation__shop"><?php if ( '' !== $logo ) { echo wp_kses_post( $logo ); } else { echo esc_html( $shop_name ); } ?></span><span><?php echo esc_html__( 'Punchout', 'punchout-woocommerce' ); ?></span></header>
	<h1 id="pow-review-title"><?php echo esc_html__( 'Review your cart', 'punchout-woocommerce' ); ?></h1>
	<p class="pow-confirmation__intro"><?php echo esc_html__( 'Check your delivery details, then send this cart to your purchasing system for approval.', 'punchout-woocommerce' ); ?></p>
	<ol class="pow-confirmation__steps" aria-label="<?php echo esc_attr( __( 'Shopping progress', 'punchout-woocommerce' ) ); ?>"><li><?php echo esc_html__( 'Cart', 'punchout-woocommerce' ); ?></li><li aria-current="step"><?php echo esc_html__( 'Delivery and review', 'punchout-woocommerce' ); ?></li><li><?php echo esc_html__( 'Purchasing approval', 'punchout-woocommerce' ); ?></li></ol>
	<?php if ( $error instanceof \WP_Error ) : ?><div class="pow-confirmation__notice pow-confirmation__error" role="alert"><?php echo esc_html( $error->get_error_message() ); ?></div><?php endif; ?>
	<?php if ( ! isset( $view['items'] ) ) : ?>
		<p><a href="<?php echo esc_url( $cart_url ); ?>"><?php echo esc_html__( 'Back to cart', 'punchout-woocommerce' ); ?></a></p>
	<?php else : ?>
	<form method="post" action="<?php echo esc_url( $action_url ); ?>">
		<input type="hidden" name="pow_nonce" value="<?php echo esc_attr( $nonce ); ?>">
		<input type="hidden" name="pow_return_nonce" value="<?php echo esc_attr( $return_nonce ); ?>">
		<input type="hidden" name="review_digest" value="<?php echo esc_attr( $view['review_digest'] ?? '' ); ?>">
		<div class="pow-confirmation__layout"><div>
		<section class="pow-confirmation__section" aria-labelledby="pow-destination-title">
			<h2 id="pow-destination-title"><?php echo esc_html( $collection ? __( 'Address for this order', 'punchout-woocommerce' ) : __( 'Delivery address', 'punchout-woocommerce' ) ); ?></h2>
			<?php if ( $delivery && 'not_required' === $delivery['status'] ) : ?>
			<p><?php echo esc_html__( 'This cart does not need a delivery address.', 'punchout-woocommerce' ); ?></p>
			<?php elseif ( ! empty( $view['choices'] ) ) : ?>
			<label for="pow-delivery-choice"><?php echo esc_html__( 'Choose a delivery address', 'punchout-woocommerce' ); ?></label>
			<select id="pow-delivery-choice" name="choice" required>
				<option value=""><?php echo esc_html__( 'Select an address', 'punchout-woocommerce' ); ?></option>
				<?php foreach ( $view['choices'] as $choice ) : $identifier = $choice['provider'] . ':' . $choice['key']; ?>
				<option value="<?php echo esc_attr( $identifier ); ?>" <?php if ( $identifier === $selected_id ) { echo 'selected'; } ?>><?php echo esc_html( $choice['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( $collection ) : ?><p class="pow-confirmation__hint"><?php echo esc_html__( 'Collection orders keep this address on the order; WooCommerce uses it to find the collection points for your area.', 'punchout-woocommerce' ); ?></p><?php endif; ?>
			<?php else : ?><p><?php echo esc_html__( 'No delivery address is available. Ask your company administrator to add and enable one.', 'punchout-woocommerce' ); ?></p><?php endif; ?>
			<?php if ( ! empty( $view['delivery_destination']['address'] ) ) : ?>
			<address class="pow-confirmation__address"><?php foreach ( \POW\Addresses\ReviewFormat::address_lines( $view['delivery_destination']['address'] ) as $line ) { echo esc_html( $line ) . '<br>'; } ?></address>
			<?php endif; ?>
		</section>
		<section class="pow-confirmation__section" aria-labelledby="pow-shipping-title">
			<h2 id="pow-shipping-title"><?php echo esc_html( $collection ? __( 'Collection', 'punchout-woocommerce' ) : __( 'Delivery method', 'punchout-woocommerce' ) ); ?></h2>
			<?php foreach ( $view['packages'] as $package ) : ?>
			<fieldset><legend><?php echo esc_html( $package['label'] ); ?></legend>
				<?php foreach ( $package['rates'] as $rate ) : ?>
				<label class="pow-confirmation__rate"><input type="radio" name="rates[<?php echo esc_attr( (string) $package['package_key'] ); ?>]" value="<?php echo esc_attr( $rate['rate_id'] ); ?>" <?php if ( $package['selected_rate_id'] === $rate['rate_id'] ) { echo 'checked'; } ?>> <span><?php echo wp_kses_post( $rate['display_label'] ); ?></span></label>
				<?php endforeach; ?>
			</fieldset>
			<?php endforeach; ?>
			<?php if ( ! empty( $view['packages'] ) ) : ?><p class="pow-confirmation__hint"><?php echo esc_html__( 'Delivery periods are shown with each delivery method.', 'punchout-woocommerce' ); ?></p><?php endif; ?>
			<?php if ( $delivery && 'not_required' === $delivery['status'] ) : ?><p><?php echo esc_html__( 'No shipping is required.', 'punchout-woocommerce' ); ?></p>
			<?php elseif ( $delivery && null === $delivery['amount_cents'] ) : ?><p><?php echo esc_html__( 'A delivery estimate is not currently available.', 'punchout-woocommerce' ); ?></p><?php endif; ?>
			<?php if ( ! empty( $view['requires_unknown_acknowledgement'] ) ) : ?>
			<label class="pow-confirmation__rate"><input type="checkbox" name="acknowledge_unknown" value="1" required><span><?php echo esc_html__( 'Delivery will be quoted separately. I understand that no delivery charge is included in this transfer.', 'punchout-woocommerce' ); ?></span></label>
			<?php endif; ?>
			<button type="submit" name="pow_delivery_action" value="review" class="button wp-element-button pow-confirmation__secondary" formnovalidate><?php echo esc_html__( 'Update delivery options', 'punchout-woocommerce' ); ?></button>
			<p class="pow-confirmation__hint"><?php echo esc_html__( 'Update after changing an address or method to review the current estimate.', 'punchout-woocommerce' ); ?></p>
		</section>
		<section class="pow-confirmation__section" aria-labelledby="pow-notes-title"><h2 id="pow-notes-title"><?php echo esc_html__( 'Delivery instructions or notes', 'punchout-woocommerce' ); ?></h2>
			<?php if ( ! ( $delivery && 'not_required' === $delivery['status'] ) ) : ?>
			<label for="pow-preferred-date"><?php echo esc_html__( 'Preferred delivery date', 'punchout-woocommerce' ); ?></label><input type="date" id="pow-preferred-date" name="preferred_delivery_date" value="<?php echo esc_attr( (string) ( $view['preferred_delivery_date'] ?? '' ) ); ?>"<?php if ( ! empty( $view['preferred_delivery_date_min'] ) ) { echo ' min="' . esc_attr( $view['preferred_delivery_date_min'] ) . '"'; } ?>><p class="pow-confirmation__hint"><?php echo esc_html__( 'Optional. Saved with the shop’s copy of this order; it is not sent to your purchasing system.', 'punchout-woocommerce' ); ?></p>
			<?php endif; ?>
			<label class="pow-confirmation__hint" for="pow-delivery-notes"><?php echo esc_html__( 'Optional. Up to 2,000 characters.', 'punchout-woocommerce' ); ?></label><textarea id="pow-delivery-notes" name="notes" rows="4" maxlength="2000"><?php echo esc_textarea( $view['notes'] ); ?></textarea></section>
		<section class="pow-confirmation__section" aria-labelledby="pow-items-title"><h2 id="pow-items-title"><?php echo esc_html__( 'Items for approval', 'punchout-woocommerce' ); ?></h2><div class="pow-confirmation__table"><table><thead><tr><th scope="col"><?php echo esc_html__( 'Item', 'punchout-woocommerce' ); ?></th><th scope="col"><?php echo esc_html__( 'Quantity', 'punchout-woocommerce' ); ?></th><th scope="col" class="pow-confirmation__number"><?php echo esc_html__( 'Unit price', 'punchout-woocommerce' ); ?></th><th scope="col" class="pow-confirmation__number"><?php echo esc_html__( 'Line total', 'punchout-woocommerce' ); ?></th></tr></thead><tbody>
			<?php foreach ( $view['items'] as $item ) : ?><tr><td><?php echo esc_html( $item['description'] ); ?><small><?php echo esc_html( $item['supplier_part_id'] ); ?></small></td><td><?php echo esc_html( (string) $item['quantity'] ); ?></td><td class="pow-confirmation__number"><?php echo esc_html( $money( $item['unit_price_cents'] ) ); ?></td><td class="pow-confirmation__number"><?php echo esc_html( $money( \POW\Addresses\ReviewFormat::line_total_cents( $item ) ) ); ?></td></tr><?php endforeach; ?>
		</tbody></table></div></section>
		<?php if ( ! empty( $view['skipped'] ) ) : ?><div class="pow-confirmation__notice"><p><?php echo esc_html__( 'These products cannot be transferred because their catalogue details are incomplete:', 'punchout-woocommerce' ); ?></p><ul><?php foreach ( $view['skipped'] as $name ) { echo '<li>' . esc_html( $name ) . '</li>'; } ?></ul></div><?php endif; ?>
		</div><aside class="pow-confirmation__summary" aria-labelledby="pow-summary-title"><h2 id="pow-summary-title"><?php echo esc_html__( 'Cart summary', 'punchout-woocommerce' ); ?></h2><dl><dt><?php echo esc_html__( 'Merchandise', 'punchout-woocommerce' ); ?></dt><dd><?php echo esc_html( $money( $view['merchandise_total_cents'] ) ); ?></dd><dt><?php echo esc_html__( 'Delivery estimate', 'punchout-woocommerce' ); ?></dt><dd><?php echo esc_html( $delivery && null !== $delivery['amount_cents'] ? $money( $delivery['amount_cents'] ) : __( 'Not included', 'punchout-woocommerce' ) ); ?></dd><dt class="pow-confirmation__total"><?php echo esc_html__( 'Amount sent for approval', 'punchout-woocommerce' ); ?></dt><dd class="pow-confirmation__total"><?php echo esc_html( $money( $view['total_cents'] ) ); ?></dd></dl>
			<p class="pow-confirmation__hint"><?php echo esc_html__( 'Amounts sent for approval exclude tax.', 'punchout-woocommerce' ); ?></p>
			<?php if ( $delivery && 'not_required' !== $delivery['status'] && ! $delivery['emit'] && null !== $delivery['amount_cents'] ) : ?><p class="pow-confirmation__hint"><?php echo esc_html__( 'The delivery estimate is saved with the local quote. This connection does not include it in the transferred amount.', 'punchout-woocommerce' ); ?></p><?php endif; ?>
			<button type="submit" name="pow_delivery_action" value="submit" class="button alt wp-element-button pow-confirmation__submit" <?php if ( $disabled ) { echo 'disabled'; } ?>><?php echo esc_html__( 'Submit for approval', 'punchout-woocommerce' ); ?></button>
			<p><?php echo esc_html__( 'Your complete cart will return to your purchasing system. Your company’s approval process continues there.', 'punchout-woocommerce' ); ?></p>
			<button type="submit" name="pow_delivery_action" value="back" class="pow-confirmation__back" formnovalidate><?php echo esc_html__( 'Back to cart', 'punchout-woocommerce' ); ?></button>
		</aside></div>
	</form>
	<?php endif; ?>
<?php if ( $document ) : ?></main><?php else : ?></section><?php endif; ?>
<?php if ( $document ) : ?></body></html><?php endif; ?>
