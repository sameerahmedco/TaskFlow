"""Local mocked browser smoke test. Does not replace a live MySQL test."""
from pathlib import Path
import re
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parent.parent
source = root.joinpath('index.php').read_text()
html = '<!DOCTYPE html>' + source.split('<!DOCTYPE html>', 1)[1]
html = re.sub(r'<\?php.*?\?>', '', html, flags=re.S)
html = re.sub(r'<script src="[^"]*">\s*</script>', '', html)
html = re.sub(r'<link rel="stylesheet"[^>]*>', '', html)
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox'])
    page=browser.new_page(viewport={'width': 1380, 'height': 900})
    errs=[]
    page.on('pageerror', lambda e:errs.append(str(e)))
    page.set_content(html.replace('id="v2-tools-drawer" hidden','id="v2-tools-drawer"'))
    page.add_style_tag(content=root.joinpath('assets/css/style.css').read_text() + '\n' + root.joinpath('assets/css/design-system.css').read_text() + '\n' + root.joinpath('assets/css/ui-overhaul.css').read_text() + '\n' + root.joinpath('assets/css/theme-accessibility.css').read_text() + '\n' + root.joinpath('assets/css/theme-palettes.css').read_text()+'\n'+root.joinpath('assets/css/workspace.css').read_text()+'\n'+root.joinpath('assets/css/v3-refinement.css').read_text() + '\n' + root.joinpath('assets/css/v3-compact.css').read_text())
    page.evaluate('''() => {
      window.__calls=[];
      window.__unread=1;
      const user={id:1,name:'Alex Admin',email:'alex@company.test',roles:['administrator'],team_id:7,
          team_name:'Design',avatar_color:'#335599',must_change_password:false};
      const task={id:24, title:'Review release checklist', description:'Detailed notes', status:'to_do',priority:'high',
          due_date:'2026-11-24',start_date:null,team_id:7,created_by:1,
          is_recurring_weekly:0,assignees:[{id:1,name:'Alex Admin'}],tags:[],subtasks:[],comments:[],checklist:[]};
      window.fetch=async(url, opts={}) => {
        const name=String(url).split('?')[0].split('/').pop();
        const param=new URL(String(url), 'http://localhost/').searchParams;
        const body=opts.body?JSON.parse(opts.body):{};
        window.__calls.push({name,method:opts.headers?.['X-HTTP-Method-Override']||opts.method||'GET',body});
        let data={};
        if(name==='mfa_status.php') data={mfa_required:false};
        if(name==='me.php') data={user,csrf_token:'mock-csrf',organization_name:'Aperture Studio',teams:[{id:7,name:'Design'}]};
        if(name==='lists.php') data={lists:[{id:3,name:'Projects',team_id:7}]};
        if(name==='tags.php') data={tags:[]};
        if(name==='users.php') data={users:[{...user, is_active:1}]};
        if(name==='monthly.php') data={weeks:[]};
        if(name==='my_work.php') data={tasks:[{...task,team_name:'Design',list_name:'Projects'}],next_before_id:null};
        if(name==='search.php') data={tasks:[{...task, list_name:'Projects', can_edit:true, assigned_to_me:true,
          status:param.get('status')||'to_do'}],next_before_id:null};
        if(name==='inbox.php') {
          if(opts.method==='POST') window.__unread=0;
          data={unread_count:window.__unread, next_before_id:null,
            notifications:[{id:4, category:'new_tasks',title:'New assignment',body:'Test task assigned',
                is_read:!window.__unread,created_at:'2026-11-15 04:00:00'}]};
        }
        if(name==='task.php') {
          if((opts.method||'GET')==='GET') data={task};
          else data={ok:true};
        }
        if(name==='duplicate.php') data={ok:true,id:25,title:task.title+' (copy)'};
        return {status:200,ok:true,json:async()=>data};
      }
    }''')
    page.add_script_tag(content=root.joinpath('assets/js/app.js').read_text())
    page.wait_for_selector('#app:not(.hidden)')
    page.wait_for_selector('#my-work-results .search-result')
    assert page.locator('#view-title').inner_text() == 'My Work'
    page.locator('[data-view="board"]').click()
    page.wait_for_selector('.board-card')
    assert page.locator('.board-lane').count()==4
    page.locator('.board-card .board-move').first.select_option('review')
    page.wait_for_function("window.__calls.some(x => x.name==='task.php' && x.method==='PUT' && x.body.status==='review')")
    page.locator('#board-mine').check()
    page.wait_for_function("window.__calls.some(x => x.name==='search.php')")
    assert page.locator('#board-mine').is_checked(), 'board filter selection should survive a reload'
    page.screenshot(path='/mnt/data/TaskFlow-Board-Preview.png',full_page=True)
    page.locator('[data-view="inbox"]').click()
    page.wait_for_selector('.inbox-item.unread')
    page.locator('.inbox-read').click()
    page.wait_for_selector('.inbox-item:not(.unread)')
    assert page.locator('#inbox-badge').is_hidden()
    page.screenshot(path='/mnt/data/TaskFlow-Inbox-Preview.png',full_page=True)
    page.locator('[data-view="my-work"]').click()
    page.wait_for_selector('#my-work-results .search-result')
    page.locator('#my-work-results .search-result').first.click()
    page.wait_for_selector('#tm-duplicate')
    page.locator('#tm-duplicate').click()
    page.wait_for_function("window.__calls.some(x => x.name==='duplicate.php' && x.method==='POST' && x.body.id===24)")
    page.locator('#close-task-modal').click()
    page.set_viewport_size({'width':390,'height':780})
    page.locator('#mobile-menu-btn').click()
    page.locator('[data-view="board"]').click()
    page.wait_for_selector('.board-card')
    page.wait_for_timeout(400)
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), 'mobile page should not overflow horizontally'
    assert page.evaluate('document.getElementById("kanban-board").scrollWidth > document.getElementById("kanban-board").clientWidth'), 'mobile board scrolls inside viewport'
    page.screenshot(path='/mnt/data/TaskFlow-Mobile-Board-Preview.png',full_page=True)
    assert not errs,errs
    print('PASS: My Work, Kanban, board status move, team-only filter, inbox mark read, task duplication')
    print('PASS: mobile board is horizontally scrollable without overflowing page')
    print('PASS: zero unhandled browser JavaScript errors')
    browser.close()
