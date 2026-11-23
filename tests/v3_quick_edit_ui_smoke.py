"""TaskFlow v3 — inline task triage and distraction-free workspace browser smoke.
Uses synthetic mocked API responses, not real MySQL/HTTP authorization.
"""
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parent.parent
fixture=ROOT/'tests/design_ui_smoke.py'
ctx={'__file__':str(fixture)}
exec(compile(fixture.read_text().split('with sync_playwright() as p:')[0],str(fixture),'exec'),ctx)
load_ui=ctx['load_ui']; paint_brand=ctx['paint_brand']
contrast_src=ROOT/'tests/theme_contrast_ui_smoke.py'
contrast_ctx={'__file__':str(contrast_src)}
exec(compile(contrast_src.read_text().split('with sync_playwright() as play:')[0],str(contrast_src),'exec'),contrast_ctx)
CHECK_CONTRAST=contrast_ctx['JS']

MOCK_EXTEND=r'''() => {
 const original=window.fetch;
 window.quickCalls=[];
 window.quickRows=[
  {id:24,title:'Review onboarding experience',status:'to_do',priority:'high',due_date:'2026-11-23',created_by:1,
   can_edit:true,team_name:'Product',list_name:'Product launch',assignees:[{id:1,name:'Morgan Lee'}],tags:[],subtasks:{total:0,done:0},unfinished_blockers:0},
  {id:25,title:'Prepare release documentation',status:'review',priority:'normal',due_date:'2026-11-24',created_by:1,
   can_edit:true,team_name:'Product',list_name:'Product launch',assignees:[],tags:[],subtasks:{total:0,done:0},unfinished_blockers:0},
  {id:26,title:'Blocked completion task',status:'to_do',priority:'urgent',due_date:'2026-11-25',created_by:1,
   can_edit:true,team_name:'Product',list_name:'Product launch',assignees:[],tags:[],subtasks:{total:0,done:0},unfinished_blockers:2},
  {id:27,title:'Read-only external work',status:'to_do',priority:'low',due_date:null,created_by:55,
   can_edit:false,team_name:'Product',list_name:'Product launch',assignees:[],tags:[],subtasks:{total:0,done:0},unfinished_blockers:0}
 ];
 window.fetch=async(url,opts={})=>{
  const name=String(url).split('?')[0].split('/').pop();
  const query=new URL(String(url),'http://localhost/').searchParams;
  const method=opts.headers?.['X-HTTP-Method-Override']||opts.method||'GET';
  const body=opts.body?JSON.parse(opts.body):{};
  if(name==='my_work.php'){
    const status=query.get('status')||'open';
    const tasks=window.quickRows.filter(t=>status==='all'||(status==='done'?t.status==='done':t.status!=='done'));
    return {ok:true,status:200,json:async()=>({tasks,next_before_id:null})};
  }
  if(name==='tasks.php'&&method==='GET'){
    const status=query.get('status');
    const tasks=window.quickRows.filter(t=>!status||(status==='pending'?t.status!=='done':t.status==='done'));
    return {ok:true,status:200,json:async()=>({tasks,next_offset:null})};
  }
  if(name==='task.php'&&method==='PATCH'){
    window.quickCalls.push({method,body});
    const task=window.quickRows.find(t=>t.id===body.id);
    if(!task)return {ok:false,status:404,json:async()=>({error:'Task not found.'})};
    if(body.status==='done'&&task.unfinished_blockers)return {ok:false,status:409,json:async()=>({error:'Finish blockers before completing this task.'})};
    Object.assign(task,body);
    return {ok:true,status:200,json:async()=>({ok:true})};
  }
  return original(url,opts);
 };
}'''

with sync_playwright() as p:
 browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
 desktop=browser.new_page(viewport={'width':1440,'height':900})
 desktop.set_default_timeout(16000)
 errors=[];desktop.on('pageerror',lambda error: errors.append(str(error)))
 load_ui(desktop,authenticated=True)
 desktop.evaluate(MOCK_EXTEND)
 desktop.evaluate("""() => document.querySelector('.nav-item[data-view="my-work"]').click()""")
 desktop.wait_for_selector('.v3-work-task[data-task-id="27"]')
 assert desktop.locator('.v3-work-task').count()==4
 assert desktop.locator('.v3-work-task[data-task-id="27"] select').count()==0,'read-only task exposed editable status controls'
 assert desktop.locator('.v3-work-task[data-task-id="24"] select').count()==2
 assert desktop.locator('.v3-work-task-open').count()==4
 desktop.locator('.v3-work-task[data-task-id="24"] select[data-quick-field="status"]').select_option('in_progress')
 desktop.wait_for_function('window.quickRows.find(t=>t.id===24).status==="in_progress"')
 desktop.wait_for_function("""() => document.querySelector('.v3-work-task[data-task-id="24"] select[data-quick-field="status"]')?.value==='in_progress'""")
 desktop.locator('.v3-work-task[data-task-id="25"] select[data-quick-field="priority"]').select_option('urgent')
 desktop.wait_for_function('window.quickRows.find(t=>t.id===25).priority==="urgent"')
 assert desktop.evaluate('window.quickCalls.some(c=>c.body.id===25&&c.body.priority==="urgent"&&c.method==="PATCH")')
 print('PASS Home inline task status and priority saves through PATCH API')
 blocked=desktop.locator('.v3-work-task[data-task-id="26"] select[data-quick-field="status"]')
 blocked.select_option('done')
 desktop.wait_for_function('window.quickCalls.some(c=>c.body.id===26&&c.body.status==="done")')
 desktop.wait_for_function("""() => document.querySelector('.v3-work-task[data-task-id="26"] select[data-quick-field="status"]')?.value==='to_do'""")
 assert desktop.evaluate('window.quickRows.find(t=>t.id===26).status==="to_do"')
 print('PASS backend rejection restores old value without dropping task')
 # Clicking the name opens details; clicking a select never opens the modal.
 desktop.locator('.v3-work-task[data-task-id="25"] select[data-quick-field="priority"]').click()
 assert desktop.locator('#task-modal:not(.hidden)').count()==0
 desktop.locator('.v3-work-task[data-task-id="25"] .v3-work-task-open').click()
 assert desktop.locator('#task-modal:not(.hidden)').count()==1
 desktop.locator('#close-task-modal').click()
 print('PASS keyboard-accessible task title opens details while inline controls do not')
 # Project List editing uses the same API without losing bulk selection semantics.
 desktop.locator('[data-v2-view="tasks"]').click()
 desktop.wait_for_selector('.task-row[data-task="24"]')
 desktop.locator('.task-row[data-task="24"] select[data-quick-field="priority"]').select_option('low')
 desktop.wait_for_function('window.quickRows.find(t=>t.id===24).priority==="low"')
 desktop.wait_for_function("""() => document.querySelector('.task-row[data-task="24"] select[data-quick-field="priority"]')?.value==='low'""")
 desktop.locator('.bulk-row-check').nth(0).check()
 assert desktop.locator('#bulk-count').inner_text()=='1 selected'
 print('PASS Project List inline editing and existing bulk selection')
 desktop.evaluate("""() => document.querySelector('.nav-item[data-view="my-work"]').click()""")
 desktop.wait_for_selector('.v3-work-task')
 assert desktop.locator('#workspace-focus-toggle').is_visible()
 desktop.locator('#workspace-focus-toggle').click()
 assert desktop.locator('#workspace-focus-toggle').get_attribute('aria-pressed')=='true'
 assert desktop.locator('.sidebar').evaluate('(element)=>getComputedStyle(element).display')=='none'
 assert desktop.locator('#quick-switch-btn').is_visible()
 desktop.locator('#quick-switch-btn').click()
 assert desktop.locator('#quick-switch-modal').is_visible()
 desktop.locator('#quick-switch-close').click()
 desktop.locator('#workspace-focus-toggle').click()
 assert desktop.locator('.sidebar').is_visible()
 assert desktop.locator('#workspace-focus-toggle').get_attribute('aria-pressed')=='false'
 print('PASS desktop Focus mode is reversible and global navigation remains available')
 paint_brand(desktop)
 desktop.evaluate("document.getElementById('toast').classList.add('hidden')")
 desktop.screenshot(path=str(ROOT/'docs/screenshots/v3-quick-edit-light.png'),full_page=True)
 for theme in ('light','dark','ocean','forest','lavender','sunset','midnight','charcoal','black'):
  desktop.locator('#appearance-preference').select_option(theme)
  desktop.wait_for_timeout(90)
  for selector in ('.v3-work-task .task-quick-select', '.v3-work-task strong', '.v3-work-task .task-quick-pill'):
   check=desktop.evaluate(CHECK_CONTRAST,selector)
   assert not check.get('missing') and check['ratio']>=4.5,(theme,selector,check)
 print('PASS 27 selected inline-control contrast checks across Light, Dark and seven custom palettes')
 for theme in ('charcoal','black'):
  desktop.locator('#appearance-preference').select_option(theme)
  desktop.wait_for_timeout(170)
  assert desktop.locator('.v3-work-task select').count()>=4
  for sel in ('.task-quick-select','.v3-work-task strong'):
   result=desktop.locator(sel).first.evaluate('''el=>{const style=getComputedStyle(el);return {color:style.color,bg:style.backgroundColor}}''')
   assert result['color'] not in ('rgb(255, 255, 255)' if theme=='light' else 'rgb(0, 0, 0)',),result
  desktop.screenshot(path=str(ROOT/f'docs/screenshots/v3-quick-edit-{theme}.png'),full_page=True)
 print('PASS quick edits remain available in Charcoal and Black')
 mobile=browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
 mobile.on('pageerror',lambda error:errors.append(str(error)))
 mobile.set_default_timeout(16000)
 load_ui(mobile,authenticated=True)
 mobile.evaluate(MOCK_EXTEND)
 mobile.evaluate("""() => document.querySelector('.nav-item[data-view="my-work"]').click()""")
 mobile.wait_for_selector('.v3-work-task[data-task-id="24"]')
 assert mobile.locator('#workspace-focus-toggle').is_hidden()
 assert mobile.locator('.v3-work-task[data-task-id="24"] select').count()==2
 mobile.locator('.v3-work-task[data-task-id="24"] select[data-quick-field="status"]').select_option('review')
 mobile.wait_for_function('window.quickRows.find(t=>t.id===24).status==="review"')
 assert mobile.evaluate('document.documentElement.scrollWidth <= innerWidth+2')
 mobile.screenshot(path=str(ROOT/'docs/screenshots/v3-quick-edit-mobile.png'),full_page=True)
 print('PASS mobile inline edit, no horizontal overflow, focus toggle intentionally hidden')
 assert not errors,errors
 browser.close()
print('TaskFlow v3 quick-edit and Focus mode UI smoke passed (mocked APIs).')
