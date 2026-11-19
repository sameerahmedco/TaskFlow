"""TaskFlow 1.12.0 recovery page with mocked API. No MySQL/SMTP required."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import re, base64
root=Path(__file__).resolve().parent.parent
html='<!doctype html>'+(root/'recover/index.php').read_text().split('<!doctype html>',1)[1]
html=re.sub(r'<\?php.*?\?>','',html,flags=re.S)
html=re.sub(r'<script src="recover.js" defer></script>','',html)
html=re.sub(r'<link rel="stylesheet"[^>]+>','',html)
css=(root/'invite/invite.css').read_text()+'\n'+(root/'recover/recover.css').read_text()
js=(root/'recover/recover.js').read_text()
logo='data:image/png;base64,'+base64.b64encode((root/'assets/img/taskflow-logo-192.png').read_bytes()).decode()

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    request=browser.new_page(viewport={'width':1350,'height':850})
    request.on('pageerror',lambda e:errors.append(str(e)))
    request.set_content(html)
    request.add_style_tag(content=css)
    request.evaluate('(logo)=>{document.querySelector(".brand img").src=logo}',logo)
    request.evaluate('history.replaceState=()=>{}')
    request.evaluate('''() => {
      window.fetch=async(url,opts={}) => {
        const body=JSON.parse(opts.body||'{}');
        window.recoveryCalls=(window.recoveryCalls||[]).concat([body]);
        if(opts.credentials!=='omit') throw Error('Cookies must not accompany public recovery');
        return {ok:true,status:200,json:async()=>({ok:true,message:'If this is an active TaskFlow account and email delivery is configured, a recovery link will arrive shortly.'})};
      };
    }''')
    request.add_script_tag(content=js)
    assert request.locator('#recover-request-form').is_visible()
    request.screenshot(path=str(root/'docs/screenshots/password-recovery.png'),full_page=True)
    request.locator('#recover-email').fill('person@example.org')
    request.locator('#recover-request-btn').click()
    request.wait_for_function("document.querySelector('#recover-request-form').hidden")
    assert 'recovery link' in request.locator('#recover-status').inner_text()
    assert request.evaluate("window.recoveryCalls[0].email==='person@example.org'")
    print('PASS: public recovery request shows non-enumerating confirmation and no cookies')

    token='a'*64
    reset=browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
    reset.on('pageerror',lambda e:errors.append(str(e)))
    reset.set_content(html)
    reset.add_style_tag(content=css)
    reset.evaluate('''() => {
      window.location.hash='#token='+'a'.repeat(64);
      history.replaceState=(...args)=>{window.historyCleared=true;};
      window.fetch=async(url,opts={})=>{
        const body=JSON.parse(opts.body||'{}');
        if(body.token!==('a'.repeat(64))) throw Error('Missing token');
        if(opts.credentials!=='omit') throw Error('Unexpected credentials');
        window.recoveryCalls=(window.recoveryCalls||[]).concat([body]);
        if(body.action==='inspect') return {ok:true,status:200,json:async()=>({valid:true,expires_in_minutes:30})};
        return {ok:true,status:200,json:async()=>({ok:true,message:'Your password was changed. Sign in again.'})};
      };
    }''')
    reset.evaluate('(logo)=>{document.querySelector(".brand img").src=logo}',logo)
    reset.add_script_tag(content=js)
    reset.wait_for_selector('#recover-reset-form:visible')
    assert reset.evaluate('window.historyCleared===true')
    assert reset.evaluate('document.documentElement.scrollWidth <= innerWidth + 2')
    reset.screenshot(path=str(root/'docs/screenshots/password-recovery-mobile.png'),full_page=True)
    reset.locator('#recover-password').fill('A Strong New Example Passphrase 2026')
    reset.locator('#recover-confirm').fill('A Strong New Example Passphrase 2026')
    reset.locator('#recover-reset-btn').click()
    reset.wait_for_function("document.querySelector('#recover-reset-form').hidden")
    assert reset.evaluate('window.recoveryCalls.some(x=>x.action===\'reset\')')
    print('PASS: mobile token reset renders, clears fragment and accepts strong confirmed password')

    expired=browser.new_page(viewport={'width':800,'height':720})
    expired.on('pageerror',lambda e:errors.append(str(e)))
    expired.set_content(html)
    expired.evaluate('''() => {
      window.location.hash='#token='+'b'.repeat(64);
      history.replaceState=()=>{};
      window.fetch=async()=>({ok:false,status:410,json:async()=>({error:'This recovery link is invalid or expired.'})});
    }''')
    expired.add_script_tag(content=js)
    expired.wait_for_function("document.querySelector('#recover-status').textContent.includes('expired')")
    assert expired.locator('#recover-reset-form').is_hidden()
    assert not errors,errors
    print('PASS: expired token remains unusable; no browser JavaScript errors')
    browser.close()
