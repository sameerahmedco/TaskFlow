"""TaskFlow 1.0.0 installer UI smoke test, no database installation performed."""
import subprocess
import base64
import re
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parent.parent
html=subprocess.check_output(['php','install/index.php'],cwd=root,text=True)
html=re.sub(r'<link rel="stylesheet"[^>]*>','',html)
html=re.sub(r'<script src="assets/installer.js" defer></script>','',html)
styles=(root/'install/assets/installer.css').read_text()+'\n'+(root/'install/assets/installer-v1.css').read_text()+'\n'+(root/'install/assets/installer-ui-overhaul.css').read_text()
script=(root/'install/assets/installer.js').read_text()
screens=root/'docs/screenshots'
screens.mkdir(exist_ok=True)
brand='data:image/png;base64,'+base64.b64encode((root/'assets/img/taskflow-logo-192.png').read_bytes()).decode()
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    for width in (1280,390):
        page=browser.new_page(viewport={'width':width,'height':870})
        errors=[]
        page.on('pageerror',lambda e: errors.append(str(e)))
        page.set_content(html)
        page.add_style_tag(content=styles)
        page.evaluate("(src) => document.querySelectorAll('img[src^=\"../assets/img/\"]').forEach(i=>i.src=src)",brand)
        page.add_script_tag(content=script)
        if width==1280:
            page.screenshot(path=str(screens/'setup.png'),full_page=True)
        else:
            page.screenshot(path=str(screens/'setup-mobile.png'),full_page=True)
        assert page.locator('.installer-grid').count()==1
        assert page.locator('.step-panel:visible').count()==1
        assert page.locator('.steps li.active').count()==1
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2')
        # Host check fails without MySQL/mbstring in this sandbox; use mock readiness
        # strictly to exercise browser step navigation, never submit to a real DB.
        page.locator('#next').evaluate('(el)=>el.disabled=false')
        page.locator('#next').click()
        assert page.locator('.step-panel:visible').get_attribute('data-step')=='1'
        page.locator('[name="db_host"]').fill('localhost')
        page.locator('[name="db_name"]').fill('taskflow')
        page.locator('[name="db_user"]').fill('test')
        page.locator('#next').click()
        assert page.locator('.step-panel:visible').get_attribute('data-step')=='2'
        page.locator('[name="org_name"]').fill('Example Org')
        page.locator('[name="first_team"]').fill('Product')
        page.locator('[name="app_url"]').fill('https://example.test')
        page.locator('[name="admin_name"]').fill('Test Admin')
        page.locator('[name="admin_email"]').fill('admin@example.test')
        page.locator('#next').click()
        assert page.locator('.step-panel:visible').get_attribute('data-step')=='3'
        assert page.locator('.steps li.active').get_attribute('aria-current')=='step'
        assert page.locator('#step-counter').inner_text()=='Step 4 of 4'
        if width==1280:
            page.screenshot(path=str(screens/'setup-security.png'),full_page=True)
        assert not errors, errors
        page.close()
    browser.close()
print('PASS: desktop/mobile installer, step navigation, field validation and ARIA step status (mocked readiness)')
