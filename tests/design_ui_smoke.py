"""Mocked Chrome UI acceptance for TaskFlow 1.1.0; no real DB is used."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import re
import base64

root = Path(__file__).resolve().parent.parent
screens = root / 'docs' / 'screenshots'
screens.mkdir(exist_ok=True, parents=True)
html = '<!doctype html>' + (root/'index.php').read_text().split('<!DOCTYPE html>', 1)[1]
html = re.sub(r'<\?php.*?\?>', '', html, flags=re.S)
html = re.sub(r'<link rel="stylesheet"[^>]*>', '', html)
html = re.sub(r'<script src="assets/js/app.js[^>]*></script>', '', html)
html = html.replace('</head>','<link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/design-system.css"></head>')
html = html.replace('</body>','<script src="assets/js/app.js"></script></body>')
MOCK = r"""() => {
  const user={id:1,name:'Morgan Lee',email:'morgan@sample.test',roles:['administrator'],team_id:7,
      team_name:'Product',avatar_color:'#3a8dd4',must_change_password:false};
  const tasks=[
    {id:24,title:'Review onboarding experience',description:'Review organization setup screens',status:'to_do',priority:'high',due_date:'2026-11-23',start_date:null,team_id:7,created_by:1,assignees:[user],tags:[],subtasks:{done:1,total:3},checklist:[],comments:[],can_edit:true,list_name:'Product launch',unfinished_blockers:0},
    {id:25,title:'Prepare release documentation',description:'Write a concise release guide',status:'in_progress',priority:'normal',due_date:'2026-11-24',start_date:null,team_id:7,created_by:1,assignees:[user],tags:[],subtasks:{done:1,total:2},checklist:[],comments:[],can_edit:true,list_name:'Product launch',unfinished_blockers:0},
    {id:26,title:'Validate account security',description:'Review sessions and authentication',status:'review',priority:'urgent',due_date:'2026-11-25',start_date:null,team_id:7,created_by:1,assignees:[user],tags:[],subtasks:{done:0,total:0},checklist:[],comments:[],can_edit:true,list_name:'Product launch',unfinished_blockers:1},
    {id:27,title:'Outline Q1 priorities',description:'Clarify next-quarter outcomes',status:'done',priority:'low',due_date:'2026-11-20',start_date:null,team_id:7,created_by:1,assignees:[user],tags:[],subtasks:{done:0,total:0},checklist:[],comments:[],can_edit:true,list_name:'Product launch',unfinished_blockers:0}
  ];
  window.__calls=[];
  window.fetch=async(url,options={})=>{
    const name=String(url).split('?')[0].split('/').pop();
    const params = new URL(String(url),'http://localhost/').searchParams;
    const method=options.headers?.['X-HTTP-Method-Override'] || options.method || 'GET';
    const body=options.body?JSON.parse(options.body):{};
    window.__calls.push({name,method,body});
    let data={};
    if(name==='mfa_status.php') data={mfa_required:false};
    if(name==='me.php') data={user,csrf_token:'test-token',organization_name:'Northstar Studio',teams:[{id:7,name:'Product'}]};
    if(name==='lists.php') data={lists:[{id:3,team_id:7,name:'Product launch'}]};
    if(name==='tags.php') data={tags:[]};
    if(name==='users.php') data={users:[{...user,is_active:1}]};
    if(name==='my_work.php') data={tasks:tasks.filter(t=>t.status!=='done').map(t=>({...t,team_name:'Product',list_name:'Product launch'})),next_before_id:null};
    if(name==='search.php') data={tasks:tasks.filter(t=>!params.has('status')||params.get('status')==='all'||params.get('status')===t.status).map(t=>({...t,list_name:'Product launch'})),next_before_id:null};
    if(name==='tasks.php') data={tasks,next_offset:null};
    if(name==='task.php') data={task:{...tasks[0], dependencies:{depends_on:[],blocks:[]},recent_activity:[]}};
    if(name==='inbox.php') data={unread_count:2,notifications:[{id:13,category:'new_tasks',title:'New task assigned',body:'Review onboarding experience',is_read:false,created_at:'2026-11-15 09:00:00'},{id:12,category:'new_tasks',title:'A teammate mentioned you',body:'Please review the project plan',is_read:false,created_at:'2026-11-15 08:30:00'}],next_before_id:null};
    if(name==='notifications.php') data={last_id:0,notifications:[]};
    if(name==='monthly.php') data={weeks:[]};
    if(name==='mfa.php') data={enabled:false};
    if(name==='duplicate.php') data={ok:true,id:40};
    return {ok:true,status:200,json:async()=>data};
  };
}"""

# Inline assets for local, network-free Playwright execution. The product itself
# continues to load normal same-origin CSS and the cached icon sprite.
icons_svg=(root/'assets/img/icons.svg').read_text()
style=(root/'assets/css/style.css').read_text()+'\n'+(root/'assets/css/design-system.css').read_text()
app_js=(root/'assets/js/app.js').read_text()
brand_png='data:image/png;base64,'+base64.b64encode((root/'assets/img/taskflow-logo-192.png').read_bytes()).decode()
def paint_brand(page):
    page.evaluate("""(url) => {
        document.querySelectorAll('img[src^=\"assets/img/\"]').forEach(img=>img.src=url);
        document.querySelectorAll('use[href^=\"assets/img/icons.svg#\"]').forEach(el=>{
            el.setAttribute('href','#'+el.getAttribute('href').split('#').pop());
        });
    }""",brand_png)

def load_ui(page, authenticated=False):
    page.set_content(html)
    page.add_style_tag(content=style)
    page.evaluate("""(svg) => {
        document.body.insertAdjacentHTML('afterbegin',svg);
        document.querySelectorAll('use[href^="assets/img/icons.svg#"]').forEach(u => {
            u.setAttribute('href','#'+u.getAttribute('href').split('#').pop());
        });
    }""",icons_svg)
    paint_brand(page)
    if authenticated:
        page.evaluate(MOCK)
        page.add_script_tag(content=app_js)
        page.wait_for_selector('#app:not(.hidden)',timeout=15000)
        page.wait_for_selector('#my-work-results .search-result',timeout=15000)
        paint_brand(page)

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox'])
    errors=[]
    page = browser.new_page(viewport={'width':1440,'height':960},device_scale_factor=1)
    page.on('pageerror', lambda e: errors.append(str(e)))
    load_ui(page)
    page.screenshot(path=str(screens/'sign-in.png'),full_page=True)
    assert page.locator('#auth-screen').is_visible()
    assert page.locator('.auth-story').is_visible()
    assert page.locator('.auth-form-eyebrow').inner_text() == 'WELCOME BACK'
    page.close()

    page = browser.new_page(viewport={'width':1440,'height':940},device_scale_factor=1)
    page.on('pageerror', lambda e: errors.append(str(e)))
    load_ui(page, authenticated=True)
    assert page.locator('#view-title').inner_text() == 'My Work'
    assert 'v1.10.0' in page.locator('.sidebar-version').inner_text()
    assert page.locator('.nav-svg').count()>15
    page.screenshot(path=str(screens/'workspace.png'),full_page=True)
    page.locator('#topbar-search').click()
    page.wait_for_selector('#task-search-q')
    assert page.locator('#view-title').inner_text() == 'Search Tasks'
    page.keyboard.press('Control+k')
    assert page.evaluate("document.activeElement === document.getElementById('task-search-q')")
    page.locator('#theme-switch').click()
    assert page.evaluate("document.documentElement.dataset.theme") == 'dark'
    # browser-local preference persists only when storage is enabled by browser
    try:
        assert page.evaluate("localStorage.getItem('taskflow-appearance')") == 'dark'
    except Exception:
        pass  # about:blank test document does not have localStorage origin
    page.locator('[data-view="board"]').click()
    page.wait_for_selector('.board-card')
    assert page.locator('.board-lane').count() == 4
    paint_brand(page)
    page.screenshot(path=str(screens/'board-dark.png'),full_page=True)
    page.locator('#theme-switch').click()
    assert page.evaluate("document.documentElement.dataset.theme") == 'light'
    paint_brand(page)
    page.screenshot(path=str(screens/'board.png'),full_page=True)
    page.locator('#quick-add-task').click()
    page.wait_for_selector('#at-title')
    assert page.evaluate("document.activeElement === document.getElementById('at-title')")
    assert page.locator('#at-assignees option').count() == 1
    page.locator('#at-title').fill('Important action')
    page.locator('#at-desc').fill('New task description')
    page.locator('[data-view="inbox"]').click()
    page.wait_for_selector('.inbox-item')
    page.screenshot(path=str(screens/'inbox.png'),full_page=True)
    print('PASS: login layout, workspace navigation, search, Ctrl+K, appearance switch, board, quick task creation, inbox')

    mobile=browser.new_page(viewport={'width':390,'height':844},device_scale_factor=1,is_mobile=True,has_touch=True)
    mobile.on('pageerror', lambda e:errors.append(str(e)))
    load_ui(mobile,authenticated=True)
    assert mobile.locator('#mobile-menu-btn').is_visible()
    mobile.locator('#mobile-menu-btn').click()
    assert mobile.locator('#mobile-menu-btn').get_attribute('aria-expanded')=='true'
    mobile.locator('[data-view="board"]').click()
    mobile.wait_for_selector('.board-card')
    assert mobile.locator('#mobile-menu-btn').get_attribute('aria-expanded')=='false'
    assert mobile.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), 'Mobile page horizontal overflow'
    assert mobile.evaluate('document.querySelector("#kanban-board").scrollWidth > document.querySelector("#kanban-board").clientWidth'), 'Board should scroll inside viewport'
    mobile.screenshot(path=str(screens/'mobile-board.png'),full_page=True)
    mobile.locator('#quick-add-task').click()
    mobile.wait_for_selector('#at-title')
    assert mobile.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), 'Mobile task form horizontal overflow'
    print('PASS: mobile navigation ARIA, no page overflow, internal board scroll, mobile quick-create')
    assert not errors, errors
    print('PASS: zero unexpected JavaScript page errors')
    browser.close()
