"""Opt-in real HTTP/native DB Account acceptance; expects guarded local runtime wrappers and server."""
import argparse
import concurrent.futures
import http.cookiejar
import json
import os
from pathlib import Path
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request
import uuid
import xml.etree.ElementTree as ET

parser = argparse.ArgumentParser()
parser.add_argument('--runtime', type=Path, required=True)
parser.add_argument('--url', required=True)
args = parser.parse_args()
assert urllib.parse.urlparse(args.url).hostname == '127.0.0.1'
os.umask(0o077)
out = args.runtime / 'account-tests' / ('run-' + uuid.uuid4().hex[:12])
out.mkdir(parents=True)
fixture = out / 'fixture.json'
native = Path(__file__).resolve().parents[1] / 'Integration' / 'AccountNative.php'
env = dict(os.environ, POW_NATIVE_TESTS='disposable', POW_ACCOUNT_FIXTURE=str(fixture), POW_ACCOUNT_URL=args.url, POW_ACCOUNT_MAIL=str(args.runtime / 'mail.jsonl'))
passed = 0

def check(ok, name):
    global passed
    if not ok:
        raise AssertionError(name)
    passed += 1
    print('PASS ' + name, flush=True)

def step(name):
    p = subprocess.run(['bash', str(args.runtime / 'wp.sh'), '--user=1', 'eval-file', str(native)], env=dict(env, POW_ACCOUNT_STEP=name), text=True, capture_output=True, timeout=60)
    with (out / 'native.log').open('a') as f:
        f.write(name + '\n' + p.stdout + p.stderr)
    if p.returncode:
        raise RuntimeError(name + ': ' + p.stdout + p.stderr)
    print(p.stdout, end='', flush=True)
    return json.loads(fixture.read_text())

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

def client():
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect()), jar

def request(c, url, data=None, method=None, content_type=None):
    headers = {}
    if isinstance(data, dict):
        data = urllib.parse.urlencode(data).encode()
        headers['Content-Type'] = 'application/x-www-form-urlencoded'
    if content_type:
        headers['Content-Type'] = content_type
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        response = c.open(req, timeout=30)
    except urllib.error.HTTPError as e:
        response = e
    return response.code, response.headers, response.read().decode(errors='replace')

def login(key):
    c, jar = client()
    request(c, args.url + '/wp-login.php')
    status, headers, body = request(c, args.url + '/wp-login.php', dict(log=f[key + '_login'], pwd=f['password'], **{'wp-submit': 'Log In', 'testcookie': '1'}))
    check(status == 302 and any(cookie.name.startswith('wordpress_logged_in_') for cookie in jar), 'native password and cookie login for ' + key)
    return c

def nonce(body):
    match = re.search(r'name="_wpnonce" value="([^"]+)"', body)
    if not match:
        raise AssertionError('native account form nonce missing')
    return match[1]

seeded = False
try:
    f = step('seed')
    seeded = True
    step('rewrites')
    owner, other, linked = login('owner'), login('other'), login('linked')
    guest, _ = client()
    url = f['account_url']
    status, _, body = request(owner, url)
    (out / 'owner-get.html').write_text(body)
    check(status == 200 and 'Request connection' in body and 'Punchout integration' in body, 'fresh/upgraded pretty endpoint resolves actual Woo account menu and application')
    check(f['docs_url'] in body and all(s in body for s in ['UserEmail', 'UniqueUsername', 'UniqueName', 'Contact/Email']), 'actual published documentation and employee identity guidance')
    n = nonce(body)
    check('Request connection' not in request(guest, url)[2], 'guest cannot render management form')
    check('Request connection' not in request(linked, url)[2], 'partner-linked ordinary login cannot render management form')
    bclient, bjar = client()
    request(bclient, args.url + '/wp-login.php')
    status, _, _ = request(bclient, args.url + '/wp-login.php', dict(log=f['buyer_login'], pwd=f['password'], testcookie='1'))
    check(not any(c.name.startswith('wordpress_logged_in_') for c in bjar), 'provisioned buyer native password login denied')
    application = dict(pow_account_action='submit', _wpnonce=n, pow_name='Native account application', from_domain='NetworkID', from_identity=f['sender'], sender_domain='', sender_identity='', deployment_mode='test', owner_user_id=f['other'], partner=f['legacy'], status='active', secret='forged-not-a-secret')
    invalid = request(owner, url, dict(application, _wpnonce='invalid'))
    (out / 'invalid-post.html').write_text(invalid[2])
    check(invalid[0] == 403, 'invalid native nonce refuses POST (HTTP ' + str(invalid[0]) + ')')
    missing = dict(application)
    del missing['_wpnonce']
    check(request(owner, url, missing)[0] == 403, 'missing native nonce refuses POST')
    check(request(other, url, application)[0] == 403, 'another authenticated actor cannot reuse owner nonce')
    request(owner, f['account_url'].replace('punchout-integration/', ''), application)
    check('Request connection' in request(owner, url)[2], 'wrong endpoint POST performs no account application')
    check(request(owner, url, application)[0] == 200, 'real HTTP application succeeds')
    check(request(owner, url, application)[0] == 400, 'repeated application refused')
    f = step('pending')
    check('awaiting store approval' in request(owner, url)[2], 'pending view rendered by real Woo endpoint')
    f = step('approve')
    step('audit')
    active = request(owner, url)[2]
    check('native-supplier' in active and '2026-01-01 02:00:00.000' in active, 'owner sees safe connection fields and native audit result')
    check(f['sender'] not in request(other, url)[2], 'other owner cannot view connection identity')
    other_nonce = nonce(request(other, url)[2])
    check(request(other, url, dict(pow_account_action='rotate', _wpnonce=other_nonce, partner=f['partner']))[0] == 403, 'forged partner ID cannot rotate another owner connection')
    step('clear_limits')
    n = nonce(active)
    for i in range(5):
        check(request(owner, url, dict(pow_account_action='finish_rotation', _wpnonce=n))[0] == 409, 'shared hourly action bucket accepted hit ' + str(i + 1))
    check(request(owner, url, dict(pow_account_action='rotate', _wpnonce=n))[0] == 429, 'sixth account action blocked at five per hour')
    f = step('expiry')
    # Two simultaneous HTTP POSTs reach the real shared mutex and overlap guard.
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(lambda _: request(owner, url, dict(pow_account_action='rotate', _wpnonce=n)), range(2)))
    check(sorted(x[0] for x in results) == [200, 409], 'simultaneous rotation yields one secret and one overlap refusal')
    status, headers, rotated = next(x for x in results if x[0] == 200)
    check(headers.get('Cache-Control') == 'no-store, private, max-age=0' and headers.get('Referrer-Policy') == 'no-referrer' and not headers.get('Location'), 'actual secret POST is private no-store no-referrer without redirect')
    match = re.search(r'Shared secret[^<]*</strong>\s*<code>([^<]+)</code>', rotated)
    check(bool(match), 'direct HTTP POST displays locally issued secret')
    f = json.loads(fixture.read_text())
    f['new_secret'] = match[1]
    fixture.write_text(json.dumps(f))
    step('rotation')
    check(f['new_secret'] not in request(owner, url)[2], 'subsequent real GET never reveals plaintext')
    check(request(owner, url, dict(pow_account_action='rotate', _wpnonce=n))[0] == 409, 'retried rotate refuses existing overlap')
    step('no_leaks')
    check(request(owner, url, dict(pow_account_action='finish_rotation', _wpnonce=n))[0] == 200, 'real HTTP finish rotation succeeds')
    step('finished')
    step('clear_limits')
    payloads = []
    starts = []
    for employee in ['alice', 'bob', 'alice']:
        payload = uuid.uuid4().hex
        xml = f'''<?xml version="1.0"?><cXML version="1.2.008" payloadID="{payload}" timestamp="2026-09-09T12:00:00Z"><Header><From><Credential domain="NetworkID"><Identity>{f['sender']}</Identity></Credential></From><To><Credential domain="NetworkID"><Identity>native-supplier</Identity></Credential></To><Sender><Credential domain="NetworkID"><Identity>{f['sender']}</Identity><SharedSecret>{f['new_secret']}</SharedSecret></Credential><UserAgent>Native Account Test</UserAgent></Sender></Header><Request deploymentMode="test"><PunchOutSetupRequest operation="create"><BuyerCookie>{payload}</BuyerCookie><Extrinsic name="UserEmail">{employee}-{f['sender']}@example.invalid</Extrinsic><BrowserFormPost><URL>{args.url}/fixture-return</URL></BrowserFormPost></PunchOutSetupRequest></Request></cXML>'''
        status, _, result = request(guest, args.url + '/punchout/setup', xml.encode(), content_type='text/xml')
        root = ET.fromstring(result)
        check(status == 200 and root.find('.//Status').get('code') == '200', 'real authenticated cXML setup for ' + employee)
        starts.append(root.findtext('.//StartPage/URL'))
        payloads.append(xml)
    status, _, replay = request(guest, args.url + '/punchout/setup', payloads[-1].encode(), content_type='text/xml')
    check(ET.fromstring(replay).findtext('.//StartPage/URL') == starts[-1], 'idempotent native setup replay reuses session')
    employee, _ = client()
    check(request(employee, starts[-1])[0] in [302, 303], 'real StartPage redeems one-time token and logs buyer in')
    f = step('employees')
    check('Rotate secret' not in request(employee, url)[2], 'actual provisioned cookie cannot render owner management')
    request(employee, url, dict(pow_account_action='deactivate', _wpnonce=n, partner=f['partner']))
    check('Rotate secret' in request(owner, url)[2], 'actual provisioned cookie cannot mutate owner management')
    check(request(owner, url, dict(pow_account_action='deactivate', _wpnonce=n))[0] == 200, 'real owner HTTP deactivation succeeds')
    step('deactivated')
    step('no_leaks')
    step('docs_absent')
    check(f['docs_url'] not in request(owner, url)[2], 'unpublished documentation is no longer linked')
    step('disabled')
    step('disabled_boot')
    check('Punchout integration' not in request(owner, url)[2], 'disabled feature removes real account menu and management view')
    print(f'HTTP assertions passed: {passed}; native assertions: see native.log; fixture: {out}', flush=True)
finally:
    if seeded:
        step('restore')
