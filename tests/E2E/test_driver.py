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


class Module(unittest.TestCase):
    """The driver as one module that several slices extend at once."""

    def test_each_module_level_name_is_defined_once(self):
        # A second `def` of the same name silently replaces the first, so one slice's callers would run another
        # slice's function.
        import ast
        tree = ast.parse(Path(driver.__file__).read_text())
        names = [node.name for node in tree.body if isinstance(node, (ast.FunctionDef, ast.ClassDef))]
        self.assertEqual(sorted({name for name in names if names.count(name) > 1}), [])


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
        self.assertEqual(driver.path_for('0.5.0', 'review', True), 'review')

    def test_auto_takes_checkout_on_a_build_with_the_punchout_payment_method_whatever_its_number(self):
        # 0.5.0 work in progress still carries the 0.4.23 number; from ticket 76 its cart has no way to the review.
        self.assertEqual(driver.path_for('0.4.23', 'auto', True), 'checkout')
        self.assertEqual(driver.path_for('0.4.23', 'auto', False), 'review')



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


CART = (
    '<div class="wc-proceed-to-checkout"><a href="' + BASE + '/?page_id=258" class="button alt wp-element-button checkout-button wc-forward">Review</a></div>'
    '<form method="post" action="' + BASE + '/punchout/confirm" class="pow-return-form"><button type="submit">Punchout</button></form>'
)
MINI_CART = (
    '<p class="woocommerce-mini-cart__buttons buttons"><a href="' + BASE + '/?page_id=6" class="button wc-forward">View cart</a>'
    '<a href="' + BASE + '/?page_id=258" class="button checkout wc-forward">Review</a></p>'
)
CHECKOUT = (
    '<form name="checkout" method="post" class="checkout woocommerce-checkout" action="' + BASE + '/?page_id=258" enctype="multipart/form-data">'
    '<input type="text" name="billing_first_name" value="Receiving">'
    '<input type="tel" name="billing_phone" value="">'
    '<textarea name="order_comments"></textarea>'
    '<ul class="wc_payment_methods payment_methods methods"><li><input id="payment_method_punchout" type="radio" class="input-radio" name="payment_method" value="punchout" checked=\'checked\' data-order_button_text="Submit" /></li></ul>'
    '<input type="hidden" id="woocommerce-process-checkout-nonce" name="woocommerce-process-checkout-nonce" value="abc123" />'
    '<button type="submit" class="button alt" name="woocommerce_checkout_place_order" id="place_order" value="Submit" data-value="Submit">Submit</button>'
    '</form>'
)


class CheckoutButtons(unittest.TestCase):

    def test_the_cart_proceed_button_is_found_with_its_label_and_target(self):
        self.assertEqual(driver.checkout_anchor(CART, BASE + '/?page_id=300', 'checkout-button'), (BASE + '/?page_id=258', 'Review'))

    def test_the_mini_cart_checkout_button_is_found_by_its_class(self):
        self.assertEqual(driver.checkout_anchor(MINI_CART, BASE + '/', 'checkout'), (BASE + '/?page_id=258', 'Review'))

    def test_a_page_without_the_button_is_none(self):
        self.assertIsNone(driver.checkout_anchor('<a class="button wc-forward" href="/cart">View cart</a>', BASE + '/', 'checkout'))


class CheckoutPage(unittest.TestCase):

    def test_the_checkout_form_is_the_one_named_checkout(self):
        form = driver.checkout_form('<form action="/search"></form>' + CHECKOUT, BASE + '/?page_id=258')
        self.assertEqual(form.attrs.get('name'), 'checkout')

    def test_only_punchout_with_the_label_passes(self):
        form = driver.checkout_form(CHECKOUT, BASE + '/?page_id=258')
        findings = {name: ok for name, ok, _ in driver.checkout_findings(CHECKOUT, form, 'Submit')}
        self.assertEqual(findings, {'checkout_offers_only_the_punchout_method': True, 'punchout_method_carries_the_place_order_label': True, 'place_order_button_shows_the_label_before_any_choice': True})

    def test_a_second_method_fails(self):
        page = CHECKOUT.replace('</ul>', '<li><input type="radio" name="payment_method" value="ozow" data-order_button_text="" /></li></ul>')
        findings = {name: ok for name, ok, _ in driver.checkout_findings(page, driver.checkout_form(page, BASE), 'Submit')}
        self.assertFalse(findings['checkout_offers_only_the_punchout_method'])

    def test_the_default_label_fails(self):
        page = CHECKOUT.replace('Submit', 'Place order')
        findings = {name: ok for name, ok, _ in driver.checkout_findings(page, driver.checkout_form(page, BASE), 'Submit')}
        self.assertFalse(findings['punchout_method_carries_the_place_order_label'])
        self.assertFalse(findings['place_order_button_shows_the_label_before_any_choice'])

    def test_the_submission_is_the_serialised_form_with_the_buyers_entries(self):
        form = driver.checkout_form(CHECKOUT, BASE + '/?page_id=258')
        entries = driver.checkout_submission(form, 'Leave at the gate.')
        self.assertIn(('payment_method', 'punchout'), entries)
        self.assertIn(('order_comments', 'Leave at the gate.'), entries)
        self.assertIn(('woocommerce-process-checkout-nonce', 'abc123'), entries)
        self.assertNotIn('woocommerce_checkout_place_order', [k for k, _ in entries], 'jQuery serialize() sends no button')
        phone = dict(entries)['billing_phone']
        self.assertTrue(phone, 'An empty phone is typed in, as a buyer must when the shop requires it')


class CheckoutAnswer(unittest.TestCase):

    def test_success_with_a_handoff_address_is_read(self):
        body = '{"result":"success","redirect":"http:\\/\\/127.0.0.1:8080\\/punchout\\/handoff?order=7&key=wc_order_x","order_id":7}'
        self.assertEqual(driver.checkout_answer(200, body), ('success', BASE + '/punchout/handoff?order=7&key=wc_order_x', []))

    def test_a_failure_keeps_woocommerces_messages_as_text(self):
        body = '{"result":"failure","messages":"<ul class=\\"woocommerce-error\\"><li>Invalid payment method.<\\/li><\\/ul>","refresh":false,"reload":false}'
        self.assertEqual(driver.checkout_answer(200, body), ('failure', None, ['Invalid payment method.']))

    def test_a_block_themes_single_notice_banner_is_read_as_text(self):
        body = json.dumps({'result': 'failure', 'messages': '<div class="wc-block-components-notice-banner is-error" role="alert"><svg><path d="M1"></path></svg><div class="wc-block-components-notice-banner__content">\n\t&quot;Widget&quot; has no product code.\t</div></div>'})
        self.assertEqual(driver.checkout_answer(200, body), ('failure', None, ['"Widget" has no product code.']))

    def test_a_page_that_is_not_json_is_a_failure(self):
        self.assertEqual(driver.checkout_answer(500, '<html>error</html>')[0], 'unreadable')


class OrderAgainstMessage(unittest.TestCase):

    def test_the_messages_lines_equal_the_orders_lines(self):
        order = [{'sku': 'E2E-ALPHA', 'qty': 2, 'total': '22.00'}, {'sku': 'E2E-BETA', 'qty': 1, 'total': '23.5'}]
        message = {'E2E-ALPHA': {'qty': '2', 'unit': '11.00'}, 'E2E-BETA': {'qty': '1', 'unit': '23.50'}}
        self.assertTrue(driver.order_lines_match(order, message)[0])

    def test_a_different_price_quantity_or_line_set_does_not_match(self):
        order = [{'sku': 'E2E-ALPHA', 'qty': 2, 'total': '22.00'}]
        self.assertFalse(driver.order_lines_match(order, {'E2E-ALPHA': {'qty': '2', 'unit': '10.00'}})[0])
        self.assertFalse(driver.order_lines_match(order, {'E2E-ALPHA': {'qty': '1', 'unit': '11.00'}})[0])
        self.assertFalse(driver.order_lines_match(order, {'E2E-ALPHA': {'qty': '2', 'unit': '11.00'}, 'X': {'qty': '1', 'unit': '1.00'}})[0])


class Lifecycle(unittest.TestCase):
    """0.5.0 order lifecycle (ticket 74): what the second leg reads from pages, answers and the run's e-mail log."""

    def test_a_state_page_is_named_by_its_modifier_class(self):
        page = '<main class="pow-state pow-state--sent"><h1 class="pow-state__title">Sent</h1></main>'
        self.assertEqual(driver.state_page(page), 'sent')
        self.assertIsNone(driver.state_page('<p>This page has already been used.</p>'))

    def test_a_cleared_login_cookie_is_seen_in_the_answers_set_cookie_headers(self):
        cleared = ['wordpress_logged_in_abc=+; expires=Thu, 09 Oct 2025 10:00:00 GMT; Max-Age=0; path=/']
        self.assertTrue(driver.login_cookie_cleared(cleared))
        self.assertTrue(driver.login_cookie_cleared(['wordpress_logged_in_abc=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/']))
        self.assertFalse(driver.login_cookie_cleared(['woocommerce_items_in_cart=1; path=/', 'wordpress_logged_in_abc=user%7C1%7Ctoken%7Chmac; path=/; HttpOnly']))
        self.assertFalse(driver.login_cookie_cleared([]))

    def test_the_apply_coupon_nonce_is_read_from_the_checkout_script_parameters(self):
        page = '<script>var wc_checkout_params = {"ajax_url":"\\/wp-admin\\/admin-ajax.php","apply_coupon_nonce":"0a1b2c3d4e","remove_coupon_nonce":"ffff"};</script>'
        self.assertEqual(driver.apply_coupon_nonce(page), '0a1b2c3d4e')
        self.assertIsNone(driver.apply_coupon_nonce('<p>no script</p>'))

    def test_a_shipping_method_is_chosen_by_its_rate_id_prefix(self):
        page = CHECKOUT.replace('</form>', '<input type="radio" name="shipping_method[0]" value="flat_rate:3" checked="checked"><input type="radio" name="shipping_method[0]" value="free_shipping:1"></form>')
        form = driver.checkout_form(page, BASE)
        self.assertEqual(driver.shipping_choice(form, 'free_shipping:'), {'shipping_method[0]': 'free_shipping:1'})
        self.assertIsNone(driver.shipping_choice(form, 'local_pickup:'))

    def test_the_submission_takes_further_choices(self):
        page = CHECKOUT.replace('</form>', '<input type="radio" name="shipping_method[0]" value="flat_rate:3" checked="checked"><input type="radio" name="shipping_method[0]" value="free_shipping:1"></form>')
        entries = driver.checkout_submission(driver.checkout_form(page, BASE), 'Note', {'shipping_method[0]': 'free_shipping:1'})
        self.assertIn(('shipping_method[0]', 'free_shipping:1'), entries)
        self.assertNotIn(('shipping_method[0]', 'flat_rate:3'), entries)

    def test_e_mails_are_counted_by_kind_for_the_orders_asked_about(self):
        log = '{"id":"pow_quote_received","order":7}\n{"id":"new_order","order":9}\n{"id":"pow_quote_received","order":9}\n\n{"id":"cancelled_order","order":7}\n'
        self.assertEqual(driver.mail_counts(log, [7]), {'pow_quote_received': 1, 'cancelled_order': 1})
        self.assertEqual(driver.mail_counts(log), {'pow_quote_received': 2, 'new_order': 1, 'cancelled_order': 1})
        self.assertEqual(driver.mail_counts('', [7]), {})

    def test_the_expected_note_is_cleaned_of_characters_cxml_cannot_carry_and_capped(self):
        self.assertEqual(driver.cleaned_note('  Gate\x01 code\x0b 7  '), 'Gate code 7')
        self.assertEqual(len(driver.cleaned_note('x' * 2100)), 2000)
        self.assertEqual(driver.cleaned_note('Line one\nLine two\ttab'), 'Line one\nLine two\ttab')

    def test_the_receivers_posts_are_counted_for_one_visits_punchback_address(self):
        captured = [{'path': '/punchout/cxml/%7BA%7D'}, {'path': '/punchout/cxml/%7BB%7D'}, {'path': '/punchout/cxml/%7BA%7D'}]
        self.assertEqual(driver.posts_for(captured, '{A}'), 2)
        self.assertEqual(driver.posts_for(captured, '{C}'), 0)


class LongNoteWithOrderDetails(unittest.TestCase):
    """0.5.0 with tickets 74 and 75 together: a note past 2,000 characters shares DeliveryInstructions with the
    estimate sentence and the order details, and only the note is shortened so that the whole fits."""

    NOTE = 'Gate\x01 code 7.' + ' Use the rear entrance.' * 100
    CLEAN = 'Gate code 7.' + ' Use the rear entrance.' * 100
    LEAD = 'The delivery charge is an estimate.'

    def test_the_note_is_shortened_to_make_room_for_the_order_details(self):
        text = self.CLEAN[:1987] + ' | Exempt: no'
        ok, detail = driver.instructions_fit(text, '', self.NOTE, ['Exempt: no'])
        self.assertTrue(ok, detail)
        self.assertTrue(detail['shortened'])

    def test_the_estimate_sentence_leads_and_the_whole_stays_within_the_limit(self):
        room = 2000 - len(self.LEAD) - len('Exempt: no') - 9
        text = self.LEAD + ' | ' + self.CLEAN[:room].rstrip() + ' | Exempt: no'
        self.assertTrue(driver.instructions_fit(text, self.LEAD, self.NOTE, ['Exempt: no'])[0])

    def test_a_note_that_fits_is_sent_whole(self):
        self.assertEqual(driver.instructions_fit('Short note | Exempt: no', '', 'Short note', ['Exempt: no']), (True, {'length': 23, 'shortened': False}))

    def test_the_order_details_must_not_be_dropped_for_the_note(self):
        self.assertFalse(driver.instructions_fit(self.CLEAN[:2000], '', self.NOTE, ['Exempt: no'])[0])

    def test_the_whole_must_stay_within_the_limit(self):
        self.assertFalse(driver.instructions_fit(self.CLEAN[:1995] + ' | Exempt: no', '', self.NOTE, ['Exempt: no'])[0])

    def test_the_note_must_not_be_shortened_further_than_needed(self):
        self.assertFalse(driver.instructions_fit(self.CLEAN[:1000] + ' | Exempt: no', '', self.NOTE, ['Exempt: no'])[0])

    def test_the_note_must_be_the_one_typed_cleaned(self):
        self.assertFalse(driver.instructions_fit(self.NOTE[:1987] + ' | Exempt: no', '', self.NOTE, ['Exempt: no'])[0])
        self.assertFalse(driver.instructions_fit('Other note | Exempt: no', '', 'Short note', ['Exempt: no'])[0])


CHECKOUT_WITH_EXTRAS = CHECKOUT.replace(
    '<textarea name="order_comments"></textarea>',
    '<input type="hidden" name="h_date" value=""><input type="text" name="e_date" value="" readonly="readonly">'
    '<textarea name="order_comments"></textarea>'
    '<ul id="shipping_method"><li><input type="radio" name="shipping_method[0]" value="free_shipping:1" checked="checked"></li>'
    '<li><input type="radio" name="shipping_method[0]" value="flat_rate:9"></li></ul>',
)

SEED_75 = {
    'connection': {'allcaps': True, 'line_description': 'name_short_description', 'emit_delivery_line': 0},
    'products': {
        'E2E-ALPHA-X': {'id': 1, 'price': '11.00', 'name': 'End-to-end alpha x', 'detail': 'Brand Example & Co Red, 600 ml'},
        'E2E-BETA-X': {'id': 2, 'price': '23.50', 'name': 'End-to-end beta x', 'detail': ''},
    },
    'instructions_expected': ['Exempt: no'],
    'estimate_note': 'The delivery charge is an estimate.',
    'shipping': {'instance_id': 9, 'priced_title': 'Courier E2E x', 'cost': '50.00'},
}


class OrderMessageLeg(unittest.TestCase):
    """0.5.0 ticket 75: the checkout leg sets the connection's message settings and checks them in the cXML."""

    def test_the_delivery_date_is_a_weekday_the_given_days_ahead(self):
        from datetime import date
        self.assertEqual(driver.delivery_date(date(2026, 10, 9), 7), date(2026, 10, 16))
        self.assertEqual(driver.delivery_date(date(2026, 10, 9), 8), date(2026, 10, 19), 'A Saturday moves to Monday')
        self.assertEqual(driver.delivery_date(date(2026, 10, 10), 7), date(2026, 10, 19))

    def test_templates_take_the_date_in_each_format_they_name(self):
        from datetime import date
        day = date(2026, 10, 16)
        self.assertEqual(driver.fill_template('{date:%d-%m-%Y}', day), '16-10-2026')
        self.assertEqual(driver.fill_template('Preferred delivery date: {date:%Y-%m-%d} ({date:%d %B, %Y})', day), 'Preferred delivery date: 2026-10-16 (16 October, 2026)')
        self.assertEqual(driver.fill_template('Attachment: plan.pdf', day), 'Attachment: plan.pdf')

    def test_checkout_extras_are_read_and_a_malformed_file_is_refused(self):
        good = {'date_offset_days': 7, 'fields': {'e_date': '{date:%d %B, %Y}', 'h_date': '{date:%d-%m-%Y}'}, 'order_details': [{'meta_key': '_date_ts', 'label': 'Preferred delivery date', 'format': 'unix_date'}], 'instructions': ['Preferred delivery date: {date:%Y-%m-%d}']}
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'extras.json'
            path.write_text(json.dumps(good))
            self.assertEqual(driver.load_extras(path), good)
            self.assertEqual(driver.load_extras(None), {'date_offset_days': 7, 'fields': {}, 'order_details': [], 'instructions': []})
            for bad in ([], {'fields': []}, {'fields': {'a': 1}}, {'order_details': [{'meta_key': '_k', 'label': 'L'}]}, {'order_details': [{'meta_key': '_k', 'label': 'L', 'format': 'date'}]}, {'instructions': 'x'}, {'date_offset_days': 1}, {'unknown': 1}):
                path.write_text(json.dumps(bad))
                with self.assertRaises(ValueError, msg=json.dumps(bad)):
                    driver.load_extras(path)

    def test_the_submission_posts_the_extra_fields_and_the_chosen_shipping_method(self):
        form = driver.checkout_form(CHECKOUT_WITH_EXTRAS, BASE + '/?page_id=258')
        entries = dict(driver.checkout_submission(form, 'Leave at the gate.', {'e_date': '16 October, 2026', 'h_date': '16-10-2026'}, 'flat_rate:9'))
        self.assertEqual([entries['e_date'], entries['h_date'], entries['shipping_method[0]']], ['16 October, 2026', '16-10-2026', 'flat_rate:9'])
        self.assertEqual(dict(driver.checkout_submission(form, 'x'))['shipping_method[0]'], 'free_shipping:1', 'Without a choice the method the page chose is kept')
        with self.assertRaises(KeyError):
            driver.checkout_submission(form, 'x', {'not_on_the_page': '1'})

    def test_the_priced_method_is_found_by_its_instance(self):
        form = driver.checkout_form(CHECKOUT_WITH_EXTRAS, BASE + '/?page_id=258')
        self.assertEqual(driver.shipping_rate(form, 9), 'flat_rate:9')
        self.assertIsNone(driver.shipping_rate(form, 8))

    def test_without_delivery_cost_the_message_is_the_settings_text_and_no_freight(self):
        expected = driver.checkout_expected(SEED_75, 'Leave at the gate.', ['Preferred delivery date: 2026-10-16'])
        self.assertEqual(expected['descriptions'], {
            'E2E-ALPHA-X': {'description': 'END-TO-END ALPHA X - BRAND EXAMPLE & CO RED, 600 ML', 'short_name': 'END-TO-END ALPHA X'},
            'E2E-BETA-X': {'description': 'END-TO-END BETA X', 'short_name': 'END-TO-END BETA X'},
        })
        self.assertEqual(expected['delivery_instructions'], 'Leave at the gate. | Preferred delivery date: 2026-10-16 | Exempt: no')
        self.assertIsNone(expected['freight'])
        self.assertNotIn('freight_description', expected)

    def test_the_name_only_setting_expects_the_name(self):
        seed = json.loads(json.dumps(SEED_75))
        seed['connection'].update({'line_description': 'name', 'allcaps': False})
        self.assertEqual(driver.checkout_expected(seed, 'n', [])['descriptions']['E2E-ALPHA-X'], {'description': 'End-to-end alpha x', 'short_name': 'End-to-end alpha x'})

    def test_with_delivery_cost_the_priced_method_is_the_freight_line_and_the_estimate_leads(self):
        seed = json.loads(json.dumps(SEED_75))
        seed['connection']['emit_delivery_line'] = 1
        expected = driver.checkout_expected(seed, 'Leave at the gate.', [])
        self.assertEqual(expected['freight'], '50.00')
        self.assertEqual(expected['freight_description'], 'COURIER E2E X — THE DELIVERY CHARGE IS AN ESTIMATE.')
        self.assertEqual(expected['delivery_instructions'], 'The delivery charge is an estimate. | Leave at the gate. | Exempt: no')


CHECKOUT_76 = (
    '<form name="checkout" method="post" class="checkout woocommerce-checkout" action="' + BASE + '/?page_id=258" enctype="multipart/form-data">'
    '<select name="billing_address_book" id="billing_address_book"><option value="a1" selected=\'selected\'>Receiving Desk, 1 Example Street</option><option value="add_new">Add New Address</option></select>'
    '<input type="text" name="billing_first_name" value="Receiving"><input type="text" name="billing_last_name" value="Desk">'
    '<input type="text" name="billing_address_1" value="9 Typed Road"><input type="text" name="billing_address_2" value="">'
    '<input type="text" name="billing_city" value="Example City"><input type="text" name="billing_postcode" value="2001">'
    '<select name="billing_country"><option value="ZA" selected="selected">South Africa</option></select>'
    '<select name="billing_state"><option value="">Select</option><option value="GP" selected="selected">Gauteng</option><option value="WC">Western Cape</option></select>'
    '<input type="tel" name="billing_phone" value=""><input type="email" name="billing_email" value="e2e@example.invalid">'
    '<input id="ship-to-different-address-checkbox" type="checkbox" name="ship_to_different_address" value="1" checked=\'checked\' />'
    '<select name="shipping_address_book" id="shipping_address_book"><option value="a1" selected=\'selected\'>Receiving Desk, 1 Example Street</option><option value="a2">Depot: Dock Two, 2 Depot Road</option><option value="add_new">Add New Address</option></select>'
    '<input type="text" name="shipping_first_name" value="Receiving"><input type="text" name="shipping_last_name" value="Desk">'
    '<input type="text" name="shipping_address_1" value="1 Example Street"><input type="text" name="shipping_address_2" value="">'
    '<input type="text" name="shipping_city" value="Example City"><input type="text" name="shipping_postcode" value="2001">'
    '<select name="shipping_country"><option value="ZA" selected="selected">South Africa</option></select>'
    '<select name="shipping_state"><option value="GP" selected="selected">Gauteng</option><option value="WC">Western Cape</option></select>'
    '<textarea name="order_comments"></textarea>'
    '<ul class="wc_payment_methods"><li><input type="radio" name="payment_method" value="punchout" checked=\'checked\' data-order_button_text="Submit" /></li></ul>'
    '<input type="hidden" name="woocommerce-process-checkout-nonce" value="abc123" />'
    '</form>'
    '<script>var wc_checkout_params = {"update_order_review_nonce":"6045fc68dd","apply_coupon_nonce":"0a1b2c3d4e"};</script>'
)
DEPOT = {'first_name': 'Dock', 'last_name': 'Two', 'company': 'Buyer Co Depot', 'address_1': '2 Depot Road', 'address_2': '', 'city': 'Depot Town', 'state': 'GP', 'postcode': '2001', 'country': 'ZA', 'phone': '', 'address_nickname': 'Depot'}
BILLING = {'first_name': 'Receiving', 'last_name': 'Desk', 'company': 'Buyer Co', 'address_1': '1 Example Street', 'address_2': '', 'city': 'Example City', 'state': 'GP', 'postcode': '2001', 'country': 'ZA', 'email': 'e2e@example.invalid'}


class CheckoutInAVisit(unittest.TestCase):
    """0.5.0 ticket 76: billing from the account, a saved delivery address chosen, refusals read from answers."""

    def form(self, page=CHECKOUT_76):
        return driver.checkout_form(page, BASE + '/?page_id=258')

    def test_the_saved_address_select_is_found_by_the_entries_it_offers(self):
        self.assertEqual(driver.address_book_select(self.form(), ['a1', 'a2']), 'shipping_address_book')
        self.assertIsNone(driver.address_book_select(self.form(), ['a1', 'a3']), 'A select missing a seeded entry is not the book')
        self.assertIsNone(driver.address_book_select(self.form(), []))

    def test_an_address_fills_the_fields_the_form_has_for_its_section(self):
        choice = driver.address_choice(self.form(), 'shipping_', DEPOT)
        self.assertEqual(choice, {'shipping_first_name': 'Dock', 'shipping_last_name': 'Two', 'shipping_address_1': '2 Depot Road', 'shipping_address_2': '', 'shipping_city': 'Depot Town', 'shipping_state': 'GP', 'shipping_postcode': '2001', 'shipping_country': 'ZA'})

    def test_an_address_a_select_does_not_offer_is_refused(self):
        with self.assertRaises(ValueError):
            driver.address_choice(self.form(), 'shipping_', dict(DEPOT, state='XX'))

    def test_a_forced_entry_replaces_what_the_form_sends_or_is_added(self):
        entries = [('a', '1'), ('payment_method', 'punchout'), ('b', '2'), ('payment_method', 'x')]
        self.assertEqual(driver.forced(entries, 'payment_method', 'cheque'), [('a', '1'), ('payment_method', 'cheque'), ('b', '2')])
        self.assertEqual(driver.forced([('a', '1')], 'payment_method', 'cheque'), [('a', '1'), ('payment_method', 'cheque')])

    def test_billing_findings_compare_what_opens_with_the_accounts_saved_billing(self):
        ok, detail = driver.billing_findings(self.form(), BILLING)
        self.assertFalse(ok, 'A typed address in the form is not the saved profile')
        self.assertEqual(detail['differs'], {'address_1': {'shown': '9 Typed Road', 'saved': '1 Example Street'}})
        page = CHECKOUT_76.replace('value="9 Typed Road"', 'value="1 Example Street"')
        ok, detail = driver.billing_findings(self.form(page), BILLING)
        self.assertTrue(ok, detail)
        self.assertIn('email', detail['compared'])
        self.assertNotIn('company', detail['compared'], 'A field the page does not draw is not compared')

    def test_the_order_review_update_posts_typed_billing_and_the_forms_shipping(self):
        entries = dict(driver.order_review_update(self.form(), '6045fc68dd', {'address': '9 Typed Road', 'city': 'Typed City'}))
        self.assertEqual(entries['security'], '6045fc68dd')
        self.assertEqual(entries['payment_method'], 'punchout')
        self.assertEqual([entries['address'], entries['city'], entries['country'], entries['state'], entries['postcode']], ['9 Typed Road', 'Typed City', 'ZA', 'GP', '2001'])
        self.assertEqual([entries['s_address'], entries['s_city'], entries['s_country']], ['1 Example Street', 'Example City', 'ZA'])
        self.assertEqual(entries['ship_to_different_address'], '1')
        self.assertIn('billing_first_name=Receiving', entries['post_data'])
        self.assertEqual(driver.update_order_review_nonce(CHECKOUT_76), '6045fc68dd')
        self.assertIsNone(driver.update_order_review_nonce('<p>no script</p>'))

    def test_ship_to_text_reads_the_ship_to_address_only(self):
        xml = '<cXML><Message><PunchOutOrderMessage><PunchOutOrderMessageHeader><ShipTo><Address><Name>Dock Two</Name><PostalAddress><Street>2 Depot Road</Street><City>Depot Town</City></PostalAddress></Address></ShipTo></PunchOutOrderMessageHeader></PunchOutOrderMessage></Message></cXML>'
        self.assertEqual(driver.ship_to_text(xml), 'Dock Two 2 Depot Road Depot Town')
        self.assertEqual(driver.ship_to_text('<cXML></cXML>'), '')

    def test_a_refusal_is_a_redirect_to_the_landing_page(self):
        landing = BASE + '/?page_id=5'
        self.assertTrue(driver.refused_to(302, {'Location': '/?page_id=5'}, BASE + '/?page_id=258&order-pay=7', landing))
        self.assertFalse(driver.refused_to(200, {}, BASE + '/?page_id=258&order-pay=7', landing), 'The page itself answered')
        self.assertFalse(driver.refused_to(302, {'Location': BASE + '/?page_id=258'}, BASE + '/', landing), 'A redirect elsewhere is not the refusal')

    def test_the_store_apis_refusal_is_read_from_its_json(self):
        self.assertEqual(driver.store_api_answer(403, '{"code":"pow_requisition_only","message":"x","data":{"status":403}}'), (403, 'pow_requisition_only'))
        self.assertEqual(driver.store_api_answer(500, '<html>'), (500, None))

    def test_profile_changes_name_every_entry_that_differs_or_appeared(self):
        before = {'billing_phone': [''], 'wc_address_book_shipping': ['a:2:{...}'], 'first_name': ['Receiving']}
        after = {'billing_phone': ['0100000000'], 'wc_address_book_shipping': ['a:2:{...}'], 'first_name': ['Receiving'], 'wc_address_book_address_shipping_a3': ['a:1:{...}']}
        self.assertEqual(driver.profile_changes(before, after), {'billing_phone': [[''], ['0100000000']], 'wc_address_book_address_shipping_a3': [None, ['a:1:{...}']]})
        self.assertEqual(driver.profile_changes(before, dict(before)), {})

    def test_the_cart_exits_page_draws_the_labelled_checkout_link_and_no_return_button(self):
        page = '<div class="pow-cart-exits"><a href="' + BASE + '/?page_id=258" class="button alt wp-element-button checkout-button wc-forward">Review</a></div>'
        findings = {name: ok for name, ok, _ in driver.cart_exits_findings(page, BASE + '/?page_id=400', 'Review', BASE + '/?page_id=258')}
        self.assertEqual(findings, {'cart_exits_draws_the_checkout_link_with_the_label': True, 'the_return_button_draws_nothing': True})
        old = '<div class="pow-cart-exits"><form method="post" action="' + BASE + '/punchout/confirm" class="pow-return-form"><button type="submit" class="pow-return-button">Punchout</button></form></div>'
        findings = {name: ok for name, ok, _ in driver.cart_exits_findings(old, BASE + '/?page_id=400', 'Review', BASE + '/?page_id=258')}
        self.assertEqual(findings, {'cart_exits_draws_the_checkout_link_with_the_label': False, 'the_return_button_draws_nothing': False})


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
