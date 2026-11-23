"""v3 task-first project shortcuts, dark/light UI and mobile width (synthetic fetch)."""
from pathlib import Path
from playwright.sync_api import sync_playwright
r=Path(__file__).resolve().parent.parent
f=r/'tests/design_ui_smoke.py'
ns={'__file__':str(f)}
exec(compile(f.read_text().split('with sync_playwright() as p:')[0],str(f),'exec'),ns)
load=ns['load_ui'];paint=ns['paint_brand']
with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    page=browser.new_page(viewport={'width':1440,'height':940})
    errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    load(page,authenticated=True)
    page.wait_for_function('document.querySelectorAll(".v3-project-link").length===1')
    assert page.locator('#v3-project-count').inner_text()=='1'
    assert page.locator('.v3-project-link').inner_text().strip().endswith('Product launch')
    assert page.locator('#v2-tools-drawer').is_hidden()
    page.locator('.v3-project-link').first.click()
    page.wait_for_selector('.task-table tbody tr')
    assert page.locator('#view-title').inner_text()=='Tasks'
    assert page.locator('[data-v2-tab="board"]').is_visible()
    page.locator('[data-v2-tab="board"]').click()
    page.wait_for_selector('.board-card')
    page.locator('#appearance-preference').select_option('light')
    page.wait_for_timeout(350)
    paint(page)
    page.screenshot(path=str(r/'docs/screenshots/v3-board-light.png'),full_page=True)
    assert page.evaluate('getComputedStyle(document.querySelector(".view-container")).backgroundColor !== "rgb(0, 0, 0)"')
    assert not errors,errors
    page.close()
    mobile=browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
    load(mobile,authenticated=True)
    mobile.locator('#mobile-menu-btn').click()
    mobile.wait_for_selector('.v3-project-link')
    mobile.locator('.v3-project-link').click()
    mobile.wait_for_selector('.task-table tbody tr')
    mobile.wait_for_timeout(350)
    assert mobile.evaluate('document.documentElement.scrollWidth <= innerWidth+2'),'Mobile horizontal overflow'
    assert mobile.locator('.v3-project-link').first.get_attribute('aria-current')=='true'
    mobile.screenshot(path=str(r/'docs/screenshots/v3-mobile-projects.png'),full_page=True)
    browser.close()
    print('PASS project rail project selection, contextual board, light screenshots, mobile width',flush=True)
