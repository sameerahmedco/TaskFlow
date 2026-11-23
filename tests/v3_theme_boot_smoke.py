"""Persisted Charcoal and OLED Black are applied before layout, with Light fallback."""
from pathlib import Path
from playwright.sync_api import sync_playwright
r=Path(__file__).resolve().parent.parent
with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    page=browser.new_page()
    page.set_content('<html><head><meta name="theme-color" content="#fff"></head><body></body></html>')
    page.evaluate('''() => {
        const state={};Object.defineProperty(window,'localStorage',{configurable:true,value:{
            getItem:k=>state[k]??null,setItem:(k,v)=>state[k]=String(v),removeItem:k=>delete state[k]
        }});
    }''')
    js=(r/'assets/js/theme-boot.js').read_text()
    for name,expected in [('light','light'),('charcoal','dark'),('black','dark'),('invalid','light')]:
        page.evaluate('p=>localStorage.setItem("taskflow-appearance",p)',name)
        page.add_script_tag(content=js)
        assert page.evaluate('document.documentElement.dataset.theme')==expected,name
        assert page.evaluate('document.documentElement.dataset.palette')==(name if name!='invalid' else 'light'),name
    print('PASS Light fallback and persisted Charcoal / Black dark bootstrap')
    browser.close()
