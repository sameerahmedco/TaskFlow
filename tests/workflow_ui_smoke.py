"""Mocked browser interactions for workflow; real HTTP/MySQL tests still needed."""
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
    browser = p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox'])
    page = browser.new_page(viewport={'width': 1280, 'height': 860})
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('dialog', lambda dialog: dialog.accept())
    page.set_content(html)
    page.add_style_tag(content=root.joinpath('assets/css/style.css').read_text())
    page.evaluate('''() => {
      window.calls=[];
      window.blocks=[{id:33,title:'Run quality assurance',status:'in_progress'}];
      const user={id:1,name:'Test Admin',email:'admin@example.test',roles:['administrator'],team_id:7,
          team_name:'Design',avatar_color:'#4477aa',must_change_password:false};
      const tasks=[
        {id:24,title:'Prepare launch',description:'Notes',status:'to_do',priority:'normal',due_date:'2026-11-22',
          start_date:null,team_id:7,created_by:1,assignees:[{id:1,name:'Test Admin'}],tags:[],
          subtasks:{total:0,done:0},checklist:[],comments:[],unfinished_blockers:1},
        {id:25,title:'Prepare review',description:'More notes',status:'to_do',priority:'normal',due_date:'2026-11-23',
          start_date:null,team_id:7,created_by:1,assignees:[{id:1,name:'Test Admin'}],tags:[],
          subtasks:{total:0,done:0},checklist:[],comments:[],unfinished_blockers:0}
      ];
      window.fetch=async(url,opts={})=>{
        const name=String(url).split('?')[0].split('/').pop();
        const params=new URL(String(url), 'http://localhost/').searchParams;
        const method=opts.headers?.['X-HTTP-Method-Override']||opts.method||'GET';
        const body=opts.body?JSON.parse(opts.body):{};
        window.calls.push({name,method,body});
        let data={};
        if(name==='mfa_status.php') data={mfa_required:false};
        if(name==='me.php') data={user,csrf_token:'mock-csrf',organization_name:'Test Company',teams:[{id:7,name:'Design'}]};
        if(name==='lists.php') data={lists:[{id:3,name:'Releases',team_id:7}]};
        if(name==='users.php') data={users:[{...user,is_active:1}]};
        if(name==='tags.php') data={tags:[]};
        if(name==='monthly.php') data={weeks:[]};
        if(name==='my_work.php') data={tasks:tasks.map(t=>({...t,team_name:'Design',list_name:'Releases'})),next_before_id:null};
        if(name==='tasks.php') data={tasks,next_offset:null};
        if(name==='search.php') data={tasks:params.get('q')?.toLowerCase().includes('plan')?
          [{id:39,title:'Planning meeting',status:'to_do',list_name:'Releases',created_by:1,priority:'normal'}] : [],
          next_before_id:null};
        if(name==='task.php') {
          const id=Number(params.get('id'));
          data={task:{...(tasks.find(t=>t.id===id)||tasks[0]),
            dependencies:{depends_on:window.blocks,blocks:[]},recent_activity:[{action:'task_created',actor:'Test Admin',created_at:'2026-11-15 04:00:00'}]}};
        }
        if(name==='bulk.php') data={ok:true,changed:body.task_ids.length};
        if(name==='dependencies.php') {
          if(method==='POST') window.blocks.push({id:39,title:'Planning meeting',status:'to_do'});
          if(method==='DELETE') window.blocks=window.blocks.filter(t=>t.id!==body.blocker_id);
          data={ok:true,changed:true};
        }
        if(name==='inbox.php') data={unread_count:0,notifications:[],next_before_id:null};
        return {ok:true,status:200,json:async()=>data};
      };
    }''')
    page.add_script_tag(content=root.joinpath('assets/js/app.js').read_text())
    page.wait_for_selector('#app:not(.hidden)')
    page.locator('[data-view="tasks"]').click()
    page.wait_for_selector('.bulk-row-check')
    assert page.locator('.bulk-row-check').count() == 2
    assert page.locator('.blocked-badge').count() == 1
    page.locator('.bulk-row-check').nth(0).check()
    page.locator('.bulk-row-check').nth(1).check()
    page.locator('#bulk-field').select_option('priority')
    page.locator('#bulk-value').select_option('high')
    page.locator('#bulk-apply').click()
    page.wait_for_function("window.calls.some(c=>c.name==='bulk.php' && c.body.field==='priority' && c.body.value==='high' && c.body.task_ids.length===2)")
    page.wait_for_selector('.bulk-row-check')
    print('PASS: multi-select, role-ready bulk priority edit and API payload')
    page.locator('.task-row').first.locator('.task-title-cell').click()
    page.wait_for_selector('#tm-blocker-list .dependency-item')
    assert page.locator('.dependency-item').count() == 1
    assert 'unfinished' in page.locator('.dependency-help').first.inner_text()
    page.locator('#tm-blocker-search').fill('Planning')
    page.wait_for_selector('.dependency-suggestion')
    page.locator('.dependency-suggestion').first.click()
    page.wait_for_function("window.calls.some(c=>c.name==='dependencies.php' && c.method==='POST' && c.body.task_id===24 && c.body.blocker_id===39)")
    page.wait_for_selector('.dependency-item:nth-child(2)')
    assert page.locator('.dependency-item').count() == 2
    page.locator('.dependency-remove').first.click()
    page.wait_for_function("window.calls.some(c=>c.name==='dependencies.php' && c.method==='DELETE' && c.body.blocker_id===33)")
    print('PASS: task dependency search, add, remove, team-scoped requests')
    page.screenshot(path='/mnt/data/TaskFlow-workflow-Dependencies-Preview.png', full_page=True)
    page.locator('#close-task-modal').click()
    page.set_viewport_size({'width': 390, 'height': 790})
    page.wait_for_timeout(250)
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), 'mobile page width overflows'
    page.screenshot(path='/mnt/data/TaskFlow-workflow-Mobile-Tasks-Preview.png', full_page=True)
    assert not errors, errors
    print('PASS: no JS exceptions, 390px mobile task table stays inside viewport')
    browser.close()
