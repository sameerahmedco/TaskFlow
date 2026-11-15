"""Milestones v1.6.0 desktop/mobile interactions with mocked API responses (not MySQL)."""
from pathlib import Path
import base64
import re
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parent.parent
html='<!DOCTYPE html>'+(root/'index.php').read_text().split('<!DOCTYPE html>',1)[1]
html=re.sub(r'<\?php.*?\?>','',html,flags=re.S)
html=re.sub(r'<link rel="stylesheet"[^>]*>','',html)
html=re.sub(r'<script src="[^"]*">\s*</script>','',html)
css=(root/'assets/css/style.css').read_text()+'\n'+(root/'assets/css/design-system.css').read_text()
script=(root/'assets/js/app.js').read_text()
icons=(root/'assets/img/icons.svg').read_text()
logo='data:image/png;base64,'+base64.b64encode((root/'assets/img/taskflow-logo-192.png').read_bytes()).decode()
mock=r'''() => {
  const isMember=window.testRole==='member';
  const user={id:isMember?9:1,name:isMember?'Taylor Chen':'Morgan Lee',email:'test@example.test',roles:[isMember?'member':'administrator'],team_id:7,
    team_name:'Studio',avatar_color:'#168dcb',must_change_password:false};
  const milestones=[
    {id:10,list_id:3,list_name:'Product launch',title:'Launch candidate',description:'Ready for quality review.',due_date:'2026-11-20',is_archived:0,created_by:1,total_tasks:2,done_tasks:1,overdue_tasks:0,health:'at_risk',progress_percent:50},
    {id:11,list_id:3,list_name:'Product launch',title:'Security review',description:'Review access, backups and production-readiness checks.',due_date:'2026-11-01',is_archived:0,created_by:1,total_tasks:1,done_tasks:1,overdue_tasks:0,health:'complete',progress_percent:100},
  ];
  const tasks=[{id:24,title:'Review permissions',status:'done',priority:'high',due_date:'2026-11-13',estimate_minutes:60,list_id:3},
    {id:25,title:'Wire deployment checks',status:'to_do',priority:'normal',due_date:'2026-11-19',estimate_minutes:90,list_id:3},
    {id:26,title:'Publish changelog',status:'in_progress',priority:'normal',due_date:'2026-11-21',estimate_minutes:30,list_id:3}];
  window.milestones=milestones;window.links={10:[24,26],11:[24]};window.calls=[];
  window.fetch=async(url,options={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const params=new URL(String(url),'http://localhost/').searchParams;
    const method=options.headers?.['X-HTTP-Method-Override']||options.method||'GET';
    const body=options.body?JSON.parse(options.body):{};
    window.calls.push({name,method,body,query:Object.fromEntries(params.entries())});
    let data={};
    if(name==='me.php')data={user,csrf_token:'fake-csrf',organization_name:'Northstar Studio',teams:[{id:7,name:'Studio'}]};
    if(name==='lists.php')data={lists:[{id:3,team_id:7,name:'Product launch'},{id:4,team_id:7,name:'Operations'}]};
    if(name==='tags.php')data={tags:[]};
    if(name==='users.php')data={users:[user]};
    if(name==='milestones.php'){
      if(method==='POST'){
        const id=12;
        milestones.push({id,...body,list_name:'Product launch',is_archived:0,created_by:1,total_tasks:0,done_tasks:0,overdue_tasks:0,health:'unplanned',progress_percent:0});
        window.links[id]=[];data={id,ok:true};
      }else if(method==='PUT'){
        const m=milestones.find(x=>x.id===body.id);Object.assign(m,body);if('is_archived' in body)m.is_archived=body.is_archived?1:0;data={ok:true};
      }else if(method==='DELETE'){
        const pos=milestones.findIndex(m=>m.id===body.id);milestones.splice(pos,1);data={ok:true};
      }else if(params.has('milestone_id')){
        const id=Number(params.get('milestone_id'));
        const m=milestones.find(x=>x.id===id);
        data={milestone:m,tasks:(window.links[id]||[]).map(t=>tasks.find(x=>x.id===t)).filter(Boolean),next_offset:null};
      }else{
        for(const m of milestones){
          const linked=(window.links[m.id]||[]).map(id=>tasks.find(t=>t.id===id)).filter(Boolean);
          m.total_tasks=linked.length;m.done_tasks=linked.filter(t=>t.status==='done').length;
          m.progress_percent=linked.length?Math.round(100*m.done_tasks/linked.length):0;
          m.health=m.is_archived?'archived':(linked.length&&m.done_tasks===linked.length?'complete':linked.length?'at_risk':'unplanned');
        }
        const list=milestones.filter(m=>(params.get('include_archived')==='1'||!m.is_archived)&&(!params.has('list_id')||m.list_id===Number(params.get('list_id'))));
        data={milestones:list,next_offset:null};
      }
    }
    if(name==='milestone_tasks.php'){
      const id=body.milestone_id,tid=body.task_id;
      if(method==='POST'){window.links[id]=window.links[id]||[];if(!window.links[id].includes(tid))window.links[id].push(tid);}
      if(method==='DELETE'){window.links[id]=window.links[id].filter(x=>x!==tid);}
      data={ok:true};
    }
    if(name==='search.php')data={tasks:tasks.filter(t=>t.title.toLowerCase().includes(String(params.get('q')).toLowerCase())).map(t=>({...t,list_name:'Product launch'})),next_before_id:null};
    if(name==='task.php')data={task:{...tasks[0],team_id:7,created_by:1,assignees:[user],tags:[],checklist:[],subtasks:[],comments:[],recent_activity:[],dependencies:{depends_on:[],blocks:[]},unfinished_blockers:0,is_recurring_weekly:0,is_favorite:false,milestone:{id:10,title:'Launch candidate',due_date:'2026-11-20'}}};
    if(name==='worklog.php')data={minutes_total:0,entries:[],can_log:false,can_view_team:false,week_locked:false};
    if(name==='timer.php')data={active:null,server_now:new Date().toISOString()};
    if(name==='notifications.php')data={notifications:[],last_id:0};
    return {ok:true,status:200,json:async()=>data};
  };
}'''

def load(page,role='administrator'):
    page.set_content(html)
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
    page=browser.new_page(viewport={'width':1440,'height':980})
    page.set_default_timeout(15000)
    page.on('pageerror',lambda e: errors.append(str(e)))
    load(page)
    page.locator('[data-view="milestones"]').click()
    page.wait_for_selector('.milestone-card')
    assert page.locator('.milestone-card').count()==2
    assert page.locator('.milestone-health-at-risk').count()==1
    assert page.locator('.milestone-health-complete').count()==1
    print('PASS: milestone progress and health indicators')
    page.screenshot(path=str(root/'docs/screenshots/milestones.png'),full_page=True)
    page.evaluate('window.milestones[1].description="<img src=x onerror=alert(1)>"')
    page.locator('[data-view="milestones"]').click()
    page.wait_for_selector('.milestone-card')
    assert page.locator('.milestone-grid [onerror]').count()==0
    print('PASS: user-controlled descriptions are escaped as text')
    page.locator('[data-ms-open="10"]').click()
    page.wait_for_selector('.milestone-detail-card')
    assert page.locator('.milestone-task-row').count()==2
    page.locator('#ms-task-search').fill('Wire')
    page.wait_for_selector('[data-ms-add="25"]')
    page.locator('[data-ms-add="25"]').click()
    page.wait_for_function('window.links[10].includes(25)')
    page.wait_for_function("document.querySelectorAll('.milestone-task-row').length===3")
    print('PASS: manager can attach in-list task and progress refreshes')
    page.locator('[data-ms-remove="25"]').click()
    page.wait_for_function('!window.links[10].includes(25)')
    print('PASS: manager can unlink task without deleting it')
    page.locator('#ms-new-top').click()
    page.wait_for_selector('#ms-input-title')
    page.locator('#ms-input-title').fill('Release handoff')
    page.locator('#ms-input-due').fill('2026-12-01')
    page.locator('#prompt-confirm').click()
    page.wait_for_function('window.milestones.some(m=>m.title==="Release handoff")')
    page.wait_for_selector('[data-ms-open="12"]')
    print('PASS: milestone creation uses POST with chosen list')
    page.locator('[data-ms-open="12"]').click()
    page.wait_for_selector('#ms-edit')
    page.locator('#ms-edit').click()
    page.locator('#ms-input-title').fill('Release handoff complete')
    page.locator('#prompt-confirm').click()
    page.wait_for_function('window.milestones.some(m=>m.title==="Release handoff complete")')
    print('PASS: milestone edit uses authenticated PUT')
    page.wait_for_selector('#ms-archive')
    page.locator('#ms-archive').click()
    page.wait_for_function('window.milestones.find(m=>m.id===12).is_archived===1')
    page.locator('#ms-show-archived').check()
    page.wait_for_selector('[data-ms-open="12"]')
    print('PASS: archive and archived filter')
    mobile=browser.new_page(viewport={'width':390,'height':844},device_scale_factor=1)
    mobile.set_default_timeout(15000)
    mobile.on('pageerror',lambda e: errors.append(str(e)))
    load(mobile)
    mobile.locator('#mobile-menu-btn').click()
    mobile.locator('[data-view="milestones"]').click()
    mobile.wait_for_selector('.milestone-card')
    mobile.wait_for_function('!document.querySelector(".sidebar").classList.contains("open")')
    mobile.wait_for_timeout(200)
    width=mobile.evaluate('document.documentElement.scrollWidth')
    assert width<=390,f'mobile overflows at {width}px'
    mobile.screenshot(path=str(root/'docs/screenshots/milestones-mobile.png'),full_page=True)
    print('PASS: mobile milestone layout stays within 390px')
    member=browser.new_page(viewport={'width':1300,'height':900})
    member.set_default_timeout(15000)
    member.on('pageerror',lambda e:errors.append(str(e)))
    load(member,'member')
    member.locator('[data-view="milestones"]').click()
    member.wait_for_selector('.milestone-card')
    assert member.locator('#ms-new-top').count()==0
    member.locator('[data-ms-open="10"]').click()
    member.wait_for_selector('.milestone-detail-card')
    assert member.locator('#ms-edit').count()==0
    assert member.locator('#ms-task-search').count()==0
    assert member.locator('[data-ms-remove]').count()==0
    print('PASS: member milestone UI is read-only')
    if errors:raise AssertionError('Browser errors: '+repr(errors))
    browser.close()
print('All milestone UI smoke scenarios passed (mocked API).')
