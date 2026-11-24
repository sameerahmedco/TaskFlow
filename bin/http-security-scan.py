#!/usr/bin/env python3
"""Read-only TaskFlow HTTP exposure audit. Python stdlib only.

Production: python3 bin/http-security-scan.py --url https://tasks.example.org --json
CI image:   python3 bin/http-security-scan.py --url http://127.0.0.1:18080 \\
                --allow-local-http --ci-uninstalled

No login credentials, private data, writes, or destructive probes. Refuses redirects
and unsafe/remote HTTP targets. Source of truth is the real deployed web server.
"""
from __future__ import annotations
import argparse
import json
import re
import sys
from dataclasses import dataclass
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


@dataclass(frozen=True)
class Response:
    code: int
    headers: dict[str, str]


def inspect(url: str, timeout: float = 6.0) -> Response:
    request = Request(url, headers={"User-Agent": "TaskFlow-Release-Readiness/3", "Accept": "*/*"})
    opener = build_opener(NoRedirect())
    try:
        response = opener.open(request, timeout=timeout)
    except HTTPError as error:
        response = error
    with response:
        return Response(response.status, {k.lower(): v for k, v in response.headers.items()})


def validate_origin(url: str, allow_local_http: bool) -> tuple[str, bool]:
    if not isinstance(url, str) or len(url) > 2048 or url.strip() != url:
        raise ValueError('Provide a clean, absolute HTTPS application URL.')
    parts = urlsplit(url)
    if not parts.hostname or parts.username or parts.password or parts.query or parts.fragment:
        raise ValueError('URL must be an absolute origin or subpath without credentials/query/fragment.')
    if parts.path and ('..' in parts.path.split('/') or '%2f' in parts.path.lower()):
        raise ValueError('Untrusted path segments are not allowed.')
    if parts.scheme == 'https':
        if parts.hostname.lower() in {'localhost', '127.0.0.1', '::1'}:
            raise ValueError('Use --allow-local-http for an explicit local test, not HTTPS loopback.')
    elif parts.scheme == 'http':
        if not allow_local_http or parts.hostname.lower() not in {'localhost', '127.0.0.1', '::1'}:
            raise ValueError('Plain HTTP is allowed only for explicit localhost CI checks.')
    else:
        raise ValueError('HTTPS is required for production.')
    # Preserve a configured subdirectory prefix (e.g. /taskflow/).
    prefix = url.rstrip('/') + '/'
    return prefix, parts.scheme == 'https'


def long_hsts(value: str) -> bool:
    match = re.search(r'(?:^|;)\s*max-age\s*=\s*([0-9]+)(?:\s*;|\s*$)', value, re.I)
    return bool(match and int(match.group(1)) >= 31536000)


def scan(origin: str, tls: bool, ci_uninstalled: bool, fetch=inspect, *, static_only: bool=False) -> dict:
    checks: list[dict] = []

    def record(name: str, ok: bool, reason: str) -> None:
        checks.append({'name': name, 'status': 'pass' if ok else 'fail', 'detail': reason})

    if not tls and not ci_uninstalled and not static_only:
        record('https_required', False, 'Production readiness can be evaluated only over verified HTTPS.')

    def fetch_path(path: str) -> Response | None:
        try:
            return fetch(urljoin(origin, path))
        except (URLError, TimeoutError, OSError, ValueError) as error:
            record('request_' + path.replace('/', '_'), False, 'Connection or TLS verification failed: ' + type(error).__name__)
            return None

    # Check real paths which exist in the source, not only absent secret filenames.
    for path in ('config/config.php', 'includes/functions.php', 'bin/release-preflight.php',
                 'docs/UPGRADE.md', 'docs/migrations/v3-task-reviews.sql',
                 '.htaccess'):
        response = fetch_path(path)
        if response:
            record('deny_' + path, response.code in (403, 404),
                   f'{path}: HTTP {response.code}; must be denied with 403 or 404')
    for path in (() if static_only else ('api/me.php', 'api/task_reviews.php', 'api/attachments.php')):
        response = fetch_path(path)
        if response:
            record('anonymous_' + path, response.code == 401,
                   f'{path}: HTTP {response.code}; anonymous access must be 401')
    for path in ('assets/js/app.js', 'assets/css/v3-task-details.css'):
        response = fetch_path(path)
        if response:
            record('asset_' + path, response.code == 200,
                   f'{path}: HTTP {response.code}; should be available')
            # Static assets should never bypass security headers at the edge.
            record('static_nosniff_' + path, response.headers.get('x-content-type-options', '').lower() == 'nosniff',
                   'Static asset sends X-Content-Type-Options: nosniff')
            record('static_frame_' + path, response.headers.get('x-frame-options', '').upper() == 'DENY',
                   'Static asset sends frame protection')
            record('static_robots_' + path, 'noindex' in response.headers.get('x-robots-tag', '').lower(),
                   'Static asset sends noindex guidance')
            if tls:
                record('static_hsts_' + path, long_hsts(response.headers.get('strict-transport-security', '')),
                    'Static asset sends long-lived HSTS via the public TLS edge')
    token = None if static_only else fetch_path('api/csrf.php')
    if token:
        record('csrf_token_endpoint', token.code == 200, f'CSRF endpoint HTTP {token.code}')
        headers = token.headers
        record('dynamic_nosniff', headers.get('x-content-type-options', '').lower() == 'nosniff',
               'Dynamic responses set X-Content-Type-Options: nosniff')
        record('dynamic_frame', headers.get('x-frame-options', '').upper() == 'DENY',
               'Dynamic responses disable framing')
        record('dynamic_robots', 'noindex' in headers.get('x-robots-tag', '').lower(),
               'Dynamic responses have X-Robots-Tag: noindex')
        record('dynamic_referrer', headers.get('referrer-policy', '').lower() == 'no-referrer',
               'Dynamic responses do not disclose navigation referrers')
        csp = headers.get('content-security-policy', '').lower()
        record('csp', 'frame-ancestors' in csp and "'none'" in csp and 'object-src' in csp,
               'Dynamic CSP blocks object embedding and frame ancestors')
        cookie = headers.get('set-cookie', '')
        record('session_cookie', 'taskflow_sid=' in cookie.lower(), 'Anonymous CSRF initializes the session cookie')
        record('cookie_httponly', 'httponly' in cookie.lower(), 'Session cookie is HttpOnly')
        record('cookie_samesite', 'samesite=strict' in cookie.lower(), 'Session cookie is SameSite=Strict')
        if tls:
            record('cookie_secure', re.search(r'(?:^|;)\s*secure(?:;|$)', cookie, re.I) is not None,
                   'Session cookie is Secure over HTTPS')
            record('hsts', long_hsts(headers.get('strict-transport-security', '')),
                'Dynamic responses send long-lived HSTS')
    main = None if static_only else fetch_path('')
    if main:
        if ci_uninstalled:
            # Deliberately not a passing production test. The CI image is not configured.
            record('ci_install_redirect', main.code in (302, 303),
                   f'Uninstalled CI image: expected redirect to installer, got HTTP {main.code}')
            location = main.headers.get('location', '')
            safe_location = bool(location) and urlsplit(urljoin(origin, location)).netloc == urlsplit(origin).netloc
            record('same_origin_redirect', safe_location,
                   'The local installer redirect stays on the same origin')
        else:
            record('installed_entrypoint', main.code == 200,
                   f'Installed application root should be HTTP 200, got {main.code}')
    failures = [item for item in checks if item['status'] == 'fail']
    return {
        'mode': 'ci-static-only' if static_only else ('ci-uninstalled' if ci_uninstalled else 'production'),
        'production_ready': tls and not static_only and not ci_uninstalled and not failures,
        'checks': checks, 'passed': len(checks) - len(failures), 'failed': len(failures),
        'note': 'Read-only header and access checks; not authentication, pen-test, or MySQL certification.'
    }


def main() -> int:
    parser = argparse.ArgumentParser(description='Audit a deployed TaskFlow host for basic exposure and header failures')
    parser.add_argument('--url', required=True, help='Public HTTPS app URL or localhost in CI mode')
    parser.add_argument('--json', action='store_true')
    parser.add_argument('--allow-local-http', action='store_true', help='Only for an explicit localhost CI smoke')
    parser.add_argument('--ci-uninstalled', action='store_true', help='Accept local installer redirect; not production approval')
    parser.add_argument('--ci-static-only', action='store_true', help='Check only static file exposure (for Docker behind an HTTP bridge)')
    parser.add_argument('--timeout', type=float, default=6.0)
    args = parser.parse_args()
    try:
        origin, tls = validate_origin(args.url, args.allow_local_http)
        if (args.ci_uninstalled or args.ci_static_only) and tls:
            raise ValueError('CI modes are only supported on local HTTP test servers.')
        if args.ci_uninstalled and args.ci_static_only:
            raise ValueError('Choose only one CI mode.')
        if args.timeout <= 0 or args.timeout > 30:
            raise ValueError('Timeout must be greater than zero and at most 30 seconds.')
    except ValueError as error:
        parser.error(str(error))
    report = scan(origin, tls, args.ci_uninstalled, lambda url: inspect(url,args.timeout),
                  static_only=args.ci_static_only)
    if args.json:
        print(json.dumps(report, indent=2))
    else:
        for item in report['checks']:
            print(f"{item['status'].upper()} {item['name']}: {item['detail']}")
        print(f"Checks passed: {report['passed']}; failed: {report['failed']}")
        if args.ci_uninstalled or args.ci_static_only:
            print('CI image checks only. NOT a production release approval.')
    return 1 if report['failed'] else 0


if __name__ == '__main__':
    sys.exit(main())
