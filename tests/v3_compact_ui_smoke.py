"""TaskFlow v3 compact-layout checks across major themes, mobile and many projects.
Browser uses synthetic API responses; not a replacement for live MySQL tests.
"""
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
src=ROOT/'tests/design_ui_smoke.py'
scope={'__file__':str(src)}
exec(compile(src.read_text().split('with sync_playwright() as p:')[0],str(src),'exec'),scope)
load=scope['load_ui']; paint=scope['paint_brand']
contrast_src=ROOT/'tests/theme_contrast_ui_smoke.py'
contrast_scope={'__file__':str(contrast_src)}
exec(compile(contrast_src.read_text().split('with sync_playwright() as play:')[0],str(contrast_src),'exec'),contrast_scope)
CHECK_CONTRAST=contrast_scope['JS']

with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    for palette in ('light','charcoal','black'):
        page=browser.new_page(viewport={'width':1440,'height':900},device_scale_factor=1)
        page.set_default_timeout(13000)
        errors=[];page.on('pageerror',lambda err:errors.append(str(err)))
        load(page,authenticated=True)
        page.locator('#appearance-preference').select_option(palette)
        page.wait_for_function('document.querySelector("#v2-task-count").textContent.includes("loaded")')
        page.wait_for_timeout(250)
        assert page.locator('.v3-work-task').count()==3
        assert page.locator('.v3-work-columns').is_visible()
        assert page.locator('.v2-home-aside').count()==0
        assert page.locator('[data-work-filter]').count()==5
        assert page.locator('[data-work-filter="all"]').get_attribute('aria-pressed')=='true'
        assert page.locator('#v3-work-open-count').inner_text()=='3'
        assert page.locator('#v2-view-tabs').is_hidden(), 'Home repeats navigation tabs'
        assert page.locator('.v3-work-task').first.evaluate('(el)=>el.getBoundingClientRect().height')<=62, 'Rows not compact'
        assert page.locator('.sidebar').evaluate('(el)=>el.getBoundingClientRect().width')<=235, 'Sidebar is too wide'
        assert page.locator('.topbar').evaluate('(el)=>el.getBoundingClientRect().height')<=60, 'Header is too tall'
        for sel in ('.v3-work-task strong','.v3-work-task small','.v3-work-columns span','.v3-work-subheading'):
            c=page.evaluate(CHECK_CONTRAST,sel)
            assert not c.get('missing'),(palette,sel,c)
            assert c['ratio']>=4.5,(palette,sel,c)
        if palette=='black':
            assert page.evaluate('getComputedStyle(document.querySelector("#view-container")).backgroundColor')=='rgb(0, 0, 0)'
        # Verify a single-click filter/reset, not just the CSS appearance.
        page.locator('[data-work-filter="overdue"]').click()
        assert page.locator('#my-work-due').input_value()=='overdue'
        page.locator('[data-work-filter="all"]').click()
        assert page.locator('#my-work-due').input_value()=='all'
        assert page.locator('[data-work-filter="all"]').get_attribute('aria-pressed')=='true'
        page.locator('.v2-filter-details summary').click()
        page.locator('#my-work-status').select_option('done')
        assert page.locator('[data-work-filter="all"]').get_attribute('aria-pressed')=='false'
        page.locator('[data-work-filter="all"]').click()
        assert page.locator('#my-work-status').input_value()=='open'
        page.locator('.v2-filter-details summary').click()
        page.wait_for_selector('.v3-work-task')
        # Stress project rail with 14 current-team projects. Search and expand are local only.
        projects=[{'id':i,'name':f'Project {i:02}'} for i in range(1,15)]
        page.evaluate('projects=>document.dispatchEvent(new CustomEvent("taskflow:project-context",{detail:{team_id:7,current_id:3,projects}}))',projects)
        assert page.locator('#v3-project-count').inner_text()=='14'
        assert page.locator('.v3-project-link').count()==8
        page.locator('.v3-project-more').click()
        assert page.locator('.v3-project-link').count()==14
        page.locator('#v3-project-filter').fill('project 12')
        assert page.locator('.v3-project-link').count()==1
        assert 'Project 12' in page.locator('.v3-project-link').inner_text()
        page.locator('#v3-project-filter').fill('')
        page.locator('.v3-project-more').click()
        assert page.locator('.v3-project-link').count()==8
        page.evaluate('()=>document.dispatchEvent(new CustomEvent("taskflow:project-context",{detail:{team_id:7,current_id:3,projects:[{id:3,name:"Product launch"}]}}))')
        page.wait_for_timeout(180)
        paint(page)
        page.screenshot(path=str(ROOT/f'docs/screenshots/v3-compact-{palette}-home.png'),full_page=True)
        assert not errors,(palette,errors)
        page.close()
        print('PASS',palette,'compact Home, rows, filters, project rail and text contrast',flush=True)
    mobile=browser.new_page(viewport={'width':390,'height':844},device_scale_factor=1,is_mobile=True,has_touch=True)
    errors=[];mobile.on('pageerror',lambda err:errors.append(str(err)))
    load(mobile,authenticated=True)
    mobile.wait_for_selector('.v3-work-task')
    mobile.wait_for_timeout(200)
    assert mobile.locator('.v3-work-columns').is_hidden()
    assert mobile.locator('.v3-work-task-priority').first.is_visible()
    assert mobile.locator('.v3-work-task-status').first.is_visible()
    assert mobile.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'),'Mobile page horizontal overflow'
    assert mobile.locator('#mobile-dock').is_visible()
    mobile.locator('[data-work-filter="today"]').click()
    assert mobile.locator('#my-work-due').input_value()=='today'
    mobile.locator('[data-work-filter="all"]').click()
    mobile.wait_for_selector('.v3-work-task')
    paint(mobile)
    mobile.screenshot(path=str(ROOT/'docs/screenshots/v3-compact-mobile-home.png'),full_page=True)
    assert not errors,errors
    print('PASS mobile task rows, filters and no horizontal overflow',flush=True)
    browser.close()
print('All TaskFlow v3 compact UI checks passed (mocked API)',flush=True)
