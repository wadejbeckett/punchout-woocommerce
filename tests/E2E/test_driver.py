"""The driver's own decisions: request shape, start-link handling, the cart's exit, which path a release takes,
the refusal of the retired review path, and how a run that cannot finish is reported."""
import base64
import hashlib
import json
import os
import re
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
        # 0.5.0 work in progress still carries the 0.4.23 number; once checkout is the way out its cart has no way to the review.
        self.assertEqual(driver.path_for('0.4.23', 'auto', True), 'checkout')
        self.assertEqual(driver.path_for('0.4.23', 'auto', False), 'review')

    def test_the_review_path_is_refused_cleanly(self):
        """0.5.0: the review retired, so a review answer is a refusal with its reason, never a run."""
        self.assertIsNone(driver.refuse_review('checkout', True))
        self.assertIsNone(driver.refuse_review('checkout', False))
        self.assertIn('PunchOut payment method', driver.refuse_review('review', True))
        self.assertIn('0.4.23', driver.refuse_review('review', False))
        self.assertFalse(hasattr(driver.Run, 'review_path'), 'The old review path is gone from the driver')



class Loopback(unittest.TestCase):

    def test_only_the_loopback_address_is_accepted(self):
        driver.require_loopback('http://127.0.0.1:8080')
        for url in ['http://localhost:8080', 'https://shop.example.com', 'http://10.0.0.1']:
            with self.assertRaises(SystemExit):
                driver.require_loopback(url)


class Handoff(unittest.TestCase):

    def test_the_handoff_form_is_read_as_a_browser_would_submit_it(self):
        page = '<form method="post" action="' + BASE + '/receiver/punchout/cxml/%7Bc%7D" id="pow-handoff-form"><input type="hidden" name="cxml-base64" value="PD94bWw+" /><button type="submit" class="pow-handoff-submit">Continue</button></form>'
        form = driver.handoff_form(page, BASE + '/punchout/handoff')
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


CART = (
    '<div class="wc-proceed-to-checkout"><a href="' + BASE + '/?page_id=258" class="button alt wp-element-button checkout-button wc-forward">Review</a></div>'
    '<form method="post" action="' + BASE + '/punchout/return" class="pow-abandon-form"><button type="submit">Cancel and return</button></form>'
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


class HandoffPolicy(unittest.TestCase):
    """Security hardening (0.5.0): the handoff page's headers, as the start page's are checked."""

    SCRIPT = "document.getElementById( 'pow-handoff-form' ).submit();"
    PAGE = ('<html><body style="margin:4em auto"><main class="pow-state pow-state--sending"><p style="color:#666">Ref</p>'
            '<form method="post" action="http://127.0.0.1:9/punchout/cxml/x" id="pow-handoff-form"><input type="hidden" name="cxml-base64" value="PD94"></form></main>'
            '<script>' + SCRIPT + '</script></body></html>')

    def policy(self, receiver="http://127.0.0.1:9"):
        return ("default-src 'none'; script-src " + sha256(self.SCRIPT) + "; style-src 'unsafe-hashes' " + sha256('margin:4em auto') + ' ' + sha256('color:#666')
                + '; form-action ' + receiver + "; frame-ancestors 'none'; base-uri 'none'")

    def findings(self, headers, body=PAGE):
        return {name: ok for name, ok, _ in driver.handoff_policy_findings(headers, body, 'http://127.0.0.1:9')}

    def test_the_hardened_handoff_passes(self):
        self.assertEqual(self.findings({'X-Frame-Options': 'DENY', 'Content-Security-Policy': self.policy()}), {'the_page_cannot_be_framed': True, 'the_page_policy_is_strict_and_posts_only_to_the_receiver': True, 'the_page_policy_names_its_own_script_and_styles_by_hash': True})

    def test_missing_or_loose_headers_fail(self):
        self.assertFalse(self.findings({'Content-Security-Policy': self.policy()})['the_page_cannot_be_framed'])
        self.assertFalse(self.findings({'X-Frame-Options': 'DENY'})['the_page_policy_is_strict_and_posts_only_to_the_receiver'])
        self.assertFalse(self.findings({'X-Frame-Options': 'DENY', 'Content-Security-Policy': self.policy('http://127.0.0.1:10')})['the_page_policy_is_strict_and_posts_only_to_the_receiver'], 'Another origin')
        self.assertFalse(self.findings({'X-Frame-Options': 'DENY', 'Content-Security-Policy': self.policy().replace("'unsafe-hashes'", "'unsafe-inline'")})['the_page_policy_is_strict_and_posts_only_to_the_receiver'])
        self.assertFalse(self.findings({'X-Frame-Options': 'DENY', 'Content-Security-Policy': self.policy().replace(sha256(self.SCRIPT), sha256('other'))})['the_page_policy_names_its_own_script_and_styles_by_hash'])
        self.assertFalse(self.findings({'X-Frame-Options': 'DENY', 'Content-Security-Policy': self.policy()}, self.PAGE.replace('</body>', '<script src="/x.js"></script></body>'))['the_page_policy_names_its_own_script_and_styles_by_hash'], 'An external script is not run')


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
    """0.5.0 order lifecycle: what the second leg reads from pages, answers and the run's e-mail log."""

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
    """0.5.0, the order lifecycle and the message settings together: a note past 2,000 characters shares DeliveryInstructions with the
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
    """0.5.0: the checkout leg sets the connection's message settings and checks them in the cXML."""

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
        good = {'date_offset_days': 7, 'fields': {'e_date': '{date:%d %B, %Y}', 'h_date': '{date:%d-%m-%Y}'}, 'order_details': [{'meta_key': '_date_ts', 'label': 'Preferred delivery date', 'format': 'unix_date'}], 'instructions': ['Preferred delivery date: {date:%Y-%m-%d}'], 'uploads': [{'settings': 'fileCheckout', 'name': 'order-notes.csv', 'content': 'a,b\n1,2\n', 'type': 'text/csv'}],
                'account_writes': [{'settings': 'book', 'nonce': 'delete_security', 'action': 'book_delete', 'fields': {'type': 'shipping', 'name': '{chosen}'}}]}
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'extras.json'
            path.write_text(json.dumps(good))
            self.assertEqual(driver.load_extras(path), good)
            self.assertEqual(driver.load_extras(None), {'date_offset_days': 7, 'fields': {}, 'order_details': [], 'instructions': [], 'uploads': [], 'account_writes': []})
            for bad in ([], {'fields': []}, {'fields': {'a': 1}}, {'order_details': [{'meta_key': '_k', 'label': 'L'}]}, {'order_details': [{'meta_key': '_k', 'label': 'L', 'format': 'date'}]}, {'instructions': 'x'}, {'date_offset_days': 1}, {'unknown': 1},
                        {'uploads': {}}, {'uploads': [{'settings': 'x', 'name': 'a.csv'}]}, {'uploads': [{'settings': '', 'name': 'a.csv', 'content': 'x', 'type': 'text/csv'}]}, {'uploads': [{'settings': 'x', 'name': '../a.csv', 'content': 'x', 'type': 'text/csv'}]}, {'uploads': [{'settings': 'x', 'name': 'a.csv', 'content': 1, 'type': 'text/csv'}]},
                        {'account_writes': {}}, {'account_writes': [{'settings': 'book', 'nonce': 'n', 'action': 'Book Delete', 'fields': {}}]}, {'account_writes': [{'settings': 'book', 'nonce': 'n', 'action': 'book_delete'}]}, {'account_writes': [{'settings': 'book', 'nonce': 'n', 'action': 'book_delete', 'fields': {'type': 1}}]}):
                path.write_text(json.dumps(bad))
                with self.assertRaises(ValueError, msg=json.dumps(bad)):
                    driver.load_extras(path)

    def test_a_scripts_settings_are_read_from_the_checkout_page_with_the_endpoint_made_absolute(self):
        page = CHECKOUT + '<script id="x">\nvar fileCheckout = {"endpoint":"\\/?wc-ajax=file_upload","nonce":"0a1b2c3d4e","maxBytes":1048576,"i18n":{"wait":"Wait"}};\n</script>'
        settings = driver.script_settings(page, 'fileCheckout', BASE)
        self.assertEqual(settings['endpoint'], BASE + '/?wc-ajax=file_upload')
        self.assertEqual(settings['nonce'], '0a1b2c3d4e')
        self.assertIsNone(driver.script_settings(page, 'otherCheckout', BASE), 'A settings object the page does not carry')
        self.assertIsNone(driver.script_settings(CHECKOUT, 'fileCheckout', BASE))

    def test_an_upload_is_a_multipart_post_of_the_nonce_and_the_file(self):
        body, content_type = driver.multipart([('nonce', '0a1b2c3d4e')], [('file', 'order-notes.csv', b'a,b\n1,2\n', 'text/csv')])
        self.assertTrue(content_type.startswith('multipart/form-data; boundary='))
        boundary = content_type.split('boundary=')[1]
        self.assertIn(('--' + boundary + '\r\nContent-Disposition: form-data; name="nonce"\r\n\r\n0a1b2c3d4e\r\n').encode(), body)
        self.assertIn(('Content-Disposition: form-data; name="file"; filename="order-notes.csv"\r\nContent-Type: text/csv\r\n\r\na,b\n1,2\n\r\n').encode(), body)
        self.assertTrue(body.endswith(('--' + boundary + '--\r\n').encode()))

    def test_an_account_write_posts_the_action_the_pages_nonce_and_the_fields_naming_the_saved_entries(self):
        write = {'settings': 'book', 'nonce': 'delete_security', 'action': 'book_delete', 'fields': {'type': 'shipping', 'name': '{chosen}', 'other': '{default}'}}
        entries = driver.account_write_entries(write, {'delete_security': 'abc', 'default_security': 'def'}, {'default': 'a1', 'chosen': 'a2', 'entries': {}})
        self.assertEqual(entries, [('action', 'book_delete'), ('nonce', 'abc'), ('type', 'shipping'), ('name', 'a2'), ('other', 'a1')])
        self.assertEqual(driver.account_write_entries(write, None, None)[1], ('nonce', ''))

    def test_an_upload_answer_is_read_as_success_or_the_refusal(self):
        self.assertEqual(driver.upload_answer(200, '{"success":true,"data":{"name":"order-notes.csv","size":8}}'), (True, 'order-notes.csv', None))
        self.assertEqual(driver.upload_answer(200, '{"success":true,"data":{"pending":{"name":"order-notes.csv","size":"8 B"},"message":"Attached"}}'), (True, 'order-notes.csv', None), 'The name on the one record the answer holds')
        self.assertEqual(driver.upload_answer(200, '{"success":true,"data":{"message":"Attached"}}'), (True, None, None))
        self.assertEqual(driver.upload_answer(400, '{"success":false,"data":{"code":"attachment_type","message":"Not allowed"}}'), (False, None, 'attachment_type'))
        self.assertEqual(driver.upload_answer(200, 'not json'), (False, None, 'unreadable'))

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
    """0.5.0: billing from the account, a saved delivery address chosen, refusals read from answers."""

    def form(self, page=CHECKOUT_76):
        return driver.checkout_form(page, BASE + '/?page_id=258')

    def test_the_saved_address_select_is_found_by_the_entries_it_offers(self):
        self.assertEqual(driver.address_book_select(self.form(), ['a1', 'a2']), 'shipping_address_book')
        self.assertIsNone(driver.address_book_select(self.form(), ['a1', 'a3']), 'A select missing a seeded entry is not the book')
        self.assertIsNone(driver.address_book_select(self.form(), []))

    def test_an_address_fills_the_fields_the_form_has_for_its_section(self):
        choice = driver.address_choice(self.form(), 'shipping_', DEPOT)
        self.assertEqual(choice, {'shipping_first_name': 'Dock', 'shipping_last_name': 'Two', 'shipping_address_1': '2 Depot Road', 'shipping_address_2': '', 'shipping_city': 'Depot Town', 'shipping_state': 'GP', 'shipping_postcode': '2001', 'shipping_country': 'ZA'})

    def test_the_saved_only_form_offers_no_add_new_and_posts_its_address_fields_hidden(self):
        # The 0.5.0 form as 0.5.1 draws it in a visit: no add_new, every address input hidden, the country and state selects hidden inputs.
        page = CHECKOUT_76.replace('<option value="add_new">Add New Address</option>', '')
        page = re.sub(r'<input type="(?:text|tel|email)" name="((?:billing|shipping)_[a-z_0-9]+)"', r'<input type="hidden" name="\1"', page)
        page = re.sub(r'<select name="((?:billing|shipping)_(?:country|state))">.*?<option value="([A-Z]+)" selected="selected">[^<]*</option>.*?</select>', r'<input type="hidden" name="\1" value="\2">', page)
        page = page.replace('</form>', '<input type="radio" name="shipping_method[0]" value="free_shipping:1" checked="checked"></form>')
        findings = {name: (ok, detail) for name, ok, detail in driver.saved_only_findings(self.form(page), 'shipping_address_book')}
        self.assertEqual(findings['no_add_new_choice_in_the_visit'][0], True, findings)
        self.assertEqual(findings['the_address_fields_post_hidden'], (True, {'typable': {}}))
        choice = driver.address_choice(self.form(page), 'shipping_', DEPOT)
        self.assertEqual(choice['shipping_address_1'], '2 Depot Road', 'A hidden field still takes the value a script would set')
        self.assertEqual(choice['shipping_state'], 'GP')

    def test_the_050_form_fails_the_saved_only_findings(self):
        findings = {name: (ok, detail) for name, ok, detail in driver.saved_only_findings(self.form(), 'shipping_address_book')}
        self.assertEqual(findings['no_add_new_choice_in_the_visit'][0], False)
        ok, detail = findings['the_address_fields_post_hidden']
        self.assertFalse(ok)
        self.assertEqual(detail['typable']['shipping_address_1'], 'input[text]')
        self.assertEqual(detail['typable']['shipping_country'], 'select')
        self.assertNotIn('shipping_address_book', detail['typable'], 'The selector itself stays visible')
        without = {name: ok for name, ok, _ in driver.saved_only_findings(self.form(), None)}
        self.assertEqual(list(without), ['the_address_fields_post_hidden'], 'A shop without an address book has no select to check')

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


class StatePages(unittest.TestCase):
    """0.5.0: the state page as the driver reads it, and Cancel and return asking first."""

    PAGE = (
        '<html><body class="page-template-default page page-id-77 logged-out">'
        '<div class="pow-state pow-state--not-sent"><h2 class="pow-state__title">Not &amp; sent</h2>'
        '<p class="pow-state__text">Your cart was <em>not</em> sent.</p>'
        '<div class="pow-state__actions"><a class="pow-state__button" href="/">Continue</a></div></div></body></html>'
    )
    HEADERS = {'Cache-Control': 'private, no-store', 'X-Robots-Tag': 'noindex, nofollow', 'X-Content-Type-Options': 'nosniff', 'Referrer-Policy': 'no-referrer'}

    def test_each_part_of_a_state_is_read_by_its_class(self):
        self.assertEqual(driver.state_page(self.PAGE), 'not-sent')
        self.assertEqual(driver.state_text(self.PAGE, 'title'), 'Not & sent')
        self.assertEqual(driver.state_text(self.PAGE, 'text'), 'Your cart was not sent.')
        self.assertEqual(driver.state_text(self.PAGE, 'button'), 'Continue')
        self.assertIsNone(driver.state_text('<p>nothing</p>', 'title'))

    def test_a_state_page_drawn_in_the_theme_passes_every_finding(self):
        found = {name: ok for name, ok, _ in driver.state_findings(200, self.HEADERS, self.PAGE, 'not-sent', 77)}
        self.assertEqual(found, {'answers_200': True, 'shows_the_not-sent_state': True, 'has_a_title_and_text': True,
                                 'is_not_stored_indexed_or_sniffed': True, 'sends_no_referrer': True, 'draws_inside_the_theme': True})

    def test_a_plain_page_another_state_or_cacheable_headers_fail(self):
        found = {name: ok for name, ok, _ in driver.state_findings(200, dict(self.HEADERS, **{'Cache-Control': 'max-age=60'}), self.PAGE, 'sent', 78)}
        self.assertFalse(found['shows_the_sent_state'])
        self.assertFalse(found['is_not_stored_indexed_or_sniffed'])
        self.assertFalse(found['draws_inside_the_theme'])
        self.assertNotIn('sends_no_referrer', {name for name, _, _ in driver.state_findings(200, self.HEADERS, self.PAGE, 'not-sent')}, 'A plain page is not asked about the theme')

    def test_a_state_address_names_its_page_and_state(self):
        self.assertEqual(driver.state_location(BASE + '/?page_id=77&pow_state=ended'), (77, 'ended'))
        self.assertEqual(driver.state_location('/?pow_state=sent&page_id=77'), (77, 'sent'))
        self.assertEqual(driver.state_location(BASE + '/punchout/sent'), (None, None))
        self.assertEqual(driver.state_location(None), (None, None))

    def test_the_cancel_control_and_its_dialog(self):
        page = (
            '<form method="post" action="/punchout/return" class="pow-abandon-form" data-pow-leave="pow-leave-1">'
            '<input type="hidden" name="pow_mode" value="empty" /><input type="hidden" name="pow_nonce" value="n" />'
            '<button type="submit" class="pow-abandon-button">Cancel and return</button></form>'
            '<dialog id="pow-leave-1" class="pow-leave" aria-labelledby="pow-leave-1-question" open>'
            '<form method="post" action="/punchout/return" class="pow-leave__form"><input type="hidden" name="pow_mode" value="empty" />'
            '<input type="hidden" name="pow_nonce" value="n" /><input type="hidden" name="pow_leave" value="confirmed" />'
            '<p class="pow-leave__question" id="pow-leave-1-question">Leave &amp; go?</p><div class="pow-leave__actions">'
            '<button type="submit" class="pow-leave__confirm">\n Leave\n</button>'
            '<button type="submit" formmethod="dialog" formnovalidate class="pow-leave__stay" autofocus>Stay</button></div></form></dialog>'
        )
        cancel, confirm, dialog = driver.leave_controls(page, BASE + '/?page_id=9')
        self.assertEqual(cancel.action, BASE + '/punchout/return')
        self.assertIn(('pow_leave', 'confirmed'), [(c.name, c.value) for c in confirm.controls if c.name], 'Only the dialog carries the answer')
        self.assertNotIn('pow_leave', [c.name for c in cancel.controls])
        self.assertEqual(dialog, {'id': 'pow-leave-1', 'open': True, 'question': 'Leave & go?', 'confirm': 'Leave', 'stay': 'Stay', 'cancel': 'Cancel and return'})
        closed = page.replace('-question" open>', '-question">')
        self.assertFalse(driver.leave_controls(closed, BASE)[2]['open'])
        at_once = page.split('<dialog')[0].replace(' data-pow-leave="pow-leave-1"', '')
        cancel, confirm, dialog = driver.leave_controls(at_once, BASE)
        self.assertIsNotNone(cancel)
        self.assertEqual((confirm, dialog), (None, None))
        self.assertEqual(driver.leave_controls('<p>no control</p>', BASE), (None, None, None))


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
