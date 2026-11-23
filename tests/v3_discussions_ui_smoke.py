"""TaskFlow v3 discussion replies, edited comments and mentions (mocked fetch)."""
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
fixture=ROOT/'tests/design_ui_smoke.py'
ctx={'__file__':str(fixture)}
exec(compile(fixture.read_text().split('with sync_playwright() as p:')[0],str(fixture),'exec'),ctx)
load_ui=ctx['load_ui']
contrast_source=ROOT/'tests/theme_contrast_ui_smoke.py'
contrast_ctx={'__file__':str(contrast_source)}
exec(compile(contrast_source.read_text().split('with sync_playwright() as play:')[0],str(contrast_source),'exec'),contrast_ctx)
CONTRAST_JS=contrast_ctx['JS']
MOCK=r'''() => {
  const original=window.fetch;
  window.discussionRows=[
   {id:51,content:'The setup needs review.',user_id:2,name:'Avery Chen',avatar_color:'#2f9fbb',roles:['member'],created_at:'2026-11-17 09:00',edit_version:1,parent_comment_id:null,edited_at:null},
   {id:52,content:'I can review this tomorrow.',user_id:1,name:'Morgan Lee',avatar_color:'#087e73',roles:['administrator'],created_at:'2026-11-17 10:00',edit_version:1,parent_comment_id:51,parent_author_name:'Avery Chen',edited_at:null}
  ];
  window.discussionCalls=[];
  window.failEdit=false;
  window.fetch=async(url,opts={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const params=new URL(String(url),'http://localhost/').searchParams;
    const method=opts.headers?.['X-HTTP-Method-Override']||opts.method||'GET';
    const body=opts.body?JSON.parse(opts.body):{};
    if(name==='users.php'){
      return {ok:true,status:200,json:async()=>({users:[
        {id:1,name:'Morgan Lee',avatar_color:'#087e73',roles:['administrator'],team_id:7,is_active:1},
        {id:2,name:'Avery Chen',avatar_color:'#2f9fbb',roles:['member'],team_id:7,is_active:1}
      ]})};
    }
    if(name==='task.php'&&method==='GET'){
      const admin={id:1,name:'Morgan Lee',avatar_color:'#087e73',roles:['administrator'],team_id:7};
      const task={id:24,title:'Review onboarding experience',description:'Validate team setup.',team_id:7,list_id:3,
        created_by:1,status:'to_do',priority:'high',start_date:null,due_date:'2026-12-05',estimate_minutes:100,
        recurrence_rule:'none',assignees:[admin],tags:[],subtasks:[],checklist:[],comments:window.discussionRows.map(c=>({...c})),
        comments_next_before_id:40,recent_activity:[],dependencies:{depends_on:[],blocks:[]},unfinished_blockers:0,
        is_favorite:false,is_watching:false,watcher_count:0,logged_minutes:0,is_recurring_weekly:0};
      return {ok:true,status:200,json:async()=>({task})};
    }
    if(name==='comments.php'){
      window.discussionCalls.push({method,body,params:Object.fromEntries(params.entries())});
      if(method==='GET') return {ok:true,status:200,json:async()=>({comments:[
        {id:38,content:'An older decision.',user_id:2,name:'Avery Chen',avatar_color:'#2f9fbb',roles:['member'],created_at:'2026-11-13',edit_version:1,parent_comment_id:null,edited_at:null}
      ],next_before_id:null})};
      if(method==='PUT') {
        if(window.failEdit){window.failEdit=false;return {ok:false,status:409,json:async()=>({error:'Comment changed. Reload before editing.'})};}
        const item=window.discussionRows.find(r=>r.id===body.id);item.content=body.content;
        item.edit_version++;item.edited_at='2026-11-18 15:00';
      }
      if(method==='POST'){
        window.discussionRows.push({id:53,content:body.content,user_id:1,name:'Morgan Lee',roles:['administrator'],avatar_color:'#087e73',
          parent_comment_id:body.parent_id,parent_author_name:'Avery Chen',created_at:'2026-11-18 15:01',edit_version:1,edited_at:null});
      }
      if(method==='DELETE')window.discussionRows=window.discussionRows.filter(c=>c.id!==body.id);
      return {ok:true,status:200,json:async()=>({ok:true})};
    }
    if(name==='worklog.php')return {ok:true,status:200,json:async()=>({entries:[],minutes_total:0,can_log:false})};
    if(name==='timer.php')return {ok:true,status:200,json:async()=>({active:null,server_now:new Date().toISOString()})};
    if(name==='attachments.php')return {ok:true,status:200,json:async()=>({attachments:[]})};
    return original(url,opts);
  };
}'''

def open_discussion(page):
    page.locator('#my-work-results .v3-work-task-open').first.click()
    page.wait_for_selector('#tm-tab-comments')
    page.locator('#tm-tab-comments').click()
    page.wait_for_selector('#tm-panel-comments:not([hidden])')

def test(browser,theme,viewport,screenshot_name):
    page=browser.new_page(viewport=viewport)
    page.set_default_timeout(15000)
    errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    load_ui(page,True)
    page.evaluate(MOCK)
    page.locator('#appearance-preference').select_option(theme)
    assert page.evaluate('document.documentElement.dataset.palette')==theme
    open_discussion(page)
    assert page.locator('[data-comment-id="51"] [data-comment-edit]').count()==0, 'not owner: cannot edit'
    assert page.locator('[data-comment-id="52"] [data-comment-edit]').count()==1
    assert page.locator('.task-comment-reply').count()==1
    for selector in ('.task-discussion-head h3','.comment-text','.comment-header strong',
                     '.task-comment-label','.task-comment-composer-actions label','.comment-actions button'):
        result=page.evaluate(CONTRAST_JS,selector)
        assert not result.get('missing') and result['ratio']>=4.5,(theme,selector,result)
    # Earlier history is retrieved only on demand and prepended.
    page.locator('#tm-comment-older').click()
    page.wait_for_selector('[data-comment-id="38"]')
    assert page.locator('#tm-comment-history').is_hidden()
    page.locator('[data-comment-id="52"] [data-comment-edit]').click()
    page.locator('[data-comment-edit-form="52"] textarea').fill('Revised plan <img src=x onerror="window.injected=1">')
    page.evaluate('window.failEdit=true')
    page.locator('[data-comment-edit-form="52"] button[type=submit]').click()
    page.wait_for_function('window.discussionCalls.some(c=>c.method==="PUT")')
    assert page.locator('[data-comment-edit-form="52"]').is_visible(),'failed edit should retain draft'
    page.locator('[data-comment-edit-form="52"] button[type=submit]').click()
    page.wait_for_function('window.discussionRows.find(c=>c.id===52).edit_version===2')
    page.wait_for_selector('[data-comment-id="52"] .comment-edited')
    assert page.locator('.task-discussion-list img').count()==0
    assert page.evaluate('window.injected===undefined'),'comment body HTML must be inert'
    # Replying and picking a teammate mention must send exact parent and token.
    page.locator('[data-comment-id="51"] [data-comment-reply]').click()
    assert page.locator('#tm-reply-banner').is_visible()
    page.locator('#tm-comment-input').fill('I can follow up.')
    page.locator('#tm-mention-member').select_option('2')
    assert '@[Avery Chen]' in page.locator('#tm-comment-input').input_value()
    page.locator('#tm-add-comment button[type=submit]').click()
    page.wait_for_function('window.discussionRows.some(c=>c.id===53)')
    assert page.evaluate('window.discussionCalls.some(c=>c.method==="POST"&&c.body.parent_id===51&&c.body.content.includes("@[Avery Chen]"))')
    page.wait_for_selector('[data-comment-id="53"] .comment-reply-context')
    page.evaluate("""() => {
      window.discussionRows.find(c=>c.id===52).content='Updated the plan after reviewing feedback.';
      document.querySelector('[data-comment-id="52"] .comment-text').textContent='Updated the plan after reviewing feedback.';
    }""")
    if theme=='light' and viewport['width']>600:
        page.locator('.task-modal').screenshot(path=str(ROOT/'docs/screenshots'/screenshot_name))
    else:
        page.screenshot(path=str(ROOT/'docs/screenshots'/screenshot_name),full_page=True)
    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'mobile overflow'
    assert not errors, errors
    page.close()

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    test(browser,'light',{'width':1365,'height':1050},'v3-discussions-light.png')
    print('PASS threaded replies, comment editing conflict, mention picker, history, HTML escaping (Light)')
    test(browser,'black',{'width':1365,'height':1050},'v3-discussions-black.png')
    print('PASS Black/OLED discussion styling and interaction')
    test(browser,'light',{'width':390,'height':844},'v3-discussions-mobile.png')
    print('PASS responsive mobile discussion layout')
    browser.close()
print('All TaskFlow v3 discussion browser checks passed (mocked APIs).')
