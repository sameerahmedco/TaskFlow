"""Mocked browser interactions for v1.5.0 task estimates and workload (no MySQL)."""
from pathlib import Path
import base64
import re
from playwright.sync_api import sync_playwright
root = Path(__file__).resolve().parent.parent
html = '<!DOCTYPE html>' + (root / 'index.php').read_text().split('<!DOCTYPE html>', 1)[1]
html = re.sub(r'<\?php.*?\?>', '', html, flags=re.S)
html = re.sub(r'<link rel="stylesheet"[^>]*>', '', html)
html = re.sub(r'<script src="[^"]*">\s*</script>', '', html)
css = (root / 'assets/css/style.css').read_text() + '\n' + (root / 'assets/css/design-system.css').read_text()
script = (root / 'assets/js/app.js').read_text()
icons = (root / 'assets/img/icons.svg').read_text()
logo = 'data:image/png;base64,' + base64.b64encode((root / 'assets/img/taskflow-logo-192.png').read_bytes()).decode()
mock = r'''() => {
  const admin={id:1,name:'Morgan Lee',email:'morgan@example.test',roles:['administrator'],team_id:7,
    team_name:'Studio',avatar_color:'#168dcb',must_change_password:false};
  const monday=new Date();monday.setUTCDate(monday.getUTCDate()-((monday.getUTCDay()+6)%7));
  const week=monday.toISOString().slice(0,10);
  const task={id:24,title:'Prepare launch checklist',description:'Review the rollout',status:'in_progress',priority:'high',
    estimate_minutes:120,logged_minutes:90,created_by:1,team_id:7,list_id:3,assignees:[admin],tags:[],
    due_date:week,start_date:null,is_recurring_weekly:0,checklist:[],subtasks:[],comments:[],recent_activity:[],
    dependencies:{depends_on:[],blocks:[]},unfinished_blockers:0,is_favorite:false};
  window.calls=[];window.capacity={1:2400,2:2400};window.task=task;window.attackName=false;
  window.fetch=async(url,options={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const params=new URL(String(url),'http://localhost/').searchParams;
    const method=options.headers?.['X-HTTP-Method-Override']||options.method||'GET';
    const body=options.body?JSON.parse(options.body):{};
    window.calls.push({name,method,body,query:Object.fromEntries(params.entries())});
    let data={};
    if(name==='me.php') data={user:admin,csrf_token:'test-csrf',organization_name:'Northstar Studio',teams:[{id:7,name:'Studio'}]};
    if(name==='lists.php') data={lists:[{id:3,team_id:7,name:'Team tasks'}]};
    if(name==='tags.php') data={tags:[]};
    if(name==='users.php') data={users:[{...admin,is_active:1},{id:2,name:'Avery Chen',team_id:7,is_active:1,roles:['member'],avatar_color:'#1e91a3'}]};
    if(name==='my_work.php') data={tasks:[{...task,team_name:'Studio',list_name:'Team tasks'}],next_before_id:null};
    if(name==='task.php') {
      if(method==='GET') data={task};
      else {task.estimate_minutes=body.estimate_minutes;data={ok:true,recurrence_new_task_id:null};}
    }
    if(name==='worklog.php') data={minutes_total:90,entries:[],can_log:false,can_view_team:true,week_locked:false};
    if(name==='timer.php') data={active:null,server_now:new Date().toISOString()};
    if(name==='notifications.php') data={last_id:0,notifications:[]};
    if(name==='workload.php') {
      if(method==='POST'){window.capacity[body.user_id]=body.weekly_minutes;data={ok:true,weekly_minutes:body.weekly_minutes};}
      else {
        const members=[
          {user_id:1,name:admin.name,capacity_minutes:window.capacity[1],due_tasks:3,unestimated_tasks:1,planned_minutes:3000,logged_minutes:270,can_edit_capacity:true},
          {user_id:2,name:window.attackName?'Avery\" onmouseover=\"alert(1)':'Avery Chen',capacity_minutes:window.capacity[2],due_tasks:2,unestimated_tasks:0,planned_minutes:900,logged_minutes:120,can_edit_capacity:true}
        ].filter(m=>params.get('scope')==='team'||m.user_id===1);
        data={week:{from:params.get('week'),to:params.get('week')},scope:params.get('scope'),rows:members.map(m=>({...m,load_percent:m.capacity_minutes?Math.round(100*m.planned_minutes/m.capacity_minutes):null})),next_offset:null};
      }
    }
    return {ok:true,status:200,json:async()=>data};
  };
}'''

def load(page):
    page.set_content(html)
    page.add_style_tag(content=css)
    page.evaluate("""asset=>{
      document.body.insertAdjacentHTML('afterbegin',asset.icons);
      document.querySelectorAll('use[href^="assets/img/icons.svg#"]').forEach(el=>el.setAttribute('href','#'+el.getAttribute('href').split('#').pop()));
      document.querySelectorAll('img[src^="assets/img/"]').forEach(img=>img.src=asset.logo);
    }""", {'icons':icons,'logo':logo})
    page.evaluate(mock)
    page.add_script_tag(content=script)
    page.wait_for_selector('#app:not(.hidden)')
with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox'])
    errors=[]
    page=browser.new_page(viewport={'width':1320,'height':900})
    page.set_default_timeout(10000)
    page.on('pageerror',lambda e:errors.append(str(e)))
    load(page)
    page.locator('[data-view="workload"]').click()
    page.wait_for_selector('.workload-card')
    assert page.locator('.workload-card').count()==2
    assert page.locator('.workload-over').count()==1
    assert 'without estimates' in page.locator('#workload-results').inner_text()
    print('PASS: team planning flags overcapacity and missing estimates')
    page.screenshot(path=str(root/'docs/screenshots/workload.png'),full_page=True)
    page.locator('[data-capacity-id="2"]').fill('30')
    page.locator('[data-save-capacity="2"]').click()
    page.wait_for_function('window.capacity[2]===1800')
    assert page.evaluate('window.calls.some(c=>c.name==="workload.php"&&c.method==="POST"&&c.body.weekly_minutes===1800&&c.body.user_id===2)')
    print('PASS: manager capacity changes use authenticated POST')
    page.locator('#workload-scope').select_option('mine')
    page.wait_for_function("document.querySelectorAll('.workload-card').length===1")
    assert page.locator('.workload-card').count()==1
    page.locator('#workload-next').click()
    page.wait_for_function("window.calls.filter(c=>c.name==='workload.php'&&c.method==='GET').length>=4")
    print('PASS: personal scope and next-week navigation')
    page.locator('[data-view="my-work"]').click()
    page.locator('.search-result').first.click()
    page.wait_for_selector('#tm-estimate')
    assert page.locator('#tm-estimate').input_value()=='2'
    assert 'Logged 1.5h' in page.locator('#task-modal-body').inner_text()
    page.locator('#tm-estimate').fill('3.5')
    page.locator('#tm-save').click()
    page.wait_for_function('window.calls.some(c=>c.name==="task.php"&&c.method==="PUT"&&c.body.estimate_minutes===210)')
    print('PASS: task estimate, planned-vs-logged and edit submit')
    page.evaluate('window.attackName=true')
    page.locator('[data-view="workload"]').click()
    page.wait_for_selector('.workload-card')
    assert page.locator('.workload-grid [onmouseover]').count()==0, 'user name escaped into unexpected HTML attribute'
    print('PASS: untrusted user name cannot inject a DOM event attribute')
    mobile=browser.new_page(viewport={'width':390,'height':844},device_scale_factor=1)
    mobile.set_default_timeout(10000)
    mobile.on('pageerror',lambda e:errors.append(str(e)))
    load(mobile)
    mobile.locator('#mobile-menu-btn').click()
    mobile.locator('[data-view="workload"]').click()
    mobile.wait_for_selector('.workload-card')
    mobile.wait_for_function("!document.querySelector('.sidebar').classList.contains('open')")
    mobile.wait_for_timeout(400)
    width=mobile.evaluate('document.documentElement.scrollWidth')
    assert width<=390, f'Mobile page overflows {width}px'
    mobile.screenshot(path=str(root/'docs/screenshots/workload-mobile.png'),full_page=True)
    print('PASS: 390px workload page has no horizontal overflow')
    if errors: raise AssertionError('Browser errors: '+repr(errors))
    browser.close()
print('All workload UI smoke scenarios passed (mocked API).')
