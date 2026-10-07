"""A small HTTP client for BUFFALO's UAT runner (Phase 9B.5).

It behaves like the browser application: cookies, the XSRF header, Inertia
page requests (JSON page objects with props), redirects, and step-up (a 428
answer is confirmed with the current TOTP code and the request repeated).
Standard library only. Talks to a UAT (or local) installation with a
self-signed certificate, so certificate checks are off: never point it at
anything but your own test environment.
"""

import base64
import hashlib
import hmac
import html
import http.cookiejar
import json
import re
import ssl
import struct
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid


def totp(secret: str, at: float | None = None) -> str:
    key = base64.b32decode(secret + '=' * (-len(secret) % 8))
    counter = int(at if at is not None else time.time()) // 30
    digest = hmac.new(key, struct.pack('>Q', counter), hashlib.sha1).digest()
    offset = digest[-1] & 0x0F
    return f"{(struct.unpack('>I', digest[offset:offset + 4])[0] & 0x7FFFFFFF) % 1000000:06d}"


class Response:
    def __init__(self, status: int, headers, body: bytes, url: str):
        self.status = status
        self.headers = headers
        self.body = body
        self.url = url

    def json(self):
        return json.loads(self.body.decode('utf-8'))

    @property
    def page(self) -> dict:
        """The Inertia page object (component, props, url)."""
        data = self.json()
        if 'component' not in data:
            raise AssertionError(f'Not an Inertia page: {self.url} ({self.status})')
        return data

    @property
    def props(self) -> dict:
        return self.page['props']

    @property
    def errors(self) -> dict:
        return self.page['props'].get('errors') or {}


class Client:
    def __init__(self, base: str, email: str, password: str, secret: str):
        self.base = base.rstrip('/')
        self.email, self.password, self.secret = email, password, secret
        self.jar = http.cookiejar.CookieJar()
        context = ssl.create_default_context()
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPSHandler(context=context),
            urllib.request.HTTPCookieProcessor(self.jar),
        )
        self.version = None
        self.step_ups = 0
        self.step_up_at = 0.0

    # -- low level -------------------------------------------------------
    def _xsrf(self) -> str:
        for cookie in self.jar:
            if cookie.name == 'XSRF-TOKEN':
                return urllib.parse.unquote(cookie.value)
        return ''

    def request(self, method: str, path: str, data=None, *, inertia=True, json_api=False, files=None, step_up=True) -> Response:
        url = path if path.startswith('http') else self.base + path
        headers = {'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': self._xsrf()}
        body = None
        if inertia:
            headers.update({'X-Inertia': 'true', 'Accept': 'text/html, application/xhtml+xml'})
            if self.version is not None:
                headers['X-Inertia-Version'] = self.version
        if json_api:
            headers['Accept'] = 'application/json'
            headers.pop('X-Inertia', None)
        if files:
            boundary = uuid.uuid4().hex
            parts = []
            for name, value in self._flatten(data or {}):
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
            for name, (filename, content, mime) in files.items():
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + content + b'\r\n')
            parts.append(f'--{boundary}--\r\n'.encode())
            body = b''.join(parts)
            headers['Content-Type'] = f'multipart/form-data; boundary={boundary}'
        elif data is not None:
            body = json.dumps(data).encode()
            headers['Content-Type'] = 'application/json'

        req = urllib.request.Request(url, data=body, headers=headers, method=method)
        try:
            with self.opener.open(req, timeout=120) as res:
                response = Response(res.status, res.headers, res.read(), res.url)
        except urllib.error.HTTPError as e:
            response = Response(e.code, e.headers, e.read(), url)

        # urllib follows redirects for GET and POST only; Inertia answers a
        # PUT or DELETE with a 302/303 to the next page: follow it as a GET.
        if response.status in (301, 302, 303) and response.headers.get('Location') and method in ('PUT', 'PATCH', 'DELETE'):
            return self.request('GET', response.headers['Location'], inertia=inertia, json_api=json_api, step_up=step_up)

        if response.status == 409 and response.headers.get('X-Inertia-Location'):
            self.version = None
            self.refresh_version()
            return self.request(method, path, data, inertia=inertia, json_api=json_api, files=files, step_up=step_up)

        if response.status == 428 and step_up:
            self.confirm_identity()
            return self.request(method, path, data, inertia=inertia, json_api=json_api, files=files, step_up=False)

        return response

    @staticmethod
    def _flatten(data, prefix=''):
        items = data.items() if isinstance(data, dict) else enumerate(data)
        for key, value in items:
            name = f'{prefix}[{key}]' if prefix else str(key)
            if isinstance(value, (dict, list)):
                yield from Client._flatten(value, name)
            elif value is None:
                continue
            elif isinstance(value, bool):
                yield name, '1' if value else '0'
            else:
                yield name, str(value)

    def refresh_version(self):
        res = self.request('GET', '/login' if self.version is None else '/dashboard', inertia=False)
        text = res.body.decode('utf-8', 'replace')
        # Inertia 3: <script data-page="app" type="application/json">{...}</script>
        match = re.search(r'<script data-page="app" type="application/json">(.*?)</script>', text, re.S)
        if match:
            self.version = json.loads(match.group(1)).get('version')
        else:
            match = re.search(r'data-page="([^"]+)"', text)
            if match:
                self.version = json.loads(html.unescape(match.group(1))).get('version')

    # Last TOTP window used per secret, across clients: BUFFALO refuses a
    # code that was already used (replay protection).
    _used_windows: dict = {}

    def fresh_code(self) -> str:
        """A TOTP code from a window not used before for this account."""
        while True:
            window = int(time.time()) // 30
            if window != Client._used_windows.get(self.secret):
                Client._used_windows[self.secret] = window
                return totp(self.secret)
            time.sleep(1)

    # -- high level --------------------------------------------------------
    def get(self, path: str) -> Response:
        return self.request('GET', path)

    def post(self, path: str, data=None, **kw) -> Response:
        return self.request('POST', path, data if data is not None else {}, **kw)

    def put(self, path: str, data=None, **kw) -> Response:
        return self.request('PUT', path, data if data is not None else {}, **kw)

    def stepped_up_since(self, before: int, minutes: int = 15) -> bool:
        """Asked for a code now, or confirmed within the step-up window."""
        return self.step_ups > before or time.time() - self.step_up_at < minutes * 60

    def login(self) -> Response:
        self.refresh_version()
        self.request('POST', '/login', {'email': self.email, 'password': self.password})
        return self.request('POST', '/two-factor-challenge', {'code': self.fresh_code()})

    def confirm_identity(self):
        self.step_ups += 1
        self.step_up_at = time.time()
        res = self.request('POST', '/step-up', {'code': self.fresh_code()}, inertia=False, json_api=True, step_up=False)
        if res.status >= 400:
            raise AssertionError(f'Step-up failed: {res.status} {res.body[:200]!r}')
