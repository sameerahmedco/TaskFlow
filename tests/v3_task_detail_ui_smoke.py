"""TaskFlow v3 task tabs, paste/rename/move/delete checklist checks with mock APIs.
Not a substitute for authenticated MySQL security tests.
"""
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
fixture=ROOT/'tests/design_ui_smoke.py'
ctx={'__file__':str(fixture)}
exec(compile(fixture.read_text().split('with sync_playwright() as p:')[0],str(fixture),'exec'),ctx)
load_ui=ctx['load_ui']
MOCK=r'''() => {
  const original=window.fetch;
  window.checklistRows=[
    {id:101,content:'Confirm requirements',is_done:1,position:0},
    {id:102,content:'Prepare design',is_done:0,position:1},
    {id:103,content:'Review <img src=x onerror="window.injected=1">',is_done:0,position:2}
  ];
  window.checklistCalls=[];window.failNextReorder=false;
  window.fetch=async(url,opts={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const method=opts.headers?.['X-HTTP-Method-Override']||opts.method||'GET';
    const body=opts.body?JSON.parse(opts.body):{};
    if(name==='task.php' && method==='GET'){
      const user={id:1,name:'Morgan Lee',avatar_color:'#087e73',roles:['administrator'],team_id:7};
      return {ok:true,status:200,json:async()=>({task:{
        id:24,title:'Review onboarding experience',description:'Validate organization setup and first-time experience.',
        status:'to_do',priority:'high',team_id:7,list_id:3,created_by:window.mockReadOnly?99:1,
        due_date:'2026-12-05',start_date:null,estimate_minutes:180,recurrence_rule:'none',
        assignees:window.mockReadOnly?[]:[user],tags:[],subtasks:[],
        checklist:window.checklistRows.map(x=>({...x})),
        comments:[{id:51,content:'The setup screen needs review.',user_id:1,name:'Morgan Lee',roles:['administrator'],avatar_color:'#087e73',created_at:'2026-11-20 09:00:00'}],
        recent_activity:[],dependencies:{depends_on:[],blocks:[]},unfinished_blockers:0,is_favorite:false,
        is_watching:false,watcher_count:0,logged_minutes:0,is_recurring_weekly:0
      }})};
    }
    if(name==='checklist.php'){
      window.checklistCalls.push({method,body});
      if(body.action==='reorder'){
        if(window.failNextReorder){window.failNextReorder=false;return {ok:false,status:409,json:async()=>({error:'Checklist changed. Reload before reordering.'})};}
        window.checklistRows=body.order.map((id,i)=>({...window.checklistRows.find(x=>x.id===id),position:i}));
      }else if(body.action==='edit_text'){
        window.checklistRows.find(x=>x.id===body.id).content=body.content;
      }else if(body.action==='add_many'){
        let next=Math.max(0,...window.checklistRows.map(x=>x.id))+1;
        for(const content of body.items) window.checklistRows.push({id:next++,content,position:window.checklistRows.length,is_done:0});
      }else if(method==='POST'){
        let next=Math.max(0,...window.checklistRows.map(x=>x.id))+1;
        window.checklistRows.push({id:next,content:body.content,position:window.checklistRows.length,is_done:0});
      }else if(method==='DELETE')window.checklistRows=window.checklistRows.filter(x=>x.id!==body.id);
      else if(method==='PUT')window.checklistRows.find(x=>x.id===body.id).is_done=body.is_done;
      return {ok:true,status:200,json:async()=>({ok:true,added_count:body.items?.length||1})};
    }
    if(name==='worklog.php') return {ok:true,status:200,json:async()=>({entries:[],minutes_total:0,can_log:false,can_view_team:false,week_locked:false})};
    if(name==='timer.php') return {ok:true,status:200,json:async()=>({active:null,server_now:new Date().toISOString()})};
    if(name==='attachments.php')return {ok:true,status:200,json:async()=>({attachments:[]})};
    return original(url,opts);
  };
}'''

def open_task(page):
    page.locator('#my-work-results .v3-work-task-open').first.click()
    page.wait_for_selector('#tm-tab-checklist')

def check_tab(page,name):
    page.locator(f'#tm-tab-{name}').click()
    assert page.locator(f'#tm-panel-{name}').is_visible()
    assert page.locator(f'#tm-tab-{name}').get_attribute('aria-selected')=='true'

with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True, executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    page=browser.new_page(viewport={'width':1440,'height':960});page.set_default_timeout(16000)
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.on('dialog',lambda dialog:dialog.accept())
    load_ui(page,authenticated=True)
    page.evaluate(MOCK)
    open_task(page)
    assert page.locator('.task-detail-tab').count()==4
    assert page.locator('#tm-panel-details').is_visible()
    assert page.locator('#tm-panel-checklist').is_hidden()
    page.locator('#tm-tab-details').focus()
    page.keyboard.press('ArrowRight')
    assert page.locator('#tm-tab-checklist').get_attribute('aria-selected')=='true'
    page.keyboard.press('Home')
    assert page.locator('#tm-tab-details').get_attribute('aria-selected')=='true'
    page.wait_for_timeout(120)
    page.screenshot(path=str(ROOT/'docs/screenshots/v3-task-details-light.png'),full_page=True)
    check_tab(page,'checklist')
    assert page.locator('.checklist-item').count()==3
    assert page.locator('#tm-checklist-count').inner_text()=='1 of 3 done'
    assert page.locator('.checklist-item img').count()==0, 'HTML injection in checklist'
    assert page.evaluate('window.injected===undefined')
    # XSS behavior verified above. Use clean synthetic content in public previews.
    page.evaluate("window.checklistRows[2].content='Review accessibility and copy'")
    page.locator('#close-task-modal').click()
    open_task(page);check_tab(page,'checklist')
    page.wait_for_timeout(180)
    page.screenshot(path=str(ROOT/'docs/screenshots/v3-task-checklist-light.png'),full_page=True)
    page.locator('.checklist-toggle[data-item="102"]').check()
    page.wait_for_function("window.checklistRows.find(x=>x.id===102).is_done===1")
    assert page.locator('#tm-checklist-count').inner_text()=='2 of 3 done'
    assert page.locator('#tm-checklist-progress').get_attribute('aria-valuenow')=='67'
    page.locator('[data-checklist-edit="102"]').click()
    page.locator('form[data-checklist-item="102"] input').fill('Prepare revised mockups')
    page.locator('form[data-checklist-item="102"] button[type=submit]').click()
    page.wait_for_function("window.checklistRows.find(x=>x.id===102).content==='Prepare revised mockups'")
    page.wait_for_selector('#tm-panel-checklist:not([hidden])')
    assert 'Prepare revised mockups' in page.locator('.checklist-item').nth(1).inner_text()
    print('PASS four task tabs, HTML-safe checklist, progress and inline editing')
    page.locator('[data-checklist-move="102"][data-direction="-1"]').click()
    page.wait_for_function('window.checklistRows[0].id===102')
    page.wait_for_selector('#tm-panel-checklist:not([hidden])')
    call=page.evaluate("window.checklistCalls.find(c=>c.body.action==='reorder')")
    assert call['body']['expected_order']==[101,102,103]
    assert call['body']['order']==[102,101,103]
    page.evaluate('window.failNextReorder=true')
    page.locator('[data-checklist-move="102"][data-direction="1"]').click()
    page.wait_for_function('window.checklistCalls.filter(c=>c.body.action===\'reorder\').length===2')
    assert 'Checklist changed' in page.locator('#toast').inner_text()
    assert page.locator('#tm-panel-checklist').is_visible()
    print('PASS order updates, expected order protection, and failed reorder recovery')
    page.locator('#tm-paste-open').click()
    page.locator('#tm-paste-text').fill('Draft specification\n\nReview details')
    page.locator('#tm-paste-form button[type=submit]').click()
    assert 'Paste 1–30 nonempty' in page.locator('#toast').inner_text()
    page.locator('#tm-paste-text').fill('Confirm colors\nReview accessibility')
    page.locator('#tm-paste-form button[type=submit]').click()
    page.wait_for_function('window.checklistRows.length===5')
    assert page.locator('.checklist-item').count()==5
    page.wait_for_selector('#tm-panel-checklist:not([hidden])')
    assert page.locator('#tm-checklist-count').inner_text()=='2 of 5 done'
    page.locator('[data-checklist-remove="103"]').click()
    page.wait_for_function('window.checklistRows.length===4')
    page.wait_for_selector('#tm-panel-checklist:not([hidden])')
    assert page.locator('.checklist-item').count()==4
    check_tab(page,'files')
    assert page.locator('#tm-attachments').is_visible()
    check_tab(page,'comments')
    assert 'The setup screen needs review' in page.locator('#tm-comments').inner_text()
    print('PASS atomic paste UI, delete, files and discussion tabs')
    for palette in ('charcoal','black'):
      page.locator('#appearance-preference').select_option(palette)
      page.wait_for_timeout(200)
      check_tab(page,'checklist')
      contrast=page.evaluate("""() => {
        const label=document.querySelector('.task-checklist-rows .checklist-label');
        const parent=document.querySelector('.task-checklist-rows');
        const rgb=s=>s.match(/[0-9.]+/g).slice(0,3).map(Number);
        const luminance=c=>c.map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4)
          .reduce((sum,v,i)=>sum+v*[.2126,.7152,.0722][i],0);
        const fg=luminance(rgb(getComputedStyle(label).color));
        const bg=luminance(rgb(getComputedStyle(parent).backgroundColor));
        return (Math.max(fg,bg)+.05)/(Math.min(fg,bg)+.05);
      }""")
      assert contrast>=4.5,(palette,contrast)
      if palette=='black':
        page.evaluate("document.querySelector('#toast').classList.add('hidden')")
        page.screenshot(path=str(ROOT/'docs/screenshots/v3-task-checklist-black.png'),full_page=True)
    print('PASS Charcoal and Black checklist views')
    mobile=browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
    mobile.set_default_timeout(16000);mobile.on('pageerror',lambda e:errors.append(str(e)))
    load_ui(mobile,authenticated=True);mobile.evaluate(MOCK);open_task(mobile);check_tab(mobile,'checklist')
    mobile.wait_for_timeout(250)
    assert mobile.evaluate('document.documentElement.scrollWidth <= innerWidth+2'), 'mobile viewport overflows'
    mobile.screenshot(path=str(ROOT/'docs/screenshots/v3-task-checklist-mobile.png'),full_page=True)
    mobile.locator('#tm-tab-comments').click();assert mobile.locator('#tm-comments').is_visible()
    # A same-team reader who is neither creator nor assignee cannot mutate steps.
    ctx['MOCK']=ctx['MOCK'].replace("roles:['administrator']","roles:['member']")
    reader=browser.new_page(viewport={'width':1280,'height':850})
    reader.set_default_timeout(16000);reader.on('pageerror',lambda e:errors.append(str(e)))
    load_ui(reader,authenticated=True)
    reader.evaluate('window.mockReadOnly=true');reader.evaluate(MOCK)
    open_task(reader);check_tab(reader,'checklist')
    assert reader.locator('#tm-add-checklist').count()==0
    assert reader.locator('#tm-paste-open').count()==0
    assert reader.locator('[data-checklist-edit]').count()==0
    assert reader.locator('[data-checklist-remove]').count()==0
    assert reader.locator('[data-checklist-move]').count()==0
    assert reader.locator('.checklist-toggle:disabled').count()==3
    assert not errors, errors
    browser.close()
    print('PASS mobile tabs and viewport; all new task-detail browser checks passed (mock APIs)')
