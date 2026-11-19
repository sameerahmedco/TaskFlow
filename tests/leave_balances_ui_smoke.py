"""TaskFlow 1.15 availability calendar and leave balances UI smoke test (mock responses)."""
"""TaskFlow 1.14.0 holidays, custom workdays and half-day UI (mocked API)."""
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
    page.set_content(html)
    page.add_style_tag(content=css)
    page.evaluate('''asset=>{
      document.body.insertAdjacentHTML('afterbegin',asset.icons);
      document.querySelectorAll('use[href^="assets/img/icons.svg#"]').forEach(el=>el.setAttribute('href','#'+el.getAttribute('href').split('#').pop()));
      document.querySelectorAll('img[src^="assets/img/"]').forEach(el=>el.src=asset.logo);
    }''',{'icons':icons,'logo':logo})
    page.evaluate(MOCK,member_mode)
    page.add_script_tag(content=js)
    page.wait_for_selector('#app:not(.hidden)')


ENHANCE = r"""(memberMode) => {
  const oldFetch=window.fetch;
  window.availableUnits=40;
  window.cleanPreview=false;
  window.fetch=async(url,opts={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const params=new URL(String(url),'http://localhost/').searchParams;
    const method=opts.method||'GET';
    const body=opts.body?JSON.parse(opts.body):{};
    if(name==='leave_balances.php'||name==='leave_calendar.php') {
      window.calls.push({name,method,body,query:Object.fromEntries(params.entries())});
      if(name==='leave_calendar.php') {
        const month=params.get('month');
        return {ok:true,status:200,json:async()=>({month,from:month+'-01',to:month+'-30',truncated:false,
          approved_leave:[{id:4,user_id:2,member_name:window.cleanPreview?'Avery Chen':'Avery <img src=x onerror=alert(1)>',start_date:month+'-10',end_date:month+'-11',day_portion:'am'}],
          holidays:[{holiday_date:month+'-12',title:'Team holiday'}]})};
      }
      if(method==='POST') {
        if(!memberMode) window.availableUnits=body.allowance_half_days;
        return {ok:true,status:200,json:async()=>({ok:true,user_id:body.user_id,allowance_half_days:window.availableUnits})};
      }
      const scope=params.get('scope');
      const rows=scope==='mine'? [{user_id:memberMode?2:1,name:memberMode?'Avery Chen':'Morgan Lee',allowance_half_days:20,approved_half_days:3,remaining_half_days:17}]:[
        {user_id:2,name:'Avery Chen',allowance_half_days:window.availableUnits,approved_half_days:5,remaining_half_days:window.availableUnits-5},
        {user_id:1,name:'Morgan Lee',allowance_half_days:null,approved_half_days:3,remaining_half_days:null}];
      return {ok:true,status:200,json:async()=>({year:Number(params.get('year')),scope,rows,can_manage:!memberMode,next_offset:null,advisory:true})};
    }
    return oldFetch(url,opts);
  };
}"""

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    admin=browser.new_page(viewport={'width':1360,'height':900})
    admin.set_default_timeout(14000)
    admin.on('pageerror',lambda err:errors.append(str(err)))
    load(admin,False)
    admin.evaluate(ENHANCE,False)
    admin.locator('[data-view="availability"]').click()
    admin.wait_for_selector('.absence-day')
    assert admin.locator('.absence-day').count()>=28
    assert admin.locator('.absence-tag.absence-holiday').count()==1
    assert admin.locator('.absence-tag').filter(has_text='Avery').count()==2
    assert admin.locator('.absence-day img').count()==0, 'untrusted names rendered as HTML'
    admin.evaluate('window.cleanPreview=true')
    admin.locator('#absence-next').click()
    admin.wait_for_selector('.absence-day')
    admin.locator('#absence-prev').click()
    admin.wait_for_selector('.absence-day')
    admin.screenshot(path=str(root/'docs/screenshots/availability.png'),full_page=True)
    print('PASS: approved-only calendar escapes names and shows holidays')
    admin.locator('#absence-prev').click()
    admin.wait_for_selector('.absence-day')
    assert admin.evaluate("window.calls.filter(c=>c.name==='leave_calendar.php').length")>=2
    admin.locator('[data-view="leave-balances"]').click()
    admin.wait_for_selector('.balance-card')
    assert admin.locator('.balance-card').count()==2
    admin.locator('[data-balance-user="2"] [name="days"]').fill('22.5')
    admin.locator('[data-balance-user="2"] .balance-edit-form button').click()
    admin.wait_for_function('window.availableUnits===45')
    admin.wait_for_function("document.querySelector('[data-balance-user=\"2\"] .balance-stats').innerText.includes('22.5 days')")
    assert admin.evaluate("window.calls.filter(c=>c.name==='leave_balances.php'&&c.method==='POST').at(-1).body.allowance_half_days")==45
    admin.screenshot(path=str(root/'docs/screenshots/leave-balances.png'),full_page=True)
    print('PASS: manager can view team balances and update half-day allowance')
    member=browser.new_page(viewport={'width':390,'height':844})
    member.set_default_timeout(14000)
    member.on('pageerror',lambda err:errors.append(str(err)))
    load(member,True)
    member.evaluate(ENHANCE,True)
    member.locator('#mobile-menu-btn').click()
    member.locator('[data-view="leave-balances"]').click()
    member.wait_for_selector('.balance-card')
    assert member.locator('.balance-edit-form').count()==0
    assert member.evaluate("window.calls.filter(c=>c.name==='leave_balances.php'&&c.method==='GET').at(-1).query.scope")=='mine'
    member.locator('#mobile-menu-btn').click()
    member.locator('[data-view="availability"]').click()
    member.wait_for_selector('.absence-day')
    member.wait_for_timeout(800)
    assert member.evaluate('document.documentElement.scrollWidth <= window.innerWidth+1'), 'mobile page overflows horizontally'
    member.evaluate('window.cleanPreview=true')
    member.locator('#absence-next').click()
    member.wait_for_selector('.absence-day')
    member.locator('#absence-prev').click()
    member.wait_for_selector('.absence-day')
    member.screenshot(path=str(root/'docs/screenshots/availability-mobile.png'),full_page=True)
    assert not errors,errors
    print('PASS: member sees own balance without edit, mobile layout fits, no page errors')
    browser.close()
