"""Rebuild GitHub screenshots from browser fixtures with Light forced on.

The README gallery intentionally shows the Light theme (white backgrounds),
not the signed-in user's saved theme or the OS's current dark preference.
Run this script explicitly when updating GitHub screenshots.
"""
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parent.parent
src=ROOT/'tests/theme_contrast_ui_smoke.py'
ns={'__file__':str(src)}
exec(compile(src.read_text().split('with sync_playwright() as play:')[0],str(src),'exec'),ns)
load_fixture=ns['load_fixture']
SHOTS=ROOT/'docs/screenshots'
SHOTS.mkdir(parents=True,exist_ok=True)

with sync_playwright() as play:
    browser=play.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])

    def page_for(fixture,loader,view,palette='light',mobile=False):
        opts={'viewport':{'width':390 if mobile else 1440,'height':844 if mobile else 940},'device_scale_factor':1}
        if mobile:opts.update(is_mobile=True,has_touch=True)
        page=browser.new_page(**opts)
        page.set_default_timeout(14000)
        load_fixture(fixture,loader,page)
        page.locator('#appearance-preference').select_option(palette)
        assert page.evaluate('document.documentElement.dataset.palette')==palette
        if mobile:page.locator('#mobile-menu-btn').click()
        if view!='my-work':page.locator(f'.nav-item[data-view="{view}"]').click()
        selector={'my-work':'.work-hero','board':'.board-card','project-decisions':'.decision-card','project-risks':'.risk-card'}[view]
        page.wait_for_selector(selector)
        if mobile:page.wait_for_function('!document.querySelector(".sidebar").classList.contains("open")')
        page.wait_for_timeout(180)
        return page

    for view,fixture,loader,name in [
        ('my-work','design_ui_smoke.py','load_ui','workspace.png'),
        ('board','design_ui_smoke.py','load_ui','board.png'),
        ('project-decisions','project_decisions_ui_smoke.py','initialize','project-decisions.png'),
        ('project-risks','project_risks_ui_smoke.py','init','project-risks.png')
    ]:
        page=page_for(fixture,loader,view)
        page.screenshot(path=str(SHOTS/name),full_page=True)
        assert page.evaluate('document.documentElement.dataset.palette')=='light'
        page.close()
        print('LIGHT screenshot:',name)
    mobile=page_for('design_ui_smoke.py','load_ui','board',mobile=True)
    mobile.screenshot(path=str(SHOTS/'mobile-board.png'),full_page=True)
    mobile.close()
    print('LIGHT screenshot: mobile-board.png')

    for theme in ('ocean','forest','lavender','sunset','midnight','charcoal','black'):
        page=page_for('design_ui_smoke.py','load_ui','my-work',palette=theme)
        page.screenshot(path=str(SHOTS/f'theme-{theme}.png'),full_page=True)
        page.close()
        print('OPTIONAL screenshot:',f'theme-{theme}.png')

    browser.close()
