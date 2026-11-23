"""TaskFlow v3 release QA: keyboard dialogs, stale responses, malformed success JSON.

Network responses are mocked; this does NOT replace real authenticated MySQL tests.
"""
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
fixture=ROOT/'tests/design_ui_smoke.py'
ctx={'__file__':str(fixture)}
exec(compile(fixture.read_text().split('with sync_playwright() as p:')[0],str(fixture),'exec'),ctx)
load_ui=ctx['load_ui']
MOCK=r'''() => {
  const base=window.fetch;
  window.pendingTasks={};window.taskCalls=[];
  window.reviewMode='success';window.reviewResolver=null;window.reviewCalls=[];
  window.fetch=(url,opts={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    if(name==='task.php' && (!opts.method || opts.method==='GET')) {
      const id=Number(new URL(String(url),'http://localhost').searchParams.get('id'));
      window.taskCalls.push(id);
      return new Promise(resolve=>{window.pendingTasks[id]=resolve;});
    }
    if(name==='task_reviews.php' && opts.method==='POST') {
      window.reviewCalls.push(JSON.parse(opts.body));
      if(window.reviewMode==='malformed')return Promise.resolve({ok:true,status:200,json:async()=>{throw new SyntaxError('Unexpected HTML')}});
      if(window.reviewMode==='expired')return Promise.resolve({ok:false,status:401,json:async()=>({error:'Your session expired.'})});
      if(window.reviewMode==='busy')return new Promise(resolve=>window.reviewResolver=resolve);
      return Promise.resolve({ok:true,status:200,json:async()=>({ok:true})});
    }
    return base(url,opts);
  };
  window.fulfillTask=(id)=>{
    const resolve=window.pendingTasks[id];if(!resolve)throw new Error('Missing task resolver '+id);
    const user={id:1,name:'Morgan Lee',avatar_color:'#087e73',roles:['administrator'],team_id:7};
    const title=id===24?'Review onboarding experience':'Prepare release documentation';
    const task={id,title,description:'Validate the public release',status:'to_do',priority:'high',team_id:7,
      list_id:3,created_by:1,due_date:null,start_date:null,estimate_minutes:90,recurrence_rule:'none',
      assignees:[user],tags:[],subtasks:[],checklist:[],comments:[],recent_activity:[],
      dependencies:{depends_on:[],blocks:[]},unfinished_blockers:0,is_favorite:false,
      is_watching:false,watcher_count:0,logged_minutes:0,is_recurring_weekly:0,
      review:null,review_events:[]};
    resolve({ok:true,status:200,json:async()=>({task})});
    delete window.pendingTasks[id];
  };
}'''

with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    page=browser.new_page(viewport={'width':1366,'height':900});page.set_default_timeout(14000)
    page.on('pageerror',lambda e:errors.append(str(e)))
    load_ui(page,authenticated=True)
    page.evaluate(MOCK)
    first=page.locator('.v3-work-task[data-task-id="24"] .v3-work-task-open')
    second=page.locator('.v3-work-task[data-task-id="25"] .v3-work-task-open')
    first.click()
    assert page.locator('#task-modal').is_visible()
    assert page.locator('#app').get_attribute('aria-hidden')=='true'
    assert page.locator('#task-modal').get_attribute('aria-hidden')=='false'
    assert page.evaluate('document.activeElement.id')=='close-task-modal'
    assert page.locator('#task-modal-body').inner_text()=='Loading task details…'
    # First response remains pending. Trigger a second task from the background.
    second.evaluate('(el)=>el.click()')
    page.wait_for_function('window.taskCalls.length===2')
    page.evaluate('window.fulfillTask(25)')
    page.wait_for_function('document.getElementById("tm-title")?.value==="Prepare release documentation"')
    page.evaluate('window.fulfillTask(24)')
    page.wait_for_timeout(80)
    assert page.locator('#tm-title').input_value()=='Prepare release documentation', 'stale task clobbered selected task'
    assert page.evaluate('document.activeElement.closest("#task-modal")!==null')
    print('PASS task modal focus and out-of-order task fetch protection')
    # Closing while a request is outstanding invalidates its response.
    first.evaluate('(el)=>el.click()')
    page.wait_for_function('window.taskCalls.length===3')
    page.locator('#close-task-modal').click()
    assert page.locator('#task-modal').is_hidden()
    assert page.locator('#app').get_attribute('aria-hidden') is None
    assert page.evaluate('document.activeElement.closest("#task-modal")===null')
    page.evaluate('window.fulfillTask(24)')
    page.wait_for_timeout(70)
    assert page.locator('#task-modal').is_hidden()
    print('PASS closed task cannot be repopulated by a late response; focus restored')
    # Open a finished task and exercise focus wrap on both modal layers.
    first.click()
    page.wait_for_function('window.taskCalls.length===4')
    page.evaluate('window.fulfillTask(24)')
    page.wait_for_selector('#tm-review-request')
    page.locator('#close-task-modal').focus()
    page.keyboard.press('Shift+Tab')
    assert page.evaluate('document.activeElement.closest("#task-modal")!==null'), 'Shift+Tab leaked behind task dialog'
    page.locator('#tm-review-request').click()
    assert page.locator('#prompt-modal').is_visible()
    assert page.locator('#task-modal').get_attribute('aria-hidden')=='true'
    assert page.evaluate('document.activeElement.id')=='p-review-note','prompt should focus first form input'
    page.keyboard.press('Control+Shift+K')
    assert page.locator('#quick-switch-modal').is_hidden(), 'page switcher must not steal focus from an active prompt'
    page.keyboard.press('Shift+Tab')
    assert page.evaluate('document.activeElement.id')=='prompt-confirm','Shift+Tab must wrap to final action'
    page.keyboard.press('Tab')
    assert page.evaluate('document.activeElement.id')=='p-review-note','Tab must wrap to first control'
    page.keyboard.press('Escape')
    assert page.locator('#prompt-modal').is_hidden()
    assert page.locator('#task-modal').get_attribute('aria-hidden')=='false'
    assert page.locator('#task-modal').is_visible()
    assert page.evaluate('document.activeElement.id')=='tm-review-request'
    print('PASS keyboard-only prompt navigation, Escape and return focus')
    # A malformed HTTP 200 used to be treated as success; preserve draft & show error.
    page.evaluate("window.reviewMode='malformed'")
    page.locator('#tm-review-request').click()
    page.locator('#p-review-note').fill('Review the release with this exact draft')
    page.locator('.prompt-modal').screenshot(path=str(ROOT/'docs/screenshots/v3-public-modal-polish-light.png'))
    page.locator('#prompt-confirm').click()
    page.wait_for_selector('#prompt-error:visible')
    assert 'invalid response' in page.locator('#prompt-error').inner_text()
    assert page.locator('#p-review-note').input_value()=='Review the release with this exact draft'
    assert page.locator('#prompt-modal').is_visible()
    assert page.evaluate('document.activeElement.closest("#prompt-modal")!==null')
    print('PASS invalid successful HTTP response is not silently accepted')
    # On an in-flight save the cancel button and Escape must not dismiss the dialog.
    page.evaluate("window.reviewMode='busy'")
    page.locator('#prompt-confirm').click()
    page.wait_for_function('document.getElementById("prompt-modal").getAttribute("aria-busy")==="true"')
    assert page.locator('#prompt-confirm').is_disabled()
    assert page.locator('#prompt-cancel').is_disabled()
    page.keyboard.press('Escape')
    assert page.locator('#prompt-modal').is_visible()
    page.evaluate("window.reviewResolver({ok:true,status:200,json:async()=>({ok:true})})")
    page.wait_for_function('window.pendingTasks[24]!==undefined')
    page.evaluate('window.fulfillTask(24)')
    page.wait_for_selector('#prompt-modal.hidden',state='attached')
    assert page.locator('#prompt-error').is_hidden()
    assert not errors,errors
    print('PASS pending-save busy state, safe completion and cleared errors')
    for palette in ('charcoal','black'):
        page.locator('#appearance-preference').select_option(palette)
        first.evaluate('(el)=>el.click()')
        page.wait_for_function('window.taskCalls.length>=5')
        page.evaluate('window.fulfillTask(24)')
        page.wait_for_selector('#tm-review-request')
        page.locator('#tm-review-request').click()
        assert page.evaluate('document.activeElement.id')=='p-review-note'
        page.screenshot(path=str(ROOT/'docs/screenshots'/('v3-modal-polish-'+palette+'.png')),full_page=True)
        page.keyboard.press('Escape');page.locator('#close-task-modal').click()
    page.close()
    mobile=browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
    mobile.on('pageerror',lambda e:errors.append(str(e)))
    load_ui(mobile,authenticated=True);mobile.evaluate(MOCK)
    mobile.locator('.v3-work-task[data-task-id="24"] .v3-work-task-open').click()
    mobile.evaluate('window.fulfillTask(24)')
    mobile.wait_for_selector('#tm-review-request')
    mobile.locator('#tm-review-request').click()
    assert mobile.evaluate('document.documentElement.scrollWidth<=innerWidth+2')
    mobile.keyboard.press('Escape')
    assert mobile.locator('#prompt-modal').is_hidden()
    assert not errors,errors
    # Administrator creation must keep entered details visible after a server rejection.
    admin=browser.new_page(viewport={'width':1280,'height':900})
    admin.set_default_timeout(14000)
    admin.on('pageerror',lambda e:errors.append(str(e)))
    load_ui(admin,authenticated=True)
    admin.evaluate("""() => {
      const original=window.fetch;
      window.accountSaveFailed=true;
      window.fetch=(url,opts={})=>{
        if(String(url).split('?')[0].endsWith('users.php') && opts.method==='POST'){
          if(window.accountSaveFailed)return Promise.resolve({ok:false,status:409,json:async()=>({error:'Email is already in use.'})});
          return Promise.resolve({ok:true,status:201,json:async()=>({ok:true,id:42})});
        }
        return original(url,opts);
      };
    }""")
    admin.evaluate("document.querySelector('[data-view=users]').click()")
    admin.wait_for_selector('#new-user-btn')
    admin.locator('#new-user-btn').click()
    admin.wait_for_selector('#u-name')
    admin.locator('#u-name').fill('Taylor Sample')
    admin.locator('#u-email').fill('taylor@example.test')
    admin.locator('#u-pass').fill('example-long-temporary-password')
    admin.locator('.prompt-modal').screenshot(path=str(ROOT/'docs/screenshots/v3-public-admin-dialog-light.png'))
    admin.locator('#prompt-confirm').click()
    admin.wait_for_selector('#prompt-error:visible')
    assert 'already in use' in admin.locator('#prompt-error').inner_text()
    assert admin.locator('#u-name').input_value()=='Taylor Sample'
    assert admin.locator('#u-pass').input_value()=='example-long-temporary-password'
    admin.evaluate('window.accountSaveFailed=false')
    admin.locator('#prompt-confirm').click()
    admin.wait_for_selector('#prompt-modal.hidden',state='attached')
    assert not errors,errors
    admin.close()
    expired=browser.new_page(viewport={'width':1280,'height':800})
    expired.on('pageerror',lambda e:errors.append(str(e)))
    load_ui(expired,authenticated=True);expired.evaluate(MOCK)
    expired.locator('.v3-work-task[data-task-id="24"] .v3-work-task-open').click()
    expired.evaluate('window.fulfillTask(24)')
    expired.wait_for_selector('#tm-review-request')
    expired.locator('#tm-review-request').click()
    expired.locator('#p-review-note').fill('Save before session expiry')
    expired.evaluate("window.reviewMode='expired'")
    expired.locator('#prompt-confirm').click()
    expired.wait_for_selector('#auth-screen:visible')
    assert expired.locator('#task-modal').is_hidden()
    assert expired.locator('#prompt-modal').is_hidden()
    assert expired.locator('#app').is_hidden()
    assert expired.locator('#app').get_attribute('aria-hidden') is None
    assert expired.evaluate('document.activeElement.getAttribute("name")')=='email'
    assert not errors,errors
    expired.close();browser.close()
    print('PASS expired sessions dismiss every modal and return focus to secure sign-in')
    print('PASS admin account creation preserves a rejected form for safe retry')
    print('PASS mobile dialog boundaries and zero uncaught script errors; release UI polish verified (mocked APIs)')
