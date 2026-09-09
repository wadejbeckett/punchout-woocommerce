<?php
/**
 * The self-test box on the integration documentation page.
 *
 * Paste a PunchOutSetupRequest or ProfileRequest, get back the cXML
 * Status the live endpoint would have answered with, and the reason.
 *
 * POST only and nonced: a GET renders the empty form. Nothing here is
 * stored — the paste is echoed back only to be corrected, and only after
 * SelfTest::redact() has taken any SharedSecret out of it. Docs\Page has
 * redacted it already; the call is repeated here because this is the echo
 * site and a theme's copy of this template must not be able to lose the
 * rule. redact() is idempotent.
 *
 * Override by copying to
 * {theme}/punchout-woocommerce/docs/self-test.php, or via the
 * pow_template_docs/self-test filter.
 *
 * Available variables:
 *
 * @var ?array{checks: list<array{label: string, result: string, detail: string}>, verdict: int, verdict_text: string} $report Result of a run, or null.
 * @var string $xml        The pasted document, redacted, to re-show ('' on first load).
 * @var string $notice     Why no run happened ('' when one did).
 * @var bool   $privileged Whether the viewer sees precise authentication reasons.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

defined( 'ABSPATH' ) || exit;

$pow_result_labels = [
	'pass' => __( 'Pass', 'punchout-woocommerce' ),
	'fail' => __( 'Fail', 'punchout-woocommerce' ),
	'info' => __( 'Note', 'punchout-woocommerce' ),
];
?>
<section class="pow-docs-self-test">
	<h2><?php esc_html_e( 'Test your setup request', 'punchout-woocommerce' ); ?></h2>
	<p><?php esc_html_e( 'Paste a PunchOutSetupRequest or ProfileRequest below. We answer with the cXML Status the live endpoint would answer with, and why. Nothing is stored: no session is created, no document is kept, and any shared secret in the paste is removed before it is shown back to you.', 'punchout-woocommerce' ); ?></p>

	<?php if ( '' !== $notice ) : ?>
		<p class="pow-docs-notice"><strong><?php echo esc_html( $notice ); ?></strong></p>
	<?php endif; ?>

	<?php if ( null !== $report ) : ?>
		<p class="pow-docs-verdict">
			<?php
			printf(
				/* translators: 1: cXML Status code, 2: cXML Status text. */
				esc_html__( 'Verdict: cXML Status %1$d %2$s', 'punchout-woocommerce' ),
				(int) $report['verdict'],
				esc_html( (string) $report['verdict_text'] )
			);
			?>
		</p>

		<table class="pow-docs-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Check', 'punchout-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'punchout-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Detail', 'punchout-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $report['checks'] as $pow_check ) : ?>
					<tr class="pow-docs-check pow-docs-check--<?php echo esc_attr( (string) $pow_check['result'] ); ?>">
						<th scope="row"><?php echo esc_html( (string) $pow_check['label'] ); ?></th>
						<td><?php echo esc_html( $pow_result_labels[ $pow_check['result'] ] ?? (string) $pow_check['result'] ); ?></td>
						<td><?php echo esc_html( (string) $pow_check['detail'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( ! $privileged ) : ?>
			<p class="pow-docs-note"><em><?php esc_html_e( 'A single "connection and shared secret" result is deliberate: telling a stranger which of the three failed would make this page a credential oracle. Signed-in store administrators see the three stages separately.', 'punchout-woocommerce' ); ?></em></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php // No action attribute: the form posts back to the page it is on, which is right for both the public page and the admin tab. ?>
	<form method="post" class="pow-docs-self-test-form">
		<?php wp_nonce_field( \POW\Docs\Page::NONCE ); ?>
		<p>
			<label for="pow-docs-xml"><?php esc_html_e( 'Your document', 'punchout-woocommerce' ); ?></label><br />
			<textarea id="pow-docs-xml" name="<?php echo esc_attr( \POW\Docs\Page::FIELD ); ?>" rows="12" cols="80" spellcheck="false"><?php echo esc_textarea( \POW\Docs\SelfTest::redact( $xml ) ); ?></textarea>
		</p>
		<p><button type="submit"><?php esc_html_e( 'Run the self-test', 'punchout-woocommerce' ); ?></button></p>
	</form>
</section>
