"""What the driver asserts on the returned PunchOutOrderMessage, proven on a neutral sample."""
import base64
import shutil
import unittest

import poom

GOOD = '''<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/1.2.008/cXML.dtd">
<cXML payloadID="1760000000.1@shop.example.com" timestamp="2026-10-09T12:00:00+00:00" version="1.2.008" xml:lang="en-US"><Header><From><Credential domain="NetworkID"><Identity>supplier-test</Identity></Credential></From><To><Credential domain="NetworkID"><Identity>BUYER-TEST</Identity></Credential></To><Sender><Credential domain="NetworkID"><Identity>supplier-test</Identity></Credential><UserAgent>PunchOut for WooCommerce</UserAgent></Sender></Header><Message deploymentMode="test"><PunchOutOrderMessage><BuyerCookie>{9D41C7A0-3E62-4B85-A1F4-6C0E2B9D5F17}</BuyerCookie><PunchOutOrderMessageHeader operationAllowed="create"><Total><Money currency="ZAR">45.50</Money></Total><ShipTo><Address><Name xml:lang="en-US">Buyer Co</Name><PostalAddress><DeliverTo>Receiving</DeliverTo><Street>1 Example Street</Street><City>Example City</City><State>GP</State><PostalCode>2001</PostalCode><Country isoCountryCode="ZA">South Africa</Country></PostalAddress></Address></ShipTo></PunchOutOrderMessageHeader><ItemIn quantity="2"><ItemID><SupplierPartID>E2E-ALPHA</SupplierPartID><SupplierPartAuxiliaryID>101|0</SupplierPartAuxiliaryID></ItemID><ItemDetail><UnitPrice><Money currency="ZAR">11.00</Money></UnitPrice><Description xml:lang="en-US"><ShortName>Alpha</ShortName>Alpha</Description><UnitOfMeasure>EA</UnitOfMeasure><Classification domain="UNSPSC"></Classification><Extrinsic name="DeliveryInstructions">Deliver to: Buyer Co. Leave at the gate.</Extrinsic></ItemDetail></ItemIn><ItemIn quantity="1"><ItemID><SupplierPartID>E2E-BETA</SupplierPartID><SupplierPartAuxiliaryID>102|0</SupplierPartAuxiliaryID></ItemID><ItemDetail><UnitPrice><Money currency="ZAR">23.50</Money></UnitPrice><Description xml:lang="en-US"><ShortName>Beta</ShortName>Beta</Description><UnitOfMeasure>EA</UnitOfMeasure><Classification domain="UNSPSC"></Classification><Extrinsic name="DeliveryInstructions">Deliver to: Buyer Co. Leave at the gate.</Extrinsic></ItemDetail></ItemIn></PunchOutOrderMessage></Message></cXML>'''

FREIGHT = '<ItemIn quantity="1"><ItemID><SupplierPartID>DELIVERY</SupplierPartID></ItemID><ItemDetail><UnitPrice><Money currency="ZAR">0.00</Money></UnitPrice><Description xml:lang="en-US">Delivery</Description><UnitOfMeasure>EA</UnitOfMeasure><Classification domain="supplier">freight</Classification></ItemDetail></ItemIn></PunchOutOrderMessage>'

EXPECTED = {
    'buyer_cookie': '{9D41C7A0-3E62-4B85-A1F4-6C0E2B9D5F17}',
    'buyer': 'BUYER-TEST',
    'supplier': 'supplier-test',
    'deployment_mode': 'test',
    'currency': 'ZAR',
    'shared_secret': 'not-a-real-secret-0123456789',
    'lines': {'E2E-ALPHA': {'qty': 2, 'unit': '11.00', 'aux': '101|0'}, 'E2E-BETA': {'qty': 1, 'unit': '23.50', 'aux': '102|0'}},
    'freight': None,
    'freight_sku': 'DELIVERY',
    'ship_to_contains': ['1 Example Street', 'Example City'],
    'notes': 'Leave at the gate.',
}

CAPTURE = {'field': 'cxml-base64', 'content_type': 'application/x-www-form-urlencoded'}

PORTED = [
    'field_is_cxml_base64', 'posted_form_urlencoded', 'doctype_1_2_008', 'valid_against_cxml_1_2_008_dtd',
    'payloadID_timestamp', 'header_reversed_identity_only', 'no_shared_secret_in_return', 'deployment_mode_test',
    'buyer_cookie_echoed', 'operation_allowed', 'line E2E-ALPHA', 'line E2E-BETA', 'no_unexpected_lines',
    'every_line_has_uom_and_classification', 'no_freight_line', 'total_equals_sum_of_lines', 'ship_to_present',
    'delivery_notes_carried',
]


def valid(_xml):
    return True, []


def failed(results):
    return [r['check'] for r in results if not r['ok']]


class PoomChecks(unittest.TestCase):

    def run_checks(self, xml=GOOD, capture=CAPTURE, dtd=valid, **expected):
        return poom.check(xml, dict(EXPECTED, **expected), dtd=dtd, capture=capture)

    def test_a_good_return_passes_every_ported_check_in_order(self):
        results = self.run_checks()
        self.assertEqual([r['check'] for r in results], PORTED)
        self.assertEqual(failed(results), [])

    def test_without_a_capture_the_wire_checks_are_left_out(self):
        results = self.run_checks(capture=None)
        self.assertEqual([r['check'] for r in results], PORTED[2:])

    def test_the_wrong_field_or_encoding_fails_the_wire_checks(self):
        results = self.run_checks(capture={'field': 'cxml-urlencoded', 'content_type': 'multipart/form-data; boundary=x'})
        self.assertEqual(failed(results), ['field_is_cxml_base64', 'posted_form_urlencoded'])

    def test_a_dtd_refusal_fails_validation(self):
        results = self.run_checks(dtd=lambda _xml: (False, ['Element ItemIn content does not follow the DTD']))
        self.assertEqual(failed(results), ['valid_against_cxml_1_2_008_dtd'])

    def test_another_declared_version_fails_the_doctype_check(self):
        self.assertIn('doctype_1_2_008', failed(self.run_checks(xml=GOOD.replace('1.2.008/cXML.dtd', '1.2.071/cXML.dtd'))))

    def test_a_shared_secret_element_fails(self):
        xml = GOOD.replace('<Identity>supplier-test</Identity></Credential><UserAgent>', '<Identity>supplier-test</Identity><SharedSecret>x</SharedSecret></Credential><UserAgent>')
        self.assertIn('no_shared_secret_in_return', failed(self.run_checks(xml=xml)))

    def test_the_connection_secret_anywhere_in_the_text_fails(self):
        xml = GOOD.replace('Leave at the gate.', 'Leave at the gate. not-a-real-secret-0123456789', 1)
        self.assertIn('no_shared_secret_in_return', failed(self.run_checks(xml=xml)))

    def test_a_header_that_is_not_reversed_fails(self):
        xml = GOOD.replace('<From><Credential domain="NetworkID"><Identity>supplier-test', '<From><Credential domain="NetworkID"><Identity>BUYER-TEST', 1)
        self.assertEqual(failed(self.run_checks(xml=xml)), ['header_reversed_identity_only'])

    def test_production_mode_fails_the_test_mode_check(self):
        self.assertEqual(failed(self.run_checks(xml=GOOD.replace(' deploymentMode="test"', ''))), ['deployment_mode_test'])

    def test_another_cookie_fails(self):
        self.assertEqual(failed(self.run_checks(buyer_cookie='{other}')), ['buyer_cookie_echoed'])

    def test_a_changed_quantity_or_price_fails_that_line(self):
        self.assertEqual(failed(self.run_checks(lines={'E2E-ALPHA': {'qty': 3, 'unit': '11.00', 'aux': '101|0'}, 'E2E-BETA': {'qty': 1, 'unit': '23.50'}})), ['line E2E-ALPHA'])

    def test_a_missing_or_unexpected_line_fails(self):
        results = self.run_checks(lines={'E2E-ALPHA': {'qty': 2, 'unit': '11.00'}, 'E2E-GAMMA': {'qty': 1, 'unit': '1.00'}})
        self.assertEqual(failed(results), ['line E2E-GAMMA', 'no_unexpected_lines'])

    def test_a_freight_line_fails_when_no_freight_is_expected(self):
        xml = GOOD.replace('</PunchOutOrderMessage>', FREIGHT)
        self.assertEqual(failed(self.run_checks(xml=xml)), ['no_freight_line'])

    def test_an_expected_freight_line_is_checked_instead(self):
        xml = GOOD.replace('</PunchOutOrderMessage>', FREIGHT)
        results = self.run_checks(xml=xml, freight='0.00')
        self.assertIn('freight_line', [r['check'] for r in results])
        self.assertNotIn('no_freight_line', [r['check'] for r in results])
        self.assertEqual(failed(results), [])

    def test_a_total_that_is_not_the_sum_of_the_lines_fails(self):
        self.assertEqual(failed(self.run_checks(xml=GOOD.replace('>45.50<', '>45.51<'))), ['total_equals_sum_of_lines'])

    def test_a_missing_ship_to_fails(self):
        start, end = GOOD.index('<ShipTo>'), GOOD.index('</ShipTo>') + len('</ShipTo>')
        self.assertEqual(failed(self.run_checks(xml=GOOD[:start] + GOOD[end:])), ['ship_to_present'])

    def test_notes_that_did_not_travel_fail(self):
        self.assertEqual(failed(self.run_checks(notes='Ring the bell')), ['delivery_notes_carried'])

    def test_no_notes_expected_drops_that_check(self):
        self.assertNotIn('delivery_notes_carried', [r['check'] for r in self.run_checks(notes=None)])

    def test_markup_that_does_not_parse_is_one_failed_check(self):
        results = self.run_checks(xml='<cXML><Header>')
        self.assertEqual([r['check'] for r in results][-1], 'parses_as_xml')
        self.assertIn('parses_as_xml', failed(results))


    def test_a_document_declaring_entities_is_refused_before_parsing(self):
        xml = GOOD.replace('<!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/1.2.008/cXML.dtd">', '<!DOCTYPE cXML [<!ENTITY a "aaaa">]>')
        results = self.run_checks(xml=xml)
        self.assertEqual(results[-1]['check'], 'parses_as_xml')
        self.assertFalse(results[-1]['ok'])
        with self.assertRaises(ValueError):
            poom.parse_xml(xml)


class HandoffField(unittest.TestCase):

    def test_the_base64_field_decodes_to_the_document(self):
        self.assertEqual(poom.decode_field('cxml-base64', base64.b64encode(GOOD.encode()).decode()), GOOD)

    def test_the_urlencoded_field_is_the_document_itself(self):
        self.assertEqual(poom.decode_field('cxml-urlencoded', GOOD), GOOD)


@unittest.skipUnless(shutil.which('php'), 'php is needed for the exact DTD check')
class ExactDtd(unittest.TestCase):

    def test_the_shipped_dtd_accepts_the_neutral_sample(self):
        self.assertEqual(poom.php_dtd(GOOD), (True, []))

    def test_the_shipped_dtd_refuses_an_item_without_a_unit_of_measure(self):
        ok, errors = poom.php_dtd(GOOD.replace('<UnitOfMeasure>EA</UnitOfMeasure>', '', 1))
        self.assertFalse(ok)
        self.assertTrue(errors)


if __name__ == '__main__':
    unittest.main()
