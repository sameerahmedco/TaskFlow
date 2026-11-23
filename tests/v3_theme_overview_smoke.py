"""Cheap in-place v3 theme sweep across all ten appearance choices (synthetic data)."""
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
ns={'__file__':str(ROOT/'tests/theme_contrast_ui_smoke.py')}
src=Path(ns['__file__'])
exec(compile(src.read_text().split('with sync_playwright() as play:')[0],str(src),'exec'),ns)
load=ns['load_fixture'];contrast=ns['JS']
PALETTES=('light','dark','system','ocean','forest','lavender','sunset','midnight','charcoal','black')
SHOTS=ROOT/'docs/screenshots'
with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    page=browser.new_page(viewport={'width':1440,'height':900})
    errors=[];page.on('pageerror',lambda exc:errors.append(str(exc)))
    load('design_ui_smoke.py','load_ui',page)
    page.wait_for_selector('.v3-project-link')
    checks=0
    for name in PALETTES:
        page.locator('#appearance-preference').select_option(name)
        expected='dark' if name in ('dark','midnight','charcoal','black') else 'light'
        assert page.evaluate('document.documentElement.dataset.theme')==expected,name
        if name=='black':
            assert page.evaluate('getComputedStyle(document.querySelector(".view-container")).backgroundColor')=='rgb(0, 0, 0)'
        for selector in ('.topbar h1','.work-section-head h3','.v2-task-stream .search-result strong',
                         '.v2-task-stream .search-result small','.v3-project-link .v3-project-name'):
            r=page.evaluate(contrast,selector)
            assert not r.get('missing'),(name,selector,r)
            assert r['ratio']>=4.5, f'{name}: {selector}: {r}'
            checks+=1
        if name in ('charcoal','black'):
            page.wait_for_timeout(170)
            page.screenshot(path=str(SHOTS/f'v3-{name}-workspace.png'),full_page=True)
            page.locator('[data-v2-view="board"]').click()
            page.wait_for_selector('.board-card')
            page.wait_for_timeout(170)
            page.screenshot(path=str(SHOTS/f'v3-{name}-board.png'),full_page=True)
            page.locator('[data-v2-view="my-work"]').click()
            page.wait_for_selector('.search-result')
        if name=='light':
            page.wait_for_timeout(160)
            page.screenshot(path=str(SHOTS/'workspace.png'),full_page=True)
    assert not errors,errors
    print(f'PASS {checks} representative text-contrast checks across {len(PALETTES)} palettes, true OLED black and theme previews',flush=True)
    browser.close()
