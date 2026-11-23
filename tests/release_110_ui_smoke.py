"""TaskFlow 1.10 browser acceptance with MOCKED APIs only; not a database/security integration test."""
from pathlib import Path
import re,base64
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parent.parent
html='<!DOCTYPE html>'+(root/'index.php').read_text().split('<!DOCTYPE html>',1)[1]
html=re.sub(r'<\?php.*?\?>','',html,flags=re.S)
html=re.sub(r'<link rel="stylesheet"[^>]*>','',html)
html=re.sub(r'<script src="[^"]*">\s*</script>','',html)
css=(root/'assets/css/style.css').read_text()+'\n'+(root/'assets/css/design-system.css').read_text()+'\n'+(root/'assets/css/ui-overhaul.css').read_text()+'\n'+(root/'assets/css/theme-accessibility.css').read_text()+'\n'+(root/'assets/css/theme-palettes.css').read_text()+'\n'+(root/'assets/css/workspace.css').read_text()+'\n'+(root/'assets/css/v3-refinement.css').read_text()+'\n'+(root/'assets/css/v3-compact.css').read_text()
app=(root/'assets/js/app.js').read_text()
logo='data:image/png;base64,'+base64.b64encode((root/'assets/img/taskflow-logo-192.png').read_bytes()).decode()
icons=(root/'assets/img/icons.svg').read_text()
jsmock=r'''() => {
  const member=window.testRole==='member';
  const user={id:member?9:1,name:member?'Dev Member':'Alex Admin',email:'admin@example.test',roles:[member?'member':'administrator'],team_id:7,team_name:'Studio',avatar_color:'#158fbd',must_change_password:false};
  window.calls=[];window.deleted=[];
  window.fetch=async(url,opts={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const params=new URL(String(url),'http://localhost/').searchParams;
    const method=opts.headers?.['X-HTTP-Method-Override'] || opts.method || 'GET';
    window.calls.push({name,method,params:Object.fromEntries(params.entries()),body:opts.body});
    let data={};
    if(name==='me.php')data={user,csrf_token:'csrf-test',organization_name:'Studio',teams:[{id:7,name:'Studio'}]};
    if(name==='lists.php')data={lists:[{id:3,team_id:7,name:'Launch Plan'}]};
    if(name==='tags.php')data={tags:[]};
    if(name==='users.php')data={users:[user]};
    if(name==='tasks.php')data={tasks:[],next_offset:null};
    if(name==='templates.php')data={templates:[]};
    if(name==='notifications.php')data={notifications:[],last_id:0};
    if(name==='my_work.php')data={tasks:[],next_before_id:null};
    if(name==='timeline.php')data={window:{start:'2026-11-01',end:'2026-12-26',days:56},
      tasks:[{id:24,title:'Draft rollout',start_date:'2026-11-02',due_date:'2026-11-30',status:'to_do',priority:'normal',list_id:3,list_name:'Launch Plan',created_by:1}],milestones:[],next_after_id:null};
    if(name==='task.php'){
      if(method==='GET')data={task:{id:24,title:'Draft rollout',description:'Task notes',start_date:'2026-11-02',due_date:'2026-11-30',team_id:7,list_id:3,created_by:1,status:'to_do',priority:'normal',recurrence_rule:'monthly',is_recurring_weekly:0,assignees:[],tags:[],checklist:[],subtasks:[],comments:[],recent_activity:[],dependencies:{depends_on:[],blocks:[]},unfinished_blockers:0,is_favorite:false,is_watching:false,watcher_count:0}};
      else data={ok:true};
    }
    if(name==='worklog.php')data={entries:[],minutes_total:0,can_log:false,can_view_team:false,week_locked:false};
    if(name==='timer.php')data={active:null,server_now:new Date().toISOString()};
    if(name==='attachments.php'){
      if(method==='GET')data={attachments:[{id:73,filename:'design-notes.pdf',size_bytes:15400,created_at:'2026-11-16 10:00:00',uploader:'Alex Admin',uploaded_by:1}]};
      else {window.deleted.push(method);data={ok:true};}
    }
    if(name==='activity.php'){
      const earlier=params.has('before_id');
      data={activity:[{id:earlier?12:22,action:'task_updated',detail:'Release checklist',created_at:'2026-11-16 10:00:00',task_id:24,name:'Alex Admin',team_name:'Studio',avatar_color:'#158fbd'}],next_before_id:earlier?null:20};
    }
    return {ok:true,status:200,json:async()=>data};
  };
}'''
def boot(page,role='administrator'):
    page.set_content(html.replace('id="v2-tools-drawer" hidden','id="v2-tools-drawer"'));page.add_style_tag(content=css)
    page.evaluate('(a)=>{document.body.insertAdjacentHTML("afterbegin",a.icons);document.querySelectorAll("use[href^=\'assets/img/icons.svg#\']").forEach(u=>u.setAttribute("href","#"+u.getAttribute("href").split("#").pop()));document.querySelectorAll("img[src^=\'assets/img/\']").forEach(i=>i.src=a.logo);}',{'icons':icons,'logo':logo})
    page.evaluate('window.testRole='+repr(role));page.evaluate(jsmock)
    page.add_script_tag(content=app)
    page.wait_for_selector('#app:not(.hidden)')
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    page=browser.new_page(viewport={'width':1440,'height':940});page.set_default_timeout(12000);page.on('pageerror',lambda e:errors.append(str(e)))
    page.on('dialog',lambda d:d.accept());boot(page)
    page.locator('[data-view="timeline"]').click();page.wait_for_selector('[data-timeline-open="24"]')
    page.locator('[data-timeline-open="24"]').first.click();page.wait_for_selector('#tm-tab-files')
    page.locator('#tm-tab-files').click();page.wait_for_selector('#tm-attachments .attachment-item')
    assert page.locator('#tm-repeat-rule').input_value()=='monthly'
    assert page.locator('#tm-attachments a[href="api/attachments.php?download_id=73"]').count()==1
    assert page.locator('#tm-attachments .attachment-item').inner_text().find('design-notes.pdf')>=0
    assert page.locator('#tm-attachment-form input[type=file]').count()==1
    page.locator('#tm-attachments').scroll_into_view_if_needed()
    page.screenshot(path=str(root/'docs/screenshots/task-files.png'),full_page=True)
    page.locator('#tm-tab-details').click()
    page.locator('#tm-repeat-rule').select_option('biweekly');page.locator('#tm-save').click()
    page.wait_for_function("window.calls.some(c=>c.name==='task.php'&&c.method==='PUT'&&JSON.parse(c.body).recurrence_rule==='biweekly')")
    print('PASS: task details show private attachment link, upload control and recurrence selector')
    page.locator('[data-view="org-activity"]').click();page.wait_for_selector('#activity-results .activity-item')
    assert page.locator('#activity-export').is_visible()
    page.locator('#activity-action').fill('task_updated');page.locator('#activity-actor').fill('1')
    page.locator('#activity-filter').click();page.wait_for_function("window.calls.some(c=>c.name==='activity.php'&&c.params.action==='task_updated'&&c.params.user_id==='1')")
    page.locator('#activity-more').click();page.wait_for_function("window.calls.some(c=>c.name==='activity.php'&&c.params.before_id==='20')")
    assert page.locator('#activity-results .activity-item').count()==2
    page.screenshot(path=str(root/'docs/screenshots/audit-history.png'),full_page=True)
    print('PASS: audit feed supports actor/action filters and cursor pagination')
    page.locator('[data-view="tasks"]').click();page.wait_for_timeout(150)
    page.locator('#quick-add-task').click();page.wait_for_selector('#at-repeat-rule')
    assert page.locator('#at-repeat-rule option').count()==5
    print('PASS: task creation exposes all supported recurrence schedules')
    mobile=browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True);mobile.set_default_timeout(12000)
    mobile.on('pageerror',lambda e:errors.append(str(e)));boot(mobile,'member')
    mobile.locator('#mobile-menu-btn').click();mobile.locator('[data-view="activity"]').click()
    mobile.wait_for_selector('#activity-results .activity-item')
    assert mobile.locator('#activity-export').count()==0
    assert mobile.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), 'mobile page overflow'
    mobile.screenshot(path=str(root/'docs/screenshots/audit-mobile.png'),full_page=True)
    print('PASS: member has no audit CSV export and mobile audit view fits viewport')
    assert not errors, repr(errors)
    browser.close()
print('1.10 UI suite passed using mocked APIs; live MySQL/HTTP security is untested here.')
