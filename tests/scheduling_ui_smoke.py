"""TaskFlow 1.14.0 holidays, custom workdays and half-day UI (mocked API)."""
from pathlib import Path
import re, base64
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parent.parent
html='<!DOCTYPE html>'+(root/'index.php').read_text().split('<!DOCTYPE html>',1)[1]
html=re.sub(r'<\?php.*?\?>','',html,flags=re.S)
html=re.sub(r'<link rel="stylesheet"[^>]*>','',html)
html=re.sub(r'<script src="[^"]*">\s*</script>','',html)
css=(root/'assets/css/style.css').read_text()+'\n'+(root/'assets/css/design-system.css').read_text()+'\n'+(root/'assets/css/ui-overhaul.css').read_text()+'\n'+(root/'assets/css/theme-accessibility.css').read_text()+'\n'+(root/'assets/css/theme-palettes.css').read_text()+'\n'+(root/'assets/css/workspace.css').read_text()+'\n'+(root/'assets/css/v3-refinement.css').read_text()+'\n'+(root/'assets/css/v3-compact.css').read_text()
js=(root/'assets/js/app.js').read_text()
icons=(root/'assets/img/icons.svg').read_text()
logo='data:image/png;base64,'+base64.b64encode((root/'assets/img/taskflow-logo-192.png').read_bytes()).decode()
MOCK=r'''(memberMode) => {
  const me={id:memberMode?2:1,name:memberMode?'Avery Chen':'Morgan Lee',email:'user@example.test',
    roles:[memberMode?'member':'administrator'],team_id:7,team_name:'Studio',avatar_color:'#168dcb',must_change_password:false};
  window.mockMe=me;
  window.calls=[];
  window.holidays=[{id:1,holiday_date:new Date().getUTCFullYear()+"-11-18",title:"Team retreat"}];
  window.leaveRows=[
    {id:2,user_id:2,user_name:'Avery Chen',start_date:'2026-11-19',end_date:'2026-11-20',
      reason:'Family appointment',status:'pending',reviewer_note:'',reviewed_at:null,created_at:'2026-11-16 09:00:00'},
    {id:1,user_id:1,user_name:'Morgan Lee',start_date:'2026-11-23',end_date:'2026-11-24',
      reason:'Holiday',status:'approved',reviewer_note:'',reviewed_at:null,created_at:'2026-11-15 10:00:00'}
  ];
  window.fetch=async(url,opts={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const params=new URL(String(url),'http://localhost/').searchParams;
    const method=opts.headers?.['X-HTTP-Method-Override']||opts.method||'GET';
    const body=opts.body?JSON.parse(opts.body):{};
    window.calls.push({name,method,body,query:Object.fromEntries(params.entries())});
    let data={};let status=200;
    if(name==='me.php') data={user:me,csrf_token:'test-csrf',organization_name:'Northstar Studio',teams:[{id:7,name:'Studio'}]};
    if(name==='lists.php') data={lists:[{id:3,name:'Team tasks',team_id:7}]};
    if(name==='tags.php') data={tags:[]};
    if(name==='users.php') data={users:[me]};
    if(name==='notifications.php') data={notifications:[],last_id:0};
    if(name==='holidays.php') {
      if(method==='GET') data={holidays:window.holidays.filter(h=>h.holiday_date.startsWith(params.get('year'))),year:Number(params.get('year')),can_manage:!memberMode};
      else if(body.action==='add') {window.holidays.push({id:99,holiday_date:body.holiday_date,title:body.title});data={ok:true,id:99};}
      else {window.holidays=window.holidays.filter(h=>h.id!==body.id);data={ok:true};}
    }
    if(name==='time_off.php') {
      if(method==='GET') {
        const rows=window.leaveRows.filter(x=>(params.get('scope')==='team'||x.user_id===me.id)&&(!params.get('before_id')||x.id<Number(params.get('before_id'))));
        data={requests:rows,scope:params.get('scope'),can_review:!memberMode,next_before_id:null};
      } else if(body.action==='request') {
        window.leaveRows.unshift({id:Date.now(),user_id:me.id,user_name:me.name,reason:body.reason,
          start_date:body.start_date,end_date:body.end_date,day_portion:body.day_portion,status:'pending',reviewer_note:''});
        data={ok:true,status:'pending',id:window.leaveRows[0].id};
      } else {
        const row=window.leaveRows.find(x=>x.id===body.id);
        if(row){row.status=body.action==='cancel'?'cancelled':(body.decision==='approve'?'approved':'declined');row.reviewer_note=body.reviewer_note||'';}
        data={ok:true,status:row.status};
      }
    }
    if(name==='workload.php') {
      data={rows:[{user_id:2,name:'Avery Chen',due_tasks:3,unestimated_tasks:1,planned_minutes:1700,
        capacity_minutes:2400,effective_capacity_minutes:1440,approved_leave_weekdays:1.5,holiday_days:1,scheduled_days:5,workdays_mask:31,logged_minutes:300,
        load_percent:118,can_edit_capacity:true}],week:{from:'2026-11-16',to:'2026-11-22'},next_offset:null};
    }
    return {ok:status===200,status,json:async()=>data};
  };
}'''

def load(page,member_mode=False):
    page.set_content(html.replace('id="v2-tools-drawer" hidden','id="v2-tools-drawer"'))
    page.add_style_tag(content=css)
    page.evaluate('''asset=>{
      document.body.insertAdjacentHTML('afterbegin',asset.icons);
      document.querySelectorAll('use[href^="assets/img/icons.svg#"]').forEach(el=>el.setAttribute('href','#'+el.getAttribute('href').split('#').pop()));
      document.querySelectorAll('img[src^="assets/img/"]').forEach(el=>el.src=asset.logo);
    }''',{'icons':icons,'logo':logo})
    page.evaluate(MOCK,member_mode)
    page.add_script_tag(content=js)
    page.wait_for_selector('#app:not(.hidden)')

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    admin=browser.new_page(viewport={'width':1360,'height':900})
    admin.set_default_timeout(14000)
    admin.on('pageerror',lambda error: errors.append(str(error)))
    admin.on('dialog',lambda dialog: dialog.accept())
    load(admin,False)
    admin.locator('[data-view="holidays"]').click()
    admin.wait_for_selector('.holiday-row')
    assert 'Team retreat' in admin.locator('.holiday-row').inner_text()
    year=admin.locator('#holiday-year').inner_text()
    admin.locator('[name="holiday_date"]').fill(year+'-11-25')
    admin.locator('[name="title"]').fill('Company planning day')
    admin.locator('#holiday-add-form button[type="submit"]').click()
    admin.wait_for_function('window.holidays.length===2')
    assert admin.locator('.holiday-row').count()==2
    admin.locator('[data-holiday-delete="99"]').click()
    admin.wait_for_function('window.holidays.length===1')
    assert admin.locator('.holiday-row').count()==1
    admin.wait_for_timeout(3200)  # allow confirmation toast to disappear
    admin.screenshot(path=str(root/'docs/screenshots/holidays.png'),full_page=True)
    print('PASS: team holiday creation and deletion, year-scoped manager controls')
    admin.locator('[data-view="workload"]').click()
    admin.wait_for_selector('.workload-card')
    assert '1 holiday day(s)' in admin.locator('.workload-card').inner_text()
    admin.locator('.workdays-editor summary').click()
    admin.locator('[data-workday="32"]').check()
    admin.locator('.workload-card').screenshot(path=str(root/'docs/screenshots/custom-workweek.png'))
    admin.locator('[data-save-schedule="2"]').click()
    admin.wait_for_function("window.calls.some(c=>c.name==='workload.php'&&c.body.action==='set_schedule')")
    assert admin.evaluate("window.calls.filter(c=>c.name==='workload.php'&&c.body.action==='set_schedule').at(-1).body.workdays_mask") == 63
    print('PASS: custom workday mask sent through authenticated workload action')
    admin.locator('[data-view="time-off"]').click()
    admin.wait_for_selector('.leave-item')
    assert admin.locator('[name="day_portion"]').count()==1
    print('PASS: time-off partial-day control shown to team member')
    mobile=browser.new_page(viewport={'width':390,'height':844},device_scale_factor=1)
    mobile.set_default_timeout(14000)
    mobile.on('pageerror',lambda error: errors.append(str(error)))
    mobile.on('dialog',lambda dialog: dialog.accept())
    load(mobile,True)
    mobile.locator('#mobile-menu-btn').click()
    mobile.locator('[data-view="holidays"]').click()
    mobile.wait_for_selector('.holiday-row')
    mobile.wait_for_function('!document.querySelector(".sidebar").classList.contains("open")')
    mobile.wait_for_timeout(650)  # wait for the closing drawer animation to finish
    assert mobile.locator('#holiday-add-form').count()==0
    assert mobile.locator('[data-holiday-delete]').count()==0
    assert mobile.evaluate('document.documentElement.scrollWidth')<=390
    mobile.screenshot(path=str(root/'docs/screenshots/holidays-mobile.png'),full_page=True)
    print('PASS: members have read-only holiday view on mobile without overflow')
    mobile.locator('#mobile-menu-btn').click()
    mobile.locator('[data-view="time-off"]').click()
    mobile.wait_for_selector('.leave-item')
    day=mobile.evaluate('new Date().toISOString().slice(0,10)')
    mobile.locator('[name="start_date"]').fill(day)
    mobile.locator('[name="end_date"]').fill(day)
    mobile.locator('[name="day_portion"]').select_option('am')
    mobile.locator('[name="reason"]').fill('<img src=x onerror=alert(1)>')
    mobile.locator('#leave-request-form button[type="submit"]').click()
    mobile.wait_for_function('window.leaveRows.length===3')
    assert mobile.evaluate("window.calls.filter(c=>c.name==='time_off.php'&&c.body.action==='request').at(-1).body.day_portion")=='am'
    assert mobile.locator('.leave-item [onerror]').count()==0
    assert 'Morning half-day' in mobile.locator('.leave-item').first.inner_text()
    assert mobile.evaluate('document.documentElement.scrollWidth')<=390
    mobile.locator('.leave-item').first.locator('.leave-note').evaluate("(el) => el.textContent = 'Personal appointment'")
    mobile.wait_for_timeout(3200)
    mobile.screenshot(path=str(root/'docs/screenshots/time-off-halfday-mobile.png'),full_page=True)
    mobile.locator('.leave-item').first.screenshot(path=str(root/'docs/screenshots/halfday-confirmation.png'))
    print('PASS: employee submits a half day, safely escaped and mobile responsive')
    assert not errors, errors
    browser.close()
print('All 1.14 scheduling browser smoke checks passed (mocked APIs).')
