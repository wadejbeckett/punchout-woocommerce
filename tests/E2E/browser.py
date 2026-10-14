#!/usr/bin/env python3
"""A real browser on the start link, the checkout and the state page (0.5.0), against the disposable local fixture.

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
  3. states (0.5.0; fixture step `states` chooses a state page and turns "Ask before leaving" on, retire
     puts both back): a start link used once and opened in another browser lands on the state page's Link expired,
     drawn in the theme; once a visit is ended (fixture step `end_visit`), its next page lands on Session ended and
     the browser is signed out, and the page after is an ordinary page.
  4. checkout (0.5.0, with WooCommerce's own checkout script): both products go into the cart, the classic cart's
     button carries the Checkout button label ("Review") and opens the checkout, which offers PunchOut and nothing
     else, its button the place-order label ("Submit"); the ship-to box, ticked as it opened, is unticked and ticked
     again by a click; a saved shipping entry is chosen from the address book's select; Submit is pressed. The
     handoff posts the cart to the punchback URL once (the receiver gets one cXML whose ShipTo is the chosen entry),
     the spent handoff lands on the Sent state drawn in the theme, and the visit is returned with one order shipped
     to that entry. The HTTP driver cannot tick a box; this is the one case that needs the browser.
  5. leave: with a line in the cart, pressing Cancel and return opens the question as a modal dialog (the plugin's
     own script) with "Keep shopping" focused, and sends nothing; Keep shopping closes it and the visit stays open;
     the dialog's leave button then answers with the plain Not sent page, which posts one empty message to the
     punchback URL, and the visit is closed.

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


def handoffs(page):
    """The addresses of the GET handoffs the page asks for, in order (0.5.0: the checkout's answer sends it there)."""
    seen = []

    def request(req):
        if req.method == 'GET' and urllib.parse.urlsplit(req.url).path.rstrip('/').endswith('/punchout/handoff'):
            seen.append(req.url)

    page.on('request', request)
    return seen


def address_book_select(names_and_options, keys):
    """The name of the checkout select that offers every one of the account's saved address `keys`, from
    [(name, [option values])] as the page lists its selects, or None."""
    for name, options in names_and_options:
        if name and keys and set(keys) <= set(options):
            return name
    return None


def returns(page):
    """The methods of the requests the page sends to /punchout/return, in order (0.5.0: the leave question)."""
    sent = []

    def request(req):
        if urllib.parse.urlsplit(req.url).path.rstrip('/').endswith('/punchout/return'):
            sent.append(req.method)

    page.on('request', request)
    return sent


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

    # -- 4. the checkout, with WooCommerce's own script -----------------------

    def checkout(self, chromium, seed, receiver, states):
        """Cart "Review" -> checkout offers PunchOut only, "Submit" -> the ship-to box ticked again -> a saved
        shipping entry -> Submit -> the Sent state; the receiver gets one cXML carrying that entry as ShipTo. Saved
        addresses only (0.5.1): the select offers no "add new" and the shipping fields are hidden inputs, which the
        address book's script still fills on a card change."""
        url = self.args.url
        labels = (seed.get('checkout') or {}).get('labels') or {}
        book = seed.get('saved_addresses')
        self.must(isinstance(book, dict) and book.get('chosen') in (book.get('entries') or {}), 'checkout: seed_gives_the_account_saved_addresses')
        entries, chosen = book['entries'], book['chosen']
        page_id = states['state_page']['id']
        context = chromium.new_context()
        try:
            page = context.new_page()
            seen = handoffs(page)
            start, payload = self.setup(seed, receiver, 'checkout', 'buyer.three')
            page.goto(start, wait_until='commit')
            leave_start(page)
            self.must(in_visit(page), 'checkout: the_link_lands_inside_the_visit', url=page.url)
            for sku, product in seed['products'].items():
                answer = context.request.post(url + '/?wc-ajax=add_to_cart', form={'product_id': str(product['id']), 'quantity': '1'})
                self.must(answer.status == 200, 'checkout: add_to_cart ' + sku, http=answer.status)

            page.goto(url + '/?page_id=' + str(seed['pages']['classic_cart']), wait_until='load')
            self.save('cart-browser.html', page.content())
            button = page.locator('.wc-proceed-to-checkout a.checkout-button')
            self.must(button.count() >= 1, 'checkout: the_cart_offers_the_checkout_button', url=page.url)
            self.check(button.first.inner_text().strip() == labels.get('proceed'), 'checkout: the_cart_button_carries_the_label', shown=button.first.inner_text().strip(), setting=labels.get('proceed'))
            self.check(page.locator('form.pow-return-form, .pow-return-button').count() == 0, 'checkout: the_cart_has_no_return_button')
            with page.expect_navigation(timeout=SECONDS * 1000):
                button.first.click()
            page.wait_for_selector('form.checkout #place_order', timeout=SECONDS * 1000)
            # WooCommerce's script refreshes the order review over AJAX as the page settles.
            page.wait_for_timeout(1500)
            self.save('checkout-browser.html', page.content())
            methods = page.locator('form.checkout input[name="payment_method"]')
            offered = [methods.nth(i).get_attribute('value') for i in range(methods.count())]
            self.must(offered == ['punchout'], 'checkout: only_punchout_is_offered', methods=offered)
            self.check(page.locator('#place_order').inner_text().strip() == labels.get('place_order'), 'checkout: the_button_is_the_place_order_label', shown=page.locator('#place_order').inner_text().strip(), setting=labels.get('place_order'))

            # The ship-to box as it opened (ticked), then unticked and ticked again, as a buyer who changes her mind does.
            box = page.locator('#ship-to-different-address-checkbox')
            self.must(box.count() == 1, 'checkout: the_shipping_section_is_offered')
            self.check(box.is_checked(), 'checkout: the_shipping_section_opens_ticked')
            box.uncheck()
            page.wait_for_timeout(400)
            box.check()
            page.wait_for_timeout(600)
            self.check(box.is_checked() and page.locator('#shipping_address_1').count() == 1, 'checkout: the_ship_to_box_is_ticked_again_and_the_fields_are_there')
            # 0.5.1: the shipping fields are hidden inputs (they post; nothing can be typed), the selector stays visible;
            # the shipping-rate radios (shipping_method[...]) are not address fields.
            typable = page.evaluate("() => [...document.querySelectorAll('form.checkout [name^=\"shipping_\"]')].filter(e => !e.name.endsWith('_address_book') && !e.name.startsWith('shipping_method') && !(e.tagName === 'INPUT' && e.type === 'hidden')).map(e => e.name)")
            self.check(typable == [] and page.locator('#shipping_address_1').get_attribute('type') == 'hidden', 'checkout: the_shipping_fields_are_hidden_inputs', typable=typable)

            # The other saved entry, chosen from the address book's select (found by the entries it offers, never by name).
            selects = page.evaluate("() => [...document.querySelectorAll('form.checkout select')].map(s => [s.name, [...s.options].map(o => o.value)])")
            select = address_book_select(selects, list(entries))
            self.must(select is not None, 'checkout: the_saved_addresses_are_offered', selects=[name for name, _ in selects])
            offered = dict(selects)[select]
            self.check('add_new' not in offered and page.locator('form.checkout select[name="' + select + '"]').is_visible(), 'checkout: no_add_new_choice_and_the_selector_stays_visible', offered=offered)
            page.select_option('form.checkout select[name="' + select + '"]', chosen)
            deadline = time.monotonic() + SECONDS
            while page.locator('#shipping_address_1').input_value() != entries[chosen].get('address_1') and time.monotonic() < deadline:
                page.wait_for_timeout(250)
            self.check(page.locator('#shipping_address_1').input_value() == entries[chosen].get('address_1'), 'checkout: the_address_books_script_fills_the_hidden_fields', shown=page.locator('#shipping_address_1').input_value(), chosen=entries[chosen].get('address_1'))
            if page.locator('#shipping_address_1').input_value() != entries[chosen].get('address_1'):
                # No script filled the fields: set them as the address book's script would (hidden inputs take no typing).
                for field, value in entries[chosen].items():
                    target = page.locator('#shipping_' + field)
                    if target.count() == 1 and value is not None and target.evaluate('e => e.tagName') == 'INPUT':
                        target.evaluate('(e, v) => { e.value = v; }', str(value))
            page.wait_for_timeout(1500)
            self.check(page.locator('#shipping_address_1').input_value() == entries[chosen].get('address_1'), 'checkout: the_chosen_entry_fills_the_shipping_fields', shown=page.locator('#shipping_address_1').input_value(), chosen=entries[chosen].get('address_1'))
            note = page.locator('#order_comments')
            if note.count():
                note.fill('Leave at the gate. Browser run ' + seed['run'] + '.')

            before = len(receiver.captured)
            page.click('#place_order')
            deadline = time.monotonic() + SECONDS * 2
            while len(receiver.captured) == before and time.monotonic() < deadline:
                page.wait_for_timeout(250)
            page.wait_for_timeout(500)
            posted = receiver.captured[before:]
            self.check(len(posted) == 1, 'checkout: submit_hands_the_order_to_the_punchback_url_once', posts=len(posted), handoffs=len(seen))
            self.must(bool(seen), 'checkout: the_checkout_answer_sent_the_browser_to_the_handoff')
            if posted:
                fields = posted[0]['fields']
                field = next((name for name in ('cxml-base64', 'cxml-urlencoded') if name in fields), None)
                xml = poom.decode_field(field, fields[field][0]) if field else ''
                self.save('poom-browser.xml', xml)
                self.check('<PunchOutOrderMessage>' in xml and '<ItemIn' in xml, 'checkout: the_browser_posts_a_punchout_order_message', field=field)
                ship, billing = driver.ship_to_text(xml).lower(), (seed['billing']['address_1'] or '').lower()
                self.check(entries[chosen]['address_1'].lower() in ship and bool(billing) and billing not in ship, 'checkout: ship_to_is_the_chosen_saved_entry_not_billing', ship_to=driver.ship_to_text(xml), chosen=entries[chosen]['address_1'])

            # 0.5.3: Back from the purchasing system skips the self-posting handoff page and asks for checkout again;
            # with the cart sent and emptied, this browser lands on the Sent state, not on an empty cart.
            page.go_back(wait_until='load')
            self.save('sent-back.html', page.content())
            self.check(driver.state_location(page.url) == (page_id, 'sent') and page.locator('.pow-state--sent').count() == 1, 'checkout: back_after_submit_lands_on_the_sent_state', url=page.url)

            # The spent handoff: this browser asks for it again and lands on the Sent state, drawn in the theme.
            page.goto(seen[0], wait_until='load')
            self.save('sent-browser.html', page.content())
            shown = page.locator('.pow-state--sent .pow-state__title')
            self.check(driver.state_location(page.url) == (page_id, 'sent') and shown.count() == 1 and shown.is_visible() and shown.inner_text().strip() == states['state_page']['sent_title'], 'checkout: the_spent_handoff_lands_on_the_sent_state_in_the_theme', url=page.url)
            self.check(not signed_in(context), 'checkout: the_browser_is_signed_out')

            state = self.visit_state(payload, 'checkout')
            visit, orders = state['visit'] or {}, state['orders']
            self.check(visit.get('status') == 'returned' and visit.get('login_live') is False and len(orders) == 1, 'checkout: the_visit_is_returned_with_one_order', status=visit.get('status'), login_live=visit.get('login_live'), orders=len(orders))
            if orders:
                order = orders[0]
                self.check(order['status'] == 'punchout-quote' and order.get('payment_method') == 'punchout', 'checkout: the_order_is_a_punchout_quote_placed_with_the_method', status=order['status'], payment_method=order.get('payment_method'))
                self.check((order.get('shipping') or {}).get('address_1') == entries[chosen]['address_1'], 'checkout: the_order_ships_to_the_chosen_entry', shipping=order.get('shipping'))
            self.check(state['account_profile'] == seed['account_profile'], 'checkout: the_bound_accounts_profile_and_saved_addresses_are_unchanged', changed=driver.profile_changes(seed['account_profile'], state['account_profile']))
        finally:
            context.close()


class StateRun(BrowserRun):
    """0.5.0: the state page and the leave question, in Chromium."""

    def states(self, chromium, seed, receiver):
        out = self.args.out / 'states-browser.json'
        self.wp('states', POW_E2E_RESULT=str(out))
        states = json.loads(out.read_text())
        url = self.args.url
        page_id = states['state_page']['id']

        context = chromium.new_context()
        other = chromium.new_context()
        try:
            page = context.new_page()
            start, payload = self.setup(seed, receiver, 'states', 'buyer.four')
            page.goto(start, wait_until='commit')
            leave_start(page)
            self.must(in_visit(page), 'states: the_link_lands_inside_the_visit', url=page.url)

            # Link expired: the same link, opened in another browser.
            elsewhere = other.new_page()
            elsewhere.goto(start, wait_until='commit')
            try:
                elsewhere.wait_for_url(lambda u: 'pow_state=expired' in u, timeout=SECONDS * 1000)
            except Exception:  # noqa: BLE001 - Playwright's timeout: checked below
                pass
            elsewhere.wait_for_load_state('load')
            self.save('states-expired-browser.html', elsewhere.content())
            shown = elsewhere.locator('.pow-state--expired .pow-state__title')
            self.check(driver.state_location(elsewhere.url) == (page_id, 'expired') and shown.count() == 1 and shown.is_visible() and shown.inner_text().strip() == 'Link expired', 'states: a_used_link_lands_on_link_expired_in_the_theme', url=elsewhere.url)
            self.check(elsewhere.evaluate('(id) => document.body.classList.contains("page-id-" + id)', page_id), 'states: link_expired_is_the_chosen_page')

            # Session ended: the visit is ended while its browser is still on the shop.
            self.wp('end_visit', POW_E2E_PAYLOAD=payload)
            page.goto(url + '/?page_id=' + str(seed['pages']['shop']), wait_until='load')
            self.save('states-ended-browser.html', page.content())
            shown = page.locator('.pow-state--ended .pow-state__title')
            self.check(driver.state_location(page.url) == (page_id, 'ended') and shown.count() == 1 and shown.is_visible() and shown.inner_text().strip() == 'Session ended', 'states: the_next_page_lands_on_session_ended', url=page.url)
            self.check(not signed_in(context), 'states: the_browser_is_signed_out')
            page.goto(url + '/?page_id=' + str(seed['pages']['shop']), wait_until='load')
            self.check(driver.state_location(page.url) == (None, None) and not in_visit(page), 'states: the_page_after_is_an_ordinary_page', url=page.url)
        finally:
            other.close()
            context.close()
        return states

    def leave(self, chromium, seed, receiver, states):
        url = self.args.url
        labels = states['labels']
        context = chromium.new_context()
        try:
            page = context.new_page()
            start, payload = self.setup(seed, receiver, 'leave', 'buyer.five')
            page.goto(start, wait_until='commit')
            leave_start(page)
            self.must(in_visit(page), 'leave: the_link_lands_inside_the_visit', url=page.url)
            sku, product = next(iter(seed['products'].items()))
            answer = context.request.post(url + '/?wc-ajax=add_to_cart', form={'product_id': str(product['id']), 'quantity': '1'})
            self.must(answer.status == 200, 'leave: add_to_cart ' + sku, http=answer.status)

            page.goto(url + '/?page_id=' + str(states['leave_page']['id']), wait_until='load')
            sent = returns(page)
            dialog = page.locator('dialog.pow-leave')
            self.must(page.locator('form.pow-abandon-form button[type="submit"]').count() == 1 and dialog.count() == 1, 'leave: the_cancel_control_and_its_dialog_are_on_the_page')
            self.check(not dialog.is_visible(), 'leave: the_dialog_starts_closed')
            page.click('form.pow-abandon-form button[type="submit"]')
            dialog.wait_for(state='visible', timeout=SECONDS * 1000)
            page.wait_for_timeout(300)
            modal = page.evaluate("() => document.querySelector('dialog.pow-leave').matches(':modal')")
            self.check(modal and sent == [], 'leave: pressing_cancel_and_return_opens_the_question_and_sends_nothing', modal=modal, sent=sent)
            self.check(dialog.locator('.pow-leave__question').inner_text().strip() == states['leave_page']['question']
                       and dialog.locator('.pow-leave__confirm').inner_text().strip() == labels['leave']
                       and dialog.locator('.pow-leave__stay').inner_text().strip() == labels['stay'], 'leave: the_question_and_its_buttons_are_the_sites_words')
            focused = page.evaluate("() => !!document.activeElement && document.activeElement.classList.contains('pow-leave__stay')")
            self.check(focused, 'leave: keep_shopping_has_the_focus')
            self.save('leave-browser.html', page.content())

            page.click('dialog.pow-leave .pow-leave__stay')
            page.wait_for_timeout(300)
            self.check(not dialog.is_visible() and sent == [], 'leave: keep_shopping_closes_it_and_sends_nothing', sent=sent)
            visit = self.visit_state(payload, 'leave-kept')['visit'] or {}
            self.check(visit.get('status') == 'active', 'leave: the_visit_stays_open', status=visit.get('status'))

            before = len(receiver.captured)
            page.click('form.pow-abandon-form button[type="submit"]')
            dialog.wait_for(state='visible', timeout=SECONDS * 1000)
            # The Not sent page posts itself away at once, so its answer is read on the way through.
            answered = []

            def through(route):
                response = route.fetch()
                answered.append((response.status, response.text()))
                route.fulfill(response=response)

            page.route('**/punchout/return', through)
            page.click('dialog.pow-leave .pow-leave__confirm')
            deadline = time.monotonic() + SECONDS
            while not answered and time.monotonic() < deadline:
                page.wait_for_timeout(100)
            status, body = answered[0] if answered else (None, '')
            self.save('leave-not-sent-browser.html', body)
            self.check(status == 200 and driver.state_page(body) == 'not-sent', 'leave: the_answer_leaves_with_the_not_sent_state', http=status, state=driver.state_page(body))
            deadline = time.monotonic() + SECONDS
            while len(receiver.captured) == before and time.monotonic() < deadline:
                page.wait_for_timeout(250)
            page.wait_for_timeout(500)
            posted = receiver.captured[before:]
            items = None
            if len(posted) == 1:
                fields = posted[0]['fields']
                field = next((name for name in ('cxml-base64', 'cxml-urlencoded') if name in fields), None)
                xml = poom.decode_field(field, fields[field][0]) if field else ''
                root = poom.parse_xml(xml) if xml else None
                items = len(root.findall('.//ItemIn')) if root is not None and root.find('.//PunchOutOrderMessage') is not None else None
            self.check(len(posted) == 1 and items == 0 and sent == ['POST'], 'leave: one_empty_message_reaches_the_punchback_url', posts=len(posted), items=items, sent=sent)
            visit = self.visit_state(payload, 'leave')['visit'] or {}
            self.check(visit.get('status') == 'closed' and visit.get('login_live') is False, 'leave: the_visit_is_closed', status=visit.get('status'), login_live=visit.get('login_live'))
        finally:
            context.close()


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

    run = StateRun(args)
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
        run.must(bool((seed.get('checkout') or {}).get('payment_method')), 'release_has_the_punchout_payment_method', version=run.version)
        print('PunchOut %s on WooCommerce %s, WordPress %s; Chromium %s; site %s' % (run.version, seed['versions']['woocommerce'], seed['versions']['wordpress'], args.chromium, args.url), flush=True)

        with sync_playwright() as playwright:
            chromium = playwright.chromium.launch(executable_path=str(args.chromium), headless=True)
            try:
                print('Chromium ' + chromium.version, flush=True)
                run.notes.append('Chromium ' + chromium.version)
                context, page, payload = run.auto(chromium, seed, receiver)
                context.close()
                run.double_click(chromium, seed, receiver)
                states = run.states(chromium, seed, receiver)
                run.checkout(chromium, seed, receiver, states)
                run.leave(chromium, seed, receiver, states)
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
