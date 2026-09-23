"""Opt-in real HTTP/native DB Account acceptance; expects guarded local runtime wrappers and server.

The My Account surface under test is one read-only screen: the setup-XML download
for the account a connection is bound to. There is no application form, no
rotation and no deactivation to drive over HTTP any more — an administrator owns
all of that — so what this driver proves is that the screen shows the connection
and hands out the template to its own account holder, refuses everyone else, and
does not exist at all for a request that is inside a punchout visit.
"""
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

def download(c, n):
    return request(c, f['account_url'], dict(pow_account_action='download_setup_template', _wpnonce=n))

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
    check(status == 200 and 'Punchout integration' in body, 'fresh/upgraded pretty endpoint resolves the actual Woo account menu')
    check('Request connection' not in body and 'pow_name' not in body and 'Rotate secret' not in body, 'the surviving tab offers no application or management form')
    check(f['docs_url'] in body and all(s in body for s in ['UserEmail', 'UniqueUsername', 'UniqueName', 'Contact/Email']), 'actual published documentation and buyer identity guidance')
    check('No active punchout connection' in body, 'a pending connection is not presented as usable')
    f = step('pending')
    f = step('approve')
    step('audit')
    active = request(owner, url)[2]
    check('native-supplier' in active and '2026-01-01 02:00:00.000' in active, 'owner sees safe connection fields and native audit result')
    check('Download setup XML' in active, 'an active bound connection offers its setup template')
    check(f['sender'] not in request(other, url)[2], 'another account cannot view this connection identity')
    check(f['sender'] not in request(linked, url)[2], 'a legacy-linked ordinary login cannot view this connection identity')
    check(f['sender'] not in request(guest, url)[2], 'a guest cannot view this connection identity')
    n = nonce(active)
    check(download(owner, 'invalid')[0] == 403, 'invalid native nonce refuses the download')
    check(request(owner, url, dict(pow_account_action='download_setup_template'))[0] == 403, 'missing native nonce refuses the download')
    check(download(other, n)[0] == 403, 'another authenticated actor cannot reuse the owner nonce')
    status, headers, xml = download(owner, n)
    (out / 'setup-template.xml').write_text(xml)
    check(status == 200 and headers.get('Content-Type', '').startswith('application/xml'), 'the owner downloads its setup template')
    check('attachment; filename="punchout-setup-' in headers.get('Content-Disposition', '') and headers.get('X-Content-Type-Options') == 'nosniff', 'the template is a private attachment')
    check(headers.get('Cache-Control') == 'no-store, private, max-age=0' and headers.get('Referrer-Policy') == 'no-referrer' and not headers.get('Location'), 'the download is private no-store no-referrer without redirect')
    check(ET.fromstring(xml).findtext('.//SharedSecret') not in (None, '') and f['old_secret'] not in xml, 'the template carries a placeholder, never the issued secret')
    # Two simultaneous downloads must both answer without inventing state.
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(lambda _: download(owner, n), range(2)))
    check([x[0] for x in results] == [200, 200], 'simultaneous downloads both succeed')
    f = step('rotation')
    check(f['new_secret'] not in request(owner, url)[2] and f['new_secret'] not in download(owner, n)[2], 'no GET or download ever reveals a plaintext secret')
    step('no_leaks')
    f = step('finished')
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
    check(ET.fromstring(replay).findtext('.//StartPage/URL') == starts[-1], 'idempotent native setup replay reuses the visit')
    visit, visit_jar = client()
    check(request(visit, starts[-1])[0] in [302, 303], 'real StartPage redeems one-time token and signs the visit in')
    check(any(cookie.name.startswith('wordpress_logged_in_') for cookie in visit_jar), 'the visit holds its own login cookie for the bound account')
    f = step('employees')
    status, headers, inside = request(visit, url)
    (out / 'visit-get.html').write_text(inside)
    check(status in [301, 302, 303] and 'Punchout integration' not in inside, 'the integration tab is unreachable during a punchout visit')
    check(download(visit, n)[0] != 200, 'a visit cannot download the setup template')
    check('Punchout integration' in request(owner, url)[2], 'the account holder keeps its own tab while a visit is live')
    step('visit')
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
