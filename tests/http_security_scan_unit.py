"""Offline unit tests for TaskFlow v3 public HTTPS exposure scan; no network used."""
from __future__ import annotations
import importlib.util
import sys
from pathlib import Path

ROOT=Path(__file__).resolve().parent.parent
path=ROOT/'bin/http-security-scan.py'
spec=importlib.util.spec_from_file_location('taskflow_http_security',path)
module=importlib.util.module_from_spec(spec)
sys.modules[spec.name]=module
spec.loader.exec_module(module)
checks=0

def verify(ok,description):
    global checks
    checks+=1
    if not ok:raise AssertionError(description)

def safe_server(url: str):
    path=url.split('://',1)[-1].partition('/')[2]
    dynamic={'x-content-type-options':'nosniff','x-frame-options':'DENY','x-robots-tag':'noindex, nofollow',
      'referrer-policy':'no-referrer','content-security-policy':"object-src 'none'; frame-ancestors 'none'",
      'set-cookie':'taskflow_sid=test-sid; Path=/; HttpOnly; SameSite=Strict; Secure',
      'strict-transport-security':'max-age=31536000'}
    static={key:dynamic[key] for key in ('x-content-type-options','x-frame-options','x-robots-tag','strict-transport-security')}
    if not path:
        return module.Response(200, dynamic)
    if path=='api/csrf.php':return module.Response(200,dynamic)
    if path in ('api/me.php','api/task_reviews.php','api/attachments.php'):
        return module.Response(401,dynamic)
    if path.startswith('assets/'):
        return module.Response(200,static)
    return module.Response(404,dynamic)

for url,allow in [
    ('https://tasks.example.com',False),('https://tasks.example.com/app',False),
    ('http://127.0.0.1:18080',True),('http://localhost:18080',True)]:
    parsed,tls=module.validate_origin(url,allow)
    verify(bool(parsed) and tls==url.startswith('https'),'accepted safe URL')
for url,allow in [
    ('http://example.com',True),('http://example.com',False),
    ('https://user:pass@example.com',False),('https://example.com/?token=secret',False),
    ('https://example.com#fragment',False),('file:///etc/passwd',False),
    ('http://127.0.0.1:18080',False),('https://tasks.example.com/../private',False)]:
    try:module.validate_origin(url,allow)
    except ValueError:pass
    else:raise AssertionError('Unsafe URL allowed: '+url)
    checks+=1

report=module.scan('https://tasks.example.com/',True,False,safe_server)
verify(report['failed']==0 and report['production_ready'],'hardened HTTPS server passes')
verify(report['passed']>=25,'scanner probes private files, anonymous APIs, assets, cookie headers')

def no_static_robots(url):
    resp=safe_server(url)
    if '/assets/' in url:
        return module.Response(resp.code,{k:v for k,v in resp.headers.items() if k!='x-robots-tag'})
    return resp
report=module.scan('https://tasks.example.com/',True,False,no_static_robots)
verify(report['failed']==2 and not report['production_ready'],'missing static noindex caught')

def exposed_file(url):
    if 'docs/UPGRADE.md' in url:return module.Response(200,{})
    return safe_server(url)
report=module.scan('https://tasks.example.com/',True,False,exposed_file)
verify(report['failed']==1 and not report['production_ready'],'publicly exposed documentation detected')

def insecure_session(url):
    resp=safe_server(url)
    if 'api/csrf.php' in url:
        return module.Response(resp.code,{**resp.headers,'set-cookie':'taskflow_sid=test; Path=/; HttpOnly; SameSite=Lax'})
    return resp
report=module.scan('https://tasks.example.com/',True,False,insecure_session)
verify(report['failed']==2,'missing Secure and Strict cookie attributes detected')

def changed_hsts(url):
    resp=safe_server(url)
    return module.Response(resp.code,{**resp.headers,'strict-transport-security':'max-age=86400'})
report=module.scan('https://tasks.example.com/',True,False,changed_hsts)
verify(report['failed']==3,'short HSTS on dynamic and static responses detected')
verify(not module.long_hsts('max-age=30000000'), 'HSTS shorter than one year is rejected')
verify(module.long_hsts('max-age=31536000; includeSubDomains'), 'one-year HSTS accepted')

def redirected_file(url):
    if 'docs/UPGRADE.md' in url:return module.Response(302,{'location':'https://attacker.example/'})
    return safe_server(url)
report=module.scan('https://tasks.example.com/',True,False,redirected_file)
verify(report['failed']>=1,'private file redirects do not count as access denied')

def ci_server(url):
    resp=safe_server(url)
    if url.rstrip('/')=='http://127.0.0.1:18080':
        return module.Response(302,{'location':'/install/'})
    return module.Response(resp.code,{k:v for k,v in resp.headers.items() if k!='strict-transport-security'})
report=module.scan('http://127.0.0.1:18080/',False,True,ci_server)
verify(report['failed']==0 and not report['production_ready'],'uninstalled local CI checks pass but never certify production')

def external_install_redirect(url):
    resp=ci_server(url)
    if url.rstrip('/')=='http://127.0.0.1:18080':
        return module.Response(302,{'location':'https://evil.example/install/'})
    return resp
report=module.scan('http://127.0.0.1:18080/',False,True,external_install_redirect)
verify(report['failed']==1,'cross-origin installer redirect detected')
report=module.scan('http://127.0.0.1:18080/',False,False,ci_server,static_only=True)
verify(report['failed']==0 and not report['production_ready'] and report['mode']=='ci-static-only',
       'Docker local static-only scan never certifies production')
report=module.scan('http://127.0.0.1:18080/',False,False,ci_server)
verify(report['failed']>=1 and not report['production_ready'],
       'a plain HTTP scan cannot certify public production')
print(f'All {checks} TaskFlow public HTTPS exposure-scan unit checks passed.')
