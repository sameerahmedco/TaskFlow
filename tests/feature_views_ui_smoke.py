"""Mocked browser acceptance for TaskFlow 1.1.0 calendar/templates/recurrence UI.
No live MySQL or PHP HTTP APIs are exercised here.
"""
from pathlib import Path
import base64
import re
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parent.parent
html = (root/'index.php').read_text().split('<!DOCTYPE html>',1)[1]
html = '<!DOCTYPE html>' + re.sub(r'<\?php.*?\?>', '', html, flags=re.S)
html = re.sub(r'<link rel="stylesheet"[^>]*>', '', html)
html = re.sub(r'<script src="[^"]*">\s*</script>', '', html)
styles = (root/'assets/css/style.css').read_text()+'\n'+(root/'assets/css/design-system.css').read_text()
script = (root/'assets/js/app.js').read_text()
screens = root/'docs'/'screenshots'
screens.mkdir(exist_ok=True,parents=True)
icon_svg = (root/'assets/img/icons.svg').read_text()
logo = 'data:image/png;base64,'+base64.b64encode((root/'assets/img/taskflow-logo-192.png').read_bytes()).decode()
mock = r'''() => {
  const today=new Date(), yy=today.getFullYear(),mm=String(today.getMonth()+1).padStart(2,'0');
  const date=yy+'-'+mm+'-10', next=yy+'-'+mm+'-11';
  window.__testDate=date;
  const user={id:1,name:'Morgan Lee',email:'morgan@example.test',roles:['administrator'],team_id:7,team_name:'Product',avatar_color:'#3b8dcc',must_change_password:false};
  const task={id:24,title:'Review launch calendar',description:'Review requirements',status:'to_do',priority:'high',
      due_date:date,start_date:null,team_id:7,list_id:3,created_by:1,assignees:[user],tags:[],
      checklist:[],subtasks:[],comments:[],recent_activity:[],dependencies:{depends_on:[],blocks:[]},unfinished_blockers:0,is_recurring_weekly:0};
  window.calls=[];
  window.fetch=async(url,options={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const params=new URL(String(url),'http://localhost/').searchParams;
    const method=options.headers?.['X-HTTP-Method-Override']||options.method||'GET';
    const body=options.body?JSON.parse(options.body):{};
    window.calls.push({name,method,body,query:Object.fromEntries(params.entries())});
    let data={};
    if(name==='mfa_status.php') data={mfa_required:false};
    if(name==='me.php') data={user,csrf_token:'mock-csrf',organization_name:'Example Studio',teams:[{id:7,name:'Product'}]};
    if(name==='lists.php') data={lists:[{id:3,team_id:7,name:'Product work'}]};
    if(name==='tags.php') data={tags:[]};
    if(name==='users.php') data={users:[{...user,is_active:1}]};
    if(name==='my_work.php') data={tasks:[],next_before_id:null};
    if(name==='notifications.php') data={last_id:0,notifications:[]};
    if(name==='monthly.php') data={weeks:[]};
    if(name==='task.php') data={task:{...task,id: Number(params.get('id'))||24}};
    if(name==='calendar.php') {
      const month=params.get('month'),[y,m]=month.split('-').map(Number);
      const start=new Date(Date.UTC(y,m-1,1));start.setUTCDate(start.getUTCDate()-((start.getUTCDay()+6)%7));
      data={month,start:start.toISOString().slice(0,10),end:'2099-12-31',truncated:false,
        tasks:params.get('mine')==='1'?[{...task,list_name:'Product work',assigned_to_me:1}]:
          [{...task,list_name:'Product work',assigned_to_me:1},{...task,id:25,title:'Approve deliverables',due_date:next,list_name:'Product work',assigned_to_me:0}]};
    }
    if(name==='templates.php') {
      if(method==='GET') data={templates:[{id:10,title:'Weekly review',description:'Review progress',priority:'high',items:['Prepare brief','Get approval']}],truncated:false};
      else data={ok:true,id:method==='POST'?11:10};
    }
    if(name==='tasks.php') data=(method==='POST'?{id:31,title:body.title}:{tasks:[task],next_offset:null});
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
    }""",{'icons':icon_svg,'logo':logo})
    page.evaluate(mock)
    page.add_script_tag(content=script)
    page.wait_for_selector('#app:not(.hidden)')

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox'])
    errors=[]
    page = browser.new_page(viewport={'width':1380,'height':880})
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.on('dialog',lambda d:d.accept())
    page.set_default_timeout(4500)
    load(page)
    page.locator('[data-view="calendar"]').click()
    page.wait_for_selector('.calendar-day')
    assert page.locator('.calendar-day').count()==42
    assert page.locator('.calendar-event').count()==2
    assert page.locator('.calendar-agenda-item').count()==2
    page.screenshot(path=str(screens/'calendar.png'),full_page=True)
    page.locator('#calendar-mine').check()
    page.wait_for_function("document.querySelectorAll('.calendar-event').length===1")
    assert page.evaluate("window.calls.some(c=>c.name==='calendar.php'&&c.query.mine==='1'&&c.query.team_id==='7')")
    page.locator('.calendar-event').first.click()
    page.wait_for_selector('#tm-repeat-rule')
    assert page.locator('#tm-repeat-rule').input_value()=='none'
    page.locator('#tm-repeat-rule').select_option('weekly')
    page.locator('#tm-save').click()
    page.wait_for_function("window.calls.some(c=>c.name==='task.php'&&c.method==='PUT'&&c.body.recurrence_rule==='weekly')")
    print('PASS: calendar, personal filter, task detail and recurring toggle')
    page.locator('[data-view="templates"]').click()
    page.wait_for_selector('.template-card')
    assert page.locator('.template-card').count()==1
    page.screenshot(path=str(screens/'templates.png'),full_page=True)
    page.locator('[data-use-template]').click()
    page.wait_for_selector('#p-use-title')
    page.locator('#p-use-title').fill('Use weekly review')
    page.locator('#p-use-repeat').check()
    page.locator('#prompt-confirm').click()
    page.wait_for_function("window.calls.some(c=>c.name==='tasks.php'&&c.method==='POST'&&c.body.template_id===10&&c.body.is_recurring_weekly===true)")
    print('PASS: template card creates task using server-side template ID')
    page.locator('#close-task-modal').click()
    page.locator('[data-view="templates"]').click()
    page.locator('#new-template-button').click()
    page.locator('#p-template-title').fill('Client handoff')
    page.locator('#p-template-items').fill('Prepare files\nSend update')
    page.locator('#prompt-confirm').click()
    page.wait_for_function("window.calls.some(c=>c.name==='templates.php'&&c.method==='POST'&&c.body.items.length===2)")
    page.locator('[data-edit-template]').click()
    page.locator('#p-template-title').fill('Updated review')
    page.locator('#prompt-confirm').click()
    page.wait_for_function("window.calls.some(c=>c.name==='templates.php'&&c.method==='PUT'&&c.body.id===10)")
    page.locator('[data-delete-template]').click()
    page.wait_for_function("window.calls.some(c=>c.name==='templates.php'&&c.method==='DELETE'&&c.body.id===10)")
    page.locator('[data-view="tasks"]').click()
    page.locator('[data-tab="add"]').click()
    page.wait_for_selector('#at-template option[value="10"]', state='attached')
    page.locator('#at-template').select_option('10')
    assert page.locator('#at-title').input_value()=='Weekly review'
    page.locator('#at-repeat-rule').select_option('weekly')
    page.locator('#at-title').fill('Use template in task form')
    page.locator('#add-task-form button[type=submit]').click()
    page.wait_for_function("window.calls.some(c=>c.name==='tasks.php'&&c.method==='POST'&&c.body.template_id===10&&c.body.recurrence_rule==='weekly')")
    print('PASS: template create/edit/delete and task-form template picker')
    page.close()
    mobile = browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
    mobile.on('pageerror',lambda e:errors.append(str(e)))
    mobile.set_default_timeout(4500)
    load(mobile)
    mobile.locator('#mobile-menu-btn').click()
    mobile.locator('[data-view="calendar"]').click()
    mobile.wait_for_selector('.calendar-agenda-item')
    assert mobile.locator('.calendar-grid').is_hidden()
    mobile.screenshot(path=str(screens/'mobile-calendar.png'),full_page=True)
    assert mobile.evaluate('document.documentElement.scrollWidth <= innerWidth+2'), 'mobile calendar overflow'
    mobile.locator('#mobile-menu-btn').click()
    mobile.locator('[data-view="templates"]').click()
    mobile.wait_for_selector('.template-card')
    assert mobile.evaluate('document.documentElement.scrollWidth <= innerWidth+2'), 'mobile template overflow'
    print('PASS: mobile calendar agenda and template cards have no page overflow')
    assert not errors,errors
    print('PASS: zero unexpected JavaScript page errors')
    browser.close()
