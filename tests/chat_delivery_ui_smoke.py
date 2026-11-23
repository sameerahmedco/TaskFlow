"""Mocked browser QA for chat/DM duplicate prevention and failed-send drafts."""
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
fixture=ROOT/'tests/design_ui_smoke.py'
ctx={'__file__':str(fixture)}
exec(compile(fixture.read_text().split('with sync_playwright() as p:')[0],str(fixture),'exec'),ctx)
load_ui=ctx['load_ui']
INTERCEPT=r'''() => {
  const base=window.fetch;
  window.testChatCalls=[];window.testChatRows=[];window.testDmRows=[];
  window.holdNextChat=false;window.rejectNextChat=false;window.failNextChatRefresh=false;
  window.fetch=async(url,options={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const qs=new URL(String(url),'http://localhost/').searchParams;
    const method=options.headers?.['X-HTTP-Method-Override']||options.method||'GET';
    const payload=options.body?JSON.parse(options.body):{};
    if(name==='chat.php'){
      window.testChatCalls.push({method,payload,qs:Object.fromEntries(qs.entries())});
      if(method==='POST'){
        if(window.rejectNextChat){window.rejectNextChat=false;
          return {ok:false,status:429,json:async()=>({error:'Too many messages. Try again later.'})};}
        const save=()=>{
          const id=window.testChatRows.length+1;
          window.testChatRows.push({id,user_id:1,name:'Morgan Lee',avatar_color:'#3a8dd4',roles:['administrator'],content:payload.content,created_at:'2026-11-22'});
          return {ok:true,status:201,json:async()=>({id})};
        };
        if(window.holdNextChat){window.holdNextChat=false;
          return await new Promise(resolve=>{window.releaseHeldChat=()=>resolve(save());});}
        return save();
      }
      if(window.failNextChatRefresh && qs.has('after_id')){window.failNextChatRefresh=false;
        return {ok:false,status:503,json:async()=>({error:'Temporary read-back outage'})};}
      const after=Number(qs.get('after_id')||0);
      return {ok:true,status:200,json:async()=>({messages:window.testChatRows.filter(r=>r.id>after)})};
    }
    if(name==='dm.php'){
      if(qs.has('conversations'))return {ok:true,status:200,json:async()=>({conversations:[]})};
      if(qs.has('directory'))return {ok:true,status:200,json:async()=>({people:[{id:2,name:'Avery Chen',avatar_color:'#2f9fbb',roles:['member']} ]})};
      if(qs.has('with')){
        const after=Number(qs.get('after_id')||0);
        return {ok:true,status:200,json:async()=>({messages:window.testDmRows.filter(r=>r.id>after)})};
      }
      if(method==='POST'){
        window.testChatCalls.push({method,name,payload});
        const id=window.testDmRows.length+1;
        window.testDmRows.push({id,sender_id:1,recipient_id:payload.recipient_id,content:payload.content,created_at:'2026-11-22'});
        return {ok:true,status:201,json:async()=>({id})};
      }
    }
    return base(url,options);
  };
}'''

with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    page=browser.new_page(viewport={'width':1280,'height':860})
    page.set_default_timeout(15000)
    errors=[]
    page.on('pageerror',lambda error:errors.append(str(error)))
    load_ui(page,authenticated=True)
    page.evaluate(INTERCEPT)
    page.evaluate("document.querySelector('[data-view=chat]').click()")
    page.wait_for_selector('#chat-compose')
    page.locator('#chat-input').fill('Quality gate message')
    page.evaluate("window.holdNextChat=true;const form=document.getElementById('chat-compose');form.requestSubmit();form.requestSubmit()")
    page.wait_for_function("window.testChatCalls.filter(c=>c.method==='POST').length===1")
    assert page.locator('#chat-compose button[type=submit]').is_disabled()
    assert page.locator('#chat-input').input_value()=='Quality gate message'
    page.locator('#chat-input').fill('A new draft typed while the send was pending')
    page.evaluate('window.releaseHeldChat()')
    page.wait_for_function('window.testChatRows.length===1')
    page.wait_for_function("document.querySelector('#chat-compose button[type=submit]').disabled===false")
    assert page.locator('#chat-input').input_value()=='A new draft typed while the send was pending'
    assert page.locator('.chat-msg').count()==1
    print('PASS double-submit suppression, disabled send button, and draft preservation across slow sends')

    page.evaluate('window.rejectNextChat=true')
    page.locator('#chat-input').fill('Retry this draft only when the limit resets')
    page.locator('#chat-compose button[type=submit]').click()
    page.wait_for_function("document.getElementById('toast')?.textContent.includes('Too many messages')")
    assert page.locator('#chat-input').input_value()=='Retry this draft only when the limit resets'
    assert page.locator('#chat-compose button[type=submit]').is_enabled()
    assert page.evaluate('window.testChatRows.length')==1
    print('PASS HTTP 429 leaves unsent text ready for later retry')

    page.evaluate("window.failNextChatRefresh=true; document.getElementById('toast')?.classList.add('hidden')")
    page.locator('#chat-input').fill('Saved despite temporary read-back outage')
    page.locator('#chat-compose button[type=submit]').click()
    page.wait_for_function('window.testChatRows.length===2')
    page.wait_for_function("document.getElementById('toast')?.textContent.includes('Message sent')")
    assert page.locator('#chat-input').input_value()==''
    assert page.evaluate("window.testChatCalls.filter(c=>c.method==='POST').length") == 3
    assert page.locator('#chat-compose button[type=submit]').is_enabled()
    print('PASS read-back outage cannot present a committed send as an error')

    page.locator('[data-ctab=personal]').click()
    page.wait_for_selector('#dm-new-btn')
    page.locator('#dm-new-btn').click()
    page.wait_for_selector('.dm-directory-item')
    page.locator('.dm-directory-item').first.click()
    page.wait_for_selector('#chat-compose')
    page.locator('#chat-input').fill('One direct message')
    page.locator('#chat-compose button[type=submit]').click()
    page.wait_for_function('window.testDmRows.length===1')
    page.wait_for_function("document.querySelector('#chat-compose button[type=submit]').disabled===false")
    assert page.locator('#chat-input').input_value()==''
    assert page.locator('.chat-msg').count()==1
    assert not errors,errors
    print('PASS shared direct-message compose path; no browser errors')
    browser.close()
