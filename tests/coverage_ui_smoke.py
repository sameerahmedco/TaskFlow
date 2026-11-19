"""TaskFlow 1.16.0 coverage handoff and availability export links (mocked APIs)."""
from pathlib import Path
import re, base64
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parent.parent
html='<!DOCTYPE html>'+(root/'index.php').read_text().split('<!DOCTYPE html>',1)[1]
html=re.sub(r'<\?php.*?\?>','',html,flags=re.S)
html=re.sub(r'<link rel="stylesheet"[^>]*>','',html)
html=re.sub(r'<script src="[^"]*">\s*</script>','',html)
css=(root/'assets/css/style.css').read_text()+'\n'+(root/'assets/css/design-system.css').read_text()+'\n'+(root/'assets/css/ui-overhaul.css').read_text()+'\n'+(root/'assets/css/theme-accessibility.css').read_text()+'\n'+(root/'assets/css/theme-palettes.css').read_text()
js=(root/'assets/js/app.js').read_text()
icons=(root/'assets/img/icons.svg').read_text()
logo='data:image/png;base64,'+base64.b64encode((root/'assets/img/taskflow-logo-192.png').read_bytes()).decode()
MOCK=r'''(memberMode) => {
  const admin={id:1,name:'Morgan Lee',roles:['administrator'],team_id:7,email:'morgan@test.local',avatar_color:'#007baa'};
  const member={id:2,name:'Avery Chen',roles:['member'],team_id:7,email:'avery@test.local',avatar_color:'#b07a19'};
  const me=memberMode?member:admin;
  window.calls=[];
  window.leaveRows=[{id:14,user_id:2,user_name:'Avery Chen',start_date:'2026-11-25',end_date:'2026-11-26',day_portion:'full',
    reason:'Private medical information',reviewer_note:'Confidential manager text',status:'pending',backup_user_id:1,backup_name:'Morgan Lee',coverage_status:'pending'}];
  window.fetch=async(url,opts={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const params=new URL(String(url),'http://localhost/').searchParams;
    const method=opts.headers?.['X-HTTP-Method-Override']||opts.method||'GET';
    const body=opts.body?JSON.parse(opts.body):{};
    window.calls.push({name,method,body,params:Object.fromEntries(params.entries())});
    let data={};
    if(name==='me.php') data={user:me,csrf_token:'csrf-test',organization_name:'Northstar Studio',teams:[{id:7,name:'Studio'}]};
    if(name==='lists.php') data={lists:[{id:3,name:'Product',team_id:7}]};
    if(name==='tags.php') data={tags:[]};
    if(name==='users.php') data={users:[admin,member]};
    if(name==='notifications.php') data={notifications:[],last_id:0};
    if(name==='time_off.php'){
      if(method==='GET'){
        const scope=params.get('scope');
        let rows=window.leaveRows.filter(r=>scope==='team'||(scope==='mine'?r.user_id===me.id:r.backup_user_id===me.id));
        if(scope==='coverage')rows=rows.map(r=>({...r,reason:'',reviewer_note:''}));
        data={scope,requests:rows,can_review:!memberMode,next_before_id:null};
      }else if(body.action==='request'){
        window.leaveRows.push({id:55,user_id:me.id,user_name:me.name,reason:body.reason,
          status:'pending',start_date:body.start_date,end_date:body.end_date,day_portion:body.day_portion,
          backup_user_id:Number(body.backup_user_id)||null,backup_name:'Avery Chen',coverage_status:'pending',reviewer_note:''});
        data={ok:true,id:55,status:'pending'};
      }else if(body.action==='coverage_response'){
        window.leaveRows.find(r=>r.id===body.id).coverage_status=body.decision==='accept'?'accepted':'declined';
        data={ok:true,coverage_status:window.leaveRows.find(r=>r.id===body.id).coverage_status};
      }else{data={ok:true};}
    }
    if(name==='leave_calendar.php') {
      const month=params.get('month');
      data={month,from:month+'-01',to:month+'-30',approved_leave:[],holidays:[],truncated:false};
    }
    if(name==='leave_balances.php') {
      data={rows:[{user_id:me.id,name:me.name,allowance_half_days:40,approved_half_days:4,remaining_half_days:36}],year:Number(params.get('year')),
        scope:params.get('scope'),can_manage:!memberMode,next_offset:null};
    }
    return {ok:true,status:200,json:async()=>data};
  };
}'''

def load(page,member_mode=False):
    page.set_content(html)
    page.add_style_tag(content=css)
    page.evaluate('''assets=>{
      document.body.insertAdjacentHTML('afterbegin',assets.icons);
      document.querySelectorAll('use[href^="assets/img/icons.svg#"]').forEach(u=>u.setAttribute('href','#'+u.getAttribute('href').split('#').pop()));
      document.querySelectorAll('img[src^="assets/img/"]').forEach(i=>i.src=assets.logo);
    }''',{'icons':icons,'logo':logo})
    page.evaluate(MOCK,member_mode)
    page.add_script_tag(content=js)
    page.wait_for_selector('#app:not(.hidden)')

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    admin=browser.new_page(viewport={'width':1360,'height':900})
    admin.set_default_timeout(14000)
    admin.on('pageerror',lambda e:errors.append(str(e)))
    load(admin)
    admin.locator('[data-view="time-off"]').click()
    admin.wait_for_selector('#leave-backup option[value="2"]',state='attached')
    today=admin.evaluate('new Date(Date.now()+7*86400000).toISOString().slice(0,10)')
    admin.locator('[name="start_date"]').fill(today)
    admin.locator('[name="end_date"]').fill(today)
    admin.locator('#leave-backup').select_option('2')
    admin.locator('#leave-request-form button[type=submit]').click()
    admin.wait_for_function("window.calls.some(c=>c.name==='time_off.php'&&c.body.action==='request'&&c.body.backup_user_id==='2')")
    print('PASS employee may nominate same-team coverage without delegating tasks')
    admin.locator('#leave-scope').select_option('coverage')
    admin.wait_for_selector('[data-coverage-accept="14"]')
    assert 'Private medical information' not in admin.locator('#leave-results').inner_text()
    assert 'Confidential manager text' not in admin.locator('#leave-results').inner_text()
    admin.screenshot(path=str(root/'docs/screenshots/coverage.png'),full_page=True)
    admin.locator('[data-coverage-accept="14"]').click()
    admin.wait_for_function("window.leaveRows.find(r=>r.id===14).coverage_status==='accepted'")
    assert admin.evaluate("window.calls.some(c=>c.name==='time_off.php'&&c.body.action==='coverage_response'&&c.body.decision==='accept')")
    print('PASS coverage inbox redacts private notes and records single acknowledgement')
    admin.locator('[data-view="availability"]').click()
    admin.wait_for_selector('#absence-export')
    assert 'availability_export.php' in admin.locator('#absence-export').get_attribute('href')
    assert 'team_id=7' in admin.locator('#absence-export').get_attribute('href')
    admin.locator('[data-view="leave-balances"]').click()
    admin.wait_for_selector('.balance-card')
    assert 'leave_balance_export.php' in admin.locator('a[href*="leave_balance_export.php"]').get_attribute('href')
    admin.screenshot(path=str(root/'docs/screenshots/leave-exports.png'),full_page=True)
    print('PASS signed-in manager sees availability and balance export links')
    member=browser.new_page(viewport={'width':390,'height':844})
    member.set_default_timeout(14000)
    member.on('pageerror',lambda e:errors.append(str(e)))
    load(member,True)
    member.locator('#mobile-menu-btn').click()
    member.locator('[data-view="time-off"]').click()
    member.wait_for_selector('#leave-backup')
    assert member.locator('#leave-scope option[value=team]').count()==0
    assert member.locator('#leave-backup option[value="1"]').count()==1
    assert member.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1')
    member.wait_for_function('!document.querySelector(".sidebar").classList.contains("open")')
    member.wait_for_timeout(450)
    member.screenshot(path=str(root/'docs/screenshots/coverage-mobile.png'),full_page=True)
    member.locator('#mobile-menu-btn').click()
    member.locator('[data-view="leave-balances"]').click()
    member.wait_for_selector('.balance-card')
    assert member.locator('a[href*="leave_balance_export.php"]').count()==0
    print('PASS member controls and mobile layout respect manager-only export')
    assert not errors, errors
    browser.close()
print('All TaskFlow 1.16.0 coverage UI scenarios passed (mocked APIs).')
