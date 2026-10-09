"""Checks on a returned PunchOutOrderMessage: what the buyer's purchasing system actually receives.

`check()` answers one list of {check, ok, detail} in a fixed order. The names
are the ones the earlier hand-run validator used, so results stay comparable
across releases; "freight_line" became "no_freight_line" when no delivery cost
is sent, and the header check says what it proves (reversed, identity only)
instead of naming any connection.

Standard library only. The exact-DTD check is delegated (`dtd` callable); the
default shells out to `php tests/E2E/dtd.php`, which resolves the repository's
own unchanged DTD copies and fetches nothing.
"""
import base64
from decimal import Decimal, InvalidOperation
import json
import os
from pathlib import Path
import re
import subprocess
import xml.etree.ElementTree as ET

DTD_SCRIPT = Path(__file__).resolve().parent / 'dtd.php'
DOCTYPE_1_2_008 = re.compile(r'<!DOCTYPE\s+cXML\s+SYSTEM\s+"https?://xml\.cxml\.org/schemas/cXML/1\.2\.008/cXML\.dtd"\s*>')


def php_dtd(xml, php=None):
    """(valid, errors) from the exact official DTD the document declares."""
    p = subprocess.run([php or os.environ.get('POW_E2E_PHP', 'php'), str(DTD_SCRIPT)], input=xml.encode('utf-8'), capture_output=True, timeout=60)
    try:
        result = json.loads(p.stdout.decode('utf-8'))
    except ValueError:
        return False, ['DTD helper failed: ' + p.stderr.decode('utf-8', 'replace')[:300]]
    return bool(result.get('valid')), list(result.get('errors') or [])


def parse_xml(xml):
    """ElementTree root of a document from the fixture. Entity declarations are refused before the parser sees them."""
    if '<!ENTITY' in xml:
        raise ValueError('entity declarations refused')
    return ET.fromstring(xml.encode('utf-8'))


def decode_field(name, value):
    """The document a handoff field carries: base64 is decoded, urlencoded is the form value itself."""
    if name == 'cxml-base64':
        return base64.b64decode(value, validate=True).decode('utf-8')
    return value


def _money(text):
    try:
        return Decimal((text or '').strip())
    except InvalidOperation:
        return None


def check(xml, expected, dtd=php_dtd, capture=None):
    results = []

    def add(name, ok, **detail):
        results.append({'check': name, 'ok': bool(ok), 'detail': detail})

    if capture is not None:
        add('field_is_cxml_base64', capture.get('field') == 'cxml-base64', field=capture.get('field'))
        content_type = capture.get('content_type') or ''
        add('posted_form_urlencoded', content_type.split(';')[0].strip().lower() == 'application/x-www-form-urlencoded', content_type=content_type)

    add('doctype_1_2_008', DOCTYPE_1_2_008.search(xml[:2048]) is not None)
    valid, errors = dtd(xml)
    add('valid_against_cxml_1_2_008_dtd', valid, errors=errors[:5])

    try:
        root = parse_xml(xml)
    except (ET.ParseError, ValueError) as error:
        add('parses_as_xml', False, error=str(error))
        return results

    def text(path):
        return (root.findtext(path) or '').strip()

    add('payloadID_timestamp', bool(root.get('payloadID')) and bool(root.get('timestamp')), payloadID=root.get('payloadID'), timestamp=root.get('timestamp'))
    supplier, buyer = expected['supplier'], expected['buyer']
    header = {party: text('Header/%s/Credential/Identity' % party) for party in ('From', 'To', 'Sender')}
    add('header_reversed_identity_only', header == {'From': supplier, 'To': buyer, 'Sender': supplier}, header=header)

    secret = expected.get('shared_secret') or ''
    add('no_shared_secret_in_return', root.find('.//SharedSecret') is None and (secret == '' or secret not in xml), element=root.find('.//SharedSecret') is not None)

    message = root.find('Message')
    mode = message.get('deploymentMode') if message is not None else None
    want_mode = expected.get('deployment_mode', 'test')
    add('deployment_mode_test', (mode == 'test') if want_mode == 'test' else (mode in (None, 'production')), mode=mode)

    poom = root.find('Message/PunchOutOrderMessage')
    if poom is None:
        add('poom_present', False)
        return results
    add('buyer_cookie_echoed', poom.findtext('BuyerCookie') == expected['buyer_cookie'], got=poom.findtext('BuyerCookie'))
    head = poom.find('PunchOutOrderMessageHeader')
    operation = head.get('operationAllowed') if head is not None else None
    add('operation_allowed', operation in ('create', 'edit', 'inspect'), operation=operation)

    freight_sku = expected.get('freight_sku', 'DELIVERY')
    lines, freight, total = {}, [], Decimal('0')
    for item in poom.findall('ItemIn'):
        sku = (item.findtext('ItemID/SupplierPartID') or '').strip()
        money = item.find('ItemDetail/UnitPrice/Money')
        quantity = _money(item.get('quantity'))
        unit = _money(money.text if money is not None else None)
        if quantity is not None and unit is not None:
            total += quantity * unit
        record = {
            'qty': quantity, 'unit': unit, 'currency': money.get('currency') if money is not None else None,
            'aux': item.findtext('ItemID/SupplierPartAuxiliaryID'), 'uom': (item.findtext('ItemDetail/UnitOfMeasure') or '').strip(),
            'classification': [(c.get('domain'), c.text) for c in item.findall('ItemDetail/Classification')],
        }
        if sku == freight_sku:
            freight.append(record)
        else:
            lines[sku] = record

    currency = expected['currency']
    for sku, want in expected['lines'].items():
        got = lines.get(sku)
        ok = got is not None and got['qty'] == Decimal(str(want['qty'])) and got['unit'] == Decimal(str(want['unit'])) and got['currency'] == currency and (want.get('aux') is None or got['aux'] == want['aux'])
        add('line ' + sku, ok, expected=want, got=None if got is None else {'qty': str(got['qty']), 'unit': str(got['unit']), 'currency': got['currency'], 'aux': got['aux']})
    add('no_unexpected_lines', set(lines) == set(expected['lines']), extra=sorted(set(lines) - set(expected['lines'])), missing=sorted(set(expected['lines']) - set(lines)))
    add('every_line_has_uom_and_classification', bool(lines) and all(r['uom'] and r['classification'] for r in lines.values()))

    if expected.get('freight') is None:
        add('no_freight_line', not freight, freight=len(freight))
    else:
        add('freight_line', len(freight) == 1 and freight[0]['unit'] == Decimal(str(expected['freight'])), freight=[str(r['unit']) for r in freight])

    money = head.find('Total/Money') if head is not None else None
    stated = _money(money.text if money is not None else None)
    add('total_equals_sum_of_lines', money is not None and money.get('currency') == currency and stated == total, total=None if stated is None else str(stated), computed=str(total))

    if expected.get('ship_to_contains') is not None:
        ship = head.find('ShipTo') if head is not None else None
        ship_text = ' '.join(t.strip() for t in ship.itertext()) if ship is not None else ''
        add('ship_to_present', ship is not None and all(s in ship_text for s in expected['ship_to_contains']), ship_to=ship_text[:200])

    if expected.get('notes') is not None:
        notes = expected['notes']
        carried = [e.get('name') for e in poom.iter('Extrinsic') if notes in (e.text or '')] + ['Comments' for c in poom.iter('Comments') if notes in (c.text or '')]
        add('delivery_notes_carried', bool(carried), where=carried[:3])

    return results
