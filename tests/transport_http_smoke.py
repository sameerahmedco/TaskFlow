"""Actual PHP-server transport checks without MySQL or an installed customer app."""
from __future__ import annotations

import contextlib
import http.client
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

SOURCE = Path(__file__).resolve().parents[1]
CHECKS = 0


def check(condition: bool, label: str) -> None:
    global CHECKS
    CHECKS += 1
    assert condition, label


def get_free_port() -> int:
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


@contextlib.contextmanager
def server(installed: bool, environment: str, app_url: str, trusted: str = ''):
    with tempfile.TemporaryDirectory(prefix='taskflow-transport-') as tmp:
        root = Path(tmp)
        for name in ['config/config.php', 'includes/db.php', 'includes/functions.php',
                     'includes/transport_policy.php', 'includes/remember_cookie.php', 'api/csrf.php']:
            dest = root / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(SOURCE / name, dest)
        if installed:
            (root/'config/.installed').touch()
        port = get_free_port()
        env = os.environ.copy()
        env.update({'TASKFLOW_APP_ENV': environment, 'TASKFLOW_APP_URL': app_url.replace('{port}', str(port))})
        env.pop('TASKFLOW_TRUSTED_PROXY_IP', None)
        if trusted:
            env['TASKFLOW_TRUSTED_PROXY_IP'] = trusted
        with subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(root)],
                              stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, env=env) as child:
            try:
                for _ in range(50):
                    if child.poll() is not None:
                        raise RuntimeError('Test PHP server failed to start')
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=.1):
                            break
                    except OSError:
                        time.sleep(.04)
                else:
                    raise RuntimeError('Timed out starting PHP for transport test')
                yield port
            finally:
                child.terminate()
                try:
                    child.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    child.kill()
                    child.wait()


def request(port: int, *, forwarded: str | None = None, host: str | None = None) -> tuple[int, dict, bytes]:
    connection = http.client.HTTPConnection('127.0.0.1', port, timeout=3)
    headers = {'Host': host or f'localhost:{port}'}
    if forwarded is not None:
        headers['X-Forwarded-Proto'] = forwarded
    try:
        connection.request('GET', '/api/csrf.php', headers=headers)
        response = connection.getresponse()
        return response.status, dict((k.lower(), v) for k, v in response.getheaders()), response.read()
    finally:
        connection.close()


with server(True, 'production', 'https://tasks.example.org') as port:
    code, headers, body = request(port)
    check(code == 403, 'production refuses spoofed localhost Host and loopback proxy source')
    check(b'requires HTTPS' in body, 'production transport rejection is explicit')
    code, headers, _ = request(port, forwarded='https')
    check(code == 403, 'untrusted X-Forwarded-Proto ignored')
    code, _, _ = request(port, host='tasks.example.org')
    check(code == 403, 'public HTTP origin blocked')

with server(True, 'development', 'http://127.0.0.1:{port}') as port:
    code, headers, body = request(port)
    check(code == 200 and b'csrf_token' in body, 'explicit development on local APP_URL works')
    cookie = headers.get('set-cookie', '').lower()
    check('httponly' in cookie and 'samesite=strict' in cookie, 'local dev session cookie protected')
    check('secure' not in cookie, 'only explicit local development allows insecure cookie')
    code, _, _ = request(port, host='example.org')
    check(code == 403, 'development still checks request host')

with server(True, 'production', 'https://tasks.example.org', trusted='127.0.0.1') as port:
    code, headers, body = request(port, forwarded='https')
    check(code == 200 and b'csrf_token' in body, 'explicitly trusted TLS proxy accepted')
    check('secure' in headers.get('set-cookie', '').lower(), 'trusted TLS proxy yields Secure cookie')
    check('max-age=31536000' in headers.get('strict-transport-security', ''), 'trusted TLS proxy sends HSTS')
    code, _, _ = request(port, forwarded='http')
    check(code == 403, 'trusted proxy must assert HTTPS, not HTTP')

with server(False, 'production', 'https://tasks.example.org') as port:
    code, _, _ = request(port)
    check(code == 403, 'uninstalled public APP_URL cannot bypass HTTPS via localhost Host')

with server(False, 'production', '') as port:
    code, headers, body = request(port)
    check(code == 200 and b'csrf_token' in body, 'uninstalled localhost setup retains HTTP bootstrap')
    check('samesite=strict' in headers.get('set-cookie', '').lower(), 'setup CSRF session SameSite Strict')
    code, _, _ = request(port, host='public.example.org')
    check(code == 403, 'public Host cannot use local setup exception')

print(f'All {CHECKS} real PHP HTTP transport checks passed (isolated temporary runtime).')
