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
     review's Submit (0.4.x), or WooCommerce checkout (0.5.0, `checkout_path`,
     not built yet);
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
import html as htmllib
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


def start_page_findings(status, headers, body, url):
    """0.4.23 and later: a GET of the start link answers a page that posts itself back, never stored or indexed. [(check, ok, detail)]"""
    kind, _ = start_outcome(status, headers, body, url)
    cache = (headers.get('Cache-Control') or '').lower()
    robots = (headers.get('X-Robots-Tag') or '').lower()
    return [
        ('a_get_of_the_start_link_answers_a_page_that_posts_itself_back', kind == 'post', {'http': status, 'outcome': kind}),
        ('the_start_page_is_not_stored', 'no-store' in cache, {'cache_control': cache}),
        ('the_start_page_is_not_indexed', 'noindex' in robots, {'x_robots_tag': robots}),
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
    findings += [
        ('review_has_no_estimate_row', 'Delivery estimate' not in area and 'pow-confirmation__estimate-note' not in area and rows == 1, {'summary_rows': rows}),
        ('review_method_list_has_no_amount', bool(methods) and not priced, {'methods': titles, 'priced': [_text(m) for m in priced]}),
        ('review_offers_the_priced_method_by_its_title', all(title in titles for title in priced_titles), {'methods': titles, 'expected': list(priced_titles)}),
        ('review_has_no_tax_sentence', re.search(r'\b(tax|vat)\b', _text(area), re.I) is None, {}),
    ]
    return findings


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

    def request(self, url, data=None, content_type=None, method=None, accept='text/html'):
        require_loopback(url)
        headers = {'User-Agent': USER_AGENT, 'Accept': accept}
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

    def punch_in(self, browser, seed, receiver):
        url = self.args.url
        connection = seed['connection']
        self.payload = '{' + str(uuid.uuid4()).upper() + '}'
        self.cookie = '{' + str(uuid.uuid4()).upper() + '}'
        xml = setup_request(
            payload_id=self.payload, timestamp=time.strftime('%Y-%m-%dT%H:%M:%S', time.gmtime()), buyer=connection['buyer'], supplier=connection['supplier'],
            secret=seed['secret'], email='buyer.one@buyer.example.com', cookie=self.cookie, supplier_setup=url + '/punchout/setup', receiver=receiver.url + '/punchout/cxml/',
        )
        self.save('setup-request.xml', xml.replace(seed['secret'], '[REDACTED]'))
        status, _, body = browser.request(url + '/punchout/setup', xml.encode('utf-8'), 'text/xml', accept='text/xml')
        self.save('setup-response.xml', body)
        try:
            root = poom.parse_xml(body)
            code, start = root.find('.//Status').get('code'), (root.findtext('.//StartPage/URL') or '').strip()
        except Exception as error:  # noqa: BLE001 - any unreadable answer is the same failure
            code, start = repr(error), ''
        self.must(status == 200 and code == '200' and start.startswith(url + '/punchout/start/'), 'setup_answers_200_with_a_start_page', http=status, cxml=code)

        mode = self.args.redeem
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
        self.notes.append('start link redeemed by ' + how)
        self.must(kind == 'redeemed' and browser.logged_in(), 'start_link_redeems_and_signs_the_visit_in', method=how, http=status)
        if scanner_proof:
            # Single use: a second POST, from a browser that never held the visit, is refused.
            again = Browser(url).request(start, b'', forms.URLENCODED)
            self.check(again[0] == 403, 'a_second_post_is_refused', http=again[0])
        landing = urllib.parse.urljoin(start, headers.get('Location'))
        status, _, body, _ = browser.get(landing)
        self.check(status == 200 and re.search(r'<body[^>]*class="[^"]*\bpow-visit\b', body) is not None, 'landing_page_is_inside_the_visit', http=status)

        self.quantities = {}
        for index, (sku, product) in enumerate(seed['products'].items()):
            quantity = 2 if index == 0 else 1
            status, _, body = browser.request(url + '/?wc-ajax=add_to_cart', forms.encode([('product_id', str(product['id'])), ('quantity', str(quantity))], forms.URLENCODED)[0], forms.URLENCODED, accept='application/json')
            try:
                answer = json.loads(body)
            except ValueError:
                answer = {'error': True}
            self.must(status == 200 and not answer.get('error') and 'cart_hash' in answer, 'wc_ajax_add_to_cart ' + sku, http=status)
            self.quantities[sku] = quantity

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
        return {
            'buyer_cookie': self.cookie, 'buyer': connection['buyer'], 'supplier': connection['supplier'], 'deployment_mode': connection['deployment_mode'],
            'currency': seed['currency'], 'shared_secret': seed['secret'], 'lines': lines,
            # The fixture's only shipping method is free shipping, so an emitted freight line would be 0.00.
            'freight': '0.00' if connection['emit_delivery_line'] else None, 'freight_sku': connection['freight_supplier_part_id'],
            'ship_to_contains': [seed['ship_to']['address_1'], seed['ship_to']['city']] if connection['emit_ship_to'] else None,
            'notes': self.note if connection['delivery_notes_policy'] == 'item_detail_extrinsic' else None,
        }


def checkout_path(browser, run, seed):
    """0.5.0 extension point: leave through WooCommerce checkout instead of the review.

    To build with ticket 29, returning the handoff page HTML exactly as
    `Run.review_path` does, so capture and every check stay shared:
      1. GET the cart (?page_id=<cart>): the proceed button and the mini-cart
         Checkout button carry the visit-only label ("Review").
      2. GET the classic checkout (?page_id=<seed checkout page_id>, which must
         be the [woocommerce_checkout] page): the `punchout` payment method is
         the only one offered, `data-order_button_text` is the label setting
         ("Submit"), the shipping section is open on the default saved address
         (Address Book select), no delivery estimate, VAT wording at most once.
      3. Read `woocommerce-process-checkout-nonce` and the form; POST
         /?wc-ajax=checkout with the form's own billing/shipping fields,
         `order_comments`, the chosen saved address and
         `payment_method=punchout` (forms.encode, URLENCODED as WooCommerce's
         script sends it). Expect JSON {"result":"success","redirect":...}.
      4. GET the redirect: the GET handoff under /punchout/. Return its HTML.
    Second leg (spec "Testing Decisions"): repeat the Submit (Sent state, no
    second order), a zero-total cart, a forged punchout method outside a visit.
    """
    raise NotImplementedError('The 0.5.0 checkout path is not built yet; run 0.4.x releases with --path review.')


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
    parser.add_argument('--out', type=Path, help='run directory (default: <wp-path>/../e2e-runs/run-<id>)')
    parser.add_argument('--wait', type=float, default=20.0, help='seconds to wait for the site to accept connections (default 20)')
    args = parser.parse_args(argv)
    os.umask(0o077)
    args.out = args.out or (args.wp_path.resolve().parent / 'e2e-runs' / ('run-' + uuid.uuid4().hex[:12]))
    args.out.mkdir(parents=True, exist_ok=False)
    os.environ['POW_E2E_PHP'] = args.php

    run = Run(args)
    restore = link_plugin(args.wp_path, args.link_plugin) if args.link_plugin else None
    server = receiver = None
    seeded = False
    try:
        if not args.url:
            port = free_port()
            args.url = 'http://%s:%d' % (LOOPBACK, port)
            log = (args.out / 'php-server.log').open('w')
            server = subprocess.Popen([args.php, '-S', '%s:%d' % (LOOPBACK, port), '-t', str(args.wp_path), str(HERE / 'router.php')], env=dict(os.environ, POW_E2E_ORIGIN=args.url, PHP_CLI_SERVER_WORKERS='4'), stdout=log, stderr=subprocess.STDOUT)
        require_loopback(args.url)
        run.must(wait_for(args.url, args.wait, server), 'site_server_answers', url=args.url, server_exit=server.poll() if server else None)
        receiver = Receiver()

        run.wp('seed')
        seeded = True
        seed = json.loads((args.out / 'fixture.json').read_text())
        version = run.version = seed['versions']['plugin']
        path = path_for(version, args.path)
        print('PunchOut %s on WooCommerce %s, WordPress %s; path: %s; site %s' % (version, seed['versions']['woocommerce'], seed['versions']['wordpress'], path, args.url), flush=True)

        browser = Browser(args.url)
        run.punch_in(browser, seed, receiver)
        if path == 'checkout':
            handoff, replay = checkout_path(browser, run, seed), None
        else:
            handoff, replay = run.review_path(browser, seed)
        field, value, xml, posted = run.capture(browser, handoff, receiver)

        results = poom.check(xml, run.expected(seed), capture={'field': field, 'content_type': posted['content_type']})
        (args.out / 'checks.json').write_text(json.dumps(results, indent=1, default=str))
        for result in results:
            run.check(result['ok'], 'cxml ' + result['check'], **({} if result['ok'] else result['detail']))

        if replay is not None:
            second, review, submitter, choose = replay
            status, _, again = second.submit(review, submitter, choose)
            form = handoff_form(again, args.url)
            run.check(status == 200 and form is not None and form.submission() == [(field, value)], 'a_repeated_submit_gets_the_same_handoff', http=status)

        status, _, body, _ = browser.get(args.url + '/?page_id=' + str(seed['pages']['shop']))
        run.check(re.search(r'<body[^>]*class="[^"]*\bpow-visit\b', body) is None, 'after_the_return_the_browser_is_no_longer_in_the_visit', http=status)

        run.wp('inspect', POW_E2E_PAYLOAD=run.payload, POW_E2E_RESULT=str(args.out / 'inspect.json'))
        state = json.loads((args.out / 'inspect.json').read_text())
        visit, orders = state['visit'] or {}, state['orders']
        run.check(visit.get('status') == 'returned', 'visit_is_returned', status=visit.get('status'))
        run.check(visit.get('login_live') is False, 'visit_login_is_dead')
        run.check(len(orders) == 1, 'exactly_one_order_for_the_visit', orders=[o['id'] for o in orders])
        if orders:
            order = orders[0]
            run.check(order['customer'] == seed['account'], 'order_belongs_to_the_bound_account', customer=order['customer'])
            run.check(order['buyer_cookie'] == run.cookie, 'order_names_the_buyer_cookie')
            run.check(sorted((line['sku'], line['qty']) for line in order['lines']) == sorted(run.quantities.items()), 'order_lines_equal_the_cart', lines=order['lines'])
            if path == 'review':
                run.check(order['status'] == 'punchout-quote', 'order_is_a_punchout_quote', status=order['status'])
    except NotImplementedError as error:
        run.check(False, 'release_path_available', reason=str(error))
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
