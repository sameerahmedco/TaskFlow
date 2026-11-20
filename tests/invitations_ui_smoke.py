"""TaskFlow 1.11.0 invitation acceptance/UI with mocked APIs only. No live DB."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import re, base64, json
root=Path(__file__).resolve().parent.parent
html='<!DOCTYPE html>' + (root/'index.php').read_text().split('<!DOCTYPE html>',1)[1]
html=re.sub(r'<\?php.*?\?>','',html,flags=re.S)
html=re.sub(r'<link rel="stylesheet"[^>]*>','',html)
html=re.sub(r'<script src="[^"]*">\s*</script>','',html)
css=(root/'assets/css/style.css').read_text()+'\n'+(root/'assets/css/design-system.css').read_text()+'\n'+(root/'assets/css/ui-overhaul.css').read_text()+'\n'+(root/'assets/css/theme-accessibility.css').read_text()+'\n'+(root/'assets/css/theme-palettes.css').read_text()+'\n'+(root/'assets/css/v2-workspace.css').read_text()
app=(root/'assets/js/app.js').read_text()
logo='data:image/png;base64,'+base64.b64encode((root/'assets/img/taskflow-logo-192.png').read_bytes()).decode()
icons=(root/'assets/img/icons.svg').read_text()
source=(root/'tests/release_110_ui_smoke.py').read_text()
jsmock=source.split("jsmock=r'''",1)[1].split("'''",1)[0]

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    page=browser.new_page(viewport={'width':1400,'height':920})
    page.set_default_timeout(15000)
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.on('dialog',lambda d:d.accept())
    page.set_content(html.replace('id="v2-tools-drawer" hidden','id="v2-tools-drawer"'))
    page.add_style_tag(content=css)
    page.evaluate('(a)=>{document.body.insertAdjacentHTML("afterbegin",a.icons);document.querySelectorAll("use[href^=\'assets/img/icons.svg#\']").forEach(u=>u.setAttribute("href","#"+u.getAttribute("href").split("#").pop()));document.querySelectorAll("img[src^=\'assets/img/\']").forEach(i=>i.src=a.logo);}',{'icons':icons,'logo':logo})
    page.evaluate('window.testRole="administrator"')
    page.evaluate(jsmock)
    # Extend the existing source fixture with invitation records and simple mutation handling.
    page.evaluate('''() => {
       const original=window.fetch;
       window.inviteState=[{id:33,name:'Jordan Employee',email:'jordan@example.test',roles:['member'],team_name:'Studio',team_id:7,expires_at:'2026-11-19 12:00:00',status:'pending'}];
       window.fetch=async(url,opts={}) => {
         const name=String(url).split('?')[0].split('/').pop();
         const method=opts.method || 'GET';
         if(name==='users.php')return {ok:true,status:200,json:async()=>({users:[{id:1,name:'Alex Admin',email:'admin@example.test',team_id:7,team_name:'Studio',is_active:1,avatar_color:'#158fbd',roles:['administrator']}]})};
         if(name==='teams.php')return {ok:true,status:200,json:async()=>({teams:[{id:7,name:'Studio'}]})};
         if(name==='invitations.php'){
           const body=opts.body?JSON.parse(opts.body):{};
           window.inviteCalls=(window.inviteCalls||[]).concat([{method,body}]);
           if(method==='GET')return {ok:true,status:200,json:async()=>({invitations:window.inviteState})};
           if(body.action==='create')return {ok:true,status:201,json:async()=>({id:34,invite_url:'https://tasks.example.org/invite/#token='+('a'.repeat(64)),delivery:body.delivery==='email'?'email_queued':'copy',expires_in_hours:72})};
           if(body.action==='revoke')window.inviteState=window.inviteState.map(x=>x.id===33?{...x,status:'revoked'}:x);
           return {ok:true,status:200,json:async()=>({ok:true})};
         }
         return original(url,opts);
       };
    }''')
    page.add_script_tag(content=app)
    page.wait_for_selector('#app:not(.hidden)')
    page.locator('[data-view="users"]').click()
    page.wait_for_selector('.invite-row')
    assert page.locator('#invite-user-btn').is_visible()
    assert page.locator('.invite-row .invite-revoke').count()==1
    page.locator('#invite-user-btn').click()
    page.wait_for_selector('#invite-name')
    page.locator('#invite-name').fill('Taylor New')
    page.locator('#invite-email').fill('taylor@example.test')
    page.locator('#invite-team').select_option('7')
    page.locator('#prompt-confirm').click()
    page.wait_for_selector('#invite-link-value')
    assert page.locator('#invite-link-value').input_value().endswith('#token='+'a'*64)
    payload=page.evaluate("window.inviteCalls.find(c=>c.body?.action==='create').body")
    assert payload['email']=='taylor@example.test' and payload['roles']==['member'] and payload['team_id']=='7'
    # screenshot no invite URL in view: close copy-link modal before screenshot.
    page.locator('#prompt-cancel').click()
    page.locator('.invite-revoke').first.click()
    page.wait_for_function("window.inviteCalls.some(c=>c.body?.action==='revoke'&&c.body.id===33)")
    assert page.locator('.invite-revoke').count()==0
    # Lost/expired invitations can be reissued using their saved identity.
    page.locator('.invite-reissue').first.click()
    page.wait_for_selector('#invite-name')
    assert page.locator('#invite-name').input_value()=='Jordan Employee'
    assert page.locator('#invite-email').input_value()=='jordan@example.test'
    page.locator('#invite-send-email').check()
    page.screenshot(path=str(root/'docs/screenshots/invitation-delivery.png'),full_page=True)
    page.locator('#prompt-confirm').click()
    page.wait_for_selector('#invite-link-value')
    assert page.locator('#prompt-body').inner_text().find('queued for delivery')>=0
    assert page.evaluate("window.inviteCalls.some(c=>c.body?.action==='create'&&c.body.delivery==='email')")
    page.locator('#prompt-cancel').click()
    page.screenshot(path=str(root/'docs/screenshots/invitations.png'),full_page=True)
    assert not errors, errors
    print('PASS: admin can invite, see one-time URL, and revoke a pending invitation')
    # Test employee-facing password setup, with the secret in URL fragment only.
    token='f'*64
    accept_html='<!doctype html>'+(root/'invite/index.php').read_text().split('<!doctype html>',1)[1]
    accept_html=re.sub(r'<\?php.*?\?>','',accept_html,flags=re.S)
    invite_js=(root/'invite/invite.js').read_text()
    invite_css=(root/'invite/invite.css').read_text()
    invite=browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
    invite.set_default_timeout(10000)
    invite.on('pageerror',lambda e:errors.append(str(e)))
    # This runtime prevents browser network navigation, so exercise the real
    # recipient UI with a mocked API and stubbed history cleanup.
    accept_html=re.sub(r'<script src="invite.js" defer></script>','',accept_html)
    accept_html=re.sub(r'<link rel="stylesheet" href="invite.css">','',accept_html)
    invite.set_content(accept_html)
    invite.add_style_tag(content=invite_css)
    invite.evaluate("""() => {
      window.location.hash='#token='+'f'.repeat(64);
      const orig = history.replaceState;
      history.replaceState=(...args)=>{window.invitationHistoryCleared=true;};
      window.fetch=async(url,opts={})=>{
        const body=JSON.parse(opts.body||'{}');
        if(body.token!==('f'.repeat(64))) throw new Error('token missing');
        window.inviteRequests=(window.inviteRequests||[]).concat([body]);
        const data=body.action==='inspect'
          ? {name:'Taylor New',email:'taylor@example.test',team_name:'Studio',roles:['member'],expires_at:'2026-11-19 12:00:00'}
          : {ok:true,message:'Your account is ready. Sign in to TaskFlow.'};
        return {ok:true,status:200,json:async()=>data};
      };
    }""")
    invite.add_script_tag(content=invite_js)
    invite.wait_for_selector('#invite-form:visible')
    assert invite.evaluate('window.invitationHistoryCleared===true')
    assert invite.locator('#invite-details').inner_text().find('taylor@example.test')>=0
    assert invite.evaluate('document.documentElement.scrollWidth <= innerWidth + 2')
    invite.evaluate('''logo => { const img=document.querySelector('.brand img'); if(img) img.src=logo; }''',logo)
    invite.screenshot(path=str(root/'docs/screenshots/invite-accept.png'),full_page=True)
    invite.locator('#invite-password').fill('Long Example Passphrase 2026!')
    invite.locator('#invite-password-confirm').fill('Long Example Passphrase 2026!')
    invite.locator('#invite-submit').click()
    invite.wait_for_selector('#invite-form[hidden]', state='attached')
    assert 'account is ready' in invite.locator('#invite-status').inner_text()
    assert not errors, errors
    print('PASS: recipient sees invite and activates account with own password; token removed from URL')
    browser.close()
print('1.11 browser checks pass with mocked APIs; live MySQL remains unverified.')
