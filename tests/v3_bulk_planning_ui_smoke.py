"""TaskFlow v3 bulk planning and labels (mocked API, no live MySQL)."""
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
fixture=ROOT/'tests/design_ui_smoke.py'
ctx={'__file__':str(fixture)}
exec(compile(fixture.read_text().split('with sync_playwright() as p:')[0],str(fixture),'exec'),ctx)
ctx['MOCK']=ctx['MOCK'].replace("if(name==='tags.php') data={tags:[]};",
    "if(name==='tags.php') data={tags:[{id:11,name:'Ready',color:'#0b927d'},{id:12,name:'Needs review',color:'#bd8124'}]};")
load_ui=ctx['load_ui'];paint_brand=ctx['paint_brand']
MOCK_NEW=r'''() => {
  const original=window.fetch;
  window.planningCalls=[];
  window.fetch=async(url,opts={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const method=opts.headers?.['X-HTTP-Method-Override']||opts.method||'GET';
    const body=opts.body?JSON.parse(opts.body):{};
    if(name==='bulk.php'&&method==='POST'){
      window.planningCalls.push({...body});
      if(body.field==='start_date'&&body.value==='2027-01-01')return {
        ok:false,status:409,json:async()=>({error:'A selected task is due before the new start date. No changes were saved.'})};
      return {ok:true,status:200,json:async()=>({ok:true,changed:body.task_ids.length,
        selected:body.task_ids.length,recurring_created:0})};
    }
    return original(url,opts);
  };
}'''

def visit(page):
    page.evaluate("document.querySelector('.nav-item[data-view=tasks]').click()")
    page.wait_for_selector('.bulk-row-check')

def selected(page):
    page.locator('.bulk-row-check').nth(0).check()
    page.locator('.bulk-row-check').nth(1).check()
    assert page.locator('#bulk-count').inner_text()=='2 selected'

def saved(page,field,value):
    page.wait_for_function("([f,v])=>window.planningCalls.some(b=>b.field===f&&b.value===v&&b.task_ids.length===2)",arg=[field,value])
    page.wait_for_selector('.bulk-row-check')

with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    admin=browser.new_page(viewport={'width':1440,'height':940})
    admin.set_default_timeout(15000)
    admin.on('pageerror',lambda e:errors.append('admin: '+str(e)))
    admin.on('dialog',lambda dialog:dialog.accept())
    load_ui(admin,authenticated=True)
    admin.evaluate(MOCK_NEW)
    visit(admin)
    selected(admin)
    assert admin.locator('#bulk-field optgroup').count()==5
    admin.locator('#bulk-field').select_option('start_date')
    assert admin.locator('#bulk-date').is_visible()
    admin.locator('#bulk-date').fill('2027-01-01')
    admin.locator('#bulk-apply').click()
    admin.wait_for_function("window.planningCalls.some(b=>b.field==='start_date'&&b.value==='2027-01-01')")
    assert admin.locator('#bulk-count').inner_text()=='2 selected'
    assert 'No changes were saved' in admin.locator('#toast').inner_text()
    admin.locator('#bulk-date').fill('2026-11-20')
    admin.locator('#bulk-apply').click()
    saved(admin,'start_date','2026-11-20')
    print('PASS: start date valid/rejected batch, preserved selection on conflict')
    selected(admin)
    admin.locator('#bulk-field').select_option('start_date_clear')
    assert admin.locator('#bulk-date').is_hidden()
    admin.locator('#bulk-apply').click()
    saved(admin,'start_date_clear',None)
    selected(admin)
    admin.locator('#bulk-field').select_option('estimate_minutes')
    assert admin.locator('#bulk-estimate').is_visible()
    assert admin.locator('#bulk-apply').is_disabled()
    admin.locator('#bulk-estimate').fill('24001')
    assert admin.locator('#bulk-apply').is_disabled()
    admin.locator('#bulk-estimate').fill('90')
    assert admin.locator('#bulk-apply').is_enabled()
    admin.locator('#bulk-apply').click()
    saved(admin,'estimate_minutes',90)
    selected(admin)
    admin.locator('#bulk-field').select_option('estimate_clear')
    assert admin.locator('#bulk-estimate').is_hidden()
    admin.locator('#bulk-apply').click()
    saved(admin,'estimate_clear',None)
    print('PASS: start date clearing and effort set/clear with bounded client input')
    selected(admin)
    admin.locator('#bulk-field').select_option('tag_add')
    assert admin.locator('#bulk-tag').is_visible()
    assert admin.locator('#bulk-apply').is_disabled()
    assert admin.locator('#bulk-tag option[value="11"]').count()==1
    admin.locator('#bulk-tag').select_option('11')
    admin.locator('#bulk-apply').click()
    saved(admin,'tag_add',11)
    selected(admin)
    admin.locator('#bulk-field').select_option('tag_remove')
    admin.locator('#bulk-tag').select_option('11')
    admin.locator('#bulk-apply').click()
    saved(admin,'tag_remove',11)
    print('PASS: selected team tag add/remove (no tag replacement)')
    selected(admin)
    admin.locator('#bulk-field').select_option('tag_add')
    admin.locator('#bulk-tag').select_option('12')
    paint_brand(admin)
    admin.evaluate("document.getElementById('toast')?.classList.add('hidden')")
    admin.screenshot(path=str(ROOT/'docs/screenshots/v3-bulk-planning-light.png'),full_page=True)
    admin.locator('#appearance-preference').select_option('charcoal')
    admin.wait_for_timeout(180)
    assert admin.locator('#bulk-tag').is_visible()
    admin.screenshot(path=str(ROOT/'docs/screenshots/v3-bulk-planning-charcoal.png'),full_page=True)
    admin.locator('#appearance-preference').select_option('black')
    admin.wait_for_timeout(180)
    assert admin.locator('#bulk-tag').is_visible()
    admin.screenshot(path=str(ROOT/'docs/screenshots/v3-bulk-planning-black.png'),full_page=True)
    print('PASS: Light, Charcoal and Black label picker visual states')
    member=browser.new_page(viewport={'width':390,'height':844})
    member.set_default_timeout(15000)
    member.on('pageerror',lambda e:errors.append('member: '+str(e)))
    member.on('dialog',lambda dialog:dialog.accept())
    ctx['MOCK']=ctx['MOCK'].replace("roles:['administrator']","roles:['member']")
    load_ui(member,authenticated=True)
    member.evaluate(MOCK_NEW)
    visit(member)
    assert member.locator('#bulk-field option[value="tag_add"]').count()==1
    assert member.locator('#bulk-field option[value="estimate_minutes"]').count()==1
    assert member.locator('#bulk-field option[value="assignee_add"]').count()==0
    member.locator('.bulk-row-check').first.check()
    member.locator('#bulk-field').select_option('tag_add')
    member.locator('#bulk-tag').select_option('11')
    assert member.locator('#bulk-apply').is_enabled()
    assert member.evaluate('document.documentElement.scrollWidth <= window.innerWidth+2')
    member.screenshot(path=str(ROOT/'docs/screenshots/v3-bulk-planning-mobile.png'),full_page=True)
    print('PASS: mobile and member permissions, no horizontal overflow')
    assert not errors,errors
    browser.close()
print('All TaskFlow v3 bulk planning browser checks passed (mocked APIs).')
