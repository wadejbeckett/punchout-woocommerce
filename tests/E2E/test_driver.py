"""The driver's own decisions: request shape, start-link handling, the cart's exit and which path a release takes."""
import unittest
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


if __name__ == '__main__':
    unittest.main()
