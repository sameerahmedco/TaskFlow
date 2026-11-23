"""v3 theme/UX acceptance with synthetic API fixtures; no live DB."""
from pathlib import Path
import os
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
contrast=ROOT/'tests/theme_contrast_ui_smoke.py'
ns={'__file__':str(contrast)}
exec(compile(contrast.read_text().split('with sync_playwright() as play:')[0],str(contrast),'exec'),ns)
load_fixture=ns['load_fixture']; JS=ns['JS']
SHOTS=ROOT/'docs/screenshots';SHOTS.mkdir(parents=True,exist_ok=True)
with sync_playwright() as play:
    browser=play.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    checks=0
    for palette in os.environ.get('TASKFLOW_THEME','charcoal,black').split(','):
        for view,selector in [('my-work','.v2-task-stream .work-section-head h3'),('board','.board-task-open'),
            ('project-decisions','.decision-card h4'),('project-risks','.risk-card h4')]:
            page=browser.new_page(viewport={'width':1440,'height':900})
            page.set_default_timeout(13000)
            errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
            if view in ('my-work','board'):fixture=('design_ui_smoke.py','load_ui')
            elif view=='project-decisions':fixture=('project_decisions_ui_smoke.py','initialize')
            else: fixture=('project_risks_ui_smoke.py','init')
            load_fixture(*fixture,page)
            page.locator('#appearance-preference').select_option(palette)
            assert page.evaluate('document.documentElement.dataset.theme')=='dark'
            assert page.evaluate('document.documentElement.dataset.palette')==palette
            assert page.locator('#appearance-preference').input_value()==palette
            if view!='my-work':
                nav=page.locator(f'.nav-item[data-view="{view}"]')
                if not nav.is_visible():page.locator('#v2-tools-toggle').click()
                nav.click()
            page.wait_for_selector(selector)
            for test_selector in (selector,'.topbar h1','.topbar-overline'):
                result=page.evaluate(JS,test_selector)
                assert not result.get('missing'),(palette,view,test_selector,result)
                assert result['ratio']>=4.5,(palette,view,test_selector,result)
                checks+=1
            if view=='my-work':
                bg=page.evaluate('getComputedStyle(document.querySelector(".view-container")).backgroundColor')
                if palette=='black':assert bg=='rgb(0, 0, 0)',bg
                assert page.locator('#v3-project-count').inner_text()=='1'
                assert page.locator('.v3-project-link').count()==1
                assert page.locator('.v3-project-link').first.inner_text().strip().endswith('Product launch')
                page.wait_for_timeout(220)
                page.screenshot(path=str(SHOTS/f'v3-{palette}-workspace.png'),full_page=True)
            if view=='board':
                page.wait_for_timeout(220)
                page.screenshot(path=str(SHOTS/f'v3-{palette}-board.png'),full_page=True)
            assert not errors,(palette,view,errors)
            page.close()
        print('PASS',palette,'theme surfaces, project rail, dark canvas and core views',flush=True)
    sign_in=browser.new_page(viewport={'width':1440,'height':900})
    load_fixture('design_ui_smoke.py','load_ui',sign_in,False)
    sign_in.evaluate("window.fetch=async()=>({ok:false,status:401,json:async()=>({error:'Not authenticated'})})")
    sign_in.add_script_tag(content=(ROOT/'assets/js/app.js').read_text())
    sign_in.wait_for_selector('#auth-screen:not(.hidden)')
    assert sign_in.locator('[data-theme-choice]').count()==10
    sign_in.locator('.auth-theme-disclosure summary').click()
    for palette in ('charcoal','black'):
        sign_in.locator(f'[data-theme-choice="{palette}"]').click()
        assert sign_in.locator(f'[data-theme-choice="{palette}"]').get_attribute('aria-pressed')=='true'
        for sel in ('.auth-heading','.auth-subtitle','.auth-form label'):
            result=sign_in.evaluate(JS,sel)
            assert not result.get('missing') and result['ratio']>=4.5, (palette,sel,result)
            checks+=1
    sign_in.close()
    browser.close()
    print(f'PASS {checks} named text contrast checks and signed-out/signed-in theme selectors',flush=True)
