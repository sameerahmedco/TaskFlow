"""TaskFlow v1.7.0 portfolio and saved-search browser smoke test. Mocked API, NOT real MySQL."""
from pathlib import Path
import base64
import re
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parent.parent
html='<!DOCTYPE html>'+(root/'index.php').read_text().split('<!DOCTYPE html>',1)[1]
html=re.sub(r'<\?php.*?\?>','',html,flags=re.S)
html=re.sub(r'<link rel="stylesheet"[^>]*>','',html)
html=re.sub(r'<script src="[^"]*">\s*</script>','',html)
css=(root/'assets/css/style.css').read_text()+'\n'+(root/'assets/css/design-system.css').read_text()+'\n'+(root/'assets/css/ui-overhaul.css').read_text()+'\n'+(root/'assets/css/theme-accessibility.css').read_text()+'\n'+(root/'assets/css/theme-palettes.css').read_text()+'\n'+(root/'assets/css/v2-workspace.css').read_text()
script=(root/'assets/js/app.js').read_text()
icons=(root/'assets/img/icons.svg').read_text()
logo='data:image/png;base64,'+base64.b64encode((root/'assets/img/taskflow-logo-192.png').read_bytes()).decode()
mock=r'''() => {
  const member=window.testRole==='member';
  const user={id:member?9:1,name:member?'Jordan Chen':'Morgan Lee',email:'jordan@example.test',roles:[member?'member':'administrator'],team_id:7,team_name:'Studio',avatar_color:'#168dcb',must_change_password:false};
  window.calls=[];
  window.views=[];
  window.projects=[
    {id:3,name:'Website launch',total_tasks:12,done_tasks:8,overdue_tasks:2,due_soon_tasks:3,unassigned_tasks:1,remaining_estimate_minutes:300,milestones_total:3,milestones_complete:1,milestones_late:1,milestones_at_risk:1,progress_percent:67,health:'at_risk'},
    {id:4,name:'Team operations',total_tasks:10,done_tasks:7,overdue_tasks:0,due_soon_tasks:2,unassigned_tasks:0,remaining_estimate_minutes:90,milestones_total:2,milestones_complete:0,milestones_late:0,milestones_at_risk:1,progress_percent:70,health:'attention'},
  ];
  window.tasks=[{id:24,title:'Finalize launch assets',status:'in_progress',priority:'high',list_id:3,list_name:'Website launch',due_date:'2026-11-18',created_by:1,assigned_to_me:true},
    {id:25,title:'QA checklist',status:'to_do',priority:'urgent',list_id:3,list_name:'Website launch',due_date:'2026-11-19',created_by:9,assigned_to_me:false}];
  window.fetch=async(url,options={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const params=new URL(String(url),'http://localhost/').searchParams;
    const method=options.headers?.['X-HTTP-Method-Override']||options.method||'GET';
    const body=options.body?JSON.parse(options.body):{};
    window.calls.push({name,method,body,params:Object.fromEntries(params.entries())});
    let data={};
    if(name==='me.php')data={user,csrf_token:'test-csrf',organization_name:'Northstar Studio',teams:[{id:7,name:'Studio'}]};
    if(name==='lists.php')data={lists:[{id:3,team_id:7,name:'Website launch'},{id:4,team_id:7,name:'Team operations'}]};
    if(name==='tags.php')data={tags:[]};
    if(name==='users.php')data={users:[user]};
    if(name==='portfolio.php')data={projects:window.projects,next_after_id:null};
    if(name==='saved_views.php'){
      if(method==='POST'){
        const id=100+window.views.length;
        if(body.is_default)window.views.forEach(v=>v.is_default=0);
        window.views.push({id,...body,is_default:body.is_default?1:0,mine:body.mine?1:0});data={id,ok:true};
      }else if(method==='PUT'){
        if(body.is_default)window.views.forEach(v=>v.is_default=0);
        const item=window.views.find(v=>v.id===body.id);if(item)item.is_default=body.is_default?1:0;data={ok:true};
      }else if(method==='DELETE'){
        window.views=window.views.filter(v=>v.id!==body.id);data={ok:true};
      }else data={views:window.views};
    }
    if(name==='tasks.php')data={tasks:window.tasks.map(t=>({...t,assignees:[],tags:[],subtasks:{total:0,done:0},unfinished_blockers:0})),next_offset:null};
    if(name==='search.php'){
      const q=params.get('q')||'';
      const filtered=window.tasks.filter(t=>(t.title.toLowerCase().includes(q.toLowerCase()))
        &&(!params.get('list_id')||t.list_id===Number(params.get('list_id')))
        &&(params.get('priority')==='all'||t.priority===params.get('priority')));
      data={tasks:filtered,next_before_id:null};
    }
    if(name==='notifications.php')data={notifications:[],last_id:0};
    if(name==='task.php')data={task:{...window.tasks[0],team_id:7,created_by:1,assignees:[],tags:[],checklist:[],subtasks:[],comments:[],recent_activity:[],dependencies:{depends_on:[],blocks:[]},unfinished_blockers:0,is_favorite:false,is_recurring_weekly:0}};
    if(name==='worklog.php')data={minutes_total:0,entries:[],can_log:false,can_view_team:false,week_locked:false};
    if(name==='timer.php')data={active:null,server_now:new Date().toISOString()};
    return {ok:true,status:200,json:async()=>data};
  };
}'''

def load(page,role='administrator'):
    page.set_content(html.replace('id="v2-tools-drawer" hidden','id="v2-tools-drawer"'))
    page.add_style_tag(content=css)
    page.evaluate("""asset=>{
      document.body.insertAdjacentHTML('afterbegin',asset.icons);
      document.querySelectorAll('use[href^="assets/img/icons.svg#"]').forEach(e=>e.setAttribute('href','#'+e.getAttribute('href').split('#').pop()));
      document.querySelectorAll('img[src^="assets/img/"]').forEach(e=>e.src=asset.logo);
    }""",{'icons':icons,'logo':logo})
    page.evaluate('window.testRole='+repr(role))
    page.evaluate(mock)
    page.add_script_tag(content=script)
    page.wait_for_selector('#app:not(.hidden)')

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    page=browser.new_page(viewport={'width':1440,'height':940})
    page.set_default_timeout(12000)
    page.on('pageerror',lambda e:errors.append(str(e)))
    load(page)
    page.locator('[data-view="portfolio"]').click()
    page.wait_for_selector('.portfolio-card')
    assert page.locator('.portfolio-card').count()==2
    assert page.locator('.portfolio-health-at_risk').count()==1
    assert page.locator('[href*="portfolio_export.php"]').count()==1
    print('PASS: team portfolio shows calculated health and manager CSV action')
    page.screenshot(path=str(root/'docs/screenshots/portfolio.png'),full_page=True)
    page.locator('[data-portfolio-list="3"]').click()
    page.wait_for_function("document.querySelector('#view-title')?.textContent==='Tasks'")
    assert page.evaluate('document.querySelector("#view-title").textContent')=='Tasks'
    print('PASS: portfolio opens the underlying task list')
    page.locator('[data-view="search"]').click()
    page.wait_for_selector('#task-search-saved')
    page.locator('#task-search-q').fill('launch')
    page.locator('#task-search-list').select_option('3')
    page.locator('#task-search-priority').select_option('high')
    page.locator('#task-search-form button[type="submit"]').click()
    page.wait_for_function("window.calls.some(c=>c.name==='search.php'&&c.params.list_id==='3'&&c.params.priority==='high'&&c.params.q==='launch')")
    print('PASS: task search respects list, priority and text filters')
    page.locator('#task-search-save').click()
    page.wait_for_selector('#saved-search-name')
    page.locator('#saved-search-name').fill('High-priority launch')
    page.locator('#saved-search-default').check()
    page.locator('#prompt-confirm').click()
    page.wait_for_function('window.views.length===1')
    page.wait_for_function("document.querySelector('#task-search-saved')?.value==='100'")
    assert page.evaluate("window.views[0].list_id==='3'")
    assert page.evaluate("window.calls.some(c=>c.name==='saved_views.php' && c.method==='POST' && !!c.body.is_default && c.body.team_id===7)")
    print('PASS: save personal task search with default and team context')
    page.locator('[data-view="search"]').click()
    page.wait_for_function("document.querySelector('#task-search-q')?.value==='launch'")
    assert page.locator('#task-search-priority').input_value()=='high'
    page.screenshot(path=str(root/'docs/screenshots/saved-searches.png'),full_page=True)
    print('PASS: default saved search reloads on return')
    page.locator('#task-search-delete').click()
    page.wait_for_selector('#prompt-confirm')
    page.locator('#prompt-confirm').click()
    page.wait_for_function('window.views.length===0')
    print('PASS: delete personal saved search')
    page.evaluate('window.projects[0].name = "Launch\\\" onmouseover=\\\"alert(1)"')
    page.locator('[data-view="portfolio"]').click()
    page.wait_for_selector('.portfolio-card')
    assert page.locator('.portfolio-card [onmouseover]').count()==0
    print('PASS: team list titles are safely escaped')
    member=browser.new_page(viewport={'width':390,'height':844},device_scale_factor=1)
    member.set_default_timeout(12000)
    member.on('pageerror',lambda e:errors.append(str(e)))
    load(member,'member')
    member.locator('#mobile-menu-btn').click()
    member.locator('[data-view="portfolio"]').click()
    member.wait_for_selector('.portfolio-card')
    member.wait_for_function("!document.querySelector('.sidebar').classList.contains('open')")
    member.wait_for_timeout(350)
    assert member.locator('[href*="portfolio_export.php"]').count()==0
    width=member.evaluate('document.documentElement.scrollWidth')
    assert width<=390,f'Mobile portfolio overflows {width}px'
    member.screenshot(path=str(root/'docs/screenshots/portfolio-mobile.png'),full_page=True)
    print('PASS: member sees portfolio without export and no 390px overflow')
    member.locator('#mobile-menu-btn').click()
    member.locator('[data-view="search"]').click()
    member.wait_for_selector('#task-search-saved')
    member.wait_for_function("!document.querySelector('.sidebar').classList.contains('open')")
    member.wait_for_timeout(350)
    width=member.evaluate('document.documentElement.scrollWidth')
    assert width<=390,f'Mobile saved search overflows {width}px'
    member.screenshot(path=str(root/'docs/screenshots/saved-searches-mobile.png'),full_page=True)
    print('PASS: mobile saved-search controls fit viewport')
    if errors:raise AssertionError('Browser errors: '+repr(errors))
    browser.close()
print('All v1.7.0 portfolio and saved-search UI smoke scenarios passed (mocked API).')
