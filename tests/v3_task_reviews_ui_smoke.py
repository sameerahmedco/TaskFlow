"""TaskFlow v3 task review queue, task handoff and team role UI (mocked HTTP)."""
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
fixture=ROOT/'tests/design_ui_smoke.py'
ctx={'__file__':str(fixture)}
exec(compile(fixture.read_text().split('with sync_playwright() as p:')[0],str(fixture),'exec'),ctx)
load_ui=ctx['load_ui']
contrast_file=ROOT/'tests/theme_contrast_ui_smoke.py'
contrast_ctx={'__file__':str(contrast_file)}
exec(compile(contrast_file.read_text().rsplit('with sync_playwright() as play:',1)[0],str(contrast_file),'exec'),contrast_ctx)
contrast_js=contrast_ctx['JS']
def assert_review_contrast(page):
    for selector in ('.task-review-intro h2','.task-review-intro p','.task-review-pending',
                     '.task-review-card h3','.task-review-meta','.task-review-project',
                     '.task-review-note','.task-review-status'):
        result=page.evaluate(contrast_js,selector)
        assert not result.get('missing') and result.get('ratio',0)>=4.5,(selector,result)
html,style,icons_svg,paint_brand=(ctx[k] for k in ('html','style','icons_svg','paint_brand'))
base_mock,app_js,ui_js,v2_js=(ctx[k] for k in ('MOCK','app_js','ui_js','v2_js'))
ADDED=r'''() => {
 const base=window.fetch;
 window.reviews=[{id:88,task_id:25,team_id:7,submitted_by:2,submitted_name:'Avery Chen',
   title:'Prepare release documentation',status:'pending',task_status:'review',version:1,request_note:'Ready for a quality check.',
   reviewer_note:'',project_name:'Product launch',submitted_at:'2026-11-19 14:30:00',can_review:true,can_cancel:true}];
 window.reviewCalls=[];window.rejectNextReview=false;
 window.fetch=async(url,opts={})=>{
   const name=String(url).split('?')[0].split('/').pop();
   const params=new URL(String(url),'http://localhost/').searchParams;
   const method=opts.headers?.['X-HTTP-Method-Override']||opts.method||'GET';
   const body=opts.body?JSON.parse(opts.body):{};
   if(name==='task_reviews.php'){
      window.reviewCalls.push({name,method,body,query:Object.fromEntries(params.entries())});
      if(method==='GET'){
         const scope=params.get('scope')||'mine';
         const status=params.get('status');
         const items=window.reviews.filter(row=>(scope==='team'||row.submitted_by===1) && (!status||row.status===status));
         return {ok:true,status:200,json:async()=>({reviews:items,scope,can_manage:true,
           pending_count:window.reviews.filter(row=>row.status==='pending' && (scope==='team'||row.submitted_by===1)).length,next_before_id:null})};
      }
      if(window.rejectNextReview){window.rejectNextReview=false;
        return {ok:false,status:409,json:async()=>({error:'Review was updated by another teammate.'})};}
      if(body.action==='request'){
        window.reviews.push({id:89,task_id:body.task_id,team_id:7,submitted_by:1,submitted_name:'Morgan Lee',
          title:'Review onboarding experience',status:'pending',task_status:'review',version:1,request_note:body.note,reviewer_note:'',
          project_name:'Product launch',submitted_at:'2026-11-20 15:00:00',can_review:false,can_cancel:true});
      }else{
         const row=window.reviews.find(row=>row.task_id===body.task_id);
         if(row){row.status=body.action==='request_changes'?'changes_requested':body.action==='cancel'?'cancelled':'approved';
           row.review_note=body.note;row.reviewer_note=body.note;row.version++;row.can_review=false;row.can_cancel=false;}
      }
      return {ok:true,status:200,json:async()=>({ok:true,review_id:88,task_id:body.task_id})};
   }
   if(name==='task.php'&&method==='GET'){
      const result=await base(url,opts);const data=await result.json();
      const taskId=Number(params.get('id'));const found=window.reviews.find(r=>r.task_id===taskId);
      data.task={...data.task,id:taskId,status:found?.status==='pending'?'review':'to_do',team_id:7,
        title:taskId===25?'Prepare release documentation':'Review onboarding experience',
        assignees:[{id:1,name:'Morgan Lee',roles:['administrator'],avatar_color:'#087c73'}],
        tags:[],checklist:[],subtasks:[],comments:[],review:found?{id:found.id,version:found.version,
          status:found.status,submitted_by:found.submitted_by,request_note:found.request_note,reviewer_note:found.reviewer_note}:null,
        review_events:found?[{actor_name:'Avery Chen',event_type:'requested',note:'Ready for a quality check.',created_at:'2026-11-19 14:30:00'}]:[],
        dependencies:{depends_on:[],blocks:[]},logged_minutes:0,is_recurring_weekly:0};
      return {ok:true,status:200,json:async()=>data};
   }
   if(name==='worklog.php')return {ok:true,status:200,json:async()=>({entries:[],minutes_total:0,can_log:false})};
   if(name==='timer.php')return {ok:true,status:200,json:async()=>({active:null,server_now:new Date().toISOString()})};
   if(name==='attachments.php')return {ok:true,status:200,json:async()=>({attachments:[]})};
   return base(url,opts);
 };
}'''
MEMBER_PATCH=r'''() => {
 const base=window.fetch;
 window.fetch=async(url,opts={})=>{
  const name=String(url).split('?')[0].split('/').pop();
  if(name==='me.php')return {ok:true,status:200,json:async()=>({
    user:{id:2,name:'Avery Chen',email:'avery@example.test',team_id:7,team_name:'Product',avatar_color:'#168c97',roles:['member']},
    csrf_token:'test-token',organization_name:'Northstar Studio',teams:[{id:7,name:'Product'}]})};
  if(name==='task_reviews.php' && (!opts.method||opts.method==='GET')){
    const params=new URL(String(url),'http://localhost/').searchParams;
    return {ok:true,status:200,json:async()=>({scope:'mine',can_manage:false,reviews:window.reviews.filter(r=>r.submitted_by===2),
      pending_count:1,next_before_id:null})};
  }
  return base(url,opts);
 };
}'''

def admin_page(browser,viewport,theme='light'):
    page=browser.new_page(viewport=viewport)
    page.set_default_timeout(14000)
    errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    load_ui(page,True)
    page.evaluate(ADDED)
    page.locator('#appearance-preference').select_option(theme)
    return page,errors

def member_page(browser):
    page=browser.new_page(viewport={'width':390,'height':844})
    errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    page.set_default_timeout(14000)
    page.set_content(html)
    page.add_style_tag(content=style)
    page.evaluate('''(svg)=>{document.body.insertAdjacentHTML('afterbegin',svg);
     document.querySelectorAll('use[href^="assets/img/icons.svg#"]').forEach(el=>el.setAttribute('href','#'+el.getAttribute('href').split('#').pop()));}''',icons_svg)
    paint_brand(page)
    page.evaluate(base_mock);page.evaluate(ADDED);page.evaluate(MEMBER_PATCH)
    page.add_script_tag(content=app_js);page.add_script_tag(content=ui_js);page.add_script_tag(content=v2_js)
    page.wait_for_selector('#app:not(.hidden)');page.wait_for_selector('#my-work-results .search-result')
    return page,errors

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    page,errors=admin_page(browser,{'width':1440,'height':970})
    page.locator('#v2-tools-toggle').click()
    page.locator('[data-view="task-reviews"]').click()
    page.wait_for_selector('.task-review-card')
    assert page.locator('.task-review-card').count()==1
    assert page.locator('[data-review-action="approve"]').count()==1
    assert 'Avery Chen' in page.locator('.task-review-card').inner_text()
    assert page.locator('#task-review-scope').input_value()=='team'
    assert_review_contrast(page)
    page.screenshot(path=str(ROOT/'docs/screenshots/v3-task-reviews-light.png'),full_page=True)
    # A stale review should leave decision controls in place for retry.
    page.locator('[data-review-action="request_changes"]').click()
    page.locator('#p-task-review-note').fill('Please update the QA checklist and attach the acceptance report.')
    page.evaluate('window.rejectNextReview=true')
    page.locator('#prompt-confirm').click()
    page.wait_for_function('window.reviewCalls.some(c=>c.method==="POST"&&c.body.action==="request_changes")')
    assert page.locator('#p-task-review-note').input_value().startswith('Please update')
    page.locator('#prompt-confirm').click()
    page.wait_for_function('window.reviews[0].status==="changes_requested"')
    page.locator('#task-review-status').select_option('changes_requested')
    page.wait_for_selector('.task-review-card')
    assert 'Please update the QA checklist' in page.locator('.task-review-card').inner_text()
    # A fresh resubmission can be approved by another manager; the UI still uses
    # its latest version instead of silently overwriting the earlier feedback.
    page.evaluate('''() => {
      const review=window.reviews[0];review.status='pending';review.version=3;
      review.can_review=true;review.can_cancel=true;review.task_status='review';
    }''')
    page.locator('#task-review-status').select_option('pending')
    page.wait_for_selector('[data-review-action="approve"]')
    page.locator('[data-review-action="approve"]').click()
    page.locator('#p-task-review-note').fill('Accepted after the requested changes.')
    page.locator('#prompt-confirm').click()
    page.wait_for_function('window.reviews[0].status==="approved"')
    assert page.evaluate('window.reviewCalls.some(c=>c.method==="POST"&&c.body.action==="approve"&&c.body.version===3)')
    print('PASS manager queue, review approval, request changes, retry after 409 and history-safe text')
    page.locator('[data-v2-view="my-work"]').click()
    page.locator('#my-work-results .v3-work-task-open').first.click()
    page.wait_for_selector('#tm-review-request')
    page.locator('#tm-review-request').click()
    page.locator('#p-review-note').fill('<img src=x onerror="window.injected=1"> Please review the onboarding flow.')
    page.locator('#prompt-confirm').click()
    page.wait_for_function('window.reviews.some(r=>r.task_id===24)')
    page.wait_for_selector('#tm-review-section .task-review-status-pending')
    assert page.locator('#tm-review-request').count()==0
    assert page.locator('#tm-review-section img').count()==0
    assert page.evaluate('window.injected===undefined')
    assert page.locator('.task-review-history').count()==1
    print('PASS request review from task details, pending-status indicator, and XSS-safe notes')
    page.locator('#close-task-modal').click()
    page.locator('#v2-tools-toggle').click()
    page.locator('[data-view="task-reviews"]').click()
    page.locator('#task-review-status').select_option('pending')
    page.wait_for_selector('.task-review-card')
    assert page.locator('.task-review-card').count()==1, 'own request omitted in team queue unexpectedly'
    page.locator('#task-review-scope').select_option('mine')
    page.wait_for_selector('.task-review-card')
    assert page.locator('[data-review-action="approve"]').count()==0, 'manager cannot approve own review'
    assert page.locator('[data-review-action="cancel"]').count()==1
    assert not errors,errors
    page.close()
    black,errors=admin_page(browser,{'width':1365,'height':900},'black')
    black.locator('#v2-tools-toggle').click();black.locator('[data-view="task-reviews"]').click()
    black.wait_for_selector('.task-review-card')
    assert_review_contrast(black)
    black.screenshot(path=str(ROOT/'docs/screenshots/v3-task-reviews-black.png'),full_page=True)
    assert black.evaluate('document.documentElement.dataset.palette')=='black'
    assert not errors,errors
    black.close()
    mobile,errors=member_page(browser)
    mobile.locator('#appearance-preference').select_option('charcoal')
    mobile.evaluate('document.querySelector(\'[data-view="task-reviews"]\').click()')
    mobile.wait_for_selector('.task-review-card')
    assert mobile.locator('#task-review-scope').count()==0
    assert mobile.locator('[data-review-action="approve"]').count()==0
    assert mobile.locator('[data-review-action="request_changes"]').count()==0
    assert mobile.locator('[data-review-action="cancel"]').count()==1
    assert mobile.evaluate('document.documentElement.scrollWidth<=window.innerWidth+2')
    mobile.screenshot(path=str(ROOT/'docs/screenshots/v3-task-reviews-mobile.png'),full_page=True)
    assert not errors,errors
    mobile.close();browser.close()
    print('PASS Black and Charcoal themes and mobile member-only review access')
print('All TaskFlow v3 task-review UI smoke scenarios passed (mocked HTTP).')
