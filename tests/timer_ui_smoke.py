"""TaskFlow 1.3.0: browser interaction checks with synthetic data and mocked HTTP."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import base64, re
root=Path(__file__).resolve().parent.parent
html='<!DOCTYPE html>'+ (root/'index.php').read_text().split('<!DOCTYPE html>',1)[1]
html=re.sub(r'<\?php.*?\?>','',html,flags=re.S)
html=re.sub(r'<link rel="stylesheet"[^>]*>','',html)
html=re.sub(r'<script src="[^\"]*">\s*</script>','',html)
style=(root/'assets/css/style.css').read_text()+'\n'+(root/'assets/css/design-system.css').read_text()+'\n'+(root/'assets/css/ui-overhaul.css').read_text()+'\n'+(root/'assets/css/theme-accessibility.css').read_text()+'\n'+(root/'assets/css/theme-palettes.css').read_text()
script=(root/'assets/js/app.js').read_text()
icons=(root/'assets/img/icons.svg').read_text()
logo='data:image/png;base64,'+base64.b64encode((root/'assets/img/taskflow-logo-192.png').read_bytes()).decode()
mock=r'''() => {
  const today=new Date().toISOString().slice(0,10);
  const monday=new Date(); monday.setUTCDate(monday.getUTCDate()-((monday.getUTCDay()+6)%7));
  const mondayDate=monday.toISOString().slice(0,10);
  const admin={id:1,name:'Taylor Morgan',email:'taylor@example.test',roles:['administrator'],team_id:7,
     team_name:'Studio',avatar_color:'#168dcb',must_change_password:false};
  const task={id:24,title:'Prepare onboarding guide',description:'Write the setup walkthrough',status:'in_progress',priority:'normal',
    created_by:1,team_id:7,list_id:3,assignees:[admin],tags:[],due_date:today,start_date:null,recurrence_generated_from:null,
    checklist:[],subtasks:[],comments:[],recent_activity:[],dependencies:{depends_on:[],blocks:[]},unfinished_blockers:0,is_favorite:false};
  window.calls=[];window.activeTimer=null;
  window.fetch=async(url,options={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const params=new URL(String(url),'http://localhost/').searchParams;
    const method=options.headers?.['X-HTTP-Method-Override']||options.method||'GET';
    const body=options.body?JSON.parse(options.body):{};
    window.calls.push({name,method,body,query:Object.fromEntries(params.entries())});
    let data={};
    if(name==='mfa_status.php') data={mfa_required:false};
    if(name==='me.php') data={user:admin,csrf_token:'unit-csrf',organization_name:'Northstar Studio',teams:[{id:7,name:'Studio'}]};
    if(name==='lists.php') data={lists:[{id:3,team_id:7,name:'Product launch'}]};
    if(name==='tags.php') data={tags:[]};
    if(name==='users.php') data={users:[{...admin,is_active:1}]};
    if(name==='my_work.php') data={tasks:[{...task,list_name:'Product launch',assigned_to_me:1}],next_before_id:null};
    if(name==='notifications.php') data={last_id:0,notifications:[]};
    if(name==='monthly.php') data={weeks:[]};
    if(name==='task.php') data={task};
    if(name==='worklog.php') data={minutes_total:60,entries:[],can_log:true,can_view_team:true};
    if(name==='timer.php'){
      if(method==='POST') {
        if(body.action==='start') window.activeTimer={task_id:24,title:task.title,started_at:new Date(Date.now()-61000).toISOString(),can_stop:true};
        if(body.action==='stop'||body.action==='discard') window.activeTimer=null;
        data={ok:true,minutes:2,task_id:24};
      } else data={active:window.activeTimer,server_now:new Date().toISOString()};
    }
    if(name==='timesheet.php') data={week:{from:mondayDate,to:today},scope:params.get('scope'),
        summary:{minutes:210,entries:5,tasks:2,contributors:1},daily:[{date:mondayDate,minutes:210}],
        rows:[{task_id:24,title:task.title,user_id:1,name:admin.name,minutes:150,entries:3}],limited:false};
    if(name==='time_report.php') data={totals:{minutes:210,entries:5,tasks:2},daily:[],entries:[],next_before_id:null};
    return {ok:true,status:200,json:async()=>data};
  };
}'''

def load(page):
    page.set_content(html)
    page.add_style_tag(content=style)
    page.evaluate("""asset=>{
      document.body.insertAdjacentHTML('afterbegin',asset.icons);
      document.querySelectorAll('use[href^="assets/img/icons.svg#"]').forEach(el=>el.setAttribute('href','#'+el.getAttribute('href').split('#').pop()));
      document.querySelectorAll('img[src^="assets/img/"]').forEach(img=>img.src=asset.logo);
    }""",{'icons':icons,'logo':logo})
    page.evaluate(mock)
    page.add_script_tag(content=script)
    page.wait_for_selector('#app:not(.hidden)')

screens=root/'docs/screenshots';screens.mkdir(exist_ok=True,parents=True)
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    desktop=browser.new_page(viewport={'width':1366,'height':900})
    desktop.on('pageerror',lambda err:errors.append(str(err)))
    desktop.on('dialog',lambda d:d.accept())
    desktop.set_default_timeout(12000)
    load(desktop)
    desktop.locator('[data-view="timesheet"]').click()
    desktop.wait_for_selector('.timesheet-task')
    assert '3h 30m' in desktop.locator('.time-metrics').inner_text()
    assert 'format=csv' in desktop.locator('#timesheet-export').get_attribute('href')
    desktop.screenshot(path=str(screens/'timesheet.png'),full_page=True)
    desktop.locator('#timesheet-prev').click()
    desktop.wait_for_function("window.calls.filter(c=>c.name==='timesheet.php').length>=2")
    desktop.locator('#timesheet-next').click()
    desktop.wait_for_selector('.timesheet-task')
    desktop.locator('#timesheet-scope').select_option('team')
    desktop.wait_for_function("window.calls.some(c=>c.name==='timesheet.php'&&c.query.scope==='team')")
    assert 'scope=team' in desktop.locator('#timesheet-export').get_attribute('href')
    print('PASS: weekly navigation, scoped report, and CSV link')

    desktop.locator('.timesheet-task').click()
    desktop.wait_for_selector('#tm-time-panel #tm-log-time')
    desktop.wait_for_selector('#task-timer-widget button[data-timer-action="start"]')
    desktop.locator('[data-timer-action="start"]').click()
    desktop.wait_for_function("window.calls.some(c=>c.name==='timer.php'&&c.method==='POST'&&c.body.action==='start')")
    desktop.wait_for_selector('#task-timer-widget [data-timer-action="stop"]')
    assert desktop.locator('.live-timer-clock').first.inner_text().count(':')==2
    desktop.locator('#task-timer-widget [data-timer-action="stop"]').click()
    desktop.wait_for_function("window.calls.some(c=>c.name==='timer.php'&&c.method==='POST'&&c.body.action==='stop')")
    desktop.wait_for_selector('#task-timer-widget button[data-timer-action="start"]')
    print('PASS: task timer starts, displays elapsed time and saves on stop')
    desktop.locator('[data-timer-action="start"]').click()
    desktop.wait_for_selector('#task-timer-widget [data-timer-action="discard"]')
    desktop.locator('#task-timer-widget [data-timer-action="discard"]').click()
    desktop.wait_for_function("window.calls.some(c=>c.name==='timer.php'&&c.method==='POST'&&c.body.action==='discard')")
    print('PASS: explicit timer discard')
    desktop.close()

    phone=browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
    phone.on('pageerror',lambda err:errors.append(str(err)))
    phone.set_default_timeout(12000)
    load(phone)
    phone.locator('#mobile-menu-btn').click()
    phone.locator('[data-view="timesheet"]').click()
    phone.wait_for_selector('.timesheet-task')
    phone.wait_for_function("!document.querySelector('.sidebar').classList.contains('open')")
    phone.wait_for_timeout(650)
    assert phone.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), 'timesheet mobile overflow'
    phone.screenshot(path=str(screens/'timesheet-mobile.png'),full_page=True)
    print('PASS: mobile timesheet fits phone viewport')
    assert not errors,errors
    browser.close()
