<?php
/**
 * The cart-return handoff page.
 *
 * A standalone page (no theme bootstrap) whose form performs the top-level
 * cross-site POST back to the buyer's procurement system. Auto-submits,
 * with a visible fallback button, "what you should see next" copy and a
 * support reference shown BEFORE submission — if the buyer lands on their
 * identity provider's sign-in instead of the basket wizard (the SameSite
 * failure mode, scope §9.1), the reference is what support needs.
 *
 * Override by copying to {theme}/punchout-woocommerce/handoff.php, or via
 * the pow_template_handoff filter.
 *
 * Available variables:
 *
 * @var string       $action_url  BrowserFormPost URL (the buyer-side receiver).
 * @var string       $field_name  cxml-base64 or cxml-urlencoded.
 * @var string       $field_value Encoded PunchOutOrderMessage.
 * @var string       $reference   Support reference (session id).
 * @var list<string> $notices     Per-line notices (skipped lines etc.).
 * @var string       $heading     Page heading.
 * @var string       $copy        Explanatory copy.
 * @var string       $button      Fallback submit label.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8" />
	<meta name="robots" content="noindex,nofollow" />
	<title><?php echo esc_html( $heading ); ?></title>
</head>
<body style="font-family:sans-serif;max-width:36em;margin:4em auto;padding:0 1em">
	<h1 style="font-size:1.3em"><?php echo esc_html( $heading ); ?></h1>
	<p><?php echo esc_html( $copy ); ?></p>
	<?php foreach ( $notices as $notice ) : ?>
		<p style="color:#8a6d3b"><?php echo esc_html( $notice ); ?></p>
	<?php endforeach; ?>
	<p style="color:#666;font-size:0.9em">
		<?php
		printf(
			/* translators: %s: support reference code */
			esc_html__( 'Support reference: %s', 'punchout-woocommerce' ),
			esc_html( $reference )
		);
		?>
	</p>
	<form method="post" action="<?php echo esc_url( $action_url ); ?>" id="pow-handoff-form">
		<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="<?php
			// esc_attr() never double-encodes, so a packed payload containing
			// entities (&amp;, &#233; from the us-ascii pack) would be decoded
			// ONCE by the browser and POSTed as raw markup — malformed XML at
			// the buyer. double_encode=true round-trips byte-exactly.
			echo htmlspecialchars( $field_value, ENT_QUOTES, 'UTF-8', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>" />
		<button type="submit" class="pow-handoff-submit"><?php echo esc_html( $button ); ?></button>
	</form>
	<script>document.getElementById( 'pow-handoff-form' ).submit();</script>
</body>
</html>
