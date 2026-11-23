"""v1.19 decisions and portfolio links, mock API browser checks. No real DB."""
from pathlib import Path
import re, base64
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
html='<!DOCTYPE html>'+(ROOT/'index.php').read_text().split('<!DOCTYPE html>',1)[1]
html=re.sub(r'<\?php.*?\?>','',html,flags=re.S)
html=re.sub(r'<link rel="stylesheet"[^>]*>','',html)
html=re.sub(r'<script src="[^"]*">\s*</script>','',html)
css=(ROOT/'assets/css/style.css').read_text()+'\n'+(ROOT/'assets/css/design-system.css').read_text()+'\n'+(ROOT/'assets/css/ui-overhaul.css').read_text()+'\n'+(ROOT/'assets/css/theme-accessibility.css').read_text()+'\n'+(ROOT/'assets/css/theme-palettes.css').read_text()+'\n'+(ROOT/'assets/css/workspace.css').read_text()+'\n'+(ROOT/'assets/css/v3-refinement.css').read_text()+'\n'+(ROOT/'assets/css/v3-compact.css').read_text()
js=(ROOT/'assets/js/app.js').read_text()
icons=(ROOT/'assets/img/icons.svg').read_text()
logo='data:image/png;base64,'+base64.b64encode((ROOT/'assets/img/taskflow-logo-192.png').read_bytes()).decode()
MOCK=r'''memberMode=>{
 window.decisions=[
  {id:74,list_id:3,project_name:'Website launch',reporter_name:'Avery Chen',owner_id:1,owner_name:'Morgan Lee',status:'approved',
   title:'Use managed hosting',context:'We require a reliable EU region.',outcome:'Choose EU-West hosting with dedicated backups.',review_date:'2026-12-07',version:1,created_at:'2026-11-17 12:00'},
  {id:73,list_id:4,project_name:'Operations',reporter_name:'Morgan Lee',owner_id:null,owner_name:null,status:'proposed',
   title:'Refresh quarterly reporting',context:'Current reporting uses too many spreadsheets.',outcome:'',review_date:'2026-12-12',version:1,created_at:'2026-11-15 09:00'}
 ]; window.calls=[];
 const admin={id:1,name:'Morgan Lee',roles:['administrator'],email:'admin@example.test',team_id:7,avatar_color:'#168acf',is_active:1};
 const member={id:2,name:'Avery Chen',roles:['member'],email:'member@example.test',team_id:7,avatar_color:'#2b8faf',is_active:1};
 const me=memberMode?member:admin;
 window.fetch=async(url,opts={})=>{
  const name=String(url).split('?')[0].split('/').pop();
  const params=new URL(String(url),'http://localhost/').searchParams;
  const method=opts.headers?.['X-HTTP-Method-Override']||opts.method||'GET';
  const body=opts.body?JSON.parse(opts.body):{};
  window.calls.push({name,method,body});let data={};
  if(name==='me.php')data={user:me,csrf_token:'mock-csrf',organization_name:'Northstar Studio',teams:[{id:7,name:'Studio'}]};
  if(name==='lists.php')data={lists:[{id:3,name:'Website launch'},{id:4,name:'Operations'}]};
  if(name==='tags.php')data={tags:[]};
  if(name==='users.php')data={users:[admin,member]};
  if(name==='notifications.php')data={notifications:[],last_id:0};
  if(name==='portfolio.php')data={projects:[{id:3,name:'Website launch',total_tasks:4,done_tasks:2,overdue_tasks:0,due_soon_tasks:0,unassigned_tasks:0,
   remaining_estimate_minutes:180,milestones_total:1,milestones_late:0,open_risks:0,critical_risks:0,progress_percent:50,health:'on_track'}],next_after_id:null};
  if(name==='project_decisions.php'){
    if(method==='GET'){
      const rows=window.decisions.filter(d=>(params.get('archived')==='1'?!!d.archived:!d.archived)
       &&(!params.get('list_id')||d.list_id===Number(params.get('list_id')))
       &&(!params.get('status')||d.status===params.get('status')));
      data={decisions:rows,can_manage:!memberMode,next_before_id:null,summary:{total:window.decisions.filter(d=>!d.archived).length,
        pending:window.decisions.filter(d=>!d.archived&&d.status==='proposed').length,
        approved:window.decisions.filter(d=>!d.archived&&d.status==='approved').length,past_review:0}};
    }else {
      if(body.action==='create')window.decisions.unshift({id:75,list_id:body.list_id,project_name:body.list_id===3?'Website launch':'Operations',
        reporter_name:me.name,owner_id:body.owner_id||null,owner_name:null,status:body.status||'proposed',
        title:body.title,context:body.context,outcome:body.outcome,review_date:body.review_date,version:1,created_at:'2026-11-18 09:00'});
      if(body.action==='update'){const d=window.decisions.find(d=>d.id===body.id);Object.assign(d,body,{version:d.version+1,owner_name:body.owner_id?'Morgan Lee':null});}
      if(body.action==='archive')window.decisions.find(d=>d.id===body.id).archived=true;
      if(body.action==='restore')window.decisions.find(d=>d.id===body.id).archived=false;
      data={ok:true,id:body.id||75,version:(body.version||0)+1};
    }
  }
  return {ok:true,status:200,json:async()=>data};
 };
}'''
def initialize(page, member=False):
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
    admin=browser.new_page(viewport={'width':1440,'height':960})
    admin.set_default_timeout(12000)
    admin.on('pageerror',lambda e:errors.append('admin: '+str(e)))
    admin.on('dialog',lambda d:d.accept())
    initialize(admin)
    admin.locator('[data-view="project-decisions"]').click()
    admin.wait_for_selector('.decision-card')
    assert admin.locator('.decision-card').count()==2
    assert admin.locator('a[href^="api/project_decisions_export.php"]').count()==1
    assert 'Awaiting review' in admin.locator('#decision-summary').inner_text()
    admin.screenshot(path=str(ROOT/'docs/screenshots/project-decisions.png'),full_page=True)
    admin.locator('#decision-toggle-create').click()
    admin.locator('#decision-new-title').fill('Confirm support handoff')
    admin.locator('#decision-new-context').fill('<img src=x onerror="window.injected=1"> Confirm owner before launch.')
    admin.locator('#decision-create-form button[type=submit]').click()
    admin.wait_for_function('window.decisions.some(d=>d.id===75)')
    admin.wait_for_function('document.querySelectorAll(".decision-card").length===3')
    assert admin.locator('.decision-card img').count()==0
    assert admin.evaluate('window.injected===undefined')
    assert admin.evaluate('window.calls.some(c=>c.name==="project_decisions.php"&&c.body.action==="create"&&c.body.title==="Confirm support handoff")')
    admin.locator('[data-decision-edit="73"]').click()
    admin.locator('#decision-edit-status').select_option('approved')
    admin.locator('#decision-edit-outcome').fill('Adopt the monthly report template.')
    admin.locator('#prompt-confirm').click()
    admin.wait_for_function('window.decisions.find(d=>d.id===73).status==="approved"')
    assert admin.evaluate('window.calls.some(c=>c.name==="project_decisions.php"&&c.body.action==="update"&&c.body.version===1)')
    admin.locator('#decision-filter-project').select_option('3')
    admin.wait_for_function('document.querySelectorAll(".decision-card").length===2')
    admin.locator('#decision-filter-project').select_option('')
    admin.wait_for_function('document.querySelectorAll(".decision-card").length===3')
    admin.locator('[data-view="portfolio"]').click()
    admin.wait_for_selector('.portfolio-card')
    admin.locator('[data-portfolio-decisions="3"]').click()
    admin.wait_for_selector('.decision-card')
    assert admin.locator('#decision-filter-project').input_value()=='3'
    print('PASS decision create/edit, safe rendering, scoped filters, CSV visibility, portfolio links')
    member=browser.new_page(viewport={'width':390,'height':844})
    member.on('pageerror',lambda e:errors.append('member: '+str(e)))
    member.set_default_timeout(12000)
    initialize(member,True)
    member.locator('#mobile-menu-btn').click()
    member.locator('[data-view="project-decisions"]').click()
    member.wait_for_selector('.decision-card')
    member.wait_for_function('!document.querySelector(".sidebar").classList.contains("open")')
    assert member.locator('[data-decision-edit]').count()==0
    assert member.locator('[data-decision-action]').count()==0
    assert member.locator('a[href^="api/project_decisions_export.php"]').count()==0
    member.locator('#decision-toggle-create').click()
    assert member.locator('#decision-new-status').count()==0
    assert member.locator('#decision-new-owner').count()==0
    assert member.evaluate('document.documentElement.scrollWidth <= window.innerWidth+2')
    member.locator('#decision-toggle-create').click()
    member.wait_for_timeout(400)
    member.screenshot(path=str(ROOT/'docs/screenshots/project-decisions-mobile.png'),full_page=True)
    assert not errors,errors
    browser.close()
    print('PASS member read/propose-only permissions, mobile layout and no script errors')
