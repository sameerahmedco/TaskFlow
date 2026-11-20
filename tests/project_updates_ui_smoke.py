"""Mocked UI interactions for Project Updates and task watchers; NOT a real MySQL test."""
from pathlib import Path
import base64
import re
from playwright.sync_api import sync_playwright
root = Path(__file__).resolve().parent.parent
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
 const user={id:member?8:1,name:member?'Sam Member':'Taylor Manager',email:'taylor@example.test',roles:[member?'member':'administrator'],team_id:7,team_name:'Product',avatar_color:'#1279df',must_change_password:false};
 window.calls=[];window.updates=[{id:22,list_id:3,list_name:'Website launch',status:'at_risk',summary:'Final QA needs review by Friday.',next_steps:'Ask the QA lead for sign-off.',created_at:'2026-11-15 12:00:00',author_name:'Taylor Manager'}];
 window.watching=false;
 window.fetch=async(url,options={})=>{
   const name=String(url).split('?')[0].split('/').pop();
   const params=new URL(String(url),'http://localhost/').searchParams;
   const method=options.headers?.['X-HTTP-Method-Override']||options.method||'GET';
   const body=options.body?JSON.parse(options.body):{};
   window.calls.push({name,method,body});
   let data={};
   if(name==='me.php')data={user,csrf_token:'test-csrf',organization_name:'Fictional Studio',teams:[{id:7,name:'Product'}]};
   if(name==='lists.php')data={lists:[{id:3,name:'Website launch',team_id:7},{id:4,name:'Operations',team_id:7}]};
   if(name==='tags.php')data={tags:[]};
   if(name==='users.php')data={users:[user]};
   if(name==='notifications.php')data={notifications:[],last_id:0};
   if(name==='project_updates.php'){
     if(method==='POST'){
       const next={...body,id:window.updates.length+40,list_name:body.list_id===3?'Website launch':'Operations',author_name:user.name,created_at:'2026-11-16 13:00:00'};
       window.updates.unshift(next);data={ok:true,id:next.id};
     }else data={updates:window.updates.filter(x=>!params.get('list_id')||Number(x.list_id)===Number(params.get('list_id'))),next_before_id:null};
   }
   if(name==='task.php') data={task:{id:28,team_id:7,list_id:3,title:'Finalize launch',description:'',status:'in_progress',priority:'high',created_by:1,assignees:[],tags:[],checklist:[],subtasks:[],comments:[],recent_activity:[],dependencies:{depends_on:[],blocks:[]},unfinished_blockers:0,is_favorite:false,is_watching:window.watching,watcher_count:window.watching?1:0,estimate_minutes:null,start_date:null,due_date:null}};
   if(name==='task_watch.php') {window.watching=method==='POST';data={ok:true,is_watching:window.watching,watcher_count:window.watching?1:0};}
   if(name==='timer.php')data={active:null,server_now:new Date().toISOString()};
   if(name==='worklog.php')data={entries:[],minutes_total:0,can_log:false,can_view_team:false,week_locked:false};
   return {ok:true,status:200,json:async()=>data};
 };
}'''

def load(page,role='administrator'):
    page.set_content(html.replace('id="v2-tools-drawer" hidden','id="v2-tools-drawer"'))
    page.add_style_tag(content=css)
    page.evaluate('''asset=>{
      document.body.insertAdjacentHTML('afterbegin',asset.icons);
      document.querySelectorAll('use[href^="assets/img/icons.svg#"]').forEach(e=>e.setAttribute('href','#'+e.getAttribute('href').split('#').pop()));
      document.querySelectorAll('img[src^="assets/img/"]').forEach(e=>e.src=asset.logo);
    }''',{'icons':icons,'logo':logo})
    page.evaluate('window.testRole='+repr(role))
    page.evaluate(mock)
    page.add_script_tag(content=script)
    page.wait_for_selector('#app:not(.hidden)')

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    page=browser.new_page(viewport={'width':1440,'height':1150})
    page.on('pageerror',lambda err:errors.append(str(err)))
    load(page)
    page.locator('[data-view="project-updates"]').click()
    page.wait_for_selector('.project-update-card')
    assert page.locator('.project-update-card').count()==1
    assert page.locator('.project-update-at_risk').count()==1
    print('PASS manager can view project status feed')
    page.locator('#pu-list').select_option('4')
    page.locator('#pu-status').select_option('on_track')
    page.locator('#pu-summary').fill('Draft homepage ready for the first review.')
    page.locator('#pu-next').fill('Share preview link with the team.')
    page.locator('#project-update-form button[type="submit"]').click()
    page.wait_for_function('window.updates.length===2')
    page.wait_for_function('document.querySelector("#pu-filter")?.value==="4"')
    assert page.locator('.project-update-card').count()==1
    assert page.locator('.project-update-card h3').inner_text()=='Operations'
    assert page.evaluate("window.calls.some(c=>c.name==='project_updates.php'&&c.method==='POST'&&c.body.team_id===7&&c.body.list_id===4)")
    print('PASS manager can publish team-scoped project update')
    page.locator('#pu-filter').select_option('')
    page.wait_for_function('document.querySelectorAll(".project-update-card").length===2')
    page.wait_for_timeout(350)
    page.screenshot(path=str(root/'docs/screenshots/project-updates.png'),full_page=True)
    print('PASS filter shows project update history')
    # HTML input must be escaped in the status feed.
    page.evaluate('window.updates[0].summary = "<img src=x onerror=alert(1)>"')
    page.locator('#pu-refresh').click()
    page.wait_for_selector('.project-update-card')
    assert page.locator('.project-update-card img').count()==0
    print('PASS update text is escaped, not interpreted as markup')
    # Open a task from an existing UI callback exposed by tests.
    page.evaluate('''() => {
      const b=document.createElement('button'); b.id='test-task-open';b.textContent='Open task';
      document.body.appendChild(b);b.addEventListener('click',()=>{
        document.querySelector('[data-view="tasks"]').click();
      });
    }''')
    # Task modal opens naturally by clicking a task card, but the mock page can also
    # use the application's delegated click if an existing screen has a card.
    # Trigger via data task card on the board with a mocked API response.
    page.locator('[data-view="project-updates"]').click()
    page.wait_for_selector('.project-update-card')
    mobile=browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
    mobile.on('pageerror',lambda err:errors.append(str(err)))
    load(mobile,'member')
    mobile.locator('#mobile-menu-btn').click()
    mobile.locator('[data-view="project-updates"]').click()
    mobile.wait_for_selector('.project-update-card')
    assert mobile.locator('#project-update-form').count()==0
    assert mobile.evaluate('document.documentElement.scrollWidth<=window.innerWidth+2')
    mobile.wait_for_timeout(450)  # allow sidebar exit animation to finish
    mobile.screenshot(path=str(root/'docs/screenshots/project-updates-mobile.png'),full_page=True)
    print('PASS member sees read-only updates on narrow mobile layout')
    # The task detail watcher toggle is present even for read-only task members.
    # Route through a built-in task-list result by exposing a simple task mock.
    mobile.evaluate('''() => {
      window.fetchOriginal=window.fetch;
      window.fetch=async(url,options={})=>{
        const name=String(url).split('?')[0].split('/').pop();
        if(name==='tasks.php')return {ok:true,status:200,json:async()=>({tasks:[{id:28,title:'Finalize launch',status:'in_progress',priority:'high',list_id:3,assignees:[],tags:[],subtasks:{total:0,done:0}}],next_offset:null})};
        return window.fetchOriginal(url,options);
      }
    }''')
    mobile.locator('#mobile-menu-btn').click()
    mobile.locator('[data-view="tasks"]').click()
    mobile.wait_for_timeout(300)
    # Alternative route: click the rendered task row if present.
    rows=mobile.locator('[data-task-id="28"], [data-open-task="28"]')
    if rows.count():
        rows.first.click()
    else:
        # Some views render compact task controls; look for title.
        candidates=mobile.get_by_text('Finalize launch',exact=True)
        if candidates.count(): candidates.first.click()
    if mobile.locator('#task-modal:not(.hidden) #tm-watch').count():
        mobile.locator('#tm-watch').click()
        mobile.wait_for_function('window.watching===true')
        assert mobile.locator('#tm-watch').get_attribute('aria-pressed')=='true'
        mobile.locator('#tm-watch').click()
        mobile.wait_for_function('window.watching===false')
        mobile.screenshot(path=str(root/'docs/screenshots/task-watching-mobile.png'),full_page=True)
        print('PASS task watcher toggle and unsubscribe')
    else:
        raise AssertionError('Could not reach the task watcher button through tasks UI')
    assert not errors, 'Browser console errors: '+str(errors)
    browser.close()
print('TaskFlow 1.8.0 UI smoke checks passed (mocked API; not a MySQL test).')
