"""TaskFlow 1.17.0 announcement interactions (mocked API / synthetic members only)."""
from pathlib import Path
import re, base64
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
html='<!DOCTYPE html>'+(ROOT/'index.php').read_text().split('<!DOCTYPE html>',1)[1]
html=re.sub(r'<\?php.*?\?>','',html,flags=re.S)
html=re.sub(r'<link rel="stylesheet"[^>]*>','',html)
html=re.sub(r'<script src="[^"]*">\s*</script>','',html)
css=(ROOT/'assets/css/style.css').read_text()+'\n'+(ROOT/'assets/css/design-system.css').read_text()+'\n'+(ROOT/'assets/css/ui-overhaul.css').read_text()+'\n'+(ROOT/'assets/css/theme-accessibility.css').read_text()+'\n'+(ROOT/'assets/css/theme-palettes.css').read_text()+'\n'+(ROOT/'assets/css/v2-workspace.css').read_text()
js=(ROOT/'assets/js/app.js').read_text()
logo='data:image/png;base64,'+base64.b64encode((ROOT/'assets/img/taskflow-logo-192.png').read_bytes()).decode()
icons=(ROOT/'assets/img/icons.svg').read_text()
MOCK=r'''memberMode => {
  const admin={id:1,name:'Morgan Lee',roles:['administrator'],team_id:7,email:'m@test.local',avatar_color:'#258dc9'};
  const member={id:2,name:'Avery Chen',roles:['member'],team_id:7,email:'a@test.local',avatar_color:'#2f9fbb'};
  const me=memberMode?member:admin;
  window.calls=[];
  window.teamAnnouncements=[{id:31,title:'Monthly meeting',message:'Please review the agenda.',
    author_name:'Morgan Lee',created_at:'2026-11-17 08:00:00',requires_ack:true,is_pinned:true,
    is_read:false,is_acknowledged:false,read_count:0,ack_count:0,active_members:2,expires_on:null}];
  window.fetch=async(url,opts={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const query=new URL(String(url),'http://localhost/').searchParams;
    const method=opts.headers?.['X-HTTP-Method-Override']||opts.method||'GET';
    const body=opts.body?JSON.parse(opts.body):{};
    window.calls.push({name,method,body});
    let data={};
    if(name==='me.php')data={user:me,csrf_token:'csrf-abc',organization_name:'Northstar Studio',teams:[{id:7,name:'Studio'}]};
    if(name==='lists.php')data={lists:[{id:3,name:'Planning',team_id:7}]};
    if(name==='tags.php')data={tags:[]};
    if(name==='users.php')data={users:[admin,member]};
    if(name==='notifications.php')data={notifications:[],last_id:0};
    if(name==='announcements.php'){
      if(method==='GET'){
        if(query.get('action')==='roster')data={announcement:{id:31,title:'Monthly meeting',requires_ack:true},members:[
          {id:1,name:'Morgan Lee',read_at:null,acknowledged_at:null},
          {id:2,name:'Avery Chen',read_at:null,acknowledged_at:null}],next_after_user_id:null};
        else data={announcements:window.teamAnnouncements.filter(a=>query.get('archived')==='1'?a.archived:!a.archived),
          can_manage:!memberMode,next_cursor:null};
      }else{
        if(body.action==='create')window.teamAnnouncements.unshift({id:41,title:body.title,message:body.message,
          author_name:me.name,created_at:'2026-11-17 08:30:00',requires_ack:body.requires_ack,
          is_pinned:body.is_pinned,is_read:false,is_acknowledged:false,active_members:2,read_count:0,ack_count:0,
          expires_on:body.expires_on});
        if(body.action==='read')window.teamAnnouncements.find(a=>a.id===body.id).is_read=true;
        if(body.action==='ack'){const a=window.teamAnnouncements.find(a=>a.id===body.id);a.is_acknowledged=true;a.is_read=true;}
        if(body.action==='archive')window.teamAnnouncements.find(a=>a.id===body.id).archived=true;
        data={ok:true,id:41};
      }
    }
    return {ok:true,status:200,json:async()=>data};
  };
}'''

def load(page, member=False):
    page.set_content(html.replace('id="v2-tools-drawer" hidden','id="v2-tools-drawer"'))
    page.add_style_tag(content=css)
    page.evaluate('''assets=>{
      document.body.insertAdjacentHTML('afterbegin',assets.icons);
      document.querySelectorAll('use[href^="assets/img/icons.svg#"]').forEach(u=>u.setAttribute('href','#'+u.getAttribute('href').split('#').pop()));
      document.querySelectorAll('img[src^="assets/img/"]').forEach(i=>i.src=assets.logo);
    }''',{'icons':icons,'logo':logo})
    page.evaluate(MOCK,member)
    page.add_script_tag(content=js)
    page.wait_for_selector('#app:not(.hidden)')

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    admin=browser.new_page(viewport={'width':1360,'height':900})
    admin.set_default_timeout(15000)
    admin.on('pageerror',lambda exc:errors.append(str(exc)))
    load(admin)
    admin.locator('[data-view="announcements"]').click()
    admin.wait_for_selector('.announcement-card')
    assert admin.locator('#announcement-form').count()==1
    assert admin.locator('[data-announcement-action="roster"]').count()==1
    admin.locator('#an-title').fill('Team kickoff')
    admin.locator('#an-message').fill('<img src=x onerror="window.injected=1"> The kickoff is tomorrow.')
    admin.locator('#an-ack').check()
    admin.locator('#an-pinned').check()
    admin.locator('#announcement-form button[type=submit]').click()
    admin.wait_for_function('window.teamAnnouncements.some(a=>a.id===41)')
    admin.wait_for_selector('.announcement-card[data-announcement-id="41"]')
    assert admin.locator('.announcement-card img').count()==0, 'untrusted body became HTML'
    assert admin.evaluate('window.injected===undefined')
    assert admin.evaluate('window.calls.some(c=>c.name==="announcements.php"&&c.body.action==="create"&&c.body.requires_ack===true)')
    print('PASS manager publication, pinned+ack flags and HTML escaping')
    admin.locator('[data-announcement-id="31"][data-announcement-action="roster"]').click()
    admin.wait_for_selector('.announcement-receipt-row')
    assert 'Avery Chen' in admin.locator('.announcement-receipts:not(.hidden)').inner_text()
    print('PASS manager receipt roster with bounded member listing')
    # Keep the negative XSS test above, but use realistic synthetic copy in the public screenshot.
    admin.evaluate("window.teamAnnouncements.find(a=>a.id===41).message='The kickoff is tomorrow at 10 AM. Please review the agenda and share blockers in advance.'")
    admin.locator('#an-refresh').click()
    admin.wait_for_selector('.announcement-card[data-announcement-id="41"]')
    admin.evaluate("document.querySelector('#view-container').scrollTop=0; window.scrollTo(0,0)")
    admin.wait_for_timeout(400)
    admin.screenshot(path=str(ROOT/'docs/screenshots/announcements.png'),full_page=True)
    member=browser.new_page(viewport={'width':390,'height':844})
    member.set_default_timeout(15000)
    member.on('pageerror',lambda exc:errors.append(str(exc)))
    load(member,True)
    member.locator('#mobile-menu-btn').click()
    member.locator('[data-view="announcements"]').click()
    member.wait_for_selector('.announcement-card[data-announcement-id="31"]')
    member.wait_for_function('!document.querySelector(".sidebar").classList.contains("open")')
    assert member.locator('#announcement-form').count()==0
    assert member.locator('[data-announcement-action="archive"]').count()==0
    assert member.locator('[data-announcement-action="roster"]').count()==0
    member.locator('[data-announcement-id="31"][data-announcement-action="ack"]').click()
    member.wait_for_selector('.announcement-acknowledged')
    assert member.evaluate('window.calls.some(c=>c.name==="announcements.php"&&c.body.action==="ack"&&c.body.id===31)')
    assert member.evaluate('document.documentElement.scrollWidth <= window.innerWidth+1')
    member.wait_for_timeout(450)
    member.screenshot(path=str(ROOT/'docs/screenshots/announcements-mobile.png'),full_page=True)
    print('PASS member acknowledgement, manager-only controls, mobile layout')
    assert not errors, errors
    browser.close()
print('All TaskFlow 1.17.0 announcement browser checks passed (mocked APIs).')
