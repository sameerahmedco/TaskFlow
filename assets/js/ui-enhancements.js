/* TaskFlow 1.20.0: progressive interface enhancements.
 * No external runtime, no additional API calls, no role or permission changes.
 * Navigation is always sourced from the existing authorized sidebar buttons.
 */
(() => {
  'use strict';
  const $ = (selector, scope=document) => scope.querySelector(selector);
  const $$ = (selector, scope=document) => [...scope.querySelectorAll(selector)];
  const app = $('#app');
  if (!app) return;

  const sections = [];
  const navFilter = $('#nav-filter');
  const navHint = document.createElement('span');
  navHint.className = 'nav-filter-status sr-only';
  navHint.setAttribute('role', 'status');
  navFilter?.closest('.sidebar-finder')?.appendChild(navHint);

  // Group existing links, keeping their original buttons and click listeners.
  const nav = $('#team-nav');
  if (nav) {
    let group = null;
    for (const child of [...nav.children]) {
      if (child.classList.contains('nav-group-label')) {
        const label = child.textContent.trim();
        const section = document.createElement('section');
        section.className = 'nav-section';
        section.dataset.label = label.toLowerCase();
        const heading = document.createElement('button');
        heading.className = 'nav-group-toggle';
        heading.type = 'button';
        heading.setAttribute('aria-expanded', 'true');
        heading.setAttribute('aria-label', 'Collapse ' + label + ' pages');
        const words = document.createElement('span');
        words.textContent = label;
        const arrow = document.createElement('span');
        arrow.className = 'nav-group-chevron';
        arrow.setAttribute('aria-hidden', 'true');
        arrow.textContent = '+';
        heading.append(words, arrow);
        const body = document.createElement('div');
        body.className = 'nav-group-body';
        section.append(heading, body);
        nav.insertBefore(section, child);
        child.remove();
        const createdGroup = {section, heading, body, expanded: true};
        group = createdGroup;
        sections.push(createdGroup);
        heading.addEventListener('click', () => {
          createdGroup.expanded = !createdGroup.expanded;
          if (!navFilter.value.trim()) updateGroup(createdGroup);
        });
      } else if (group && child.classList.contains('nav-item')) {
        group.body.appendChild(child);
      }
    }
  }

  function updateGroup(group, forceOpen=false) {
    const expanded = forceOpen || group.expanded;
    group.section.classList.toggle('nav-section-collapsed', !expanded);
    group.heading.setAttribute('aria-expanded', String(expanded));
    group.heading.setAttribute('aria-label', (expanded ? 'Collapse ' : 'Expand ') + group.section.dataset.label + ' pages');
    group.body.hidden = !expanded;
  }
  sections.forEach(group => updateGroup(group));

  function visibleToUser(button) {
    return !button.closest('#admin-nav.hidden') && !button.closest('#team-nav.hidden');
  }
  function allViewButtons() {
    return $$('.nav-item[data-view]').filter(visibleToUser);
  }
  function filterNavigation(text) {
    const query = text.trim().toLocaleLowerCase();
    let matches = 0;
    for (const group of sections) {
      let inGroup = 0;
      for (const item of $$('.nav-item', group.body)) {
        const matched = !query || item.textContent.toLocaleLowerCase().includes(query);
        item.hidden = !matched;
        if (matched) inGroup++;
      }
      matches += inGroup;
      group.section.hidden = Boolean(query) && !inGroup;
      updateGroup(group, Boolean(query));
    }
    for (const item of $$('#admin-nav .nav-item')) {
      const matched = !query || item.textContent.toLocaleLowerCase().includes(query);
      item.hidden = !matched;
      if (matched && visibleToUser(item)) matches++;
    }
    const adminHeading = $('#admin-section-title');
    if (adminHeading) adminHeading.classList.toggle('nav-filter-hide', Boolean(query) && !$$('#admin-nav .nav-item:not([hidden])').length);
    navHint.textContent = query ? `${matches} matching pages` : '';
  }
  nav?.addEventListener('click',event=>{
    const item=event.target.closest('.nav-item[data-view]');
    if(!item) return;
    const group=sections.find(entry=>entry.body.contains(item));
    if(group) {group.expanded=true;updateGroup(group);}
    if(navFilter.value){navFilter.value='';filterNavigation('');}
  });
  navFilter?.addEventListener('input', e=>filterNavigation(e.target.value));
  navFilter?.addEventListener('keydown', e=>{
    if (e.key === 'Escape') { e.stopPropagation(); navFilter.value = ''; filterNavigation(''); navFilter.blur(); }
    if (e.key === 'Enter') {
      const match = allViewButtons().find(el => !el.hidden);
      if (match) { e.preventDefault(); match.click(); navFilter.value=''; filterNavigation(''); }
    }
  });

  // Lightweight command switcher, using the same authorized navigation as the sidebar.
  const dialog = $('#quick-switch-modal');
  const input = $('#quick-switch-input');
  const results = $('#quick-switch-results');
  const trigger = $('#quick-switch-btn');
  let selection = 0;
  let lastFocus = null;
  let available = [];
  const labelFor = (item) => item.querySelector(':scope > span:not(.inbox-badge)')?.textContent.trim() || item.textContent.trim();
  input?.setAttribute('role','combobox');
  input?.setAttribute('aria-autocomplete','list');
  input?.setAttribute('aria-controls','quick-switch-results');
  input?.setAttribute('aria-expanded','false');
  results?.setAttribute('role','listbox');
  const sectionFor = (item) => {
    if (item.closest('#admin-nav')) return 'Organization';
    return item.closest('.nav-section')?.dataset.label?.replace(/\b\w/g,ch=>ch.toUpperCase()) || 'Workspace';
  };
  function drawCommands() {
    if (!results) return;
    const query = input.value.trim().toLocaleLowerCase();
    available = allViewButtons().filter(item => {
      const searchable = labelFor(item) + ' ' + sectionFor(item);
      return searchable.toLocaleLowerCase().includes(query);
    }).slice(0, 28);
    selection = Math.max(0, Math.min(selection, available.length - 1));
    results.replaceChildren();
    input.removeAttribute('aria-activedescendant');
    if (!available.length) {
      const empty=document.createElement('div');
      empty.className='command-empty';
      empty.textContent='No matching pages. Try another name.';
      results.appendChild(empty);
      return;
    }
    available.forEach((item,index) => {
      const option=document.createElement('button');
      option.type='button'; option.className='command-result';
      option.dataset.index=String(index);
      option.id='quick-switch-option-'+index;
      option.setAttribute('role','option');
      option.setAttribute('aria-selected',String(index === selection));
      const title=document.createElement('strong');
      title.textContent=labelFor(item);
      const category=document.createElement('small');
      category.textContent=sectionFor(item);
      const hint=document.createElement('span');
      hint.className='command-enter';
      hint.setAttribute('aria-hidden','true');
      hint.textContent='Enter';
      option.append(title,category,hint);
      option.addEventListener('mouseenter',()=>{selection=index;markSelected();});
      option.addEventListener('click',()=>navigateCommand(index));
      results.appendChild(option);
    });
  }
  function markSelected(){
    $$('.command-result',results).forEach((node,index)=>node.setAttribute('aria-selected',String(index===selection)));
    const chosen=$$('.command-result',results)[selection];
    if(chosen){input.setAttribute('aria-activedescendant',chosen.id);chosen.scrollIntoView({block:'nearest'});}
    else input.removeAttribute('aria-activedescendant');
  }
  function closeCommands(){
    if (dialog.classList.contains('hidden')) return;
    dialog.classList.add('hidden');
    document.body.classList.remove('command-open');
    input.setAttribute('aria-expanded','false');
    input.removeAttribute('aria-activedescendant');
    if (lastFocus && lastFocus.isConnected && typeof lastFocus.focus==='function') lastFocus.focus();
  }
  function navigateCommand(index){
    const item=available[index];
    if (!item) return;
    const section=item.closest('.nav-section');
    const group=sections.find(g=>g.section===section);
    if (group) {group.expanded=true;updateGroup(group);}
    if (navFilter) {navFilter.value=''; filterNavigation('');}
    closeCommands();
    item.click();
  }
  function openCommands(){
    if (app.classList.contains('hidden') || !dialog || !input) return;
    // Keep the page switcher from navigating behind an unfinished save dialog.
    if (['task-modal','prompt-modal'].some(id=>!$('#'+id)?.classList.contains('hidden'))) return;
    lastFocus=document.activeElement;
    dialog.classList.remove('hidden');
    document.body.classList.add('command-open');
    input.setAttribute('aria-expanded','true');
    selection=0;
    input.value='';
    drawCommands();
    input.focus();
    markSelected();
  }
  trigger?.addEventListener('click',openCommands);
  $('#quick-switch-close')?.addEventListener('click',closeCommands);
  dialog?.addEventListener('click',e=>{if(e.target===dialog)closeCommands();});
  input?.addEventListener('input',()=>{selection=0;drawCommands();});
  input?.addEventListener('keydown',e=>{
    if (e.key==='ArrowDown'){ e.preventDefault();selection=Math.min(available.length-1,selection+1);markSelected(); }
    else if (e.key==='ArrowUp'){e.preventDefault();selection=Math.max(0,selection-1);markSelected();}
    else if (e.key==='Enter'){e.preventDefault();navigateCommand(selection);}
  });

  document.addEventListener('keydown',e=>{
    const editing=e.target?.matches?.('input,textarea,select,[contenteditable="true"]');
    if(e.key==='Tab' && !dialog.classList.contains('hidden')){
      const focusable=[input,...$$('.command-result',results),$('#quick-switch-close')].filter(Boolean);
      const first=focusable[0],last=focusable[focusable.length-1];
      if(e.shiftKey && document.activeElement===first){e.preventDefault();last.focus();}
      else if(!e.shiftKey && document.activeElement===last){e.preventDefault();first.focus();}
      return;
    }
    if(e.key==='Escape' && !dialog.classList.contains('hidden')){
      e.stopImmediatePropagation();e.preventDefault();closeCommands();return;
    }
    if((e.ctrlKey||e.metaKey) && e.shiftKey && e.key.toLowerCase()==='k'){
      e.preventDefault();e.stopImmediatePropagation();
      if(dialog.classList.contains('hidden'))openCommands();else closeCommands();
      return;
    }
    if(e.key==='/' && !editing && !e.ctrlKey && !e.metaKey && !e.altKey && !app.classList.contains('hidden')){
      e.preventDefault();
      if(window.matchMedia('(max-width:860px)').matches) $('#mobile-menu-btn')?.click();
      navFilter?.focus();
    }
  },true);

  const densityButton=$('#density-switch');
  const densityKey='taskflow-density';
  function applyDensity(value){
    const compact=value==='compact';
    document.documentElement.dataset.density=compact?'compact':'comfortable';
    densityButton.setAttribute('aria-pressed',String(compact));
    densityButton.setAttribute('aria-label',compact?'Use comfortable layout':'Use compact layout');
    densityButton.title=compact?'Use comfortable layout':'Use compact layout';
    try{localStorage.setItem(densityKey,compact?'compact':'comfortable');}catch(_){}
  }
  let storedDensity='compact';
  try{storedDensity=localStorage.getItem(densityKey)||'compact';}catch(_){}
  if(densityButton){applyDensity(storedDensity);densityButton.addEventListener('click',()=>applyDensity(document.documentElement.dataset.density==='compact'?'comfortable':'compact'));}

  // Avatar initials must contrast against an arbitrary, user-supplied avatar
  // color. Choosing between two foregrounds avoids invisible initials on both
  // light cyan and dark indigo backgrounds without a per-color CSS catalog.
  function avatarRelativeLuminance(rgb) {
    const channels=rgb.map(value=>{
      const v=value/255;
      return v<=0.04045 ? v/12.92 : ((v+0.055)/1.055)**2.4;
    });
    return 0.2126*channels[0]+0.7152*channels[1]+0.0722*channels[2];
  }
  function fixAvatarContrast(root) {
    const avatars=[];
    if(root?.matches?.('.avatar'))avatars.push(root);
    root?.querySelectorAll?.('.avatar').forEach(el=>avatars.push(el));
    for(const el of avatars){
      const bg=getComputedStyle(el).backgroundColor.match(/^rgba?\(([^)]+)\)/);
      if(!bg)continue;
      const channels=bg[1].replace(/,/g,' ').replace(/\//g,' ').split(/\s+/).map(Number);
      if(channels.length<3||channels.slice(0,3).some(x=>!Number.isFinite(x)))continue;
      const luminance=avatarRelativeLuminance(channels.slice(0,3));
      const lightContrast=1.05/(luminance+0.05);
      const darkContrast=(luminance+0.05)/(avatarRelativeLuminance([12,29,39])+0.05);
      el.style.color=lightContrast>=darkContrast?'#ffffff':'#0c1d27';
    }
  }
  fixAvatarContrast(app);
  const avatarObserver=new MutationObserver(records=>{
    for(const record of records){for(const node of record.addedNodes){
      if(node.nodeType===1)fixAvatarContrast(node);
    }}
  });
  avatarObserver.observe(app,{subtree:true,childList:true});

  // Thumb-friendly bottom navigation; link activation continues through the
  // original role-aware sidebar handlers and does not bypass the backend.
  const dockButtons=$$('[data-mobile-view]');
  function syncDock(){
    const active=$('.nav-item.active');
    dockButtons.forEach(button=>{
      const chosen=Boolean(active && active.dataset.view===button.dataset.mobileView);
      button.classList.toggle('active',chosen);
      if(chosen)button.setAttribute('aria-current','page');else button.removeAttribute('aria-current');
    });
  }
  for(const button of dockButtons){
    button.addEventListener('click',()=>{
      const item=allViewButtons().find(navItem=>navItem.dataset.view===button.dataset.mobileView);
      item?.click();
      if(button.dataset.mobileView==='search') $('#task-search-q')?.focus();
    });
  }
  $('#mobile-dock-more')?.addEventListener('click',()=>{
    const side=$('#workspace-sidebar');
    if(!side.classList.contains('open'))$('#mobile-menu-btn')?.click();
    navFilter?.focus();
  });
  const navObserver=new MutationObserver(syncDock);
  const teamNav=$('#team-nav');
  if(teamNav)navObserver.observe(teamNav,{attributes:true,subtree:true,attributeFilter:['class']});
  syncDock();
})();
