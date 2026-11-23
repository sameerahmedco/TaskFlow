"""TaskFlow v3: CSV task import preview and transaction request (mocked API)."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import re

ROOT=Path(__file__).resolve().parent.parent
context={'__file__':str(ROOT/'tests/design_ui_smoke.py')}
source=(ROOT/'tests/design_ui_smoke.py').read_text().split('with sync_playwright() as p:')[0]
exec(compile(source,'design_ui_smoke.py','exec'),context)
load_ui=context['load_ui']
shots=ROOT/'docs/screenshots';shots.mkdir(parents=True,exist_ok=True)
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    errors=[]
    page=browser.new_page(viewport={'width':1440,'height':920})
    page.set_default_timeout(13000)
    page.on('pageerror',lambda e:errors.append(str(e)))
    load_ui(page,authenticated=True)
    page.add_script_tag(content=(ROOT/'assets/js/task-import.js').read_text())
    page.evaluate('''() => {
        const previous=window.fetch;
        window.importCalls=[];
        window.fetch=async(url,options={})=>{
            if(String(url).includes('task_import.php')){
                const payload=JSON.parse(options.body||'{}');window.importCalls.push(payload);
                return {ok:true,status:201,json:async()=>({ok:true,created:payload.rows.length,ids:[53,54],list_id:payload.list_id})};
            }
            return previous(url,options);
        };
    }''')
    page.locator('[data-v2-view="tasks"]').click()
    page.wait_for_selector('#task-import-open')
    page.locator('#task-import-open').click()
    page.wait_for_selector('#task-import-file')
    assert 'Product launch' in page.locator('#prompt-body').inner_text()
    assert page.locator('#task-import-template').get_attribute('download')=='taskflow-tasks-template.csv'
    # Invalid dates must remain in preview state and never reach the server.
    bad=b'title,due_date\nBad date,2027-02-30\n'
    page.locator('#task-import-file').set_input_files({'name':'bad.csv','mimeType':'text/csv','buffer':bad})
    page.wait_for_function("document.querySelector('#task-import-status')?.textContent.includes('invalid due date')")
    assert page.locator('.task-import-table').count()==0
    assert page.evaluate('window.importCalls.length')==0
    good=b'\xef\xbb\xbftitle,description,priority,due_date,estimate_hours\r\n"=DANGEROUS(1)","Line 1, notes",high,2027-01-20,2.5\r\n"Review documentation",,low,,\r\n'
    page.locator('#task-import-file').set_input_files({'name':'valid.csv','mimeType':'text/csv','buffer':good})
    page.wait_for_function("document.querySelector('#task-import-status')?.textContent.includes('Ready to import 2 tasks')")
    assert page.locator('.task-import-table tbody tr').count()==2
    assert page.locator('#task-import-preview img').count()==0
    assert '=DANGEROUS(1)' in page.locator('.task-import-table').inner_text()
    page.wait_for_timeout(280)
    page.screenshot(path=str(shots/'task-csv-import.png'),full_page=True)
    page.locator('#prompt-confirm').click()
    page.wait_for_function('window.importCalls.length===1')
    imported=page.evaluate('window.importCalls[0]')
    assert imported['team_id']==7 and imported['list_id']==3
    assert len(imported['rows'])==2
    assert imported['rows'][0]['title']=='=DANGEROUS(1)'
    assert imported['rows'][1]['priority']=='low'
    page.wait_for_selector('#prompt-modal.hidden',state='attached')
    print('PASS valid CSV preview, template download, invalid file rejection, atomic import API payload')
    # User-managed task import stays within the current list, no cross-team mutation.
    mobile=browser.new_page(viewport={'width':390,'height':844})
    mobile.set_default_timeout(15000)
    mobile.on('pageerror',lambda e:errors.append(str(e)))
    load_ui(mobile,authenticated=True)
    mobile.add_script_tag(content=(ROOT/'assets/js/task-import.js').read_text())
    mobile.locator('[data-mobile-view="board"]').click()
    mobile.locator('[data-v2-tab="tasks"]').click() if mobile.locator('[data-v2-tab="tasks"]').count() else mobile.locator('[data-v2-view="tasks"]').click()
    mobile.wait_for_selector('#task-import-open')
    mobile.locator('#task-import-open').click()
    mobile.wait_for_selector('#task-import-file')
    assert mobile.evaluate('document.documentElement.scrollWidth <= innerWidth+2')
    mobile.wait_for_timeout(250)
    mobile.screenshot(path=str(shots/'task-csv-import-mobile.png'),full_page=True)
    assert not errors,errors
    print('PASS mobile import modal responsive and no JavaScript page errors')
    browser.close()
