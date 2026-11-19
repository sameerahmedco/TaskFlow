"""Browser interaction checks for TaskFlow 1.2.0 (mocked HTTP only)."""
from pathlib import Path
import base64
import re
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parent.parent
html = '<!DOCTYPE html>' + (root/'index.php').read_text().split('<!DOCTYPE html>',1)[1]
html = re.sub(r'<\?php.*?\?>', '', html, flags=re.S)
html = re.sub(r'<link rel="stylesheet"[^>]*>', '', html)
html = re.sub(r'<script src="[^"]*">\s*</script>', '', html)
styles = (root/'assets/css/style.css').read_text() + '\n' + (root/'assets/css/design-system.css').read_text()+'\n'+(root/'assets/css/ui-overhaul.css').read_text()+'\n'+(root/'assets/css/theme-accessibility.css').read_text()+'\n'+(root/'assets/css/theme-palettes.css').read_text()
script = (root/'assets/js/app.js').read_text()
icons = (root/'assets/img/icons.svg').read_text()
logo = 'data:image/png;base64,'+base64.b64encode((root/'assets/img/taskflow-logo-192.png').read_bytes()).decode()
mock = r'''() => {
  const iso=new Date().toISOString().slice(0,10);
  const user={id:1,name:'Alex Rivera',email:'alex@example.test',roles:['administrator'],team_id:7,
      team_name:'Studio',avatar_color:'#2a8db9',must_change_password:false};
  const task={id:24,title:'Prepare workshop resources',description:'Draft agenda',status:'in_progress',priority:'high',
      due_date:iso,start_date:null,team_id:7,list_id:3,created_by:1,assignees:[user],tags:[],
      checklist:[],subtasks:[],comments:[],recent_activity:[],dependencies:{depends_on:[],blocks:[]},
      unfinished_blockers:0,is_recurring_weekly:0,is_favorite:false};
  window.calls=[];
  window.fetch=async(url,options={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const params=new URL(String(url),'http://localhost/').searchParams;
    const method=options.headers?.['X-HTTP-Method-Override']||options.method||'GET';
    const body=options.body?JSON.parse(options.body):{};
    window.calls.push({name,method,body,query:Object.fromEntries(params.entries())});
    let data={};
    if(name==='me.php') data={user,csrf_token:'unit-csrf',organization_name:'Studio One',teams:[{id:7,name:'Studio'}]};
    if(name==='lists.php') data={lists:[{id:3,team_id:7,name:'Operations'}]};
    if(name==='tags.php') data={tags:[]};
    if(name==='users.php') data={users:[{...user,is_active:1}]};
    if(name==='my_work.php') data={tasks:params.get('mode')==='favorites'?[{...task,list_name:'Operations',assigned_to_me:1}]:[],next_before_id:null};
    if(name==='notifications.php') data={last_id:0,notifications:[]};
    if(name==='monthly.php') data={weeks:[]};
    if(name==='task.php') data={task};
    if(name==='time_report.php') data={totals:{minutes:135,entries:3,tasks:2},
        daily:[{date:iso,minutes:135}],entries:[{id:101,task_id:24,user_id:1,
          name:'Alex Rivera',title:'Prepare workshop resources',minutes:75,note:'Agenda',work_date:iso}],next_before_id:null};
    if(name==='worklog.php') data=method==='GET'?{minutes_total:75,can_log:true,can_view_team:true,
        entries:[{id:101,user_id:1,name:'Alex Rivera',minutes:75,note:'Draft agenda',work_date:iso}]}:{ok:true,id:102};
    if(name==='favorites.php') data={ok:true,is_favorite:method==='POST'};
    return {ok:true,status:200,json:async()=>data};
  };
}'''

def load(page):
    page.set_content(html)
    page.add_style_tag(content=styles)
    page.evaluate("""(asset)=>{
      document.body.insertAdjacentHTML('afterbegin',asset.icons);
      document.querySelectorAll('use[href^="assets/img/icons.svg#"]').forEach(u=>u.setAttribute('href','#'+u.getAttribute('href').split('#').pop()));
      document.querySelectorAll('img[src^="assets/img/"]').forEach(img=>img.src=asset.logo);
    }""",{'icons':icons,'logo':logo})
    page.evaluate(mock)
    page.add_script_tag(content=script)
    page.wait_for_selector('#app:not(.hidden)')

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True, executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    desktop=browser.new_page(viewport={'width':1366,'height':900})
    desktop.on('pageerror', lambda e: errors.append(str(e)))
    desktop.on('dialog',lambda d:d.accept())
    desktop.set_default_timeout(8000)
    load(desktop)
    desktop.locator('[data-view="time-reports"]').click()
    desktop.wait_for_selector('.time-metrics strong')
    assert desktop.locator('.time-metrics').inner_text().find('2h 15m')>=0
    assert desktop.locator('.time-report-entry').count()==1
    desktop.screenshot(path=str(root/'docs/screenshots/time-reports.png'),full_page=True)
    desktop.locator('#report-scope').select_option('team')
    desktop.locator('#time-report-filters button').click()
    desktop.wait_for_function("window.calls.some(c=>c.name==='time_report.php'&&c.query.scope==='team')")
    print('PASS: team time reporting, UTC date filters and day chart')

    desktop.locator('.time-report-entry').click()
    desktop.wait_for_selector('#tm-time-panel #tm-log-time')
    assert '1h 15m' in desktop.locator('.worklog-summary').inner_text()
    desktop.locator('#tm-star').click()
    desktop.wait_for_function("window.calls.some(c=>c.name==='favorites.php'&&c.method==='POST'&&c.body.task_id===24)")
    assert desktop.locator('#tm-star').get_attribute('aria-pressed')=='true'
    desktop.screenshot(path=str(root/'docs/screenshots/task-worklog.png'),full_page=True)
    desktop.locator('#tm-log-minutes').fill('25')
    desktop.locator('#tm-log-note').fill('Prepared follow-up')
    desktop.locator('#tm-log-time button[type=submit]').click()
    desktop.wait_for_function("window.calls.some(c=>c.name==='worklog.php'&&c.method==='POST'&&c.body.minutes===25)")
    desktop.wait_for_selector('.worklog-remove')
    desktop.locator('.worklog-remove').click()
    desktop.wait_for_function("window.calls.some(c=>c.name==='worklog.php'&&c.method==='DELETE'&&c.body.id===101)")
    print('PASS: star task, add and remove own manual worklogs')

    desktop.locator('#close-task-modal').click()
    desktop.locator('[data-view="my-work"]').click()
    desktop.locator('#my-work-mode').select_option('favorites')
    desktop.wait_for_function("window.calls.some(c=>c.name==='my_work.php'&&c.query.mode==='favorites')")
    assert desktop.locator('#my-work-results [data-task-id]').count()>=1
    print('PASS: per-user starred task filter in My Work')
    desktop.close()

    mobile=browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
    mobile.on('pageerror',lambda e:errors.append(str(e)))
    mobile.set_default_timeout(8000)
    load(mobile)
    mobile.locator('#mobile-menu-btn').click()
    mobile.locator('[data-view="time-reports"]').click()
    mobile.wait_for_selector('.time-metrics strong')
    mobile.wait_for_function("!document.querySelector('.sidebar').classList.contains('open')")
    mobile.wait_for_timeout(600)
    assert mobile.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), 'mobile page overflow'
    mobile.screenshot(path=str(root/'docs/screenshots/time-reports-mobile.png'),full_page=True)
    print('PASS: mobile time reports fit the viewport')
    assert not errors,errors
    print('PASS: no unexpected JavaScript errors')
    browser.close()
