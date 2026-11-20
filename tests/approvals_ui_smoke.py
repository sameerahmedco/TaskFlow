"""TaskFlow 1.4.0 browser smoke test: synthetic UI + mocked API; no MySQL involved."""
from pathlib import Path
import base64
import re
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parent.parent
html='<!DOCTYPE html>'+(root/'index.php').read_text().split('<!DOCTYPE html>',1)[1]
html=re.sub(r'<\?php.*?\?>','',html,flags=re.S)
html=re.sub(r'<link rel="stylesheet"[^>]*>','',html)
html=re.sub(r'<script src="[^"]*">\s*</script>','',html)
styles=(root/'assets/css/style.css').read_text()+'\n'+(root/'assets/css/design-system.css').read_text()+'\n'+(root/'assets/css/ui-overhaul.css').read_text()+'\n'+(root/'assets/css/theme-accessibility.css').read_text()+'\n'+(root/'assets/css/theme-palettes.css').read_text()+'\n'+(root/'assets/css/v2-workspace.css').read_text()
script=(root/'assets/js/app.js').read_text()
icons=(root/'assets/img/icons.svg').read_text()
logo='data:image/png;base64,'+base64.b64encode((root/'assets/img/taskflow-logo-192.png').read_bytes()).decode()
mock=r'''() => {
  const today=new Date().toISOString().slice(0,10);
  const monday=new Date();monday.setUTCDate(monday.getUTCDate()-((monday.getUTCDay()+6)%7));
  const week=monday.toISOString().slice(0,10);
  const admin={id:1,name:'Morgan Lee',email:'morgan@example.test',roles:['administrator'],team_id:7,team_name:'Studio',avatar_color:'#0895ad',must_change_password:false};
  const task={id:24,title:'Prepare onboarding guide',description:'Document the process',status:'in_progress',priority:'normal',created_by:1,
    team_id:7,list_id:3,assignees:[admin],tags:[],due_date:today,start_date:null,checklist:[],subtasks:[],comments:[],recent_activity:[],
    dependencies:{depends_on:[],blocks:[]},unfinished_blockers:0,is_favorite:false};
  window.calls=[];window.myReview=null;
  window.teamReviews=[
    {id:88,member_name:'Jamie Rivera',member_id:2,status:'submitted',entry_count:3,total_minutes:115,reviewer_note:''},
    {id:89,member_name:'Avery Chen',member_id:3,status:'submitted',entry_count:2,total_minutes:90,reviewer_note:''}
  ];
  window.fetch=async(url,options={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const params=new URL(String(url),'http://localhost/').searchParams;
    const method=options.headers?.['X-HTTP-Method-Override']||options.method||'GET';
    const body=options.body?JSON.parse(options.body):{};
    window.calls.push({name,method,body,query:Object.fromEntries(params.entries())});
    let data={};
    if(name==='me.php') data={user:admin,csrf_token:'test-csrf',organization_name:'Northstar Studio',teams:[{id:7,name:'Studio'}]};
    if(name==='lists.php') data={lists:[{id:3,team_id:7,name:'Team goals'}]};
    if(name==='tags.php') data={tags:[]};
    if(name==='users.php') data={users:[{...admin,is_active:1}]};
    if(name==='my_work.php') data={tasks:[],next_before_id:null};
    if(name==='notifications.php') data={last_id:0,notifications:[]};
    if(name==='monthly.php') data={weeks:[]};
    if(name==='task.php') data={task};
    if(name==='time_report.php') data={totals:{minutes:240,entries:5,tasks:1},daily:[],entries:[],next_before_id:null};
    if(name==='worklog.php') data={minutes_total:120,week_locked:true,can_log:false,can_view_team:true,
      entries:[{id:9,user_id:1,name:admin.name,minutes:120,work_date:today,review_status:'approved'}]};
    if(name==='timer.php') data={active:null,server_now:new Date().toISOString()};
    if(name==='timesheet.php') data={week:{from:week,to:today},scope:params.get('scope'),
      summary:{minutes:240,entries:5,tasks:1,contributors:1},daily:[{date:week,minutes:240}],
      rows:[{task_id:24,title:task.title,user_id:1,name:admin.name,minutes:240,entries:5}],limited:false};
    if(name==='timesheet_approvals.php'){
      if(method==='POST') {
        if(body.action==='submit'){
          window.myReview={id:91,status:'submitted',entry_count:5,total_minutes:240,reviewed_by_name:null,reviewer_note:''};
          data={ok:true,status:'submitted',review_id:91};
        } else {
          let item=window.teamReviews.find(r=>r.id===body.review_id);
          if(item){item.status=body.action==='approve'?'approved':'changes_requested';item.reviewer_note=body.note||'';}
          data={ok:true,status:item?.status};
        }
      } else if(params.get('mode')==='team') data={reviews:window.teamReviews,limited:false};
      else data={review:window.myReview,events:window.myReview?[{event_type:'submitted',note:'',created_at:today+' 12:00:00',actor_name:admin.name}]:[]};
    }
    return {ok:true,status:200,json:async()=>data};
  };
}'''

def load(page):
    page.set_content(html.replace('id="v2-tools-drawer" hidden','id="v2-tools-drawer"'))
    page.add_style_tag(content=styles)
    page.evaluate("""asset=>{
      document.body.insertAdjacentHTML('afterbegin',asset.icons);
      document.querySelectorAll('use[href^="assets/img/icons.svg#"]').forEach(el=>el.setAttribute('href','#'+el.getAttribute('href').split('#').pop()));
      document.querySelectorAll('img[src^="assets/img/"]').forEach(img=>img.src=asset.logo);
    }""",{'icons':icons,'logo':logo})
    page.evaluate(mock)
    page.add_script_tag(content=script)
    page.wait_for_selector('#app:not(.hidden)')

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    desktop=browser.new_page(viewport={'width':1280,'height':900})
    desktop.set_default_timeout(9000)
    desktop.on('pageerror',lambda e:errors.append(str(e)))
    desktop.on('dialog',lambda d:d.accept('Please clarify the time entry'))
    load(desktop)
    desktop.locator('[data-view="timesheet"]').click()
    desktop.wait_for_selector('#submit-timesheet')
    assert 'Not submitted' in desktop.locator('.approval-panel').inner_text()
    desktop.locator('#submit-timesheet').click()
    desktop.wait_for_selector('.approval-submitted')
    assert 'Awaiting review' in desktop.locator('.approval-panel').inner_text()
    assert desktop.locator('.approval-history li').count()==1
    print('PASS personal submission and audit history')
    desktop.locator('#timesheet-scope').select_option('team')
    desktop.wait_for_selector('.approval-row')
    assert desktop.locator('[data-review-action="approve"]').count()==2
    desktop.screenshot(path=str(root/'docs/screenshots/approvals.png'),full_page=True)
    desktop.locator('[data-review-id="88"][data-review-action="approve"]').click()
    desktop.wait_for_function("window.teamReviews[0].status==='approved'")
    desktop.wait_for_function("document.querySelector('.approval-queue').innerText.includes('Approved')")
    desktop.locator('[data-review-id="89"][data-review-action="request_changes"]').click()
    desktop.wait_for_function("window.teamReviews[1].status==='changes_requested'")
    assert desktop.locator('[data-review-action="approve"]').count()==0
    assert desktop.evaluate("window.calls.some(c=>c.name==='timesheet_approvals.php' && c.method==='POST' && c.body.action==='request_changes' && c.body.note.length>0)")
    print('PASS manager approval, change request and note')
    desktop.locator('#timesheet-scope').select_option('mine')
    desktop.locator('.timesheet-task').click()
    desktop.wait_for_selector('.worklog-entry')
    assert desktop.locator('#tm-log-time').count()==0
    assert desktop.locator('.worklog-remove').count()==0
    assert 'submitted or approved' in desktop.locator('#tm-time-panel').inner_text()
    print('PASS submitted worklog shows a locked state')

    mobile=browser.new_page(viewport={'width':390,'height':844},device_scale_factor=1)
    mobile.set_default_timeout(9000)
    mobile.on('pageerror',lambda e:errors.append(str(e)))
    mobile.on('dialog',lambda d:d.accept())
    load(mobile)
    mobile.locator('#mobile-menu-btn').click()
    mobile.locator('[data-view="timesheet"]').click()
    mobile.wait_for_selector('#submit-timesheet')
    mobile.wait_for_function("!document.querySelector('.sidebar').classList.contains('open')")
    mobile.wait_for_timeout(500)  # let the responsive drawer transition finish
    mobile.screenshot(path=str(root/'docs/screenshots/approvals-mobile.png'),full_page=True)
    body_width=mobile.evaluate('document.documentElement.scrollWidth')
    assert body_width<=390, f'mobile horizontal page overflow {body_width}'
    print('PASS mobile review layout fits 390px viewport')
    if errors: raise AssertionError('Browser console errors: '+repr(errors))
    browser.close()
print('Approval browser interactions passed (mocked API, not MySQL integration).')
