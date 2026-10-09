"""The driver's own decisions: request shape, start-link handling, the cart's exit, which path a release takes,
the 0.4.23 review checks, and how a run that cannot finish is reported."""
import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import socket
import stat
import sys
import tempfile
import threading
import unittest
from unittest import mock
import xml.etree.ElementTree as ET

import driver

BASE = 'http://127.0.0.1:8080'


class SetupRequest(unittest.TestCase):

    def setUp(self):
        self.cookie = '{9D41C7A0-3E62-4B85-A1F4-6C0E2B9D5F17}'
        self.xml = driver.setup_request(
            payload_id='{0B6E2D51-7C3A-4F19-9E08-5A2C4D7F1B36}', timestamp='2026-01-15T08:30:00', buyer='BUYER-TEST', supplier='supplier-test',
            secret='s3cret', email='Buyer.One@buyer.example.com', cookie=self.cookie, supplier_setup=BASE + '/punchout/setup', receiver=BASE + '/receiver/punchout/cxml/',
        )

    def test_it_is_the_one_line_dynamics_shape_without_a_doctype(self):
        self.assertTrue(self.xml.startswith('<?xml version="1.0" encoding="utf-8"?><cXML payloadID="{0B6E2D51-7C3A-4F19-9E08-5A2C4D7F1B36}" timestamp="2026-01-15T08:30:00" version="1.2.008" xml:lang="en-US">'))
        self.assertNotIn('\n', self.xml)
        self.assertNotIn('DOCTYPE', self.xml)

    def test_identities_secret_and_mode_are_where_the_tenant_puts_them(self):
        root = ET.fromstring(self.xml)
        self.assertEqual(root.findtext('Header/From/Credential/Identity'), 'BUYER-TEST')
        self.assertEqual(root.findtext('Header/To/Credential/Identity'), 'supplier-test')
        self.assertEqual(root.findtext('Header/Sender/Credential/Identity'), 'BUYER-TEST')
        self.assertEqual(root.findtext('Header/Sender/Credential/SharedSecret'), 's3cret')
        self.assertEqual(root.findtext('Header/Sender/UserAgent'), 'Dynamics 365 for Operations')
        self.assertEqual(root.find('Request').get('deploymentMode'), 'test')
        self.assertEqual(root.find('Request/PunchOutSetupRequest').get('operation'), 'create')

    def test_the_email_label_comes_before_the_cookie_and_the_punchback_ends_in_the_encoded_cookie(self):
        request = ET.fromstring(self.xml).find('Request/PunchOutSetupRequest')
        self.assertEqual([child.tag for child in request], ['SupplierSetup', 'Extrinsic', 'BuyerCookie', 'BrowserFormPost'])
        self.assertEqual(request.find('Extrinsic').get('name'), 'User email')
        self.assertEqual(request.findtext('SupplierSetup/URL'), BASE + '/punchout/setup ')
        self.assertEqual(request.findtext('BuyerCookie'), self.cookie)
        self.assertEqual(request.findtext('BrowserFormPost/URL'), BASE + '/receiver/punchout/cxml/%7B9D41C7A0-3E62-4B85-A1F4-6C0E2B9D5F17%7D')

    def test_values_are_escaped(self):
        xml = driver.setup_request(payload_id='a&b', timestamp='t', buyer='<b>', supplier='s', secret='x"y', email='e', cookie='c', supplier_setup='u', receiver='r/')
        root = ET.fromstring(xml)
        self.assertEqual(root.get('payloadID'), 'a&b')
        self.assertEqual(root.findtext('Header/From/Credential/Identity'), '<b>')


class StartLink(unittest.TestCase):

    def test_a_redirect_is_a_redeem(self):
        self.assertEqual(driver.start_outcome(302, {'Location': BASE + '/?page_id=5'}, '', BASE + '/punchout/start/t'), ('redeemed', BASE + '/?page_id=5'))

    def test_a_page_that_posts_itself_back_is_a_form_to_submit(self):
        page = '<form method="post" action="/punchout/start/t"><button type="submit">Open the catalogue</button></form>'
        kind, form = driver.start_outcome(200, {}, page, BASE + '/punchout/start/t')
        self.assertEqual(kind, 'post')
        self.assertEqual(form.action, BASE + '/punchout/start/t')

    def test_a_page_whose_form_goes_elsewhere_is_not_followed(self):
        page = '<form method="post" action="https://elsewhere.example.com/x"></form>'
        self.assertEqual(driver.start_outcome(200, {}, page, BASE + '/punchout/start/t')[0], 'refused')

    def test_anything_else_is_a_refusal(self):
        self.assertEqual(driver.start_outcome(403, {}, 'This catalog link has expired.', BASE + '/punchout/start/t')[0], 'refused')


class CartExit(unittest.TestCase):

    def test_the_classic_cart_offers_the_return_form(self):
        html = '<form method="post" action="' + BASE + '/punchout/confirm" class="pow-return-form"><input type="hidden" name="pow_mode" value="cart"><input type="hidden" name="pow_nonce" value="n"><button type="submit" class="pow-return-button">Punchout</button></form>'
        kind, form = driver.cart_exit(html, BASE + '/?page_id=6')
        self.assertEqual(kind, 'form')
        self.assertEqual(form.submission(), [('pow_mode', 'cart'), ('pow_nonce', 'n')])

    def test_the_block_cart_offers_its_configured_link(self):
        html = '<script id="pow-cart-blocks-js-before">window.powCartBlocks = {"restricted":true,"label":"Punchout","confirmUrl":"http:\\/\\/127.0.0.1:8080\\/punchout\\/confirm"};</script>'
        self.assertEqual(driver.cart_exit(html, BASE + '/?page_id=6'), ('link', BASE + '/punchout/confirm'))

    def test_a_cart_without_an_exit_says_so(self):
        self.assertIsNone(driver.cart_exit('<a href="/checkout">Proceed to checkout</a>', BASE + '/?page_id=6'))


class ReleasePath(unittest.TestCase):

    def test_auto_follows_the_installed_version(self):
        self.assertEqual(driver.path_for('0.4.22', 'auto'), 'review')
        self.assertEqual(driver.path_for('0.4.23', 'auto'), 'review')
        self.assertEqual(driver.path_for('0.5.0', 'auto'), 'checkout')
        self.assertEqual(driver.path_for('0.10.1', 'auto'), 'checkout')

    def test_an_explicit_path_wins(self):
        self.assertEqual(driver.path_for('0.4.22', 'checkout'), 'checkout')

    def test_the_checkout_path_is_an_extension_point_until_it_is_built(self):
        with self.assertRaises(NotImplementedError):
            driver.checkout_path(None, None, None)


class Loopback(unittest.TestCase):

    def test_only_the_loopback_address_is_accepted(self):
        driver.require_loopback('http://127.0.0.1:8080')
        for url in ['http://localhost:8080', 'https://shop.example.com', 'http://10.0.0.1']:
            with self.assertRaises(SystemExit):
                driver.require_loopback(url)


class Handoff(unittest.TestCase):

    def test_the_handoff_form_is_read_as_a_browser_would_submit_it(self):
        page = '<form method="post" action="' + BASE + '/receiver/punchout/cxml/%7Bc%7D" id="pow-handoff-form"><input type="hidden" name="cxml-base64" value="PD94bWw+" /><button type="submit" class="pow-handoff-submit">Continue</button></form>'
        form = driver.handoff_form(page, BASE + '/punchout/confirm')
        self.assertEqual(form.action, BASE + '/receiver/punchout/cxml/%7Bc%7D')
        self.assertEqual(form.submission(), [('cxml-base64', 'PD94bWw+')])

    def test_a_page_without_the_handoff_form_is_none(self):
        self.assertIsNone(driver.handoff_form('<p>Expired</p>', BASE))


class Versions(unittest.TestCase):

    def test_at_least_compares_release_numbers_not_strings(self):
        self.assertTrue(driver.at_least('0.4.23', (0, 4, 23)))
        self.assertTrue(driver.at_least('0.4.100', (0, 4, 23)))
        self.assertTrue(driver.at_least('0.5.0', (0, 4, 23)))
        self.assertFalse(driver.at_least('0.4.22', (0, 4, 23)))
        self.assertFalse(driver.at_least('0.4.3', (0, 4, 23)))


START = BASE + '/punchout/start/' + 'a' * 40
START_SCRIPT = "(function(){var form=document.getElementById('pow-start-form');form.submit();})();"
START_STYLE = 'body{font-family:sans-serif}'


def sha256(text):
    return "'sha256-" + base64.b64encode(hashlib.sha256(text.encode('utf-8')).digest()).decode('ascii') + "'"


START_PAGE = ('<style>' + START_STYLE + '</style><form method="post" action="' + START + '" id="pow-start-form" data-pow-start="auto"><button type="submit">Open the catalog</button></form>'
              '<script>' + START_SCRIPT + '</script>')
START_POLICY = ("default-src 'none'; script-src " + sha256(START_SCRIPT) + '; style-src ' + sha256(START_STYLE)
                + "; form-action 'self'; frame-ancestors 'none'; base-uri 'none'")
START_HEADERS = {'Cache-Control': 'private, no-store, no-transform', 'X-Robots-Tag': 'noindex, nofollow', 'Content-Security-Policy': START_POLICY, 'X-Frame-Options': 'DENY'}


class StartPage(unittest.TestCase):
    """0.4.23: a GET of the start link answers a page that posts itself back, uncached, unindexed, unframed, and
    allowed to run its own inline script only."""

    def findings(self, status, headers, body):
        return {name: ok for name, ok, _ in driver.start_page_findings(status, headers, body, START)}

    def test_the_0423_page_passes(self):
        self.assertEqual(self.findings(200, START_HEADERS, START_PAGE), {
            'a_get_of_the_start_link_answers_a_page_that_posts_itself_back': True,
            'the_start_page_is_not_stored': True,
            'the_start_page_is_not_indexed': True,
            'the_start_page_is_not_transformed': True,
            'the_start_page_cannot_be_framed': True,
            'the_start_page_policy_is_strict': True,
            'the_start_page_policy_allows_its_own_script_and_style_by_hash': True,
        })

    def test_a_redirecting_get_is_a_redeem_and_fails(self):
        self.assertFalse(self.findings(302, {'Location': BASE + '/?page_id=5'}, '')['a_get_of_the_start_link_answers_a_page_that_posts_itself_back'])

    def test_missing_headers_fail(self):
        found = self.findings(200, {}, START_PAGE)
        self.assertEqual(sorted(name for name, ok in found.items() if not ok), [
            'the_start_page_cannot_be_framed', 'the_start_page_is_not_indexed', 'the_start_page_is_not_stored', 'the_start_page_is_not_transformed',
            'the_start_page_policy_allows_its_own_script_and_style_by_hash', 'the_start_page_policy_is_strict',
        ])

    def test_a_loose_policy_fails(self):
        for loose in [START_POLICY.replace(sha256(START_SCRIPT), "'unsafe-inline'"), START_POLICY.replace("default-src 'none'", "default-src *"),
                      START_POLICY.replace("; frame-ancestors 'none'", ''), START_POLICY.replace("form-action 'self'", 'form-action *')]:
            self.assertFalse(self.findings(200, dict(START_HEADERS, **{'Content-Security-Policy': loose}), START_PAGE)['the_start_page_policy_is_strict'], loose)

    def test_a_script_or_style_the_policy_does_not_name_fails(self):
        for page in [START_PAGE.replace('form.submit();', 'form.submit(); '), START_PAGE.replace('sans-serif', 'serif'), START_PAGE.replace('<button', '<button style="color:red"'),
                     START_PAGE + '<script src="' + BASE + '/x.js"></script>', START_PAGE.replace('<button', '<button onclick="go()"')]:
            self.assertFalse(self.findings(200, START_HEADERS, page)['the_start_page_policy_allows_its_own_script_and_style_by_hash'], page[:60])


LABELS = {'submit': 'Send cart', 'items': 'Items on order', 'total': 'Order total'}
PRICED = 'Courier E2E ab12'
# The 0.4.23 template drawn for a connection without the delivery line (templates/delivery-confirmation.php, trimmed).
REVIEW = (
    '<main class="pow-confirmation woocommerce" aria-labelledby="pow-review-title"><h1 id="pow-review-title">Review your cart</h1>'
    '<form method="post" action="' + BASE + '/punchout/confirm" enctype="multipart/form-data">'
    '<section class="pow-confirmation__section" aria-labelledby="pow-shipping-title"><h2 id="pow-shipping-title">Delivery method</h2>'
    '<fieldset><legend>Shipment 1</legend>\n'
    '<label class="pow-confirmation__rate"><input type="radio" name="rates[0]" value="free_shipping:1" checked> <span>Free shipping</span></label>\n'
    '<label class="pow-confirmation__rate"><input type="radio" name="rates[0]" value="flat_rate:9" > <span>' + PRICED + '</span></label>\n'
    '</fieldset><button type="submit" name="pow_delivery_action" value="review" class="button wp-element-button pow-confirmation__secondary" formnovalidate data-busy="Updating delivery…">Update delivery</button>'
    '<p class="pow-confirmation__hint">The address and the delivery methods update as soon as you change a choice.</p></section>'
    '<section class="pow-confirmation__section" aria-labelledby="pow-notes-title"><h2 id="pow-notes-title">Notes and attachment</h2>'
    '<textarea id="pow-delivery-notes" name="notes" rows="4" maxlength="2000"></textarea></section>'
    '<section class="pow-confirmation__section" aria-labelledby="pow-items-title"><h2 id="pow-items-title">Items on order</h2><table><tbody>'
    '<tr><td>END-TO-END ALPHA<small>E2E-ALPHA</small></td><td>2</td><td class="pow-confirmation__number">R 11,00</td><td class="pow-confirmation__number">R 22,00</td></tr>'
    '</tbody></table></section>'
    '<aside class="pow-confirmation__summary" aria-labelledby="pow-summary-title"><h2 id="pow-summary-title">Cart summary</h2>'
    '<dl><dt class="pow-confirmation__total">Order total</dt><dd class="pow-confirmation__total">R 45,50</dd></dl>\n'
    '<button type="submit" name="pow_delivery_action" value="submit" class="button alt wp-element-button pow-confirmation__submit" >Send cart</button>'
    '<p>Sends this cart to your purchasing system.</p></aside></form></main>'
)


class Review0423(unittest.TestCase):
    """The review's own words come from the settings; with the delivery line off it shows no delivery amount, no tax and no date field."""

    def failed(self, html, freight=False, labels=None):
        return sorted(name for name, ok, _ in driver.review_findings(html, labels or LABELS, freight, [PRICED]) if not ok)

    def test_the_0423_page_without_the_delivery_line_passes(self):
        names = [name for name, _, _ in driver.review_findings(REVIEW, LABELS, False, [PRICED])]
        self.assertEqual(names, [
            'review_submit_label_is_the_setting', 'review_items_heading_is_the_setting', 'review_total_label_is_the_setting',
            'review_has_no_date_input', 'review_has_no_estimate_row', 'review_method_list_has_no_amount', 'review_offers_the_priced_method_by_its_title',
            'review_has_no_tax_sentence', 'review_update_control_is_neutral',
        ])
        self.assertEqual(self.failed(REVIEW), [])

    def test_default_words_fail_the_label_checks(self):
        html = REVIEW.replace('>Send cart</button>', '>Submit</button>').replace('Items on order', 'Items').replace('Order total', 'Total')
        self.assertEqual(self.failed(html), ['review_items_heading_is_the_setting', 'review_submit_label_is_the_setting', 'review_total_label_is_the_setting'])

    def test_an_escaped_label_still_matches(self):
        html = REVIEW.replace('>Send cart</button>', '>Send &amp; close</button>')
        self.assertEqual(self.failed(html, labels=dict(LABELS, submit='Send & close')), [])

    def test_an_estimate_row_fails(self):
        html = REVIEW.replace('<dl>', '<dl><dt>Merchandise</dt><dd>R 45,50</dd><dt>Delivery estimate</dt><dd>R 50,00</dd>')
        self.assertEqual(self.failed(html), ['review_has_no_estimate_row'])

    def test_a_priced_method_label_fails(self):
        priced = PRICED + ': <span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">R</span>50.00</bdi></span>'
        self.assertEqual(self.failed(REVIEW.replace('<span>' + PRICED + '</span>', '<span>' + priced + '</span>')), ['review_method_list_has_no_amount', 'review_offers_the_priced_method_by_its_title'])
        self.assertEqual(self.failed(REVIEW.replace('<span>Free shipping</span>', '<span>Free shipping: R 0,00</span>')), ['review_method_list_has_no_amount'])

    def test_a_page_without_a_method_list_cannot_prove_it_shows_no_amount(self):
        html = REVIEW.split('<fieldset>')[0] + REVIEW.split('</fieldset>')[1]
        self.assertIn('review_method_list_has_no_amount', self.failed(html))

    def test_the_old_recalculate_wording_fails(self):
        self.assertEqual(self.failed(REVIEW.replace('>Update delivery<', '>Recalculate delivery<')), ['review_update_control_is_neutral'])
        self.assertEqual(self.failed(REVIEW.replace('data-busy="Updating delivery…"', 'data-busy="Recalculating…"')), ['review_update_control_is_neutral'])
        self.assertEqual(self.failed(REVIEW.replace('value="review"', 'value="other"')), ['review_update_control_is_neutral'], 'A page without the control cannot prove its words')

    def test_any_tax_wording_fails(self):
        for sentence in ['<p class="pow-confirmation__hint">Amounts exclude tax.</p>', '<small class="tax_label">(ex. VAT)</small>']:
            self.assertEqual(self.failed(REVIEW.replace('</dl>', '</dl>' + sentence)), ['review_has_no_tax_sentence'], sentence)

    def test_a_date_input_fails(self):
        html = REVIEW.replace('</textarea>', '</textarea><input type="date" id="pow-preferred-date" name="preferred_delivery_date">')
        self.assertEqual(self.failed(html), ['review_has_no_date_input'])

    def test_with_the_delivery_line_on_only_the_words_and_the_date_are_checked(self):
        names = [name for name, _, _ in driver.review_findings(REVIEW, LABELS, True, [PRICED])]
        self.assertEqual(names, ['review_submit_label_is_the_setting', 'review_items_heading_is_the_setting', 'review_total_label_is_the_setting', 'review_has_no_date_input'])


class _Closer:
    """A loopback listener that accepts and hangs up at once: the site came up, then every request fails."""

    def __init__(self):
        self.socket = socket.socket()
        self.socket.bind(('127.0.0.1', 0))
        self.socket.listen(16)
        self.port = self.socket.getsockname()[1]
        self.running = True
        threading.Thread(target=self.serve, daemon=True).start()

    def serve(self):
        while self.running:
            try:
                connection, _ = self.socket.accept()
            except OSError:
                return
            connection.close()

    def close(self):
        self.running = False
        self.socket.close()


FAKE_PHP = """#!{python}
import json, os, sys
step = os.environ.get('POW_E2E_STEP')
if step == 'seed':
    with open(os.environ['POW_E2E_FIXTURE'], 'w') as f:
        json.dump({{'run': 'test', 'versions': {{'plugin': '0.4.23', 'woocommerce': '11.2.0', 'wordpress': '7.1'}}, 'connection': {{'buyer': 'BUYER-TEST', 'supplier': 'supplier-test'}}, 'secret': 'not-a-real-secret'}}, f)
print(step)
"""


class RunThatCannotFinish(unittest.TestCase):
    """A run that cannot finish says so in result.json: never `failed: []` after a crash."""

    def setUp(self):
        self.dir = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.dir, ignore_errors=True)
        # main() hands its --php to the DTD helper through the environment; keep this fake PHP out of other tests.
        environment = mock.patch.dict(os.environ)
        environment.start()
        self.addCleanup(environment.stop)
        self.php = self.dir / 'php'
        self.php.write_text(FAKE_PHP.format(python=sys.executable))
        self.php.chmod(self.php.stat().st_mode | stat.S_IXUSR)
        (self.dir / 'site').mkdir()

    def run_driver(self, url, *extra):
        out = self.dir / 'out'
        code = driver.main(['--wp-path', str(self.dir / 'site'), '--wp-cli', str(self.dir / 'wp-cli.phar'), '--php', str(self.php), '--url', url, '--out', str(out), *extra])
        return code, json.loads((out / 'result.json').read_text()), out

    def test_a_site_that_never_comes_up_fails_before_seeding(self):
        port = driver.free_port()
        code, result, out = self.run_driver('http://127.0.0.1:%d' % port, '--wait', '0.5')
        self.assertEqual(code, 1)
        self.assertIn('site_server_answers', result['failed'])
        self.assertFalse((out / 'wp-cli.log').exists(), 'Nothing was seeded')

    def test_a_connection_error_mid_run_is_a_failure_and_the_fixture_is_still_retired(self):
        closer = _Closer()
        try:
            code, result, out = self.run_driver('http://127.0.0.1:%d' % closer.port, '--wait', '2')
        finally:
            closer.close()
        self.assertEqual(code, 1)
        self.assertNotEqual(result['failed'], [])
        crash = [c for c in result['checks'] if c['check'] == 'run_completed']
        self.assertEqual(len(crash), 1)
        self.assertNotIn('Stopped:', crash[0]['detail']['reason'], 'An exception, not a failed check')
        self.assertIn('== retire', (out / 'wp-cli.log').read_text())


if __name__ == '__main__':
    unittest.main()
