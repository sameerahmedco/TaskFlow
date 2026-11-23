"""TaskFlow v3 inbox triage and cross-project copies; browser with mocked API data."""
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
fixture=ROOT/'tests/design_ui_smoke.py'
ctx={'__file__':str(fixture)}
exec(compile(fixture.read_text().split('with sync_playwright() as p:')[0],str(fixture),'exec'),ctx)
ctx['MOCK']=ctx['MOCK'].replace(
 "if(name==='lists.php') data={lists:[{id:3,team_id:7,name:'Product launch'}]};",
 "if(name==='lists.php') data={lists:[{id:3,team_id:7,name:'Product launch'},{id:4,team_id:7,name:'Design system'}]};")
ctx['MOCK']=ctx['MOCK'].replace("{id:24,title:'Review onboarding experience'","{id:24,list_id:3,title:'Review onboarding experience'")
load_ui=ctx['load_ui']; paint_brand=ctx['paint_brand']
INTERCEPT=r'''() => {
  const source=window.fetch;
  window.inboxCalls=[];window.copyCalls=[];window.failNextInbox=false;
  window.inboxRows=[
    {id:42,category:'new_tasks',title:'Launch checklist updated',body:'Check release readiness this week.',is_read:false,created_at:'2026-11-22 09:00:00'},
    {id:41,category:'messages',title:'Team message',body:'Project channel update.',is_read:false,created_at:'2026-11-21 14:20:00'},
    {id:40,category:'mentions',title:'A colleague mentioned you',body:'<img src=x onerror="window.injected=1"> Please review.',is_read:true,created_at:'2026-11-20 10:00:00'}
  ];
  window.fetch=async(url,opts={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const method=opts.headers?.['X-HTTP-Method-Override']||opts.method||'GET';
    const params=new URL(String(url),'http://localhost/').searchParams;
    const body=opts.body?JSON.parse(opts.body):{};
    if(name==='inbox.php'){
      window.inboxCalls.push({method,body,category:params.get('category'),q:params.get('q')});
      if(method==='POST'){
        if(window.failNextInbox){window.failNextInbox=false;return {ok:false,status:409,json:async()=>({error:'Batch rejected; nothing saved'})};}
        if(body.action==='read')window.inboxRows.find(r=>r.id===body.id).is_read=true;
        if(body.action==='unread')window.inboxRows.find(r=>r.id===body.id).is_read=false;
        if(['read_selected','unread_selected'].includes(body.action)){
          for(const r of window.inboxRows)if(body.ids.includes(r.id))r.is_read=body.action==='read_selected';
        }
        if(body.action==='read_all')window.inboxRows.forEach(r=>r.is_read=true);
      }
      const count=window.inboxRows.filter(r=>!r.is_read).length;
      const q=(params.get('q')||'').toLowerCase(),category=params.get('category')||'';
      const result=window.inboxRows.filter(r=>
        (!category||r.category===category) &&(!q||(r.title+' '+r.body).toLowerCase().includes(q))&&
        (params.get('unread')!=='1'||!r.is_read));
      return {ok:true,status:200,json:async()=>({ok:true,updated_count:body.ids?.length||1,unread_count:count,
        notifications:result,next_before_id:null})};
    }
    if(name==='duplicate.php'&&method==='POST'){
      window.copyCalls.push(body);
      if(body.list_id===99)return {ok:false,status:403,json:async()=>({error:'Destination project must belong to the same team'})};
      return {ok:true,status:201,json:async()=>({ok:true,id:77,title:'Review onboarding experience (copy)',list_id:body.list_id||3})};
    }
    return source(url,opts);
  };
}'''

def visit_inbox(page):
    page.evaluate("document.querySelector('[data-view=inbox]').click()")
    page.wait_for_selector('.inbox-item')

with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    page=browser.new_page(viewport={'width':1366,'height':900})
    page.set_default_timeout(15000)
    page.on('pageerror',lambda e:errors.append(str(e)))
    load_ui(page,authenticated=True)
    page.evaluate(INTERCEPT)
    visit_inbox(page)
    page.wait_for_function('document.querySelectorAll(".inbox-item").length===3')
    assert page.locator('.inbox-item img').count()==0, 'notification HTML must be escaped'
    assert page.evaluate('window.injected===undefined')
    assert page.locator('.inbox-category').count()==3
    page.locator('#inbox-category').select_option('messages')
    page.wait_for_function('document.querySelectorAll(".inbox-item").length===1')
    assert 'Team message' in page.locator('.inbox-item').inner_text()
    assert page.evaluate("window.inboxCalls.some(c=>c.method==='GET'&&c.category==='messages')")
    page.locator('#inbox-category').select_option('')
    page.wait_for_function('document.querySelectorAll(".inbox-item").length===3')
    page.locator('#inbox-search').fill('release')
    page.wait_for_function('document.querySelectorAll(".inbox-item").length===1')
    assert 'Launch checklist' in page.locator('.inbox-item').inner_text()
    assert page.evaluate("window.inboxCalls.some(c=>c.q==='release')")
    page.locator('#inbox-search').fill('')
    page.wait_for_function('document.querySelectorAll(".inbox-item").length===3')
    page.locator('#inbox-unread').check()
    page.wait_for_function('document.querySelectorAll(".inbox-item").length===2')
    page.locator('#inbox-unread').uncheck()
    page.wait_for_function('document.querySelectorAll(".inbox-item").length===3')
    print('PASS inbox categories, literal search, unread filter and HTML escaping')
    page.evaluate("window.inboxRows.find(r=>r.id===40).body='Please review the project plan with the team.'")
    page.locator('#inbox-category').select_option('mentions')
    page.wait_for_function('document.querySelectorAll(".inbox-item").length===1')
    page.locator('#inbox-category').select_option('')
    page.wait_for_function('document.querySelectorAll(".inbox-item").length===3')

    page.locator('#inbox-select-page').check()
    assert '3 selected' in page.locator('#inbox-selected-count').inner_text()
    page.evaluate("document.getElementById('toast')?.classList.add('hidden')")
    page.screenshot(path=str(ROOT/'docs/screenshots/v3-inbox-bulk-selection-light.png'),full_page=True)
    page.evaluate('window.failNextInbox=true')
    page.locator('#inbox-selected-read').click()
    page.wait_for_function("document.getElementById('toast')?.textContent.includes('Batch rejected')")
    assert '3 selected' in page.locator('#inbox-selected-count').inner_text(), 'failed batch preserves selection'
    page.locator('#inbox-selected-read').click()
    page.wait_for_function("window.inboxRows.every(r=>r.is_read)")
    page.wait_for_function('document.querySelectorAll(".inbox-item").length===3')
    assert '0 selected' in page.locator('#inbox-selected-count').inner_text()
    page.locator('.inbox-row-select').nth(0).check()
    page.locator('.inbox-row-select').nth(1).check()
    page.locator('#inbox-selected-unread').click()
    page.wait_for_function("window.inboxRows.filter(r=>!r.is_read).length===2")
    assert page.evaluate("window.inboxCalls.some(c=>c.method==='POST'&&c.body.action==='read_selected'&&c.body.ids.length===3)")
    assert page.evaluate("window.inboxCalls.some(c=>c.method==='POST'&&c.body.action==='unread_selected'&&c.body.ids.length===2)")
    page.locator('.inbox-read').first.click()
    page.wait_for_selector('.inbox-item:not(.unread)')
    page.locator('.inbox-unread').first.click()
    page.wait_for_function("window.inboxCalls.some(c=>c.method==='POST'&&c.body.action==='unread')")
    # Keep the adversarial HTML fixture in the test, but use neutral copy in release screenshots.
    page.evaluate("window.inboxRows.find(r=>r.id===40).body='Please review the latest project plan and share your thoughts.'")
    page.locator('#inbox-category').select_option('mentions')
    page.wait_for_function('document.querySelectorAll(".inbox-item").length===1')
    page.locator('#inbox-category').select_option('')
    page.wait_for_function('document.querySelectorAll(".inbox-item").length===3')
    page.evaluate("document.getElementById('toast')?.classList.add('hidden')")
    page.screenshot(path=str(ROOT/'docs/screenshots/v3-inbox-triage-light.png'),full_page=True)
    page.locator('#appearance-preference').select_option('charcoal')
    page.wait_for_timeout(200)
    page.screenshot(path=str(ROOT/'docs/screenshots/v3-inbox-triage-charcoal.png'),full_page=True)
    page.locator('#appearance-preference').select_option('black')
    page.wait_for_timeout(200)
    page.screenshot(path=str(ROOT/'docs/screenshots/v3-inbox-triage-black.png'),full_page=True)
    print('PASS inbox batch read/unread, error recovery, per-item actions, light/charcoal/black')

    page.locator('#appearance-preference').select_option('light')
    page.locator('[data-v2-view="my-work"]').click()
    page.wait_for_selector('#my-work-results .search-result')
    page.locator('#my-work-results .search-result').first.click()
    page.wait_for_selector('#tm-copy-project')
    page.locator('#tm-copy-project').click()
    page.wait_for_selector('#p-copy-list')
    assert page.locator('#p-copy-list option[value="4"]').count()==1
    assert page.locator('#p-copy-list option[value="3"]').count()==0
    assert 'without assignees or dates' in page.locator('#prompt-body').inner_text()
    page.evaluate("document.getElementById('toast')?.classList.add('hidden')")
    page.screenshot(path=str(ROOT/'docs/screenshots/v3-copy-to-project-light.png'),full_page=True)
    page.locator('#p-copy-list').select_option('4')
    page.locator('#prompt-confirm').click()
    page.wait_for_function("window.copyCalls.some(c=>c.id===24&&c.list_id===4)")
    page.wait_for_selector('#prompt-modal.hidden',state='attached')
    print('PASS copy to same-team project with preserved legacy duplication option')

    mobile=browser.new_page(viewport={'width':390,'height':844})
    mobile.set_default_timeout(15000)
    mobile.on('pageerror',lambda e:errors.append(str(e)))
    load_ui(mobile,authenticated=True)
    mobile.evaluate(INTERCEPT)
    visit_inbox(mobile)
    mobile.wait_for_selector('.inbox-item')
    assert mobile.evaluate('document.documentElement.scrollWidth<=window.innerWidth+2')
    mobile.screenshot(path=str(ROOT/'docs/screenshots/v3-inbox-triage-mobile.png'),full_page=True)
    assert not errors,errors
    print('PASS mobile inbox fits viewport without horizontal overflow and zero JS errors')
    browser.close()
