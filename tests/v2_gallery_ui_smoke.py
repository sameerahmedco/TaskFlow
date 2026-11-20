"""TaskFlow v2 gallery: capture real mocked List and Home views in default Light.

The shared design fixture uses synthetic data and the actual app.js router;
no user/session data or production endpoints are accessed.
"""
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
fixture=ROOT/'tests/design_ui_smoke.py'
context={'__file__':str(fixture)}
exec(compile(fixture.read_text().split('with sync_playwright() as p:')[0],str(fixture),'exec'),context)
load_ui=context['load_ui']
paint_brand=context['paint_brand']
shots=ROOT/'docs/screenshots'
with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    page=browser.new_page(viewport={'width':1440,'height':940})
    errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    load_ui(page,authenticated=True)
    # Default must be bright Light and all non-essential tools collapsed.
    assert page.evaluate("document.documentElement.dataset.theme")=='light'
    assert page.locator('#v2-tools-drawer').is_hidden()
    page.locator('[data-v2-view="tasks"]').click()
    page.wait_for_selector('.task-table tbody tr')
    assert page.locator('#view-title').inner_text()=='Tasks'
    assert page.locator('.task-table tbody tr').count() >= 1
    page.wait_for_timeout(430)
    paint_brand(page)
    page.screenshot(path=str(shots/'tasks-v2.png'),full_page=True)
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth+2')
    # contextual switcher from List to Board and back, no hidden duplicated nav.
    page.locator('[data-v2-tab="board"]').click()
    page.wait_for_selector('.board-card')
    page.locator('[data-v2-tab="tasks"]').click()
    page.wait_for_selector('.task-table tbody tr')
    # Advanced UI direct tool navigation is available even when hidden by default.
    page.locator('#v2-tools-toggle').click()
    assert page.locator('#v2-tools-drawer').is_visible()
    page.locator('#nav-filter').fill('milestones')
    assert page.locator('[data-view="milestones"]').is_visible()
    page.locator('#nav-filter').fill('')
    # Test keyboard-only page switcher can reach less-used tools.
    page.keyboard.press('Control+Shift+k')
    page.locator('#quick-switch-input').fill('Project risks')
    assert page.locator('.command-result').count()>=1
    page.keyboard.press('Enter')
    assert page.locator('#view-title').inner_text()=='Project risks'
    assert page.locator('#v2-tools-drawer').is_hidden()
    assert not errors,errors
    print('PASS v2 list, contextual tabs, hidden tool library, keyboard navigation, desktop width, Light screenshot')
    browser.close()
