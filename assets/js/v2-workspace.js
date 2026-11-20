/* TaskFlow v2.0.0 — streamlined navigation and contextual views.
 * Uses the existing role-aware buttons and server APIs; never grants permissions.
 */
(() => {
  'use strict';
  const $=(q,root=document)=>root.querySelector(q);
  const $$=(q,root=document)=>Array.from(root.querySelectorAll(q));
  const app=$('#app'), drawer=$('#v2-tools-drawer'),toggle=$('#v2-tools-toggle'),nav=$('#v2-pinned-nav'),tabs=$('#v2-view-tabs');
  if(!app||!drawer||!toggle||!nav||!tabs)return;
  const pinned=['my-work','tasks','board','calendar','inbox'];
  const groups={
    'my-work':[['my-work','Home'],['tasks','List'],['board','Board'],['calendar','Calendar']],
    'tasks':[['tasks','List'],['board','Board'],['calendar','Calendar'],['timeline','Timeline']],
    'board':[['tasks','List'],['board','Board'],['calendar','Calendar'],['timeline','Timeline']],
    'calendar':[['tasks','List'],['board','Board'],['calendar','Calendar'],['timeline','Timeline']],
    'timeline':[['tasks','List'],['board','Board'],['calendar','Calendar'],['timeline','Timeline']],
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
  sync();
  closeTools();
})();
