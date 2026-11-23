"""TaskFlow 1.22: palette switching and readable text on eight existing screens.

Uses Chromium and synthetic API fixtures, not a live MySQL server. Additional
palette themes must preserve >= 4.5:1 on the same named text surfaces covered
by the existing Light/Dark regression suite.
"""
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parent.parent
source=ROOT/'tests/theme_contrast_ui_smoke.py'
namespace={'__file__':str(source)}
exec(compile(source.read_text().split('with sync_playwright() as play:')[0],str(source),'exec'),namespace)
CASES=namespace['CASES']
JS=namespace['JS']
load_fixture=namespace['load_fixture']
PALETTES=('ocean','forest','lavender','sunset','midnight','charcoal','black')

with sync_playwright() as play:
    browser=play.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    checks=0
    for palette in PALETTES:
        for fixture,loader,view,selectors in CASES:
            page=browser.new_page(viewport={'width':1440,'height':900})
            page.set_default_timeout(14000)
            errors=[]
            page.on('pageerror',lambda exc:errors.append(str(exc)))
            load_fixture(fixture,loader,page)
            page.locator('#appearance-preference').select_option(palette)
            expected='dark' if palette in ('midnight','charcoal','black') else 'light'
            assert page.evaluate('document.documentElement.dataset.theme')==expected,(palette,view)
            assert page.evaluate('document.documentElement.dataset.palette')==palette,(palette,view)
            assert page.locator('#appearance-preference').input_value()==palette
            target=page.locator(f'.nav-item[data-view="{view}"]').first
            if not target.is_visible():
                page.locator('#v2-tools-toggle').click()
            target.click()
            page.wait_for_timeout(140)
            for selector in selectors:
                result=page.evaluate(JS,selector)
                assert not result.get('missing'),f'{palette} {view}: missing {selector}'
                assert result['ratio']>=4.5, f'{palette} {view} {selector} contrast {result["ratio"]:.2f}: {result["text"]}'
                checks+=1
            assert not errors, (palette,view,errors)
            page.close()
        print(f'PASS {palette}: typography and badges across {len(CASES)} screens')

    # Test actual sign-in buttons as well as the signed-in select.
    login=browser.new_page(viewport={'width':1440,'height':900})
    load_fixture('design_ui_smoke.py','load_ui',login,authenticated=False)
    login.evaluate("window.fetch=async()=>({ok:false,status:401,json:async()=>({error:'Not authenticated.'})})")
    login.add_script_tag(content=(ROOT/'assets/js/app.js').read_text())
    login.wait_for_selector('#auth-screen:not(.hidden)')
    assert login.locator('[data-theme-choice]').count()==10
    login.locator('.auth-theme-disclosure summary').click()
    for palette in PALETTES:
        login.locator(f'[data-theme-choice="{palette}"]').click()
        assert login.evaluate('document.documentElement.dataset.palette')==palette
        assert login.locator(f'[data-theme-choice="{palette}"]').get_attribute('aria-pressed')=='true'
        assert login.evaluate('document.documentElement.scrollWidth<=innerWidth+2')
        for selector in ['.auth-heading','.auth-subtitle','.auth-form label','.auth-footnote']:
            result=login.evaluate(JS,selector)
            assert not result.get('missing') and result['ratio']>=4.5, f'{palette} sign-in {selector}: {result}'
            checks+=1
    login.close()

    # The eight-choice sign-in picker must remain usable on narrow screens.
    narrow=browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
    load_fixture('design_ui_smoke.py','load_ui',narrow,authenticated=False)
    narrow.evaluate("window.fetch=async()=>({ok:false,status:401,json:async()=>({error:'Not authenticated.'})})")
    narrow.add_script_tag(content=(ROOT/'assets/js/app.js').read_text())
    narrow.wait_for_selector('#auth-screen:not(.hidden)')
    # The v3 sign-in appearance picker is deliberately collapsed until opened.
    narrow.locator('.auth-theme-disclosure summary').click()
    for palette in ('light','sunset','midnight','charcoal','black'):
        narrow.locator(f'[data-theme-choice="{palette}"]').click()
        assert narrow.evaluate('document.documentElement.scrollWidth<=window.innerWidth+2'),f'Overflow on mobile sign-in with {palette}'
        assert narrow.locator(f'[data-theme-choice="{palette}"]').get_attribute('aria-pressed')=='true'
    narrow.close()

    # Test pre-style boot against a controlled storage implementation. The
    # browser sandbox disallows external network navigation, so persist and
    # re-run the no-flash bootstrap using the same stored key.
    page=browser.new_page()
    page.set_content('<html><head><meta name="theme-color" content="#fff"></head><body>TaskFlow theme bootstrap</body></html>')
    page.evaluate("""() => {
      const prefs={};
      Object.defineProperty(window,'localStorage',{configurable:true,value:{
        getItem:k=>Object.hasOwn(prefs,k)?prefs[k]:null,
        setItem:(k,v)=>{prefs[k]=String(v)},
        removeItem:k=>{delete prefs[k]}
      }});
    }""")
    page.add_script_tag(content=(ROOT/'assets/js/theme-boot.js').read_text())
    assert page.evaluate('document.documentElement.dataset.theme')=='light'
    assert page.evaluate('document.documentElement.dataset.palette')=='light'
    for palette in PALETTES:
        page.evaluate('(p)=>localStorage.setItem("taskflow-appearance",p)',palette)
        page.add_script_tag(content=(ROOT/'assets/js/theme-boot.js').read_text())
        assert page.evaluate('document.documentElement.dataset.palette')==palette
        expected='dark' if palette in ('midnight','charcoal','black') else 'light'
        assert page.evaluate('document.documentElement.dataset.theme')==expected
    page.close()
    browser.close()
    print(f'PASS {checks} additional text contrast checks in seven themes; ten sign-in theme buttons; persistent preference; Light default.')
