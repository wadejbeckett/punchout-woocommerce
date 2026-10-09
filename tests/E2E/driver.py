#!/usr/bin/env python3
"""End-to-end acceptance over real HTTP: a Dynamics-shaped setup request in, the cXML return out.

Opt-in, against a disposable local WordPress/WooCommerce only (see tests/README.md,
"End-to-end HTTP driver"). Standard library only. One run:

  1. seeds a neutral connection, its bound customer account and two products
     (tests/E2E/fixture.php over WP-CLI);
  2. serves the site on 127.0.0.1 with PHP's built-in server (or uses --url);
  3. POSTs a PunchOutSetupRequest in the exact one-line shape a Dynamics 365
     tenant sends, whose BrowserFormPost is this driver's own loopback receiver;
  4. redeems the StartPage the way a browser does: a GET that redirects is a
     0.4.22 redeem; a page that posts itself back (0.4.23+) is submitted by
     POST (--redeem picks a method outright);
  5. adds both products with wc-ajax=add_to_cart;
  6. leaves through the release's own path: the cart's Punchout exit and the
     review's Submit (0.4.x), or (0.5.0) the cart's checkout button to
     WooCommerce's classic checkout, where PunchOut is the only payment method
     and its button is the place-order label, then the GET handoff the
     checkout answer redirects to (`Run.checkout_path`). The 0.5.0 leg also
     sets the connection's message settings (line description with the short
     description, "Order details sent", and with --send-delivery-cost the
     freight line) and checks each in the cXML; --checkout-extras names the
     fixture's own checkout fields (a delivery date) and what they must send;
  7. reads the handoff page's form, submits it to the receiver as the browser's
     auto-post would, decodes the cXML and checks it (tests/E2E/poom.py,
     exact 1.2.008 DTD through tests/E2E/dtd.php);
  8. repeats the Submit as a double click would and expects the same handoff,
     then reads back the visit and its order (fixture.php inspect) and retires
     the connection.

It never talks to anything but 127.0.0.1, prints no secret and writes its
evidence (the seed, which holds the generated secret, included) to a private
run directory, mode 700.
"""
import argparse
import base64
import copy
from datetime import datetime, timedelta, timezone
from decimal import Decimal
import hashlib
import html as htmllib
from html.parser import HTMLParser
import http.cookiejar
import http.server
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from xml.sax.saxutils import escape, quoteattr

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import forms  # noqa: E402
import poom  # noqa: E402

LOOPBACK = '127.0.0.1'
USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) pow-e2e-driver'


# ---------------------------------------------------------------------------
# Pure decisions (unit-tested in test_driver.py)
# ---------------------------------------------------------------------------

def require_loopback(url):
    parts = urllib.parse.urlsplit(url)
    if parts.scheme != 'http' or parts.hostname != LOOPBACK:
        sys.exit('Refusing ' + url + ': the driver only talks to http://' + LOOPBACK)


def setup_request(*, payload_id, timestamp, buyer, supplier, secret, email, cookie, supplier_setup, receiver):
    """The one-line PunchOutSetupRequest a Dynamics 365 tenant sends (tests/Unit/ParserTest.php, neutral identities).

    No DOCTYPE, braced-GUID payloadID and BuyerCookie, a timestamp without an
    offset, the SupplierSetup URL with the trailing space the tenant pasted, the
    e-mail extrinsic labelled "User email" before BuyerCookie, and a punchback
    URL whose last segment is the percent-encoded cookie.
    """
    def credential(identity, shared=None):
        inner = '<Identity>' + escape(identity) + '</Identity>'
        if shared is not None:
            inner += '<SharedSecret>' + escape(shared) + '</SharedSecret>'
        return '<Credential domain="NetworkID">' + inner + '</Credential>'

    punchback = receiver + urllib.parse.quote(cookie, safe='')
    return (
        '<?xml version="1.0" encoding="utf-8"?>'
        '<cXML payloadID=' + quoteattr(payload_id) + ' timestamp=' + quoteattr(timestamp) + ' version="1.2.008" xml:lang="en-US">'
        '<Header><From>' + credential(buyer) + '</From><To>' + credential(supplier) + '</To>'
        '<Sender>' + credential(buyer, secret) + '<UserAgent>Dynamics 365 for Operations</UserAgent></Sender></Header>'
        '<Request deploymentMode="test"><PunchOutSetupRequest operation="create">'
        '<SupplierSetup><URL>' + escape(supplier_setup) + ' </URL></SupplierSetup>'
        '<Extrinsic name="User email">' + escape(email) + '</Extrinsic>'
        '<BuyerCookie>' + escape(cookie) + '</BuyerCookie>'
        '<BrowserFormPost><URL>' + escape(punchback) + '</URL></BrowserFormPost>'
        '</PunchOutSetupRequest></Request></cXML>'
    )


def start_outcome(status, headers, body, url):
    """What a browser does with the StartPage answer: ('redeemed', target), ('post', form) or ('refused', detail)."""
    if status in (301, 302, 303, 307, 308) and headers.get('Location'):
        return 'redeemed', urllib.parse.urljoin(url, headers.get('Location'))
    if status == 200:
        for form in forms.parse_forms(body, url):
            if form.method == 'POST' and form.action.split('#')[0] == url.split('#')[0]:
                return 'post', form
    return 'refused', status


def cart_exit(html, base_url):
    """The cart's PunchOut exit: the classic return form, the Cart block's configured link, or None."""
    for form in forms.parse_forms(html, base_url):
        if 'pow-return-form' in (form.attrs.get('class') or '').split():
            return 'form', form
    match = re.search(r'window\.powCartBlocks\s*=\s*(\{.*?\});', html)
    if match:
        try:
            config = json.loads(match.group(1))
        except ValueError:
            config = {}
        if config.get('restricted') is True and isinstance(config.get('confirmUrl'), str):
            return 'link', urllib.parse.urljoin(base_url, config['confirmUrl'])
    return None


def at_least(version, floor):
    """True when the release number `version` ('0.4.23') is `floor` ((0, 4, 23)) or later."""
    return tuple(int(p) for p in re.findall(r'\d+', version)[:3]) >= floor


def path_for(version, flag):
    """Which exit a release takes: the review (0.4.x) or WooCommerce checkout (0.5.0 and later)."""
    if flag in ('review', 'checkout'):
        return flag
    return 'checkout' if at_least(version, (0, 5, 0)) else 'review'


def _policy(header):
    """A Content-Security-Policy header as {directive: [sources]}."""
    directives = {}
    for part in (header or '').split(';'):
        words = part.split()
        if words:
            directives[words[0].lower()] = words[1:]
    return directives


def _sha256(text):
    return "'sha256-" + base64.b64encode(hashlib.sha256(text.encode('utf-8')).digest()).decode('ascii') + "'"


def start_page_findings(status, headers, body, url):
    """0.4.23 and later: a GET of the start link answers a page that posts itself back, never stored, transformed,
    indexed or framed, whose policy runs nothing but the page's own inline script and style. [(check, ok, detail)]"""
    kind, _ = start_outcome(status, headers, body, url)
    cache = (headers.get('Cache-Control') or '').lower()
    robots = (headers.get('X-Robots-Tag') or '').lower()
    policy = _policy(headers.get('Content-Security-Policy'))
    scripts = re.findall(r'<script>(.*?)</script>', body, re.S)
    styles = re.findall(r'<style>(.*?)</style>', body, re.S)
    strict = (policy.get('default-src') == ["'none'"] and policy.get('form-action') == ["'self'"] and policy.get('frame-ancestors') == ["'none'"]
              and policy.get('base-uri') == ["'none'"] and not any('unsafe' in source or source == '*' for sources in policy.values() for source in sources))
    named = (bool(scripts) and all(_sha256(script) in policy.get('script-src', []) for script in scripts)
             and all(_sha256(style) in policy.get('style-src', []) for style in styles)
             and re.search(r'<script\s[^>]*>|\sstyle="|\son[a-z]+=', re.sub(r'<(script|style)>.*?</\1>', '', body, flags=re.S), re.I) is None)
    return [
        ('a_get_of_the_start_link_answers_a_page_that_posts_itself_back', kind == 'post', {'http': status, 'outcome': kind}),
        ('the_start_page_is_not_stored', 'no-store' in cache, {'cache_control': cache}),
        ('the_start_page_is_not_indexed', 'noindex' in robots, {'x_robots_tag': robots}),
        ('the_start_page_is_not_transformed', 'no-transform' in cache, {'cache_control': cache}),
        ('the_start_page_cannot_be_framed', (headers.get('X-Frame-Options') or '').upper() == 'DENY', {'x_frame_options': headers.get('X-Frame-Options')}),
        ('the_start_page_policy_is_strict', strict, {'policy': headers.get('Content-Security-Policy')}),
        ('the_start_page_policy_allows_its_own_script_and_style_by_hash', named, {'scripts': len(scripts), 'styles': len(styles)}),
    ]


def _text(fragment):
    return re.sub(r'\s+', ' ', htmllib.unescape(re.sub(r'<[^>]+>', '', fragment))).strip()


def review_findings(page, labels, freight, priced_titles):
    """0.4.23 and later, the review page: its words are the settings, it has no date field, and when the connection
    sends no delivery line (`freight` False) it shows no estimate row, no amount beside a delivery method and no tax
    wording, while still offering each priced method by its own title. [(check, ok, detail)]"""
    start = page.find('class="pow-confirmation')
    end = page.find('</form>', start)
    area = page[start:end if end > start else len(page)] if start >= 0 else ''
    submit = re.search(r'<button type="submit" name="pow_delivery_action" value="submit"[^>]*>([^<]*)</button>', area)
    items = re.search(r'<h2 id="pow-items-title">([^<]*)</h2>', area)
    total = re.search(r'<dt class="pow-confirmation__total">([^<]*)</dt>', area)
    shown = {key: _text(match.group(1)) if match else None for key, match in (('submit', submit), ('items', items), ('total', total))}
    lowered = page.lower()
    findings = [
        ('review_submit_label_is_the_setting', shown['submit'] == labels['submit'], {'shown': shown['submit'], 'setting': labels['submit']}),
        ('review_items_heading_is_the_setting', shown['items'] == labels['items'], {'shown': shown['items'], 'setting': labels['items']}),
        ('review_total_label_is_the_setting', shown['total'] == labels['total'], {'shown': shown['total'], 'setting': labels['total']}),
        ('review_has_no_date_input', 'type="date"' not in lowered and 'preferred_delivery_date' not in lowered, {}),
    ]
    if freight:
        return findings
    summary = re.search(r'<aside class="pow-confirmation__summary".*?</dl>', area, re.S)
    rows = summary.group(0).count('<dt') if summary else 0
    methods = re.findall(r'<label class="pow-confirmation__rate"><input type="radio"[^>]*>\s*<span>(.*?)</span></label>', area, re.S)
    priced = [m for m in methods if 'Price-amount' in m or 'Price-currencySymbol' in m or re.search(r'\d[.,]\d{2}(?!\d)', _text(m))]
    titles = [_text(m) for m in methods]
    update = re.search(r'<button type="submit" name="pow_delivery_action" value="review"[^>]*>([^<]*)</button>', area)
    findings += [
        ('review_has_no_estimate_row', 'Delivery estimate' not in area and 'pow-confirmation__estimate-note' not in area and rows == 1, {'summary_rows': rows}),
        ('review_method_list_has_no_amount', bool(methods) and not priced, {'methods': titles, 'priced': [_text(m) for m in priced]}),
        ('review_offers_the_priced_method_by_its_title', all(title in titles for title in priced_titles), {'methods': titles, 'expected': list(priced_titles)}),
        ('review_has_no_tax_sentence', re.search(r'\b(tax|vat)\b', _text(area), re.I) is None, {}),
        ('review_update_control_is_neutral', update is not None and _text(update.group(1)) == 'Update delivery' and 'recalculat' not in area.lower(), {'shown': _text(update.group(1)) if update else None}),
    ]
    return findings


class _Anchors(HTMLParser):
    def __init__(self, base_url):
        super().__init__(convert_charrefs=True)
        self.base_url = base_url
        self.found = []
        self.open = None

    def handle_starttag(self, tag, attrs):
        if tag == 'a':
            attrs = {k: (v or '') for k, v in attrs}
            self.open = {'href': urllib.parse.urljoin(self.base_url, attrs.get('href', '')), 'classes': attrs.get('class', '').split(), 'text': ''}
            self.found.append(self.open)

    def handle_endtag(self, tag):
        if tag == 'a':
            self.open = None

    def handle_data(self, data):
        if self.open is not None:
            self.open['text'] += data


def checkout_anchor(html, base_url, css_class):
    """(href, label) of the first link carrying `css_class`: the cart's proceed button ('checkout-button') or the
    Cart widget's checkout button ('checkout'). None when the page has none."""
    parser = _Anchors(base_url)
    parser.feed(html)
    parser.close()
    for anchor in parser.found:
        if css_class in anchor['classes']:
            return anchor['href'], ' '.join(anchor['text'].split())
    return None


def checkout_form(html, base_url):
    """WooCommerce's classic checkout form (name="checkout"), or None."""
    for form in forms.parse_forms(html, base_url):
        if form.attrs.get('name') == 'checkout':
            return form
    return None


def checkout_findings(page, form, place_order_label):
    """0.5.0, the classic checkout in a visit: the PunchOut method is the only one offered, it carries the place-order
    label setting, and the button already shows that label before any method is chosen. [(check, ok, detail)]"""
    methods = [c for c in form.controls if c.name == 'payment_method' and c.type == 'radio']
    offered = [c.value for c in methods]
    punchout = [c for c in methods if c.value == 'punchout']
    button = re.search(r'<button[^>]*\bid="place_order"[^>]*>(.*?)</button>', page, re.S)
    shown = _text(button.group(1)) if button else None
    return [
        ('checkout_offers_only_the_punchout_method', offered == ['punchout'], {'offered': offered}),
        ('punchout_method_carries_the_place_order_label', bool(punchout) and punchout[0].attrs.get('data-order_button_text') == place_order_label, {'label': punchout[0].attrs.get('data-order_button_text') if punchout else None, 'setting': place_order_label}),
        ('place_order_button_shows_the_label_before_any_choice', shown == place_order_label, {'shown': shown, 'setting': place_order_label}),
    ]


def checkout_submission(form, note, extra=None, shipping=None):
    """What WooCommerce's checkout script posts: the form serialised (no button, as jQuery's serialize()), with the
    PunchOut method chosen, the buyer's note, and a phone typed in when the shop asks for one and the account has none.
    `extra` holds further fields or choices on the page (another checkout plugin's fields, a shipping method; a
    KeyError if the page has no such field); `shipping` picks the first package's shipping method."""
    choose = {'payment_method': 'punchout', 'order_comments': note}
    if any(c.name == 'billing_phone' for c in form.controls) and not form.value('billing_phone'):
        choose['billing_phone'] = '0100000000'
    choose.update(extra or {})
    if shipping is not None:
        choose['shipping_method[0]'] = shipping
    return [(name, '' if value is None else value) for name, value in form.submission(None, choose)]


def shipping_rate(form, instance_id):
    """The first package's radio value for the shipping method instance `instance_id` ('<method>:<id>'), or None."""
    for control in form.controls:
        if control.name == 'shipping_method[0]' and (control.value or '').endswith(':' + str(instance_id)):
            return control.value
    return None


# -- 0.5.0 ticket 75: what the connection screen sets, checked in the order's cXML ---------------------------

EXTRAS_DEFAULT = {'date_offset_days': 7, 'fields': {}, 'order_details': [], 'instructions': []}
_DATE_TEMPLATE = re.compile(r'\{date:([^}]*)\}')


def delivery_date(today, offset_days):
    """The run's delivery date: `offset_days` after `today`, moved on to the Monday when that is a weekend day."""
    day = today + timedelta(days=offset_days)
    while day.weekday() >= 5:
        day += timedelta(days=1)
    return day


def fill_template(text, day):
    """`text` with every {date:<strftime format>} replaced by `day` in that format."""
    return _DATE_TEMPLATE.sub(lambda match: day.strftime(match.group(1)), text)


def load_extras(path):
    """The fixture's own checkout extras (--checkout-extras, a JSON file kept with the fixture, not here), so this
    repository names no other plugin: the fields a checkout plugin draws, posted with the run's delivery date
    ("fields": {name: template}); the order details its order meta adds to the connection ("order_details": [{meta_key,
    label, format}]); and the text each must reach DeliveryInstructions as ("instructions": [template]). Templates may
    hold {date:<strftime format>}; the date is "date_offset_days" (at least 2) ahead, on a weekday. None reads as no
    extras. ValueError for anything else."""
    if path is None:
        return copy.deepcopy(EXTRAS_DEFAULT)
    data = json.loads(Path(path).read_text())
    if not isinstance(data, dict) or set(data) - set(EXTRAS_DEFAULT):
        raise ValueError('Checkout extras must be an object with only ' + ', '.join(EXTRAS_DEFAULT))
    extras = dict(copy.deepcopy(EXTRAS_DEFAULT), **data)
    fields, details, lines, offset = extras['fields'], extras['order_details'], extras['instructions'], extras['date_offset_days']
    if not isinstance(fields, dict) or not all(isinstance(k, str) and isinstance(v, str) for k, v in fields.items()):
        raise ValueError('"fields" must map field names to text')
    if not isinstance(details, list) or not all(isinstance(d, dict) and set(d) == {'meta_key', 'label', 'format'} and all(isinstance(v, str) for v in d.values()) and d['format'] in ('text', 'unix_date') for d in details):
        raise ValueError('"order_details" must list {meta_key, label, format text or unix_date}')
    if not isinstance(lines, list) or not all(isinstance(line, str) for line in lines):
        raise ValueError('"instructions" must list text')
    if not isinstance(offset, int) or isinstance(offset, bool) or offset < 2:
        raise ValueError('"date_offset_days" must be a whole number of days, at least 2')
    return extras


def checkout_expected(seed, note, extra_lines):
    """What the order's cXML must carry under the seeded connection's settings: each line's Description (the name,
    or the name, " - " and the product's short description as the seed says it reads in plain text) and ShortName (the
    name), in capitals for an all-caps connection; DeliveryInstructions exactly (the estimate sentence when delivery
    cost is sent, the note, then the extras' and the seed's order details, joined by " | "); and the freight line, the
    priced method the run chose, only when delivery cost is sent."""
    connection = seed['connection']
    caps = (lambda text: text.upper()) if connection.get('allcaps') else (lambda text: text)
    with_short = connection.get('line_description') == 'name_short_description'
    descriptions = {}
    for sku, product in seed['products'].items():
        text = product['name'] + ' - ' + product['detail'] if with_short and product.get('detail') else product['name']
        descriptions[sku] = {'description': caps(text), 'short_name': caps(product['name'])}
    freight = bool(connection.get('emit_delivery_line'))
    parts = ([seed['estimate_note']] if freight else []) + [note] + list(extra_lines) + list(seed.get('instructions_expected') or [])
    expected = {'descriptions': descriptions, 'delivery_instructions': ' | '.join(part for part in parts if part), 'freight': None}
    if freight:
        expected['freight'] = seed['shipping']['cost']
        expected['freight_description'] = caps(seed['shipping']['priced_title'] + ' \u2014 ' + seed['estimate_note'])
    return expected


def checkout_answer(status, body):
    """(result, redirect, messages) from WooCommerce's wc-ajax=checkout answer; 'unreadable' when it is not its JSON."""
    try:
        answer = json.loads(body)
    except ValueError:
        return 'unreadable', None, [_text(body)[:300]]
    if not isinstance(answer, dict):
        return 'unreadable', None, []
    notices = answer.get('messages') or ''
    # A classic theme lists each notice; a block theme's banner lists several, or holds one as its content.
    messages = [_text(m) for m in re.findall(r'<li[^>]*>(.*?)</li>', notices, re.S)]
    if not messages:
        messages = [_text(m) for m in re.findall(r'<div class="wc-block-components-notice-banner__content">(.*?)</div>', notices, re.S)]
    return answer.get('result'), answer.get('redirect'), messages


def order_lines_match(order_lines, message_lines):
    """(ok, detail): the message carries exactly the order's lines, each with the order's quantity and its ex-tax line
    total divided by that quantity as the unit price."""
    want = {}
    for line in order_lines:
        quantity = Decimal(str(line['qty']))
        want[line['sku']] = {'qty': quantity, 'unit': (Decimal(str(line['total'])) / quantity).quantize(Decimal('0.01'))}
    got = {sku: {'qty': Decimal(str(v['qty'])), 'unit': Decimal(str(v['unit']))} for sku, v in message_lines.items()}
    return want == got, {'order': {k: {'qty': str(v['qty']), 'unit': str(v['unit'])} for k, v in want.items()}, 'message': message_lines}


def state_page(html):
    """The state a PunchOut state page shows ('sent', 'not-sent', ...), from its `pow-state--<state>` class; None."""
    match = re.search(r'class="[^"]*\bpow-state--([a-z-]+)', html)
    return match.group(1) if match else None


def state_text(html, part):
    """The text of the first element carrying `pow-state__<part>` (title, text, actions, button), tags removed and
    entities decoded; None when there is none."""
    match = re.search(r'<(\w+)[^>]*\bclass="[^"]*\bpow-state__' + re.escape(part) + r'\b[^"]*"[^>]*>(.*?)</\1>', html or '', re.S)
    return _text(match.group(2)) if match else None


def state_findings(status, headers, body, state, page_id=None):
    """0.5.0 (ticket 77), a state the plugin drew: the state's modifier, a title and a text, and headers that keep it
    out of caches, indexes and sniffing. With `page_id`, the site's state page: drawn inside the theme as that page
    (the theme's body class names it) and giving away no referrer. [(check, ok, detail)]"""
    def header(name):
        return (headers.get(name) or '').lower() if headers is not None else ''
    found = [
        ('answers_200', status == 200, {'http': status}),
        ('shows_the_' + state + '_state', state_page(body) == state, {'state': state_page(body)}),
        ('has_a_title_and_text', bool(state_text(body, 'title')) and bool(state_text(body, 'text')), {'title': state_text(body, 'title'), 'text': state_text(body, 'text')}),
        ('is_not_stored_indexed_or_sniffed', 'no-store' in header('Cache-Control') and 'noindex' in header('X-Robots-Tag') and header('X-Content-Type-Options') == 'nosniff',
         {'cache_control': header('Cache-Control'), 'robots': header('X-Robots-Tag'), 'nosniff': header('X-Content-Type-Options')}),
    ]
    if page_id is not None:
        found.append(('sends_no_referrer', header('Referrer-Policy') == 'no-referrer', {'referrer_policy': header('Referrer-Policy')}))
        found.append(('draws_inside_the_theme', re.search(r'<body[^>]*class="[^"]*\bpage-id-%d\b' % int(page_id), body or '') is not None, {'page_id': page_id}))
    return found


def state_location(url):
    """(page id, state) a state page address names (?page_id=N&pow_state=S); (None, None) for anything else."""
    query = urllib.parse.parse_qs(urllib.parse.urlsplit(url or '').query)
    if 'pow_state' not in query:
        return None, None
    page = query.get('page_id', [None])[0]
    return (int(page) if page and page.isdigit() else None), query['pow_state'][0]


def leave_controls(html, base_url):
    """0.5.0 (ticket 77): the cancel control's form, its dialog's form and the dialog's facts ({id, open, question,
    confirm, stay, cancel}); (form, None, None) when the control leaves at once; (None, None, None) without it."""
    found = forms.parse_forms(html or '', base_url)
    cancel = next((f for f in found if 'pow-abandon-form' in (f.attrs.get('class') or '').split()), None)
    if cancel is None:
        return None, None, None
    dialog_id = cancel.attrs.get('data-pow-leave')
    match = re.search(r'<dialog id="' + re.escape(dialog_id) + r'"([^>]*)>(.*?)</dialog>', html, re.S) if dialog_id else None
    if not match:
        return cancel, None, None
    attrs, inner = match.groups()
    confirm = next((f for f in forms.parse_forms(inner, base_url) if 'pow-leave__form' in (f.attrs.get('class') or '').split()), None)
    question = re.search(r'class="pow-leave__question"[^>]*>(.*?)</p>', inner, re.S)
    buttons = {name: _text(text) for name, text in re.findall(r'<button[^>]*class="pow-leave__(confirm|stay)"[^>]*>(.*?)</button>', inner, re.S)}
    label = re.search(r'<form[^>]*class="pow-abandon-form"[^>]*>.*?<button[^>]*>(.*?)</button>', html, re.S)
    return cancel, confirm, {
        'id': dialog_id, 'open': re.search(r'\sopen(\s|=|$)', attrs) is not None, 'question': _text(question.group(1)) if question else None,
        'confirm': buttons.get('confirm'), 'stay': buttons.get('stay'), 'cancel': _text(label.group(1)) if label else None,
    }


def login_cookie_cleared(set_cookies):
    """Whether any of an answer's Set-Cookie headers expires the WordPress login cookie in the browser."""
    for header in set_cookies or []:
        name, _, rest = header.partition('=')
        if not name.strip().startswith('wordpress_logged_in_'):
            continue
        value = rest.split(';')[0].strip()
        lowered = header.lower()
        if 'max-age=0' in lowered or value in ('', '+', 'deleted') or re.search(r'expires=[^;]*\b19[0-9]{2}\b', lowered):
            return True
    return False


def apply_coupon_nonce(html):
    """The nonce WooCommerce's checkout script sends with wc-ajax=apply_coupon, or None."""
    match = re.search(r'"apply_coupon_nonce"\s*:\s*"([0-9a-f]+)"', html)
    return match.group(1) if match else None


def shipping_choice(form, prefix):
    """{shipping_method[i]: rate id} choosing, in each package, the rate whose id starts with `prefix`; None when a
    package offers none."""
    groups = {}
    for control in form.controls:
        if control.type == 'radio' and (control.name or '').startswith('shipping_method['):
            groups.setdefault(control.name, []).append(control.value or '')
    choice = {}
    for name, values in groups.items():
        wanted = [v for v in values if v.startswith(prefix)]
        if not wanted:
            return None
        choice[name] = wanted[0]
    return choice or None


def mail_counts(log, order_ids=None):
    """{e-mail id: count} from the run's e-mail log (one JSON object per line), for the given orders or all."""
    counts = {}
    for line in (log or '').splitlines():
        if not line.strip():
            continue
        entry = json.loads(line)
        if order_ids is not None and entry.get('order') not in order_ids:
            continue
        counts[entry.get('id')] = counts.get(entry.get('id'), 0) + 1
    return counts


_NOT_XML = re.compile('[^\t\n\r\x20-퟿-�\U00010000-\U0010ffff]')


def cleaned_note(note, limit=2000):
    """The buyer's note as the purchasing system must receive it: characters outside XML removed, trimmed, capped."""
    clean = _NOT_XML.sub('', note).strip()
    return clean[:limit].rstrip() if len(clean) > limit else clean


INSTRUCTIONS_SEPARATOR = ' | '


def instructions_fit(text, lead, note, details, limit=2000):
    """(ok, detail): `text` is DeliveryInstructions as 0.5.0 sends them: `lead` (the estimate sentence, or '' when no
    freight line is sent), the buyer's note cleaned, then each order detail ("Label: value"), joined by " | ", within
    `limit` characters, the note alone shortened to fit, and no further than two separators' room and its trailing
    space."""
    sep = INSTRUCTIONS_SEPARATOR
    head = lead + sep if lead else ''
    tail = sep + sep.join(details) if details else ''
    clean = cleaned_note(note, limit=len(note))
    sent = text[len(head):len(text) - len(tail)] if text.startswith(head) and text.endswith(tail) and len(text) >= len(head) + len(tail) else None
    shortened = sent is not None and sent != clean
    ok = (sent is not None and len(text) <= limit and bool(sent) == bool(clean)
          and (sent == clean or (clean.startswith(sent) and len(text) >= limit - 2 * len(sep) - 1)))
    return ok, {'length': len(text), 'shortened': shortened}


def posts_for(captured, cookie):
    """How many cXML posts the receiver took at one visit's punchback address (which ends in its encoded cookie)."""
    return sum(1 for post in captured if post['path'].endswith(urllib.parse.quote(cookie, safe='')))


def connection_freight_sku(seed):
    return (seed.get('connection') or {}).get('freight_supplier_part_id') or 'DELIVERY'


def handoff_form(html, base_url):
    """The handoff page's auto-posting form, or None."""
    for form in forms.parse_forms(html, base_url):
        if form.attrs.get('id') == 'pow-handoff-form' or any(c.name in ('cxml-base64', 'cxml-urlencoded') for c in form.controls):
            return form
    return None


# ---------------------------------------------------------------------------
# HTTP: one browser (cookie jar, no automatic redirects) and a loopback receiver
# ---------------------------------------------------------------------------

class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Browser:
    def __init__(self, origin, jar=None):
        self.origin = origin
        self.jar = jar if jar is not None else http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), _NoRedirect())

    def clone(self):
        """Another browser holding a copy of this one's cookies as they are now (a second click before the first answer)."""
        jar = http.cookiejar.CookieJar()
        for cookie in self.jar:
            jar.set_cookie(copy.copy(cookie))
        return Browser(self.origin, jar)

    def request(self, url, data=None, content_type=None, method=None, accept='text/html', extra_headers=None):
        require_loopback(url)
        headers = dict(extra_headers or {}, **{'User-Agent': USER_AGENT, 'Accept': accept})
        if data is not None:
            headers['Origin'] = self.origin
            if content_type:
                headers['Content-Type'] = content_type
        req = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            response = self.opener.open(req, timeout=60)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read().decode('utf-8', 'replace')
        return response.status if hasattr(response, 'status') else response.code, response.headers, body

    def get(self, url, follow=5):
        status, headers, body = self.request(url)
        while follow and status in (301, 302, 303, 307, 308) and headers.get('Location'):
            url = urllib.parse.urljoin(url, headers['Location'])
            status, headers, body = self.request(url)
            follow -= 1
        return status, headers, body, url

    def submit(self, form, submitter=None, choose=None):
        entries = form.submission(submitter, choose)
        if form.method == 'GET':
            query = urllib.parse.urlencode([(k, v or '') for k, v in entries])
            return self.request(form.action.split('?')[0] + '?' + query)
        body, content_type = forms.encode(entries, form.enctype)
        return self.request(form.action, body, content_type)

    def logged_in(self):
        return any(c.name.startswith('wordpress_logged_in_') for c in self.jar)


class Receiver:
    """The buyer's punchback URL: a loopback listener that records what the browser posts."""

    def __init__(self):
        captured = self.captured = []

        class Handler(http.server.BaseHTTPRequestHandler):
            def do_POST(self):
                length = int(self.headers.get('Content-Length') or 0)
                raw = self.rfile.read(length).decode('utf-8', 'replace')
                captured.append({'path': self.path, 'content_type': self.headers.get('Content-Type') or '', 'fields': urllib.parse.parse_qs(raw, keep_blank_values=True), 'origin': self.headers.get('Origin')})
                self.send_response(200)
                self.send_header('Content-Type', 'text/plain')
                self.end_headers()
                self.wfile.write(b'received')

            def log_message(self, *args):
                pass

        self.server = http.server.HTTPServer((LOOPBACK, 0), Handler)
        self.url = 'http://%s:%d' % (LOOPBACK, self.server.server_address[1])
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    def close(self):
        self.server.shutdown()
        self.server.server_close()


# ---------------------------------------------------------------------------
# The run
# ---------------------------------------------------------------------------

class Run:
    def __init__(self, args):
        self.args = args
        self.version = '0'
        self.passed = []
        self.failed = []
        self.notes = []
        self.quote_orders = []  # Punchout Quotes the run made: each must have had exactly one e-mail, the Quote e-mail
        self.quiet_orders = []  # binned or refused orders: no e-mail at all
        self.path = 'review'
        self.extras = copy.deepcopy(EXTRAS_DEFAULT)
        self.extra_lines = []

    def check(self, ok, name, **detail):
        (self.passed if ok else self.failed).append({'check': name, 'detail': detail})
        print(('PASS ' if ok else 'FAIL ') + name + ((' ' + json.dumps(detail, default=str)[:300]) if detail and not ok else ''), flush=True)
        return ok

    def must(self, ok, name, **detail):
        if not self.check(ok, name, **detail):
            raise SystemExit('Stopped: ' + name + ' failed, see ' + str(self.args.out))

    def save(self, name, text):
        (self.args.out / name).write_text(text)

    # -- WP-CLI fixture steps -------------------------------------------------

    def wp(self, step, **env):
        a = self.args
        command = [a.php, str(a.wp_cli), '--path=' + str(a.wp_path), '--user=' + str(a.wp_user), 'eval', 'require getenv("POW_E2E_SCRIPT");']
        run_env = dict(os.environ, POW_NATIVE_TESTS='disposable', POW_E2E_SCRIPT=str(HERE / 'fixture.php'), POW_E2E_STEP=step, POW_E2E_FIXTURE=str(a.out / 'fixture.json'), **env)
        p = subprocess.run(command, env=run_env, text=True, capture_output=True, timeout=120)
        with (a.out / 'wp-cli.log').open('a') as log:
            log.write('== ' + step + '\n' + p.stdout + ''.join(line + '\n' for line in p.stderr.splitlines() if 'already loaded' not in line))
        if p.returncode:
            raise SystemExit('Fixture step ' + step + ' failed; see ' + str(a.out / 'wp-cli.log'))
        print('fixture: ' + p.stdout.strip(), flush=True)

    # -- the shared front half: setup, redeem, cart ---------------------------

    def punch_in(self, browser, seed, receiver, items=None, label='', redeem=None):
        """Setup request, start link, cart. Returns the visit: its payloadID, BuyerCookie, cart quantities and the Cart
        widget as the last add redrew it. The main leg (no `label`) also keeps them on the run; the second leg's visits
        prefix their check names with their label. `items` is [(key, product id, quantity)], by default every seeded
        product (2 of the first, 1 of each other)."""
        url = self.args.url
        connection = seed['connection']
        prefix = label + ': ' if label else ''
        payload = '{' + str(uuid.uuid4()).upper() + '}'
        cookie = '{' + str(uuid.uuid4()).upper() + '}'
        xml = setup_request(
            payload_id=payload, timestamp=time.strftime('%Y-%m-%dT%H:%M:%S', time.gmtime()), buyer=connection['buyer'], supplier=connection['supplier'],
            secret=seed['secret'], email='buyer.one@buyer.example.com', cookie=cookie, supplier_setup=url + '/punchout/setup', receiver=receiver.url + '/punchout/cxml/',
        )
        if not label:
            self.save('setup-request.xml', xml.replace(seed['secret'], '[REDACTED]'))
        status, _, body = browser.request(url + '/punchout/setup', xml.encode('utf-8'), 'text/xml', accept='text/xml')
        if not label:
            self.save('setup-response.xml', body)
        try:
            root = poom.parse_xml(body)
            code, start = root.find('.//Status').get('code'), (root.findtext('.//StartPage/URL') or '').strip()
        except Exception as error:  # noqa: BLE001 - any unreadable answer is the same failure
            code, start = repr(error), ''
        self.must(status == 200 and code == '200' and start.startswith(url + '/punchout/start/'), prefix + 'setup_answers_200_with_a_start_page', http=status, cxml=code)

        mode = redeem or self.args.redeem
        scanner_proof = at_least(self.version, (0, 4, 23))
        if mode == 'post':
            status, headers, body = browser.request(start, b'', forms.URLENCODED)
            kind = 'redeemed' if status in (302, 303) else 'refused'
            how = 'POST'
        else:
            status, headers, body = browser.request(start)
            if scanner_proof and mode == 'auto':
                # 0.4.23: a link scanner's GET must not use the link up. Only the page's own POST redeems.
                for name, ok, detail in start_page_findings(status, headers, body, start):
                    if name.startswith('a_get_'):
                        self.must(ok, name, **detail)
                    else:
                        self.check(ok, name, **detail)
                again = Browser(url).request(start)
                self.check(start_outcome(*again, start)[0] == 'post', 'a_second_get_still_answers_the_page', http=again[0])
            kind, found = start_outcome(status, headers, body, start)
            how = 'GET'
            if kind == 'post' and mode == 'auto':
                self.check(not browser.logged_in(), 'a_get_leaves_the_start_link_unused')
                status, headers, body = browser.submit(found)
                kind = 'redeemed' if status in (302, 303) else 'refused'
                how = 'POST'
        if not label:
            self.notes.append('start link redeemed by ' + how)
        self.must(kind == 'redeemed' and browser.logged_in(), prefix + 'start_link_redeems_and_signs_the_visit_in', method=how, http=status)
        landing = urllib.parse.urljoin(start, headers.get('Location'))
        if scanner_proof and not label:
            # Single use: a second POST, from a browser that never held the visit, is refused.
            again = Browser(url).request(start, b'', forms.URLENCODED)
            self.check(again[0] == 403, 'a_second_post_is_refused', http=again[0])
            # The browser that redeemed it, still in the visit, is sent to the landing page again (0.4.23).
            repeat = browser.request(start, b'', forms.URLENCODED)
            where = urllib.parse.urljoin(start, repeat[1].get('Location') or '')
            self.check(repeat[0] in (302, 303) and where == landing, 'a_repeat_post_from_the_same_browser_lands_again', http=repeat[0], location=where, landing=landing)
        status, _, body, _ = browser.get(landing)
        self.check(status == 200 and re.search(r'<body[^>]*class="[^"]*\bpow-visit\b', body) is not None, prefix + 'landing_page_is_inside_the_visit', http=status)

        if items is None:
            items = [(sku, product['id'], 2 if index == 0 else 1) for index, (sku, product) in enumerate(seed['products'].items())]
        quantities = {}
        mini_cart = ''
        for key, product_id, quantity in items:
            status, _, body = browser.request(url + '/?wc-ajax=add_to_cart', forms.encode([('product_id', str(product_id)), ('quantity', str(quantity))], forms.URLENCODED)[0], forms.URLENCODED, accept='application/json')
            try:
                answer = json.loads(body)
            except ValueError:
                answer = {'error': True}
            self.must(status == 200 and not answer.get('error') and 'cart_hash' in answer, prefix + 'wc_ajax_add_to_cart ' + key, http=status)
            quantities[key] = quantity
            # The Cart widget as WooCommerce redraws it after the add (0.5.0 checks its checkout button).
            mini_cart = (answer.get('fragments') or {}).get('div.widget_shopping_cart_content') or ''
        visit = {'payload': payload, 'cookie': cookie, 'quantities': quantities, 'mini_cart': mini_cart, 'start': start}
        if not label:
            self.payload, self.cookie, self.quantities, self.mini_cart = payload, cookie, quantities, mini_cart
        return visit

    # -- 0.4.x: the cart's Punchout exit and the review -------------------------

    def review_path(self, browser, seed):
        url = self.args.url
        status, _, cart, cart_url = browser.get(url + '/?page_id=' + str(seed['pages']['cart']))
        self.save('cart.html', cart)
        exit_ = cart_exit(cart, cart_url)
        self.must(status == 200 and exit_ is not None, 'cart_offers_the_punchout_exit', http=status)
        kind, target = exit_
        self.notes.append('cart exit: ' + ('classic return form' if kind == 'form' else 'Cart block link'))
        status, _, page = browser.submit(target) if kind == 'form' else browser.request(target)
        review = self.review_form(page, url)
        self.must(status == 200 and review is not None, 'the_exit_opens_the_review', http=status)

        choose = {}
        offered = [o for o in review.options('choice') if o] if any(c.name == 'choice' for c in review.controls) else []
        self.must(bool(offered), 'review_offers_a_delivery_address', offered=offered)
        if not review.value('choice'):
            # A buyer picks the first address; the review script posts the change at once.
            status, _, page = browser.submit(review, ('pow_delivery_action', 'review'), {'choice': offered[0]})
            review = self.review_form(page, url)
            self.must(status == 200 and review is not None and review.value('choice') == offered[0], 'choosing_an_address_redraws_the_review', http=status)
        groups = {}
        for control in review.controls:
            if control.type == 'radio' and control.name and control.name.startswith('rates['):
                groups.setdefault(control.name, []).append(control)
        for name, radios in groups.items():
            if not any(r.checked for r in radios):
                choose[name] = radios[0].value
        if any(c.name == 'acknowledge_unknown' for c in review.controls):
            choose['acknowledge_unknown'] = '1'
        self.note = 'Leave at the gate. End-to-end run ' + seed['run'] + '.'
        choose['notes'] = self.note
        self.save('review.html', page)
        if at_least(self.version, (0, 4, 23)):
            review_settings = seed.get('review') or {}
            labels = review_settings.get('labels') or {}
            priced = [title for title in [(seed.get('shipping') or {}).get('priced_title')] if title]
            self.must(set(labels) == {'submit', 'items', 'total'} and bool(priced), 'seed_sets_the_review_words_and_a_priced_method', labels=labels, priced=priced)
            for name, ok, detail in review_findings(page, labels, bool(seed['connection']['emit_delivery_line']), priced):
                self.check(ok, name, **detail)
        submitter = ('pow_delivery_action', 'submit')
        self.must(submitter in review.buttons(), 'review_offers_submit')
        second = browser.clone()
        status, _, handoff = browser.submit(review, submitter, choose)
        self.save('handoff.html', handoff)
        self.check(status == 200, 'submit_answers_with_the_handoff_page', http=status)
        return handoff, (second, review, submitter, choose)

    # -- 0.5.0: the cart's checkout button, WooCommerce checkout, the GET handoff --

    def checkout_path(self, browser, seed):
        """Leave through WooCommerce's classic checkout and return the handoff page, as `review_path` does.

        The order lifecycle's second leg (ticket 74) is `late_submits` and `second_leg`; the connection's message
        settings (ticket 75) are checked here and in the cXML. Still to come with the later 0.5.0 tickets (spec
        "Testing Decisions"): the shipping section opened on the default saved address, a file, no delivery amount on
        the page, and the refusals outside a visit."""
        url = self.args.url
        checkout = seed.get('checkout') or {}
        labels = checkout.get('labels') or {}
        cart_page = (seed.get('pages') or {}).get('classic_cart')
        self.must({'proceed', 'place_order'} <= set(labels) and bool(cart_page) and checkout.get('classic') is True, 'seed_sets_the_checkout_labels_a_classic_cart_and_a_classic_checkout', labels=labels, classic_cart=cart_page, classic_checkout=checkout.get('classic'))

        widget = checkout_anchor(getattr(self, 'mini_cart', ''), url, 'checkout')
        self.check(widget is not None and widget[1] == labels['proceed'], 'cart_widget_checkout_button_carries_the_label', shown=widget[1] if widget else None, setting=labels['proceed'])

        status, _, cart, cart_url = browser.get(url + '/?page_id=' + str(cart_page))
        self.save('cart.html', cart)
        proceed = checkout_anchor(cart, cart_url, 'checkout-button')
        self.must(status == 200 and proceed is not None, 'cart_offers_the_checkout_button', http=status)
        self.check(proceed[1] == labels['proceed'], 'cart_checkout_button_carries_the_label', shown=proceed[1], setting=labels['proceed'])

        status, _, page, page_url = browser.get(proceed[0])
        self.save('checkout.html', page)
        form = checkout_form(page, page_url)
        self.must(status == 200 and form is not None, 'the_checkout_button_opens_woocommerce_checkout_in_the_visit', http=status, url=page_url)
        for name, ok, detail in checkout_findings(page, form, labels['place_order']):
            if name == 'checkout_offers_only_the_punchout_method':
                self.must(ok, name, **detail)
            else:
                self.check(ok, name, **detail)

        # Ticket 75: the connection's message settings, the fixture's own checkout fields (a date, when the extras name
        # them) and, with delivery cost on, the priced method, so the freight line has an amount to carry.
        connection = seed['connection']
        details = [d for d in (connection.get('order_details') or [])]
        self.must(connection.get('line_description') == 'name_short_description' and all(d in details for d in self.extras['order_details']) and len(details) > len(self.extras['order_details']), 'seed_sets_the_line_description_and_the_order_details', line_description=connection.get('line_description'), order_details=details)
        day = delivery_date(datetime.now(timezone.utc).date(), self.extras['date_offset_days'])
        fields = {name: fill_template(template, day) for name, template in self.extras['fields'].items()}
        self.extra_lines = [fill_template(template, day) for template in self.extras['instructions']]
        if fields:
            self.notes.append('checkout extras posted for ' + day.isoformat() + ': ' + ', '.join(sorted(fields)))
        shipping = None
        if connection['emit_delivery_line']:
            shipping = shipping_rate(form, seed['shipping']['instance_id'])
            self.must(shipping is not None, 'checkout_offers_the_priced_method', instance=seed['shipping']['instance_id'])

        self.note = 'Leave at the gate. End-to-end run ' + seed['run'] + '.'
        self.checkout_form = form
        try:
            answer = self.post_checkout(browser, form, self.note, fields, shipping=shipping)
        except KeyError as error:
            self.must(False, 'checkout_draws_the_extras_fields', missing=str(error))
        self.save('checkout-answer.json', answer['raw'])
        redirect = answer['redirect']
        self.must(answer['result'] == 'success' and isinstance(redirect, str) and redirect.startswith(url + '/punchout/'), 'submit_answers_success_with_the_handoff_address', http=answer['status'], result=answer['result'], redirect=redirect, messages=answer['messages'])
        # 0.5.0 (ticket 74): the checkout answer never clears the login cookie; the handoff, keyed to it, does.
        self.check(not login_cookie_cleared(answer['set_cookies']) and browser.logged_in(), 'the_checkout_answer_keeps_the_login_cookie', set_cookies=[c.split('=')[0] for c in answer['set_cookies']])

        # A second browser holding this one's cookies as they are now asks for the handoff again afterwards.
        self.handoff_again = (browser.clone(), redirect)
        status, headers, handoff = browser.request(redirect)
        self.save('handoff.html', handoff)
        self.check(status == 200, 'the_handoff_answers', http=status)
        self.check('no-store' in (headers.get('Cache-Control') or '').lower(), 'the_handoff_is_not_stored', cache_control=headers.get('Cache-Control'))
        self.check((headers.get('Referrer-Policy') or '').lower() == 'no-referrer', 'the_handoff_sends_no_referrer', referrer_policy=headers.get('Referrer-Policy'))
        # 0.5.0 (ticket 77): the page that posts the cart stays plain and carries the Sending state's classes.
        self.check(state_page(handoff) == 'sending' and bool(state_text(handoff, 'title')) and state_text(handoff, 'button') is not None, 'the_handoff_page_is_the_sending_state', state=state_page(handoff))
        return handoff

    def post_checkout(self, browser, form, note, extra=None, headers=None, shipping=None):
        """POST the checkout form as WooCommerce's script does (`?wc-ajax=checkout`) and read the answer. `extra` and
        `shipping` are `checkout_submission`'s."""
        body, content_type = forms.encode(checkout_submission(form, note, extra, shipping), forms.URLENCODED)
        status, answer_headers, raw = browser.request(self.args.url + '/?wc-ajax=checkout', body, content_type, accept='application/json', extra_headers=headers)
        result, redirect, messages = checkout_answer(status, raw)
        return {'status': status, 'result': result, 'redirect': redirect, 'messages': messages, 'raw': raw, 'set_cookies': answer_headers.get_all('Set-Cookie') or []}

    def open_checkout(self, browser, seed, label):
        """GET the shop's classic checkout page inside the visit: (page, form)."""
        status, _, page, page_url = browser.get(self.args.url + '/?page_id=' + str(seed['checkout']['page_id']))
        form = checkout_form(page, page_url)
        self.must(status == 200 and form is not None, label + ': checkout_opens_in_the_visit', http=status, url=page_url)
        return page, form

    def inspect(self, payload, name):
        out = self.args.out / ('inspect-' + name + '.json')
        self.wp('inspect', POW_E2E_PAYLOAD=payload, POW_E2E_RESULT=str(out))
        return json.loads(out.read_text())

    def follow(self, browser, answer):
        """Where a successful checkout answer sends the browser: (page, state, handoff form or None)."""
        redirect = answer['redirect']
        if answer['result'] != 'success' or not isinstance(redirect, str) or not redirect.startswith(self.args.url + '/punchout/'):
            return '', None, None
        _, _, page = browser.request(redirect)
        return page, state_page(page), handoff_form(page, self.args.url)

    def late_submits(self, after, before, receiver):
        """A Submit after the visit has returned (ticket 74), from the browser as the handoff left it (no login cookie)
        and as the checkout answer left it (the login cookie, whose login has ended): each reaches the Sent state, with
        no auto-posting form, and nothing is posted again."""
        posts = len(receiver.captured)
        for name, browser in (('after_the_handoff', after), ('with_the_ended_login_cookie', before)):
            answer = self.post_checkout(browser, self.checkout_form, self.note)
            page, state, form = self.follow(browser, answer)
            self.save('late-submit-' + name + '.html', page)
            self.check(state == 'sent' and form is None, 'a_submit_' + name + '_reaches_the_sent_state', result=answer['result'], redirect=answer['redirect'], messages=answer['messages'], state=state, form=form is not None)
        self.check(len(receiver.captured) == posts, 'a_submit_after_the_return_posts_nothing_again', posts=len(receiver.captured) - posts)

    def capture_leg(self, browser, form, receiver, visit, label):
        """Post a second-leg visit's handoff form as the browser's auto-post would; the decoded cXML."""
        self.must(form.action.endswith(urllib.parse.quote(visit['cookie'], safe='')), label + ': handoff_posts_to_the_visits_punchback_url', action=form.action)
        entries = form.submission()
        field, value = entries[0] if entries else (None, '')
        before = len(receiver.captured)
        browser.submit(form)
        posted = receiver.captured[before] if len(receiver.captured) > before else {'fields': {}, 'content_type': ''}
        self.must(posted['fields'].get(field) == [value], label + ': the_browser_post_carries_the_page_field', field=field)
        return poom.decode_field(field, value), field, posted

    def second_leg(self, seed, receiver):
        """The order lifecycle's second leg (ticket 74), each case in a visit of its own: two racing Submits, a
        zero-total cart (with a currency refusal first, and a note cXML cannot carry as typed), and a line without a
        SKU."""
        for label, leg in (('race', self.race_leg), ('zero', self.zero_total_leg), ('sku', self.skuless_leg)):
            try:
                leg(seed, receiver)
            except SystemExit as stop:
                self.check(False, label + ': case_completed', reason=str(stop))

    def race_leg(self, seed, receiver):
        """Two Submits from one visit, the first held after WooCommerce created its order so the second overtakes it.
        One handoff and one post; the overtaken Submit trashes its own order, releases its holds and reaches Sent."""
        url, label = self.args.url, 'race'
        first = Browser(url)
        visit = self.punch_in(first, seed, receiver, label=label, redeem='post')
        _, form = self.open_checkout(first, seed, label)
        second = first.clone()
        note = 'Race run ' + seed['run'] + '.'
        answers = {}
        held = threading.Thread(target=lambda: answers.__setitem__('held', self.post_checkout(first, form, note, headers={'X-Pow-E2E-Pause': '6'})), daemon=True)
        held.start()
        time.sleep(2.5)
        fast = self.post_checkout(second, form, note)
        page, state, handoff = self.follow(second, fast)
        served = 0
        if handoff is not None:
            served += 1
            second.submit(handoff)
        self.check(handoff is not None, label + ': the_overtaking_submit_gets_the_handoff', result=fast['result'], redirect=fast['redirect'], messages=fast['messages'], state=state)
        held.join(90)
        late = answers.get('held') or {'result': None, 'redirect': None, 'messages': ['no answer']}
        page, state, handoff = self.follow(first, late)
        self.save('race-overtaken.html', page)
        if handoff is not None:
            served += 1
            first.submit(handoff)
        self.check(state == 'sent' and handoff is None, label + ': the_overtaken_submit_reaches_the_sent_state', result=late['result'], redirect=late['redirect'], messages=late['messages'], state=state)
        self.check(served == 1 and posts_for(receiver.captured, visit['cookie']) == 1, label + ': one_handoff_and_one_post', served=served, posts=posts_for(receiver.captured, visit['cookie']))

        result = self.inspect(visit['payload'], label)
        orders, trashed = result['orders'], result['trashed']
        self.check(len(orders) == 1 and orders[0]['status'] == 'punchout-quote', label + ': exactly_one_order_a_punchout_quote', orders=[(o['id'], o['status']) for o in orders])
        self.check(bool(orders) and (result['visit'] or {}).get('order_id') == orders[0]['id'], label + ': the_visit_names_that_order', visit=result['visit'])
        self.check(len(trashed) == 1 and all(t['reserved_stock'] == 0 and t['coupon_holds'] == 0 for t in trashed), label + ': the_overtaken_order_is_in_the_bin_with_its_holds_released', trashed=trashed)
        self.check(all(o['reserved_stock'] == 0 and not o['auto_cancel'] for o in orders), label + ': the_order_holds_no_stock_and_is_never_auto_cancelled', orders=orders)
        self.quote_orders += [o['id'] for o in orders]
        self.quiet_orders += [t['id'] for t in trashed]

    def zero_total_leg(self, seed, receiver):
        """A cart a 100% coupon brings to zero, with free shipping: PunchOut is still offered and the cart still hands
        off. First a Submit priced in another currency is refused before any order exists. The note carries a control
        character and runs past 2,000 characters: the cXML carries it cleaned and capped."""
        url, label = self.args.url, 'zero'
        browser = Browser(url)
        sku, product = next(iter(seed['products'].items()))
        visit = self.punch_in(browser, seed, receiver, items=[(sku, product['id'], 1)], label=label, redeem='post')
        page, form = self.open_checkout(browser, seed, label)
        nonce = apply_coupon_nonce(page)
        body = forms.encode([('security', nonce or ''), ('coupon_code', seed['coupon']['code'])], forms.URLENCODED)[0]
        status, _, applied = browser.request(url + '/?wc-ajax=apply_coupon', body, forms.URLENCODED)
        self.must(status == 200 and nonce is not None and 'woocommerce-error' not in applied, label + ': the_coupon_applies', http=status, answer=_text(applied)[:200])
        page, form = self.open_checkout(browser, seed, label)
        self.save('zero-checkout.html', page)
        choice = shipping_choice(form, 'free_shipping:')
        self.must(choice is not None, label + ': free_shipping_is_offered')
        offered = [c.value for c in form.controls if c.name == 'payment_method' and c.type == 'radio']
        self.must(offered == ['punchout'], label + ': a_zero_total_checkout_offers_the_punchout_method', offered=offered)

        other = 'USD' if seed['currency'] != 'USD' else 'EUR'
        refused = self.post_checkout(browser, form, 'Currency check.', choice, headers={'X-Pow-E2E-Currency': other})
        self.check(refused['result'] == 'failure' and any(other in m and seed['currency'] in m for m in refused['messages']), label + ': a_cart_priced_in_another_currency_is_refused', result=refused['result'], messages=refused['messages'])
        result = self.inspect(visit['payload'], label + '-refused')
        self.check(result['orders'] == [] and result['trashed'] == [] and (result['visit'] or {}).get('status') == 'active', label + ': the_currency_refusal_leaves_no_order_and_the_visit_open', orders=len(result['orders']), visit=result['visit'])

        note = 'Gate\x01 code 7.' + ' Use the rear entrance.' * 100
        answer = self.post_checkout(browser, form, note, choice)
        page, state, handoff = self.follow(browser, answer)
        self.must(handoff is not None, label + ': a_zero_total_cart_hands_off', result=answer['result'], redirect=answer['redirect'], messages=answer['messages'], state=state)
        xml, field, posted = self.capture_leg(browser, handoff, receiver, visit, label)
        self.save('zero-poom.xml', xml)
        connection = seed['connection']
        expected = {
            'buyer_cookie': visit['cookie'], 'buyer': connection['buyer'], 'supplier': connection['supplier'], 'deployment_mode': connection['deployment_mode'],
            'currency': seed['currency'], 'shared_secret': seed['secret'], 'lines': {sku: {'qty': 1, 'unit': '0.00', 'aux': '%d|0' % product['id']}},
            # 0.5.0 (ticket 75): with Send delivery cost on, the order's shipping line is the freight line, here free
            # shipping at 0.00; off, none.
            'freight': '0.00' if connection['emit_delivery_line'] else None,
            'freight_sku': connection['freight_supplier_part_id'], 'ship_to_contains': None, 'notes': None,
        }
        for check in poom.check(xml, expected, capture={'field': field, 'content_type': posted['content_type']}):
            self.check(check['ok'], label + ': cxml ' + check['check'], **({} if check['ok'] else check['detail']))

        result = self.inspect(visit['payload'], label)
        orders = result['orders']
        self.check(len(orders) == 1 and orders[0]['status'] == 'punchout-quote' and float(orders[0]['total']) == 0.0, label + ': one_zero_total_punchout_quote', orders=[(o['id'], o['status'], o['total']) for o in orders])
        self.check(all(o['reserved_stock'] == 0 and o['coupon_holds'] == 0 and not o['auto_cancel'] for o in orders) and result['coupon_holds'] == 0, label + ': stock_and_coupon_holds_released', orders=orders, coupon_holds=result['coupon_holds'])
        if orders:
            instructions = [e.text or '' for e in poom.parse_xml(xml).iter('Extrinsic') if e.get('name') == 'DeliveryInstructions']
            # 0.5.0 (ticket 75): the note shares DeliveryInstructions with the estimate sentence (only with a freight
            # line) and the order details, and only the note is shortened so that the whole stays within 2,000.
            lead = seed['estimate_note'] if connection['emit_delivery_line'] else ''
            details = list(seed.get('instructions_expected') or [])
            fits = [instructions_fit(i, lead, orders[0]['customer_note'], details) for i in instructions]
            self.check(bool(fits) and all(ok and detail['shortened'] for ok, detail in fits), label + ': the_note_reaches_the_cxml_cleaned_and_capped', got=[detail for _, detail in fits], lead=bool(lead), details=details)
        self.quote_orders += [o['id'] for o in orders]
        self.quiet_orders += [t['id'] for t in result['trashed']]

    def skuless_leg(self, seed, receiver):
        """A cart holding a product without a SKU: Submit is refused, naming the product, before any order exists."""
        label = 'sku'
        browser = Browser(self.args.url)
        sku, product = next(iter(seed['products'].items()))
        skuless = seed['skuless']
        visit = self.punch_in(browser, seed, receiver, items=[(sku, product['id'], 1), ('without a SKU', skuless['id'], 1)], label=label, redeem='post')
        _, form = self.open_checkout(browser, seed, label)
        answer = self.post_checkout(browser, form, 'SKU check.')
        self.check(answer['result'] == 'failure' and any(skuless['name'].lower() in m.lower() for m in answer['messages']), label + ': a_line_without_a_sku_is_refused_by_name', result=answer['result'], redirect=answer['redirect'], messages=answer['messages'])
        result = self.inspect(visit['payload'], label)
        self.check(result['orders'] == [] and result['trashed'] == [] and (result['visit'] or {}).get('status') == 'active', label + ': the_refusal_leaves_no_order_and_the_visit_open', orders=[(o['id'], o['status']) for o in result['orders']], visit=result['visit'])
        self.check(posts_for(receiver.captured, visit['cookie']) == 0, label + ': nothing_is_posted')
        self.quiet_orders += [o['id'] for o in result['orders'] + result['trashed']]

    def states_leg(self, seed, receiver):
        """0.5.0 (ticket 77), with a state page chosen and "Ask before leaving" on (fixture step `states`; retire puts
        both back). Each case in a visit of its own, its checks prefixed `states:`:

        - Sending and Sent: the page that posts the cart is the Sending state, its button the state page button
          label; fetched again it sends the browser to Sent on the state page, drawn inside the theme, not stored,
          indexed or sniffed, its title the shortcode attribute; a late Submit reaches the same Sent;
        - Link expired: the used start link, posted again, sends the browser to Link expired;
        - Session ended: once the visit is ended (fixture step `end_visit`), its Cancel and return and its next page
          go to Session ended, the dead login cookie is cleared with that redirect, and the page after is an
          ordinary guest page;
        - Not sent and asking first: with a line in the cart the cancel control names a closed dialog (the question
          attribute, the two button settings, the plugin's script); posted without the script it goes back to its
          page with the dialog open and leaves nothing; the dialog's answer leaves with the plain Not sent page,
          which posts an empty message; a tab still holding the cookie is sent to Not sent on the state page;
        - an empty cart leaves at once, with no dialog."""
        url, label = self.args.url, 'states'
        out = self.args.out / 'states.json'
        self.wp('states', POW_E2E_RESULT=str(out))
        states = json.loads(out.read_text())
        page_id, leave_id = states['state_page']['id'], states['leave_page']['id']
        labels = states['labels']
        leave_url = url + '/?page_id=' + str(leave_id)
        shop_url = url + '/?page_id=' + str(seed['pages']['shop'])
        first_sku, first = next(iter(seed['products'].items()))

        def state_page_check(name, response, state, title=None):
            status, headers, body = response
            self.save('states-' + name + '.html', body)
            for check, ok, detail in state_findings(status, headers, body, state, page_id):
                self.check(ok, label + ': ' + name + ' ' + check, **detail)
            if title is not None:
                self.check(state_text(body, 'title') == title, label + ': ' + name + ' title', title=state_text(body, 'title'), expected=title)
            return body

        def redirected(name, response, state):
            status, headers = response[0], response[1]
            where = urllib.parse.urljoin(url + '/', headers.get('Location') or '')
            self.check(status in (302, 303) and state_location(where) == (page_id, state), label + ': ' + name, http=status, location=where)
            return where

        # Sending, then Sent.
        browser = Browser(url)
        visit = self.punch_in(browser, seed, receiver, items=[(first_sku, first['id'], 1)], label=label, redeem='post')
        _, form = self.open_checkout(browser, seed, label)
        answer = self.post_checkout(browser, form, 'State page run ' + seed['run'] + '.')
        self.must(answer['result'] == 'success' and isinstance(answer['redirect'], str) and '/punchout/handoff' in answer['redirect'], label + ': submit_answers_with_the_handoff', result=answer['result'], messages=answer['messages'])
        status, headers, page = browser.request(answer['redirect'])
        self.save('states-sending.html', page)
        handoff = handoff_form(page, url)
        self.check(status == 200 and state_page(page) == 'sending' and handoff is not None and 'pow-state__actions' in (handoff.attrs.get('class') or ''), label + ': the_page_that_posts_the_cart_is_the_sending_state', http=status, state=state_page(page))
        self.check(state_text(page, 'button') == labels['state_button'], label + ': its_button_is_the_state_page_button_label', shown=state_text(page, 'button'), setting=labels['state_button'])
        self.must(handoff is not None, label + ': handoff_form')
        self.capture_leg(browser, handoff, receiver, visit, label)
        where = redirected('a_served_handoff_sends_the_browser_to_sent', browser.request(answer['redirect']), 'sent')
        body = state_page_check('sent', browser.request(where), 'sent', states['state_page']['sent_title'])
        self.check(state_text(body, 'button') == labels['state_button'] and re.search(r'class="pow-state__button" href="/"', body) is not None, label + ': the_state_page_button_carries_the_setting', shown=state_text(body, 'button'))
        late = self.post_checkout(browser, form, 'Late.')
        self.check(late['result'] == 'success' and state_location(late['redirect']) == (page_id, 'sent'), label + ': a_late_submit_reaches_sent_on_the_state_page', result=late['result'], redirect=late['redirect'], messages=late['messages'])
        self.check(posts_for(receiver.captured, visit['cookie']) == 1, label + ': one_post', posts=posts_for(receiver.captured, visit['cookie']))
        result = self.inspect(visit['payload'], label)
        self.quote_orders += [o['id'] for o in result['orders']]
        self.quiet_orders += [t['id'] for t in result['trashed']]

        # Link expired.
        where = redirected('a_used_start_link_sends_the_browser_to_link_expired', Browser(url).request(visit['start'], b'', forms.URLENCODED), 'expired')
        state_page_check('expired', Browser(url).request(where), 'expired', 'Link expired')

        # Session ended.
        ended = Browser(url)
        gone = self.punch_in(ended, seed, receiver, items=[(first_sku, first['id'], 1)], label=label + ' ended', redeem='post')
        status, _, page, page_url = ended.get(leave_url)
        cancel, _, _ = leave_controls(page, page_url)
        self.must(status == 200 and cancel is not None, label + ': ended: the_cancel_control_is_on_the_page', http=status)
        self.wp('end_visit', POW_E2E_PAYLOAD=gone['payload'])
        redirected('ended: cancel_and_return_after_the_end_goes_to_session_ended', ended.submit(cancel), 'ended')
        response = ended.request(shop_url)
        where = redirected('ended: the_next_page_goes_to_session_ended', response, 'ended')
        self.check(login_cookie_cleared(response[1].get_all('Set-Cookie') or []) and not ended.logged_in(), label + ': ended: the_dead_login_cookie_is_cleared_on_the_way')
        state_page_check('ended', ended.request(where), 'ended', 'Session ended')
        status, _, body, final = ended.get(shop_url)
        self.check(status == 200 and state_location(final) == (None, None) and re.search(r'<body[^>]*class="[^"]*\bpow-visit\b', body) is None, label + ': ended: the_page_after_is_an_ordinary_guest_page', http=status, url=final)

        # Asking first, then Not sent.
        leaver = Browser(url)
        leaving = self.punch_in(leaver, seed, receiver, items=[(first_sku, first['id'], 1)], label=label + ' leave', redeem='post')
        status, _, page, page_url = leaver.get(leave_url)
        self.save('states-leave.html', page)
        cancel, confirm, dialog = leave_controls(page, page_url)
        self.must(cancel is not None, label + ': leave: the_cancel_control_is_on_the_page', http=status)
        expected = {'id': (dialog or {}).get('id'), 'open': False, 'question': states['leave_page']['question'], 'confirm': labels['leave'], 'stay': labels['stay'], 'cancel': labels['cancel']}
        self.check(confirm is not None and dialog == expected, label + ': leave: with_items_the_cancel_control_names_a_closed_dialog', dialog=dialog, expected=expected)
        self.check('assets/js/leave-confirmation.js' in page, label + ': leave: the_plugins_own_script_opens_it')
        body, content_type = forms.encode(cancel.submission(), cancel.enctype)
        status, headers, _ = leaver.request(cancel.action, body, content_type, extra_headers={'Referer': page_url})
        asked = urllib.parse.urljoin(url + '/', headers.get('Location') or '')
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(asked).query)
        self.check(status in (302, 303) and query.get('page_id') == [str(leave_id)] and query.get('pow_leave') == ['ask'], label + ': leave: without_the_script_it_goes_back_to_its_page_to_ask', http=status, location=asked)
        result = self.inspect(leaving['payload'], label + '-leave-asked')
        self.check((result['visit'] or {}).get('status') == 'active' and posts_for(receiver.captured, leaving['cookie']) == 0, label + ': leave: asking_sends_nothing_and_keeps_the_visit', visit=result['visit'])
        status, _, page, page_url = leaver.get(asked)
        _, confirm, dialog = leave_controls(page, page_url)
        self.check(confirm is not None and bool(dialog) and dialog['open'], label + ': leave: the_page_draws_the_dialog_open', dialog=dialog)
        self.must(confirm is not None, label + ': leave: dialog_form')
        tab = leaver.clone()
        status, _, page = leaver.submit(confirm)
        self.save('states-leave-not-sent.html', page)
        handoff = handoff_form(page, url)
        self.check(status == 200 and state_page(page) == 'not-sent' and handoff is not None, label + ': leave: the_answer_leaves_with_the_not_sent_state', http=status, state=state_page(page))
        self.must(handoff is not None, label + ': leave: handoff_form')
        xml, _, _ = self.capture_leg(leaver, handoff, receiver, leaving, label + ' leave')
        root = poom.parse_xml(xml)
        self.check(root.find('.//PunchOutOrderMessage') is not None and not root.findall('.//ItemIn'), label + ': leave: an_empty_message_is_posted', items=len(root.findall('.//ItemIn')))
        result = self.inspect(leaving['payload'], label + '-leave')
        self.check((result['visit'] or {}).get('status') == 'closed' and result['orders'] == [], label + ': leave: the_visit_is_closed_with_no_order', visit=result['visit'], orders=len(result['orders']))
        where = redirected('leave: a_tab_still_holding_the_cookie_goes_to_not_sent', tab.request(shop_url), 'not-sent')
        state_page_check('not-sent', tab.request(where), 'not-sent', 'Not sent')

        # An empty cart leaves at once.
        empty = Browser(url)
        nothing = self.punch_in(empty, seed, receiver, items=[], label=label + ' empty', redeem='post')
        status, _, page, page_url = empty.get(leave_url)
        cancel, confirm, dialog = leave_controls(page, page_url)
        self.check(cancel is not None and confirm is None and dialog is None, label + ': empty: an_empty_cart_draws_no_dialog', dialog=dialog)
        if cancel is not None:
            status, _, page = empty.submit(cancel)
            handoff = handoff_form(page, url)
            self.check(status == 200 and state_page(page) == 'not-sent' and handoff is not None, label + ': empty: an_empty_cart_leaves_at_once', http=status, state=state_page(page))
            if handoff is not None:
                self.capture_leg(empty, handoff, receiver, nothing, label + ' empty')

    def mail_checks(self, seed):
        """Exactly one e-mail per Punchout Quote, the store's Quote e-mail; none for a binned or refused order; no
        failed or cancelled order e-mail anywhere in the run."""
        log_path = self.args.out / 'mail.log'
        log = log_path.read_text() if log_path.exists() else ''
        for order_id in self.quote_orders:
            counts = mail_counts(log, [order_id])
            self.check(counts == {'pow_quote_received': 1}, 'exactly_one_email_for_order_%d_the_quote_email' % order_id, emails=counts)
        quiet = mail_counts(log, self.quiet_orders)
        self.check(quiet == {}, 'no_email_for_a_binned_or_refused_order', emails=quiet)
        everything = mail_counts(log)
        self.check(not any(everything.get(kind) for kind in ('failed_order', 'customer_failed_order', 'cancelled_order', 'customer_cancelled_order')), 'no_failed_or_cancelled_email_in_the_run', emails=everything)

    @staticmethod
    def review_form(page, base):
        for form in forms.parse_forms(page, base):
            if form.action.split('?')[0].rstrip('/').endswith('/punchout/confirm') and ('pow_delivery_action', 'submit') in form.buttons():
                return form
        return None

    # -- the end: capture, decode, check --------------------------------------

    def capture(self, browser, handoff, receiver):
        form = handoff_form(handoff, self.args.url)
        self.must(form is not None, 'handoff_page_carries_the_auto_posting_form')
        entries = form.submission()
        field, value = entries[0] if entries else (None, '')
        self.must(form.action.startswith(receiver.url + '/punchout/cxml/'), 'handoff_posts_to_the_buyers_punchback_url', action=form.action)
        self.must(form.action.endswith(urllib.parse.quote(self.cookie, safe='')), 'punchback_url_kept_exactly', action=form.action)
        before = len(receiver.captured)
        browser.submit(form)
        posted = receiver.captured[before] if len(receiver.captured) > before else {'fields': {}, 'content_type': ''}
        self.must(posted['fields'].get(field) == [value], 'the_browser_post_carries_the_page_field_byte_for_byte', field=field)
        xml = poom.decode_field(field, value)
        self.save('poom.xml', xml)
        return field, value, xml, posted

    def expected(self, seed):
        connection = seed['connection']
        lines = {sku: {'qty': self.quantities[sku], 'unit': product['price'], 'aux': '%d|0' % product['id']} for sku, product in seed['products'].items()}
        base = {
            'buyer_cookie': self.cookie, 'buyer': connection['buyer'], 'supplier': connection['supplier'], 'deployment_mode': connection['deployment_mode'],
            'currency': seed['currency'], 'shared_secret': seed['secret'], 'lines': lines,
            # The fixture's only shipping method is free shipping, so an emitted freight line would be 0.00.
            'freight': '0.00' if connection['emit_delivery_line'] else None, 'freight_sku': connection['freight_supplier_part_id'],
            'ship_to_contains': [seed['ship_to']['address_1'], seed['ship_to']['city']] if connection['emit_ship_to'] else None,
            'notes': self.note if connection['delivery_notes_policy'] == 'item_detail_extrinsic' else None,
        }
        if self.path == 'checkout':
            base.update(checkout_expected(seed, self.note, self.extra_lines))
        return base


def wait_for(url, seconds, server=None):
    """True once something accepts connections at `url` within `seconds`; False at once if `server` (a Popen) exits."""
    deadline = time.monotonic() + seconds
    port = urllib.parse.urlsplit(url).port
    while True:
        if server is not None and server.poll() is not None:
            return False
        try:
            with socket.create_connection((LOOPBACK, port), timeout=1):
                return True
        except OSError:
            if time.monotonic() >= deadline:
                return False
            time.sleep(0.25)


def free_port():
    with socket.socket() as s:
        s.bind((LOOPBACK, 0))
        return s.getsockname()[1]


def link_plugin(wp_path, target):
    """Point the fixture's plugin symlink at `target`; return a function that puts the old link back."""
    link = Path(wp_path) / 'wp-content' / 'plugins' / 'punchout-woocommerce'
    if not link.is_symlink():
        sys.exit('Refusing to relink: ' + str(link) + ' is not a symlink.')
    old = os.readlink(link)
    if not (Path(target) / 'punchout-woocommerce.php').is_file():
        sys.exit('Refusing to link ' + str(target) + ': no punchout-woocommerce.php there.')

    def point(to):
        temporary = link.with_name('.punchout-woocommerce.e2e-link')
        if temporary.is_symlink():
            temporary.unlink()
        os.symlink(to, temporary)
        os.replace(temporary, link)

    point(str(Path(target).resolve()))
    print('plugin linked: ' + str(Path(target).resolve()) + ' (was ' + old + ')', flush=True)

    def restore():
        point(old)
        print('plugin link restored: ' + old, flush=True)
    return restore


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    parser.add_argument('--wp-path', type=Path, required=True, help='WordPress root of the disposable fixture')
    parser.add_argument('--wp-cli', type=Path, required=True, help='wp-cli.phar')
    parser.add_argument('--wp-user', default='1', help='fixture administrator for WP-CLI')
    parser.add_argument('--php', default=os.environ.get('POW_E2E_PHP', 'php'))
    parser.add_argument('--url', help='an already running http://127.0.0.1:<port> server for --wp-path (default: start one)')
    parser.add_argument('--link-plugin', type=Path, help='plugin checkout to link into the fixture for this run; the old link is restored after')
    parser.add_argument('--redeem', choices=['auto', 'get', 'post'], default='auto', help='auto: as a browser (GET, then the page\'s own POST form)')
    parser.add_argument('--path', choices=['auto', 'review', 'checkout'], default='auto', help='auto: review below 0.5.0, checkout from 0.5.0')
    parser.add_argument('--checkout-extras', type=Path, help='JSON kept with the fixture: checkout fields another plugin draws, the order details they add and the DeliveryInstructions text they must send (load_extras)')
    parser.add_argument('--send-delivery-cost', action='store_true', help='seed the connection with Send delivery cost on and choose the priced method at checkout (0.5.0)')
    parser.add_argument('--out', type=Path, help='run directory (default: <wp-path>/../e2e-runs/run-<id>)')
    parser.add_argument('--wait', type=float, default=20.0, help='seconds to wait for the site to accept connections (default 20)')
    args = parser.parse_args(argv)
    os.umask(0o077)
    args.out = args.out or (args.wp_path.resolve().parent / 'e2e-runs' / ('run-' + uuid.uuid4().hex[:12]))
    args.out.mkdir(parents=True, exist_ok=False)
    os.environ['POW_E2E_PHP'] = args.php

    run = Run(args)
    try:
        run.extras = load_extras(args.checkout_extras)
    except (OSError, ValueError) as error:
        sys.exit('Refusing --checkout-extras: ' + str(error))
    restore = link_plugin(args.wp_path, args.link_plugin) if args.link_plugin else None
    server = receiver = None
    seeded = False
    try:
        if not args.url:
            port = free_port()
            args.url = 'http://%s:%d' % (LOOPBACK, port)
            log = (args.out / 'php-server.log').open('w')
            # The router records each WooCommerce e-mail the site sends (or tries to, while the fixture blocks mail).
            server = subprocess.Popen([args.php, '-S', '%s:%d' % (LOOPBACK, port), '-t', str(args.wp_path), str(HERE / 'router.php')], env=dict(os.environ, POW_E2E_ORIGIN=args.url, PHP_CLI_SERVER_WORKERS='4', POW_E2E_MAIL_LOG=str(args.out / 'mail.log')), stdout=log, stderr=subprocess.STDOUT)
        require_loopback(args.url)
        run.must(wait_for(args.url, args.wait, server), 'site_server_answers', url=args.url, server_exit=server.poll() if server else None)
        receiver = Receiver()

        run.wp('seed', POW_E2E_ORDER_DETAILS=json.dumps(run.extras['order_details']), POW_E2E_SEND_DELIVERY_COST='1' if args.send_delivery_cost else '0')
        seeded = True
        seed = json.loads((args.out / 'fixture.json').read_text())
        version = run.version = seed['versions']['plugin']
        path = run.path = path_for(version, args.path)
        print('PunchOut %s on WooCommerce %s, WordPress %s; path: %s; site %s' % (version, seed['versions']['woocommerce'], seed['versions']['wordpress'], path, args.url), flush=True)

        browser = Browser(args.url)
        run.punch_in(browser, seed, receiver)
        if path == 'checkout':
            handoff, replay = run.checkout_path(browser, seed), None
        else:
            handoff, replay = run.review_path(browser, seed)
        field, value, xml, posted = run.capture(browser, handoff, receiver)

        results = poom.check(xml, run.expected(seed), capture={'field': field, 'content_type': posted['content_type']})
        (args.out / 'checks.json').write_text(json.dumps(results, indent=1, default=str))
        for result in results:
            run.check(result['ok'], 'cxml ' + result['check'], **({} if result['ok'] else result['detail']))

        if path == 'checkout':
            second, handoff_url = run.handoff_again
            status, _, again = second.request(handoff_url)
            run.save('handoff-again.html', again)
            run.check(handoff_form(again, args.url) is None, 'the_handoff_is_served_once', http=status)
            run.late_submits(browser, second, receiver)

        if replay is not None:
            second, review, submitter, choose = replay
            status, _, again = second.submit(review, submitter, choose)
            form = handoff_form(again, args.url)
            run.check(status == 200 and form is not None and form.submission() == [(field, value)], 'a_repeated_submit_gets_the_same_handoff', http=status)

        status, _, body, _ = browser.get(args.url + '/?page_id=' + str(seed['pages']['shop']))
        run.check(re.search(r'<body[^>]*class="[^"]*\bpow-visit\b', body) is None, 'after_the_return_the_browser_is_no_longer_in_the_visit', http=status)

        state = run.inspect(run.payload, 'main')
        visit, orders = state['visit'] or {}, state['orders']
        run.check(visit.get('status') == 'returned', 'visit_is_returned', status=visit.get('status'))
        run.check(visit.get('login_live') is False, 'visit_login_is_dead')
        run.check(len(orders) == 1, 'exactly_one_order_for_the_visit', orders=[o['id'] for o in orders])
        if orders:
            order = orders[0]
            run.check(order['customer'] == seed['account'], 'order_belongs_to_the_bound_account', customer=order['customer'])
            run.check(order['buyer_cookie'] == run.cookie, 'order_names_the_buyer_cookie')
            run.check(sorted((line['sku'], line['qty']) for line in order['lines']) == sorted(run.quantities.items()), 'order_lines_equal_the_cart', lines=order['lines'])
            run.check(order['status'] == 'punchout-quote', 'order_is_a_punchout_quote', status=order['status'])
            if path == 'checkout':
                run.check(order['payment_method'] == 'punchout', 'order_was_placed_with_the_punchout_method', payment_method=order['payment_method'])
                run.check(visit.get('order_id') == order['id'], 'visit_names_its_order', visit_order=visit.get('order_id'), order=order['id'])
                ok, detail = order_lines_match(order['lines'], poom.lines(xml, connection_freight_sku(seed)))
                run.check(ok, 'cxml_lines_equal_the_orders_lines', **detail)
                run.check(order['customer_note'] == run.note, 'order_keeps_the_buyers_note', note=order['customer_note'])
                # 0.5.0 (ticket 74): checkout's stock hold is released, and the unpaid-order clean-up leaves the order alone.
                run.check(order['reserved_stock'] == 0 and order['coupon_holds'] == 0, 'order_holds_no_stock_and_no_coupon', reserved_stock=order['reserved_stock'], coupon_holds=order['coupon_holds'])
                run.check(order['auto_cancel'] is False, 'order_is_never_auto_cancelled')
                run.check(state['trashed'] == [], 'nothing_of_the_visit_is_in_the_bin', trashed=state['trashed'])
                run.quote_orders.append(order['id'])
        if path == 'checkout':
            run.second_leg(seed, receiver)
            try:
                run.states_leg(seed, receiver)
            except SystemExit as stop:
                run.check(False, 'states: case_completed', reason=str(stop))
            run.mail_checks(seed)
            stock = run.inspect(run.payload, 'stock')['stock']
            run.check(all(quantity == seed['stock'] for quantity in stock.values()), 'punchout_quotes_move_no_stock', stock=stock, seeded=seed['stock'])
    except SystemExit as stop:
        run.check(False, 'run_completed', reason=str(stop))
    except (Exception, KeyboardInterrupt) as error:  # noqa: BLE001 - a connection error or a crash is a failed run, never an empty failure list
        run.check(False, 'run_completed', reason=type(error).__name__ + ': ' + str(error))
    finally:
        def cleanup(name, step):
            try:
                step()
            except (Exception, SystemExit) as error:  # noqa: BLE001 - every cleanup step runs, and a failed one is reported
                run.check(False, 'cleanup ' + name, reason=type(error).__name__ + ': ' + str(error))
        if seeded:
            cleanup('retire', lambda: run.wp('retire'))
        if receiver:
            cleanup('receiver', receiver.close)
        if server:
            def stop_server():
                server.terminate()
                server.wait(timeout=10)
            cleanup('server', stop_server)
        if restore:
            cleanup('plugin_link', restore)
        (args.out / 'result.json').write_text(json.dumps({'ok': not run.failed, 'passed': len(run.passed), 'failed': [f['check'] for f in run.failed], 'notes': run.notes, 'checks': run.passed + run.failed}, indent=1, default=str))
        print('%d passed, %d failed%s; evidence: %s' % (len(run.passed), len(run.failed), (': ' + ', '.join(f['check'] for f in run.failed)) if run.failed else '', args.out), flush=True)
    return 1 if run.failed else 0


if __name__ == '__main__':
    sys.exit(main())
