"""TaskFlow v1.21.0: WCAG AA contrast of critical real UI elements.

These checks run in Chromium with the existing synthetic API fixtures. They do
not require production data or prove every uninspected pixel is accessible.
"""
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parent.parent
CASES = [
    # (synthetic fixture, loader, sidebar view, selectors with required 4.5:1)
    ('design_ui_smoke.py','load_ui','my-work',['.topbar h1','.topbar-overline','.work-section-head h3','.search-result strong','.search-result small','.v2-task-count']),
    ('design_ui_smoke.py','load_ui','board',['.board-task-open','.board-lane h3','.status-pill','.priority-pill']),
    ('announcements_ui_smoke.py','load','announcements',['.announcement-card h3','.announcement-author','.announcement-stats','.announcement-actions span']),
    ('project_decisions_ui_smoke.py','initialize','project-decisions',['.decision-card h4','.decision-context','.decision-outcome strong','.decision-outcome p','.decision-project','.decision-meta span','.decision-summary span']),
    ('project_risks_ui_smoke.py','init','project-risks',['.risk-card h4','.risk-severity-critical','.risk-severity-medium','.risk-description','.risk-meta span']),
    ('time_off_ui_smoke.py','load','time-off',['.leave-request-card>p','.leave-note','.leave-request-form label']),
    ('milestones_ui_smoke.py','load','milestones',['.milestone-health-complete','.milestone-health-at-risk']),
    ('portfolio_saved_ui_smoke.py','load','portfolio',['.portfolio-health-at_risk','.portfolio-health-attention']),
]
JS = r'''(selector) => {
  const el=document.querySelector(selector);
  if(!el||!el.getClientRects().length)return {missing:true,selector};
  const st=getComputedStyle(el);
  const parse=s=> {const m=s.match(/rgba?\(([^)]*)\)/);if(!m)return null;return m[1].replace(/,/g,' ').replace(/\//g,' ').split(/\s+/).map(Number);};
  const fg=parse(st.color);
  let node=el,bg=null;
  while(node){ const current=parse(getComputedStyle(node).backgroundColor);if(current && (current.length<4||current[3]>.96)){bg=current.slice(0,3);break;}node=node.parentElement; }
  if(!bg) bg=[255,255,255];
  function luminance(a){return a.map(x=>{x=x/255;return x<=.04045?x/12.92:((x+.055)/1.055)**2.4}).reduce((v,x,i)=>v+x*[.2126,.7152,.0722][i],0)}
  const f=luminance(fg), b=luminance(bg);
  const ratio=(Math.max(f,b)+.05)/(Math.min(f,b)+.05);
  return {selector,text:el.textContent.trim().slice(0,42),ratio,foreground:st.color,background:bg};
}'''

def load_fixture(path, function, page, authenticated=True):
    src=ROOT/'tests'/path
    definitions=src.read_text().split('with sync_playwright() as p:')[0]
    ctx={'__file__':str(src)}
    exec(compile(definitions,str(src),'exec'),ctx)
    fn=ctx[function]
    fn(page,authenticated) if function=='load_ui' else fn(page)

with sync_playwright() as play:
    browser=play.chromium.launch(headless=True,executable_path='/usr/bin/chromium' if Path('/usr/bin/chromium').exists() else None,args=['--no-sandbox'])
    checked=0
    for fixture, loader, view, selectors in CASES:
        page=browser.new_page(viewport={'width':1440,'height':900})
        page.set_default_timeout(9000)
        errors=[]
        page.on('pageerror',lambda error:errors.append(str(error)))
        load_fixture(fixture,loader,page)
        for theme in ('light','dark'):
            page.evaluate('(value)=>{document.documentElement.dataset.theme=value}',theme)
            target=page.locator(f'.nav-item[data-view="{view}"]').first
            if not target.is_visible():
                page.locator('#v2-tools-toggle').click()
            target.click()
            page.wait_for_timeout(180)
            for selector in selectors:
                result=page.evaluate(JS,selector)
                assert not result.get('missing'),f'{theme}: missing {view}: {selector}'
                assert result['ratio'] >= 4.5, f'{theme} {view} {selector}: contrast {result["ratio"]:.2f}: {result["text"]}'
                checked+=1
        assert not errors,(view,errors)
        page.close()

    # Verify the actual preference controls, not just manually applied CSS.
    page=browser.new_page(viewport={'width':1440,'height':940})
    load_fixture('design_ui_smoke.py','load_ui',page)
    pref=page.locator('#appearance-preference')
    pref.select_option('dark')
    assert page.evaluate('document.documentElement.dataset.theme')=='dark'
    assert page.evaluate('document.documentElement.dataset.themePreference')=='dark'
    assert page.locator('#theme-switch').get_attribute('aria-pressed')=='true'
    assert page.locator('#theme-switch use').get_attribute('href').endswith('#sun')
    pref.select_option('light')
    assert page.evaluate('document.documentElement.dataset.theme')=='light'
    assert page.locator('#theme-switch use').get_attribute('href').endswith('#moon')
    page.emulate_media(color_scheme='dark')
    pref.select_option('system')
    assert page.evaluate('document.documentElement.dataset.theme')=='dark'
    page.emulate_media(color_scheme='light')
    page.wait_for_function('document.documentElement.dataset.theme === "light"')
    page.locator('#theme-switch').click()
    assert page.evaluate('document.documentElement.dataset.theme')=='dark'
    assert page.evaluate('document.documentElement.dataset.themePreference')=='dark'
    # Avatar initials choose a foreground that contrasts against arbitrary
    # profile colors (rather than always assuming white works).
    initial = page.evaluate(JS,'.me-chip .avatar')
    assert not initial.get('missing') and initial['ratio'] >= 4.5, f"Avatar initials contrast: {initial}"
    page.close()

    # A saved dark preference must also remain readable before login.
    login=browser.new_page(viewport={'width':1440,'height':900})
    load_fixture('design_ui_smoke.py','load_ui',login,authenticated=False)
    login.evaluate("window.fetch=async()=>({ok:false,status:401,json:async()=>({error:'Not authenticated.'})})")
    login.add_script_tag(content=(ROOT/'assets/js/app.js').read_text())
    login.wait_for_selector('#auth-screen:not(.hidden)')
    login.locator('[data-theme-choice="dark"]').click()
    assert login.evaluate('document.documentElement.dataset.theme')=='dark'
    assert login.locator('[data-theme-choice="dark"]').get_attribute('aria-pressed')=='true'
    for selector in ['.auth-heading','.auth-subtitle','.auth-form label','.auth-footnote','.auth-form input[type=email]']:
        result=login.evaluate(JS,selector)
        assert not result.get('missing') and result['ratio']>=4.5, f'Login {selector}: {result}'
    login.screenshot(path=str(ROOT/'docs/screenshots/login-dark-accessible.png'),full_page=True)
    login.close()

    # Test the selection mechanism on narrow devices as well as desktop.
    mobile=browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
    load_fixture('project_decisions_ui_smoke.py','initialize',mobile)
    mobile.locator('#mobile-menu-btn').click()
    mobile.locator('#appearance-preference').select_option('dark')
    assert mobile.evaluate('document.documentElement.dataset.theme')=='dark'
    mobile.locator('.nav-item[data-view="project-decisions"]').click()
    mobile.wait_for_selector('.decision-card')
    mobile.wait_for_timeout(300)
    assert mobile.evaluate('document.documentElement.scrollWidth <= innerWidth+2'), 'Mobile dark mode horizontal overflow'
    mobile.screenshot(path=str(ROOT/'docs/screenshots/decisions-mobile-dark-accessible.png'),full_page=True)
    mobile.close()
    browser.close()
    print(f'PASS {checked} cross-theme, screen-specific WCAG 4.5:1 checks across {len(CASES)} real UI fixtures.')
    print('PASS light / dark / system preferences, reactive device theme and working mode icons.')
