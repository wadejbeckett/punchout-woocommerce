"""The real-browser run's own decisions, without a browser: what counts as a POST to the start link, where it led,
which policy messages count, and that the run refuses to start without a local Chromium."""
import contextlib
import io
import tempfile
import unittest
from pathlib import Path

import browser

START = 'http://127.0.0.1:8080/punchout/start/' + 'a' * 43


class _Page:
    def __init__(self):
        self.handlers = {}

    def on(self, event, handler):
        self.handlers[event] = handler

    def fire(self, event, value):
        self.handlers[event](value)


class _Request:
    def __init__(self, url, method):
        self.url, self.method = url, method


class _Response:
    def __init__(self, url, method, status, location=None):
        self.url, self.status, self.request = url, status, _Request(url, method)
        self.headers = {'location': location} if location else {}


class _Message:
    def __init__(self, text):
        self.text = text


class Watch(unittest.TestCase):

    def test_it_counts_requests_to_the_start_link_only(self):
        page = _Page()
        seen = browser.watch(page)
        page.fire('request', _Request(START, 'GET'))
        page.fire('request', _Request(START, 'POST'))
        page.fire('request', _Request('http://127.0.0.1:8080/?page_id=5', 'GET'))
        page.fire('request', _Request('http://127.0.0.1:8080/punchout/confirm', 'POST'))
        self.assertEqual((seen['gets'], seen['posts']), (1, 1))

    def test_it_records_where_a_post_led(self):
        page = _Page()
        seen = browser.watch(page)
        page.fire('response', _Response(START, 'POST', 302, '/?page_id=5'))
        page.fire('response', _Response(START, 'GET', 200))
        page.fire('response', _Response(START, 'POST', 403))
        self.assertEqual(seen['landing'], ['http://127.0.0.1:8080/?page_id=5'])

    def test_a_policy_message_counts_unless_it_is_the_browsers_favicon(self):
        page = _Page()
        seen = browser.watch(page)
        page.fire('console', _Message("Refused to execute inline script because it violates the following Content Security Policy directive: \"script-src 'sha256-x'\""))
        page.fire('console', _Message("Refused to load the image 'http://127.0.0.1:8080/favicon.ico' because it violates the following Content Security Policy directive: \"default-src 'none'\""))
        page.fire('console', _Message('JQMIGRATE: Migrate is installed'))
        self.assertEqual(len(seen['policy']), 1)
        self.assertIn('inline script', seen['policy'][0])


class Arguments(unittest.TestCase):

    def test_the_run_refuses_to_start_without_a_local_chromium(self):
        with tempfile.TemporaryDirectory() as root:
            for chromium in ([], ['--chromium', str(Path(root) / 'missing')]):
                with self.assertRaises(SystemExit) as stop, contextlib.redirect_stderr(io.StringIO()):
                    browser.main(['--wp-path', root, '--wp-cli', str(Path(root) / 'wp-cli.phar')] + chromium)
                self.assertEqual(stop.exception.code, 2)
            self.assertEqual(list(Path(root).iterdir()), [], 'Nothing written before the check')


if __name__ == '__main__':
    unittest.main()
