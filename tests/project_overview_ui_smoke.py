"""v2.1 project overview across theme, navigation, task actions, project changes and mobile.
Mocks authenticated API responses; live MySQL authorization is covered separately in CI.
"""
from pathlib import Path
import re
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parent.parent
fixture=ROOT/'tests/design_ui_smoke.py'
context={'__file__':str(fixture)}
exec(compile(fixture.read_text().split('with sync_playwright() as p:')[0],str(fixture),'exec'),context)
# Extend the project's existing mock API while keeping all older UI modules present.
MOCK=context['MOCK']
assert "lists:[{id:3,team_id:7,name:'Product launch'}]" in MOCK
MOCK=MOCK.replace("lists:[{id:3,team_id:7,name:'Product launch'}]",
                  "lists:[{id:3,team_id:7,name:'Product launch'},{id:4,team_id:7,name:'Operations'}]")
MOCK=MOCK.replace("    if(name==='mfa_status.php')",r"""
    if(name==='project_overview.php') {
      const listId=Number(params.get('list_id'));
      window.overviewRequests=(window.overviewRequests||[]).concat([{listId,teamId:Number(params.get('team_id'))}]);
      if(window.overviewError) return {ok:false,status:503,json:async()=>({error:'Temporary overview error'})};
      if(window.overviewDelay && listId===3) await new Promise(resolve=>setTimeout(resolve,320));
      const empty=listId===4;
      data={project:{id:listId,name:empty?'Operations':'Product launch'},
        tasks:{counts:empty?{total:0,completed:0,todo:0,in_progress:0,in_review:0,overdue:0,due_soon:0,estimated_count:0}:
          {total:7,completed:4,todo:1,in_progress:2,in_review:0,overdue:1,due_soon:1,estimated_count:2},
          progress:empty?0:57,health:empty?'empty':'at_risk',upcoming:empty?[]:[
            {id:24,title:'Review onboarding experience',status:'to_do',priority:'high',due_date:'2026-11-23'},
            {id:25,title:'Prepare release documentation',status:'in_progress',priority:'normal',due_date:'2026-11-24'}]},
        milestones:{counts:{total:empty?0:1,past_target:0,due_soon:empty?0:1},items:empty?[]:[
          {id:2,title:'Ready for stakeholder review',due_date:'2026-11-26'}]},
        risks:{counts:{active:empty?0:1,high_count:empty?0:1,overdue:0},items:empty?[]:[
          {id:33,title:window.overviewInject?'<img src=x onerror="window.injected=1">':'Vendor delivery risk',severity:'high',status:'open',due_date:'2026-11-25'}]},
        decisions:{pending:empty?0:1,items:empty?[]:[{id:21,title:'Confirm sign-off process',review_date:'2026-11-29'}]},
        latest_update:empty?null:{author_name:'Morgan Lee',status:'at_risk',summary:'Pilot testing is progressing. Vendor timing needs attention.',created_at:'2026-11-18 15:00:00'},
        as_of:'2026-11-20T08:00:00Z'};
    }
    if(name==='portfolio.php')data={projects:[{id:3,name:'Product launch',total_tasks:7,done_tasks:4,overdue_tasks:1,
      due_soon_tasks:1,unassigned_tasks:0,remaining_estimate_minutes:240,milestones_total:1,milestones_late:0,
      open_risks:1,critical_risks:1,progress_percent:57,health:'at_risk'}],next_after_id:null};
    if(name==='mfa_status.php')""",1)
context['MOCK']=MOCK
load_ui=context['load_ui']
paint_brand=context['paint_brand']
shots=ROOT/'docs/screenshots'
shots.mkdir(parents=True,exist_ok=True)
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    page=browser.new_page(viewport={'width':1440,'height':960})
    page.set_default_timeout(15000)
    page.on('pageerror',lambda exc:errors.append(str(exc)))
    load_ui(page,authenticated=True)
    page.locator('[data-v2-view="tasks"]').click()
    page.locator('[data-v2-tab="project-overview"]').click()
    page.wait_for_selector('.overview-metrics strong')
    assert page.locator('#view-title').inner_text()=='Project overview'
    assert page.locator('.overview-header h2').inner_text()=='Product launch'
    assert page.locator('.overview-metrics strong').all_inner_texts()==['3','1','1','1','1']
    assert page.locator('.overview-item-task').count()==2
    assert page.locator('[data-v2-tab="project-overview"]').get_attribute('aria-current')=='page'
    assert page.evaluate('document.documentElement.dataset.theme')=='light'
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth+2')
    page.wait_for_timeout(450)
    paint_brand(page)
    page.screenshot(path=str(shots/'project-overview.png'),full_page=True)
    print('PASS light overview totals, shortlist, semantic navigation, screenshot')
    # Existing task detail uses the same scope-checked endpoint as task lists.
    page.locator('[data-overview-task="24"]').click()
    page.wait_for_selector('#task-modal:not(.hidden)')
    page.locator('#task-modal .modal-close').first.click()
    # Navigation from portfolio should switch to this project's overview.
    page.locator('#v2-tools-toggle').click()
    page.locator('[data-view="portfolio"]').click()
    page.wait_for_selector('[data-portfolio-overview="3"]')
    page.locator('[data-portfolio-overview="3"]').click()
    page.wait_for_selector('.overview-header h2')
    assert page.locator('#overview-project-select').input_value()=='3'
    page.locator('#overview-project-select').select_option('4')
    page.wait_for_function("document.querySelector('.overview-header h2')?.textContent==='Operations'")
    assert page.locator('.project-overview-empty').count()>=4
    assert page.locator('.overview-metrics strong').all_inner_texts()==['0','0','0','0','0']
    # about:blank mock pages may deny sessionStorage; the app gracefully tolerates this.
    page.evaluate('window.overviewDelay=true')
    page.locator('#overview-project-select').select_option('3')
    page.locator('#overview-project-select').select_option('4')
    page.wait_for_timeout(420)
    assert page.locator('.overview-header h2').inner_text()=='Operations', 'late request overwrote current project'
    page.evaluate('window.overviewDelay=false')
    print('PASS task details, portfolio deep-link, empty states and stale-request protection')
    page.locator('#overview-project-select').select_option('3')
    page.wait_for_selector('.overview-item-task')
    # Text from the API is not interpreted as markup.
    page.evaluate('window.overviewInject=true')
    page.locator('#overview-refresh').click()
    page.wait_for_function('document.querySelector(".overview-severity")?.parentElement?.querySelector("strong")?.textContent.includes("<img")')
    assert page.locator('.overview-panel img').count()==0
    assert page.evaluate('window.injected===undefined')
    page.evaluate('window.overviewInject=false')
    page.locator('#overview-refresh').click()
    page.wait_for_selector('.overview-item-task')
    # Response errors present a Retry control, without destroying navigation.
    page.evaluate('window.overviewError=true')
    page.locator('#overview-refresh').click()
    page.wait_for_selector('#overview-retry')
    assert 'Temporary overview error' in page.locator('#project-overview').inner_text()
    page.evaluate('window.overviewError=false')
    page.locator('#overview-retry').click()
    page.wait_for_selector('.overview-header h2')
    page.locator('#appearance-preference').select_option('dark')
    page.wait_for_timeout(550)
    assert page.evaluate('document.documentElement.dataset.theme')=='dark'
    page.screenshot(path=str(shots/'project-overview-dark.png'),full_page=True)
    print('PASS HTML escaping, error/retry, dark palette without missing elements')
    def contrast(foreground,background):
        def luminance(css):
            numbers=[float(x) for x in re.findall(r'\d+(?:\.\d+)?',css)[:3]]
            assert len(numbers)==3,css
            values=[n/255 for n in numbers]
            values=[(v/12.92 if v<=0.04045 else ((v+0.055)/1.055)**2.4) for v in values]
            return .2126*values[0]+.7152*values[1]+.0722*values[2]
        hi,lo=sorted((luminance(foreground),luminance(background)),reverse=True)
        return (hi+0.05)/(lo+0.05)
    checked=0
    for palette in ('light','dark','system','ocean','forest','lavender','sunset','midnight'):
        page.locator('#appearance-preference').select_option(palette)
        page.wait_for_timeout(150)
        panel=page.evaluate('''() => {
          const panel=document.querySelector('.overview-panel');
          const text=document.querySelector('.overview-panel .overview-item-copy strong');
          const caption=document.querySelector('.overview-panel .overview-item-copy small');
          return {bg:getComputedStyle(panel).backgroundColor,fg:getComputedStyle(text).color,
            caption:getComputedStyle(caption).color};
        }''')
        for value in ('fg','caption'):
            measured=contrast(panel[value],panel['bg'])
            assert measured>=4.5,(palette,value,measured)
            checked+=1
    print(f'PASS {checked} project overview palette contrast checks (8 appearance settings)')
    mobile=browser.new_page(viewport={'width':390,'height':844},device_scale_factor=1)
    mobile.set_default_timeout(15000)
    mobile.on('pageerror',lambda exc:errors.append(str(exc)))
    load_ui(mobile,authenticated=True)
    mobile.locator('[data-mobile-view="board"]').click()
    mobile.locator('[data-v2-tab="project-overview"]').click()
    mobile.wait_for_selector('.overview-metrics strong')
    assert mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth+2')
    mobile.wait_for_timeout(400)
    paint_brand(mobile)
    mobile.screenshot(path=str(shots/'project-overview-mobile.png'),full_page=True)
    assert not errors,errors
    print('PASS responsive 390px layout, no horizontal overflow or runtime errors')
    browser.close()
print('All v2.1 project overview browser checks passed (mocked API).')
