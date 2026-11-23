"""Nested sidebar navigation with keyboard, themes, and mobile (mocked API)."""
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parent.parent
fixture=root/'tests/design_ui_smoke.py'
context={'__file__':str(fixture)}
exec(compile(fixture.read_text().split('with sync_playwright() as p:')[0],str(fixture),'exec'),context)
load=context['load_ui']; paint=context['paint_brand']
with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    for appearance in ('light','charcoal','black'):
        page=browser.new_page(viewport={'width':1440,'height':900})
        page.set_default_timeout(17000)
        errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
        load(page,authenticated=True)
        page.locator('#appearance-preference').select_option(appearance)
        page.wait_for_selector('.v3-menu-group')
        assert page.locator('.v3-menu-group').count()==5
        assert page.locator('.v3-submenu-toggle').count()==5
        assert page.locator('.v3-submenu-items:visible').count()==0
        assert page.locator('#v2-tools-drawer').is_hidden()
        # Expand Projects via chevron without navigating away from Home.
        project_toggle=page.locator('.v3-menu-group[data-group="tasks"] .v3-submenu-toggle')
        project_toggle.click()
        assert project_toggle.get_attribute('aria-expanded')=='true'
        assert page.locator('#view-title').inner_text()=='My Work'
        assert page.locator('#v3-submenu-tasks').is_visible()
        assert page.locator('#v3-submenu-tasks [data-v3-submenu-view="project-risks"]').is_visible()
        # Another submenu closes previous rather than cluttering the rail.
        page.locator('.v3-menu-group[data-group="calendar"] .v3-submenu-toggle').click()
        assert project_toggle.get_attribute('aria-expanded')=='false'
        assert page.locator('#v3-submenu-tasks').is_hidden()
        assert page.locator('#v3-submenu-calendar').is_visible()
        # Keyboard access: ArrowDown enters submenu; Escape closes and refocuses toggle.
        calendar_toggle=page.locator('.v3-menu-group[data-group="calendar"] .v3-submenu-toggle')
        calendar_toggle.focus();page.keyboard.press('ArrowDown')
        assert page.evaluate('document.activeElement.matches("#v3-submenu-calendar button")')
        page.keyboard.press('Escape')
        assert calendar_toggle.get_attribute('aria-expanded')=='false'
        assert page.evaluate('document.activeElement.matches(".v3-menu-group[data-group=calendar] .v3-submenu-toggle")')
        project_toggle.click()
        page.locator('#v3-submenu-tasks [data-v3-submenu-view="tasks"]').click()
        page.wait_for_selector('.task-table tbody tr')
        assert page.locator('#view-title').inner_text()=='Tasks'
        assert page.locator('#v3-submenu-tasks').is_visible()
        assert page.locator('#v3-submenu-tasks [data-v3-submenu-view="tasks"]').get_attribute('aria-current')=='page'
        assert page.locator('#v2-tools-drawer').is_hidden()
        # Main destination still works as normal.
        page.locator('.v3-menu-group[data-group="board"] [data-v2-view="board"]').click()
        page.wait_for_selector('.board-card')
        assert page.locator('#v3-submenu-board').is_visible()
        # Existing search / Explore all tools and command palette remain accessible.
        page.locator('#v2-tools-toggle').click()
        assert page.locator('#v2-tools-drawer').is_visible()
        page.locator('#quick-switch-btn').click()
        assert page.locator('#quick-switch-modal').is_visible()
        page.locator('#quick-switch-close').click()
        page.locator('#v2-tools-toggle').click()
        assert page.locator('#v2-tools-drawer').is_hidden()
        # Theme-aware selected submenu text and background; no force-white dark labels.
        selected=page.locator('#v3-submenu-board .v3-submenu-link[aria-current="page"]')
        assert selected.is_visible()
        info=selected.evaluate('''el=>{let c=getComputedStyle(el),b=c.backgroundColor;return {color:c.color,background:b}}''')
        assert info['color']!=info['background'],(appearance,info)
        paint(page)
        page.screenshot(path=str(root/f'docs/screenshots/v3-submenus-{appearance}.png'),full_page=True)
        assert not errors,(appearance,errors)
        page.close()
        print('PASS',appearance,'nested sidebar, active navigation, keyboard, Explore tools, command palette',flush=True)
    # Per-project submenus provide the deeper workspace hierarchy without duplicating the full tools list.
    project_page=browser.new_page(viewport={'width':1365,'height':850})
    project_page.set_default_timeout(16000)
    load(project_page,authenticated=True)
    project_page.wait_for_selector('.v3-project-submenu-toggle')
    project_page.locator('.v3-project-submenu-toggle').first.click()
    assert project_page.locator('.v3-project-submenu').is_visible()
    assert project_page.locator('.v3-project-submenu [data-project-sub-view]').count()>=5
    paint(project_page)
    project_page.screenshot(path=str(root/'docs/screenshots/v3-project-submenu-light.png'),full_page=True)
    project_page.locator('.v3-project-submenu [data-project-sub-view="board"]').click()
    project_page.wait_for_selector('.board-card')
    assert project_page.locator('#view-title').inner_text()=='Board'
    assert project_page.locator('.v3-project-submenu [data-project-sub-view="board"]').get_attribute('aria-current')=='page'
    # Switching a project directly to Overview should not briefly render Tasks.
    project_page.locator('.v3-project-submenu [data-project-sub-view="project-overview"]').click()
    assert project_page.locator('#view-title').inner_text()=='Project overview'
    project_page.locator('.v3-project-submenu-toggle').first.click()
    assert project_page.locator('.v3-project-submenu').is_hidden()
    # 14 projects must not pre-render seven hidden submenu controls each.
    project_page.evaluate('''() => document.dispatchEvent(new CustomEvent('taskflow:project-context', {
      detail: {team_id:7,current_id:3,projects:Array.from({length:14},(_,i)=>({id:i+1,name:'Project '+(i+1)}))}
    }))''')
    assert project_page.locator('.v3-project-link').count()==8
    assert project_page.locator('.v3-project-submenu button').count()==0
    project_page.locator('.v3-project-submenu-toggle').first.click()
    assert 1<=project_page.locator('.v3-project-submenu button').count()<=7
    project_page.close()
    print('PASS individual project submenus, contextual view selection and active indicator',flush=True)
    mobile=browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
    mobile.set_default_timeout(17000)
    errors=[];mobile.on('pageerror',lambda error:errors.append(str(error)))
    load(mobile,authenticated=True)
    mobile.locator('#mobile-menu-btn').click()
    mobile.locator('.v3-menu-group[data-group="tasks"] .v3-submenu-toggle').click()
    assert mobile.locator('#v3-submenu-tasks').is_visible()
    mobile.locator('#v3-submenu-tasks [data-v3-submenu-view="tasks"]').click()
    mobile.wait_for_selector('.task-table tbody tr')
    assert mobile.locator('#mobile-menu-btn').get_attribute('aria-expanded')=='false'
    assert mobile.evaluate('document.documentElement.scrollWidth<=innerWidth+2')
    mobile.locator('#mobile-menu-btn').click()
    assert mobile.locator('#v3-submenu-tasks').is_visible()
    assert mobile.locator('#v3-submenu-tasks [data-v3-submenu-view="tasks"]').get_attribute('aria-current')=='page'
    mobile.wait_for_timeout(470)  # mobile drawer slide-in animation
    assert mobile.locator('#workspace-sidebar').evaluate('el=>el.getBoundingClientRect().left >= -1')
    paint(mobile)
    mobile.screenshot(path=str(root/'docs/screenshots/v3-submenus-mobile.png'),full_page=True)
    assert not errors,errors
    print('PASS mobile expanded submenu, correct task route, drawer close and viewport width',flush=True)
    browser.close()
print('All TaskFlow v3 nested sidebar navigation tests passed (synthetic APIs).')
