"""HTML forms read and submitted the way a browser does, with the standard library only.

The end-to-end driver never invents a request body: it reads the form the page
drew and sends that form's successful controls (the HTML "constructing the
entry list" rules, reduced to what PunchOut and WooCommerce pages use), plus the
one button that was clicked. A buyer's choice is applied with `choose`, which
refuses a value the page does not offer.
"""
from html.parser import HTMLParser
import secrets
from urllib.parse import urlencode, urljoin

URLENCODED = 'application/x-www-form-urlencoded'
MULTIPART = 'multipart/form-data'


class Control:
    def __init__(self, tag, attrs):
        self.tag = tag
        self.type = (attrs.get('type') or ('submit' if tag == 'button' else 'text')).lower() if tag in ('input', 'button') else tag
        self.name = attrs.get('name')
        self.value = attrs.get('value')
        self.checked = 'checked' in attrs
        self.disabled = 'disabled' in attrs
        self.multiple = 'multiple' in attrs
        self.options = []  # [value, text, selected, disabled] for a select
        self.text = ''     # a textarea's content


class Form:
    def __init__(self, attrs, base_url):
        self.attrs = attrs
        self.action = urljoin(base_url, attrs.get('action') or base_url)
        self.method = (attrs.get('method') or 'get').upper()
        enctype = (attrs.get('enctype') or URLENCODED).lower()
        self.enctype = enctype if enctype in (URLENCODED, MULTIPART) else URLENCODED
        self.controls = []

    def _named(self, name):
        return [c for c in self.controls if c.name == name and not c.disabled]

    def options(self, name):
        """The values a select of that name offers, in page order."""
        for control in self._named(name):
            if control.tag == 'select':
                return [o[0] for o in control.options]
        raise KeyError(name)

    def value(self, name):
        """What the form would send for that name today (first entry), or None."""
        for key, value in self.submission():
            if key == name:
                return value
        return None

    def buttons(self):
        return [(c.name, c.value or '') for c in self.controls if c.type in ('submit', 'image') and c.name and not c.disabled]

    def submission(self, submitter=None, choose=None):
        """The entry list a browser builds when `submitter` (name, value) is clicked, after `choose` is applied."""
        choose = dict(choose or {})
        if submitter is not None and submitter not in self.buttons():
            raise KeyError('The form has no button ' + repr(submitter))
        entries = []
        used = set()
        for c in self.controls:
            if c.disabled or not c.name:
                continue
            if c.type in ('submit', 'image', 'reset', 'button'):
                if c.type in ('submit', 'image') and submitter == (c.name, c.value or ''):
                    entries.append((c.name, c.value or ''))
                continue
            if c.type in ('checkbox', 'radio'):
                own = c.value if c.value is not None else 'on'
                if c.name in choose:
                    wanted = choose[c.name]
                    used.add(c.name)
                    if wanted is not None and str(wanted) == own:
                        entries.append((c.name, own))
                elif c.checked:
                    entries.append((c.name, own))
                continue
            if c.tag == 'select':
                offered = [o for o in c.options if not o[3]]
                if c.name in choose:
                    wanted = str(choose[c.name])
                    if wanted not in [o[0] for o in offered]:
                        raise ValueError('The select ' + c.name + ' does not offer ' + repr(wanted))
                    used.add(c.name)
                    entries.append((c.name, wanted))
                    continue
                selected = [o for o in offered if o[2]]
                if not selected and not c.multiple and offered:
                    selected = offered[:1]
                entries.extend((c.name, o[0]) for o in (selected if c.multiple else selected[-1:]))
                continue
            if c.type == 'file':
                entries.append((c.name, None))
                continue
            current = c.text if c.tag == 'textarea' else (c.value or '')
            if c.name in choose:
                used.add(c.name)
                current = str(choose[c.name])
            entries.append((c.name, current))
        missing = [name for name in choose if name not in used]
        for name in missing:
            if not any(c.name == name for c in self.controls):
                raise KeyError('The form has no control ' + repr(name))
        return entries


class _Parser(HTMLParser):
    def __init__(self, base_url):
        super().__init__(convert_charrefs=True)
        self.base_url = base_url
        self.forms = []
        self.form = None
        self.select = None
        self.option = None
        self.textarea = None

    def handle_starttag(self, tag, attrs):
        attrs = {k: (v if v is not None else '') for k, v in attrs}
        if tag == 'form':
            self.form = Form(attrs, self.base_url)
            self.forms.append(self.form)
            return
        if self.form is None:
            return
        if tag in ('input', 'button', 'textarea', 'select'):
            control = Control(tag, attrs)
            self.form.controls.append(control)
            if tag == 'select':
                self.select = control
            elif tag == 'textarea':
                self.textarea = control
        elif tag == 'option' and self.select is not None:
            self.option = [attrs.get('value'), '', 'selected' in attrs, 'disabled' in attrs]
            self.select.options.append(self.option)

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)

    def handle_endtag(self, tag):
        if tag == 'form':
            self.form = None
        elif tag == 'select':
            self._close_option()
            self.select = None
        elif tag == 'option':
            self._close_option()
        elif tag == 'textarea' and self.textarea is not None:
            # A newline straight after <textarea> is not part of its value.
            if self.textarea.text.startswith('\r\n'):
                self.textarea.text = self.textarea.text[2:]
            elif self.textarea.text.startswith('\n'):
                self.textarea.text = self.textarea.text[1:]
            self.textarea = None

    def _close_option(self):
        if self.option is not None and self.option[0] is None:
            self.option[0] = ' '.join(self.option[1].split())
        self.option = None

    def handle_data(self, data):
        if self.textarea is not None:
            self.textarea.text += data
        elif self.option is not None:
            self.option[1] += data


def parse_forms(html, base_url):
    """Every form on the page, with actions made absolute against `base_url`."""
    parser = _Parser(base_url)
    parser.feed(html)
    parser.close()
    return parser.forms


def encode(entries, enctype):
    """(body bytes, Content-Type) for an entry list, as a browser encodes it."""
    if enctype == MULTIPART:
        boundary = '----powE2E' + secrets.token_hex(12)
        chunks = []
        for name, value in entries:
            if value is None:
                chunks.append('--%s\r\nContent-Disposition: form-data; name="%s"; filename=""\r\nContent-Type: application/octet-stream\r\n\r\n\r\n' % (boundary, name))
            else:
                chunks.append('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, name, value))
        chunks.append('--%s--\r\n' % boundary)
        return ''.join(chunks).encode('utf-8'), MULTIPART + '; boundary=' + boundary
    return urlencode([(name, '' if value is None else value) for name, value in entries]).encode('ascii'), URLENCODED
