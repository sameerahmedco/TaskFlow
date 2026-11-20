"""TaskFlow v1.18.0 project risks, portfolio and mobile authorization mock browser checks.
Uses synthetic responses; does not test SQL authorization or real MySQL.
"""
from pathlib import Path
import re, base64
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
html='<!DOCTYPE html>'+(ROOT/'index.php').read_text().split('<!DOCTYPE html>',1)[1]
html=re.sub(r'<\?php.*?\?>','',html,flags=re.S)
html=re.sub(r'<link rel="stylesheet"[^>]*>','',html)
html=re.sub(r'<script src="[^"]*">\s*</script>','',html)
css=(ROOT/'assets/css/style.css').read_text()+'\n'+(ROOT/'assets/css/design-system.css').read_text()+'\n'+(ROOT/'assets/css/ui-overhaul.css').read_text()+'\n'+(ROOT/'assets/css/theme-accessibility.css').read_text()+'\n'+(ROOT/'assets/css/theme-palettes.css').read_text()+'\n'+(ROOT/'assets/css/v2-workspace.css').read_text()
js=(ROOT/'assets/js/app.js').read_text()
icons=(ROOT/'assets/img/icons.svg').read_text()
logo='data:image/png;base64,'+base64.b64encode((ROOT/'assets/img/taskflow-logo-192.png').read_bytes()).decode()
MOCK=r'''memberMode=>{
  window.calls=[];window.risks=[
    {id:42,team_id:7,list_id:3,project_name:'Website launch',kind:'risk',severity:'critical',status:'open',
     title:'Vendor timeline could delay launch',description:'Supplier confirmation has not arrived.\nThis may affect QA.',
     mitigation:'Confirm revised date and prepare an alternative.',owner_id:1,owner_name:'Morgan Lee',
     due_date:'2026-12-02',reported_name:'Avery Chen',version:1,created_at:'2026-11-16 13:00:00'},
    {id:41,team_id:7,list_id:4,project_name:'Operations',kind:'issue',severity:'medium',status:'mitigating',
     title:'Checklist handover needs review',description:'The current checklist misses a step.',mitigation:'Update the checklist.',
     owner_id:null,owner_name:null,due_date:null,reported_name:'Morgan Lee',version:1,created_at:'2026-11-15 13:00:00'}
  ];
  const admin={id:1,name:'Morgan Lee',email:'m@test.local',roles:['administrator'],team_id:7,avatar_color:'#168acf',is_active:1};
  const member={id:2,name:'Avery Chen',email:'a@test.local',roles:['member'],team_id:7,avatar_color:'#2b8faf',is_active:1};
  const me=memberMode?member:admin;
  window.fetch=async(url,opts={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const query=new URL(String(url),'http://localhost/').searchParams;
    const method=opts.headers?.['X-HTTP-Method-Override']||opts.method||'GET';
    const body=opts.body?JSON.parse(opts.body):{};
    window.calls.push({name,method,body});
    let data={};
    if(name==='me.php')data={user:me,csrf_token:'fake-test-csrf',organization_name:'Northstar Studio',teams:[{id:7,name:'Studio'}]};
    if(name==='lists.php')data={lists:[{id:3,name:'Website launch'},{id:4,name:'Operations'}]};
    if(name==='tags.php')data={tags:[]};
    if(name==='users.php')data={users:[admin,member]};
    if(name==='notifications.php')data={notifications:[],last_id:0};
    if(name==='portfolio.php')data={projects:[{id:3,name:'Website launch',total_tasks:4,done_tasks:2,overdue_tasks:0,due_soon_tasks:0,
      unassigned_tasks:0,remaining_estimate_minutes:180,milestones_total:1,milestones_late:0,
      open_risks:1,critical_risks:1,progress_percent:50,health:'at_risk'}],next_after_id:null};
    if(name==='project_risks.php'){
      if(method==='GET'){
        const filtered=window.risks.filter(r=>(query.get('archived')==='1'?r.archived:!r.archived)
          &&(!query.get('list_id')||r.list_id===Number(query.get('list_id')))
          &&(!query.get('status')||r.status===query.get('status'))
          &&(!query.get('severity')||r.severity===query.get('severity')));
        data={risks:filtered,can_manage:!memberMode,next_before_id:null,summary:{total:window.risks.length,
          active_count:window.risks.filter(r=>!r.archived&&['open','mitigating'].includes(r.status)).length,
          high_count:window.risks.filter(r=>!r.archived&&['open','mitigating'].includes(r.status)&&['high','critical'].includes(r.severity)).length,
          overdue_count:0}};
      }else{
        if(body.action==='create')window.risks.unshift({id:43,team_id:7,list_id:body.list_id,project_name:body.list_id===3?'Website launch':'Operations',
          kind:body.kind,severity:body.severity,status:'open',title:body.title,description:body.description,
          mitigation:body.mitigation,due_date:body.due_date,owner_id:body.owner_id||null,owner_name:null,
          reported_name:me.name,version:1,created_at:'2026-11-17 12:00:00'});
        if(body.action==='update'){
          const r=window.risks.find(r=>r.id===body.id);Object.assign(r,body,{version:r.version+1,owner_name:body.owner_id?'Morgan Lee':null});
        }
        if(body.action==='archive')window.risks.find(r=>r.id===body.id).archived=true;
        if(body.action==='restore')window.risks.find(r=>r.id===body.id).archived=false;
        data={ok:true,id:body.id||43,version:(body.version||0)+1};
      }
    }
    return {ok:true,status:200,json:async()=>data};
  };
}'''
def init(page, member=False):
    page.set_content(html.replace('id="v2-tools-drawer" hidden','id="v2-tools-drawer"'))
    page.add_style_tag(content=css)
    page.evaluate('''assets=>{
      document.body.insertAdjacentHTML('afterbegin',assets.icons);
      document.querySelectorAll('use[href^="assets/img/icons.svg#"]').forEach(u=>u.setAttribute('href','#'+u.getAttribute('href').split('#').pop()));
      document.querySelectorAll('img[src^="assets/img/"]').forEach(i=>i.src=assets.logo);
    }''',{'icons':icons,'logo':logo})
    page.evaluate(MOCK,member)
    page.add_script_tag(content=js)
    page.wait_for_selector('#app:not(.hidden)')

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    admin=browser.new_page(viewport={'width':1366,'height':900})
    admin.set_default_timeout(15000)
    admin.on('pageerror',lambda error:errors.append(str(error)))
    admin.on('dialog',lambda d:d.accept())
    init(admin)
    admin.locator('[data-view="project-risks"]').click()
    admin.wait_for_selector('.risk-card')
    assert admin.locator('.risk-card').count()==2
    assert admin.locator('#risk-summary').inner_text().find('High / critical')>=0
    assert admin.locator('a[href^="api/project_risks_export.php"]').count()==1
    admin.locator('#risk-toggle-create').click()
    admin.locator('#risk-new-title').fill('Check partner delivery')
    admin.locator('#risk-new-description').fill('<img src=x onerror="window.injected=1"> Partner response is pending.')
    admin.locator('#risk-new-kind').select_option('issue')
    admin.locator('#risk-create-form button[type=submit]').click()
    admin.wait_for_function('window.risks.some(r=>r.id===43)')
    admin.wait_for_selector('.risk-card')
    assert admin.locator('.risk-card').count()==3
    assert admin.locator('.risk-card img').count()==0
    assert admin.evaluate('window.injected===undefined')
    assert admin.evaluate('window.calls.some(c=>c.name==="project_risks.php"&&c.body.action==="create"&&c.body.kind==="issue")')
    admin.locator('[data-risk-edit="42"]').click()
    admin.locator('#risk-edit-status').select_option('mitigating')
    admin.locator('#prompt-confirm').click()
    admin.wait_for_function('window.risks.find(r=>r.id===42).status==="mitigating"')
    assert admin.evaluate('window.calls.some(c=>c.name==="project_risks.php"&&c.body.action==="update"&&c.body.version===1)')
    admin.locator('#risk-filter-severity').select_option('critical')
    admin.wait_for_function('document.querySelectorAll(".risk-card").length===1')
    admin.locator('#risk-filter-severity').select_option('')
    admin.wait_for_function('document.querySelectorAll(".risk-card").length===3')
    # Keep malicious payload test above but use realistic copy for documentation screenshot.
    admin.evaluate("window.risks.find(r=>r.id===43).description='Partner response is pending. Confirm the delivery date with the vendor.'")
    admin.locator('#risk-refresh').click()
    admin.evaluate('document.getElementById("view-container").scrollTop=0; window.scrollTo(0,0)')
    admin.wait_for_timeout(550)
    admin.screenshot(path=str(ROOT/'docs/screenshots/project-risks.png'),full_page=True)
    admin.locator('[data-view="portfolio"]').click()
    admin.wait_for_selector('.portfolio-card')
    assert 'Open risks' in admin.locator('.portfolio-card').inner_text()
    admin.locator('[data-portfolio-risks="3"]').click()
    admin.wait_for_selector('.risk-card')
    assert admin.locator('#risk-filter-project').input_value()=='3'
    print('PASS manager create/edit/filter, safe HTML, export and portfolio links')
    member=browser.new_page(viewport={'width':390,'height':844})
    member.set_default_timeout(15000)
    member.on('pageerror',lambda error:errors.append(str(error)))
    init(member,True)
    member.locator('#mobile-menu-btn').click()
    member.locator('[data-view="project-risks"]').click()
    member.wait_for_selector('.risk-card')
    member.wait_for_function('!document.querySelector(".sidebar").classList.contains("open")')
    assert member.locator('[data-risk-edit]').count()==0
    assert member.locator('[data-risk-action]').count()==0
    assert member.locator('a[href^="api/project_risks_export.php"]').count()==0
    member.locator('#risk-toggle-create').click()
    assert member.locator('#risk-new-owner').count()==0
    assert member.locator('#risk-new-status').count()==0
    member.locator('#risk-new-title').fill('New team dependency')
    member.locator('#risk-new-description').fill('An external integration needs final confirmation.')
    member.locator('#risk-create-form button[type=submit]').click()
    member.wait_for_function('window.risks.some(r=>r.id===43)')
    member.wait_for_selector('.risk-card')
    assert member.evaluate('document.documentElement.scrollWidth <= window.innerWidth+1')
    member.evaluate('document.getElementById("view-container").scrollTop=0; window.scrollTo(0,0)')
    member.wait_for_timeout(550)
    member.screenshot(path=str(ROOT/'docs/screenshots/project-risks-mobile.png'),full_page=True)
    print('PASS member reporting, no manager controls and mobile viewport')
    assert not errors,errors
    browser.close()
print('All v1.18.0 risk UI checks passed (mocked APIs).')
