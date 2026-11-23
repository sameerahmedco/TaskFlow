"""TaskFlow v3: bulk due-date/assignment actions and compact mobile selection.
Uses mocked APIs only; SQL integration and real authorization still need staging.
"""
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
fixture=ROOT/'tests/design_ui_smoke.py'
ctx={'__file__':str(fixture)}
exec(compile(fixture.read_text().split('with sync_playwright() as p:')[0],str(fixture),'exec'),ctx)
load_ui=ctx['load_ui'];paint_brand=ctx['paint_brand']
MOCK_BULK=r'''() => {
 const original=window.fetch;
 window.batchCalls=[];
 window.fetch=async(url,opts={})=>{
  const name=String(url).split('?')[0].split('/').pop();
  const method=opts.headers?.['X-HTTP-Method-Override']||opts.method||'GET';
  const body=opts.body?JSON.parse(opts.body):{};
  if(name==='users.php')return {ok:true,status:200,json:async()=>({users:[
    {id:1,name:'Morgan Lee',team_id:7,is_active:1,roles:['administrator']},
    {id:2,name:'Avery Chen',team_id:7,is_active:1,roles:['member']},
    {id:3,name:'Inactive Member',team_id:7,is_active:0,roles:['member']},
    {id:4,name:'Different Team',team_id:8,is_active:1,roles:['member']}
  ]})};
  if(name==='bulk.php'&&method==='POST'){
    window.batchCalls.push({...body});
    if(body.field==='due_date'&&body.value==='2027-01-01')return {ok:false,status:409,json:async()=>({error:'A selected task starts after the new due date. No changes were saved.'})};
    return {ok:true,status:200,json:async()=>({ok:true,changed:body.task_ids.length,selected:body.task_ids.length,recurring_created:0})};
  }
  return original(url,opts);
 };
}'''

def view_tasks(page):
    page.evaluate("document.querySelector('.nav-item[data-view=tasks]').click()")
    page.wait_for_selector('.bulk-row-check')
    assert page.locator('.bulk-row-check').count()>=2

def select_two(page):
    page.locator('.bulk-row-check').nth(0).check()
    page.locator('.bulk-row-check').nth(1).check()
    assert page.locator('#bulk-count').inner_text()=='2 selected'

with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    admin=browser.new_page(viewport={'width':1440,'height':940})
    admin.set_default_timeout(14000)
    admin.on('pageerror',lambda e:errors.append(str(e)))
    admin.on('dialog',lambda dialog:dialog.accept())
    load_ui(admin,authenticated=True)
    admin.evaluate(MOCK_BULK)
    view_tasks(admin)
    select_two(admin)
    admin.locator('#bulk-field').select_option('due_date')
    assert admin.locator('#bulk-date').is_visible()
    assert admin.locator('#bulk-apply').is_disabled()
    admin.locator('#bulk-date').fill('2027-01-01')
    assert admin.locator('#bulk-apply').is_enabled()
    admin.locator('#bulk-apply').click()
    admin.wait_for_function("window.batchCalls.some(b=>b.field==='due_date'&&b.value==='2027-01-01')")
    assert admin.locator('#bulk-count').inner_text()=='2 selected', 'failed batch must keep selected tasks'
    assert 'No changes were saved' in admin.locator('#toast').inner_text()
    admin.locator('#bulk-date').fill('2027-06-18')
    admin.locator('#bulk-apply').click()
    admin.wait_for_function("window.batchCalls.some(b=>b.field==='due_date'&&b.value==='2027-06-18'&&b.task_ids.length===2)")
    admin.wait_for_selector('.bulk-row-check')
    print('PASS: date validation, atomic rejection feedback and successful bulk due-date payload')
    select_two(admin)
    admin.locator('#bulk-field').select_option('due_date_clear')
    assert admin.locator('#bulk-date').is_hidden()
    admin.locator('#bulk-apply').click()
    admin.wait_for_function("window.batchCalls.some(b=>b.field==='due_date_clear'&&b.value===null&&b.task_ids.length===2)")
    admin.wait_for_selector('.bulk-row-check')
    print('PASS: clear due dates produces null value rather than empty string')
    select_two(admin)
    admin.locator('#bulk-field').select_option('assignee_add')
    assert admin.locator('#bulk-teammate').is_visible()
    assert admin.locator('#bulk-apply').is_disabled()
    assert admin.locator('#bulk-teammate option[value="2"]').count()==1
    assert admin.locator('#bulk-teammate option[value="3"]').count()==0
    assert admin.locator('#bulk-teammate option[value="4"]').count()==0
    admin.locator('#bulk-teammate').select_option('2')
    admin.locator('#bulk-apply').click()
    admin.wait_for_function("window.batchCalls.some(b=>b.field==='assignee_add'&&b.value===2&&b.task_ids.length===2)")
    admin.wait_for_selector('.bulk-row-check')
    print('PASS: manager can add active same-team assignee across selected tasks')
    select_two(admin)
    admin.locator('#bulk-field').select_option('assignee_remove')
    admin.locator('#bulk-teammate').select_option('2')
    admin.locator('#bulk-apply').click()
    admin.wait_for_function("window.batchCalls.some(b=>b.field==='assignee_remove'&&b.value===2)")
    admin.wait_for_selector('.bulk-row-check')
    select_two(admin)
    admin.locator('#bulk-field').select_option('assignee_add')
    admin.locator('#bulk-teammate').select_option('2')
    paint_brand(admin)
    admin.evaluate("document.getElementById('toast')?.classList.add('hidden')")
    admin.screenshot(path=str(ROOT/'docs/screenshots/v3-bulk-productivity-light.png'),full_page=True)
    admin.locator('#appearance-preference').select_option('charcoal')
    admin.wait_for_timeout(120)
    assert admin.locator('#bulk-teammate').is_visible()
    assert admin.locator('#bulk-teammate').evaluate('(el)=>getComputedStyle(el).color')!='rgba(0, 0, 0, 0)'
    admin.locator('#appearance-preference').select_option('black')
    admin.wait_for_timeout(120)
    assert admin.locator('#bulk-field').is_visible()
    admin.screenshot(path=str(ROOT/'docs/screenshots/v3-bulk-productivity-black.png'),full_page=True)
    admin.locator('#bulk-clear-selection').click()
    assert admin.locator('#bulk-count').inner_text()=='0 selected'
    assert admin.locator('#bulk-apply').is_disabled()
    assert admin.locator('.bulk-row-check:checked').count()==0
    admin.locator('.bulk-row-check').nth(0).check()
    admin.locator('.bulk-row-check').nth(2).click(modifiers=['Shift'])
    assert admin.locator('.bulk-row-check:checked').count()==3, 'Shift-click must select the whole range'
    admin.locator('#bulk-clear-selection').click()
    print('PASS: remove assignee, Shift-click range, and clear selection without navigation')
    # Browser at 390px: action picker and buttons may wrap but must not overflow viewport.
    mobile=browser.new_page(viewport={'width':390,'height':844})
    mobile.set_default_timeout(14000)
    mobile.on('pageerror',lambda e:errors.append(str(e)))
    mobile.on('dialog',lambda dialog:dialog.accept())
    ctx['MOCK']=ctx['MOCK'].replace("roles:['administrator']","roles:['member']")
    load_ui(mobile,authenticated=True)
    mobile.evaluate(MOCK_BULK)
    view_tasks(mobile)
    assert mobile.locator('#bulk-field option[value="assignee_add"]').count()==0
    assert mobile.locator('#bulk-field option[value="assignee_remove"]').count()==0
    assert mobile.locator('#bulk-field option[value="due_date"]').count()==1
    mobile.locator('.bulk-row-check').nth(0).check()
    mobile.locator('#bulk-field').select_option('due_date')
    mobile.locator('#bulk-date').fill('2027-07-19')
    assert mobile.locator('#bulk-apply').is_enabled()
    assert mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth+2')
    mobile.screenshot(path=str(ROOT/'docs/screenshots/v3-bulk-productivity-mobile.png'),full_page=True)
    print('PASS: mobile layout, members can edit dates but not team assignments')
    assert not errors,errors
    browser.close()
print('All TaskFlow v3 batch-action UI smoke checks passed (mocked APIs).')
