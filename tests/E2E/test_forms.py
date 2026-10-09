"""The driver submits forms the way a browser does: these are the rules it relies on."""
import unittest
from urllib.parse import parse_qsl

from forms import parse_forms, encode


REVIEW = '''
<form method="post" action="/punchout/confirm" enctype="multipart/form-data">
  <input type="hidden" name="pow_nonce" value="n1">
  <input type="hidden" name="pow_return_nonce" value="r1">
  <select name="choice" required>
    <option value="">Select an address</option>
    <option value="account:shipping" selected>Head office</option>
    <option value="account:depot">Depot</option>
  </select>
  <label><input type="radio" name="rates[0]" value="free_shipping:1" checked> Free</label>
  <label><input type="radio" name="rates[0]" value="flat_rate:2"> Flat</label>
  <input type="checkbox" name="acknowledge_unknown" value="1">
  <input type="date" name="preferred_delivery_date" value="">
  <textarea name="notes">
Leave at gate</textarea>
  <input type="file" name="pow_attachment">
  <input type="text" name="ignored" value="x" disabled>
  <button type="submit" name="pow_delivery_action" value="review" formnovalidate>Recalculate</button>
  <button type="submit" name="pow_delivery_action" value="submit">Submit</button>
  <button type="submit" name="pow_delivery_action" value="back">Back</button>
</form>
<form method="post" action="http://127.0.0.1:8080/other"><input type="hidden" name="a" value="&amp;b"></form>
<form action="search"><input name="q" value="x"><button>Go</button></form>
'''


class FormParsing(unittest.TestCase):

    def setUp(self):
        self.forms = parse_forms(REVIEW, 'http://127.0.0.1:8080/punchout/start/abc')

    def test_every_form_is_found_with_its_absolute_action_method_and_enctype(self):
        self.assertEqual([f.action for f in self.forms], ['http://127.0.0.1:8080/punchout/confirm', 'http://127.0.0.1:8080/other', 'http://127.0.0.1:8080/punchout/start/search'])
        self.assertEqual([f.method for f in self.forms], ['POST', 'POST', 'GET'])
        self.assertEqual(self.forms[0].enctype, 'multipart/form-data')
        self.assertEqual(self.forms[1].enctype, 'application/x-www-form-urlencoded')

    def test_only_the_clicked_button_and_the_successful_controls_are_sent(self):
        data = self.forms[0].submission(('pow_delivery_action', 'submit'))
        self.assertEqual(data, [
            ('pow_nonce', 'n1'),
            ('pow_return_nonce', 'r1'),
            ('choice', 'account:shipping'),
            ('rates[0]', 'free_shipping:1'),
            ('preferred_delivery_date', ''),
            ('notes', 'Leave at gate'),
            ('pow_attachment', None),
            ('pow_delivery_action', 'submit'),
        ])

    def test_a_submitter_the_form_does_not_have_is_refused(self):
        with self.assertRaises(KeyError):
            self.forms[0].submission(('pow_delivery_action', 'pay'))

    def test_a_select_with_nothing_selected_sends_its_first_option(self):
        form = parse_forms('<form method="post"><select name="s"><option value="a">A</option><option value="b">B</option></select></form>', 'http://127.0.0.1/x')[0]
        self.assertEqual(form.submission(), [('s', 'a')])

    def test_an_option_without_a_value_sends_its_text(self):
        form = parse_forms('<form method="post"><select name="s"><option selected> Plain </option></select></form>', 'http://127.0.0.1/x')[0]
        self.assertEqual(form.submission(), [('s', 'Plain')])

    def test_a_checked_checkbox_without_a_value_sends_on(self):
        form = parse_forms('<form method="post"><input type="checkbox" name="c" checked></form>', 'http://127.0.0.1/x')[0]
        self.assertEqual(form.submission(), [('c', 'on')])

    def test_choosing_changes_a_value_the_way_a_buyer_would(self):
        data = self.forms[0].submission(('pow_delivery_action', 'submit'), choose={'choice': 'account:depot', 'acknowledge_unknown': '1', 'notes': 'Ring first'})
        self.assertIn(('choice', 'account:depot'), data)
        self.assertIn(('acknowledge_unknown', '1'), data)
        self.assertIn(('notes', 'Ring first'), data)
        self.assertNotIn(('choice', 'account:shipping'), data)

    def test_choosing_an_option_the_select_does_not_offer_is_refused(self):
        with self.assertRaises(ValueError):
            self.forms[0].submission(choose={'choice': 'account:elsewhere'})

    def test_attribute_entities_are_decoded_once(self):
        self.assertEqual(self.forms[1].submission(), [('a', '&b')])

    def test_options_lists_what_a_select_offers(self):
        self.assertEqual(self.forms[0].options('choice'), ['', 'account:shipping', 'account:depot'])
        self.assertEqual(self.forms[0].value('choice'), 'account:shipping')


class Encoding(unittest.TestCase):

    def test_urlencoded_body(self):
        body, content_type = encode([('a', '&b'), ('c', 'd e')], 'application/x-www-form-urlencoded')
        self.assertEqual(content_type, 'application/x-www-form-urlencoded')
        self.assertEqual(parse_qsl(body.decode()), [('a', '&b'), ('c', 'd e')])

    def test_multipart_body_carries_each_field_and_an_empty_file_part(self):
        body, content_type = encode([('a', 'x'), ('f', None)], 'multipart/form-data')
        self.assertTrue(content_type.startswith('multipart/form-data; boundary='))
        boundary = content_type.split('boundary=', 1)[1]
        text = body.decode()
        self.assertIn('--' + boundary + '\r\nContent-Disposition: form-data; name="a"\r\n\r\nx\r\n', text)
        self.assertIn('Content-Disposition: form-data; name="f"; filename=""\r\nContent-Type: application/octet-stream\r\n\r\n\r\n', text)
        self.assertTrue(text.endswith('--' + boundary + '--\r\n'))


if __name__ == '__main__':
    unittest.main()
