/* TaskFlow v3 — streamlined navigation and contextual views.
 * Uses the existing role-aware buttons and server APIs; never grants permissions.
 */
(() => {
  'use strict';
  const $=(q,root=document)=>root.querySelector(q);
  const $$=(q,root=document)=>Array.from(root.querySelectorAll(q));
  const app=$('#app'), drawer=$('#v2-tools-drawer'),toggle=$('#v2-tools-toggle'),nav=$('#v2-pinned-nav'),tabs=$('#v2-view-tabs');
  if(!app||!drawer||!toggle||!nav||!tabs)return;
  // Small, task-first project rail, inspired by the hierarchy of modern work apps.
  // Data arrives from app.js's authorized team list; no extra API/permission layer.
  const projectList=$('#v3-project-items'),projectCount=$('#v3-project-count');
  let projectContext={team_id:0,current_id:0,projects:[]};
  let showAllProjects=false;
  let projectSearch='';
  let expandedProjectId=0;
  const projectRail=$('.v3-project-rail');
  let projectSearchInput=null;
  if(projectRail&&projectList){
    const searchWrap=document.createElement('div');
    searchWrap.className='v3-project-search-wrap';
    projectSearchInput=document.createElement('input');
    projectSearchInput.id='v3-project-filter';
    projectSearchInput.type='search';
    projectSearchInput.setAttribute('aria-label','Filter projects');
    projectSearchInput.placeholder='Find a project…';
    projectSearchInput.autocomplete='off';
    searchWrap.appendChild(projectSearchInput);
    projectRail.insertBefore(searchWrap,projectList);
    projectSearchInput.addEventListener('input',()=>{projectSearch=projectSearchInput.value.trim().toLocaleLowerCase();drawProjectRail();});
    projectSearchInput.addEventListener('keydown',event=>{if(event.key==='Escape'){
      projectSearchInput.value='';projectSearch='';drawProjectRail();projectSearchInput.blur();
    }});
  }
  function drawProjectRail(){
    if(!projectList)return;
    projectList.replaceChildren();
    if(projectCount)projectCount.textContent=String(projectContext.projects.length);
    const hasMany=projectContext.projects.length>8;
    if(projectSearchInput){projectSearchInput.parentElement.hidden=!hasMany;}
    const matching=projectSearch ? projectContext.projects.filter(project=>project.name.toLocaleLowerCase().includes(projectSearch)) : projectContext.projects;
    const projects=(showAllProjects||projectSearch ? matching : matching.slice(0,8));
    if(!projects.length){const empty=document.createElement('span');empty.className='v3-project-empty';
      empty.textContent=projectSearch?'No matching projects':'No projects yet';projectList.appendChild(empty);return;}
    projects.forEach(project=>{
      const wrapper=document.createElement('div');wrapper.className='v3-project-group';
      const heading=document.createElement('div');heading.className='v3-project-row';
      const button=document.createElement('button');button.type='button';
      button.className='v3-project-link';button.dataset.projectId=String(project.id);
      if(project.id===projectContext.current_id){button.classList.add('is-selected');button.setAttribute('aria-current','true');}
      const dot=document.createElement('span');dot.className='v3-project-icon';dot.setAttribute('aria-hidden','true');
      dot.textContent=String(project.name).trim().slice(0,1).toUpperCase();
      const text=document.createElement('span');text.className='v3-project-name';text.textContent=project.name;
      button.append(dot,text);
      button.addEventListener('click',()=>document.dispatchEvent(new CustomEvent('taskflow:select-project',{detail:{id:project.id}})));
      const arrow=document.createElement('button');arrow.type='button';arrow.className='v3-project-submenu-toggle';
      arrow.setAttribute('aria-label','Expand '+project.name+' views');
      arrow.setAttribute('aria-controls','v3-project-submenu-'+project.id);
      arrow.dataset.projectMenu=String(project.id);
      arrow.setAttribute('aria-expanded',String(expandedProjectId===project.id));
      arrow.innerHTML='<span class="v3-submenu-chevron" aria-hidden="true"></span>';
      const panel=document.createElement('div');panel.id='v3-project-submenu-'+project.id;
      panel.className='v3-project-submenu';panel.hidden=expandedProjectId!==project.id;
      // Build children only for the open project, not for every row in a large team.
      if(expandedProjectId===project.id)for(const [view,label] of [['project-overview','Overview'],['tasks','Task list'],['board','Board'],
        ['timeline','Timeline'],['milestones','Milestones'],['project-risks','Risks'],['project-decisions','Decisions']]){
        if(!source(view))continue;
        const item=document.createElement('button');item.type='button';item.dataset.projectSubView=view;
        item.textContent=label;
        item.addEventListener('click',()=>document.dispatchEvent(new CustomEvent('taskflow:select-project',
          {detail:{id:project.id,view}})));
        panel.appendChild(item);
      }
      arrow.addEventListener('click',()=>{
        expandedProjectId=expandedProjectId===project.id?0:project.id;
        drawProjectRail();
        projectList.querySelector('.v3-project-submenu-toggle[data-project-menu="'+project.id+'"]')?.focus();
      });
      arrow.addEventListener('keydown',event=>{if(event.key==='ArrowDown' && expandedProjectId===project.id){
        event.preventDefault();panel.querySelector('button')?.focus();
      }});
      panel.addEventListener('keydown',event=>{if(event.key==='Escape'){
        event.preventDefault();event.stopPropagation();expandedProjectId=0;drawProjectRail();
        projectList.querySelector('.v3-project-submenu-toggle')?.focus();
      }});
      heading.append(button,arrow);wrapper.append(heading,panel);projectList.appendChild(wrapper);
    });
    if(hasMany && !projectSearch){const extra=document.createElement('button');extra.type='button';
      extra.className='v3-project-more';extra.textContent=showAllProjects?'Show fewer projects':`Show all ${projectContext.projects.length} projects`;
      extra.setAttribute('aria-expanded',String(showAllProjects));
      extra.addEventListener('click',()=>{showAllProjects=!showAllProjects;drawProjectRail();});projectList.appendChild(extra);}
  }
  document.addEventListener('taskflow:project-context',event=>{
    const info=event.detail||{};
    const projects=Array.isArray(info.projects)?info.projects.filter(item=>Number.isSafeInteger(item.id)&&item.id>0&&typeof item.name==='string'):[];
    const teamId=Number(info.team_id)||0;
    if(teamId!==projectContext.team_id){showAllProjects=false;expandedProjectId=0;projectSearch='';if(projectSearchInput)projectSearchInput.value='';}
    projectContext={team_id:teamId,current_id:Number(info.current_id)||0,projects};
    drawProjectRail();
  });
  document.addEventListener('taskflow:active-project',event=>{
    const id=Number(event.detail?.id);
    if(projectContext.projects.some(p=>p.id===id)){projectContext.current_id=id;drawProjectRail();}
  });
  const pinned=['my-work','tasks','board','calendar','inbox'];
  const groups={
    'my-work':[['my-work','Home'],['project-overview','Overview'],['tasks','List'],['board','Board'],['calendar','Calendar']],
    'tasks':[['project-overview','Overview'],['tasks','List'],['board','Board'],['calendar','Calendar'],['timeline','Timeline']],
    'board':[['project-overview','Overview'],['tasks','List'],['board','Board'],['calendar','Calendar'],['timeline','Timeline']],
    'calendar':[['project-overview','Overview'],['tasks','List'],['board','Board'],['calendar','Calendar'],['timeline','Timeline']],
    'timeline':[['project-overview','Overview'],['tasks','List'],['board','Board'],['calendar','Calendar'],['timeline','Timeline']],
    'project-overview':[['project-overview','Overview'],['tasks','List'],['board','Board'],['calendar','Calendar'],['timeline','Timeline']],
    'portfolio':[['portfolio','Portfolio'],['milestones','Milestones'],['project-risks','Risks'],['project-decisions','Decisions'],['project-updates','Updates']],
    'milestones':[['portfolio','Portfolio'],['milestones','Milestones'],['project-risks','Risks'],['project-decisions','Decisions'],['project-updates','Updates']],
    'project-risks':[['portfolio','Portfolio'],['milestones','Milestones'],['project-risks','Risks'],['project-decisions','Decisions'],['project-updates','Updates']],
    'project-decisions':[['portfolio','Portfolio'],['milestones','Milestones'],['project-risks','Risks'],['project-decisions','Decisions'],['project-updates','Updates']],
    'project-updates':[['portfolio','Portfolio'],['milestones','Milestones'],['project-risks','Risks'],['project-decisions','Decisions'],['project-updates','Updates']],
    'inbox':[['inbox','Inbox'],['announcements','Announcements'],['chat','Chat'],['roster','People']],
    'announcements':[['inbox','Inbox'],['announcements','Announcements'],['chat','Chat'],['roster','People']],
    'chat':[['inbox','Inbox'],['announcements','Announcements'],['chat','Chat'],['roster','People']],
    'roster':[['inbox','Inbox'],['announcements','Announcements'],['chat','Chat'],['roster','People']],
  };
  const isAuthorized=(node)=>node && !node.closest('#admin-nav.hidden');
  const source=(view)=>$$('.nav-item[data-view]').find(node=>node.dataset.view===view&&isAuthorized(node));
  function go(view){
    const item=source(view);
    if(!item)return;
    item.click();
    closeTools();
  }
  // Collapsible submenus keep everyday destinations close without creating
  // another always-open menu. Reuse the original role-aware nav source.
  // The arrow expands; the large label navigates as before.
  const submenuMap = {
    'my-work': [['my-work','My work'],['search','Find tasks'],['priorities','Weekly goals'],['time-off','My time off']],
    'tasks': [['project-overview','Project overview'],['tasks','Task list'],['portfolio','Portfolio'],
              ['timeline','Timeline'],['milestones','Milestones'],['project-risks','Risks & issues'],
              ['project-decisions','Decisions'],['project-updates','Status updates']],
    'board': [['board','Kanban board'],['templates','Task templates'],['workload','Workload planner']],
    'calendar': [['calendar','Task calendar'],['monthly','Monthly plan'],['time-off','Time off'],
                 ['availability','Team availability'],['holidays','Team holidays'],['leave-balances','Leave balances']],
    'inbox': [['inbox','Notifications'],['task-reviews','Task reviews'],['announcements','Announcements'],['chat','Team chat'],
              ['roster','People'],['activity','Activity']],
  };
  const sidebarMenus = new Map();
  let expandedMenu = null;
  let lastSubmenuView = null;
  function setExpandedMenu(key, persist=false){
    expandedMenu=key;
    for(const [id, menu] of sidebarMenus){
      const opened=id===key;
      menu.panel.hidden=!opened;
      menu.toggle.setAttribute('aria-expanded',String(opened));
      menu.toggle.setAttribute('aria-label',(opened?'Collapse ':'Expand ')+menu.label+' submenu');
      menu.toggle.title=(opened?'Hide ':'Show ')+menu.label+' pages';
      menu.wrapper.classList.toggle('v3-menu-expanded',opened);
    }
    if(persist){try {localStorage.setItem('taskflow-v3-sidebar-submenu',key||'');}catch(_){}}
  }
  for(const primary of Array.from(nav.querySelectorAll(':scope > button[data-v2-view]'))){
    const key=primary.dataset.v2View;
    const list=submenuMap[key];
    if(!list)continue;
    const wrapper=document.createElement('div');wrapper.className='v3-menu-group';wrapper.dataset.group=key;
    const heading=document.createElement('div');heading.className='v3-menu-heading';
    const arrow=document.createElement('button');arrow.type='button';arrow.className='v3-submenu-toggle';
    const name=primary.querySelector('span')?.textContent.trim()||key;
    arrow.setAttribute('aria-label','Expand '+name+' submenu');
    arrow.setAttribute('aria-controls','v3-submenu-'+key);
    arrow.setAttribute('aria-expanded','false');
    arrow.title='Show '+name+' pages';
    arrow.innerHTML='<span aria-hidden="true" class="v3-submenu-chevron"></span>';
    const body=document.createElement('div');body.id='v3-submenu-'+key;body.className='v3-submenu-items';body.hidden=true;
    for(const [view,label] of list){
      if(!source(view))continue;  // Role-aware: no new privileges are granted.
      const item=document.createElement('button');item.type='button';item.className='v3-submenu-link';
      item.dataset.v3SubmenuView=view;item.textContent=label;
      item.addEventListener('click',()=>{
        go(view);
        // After navigation, keep the parent submenu open and reveal the selected item.
        setExpandedMenu(key);
      });
      body.appendChild(item);
    }
    primary.parentNode.insertBefore(wrapper,primary);
    heading.append(primary,arrow);wrapper.append(heading,body);
    sidebarMenus.set(key,{wrapper,primary,toggle:arrow,panel:body,label:name});
    arrow.addEventListener('click',()=>setExpandedMenu(expandedMenu===key?null:key,true));
    arrow.addEventListener('keydown',event=>{
      if(event.key==='ArrowDown' && arrow.getAttribute('aria-expanded')==='true'){
        event.preventDefault();body.querySelector('button')?.focus();
      }
    });
    body.addEventListener('keydown',event=>{
      if(event.key==='Escape'){
        event.stopPropagation();setExpandedMenu(null,true);arrow.focus();
      }
    });
    primary.addEventListener('click',()=>setExpandedMenu(key));
  }
  // Restore only a valid, available submenu. Leave the initial workspace compact.
  try{
    const stored=localStorage.getItem('taskflow-v3-sidebar-submenu');
    if(stored&&sidebarMenus.has(stored))setExpandedMenu(stored);
  }catch(_){}
  function syncSidebarSubmenus(view){
    for(const [key,menu] of sidebarMenus){
      const active=Array.from(menu.panel.children).find(item=>item.dataset.v3SubmenuView===view);
      const matching=Boolean(active);
      menu.wrapper.classList.toggle('v3-menu-related',matching);
      menu.primary.classList.toggle('v3-menu-parent-active',matching);
      for(const item of menu.panel.children){
        const selected=item.dataset.v3SubmenuView===view;
        item.classList.toggle('active',selected);
        if(selected)item.setAttribute('aria-current','page');else item.removeAttribute('aria-current');
      }
    }
    // An external shortcut or contextual tab should reveal its destination.
    // Do not force-expand a menu at initial load or reopen one just collapsed.
    if(lastSubmenuView!==null && lastSubmenuView!==view){
      const preferred=Array.from(sidebarMenus).find(([key,menu])=>
        Array.from(menu.panel.children).some(item=>item.dataset.v3SubmenuView===view) &&
        (key===view || (key==='tasks' && ['project-overview','portfolio','milestones','project-risks','project-decisions','project-updates','timeline'].includes(view)) ||
         (key==='calendar' && ['monthly','availability','holidays','leave-balances'].includes(view)) ||
         (key==='inbox' && ['announcements','chat','roster','activity'].includes(view)) ||
         (key==='my-work' && ['search','priorities'].includes(view))))?.[0];
      if(preferred)setExpandedMenu(preferred);
    }
    lastSubmenuView=view;
  }
  function openTools(focus=false){
    drawer.hidden=false;toggle.setAttribute('aria-expanded','true');
    toggle.classList.add('is-open');
    if(focus)$('#nav-filter')?.focus();
  }
  function closeTools(){
    drawer.hidden=true;toggle.setAttribute('aria-expanded','false');
    toggle.classList.remove('is-open');
  }
  toggle.addEventListener('click',()=>drawer.hidden?openTools(true):closeTools());
  nav.addEventListener('click',event=>{
    const item=event.target.closest('[data-v2-view]');
    if(item)go(item.dataset.v2View);
  });
  // Retain every original feature in the library, but close its menu after
  // navigation. Focusing the existing search opens this library automatically.
  drawer.addEventListener('click',event=>{
    if(event.target.closest('.nav-item[data-view]'))closeTools();
  });
  $('#nav-filter')?.addEventListener('focus',()=>openTools(false));
  $('#nav-filter')?.addEventListener('input',()=>openTools(false));
  // The original Ctrl/Cmd+Shift+K command palette remains the global tool finder.
  function activeView(){return $$('.nav-item.active[data-view]').find(isAuthorized)?.dataset.view || 'my-work';}
  function sync(){
    const view=activeView();
    syncSidebarSubmenus(view);
    const main=$('#main-content');
    if(main) main.dataset.activeView=view;
    projectList?.querySelectorAll('.v3-project-link').forEach(button=>{
      const selected=Number(button.dataset.projectId)===projectContext.current_id && ['tasks','board','project-overview','timeline','milestones','project-risks','project-decisions'].includes(view);
      button.classList.toggle('is-selected',selected);
    });
    projectList?.querySelectorAll('.v3-project-submenu [data-project-sub-view]').forEach(button=>{
      const selected=Number(button.closest('.v3-project-group')?.querySelector('.v3-project-link')?.dataset.projectId)===projectContext.current_id && button.dataset.projectSubView===view;
      button.classList.toggle('active',selected);
      if(selected)button.setAttribute('aria-current','page');else button.removeAttribute('aria-current');
    });
    $$('#v2-pinned-nav [data-v2-view]').forEach(button=>{
      const selected=button.dataset.v2View===view;
      button.classList.toggle('active',selected);
      if(selected)button.setAttribute('aria-current','page');else button.removeAttribute('aria-current');
    });
    const related=groups[view]||[[view, $('#view-title')?.textContent || 'Overview']];
    const signature=related.map(([key])=>key).join(',');
    if(tabs.dataset.signature!==signature){
      tabs.replaceChildren();tabs.dataset.signature=signature;
      related.forEach(([key,label])=>{
        if(!source(key))return;
        const button=document.createElement('button');
        button.type='button';button.dataset.v2Tab=key;button.textContent=label;
        button.addEventListener('click',()=>go(key));tabs.appendChild(button);
      });
    }
    $$('.v2-view-tabs button',tabs).forEach(button=>{
      const selected=button.dataset.v2Tab===view;
      button.classList.toggle('active',selected);
      if(selected)button.setAttribute('aria-current','page');else button.removeAttribute('aria-current');
    });
    const other=!pinned.includes(view);
    toggle.classList.toggle('v2-tools-has-active',other);
    toggle.title=other?'Current: '+($('#view-title')?.textContent||view):'Browse the rest of your workspace';
  }
  // app.js does not emit navigation events; monitor existing active class
  // changes instead of modifying the router or making API calls.
  const observer=new MutationObserver(sync);
  const teamNav=$('#team-nav'),adminNav=$('#admin-nav');
  if(teamNav)observer.observe(teamNav,{subtree:true,attributes:true,attributeFilter:['class']});
  if(adminNav)observer.observe(adminNav,{subtree:true,attributes:true,attributeFilter:['class']});
  if($('#view-title'))observer.observe($('#view-title'),{childList:true,subtree:true,characterData:true});
  // Focus workspace removes the desktop sidebar while keeping global search,
  // project tabs, command palette and this restore button available.
  const focusButton=$('#workspace-focus-toggle');
  let focused=false;
  try{focused=localStorage.getItem('taskflow-v3-focus')==='true';}catch(_){}
  function setWorkspaceFocus(next){
    focused=Boolean(next);
    app.classList.toggle('v3-focus-workspace',focused);
    if(focusButton){
      focusButton.setAttribute('aria-pressed',String(focused));
      focusButton.setAttribute('aria-label',focused?'Show sidebar':'Hide sidebar for focus');
      focusButton.title=focused?'Show sidebar':'Focus workspace: hide sidebar';
    }
    try{localStorage.setItem('taskflow-v3-focus',String(focused));}catch(_){}
  }
  focusButton?.addEventListener('click',()=>setWorkspaceFocus(!focused));
  setWorkspaceFocus(focused);
  sync();
  closeTools();
  document.dispatchEvent(new CustomEvent('taskflow:project-context-request'));
})();
