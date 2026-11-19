"""TaskFlow 1.9.0 roadmap acceptance using mock responses; NOT a live MySQL security test."""
from pathlib import Path
import base64
import re
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
mock=r'''() => {
  const member=window.testRole==='member';
  const user={id:member?9:1,name:member?'Morgan Porter':'Alex Chen',email:'alex@example.test',roles:[member?'member':'administrator'],team_id:7,team_name:'Studio',avatar_color:'#168dcb',must_change_password:false};
  window.tfCalls=[];
  window.tfTasks=[
   {id:24,title:'Draft launch identity',list_id:3,list_name:'Website launch',start_date:'2026-11-03',due_date:'2026-11-24',status:'in_progress',priority:'high',created_by:1,estimate_minutes:180},
   {id:25,title:'Review analytics rollout',list_id:3,list_name:'Website launch',start_date:'2026-11-18',due_date:'2026-12-15',status:'review',priority:'normal',created_by:9,estimate_minutes:120},
   {id:26,title:'Prepare team training',list_id:4,list_name:'Operations',start_date:'2026-11-15',due_date:'2026-12-18',status:'to_do',priority:'normal',created_by:1,estimate_minutes:240},
  ];
  window.tfMilestones=[{id:8,list_id:3,list_name:'Website launch',title:'Beta launch',due_date:'2026-11-30'},
    {id:9,list_id:4,list_name:'Operations',title:'Staff training',due_date:'2026-12-12'}];
  window.fetch=async(url,options={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const params=new URL(String(url),'http://localhost/').searchParams;
    window.tfCalls.push({name,params:Object.fromEntries(params.entries())});
    let data={};
    if(name==='me.php')data={user,csrf_token:'csrf-test',organization_name:'Northstar Studio',teams:[{id:7,name:'Studio'}]};
    if(name==='lists.php')data={lists:[{id:3,team_id:7,name:'Website launch'},{id:4,team_id:7,name:'Operations'}]};
    if(name==='tags.php')data={tags:[]};
    if(name==='users.php')data={users:[user]};
    if(name==='my_work.php')data={tasks:[],next_before_id:null};
    if(name==='notifications.php')data={notifications:[],last_id:0};
    if(name==='timeline.php'){
      const after=Number(params.get('after_id')||0);
      const list=Number(params.get('list_id')||0);
      const mine=params.get('mine')==='1';
      const tasks=window.tfTasks.filter(t=>t.id>after&&(!list||list===t.list_id)&&(!mine||t.created_by===user.id));
      const first=tasks.slice(0,after?10:2);
      data={window:{start:'2026-11-01',end:params.get('days')==='28'?'2026-11-28':'2026-12-26',days:Number(params.get('days')||56)},
       tasks:first,milestones:after?[]:window.tfMilestones.filter(m=>(!list||m.list_id===list)&&m.due_date<=(params.get('days')==='28'?'2026-11-28':'2026-12-26')), 
       milestones_truncated:false,next_after_id:tasks.length>first.length?first[first.length-1].id:null};
    }
    if(name==='task.php')data={task:{...window.tfTasks[0],team_id:7,assignees:[],tags:[],checklist:[],subtasks:[],comments:[],recent_activity:[],dependencies:{depends_on:[],blocks:[]},unfinished_blockers:0,is_favorite:false,is_watching:false,watcher_count:0}};
    if(name==='worklog.php')data={entries:[],minutes_total:0,can_log:false,can_view_team:false,week_locked:false};
    if(name==='timer.php')data={active:null,server_now:new Date().toISOString()};
    return {ok:true,status:200,json:async()=>data};
  };
}'''

def boot(page,role='administrator'):
    page.set_content(html)
    page.add_style_tag(content=css)
    page.evaluate('''a=>{
      document.body.insertAdjacentHTML('afterbegin',a.icons);
      document.querySelectorAll('use[href^="assets/img/icons.svg#"]').forEach(e=>e.setAttribute('href','#'+e.getAttribute('href').split('#').pop()));
      document.querySelectorAll('img[src^="assets/img/"]').forEach(e=>e.src=a.logo);
    }''', {'icons':icons,'logo':logo})
    page.evaluate('window.testRole='+repr(role))
    page.evaluate(mock)
    page.add_script_tag(content=js)
    page.wait_for_selector('#app:not(.hidden)')

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    page=browser.new_page(viewport={'width':1440,'height':900},device_scale_factor=1)
    page.set_default_timeout(15000)
    page.on('pageerror',lambda e:errors.append(str(e)))
    boot(page)
    page.locator('[data-view="timeline"]').click()
    page.wait_for_selector('.timeline-bar')
    assert page.locator('.timeline-row:not(.timeline-milestone-row)').count()==2
    assert page.locator('.timeline-milestone-row').count()==2
    assert page.locator('#timeline-export').get_attribute('href').startswith('api/calendar_export.php?team_id=7')
    print('PASS: timeline renders team task spans, milestone targets and private calendar download')
    page.locator('#timeline-more-btn').click()
    page.wait_for_function("document.querySelectorAll('.timeline-row:not(.timeline-milestone-row)').length===3")
    print('PASS: keyset pagination loads additional tasks without milestone duplicates')
    page.locator('#timeline-list').select_option('3')
    page.wait_for_function("document.querySelectorAll('.timeline-row:not(.timeline-milestone-row)').length===2")
    assert page.locator('.timeline-milestone-row').count()==1
    print('PASS: project filter limits milestone and task rows')
    page.locator('#timeline-mine').check()
    page.wait_for_function("document.querySelectorAll('.timeline-row:not(.timeline-milestone-row)').length===1")
    assert 'mine=1' in page.locator('#timeline-export').get_attribute('href')
    print('PASS: personal task filter and calendar download parameters stay in sync')
    page.locator('#timeline-days').select_option('28')
    page.wait_for_function("window.tfCalls.some(c=>c.name==='timeline.php'&&c.params.days==='28')")
    assert page.locator('.timeline-ticks span').count()==4
    print('PASS: window selector changes bounded duration')
    page.locator('#timeline-mine').uncheck()
    page.locator('#timeline-days').select_option('56')
    page.locator('#timeline-list').select_option('0')
    page.wait_for_function("document.querySelectorAll('.timeline-row:not(.timeline-milestone-row)').length===2")
    page.screenshot(path=str(root/'docs/screenshots/timeline.png'),full_page=True)
    page.locator('[data-timeline-open="24"]').first.click()
    page.wait_for_selector('#task-modal:not(.hidden)')
    print('PASS: timeline opens task detail')
    page.locator('#close-task-modal').click()
    page.locator('[data-timeline-milestone="8"]').first.click()
    page.wait_for_function("document.querySelector('#view-title')?.textContent==='Milestones'")
    print('PASS: milestone marker routes to existing milestone workspace')
    # Restore the original team screen before testing user-supplied text escaping.
    page.evaluate("window.tfTasks[0].title='Launch \" onmouseover=\"alert(1)' ")
    page.locator('[data-view="timeline"]').click()
    page.wait_for_selector('.timeline-row-link[data-timeline-open="24"]')
    assert page.locator('.timeline-table [onmouseover]').count()==0
    print('PASS: timeline escapes user-supplied titles in HTML and attributes')
    mobile=browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True,device_scale_factor=1)
    mobile.set_default_timeout(15000)
    mobile.on('pageerror',lambda e:errors.append(str(e)))
    boot(mobile,'member')
    mobile.locator('#mobile-menu-btn').click()
    mobile.locator('[data-view="timeline"]').click()
    mobile.wait_for_selector('.timeline-bar')
    mobile.wait_for_timeout(400)
    assert mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth+2')
    assert mobile.locator('.timeline-scroll').evaluate('(e)=>e.scrollWidth>e.clientWidth')
    mobile.screenshot(path=str(root/'docs/screenshots/timeline-mobile.png'),full_page=True)
    print('PASS: mobile timeline scroll stays inside 390px page')
    assert not errors, repr(errors)
    browser.close()
print('TaskFlow 1.9.0 browser suite passed with mocked APIs (not a live database test).')
