#!/usr/bin/env python3
"""A real browser on the start link and the review (0.4.23 and later), against the disposable local fixture.

Opt-in, like driver.py, and on the same fixture (tests/README.md, "Real-browser run"). It needs Python Playwright
and a local Chromium named with --chromium (or POW_E2E_CHROMIUM); it never downloads a browser. Headless. One run
seeds the fixture as the driver does (tests/E2E/fixture.php), serves the site on 127.0.0.1, and then:

  1. auto: Chromium opens a fresh start link. The page's own script posts it once (no policy violation), and the
     browser lands on the landing page inside the visit (body class pow-visit, signed in; the visit is active and
     its login live). The same browser posting the link again is sent to the same landing page; another browser
     gets 403.
  2. double click: with "Login link needs a click" on (fixture step start_click, put back by retire), the page
     waits; two presses 80 ms apart on its button send one POST and land inside the visit. Back to the page and one
     more press: the same browser lands again.
  3. review: in the first browser, both products go into the cart and the cart's Punchout exit opens the review.
     The connection sends no delivery line, so the review shows no amount beside any delivery method, no
     estimate row and no tax wording, its words are the seeded settings, and its update control says
     "Update delivery". Submit then hands the cart to the punchback URL once, and the visit is returned.

It talks to nothing but 127.0.0.1, prints no secret, and writes its evidence to a private run directory (mode 700).
"""
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys
import time
import urllib.parse
import uuid

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import driver  # noqa: E402
import poom  # noqa: E402

START = '/punchout/start/'
SECONDS = 20
# The gap between the two presses of a double click, well inside the time a redeem takes on the fixture.
PRESS_GAP_MS = 80


def in_visit(page):
    """The landing page as the visit's browser sees it: the plugin marks the body of every page inside a visit."""
    return bool(page.evaluate("() => document.body !== null && document.body.classList.contains('pow-visit')"))


def watch(page):
    """What the page sends to the start link, the answers it gets, and any policy violation it reports."""
    seen = {'posts': 0, 'gets': 0, 'landing': [], 'policy': []}

    def request(req):
        if START in req.url:
            seen['posts' if req.method == 'POST' else 'gets'] += 1

    def response(res):
        if START in res.url and res.request.method == 'POST' and res.status in (302, 303):
            seen['landing'].append(urllib.parse.urljoin(res.url, res.headers.get('location') or ''))

    def console(message):
        # A favicon fetch is the browser's, not the page's; everything else counts.
        if 'Content Security Policy' in message.text and 'favicon' not in message.text:
            seen['policy'].append(message.text)

    page.on('request', request)
    page.on('response', response)
    page.on('console', console)
    return seen


def leave_start(page):
    """Wait until the browser has left the start link (True) or SECONDS pass on it (False: it stayed, for example on
    the expired page, which has the same address)."""
    try:
        page.wait_for_url(lambda u: START not in u, timeout=SECONDS * 1000)
    except Exception:  # noqa: BLE001 - Playwright's timeout: the page stayed on the start link
        pass
    page.wait_for_load_state('load')
    return START not in page.url


def signed_in(context):
    return any(cookie['name'].startswith('wordpress_logged_in_') for cookie in context.cookies())


class BrowserRun(driver.Run):

    def setup(self, seed, receiver, label, buyer):
        """A fresh setup request, as the purchasing system sends one per visit. Returns (start link, payloadID).

        Each case is its own buyer: a new setup for the same buyer ends that buyer's earlier visit (superseded)."""
        url = self.args.url
        connection = seed['connection']
        payload = '{' + str(uuid.uuid4()).upper() + '}'
        xml = driver.setup_request(
            payload_id=payload, timestamp=time.strftime('%Y-%m-%dT%H:%M:%S', time.gmtime()), buyer=connection['buyer'], supplier=connection['supplier'],
            secret=seed['secret'], email=buyer + '@buyer.example.com', cookie='{' + str(uuid.uuid4()).upper() + '}', supplier_setup=url + '/punchout/setup',
            receiver=receiver.url + '/punchout/cxml/',
        )
        status, _, body = driver.Browser(url).request(url + '/punchout/setup', xml.encode('utf-8'), 'text/xml', accept='text/xml')
        self.save('setup-response-' + label + '.xml', body)
        try:
            root = poom.parse_xml(body)
            code, start = root.find('.//Status').get('code'), (root.findtext('.//StartPage/URL') or '').strip()
        except Exception as error:  # noqa: BLE001 - any unreadable answer is the same failure
            code, start = repr(error), ''
        self.must(status == 200 and code == '200' and start.startswith(url + START), label + ': setup_answers_200_with_a_start_page', http=status, cxml=code)
        return start, payload

    def visit_state(self, payload, label):
        result = self.args.out / ('inspect-' + label + '.json')
        self.wp('inspect', POW_E2E_PAYLOAD=payload, POW_E2E_RESULT=str(result))
        return json.loads(result.read_text())

    def landed(self, label, page, context, seen, payload, posts=1):
        self.check(seen['posts'] == posts, label + ': one_post_to_the_start_link', posts=seen['posts'], expected=posts)
        self.check(START not in page.url and in_visit(page), label + ': lands_on_the_landing_page_inside_the_visit', url=page.url)
        self.check(signed_in(context), label + ': the_browser_is_signed_in')
        self.check(not seen['policy'], label + ': no_policy_violation', messages=seen['policy'])
        visit = self.visit_state(payload, label)['visit'] or {}
        self.check(visit.get('status') == 'active' and visit.get('login_live') is True, label + ': the_visit_is_active_with_a_live_login', status=visit.get('status'), login_live=visit.get('login_live'))

    # -- 1. the page posts itself --------------------------------------------

    def auto(self, chromium, seed, receiver):
        context = chromium.new_context()
        page = context.new_page()
        seen = watch(page)
        start, payload = self.setup(seed, receiver, 'auto', 'buyer.one')
        page.goto(start, wait_until='commit')
        leave_start(page)
        self.save('landing-auto.html', page.content())
        self.must(bool(seen['landing']), 'auto: the_post_answers_a_redirect', landing=seen['landing'])
        self.landed('auto', page, context, seen, payload)
        landing = seen['landing'][0]

        again = context.request.post(start, form={}, max_redirects=0)
        where = urllib.parse.urljoin(start, again.headers.get('location') or '')
        self.check(again.status == 302 and where == landing, 'auto: a_repeat_post_from_the_same_browser_lands_again', http=again.status, location=where, landing=landing)
        other = chromium.new_context()
        try:
            refused = other.request.post(start, form={}, max_redirects=0)
            self.check(refused.status == 403, 'auto: a_post_from_another_browser_is_refused', http=refused.status)
        finally:
            other.close()
        return context, page, payload

    # -- 2. a double click on the button ------------------------------------

    def double_click(self, chromium, seed, receiver):
        self.wp('start_click', POW_E2E_START_CLICK='yes')
        context = chromium.new_context()
        try:
            page = context.new_page()
            seen = watch(page)
            start, payload = self.setup(seed, receiver, 'double-click', 'buyer.two')
            page.goto(start, wait_until='load')
            self.check(page.url == start and seen['posts'] == 0 and not signed_in(context), 'double-click: the_page_waits_for_a_press', url=page.url, posts=seen['posts'])
            # As a person double-clicks: two presses a moment apart, the second while the first POST is on its way.
            box = page.locator('#pow-start-form button').bounding_box()
            x, y = box['x'] + box['width'] / 2, box['y'] + box['height'] / 2
            page.mouse.click(x, y)
            page.wait_for_timeout(PRESS_GAP_MS)
            page.mouse.click(x, y)
            leave_start(page)
            self.save('landing-double-click.html', page.content())
            self.landed('double-click', page, context, seen, payload)

            # Back to the page and one more press: the same browser is still in the visit, so it lands again.
            page.go_back(wait_until='load')
            self.must(START in page.url and page.locator('#pow-start-form button').count() == 1, 'double-click: back_shows_the_start_page_again', url=page.url)
            page.click('#pow-start-form button')
            leave_start(page)
            self.check(seen['posts'] == 2 and START not in page.url and in_visit(page), 'double-click: back_and_press_again_lands_again', posts=seen['posts'], url=page.url)
        finally:
            context.close()
            self.wp('start_click', POW_E2E_START_CLICK='no')

    # -- 3. the review, with the connection's delivery line off --------------

    def review(self, context, page, payload, seed, receiver):
        url = self.args.url
        for sku, product in seed['products'].items():
            answer = context.request.post(url + '/?wc-ajax=add_to_cart', form={'product_id': str(product['id']), 'quantity': '1'})
            try:
                data = answer.json()
            except Exception:  # noqa: BLE001 - an unreadable answer is a failed add
                data = {'error': True}
            self.must(answer.status == 200 and not data.get('error') and 'cart_hash' in data, 'review: add_to_cart ' + sku, http=answer.status)

        page.goto(url + '/?page_id=' + str(seed['pages']['cart']), wait_until='load')
        cart = page.content()
        self.save('cart-browser.html', cart)
        exit_ = driver.cart_exit(cart, page.url)
        self.must(exit_ is not None, 'review: the_cart_offers_the_punchout_exit', url=page.url)
        kind, target = exit_
        if kind == 'form':
            with page.expect_navigation(timeout=SECONDS * 1000):
                page.click('form.pow-return-form [type="submit"]')
        else:
            page.goto(target, wait_until='load')
        self.must(page.locator('.pow-confirmation form').count() == 1, 'review: the_exit_opens_the_review', url=page.url)

        # As a buyer would: pick the first address and a delivery method when none is chosen; the page updates itself.
        choice = page.locator('#pow-delivery-choice')
        if choice.count() and not choice.input_value():
            with page.expect_navigation(timeout=SECONDS * 1000):
                choice.select_option(index=1)
        groups = page.evaluate("() => [...new Set([...document.querySelectorAll('input[type=radio][name^=\"rates[\"]')].map(r => r.name))]")
        for name in groups:
            radios = page.locator('input[type="radio"][name="' + name + '"]')
            if not page.locator('input[type="radio"][name="' + name + '"]:checked').count():
                with page.expect_navigation(timeout=SECONDS * 1000):
                    radios.first.check()

        html = page.content()
        self.save('review-browser.html', html)
        labels = (seed.get('review') or {}).get('labels') or {}
        priced = [title for title in [(seed.get('shipping') or {}).get('priced_title')] if title]
        for name, ok, detail in driver.review_findings(html, labels, bool(seed['connection']['emit_delivery_line']), priced):
            self.check(ok, 'review: ' + name, **detail)
        shown = page.locator('.pow-confirmation').inner_text()
        self.check('Delivery estimate' not in shown and 'Recalculate' not in shown, 'review: the_visible_text_names_no_estimate', text=shown[:300])

        before = len(receiver.captured)
        page.click('button[name="pow_delivery_action"][value="submit"]')
        deadline = time.monotonic() + SECONDS
        while len(receiver.captured) == before and time.monotonic() < deadline:
            page.wait_for_timeout(250)
        page.wait_for_timeout(500)
        posted = receiver.captured[before:]
        self.check(len(posted) == 1, 'review: submit_hands_the_cart_to_the_punchback_url_once', posts=len(posted))
        if posted:
            fields = posted[0]['fields']
            field = next((name for name in ('cxml-base64', 'cxml-urlencoded') if name in fields), None)
            xml = poom.decode_field(field, fields[field][0]) if field else ''
            self.save('poom-browser.xml', xml)
            self.check('<PunchOutOrderMessage>' in xml, 'review: the_browser_posts_a_punchout_order_message', field=field)
        state = self.visit_state(payload, 'review')
        visit = state['visit'] or {}
        self.check(visit.get('status') == 'returned' and visit.get('login_live') is False and len(state['orders']) == 1, 'review: the_visit_is_returned_with_one_order', status=visit.get('status'), login_live=visit.get('login_live'), orders=len(state['orders']))
        return posted


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    parser.add_argument('--wp-path', type=Path, required=True, help='WordPress root of the disposable fixture')
    parser.add_argument('--wp-cli', type=Path, required=True, help='wp-cli.phar')
    parser.add_argument('--wp-user', default='1', help='fixture administrator for WP-CLI')
    parser.add_argument('--php', default=os.environ.get('POW_E2E_PHP', 'php'))
    parser.add_argument('--chromium', type=Path, default=os.environ.get('POW_E2E_CHROMIUM'), help='a local Chromium executable (or POW_E2E_CHROMIUM)')
    parser.add_argument('--url', help='an already running http://127.0.0.1:<port> server for --wp-path (default: start one)')
    parser.add_argument('--link-plugin', type=Path, help='plugin checkout to link into the fixture for this run; the old link is restored after')
    parser.add_argument('--out', type=Path, help='run directory (default: <wp-path>/../e2e-runs/browser-<id>)')
    parser.add_argument('--wait', type=float, default=20.0, help='seconds to wait for the site to accept connections (default 20)')
    args = parser.parse_args(argv)
    if not args.chromium or not Path(args.chromium).is_file():
        parser.error('--chromium must name a local Chromium executable')
    from playwright.sync_api import sync_playwright  # only this opt-in run needs it
    os.umask(0o077)
    args.out = args.out or (args.wp_path.resolve().parent / 'e2e-runs' / ('browser-' + uuid.uuid4().hex[:12]))
    args.out.mkdir(parents=True, exist_ok=False)
    os.environ['POW_E2E_PHP'] = args.php

    run = BrowserRun(args)
    restore = driver.link_plugin(args.wp_path, args.link_plugin) if args.link_plugin else None
    server = receiver = None
    seeded = False
    try:
        if not args.url:
            port = driver.free_port()
            args.url = 'http://%s:%d' % (driver.LOOPBACK, port)
            log = (args.out / 'php-server.log').open('w')
            server = subprocess.Popen([args.php, '-S', '%s:%d' % (driver.LOOPBACK, port), '-t', str(args.wp_path), str(HERE / 'router.php')], env=dict(os.environ, POW_E2E_ORIGIN=args.url, PHP_CLI_SERVER_WORKERS='4'), stdout=log, stderr=subprocess.STDOUT)
        driver.require_loopback(args.url)
        run.must(driver.wait_for(args.url, args.wait, server), 'site_server_answers', url=args.url, server_exit=server.poll() if server else None)
        receiver = driver.Receiver()

        run.wp('seed')
        seeded = True
        seed = json.loads((args.out / 'fixture.json').read_text())
        run.version = seed['versions']['plugin']
        run.must(driver.at_least(run.version, (0, 4, 23)), 'release_has_the_start_page', version=run.version)
        print('PunchOut %s on WooCommerce %s, WordPress %s; Chromium %s; site %s' % (run.version, seed['versions']['woocommerce'], seed['versions']['wordpress'], args.chromium, args.url), flush=True)

        with sync_playwright() as playwright:
            chromium = playwright.chromium.launch(executable_path=str(args.chromium), headless=True)
            try:
                print('Chromium ' + chromium.version, flush=True)
                run.notes.append('Chromium ' + chromium.version)
                context, page, payload = run.auto(chromium, seed, receiver)
                run.double_click(chromium, seed, receiver)
                run.review(context, page, payload, seed, receiver)
                context.close()
            finally:
                chromium.close()
    except SystemExit as stop:
        run.check(False, 'run_completed', reason=str(stop))
    except (Exception, KeyboardInterrupt) as error:  # noqa: BLE001 - a crash or a timeout is a failed run, never an empty failure list
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
