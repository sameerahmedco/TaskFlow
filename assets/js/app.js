/* =================================================================
   TaskFlow — front-end application logic
   Roles: administrator / manager / member. No self-registration.
   ================================================================= */
(function () {
  'use strict';

  const state = {
    user: null,
    csrf: null,
    teams: [],
    currentTeamId: null,
    currentView: 'monthly',
    lists: [],
    currentListId: null,
    taskTab: 'view', // add | view | pending | completed
    calendarMonth: new Date().getFullYear() + '-' + String(new Date().getMonth()+1).padStart(2,'0'),
    calendarMine: false,
    timelineMonth: new Date().getUTCFullYear() + '-' + String(new Date().getUTCMonth()+1).padStart(2,'0'),
    timelineDays: 56,
    timelineListId: 0,
    timelineMine: false,
    weekOffset: 0,
    dashPeriod: 'weekly',
    dashOffset: 0,
    members: [],
    tags: [],
    modalContexts: {},
    editingGoalsFor: new Set(),
  };

  // ---------------------------------------------------------------
  // API helper
  // ---------------------------------------------------------------
  async function api(path, options = {}) {
    const intendedMethod = options.method || 'GET';
    // Some shared hosts reject real PUT/PATCH/DELETE requests before PHP
    // even runs (bare "405 Method Not Allowed" from the web server). To
    // work everywhere, send those as POST with the real method in a
    // header; the backend reads it via effective_method().
    const needsOverride = ['PUT', 'PATCH', 'DELETE'].includes(intendedMethod);
    const opts = {
      method: needsOverride ? 'POST' : intendedMethod,
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
    };
    if (needsOverride) opts.headers['X-HTTP-Method-Override'] = intendedMethod;
    if (state.csrf && intendedMethod !== 'GET') opts.headers['X-CSRF-Token'] = state.csrf;
    if (options.body) opts.body = JSON.stringify(options.body);

    const res = await fetch('api/' + path, opts);
    let data;
    try { data = await res.json(); }
    catch (e) {
      // An HTTP 200 with HTML/empty/invalid JSON is not proof of a saved edit.
      // Never silently treat it as a successful empty response.
      if (res.ok) throw new Error('The server returned an invalid response. Check whether your changes were saved before retrying.');
      data = {};
    }
    if (res.status === 401 && !options.preserveMfa) { showAuthScreen(); throw new Error(data?.error || 'Not authenticated'); }
    if (!res.ok) throw new Error(data?.error || 'Something went wrong.');
    if (data === null || typeof data !== 'object' || Array.isArray(data))
      throw new Error('The server returned an unexpected response. Check your changes before retrying.');
    return data;
  }

  function toast(message, isError = false) {
    const el = document.getElementById('toast');
    el.textContent = message;
    el.className = 'toast' + (isError ? ' toast-error' : '');
    el.classList.remove('hidden');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => el.classList.add('hidden'), 3000);
  }

  function esc(str) {
    const d = document.createElement('div');
    d.textContent = str == null ? '' : String(str);
    // Safe both in text nodes and inside quoted HTML attributes.
    return d.innerHTML.replaceAll('"', '&quot;').replaceAll("'", '&#39;');
  }

  function escapeForRegex(str) {
    var specialChars = '.*+?^' + String.fromCharCode(36) + '{}()|[]' + String.fromCharCode(92);
    var out = '';
    for (var i = 0; i < str.length; i++) {
      var ch = str.charAt(i);
      out += (specialChars.indexOf(ch) !== -1 ? String.fromCharCode(92) : '') + ch;
    }
    return out;
  }

  function hoursText(minutes) {
    const n = Number(minutes || 0) / 60;
    return n.toFixed(2).replace(/\.?0+$/, '') + 'h';
  }
  function hoursInput(minutes) {
    if (minutes == null || minutes === '') return '';
    return String(Math.round(Number(minutes) / 60 * 10000) / 10000);
  }
  function readEstimateHours(inputId) {
    const value = document.getElementById(inputId).value.trim();
    if (!value) return null;
    const hours = Number(value);
    if (!Number.isFinite(hours) || hours < 1 / 60 || hours > 400)
      throw new Error('Estimated effort must be between 1 minute and 400 hours, or blank.');
    const minutes = Math.round(hours * 60);
    if (minutes < 1 || minutes > 24000) throw new Error('Enter an estimate of 1 to 24,000 minutes.');
    return minutes;
  }
  function fmtDate(dateStr) {
    if (!dateStr) return '';
    return new Date(dateStr + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  }
  function initials(name) {
    return (name || '?').trim().split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase();
  }
  function roleLabel(r) { return { administrator: 'Administrator', manager: 'Manager', member: 'Member' }[r] || r; }
  function rolesLabel(roles) { return (roles || []).map(roleLabel).join(', ') || 'No role'; }
  function iHave(roleList) { return (state.user.roles || []).some(r => roleList.includes(r)); }
  function personHas(person, roleList) { return (person.roles || []).some(r => roleList.includes(r)); }
  function personMetaHtml(person) {
    const bits = [];
    if (person.roles && person.roles.length) bits.push(rolesLabel(person.roles));
    if (person.team_name) bits.push(esc(person.team_name));
    return bits.length ? `<div class="person-meta">${bits.map(esc).join(' &middot; ')}</div>` : '';
  }

  // ---------------------------------------------------------------
  // Screen toggles
  // ---------------------------------------------------------------
  function showAuthScreen() {
    const app = document.getElementById('app');
    const task = document.getElementById('task-modal');
    const prompt = document.getElementById('prompt-modal');
    const wasInWorkspace = !app.classList.contains('hidden') ||
      !task.classList.contains('hidden') || !prompt.classList.contains('hidden');
    // Expired sessions must never leave a modal floating above the sign-in form.
    ++taskModalRequestSeq;
    ++promptModalGeneration;
    taskDetailCurrentId = null;taskDetailTab = 'details';commentReplyTo = null;
    taskModalReturnFocus = null;
    task.classList.add('hidden');prompt.classList.add('hidden');
    prompt.setAttribute('aria-busy','false');
    document.getElementById('prompt-confirm').disabled = false;
    document.getElementById('prompt-cancel').disabled = false;
    document.getElementById('prompt-error').hidden = true;
    task.setAttribute('aria-hidden','true');
    app.removeAttribute('aria-hidden');
    document.getElementById('auth-screen').classList.remove('hidden');
    document.getElementById('pw-screen').classList.add('hidden');
    document.getElementById('mfa-screen').classList.add('hidden');
    app.classList.add('hidden');
    if (wasInWorkspace) document.querySelector('#login-form input[name="email"]')?.focus();
  }
  function showPasswordScreen() {
    document.getElementById('auth-screen').classList.add('hidden');
    document.getElementById('pw-screen').classList.remove('hidden');
    document.getElementById('mfa-screen').classList.add('hidden');
    document.getElementById('app').classList.add('hidden');
  }
  function showMfaScreen() {
    document.getElementById('auth-screen').classList.add('hidden');
    document.getElementById('pw-screen').classList.add('hidden');
    document.getElementById('app').classList.add('hidden');
    document.getElementById('mfa-screen').classList.remove('hidden');
    document.getElementById('mfa-code').focus();
  }
  function showApp() {
    document.getElementById('auth-screen').classList.add('hidden');
    document.getElementById('pw-screen').classList.add('hidden');
    document.getElementById('mfa-screen').classList.add('hidden');
    document.getElementById('app').classList.remove('hidden');
  }

  // ---------------------------------------------------------------
  // Login
  // ---------------------------------------------------------------
  document.getElementById('login-form').addEventListener('submit', async e => {
    e.preventDefault();
    const errEl = document.getElementById('login-error');
    errEl.textContent = '';
    const fd = new FormData(e.target);
    try {
      // Anonymous CSRF token is bound to the new/preexisting browser session.
      const anonymous = await api('csrf.php');
      state.csrf = anonymous.csrf_token;
      const data = await api('login.php', { method: 'POST', body: { email: fd.get('email'), password: fd.get('password'), remember: fd.get('remember') === 'on' } });
      state.csrf = data.csrf_token;
      if (data.mfa_required) { showMfaScreen(); return; }
      state.user = data.user;
      if (data.user.must_change_password) { showPasswordScreen(); return; }
      bootstrapApp();
    } catch (err) { errEl.textContent = err.message; }
  });

  document.getElementById('mfa-form').addEventListener('submit', async e => {
    e.preventDefault();
    const err = document.getElementById('mfa-error');
    err.textContent = '';
    try {
      const response = await api('mfa_login.php', { method:'POST', preserveMfa:true, body:{ code: document.getElementById('mfa-code').value.trim() } });
      state.user = response.user;
      state.csrf = response.csrf_token;
      document.getElementById('mfa-code').value = '';
      if (state.user.must_change_password) { showPasswordScreen(); return; }
      await bootstrapApp();
    } catch (e) { err.textContent = e.message; }
  });
  document.getElementById('mfa-cancel').addEventListener('click', async () => {
    try { await api('logout.php', { method:'POST', preserveMfa:true }); } catch (e) {}
    document.getElementById('mfa-code').value = '';
    showAuthScreen();
  });

  document.getElementById('pw-form').addEventListener('submit', async e => {
    e.preventDefault();
    const errEl = document.getElementById('pw-error');
    errEl.textContent = '';
    const fd = new FormData(e.target);
    const p1 = fd.get('new_password'), p2 = fd.get('confirm_password');
    if (p1 !== p2) { errEl.textContent = 'Passwords do not match.'; return; }
    try {
      const changed = await api('change_password.php', { method: 'POST', body: { current_password: '', new_password: p1 } });
      state.csrf = changed.csrf_token;
      toast('Password set. Welcome!');
      bootstrapApp();
    } catch (err) { errEl.textContent = err.message; }
  });

  document.getElementById('change-pw-btn').addEventListener('click', () => {
    promptModal('Change password', `
      <input type="password" id="p-cur-pw" placeholder="Current password" style="width:100%;padding:8px;margin-bottom:8px;border:1.5px solid var(--border);border-radius:8px;">
      <input type="password" id="p-new-pw" placeholder="New passphrase (12–72 bytes)" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;">
    `, async () => {
      const current_password = document.getElementById('p-cur-pw').value;
      const new_password = document.getElementById('p-new-pw').value;
      try {
        const response = await api('change_password.php', { method: 'POST', body: { current_password, new_password } });
        state.csrf = response.csrf_token;
        toast('Password updated');
      } catch (err) { toast(err.message, true); }
    });
  });

  document.getElementById('logout-btn').addEventListener('click', async () => {
    stopChatPolling();
    stopNotificationPolling();
    try { await api('logout.php', { method: 'POST' }); } catch (e) {}
    state.user = null;
    showAuthScreen();
  });

  // ---------------------------------------------------------------
  // Bootstrap
  // ---------------------------------------------------------------
  async function bootstrapApp() {
    const data = await api('me.php');
    state.user = data.user;
    state.csrf = data.csrf_token;
    state.teams = data.teams;
    const orgLabel = document.getElementById('org-name');
    if (orgLabel) orgLabel.textContent = data.organization_name || '';

    if (state.user.must_change_password) { showPasswordScreen(); return; }

    showApp();
    renderMeChip();
    setupRoleNav();

    if (iHave(['administrator'])) {
      document.getElementById('team-switcher').classList.remove('hidden');
      const sel = document.getElementById('team-select');
      sel.innerHTML = state.teams.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
      sel.addEventListener('change', () => { state.currentTeamId = Number(sel.value); onTeamChanged(); });
      state.currentTeamId = state.teams.length ? state.teams[0].id : null;
      if (state.currentTeamId) await loadTeamContext();
      setView('my-work');
    } else {
      state.currentTeamId = state.user.team_id;
      if (!state.currentTeamId) {
        document.getElementById('view-container').innerHTML = `<div class="empty-state"><h3>No team assigned yet</h3><p>Ask your Administrator to add you to a team.</p></div>`;
        return;
      }
      await loadTeamContext();
      setView('my-work');
    }

    maybeStartNotificationPolling();
    refreshInboxBadge();
  }

  // ---------------------------------------------------------------
  // Browser/desktop notifications — polls while the app is open
  // (including backgrounded tabs). Does NOT wake a closed browser.
  // ---------------------------------------------------------------
  let notificationPollTimer = null;
  let notificationLastId = null;

  function browserNotifyStatusText(enabled) {
    if (!('Notification' in window)) return 'Not supported in this browser.';
    if (!enabled) return 'Off.';
    if (Notification.permission === 'denied') return 'Blocked — allow notifications in your browser settings.';
    return 'On — you\u2019ll get a notification while TaskFlow is open.';
  }

  async function maybeStartNotificationPolling() {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    try {
      const data = await api('profile.php');
      if (data.browser_enabled) startNotificationPolling();
    } catch (err) { /* silent — not critical to app startup */ }
  }

  function startNotificationPolling() {
    if (notificationPollTimer) return; // already running
    notificationPollTimer = setInterval(pollNotifications, 20000);
    pollNotifications(); // establish baseline immediately
  }

  function stopNotificationPolling() {
    if (notificationPollTimer) { clearInterval(notificationPollTimer); notificationPollTimer = null; }
  }

  async function pollNotifications() {
    try {
      const url = notificationLastId === null ? 'notifications.php' : `notifications.php?since_id=${notificationLastId}`;
      const data = await api(url);
      notificationLastId = data.last_id;
      if (data.notifications.length) refreshInboxBadge();
      if (Notification.permission === 'granted') {
        data.notifications.forEach(n => {
          try { new Notification(n.title, { body: n.body }); } catch (e) { /* ignore */ }
        });
      }
    } catch (err) { /* silent — next poll retries */ }
  }

  function renderMeChip() {
    const el = document.getElementById('me-chip');
    el.innerHTML = `<span class="avatar" style="background:${esc(state.user.avatar_color)}">${esc(initials(state.user.name))}</span>
      <div><div style="font-weight:700;">${esc(state.user.name)}</div><div style="font-size:11px;color:var(--text-faint);">${esc(rolesLabel(state.user.roles))}${state.user.team_name ? ' &middot; ' + esc(state.user.team_name) : ''}</div></div>`;
  }

  function setupRoleNav() {
    const isAdmin = iHave(['administrator']);
    document.getElementById('admin-nav').classList.toggle('hidden', !isAdmin);
    document.getElementById('admin-section-title').classList.toggle('hidden', !isAdmin);
    document.querySelectorAll('#admin-nav .nav-item, #team-nav .nav-item').forEach(btn => {
      btn.addEventListener('click', () => { setView(btn.dataset.view); closeMobileSidebar(); });
    });
  }

  function openMobileSidebar() {
    document.querySelector('.sidebar').classList.add('open');
    document.getElementById('mobile-menu-btn').setAttribute('aria-expanded', 'true');
    document.getElementById('sidebar-backdrop').classList.add('visible');
  }
  function closeMobileSidebar() {
    document.querySelector('.sidebar').classList.remove('open');
    document.getElementById('mobile-menu-btn').setAttribute('aria-expanded', 'false');
    document.getElementById('sidebar-backdrop').classList.remove('visible');
  }
  document.getElementById('mobile-menu-btn').addEventListener('click', openMobileSidebar);
  document.getElementById('sidebar-backdrop').addEventListener('click', closeMobileSidebar);

  async function onTeamChanged() {
    state.modalContexts = {};
    milestoneSelectedId = null;
    milestoneListFilter = '';
    projectUpdateListFilter = '';
    riskProjectFilter = ''; riskStatusFilter = ''; riskSeverityFilter = ''; riskArchived = false; riskCursor = null;
    decisionProjectFilter = ''; decisionStatusFilter = ''; decisionArchived = false; decisionCursor = null;
    announcementCursor = null;
    announcementShowArchive = false;
    await loadTeamContext();
    setView(['org-dashboard','teams','users','org-activity','search','my-work','inbox','board','calendar','templates','time-reports','timesheet','workload','time-off','availability','leave-balances','holidays','milestones','portfolio','project-updates','project-risks','project-decisions','project-overview','task-reviews','announcements','timeline'].includes(state.currentView) ? state.currentView : 'monthly');
  }

  // Keep the current project per signed-in user/team in this browser tab.
  // Only restore IDs still present in the server-authorized team list.
  function projectPreferenceKey() { return `taskflow-project:${Number(state.user?.id)}:${Number(state.currentTeamId)}`; }
  function rememberProjectSelection() {
    if (!state.user || !state.currentTeamId || !state.currentListId) return;
    try { sessionStorage.setItem(projectPreferenceKey(),String(Number(state.currentListId))); } catch (_) {}
    document.dispatchEvent(new CustomEvent('taskflow:active-project',{detail:{id:Number(state.currentListId)}}));
  }
  function restoredProjectSelection(lists) {
    let remembered=0;
    try { remembered=Number(sessionStorage.getItem(projectPreferenceKey())||0); } catch (_) {}
    const chosen=lists.find(l=>Number(l.id)===remembered);
    return chosen?Number(chosen.id):(lists.length?Number(lists[0].id):null);
  }

  async function loadTeamContext() {
    if (!state.currentTeamId) return;
    try {
      const listData = await api('lists.php?team_id=' + state.currentTeamId);
      state.lists = listData.lists;
      state.currentListId = restoredProjectSelection(state.lists);
      document.dispatchEvent(new CustomEvent('taskflow:project-context',{detail:{
        team_id:Number(state.currentTeamId),current_id:Number(state.currentListId||0),
        projects:state.lists.map(l=>({id:Number(l.id),name:String(l.name||'Project')}))
      }}));
      const tagData = await api('tags.php?team_id=' + state.currentTeamId);
      state.tags = tagData.tags;
    } catch (err) {
      console.error('loadTeamContext failed:', err);
      toast('Could not load this team\u2019s lists: ' + err.message, true);
      state.lists = [];
    }
  }

  // The lightweight workspace script may load after the first team context.
  // Re-emit only already-authorized project names when its rail initializes.
  document.addEventListener('taskflow:project-context-request',()=>{
    if(!state.user || !state.currentTeamId)return;
    document.dispatchEvent(new CustomEvent('taskflow:project-context',{detail:{
      team_id:Number(state.currentTeamId),current_id:Number(state.currentListId||0),
      projects:state.lists.map(l=>({id:Number(l.id),name:String(l.name||'Project')}))
    }}));
  });
  // Project shortcuts are selected only from the server-authorized team list.
  document.addEventListener('taskflow:select-project', event=>{
    const id=Number(event.detail?.id);
    if(!state.user || !Number.isSafeInteger(id) || !state.lists.some(l=>Number(l.id)===id))return;
    state.currentListId=id;
    rememberProjectSelection();
    state.taskTab='view';
    const requestedView=String(event.detail?.view||'tasks');
    const projectViews=['tasks','project-overview','board','timeline','milestones','project-risks','project-decisions'];
    setView(projectViews.includes(requestedView)?requestedView:'tasks');
  });

  // ---------------------------------------------------------------
  // View switching
  // ---------------------------------------------------------------
  function setView(view) {
    document.getElementById('view-container').onclick = null;
    state.currentView = view;
    document.querySelectorAll('.nav-item').forEach(b => b.classList.toggle('active', b.dataset.view === view));
    const titles = {
      'my-work': 'My Work', 'task-reviews':'Task reviews', 'project-overview':'Project overview', board: 'Board', inbox: 'Inbox', calendar:'Calendar', timeline:'Project timeline', 'time-reports':'Time reports', timesheet:'Weekly timesheet', workload:'Workload planner', 'time-off':'Time off', availability:'Team availability', 'leave-balances':'Leave balances', holidays:'Team holidays', milestones:'Milestones', portfolio:'Project portfolio', 'project-updates':'Project updates', 'project-risks':'Project risks', 'project-decisions':'Project decisions', announcements:'Announcements', templates:'Templates', monthly: 'Monthly', tasks: 'Tasks', search: 'Search Tasks', 'team-dashboard': 'Team Dashboard', priorities: 'Weekly Goals',
      roster: 'Team Members', activity: 'Activity', 'org-dashboard': 'Organization Dashboard',
      teams: 'Teams', users: 'Users', 'org-activity': 'Organization Activity', chat: 'Chat',
      'advanced-settings': 'Advanced Settings', 'my-account': 'My Account',
    };
    document.getElementById('view-title').textContent = titles[view] || 'TaskFlow';
    const adminViews = ['org-dashboard','teams','users','org-activity','advanced-settings'];
    const collaborate = ['chat','roster','activity','announcements','task-reviews'];
    const planning = ['calendar','timeline','templates','time-reports','timesheet','workload','time-off','availability','leave-balances','holidays','milestones','portfolio','project-updates','project-risks','project-decisions','project-overview','monthly','priorities','team-dashboard'];
    document.getElementById('view-overline').textContent = adminViews.includes(view) ? 'ORGANIZATION / ADMINISTRATION' : collaborate.includes(view) ? 'WORKSPACE / COLLABORATION' : planning.includes(view) ? 'WORKSPACE / PLANNING' : 'WORKSPACE / YOUR WORK';
    const descriptions = {
      'my-work':'Your priorities, without the noise.',
      board:'Move work forward, one card at a time.',
      tasks:'Plan and manage the details that matter.',
      search:'Find exactly what your team is working on.',
      inbox:'Updates and reminders, all in one place.',
      'task-reviews':'Request feedback, approve finished work, and track review history.',
      calendar:'A clear calendar of every due date.',
      timeline:'A visual roadmap of tasks, milestones and deadlines.',
      templates:'Reusable starting points for repeatable work.',
      'time-reports':'A clear view of logged work, organized by day and task.',
      timesheet:'Track, submit and review weekly timesheets with an approval history.',
      workload:'Plan the week with estimates, approved leave, and logged hours.',
      'time-off':'Request full-day or half-day leave and track approvals.',
      availability:'See approved team absences and holidays without exposing private request notes.',
      'leave-balances':'Review annual approved leave; managers can set advisory allowances.',
      holidays:'Plan shared holidays and keep workload availability accurate.',
      'project-updates':'Share delivery status, risks, and next steps across your team.',
      'project-overview':'Progress, priorities and decisions for one project.',
      'project-risks':'Track project issues, mitigation plans and delivery risk.',
      'project-decisions':'Capture project choices, owners, outcomes and review dates.',
      announcements:'Team news, pinned notices and acknowledgements in one place.',
      milestones:'Track delivery targets and work completed against them.',
      portfolio:'See every task list, its progress, and delivery risks.',
      monthly:'A clear view of what is coming up.',
      priorities:'Keep the most important outcomes in sight.',
      'team-dashboard':'Understand progress across your team.',
      chat:'Stay in sync with the people you work with.',
      'my-account':'Manage your profile and account security.',
    };
    document.getElementById('view-description').textContent = descriptions[view] || 'Your team, moving forward together.';
    document.getElementById('topbar-actions').innerHTML = '';

    if (view !== 'chat') stopChatPolling();

    const renderers = {
      'my-work': renderMyWork, 'project-overview':renderProjectOverview, board: renderBoard, inbox: renderInbox, 'task-reviews':renderTaskReviews, calendar:renderCalendar, timeline:renderTimeline, 'time-reports':renderTimeReports, timesheet:renderTimesheet, workload:renderWorkload, 'time-off':renderTimeOff, availability:renderAvailability, 'leave-balances':renderLeaveBalances, holidays:renderHolidays, milestones: renderMilestones, portfolio:renderPortfolio, 'project-updates':renderProjectUpdates, 'project-risks':renderProjectRisks, 'project-decisions':renderProjectDecisions, announcements:renderAnnouncements, templates:renderTemplates, monthly: renderMonthly, tasks: renderTasksView, search: renderSearchTasks, 'team-dashboard': () => renderDashboard('team'),
      priorities: renderPriorities, roster: renderRoster, activity: () => renderActivity(state.currentTeamId),
      'org-dashboard': () => renderDashboard('org'), teams: renderTeamsAdmin, users: renderUsersAdmin,
      'org-activity': () => renderActivity(null), chat: renderChat, 'advanced-settings': renderAdvancedSettings,
      'my-account': renderMyAccount,
    };
    return (renderers[view] || (() => {}))();
  }

  // TaskFlow: lightweight shell controls (no third-party runtime).
  const themeStorageKey = 'taskflow-appearance';
  const appearanceOptions = ['light','dark','system','ocean','forest','lavender','sunset','midnight','charcoal','black'];
  const systemThemeQuery = typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  const darkPalettes = ['dark','midnight','charcoal','black'];
  function setAppearance(preference, save=true) {
    const chosen = appearanceOptions.includes(preference) ? preference : 'light';
    const theme = chosen === 'system' ? (systemThemeQuery?.matches ? 'dark' : 'light')
      : (darkPalettes.includes(chosen) ? 'dark' : 'light');
    document.documentElement.dataset.theme = theme;
    document.documentElement.dataset.themePreference = chosen;
    document.documentElement.dataset.palette = chosen === 'system' ? theme : chosen;
    const switcher = document.getElementById('theme-switch');
    switcher.setAttribute('aria-label', theme === 'dark' ? 'Switch to light appearance' : 'Switch to dark appearance');
    switcher.setAttribute('title', chosen === 'system'
      ? 'Following device appearance · click to choose '+(theme === 'dark'?'light':'dark')
      : 'Use '+(theme === 'dark'?'light':'dark')+' appearance');
    switcher.setAttribute('aria-pressed', String(theme === 'dark'));
    const iconName = theme === 'dark' ? 'sun' : 'moon';
    const icon = switcher.querySelector('use');
    if (icon) {
      const href = icon.getAttribute('href') || 'assets/img/icons.svg#moon';
      const prefix = href.includes('#') ? href.slice(0,href.lastIndexOf('#')+1) : 'assets/img/icons.svg#';
      icon.setAttribute('href',prefix+iconName);
    }
    const select = document.getElementById('appearance-preference');
    if (select) select.value=chosen;
    document.querySelectorAll('[data-theme-choice]').forEach(button=>{
      button.setAttribute('aria-pressed',String(button.dataset.themeChoice===chosen));
    });
    const themeColors={light:'#ffffff',dark:'#0c1822',ocean:'#f6faff',forest:'#f7faf6',
      lavender:'#fbf9ff',sunset:'#fffaf6',midnight:'#0b1427',charcoal:'#202124',black:'#000000'};
    const meta=document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content',themeColors[chosen==='system'?theme:chosen] || '#ffffff');
    if (save) {
      try { localStorage.setItem(themeStorageKey,chosen); } catch (_) { /* no persistent storage */ }
    }
  }
  let savedAppearance = document.documentElement.dataset.themePreference || 'light';
  try { savedAppearance = localStorage.getItem(themeStorageKey) || savedAppearance; } catch (_) {}
  setAppearance(savedAppearance,false);
  document.getElementById('theme-switch').addEventListener('click',()=>{
    // One-click toggle always selects an explicit Light or Dark setting.
    setAppearance(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
  });
  document.getElementById('appearance-preference')?.addEventListener('change',event=>{
    setAppearance(event.target.value);
  });
  document.querySelectorAll('[data-theme-choice]').forEach(button=>{
    button.addEventListener('click',()=>setAppearance(button.dataset.themeChoice));
  });
  if (systemThemeQuery) {
    const syncSystemAppearance=()=>{
      if (document.documentElement.dataset.themePreference === 'system') setAppearance('system',false);
    };
    if (systemThemeQuery.addEventListener) systemThemeQuery.addEventListener('change',syncSystemAppearance);
    else if (systemThemeQuery.addListener) systemThemeQuery.addListener(syncSystemAppearance);
  }
  document.getElementById('topbar-search').addEventListener('click', () => {
    if (!state.user || !state.currentTeamId) return;
    setView('search');
    document.getElementById('task-search-q')?.focus();
  });
  document.getElementById('quick-add-task').addEventListener('click', async () => {
    if (!state.user || !state.currentTeamId || !state.currentListId) { toast('Choose a team and list first.', true); return; }
    state.taskTab = 'add';
    await setView('tasks');
    document.getElementById('at-title')?.focus();
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      if (!document.getElementById('quick-switch-modal').classList.contains('hidden')) return;
      if (!document.getElementById('prompt-modal').classList.contains('hidden')) {
        e.preventDefault();
        document.getElementById('prompt-cancel').click();
      } else if (!document.getElementById('task-modal').classList.contains('hidden')) {
        closeTaskModal();
      } else { closeMobileSidebar(); }
    }
  });
  // Keyboard shortcut: Ctrl/Cmd+K opens team-scoped task search.
  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key.toLowerCase() === 'k' && state.user && state.currentTeamId
      && document.getElementById('task-modal').classList.contains('hidden')
      && document.getElementById('prompt-modal').classList.contains('hidden')) {
      e.preventDefault();
      setView('search');
      document.getElementById('task-search-q')?.focus();
    }
  });

  // ---------------------------------------------------------------
  // PROJECT OVERVIEW: one bounded, permission-scoped project health snapshot.
  // ---------------------------------------------------------------
  let projectOverviewRequestSeq = 0;
  const overviewHealthText = {
    empty:'Not started', complete:'Complete', on_track:'On track', attention:'Needs attention', at_risk:'At risk',
  };
  function overviewEmpty(message, icon='○') {
    return `<div class="project-overview-empty"><span aria-hidden="true">${icon}</span><p>${esc(message)}</p></div>`;
  }
  async function renderProjectOverview() {
    const container=document.getElementById('view-container');
    if (!state.currentTeamId) {container.innerHTML=emptyTeamState();return;}
    if (!state.lists.length) {
      container.innerHTML='<div class="empty-state"><h3>No projects yet</h3><p>Create a task list to start tracking project health.</p><button type="button" class="btn btn-primary" id="overview-open-tasks">Go to projects</button></div>';
      container.querySelector('#overview-open-tasks')?.addEventListener('click',()=>setView('tasks'));
      return;
    }
    if (!state.currentListId || !state.lists.some(l=>Number(l.id)===Number(state.currentListId))) state.currentListId=Number(state.lists[0].id);
    const actions=document.getElementById('topbar-actions');
    actions.innerHTML=`<label class="overview-project-picker"><span>Project</span><select id="overview-project-select" aria-label="Choose project">${state.lists.map(l=>`<option value="${Number(l.id)}" ${Number(l.id)===Number(state.currentListId)?'selected':''}>${esc(l.name)}</option>`).join('')}</select></label><button class="btn btn-ghost" type="button" id="overview-refresh">Refresh</button>`;
    actions.querySelector('#overview-project-select').addEventListener('change',event=>{
      state.currentListId=Number(event.target.value);rememberProjectSelection();loadProjectOverview();
    });
    actions.querySelector('#overview-refresh').addEventListener('click',()=>loadProjectOverview());
    container.innerHTML='<section class="project-overview-shell" id="project-overview" aria-live="polite"><p class="project-overview-loading">Loading project health…</p></section>';
    container.onclick=event=>{
      const task=event.target.closest('[data-overview-task]');
      if (task) {openTaskModal(Number(task.dataset.overviewTask));return;}
      const next=event.target.closest('[data-overview-destination]');
      if (!next) return;
      const view=next.dataset.overviewDestination;
      const id=Number(state.currentListId);
      if (view==='milestones') {milestoneListFilter=String(id);milestoneSelectedId=null;}
      if (view==='project-risks') riskProjectFilter=String(id);
      if (view==='project-decisions') decisionProjectFilter=String(id);
      if (view==='project-updates') projectUpdateListFilter=String(id);
      if (view==='tasks') state.taskTab='view';
      if (['tasks','board','milestones','project-risks','project-decisions','project-updates'].includes(view)) setView(view);
    };
    await loadProjectOverview();
  }
  async function loadProjectOverview() {
    const seq=++projectOverviewRequestSeq;
    const teamId=Number(state.currentTeamId),listId=Number(state.currentListId);
    const shell=document.getElementById('project-overview');
    if (!shell) return;
    shell.innerHTML='<p class="project-overview-loading">Loading project health…</p>';
    try {
      const qs=new URLSearchParams({team_id:String(teamId),list_id:String(listId)});
      const result=await api('project_overview.php?'+qs.toString());
      if (seq!==projectOverviewRequestSeq || state.currentView!=='project-overview' || Number(state.currentListId)!==listId || Number(state.currentTeamId)!==teamId || !shell.isConnected) return;
      const t=result.tasks.counts,m=result.milestones.counts,r=result.risks.counts;
      const total=Number(t.total||0),done=Number(t.completed||0);
      const health=Object.hasOwn(overviewHealthText,result.tasks.health)?result.tasks.health:'empty';
      const progress=Math.max(0,Math.min(100,Number(result.tasks.progress||0)));
      const dateText=date=>date?fmtDate(date):'Not scheduled';
      const taskList=result.tasks.upcoming.length?result.tasks.upcoming.map(task=>{
        const priority=['urgent','high','normal','low','none'].includes(task.priority)?task.priority:'none';
        return `<button type="button" class="overview-item overview-item-task" data-overview-task="${Number(task.id)}"><span class="overview-item-copy"><strong>${esc(task.title)}</strong><small>${esc(task.status?.replaceAll('_',' ')||'Open')} · ${esc(priority)} priority</small></span><span class="overview-item-date">${dateText(task.due_date)}</span></button>`;
      }).join(''):overviewEmpty('No open tasks with due dates.');
      const milestoneList=result.milestones.items.length?result.milestones.items.map(item=>
        `<div class="overview-item"><span class="overview-item-copy"><strong>${esc(item.title)}</strong><small>Milestone</small></span><span class="overview-item-date">${dateText(item.due_date)}</span></div>`).join(''):overviewEmpty('No active milestones.');
      const riskList=result.risks.items.length?result.risks.items.map(item=>{
        const severity=['critical','high','medium','low'].includes(item.severity)?item.severity:'medium';
        return `<div class="overview-item"><span class="overview-item-copy"><strong>${esc(item.title)}</strong><small>${esc(item.status)}</small></span><span class="overview-severity overview-severity-${severity}">${esc(severity)}</span></div>`;
      }).join(''):overviewEmpty('No active risks or issues.');
      const decisionList=result.decisions.items.length?result.decisions.items.map(item=>
        `<div class="overview-item"><span class="overview-item-copy"><strong>${esc(item.title)}</strong><small>Review date</small></span><span class="overview-item-date">${dateText(item.review_date)}</span></div>`).join(''):overviewEmpty('No decisions awaiting review.');
      const lastUpdate=result.latest_update;
      const updateHtml=lastUpdate?`<div class="overview-last-update"><span class="overview-update-state">${esc(lastUpdate.status?.replaceAll('_',' ')||'Update')}</span><p>${esc(lastUpdate.summary)}</p><small>${esc(lastUpdate.author_name)} · ${esc(lastUpdate.created_at)}</small></div>`:
        overviewEmpty('No project status updates yet.');
      shell.innerHTML=`<div class="overview-header"><div><div class="overview-eyebrow">PROJECT / OVERVIEW</div><h2>${esc(result.project.name)}</h2><p>Everything that matters for this project, without switching between reports.</p></div><span class="overview-health overview-health-${health}">${overviewHealthText[health]}</span></div>
      <div class="overview-progress"><div><strong>${progress}% complete</strong><span>${done} of ${total} tasks</span></div><div class="overview-progress-track" role="progressbar" aria-label="Project completion" aria-valuenow="${progress}" aria-valuemin="0" aria-valuemax="100"><span style="width:${progress}%"></span></div></div>
      <div class="overview-metrics">
        <div><strong>${Number(t.total)-done}</strong><span>Open tasks</span></div><div><strong>${Number(t.overdue)}</strong><span>Overdue tasks</span></div>
        <div><strong>${Number(m.total)}</strong><span>Milestones</span></div><div><strong>${Number(r.active)}</strong><span>Active risks</span></div>
        <div><strong>${Number(result.decisions.pending)}</strong><span>Pending decisions</span></div>
      </div>
      <div class="overview-sections">
        <section class="overview-panel overview-panel-wide"><div class="overview-panel-heading"><div><span class="overview-kicker">UP NEXT</span><h3>Upcoming work</h3></div><button type="button" class="overview-panel-link" data-overview-destination="tasks">Open tasks <span aria-hidden="true">→</span></button></div><div>${taskList}</div></section>
        <section class="overview-panel"><div class="overview-panel-heading"><div><span class="overview-kicker">DELIVERY</span><h3>Milestones</h3></div><button type="button" class="overview-panel-link" data-overview-destination="milestones">View all <span aria-hidden="true">→</span></button></div><div>${milestoneList}</div></section>
        <section class="overview-panel"><div class="overview-panel-heading"><div><span class="overview-kicker">WATCH ITEMS</span><h3>Risks &amp; issues</h3></div><button type="button" class="overview-panel-link" data-overview-destination="project-risks">View all <span aria-hidden="true">→</span></button></div><div>${riskList}</div></section>
        <section class="overview-panel"><div class="overview-panel-heading"><div><span class="overview-kicker">GOVERNANCE</span><h3>Decisions</h3></div><button type="button" class="overview-panel-link" data-overview-destination="project-decisions">View all <span aria-hidden="true">→</span></button></div><div>${decisionList}</div></section>
        <section class="overview-panel"><div class="overview-panel-heading"><div><span class="overview-kicker">TEAM UPDATES</span><h3>Latest status</h3></div><button type="button" class="overview-panel-link" data-overview-destination="project-updates">View updates <span aria-hidden="true">→</span></button></div>${updateHtml}</section>
      </div><p class="overview-footnote">Snapshot as of ${esc(new Date(result.as_of).toLocaleString())}. Milestone target-date counts do not imply unfinished work.</p>`;
    } catch (error) {
      if (seq!==projectOverviewRequestSeq || state.currentView!=='project-overview' || !shell.isConnected) return;
      shell.innerHTML=`<div class="load-error-box" role="alert"><h3>Could not load project overview</h3><p>${esc(error.message)}</p><button type="button" class="btn btn-ghost" id="overview-retry">Try again</button></div>`;
      shell.querySelector('#overview-retry')?.addEventListener('click',()=>loadProjectOverview());
    }
  }

  // ---------------------------------------------------------------
  // PROJECT PORTFOLIO: compact per-list health summary, lazy pages.
  // ---------------------------------------------------------------
  let portfolioCursor = null;
  let portfolioRequestSeq = 0;
  const portfolioHealthText = {
    empty:'No tasks yet', complete:'Complete', at_risk:'At risk', attention:'Needs attention', on_track:'On track',
  };

  async function renderPortfolio() {
    const container = document.getElementById('view-container');
    if (!state.currentTeamId) { container.innerHTML=emptyTeamState(); return; }
    const canExport=iHave(['administrator','manager']);
    document.getElementById('topbar-actions').innerHTML=canExport
      ? `<a class="btn btn-ghost" href="api/portfolio_export.php?team_id=${Number(state.currentTeamId)}" download>Export portfolio CSV</a>` : '';
    container.innerHTML=`<section class="portfolio-intro"><div class="milestone-eyebrow">PROJECT HEALTH</div>
      <h2>A clear picture of your team's delivery.</h2><p>Each card represents one task list. Track finished work, upcoming deadlines, and milestone risks without opening every task.</p></section>
      <div class="portfolio-summary" id="portfolio-summary" aria-live="polite"></div>
      <div class="portfolio-grid" id="portfolio-cards" aria-live="polite"><p>Loading your lists…</p></div>
      <div class="portfolio-more" id="portfolio-more"></div>
      <p class="portfolio-footnote">Progress counts all tasks in a list, including subtasks. Only active milestones affect health. Counts are current as of the last refresh.</p>`;
    // Replace the delegated handler on re-render so navigation never stacks listeners.
    container.onclick=event=>{
      const overview=event.target.closest('[data-portfolio-overview]');
      if(overview){const id=Number(overview.dataset.portfolioOverview);if(state.lists.some(l=>Number(l.id)===id)){state.currentListId=id;rememberProjectSelection();setView('project-overview');}return;}
      const list=event.target.closest('[data-portfolio-list]');
      if(list){
        const id=Number(list.dataset.portfolioList);
        if(!state.lists.some(l=>Number(l.id)===id))return;
        state.currentListId=id;rememberProjectSelection();state.taskTab='view';setView('tasks');
      }
      const updates=event.target.closest('[data-portfolio-updates]');
      if(updates){projectUpdateListFilter=String(Number(updates.dataset.portfolioUpdates));setView('project-updates');return;}
      const decisions=event.target.closest('[data-portfolio-decisions]');
      if(decisions){decisionProjectFilter=String(Number(decisions.dataset.portfolioDecisions));setView('project-decisions');return;}
      const risks=event.target.closest('[data-portfolio-risks]');
      if(risks){riskProjectFilter=String(Number(risks.dataset.portfolioRisks));setView('project-risks');return;}
      const milestone=event.target.closest('[data-portfolio-milestone]');
      if(milestone){
        milestoneListFilter=String(Number(milestone.dataset.portfolioMilestone));
        milestoneSelectedId=null;setView('milestones');
      }
    };
    portfolioCursor=null;
    await loadPortfolio(false);
  }
  async function loadPortfolio(append=false){
    const seq=++portfolioRequestSeq;
    const teamId=Number(state.currentTeamId);
    const cards=document.getElementById('portfolio-cards');
    if(!cards)return;
    const cursor=append?portfolioCursor:null;
    if(!append)cards.innerHTML='<p>Loading your lists…</p>';
    try {
      const qs=new URLSearchParams({team_id:String(teamId)});
      if(cursor)qs.set('after_id',String(cursor));
      const data=await api('portfolio.php?'+qs);
      if(seq!==portfolioRequestSeq || state.currentView!=='portfolio' || Number(state.currentTeamId)!==teamId)return;
      if(!append)cards.innerHTML='';
      if(!data.projects.length && !append){
        cards.innerHTML='<div class="empty-state"><h3>No task lists yet</h3><p>Start by creating a list and adding tasks.</p></div>';
      }
      for(const p of data.projects){
        const health=Object.prototype.hasOwnProperty.call(portfolioHealthText,p.health)?p.health:'empty';
        const card=document.createElement('article');
        card.className='portfolio-card';
        card.dataset.tasks=String(Number(p.total_tasks));
        card.dataset.done=String(Number(p.done_tasks));
        card.dataset.overdue=String(Number(p.overdue_tasks));
        card.innerHTML=`<div class="portfolio-card-head"><span class="portfolio-kicker">TASK LIST #${Number(p.id)}</span>
          <span class="portfolio-health portfolio-health-${health}">${portfolioHealthText[health]}</span></div>
          <h3>${esc(p.name)}</h3>
          <div class="portfolio-progress-line"><strong>${Number(p.progress_percent)}%</strong><span>${Number(p.done_tasks)} of ${Number(p.total_tasks)} tasks done</span></div>
          ${progressBarHtml(Number(p.progress_percent))}
          <div class="portfolio-metrics">
            <div><strong>${Number(p.overdue_tasks)}</strong><span>Overdue</span></div>
            <div><strong>${Number(p.due_soon_tasks)}</strong><span>Due in 7 days</span></div>
            <div><strong>${Number(p.unassigned_tasks)}</strong><span>Unassigned</span></div>
            <div><strong>${Number(p.open_risks||0)}</strong><span>Open risks</span></div>
          </div>
          <div class="portfolio-meta">${Number(p.critical_risks||0)?`<strong>${Number(p.critical_risks)} high/critical risk(s)</strong> · `:''}${Number(p.milestones_late) ? `<strong>${Number(p.milestones_late)} past-target milestones</strong> · ` : ''}
            ${hoursText(Number(p.remaining_estimate_minutes))} estimated remaining</div>
          <div class="portfolio-actions"><button type="button" class="btn btn-primary" data-portfolio-overview="${Number(p.id)}">Overview</button><button type="button" class="btn btn-ghost" data-portfolio-list="${Number(p.id)}">Open tasks</button>
            <button type="button" class="btn btn-ghost" data-portfolio-milestone="${Number(p.id)}">Milestones</button>
            <button type="button" class="btn btn-ghost" data-portfolio-updates="${Number(p.id)}">Updates</button>
            <button type="button" class="btn btn-ghost" data-portfolio-risks="${Number(p.id)}">Risks</button>
            <button type="button" class="btn btn-ghost" data-portfolio-decisions="${Number(p.id)}">Decisions</button></div>`;
        cards.appendChild(card);
      }
      portfolioCursor=data.next_after_id;
      const wrap=document.getElementById('portfolio-more');
      wrap.innerHTML=portfolioCursor ? '<button type="button" class="btn btn-ghost" id="portfolio-more-btn">Load more lists</button>' : '';
      wrap.querySelector('button')?.addEventListener('click',()=>loadPortfolio(true));
      const rendered=Array.from(cards.querySelectorAll('.portfolio-card'));
      const total=rendered.reduce((n,el)=>n+Number(el.dataset.tasks),0);
      const done=rendered.reduce((n,el)=>n+Number(el.dataset.done),0);
      const overdue=rendered.reduce((n,el)=>n+Number(el.dataset.overdue),0);
      document.getElementById('portfolio-summary').innerHTML=`<div><strong>${rendered.length}</strong><span>Lists loaded</span></div>
        <div><strong>${done}/${total}</strong><span>Tasks complete</span></div>
        <div><strong>${overdue}</strong><span>Overdue tasks</span></div>`;
    }catch(err){
      if(seq!==portfolioRequestSeq || state.currentView!=='portfolio')return;
      if(!append)cards.innerHTML=`<div class="load-error-box"><p>${esc(err.message)}</p><button type="button" class="btn btn-ghost" id="portfolio-retry">Retry</button></div>`;
      else toast(err.message,true);
      document.getElementById('portfolio-retry')?.addEventListener('click',()=>loadPortfolio(false));
    }
  }


  // ---------------------------------------------------------------
  // PROJECT RISKS: team-scoped risk/issue registry and manager triage.
  // ---------------------------------------------------------------
  let riskProjectFilter = '';
  let riskStatusFilter = '';
  let riskSeverityFilter = '';
  let riskArchived = false;
  let riskCursor = null;
  let riskLoadSeq = 0;
  const riskStatusLabel = {open:'Open',mitigating:'Mitigating',resolved:'Resolved',accepted:'Accepted'};
  const riskSeverityLabel = {low:'Low',medium:'Medium',high:'High',critical:'Critical'};
  const riskKindLabel = {risk:'Risk',issue:'Issue'};
  function riskIsManager() { return iHave(['administrator','manager']); }
  function riskFormHtml(prefix, item = null) {
    const editing = !!item;
    const manager = riskIsManager();
    const chosenList = Number(item?.list_id || riskProjectFilter || state.currentListId || state.lists[0]?.id || 0);
    return `<div class="risk-form-grid">
      <label>Project<select id="${prefix}-list" ${editing?'disabled':''} required>
        ${state.lists.map(l=>`<option value="${Number(l.id)}" ${Number(l.id)===chosenList?'selected':''}>${esc(l.name)}</option>`).join('')}
      </select></label>
      <label>Type<select id="${prefix}-kind">${Object.entries(riskKindLabel).map(([key,value])=>`<option value="${key}" ${key===(item?.kind||'risk')?'selected':''}>${value}</option>`).join('')}</select></label>
      <label>Severity<select id="${prefix}-severity">${Object.entries(riskSeverityLabel).map(([key,value])=>`<option value="${key}" ${key===(item?.severity||'medium')?'selected':''}>${value}</option>`).join('')}</select></label>
      <label>Target date <small>(optional)</small><input id="${prefix}-due" type="date" value="${esc(item?.due_date||'')}"></label>
      ${manager ? `<label>Status<select id="${prefix}-status">${Object.entries(riskStatusLabel).map(([key,value])=>`<option value="${key}" ${key===(item?.status||'open')?'selected':''}>${value}</option>`).join('')}</select></label>
      <label>Owner <small>(optional)</small><select id="${prefix}-owner"><option value="">Unassigned</option>${state.members.filter(m=>Number(m.team_id||state.currentTeamId)===Number(state.currentTeamId)).map(m=>`<option value="${Number(m.id)}" ${Number(m.id)===Number(item?.owner_id)?'selected':''}>${esc(m.name)}</option>`).join('')}</select></label>` : ''}
      <label class="risk-span">Title<input id="${prefix}-title" maxlength="160" required placeholder="What could affect delivery?" value="${esc(item?.title||'')}"></label>
      <label class="risk-span">Details<textarea id="${prefix}-description" maxlength="3000" rows="3" required placeholder="Describe the risk, impact, or issue">${esc(item?.description||'')}</textarea></label>
      <label class="risk-span">Mitigation plan <small>(optional)</small><textarea id="${prefix}-mitigation" maxlength="3000" rows="2" placeholder="Next steps to reduce the impact">${esc(item?.mitigation||'')}</textarea></label>
    </div>`;
  }
  function riskFormValues(prefix) {
    const val = key => document.getElementById(prefix+'-'+key)?.value || '';
    const payload = {list_id:Number(val('list')), kind:val('kind'), severity:val('severity'),
      title:val('title').trim(),description:val('description').trim(),mitigation:val('mitigation').trim(),due_date:val('due')||null};
    if (!payload.list_id || payload.title.length<3 || payload.description.length<5) throw new Error('Choose a project and describe the risk.');
    if (riskIsManager()) {payload.status=val('status');payload.owner_id=val('owner')?Number(val('owner')):null;}
    return payload;
  }
  async function renderProjectRisks() {
    const container=document.getElementById('view-container');
    if (!state.currentTeamId) {container.innerHTML=emptyTeamState();return;}
    const manager=riskIsManager();
    if(manager && (state.members.teamIdLoaded!==state.currentTeamId || !state.members.length)){
      try {const response=await api('users.php');state.members=response.users.filter(u=>Number(u.team_id)===Number(state.currentTeamId)&&Number(u.is_active ?? 1)===1);state.members.teamIdLoaded=state.currentTeamId;}
      catch(err){toast('Could not load potential owners: '+err.message,true);}
    }
    document.getElementById('topbar-actions').innerHTML=manager
      ? `<a class="btn btn-ghost" href="api/project_risks_export.php?team_id=${Number(state.currentTeamId)}" download>Export risks CSV</a>` : '';
    container.innerHTML=`<section class="portfolio-intro"><div class="milestone-eyebrow">PROJECT OVERSIGHT</div>
      <h2>Spot risks early. Make a plan.</h2><p>Record issues and delivery risks, assign mitigation owners, and keep a shared view of what needs attention. Reporting a risk never changes task ownership.</p></section>
      <div class="risk-summary" id="risk-summary" aria-live="polite"></div>
      <div class="risk-panel"><div class="risk-heading"><h3>Project risk register</h3><button class="btn btn-ghost" id="risk-toggle-create" type="button">+ Report risk or issue</button></div>
      <form class="risk-create-form hidden" id="risk-create-form">${riskFormHtml('risk-new')}
        <div class="risk-form-actions"><span>Team members can report; managers manage status and owners.</span><button type="submit" class="btn btn-primary">Submit risk</button></div></form>
      <div class="risk-filters">
        <label>Project<select id="risk-filter-project"><option value="">All projects</option>${state.lists.map(l=>`<option value="${Number(l.id)}" ${String(l.id)===riskProjectFilter?'selected':''}>${esc(l.name)}</option>`).join('')}</select></label>
        <label>Status<select id="risk-filter-status"><option value="">All statuses</option>${Object.entries(riskStatusLabel).map(([key,value])=>`<option value="${key}" ${key===riskStatusFilter?'selected':''}>${value}</option>`).join('')}</select></label>
        <label>Severity<select id="risk-filter-severity"><option value="">All severities</option>${Object.entries(riskSeverityLabel).map(([key,value])=>`<option value="${key}" ${key===riskSeverityFilter?'selected':''}>${value}</option>`).join('')}</select></label>
        ${manager ? '<label class="risk-check"><input type="checkbox" id="risk-show-archived" '+(riskArchived?'checked':'')+'> Archived</label>' : ''}
        <button class="btn btn-ghost" type="button" id="risk-refresh">Refresh</button></div>
      <div id="risk-feed" class="risk-feed" aria-live="polite">Loading project risks…</div>
      <div id="risk-more" class="portfolio-more"></div></div>`;
    document.getElementById('risk-toggle-create').addEventListener('click',()=>document.getElementById('risk-create-form').classList.toggle('hidden'));
    document.getElementById('risk-create-form').addEventListener('submit',async e=>{
      e.preventDefault();const button=e.target.querySelector('button[type=submit]');button.disabled=true;
      try {await api('project_risks.php',{method:'POST',body:{team_id:Number(state.currentTeamId),action:'create',...riskFormValues('risk-new')}});
        toast('Risk reported'); await renderProjectRisks();
      } catch(err){toast(err.message,true);} finally {button.disabled=false;}
    });
    for (const [name, key] of [['project','riskProjectFilter'],['status','riskStatusFilter'],['severity','riskSeverityFilter']]) {
      document.getElementById('risk-filter-'+name).addEventListener('change',e=>{
        if(key==='riskProjectFilter')riskProjectFilter=e.target.value;
        if(key==='riskStatusFilter')riskStatusFilter=e.target.value;
        if(key==='riskSeverityFilter')riskSeverityFilter=e.target.value;
        loadProjectRisks(false);
      });
    }
    document.getElementById('risk-show-archived')?.addEventListener('change',e=>{riskArchived=e.target.checked;loadProjectRisks(false);});
    document.getElementById('risk-refresh').addEventListener('click',()=>loadProjectRisks(false));
    container.onclick=async e=>{
      const edit=e.target.closest('[data-risk-edit]');
      if(edit){const id=Number(edit.dataset.riskEdit);const item=riskVisibleEntries.find(r=>Number(r.id)===id);if(item)editProjectRisk(item);return;}
      const action=e.target.closest('[data-risk-action]');
      if(action){const id=Number(action.dataset.riskId);const item=riskVisibleEntries.find(r=>Number(r.id)===id);
        if(!item)return;
        const operation=action.dataset.riskAction;
        if(!confirm(operation==='archive'?'Archive this risk?':'Restore this risk?'))return;
        try{await api('project_risks.php',{method:'POST',body:{team_id:Number(state.currentTeamId),action:operation,id,version:item.version}});
          toast('Entry updated');loadProjectRisks(false);}catch(err){toast(err.message,true);}return;
      }
    };
    await loadProjectRisks(false);
  }
  let riskVisibleEntries=[];
  async function loadProjectRisks(append=false) {
    const teamId=Number(state.currentTeamId), seq=++riskLoadSeq;
    const feed=document.getElementById('risk-feed'),more=document.getElementById('risk-more');
    if(!feed||!more)return;
    if(!append){riskCursor=null;riskVisibleEntries=[];feed.textContent='Loading…';}
    const query=new URLSearchParams({team_id:String(teamId)});
    if(riskProjectFilter)query.set('list_id',riskProjectFilter);
    if(riskStatusFilter)query.set('status',riskStatusFilter);
    if(riskSeverityFilter)query.set('severity',riskSeverityFilter);
    if(riskArchived)query.set('archived','1');
    if(append&&riskCursor)query.set('before_id',String(riskCursor));
    try {
      const response=await api('project_risks.php?'+query.toString());
      if(seq!==riskLoadSeq||state.currentView!=='project-risks'||teamId!==Number(state.currentTeamId))return;
      if(!append)feed.innerHTML='';
      riskVisibleEntries.push(...response.risks);
      const sum=response.summary;
      document.getElementById('risk-summary').innerHTML=`<div><strong>${Number(sum.active_count)}</strong><span>Active entries</span></div>
        <div><strong>${Number(sum.high_count)}</strong><span>High / critical</span></div>
        <div><strong>${Number(sum.overdue_count)}</strong><span>Past target</span></div>`;
      if(!response.risks.length&&!append)feed.innerHTML='<div class="empty-state"><h3>No matching entries</h3><p>Risks and issues reported for this team will appear here.</p></div>';
      for(const risk of response.risks) {
        const severity=Object.hasOwn(riskSeverityLabel,risk.severity)?risk.severity:'medium';
        const status=Object.hasOwn(riskStatusLabel,risk.status)?risk.status:'open';
        const kind=Object.hasOwn(riskKindLabel,risk.kind)?risk.kind:'risk';
        const card=document.createElement('article');card.className='risk-card';
        card.innerHTML=`<div class="risk-card-head"><div class="risk-tags"><span class="risk-kind">${riskKindLabel[kind]}</span>
          <span class="risk-severity risk-severity-${severity}">${riskSeverityLabel[severity]}</span>
          <span class="risk-status">${riskStatusLabel[status]}</span></div>
          <span class="risk-project">${esc(risk.project_name)}</span></div>
          <h4>${esc(risk.title)}</h4><p class="risk-description">${esc(risk.description)}</p>
          ${risk.mitigation?`<div class="risk-mitigation"><strong>Mitigation</strong><p>${esc(risk.mitigation)}</p></div>`:''}
          <div class="risk-meta"><span>Owner: ${esc(risk.owner_name||'Not assigned')}</span><span>Target: ${risk.due_date?fmtDate(risk.due_date):'Not set'}</span>
          <span>Reported by ${esc(risk.reported_name)}</span></div>
          ${riskIsManager()?`<div class="risk-actions">${riskArchived?'':`<button type="button" class="btn btn-ghost" data-risk-edit="${Number(risk.id)}">Edit / triage</button>`}
            <button type="button" class="btn btn-ghost" data-risk-action="${riskArchived?'restore':'archive'}" data-risk-id="${Number(risk.id)}">${riskArchived?'Restore':'Archive'}</button></div>`:''}`;
        feed.appendChild(card);
      }
      riskCursor=response.next_before_id;
      more.innerHTML=riskCursor?'<button type="button" class="btn btn-ghost">Load older entries</button>':'';
      more.querySelector('button')?.addEventListener('click',()=>loadProjectRisks(true));
    } catch(err){if(seq!==riskLoadSeq||state.currentView!=='project-risks')return;
      if(!append)feed.innerHTML=`<div class="load-error-box">${esc(err.message)}</div>`;else toast(err.message,true);}
  }
  function editProjectRisk(item) {
    if(!riskIsManager())return;
    promptModal('Edit project risk',riskFormHtml('risk-edit',item),async()=>{
      await api('project_risks.php',{method:'POST',body:{team_id:Number(state.currentTeamId),action:'update',id:Number(item.id),version:Number(item.version),...riskFormValues('risk-edit')}});
      toast('Risk updated');await loadProjectRisks(false);
    });
  }

  // ---------------------------------------------------------------
  // PROJECT DECISIONS: proposer history with manager review and owners.
  // ---------------------------------------------------------------
  let decisionProjectFilter = '';
  let decisionStatusFilter = '';
  let decisionArchived = false;
  let decisionCursor = null;
  let decisionRequestSeq = 0;
  const decisionStatuses = {proposed:'Proposed',approved:'Approved',rejected:'Rejected',deferred:'Deferred'};
  function decisionCanManage() { return iHave(['administrator','manager']); }
  function decisionFormHtml(prefix, item = null) {
    const manager=decisionCanManage();
    const chosen=Number(item?.list_id || decisionProjectFilter || state.currentListId || state.lists[0]?.id || 0);
    const status=item?.status||'proposed';
    return `<div class="decision-form-grid">
      <label>Project<select id="${prefix}-list" required ${item?'disabled':''}>${state.lists.map(l=>`<option value="${Number(l.id)}" ${Number(l.id)===chosen?'selected':''}>${esc(l.name)}</option>`).join('')}</select></label>
      <label>Review date <small>(optional)</small><input type="date" id="${prefix}-review" value="${esc(item?.review_date||'')}"></label>
      ${manager?`<label>Status<select id="${prefix}-status">${Object.entries(decisionStatuses).map(([key,value])=>`<option value="${key}" ${key===status?'selected':''}>${value}</option>`).join('')}</select></label>
      <label>Owner <small>(optional)</small><select id="${prefix}-owner"><option value="">Not assigned</option>${state.members.filter(m=>Number(m.team_id||state.currentTeamId)===Number(state.currentTeamId)&&Number(m.is_active??1)===1).map(m=>`<option value="${Number(m.id)}" ${Number(item?.owner_id)===Number(m.id)?'selected':''}>${esc(m.name)}</option>`).join('')}</select></label>`:''}
      <label class="decision-span">Decision title<input id="${prefix}-title" minlength="3" maxlength="160" required placeholder="What needs to be decided?" value="${esc(item?.title||'')}"></label>
      <label class="decision-span">Background and options<textarea id="${prefix}-context" rows="3" minlength="5" maxlength="4000" required placeholder="Summarize the decision and why it matters">${esc(item?.context||'')}</textarea></label>
      <label class="decision-span">Outcome <small>(${manager?'required to approve':'optional'})</small><textarea id="${prefix}-outcome" rows="2" maxlength="4000" placeholder="Record the chosen approach and next steps">${esc(item?.outcome||'')}</textarea></label>
    </div>`;
  }
  function decisionFormValues(prefix) {
    const val=key=>document.getElementById(prefix+'-'+key)?.value||'';
    const result={list_id:Number(val('list')), title:val('title').trim(),context:val('context').trim(),
      outcome:val('outcome').trim(),review_date:val('review')||null};
    if (!result.list_id||result.title.length<3||result.context.length<5)throw new Error('Choose a project and describe the decision.');
    if(decisionCanManage()) {
      result.status=val('status')||'proposed';
      result.owner_id=val('owner')?Number(val('owner')):null;
      if(result.status==='approved'&&!result.outcome)throw new Error('Add an outcome before approving the decision.');
    }
    return result;
  }
  async function renderProjectDecisions() {
    const container=document.getElementById('view-container');
    if(!state.currentTeamId){container.innerHTML=emptyTeamState();return;}
    const manager=decisionCanManage();
    if(manager&&(state.members.teamIdLoaded!==state.currentTeamId||!state.members.length)) {
      try {const r=await api('users.php');state.members=r.users.filter(u=>Number(u.team_id)===Number(state.currentTeamId)&&Number(u.is_active??1)===1);state.members.teamIdLoaded=state.currentTeamId;}
      catch(err){toast('Could not load decision owners: '+err.message,true);}
    }
    document.getElementById('topbar-actions').innerHTML=manager ? `<a class="btn btn-ghost" href="api/project_decisions_export.php?team_id=${Number(state.currentTeamId)}" download>Export decisions CSV</a>` : '';
    container.innerHTML=`<section class="portfolio-intro"><div class="milestone-eyebrow">PROJECT GOVERNANCE</div>
       <h2>Decisions everyone can find.</h2><p>Propose and review important project choices in one shared history. Managers record outcomes; an approval does not automatically alter tasks or other records.</p></section>
      <div class="decision-summary" id="decision-summary" aria-live="polite"></div>
      <section class="decision-panel"><div class="decision-section-heading"><h3>Decision register</h3>
        <button type="button" class="btn btn-primary" id="decision-toggle-create">+ Propose decision</button></div>
      <form id="decision-create-form" class="decision-create-form hidden">${decisionFormHtml('decision-new')}
        <div class="decision-form-actions"><span>Everyone can propose; managers review outcomes.</span><button type="submit" class="btn btn-primary">Save proposal</button></div></form>
      <div class="decision-filters">
        <label>Project<select id="decision-filter-project"><option value="">All projects</option>${state.lists.map(l=>`<option value="${Number(l.id)}">${esc(l.name)}</option>`).join('')}</select></label>
        <label>Status<select id="decision-filter-status"><option value="">All statuses</option>${Object.entries(decisionStatuses).map(([key,label])=>`<option value="${key}">${label}</option>`).join('')}</select></label>
        ${manager?'<label class="decision-archived"><input type="checkbox" id="decision-filter-archived"> Archived</label>':''}
        <button type="button" class="btn btn-ghost" id="decision-refresh">Refresh</button>
      </div><div class="decision-feed" id="decision-feed" aria-live="polite">Loading decisions…</div>
      <div class="portfolio-more" id="decision-more"></div></section>`;
    container.querySelector('#decision-filter-project').value=decisionProjectFilter;
    container.querySelector('#decision-filter-status').value=decisionStatusFilter;
    if(manager)container.querySelector('#decision-filter-archived').checked=decisionArchived;
    container.querySelector('#decision-toggle-create').addEventListener('click',()=>container.querySelector('#decision-create-form').classList.toggle('hidden'));
    container.querySelector('#decision-refresh').addEventListener('click',()=>loadProjectDecisions(false));
    container.querySelector('#decision-filter-project').addEventListener('change',e=>{decisionProjectFilter=e.target.value;loadProjectDecisions(false);});
    container.querySelector('#decision-filter-status').addEventListener('change',e=>{decisionStatusFilter=e.target.value;loadProjectDecisions(false);});
    container.querySelector('#decision-filter-archived')?.addEventListener('change',e=>{decisionArchived=e.target.checked;loadProjectDecisions(false);});
    container.querySelector('#decision-create-form').addEventListener('submit',async e=>{
      e.preventDefault();const submit=e.currentTarget.querySelector('button[type="submit"]');submit.disabled=true;
      try {
        await api('project_decisions.php',{method:'POST',body:{team_id:Number(state.currentTeamId),action:'create',...decisionFormValues('decision-new')}});
        e.currentTarget.reset();e.currentTarget.classList.add('hidden');toast('Decision proposal saved');
        await loadProjectDecisions(false);
      }catch(err){toast(err.message,true);}finally{submit.disabled=false;}
    });
    container.querySelector('#decision-feed').addEventListener('click',async e=>{
      const edit=e.target.closest('[data-decision-edit]');
      if(edit){const item=decisionItems.find(r=>r.id===Number(edit.dataset.decisionEdit));if(item)editProjectDecision(item);return;}
      const action=e.target.closest('[data-decision-action]');if(!action)return;
      if(!decisionCanManage())return;
      const item=decisionItems.find(r=>r.id===Number(action.dataset.decisionId));if(!item)return;
      const operation=action.dataset.decisionAction;
      if(!window.confirm(`${operation==='archive'?'Archive':'Restore'} this decision?`))return;
      action.disabled=true;
      try{
        await api('project_decisions.php',{method:'POST',body:{team_id:Number(state.currentTeamId),id:item.id,version:item.version,action:operation}});
        toast('Decision '+(operation==='archive'?'archived':'restored'));await loadProjectDecisions(false);
      }catch(err){toast(err.message,true);action.disabled=false;}
    });
    await loadProjectDecisions(false);
  }
  let decisionItems=[];
  async function loadProjectDecisions(append=false) {
    const feed=document.getElementById('decision-feed'),more=document.getElementById('decision-more');
    if(!feed||!more)return;
    const teamId=Number(state.currentTeamId),project=decisionProjectFilter,status=decisionStatusFilter,archived=decisionArchived;
    const seq=++decisionRequestSeq;
    if(!append){decisionCursor=null;decisionItems=[];feed.innerHTML='<p>Loading decisions…</p>';}
    try{
      const params=new URLSearchParams({team_id:String(teamId)});
      if(project)params.set('list_id',project);
      if(status)params.set('status',status);
      if(archived)params.set('archived','1');
      if(append&&decisionCursor)params.set('before_id',String(decisionCursor));
      const response=await api('project_decisions.php?'+params.toString());
      if(seq!==decisionRequestSeq||state.currentView!=='project-decisions'||teamId!==Number(state.currentTeamId)||project!==decisionProjectFilter||status!==decisionStatusFilter||archived!==decisionArchived)return;
      if(!append)feed.innerHTML='';
      decisionItems.push(...response.decisions);
      const summary=document.getElementById('decision-summary');
      summary.innerHTML=`<div><strong>${Number(response.summary.total)}</strong><span>Current decisions</span></div>
        <div><strong>${Number(response.summary.pending)}</strong><span>Awaiting review</span></div>
        <div><strong>${Number(response.summary.approved)}</strong><span>Approved</span></div>
        <div><strong>${Number(response.summary.past_review)}</strong><span>Past review date</span></div>`;
      for(const item of response.decisions){
        const statusText=Object.hasOwn(decisionStatuses,item.status)?item.status:'proposed';
        const card=document.createElement('article');card.className='decision-card';
        card.innerHTML=`<div class="decision-card-head"><span class="decision-status decision-status-${statusText}">${decisionStatuses[statusText]}</span>
           <span class="decision-project">${esc(item.project_name)}</span></div>
           <h4>${esc(item.title)}</h4><p class="decision-context">${esc(item.context)}</p>
           ${item.outcome?`<div class="decision-outcome"><strong>Outcome / next steps</strong><p>${esc(item.outcome)}</p></div>`:''}
           <div class="decision-meta"><span>Proposed by ${esc(item.reporter_name)}</span><span>Owner: ${esc(item.owner_name||'Unassigned')}</span>
             <span>Review: ${item.review_date?fmtDate(item.review_date):'Not scheduled'}</span><span>Created ${esc(item.created_at)}</span></div>
           ${response.can_manage?`<div class="decision-actions">${archived?'':`<button type="button" class="btn btn-ghost" data-decision-edit="${Number(item.id)}">Review / edit</button>`}
             <button type="button" class="btn btn-ghost" data-decision-action="${archived?'restore':'archive'}" data-decision-id="${Number(item.id)}">${archived?'Restore':'Archive'}</button></div>`:''}`;
        feed.appendChild(card);
      }
      if(!decisionItems.length)feed.innerHTML='<div class="empty-state"><h3>No decisions yet</h3><p>Propose a project decision and keep a clear record of the outcome.</p></div>';
      decisionCursor=response.next_before_id;
      more.innerHTML=decisionCursor?'<button type="button" class="btn btn-ghost">Load older decisions</button>':'';
      more.querySelector('button')?.addEventListener('click',()=>loadProjectDecisions(true));
    }catch(err){
      if(seq!==decisionRequestSeq||state.currentView!=='project-decisions')return;
      if(!append)feed.innerHTML=`<div class="load-error-box">${esc(err.message)}</div>`;else toast(err.message,true);
    }
  }
  function editProjectDecision(item) {
    if(!decisionCanManage())return;
    promptModal('Review project decision',decisionFormHtml('decision-edit',item),async()=>{
      await api('project_decisions.php',{method:'POST',body:{team_id:Number(state.currentTeamId),
        id:Number(item.id),version:Number(item.version),action:'update',...decisionFormValues('decision-edit')}});
      toast('Decision updated');await loadProjectDecisions(false);
    });
  }

  // ---------------------------------------------------------------
  // PROJECT UPDATES: append-only status reporting per task list.
  // ---------------------------------------------------------------
  let projectUpdateListFilter = '';
  let projectUpdateBefore = null;
  let projectUpdateSeq = 0;
  const projectUpdateStatus = {
    on_track:'On track', at_risk:'At risk', blocked:'Blocked', complete:'Complete',
  };
  async function renderProjectUpdates() {
    const container=document.getElementById('view-container');
    if(!state.currentTeamId){container.innerHTML=emptyTeamState();return;}
    const manager=iHave(['administrator','manager']);
    const options=state.lists.map(l=>`<option value="${Number(l.id)}">${esc(l.name)}</option>`).join('');
    const selectOptions=`<option value="">All task lists</option>${options}`;
    container.innerHTML=`<section class="portfolio-intro"><div class="milestone-eyebrow">DELIVERY NOTES</div>
      <h2>Progress that tells the full story.</h2><p>Post a short status report for each project. Updates are dated and preserved in order so teams can follow decisions and changes.</p></section>
      ${manager&&state.lists.length?`<form id="project-update-form" class="project-update-form">
        <h3>Publish a project update</h3>
        <div class="project-update-form-grid"><label>Project<select id="pu-list" required>${options}</select></label>
          <label>Overall status<select id="pu-status"><option value="on_track">On track</option><option value="at_risk">At risk</option><option value="blocked">Blocked</option><option value="complete">Complete</option></select></label></div>
        <label>Progress summary<textarea id="pu-summary" minlength="10" maxlength="2000" required rows="3" placeholder="What changed, what was delivered, and what needs attention?"></textarea></label>
        <label>Next steps (optional)<textarea id="pu-next" maxlength="1000" rows="2" placeholder="Upcoming actions, owners, or decisions"></textarea></label>
        <div class="project-update-form-actions"><span>Published updates are kept as an append-only history.</span><button class="btn btn-primary" type="submit">Publish update</button></div>
      </form>`:''}
      <div class="project-update-toolbar"><label for="pu-filter">Update history</label>
        <select id="pu-filter">${selectOptions}</select><button type="button" class="btn btn-ghost" id="pu-refresh">Refresh</button></div>
      <div id="pu-feed" class="project-update-feed" aria-live="polite">Loading updates…</div>
      <div id="pu-more" class="portfolio-more"></div>`;
    if(projectUpdateListFilter && !state.lists.some(l=>String(l.id)===projectUpdateListFilter))projectUpdateListFilter='';
    const filter=container.querySelector('#pu-filter');filter.value=projectUpdateListFilter;
    if(container.querySelector('#pu-list') && projectUpdateListFilter)container.querySelector('#pu-list').value=projectUpdateListFilter;
    filter.addEventListener('change',()=>{projectUpdateListFilter=filter.value;loadProjectUpdates();});
    container.querySelector('#pu-refresh').addEventListener('click',()=>loadProjectUpdates());
    container.querySelector('#project-update-form')?.addEventListener('submit',async e=>{
      e.preventDefault();
      const button=e.currentTarget.querySelector('button[type="submit"]');
      button.disabled=true;
      try{
        const listId=Number(container.querySelector('#pu-list').value);
        const status=container.querySelector('#pu-status').value;
        const summary=container.querySelector('#pu-summary').value.trim();
        const next_steps=container.querySelector('#pu-next').value.trim();
        await api('project_updates.php',{method:'POST',body:{team_id:Number(state.currentTeamId),list_id:listId,status,summary,next_steps}});
        container.querySelector('#pu-summary').value='';container.querySelector('#pu-next').value='';
        projectUpdateListFilter=String(listId);filter.value=projectUpdateListFilter;
        toast('Project update published');await loadProjectUpdates();
      }catch(err){toast(err.message,true);}finally{button.disabled=false;}
    });
    projectUpdateBefore=null;
    await loadProjectUpdates();
  }
  async function loadProjectUpdates(append=false){
    const feed=document.getElementById('pu-feed');if(!feed)return;
    const seq=++projectUpdateSeq,teamId=Number(state.currentTeamId),listId=projectUpdateListFilter;
    if(!append){feed.innerHTML='<p>Loading updates…</p>';projectUpdateBefore=null;}
    try{
      const query=new URLSearchParams({team_id:String(teamId)});
      if(listId)query.set('list_id',listId);
      if(append&&projectUpdateBefore)query.set('before_id',String(projectUpdateBefore));
      const data=await api('project_updates.php?'+query.toString());
      if(seq!==projectUpdateSeq||state.currentView!=='project-updates'||Number(state.currentTeamId)!==teamId||projectUpdateListFilter!==listId)return;
      if(!append)feed.innerHTML='';
      for(const item of data.updates){
        const status=Object.hasOwn(projectUpdateStatus,item.status)?item.status:'on_track';
        const card=document.createElement('article');card.className='project-update-card';
        card.innerHTML=`<div class="project-update-head"><span class="project-update-chip project-update-${status}">${projectUpdateStatus[status]}</span>
          <span class="project-update-date">${esc(item.created_at)}</span></div>
          <h3>${esc(item.list_name)}</h3><div class="project-update-meta">${esc(item.author_name)}</div>
          <p class="project-update-text">${esc(item.summary)}</p>
          ${item.next_steps?`<div class="project-update-next"><strong>Next steps</strong><p>${esc(item.next_steps)}</p></div>`:''}`;
        feed.appendChild(card);
      }
      if(!feed.children.length)feed.innerHTML='<div class="empty-state"><h3>No updates yet</h3><p>Managers can publish the first project status report above.</p></div>';
      projectUpdateBefore=data.next_before_id;
      const more=document.getElementById('pu-more');
      more.innerHTML=projectUpdateBefore?'<button type="button" class="btn btn-ghost">Load older updates</button>':'';
      more.querySelector('button')?.addEventListener('click',()=>loadProjectUpdates(true));
    }catch(err){
      if(seq!==projectUpdateSeq||state.currentView!=='project-updates')return;
      if(!append)feed.innerHTML=`<p class="load-error-box">${esc(err.message)}</p>`;
      else toast(err.message,true);
    }
  }

  // ---------------------------------------------------------------
  // ANNOUNCEMENTS: team feed with optional, auditable acknowledgements.
  // ---------------------------------------------------------------
  let announcementCursor = null;
  let announcementShowArchive = false;
  let announcementRequestSeq = 0;
  async function renderAnnouncements() {
    const container = document.getElementById('view-container');
    if (!state.currentTeamId) { container.innerHTML = emptyTeamState(); return; }
    const manager = iHave(['administrator','manager']);
    container.innerHTML = `<section class="portfolio-intro"><div class="milestone-eyebrow">TEAM COMMUNICATIONS</div>
      <h2>Keep everyone in the loop.</h2><p>Find important team notices in one place. Acknowledgements confirm that someone saw a notice; they do not signify approval of its contents.</p></section>
      ${manager ? `<form id="announcement-form" class="announcement-form">
       <h3>Publish a team announcement</h3>
       <label for="an-title">Title</label><input id="an-title" required minlength="3" maxlength="150" placeholder="e.g. December planning kickoff">
       <label for="an-message">Message</label><textarea id="an-message" rows="4" required minlength="5" maxlength="4000" placeholder="What does the team need to know?"></textarea>
       <div class="announcement-options"><label><input type="checkbox" id="an-pinned"> Pin this announcement</label>
         <label><input type="checkbox" id="an-ack"> Ask colleagues to acknowledge</label>
         <label>Expires (optional)<input id="an-expiry" type="date"></label></div>
       <div class="announcement-actions"><span>Managers can archive notices after publishing.</span><button type="submit" class="btn btn-primary">Publish announcement</button></div>
      </form>` : ''}
      <div class="project-update-toolbar"><strong>Team announcements</strong>
        ${manager ? '<label class="announcement-archive-label"><input type="checkbox" id="an-archived"> Show archived / expired</label>' : ''}
        <button type="button" class="btn btn-ghost" id="an-refresh">Refresh</button></div>
      <div id="an-feed" class="announcement-feed" aria-live="polite">Loading announcements…</div>
      <div id="an-more" class="portfolio-more"></div>`;
    container.querySelector('#an-refresh')?.addEventListener('click',()=>loadAnnouncements(false));
    const archiveInput=container.querySelector('#an-archived');
    if(archiveInput){archiveInput.checked=announcementShowArchive;archiveInput.addEventListener('change',()=>{
      announcementShowArchive=archiveInput.checked;loadAnnouncements(false);
    });}
    container.querySelector('#announcement-form')?.addEventListener('submit',async event=>{
      event.preventDefault();const button=event.currentTarget.querySelector('button[type="submit"]');button.disabled=true;
      try{
        const payload={action:'create',team_id:Number(state.currentTeamId),title:container.querySelector('#an-title').value.trim(),
          message:container.querySelector('#an-message').value.trim(),requires_ack:container.querySelector('#an-ack').checked,
          is_pinned:container.querySelector('#an-pinned').checked,expires_on:container.querySelector('#an-expiry').value||null};
        await api('announcements.php',{method:'POST',body:payload});
        event.currentTarget.reset();announcementShowArchive=false;
        if(archiveInput)archiveInput.checked=false;
        toast('Announcement published');await loadAnnouncements(false);
      }catch(error){toast(error.message,true);}finally{button.disabled=false;}
    });
    container.querySelector('#an-feed').addEventListener('click',async event=>{
      const button=event.target.closest('button[data-announcement-action]');if(!button||button.disabled)return;
      const id=Number(button.dataset.announcementId),action=button.dataset.announcementAction;
      if(!Number.isSafeInteger(id)||id<=0)return;
      if(action==='roster'){
        await showAnnouncementRoster(id,button.closest('.announcement-card'));return;
      }
      if(action==='archive' && !window.confirm('Archive this announcement?'))return;
      button.disabled=true;
      try{
        await api('announcements.php',{method:'POST',body:{action,id,team_id:Number(state.currentTeamId)}});
        toast(action==='ack'?'Acknowledgement saved':action==='archive'?'Announcement archived':'Marked as read');
        await loadAnnouncements(false);
      }catch(error){toast(error.message,true);button.disabled=false;}
    });
    await loadAnnouncements(false);
  }

  async function loadAnnouncements(append=false) {
    const feed=document.getElementById('an-feed'),more=document.getElementById('an-more');
    if(!feed||!more)return;
    const teamId=Number(state.currentTeamId),archived=announcementShowArchive,seq=++announcementRequestSeq;
    if(!append){announcementCursor=null;feed.innerHTML='<p>Loading announcements…</p>';}
    try{
      const query=new URLSearchParams({team_id:String(teamId)});
      if(archived)query.set('archived','1');
      if(append&&announcementCursor)query.set('cursor',announcementCursor);
      const result=await api('announcements.php?'+query.toString());
      if(seq!==announcementRequestSeq||state.currentView!=='announcements'||Number(state.currentTeamId)!==teamId||archived!==announcementShowArchive)return;
      if(!append)feed.innerHTML='';
      for(const notice of result.announcements){
        const card=document.createElement('article');card.className='announcement-card'+(notice.is_read?'':' announcement-unread');
        card.dataset.announcementId=String(notice.id);
        const meta=[];
        if(notice.is_pinned)meta.push('<span class="announcement-flag">Pinned</span>');
        if(notice.requires_ack)meta.push('<span class="announcement-flag">Acknowledgement requested</span>');
        if(!notice.is_read&&!archived)meta.push('<span class="announcement-flag announcement-new">Unread</span>');
        card.innerHTML=`<div class="announcement-meta">${meta.join('')}<time>${esc(notice.created_at)}</time></div>
          <h3>${esc(notice.title)}</h3><p class="announcement-author">Posted by ${esc(notice.author_name)}</p>
          <div class="announcement-body">${esc(notice.message)}</div>
          ${notice.expires_on?`<p class="announcement-expiry">Visible until ${esc(notice.expires_on)}</p>`:''}
          ${result.can_manage?`<p class="announcement-stats">Read by ${Number(notice.read_count||0)} of ${Number(notice.active_members||0)} active members${notice.requires_ack?` · Acknowledged by ${Number(notice.ack_count||0)}`:''}</p>`:''}
          <div class="announcement-card-actions">
            ${!notice.is_read&&!archived?`<button type="button" class="btn btn-ghost" data-announcement-action="read" data-announcement-id="${Number(notice.id)}">Mark as read</button>`:''}
            ${notice.requires_ack&&!notice.is_acknowledged&&!archived?`<button type="button" class="btn btn-primary" data-announcement-action="ack" data-announcement-id="${Number(notice.id)}">Acknowledge</button>`:''}
            ${notice.is_acknowledged?'<span class="announcement-acknowledged">✓ Acknowledged</span>':''}
            ${result.can_manage?`<button type="button" class="btn btn-ghost" data-announcement-action="roster" data-announcement-id="${Number(notice.id)}">View read receipts</button>`:''}
            ${result.can_manage&&!archived?`<button type="button" class="btn btn-ghost" data-announcement-action="archive" data-announcement-id="${Number(notice.id)}">Archive</button>`:''}
          </div><div class="announcement-receipts hidden"></div>`;
        feed.appendChild(card);
      }
      if(!feed.children.length)feed.innerHTML='<div class="empty-state"><h3>No announcements yet</h3><p>Managers can publish the first team notice above.</p></div>';
      announcementCursor=result.next_cursor;
      more.innerHTML=announcementCursor?'<button type="button" class="btn btn-ghost">Load older announcements</button>':'';
      more.querySelector('button')?.addEventListener('click',()=>loadAnnouncements(true));
    }catch(error){
      if(seq!==announcementRequestSeq||state.currentView!=='announcements')return;
      if(!append)feed.innerHTML=`<p class="load-error-box">${esc(error.message)}</p>`;
      else toast(error.message,true);
    }
  }
  async function showAnnouncementRoster(announcementId,card){
    if(!card)return;
    const target=card.querySelector('.announcement-receipts');
    if(!target)return;
    if(!target.classList.contains('hidden')){target.classList.add('hidden');return;}
    target.classList.remove('hidden');target.textContent='Loading member receipts…';
    const teamId=Number(state.currentTeamId);
    let cursor=null;
    async function loadRoster(append){
      try{
        const query=new URLSearchParams({action:'roster',team_id:String(teamId),id:String(announcementId)});
        if(append&&cursor)query.set('after_user_id',String(cursor));
        const result=await api('announcements.php?'+query.toString());
        if(state.currentView!=='announcements'||teamId!==Number(state.currentTeamId)||!target.isConnected)return;
        if(!append)target.innerHTML='<h4>Member receipts</h4><div class="announcement-receipt-rows"></div><div class="announcement-receipt-more"></div>';
        const list=target.querySelector('.announcement-receipt-rows');
        for(const row of result.members){
          const entry=document.createElement('div');entry.className='announcement-receipt-row';
          entry.innerHTML=`<span>${esc(row.name)}</span><span>${row.acknowledged_at?'Acknowledged':row.read_at?'Read':'Not read'}</span>`;
          list.appendChild(entry);
        }
        cursor=result.next_after_user_id;
        const more=target.querySelector('.announcement-receipt-more');
        more.innerHTML=cursor?'<button type="button" class="btn btn-ghost">Load more members</button>':'';
        more.querySelector('button')?.addEventListener('click',()=>loadRoster(true));
      }catch(error){target.textContent=error.message;}
    }
    await loadRoster(false);
  }

  // ---------------------------------------------------------------
  // MILESTONES: bounded list milestones and team-scoped task links
  // ---------------------------------------------------------------
  let milestoneListFilter = '';
  let milestoneIncludeArchived = false;
  let milestoneRows = [];
  let milestoneNextOffset = null;
  let milestoneSelectedId = null;
  let milestoneDetailOffset = null;
  let milestoneRequestSeq = 0;

  function milestoneManager() { return iHave(['administrator', 'manager']); }
  function milestoneHealthLabel(value) {
    return {complete:'Complete', overdue:'Past target', at_risk:'At risk', on_track:'On track', unplanned:'Needs tasks', archived:'Archived'}[value] || 'Tracking';
  }
  function milestoneHealthClass(value) {
    return ['complete','overdue','at_risk','on_track','unplanned','archived'].includes(value) ? value.replace('_','-') : 'unplanned';
  }
  function milestoneFormHtml(current = null) {
    const listId = Number(current?.list_id || milestoneListFilter || state.currentListId || (state.lists[0] || {}).id);
    return `<div class="milestone-form">
      <label for="ms-input-list">Task list</label>
      <select id="ms-input-list" ${current ? 'disabled' : ''}>${state.lists.map(l=>`<option value="${Number(l.id)}" ${Number(l.id)===listId?'selected':''}>${esc(l.name)}</option>`).join('')}</select>
      <label for="ms-input-title">Milestone name</label><input id="ms-input-title" maxlength="150" required value="${esc(current?.title || '')}" placeholder="e.g. Launch ready">
      <label for="ms-input-due">Target date (optional)</label><input id="ms-input-due" type="date" value="${esc(current?.due_date || '')}">
      <label for="ms-input-description">Description (optional)</label><textarea id="ms-input-description" maxlength="2000" rows="3" placeholder="What must this milestone achieve?">${esc(current?.description || '')}</textarea>
    </div>`;
  }
  function milestoneFormValues() {
    const title = document.getElementById('ms-input-title').value.trim();
    if (!title || new TextEncoder().encode(title).length > 150) throw new Error('Enter a milestone name no longer than 150 bytes.');
    const description = document.getElementById('ms-input-description').value;
    if (new TextEncoder().encode(description).length > 2000) throw new Error('Description is too long.');
    return {list_id:Number(document.getElementById('ms-input-list').value),title,description,due_date:document.getElementById('ms-input-due').value || null};
  }
  function createMilestonePrompt() {
    if (!state.lists.length) { toast('Create a task list first.', true); return; }
    promptModal('New milestone', milestoneFormHtml(), async () => {
      const values = milestoneFormValues();
      const created = await api('milestones.php',{method:'POST',body:values});
      milestoneSelectedId = Number(created.id);
      milestoneListFilter = String(values.list_id);
      toast('Milestone created');
      await renderMilestones();
    });
  }
  function editMilestonePrompt(row) {
    promptModal('Edit milestone', milestoneFormHtml(row), async () => {
      const body=milestoneFormValues();
      delete body.list_id; // changing a milestone's list would corrupt task links
      await api('milestones.php',{method:'PUT',body:{id:Number(row.id),...body}});
      toast('Milestone updated');
      milestoneSelectedId=Number(row.id);
      await loadMilestoneCards(false);
    });
  }
  async function renderMilestones() {
    const container=document.getElementById('view-container');
    if (!state.currentTeamId) { container.innerHTML=emptyTeamState(); return; }
    if (milestoneListFilter && !state.lists.some(l=>Number(l.id)===Number(milestoneListFilter))) milestoneListFilter='';
    document.getElementById('topbar-actions').innerHTML=milestoneManager()?'<button type="button" class="btn btn-primary" id="ms-new-top">+ New milestone</button>':'';
    document.getElementById('ms-new-top')?.addEventListener('click',createMilestonePrompt);
    container.innerHTML=`<section class="milestone-intro"><div><span class="milestone-eyebrow">DELIVERY PLANNING</span>
      <h2>Know what is ready for the next milestone.</h2><p>Set target dates, link list tasks, and follow progress. Only managers can change milestones or task links.</p></div>
      <div class="milestone-intro-art" aria-hidden="true"><span>01</span><span>02</span><span>03</span></div></section>
      <div class="milestone-toolbar"><label for="ms-filter-list">Task list <select id="ms-filter-list"><option value="">All lists</option>${state.lists.map(l=>`<option value="${Number(l.id)}" ${Number(l.id)===Number(milestoneListFilter)?'selected':''}>${esc(l.name)}</option>`).join('')}</select></label>
      <label class="milestone-archived-toggle"><input type="checkbox" id="ms-show-archived" ${milestoneIncludeArchived?'checked':''}> Include archived</label></div>
      <div id="ms-summary" class="milestone-summary" aria-live="polite"></div>
      <div id="ms-cards" class="milestone-grid" aria-live="polite"><p>Loading milestones...</p></div>
      <div id="ms-load-more-wrap" class="milestone-more"></div>
      <div id="ms-detail" aria-live="polite"></div>`;
    container.querySelector('#ms-filter-list').addEventListener('change',e=>{
      milestoneListFilter=e.target.value;milestoneSelectedId=null;loadMilestoneCards(false);
    });
    container.querySelector('#ms-show-archived').addEventListener('change',e=>{
      milestoneIncludeArchived=e.target.checked;milestoneSelectedId=null;loadMilestoneCards(false);
    });
    await loadMilestoneCards(false);
  }
  async function loadMilestoneCards(append=false) {
    const seq=++milestoneRequestSeq;
    const teamId=Number(state.currentTeamId);
    const params=new URLSearchParams({team_id:String(teamId),offset:String(append?(milestoneNextOffset||0):0),include_archived:milestoneIncludeArchived?'1':'0'});
    if(milestoneListFilter)params.set('list_id',milestoneListFilter);
    const cards=document.getElementById('ms-cards');
    if(!cards)return;
    if(!append){cards.innerHTML='<p>Loading milestones...</p>';document.getElementById('ms-detail').innerHTML='';}
    try {
      const response=await api('milestones.php?'+params);
      if(seq!==milestoneRequestSeq||state.currentView!=='milestones'||Number(state.currentTeamId)!==teamId)return;
      milestoneRows=append?milestoneRows.concat(response.milestones):response.milestones;
      milestoneNextOffset=response.next_offset;
      const late=milestoneRows.filter(m=>['overdue','at_risk'].includes(m.health)).length;
      const completed=milestoneRows.filter(m=>m.health==='complete').length;
      document.getElementById('ms-summary').innerHTML=`<div><strong>${milestoneRows.length}</strong><span>Loaded milestones</span></div><div><strong>${completed}</strong><span>Complete</span></div><div><strong>${late}</strong><span>Need attention</span></div>`;
      cards.innerHTML=milestoneRows.length?milestoneRows.map(m=>{
        const total=Number(m.total_tasks),done=Number(m.done_tasks),percent=Number(m.progress_percent);
        return `<article class="milestone-card ${Number(m.id)===milestoneSelectedId?'milestone-selected':''}">
          <div class="milestone-card-top"><span class="milestone-list-name">${esc(m.list_name)}</span><span class="milestone-health milestone-health-${milestoneHealthClass(m.health)}">${milestoneHealthLabel(m.health)}</span></div>
          <h3>${esc(m.title)}</h3><p class="milestone-description">${esc(m.description||'No description yet.')}</p>
          <div class="milestone-progress-label"><span>${done} of ${total} tasks done</span><strong>${percent}%</strong></div>
          <div class="milestone-progress" role="progressbar" aria-label="${esc(m.title)} progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${percent}"><span style="width:${percent}%"></span></div>
          <div class="milestone-card-footer"><span>${m.due_date?'Target '+fmtDate(m.due_date):'No target date'}</span><button type="button" class="btn btn-ghost" data-ms-open="${Number(m.id)}">View tasks</button></div>
        </article>`;
      }).join(''):'<div class="empty-state"><h3>No milestones here</h3><p>Managers can add a milestone for any task list in this team.</p></div>';
      cards.querySelectorAll('[data-ms-open]').forEach(btn=>btn.addEventListener('click',()=>openMilestoneDetail(Number(btn.dataset.msOpen))));
      document.getElementById('ms-load-more-wrap').innerHTML=milestoneNextOffset!==null?'<button type="button" class="btn btn-ghost" id="ms-more">Load more milestones</button>':'';
      document.getElementById('ms-more')?.addEventListener('click',()=>loadMilestoneCards(true));
      if(milestoneSelectedId && milestoneRows.some(r=>Number(r.id)===milestoneSelectedId)) await openMilestoneDetail(milestoneSelectedId,false);
    } catch(err){if(seq===milestoneRequestSeq)cards.innerHTML=`<div class="load-error-box"><p>${esc(err.message)}</p><button class="btn btn-ghost" id="ms-retry">Try again</button></div>`;document.getElementById('ms-retry')?.addEventListener('click',()=>loadMilestoneCards(false));}
  }
  async function openMilestoneDetail(id,scroll=true,append=false) {
    const detail=document.getElementById('ms-detail');
    if(!detail)return;
    const row=milestoneRows.find(m=>Number(m.id)===Number(id));
    if(!row)return;
    if(!append) {milestoneSelectedId=id;milestoneDetailOffset=null;detail.innerHTML='<p>Loading milestone tasks...</p>';}
    const selected=id;
    try {
      const response=await api('milestones.php?'+new URLSearchParams({milestone_id:String(id),offset:String(append?(milestoneDetailOffset||0):0)}));
      if(state.currentView!=='milestones'||milestoneSelectedId!==selected)return;
      const tasks=response.tasks||[];
      milestoneDetailOffset=response.next_offset;
      if(!append){
        detail.innerHTML=`<section class="milestone-detail-card"><div class="milestone-detail-header"><div><span class="milestone-eyebrow">${esc(row.list_name)}</span><h2>${esc(row.title)}</h2><p>${row.due_date?'Target '+fmtDate(row.due_date):'No target date'} · ${Number(row.done_tasks)} of ${Number(row.total_tasks)} tasks complete</p></div>
          <button type="button" class="btn btn-ghost" id="ms-close-detail" aria-label="Close milestone detail">Close</button></div>
          ${milestoneManager()?`<div class="milestone-actions"><button class="btn btn-ghost" id="ms-edit">Edit</button><button class="btn btn-ghost" id="ms-archive">${Number(row.is_archived)?'Restore':'Archive'}</button><button class="btn btn-ghost milestone-delete" id="ms-delete">Delete milestone</button></div>`:''}
          <div class="milestone-detail-list" id="ms-task-list"></div><div id="ms-task-more"></div>
          ${milestoneManager()&&!Number(row.is_archived)?`<div class="milestone-add"><label for="ms-task-search">Link a task from ${esc(row.list_name)}</label><input id="ms-task-search" type="search" maxlength="120" autocomplete="off" placeholder="Search tasks to link (2+ characters)"><div id="ms-task-results" aria-live="polite"></div></div>`:''}
          </section>`;
        detail.querySelector('#ms-close-detail').addEventListener('click',()=>{milestoneSelectedId=null;detail.innerHTML='';});
        detail.querySelector('#ms-edit')?.addEventListener('click',()=>editMilestonePrompt(row));
        detail.querySelector('#ms-archive')?.addEventListener('click',async()=>{
          try {await api('milestones.php',{method:'PUT',body:{id:Number(row.id),is_archived:!Number(row.is_archived)}});toast(Number(row.is_archived)?'Milestone restored':'Milestone archived');milestoneSelectedId=null;loadMilestoneCards(false);}
          catch(err){toast(err.message,true);}
        });
        detail.querySelector('#ms-delete')?.addEventListener('click',async()=>{
          if(!confirm('Delete this milestone? Linked tasks will stay in their lists.'))return;
          try {await api('milestones.php',{method:'DELETE',body:{id:Number(row.id)}});toast('Milestone deleted');milestoneSelectedId=null;loadMilestoneCards(false);}
          catch(err){toast(err.message,true);}
        });
        const search=detail.querySelector('#ms-task-search');
        if(search){
          let serial=0,timer;
          search.addEventListener('input',()=>{
            const q=search.value.trim();const target=detail.querySelector('#ms-task-results');
            clearTimeout(timer);const current=++serial;
            if(q.length<2){target.textContent='';return;}
            timer=setTimeout(async()=>{
              try{
                const data=await api('search.php?'+new URLSearchParams({team_id:String(state.currentTeamId),list_id:String(row.list_id),q,limit:'15'}));
                if(current!==serial||milestoneSelectedId!==selected||!search.isConnected)return;
                target.innerHTML=data.tasks.length?data.tasks.map(t=>`<button type="button" class="milestone-search-result" data-ms-add="${Number(t.id)}"><strong>${esc(t.title)}</strong><small>${esc(t.status.replaceAll('_',' '))}</small></button>`).join(''):'<p class="milestone-note">No matching tasks.</p>';
                target.querySelectorAll('[data-ms-add]').forEach(btn=>btn.addEventListener('click',async()=>{
                  btn.disabled=true;
                  try {await api('milestone_tasks.php',{method:'POST',body:{milestone_id:Number(row.id),task_id:Number(btn.dataset.msAdd)}});toast('Task linked');await loadMilestoneCards(false);}
                  catch(err){toast(err.message,true);btn.disabled=false;}
                }));
              }catch(err){if(current===serial)target.textContent=err.message;}
            },250);
          });
        }
      }
      const list=detail.querySelector('#ms-task-list');
      if(!list)return;
      const html=tasks.map(t=>`<div class="milestone-task-row">
        <button type="button" data-ms-task="${Number(t.id)}" class="milestone-task-open"><span class="milestone-task-check ${t.status==='done'?'is-done':''}" aria-hidden="true">${t.status==='done'?'&#10003;':''}</span><span>${esc(t.title)}</span></button>
        <span class="milestone-task-status">${esc(t.status.replaceAll('_',' '))}</span>
        ${milestoneManager()?`<button type="button" class="btn btn-ghost" data-ms-remove="${Number(t.id)}" aria-label="Unlink ${esc(t.title)}">Unlink</button>`:''}
      </div>`).join('');
      if(append)list.insertAdjacentHTML('beforeend',html);
      else list.innerHTML=html||'<p class="milestone-note">No tasks linked yet. Managers can search and link tasks from this list.</p>';
      list.querySelectorAll('[data-ms-task]').forEach(btn=>btn.onclick=()=>openTaskModal(Number(btn.dataset.msTask)));
      list.querySelectorAll('[data-ms-remove]').forEach(btn=>btn.onclick=async()=>{
        try {await api('milestone_tasks.php',{method:'DELETE',body:{milestone_id:Number(row.id),task_id:Number(btn.dataset.msRemove)}});toast('Task unlinked');await loadMilestoneCards(false);}
        catch(err){toast(err.message,true);}
      });
      detail.querySelector('#ms-task-more').innerHTML=milestoneDetailOffset!==null?'<button type="button" class="btn btn-ghost" id="ms-task-more-btn">Load more tasks</button>':'';
      detail.querySelector('#ms-task-more-btn')?.addEventListener('click',()=>openMilestoneDetail(id,false,true));
      if(scroll)detail.scrollIntoView({behavior:'smooth',block:'start'});
    } catch(err){if(!append)detail.textContent='Could not load milestone tasks: '+err.message;else toast(err.message,true);}
  }

  // ---------------------------------------------------------------
  // MONTHLY VIEW (year broken into weeks, departments horizontal)
  // ---------------------------------------------------------------
  let monthlyYear = new Date().getFullYear();
  let monthlyTab = 'current'; // past | current | upcoming

  function mondayOf(date) {
    const d = new Date(date);
    const day = d.getDay(); // 0=Sun..6=Sat
    const diff = (day === 0 ? -6 : 1) - day;
    d.setDate(d.getDate() + diff);
    return d.toISOString().slice(0, 10);
  }
  function addDays(dateStr, n) {
    const d = new Date(dateStr + 'T00:00:00');
    d.setDate(d.getDate() + n);
    return d.toISOString().slice(0, 10);
  }

  async function renderMonthly() {
    const container = document.getElementById('view-container');
    const isAdmin = iHave(['administrator']);
    if (!isAdmin && !state.currentTeamId) { container.innerHTML = emptyTeamState(); return; }

    document.getElementById('topbar-actions').innerHTML = monthlyTab === 'current' ? '' : `
      <div class="week-nav">
        <button id="my-prev" class="btn btn-ghost">&#8249; ${monthlyYear - 1}</button>
        <button id="my-today" class="btn btn-ghost">This year</button>
        <button id="my-next" class="btn btn-ghost">${monthlyYear + 1} &#8250;</button>
      </div>`;
    if (monthlyTab !== 'current') {
      document.getElementById('my-prev').onclick = () => { monthlyYear--; renderMonthly(); };
      document.getElementById('my-next').onclick = () => { monthlyYear++; renderMonthly(); };
      document.getElementById('my-today').onclick = () => { monthlyYear = new Date().getFullYear(); renderMonthly(); };
    }

    container.innerHTML = `
      <div class="tab-bar">
        <button class="tab-btn ${monthlyTab === 'past' ? 'active' : ''}" data-mtab="past">Past Weeks</button>
        <button class="tab-btn ${monthlyTab === 'current' ? 'active' : ''}" data-mtab="current">Current &amp; Next</button>
        <button class="tab-btn ${monthlyTab === 'upcoming' ? 'active' : ''}" data-mtab="upcoming">Upcoming</button>
      </div>
      <div id="month-body"><p>Loading…</p></div>`;
    container.querySelectorAll('.tab-btn').forEach(btn => {
      btn.addEventListener('click', () => { monthlyTab = btn.dataset.mtab; renderMonthly(); });
    });

    const scopeQs = isAdmin ? 'scope=org' : `scope=team&team_id=${state.currentTeamId}`;
    const todayStr = new Date().toISOString().slice(0, 10);

    let weeksToShow = [];
    let headerNote = '';

    try {
      if (monthlyTab === 'current') {
        const thisYear = new Date().getFullYear();
        const data = await api(`monthly.php?${scopeQs}&year=${thisYear}`);
        let idx = data.weeks.findIndex(w => todayStr >= w.start && todayStr <= w.end);
        if (idx === -1) idx = 0;
        const current = data.weeks[idx];
        let next = data.weeks[idx + 1];
        if (!next) {
          const nextYearData = await api(`monthly.php?${scopeQs}&year=${thisYear + 1}`);
          next = nextYearData.weeks[0];
        }
        weeksToShow = [current, next].filter(Boolean);
        headerNote = 'This week and next week';
      } else {
        const data = await api(`monthly.php?${scopeQs}&year=${monthlyYear}`);
        if (monthlyTab === 'past') {
          weeksToShow = data.weeks.filter(w => w.end < todayStr);
          headerNote = `${data.year} \u2014 completed weeks`;
        } else {
          const monday = mondayOf(new Date());
          const currentAndNextEnd = addDays(monday, 13); // covers this week + next week
          weeksToShow = data.weeks.filter(w => w.start > currentAndNextEnd);
          headerNote = `${data.year} \u2014 upcoming`;
        }
      }
    } catch (err) {
      const body = document.getElementById('month-body');
      if (body) {
        body.innerHTML = `<div class="load-error-box">
          <p>Couldn't load the monthly view: ${esc(err.message)}</p>
          <button type="button" class="btn btn-ghost" id="monthly-retry">Try again</button>
        </div>`;
        const retryBtn = document.getElementById('monthly-retry');
        if (retryBtn) retryBtn.addEventListener('click', renderMonthly);
      }
      return;
    }

    const body = document.getElementById('month-body');
    if (!weeksToShow.length) {
      body.innerHTML = `<div class="empty-state"><h3>Nothing here</h3><p>${monthlyTab === 'past' ? 'No completed weeks in this year yet.' : monthlyTab === 'upcoming' ? 'No further weeks in this year.' : 'Could not find the current week — try refreshing.'}</p></div>`;
      return;
    }

    let html = `<div class="month-header"><h2>${esc(headerNote)}</h2><p>${isAdmin ? 'All departments' : 'Your team'}</p></div>`;
    weeksToShow.forEach(week => {
      const isCurrent = todayStr >= week.start && todayStr <= week.end;
      const weekTotal = week.teams.reduce((sum, t) => sum + t.tasks.length, 0);
      html += `<div class="month-week-row ${isCurrent ? 'is-current' : ''}">
        <div class="month-week-header">
          <span class="month-week-num">Week ${week.week_number}</span>
          <span class="month-week-range">${fmtDate(week.start)} \u2013 ${fmtDate(week.end)}</span>
          ${isCurrent ? '<span class="month-week-tag">This week</span>' : ''}
          ${weekTotal === 0 ? '<span class="month-week-empty">No tasks</span>' : ''}
        </div>
        <div class="month-week-depts">
          ${week.teams.map(team => `
            <div class="month-dept-col">
              <div class="month-dept-header"><span class="space-dot" style="background:${esc(team.color)}"></span>${esc(team.name)}</div>
              <div class="month-dept-tasks">
                ${team.tasks.length ? team.tasks.map(monthTaskChipHtml).join('') : '<p class="month-dept-empty">\u2014</p>'}
              </div>
            </div>`).join('')}
        </div>
      </div>`;
    });
    body.innerHTML = html;

    body.querySelectorAll('.month-task-chip').forEach(chip => {
      chip.addEventListener('click', () => openTaskModal(Number(chip.dataset.task)));
    });
  }

  function monthTaskChipHtml(t) {
    const assignees = (t.assignees || []).slice(0, 3).map(a =>
      `<span class="avatar avatar-xs" style="background:${esc(a.avatar_color)}" title="${esc(a.name)}">${esc(initials(a.name))}</span>`
    ).join('');
    return `<div class="month-task-chip priority-border-${esc(t.priority)} ${t.status === 'done' ? 'is-done' : ''}" data-task="${t.id}">
      <div class="month-task-chip-title">${esc(t.title)}</div>
      <div class="month-task-chip-meta">
        <span class="status-pill status-${esc(t.status)}">${esc(t.status.replace('_', ' '))}</span>
        <span class="avatar-row">${assignees}</span>
      </div>
    </div>`;
  }

  function taskCardHtml(t) {
    const assignees = (t.assignees || []).slice(0, 3).map(a =>
      `<span class="avatar" style="background:${esc(a.avatar_color)}" title="${esc(a.name)}${a.roles ? ' — ' + esc(rolesLabel(a.roles)) + (a.team_name ? ' · ' + esc(a.team_name) : '') : ''}">${esc(initials(a.name))}</span>`
    ).join('');
    return `<div class="task-card ${t.status === 'done' ? 'is-done' : ''}" data-task="${t.id}">
      <div class="task-card-title">${esc(t.title)}</div>
      <div class="task-card-meta">
        <span class="priority-pill priority-${esc(t.priority)}">${esc(t.priority)}</span>
        <span class="status-pill status-${esc(t.status)}">${esc(t.status.replace('_', ' '))}</span>
        <span class="avatar-row">${assignees}</span>
      </div>
    </div>`;
  }

  function emptyTeamState() {
    return `<div class="empty-state"><h3>No team selected</h3><p>Pick a team from the switcher at the top of the sidebar.</p></div>`;
  }

  // ---------------------------------------------------------------
  // ---------------------------------------------------------------
  // Project timeline — date ranges, milestones, team-scoped keyset pages
  // ---------------------------------------------------------------
  let timelineCursor = null;
  let timelineRequestSeq = 0;
  function timelineDownloadUrl() {
    const qs = new URLSearchParams({team_id:String(state.currentTeamId),month:state.timelineMonth,
      days:String(state.timelineDays),mine:state.timelineMine?'1':'0'});
    if (state.timelineListId) qs.set('list_id',String(state.timelineListId));
    return 'api/calendar_export.php?' + qs;
  }
  function timelineBar(row,window) {
    const start = row.start_date || row.due_date;
    const end = row.due_date || row.start_date;
    if (!start || !end) return '';
    const origin = Date.parse(window.start + 'T00:00:00Z');
    const length = 86400000 * Number(window.days);
    const a = Date.parse(start + 'T00:00:00Z');
    const b = Date.parse(end + 'T00:00:00Z');
    if (!Number.isFinite(a) || !Number.isFinite(b)) return '';
    const left = Math.max(0, Math.min(99.5, (Math.min(a,b) - origin) / length * 100));
    const right = Math.max(0, Math.min(100, (Math.max(a,b) - origin) / length * 100 + 100 / Number(window.days)));
    const width = Math.max(1, right - left);
    const status = ['done','to_do','in_progress','review'].includes(row.status) ? row.status : 'to_do';
    return `<button type="button" class="timeline-bar timeline-bar-${status}" data-timeline-open="${Number(row.id)}"
      style="left:${left.toFixed(3)}%;width:${width.toFixed(3)}%" title="${esc(row.title)}: ${esc(start)} to ${esc(end)}"
      aria-label="Open ${esc(row.title)}"></button>`;
  }
  async function renderTimeline() {
    const container = document.getElementById('view-container');
    if (!state.currentTeamId) {container.innerHTML=emptyTeamState();return;}
    if (!state.lists.some(l => Number(l.id) === Number(state.timelineListId))) state.timelineListId = 0;
    container.innerHTML = `<section class="timeline-shell"><div class="milestone-eyebrow">DELIVERY ROADMAP</div>
      <h2>See your work over time</h2><p class="calendar-hint">Task bars span start to due date; milestone diamonds mark delivery targets. Open any task to update its dates.</p>
      <div class="timeline-tools">
        <label>Starting month<input type="month" id="timeline-month" min="2000-01" max="2099-12" value="${esc(state.timelineMonth)}"></label>
        <label>Window<select id="timeline-days"><option value="28" ${state.timelineDays===28?'selected':''}>4 weeks</option>
          <option value="56" ${state.timelineDays===56?'selected':''}>8 weeks</option><option value="84" ${state.timelineDays===84?'selected':''}>12 weeks</option></select></label>
        <label>Project<select id="timeline-list"><option value="0">All projects</option>${state.lists.map(l=>`<option value="${Number(l.id)}" ${Number(l.id)===Number(state.timelineListId)?'selected':''}>${esc(l.name)}</option>`).join('')}</select></label>
        <label class="calendar-mine"><input type="checkbox" id="timeline-mine" ${state.timelineMine?'checked':''}> My tasks</label>
        <a class="btn btn-ghost" id="timeline-export" href="${esc(timelineDownloadUrl())}" download="taskflow-calendar.ics">Export calendar (.ics)</a>
      </div>
      <div class="timeline-summary" id="timeline-summary" aria-live="polite">Loading roadmap…</div>
      <div class="timeline-scroll" tabindex="0" aria-label="Scrollable project timeline">
        <div class="timeline-table" id="timeline-table"></div>
      </div>
      <div class="portfolio-more" id="timeline-more"></div>
      <p class="calendar-hint">Calendar downloads are snapshots, not automatic subscriptions. The export includes all visible-period due dates and project milestones, with your team access enforced on the server.</p></section>`;
    const refresh=()=>{document.getElementById('timeline-export').href=timelineDownloadUrl(); timelineCursor=null;loadTimeline(false);};
    document.getElementById('timeline-month').addEventListener('change',event=>{if (!/^20\d\d-(0[1-9]|1[0-2])$/.test(event.target.value)) return;state.timelineMonth=event.target.value;refresh();});
    document.getElementById('timeline-days').addEventListener('change',event=>{state.timelineDays=Number(event.target.value);refresh();});
    document.getElementById('timeline-list').addEventListener('change',event=>{state.timelineListId=Number(event.target.value);refresh();});
    document.getElementById('timeline-mine').addEventListener('change',event=>{state.timelineMine=event.target.checked;refresh();});
    container.querySelector('#timeline-table').addEventListener('click',event=>{
      const task=event.target.closest('[data-timeline-open]');
      if (task) {openTaskModal(Number(task.dataset.timelineOpen));return;}
      const milestone=event.target.closest('[data-timeline-milestone]');
      if (milestone) {milestoneSelectedId=Number(milestone.dataset.timelineMilestone);milestoneListFilter='';setView('milestones');}
    });
    container.querySelector('#timeline-more').addEventListener('click',event=>{
      if(event.target.closest('#timeline-more-btn'))loadTimeline(true);
    });
    timelineCursor=null;
    await loadTimeline(false);
  }
  async function loadTimeline(append=false) {
    const seq=++timelineRequestSeq;
    const team=Number(state.currentTeamId);
    const cursor=append?timelineCursor:null;
    const table=document.getElementById('timeline-table');
    const summary=document.getElementById('timeline-summary');
    if(!table || !summary || (!append && state.currentView!=='timeline'))return;
    const qs=new URLSearchParams({team_id:String(team),month:state.timelineMonth,days:String(state.timelineDays),
      mine:state.timelineMine?'1':'0',after_id:String(cursor||0)});
    if(state.timelineListId)qs.set('list_id',String(state.timelineListId));
    try {
      const data=await api('timeline.php?'+qs);
      if(seq!==timelineRequestSeq || state.currentView!=='timeline' || Number(state.currentTeamId)!==team)return;
      const win=data.window;
      if(!append){
        const origin=Date.parse(win.start+'T00:00:00Z');
        const ticks=Array.from({length:Number(win.days)/7},(_,i)=>{
          const date=new Date(origin+i*7*86400000);
          return `<span>${esc(date.toLocaleDateString(undefined,{month:'short',day:'numeric',timeZone:'UTC'}))}</span>`;
        });
        table.innerHTML=`<div class="timeline-header"><div>Projects &amp; tasks</div><div class="timeline-ticks">${ticks.join('')}</div></div><div id="timeline-rows"></div>`;
        const markers=(data.milestones||[]).map(m=>{
          const date=Date.parse(m.due_date+'T00:00:00Z');
          const left=Math.min(99.5,Math.max(0,((date-origin)/(86400000*Number(win.days)))*100));
          return `<div class="timeline-row timeline-milestone-row"><div class="timeline-row-info"><span class="timeline-subtitle">${esc(m.list_name)}</span>
             <button type="button" class="timeline-row-link" data-timeline-milestone="${Number(m.id)}">◆ ${esc(m.title)}</button></div>
             <div class="timeline-track" style="background-size:${(700/Number(win.days)).toFixed(3)}% 100%"><button type="button" class="timeline-diamond" data-timeline-milestone="${Number(m.id)}" style="left:${left.toFixed(3)}%" title="${esc(m.title)} — ${esc(m.due_date)}" aria-label="Open milestone ${esc(m.title)}"></button></div></div>`;
        });
        if(markers.length)table.querySelector('#timeline-rows').insertAdjacentHTML('beforeend',markers.join(''));
      }
      const rows=table.querySelector('#timeline-rows');
      const cards=(data.tasks||[]).map(task=>`<div class="timeline-row"><div class="timeline-row-info"><span class="timeline-subtitle">${esc(task.list_name)} · ${esc(task.status.replaceAll('_',' '))}</span>
        <button type="button" class="timeline-row-link" data-timeline-open="${Number(task.id)}">${esc(task.title)}</button></div>
        <div class="timeline-track" style="background-size:${(700/Number(win.days)).toFixed(3)}% 100%">${timelineBar(task,win)}</div></div>`);
      rows.insertAdjacentHTML('beforeend',cards.join(''));
      timelineCursor=data.next_after_id;
      const more=document.getElementById('timeline-more');
      more.innerHTML=timelineCursor?'<button type="button" class="btn btn-ghost" id="timeline-more-btn">Load more tasks</button>':'';
      const total=rows.querySelectorAll('.timeline-row:not(.timeline-milestone-row)').length;
      const countMilestones=rows.querySelectorAll('.timeline-milestone-row').length;
      summary.textContent=`${total} tasks and ${countMilestones} milestones shown · ${win.start} to ${win.end}`+
        (data.milestones_truncated?' · Showing first 100 milestones; narrow your project filter.':'')+
        (timelineCursor?' · More tasks available.':'');
      if(!rows.children.length)rows.innerHTML='<div class="timeline-empty">No scheduled tasks or milestones in this period. Set start and due dates on tasks, or change the date window.</div>';
    }catch(err){
      if(seq!==timelineRequestSeq || state.currentView!=='timeline')return;
      summary.textContent='Unable to load project timeline.';
      if(!append)table.innerHTML=`<div class="load-error-box">${esc(err.message)}</div>`;
      toast(err.message,true);
    }
  }

  // Calendar — bounded team-scoped due dates, responsive agenda on mobile
  // ---------------------------------------------------------------
  async function renderCalendar() {
    const container = document.getElementById('view-container');
    if (!state.currentTeamId) { container.innerHTML = emptyTeamState(); return; }
    const teamId = state.currentTeamId;
    const month = state.calendarMonth;
    container.innerHTML = `<div class="calendar-toolbar">
      <div class="calendar-controls"><button type="button" class="btn btn-ghost" id="calendar-previous" aria-label="Previous month">←</button>
      <h2 id="calendar-month-label"></h2><button type="button" class="btn btn-ghost" id="calendar-next" aria-label="Next month">→</button>
      <button type="button" class="btn btn-ghost" id="calendar-today">Today</button></div>
      <label class="calendar-mine"><input type="checkbox" id="calendar-mine" ${state.calendarMine?'checked':''}> Only my tasks</label>
      <a class="btn btn-ghost" href="${esc('api/calendar_export.php?' + new URLSearchParams({team_id:String(teamId),month,days:'56',mine:state.calendarMine?'1':'0'}))}" download="taskflow-calendar.ics">Export .ics</a></div>
      <p id="calendar-status" class="calendar-hint" role="status">Loading dates…</p>
      <div id="calendar-grid" class="calendar-grid" aria-label="Team task calendar"></div>
      <div id="calendar-agenda" class="calendar-agenda" aria-label="Due date agenda"></div>`;
    const shiftMonth = offset => {
      const [year,mon] = state.calendarMonth.split('-').map(Number);
      const next = new Date(Date.UTC(year, mon - 1 + offset, 1));
      state.calendarMonth = next.getUTCFullYear() + '-' + String(next.getUTCMonth()+1).padStart(2,'0');
      renderCalendar();
    };
    container.querySelector('#calendar-previous').addEventListener('click', () => shiftMonth(-1));
    container.querySelector('#calendar-next').addEventListener('click', () => shiftMonth(1));
    container.querySelector('#calendar-today').addEventListener('click', () => {
      const today = new Date(); state.calendarMonth = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2,'0'); renderCalendar();
    });
    container.querySelector('#calendar-mine').addEventListener('change', e => { state.calendarMine = e.target.checked; renderCalendar(); });
    try {
      const data = await api('calendar.php?' + new URLSearchParams({team_id:String(teamId), month, mine:state.calendarMine?'1':'0'}));
      if (state.currentView !== 'calendar' || state.currentTeamId !== teamId || state.calendarMonth !== month || !container.querySelector('#calendar-grid')) return;
      const [year, m] = month.split('-').map(Number);
      const first = new Date(Date.UTC(year,m-1,1));
      container.querySelector('#calendar-month-label').textContent = first.toLocaleDateString(undefined, {month:'long',year:'numeric',timeZone:'UTC'});
      const tasksByDay = new Map();
      for (const task of data.tasks) {
        if (!tasksByDay.has(task.due_date)) tasksByDay.set(task.due_date, []);
        tasksByDay.get(task.due_date).push(task);
      }
      const todayStr = [new Date().getFullYear(),String(new Date().getMonth()+1).padStart(2,'0'),String(new Date().getDate()).padStart(2,'0')].join('-');
      const weekdayHeader = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].map(d=>`<div class="calendar-weekday">${d}</div>`).join('');
      let grid = weekdayHeader;
      for (let index=0; index<42; index++) {
        const date = new Date(data.start+'T00:00:00Z');
        date.setUTCDate(date.getUTCDate()+index);
        const iso = date.toISOString().slice(0,10);
        const dayTasks = tasksByDay.get(iso) || [];
        const outside = iso.slice(0,7)!==month;
        grid += `<div class="calendar-day ${outside?'calendar-outside':''} ${iso===todayStr?'calendar-today':''}" aria-label="${esc(iso)}">
          <div class="calendar-day-number">${date.getUTCDate()}${iso===todayStr?'<span>Today</span>':''}</div>
          <div class="calendar-day-tasks">${dayTasks.slice(0,4).map(t=>`<button type="button" class="calendar-event ${t.status==='done'?'calendar-done':''}" data-task-id="${Number(t.id)}" title="${esc(t.title)}">${esc(t.title)}</button>`).join('')}
          ${dayTasks.length>4?`<small class="calendar-more">+${dayTasks.length-4} more</small>`:''}</div></div>`;
      }
      container.querySelector('#calendar-grid').innerHTML=grid;
      const monthTasks=data.tasks.filter(t=>t.due_date && t.due_date.slice(0,7)===month);
      container.querySelector('#calendar-agenda').innerHTML=monthTasks.length ? `<h3>Due this month</h3><div class="calendar-agenda-list">${monthTasks.map(t=>`<button type="button" class="calendar-agenda-item" data-task-id="${Number(t.id)}"><time>${esc(fmtDate(t.due_date))}</time><strong>${esc(t.title)}</strong><span>${esc(t.list_name)}</span><span class="status-pill status-${esc(t.status)}">${esc(t.status.replaceAll('_',' '))}</span></button>`).join('')}</div>` : '<p class="calendar-empty">Nothing is due this month.</p>';
      container.querySelector('#calendar-status').textContent = data.truncated ? 'Showing the first 400 tasks for these dates. Narrow the view with “Only my tasks”.' : `${monthTasks.length} task${monthTasks.length===1?'':'s'} due this month`;
      container.querySelectorAll('[data-task-id]').forEach(el=>el.addEventListener('click',()=>openTaskModal(Number(el.dataset.taskId))));
    } catch (e) { if (state.currentView==='calendar') container.querySelector('#calendar-status').textContent='Could not load calendar: '+e.message; }
  }

  // ---------------------------------------------------------------
  // Templates — team-scoped reusable work, manager maintained
  // ---------------------------------------------------------------
  async function renderTemplates() {
    const container=document.getElementById('view-container');
    if (!state.currentTeamId) { container.innerHTML=emptyTeamState(); return; }
    const teamId=state.currentTeamId;
    const canManage=iHave(['administrator','manager']);
    document.getElementById('topbar-actions').innerHTML=canManage?'<button type="button" class="btn btn-primary" id="new-template-button">+ New template</button>':'';
    document.getElementById('new-template-button')?.addEventListener('click',()=>editTemplate(null));
    container.innerHTML='<p class="template-hint">Loading team templates…</p>';
    try {
      const data=await api('templates.php?team_id='+teamId);
      if (state.currentView!=='templates' || state.currentTeamId!==teamId) return;
      const templates=data.templates || [];
      container.innerHTML=`<div class="feature-intro"><h2>Start with a proven workflow</h2><p>Use a template to create a task with its checklist. Due dates and assignments are always new.</p></div>
        ${data.truncated?'<p class="template-hint">Showing the first 100 templates.</p>':''}
        <div class="template-grid">${templates.map(t=>`<article class="template-card"><div class="template-card-top"><span class="template-flag">REUSABLE</span><span class="priority-pill priority-${esc(t.priority)}">${esc(t.priority)}</span></div>
          <h3>${esc(t.title)}</h3><p>${esc((t.description||'').slice(0,280))}</p>${t.estimate_minutes?`<div class="template-estimate">Estimated: ${hoursText(t.estimate_minutes)}</div>`:''}<div class="template-item-preview">${(t.items||[]).slice(0,3).map(i=>`<div><span aria-hidden="true">☐</span>${esc(i)}</div>`).join('')}${(t.items||[]).length>3?`<small>+${t.items.length-3} more checklist items</small>`:''}</div>
          <div class="template-actions"><button type="button" class="btn btn-primary" data-use-template="${Number(t.id)}">Use template</button>
          ${canManage?`<button type="button" class="btn btn-ghost" data-edit-template="${Number(t.id)}">Edit</button><button type="button" class="btn btn-ghost" data-delete-template="${Number(t.id)}">Delete</button>`:''}</div></article>`).join('') || '<div class="empty-state"><h3>No templates yet</h3><p>A manager can create reusable checklists for this team.</p></div>'}</div>`;
      container.querySelectorAll('[data-use-template]').forEach(btn=>btn.addEventListener('click',()=>useTemplate(templates.find(t=>Number(t.id)===Number(btn.dataset.useTemplate)))));
      container.querySelectorAll('[data-edit-template]').forEach(btn=>btn.addEventListener('click',()=>editTemplate(templates.find(t=>Number(t.id)===Number(btn.dataset.editTemplate)))));
      container.querySelectorAll('[data-delete-template]').forEach(btn=>btn.addEventListener('click',async()=>{
        if (!confirm('Delete this template? Existing tasks will be unchanged.')) return;
        try {await api('templates.php',{method:'DELETE',body:{id:Number(btn.dataset.deleteTemplate)}}); toast('Template deleted');renderTemplates();}
        catch(e){toast(e.message,true);}
      }));
    } catch(e){if(state.currentView==='templates') container.innerHTML=`<div class="load-error-box">Could not load templates: ${esc(e.message)}<button type="button" class="btn btn-ghost" id="template-retry">Retry</button></div>`;
      document.getElementById('template-retry')?.addEventListener('click',renderTemplates);}
  }

  function editTemplate(template) {
    const isEditing=!!template;
    promptModal(isEditing?'Edit template':'New task template', `
      <p class="template-hint">For your current team. Each line becomes an unchecked checklist item (up to 30).</p>
      <label for="p-template-title">Template name</label><input id="p-template-title" maxlength="255" required value="${esc(template?.title || '')}" placeholder="e.g. Weekly client review">
      <label for="p-template-desc">Description</label><textarea id="p-template-desc" maxlength="5000" placeholder="Instructions or context">${esc(template?.description || '')}</textarea>
      <label for="p-template-priority">Default priority</label><select id="p-template-priority">${['none','low','normal','high','urgent'].map(p=>`<option value="${p}" ${p===(template?.priority||'normal')?'selected':''}>${p}</option>`).join('')}</select>
      <label for="p-template-estimate">Estimated effort (hours, optional)</label><input type="number" id="p-template-estimate" min="0.0167" max="400" step="any" value="${hoursInput(template?.estimate_minutes)}" placeholder="e.g. 2.5">
      <label for="p-template-items">Checklist, one item per line</label><textarea id="p-template-items" rows="5" placeholder="Prepare brief&#10;Get approval&#10;Share updates">${esc((template?.items||[]).join('\n'))}</textarea>
    `,async()=>{
      const title=document.getElementById('p-template-title').value.trim();
      if(!title) throw new Error('Enter a template name.');
      const items=document.getElementById('p-template-items').value.split(/\r?\n/).map(x=>x.trim()).filter(Boolean);
      if(items.length>30) throw new Error('A template supports up to 30 checklist items.');
      await api('templates.php',{method:isEditing?'PUT':'POST',body:{...(isEditing?{id:Number(template.id)}:{team_id:state.currentTeamId}),
        title,description:document.getElementById('p-template-desc').value,priority:document.getElementById('p-template-priority').value,estimate_minutes:readEstimateHours('p-template-estimate'),items}});
      toast(isEditing?'Template updated':'Template created');renderTemplates();
    });
  }

  function useTemplate(template) {
    if (!template) return;
    if (!state.lists.length) { toast('Ask your manager to create a task list first.',true); return; }
    promptModal('Create task from template', `
      <p class="template-hint">${esc(template.title)} • ${(template.items||[]).length} checklist items</p>
      <label for="p-use-title">Task title</label><input id="p-use-title" maxlength="255" value="${esc(template.title)}" required>
      <label for="p-use-list">Task list</label><select id="p-use-list">${state.lists.map(l=>`<option value="${Number(l.id)}">${esc(l.name)}</option>`).join('')}</select>
      <label for="p-use-due">Due date (optional)</label><input type="date" id="p-use-due">
      <label class="repeat-field"><input type="checkbox" id="p-use-repeat"> Repeat weekly after completion</label>
    `,async()=>{
      const title=document.getElementById('p-use-title').value.trim();if(!title) throw new Error('Enter a task title.');
      const result=await api('tasks.php',{method:'POST',body:{template_id:Number(template.id),list_id:Number(document.getElementById('p-use-list').value),
        title,due_date:document.getElementById('p-use-due').value,is_recurring_weekly:document.getElementById('p-use-repeat').checked,
        assignee_ids:iHave(['administrator','manager'])?[]:[Number(state.user.id)]}});
      toast('Task created from template');openTaskModal(result.id);
    });
  }

  // ---------------------------------------------------------------
  // TASKS VIEW — tabs: Add Task / View Tasks / Pending / Completed
  // ---------------------------------------------------------------
  async function renderTasksView() {
    const container = document.getElementById('view-container');
    if (!state.currentTeamId) { container.innerHTML = emptyTeamState(); return; }
    if (!state.lists.length) {
      const canCreate = iHave(['administrator', 'manager']);
      container.innerHTML = `<div class="empty-state"><h3>No list yet</h3><p>${canCreate ? 'Create a list to start adding tasks.' : 'Ask your manager or administrator to create a list.'}</p>${canCreate ? '<button class="btn btn-primary" id="empty-new-list-btn">+ New list</button>' : ''}</div>`;
      if (document.getElementById('empty-new-list-btn')) {
        document.getElementById('empty-new-list-btn').addEventListener('click', () => {
          promptModal('New list', `<input id="p-list-name" placeholder="List name" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;">`, async () => {
            const name = document.getElementById('p-list-name').value.trim();
            if (!name) return;
            await api('lists.php', { method: 'POST', body: { team_id: state.currentTeamId, name } });
            await loadTeamContext();
            renderTasksView();
          });
        });
      }
      return;
    }
    if (!state.currentListId) state.currentListId = state.lists[0].id;

    const listPicker = state.lists.length > 1
      ? `<select id="list-picker">${state.lists.map(l => `<option value="${l.id}" ${l.id === state.currentListId ? 'selected' : ''}>${esc(l.name)}</option>`).join('')}</select>`
      : '';
    const canManageLists = iHave(['administrator', 'manager']);

    document.getElementById('topbar-actions').innerHTML = `
      ${listPicker}
      ${canManageLists ? '<button class="btn btn-ghost" id="new-list-btn">+ New list</button>' : ''}
      <button type="button" class="btn btn-ghost" id="task-import-open">Import CSV</button>
    `;
    if (document.getElementById('list-picker')) {
      document.getElementById('list-picker').addEventListener('change', e => {
        state.currentListId = Number(e.target.value);rememberProjectSelection();
        renderTasksView();
      });
    }
    document.getElementById('task-import-open')?.addEventListener('click',openTaskImportModal);
    if (document.getElementById('new-list-btn')) {
      document.getElementById('new-list-btn').addEventListener('click', () => {
        promptModal('New list', `<input id="p-list-name" placeholder="List name" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;">`, async () => {
          const name = document.getElementById('p-list-name').value.trim();
          if (!name) return;
          await api('lists.php', { method: 'POST', body: { team_id: state.currentTeamId, name } });
          await loadTeamContext();
          renderTasksView();
        });
      });
    }

    container.innerHTML = `
      <div class="tab-bar">
        <button class="tab-btn ${state.taskTab === 'add' ? 'active' : ''}" data-tab="add">+ New task</button>
        <button class="tab-btn ${state.taskTab === 'view' ? 'active' : ''}" data-tab="view">List view</button>
        <button class="tab-btn ${state.taskTab === 'pending' ? 'active' : ''}" data-tab="pending">Pending</button>
        <button class="tab-btn ${state.taskTab === 'completed' ? 'active' : ''}" data-tab="completed">Completed</button>
      </div>
      <div id="tasks-tab-body"></div>
    `;
    container.querySelectorAll('.tab-btn').forEach(btn => {
      btn.addEventListener('click', () => { state.taskTab = btn.dataset.tab; renderTasksView(); });
    });

    if (state.members.length === 0 || state.members.teamIdLoaded !== state.currentTeamId) {
      try {
        const md = await api('users.php');
        state.members = md.users;
        state.members.teamIdLoaded = state.currentTeamId;
      } catch (err) {
        document.getElementById('tasks-tab-body').innerHTML = `<div class="load-error-box">
          <p>Couldn't load team members: ${esc(err.message)}</p>
          <button type="button" class="btn btn-ghost" id="members-retry">Try again</button>
        </div>`;
        const retryBtn = document.getElementById('members-retry');
        if (retryBtn) retryBtn.addEventListener('click', renderTasksView);
        return;
      }
    }

    if (state.taskTab === 'add') renderAddTaskForm();
    else renderTaskTable(state.taskTab === 'view' ? null : state.taskTab);
  }

  // v3 task import: preview in the browser, validate again on the server,
  // and create all selected rows in a single database transaction.
  function openTaskImportModal() {
    const teamId=Number(state.currentTeamId),listId=Number(state.currentListId);
    let ready=[];
    const template='title,description,priority,due_date,estimate_hours\nExample task,Optional details,high,2027-01-20,2.5\n';
    promptModal('Import tasks from CSV', `
      <div class="task-import-intro"><p>Import up to <strong>50 tasks</strong> into <strong>${esc(state.lists.find(l=>Number(l.id)===listId)?.name||'this project')}</strong>.</p>
      <p>Columns: <code>title</code> (required), <code>description</code>, <code>priority</code>, <code>due_date</code>, <code>estimate_hours</code>.</p>
      <p>Tasks begin as To do. Members are assigned to their own imports; managers create unassigned tasks.</p></div>
      <div class="task-import-actions"><a id="task-import-template" class="btn btn-ghost" download="taskflow-tasks-template.csv" href="data:text/csv;charset=utf-8,${encodeURIComponent(template)}">Download template</a></div>
      <label class="task-import-file-label" for="task-import-file">Select CSV file (maximum 128 KB)</label>
      <input id="task-import-file" type="file" accept=".csv,text/csv" aria-describedby="task-import-status">
      <div id="task-import-status" role="status" aria-live="polite" class="task-import-status">Choose a CSV file to preview its tasks.</div>
      <div id="task-import-preview" class="task-import-preview"></div>
    `,async()=>{
      if(!ready.length)throw new Error('Select and preview a valid CSV file first.');
      if(Number(state.currentTeamId)!==teamId || Number(state.currentListId)!==listId)throw new Error('The project changed. Reopen the import.');
      const result=await api('task_import.php',{method:'POST',body:{team_id:teamId,list_id:listId,rows:ready}});
      toast(`${Number(result.created)} tasks imported`);
      state.taskTab='view';
      renderTasksView();
    });
    const picker=document.getElementById('task-import-file');
    const status=document.getElementById('task-import-status');
    const preview=document.getElementById('task-import-preview');
    picker?.addEventListener('change',async()=>{
      ready=[];
      preview.replaceChildren();
      const file=picker.files?.[0];
      if(!file){status.textContent='Select a CSV file to continue.';return;}
      if(file.size>128*1024){status.textContent='File too large. Use a CSV smaller than 128 KB.';return;}
      status.textContent='Checking CSV…';
      try{
        const contents=await file.text();
        if(picker.files?.[0]!==file || !picker.isConnected || document.getElementById('prompt-modal')?.classList.contains('hidden'))return;
        if(!window.TaskFlowCSV?.parse)throw new Error('CSV importer did not load. Refresh and retry.');
        ready=window.TaskFlowCSV.parse(contents);
        status.textContent=`Ready to import ${ready.length} tasks. Review the preview, then confirm.`;
        const table=document.createElement('table');table.className='task-import-table';
        table.innerHTML='<thead><tr><th>Title</th><th>Priority</th><th>Due</th><th>Hours</th></tr></thead>';
        const body=document.createElement('tbody');
        for(const row of ready.slice(0,12)){
          const tr=document.createElement('tr');
          for(const value of [row.title,row.priority,row.due_date||'—',row.estimate_hours||'—']){
            const td=document.createElement('td');td.textContent=value;tr.appendChild(td);
          }
          body.appendChild(tr);
        }
        table.appendChild(body);preview.appendChild(table);
        if(ready.length>12){const note=document.createElement('p');note.textContent=`Showing 12 of ${ready.length} rows.`;preview.appendChild(note);}
      }catch(e){ready=[];preview.replaceChildren();status.textContent=e.message||'Could not read CSV.';}
    });
  }

  function renderAddTaskForm() {
    const body = document.getElementById('tasks-tab-body');
    const canAssignOthers = iHave(['administrator', 'manager']);
    const assigneeOptions = state.members.filter(m => (m.team_id === undefined || Number(m.team_id) === Number(state.currentTeamId)) && (m.is_active == null || Number(m.is_active) === 1)).map(m => `<option value="${m.id}">${esc(m.name)}${m.roles && m.roles.length ? ' (' + esc(rolesLabel(m.roles)) + ')' : ''}</option>`).join('');

    body.innerHTML = `
      <form id="add-task-form" class="add-task-form">
        <div class="field-group"><label for="at-template">Start from a template <span class="muted-label">optional</span></label><select id="at-template"><option value="">Blank task</option></select></div>
        <div class="field-group"><label for="at-title">Title</label><input type="text" id="at-title" required maxlength="255" placeholder="Task title"></div>
        <div class="field-group"><label>Description</label><textarea id="at-desc" placeholder="Optional details"></textarea></div>
        <div class="field-row">
          <div class="field-group"><label>Priority</label>
            <select id="at-priority">${['none','low','normal','high','urgent'].map(p => `<option value="${p}">${p}</option>`).join('')}</select>
          </div>
          <div class="field-group"><label>Due date</label><input type="date" id="at-due"></div>
          <div class="field-group"><label for="at-estimate">Estimated effort (hours)</label><input type="number" id="at-estimate" min="0.0167" max="400" step="any" placeholder="e.g. 3"></div>
          <div class="field-group"><label for="at-repeat-rule">Repeat after completion</label><select id="at-repeat-rule"><option value="none">Does not repeat</option><option value="daily">Every day</option><option value="weekly">Every week</option><option value="biweekly">Every two weeks</option><option value="monthly">Every month</option></select></div>
          ${canAssignOthers ? `<div class="field-group"><label>Assign to</label><select id="at-assignees" multiple size="3">${assigneeOptions}</select></div>` : ''}
        </div>
        <button type="submit" class="btn btn-primary">Create task</button>
      </form>
    `;
    // Render the form immediately, then fetch team templates without blocking task creation.
    const formTeamId = state.currentTeamId;
    api('templates.php?team_id=' + formTeamId).then(data => {
      const sel = document.getElementById('at-template');
      if (!sel || formTeamId !== state.currentTeamId) return;
      const templateMap = new Map((data.templates || []).map(t => [String(t.id),t]));
      sel.innerHTML = '<option value="">Blank task</option>' + (data.templates || []).map(t => `<option value="${Number(t.id)}">${esc(t.title)}</option>`).join('');
      sel.addEventListener('change', () => {
        const tmpl = templateMap.get(sel.value);
        if (!tmpl) return;
        document.getElementById('at-title').value = tmpl.title;
        document.getElementById('at-desc').value = tmpl.description || '';
        document.getElementById('at-priority').value = tmpl.priority;
        document.getElementById('at-estimate').value = hoursInput(tmpl.estimate_minutes);
      });
    }).catch(() => {/* new task stays available without templates */});
    document.getElementById('add-task-form').addEventListener('submit', async e => {
      e.preventDefault();
      const title = document.getElementById('at-title').value.trim();
      if (!title) return;
      let estimateMinutes;
      try { estimateMinutes = readEstimateHours('at-estimate'); } catch(err) { toast(err.message,true); return; }
      const body = {
        list_id: state.currentListId, title,
        description: document.getElementById('at-desc').value,
        priority: document.getElementById('at-priority').value, estimate_minutes: estimateMinutes,
        due_date: document.getElementById('at-due').value,
        recurrence_rule: document.getElementById('at-repeat-rule').value,
      };
      const chosenTemplate = document.getElementById('at-template').value;
      if (chosenTemplate) body.template_id = Number(chosenTemplate);
      if (canAssignOthers) {
        body.assignee_ids = Array.from(document.getElementById('at-assignees').selectedOptions).map(o => Number(o.value));
      } else {
        body.assignee_ids = [state.user.id]; // members creating their own task assign it to themselves
      }
      try {
        await api('tasks.php', { method: 'POST', body });
        toast('Task created');
        e.target.reset();
        state.taskTab = 'view';
        renderTasksView();
      } catch (err) { toast(err.message, true); }
    });
  }

  // TaskFlow v3: edit common task fields in the queue without opening the full dialog.
  // Mutations continue through task.php: same CSRF, team, role and blocker checks.
  const quickTaskValues={
    status:[['to_do','To do'],['in_progress','In progress'],['review','Review'],['done','Done']],
    priority:[['none','None'],['low','Low'],['normal','Normal'],['high','High'],['urgent','Urgent']]
  };
  const quickTaskSaving=new Set();
  function quickTaskFieldHtml(task,field,editable){
    const value=String(task[field]||''),label=field==='status'?'Status':'Priority';
    const cls=field==='status'?'status-pill status-'+esc(value):'priority-pill priority-'+esc(value);
    if(!editable)return `<span class="${cls}">${esc(value.replaceAll('_',' '))}</span>`;
    const options=quickTaskValues[field].map(([key,name])=>`<option value="${key}" ${key===value?'selected':''}>${name}</option>`).join('');
    return `<span class="task-quick-pill ${cls}"><select class="task-quick-select" data-quick-task-id="${Number(task.id)}" data-quick-field="${field}" data-original-value="${esc(value)}" aria-label="Change ${label.toLowerCase()} for ${esc(task.title)}" title="Change ${label.toLowerCase()}">${options}</select></span>`;
  }
  async function changeTaskQuickly(control,refresh){
    const taskId=Number(control.dataset.quickTaskId),field=control.dataset.quickField;
    const selected=control.value,original=control.dataset.originalValue;
    if(!Number.isSafeInteger(taskId)||taskId<1||!Object.hasOwn(quickTaskValues,field)
      ||!quickTaskValues[field].some(([key])=>key===selected)){
      control.value=original;toast('Invalid task update.',true);return;
    }
    if(selected===original)return;
    if(quickTaskSaving.has(taskId)){
      control.value=original;toast('That task is already being updated.',true);return;
    }
    quickTaskSaving.add(taskId);
    const otherControls=[...document.querySelectorAll('.task-quick-select')].filter(item=>Number(item.dataset.quickTaskId)===taskId);
    otherControls.forEach(item=>{item.disabled=true;item.setAttribute('aria-busy','true');});
    try{
      await api('task.php',{method:'PATCH',body:{id:taskId,[field]:selected}});
      control.dataset.originalValue=selected;
      toast(field==='status'?'Task status updated':'Task priority updated');
      try{await refresh();}catch(reloadError){toast('Saved, but the list could not refresh: '+reloadError.message,true);}
    }catch(error){
      if(control.isConnected)control.value=original;
      toast(error.message,true);
    }finally{
      quickTaskSaving.delete(taskId);
      otherControls.forEach(item=>{if(item.isConnected){item.disabled=false;item.removeAttribute('aria-busy');}});
    }
  }

  const bulkSelectedTasks = new Set();
  function updateBulkSelection(body) {
    const count=body.querySelector('#bulk-count');
    if (!count) return;
    count.textContent=bulkSelectedTasks.size + ' selected';
    const field=body.querySelector('#bulk-field')?.value;
    const dateField=body.querySelector('#bulk-date');
    const teammate=body.querySelector('#bulk-teammate');
    const tag=body.querySelector('#bulk-tag');
    const estimate=body.querySelector('#bulk-estimate');
    const missingDate=(field==='due_date'||field==='start_date') && !/^\d{4}-\d{2}-\d{2}$/.test(dateField?.value||'');
    const missingTeammate=(field==='assignee_add'||field==='assignee_remove') && !Number(teammate?.value);
    const missingTag=(field==='tag_add'||field==='tag_remove') && !Number(tag?.value);
    const missingEstimate=field==='estimate_minutes' && (!/^\d+$/.test(estimate?.value||'') || Number(estimate.value)<1 || Number(estimate.value)>24000);
    body.querySelector('#bulk-apply').disabled=bulkSelectedTasks.size===0 || missingDate || missingTeammate || missingTag || missingEstimate;
    const clear=body.querySelector('#bulk-clear-selection');
    if(clear)clear.disabled=bulkSelectedTasks.size===0;
    const page=body.querySelector('#bulk-select-page');
    const checks=Array.from(body.querySelectorAll('.bulk-row-check:not(:disabled)'));
    page.checked=checks.length>0 && checks.every(c=>c.checked);
    page.indeterminate=checks.some(c=>c.checked) && !page.checked;
  }

  async function renderTaskTable(statusFilter, offset = 0) {
    const body = document.getElementById('tasks-tab-body');
    if (offset === 0) body.innerHTML = '<p>Loading…</p>';
    else body.querySelector('#task-load-more')?.remove();
    let url = 'tasks.php?list_id=' + state.currentListId + '&limit=100&offset=' + Number(offset);
    if (statusFilter === 'pending') url += '&status=pending';
    if (statusFilter === 'completed') url += '&status=done';

    let data;
    try {
      data = await api(url);
    } catch (err) {
      if (offset > 0) {
        toast('Could not load more tasks: ' + err.message, true);
        const button = document.createElement('button');
        button.id = 'task-load-more'; button.className = 'btn btn-ghost'; button.textContent = 'Try loading more';
        button.onclick = () => renderTaskTable(statusFilter, offset);
        body.appendChild(button);
      } else {
        body.innerHTML = `<div class="load-error-box">
          <p>Couldn't load tasks: ${esc(err.message)}</p>
          <button type="button" class="btn btn-ghost" id="task-table-retry">Try again</button>
        </div>`;
        document.getElementById('task-table-retry').onclick = () => renderTaskTable(statusFilter);
      }
      return;
    }

    if (!data.tasks.length && offset === 0) {
      body.innerHTML = `<div class="empty-state"><h3>Nothing here</h3><p>${statusFilter === 'completed' ? 'No completed tasks yet.' : statusFilter === 'pending' ? 'No pending tasks — nice.' : 'No tasks yet — use + Add Task to create one.'}</p></div>`;
      return;
    }

    const rows = data.tasks.map(t => {
      const assignees = (t.assignees || []).map(a => `<span class="avatar" style="background:${esc(a.avatar_color)}" title="${esc(a.name)}${a.roles ? ' — ' + esc(rolesLabel(a.roles)) + (a.team_name ? ' · ' + esc(a.team_name) : '') : ''}">${esc(initials(a.name))}</span>`).join('');
      const tags = (t.tags || []).map(tg => `<span class="tag-pill" style="background:${esc(tg.color)}22;color:${esc(tg.color)}">${esc(tg.name)}</span>`).join(' ');
      const subBadge = t.subtasks && t.subtasks.total ? ` <span style="color:var(--text-faint);font-size:11.5px;">${t.subtasks.done}/${t.subtasks.total} subtasks</span>` : '';
      const blockedBadge = Number(t.unfinished_blockers) ? ` <span class="blocked-badge" title="This task has unfinished prerequisites">${Number(t.unfinished_blockers)} blocked</span>` : '';
      const editable = iHave(['administrator','manager']) || Number(t.created_by) === Number(state.user.id) || (t.assignees || []).some(a => Number(a.id)===Number(state.user.id));
      return `<tr class="task-row ${t.status === 'done' ? 'is-done' : ''}" data-task="${t.id}">
        <td class="task-bulk-cell"><input class="bulk-row-check" type="checkbox" aria-label="Select ${esc(t.title)}" value="${Number(t.id)}" ${editable?'':'disabled'}></td>
        <td class="task-title-cell">${esc(t.title)}${subBadge}${blockedBadge}</td>
        <td>${quickTaskFieldHtml(t,'status',editable)}</td>
        <td>${quickTaskFieldHtml(t,'priority',editable)}</td>
        <td>${t.due_date ? fmtDate(t.due_date) : '<span style="color:var(--text-faint)">—</span>'}</td>
        <td>${tags}</td>
        <td><span class="avatar-row">${assignees}</span></td>
      </tr>`;
    }).join('');

    if (offset === 0) {
      bulkSelectedTasks.clear();
      body.innerHTML = `<div class="bulk-toolbar" id="bulk-toolbar">
        <strong id="bulk-count">0 selected</strong>
        <label>Action <select id="bulk-field" aria-label="Bulk action">
          <optgroup label="Progress"><option value="status">Change status</option><option value="priority">Change priority</option></optgroup>
          <optgroup label="Schedule"><option value="start_date">Set start date</option><option value="start_date_clear">Clear start date</option><option value="due_date">Set due date</option><option value="due_date_clear">Clear due date</option></optgroup>
          <optgroup label="Planning"><option value="estimate_minutes">Set effort (minutes)</option><option value="estimate_clear">Clear effort estimate</option></optgroup>
          <optgroup label="Labels"><option value="tag_add">Add label</option><option value="tag_remove">Remove label</option></optgroup>
          ${iHave(['administrator','manager'])?'<optgroup label="People"><option value="assignee_add">Add assignee</option><option value="assignee_remove">Remove assignee</option></optgroup>':''}</select></label>
        <select id="bulk-value" aria-label="New value"></select>
        <input id="bulk-date" type="date" aria-label="Start or due date" hidden>
        <input id="bulk-estimate" type="number" min="1" max="24000" step="1" inputmode="numeric" placeholder="Minutes (1–24000)" aria-label="Estimated effort in minutes" hidden>
        <select id="bulk-tag" aria-label="Team label" hidden><option value="">Choose label…</option>${state.tags.map(t=>`<option value="${Number(t.id)}">${esc(t.name)}</option>`).join('')}</select>
        <select id="bulk-teammate" aria-label="Team member" hidden><option value="">Choose teammate…</option>${state.members.filter(u=>Number(u.team_id)===Number(state.currentTeamId)&&Number(u.is_active??1)===1).map(u=>`<option value="${Number(u.id)}">${esc(u.name)}</option>`).join('')}</select>
        <button type="button" class="btn btn-primary" id="bulk-apply" disabled>Apply to selected</button>
        <button type="button" class="btn btn-ghost" id="bulk-clear-selection" disabled>Clear selection</button>
        <span class="bulk-hint" role="status" aria-live="polite">Select up to 50 editable tasks. Shift-click for a range; every change is atomic.</span>
      </div><div class="table-scroll"><table class="task-table">
        <thead><tr><th><input id="bulk-select-page" type="checkbox" aria-label="Select visible editable tasks (maximum 50)"></th><th>Task</th><th>Status</th><th>Priority</th><th>Due</th><th>Tags</th><th>Assignees</th></tr></thead>
        <tbody>${rows}</tbody>
      </table></div>`;
      let selectionAnchor=null;
      const selectOptions = {
        status:[['to_do','To do'],['in_progress','In progress'],['review','Review'],['done','Done']],
        priority:[['none','None'],['low','Low'],['normal','Normal'],['high','High'],['urgent','Urgent']]
      };
      const updateValues = () => {
        const field=body.querySelector('#bulk-field').value;
        const dropdown=body.querySelector('#bulk-value');
        const date=body.querySelector('#bulk-date');
        const teammate=body.querySelector('#bulk-teammate');
        const tag=body.querySelector('#bulk-tag');
        const estimate=body.querySelector('#bulk-estimate');
        dropdown.hidden=!selectOptions[field];
        date.hidden=field!=='due_date' && field!=='start_date';
        date.setAttribute('aria-label',field==='start_date'?'New start date':'New due date');
        teammate.hidden=field!=='assignee_add' && field!=='assignee_remove';
        tag.hidden=field!=='tag_add' && field!=='tag_remove';
        estimate.hidden=field!=='estimate_minutes';
        if(selectOptions[field])dropdown.innerHTML=selectOptions[field].map(([v,label])=>`<option value="${v}">${label}</option>`).join('');
        updateBulkSelection(body);
      };
      updateValues();
      body.querySelector('#bulk-field').addEventListener('change',updateValues);
      body.querySelector('#bulk-date').addEventListener('input',()=>updateBulkSelection(body));
      body.querySelector('#bulk-teammate').addEventListener('change',()=>updateBulkSelection(body));
      body.querySelector('#bulk-tag').addEventListener('change',()=>updateBulkSelection(body));
      body.querySelector('#bulk-estimate').addEventListener('input',()=>updateBulkSelection(body));
      body.querySelector('#bulk-clear-selection').addEventListener('click',()=>{
        bulkSelectedTasks.clear();
        selectionAnchor=null;
        body.querySelectorAll('.bulk-row-check').forEach(check=>{check.checked=false;});
        updateBulkSelection(body);
      });
      body.querySelector('#bulk-apply').addEventListener('click', async () => {
        if (!bulkSelectedTasks.size || bulkSelectedTasks.size > 50) return;
        const field=body.querySelector('#bulk-field').value;
        let value=body.querySelector('#bulk-value').value;
        if(field==='due_date'||field==='start_date')value=body.querySelector('#bulk-date').value;
        if(['due_date_clear','start_date_clear','estimate_clear'].includes(field))value=null;
        if(field==='estimate_minutes')value=Number(body.querySelector('#bulk-estimate').value);
        if(field==='tag_add'||field==='tag_remove')value=Number(body.querySelector('#bulk-tag').value);
        if(field==='assignee_add'||field==='assignee_remove')value=Number(body.querySelector('#bulk-teammate').value);
        const count=bulkSelectedTasks.size;
        const label=field==='due_date_clear'?'clear due dates':field==='start_date_clear'?'clear start dates':field==='estimate_clear'?'clear estimates':
          field==='due_date'?'set due date '+value:field==='start_date'?'set start date '+value:
          field==='estimate_minutes'?'set estimate to '+value+' minutes':
          field.startsWith('tag_')?`${field==='tag_add'?'add':'remove'} label ${body.querySelector('#bulk-tag').selectedOptions[0]?.textContent}`:
          field.startsWith('assignee_')?`${field==='assignee_add'?'add':'remove'} teammate ${body.querySelector('#bulk-teammate').selectedOptions[0]?.textContent}`:
          `${field.replaceAll('_',' ')}: ${String(value).replaceAll('_',' ')}`;
        if(!confirm(`Apply ${label} to ${count} selected tasks?`))return;
        const button=body.querySelector('#bulk-apply');button.disabled=true;
        try {
          const response = await api('bulk.php',{ method:'POST',body:{
            team_id:Number(state.currentTeamId), task_ids:Array.from(bulkSelectedTasks),field,value
          }});
          toast(`${response.changed} ${field.startsWith('assignee_')?'assignment(s)':field.startsWith('tag_')?'label(s)':'task(s)'} updated${response.recurring_created ? `; ${response.recurring_created} next recurring occurrence(s) created` : ''}`);
          renderTasksView();
        } catch(e) {toast(e.message,true);updateBulkSelection(body);}
      });
      body.addEventListener('click',event=>{
        const checkbox=event.target.closest('.bulk-row-check:not(:disabled)');
        if(!checkbox)return;
        const id=Number(checkbox.value);
        if(event.shiftKey && selectionAnchor!==null){
          const boxes=Array.from(body.querySelectorAll('.bulk-row-check:not(:disabled)'));
          const start=boxes.findIndex(box=>Number(box.value)===selectionAnchor);
          const finish=boxes.indexOf(checkbox);
          if(start>=0 && finish>=0){
            const desired=checkbox.checked;
            for(let i=Math.min(start,finish);i<=Math.max(start,finish);i++){
              const row=boxes[i],key=Number(row.value);
              if(desired && (bulkSelectedTasks.size<50 || bulkSelectedTasks.has(key))){row.checked=true;bulkSelectedTasks.add(key);}
              if(!desired){row.checked=false;bulkSelectedTasks.delete(key);}
            }
            updateBulkSelection(body);
          }
        }
        selectionAnchor=id;
      });
      body.onclick = event => {
        if (event.target.closest('.task-bulk-cell') || event.target.closest('.bulk-toolbar') || event.target.closest('thead') || event.target.closest('.task-quick-select')) return;
        const row = event.target.closest('.task-row');
        if (row) openTaskModal(Number(row.dataset.task));
      };
      body.onchange = event => {
        if(event.target.matches('.task-quick-select')){
          changeTaskQuickly(event.target,async()=>{if(state.currentView==='tasks')await renderTasksView();});
          return;
        }
        if (event.target.matches('.bulk-row-check')) {
          const id=Number(event.target.value);
          if (event.target.checked && bulkSelectedTasks.size>=50 && !bulkSelectedTasks.has(id)) {
            event.target.checked=false;toast('Select at most 50 tasks.',true);return;
          }
          if (event.target.checked) bulkSelectedTasks.add(id);else bulkSelectedTasks.delete(id);
          updateBulkSelection(body);
        } else if (event.target.matches('#bulk-select-page')) {
          const checks=Array.from(body.querySelectorAll('.bulk-row-check:not(:disabled)'));
          if (!event.target.checked) {
            checks.forEach(ch => {ch.checked=false;bulkSelectedTasks.delete(Number(ch.value));});
          } else {
            checks.forEach(ch => {if (bulkSelectedTasks.size < 50) {ch.checked=true;bulkSelectedTasks.add(Number(ch.value));}});
            if (checks.length > 50) toast('Selected first 50 editable tasks.',false);
          }
          updateBulkSelection(body);
        }
      };
    } else {
      body.querySelector('.task-table tbody')?.insertAdjacentHTML('beforeend', rows);
    }
    if (data.next_offset !== null && data.next_offset !== undefined) {
      const more = document.createElement('button');
      more.id = 'task-load-more'; more.className = 'btn btn-ghost'; more.textContent = 'Load 100 more tasks';
      more.style.marginTop = '12px';
      more.onclick = () => renderTaskTable(statusFilter, data.next_offset);
      body.appendChild(more);
    }
  }

  // ---------------------------------------------------------------
  // DASHBOARD VIEW (team or org)
  // ---------------------------------------------------------------
  async function renderDashboard(scope) {
    const container = document.getElementById('view-container');
    if (scope === 'team' && !state.currentTeamId) { container.innerHTML = emptyTeamState(); return; }
    container.innerHTML = '<p>Loading…</p>';

    document.getElementById('topbar-actions').innerHTML = `
      <div class="week-nav">
        <button class="btn btn-ghost period-toggle" data-p="weekly">Weekly</button>
        <button class="btn btn-ghost period-toggle" data-p="monthly">Monthly</button>
        <button class="btn btn-ghost" id="dash-prev">&#8249; Prev</button>
        <button class="btn btn-ghost" id="dash-current">Current</button>
        <button class="btn btn-ghost" id="dash-next">Next &#8250;</button>
      </div>`;
    document.querySelectorAll('.period-toggle').forEach(b => {
      b.classList.toggle('active', b.dataset.p === state.dashPeriod);
      b.addEventListener('click', () => { state.dashPeriod = b.dataset.p; state.dashOffset = 0; renderDashboard(scope); });
    });
    document.getElementById('dash-prev').onclick = () => { state.dashOffset--; renderDashboard(scope); };
    document.getElementById('dash-next').onclick = () => { state.dashOffset++; renderDashboard(scope); };
    document.getElementById('dash-current').onclick = () => { state.dashOffset = 0; renderDashboard(scope); };

    let url = `dashboard.php?scope=${scope}&period=${state.dashPeriod}&period_offset=${state.dashOffset}`;
    if (scope === 'team') url += `&team_id=${state.currentTeamId}`;
    let data;
    try {
      data = await api(url);
    } catch (err) {
      container.innerHTML = `<div class="load-error-box">
        <p>Couldn't load the dashboard: ${esc(err.message)}</p>
        <button type="button" class="btn btn-ghost" id="dashboard-retry">Try again</button>
      </div>`;
      const retryBtn = document.getElementById('dashboard-retry');
      if (retryBtn) retryBtn.addEventListener('click', () => renderDashboard(scope));
      return;
    }

    let html = `<div class="dash-period">${fmtDate(data.period_start)} – ${fmtDate(data.period_end)}</div>`;
    html += progressCardHtml('Overall progress', data.overall.done, data.overall.total, data.overall.percent);

    if (scope === 'org') {
      html += `<div class="section-title">By team</div><div class="dash-grid">`;
      data.teams.forEach(t => {
        html += `<div class="dash-tile"><div class="dash-tile-title"><span class="space-dot" style="background:${esc(t.color)}"></span>${esc(t.name)}</div>${progressBarHtml(t.percent)}<div class="dash-tile-sub">${t.done}/${t.total} tasks done</div></div>`;
      });
      html += `</div>`;
    } else {
      html += `<div class="section-title">By team member</div><div class="dash-grid">`;
      data.members.forEach(m => {
        html += `<div class="dash-tile"><div class="dash-tile-title"><span class="avatar" style="background:${esc(m.avatar_color)}">${esc(initials(m.name))}</span><div>${esc(m.name)}${personMetaHtml(m)}</div></div>${progressBarHtml(m.percent)}<div class="dash-tile-sub">${m.done}/${m.total} tasks done</div></div>`;
      });
      html += `</div>`;
    }
    container.innerHTML = html;
  }

  function progressCardHtml(label, done, total, percent) {
    return `<div class="progress-card"><div class="progress-card-top"><strong>${label}</strong><span>${done}/${total} tasks (${percent}%)</span></div>${progressBarHtml(percent)}</div>`;
  }
  function progressBarHtml(percent) {
    return `<div class="progress-bar"><div class="progress-bar-fill" style="width:${percent}%"></div></div>`;
  }

  // ---------------------------------------------------------------
  // WEEKLY GOALS — each person lists as many goals as they want for the
  // week; everyone on the team can see everyone else's, only edit their own.
  // ---------------------------------------------------------------
  let prioritiesWeekOffset = 0;

  async function renderPriorities() {
    const container = document.getElementById('view-container');
    if (!state.currentTeamId) { container.innerHTML = emptyTeamState(); return; }

    document.getElementById('topbar-actions').innerHTML = `
      <div class="week-nav">
        <button id="pr-prev" class="btn btn-ghost">&#8249; Prev week</button>
        <button id="pr-today" class="btn btn-ghost">This week</button>
        <button id="pr-next" class="btn btn-ghost">Next week &#8250;</button>
      </div>`;
    document.getElementById('pr-prev').onclick = () => { prioritiesWeekOffset--; renderPriorities(); };
    document.getElementById('pr-next').onclick = () => { prioritiesWeekOffset++; renderPriorities(); };
    document.getElementById('pr-today').onclick = () => { prioritiesWeekOffset = 0; renderPriorities(); };

    container.innerHTML = '<p>Loading…</p>';
    let data;
    try {
      data = await api(`priorities.php?team_id=${state.currentTeamId}&week_offset=${prioritiesWeekOffset}`);
    } catch (err) {
      container.innerHTML = `<div class="load-error-box">
        <p>Couldn't load weekly goals: ${esc(err.message)}</p>
        <button type="button" class="btn btn-ghost" id="priorities-retry">Try again</button>
      </div>`;
      const retryBtn = document.getElementById('priorities-retry');
      if (retryBtn) retryBtn.addEventListener('click', renderPriorities);
      return;
    }

    const myTile = data.members.find(m => m.id === state.user.id);
    const canManageOthers = iHave(['administrator', 'manager']);
    let html = `<div class="dash-period">Week of ${fmtDate(data.week_start)}</div>`;

    html += `<div class="section-title">Enter your top weekly goals</div>`;
    html += `<div class="goal-edit-card">${goalEditListHtml(myTile ? myTile.priorities : [], 'me')}</div>`;

    html += `<div class="section-title">Team</div><div class="priority-board">`;
    data.members.forEach(m => {
      const isMe = m.id === state.user.id;
      const canEditThis = !isMe && canManageOthers;
      const isEditing = state.editingGoalsFor.has(m.id);
      html += `<div class="priority-tile ${isMe ? 'is-me' : ''}">
        <div class="priority-tile-header">
          <span class="avatar" style="background:${esc(m.avatar_color)}">${esc(initials(m.name))}</span>
          <div><div>${esc(m.name)}${isMe ? ' (you)' : ''}</div>${personMetaHtml(m)}</div>
          ${canEditThis ? `<button class="btn btn-ghost goal-toggle-edit-btn" data-user="${m.id}" style="margin-left:auto;">${isEditing ? 'Done editing' : 'Edit'}</button>` : ''}
        </div>
        ${isEditing ? `<div class="goal-edit-card goal-edit-card-nested">${goalEditListHtml(m.priorities, 'u' + m.id)}</div>` : priorityListHtml(m.priorities)}
      </div>`;
    });
    html += `</div>`;

    container.innerHTML = html;
    wireGoalEditForm('me', state.user.id);
    data.members.forEach(m => {
      if (state.editingGoalsFor.has(m.id)) wireGoalEditForm('u' + m.id, m.id);
    });
    container.querySelectorAll('.goal-toggle-edit-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const uid = Number(btn.dataset.user);
        if (state.editingGoalsFor.has(uid)) state.editingGoalsFor.delete(uid);
        else state.editingGoalsFor.add(uid);
        renderPriorities();
      });
    });
  }

  function priorityListHtml(priorities) {
    if (!priorities.length) return `<p class="priority-empty">No goals set yet.</p>`;
    return `<ul class="priority-list">${priorities.map(p => `
      <li class="${p.is_done ? 'is-done' : ''}"><span class="priority-num">${p.position}</span><span>${esc(p.content)}</span></li>
    `).join('')}</ul>`;
  }

  // ---- dynamic add/remove goal editor (no fixed count); idSuffix keeps
  // multiple simultaneous editors (your own + one teammate's) from colliding ----
  function goalRowHtml(content = '', isDone = false) {
    return `<div class="goal-row">
      <span class="goal-row-num"></span>
      <input type="text" class="goal-row-input" maxlength="255" placeholder="Enter a goal…" value="${esc(content)}">
      <label class="goal-row-done"><input type="checkbox" class="goal-row-check" ${isDone ? 'checked' : ''}> Done</label>
      <button type="button" class="goal-row-remove" title="Remove">&#10005;</button>
    </div>`;
  }

  function goalEditListHtml(priorities, idSuffix) {
    const rows = priorities.length
      ? priorities.map(p => goalRowHtml(p.content, p.is_done)).join('')
      : goalRowHtml(); // start with one empty row so the form isn't blank
    return `<form id="pr-edit-form-${idSuffix}" class="goal-edit-form">
      <div class="goal-edit-list" id="pr-edit-list-${idSuffix}">${rows}</div>
      <div class="goal-edit-actions">
        <button type="button" class="btn btn-ghost" id="pr-add-row-${idSuffix}">+ Add another goal</button>
        <button type="submit" class="btn btn-primary">Save goals</button>
      </div>
    </form>`;
  }

  function wireGoalEditForm(idSuffix, targetUserId) {
    const list = document.getElementById('pr-edit-list-' + idSuffix);
    const form = document.getElementById('pr-edit-form-' + idSuffix);
    const addBtn = document.getElementById('pr-add-row-' + idSuffix);
    if (!list || !form || !addBtn) return;

    addBtn.addEventListener('click', () => {
      list.insertAdjacentHTML('beforeend', goalRowHtml());
      const inputs = list.querySelectorAll('.goal-row-input');
      inputs[inputs.length - 1].focus();
    });

    list.addEventListener('click', e => {
      if (e.target.classList.contains('goal-row-remove')) {
        const rows = list.querySelectorAll('.goal-row');
        if (rows.length > 1) {
          e.target.closest('.goal-row').remove();
        } else {
          // keep at least one row, just clear it
          e.target.closest('.goal-row').querySelector('.goal-row-input').value = '';
          e.target.closest('.goal-row').querySelector('.goal-row-check').checked = false;
        }
      }
    });

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const items = Array.from(list.querySelectorAll('.goal-row')).map(row => ({
        content: row.querySelector('.goal-row-input').value.trim(),
        is_done: row.querySelector('.goal-row-check').checked,
      }));
      try {
        await api('priorities.php', { method: 'POST', body: { week_offset: prioritiesWeekOffset, items, target_user_id: targetUserId } });
        toast('Goals saved');
        renderPriorities();
      } catch (err) { toast(err.message, true); }
    });
  }

  // ---------------------------------------------------------------
  // CHAT — three spaces: Personal (1:1 DMs), Group (this team),
  // All Departments (org-wide). Polled, not real-time. @mention
  // autocomplete in Group/All so a tag can only match a real person.
  // ---------------------------------------------------------------
  let chatPollTimer = null;
  let chatLastId = 0;
  let chatDirectory = [];
  let chatSubTab = 'group'; // personal | group | org
  let dmActiveUserId = null;

  function stopChatPolling() {
    if (chatPollTimer) { clearInterval(chatPollTimer); chatPollTimer = null; }
  }

  async function renderChat() {
    const container = document.getElementById('view-container');
    stopChatPolling();

    container.innerHTML = `
      <div class="tab-bar">
        <button class="tab-btn ${chatSubTab === 'personal' ? 'active' : ''}" data-ctab="personal">Personal</button>
        <button class="tab-btn ${chatSubTab === 'group' ? 'active' : ''}" data-ctab="group">Group${state.currentTeamId ? '' : ' (pick a team)'}</button>
        <button class="tab-btn ${chatSubTab === 'org' ? 'active' : ''}" data-ctab="org">All Departments</button>
      </div>
      <div id="chat-sub-body"></div>
    `;
    container.querySelectorAll('.tab-btn').forEach(btn => {
      btn.addEventListener('click', () => { chatSubTab = btn.dataset.ctab; renderChat(); });
    });

    if (chatSubTab === 'personal') renderPersonalChat();
    else if (chatSubTab === 'org') renderScopedChat('org', null);
    else renderScopedChat('team', state.currentTeamId);
  }

  // ---- shared message rendering (used by Group + All Departments + DMs) ----
  function chatMessageHtml(m, opts = {}) {
    const isMe = m.user_id ? m.user_id === state.user.id : m.sender_id === state.user.id;
    const mentionedNames = (m.mentions || []).map(x => x.name);
    let content = esc(m.content);
    mentionedNames.forEach(name => {
      const escapedName = escapeForRegex(esc(name));
      const re = new RegExp('@' + escapedName, 'gi');
      content = content.replace(re, match => `<strong class="mention-hit">${match}</strong>`);
    });
    const name = opts.name || m.name;
    const avatarColor = opts.avatarColor || m.avatar_color;
    const rolesHtml = m.roles && m.roles.length ? `<span class="person-meta" style="display:inline;">${esc(rolesLabel(m.roles))}</span>` : '';
    return `<div class="chat-msg ${isMe ? 'is-me' : ''}">
      <span class="avatar" style="background:${esc(avatarColor)}">${esc(initials(name))}</span>
      <div class="chat-msg-body">
        <div class="chat-msg-header"><strong>${esc(name)}</strong>${rolesHtml}<span class="comment-time">${esc(m.created_at)}</span></div>
        <div class="chat-msg-text">${content}</div>
      </div>
    </div>`;
  }

  function renderMessagesInto(elId, messages, htmlFn) {
    const el = document.getElementById(elId);
    el.innerHTML = messages.length ? messages.map(htmlFn).join('') : `<p class="priority-empty">No messages yet — say hello.</p>`;
    el.scrollTop = el.scrollHeight;
  }
  function appendMessagesInto(elId, messages, htmlFn) {
    const el = document.getElementById(elId);
    if (el.querySelector('.priority-empty')) el.innerHTML = '';
    el.insertAdjacentHTML('beforeend', messages.map(htmlFn).join(''));
    el.scrollTop = el.scrollHeight;
  }

  // ---- Group / All Departments (team or org scope) ----
  async function renderScopedChat(scope, teamId) {
    const body = document.getElementById('chat-sub-body');
    if (scope === 'team' && !teamId) { body.innerHTML = emptyTeamState(); return; }

    const canClear = scope === 'org' ? iHave(['administrator']) : iHave(['administrator', 'manager']);
    body.innerHTML = `
      <div class="chat-wrap">
        <div class="chat-toolbar">
          ${canClear ? `<button class="btn btn-ghost chat-clear-btn" id="chat-clear-btn">Clear chat</button>` : ''}
        </div>
        <div class="chat-messages" id="chat-messages"><p>Loading…</p></div>
        <form class="chat-compose" id="chat-compose" autocomplete="off">
          <div class="chat-input-wrap">
            <input type="text" id="chat-input" placeholder="${scope === 'org' ? 'Message everyone…' : 'Message your team…'} type @ to mention someone" maxlength="1000">
            <div class="mention-suggest hidden" id="mention-suggest"></div>
          </div>
          <button type="submit" class="btn btn-primary">Send</button>
        </form>
      </div>`;

    try {
      const usersData = scope === 'org' ? await api('dm.php?directory=1') : await api('users.php');
      chatDirectory = scope === 'org' ? usersData.people : usersData.users;

      chatLastId = 0;
      const qs = scope === 'org' ? 'scope=org' : `scope=team&team_id=${teamId}`;
      const data = await api(`chat.php?${qs}&limit=50`);
      renderMessagesInto('chat-messages', data.messages, chatMessageHtml);
      chatLastId = data.messages.length ? data.messages[data.messages.length - 1].id : 0;

      wireChatCompose({
        onSend: async (content) => {
          await api('chat.php', { method: 'POST', body: { scope, team_id: teamId, content } });
        },
      });
      chatPollTimer = setInterval(() => pollActiveChat(scope, teamId), 8000);

      if (canClear) {
        document.getElementById('chat-clear-btn').addEventListener('click', async () => {
          const label = scope === 'org' ? 'the All Departments chat' : "this team's chat";
          if (!confirm(`Clear ${label} for everyone? This cannot be undone.`)) return;
          try {
            await api('chat.php', { method: 'DELETE', body: { scope, team_id: teamId } });
            toast('Chat cleared');
            renderChat();
          } catch (err) { toast(err.message, true); }
        });
      }
    } catch (err) {
      const el = document.getElementById('chat-messages');
      if (el) {
        el.innerHTML = `<div class="dm-directory-error">
          <p>Couldn't load this chat: ${esc(err.message)}</p>
          <button type="button" class="btn btn-ghost" id="chat-scoped-retry">Try again</button>
        </div>`;
        const retryBtn = document.getElementById('chat-scoped-retry');
        if (retryBtn) retryBtn.addEventListener('click', () => renderScopedChat(scope, teamId));
      }
    }
  }

  async function pollActiveChat(scope, teamId) {
    if (state.currentView !== 'chat' || chatSubTab !== (scope === 'org' ? 'org' : 'group')) { stopChatPolling(); return; }
    try {
      const qs = scope === 'org' ? 'scope=org' : `scope=team&team_id=${teamId}`;
      const res = await api(`chat.php?${qs}&after_id=${chatLastId}`);
      if (res.messages.length) {
        appendMessagesInto('chat-messages', res.messages, chatMessageHtml);
        chatLastId = res.messages[res.messages.length - 1].id;
      }
    } catch (err) { /* silent — next poll retries */ }
  }

  // ---- Personal (1:1 DMs) ----
  async function renderPersonalChat() {
    const body = document.getElementById('chat-sub-body');
    body.innerHTML = `
      <div class="dm-layout">
        <div class="dm-list-pane">
          <button class="btn btn-primary btn-block" id="dm-new-btn" style="margin-bottom:10px;">+ New message</button>
          <div id="dm-conversations"><p>Loading…</p></div>
        </div>
        <div class="dm-thread-pane" id="dm-thread-pane">
          <div class="empty-state"><h3>Select a conversation</h3><p>Or start a new one.</p></div>
        </div>
      </div>`;

    document.getElementById('dm-new-btn').addEventListener('click', openDmDirectoryPicker);

    try {
      const data = await api('dm.php?conversations=1');
      renderDmConversationList(data.conversations || []);

      if (dmActiveUserId) {
        const match = (data.conversations || []).find(c => c.id === dmActiveUserId);
        openDmThread(dmActiveUserId, match ? match.name : '', match ? match.avatar_color : '#999');
      }
    } catch (err) {
      const el = document.getElementById('dm-conversations');
      if (el) {
        el.innerHTML = `<div class="dm-directory-error">
          <p>Couldn't load conversations: ${esc(err.message)}</p>
          <button type="button" class="btn btn-ghost" id="dm-convo-retry">Try again</button>
        </div>`;
        const retryBtn = document.getElementById('dm-convo-retry');
        if (retryBtn) retryBtn.addEventListener('click', renderPersonalChat);
      }
    }
  }

  function renderDmConversationList(conversations) {
    conversations = conversations || [];
    const el = document.getElementById('dm-conversations');
    if (!el) return;
    if (!conversations.length) {
      el.innerHTML = `<p class="priority-empty">No conversations yet.</p>`;
      return;
    }
    el.innerHTML = conversations.map(c => `
      <div class="dm-convo-row ${c.id === dmActiveUserId ? 'active' : ''}" data-user="${c.id}">
        <span class="avatar" style="background:${esc(c.avatar_color)}">${esc(initials(c.name))}</span>
        <div class="dm-convo-info">
          <div class="dm-convo-name">${esc(c.name)}</div>
          <div class="dm-convo-preview">${c.last_from_me ? 'You: ' : ''}${esc((c.last_message || '').slice(0, 40))}</div>
        </div>
      </div>`).join('');
    el.querySelectorAll('.dm-convo-row').forEach(row => {
      row.addEventListener('click', () => {
        const c = conversations.find(x => x.id === Number(row.dataset.user));
        openDmThread(c.id, c.name, c.avatar_color);
      });
    });
  }

  function openDmDirectoryPicker() {
    promptModal('New message', `<div id="dm-directory-list" class="dm-directory-list"><p>Loading…</p></div>
      <input type="text" id="dm-directory-search" placeholder="Search people…" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;margin-top:8px;">`,
      async () => {} // no explicit confirm action — picking a person below closes the modal directly
    );
    document.getElementById('prompt-confirm').classList.add('hidden');
    loadDmDirectory();
  }

  function loadDmDirectory() {
    const listEl = document.getElementById('dm-directory-list');
    if (listEl) listEl.innerHTML = '<p>Loading…</p>';

    api('dm.php?directory=1').then(data => {
      chatDirectory = data.people || [];
      const renderList = (filter = '') => {
        const el = document.getElementById('dm-directory-list');
        if (!el) return;
        const f = filter.toLowerCase();
        const matches = chatDirectory.filter(p => p.name.toLowerCase().includes(f));
        el.innerHTML = matches.length
          ? matches.map(p => `
            <div class="mention-suggest-item dm-directory-item" data-user="${p.id}" data-name="${esc(p.name)}" data-color="${esc(p.avatar_color)}">
              <span class="avatar" style="background:${esc(p.avatar_color)}">${esc(initials(p.name))}</span>
              <div><div>${esc(p.name)}</div>${personMetaHtml(p)}</div>
            </div>`).join('')
          : `<p class="priority-empty">No matches.</p>`;
        el.querySelectorAll('.dm-directory-item').forEach(item => {
          item.addEventListener('click', () => {
            document.getElementById('prompt-modal').classList.add('hidden');
            openDmThread(Number(item.dataset.user), item.dataset.name, item.dataset.color);
          });
        });
      };
      renderList();
      const search = document.getElementById('dm-directory-search');
      if (search) search.addEventListener('input', () => renderList(search.value));
    }).catch(err => {
      const el = document.getElementById('dm-directory-list');
      if (!el) return;
      el.innerHTML = `<div class="dm-directory-error">
        <p>Couldn't load people: ${esc(err.message)}</p>
        <button type="button" class="btn btn-ghost" id="dm-directory-retry">Try again</button>
      </div>`;
      const retryBtn = document.getElementById('dm-directory-retry');
      if (retryBtn) retryBtn.addEventListener('click', loadDmDirectory);
    });
  }

  async function openDmThread(userId, name, avatarColor) {
    dmActiveUserId = userId;
    stopChatPolling();
    document.querySelectorAll('.dm-convo-row').forEach(r => r.classList.toggle('active', Number(r.dataset.user) === userId));

    const pane = document.getElementById('dm-thread-pane');
    pane.innerHTML = `
      <div class="chat-wrap">
        <div class="chat-toolbar"><strong>${esc(name)}</strong></div>
        <div class="chat-messages" id="chat-messages"><p>Loading…</p></div>
        <form class="chat-compose" id="chat-compose" autocomplete="off">
          <div class="chat-input-wrap"><input type="text" id="chat-input" placeholder="Message ${esc(name)}…" maxlength="1000"></div>
          <button type="submit" class="btn btn-primary">Send</button>
        </form>
      </div>`;

    chatLastId = 0;
    try {
      const data = await api(`dm.php?with=${userId}&limit=50`);
      const htmlFn = (m) => chatMessageHtml(m, { name: m.sender_id === state.user.id ? state.user.name : name, avatarColor: m.sender_id === state.user.id ? state.user.avatar_color : avatarColor });
      renderMessagesInto('chat-messages', data.messages, htmlFn);
      chatLastId = data.messages.length ? data.messages[data.messages.length - 1].id : 0;

      wireChatCompose({
        mentionAutocomplete: false,
        onSend: async (content) => {
          await api('dm.php', { method: 'POST', body: { recipient_id: userId, content } });
        },
      });
      chatPollTimer = setInterval(async () => {
        if (state.currentView !== 'chat' || chatSubTab !== 'personal' || dmActiveUserId !== userId) { stopChatPolling(); return; }
        try {
          const res = await api(`dm.php?with=${userId}&after_id=${chatLastId}`);
          if (res.messages.length) {
            appendMessagesInto('chat-messages', res.messages, htmlFn);
            chatLastId = res.messages[res.messages.length - 1].id;
          }
        } catch (err) { /* silent — next poll retries */ }
      }, 8000);
    } catch (err) {
      const el = document.getElementById('chat-messages');
      if (el) {
        el.innerHTML = `<div class="dm-directory-error">
          <p>Couldn't load this conversation: ${esc(err.message)}</p>
          <button type="button" class="btn btn-ghost" id="dm-thread-retry">Try again</button>
        </div>`;
        const retryBtn = document.getElementById('dm-thread-retry');
        if (retryBtn) retryBtn.addEventListener('click', () => openDmThread(userId, name, avatarColor));
      }
    }
  }

  // ---- shared compose box wiring (Send button + optional @mention autocomplete) ----
  function wireChatCompose({ onSend, mentionAutocomplete = true }) {
    const form = document.getElementById('chat-compose');
    const input = document.getElementById('chat-input');
    const suggestBox = document.getElementById('mention-suggest');

    function currentMentionQuery() {
      if (!mentionAutocomplete) return null;
      const upToCursor = input.value.slice(0, input.selectionStart);
      const match = upToCursor.match(/@([A-Za-z0-9_.' -]{0,50})$/);
      return match ? match[1] : null;
    }
    function closeSuggestions() {
      if (!suggestBox) return;
      suggestBox.classList.add('hidden');
      suggestBox.innerHTML = '';
    }
    function showSuggestions(query) {
      const q = query.toLowerCase();
      const matches = chatDirectory.filter(m => m.name.toLowerCase().includes(q)).slice(0, 6);
      if (!matches.length) { closeSuggestions(); return; }
      suggestBox.innerHTML = matches.map(m => `
        <div class="mention-suggest-item" data-name="${esc(m.name)}">
          <span class="avatar" style="background:${esc(m.avatar_color)}">${esc(initials(m.name))}</span>
          <div><div>${esc(m.name)}</div>${personMetaHtml(m)}</div>
        </div>`).join('');
      suggestBox.classList.remove('hidden');
      suggestBox.querySelectorAll('.mention-suggest-item').forEach(item => {
        item.addEventListener('click', () => insertMention(item.dataset.name));
      });
    }
    function insertMention(name) {
      const upToCursor = input.value.slice(0, input.selectionStart);
      const afterCursor = input.value.slice(input.selectionStart);
      const newUpToCursor = upToCursor.replace(/@([A-Za-z0-9_.' -]{0,50})$/, '@' + name + ' ');
      input.value = newUpToCursor + afterCursor;
      const newPos = newUpToCursor.length;
      input.focus();
      input.setSelectionRange(newPos, newPos);
      closeSuggestions();
    }

    if (mentionAutocomplete) {
      input.addEventListener('input', () => {
        const q = currentMentionQuery();
        if (q !== null) showSuggestions(q); else closeSuggestions();
      });
      input.addEventListener('keydown', e => { if (e.key === 'Escape') closeSuggestions(); });
      input.addEventListener('blur', () => setTimeout(closeSuggestions, 150));
    }

    let sending = false;
    const sendButton = form.querySelector('button[type="submit"]');
    form.addEventListener('submit', async e => {
      e.preventDefault();
      if (sending) return; // Duplicate clicks/Enter must not create duplicate messages.
      const content = input.value.trim();
      if (!content) return;
      sending = true;
      if (sendButton) sendButton.disabled = true;
      try {
        try {
          await onSend(content);
        } catch (err) {
          // Keep the draft intact on HTTP 429, network failure, or validation error.
          if (form.isConnected) toast(err.message, true);
          return;
        }
        // The user might have switched rooms while the request was in flight.
        if (!form.isConnected) return;
        // Preserve any new draft typed while the previous send was in flight.
        if (input.value.trim() === content) input.value = '';
        closeSuggestions();
        try {
          // Refresh is best-effort after a confirmed send; a failed read-back
          // must not mislead the user into retrying an already saved message.
          if (chatSubTab === 'personal' && dmActiveUserId) {
            const res = await api(`dm.php?with=${dmActiveUserId}&after_id=${chatLastId}`);
            if (res.messages.length) {
              const htmlFn = (m) => chatMessageHtml(m, { name: state.user.name, avatarColor: state.user.avatar_color });
              appendMessagesInto('chat-messages', res.messages, htmlFn);
              chatLastId = res.messages[res.messages.length - 1].id;
            }
          } else {
            const scope = chatSubTab === 'org' ? 'org' : 'team';
            const qs = scope === 'org' ? 'scope=org' : `scope=team&team_id=${state.currentTeamId}`;
            const res = await api(`chat.php?${qs}&after_id=${chatLastId}`);
            if (res.messages.length) {
              appendMessagesInto('chat-messages', res.messages, chatMessageHtml);
              chatLastId = res.messages[res.messages.length - 1].id;
            }
          }
        } catch (err) {
          if (form.isConnected) toast('Message sent. It will appear on the next refresh.');
        }
      } finally {
        sending = false;
        if (sendButton) sendButton.disabled = false;
      }
    });
  }

  // ---------------------------------------------------------------
  // TEAM ROSTER (read view for manager/member; admin uses Users page instead)
  // ---------------------------------------------------------------
  async function renderRoster() {
    const container = document.getElementById('view-container');
    if (!state.currentTeamId) { container.innerHTML = emptyTeamState(); return; }
    container.innerHTML = '<p>Loading…</p>';
    let data;
    try {
      data = await api('users.php');
    } catch (err) {
      container.innerHTML = `<div class="load-error-box">
        <p>Couldn't load team members: ${esc(err.message)}</p>
        <button type="button" class="btn btn-ghost" id="roster-retry">Try again</button>
      </div>`;
      const retryBtn = document.getElementById('roster-retry');
      if (retryBtn) retryBtn.addEventListener('click', renderRoster);
      return;
    }
    const canRemove = iHave(['manager']);
    container.innerHTML = data.users.map(m => `
      <div class="member-row">
        <div class="member-info">
          <span class="avatar" style="background:${esc(m.avatar_color)}">${esc(initials(m.name))}</span>
          <div><strong>${esc(m.name)}</strong>${m.email ? `<div style="font-size:12px;color:var(--text-faint);">${esc(m.email)}</div>` : ''}${m.team_name ? `<div class="person-meta">${esc(m.team_name)}</div>` : ''}</div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          ${m.roles && m.roles.length ? `<span class="role-badge">${esc(rolesLabel(m.roles))}</span>` : ''}
          ${canRemove && m.id !== state.user.id ? `<button class="btn btn-ghost remove-from-team-btn" data-user="${m.id}" data-name="${esc(m.name)}" style="color:var(--danger);">Remove from team</button>` : ''}
        </div>
      </div>`).join('');

    container.querySelectorAll('.remove-from-team-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm(`Remove ${btn.dataset.name} from this team? They'll keep their account but lose access to this team's tasks and chat.`)) return;
        try {
          await api('users.php', { method: 'PUT', body: { id: Number(btn.dataset.user), team_id: null } });
          toast('Removed from team');
          renderRoster();
        } catch (err) { toast(err.message, true); }
      });
    });
  }

  // ---------------------------------------------------------------
  // ACTIVITY VIEW
  // ---------------------------------------------------------------
  async function renderActivity(teamId) {
    const container=document.getElementById('view-container');
    const canExport=iHave(['administrator','manager']);
    container.innerHTML=`<div class="activity-filters">
      <label for="activity-action">Action</label><input id="activity-action" placeholder="e.g. task_updated" maxlength="50">
      <label for="activity-actor">User ID</label><input id="activity-actor" type="number" min="1" placeholder="All users">
      <button type="button" class="btn btn-ghost" id="activity-filter">Filter</button>
      ${canExport?'<button type="button" class="btn btn-ghost" id="activity-export">Export CSV</button>':''}
    </div><div id="activity-results" aria-live="polite">Loading activity…</div>
    <button type="button" class="btn btn-ghost hidden" id="activity-more">Load more</button>`;
    let cursor=null, query='', actor='';
    const params=()=>{
      const q=new URLSearchParams({team_id:String(teamId||0),limit:'30'});
      if(query)q.set('action',query);
      if(actor)q.set('user_id',actor);
      return q;
    };
    async function load(append=false){
      const list=container.querySelector('#activity-results');
      const more=container.querySelector('#activity-more');more.disabled=true;
      if(!append)list.textContent='Loading activity…';
      try{
        const q=params();if(append&&cursor)q.set('before_id',String(cursor));
        const data=await api('activity.php?'+q.toString());
        const html=(data.activity||[]).map(a=>`<div class="activity-item">
          <span class="avatar" style="background:${esc(a.avatar_color)}">${esc(initials(a.name))}</span>
          <div><div class="activity-text"><strong>${esc(a.name)}</strong> ${esc(readableAction(a.action))}${a.detail?' — '+esc(a.detail):''}${a.team_name?` <span class="muted-label">(${esc(a.team_name)})</span>`:''}</div>
          <div class="activity-time">${esc(a.created_at)}${a.task_id?' · Task #'+Number(a.task_id):''}</div></div></div>`).join('');
        if(append)list.insertAdjacentHTML('beforeend',html);
        else list.innerHTML=html||'<div class="empty-state"><h3>No matching activity</h3></div>';
        cursor=data.next_before_id;
        more.classList.toggle('hidden',!cursor);more.disabled=false;
      }catch(e){list.textContent=e.message;more.classList.add('hidden');}
    }
    container.querySelector('#activity-filter').addEventListener('click',()=>{
      query=container.querySelector('#activity-action').value.trim();
      actor=container.querySelector('#activity-actor').value.trim();cursor=null;load();
    });
    container.querySelector('#activity-more').addEventListener('click',()=>load(true));
    container.querySelector('#activity-export')?.addEventListener('click',()=>{
      const q=params();window.location.href='api/activity_export.php?'+q.toString();
    });
    await load();
  }

  function readableAction(action) {
    const map = {
      task_created: 'created a task', task_updated: 'updated a task', task_deleted: 'deleted a task',
      comment_added: 'commented', list_created: 'created a list', list_deleted: 'deleted a list',
      team_created: 'created a team', team_updated: 'updated a team', team_deleted: 'deleted a team',
      user_created: 'created an account for', user_updated: 'updated an account', user_deactivated: 'deactivated an account',
      chat_message: 'posted in team chat', settings_updated: 'updated Advanced Settings', chat_cleared: 'cleared a chat',
      user_deleted: 'permanently deleted an account', email_changed: 'changed their email',
    };
    return map[action] || action;
  }

  // ---------------------------------------------------------------
  // ADMIN: TEAMS
  // ---------------------------------------------------------------
  async function renderTeamsAdmin() {
    const container = document.getElementById('view-container');
    document.getElementById('topbar-actions').innerHTML = `<button class="btn btn-primary" id="new-team-btn">+ New team</button>`;
    document.getElementById('new-team-btn').addEventListener('click', openNewTeamPrompt);

    container.innerHTML = '<p>Loading…</p>';
    let teamData, userData;
    try {
      [teamData, userData] = await Promise.all([api('teams.php'), api('users.php')]);
    } catch (err) {
      container.innerHTML = `<div class="load-error-box">
        <p>Couldn't load teams: ${esc(err.message)}</p>
        <button type="button" class="btn btn-ghost" id="teams-admin-retry">Try again</button>
      </div>`;
      const retryBtn = document.getElementById('teams-admin-retry');
      if (retryBtn) retryBtn.addEventListener('click', renderTeamsAdmin);
      return;
    }
    const managers = userData.users.filter(u => personHas(u, ['manager', 'administrator']));

    container.innerHTML = teamData.teams.map(t => `
      <div class="member-row">
        <div class="member-info">
          <span class="space-dot" style="background:${esc(t.color)}"></span>
          <div><strong>${esc(t.name)}</strong><div style="font-size:12px;color:var(--text-faint);">Manager: ${esc(t.manager_name || 'Unassigned')} · ${t.member_count} member(s)</div></div>
        </div>
        <div style="display:flex;gap:8px;">
          <button class="btn btn-ghost assign-manager-btn" data-team="${t.id}">Assign manager</button>
          <button class="btn btn-ghost delete-team-btn" data-team="${t.id}" style="color:var(--danger);">Delete</button>
        </div>
      </div>`).join('');

    container.querySelectorAll('.assign-manager-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        promptModal('Assign manager', `
          <select id="p-manager" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;">
            <option value="">Unassigned</option>
            ${managers.map(m => `<option value="${m.id}">${esc(m.name)}</option>`).join('')}
          </select>
        `, async () => {
          const managerId = document.getElementById('p-manager').value || null;
          await api('teams.php', { method: 'PUT', body: { id: Number(btn.dataset.team), manager_id: managerId } });
          renderTeamsAdmin();
        });
      });
    });
    container.querySelectorAll('.delete-team-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this team and all its lists/tasks? This cannot be undone.')) return;
        try {
          await api('teams.php', { method: 'DELETE', body: { id: Number(btn.dataset.team) } });
          renderTeamsAdmin();
        } catch (err) { toast(err.message, true); }
      });
    });
  }

  function openNewTeamPrompt() {
    promptModal('New team', `<input id="p-team-name" placeholder="Team name" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;margin-bottom:8px;">
      <input id="p-team-color" type="color" value="#7B68EE">`, async () => {
      const name = document.getElementById('p-team-name').value.trim();
      if (!name) return;
      const color = document.getElementById('p-team-color').value;
      await api('teams.php', { method: 'POST', body: { name, color } });
      const data = await api('me.php'); state.teams = data.teams;
      const sel = document.getElementById('team-select');
      sel.innerHTML = state.teams.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
      renderTeamsAdmin();
    });
  }

  // ---------------------------------------------------------------
  // ADMIN: USERS
  // ---------------------------------------------------------------
  // ---------------------------------------------------------------
  // ADMIN: ADVANCED SETTINGS — SMTP config + notification checklist
  // ---------------------------------------------------------------
  const NOTIFY_CHECKLIST = [
    { key: 'notify_task_created', label: 'A new task is created' },
    { key: 'notify_task_assigned', label: 'Someone is assigned to a task' },
    { key: 'notify_task_completed', label: 'A task is marked complete' },
    { key: 'notify_task_updated', label: 'A task is updated (title, dates, priority, etc.)' },
    { key: 'notify_due_reminder', label: 'A task is due today or became overdue (requires daily reminder cron)' },
    { key: 'notify_comment_added', label: 'A comment is added to a task you\u2019re assigned to' },
    { key: 'notify_mentioned', label: 'You are @mentioned in a comment' },
    { key: 'notify_new_message', label: 'Someone sends a direct message' },
  ];

  // ---------------------------------------------------------------
  // MY ACCOUNT — every role: change your own email, choose which
  // notification categories you personally want emailed to you.
  // ---------------------------------------------------------------
  const CATEGORY_META = [
    { key: 'messages', label: 'Messages', hint: 'Direct messages and comments on tasks you\u2019re assigned to' },
    { key: 'new_tasks', label: 'New tasks', hint: 'Created, assigned, updated, or marked complete' },
    { key: 'mentions', label: 'Mentions', hint: 'Someone @mentions you in a comment or chat' },
  ];

  function categoryChecklistHtml(prefix, data, checkboxClass) {
    return CATEGORY_META.map(item => {
      const dataKey = prefix + '_' + item.key;
      return `<label class="settings-toggle-row">
        <input type="checkbox" class="${checkboxClass}" data-key="${dataKey}" ${data[dataKey] ? 'checked' : ''}>
        <span><strong>${esc(item.label)}</strong><br><span class="person-meta">${esc(item.hint)}</span></span>
      </label>`;
    }).join('');
  }

  async function renderMyAccount() {
    const container = document.getElementById('view-container');
    container.innerHTML = '<p>Loading…</p>';
    let data;
    try {
      data = await api('profile.php');
    } catch (err) {
      container.innerHTML = `<div class="load-error-box">
        <p>Couldn't load your account: ${esc(err.message)}</p>
        <button type="button" class="btn btn-ghost" id="my-account-retry">Try again</button>
      </div>`;
      const retryBtn = document.getElementById('my-account-retry');
      if (retryBtn) retryBtn.addEventListener('click', renderMyAccount);
      return;
    }

    container.innerHTML = `
      <div class="settings-box" style="margin-bottom:20px;">
        <div class="settings-box-header">
          <h2>Email address</h2>
          <p>Current: <strong>${esc(data.email)}</strong></p>
        </div>
        <form id="email-change-form">
          <div class="field-row">
            <div class="field-group"><label>New email</label><input type="email" id="acc-new-email" placeholder="you@example.com"></div>
            <div class="field-group"><label>Current password</label><input type="password" id="acc-current-pw" placeholder="Confirm it's you"></div>
          </div>
          <button type="submit" class="btn btn-primary">Update email</button>
        </form>
      </div>

      <div class="settings-box" style="margin-bottom:20px;">
        <div class="settings-box-header">
          <h2>Email notifications</h2>
          <p>Choose which categories you want emailed to you. Your Administrator controls whether email is enabled organization-wide.</p>
        </div>
        <div class="settings-checklist">${categoryChecklistHtml('email', data, 'email-notify-check')}</div>
        <div class="settings-actions">
          <button class="btn btn-primary" id="my-notify-save">Save preferences</button>
        </div>
        <p class="settings-note" id="my-account-note"></p>
      </div>

      <div class="settings-box">
        <div class="settings-box-header">
          <h2>Browser notifications</h2>
          <p>Get a desktop/mobile browser notification while TaskFlow is open in a tab (even in the background). This won't wake a fully closed browser.</p>
        </div>
        <label class="settings-toggle-row" style="margin-bottom:14px;">
          <input type="checkbox" id="browser-notify-toggle" ${data.browser_enabled ? 'checked' : ''}>
          <span><strong>Enable browser notifications</strong><br><span class="person-meta" id="browser-notify-status">${browserNotifyStatusText(data.browser_enabled)}</span></span>
        </label>
        <div class="settings-checklist" id="browser-category-list" style="${data.browser_enabled ? '' : 'opacity:0.5;pointer-events:none;'}">${categoryChecklistHtml('browser', data, 'browser-notify-check')}</div>
        <div class="settings-actions">
          <button class="btn btn-primary" id="browser-notify-save">Save preferences</button>
        </div>
        <p class="settings-note" id="browser-account-note"></p>
      </div>
      <div id="account-security-panel" class="settings-box" style="margin-top:20px;">
        <div class="settings-box-header"><h2>Account security</h2><p>Loading security status…</p></div>
      </div>`;

    document.getElementById('browser-notify-toggle').addEventListener('change', async e => {
      const statusEl = document.getElementById('browser-notify-status');
      const listEl = document.getElementById('browser-category-list');
      if (e.target.checked) {
        if (!('Notification' in window)) {
          toast('This browser does not support notifications.', true);
          e.target.checked = false;
          return;
        }
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
          toast('Notifications were blocked — allow them in your browser settings to enable this.', true);
          e.target.checked = false;
          return;
        }
      }
      try {
        await api('profile.php', { method: 'PUT', body: { browser_enabled: e.target.checked } });
        statusEl.textContent = browserNotifyStatusText(e.target.checked);
        listEl.style.opacity = e.target.checked ? '1' : '0.5';
        listEl.style.pointerEvents = e.target.checked ? 'auto' : 'none';
        if (e.target.checked) startNotificationPolling(); else stopNotificationPolling();
        toast(e.target.checked ? 'Browser notifications on' : 'Browser notifications off');
      } catch (err) {
        toast(err.message, true);
        e.target.checked = !e.target.checked;
      }
    });

    document.getElementById('email-change-form').addEventListener('submit', async e => {
      e.preventDefault();
      const new_email = document.getElementById('acc-new-email').value.trim();
      const current_password = document.getElementById('acc-current-pw').value;
      if (!new_email || !current_password) { toast('Enter a new email and your current password.', true); return; }
      try {
        const result = await api('profile.php', { method: 'PUT', body: { new_email, current_password } });
        if (result.csrf_token) state.csrf = result.csrf_token;
        toast('Email updated');
        renderMyAccount();
      } catch (err) { toast(err.message, true); }
    });

    document.getElementById('my-notify-save').addEventListener('click', async () => {
      const body = {};
      document.querySelectorAll('.email-notify-check').forEach(cb => { body[cb.dataset.key] = cb.checked; });
      const note = document.getElementById('my-account-note');
      try {
        await api('profile.php', { method: 'PUT', body });
        note.textContent = 'Preferences saved.';
        note.className = 'settings-note settings-note-ok';
      } catch (err) {
        note.textContent = err.message;
        note.className = 'settings-note settings-note-error';
      }
    });

    document.getElementById('browser-notify-save').addEventListener('click', async () => {
      const body = {};
      document.querySelectorAll('.browser-notify-check').forEach(cb => { body[cb.dataset.key] = cb.checked; });
      const note = document.getElementById('browser-account-note');
      try {
        await api('profile.php', { method: 'PUT', body });
        note.textContent = 'Preferences saved.';
        note.className = 'settings-note settings-note-ok';
      } catch (err) {
        note.textContent = err.message;
        note.className = 'settings-note settings-note-error';
      }
    });
    renderSecurityPanel();
  }

  // ---------------------------------------------------------------
  // ACCOUNT SECURITY: authenticator MFA, one-time recovery codes and
  // all-session revocation. Secret/recovery code is never reloaded.
  // ---------------------------------------------------------------
  function presentRecoveryCodes(codes) {
    const panel = document.getElementById('account-security-panel');
    if (!panel) return;
    panel.innerHTML = `<div class="settings-box-header"><h2>Save your recovery codes now</h2>
      <p>Each code works once. Store these offline in a safe place. They cannot be viewed again.</p></div>
      <pre class="security-code-list">${codes.map(esc).join('\n')}</pre>
      <div class="security-actions"><button class="btn btn-primary" id="security-codes-done">I saved these codes</button></div>`;
    document.getElementById('security-codes-done').onclick = renderSecurityPanel;
  }

  async function renderSecurityPanel() {
    const panel = document.getElementById('account-security-panel');
    if (!panel) return;
    let data;
    try { data = await api('mfa.php'); }
    catch (e) { panel.innerHTML = `<p role="alert">Security settings unavailable: ${esc(e.message)}</p>`; return; }
    if (!document.getElementById('account-security-panel')) return;
    panel.innerHTML = `<div class="settings-box-header"><h2>Sign-in protection</h2>
      <p>${data.enabled ? 'Authenticator MFA is enabled. ' + data.recovery_remaining + ' unused recovery codes remain.' : 'Add an authenticator app to protect your account when a password is stolen.'}</p></div>
      <div class="security-actions">
        ${data.enabled ? '<button class="btn btn-primary" id="security-regenerate">New recovery codes</button><button class="btn btn-ghost" id="security-disable">Disable MFA</button>' : '<button class="btn btn-primary" id="security-enable">Enable two-factor authentication</button>'}
        <button class="btn btn-ghost" id="security-revoke">Sign out other devices</button>
      </div>
      <p class="settings-note">Security changes require your password. MFA changes revoke remembered devices.</p>`;
    const enable = document.getElementById('security-enable');
    if (enable) enable.onclick = () => {
      promptModal('Set up an authenticator app', `<p>Confirm your password to create a new private authenticator key.</p><input id="sec-password" type="password" autocomplete="current-password" placeholder="Current password" class="security-input">`, async () => {
        const response = await api('mfa.php', { method:'POST', body:{ action:'begin', current_password:document.getElementById('sec-password').value } });
        const secret = response.secret;
        // Wait for the generic modal to close before replacing account content.
        setTimeout(() => {
          const target = document.getElementById('account-security-panel');
          if (!target) return;
          target.innerHTML = `<div class="settings-box-header"><h2>Connect your authenticator</h2>
            <p>Add this setup key manually in Google Authenticator, Microsoft Authenticator or another TOTP app. Type the current six-digit code to finish.</p></div>
            <div class="security-secret" aria-label="Authenticator setup key">${esc(secret)}</div>
            <form id="security-confirm-form"><label>Six-digit verification code<input class="security-input" id="sec-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required></label>
            <div class="security-actions"><button class="btn btn-primary" type="submit">Enable MFA</button><button class="btn btn-ghost" type="button" id="security-cancel-setup">Cancel</button></div></form>`;
          document.getElementById('security-cancel-setup').onclick = renderSecurityPanel;
          document.getElementById('security-confirm-form').onsubmit = async e => {
            e.preventDefault();
            try {
              const result = await api('mfa.php', {method:'POST', body:{action:'confirm',code:document.getElementById('sec-code').value.trim()}});
              state.csrf = result.csrf_token;
              presentRecoveryCodes(result.recovery_codes);
              toast('Two-factor authentication enabled');
            } catch (err) { toast(err.message,true); }
          };
        }, 0);
      });
    };
    const configMfaAction = (buttonId, title, action, warning) => {
      const button = document.getElementById(buttonId);
      if (!button) return;
      button.onclick = () => promptModal(title, `<p>${esc(warning)}</p>
        <input type="password" class="security-input" id="sec-password" autocomplete="current-password" placeholder="Current password">
        <input type="text" class="security-input" id="sec-code" autocomplete="one-time-code" placeholder="Authenticator or recovery code">`, async () => {
          const result = await api('mfa.php', {method:'POST',body:{action, current_password:document.getElementById('sec-password').value, code:document.getElementById('sec-code').value.trim()}});
          if (result.csrf_token) state.csrf = result.csrf_token;
          if (result.recovery_codes) setTimeout(() => presentRecoveryCodes(result.recovery_codes), 0);
          else setTimeout(renderSecurityPanel, 0);
          toast(action === 'disable' ? 'MFA disabled' : 'Security updated');
      });
    };
    configMfaAction('security-regenerate', 'Generate fresh recovery codes', 'regenerate', 'Old unused recovery codes will stop working. Save your new ones.');
    configMfaAction('security-disable', 'Disable two-factor authentication', 'disable', 'Your account will no longer require an authenticator. Other devices will be signed out.');
    document.getElementById('security-revoke').onclick = () => promptModal('Sign out other devices', `
      <p>All other sessions and remembered devices will become invalid. This browser stays signed in.</p>
      <input type="password" id="sec-password" autocomplete="current-password" class="security-input" placeholder="Current password">`, async () => {
      const result = await api('mfa.php', {method:'POST',body:{action:'revoke_sessions',current_password:document.getElementById('sec-password').value}});
      state.csrf = result.csrf_token;
      toast('Other devices signed out');
    });
  }

  // ---------------------------------------------------------------
  // TASK TIMER: server is authoritative; the 1-second interval updates display only.
  // MANUAL WORKLOGS: no polling, no external libraries or client identity.
  // ---------------------------------------------------------------
  function formatMinutes(minutes) {
    const value = Math.max(0, Math.floor(Number(minutes) || 0));
    return `${Math.floor(value / 60)}h ${String(value % 60).padStart(2,'0')}m`;
  }

  let activeTimer = null;
  let timerClockOffset = 0;
  function elapsedTimerText() {
    if (!activeTimer) return '00:00:00';
    const seconds = Math.max(0, Math.floor((Date.now() + timerClockOffset - Date.parse(activeTimer.started_at)) / 1000));
    const padded = n => String(n).padStart(2, '0');
    return `${padded(Math.floor(seconds / 3600))}:${padded(Math.floor(seconds / 60) % 60)}:${padded(seconds % 60)}`;
  }
  function tickTaskTimer() {
    document.querySelectorAll('.live-timer-clock').forEach(el => { el.textContent = elapsedTimerText(); });
  }
  setInterval(tickTaskTimer, 1000);
  async function refreshActiveTimer() {
    const result = await api('timer.php');
    timerClockOffset = Date.parse(result.server_now) - Date.now();
    activeTimer = result.active;
    renderTaskTimerWidget();
    renderReportTimerWidget();
    tickTaskTimer();
  }
  async function runTimerAction(action, taskId = null, note = '') {
    const payload = {action};
    if (action === 'start') payload.task_id = Number(taskId);
    if (action === 'stop') payload.note = note;
    const result = await api('timer.php', {method:'POST', body:payload});
    await refreshActiveTimer();
    if (action === 'stop') toast(`Saved ${formatMinutes(result.minutes)} of tracked time`);
    else toast(action === 'start' ? 'Timer started' : 'Timer discarded');
    return result;
  }
  function renderTaskTimerWidget() {
    const widget = document.getElementById('task-timer-widget');
    if (!widget) return;
    const taskId = Number(widget.dataset.taskId);
    const canLog = widget.dataset.canLog === 'true';
    if (!activeTimer) {
      widget.innerHTML = canLog ? '<button type="button" class="btn btn-primary" data-timer-action="start">▶ Start timer</button>' : '';
    } else {
      const onTask = Number(activeTimer.task_id) === taskId;
      widget.innerHTML = `<div class="timer-panel"><div><span class="timer-live-dot"></span><strong>${onTask ? 'Timer running for this task' : 'Timer running elsewhere'}</strong>
        <p class="timer-subtitle">${esc(activeTimer.title)}</p><span class="live-timer-clock" aria-label="Elapsed timer">${elapsedTimerText()}</span></div>
        <div class="timer-controls">${onTask && activeTimer.can_stop ? '<input type="text" class="timer-note" maxlength="300" placeholder="Stop note (optional)" aria-label="Time log note"><button type="button" class="btn btn-primary" data-timer-action="stop">■ Stop & save</button>' : ''}
        ${!onTask && activeTimer.task_id ? '<button type="button" class="btn btn-ghost" data-timer-action="open">Open task</button>' : ''}
        <button type="button" class="btn btn-ghost" data-timer-action="discard">Discard</button></div></div>`;
    }
    widget.querySelectorAll('[data-timer-action]').forEach(btn => btn.addEventListener('click', async () => {
      const action = btn.dataset.timerAction;
      if (action === 'open') { openTaskModal(activeTimer.task_id); return; }
      if (action === 'discard' && !confirm('Discard this running timer without saving its time?')) return;
      btn.disabled = true;
      try {
        await runTimerAction(action, taskId, widget.querySelector('.timer-note')?.value || '');
        if (action === 'stop') await loadTaskWorklog(taskId);
      } catch (e) { toast(e.message, true); btn.disabled = false; }
    }));
  }
  function renderReportTimerWidget() {
    for (const widget of document.querySelectorAll('.report-active-timer')) {
      if (!activeTimer) {
        widget.innerHTML = '<p class="dependency-help">No timer running. Open a task to start tracking.</p>';
      } else {
        widget.innerHTML = `<div class="timer-panel"><div><span class="timer-live-dot"></span><strong>${esc(activeTimer.title)}</strong>
          <p class="timer-subtitle">Active timer · server recorded</p><span class="live-timer-clock">${elapsedTimerText()}</span></div>
          <div class="timer-controls">${activeTimer.can_stop ? '<button class="btn btn-primary" data-timer-action="stop">■ Stop & save</button>' : ''}
          ${activeTimer.task_id ? '<button class="btn btn-ghost" data-timer-action="open">Open task</button>' : ''}
          <button class="btn btn-ghost" data-timer-action="discard">Discard</button></div></div>`;
      }
      widget.querySelectorAll('[data-timer-action]').forEach(btn => btn.addEventListener('click', async () => {
        const action = btn.dataset.timerAction;
        if (action === 'open') { openTaskModal(activeTimer.task_id); return; }
        if (action === 'discard' && !confirm('Discard this timer without saving?')) return;
        btn.disabled = true;
        try {
          await runTimerAction(action);
          if (action === 'stop') {
            if (state.currentView === 'time-reports') {timeReportCursor=null;loadTimeReport();}
            if (state.currentView === 'timesheet') loadTimesheet();
          }
        } catch(e) {toast(e.message,true); btn.disabled=false;}
      }));
    }
  }

  async function loadTaskWorklog(taskId) {
    const root = document.getElementById('tm-time-panel');
    if (!root || Number(root.dataset.taskId) !== Number(taskId)) return;
    try {
      const result = await api('worklog.php?task_id=' + taskId);
      const panel = document.getElementById('tm-time-panel');
      if (!panel || Number(panel.dataset.taskId) !== Number(taskId)) return;
      const today = new Date().toISOString().slice(0, 10);
      panel.innerHTML = `<div class="worklog-summary"><strong>${formatMinutes(result.minutes_total)}</strong><span>${result.can_view_team ? 'Logged by this task’s team' : 'Your logged time'}</span></div>
        <div id="task-timer-widget" data-task-id="${Number(taskId)}" data-can-log="${result.can_log ? 'true' : 'false'}"></div>
        <div class="worklog-entries">${result.entries.map(e => `<div class="worklog-entry">
          <span class="worklog-duration">${formatMinutes(e.minutes)}</span>
          <span class="worklog-detail"><strong>${esc(e.name)}</strong> · ${fmtDate(e.work_date)} ${e.note ? `<small>${esc(e.note)}</small>` : ''} ${['submitted','approved'].includes(e.review_status) ? '<small>Week locked for review</small>' : ''}</span>
          ${Number(e.user_id) === Number(state.user.id) && !['submitted','approved'].includes(e.review_status) ? `<button type="button" class="worklog-remove" data-entry-id="${Number(e.id)}" aria-label="Remove time entry">Remove</button>` : ''}
        </div>`).join('') || '<p class="dependency-empty">No time has been logged yet.</p>'}</div>
        ${result.can_log ? `<form id="tm-log-time" class="worklog-form">
          <label>Minutes <input type="number" id="tm-log-minutes" min="1" max="720" step="1" required placeholder="45"></label>
          <label>Work date (UTC) <input type="date" id="tm-log-date" value="${today}" max="${today}" required></label>
          <label>Notes (optional) <input type="text" id="tm-log-note" maxlength="300" placeholder="What did you work on?"></label>
          <button type="submit" class="btn btn-ghost">Log time</button>
        </form>` : `<p class="dependency-help">${result.week_locked ? 'Your current week is submitted or approved. You can log more time after a reviewer requests changes.' : 'Only task creators, assignees and managers can log time.'}</p>`}`;
      await refreshActiveTimer();
      panel.querySelector('#tm-log-time')?.addEventListener('submit', async event => {
        event.preventDefault();
        const form=event.currentTarget; const submit=form.querySelector('button[type="submit"]');
        submit.disabled=true;
        try {
          await api('worklog.php',{method:'POST',body:{task_id:Number(taskId),minutes:Number(form.querySelector('#tm-log-minutes').value),
            work_date:form.querySelector('#tm-log-date').value,note:form.querySelector('#tm-log-note').value}});
          toast('Time logged');
          await loadTaskWorklog(taskId);
        } catch(e) { toast(e.message, true);submit.disabled=false; }
      });
      panel.querySelectorAll('[data-entry-id]').forEach(btn => btn.addEventListener('click', async () => {
        if (!confirm('Remove your time entry?')) return;
        btn.disabled=true;
        try {
          await api('worklog.php',{method:'DELETE',body:{task_id:Number(taskId),id:Number(btn.dataset.entryId)}});
          toast('Entry removed');await loadTaskWorklog(taskId);
        } catch(e) { toast(e.message, true);btn.disabled=false; }
      }));
    } catch(e) {
      const panel = document.getElementById('tm-time-panel');
      if (panel && Number(panel.dataset.taskId) === Number(taskId)) panel.innerHTML = `<p role="alert">Could not load time entries: ${esc(e.message)}</p>`;
    }
  }

  let timeReportCursor = null;
  let timeReportRequestId = 0;
  function utcIso(date) { return date.toISOString().slice(0,10); }
  async function renderTimeReports() {
    timeReportCursor = null;
    const today = new Date();
    const start = new Date(today); start.setUTCDate(start.getUTCDate()-6);
    const container=document.getElementById('view-container');
    const managers = iHave(['administrator','manager']);
    container.innerHTML=`<section class="time-report-intro"><div class="feature-intro"><h2>Time reports</h2>
      <p>Review both manual and timer-based worklogs. Dates use UTC; entries are not verified billable hours.</p></div>
      <div class="report-active-timer" aria-live="polite"></div>
      <form class="time-report-filters" id="time-report-filters">
        <label>From <input type="date" id="report-from" value="${utcIso(start)}" max="${utcIso(today)}" required></label>
        <label>To <input type="date" id="report-to" value="${utcIso(today)}" max="${utcIso(today)}" required></label>
        ${managers ? '<label>Show <select id="report-scope"><option value="mine">My entries</option><option value="team">Team entries</option></select></label>' : ''}
        <button class="btn btn-primary" type="submit">Update report</button>
      </form>
      <div id="time-report-summary" aria-live="polite"></div>
      <div id="time-report-results"></div></section>`;
    container.querySelector('#time-report-filters').addEventListener('submit',e=>{e.preventDefault();timeReportCursor=null;loadTimeReport();});
    await Promise.all([loadTimeReport(), refreshActiveTimer()]);
  }

  async function loadTimeReport() {
    const output=document.getElementById('time-report-results');
    if (!output || state.currentView !== 'time-reports') return;
    const append=timeReportCursor!==null;
    const seq=++timeReportRequestId;
    const params = new URLSearchParams({team_id:String(state.currentTeamId),
      from:document.getElementById('report-from').value,to:document.getElementById('report-to').value,
      scope:document.getElementById('report-scope')?.value || 'mine'});
    if (append) params.set('before_id',String(timeReportCursor));
    if (!append) {output.innerHTML='<p>Loading time report…</p>';document.getElementById('time-report-summary').innerHTML='';}
    try {
      const data=await api('time_report.php?'+params);
      if (state.currentView!=='time-reports' || seq!==timeReportRequestId || !document.getElementById('time-report-results')) return;
      const total=data.totals || {};
      const daily=data.daily || [];
      const highest=Math.max(1,...daily.map(day=>Number(day.minutes)||0));
      document.getElementById('time-report-summary').innerHTML=`
        <div class="time-metrics">
          <div><span>Logged time</span><strong>${formatMinutes(total.minutes)}</strong></div>
          <div><span>Entries</span><strong>${Number(total.entries||0)}</strong></div>
          <div><span>Tasks worked</span><strong>${Number(total.tasks||0)}</strong></div>
        </div>
        <div class="time-chart" aria-label="Minutes logged by work date">${daily.length ? daily.map(day=>`
          <div class="time-chart-day" title="${esc(day.date)}: ${formatMinutes(day.minutes)}">
            <div class="time-chart-column"><div style="height:${Math.max(3,Number(day.minutes)/highest*100)}%"></div></div>
            <small>${fmtDate(day.date)}</small>
          </div>`).join('') : '<p class="dependency-empty">No logged time in this date range.</p>'}</div>`;
      output.querySelector('#time-report-more')?.remove();
      if (!append) output.innerHTML='<h3 class="time-report-heading">Recent entries</h3><div id="time-report-list" class="time-report-list"></div>';
      const list=output.querySelector('#time-report-list');
      if (!append && !data.entries.length) list.innerHTML='<p class="dependency-empty">Nothing logged in this period.</p>';
      if (data.entries.length) {
        const rows=document.createElement('div');
        rows.innerHTML=data.entries.map(e=>`<button type="button" class="time-report-entry" data-task-id="${Number(e.task_id)}">
          <span><strong>${esc(e.title)}</strong><small>${esc(e.name)} · ${esc(e.work_date)} ${e.note ? '· '+esc(e.note) : ''}</small></span>
          <strong>${formatMinutes(e.minutes)}</strong></button>`).join('');
        rows.querySelectorAll('[data-task-id]').forEach(btn=>btn.addEventListener('click',()=>openTaskModal(Number(btn.dataset.taskId))));
        list.appendChild(rows);
      }
      timeReportCursor=data.next_before_id;
      if (timeReportCursor) {
        const more=document.createElement('button'); more.id='time-report-more';more.className='btn btn-ghost';more.textContent='Load more entries';
        more.addEventListener('click',()=>loadTimeReport());output.appendChild(more);
      }
    } catch(e) {
      if (seq===timeReportRequestId) {if (!append) output.innerHTML=`<p role="alert">${esc(e.message)}</p>`; else toast(e.message,true);}
    }
  }

  // ---------------------------------------------------------------
  // WEEKLY TIMESHEET: 7-day UTC windows, permission-scoped server results.
  // ---------------------------------------------------------------
  let timesheetWeek = null;
  let timesheetRequest = 0;
  function mondayUtc(now = new Date()) {
    const date = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
    date.setUTCDate(date.getUTCDate() - ((date.getUTCDay() + 6) % 7));
    return date;
  }
  function shiftTimesheetWeek(days) {
    const d = new Date(timesheetWeek + 'T00:00:00Z');
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0,10);
  }
  async function renderTimesheet() {
    if (!timesheetWeek) timesheetWeek = utcIso(mondayUtc());
    const manager = iHave(['administrator','manager']);
    document.getElementById('view-container').innerHTML = `<section class="timesheet-view">
      <div class="feature-intro"><h2>Weekly timesheet</h2><p>Track weekly hours, submit for review, and export a spreadsheet-safe CSV.</p></div>
      <div class="timesheet-toolbar">
        <button class="btn btn-ghost" type="button" id="timesheet-prev" aria-label="Previous week">← Previous</button>
        <strong id="timesheet-week-label"></strong>
        <button class="btn btn-ghost" type="button" id="timesheet-next" aria-label="Next week">Next →</button>
        ${manager ? `<label class="timesheet-scope">View <select id="timesheet-scope"><option value="mine">My work</option><option value="team">Team work</option></select></label>` : ''}
        <a class="btn btn-primary" id="timesheet-export" href="#" download>↓ Export CSV</a>
      </div><div class="report-active-timer" aria-live="polite"></div>
      <div id="timesheet-review-panel" aria-live="polite"></div>
      <div id="timesheet-results" aria-live="polite"><p>Loading weekly totals…</p></div>
    </section>`;
    document.getElementById('timesheet-prev').onclick = () => {timesheetWeek=shiftTimesheetWeek(-7);loadTimesheet();};
    document.getElementById('timesheet-next').onclick = () => {timesheetWeek=shiftTimesheetWeek(7);loadTimesheet();};
    document.getElementById('timesheet-scope')?.addEventListener('change', () => loadTimesheet());
    await Promise.all([loadTimesheet(), refreshActiveTimer()]);
  }
  async function loadTimesheet() {
    const output=document.getElementById('timesheet-results');
    if (!output || state.currentView !== 'timesheet') return;
    const request=++timesheetRequest;
    const weekDate=new Date(timesheetWeek+'T00:00:00Z');
    const end=new Date(weekDate);end.setUTCDate(end.getUTCDate()+6);
    document.getElementById('timesheet-week-label').textContent=`${fmtDate(timesheetWeek)} – ${fmtDate(utcIso(end))} (UTC)`;
    document.getElementById('timesheet-next').disabled=shiftTimesheetWeek(7)>utcIso(new Date());
    document.getElementById('timesheet-prev').disabled=shiftTimesheetWeek(-7)<utcIso(new Date(Date.now()-365*86400000));
    const params = new URLSearchParams({team_id:String(state.currentTeamId),week:timesheetWeek,scope:document.getElementById('timesheet-scope')?.value||'mine'});
    document.getElementById('timesheet-export').href='api/timesheet.php?'+params+'&format=csv';
    output.innerHTML='<p>Loading weekly totals…</p>';
    loadTimesheetReviews();
    try {
      const data=await api('timesheet.php?'+params);
      if (request!==timesheetRequest || state.currentView!=='timesheet' || !document.getElementById('timesheet-results')) return;
      const totals=data.summary||{};
      const dailyMap=new Map((data.daily||[]).map(x=>[x.date,Number(x.minutes)||0]));
      const days=Array.from({length:7},(_,i)=>{const date=new Date(timesheetWeek+'T00:00:00Z');date.setUTCDate(date.getUTCDate()+i);return utcIso(date);});
      const max=Math.max(1,...days.map(day=>dailyMap.get(day)||0));
      output.innerHTML=`<div class="time-metrics"><div><span>Logged hours</span><strong>${formatMinutes(totals.minutes)}</strong></div>
        <div><span>Time entries</span><strong>${Number(totals.entries||0)}</strong></div>
        <div><span>Tasks worked</span><strong>${Number(totals.tasks||0)}</strong></div>
        <div><span>Contributors</span><strong>${Number(totals.contributors||0)}</strong></div></div>
        <div class="timesheet-days">${days.map((date,i)=>`<div class="timesheet-day"><span>${['Mon','Tue','Wed','Thu','Fri','Sat','Sun'][i]}</span>
          <div class="timesheet-day-bar"><div style="height:${(dailyMap.get(date)||0)>0 ? Math.max(4,(dailyMap.get(date)||0)/max*100) : 0}%"></div></div>
          <strong>${formatMinutes(dailyMap.get(date)||0)}</strong></div>`).join('')}</div>
        <div class="timesheet-results-header"><h3>Work by task</h3><p>${data.limited?'Showing the 100 largest task/member groups. Download CSV for the full week.':'All task/member groups this week.'}</p></div>
        <div class="timesheet-list">${data.rows.length ? data.rows.map(row=>`<button class="timesheet-task" type="button" data-task-id="${Number(row.task_id)}">
          <span><strong>${esc(row.title)}</strong><small>${esc(row.name)} · ${Number(row.entries)} ${Number(row.entries)===1?'entry':'entries'}</small></span>
          <strong>${formatMinutes(row.minutes)}</strong></button>`).join(''):'<p class="dependency-empty">No work logged in this week yet.</p>'}</div>`;
      output.querySelectorAll('[data-task-id]').forEach(btn=>btn.addEventListener('click',()=>openTaskModal(Number(btn.dataset.taskId))));
    } catch(err) {if(request===timesheetRequest) output.innerHTML=`<p role="alert">${esc(err.message)}</p>`;}
  }

  // ---------------------------------------------------------------
  // WEEKLY REVIEWS: member submission, manager queue and audit trail.
  // ---------------------------------------------------------------
  let timesheetReviewRequest=0;
  const timesheetStatusLabel={submitted:'Awaiting review',approved:'Approved',changes_requested:'Changes requested'};
  async function loadTimesheetReviews() {
    const panel=document.getElementById('timesheet-review-panel');
    if (!panel || !state.currentTeamId) return;
    const request=++timesheetReviewRequest;
    const scope=document.getElementById('timesheet-scope')?.value||'mine';
    const params=new URLSearchParams({team_id:String(state.currentTeamId),week:timesheetWeek,mode:scope});
    panel.innerHTML='<section class="approval-panel"><p>Loading review status…</p></section>';
    try {
      const data=await api('timesheet_approvals.php?'+params);
      if (request!==timesheetReviewRequest || state.currentView!=='timesheet' || !document.getElementById('timesheet-review-panel')) return;
      if (scope==='mine') {
        const r=data.review;
        const status=r?.status||'draft';
        const statusText=timesheetStatusLabel[status]||'Not submitted';
        panel.innerHTML=`<section class="approval-panel"><div class="approval-heading"><div><h3>My weekly approval</h3>
          <p>Your time entries stay editable until submitted. Submitted or approved weeks are locked.</p></div>
          <span class="approval-badge approval-${esc(status)}">${esc(statusText)}</span></div>
          ${r ? `<p class="approval-detail">${Number(r.entry_count)} entries · ${formatMinutes(r.total_minutes)} submitted${r.reviewed_by_name ? ` · Reviewed by ${esc(r.reviewed_by_name)}` : ''}</p>`:''}
          ${r?.reviewer_note ? `<p class="approval-note"><strong>Reviewer note:</strong> ${esc(r.reviewer_note)}</p>`:''}
          ${status==='draft'||status==='changes_requested' ? '<button type="button" class="btn btn-primary" id="submit-timesheet">Submit week for review</button>' : ''}
          ${data.events?.length ? `<details class="approval-history"><summary>Review history</summary><ol>${data.events.map(e=>`<li><strong>${esc(e.event_type.replaceAll('_',' '))}</strong> · ${esc(e.actor_name)} · ${esc(e.created_at)}${e.note?`<p>${esc(e.note)}</p>`:''}</li>`).join('')}</ol></details>`:''}</section>`;
        panel.querySelector('#submit-timesheet')?.addEventListener('click', async ev=>{
          if (!confirm('Submit this week for review? You will not be able to edit its time entries until changes are requested.')) return;
          ev.currentTarget.disabled=true;
          try {
            await api('timesheet_approvals.php',{method:'POST',body:{action:'submit',team_id:state.currentTeamId,week:timesheetWeek}});
            toast('Timesheet submitted for review');loadTimesheetReviews();
          } catch(e) {toast(e.message,true);loadTimesheetReviews();}
        });
      } else {
        const rows=data.reviews||[];
        panel.innerHTML=`<section class="approval-panel"><div class="approval-heading"><div><h3>Team approvals</h3>
          <p>Review submitted weekly hours. You cannot approve your own timesheet.</p></div>
          <span class="approval-badge">${rows.filter(r=>r.status==='submitted').length} pending</span></div>
          ${data.limited?'<p class="approval-note">Showing the first 100 submissions for this week.</p>':''}
          <div class="approval-queue">${rows.length ? rows.map(r=>`<div class="approval-row"><div class="approval-row-meta"><strong>${esc(r.member_name)}</strong>
            <span>${Number(r.entry_count)} entries · ${formatMinutes(r.total_minutes)} · ${esc(timesheetStatusLabel[r.status]||r.status)}</span>
            ${r.reviewer_note?`<small>${esc(r.reviewer_note)}</small>`:''}</div>
            ${r.status==='submitted'&&Number(r.member_id)!==Number(state.user.id)?`<div class="approval-actions"><button type="button" class="btn btn-primary" data-review-action="approve" data-review-id="${Number(r.id)}">Approve</button>
              <button type="button" class="btn btn-ghost" data-review-action="request_changes" data-review-id="${Number(r.id)}">Request changes</button></div>`:''}</div>`).join(''):'<p class="dependency-empty">No submissions for this week yet.</p>'}</div></section>`;
        panel.querySelectorAll('[data-review-action]').forEach(btn=>btn.addEventListener('click',async()=>{
          const action=btn.dataset.reviewAction;
          let note='';
          if (action==='request_changes') {
            const answer=prompt('What needs to change? Please explain clearly (up to 500 characters).');
            if(answer===null)return;
            note=answer.trim();
            if (!note || note.length>500) {toast('Please enter a note of 1–500 characters.',true);return;}
          } else if(!confirm('Approve this submitted timesheet?')) return;
          btn.disabled=true;
          try {
            await api('timesheet_approvals.php',{method:'POST',body:{action,review_id:Number(btn.dataset.reviewId),team_id:state.currentTeamId,note}});
            toast(action==='approve'?'Timesheet approved':'Changes requested');loadTimesheetReviews();
          }catch(e){toast(e.message,true);loadTimesheetReviews();}
        }));
      }
    } catch(err) {
      if (request===timesheetReviewRequest && document.getElementById('timesheet-review-panel'))
        panel.innerHTML=`<p class="approval-error" role="alert">${esc(err.message)}</p>`;
    }
  }

  // ---------------------------------------------------------------
  // WORKLOAD: due-week estimates, separately shown from time logged.
  // ---------------------------------------------------------------
  let workloadWeek = null;
  let workloadScope = 'team';
  let workloadOffset = 0;
  function isoUtcMonday(date = new Date()) {
    const utc = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate()));
    utc.setUTCDate(utc.getUTCDate() - ((utc.getUTCDay() + 6) % 7));
    return utc.toISOString().slice(0, 10);
  }
  function moveWorkloadWeek(amount) {
    const value = new Date(workloadWeek + 'T00:00:00Z');
    value.setUTCDate(value.getUTCDate() + 7 * amount);
    workloadWeek = value.toISOString().slice(0, 10);
    renderWorkload();
  }
  async function renderWorkload() {
    const container = document.getElementById('view-container');
    if (!state.currentTeamId) { container.innerHTML = emptyTeamState(); return; }
    if (!workloadWeek) workloadWeek = isoUtcMonday();
    const canManage = iHave(['administrator', 'manager']);
    if (!canManage) workloadScope = 'mine';
    workloadOffset = 0;
    container.innerHTML = `<div class="feature-intro"><h2>Plan a realistic week</h2><p>See estimated open work due this week, alongside team availability and actual time logged.</p></div>
      <section class="workload-toolbar" aria-label="Workload filters">
        <button type="button" class="btn btn-ghost" id="workload-prev" aria-label="Previous week">← Previous</button>
        <label>Week of <input id="workload-week" type="date" value="${workloadWeek}"></label>
        <button type="button" class="btn btn-ghost" id="workload-next" aria-label="Next week">Next →</button>
        ${canManage ? `<label>View <select id="workload-scope"><option value="team" ${workloadScope==='team'?'selected':''}>Whole team</option><option value="mine" ${workloadScope==='mine'?'selected':''}>My work</option></select></label>` : '<span class="workload-scope-label">My work</span>'}
      </section>
      <p class="workload-explain">Open tasks due this UTC Monday-Sunday week are compared with each member's scheduled workdays. Approved half-day leave and team holidays reduce availability without double-counting overlap. Logged time remains separate.</p>
      <div id="workload-results" aria-live="polite"><p>Calculating workload…</p></div>`;
    document.getElementById('workload-prev').addEventListener('click', () => moveWorkloadWeek(-1));
    document.getElementById('workload-next').addEventListener('click', () => moveWorkloadWeek(1));
    document.getElementById('workload-week').addEventListener('change', ev => {
      const value = ev.target.value;
      if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return;
      workloadWeek = isoUtcMonday(new Date(value + 'T00:00:00Z'));
      renderWorkload();
    });
    document.getElementById('workload-scope')?.addEventListener('change', ev => {
      workloadScope = ev.target.value;
      renderWorkload();
    });
    await loadWorkload(false);
  }
  async function loadWorkload(append = false) {
    const target = document.getElementById('workload-results');
    if (!target || state.currentView !== 'workload') return;
    const teamId = state.currentTeamId;
    const requestedWeek = workloadWeek;
    const requestedScope = workloadScope;
    const params = new URLSearchParams({team_id:String(teamId), week:requestedWeek, scope:requestedScope, offset:String(append ? workloadOffset : 0)});
    if (!append) target.innerHTML = '<p>Calculating workload…</p>';
    try {
      const data = await api('workload.php?' + params);
      if (state.currentView !== 'workload' || state.currentTeamId !== teamId || workloadWeek !== requestedWeek || workloadScope !== requestedScope || !document.getElementById('workload-results')) return;
      if (!append) target.innerHTML = '';
      target.querySelector('#workload-more')?.remove();
      if (!data.rows.length && !append) {target.innerHTML='<div class="empty-state"><h3>No team members in this view</h3><p>Switch scope or ask an administrator to assign a team.</p></div>';return;}
      const list = document.createElement('div');
      list.className = 'workload-grid';
      list.innerHTML = data.rows.map(row => {
        const over = row.load_percent === null ? Number(row.planned_minutes)>0 : Number(row.load_percent)>100;
        const progress = row.load_percent===null ? (Number(row.planned_minutes)>0 ? 100 : 0) : Math.max(0,Math.min(100,Number(row.load_percent)));
        return `<article class="workload-card ${over?'workload-over':''}" data-member-id="${Number(row.user_id)}">
          <div class="workload-card-header"><div><span class="workload-avatar">${esc(initials(row.name))}</span><strong>${esc(row.name)}</strong></div><span class="workload-chip ${over?'is-over':''}">${row.load_percent === null ? (Number(row.planned_minutes)>0?'No capacity':'Not scheduled') : row.load_percent+'% planned'}</span></div>
          <div class="workload-hours"><div><small>Due this week</small><strong>${hoursText(row.planned_minutes)}</strong></div><div><small>Available this week</small><strong>${hoursText(row.effective_capacity_minutes ?? row.capacity_minutes)}</strong>${Number(row.approved_leave_weekdays)?`<small class="leave-adjustment">${Number(row.approved_leave_weekdays)} approved leave day(s)</small>`:''}${Number(row.holiday_days)?`<small class="leave-adjustment">${Number(row.holiday_days)} holiday day(s)</small>`:''}<small>${Number(row.scheduled_days ?? 5)} scheduled day(s)</small></div><div><small>Logged this week</small><strong>${hoursText(row.logged_minutes)}</strong></div></div>
          <div class="workload-meter" aria-label="${esc(row.name)}: ${esc(row.load_percent===null?'No capacity set':row.load_percent+' percent of capacity')}" role="img"><span style="width:${progress}%"></span></div>
          <div class="workload-foot"><span>${Number(row.due_tasks)} open due task(s)${Number(row.unestimated_tasks)?' · '+Number(row.unestimated_tasks)+' without estimates':''}</span>
          ${row.can_edit_capacity?`<label>Weekly capacity (h) <input type="number" data-capacity-id="${Number(row.user_id)}" min="0" max="80" step="0.5" value="${Number(row.capacity_minutes)/60}"></label><button type="button" class="btn btn-ghost" data-save-capacity="${Number(row.user_id)}">Save hours</button><details class="workdays-editor"><summary>Scheduled workdays</summary><div class="workdays-options">${['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].map((label,index)=>`<label><input type="checkbox" data-workday="${1<<index}" ${(Number(row.workdays_mask ?? 31)&(1<<index))?'checked':''}>${label}</label>`).join('')}</div><button type="button" class="btn btn-ghost" data-save-schedule="${Number(row.user_id)}">Save workdays</button></details>`:''}</div>
        </article>`;
      }).join('');
      target.appendChild(list);
      list.querySelectorAll('[data-save-capacity]').forEach(btn => btn.addEventListener('click', async () => {
        const id=Number(btn.dataset.saveCapacity);
        const input=list.querySelector(`[data-capacity-id="${id}"]`);
        const hours=Number(input.value);
        if (!input.value.trim() || !Number.isFinite(hours) || hours<0 || hours>80 || !Number.isInteger(hours*60)) {
          toast('Capacity must be between 0 and 80 hours in whole minutes.',true);return;
        }
        btn.disabled=true;
        try {
          await api('workload.php',{method:'POST',body:{action:'set_capacity',team_id:teamId,user_id:id,weekly_minutes:Math.round(hours*60)}});
          toast('Capacity saved');renderWorkload();
        } catch(err) {toast(err.message,true);btn.disabled=false;}
      }));
      list.querySelectorAll('[data-save-schedule]').forEach(btn => btn.addEventListener('click', async () => {
        const id=Number(btn.dataset.saveSchedule);
        const card=btn.closest('.workload-card');
        let mask=0;
        card.querySelectorAll('[data-workday]:checked').forEach(checkbox => {mask |= Number(checkbox.dataset.workday);});
        if (!mask) {toast('Select at least one workday.',true);return;}
        btn.disabled=true;
        try {
          await api('workload.php',{method:'POST',body:{action:'set_schedule',team_id:teamId,user_id:id,workdays_mask:mask}});
          toast('Schedule saved');await loadWorkload(false);
        } catch(error){toast(error.message,true);btn.disabled=false;}
      }));
      workloadOffset=data.next_offset;
      if (workloadOffset !== null) {
        const more=document.createElement('button');more.id='workload-more';more.className='btn btn-ghost';more.textContent='Load more team members';
        more.addEventListener('click', () => loadWorkload(true));target.appendChild(more);
      }
    } catch(err) {
      if (!append) target.innerHTML=`<div class="load-error-box"><p role="alert">Could not load workload: ${esc(err.message)}</p><button type="button" class="btn btn-ghost" id="workload-retry">Retry</button></div>`;
      else toast(err.message,true);
      document.getElementById('workload-retry')?.addEventListener('click', () => loadWorkload(false));
    }
  }

  // ---------------------------------------------------------------
  // TIME OFF: personal requests and manager approvals, team-scoped.
  // ---------------------------------------------------------------
  let timeOffScope = 'mine';
  let timeOffCursor = null;
  async function renderTimeOff() {
    const target = document.getElementById('view-container');
    if (!state.currentTeamId) {
      target.innerHTML = '<div class="empty-state"><h3>Choose a team</h3><p>Join or select a team to request time off.</p></div>';
      return;
    }
    const canReview = iHave(['administrator', 'manager']);
    const canRequest = Number(state.user.team_id) === Number(state.currentTeamId);
    if (!canReview) timeOffScope = 'mine';
    if (!canRequest) timeOffScope = 'team';
    timeOffCursor = null;
    target.innerHTML = `<div class="feature-intro"><h2>Make room for time away</h2><p>Submit your dates, track a decision, and keep team capacity realistic.</p></div>
      <div class="leave-layout">
        ${canRequest?`<section class="leave-request-card" aria-label="Request time off">
          <h3>New time-off request</h3>
          <p>Choose a date range for full days, or a single date for a morning or afternoon. Your work schedule and team holidays are respected.</p>
          <form id="leave-request-form" class="leave-request-form">
            <label>First day <input type="date" name="start_date" required></label>
            <label>Last day <input type="date" name="end_date" required></label>
            <label>Duration <select name="day_portion"><option value="full">Full day(s)</option><option value="am">Morning half-day</option><option value="pm">Afternoon half-day</option></select></label>
            <label class="leave-wide">Coverage colleague (optional) <select name="backup_user_id" id="leave-backup"><option value="">No coverage requested</option></select><small>This person may accept or decline a handoff. Tasks are not reassigned.</small></label>
            <label class="leave-wide">Note for your manager <textarea name="reason" maxlength="300" rows="3" placeholder="Optional context"></textarea></label>
            <button type="submit" class="btn btn-primary">Submit request</button>
          </form>
        </section>`:'<section class="leave-request-card"><h3>Review only</h3><p>You can review leave for this team, but may request time off only in your own team.</p></section>'}
        <section class="leave-history-card" aria-label="Time off history">
          <div class="leave-heading"><h3>Requests</h3>
          <label>Show <select id="leave-scope">${canRequest?`<option value="mine" ${timeOffScope==='mine'?'selected':''}>My requests</option>`:''}${canReview?`<option value="team" ${timeOffScope==='team'?'selected':''}>Team requests</option>`:''}<option value="coverage" ${timeOffScope==='coverage'?'selected':''}>Covering for others</option></select></label></div>
          <div id="leave-results" aria-live="polite"><p>Loading requests...</p></div>
        </section>
      </div>`;
    const form=target.querySelector('#leave-request-form');
    if (form) {
    // Load a team-only choice list; never expose a personal leave reason to the backup.
    const selectedTeam=state.currentTeamId;
    api('users.php').then(data=>{
      const select=form.querySelector('#leave-backup');
      if (!select || state.currentView!=='time-off' || selectedTeam!==state.currentTeamId) return;
      const colleagues=(data.users||[]).filter(u=>Number(u.id)!==Number(state.user.id)
        && (u.team_id===undefined || u.team_id===null || Number(u.team_id)===Number(selectedTeam))
        && (u.is_active===undefined || Boolean(Number(u.is_active))));
      select.innerHTML='<option value="">No coverage requested</option>'+colleagues.map(u=>`<option value="${Number(u.id)}">${esc(u.name)}</option>`).join('');
    }).catch(()=>{ /* Optional picker; the request form still works. */ });
    form.querySelector('[name="start_date"]').min=new Date(Date.now()-30*86400000).toISOString().slice(0,10);
    form.querySelector('[name="start_date"]').max=new Date(Date.now()+365*86400000).toISOString().slice(0,10);
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const data=new FormData(form);
      const first=String(data.get('start_date')||'');
      const last=String(data.get('end_date')||'');
      if (!first || !last || first>last) {toast('Check the first and last dates.',true);return;}
      const portion=String(data.get('day_portion')||'full');
      if (portion!=='full' && first!==last) {toast('Half-day leave requires a single date.',true);return;}
      const button=form.querySelector('button[type="submit"]');button.disabled=true;
      try {
        await api('time_off.php',{method:'POST',body:{action:'request',team_id:state.currentTeamId,
          start_date:first,end_date:last,day_portion:portion,reason:String(data.get('reason')||''),
          backup_user_id:String(data.get('backup_user_id')||'')}});
        toast('Request submitted');form.reset();timeOffCursor=null;await loadTimeOff(false);
      } catch (error) {toast(error.message,true);} finally {button.disabled=false;}
    });
    }
    target.querySelector('#leave-scope')?.addEventListener('change', event => {
      timeOffScope=event.target.value;timeOffCursor=null;loadTimeOff(false);
    });
    await loadTimeOff(false);
  }
  async function loadTimeOff(append) {
    const target=document.getElementById('leave-results');
    if (!target || state.currentView!=='time-off') return;
    const teamId=state.currentTeamId;
    const scope=timeOffScope;
    const params=new URLSearchParams({team_id:String(teamId),scope});
    if (append && timeOffCursor) params.set('before_id',String(timeOffCursor));
    if (!append) target.innerHTML='<p>Loading requests...</p>';
    try {
      const data=await api('time_off.php?'+params);
      if (state.currentView!=='time-off'||state.currentTeamId!==teamId||timeOffScope!==scope||!document.getElementById('leave-results')) return;
      if (!append) target.innerHTML='';
      target.querySelector('#leave-more')?.remove();
      if (!data.requests.length && !append) { target.innerHTML='<p class="leave-empty">No requests yet.</p>'; return; }
      const list=document.createElement('div');list.className='leave-list';
      list.innerHTML=data.requests.map(r=>{
        const status=String(r.status);
        const mine=Number(r.user_id)===Number(state.user.id);
        const canCancel=mine && (status==='pending'||status==='approved');
        const canReview=data.can_review && !mine && status==='pending';
        return `<article class="leave-item" data-leave-id="${Number(r.id)}">
          <div class="leave-item-main"><strong>${esc(r.user_name)}</strong><span class="leave-status leave-${esc(status)}">${esc(status)}</span></div>
          <p class="leave-dates">${esc(r.start_date)}${r.end_date!==r.start_date?' to '+esc(r.end_date):''}${r.day_portion==='am'?' · Morning half-day':r.day_portion==='pm'?' · Afternoon half-day':''}</p>
          ${r.reason?`<p class="leave-note">${esc(r.reason)}</p>`:''}
          ${r.reviewer_note?`<p class="leave-review-note">Manager note: ${esc(r.reviewer_note)}</p>`:''}
          ${r.backup_user_id?`<p class="leave-review-note">Coverage: ${esc(r.backup_name||'Former member')} · ${esc(r.coverage_status||'pending')}</p>`:''}
          <div class="leave-item-actions">${Number(r.backup_user_id)===Number(state.user.id) && r.coverage_status==='pending' && (status==='pending'||status==='approved')?`<button type="button" class="btn btn-ghost" data-coverage-decline="${Number(r.id)}">Decline coverage</button><button type="button" class="btn btn-primary" data-coverage-accept="${Number(r.id)}">Accept coverage</button>`:''}${canCancel?`<button type="button" class="btn btn-ghost" data-leave-cancel="${Number(r.id)}">Cancel</button>`:''}
          ${canReview?`<button type="button" class="btn btn-ghost" data-leave-decline="${Number(r.id)}">Decline</button><button type="button" class="btn btn-primary" data-leave-approve="${Number(r.id)}">Approve</button>`:''}</div>
        </article>`;
      }).join('');
      target.appendChild(list);
      list.querySelectorAll('button[data-coverage-accept],button[data-coverage-decline]').forEach(btn=>btn.addEventListener('click',async()=>{
        const id=Number(btn.dataset.coverageAccept||btn.dataset.coverageDecline);
        const decision=btn.dataset.coverageAccept?'accept':'decline';
        btn.disabled=true;
        try {await api('time_off.php',{method:'POST',body:{team_id:teamId,action:'coverage_response',id,decision}});
          toast('Coverage response saved');timeOffCursor=null;await loadTimeOff(false);
        }catch(error){toast(error.message,true);btn.disabled=false;}
      }));
      list.querySelectorAll('button[data-leave-cancel],button[data-leave-decline],button[data-leave-approve]').forEach(btn=>{
        btn.addEventListener('click',async()=>{
          const id=Number(btn.dataset.leaveCancel || btn.dataset.leaveDecline || btn.dataset.leaveApprove);
          const action=btn.dataset.leaveCancel?'cancel':'review';
          const decision=btn.dataset.leaveApprove?'approve':'decline';
          let reviewer_note='';
          if (action==='cancel' && !confirm('Cancel this time-off request?')) return;
          if (action==='review') {
            const answer=prompt(decision==='approve'?'Optional approval note:':'Reason for declining:','');
            if (answer===null) return;
            reviewer_note=answer;
          }
          btn.disabled=true;
          try {
            await api('time_off.php',{method:'POST',body:{action,team_id:teamId,id,decision,reviewer_note}});
            toast(action==='cancel'?'Request cancelled':'Request reviewed');timeOffCursor=null;await loadTimeOff(false);
          } catch(error){toast(error.message,true);btn.disabled=false;}
        });
      });
      timeOffCursor=data.next_before_id;
      if (timeOffCursor!==null) {
        const more=document.createElement('button');more.type='button';more.id='leave-more';more.className='btn btn-ghost';more.textContent='Load older requests';
        more.addEventListener('click',()=>loadTimeOff(true));target.appendChild(more);
      }
    } catch(error) {
      if(!append) target.innerHTML=`<p class="load-error-box" role="alert">${esc(error.message)}</p>`;
      else toast(error.message,true);
    }
  }

  // ---------------------------------------------------------------
  // AVAILABILITY & ADVISORY LEAVE BALANCES (TaskFlow 1.15).
  // Dates are UTC; private leave reasons are never requested by the calendar.
  // ---------------------------------------------------------------
  let availabilityMonth = new Date().toISOString().slice(0,7);
  let availabilityLoadId = 0;
  let balanceYear = new Date().getUTCFullYear();
  let balanceScope = 'team';
  let balanceOffset = 0;
  let balanceLoadId = 0;
  function changeCalendarMonth(delta) {
    const date = new Date(availabilityMonth+'-01T00:00:00Z');
    date.setUTCMonth(date.getUTCMonth()+delta);
    availabilityMonth=date.toISOString().slice(0,7);
    renderAvailability();
  }
  async function renderAvailability() {
    const target=document.getElementById('view-container');
    if(!state.currentTeamId){target.innerHTML=emptyTeamState();return;}
    target.innerHTML=`<div class="feature-intro"><h2>Team availability</h2><p>Approved absences and organization holidays, so your team can plan around who is away. Private request notes are never shown.</p></div>
      <div class="availability-toolbar"><button type="button" class="btn btn-ghost" id="absence-prev" aria-label="Previous month">← Previous</button>
      <label>Month <input type="month" id="absence-month" value="${esc(availabilityMonth)}"></label>
      <button type="button" class="btn btn-ghost" id="absence-next" aria-label="Next month">Next →</button>
      <a class="btn btn-ghost" id="absence-export" href="${esc('api/availability_export.php?'+new URLSearchParams({team_id:String(state.currentTeamId),month:availabilityMonth}))}" download>Export calendar (.ics)</a></div>
      <section id="availability-results" class="availability-results" aria-live="polite"><p>Loading team availability…</p></section>`;
    target.querySelector('#absence-prev').addEventListener('click',()=>changeCalendarMonth(-1));
    target.querySelector('#absence-next').addEventListener('click',()=>changeCalendarMonth(1));
    target.querySelector('#absence-month').addEventListener('change',ev=>{
      if(!/^\d{4}-\d{2}$/.test(ev.target.value))return;
      availabilityMonth=ev.target.value;renderAvailability();
    });
    await loadAvailability();
  }
  async function loadAvailability() {
    const target=document.getElementById('availability-results');
    if (!target)return;
    const request=++availabilityLoadId, month=availabilityMonth, teamId=state.currentTeamId;
    try {
      const query=new URLSearchParams({team_id:String(teamId),month});
      const response=await api('leave_calendar.php?'+query);
      if(request!==availabilityLoadId || state.currentView!=='availability' || teamId!==state.currentTeamId || month!==availabilityMonth || !document.getElementById('availability-results'))return;
      const events={};
      const add=(date,label,kind)=>{(events[date] ||= []).push({label,kind});};
      const last=Number(month.split('-')[1]);
      const start=new Date(month+'-01T00:00:00Z');
      const days=new Date(Date.UTC(start.getUTCFullYear(), last,0)).getUTCDate();
      response.holidays.forEach(h=>add(h.holiday_date,h.title,'holiday'));
      response.approved_leave.forEach(r=>{
        const from=new Date(r.start_date+'T00:00:00Z'), to=new Date(r.end_date+'T00:00:00Z');
        const left=from>start?from:start;
        const end=new Date(Date.UTC(start.getUTCFullYear(),start.getUTCMonth(),days));
        const right=to<end?to:end;
        const suffix=r.day_portion==='am'?' (AM)':r.day_portion==='pm'?' (PM)':'';
        for(let cursor=new Date(left);cursor<=right;cursor.setUTCDate(cursor.getUTCDate()+1))add(cursor.toISOString().slice(0,10),r.member_name+suffix,'absence');
      });
      const leading=(start.getUTCDay()+6)%7;
      const title=new Intl.DateTimeFormat(undefined,{month:'long',year:'numeric',timeZone:'UTC'}).format(start);
      let cells='';
      for(let n=0;n<leading;n++)cells+='<div class="absence-blank" aria-hidden="true"></div>';
      let count=0;
      for(let d=1;d<=days;d++) {
        const day=month+'-'+String(d).padStart(2,'0');
        const entries=events[day]||[]; count+=entries.length;
        const display=entries.slice(0,3).map(e=>`<span class="absence-tag ${e.kind==='holiday'?'absence-holiday':''}" title="${esc(e.label)}">${esc(e.label)}</span>`).join('');
        cells+=`<div class="absence-day" data-absence-date="${esc(day)}"><time datetime="${esc(day)}">${d}</time>${display}
          ${entries.length>3?`<details class="absence-more"><summary>+${entries.length-3} more</summary>${entries.slice(3).map(e=>`<span class="absence-tag ${e.kind==='holiday'?'absence-holiday':''}">${esc(e.label)}</span>`).join('')}</details>`:''}</div>`;
      }
      target.innerHTML=`<div class="absence-heading"><h3>${esc(title)}</h3><p>${count} visible leave or holiday entries</p></div>
        ${response.truncated?'<p class="absence-warning" role="status">More than 1,000 approved requests match this month. This calendar is incomplete; contact an administrator.</p>':''}
        <div class="absence-weekdays" aria-hidden="true">${['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].map(d=>`<span>${d}</span>`).join('')}</div>
        <div class="absence-grid">${cells}</div>
        <p class="absence-legend">Blue = approved time off · Gold = team holiday. Dates are in UTC; notes and pending requests are private.</p>`;
    }catch(error){if(request===availabilityLoadId&&document.getElementById('availability-results'))target.innerHTML=`<p class="load-error-box" role="alert">${esc(error.message)}</p>`;}
  }

  async function renderLeaveBalances() {
    const target=document.getElementById('view-container');
    if(!state.currentTeamId){target.innerHTML=emptyTeamState();return;}
    const canManage=iHave(['administrator','manager']);
    if(!canManage||Number(state.user.team_id)!==Number(state.currentTeamId))balanceScope=canManage?'team':'mine';
    const thisYear=new Date().getUTCFullYear();
    balanceYear=Math.max(thisYear-2,Math.min(thisYear+2,balanceYear));
    balanceOffset=0;
    target.innerHTML=`<div class="feature-intro"><h2>Leave balances</h2><p>Understand approved time away in scheduled workdays. Annual allowances are optional and advisory; they do not block requests or approvals.</p></div>
      <div class="availability-toolbar"><button class="btn btn-ghost" type="button" id="balance-prev">← Previous year</button>
      <strong id="balance-year">${balanceYear}</strong><button class="btn btn-ghost" type="button" id="balance-next">Next year →</button>
      ${canManage?`<a class="btn btn-ghost" href="${esc('api/leave_balance_export.php?'+new URLSearchParams({team_id:String(state.currentTeamId),year:String(balanceYear)}))}" download>Export leave balances (CSV)</a>`:''}
      ${canManage?`<label>View <select id="balance-scope"><option value="team" ${balanceScope==='team'?'selected':''}>Team</option>${Number(state.user.team_id)===Number(state.currentTeamId)?`<option value="mine" ${balanceScope==='mine'?'selected':''}>Me</option>`:''}</select></label>`:''}</div>
      <section id="balance-results" aria-live="polite"><p>Calculating leave balances…</p></section>`;
    const changeYear=(delta)=>{const value=balanceYear+delta;if(value<thisYear-2||value>thisYear+2)return;balanceYear=value;renderLeaveBalances();};
    target.querySelector('#balance-prev').addEventListener('click',()=>changeYear(-1));
    target.querySelector('#balance-next').addEventListener('click',()=>changeYear(1));
    target.querySelector('#balance-scope')?.addEventListener('change',ev=>{balanceScope=ev.target.value;balanceOffset=0;loadLeaveBalances(false);});
    await loadLeaveBalances(false);
  }
  function halfDayText(units){return units===null||units===undefined?'Not set':(Number(units)/2).toFixed(1).replace(/\.0$/,'')+' days';}
  async function loadLeaveBalances(append) {
    const target=document.getElementById('balance-results');if(!target)return;
    const request=++balanceLoadId, teamId=state.currentTeamId, year=balanceYear, scope=balanceScope;
    if(!append)target.innerHTML='<p>Calculating leave balances…</p>';
    const query=new URLSearchParams({team_id:String(teamId),year:String(year),scope,offset:String(append?balanceOffset:0)});
    try {
      const data=await api('leave_balances.php?'+query);
      if(request!==balanceLoadId||state.currentView!=='leave-balances'||teamId!==state.currentTeamId||year!==balanceYear||scope!==balanceScope||!document.getElementById('balance-results'))return;
      if(!append)target.innerHTML='';
      target.querySelector('#balance-more')?.remove();
      if(!data.rows.length && !append){target.innerHTML='<p class="leave-empty">No members found for this view.</p>';return;}
      const section=document.createElement('div');section.className='balance-grid';
      section.innerHTML=data.rows.map(r=>`<article class="balance-card" data-balance-user="${Number(r.user_id)}"><strong>${esc(r.name)}</strong>
        <div class="balance-stats"><span><small>Approved</small><b>${esc(halfDayText(r.approved_half_days))}</b></span>
        <span><small>Allowance</small><b>${esc(halfDayText(r.allowance_half_days))}</b></span>
        <span><small>Remaining</small><b>${esc(halfDayText(r.remaining_half_days))}</b></span></div>
        ${data.can_manage && scope==='team'?`<form class="balance-edit-form" data-allowance-for="${Number(r.user_id)}"><label>Annual allowance (days)
          <input aria-label="Annual allowance for ${esc(r.name)}" type="number" name="days" min="0" max="366" step="0.5" value="${r.allowance_half_days===null?'':Number(r.allowance_half_days)/2}" placeholder="Not configured" required></label><button class="btn btn-ghost" type="submit">Save</button></form>`:''}
      </article>`).join('');
      target.appendChild(section);
      section.querySelectorAll('.balance-edit-form').forEach(form=>form.addEventListener('submit',async event=>{
        event.preventDefault();const days=Number(form.querySelector('[name="days"]').value);
        if(!Number.isFinite(days)||days<0||days>366||Math.round(days*2)!==days*2){toast('Enter a number of days in half-day increments.',true);return;}
        const button=form.querySelector('button');button.disabled=true;
        try {await api('leave_balances.php',{method:'POST',body:{team_id:teamId,year,action:'set_allowance',user_id:Number(form.dataset.allowanceFor),allowance_half_days:Math.round(days*2)}});
          toast('Annual allowance saved');balanceOffset=0;loadLeaveBalances(false);
        } catch(error){toast(error.message,true);button.disabled=false;}
      }));
      balanceOffset=data.next_offset;
      if(balanceOffset!==null){const more=document.createElement('button');more.type='button';more.id='balance-more';more.className='btn btn-ghost';more.textContent='Load more people';more.addEventListener('click',()=>loadLeaveBalances(true));target.appendChild(more);}
      if(!append){const note=document.createElement('p');note.className='absence-legend';note.textContent='Approved time off includes future approved dates. Unscheduled days and team holidays are excluded. Balances are informational only.';target.prepend(note);}
    }catch(error){if(!append)target.innerHTML=`<p class="load-error-box" role="alert">${esc(error.message)}</p>`;else toast(error.message,true);}
  }

  // ---------------------------------------------------------------
  // TEAM HOLIDAYS: one-off dates; managers maintain, members can read.
  // ---------------------------------------------------------------
  let holidayYear = new Date().getUTCFullYear();
  async function renderHolidays() {
    const container=document.getElementById('view-container');
    if (!state.currentTeamId){container.innerHTML=emptyTeamState();return;}
    const canManage=iHave(['administrator','manager']);
    const todayYear=new Date().getUTCFullYear();
    holidayYear=Math.max(todayYear-2,Math.min(todayYear+2,holidayYear));
    container.innerHTML=`<div class="feature-intro"><h2>Team holidays</h2><p>Shared non-working dates reduce planned availability only for people who normally work that day. Holiday dates and leave never deduct the same day twice.</p></div>
      <div class="holiday-toolbar"><button type="button" class="btn btn-ghost" id="holiday-prev">← Previous</button><strong id="holiday-year">${holidayYear}</strong><button type="button" class="btn btn-ghost" id="holiday-next">Next →</button></div>
      ${canManage?`<form id="holiday-add-form" class="holiday-add-form"><label>Date <input type="date" name="holiday_date" required></label><label>Holiday name <input type="text" name="title" maxlength="100" required placeholder="e.g. Organization holiday"></label><button type="submit" class="btn btn-primary">Add holiday</button></form>`:''}
      <section class="holiday-panel" aria-label="Team holiday calendar"><div id="holiday-results" aria-live="polite"><p>Loading holidays...</p></div></section>`;
    const changeYear=(delta)=>{const next=holidayYear+delta;if(next<todayYear-2||next>todayYear+2)return;holidayYear=next;renderHolidays();};
    container.querySelector('#holiday-prev').addEventListener('click',()=>changeYear(-1));
    container.querySelector('#holiday-next').addEventListener('click',()=>changeYear(1));
    container.querySelector('#holiday-add-form')?.addEventListener('submit',async ev=>{
      ev.preventDefault();const form=ev.currentTarget;const body=new FormData(form);
      const date=String(body.get('holiday_date')||'');
      if(!date.startsWith(String(holidayYear)+'-')){toast('Select a date in the displayed year.',true);return;}
      const btn=form.querySelector('[type="submit"]');btn.disabled=true;
      try{
        await api('holidays.php',{method:'POST',body:{action:'add',team_id:state.currentTeamId,holiday_date:date,title:String(body.get('title')||'')}});
        form.reset();toast('Holiday saved');await loadHolidays();
      }catch(error){toast(error.message,true);}finally{btn.disabled=false;}
    });
    await loadHolidays();
  }
  async function loadHolidays() {
    const target=document.getElementById('holiday-results');
    if(!target||state.currentView!=='holidays')return;
    const teamId=state.currentTeamId;const year=holidayYear;
    target.innerHTML='<p>Loading holidays...</p>';
    try{
      const data=await api('holidays.php?'+new URLSearchParams({team_id:String(teamId),year:String(year)}));
      if(state.currentView!=='holidays'||state.currentTeamId!==teamId||holidayYear!==year||!document.getElementById('holiday-results'))return;
      if(!data.holidays.length){target.innerHTML='<p class="leave-empty">No holidays recorded for this year.</p>';return;}
      target.innerHTML='<div class="holiday-list">'+data.holidays.map(row=>`<article class="holiday-row"><div><strong>${esc(row.title)}</strong><p>${esc(row.holiday_date)}</p></div>${data.can_manage?`<button type="button" class="btn btn-ghost" data-holiday-delete="${Number(row.id)}">Remove</button>`:''}</article>`).join('')+'</div>';
      target.querySelectorAll('[data-holiday-delete]').forEach(button=>button.addEventListener('click',async()=>{
        if(!confirm('Remove this team holiday?'))return;
        button.disabled=true;
        try{await api('holidays.php',{method:'POST',body:{action:'delete',team_id:teamId,id:Number(button.dataset.holidayDelete)}});toast('Holiday removed');await loadHolidays();}
        catch(error){toast(error.message,true);button.disabled=false;}
      }));
    }catch(error){target.innerHTML='<p class="load-error-box" role="alert">'+esc(error.message)+'</p>';}
  }

  // ---------------------------------------------------------------
  // PERSONAL WORK: user's own tasks with server-side team boundaries.
  // ---------------------------------------------------------------
  let myWorkCursor = null;
  let myWorkRequestSeq = 0;
  let myWorkVisibleTasks = [];
  // ---------------------------------------------------------------
  // TASK REVIEWS: manager decisions and team submission history.
  // ---------------------------------------------------------------
  let taskReviewScope = 'team';
  let taskReviewStatus = 'pending';
  let taskReviewCursor = null;
  let taskReviewRows = [];
  let taskReviewRequestSeq = 0;
  const reviewLabels = {pending:'Awaiting review',approved:'Approved',changes_requested:'Changes requested',cancelled:'Cancelled'};
  function taskReviewManager(){return iHave(['administrator','manager']);}
  function taskReviewStatusLabel(key){return reviewLabels[key]||'Review';}
  function taskReviewNoteForm(action,review) {
    const required=action==='request_changes';
    const title=action==='approve'?'Approve task review':required?'Request changes':'Cancel review';
    const hint=required?'What should change before this task can be approved?':'Optional note for the review history';
    promptModal(title,`<label for="p-task-review-note">${esc(hint)}</label>
      <textarea id="p-task-review-note" maxlength="1000" rows="3" placeholder="${esc(hint)}" style="width:100%;min-height:100px;"></textarea>`,async()=>{
      const note=document.getElementById('p-task-review-note').value.trim();
      if(required && new TextEncoder().encode(note).length<5)throw new Error('Explain what should change (at least 5 bytes).');
      await api('task_reviews.php',{method:'POST',body:{team_id:Number(state.currentTeamId),task_id:Number(review.task_id),
        action,version:Number(review.version),note}});
      toast(action==='approve'?'Task approved':required?'Changes requested':'Review cancelled');
      await loadTaskReviews(false);
    });
  }
  async function renderTaskReviews(){
    const container=document.getElementById('view-container');
    if(!state.currentTeamId){container.innerHTML=emptyTeamState();return;}
    const manager=taskReviewManager();
    if(!manager)taskReviewScope='mine';
    container.innerHTML=`<section class="task-review-intro"><div><span class="section-kicker">TEAM DELIVERY</span>
      <h2>Reviews, without the back-and-forth.</h2><p>Submit finished work, review feedback, and keep every decision in one place.</p></div>
      <span class="task-review-pending" id="task-review-counter" aria-live="polite">Loading…</span></section>
      <section class="task-review-panel"><div class="task-review-filters">
        ${manager?`<label>Show<select id="task-review-scope"><option value="team">Team submissions</option><option value="mine">My requests</option></select></label>`:''}
        <label>Status<select id="task-review-status"><option value="pending">Awaiting review</option>
          <option value="approved">Approved</option><option value="changes_requested">Changes requested</option>
          <option value="cancelled">Cancelled</option><option value="">All statuses</option></select></label>
        <button type="button" class="btn btn-ghost" id="task-review-refresh">Refresh</button></div>
        <div id="task-review-results" class="task-review-list" aria-live="polite">Loading…</div>
        <div id="task-review-more" class="portfolio-more"></div></section>`;
    const scopeSelect=container.querySelector('#task-review-scope');if(scopeSelect)scopeSelect.value=taskReviewScope;
    container.querySelector('#task-review-status').value=taskReviewStatus;
    scopeSelect?.addEventListener('change',event=>{taskReviewScope=event.target.value;loadTaskReviews(false);});
    container.querySelector('#task-review-status').addEventListener('change',event=>{taskReviewStatus=event.target.value;loadTaskReviews(false);});
    container.querySelector('#task-review-refresh').addEventListener('click',()=>loadTaskReviews(false));
    container.querySelector('#task-review-results').addEventListener('click',event=>{
      const button=event.target.closest('[data-review-action]');if(!button || button.disabled)return;
      const item=taskReviewRows.find(row=>Number(row.id)===Number(button.dataset.reviewId));if(!item)return;
      const action=button.dataset.reviewAction;
      if(action==='open'){openTaskModal(item.task_id);return;}
      if((action==='approve'||action==='request_changes')&&!item.can_review)return;
      if(action==='cancel'&&!item.can_cancel)return;
      taskReviewNoteForm(action,item);
    });
    await loadTaskReviews(false);
  }
  async function loadTaskReviews(append=false){
    const feed=document.getElementById('task-review-results'),more=document.getElementById('task-review-more');
    if(!feed||!more)return;
    const teamId=Number(state.currentTeamId),scope=taskReviewScope,status=taskReviewStatus,request=++taskReviewRequestSeq;
    if(!append){taskReviewCursor=null;taskReviewRows=[];feed.textContent='Loading reviews…';}
    const params=new URLSearchParams({team_id:String(teamId),scope,status});
    if(append&&taskReviewCursor)params.set('before_id',String(taskReviewCursor));
    try{
      const result=await api('task_reviews.php?'+params.toString());
      if(request!==taskReviewRequestSeq||state.currentView!=='task-reviews'||teamId!==Number(state.currentTeamId)||scope!==taskReviewScope||status!==taskReviewStatus)return;
      if(!append)feed.replaceChildren();
      const counter=document.getElementById('task-review-counter');
      if(counter)counter.textContent=`${Number(result.pending_count||0)} awaiting review`;
      taskReviewRows.push(...result.reviews);
      for(const item of result.reviews){
        const card=document.createElement('article');card.className='task-review-card';
        const statusKey=Object.hasOwn(reviewLabels,item.status)?item.status:'pending';
        card.innerHTML=`<div class="task-review-header"><span class="task-review-status task-review-status-${statusKey}">${taskReviewStatusLabel(statusKey)}</span>
            <span class="task-review-project">${esc(item.project_name)}</span></div>
          <h3>${esc(item.title)}</h3>
          <div class="task-review-meta"><span>Submitted by ${esc(item.submitted_name)}</span><span>${esc(item.submitted_at||'')}</span></div>
          ${item.request_note?`<p class="task-review-note">${esc(item.request_note)}</p>`:''}
          ${item.reviewer_note?`<p class="task-review-note task-review-feedback"><strong>Reviewer feedback:</strong> ${esc(item.reviewer_note)}</p>`:''}
          <div class="task-review-actions"><button type="button" class="btn btn-ghost" data-review-action="open" data-review-id="${Number(item.id)}">View task</button>
          ${item.can_review && taskReviewManager()?`<button type="button" class="btn btn-primary" data-review-action="approve" data-review-id="${Number(item.id)}">Approve</button>
            <button type="button" class="btn btn-ghost" data-review-action="request_changes" data-review-id="${Number(item.id)}">Request changes</button>`:''}
          ${item.can_cancel?`<button type="button" class="btn btn-ghost" data-review-action="cancel" data-review-id="${Number(item.id)}">Cancel</button>`:''}</div>`;
        feed.appendChild(card);
      }
      if(!taskReviewRows.length)feed.innerHTML='<div class="empty-state"><h3>Nothing in this review queue</h3><p>Open a task and choose Request review when the work is ready.</p></div>';
      taskReviewCursor=result.next_before_id;
      more.innerHTML=taskReviewCursor?'<button type="button" class="btn btn-ghost">Load older reviews</button>':'';
      more.querySelector('button')?.addEventListener('click',()=>loadTaskReviews(true));
    }catch(error){
      if(request!==taskReviewRequestSeq||state.currentView!=='task-reviews')return;
      if(!append)feed.innerHTML=`<p class="load-error-box">${esc(error.message)}</p>`;else toast(error.message,true);
    }
  }

  async function renderMyWork() {
    myWorkCursor = null;
    const container = document.getElementById('view-container');
    const firstName = String(state.user?.name || 'there').trim().split(/\s+/)[0];
    const greeting = new Date().getHours() < 12 ? 'Good morning' : new Date().getHours() < 17 ? 'Good afternoon' : 'Good evening';
    const dateLabel = new Intl.DateTimeFormat(undefined,{weekday:'long',month:'long',day:'numeric'}).format(new Date());
    container.innerHTML = `<section class="work-hero v2-home-header" aria-labelledby="work-greeting">
      <div class="work-hero-main"><div class="work-eyebrow">${esc(dateLabel)} <span class="work-eyebrow-separator">/</span> MY WORKSPACE</div>
      <h2 id="work-greeting">${greeting}, ${esc(firstName)}</h2></div>
      <button type="button" class="btn btn-ghost v3-work-open-project" id="v3-work-projects">View project tasks <span aria-hidden="true">↗</span></button>
    </section>
    <section class="work-results-section v2-task-stream" aria-labelledby="v3-work-list-heading">
      <div class="work-section-head"><div><h3 id="v3-work-list-heading">My tasks</h3><span class="v3-work-subheading">Your current queue, all in one place</span></div>
      <span class="v2-task-count" id="v2-task-count" aria-live="polite">Loading tasks…</span></div>
      <div class="v3-work-control-row"><div class="focus-shortcuts" role="group" aria-label="Quick task filters">
        <button type="button" data-work-filter="all" aria-pressed="true">Open tasks</button>
        <button type="button" data-work-filter="overdue" aria-pressed="false">Overdue</button>
        <button type="button" data-work-filter="today" aria-pressed="false">Today</button>
        <button type="button" data-work-filter="week" aria-pressed="false">Next 7 days</button>
        <button type="button" data-work-filter="favorites" aria-pressed="false">Starred</button>
      </div>
      <details class="v2-filter-details"><summary><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#sliders"></use></svg> Filters <span aria-hidden="true">⌄</span></summary>
      <form class="search-form" id="my-work-filters" aria-label="Filter your work">
        <select id="my-work-mode" aria-label="My work type"><option value="all">Created or assigned</option><option value="assigned">Assigned to me</option><option value="created">Created by me</option><option value="favorites">Starred tasks</option></select>
        <select id="my-work-due" aria-label="Due date"><option value="all">Any due date</option><option value="overdue">Overdue</option><option value="today">Due today</option><option value="week">Next 7 days</option><option value="upcoming">Upcoming</option><option value="none">Without due date</option></select>
        <select id="my-work-status" aria-label="Completion"><option value="open">Open tasks</option><option value="done">Completed</option><option value="all">All statuses</option></select>
      </form></details></div>
      <div class="v3-work-statline" id="v3-work-statline" aria-live="polite"><span class="v3-stat"><strong id="v3-work-open-count">—</strong> loaded</span><span class="v3-stat"><strong id="v3-work-overdue-count">—</strong> overdue</span><span class="v3-stat"><strong id="v3-work-today-count">—</strong> due today</span><span class="v3-stat-hint">Counts reflect tasks loaded below</span></div>
      <div class="v3-work-columns" aria-hidden="true"><span>Task / project</span><span>Status</span><span>Priority</span><span>Due</span></div>
      <div class="search-results" id="my-work-results" aria-live="polite"></div>
    </section>`;
    container.querySelector('#v3-work-projects')?.addEventListener('click',()=>document.querySelector('.nav-item[data-view="tasks"]')?.click());
    container.querySelectorAll('[data-work-filter]').forEach(button => button.addEventListener('click',()=>{
      const filter=button.dataset.workFilter;
      const mode=document.getElementById('my-work-mode'), due=document.getElementById('my-work-due'), status=document.getElementById('my-work-status');
      if(filter==='favorites'){mode.value='favorites';due.value='all';}
      else {mode.value='all';due.value=filter;}
      if(filter==='all') status.value='open';
      myWorkCursor=null;
      container.querySelectorAll('[data-work-filter]').forEach(el=>el.setAttribute('aria-pressed',String(el===button)));
      loadMyWork();
    }));
    const myWorkResults=container.querySelector('#my-work-results');
    myWorkResults.addEventListener('click',event=>{
      if(event.target.closest('.task-quick-select'))return;
      const row=event.target.closest('.v3-work-task[data-task-id]');
      if(row)openTaskModal(Number(row.dataset.taskId));
    });
    myWorkResults.addEventListener('change',event=>{
      if(!event.target.matches('.task-quick-select'))return;
      changeTaskQuickly(event.target,async()=>{
        if(state.currentView==='my-work'){
          myWorkCursor=null;
          await loadMyWork();
        }
      });
    });
    container.querySelectorAll('#my-work-filters select').forEach(el => el.addEventListener('change', () => {
      myWorkCursor = null;
      const mode=document.getElementById('my-work-mode').value, due=document.getElementById('my-work-due').value,
        status=document.getElementById('my-work-status').value;
      container.querySelectorAll('[data-work-filter]').forEach(button=>{
        const filter=button.dataset.workFilter;
        button.setAttribute('aria-pressed',String(filter==='favorites' ? mode==='favorites' :
          mode==='all' && due===filter && (filter!=='all'||status==='open')));
      });
      loadMyWork();
    }));
    await loadMyWork();
  }
  async function loadMyWork() {
    const result = document.getElementById('my-work-results');
    if (!result || state.currentView !== 'my-work') return;
    const requestId=++myWorkRequestSeq;
    const append=!!myWorkCursor;
    const params=new URLSearchParams({mode:document.getElementById('my-work-mode').value,
      due:document.getElementById('my-work-due').value,status:document.getElementById('my-work-status').value,limit:'30'});
    if(myWorkCursor)params.set('before_id',String(myWorkCursor));
    if(!append){myWorkVisibleTasks=[];result.innerHTML='<p class="v3-work-loading">Loading tasks…</p>';}
    try {
      const data=await api('my_work.php?'+params);
      if(requestId!==myWorkRequestSeq || !document.getElementById('my-work-results') || state.currentView!=='my-work')return;
      result.querySelector('#my-work-more')?.remove();
      if(!append)result.replaceChildren();
      myWorkVisibleTasks.push(...data.tasks);
      const count=myWorkVisibleTasks.length;
      const loadedCount=document.getElementById('v2-task-count');
      if(loadedCount)loadedCount.textContent=count+' task'+(count===1?'':'s')+' loaded';
      const today=new Date().toISOString().slice(0,10);
      const counts={overdue:myWorkVisibleTasks.filter(t=>isOverdue(t)).length,
        dueToday:myWorkVisibleTasks.filter(t=>t.due_date===today && t.status!=='done').length};
      const setCount=(id,value)=>{const target=document.getElementById(id);if(target)target.textContent=String(value);};
      setCount('v3-work-open-count',count);setCount('v3-work-overdue-count',counts.overdue);setCount('v3-work-today-count',counts.dueToday);
      if(!data.tasks.length&&!append){result.innerHTML='<div class="empty-state"><h3>All caught up</h3><p>No tasks match this view. Choose Open tasks to reset your filters.</p></div>';myWorkCursor=null;return;}
      const list=document.createElement('div');list.className='search-result-list';
      list.innerHTML=data.tasks.map(t=>`<div class="search-result v3-work-task" role="group" data-task-id="${Number(t.id)}" aria-label="Task ${esc(t.title)}">
        <button class="v3-work-task-main v3-work-task-open" type="button" aria-label="Open task: ${esc(t.title)}"><strong>${esc(t.title)}</strong><small>${esc(t.team_name)} · ${esc(t.list_name)}</small></button>
        <span class="v3-work-task-status">${quickTaskFieldHtml(t,'status',Boolean(t.can_edit))}</span>
        <span class="v3-work-task-priority">${quickTaskFieldHtml(t,'priority',Boolean(t.can_edit))}</span>
        <span class="search-due ${isOverdue(t)?'due-overdue':''}">${t.due_date?fmtDate(t.due_date):'—'}</span>
      </div>`).join('');
      result.appendChild(list);
      myWorkCursor=data.next_before_id;
      if(myWorkCursor){const more=document.createElement('button');more.id='my-work-more';more.className='btn btn-ghost';more.type='button';more.textContent='Load more tasks';more.addEventListener('click',()=>{more.disabled=true;loadMyWork();});result.appendChild(more);}
    }catch(e){if(requestId!==myWorkRequestSeq)return;if(!append)result.innerHTML=`<p role="alert">${esc(e.message)}</p>`;else{toast(e.message,true);const retry=result.querySelector('#my-work-more');if(retry)retry.disabled=false;}}
  }
  function isOverdue(task) { return !!task.due_date && task.status !== 'done' && task.due_date < new Date().toISOString().slice(0,10); }

  // ---------------------------------------------------------------
  // KANBAN BOARD: four independently paginated server-side lanes.
  // Drag and drop and accessible select both use the same secure task API.
  // ---------------------------------------------------------------
  const boardStatuses = [ ['to_do','To do'], ['in_progress','In progress'], ['review','In review'], ['done','Done'] ];
  const boardCursors = {};
  let boardMine = false;
  let boardRenderSeq = 0;
  async function renderBoard() {
    const renderSeq = ++boardRenderSeq;
    const container=document.getElementById('view-container');
    if (!state.currentTeamId) { container.innerHTML=emptyTeamState(); return; }
    Object.keys(boardCursors).forEach(k => delete boardCursors[k]);
    container.innerHTML = `<div class="v2-view-intro"><div><span>TEAM PROJECTS</span><h2>Board overview</h2><p>Follow work as it moves from idea to done.</p></div><span class="v2-view-intro-hint">Drag a card to update its status</span></div>
      <div class="board-toolbar"><label><input type="checkbox" id="board-mine" ${boardMine?'checked':''}> Only my work</label><span class="v3-board-hint">Drag tasks across columns to update their status</span><button type="button" class="btn btn-ghost" id="board-refresh">Refresh</button></div>
      <div class="kanban-board" id="kanban-board">${boardStatuses.map(([status,label]) => `<section class="board-lane" data-lane="${status}" aria-label="${label}">
        <h3><span class="status-pill status-${status}">${label}</span><span class="board-count" id="board-count-${status}"></span></h3>
        <div class="board-items" id="board-items-${status}" data-drop-status="${status}"><p class="board-hint">Loading…</p></div>
        <div class="board-footer" id="board-footer-${status}"></div></section>`).join('')}</div>`;
    document.getElementById('board-mine').addEventListener('change', e => {boardMine=e.target.checked;renderBoard();});
    document.getElementById('board-refresh').addEventListener('click', renderBoard);
    document.querySelectorAll('.board-items').forEach(lane => {
      lane.addEventListener('dragover', e => {
        if (e.dataTransfer.types.includes('text/taskflow-task')) { e.preventDefault(); lane.classList.add('drag-target'); }
      });
      lane.addEventListener('dragleave', e => { if (!lane.contains(e.relatedTarget)) lane.classList.remove('drag-target'); });
      lane.addEventListener('drop', async e => {
        e.preventDefault();lane.classList.remove('drag-target');
        const taskId=Number(e.dataTransfer.getData('text/taskflow-task'));
        if (taskId>0) await moveBoardTask(taskId,lane.dataset.dropStatus);
      });
    });
    await Promise.all(boardStatuses.map(([status]) => loadBoardColumn(status, renderSeq)));
  }
  async function loadBoardColumn(status, renderSeq = boardRenderSeq) {
    const root=document.getElementById('board-items-'+status), footer=document.getElementById('board-footer-'+status);
    if (!root || state.currentView!=='board' || renderSeq!==boardRenderSeq) return;
    const cursor=boardCursors[status] || null;
    const mine=document.getElementById('board-mine')?.checked;
    const params = new URLSearchParams({team_id:String(state.currentTeamId),status,limit:'30',mine:mine?'1':'0'});
    if (cursor) params.set('before_id',String(cursor));
    if (!cursor) root.innerHTML='<p class="board-hint">Loading…</p>';
    if (footer) footer.textContent='';
    try {
      const data=await api('search.php?'+params);
      if (state.currentView!=='board' || renderSeq!==boardRenderSeq || !document.getElementById('board-items-'+status)) return;
      if (!cursor) root.innerHTML='';
      if (!data.tasks.length && !cursor) root.innerHTML='<p class="board-hint">No tasks in this lane</p>';
      const nodes=document.createElement('div');
      nodes.innerHTML=data.tasks.map(t => `<article class="board-card" data-task-id="${Number(t.id)}" ${t.can_edit?'draggable="true"':''}>
        <button type="button" class="board-task-open" data-task-id="${Number(t.id)}">${esc(t.title)}</button>
        <div class="board-card-meta"><span class="priority-pill priority-${esc(t.priority)}">${esc(t.priority)}</span>${Number(t.has_unfinished_blockers)?'<span class="blocked-badge">Blocked</span>':''}<small>${esc(t.list_name)}</small></div>
        <div class="board-card-bottom"><span class="search-due ${isOverdue(t)?'due-overdue':''}">${t.due_date?fmtDate(t.due_date):'No due date'}</span>
          ${t.can_edit ? `<label><span class="sr-only">Move ${esc(t.title)}</span><select class="board-move" data-task-id="${Number(t.id)}" aria-label="Move task: ${esc(t.title)}">
            ${boardStatuses.map(([key,label]) => `<option value="${key}" ${key===status?'selected':''}>${label}</option>`).join('')}</select></label>` : '<span class="board-readonly">View only</span>'}</div></article>`).join('');
      nodes.querySelectorAll('.board-task-open').forEach(btn => btn.addEventListener('click',() => openTaskModal(Number(btn.dataset.taskId))));
      nodes.querySelectorAll('.board-move').forEach(sel => sel.addEventListener('change', async () => moveBoardTask(Number(sel.dataset.taskId),sel.value)));
      nodes.querySelectorAll('[draggable="true"]').forEach(card => card.addEventListener('dragstart',e => {
        e.dataTransfer.setData('text/taskflow-task',card.dataset.taskId);
        e.dataTransfer.effectAllowed='move';
      }));
      while(nodes.firstChild) root.appendChild(nodes.firstChild);
      boardCursors[status]=data.next_before_id;
      if (data.next_before_id) {
        const button=document.createElement('button');button.className='btn btn-ghost';button.textContent='Load 30 more';
        button.addEventListener('click',() => loadBoardColumn(status));footer.appendChild(button);
      }
    } catch(e) { if (!cursor) root.innerHTML=`<p role="alert">${esc(e.message)}</p>`; else toast(e.message,true); }
  }
  async function moveBoardTask(id,status) {
    if (!boardStatuses.some(([s]) => s===status)) return;
    try { const result=await api('task.php',{method:'PUT',body:{id,status}}); toast(result.recurrence_new_task_id ? 'Task completed. Next recurring task created.' : 'Task moved'); await renderBoard(); }
    catch(e) { toast(e.message,true); await renderBoard(); }
  }

  // ---------------------------------------------------------------
  // IN-APP INBOX: personal search, category triage, and bounded batch actions.
  // ---------------------------------------------------------------
  let inboxCursor = null;
  let inboxRequestSeq = 0;
  const inboxSelected = new Set();
  let inboxSearchTimer = null;
  async function refreshInboxBadge() {
    try {
      const data=await api('inbox.php?limit=1');
      const badge=document.getElementById('inbox-badge');
      if (badge) { badge.textContent=data.unread_count>99?'99+':String(data.unread_count);badge.classList.toggle('hidden',!data.unread_count); }
    } catch (e) { /* badge is optional; never interrupt work */ }
  }
  function inboxSelectionState() {
    const root=document.getElementById('inbox-results');
    if(!root)return;
    const count=document.getElementById('inbox-selected-count');
    if(count)count.textContent=`${inboxSelected.size} selected`;
    for(const id of ['inbox-selected-read','inbox-selected-unread','inbox-clear-selection']){
      const button=document.getElementById(id);
      if(button)button.disabled=!inboxSelected.size;
    }
    const boxes=Array.from(root.querySelectorAll('.inbox-row-select'));
    const selectPage=document.getElementById('inbox-select-page');
    if(selectPage){
      const checked=boxes.filter(x=>inboxSelected.has(Number(x.value))).length;
      selectPage.checked=Boolean(boxes.length&&checked===boxes.length);
      selectPage.indeterminate=checked>0&&checked<boxes.length;
    }
  }
  function inboxReset() {
    inboxCursor=null;
    inboxSelected.clear();
    inboxSelectionState();
    loadInbox();
  }
  async function renderInbox() {
    inboxCursor=null;
    inboxSelected.clear();
    const container=document.getElementById('view-container');
    container.innerHTML=`<section class="inbox-shell"><div class="inbox-heading"><div><span class="section-kicker">YOUR NOTIFICATIONS</span><h2>Inbox</h2><p>Assignments, messages, and mentions. Organized your way.</p></div><button type="button" id="inbox-mark-all" class="btn btn-ghost">Mark latest 500 read</button></div>
      <div class="inbox-filters"><label>Type <select id="inbox-category" aria-label="Notification type"><option value="">All activity</option><option value="new_tasks">Tasks & updates</option><option value="messages">Messages</option><option value="mentions">Mentions</option></select></label>
        <label>Find <input type="search" id="inbox-search" maxlength="80" placeholder="Search notifications…" autocomplete="off" aria-label="Search notification text"></label>
        <label class="inbox-unread-filter"><input id="inbox-unread" type="checkbox"> Unread only</label></div>
      <div class="inbox-selection-bar"><label class="inbox-page-select"><input type="checkbox" id="inbox-select-page" aria-label="Select visible notifications"> Select visible</label><span id="inbox-selected-count" aria-live="polite">0 selected</span>
        <button type="button" class="btn btn-ghost" id="inbox-selected-read" disabled>Mark selected read</button><button type="button" class="btn btn-ghost" id="inbox-selected-unread" disabled>Mark selected unread</button>
        <button type="button" class="btn btn-ghost" id="inbox-clear-selection" disabled>Clear</button></div>
      <div id="inbox-results" class="search-results" aria-live="polite"></div></section>`;
    container.querySelector('#inbox-category').addEventListener('change',inboxReset);
    container.querySelector('#inbox-unread').addEventListener('change',inboxReset);
    container.querySelector('#inbox-search').addEventListener('input',()=>{
      if(inboxSearchTimer!==null)clearTimeout(inboxSearchTimer);
      inboxSearchTimer=setTimeout(()=>{inboxSearchTimer=null;inboxReset();},160);
    });
    container.querySelector('#inbox-mark-all').addEventListener('click',async event=>{
      const button=event.currentTarget;button.disabled=true;
      try {
        const result=await api('inbox.php',{method:'POST',body:{action:'read_all'}});
        await refreshInboxBadge();inboxReset();
        if(result.unread_count>0)toast(`${result.unread_count} older notifications are still unread`);
      }catch(error){toast(error.message,true);}finally{button.disabled=false;}
    });
    container.querySelector('#inbox-select-page').addEventListener('change',event=>{
      const boxes=Array.from(container.querySelectorAll('.inbox-row-select'));
      if(event.target.checked){
        for(const checkbox of boxes){
          if(inboxSelected.size>=25&&!inboxSelected.has(Number(checkbox.value)))break;
          inboxSelected.add(Number(checkbox.value));checkbox.checked=true;
        }
      }else boxes.forEach(checkbox=>{inboxSelected.delete(Number(checkbox.value));checkbox.checked=false;});
      inboxSelectionState();
    });
    container.querySelector('#inbox-clear-selection').addEventListener('click',()=>{
      inboxSelected.clear();
      container.querySelectorAll('.inbox-row-select').forEach(box=>{box.checked=false;});
      inboxSelectionState();
    });
    for(const [buttonId,action] of [['inbox-selected-read','read_selected'],['inbox-selected-unread','unread_selected']]){
      container.querySelector('#'+buttonId).addEventListener('click',async event=>{
        if(!inboxSelected.size)return;
        const button=event.currentTarget;button.disabled=true;
        try {
          const result=await api('inbox.php',{method:'POST',body:{action,ids:Array.from(inboxSelected)}});
          toast(`${Number(result.updated_count||0)} notification(s) updated`);
          await refreshInboxBadge();inboxReset();
        } catch(error){toast(error.message,true);inboxSelectionState();}
      });
    }
    container.querySelector('#inbox-results').addEventListener('change',event=>{
      if(!event.target.matches('.inbox-row-select'))return;
      const id=Number(event.target.value);
      if(event.target.checked){
        if(inboxSelected.size>=25&&!inboxSelected.has(id)){
          event.target.checked=false;toast('Select up to 25 notifications at a time.',true);return;
        }
        inboxSelected.add(id);
      }else inboxSelected.delete(id);
      inboxSelectionState();
    });
    container.querySelector('#inbox-results').addEventListener('click',async event=>{
      const button=event.target.closest('button[data-inbox-action]');if(!button||button.disabled)return;
      const action=button.dataset.inboxAction,id=Number(button.dataset.noteId);
      if(!['read','unread'].includes(action)||!Number.isSafeInteger(id))return;
      button.disabled=true;
      try {
        await api('inbox.php',{method:'POST',body:{action,id}});
        if(state.currentView!=='inbox'||!button.isConnected)return;
        if(document.getElementById('inbox-unread')?.checked){await refreshInboxBadge();inboxReset();return;}
        const item=button.closest('.inbox-item');
        item.classList.toggle('unread',action==='unread');
        button.dataset.inboxAction=action==='read'?'unread':'read';
        button.textContent=action==='read'?'Mark unread':'Mark read';
        await refreshInboxBadge();
      } catch(error){toast(error.message,true);}finally{button.disabled=false;}
    });
    await loadInbox();
  }
  async function loadInbox() {
    const container=document.getElementById('inbox-results');
    if(!container||state.currentView!=='inbox')return;
    const request=++inboxRequestSeq;
    const cursor=inboxCursor;
    const append=Boolean(cursor);
    const params=new URLSearchParams({limit:'25',unread:document.getElementById('inbox-unread').checked?'1':'0'});
    const category=document.getElementById('inbox-category').value;
    const query=document.getElementById('inbox-search').value.trim();
    if(category)params.set('category',category);
    if(query)params.set('q',query);
    if(cursor)params.set('before_id',String(cursor));
    if(!append)container.innerHTML='<p class="inbox-loading">Loading notifications…</p>';
    try {
      const data=await api('inbox.php?'+params);
      if(request!==inboxRequestSeq||state.currentView!=='inbox'||!container.isConnected)return;
      if(!append)container.innerHTML='';
      container.querySelector('#inbox-load-more')?.remove();
      if(!data.notifications.length&&!append){
        container.innerHTML='<div class="empty-state"><h3>No matching notifications</h3><p>Try another category or clear the search.</p></div>';
        inboxSelectionState();return;
      }
      const list=document.createElement('div');list.className='inbox-list';
      const labels={messages:'Message',new_tasks:'Task update',mentions:'Mention'};
      list.innerHTML=data.notifications.map(n=>`<article class="inbox-item ${n.is_read?'':'unread'}" data-note-id="${Number(n.id)}">
        <label class="inbox-select-cell"><input class="inbox-row-select" type="checkbox" value="${Number(n.id)}" aria-label="Select ${esc(n.title)}" ${inboxSelected.has(Number(n.id))?'checked':''}></label>
        <div class="inbox-marker" aria-hidden="true"></div><div class="inbox-content"><div class="inbox-content-heading"><strong>${esc(n.title)}</strong><span class="inbox-category">${esc(labels[n.category]||'Update')}</span></div>
        <p>${esc(n.body)}</p><small>${esc(n.created_at)}</small></div>
        <button type="button" class="btn btn-ghost ${n.is_read?'inbox-unread':'inbox-read'}" data-inbox-action="${n.is_read?'unread':'read'}" data-note-id="${Number(n.id)}">${n.is_read?'Mark unread':'Mark read'}</button></article>`).join('');
      container.appendChild(list);
      inboxCursor=data.next_before_id;
      if(inboxCursor){const button=document.createElement('button');button.className='btn btn-ghost';button.id='inbox-load-more';button.textContent='Load older notifications';
        button.addEventListener('click',()=>{button.disabled=true;loadInbox();});container.appendChild(button);}
      inboxSelectionState();
      const badge=document.getElementById('inbox-badge');
      if(badge){badge.textContent=data.unread_count>99?'99+':String(data.unread_count);badge.classList.toggle('hidden',!data.unread_count);}
    } catch(error) {
      if(request!==inboxRequestSeq||state.currentView!=='inbox'||!container.isConnected)return;
      if(!append)container.innerHTML=`<p role="alert">${esc(error.message)}</p>`;else toast(error.message,true);
    }
  }

  // ---------------------------------------------------------------
  // SEARCH: user-owned saved filters plus paginated team-scoped results.
  // ---------------------------------------------------------------
  let taskSearchCursor = null;
  let taskSavedViews = [];
  let taskSavedViewTeam = null;
  function taskSearchFilters(){
    return {
      q:document.getElementById('task-search-q').value.trim(),
      status:document.getElementById('task-search-status').value,
      priority:document.getElementById('task-search-priority').value,
      list_id:document.getElementById('task-search-list').value || null,
      mine:document.getElementById('task-search-mine').checked,
    };
  }
  function applyTaskSavedView(view){
    document.getElementById('task-search-q').value=view.q||'';
    document.getElementById('task-search-status').value=view.status||'all';
    document.getElementById('task-search-priority').value=view.priority||'all';
    const list=String(view.list_id||'');
    document.getElementById('task-search-list').value=state.lists.some(l=>String(l.id)===list)?list:'';
    document.getElementById('task-search-mine').checked=Boolean(Number(view.mine));
    taskSearchCursor=null;
    loadTaskSearch();
  }
  async function refreshSavedViewPicker(selectedId=null){
    const teamId=Number(state.currentTeamId);
    let response;
    try{response=await api('saved_views.php?team_id='+teamId);}
    catch(e){toast('Saved searches unavailable: '+e.message,true);response={views:[]};}
    const picker=document.getElementById('task-search-saved');
    if(!picker || state.currentView!=='search' || Number(state.currentTeamId)!==teamId)return;
    taskSavedViews=Array.isArray(response.views)?response.views:[];
    taskSavedViewTeam=teamId;
    picker.innerHTML='<option value="">Choose a saved search</option>'+taskSavedViews.map(v=>
      `<option value="${Number(v.id)}">${esc(v.name)}${Number(v.is_default)?' (default)':''}</option>`).join('');
    if(selectedId && taskSavedViews.some(v=>Number(v.id)===Number(selectedId)))picker.value=String(selectedId);
    document.getElementById('task-search-default').disabled=!picker.value;
    document.getElementById('task-search-delete').disabled=!picker.value;
    return taskSavedViews;
  }
  async function renderSearchTasks() {
    const container = document.getElementById('view-container');
    if (!state.currentTeamId) { container.innerHTML = emptyTeamState(); return; }
    const canExport = iHave(['administrator','manager']);
    const exportBtn = canExport ? `<a class="btn btn-ghost" id="search-export" href="api/export.php?team_id=${Number(state.currentTeamId)}" download>Export team CSV</a>` : '';
    container.innerHTML = `<div class="settings-box"><div class="settings-box-header"><h2>Find work in your team</h2><p>Search titles and descriptions. Save up to 20 personal searches per team.</p></div>
      <div class="saved-search-bar"><label for="task-search-saved">Saved searches</label><select id="task-search-saved"><option value="">Choose a saved search</option></select>
        <button class="btn btn-ghost" type="button" id="task-search-save">Save filters</button>
        <button class="btn btn-ghost" type="button" id="task-search-default" disabled>Make default</button>
        <button class="btn btn-ghost" type="button" id="task-search-delete" disabled>Delete</button></div>
      <form id="task-search-form" class="search-form">
        <input id="task-search-q" type="search" maxlength="120" placeholder="Search tasks…" aria-label="Search task titles and descriptions">
        <select id="task-search-list" aria-label="Task list"><option value="">All lists</option>${state.lists.map(l=>`<option value="${Number(l.id)}">${esc(l.name)}</option>`).join('')}</select>
        <select id="task-search-status" aria-label="Task status"><option value="all">Any status</option><option value="to_do">To do</option><option value="in_progress">In progress</option><option value="review">Review</option><option value="done">Done</option></select>
        <select id="task-search-priority" aria-label="Priority"><option value="all">Any priority</option><option value="urgent">Urgent</option><option value="high">High</option><option value="normal">Normal</option><option value="low">Low</option><option value="none">None</option></select>
        <label class="search-mine"><input id="task-search-mine" type="checkbox"> My work</label>
        <button class="btn btn-primary" type="submit">Search</button>${exportBtn}
      </form></div>
      <div id="task-search-results" class="search-results" aria-live="polite"></div>`;
    document.getElementById('task-search-form').addEventListener('submit', e => { e.preventDefault(); taskSearchCursor=null; loadTaskSearch(); });
    ['status','priority','list','mine'].forEach(id=>document.getElementById('task-search-'+id).addEventListener('change',()=>{taskSearchCursor=null;loadTaskSearch();}));
    document.getElementById('task-search-saved').addEventListener('change',e=>{
      const selected=taskSavedViews.find(v=>String(v.id)===e.target.value);
      document.getElementById('task-search-default').disabled=!selected;
      document.getElementById('task-search-default').textContent=selected && Number(selected.is_default)?'Remove default':'Make default';
      document.getElementById('task-search-delete').disabled=!selected;
      if(selected)applyTaskSavedView(selected);
    });
    document.getElementById('task-search-save').addEventListener('click',()=>{
      promptModal('Save this search',`<label for="saved-search-name">Search name</label><input id="saved-search-name" maxlength="60" placeholder="e.g. High priority this week" required>
        <label class="search-mine"><input type="checkbox" id="saved-search-default"> Use as my default search</label>`,async()=>{
        const name=document.getElementById('saved-search-name').value.trim();
        if(!name || new TextEncoder().encode(name).length>60)throw new Error('Enter a name no longer than 60 bytes.');
        const result=await api('saved_views.php',{method:'POST',body:{team_id:Number(state.currentTeamId),name,...taskSearchFilters(),is_default:document.getElementById('saved-search-default').checked}});
        await refreshSavedViewPicker(result.id);
        toast('Search saved');
      });
    });
    document.getElementById('task-search-default').addEventListener('click',async()=>{
      const view=taskSavedViews.find(v=>String(v.id)===document.getElementById('task-search-saved').value);
      if(!view)return;
      try{
        await api('saved_views.php',{method:'PUT',body:{team_id:Number(state.currentTeamId),id:Number(view.id),is_default:!Number(view.is_default)}});
        await refreshSavedViewPicker(view.id);
        document.getElementById('task-search-default').textContent=Number(view.is_default)?'Make default':'Remove default';
        toast('Default search updated');
      }catch(err){toast(err.message,true);}
    });
    document.getElementById('task-search-delete').addEventListener('click',()=>{
      const view=taskSavedViews.find(v=>String(v.id)===document.getElementById('task-search-saved').value);
      if(!view)return;
      promptModal('Delete saved search',`<p>Delete “${esc(view.name)}”? This will not delete any tasks.</p>`,async()=>{
        await api('saved_views.php',{method:'DELETE',body:{team_id:Number(state.currentTeamId),id:Number(view.id)}});
        await refreshSavedViewPicker();
        toast('Saved search deleted');
      });
    });
    const team=Number(state.currentTeamId);
    const views=await refreshSavedViewPicker();
    if(state.currentView!=='search' || Number(state.currentTeamId)!==team)return;
    const defaultView=(views||[]).find(v=>Number(v.is_default)===1);
    if(defaultView){
      document.getElementById('task-search-saved').value=String(defaultView.id);
      document.getElementById('task-search-default').disabled=false;
      document.getElementById('task-search-default').textContent='Remove default';
      document.getElementById('task-search-delete').disabled=false;
      applyTaskSavedView(defaultView);
    }else await loadTaskSearch();
  }
  async function loadTaskSearch() {
    const results = document.getElementById('task-search-results');
    if (!results) return;
    const params = new URLSearchParams({
      team_id:String(state.currentTeamId), q:document.getElementById('task-search-q').value.trim(),
      status:document.getElementById('task-search-status').value,
      priority:document.getElementById('task-search-priority').value,
      list_id:document.getElementById('task-search-list').value,
      mine:document.getElementById('task-search-mine').checked ? '1':'0', limit:'30',
    });
    if (taskSearchCursor) params.set('before_id',String(taskSearchCursor));
    const append = !!taskSearchCursor;
    if (!append) results.innerHTML = '<p>Searching…</p>';
    try {
      const data = await api('search.php?' + params.toString());
      if (!append) results.innerHTML = '';
      results.querySelector('#search-load-more')?.remove();
      if (!data.tasks.length && !append) {
        results.innerHTML = '<div class="empty-state"><h3>No matching tasks</h3><p>Try a different search or filter.</p></div>';
        return;
      }
      const rows = document.createElement('div');
      rows.className = 'search-result-list';
      rows.innerHTML = data.tasks.map(t => `<button class="search-result" type="button" data-task-id="${Number(t.id)}">
        <span><strong>${esc(t.title)}</strong><small>${esc(t.list_name)} · #${Number(t.id)} · ${esc(t.status.replace('_',' '))}</small></span>
        <span class="priority-pill priority-${esc(t.priority)}">${esc(t.priority)}</span>
        <span class="search-due">${t.due_date ? fmtDate(t.due_date) : 'No due date'}</span>
      </button>`).join('');
      rows.querySelectorAll('[data-task-id]').forEach(btn => btn.onclick = () => openTaskModal(Number(btn.dataset.taskId)));
      results.appendChild(rows);
      taskSearchCursor = data.next_before_id;
      if (taskSearchCursor) {
        const more = document.createElement('button');
        more.id='search-load-more'; more.className='btn btn-ghost'; more.textContent='Load more results';
        more.addEventListener('click',loadTaskSearch);
        results.appendChild(more);
      }
    } catch (err) { results.innerHTML = `<p role="alert">${esc(err.message)}</p>`; }
  }

  async function renderAdvancedSettings() {
    const container = document.getElementById('view-container');
    container.innerHTML = '<p>Loading…</p>';
    let data;
    try {
      data = await api('settings.php');
    } catch (err) {
      container.innerHTML = `<div class="load-error-box">
        <p>Couldn't load settings: ${esc(err.message)}</p>
        <button type="button" class="btn btn-ghost" id="settings-retry">Try again</button>
      </div>`;
      const retryBtn = document.getElementById('settings-retry');
      if (retryBtn) retryBtn.addEventListener('click', renderAdvancedSettings);
      return;
    }
    const s = data.settings;

    container.innerHTML = `
      <div class="settings-box">
        <div class="settings-box-header"><h2>Organization</h2><p>Manage the name shown across your TaskFlow workspace.</p></div>
        <div class="field-row"><div class="field-group"><label>Organization name</label><input id="st-org-name" type="text" maxlength="150" value="${esc(s.organization_name || '')}"></div></div>
        <div class="section-title">Email notifications</div>
        <div class="settings-box-header">
          <h2>Email Notifications</h2>
          <p>Configure an SMTP server so TaskFlow can send email updates, then choose which events should send one.</p>
        </div>

        <label class="settings-toggle-row">
          <input type="checkbox" id="st-enabled" ${s.smtp_enabled ? 'checked' : ''}>
          <span><strong>Enable email notifications</strong><br><span class="person-meta">Master switch — turn this off to stop all outgoing email regardless of the checklist below.</span></span>
        </label>

        <div class="section-title">SMTP server</div>
        <div class="field-row">
          <div class="field-group"><label>Host</label><input type="text" id="st-host" placeholder="smtp.example.com" value="${esc(s.smtp_host)}"></div>
          <div class="field-group"><label>Port</label><input type="number" id="st-port" value="${s.smtp_port}" style="width:100px;"></div>
          <div class="field-group"><label>Encryption</label>
            <select id="st-encryption">
              <option value="tls" ${s.smtp_encryption==='tls'?'selected':''}>STARTTLS</option>
              <option value="ssl" ${s.smtp_encryption==='ssl'?'selected':''}>SSL</option>
              <option value="none" ${s.smtp_encryption==='none'?'selected':''}>None</option>
            </select>
          </div>
        </div>
        <div class="field-row">
          <div class="field-group"><label>Username</label><input type="text" id="st-username" value="${esc(s.smtp_username)}"></div>
          <div class="field-group"><label>Password</label><input type="password" id="st-password" placeholder="${s.has_password ? '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022 (saved \u2014 leave blank to keep)' : 'Not set'}"></div>
        </div>
        <div class="field-row">
          <div class="field-group"><label>From email</label><input type="text" id="st-from-email" placeholder="taskflow@yourcompany.com" value="${esc(s.from_email)}"></div>
          <div class="field-group"><label>From name</label><input type="text" id="st-from-name" value="${esc(s.from_name)}"></div>
        </div>

        <div class="section-title">Send an email when\u2026</div>
        <div class="settings-checklist">
          ${NOTIFY_CHECKLIST.map(item => `
            <label class="settings-toggle-row">
              <input type="checkbox" class="st-notify-check" data-key="${item.key}" ${s[item.key] ? 'checked' : ''}>
              <span>${esc(item.label)}</span>
            </label>`).join('')}
        </div>

        <div class="settings-actions">
          <button class="btn btn-primary" id="st-save">Save settings</button>
          <button class="btn btn-ghost" id="st-test">Send test email</button>
        </div>
        <p class="settings-note" id="st-note"></p>
      </div>`;

    document.getElementById('st-save').addEventListener('click', async () => {
      const body = {
        organization_name: document.getElementById('st-org-name').value.trim(),
        smtp_enabled: document.getElementById('st-enabled').checked,
        smtp_host: document.getElementById('st-host').value.trim(),
        smtp_port: Number(document.getElementById('st-port').value) || 587,
        smtp_encryption: document.getElementById('st-encryption').value,
        smtp_username: document.getElementById('st-username').value.trim(),
        from_email: document.getElementById('st-from-email').value.trim(),
        from_name: document.getElementById('st-from-name').value.trim(),
      };
      const pw = document.getElementById('st-password').value;
      if (pw) body.smtp_password = pw;
      document.querySelectorAll('.st-notify-check').forEach(cb => { body[cb.dataset.key] = cb.checked; });
      try {
        await api('settings.php', { method: 'PUT', body });
        toast('Settings saved');
        renderAdvancedSettings();
      } catch (err) { toast(err.message, true); }
    });

    document.getElementById('st-test').addEventListener('click', async () => {
      const note = document.getElementById('st-note');
      note.textContent = 'Sending…';
      note.className = 'settings-note';
      try {
        const res = await api('settings.php', { method: 'POST', body: {} });
        note.textContent = `Test email sent to ${res.sent_to}. Check your inbox (and spam folder).`;
        note.className = 'settings-note settings-note-ok';
      } catch (err) {
        note.textContent = err.message;
        note.className = 'settings-note settings-note-error';
      }
    });
  }

  async function renderUsersAdmin() {
    const container = document.getElementById('view-container');
    document.getElementById('topbar-actions').innerHTML = `<button class="btn btn-primary" id="invite-user-btn">+ Invite person</button> <button class="btn btn-ghost" id="new-user-btn">Create directly</button>`;
    document.getElementById('invite-user-btn').addEventListener('click', openInvitePrompt);
    document.getElementById('new-user-btn').addEventListener('click', openNewUserPrompt);

    container.innerHTML = '<p>Loading…</p>';
    let data;
    try {
      data = await api('users.php');
    } catch (err) {
      container.innerHTML = `<div class="load-error-box">
        <p>Couldn't load users: ${esc(err.message)}</p>
        <button type="button" class="btn btn-ghost" id="users-admin-retry">Try again</button>
      </div>`;
      const retryBtn = document.getElementById('users-admin-retry');
      if (retryBtn) retryBtn.addEventListener('click', renderUsersAdmin);
      return;
    }
    container.innerHTML = data.users.map(u => `
      <div class="member-row">
        <div class="member-info">
          <span class="avatar" style="background:${esc(u.avatar_color)}">${esc(initials(u.name))}</span>
          <div><strong>${esc(u.name)}</strong> ${!u.is_active ? '<span class="role-badge" style="color:var(--danger);">inactive</span>' : ''}
            <div style="font-size:12px;color:var(--text-faint);">${esc(u.email)} · ${esc(u.team_name || 'No team')}</div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          <span class="role-badge">${esc(rolesLabel(u.roles))}</span>
          <button class="btn btn-ghost edit-user-btn" data-user="${u.id}">Edit</button>
          ${u.is_active ? `<button class="btn btn-ghost deactivate-user-btn" data-user="${u.id}" style="color:var(--danger);">Deactivate</button>` : `<button class="btn btn-ghost reactivate-user-btn" data-user="${u.id}" style="color:var(--success);">Reactivate</button>`}
          <button class="btn btn-ghost delete-user-btn" data-user="${u.id}" data-name="${esc(u.name)}" style="color:var(--danger);">Delete</button>
        </div>
      </div>`).join('');

    container.querySelectorAll('.deactivate-user-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Deactivate this account? They will no longer be able to log in.')) return;
        try {
          await api('users.php', { method: 'DELETE', body: { id: Number(btn.dataset.user) } });
          renderUsersAdmin();
        } catch (err) { toast(err.message, true); }
      });
    });
    container.querySelectorAll('.reactivate-user-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        try {
          await api('users.php', { method: 'PUT', body: { id: Number(btn.dataset.user), is_active: true } });
          toast('Account reactivated');
          renderUsersAdmin();
        } catch (err) { toast(err.message, true); }
      });
    });
    container.querySelectorAll('.delete-user-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const name = btn.dataset.name;
        if (!confirm(`Permanently delete ${name}'s account? This removes their comments, chat messages, and task assignments too. This CANNOT be undone.`)) return;
        if (!confirm(`Really delete ${name}? Type-to-confirm isn't required, but there's no way back from this — click OK only if you're sure.`)) return;
        try {
          await api('users.php', { method: 'DELETE', body: { id: Number(btn.dataset.user), permanent: true } });
          toast('Account permanently deleted');
          renderUsersAdmin();
        } catch (err) { toast(err.message, true); }
      });
    });
    container.querySelectorAll('.edit-user-btn').forEach(btn => {
      btn.addEventListener('click', () => openEditUserPrompt(Number(btn.dataset.user), data.users));
    });
    loadInvitationManagement(container);
  }

  function showInvitationLink(url, delivery = 'copy') {
    const deliveryNote = delivery === 'email_queued' ? 'Invitation email is queued for delivery. Make sure your mail worker is running. You can also copy the private link now.' : delivery === 'email_sent' ? 'Invitation email was handed to the SMTP server. You can also copy the link as a backup.' : delivery === 'email_failed' ? 'SMTP could not send this invitation. Copy the link and deliver it privately instead.' : 'Copy and deliver the link privately.';
    promptModal('Invitation created', `<p class="settings-note">${esc(deliveryNote)} This link works once, expires after 72 hours, and cannot be shown again after closing this dialog.</p>
      <label for="invite-link-value">Secure invitation link</label>
      <input id="invite-link-value" aria-label="Secure invitation link" readonly style="width:100%;padding:10px;border:1px solid var(--border);border-radius:8px;" value="${esc(url)}">
      <button type="button" class="btn btn-ghost" id="copy-invite-link" style="margin-top:10px;">Copy link</button>`, async () => {});
    const input = document.getElementById('invite-link-value');
    input.focus(); input.select();
    document.getElementById('copy-invite-link').addEventListener('click', async () => {
      try {
        if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(input.value);
        else { input.select(); if (!document.execCommand('copy')) throw new Error('Copy not available'); }
        toast('Invitation link copied');
      } catch (_) { input.select(); toast('Select and copy the invitation link.', true); }
    });
  }

  async function openInvitePrompt(previous = null) {
    const teamOptions = await teamOptionsHtml(previous?.team_id ? Number(previous.team_id) : null);
    promptModal('Invite a teammate', `
      <div class="field-group" style="margin-bottom:9px;"><label for="invite-name">Full name</label><input id="invite-name" maxlength="100" autocomplete="off" value="${esc(previous?.name || '')}" placeholder="Full name" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:8px;"></div>
      <div class="field-group" style="margin-bottom:9px;"><label for="invite-email">Email</label><input id="invite-email" type="email" maxlength="190" autocomplete="off" value="${esc(previous?.email || '')}" placeholder="name@organization.org" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:8px;"></div>
      <div class="field-group" style="margin-bottom:9px;"><label>Roles</label><div class="role-checkbox-group">${roleCheckboxesHtml(previous?.roles || ['member'])}</div></div>
      <label for="invite-team">Team</label><select id="invite-team" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:8px;"><option value="">No team</option>${teamOptions}</select>
      <label class="settings-note" style="display:flex;gap:9px;align-items:center;margin-top:12px;"><input type="checkbox" id="invite-send-email"> Email invitation using configured SMTP</label><p class="settings-note">The person sets their own password. If SMTP is not configured, leave email delivery unchecked and copy the link instead.</p>
    `, async () => {
      const body = {
        action:'create',name:document.getElementById('invite-name').value.trim(),email:document.getElementById('invite-email').value.trim(),
        roles:[...document.querySelectorAll('.u-role-check:checked')].map(x=>x.value),
        team_id:document.getElementById('invite-team').value || null,
        delivery:document.getElementById('invite-send-email').checked ? 'email' : 'copy'
      };
      // The generic modal dismisses after this callback; show the secret in a follow-up dialog.
      const result = await api('invitations.php', {method:'POST',body});
      setTimeout(() => { renderUsersAdmin(); showInvitationLink(result.invite_url, result.delivery || 'copy'); }, 0);
    });
  }

  async function loadInvitationManagement(container) {
    if (!container.isConnected) return;
    const panel = document.createElement('section');
    panel.className = 'invitation-panel';
    panel.setAttribute('aria-label', 'Account invitations');
    panel.innerHTML = `<h3>Invitations</h3><p class="settings-note">Links expire after 72 hours and are shown only once when created.</p><p class="settings-note">Loading invitations…</p>`;
    container.appendChild(panel);
    try {
      const data = await api('invitations.php');
      if (!panel.isConnected) return;
      const rows = data.invitations || [];
      panel.innerHTML = `<h3>Invitations</h3><p class="settings-note">Pending links can be revoked here. Secrets are not recoverable; create a new invitation to replace a lost link.</p>` +
        (rows.length ? rows.map(x => `<div class="member-row invite-row">
          <div class="member-info"><div><strong>${esc(x.name)}</strong> <span class="role-badge">${esc(x.status)}</span>
          <div style="font-size:12px;color:var(--text-faint);">${esc(x.email)} · ${esc((x.roles||[]).join(', '))} · ${esc(x.team_name || 'No team')} · expires ${esc(x.expires_at)}</div></div></div>
          ${x.status==='pending'?`<button class="btn btn-ghost invite-revoke" type="button" data-invite-id="${Number(x.id)}">Revoke</button>`:''}
          ${x.status!=='accepted'?`<button class="btn btn-ghost invite-reissue" type="button" data-invite-id="${Number(x.id)}">Replace link</button>`:''}
        </div>`).join('') : '<p class="settings-note">No invitations created yet.</p>');
      panel.querySelectorAll('.invite-reissue').forEach(btn => btn.addEventListener('click', () => {
        const earlier = rows.find(x => Number(x.id) === Number(btn.dataset.inviteId));
        if (earlier) openInvitePrompt(earlier);
      }));
      panel.querySelectorAll('.invite-revoke').forEach(btn => btn.addEventListener('click',async () => {
        if (!confirm('Revoke this invitation? Its link will stop working.')) return;
        btn.disabled = true;
        try {
          await api('invitations.php',{method:'POST',body:{action:'revoke',id:Number(btn.dataset.inviteId)}});
          toast('Invitation revoked');
          renderUsersAdmin();
        } catch(e) { btn.disabled=false; toast(e.message,true); }
      }));
    } catch (e) { panel.innerHTML = `<h3>Invitations</h3><p class="load-error-box">${esc(e.message)}</p>`; }
  }

  async function teamOptionsHtml(selectedId) {
    try {
      const teamData = await api('teams.php');
      return teamData.teams.map(t => `<option value="${t.id}" ${t.id === selectedId ? 'selected' : ''}>${esc(t.name)}</option>`).join('');
    } catch (err) {
      toast('Could not load teams: ' + err.message, true);
      return '';
    }
  }

  function roleCheckboxesHtml(selectedRoles) {
    selectedRoles = selectedRoles || [];
    return ['administrator', 'manager', 'member'].map(r => `
      <label class="role-checkbox">
        <input type="checkbox" class="u-role-check" value="${r}" ${selectedRoles.includes(r) ? 'checked' : ''}> ${roleLabel(r)}
      </label>`).join('');
  }

  async function openNewUserPrompt() {
    const teamOptions = await teamOptionsHtml(null);
    promptModal('New account', `
      <div class="field-group" style="margin-bottom:8px;"><label class="sr-only" for="u-name">Full name</label><input id="u-name" placeholder="Full name" autocomplete="name" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;"></div>
      <div class="field-group" style="margin-bottom:8px;"><label class="sr-only" for="u-email">Email address</label><input id="u-email" type="email" placeholder="Email" autocomplete="email" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;"></div>
      <div class="field-group" style="margin-bottom:12px;"><label class="sr-only" for="u-pass">Temporary password (at least 12 bytes)</label><input id="u-pass" type="password" autocomplete="new-password" placeholder="Temporary password (min 12 bytes)" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;"></div>
      <div class="field-group" style="margin-bottom:8px;">
        <label style="display:block;margin-bottom:6px;">Roles (select one or more — e.g. Administrator AND Manager)</label>
        <div class="role-checkbox-group" role="group" aria-label="Account permissions">${roleCheckboxesHtml(['member'])}</div>
      </div>
      <select id="u-team" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;">
        <option value="">No team</option>${teamOptions}
      </select>
      <p style="font-size:12px;color:var(--text-faint);margin-top:8px;">They'll be required to set their own password on first login.</p>
    `, async () => {
      const name = document.getElementById('u-name').value.trim();
      const email = document.getElementById('u-email').value.trim();
      const password = document.getElementById('u-pass').value;
      const roles = Array.from(document.querySelectorAll('.u-role-check:checked')).map(cb => cb.value);
      const team_id = document.getElementById('u-team').value || null;
      // Let promptModal surface failures without dismissing or discarding credentials.
      await api('users.php', { method: 'POST', body: { name, email, password, roles, team_id } });
      toast('Account created');
      renderUsersAdmin();
    });
  }

  async function openEditUserPrompt(userId, users) {
    const u = users.find(x => x.id === userId);
    const teamOptions = await teamOptionsHtml(u.team_id);
    promptModal('Edit account', `
      <div class="field-group" style="margin-bottom:8px;">
        <label style="display:block;margin-bottom:6px;">Roles (select one or more)</label>
        <div class="role-checkbox-group">${roleCheckboxesHtml(u.roles)}</div>
      </div>
      <select id="u-team" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;margin-bottom:8px;">
        <option value="">No team</option>${teamOptions}
      </select>
      <input id="u-newpass" type="password" autocomplete="new-password" placeholder="Reset password (leave blank to keep current)" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;">
    `, async () => {
      const roles = Array.from(document.querySelectorAll('.u-role-check:checked')).map(cb => cb.value);
      const team_id = document.getElementById('u-team').value || null;
      const new_password = document.getElementById('u-newpass').value;
      const body = { id: userId, roles, team_id };
      if (new_password) body.new_password = new_password;
      await api('users.php', { method: 'PUT', body });
      toast('Account updated');
      renderUsersAdmin();
    });
  }

  // ---------------------------------------------------------------
  // MODAL KEYBOARD ACCESSIBILITY (task details and secondary prompts).
  // ---------------------------------------------------------------
  const dialogFocusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  function dialogFocusables(modal) {
    return [...modal.querySelectorAll(dialogFocusableSelector)].filter(el =>
      el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden' &&
      !el.closest('[hidden], .hidden'));
  }
  function returnDialogFocus(previous) {
    for (const target of [previous, document.getElementById('tm-tab-' + taskDetailTab),
      document.getElementById('close-task-modal'), document.getElementById('quick-switch-btn')]) {
      if (target?.isConnected && target.getClientRects().length) { target.focus(); break; }
    }
  }
  let taskModalReturnFocus = null;
  let taskModalRequestSeq = 0;
  function syncDialogAccessibility() {
    const task = document.getElementById('task-modal');
    const prompt = document.getElementById('prompt-modal');
    const promptShown = !prompt.classList.contains('hidden');
    const taskShown = !task.classList.contains('hidden');
    task.setAttribute('aria-hidden',String(promptShown || !taskShown));
    const app = document.getElementById('app');
    if (promptShown || taskShown) app.setAttribute('aria-hidden','true');
    else app.removeAttribute('aria-hidden');
  }
  document.addEventListener('keydown', event => {
    if (event.key !== 'Tab') return;
    const quick = document.getElementById('quick-switch-modal');
    if (!quick.classList.contains('hidden')) return; // command palette has its own trap.
    const prompt = document.getElementById('prompt-modal');
    const task = document.getElementById('task-modal');
    const active = !prompt.classList.contains('hidden') ? prompt
      : !task.classList.contains('hidden') ? task : null;
    if (!active) return;
    const controls = dialogFocusables(active);
    if (!controls.length) { event.preventDefault(); return; }
    const index = controls.indexOf(document.activeElement);
    if (index < 0 || (event.shiftKey && index === 0) || (!event.shiftKey && index === controls.length - 1)) {
      event.preventDefault();
      (event.shiftKey ? controls[controls.length - 1] : controls[0]).focus();
    }
  });

  // ---------------------------------------------------------------
  // TASK MODAL
  // ---------------------------------------------------------------
  // Keep the active detail tab while saving steps, not when switching tasks.
  let taskDetailTab = 'details';
  let taskDetailCurrentId = null;
  let commentReplyTo = null;
  async function openTaskModal(taskId) {
    const modal = document.getElementById('task-modal');
    const wasClosed = modal.classList.contains('hidden');
    const requestSeq = ++taskModalRequestSeq;
    if (taskDetailCurrentId !== Number(taskId)) {
      commentReplyTo = null;
      taskDetailTab = 'details';
      taskDetailCurrentId = Number(taskId);
    }
    if (wasClosed) taskModalReturnFocus = document.activeElement;
    modal.classList.remove('hidden');
    if (wasClosed) document.getElementById('close-task-modal').focus();
    syncDialogAccessibility();
    document.getElementById('task-modal-body').innerHTML = '<p role="status">Loading task details…</p>';
    const stillCurrent = () => requestSeq === taskModalRequestSeq &&
      !modal.classList.contains('hidden') && taskDetailCurrentId === Number(taskId);
    try {
      const { task } = await api('task.php?id=' + taskId);
      if (!stillCurrent()) return;
      const teamId=Number(task.team_id);
      if (!state.modalContexts[teamId]) {
        const [people, tags] = await Promise.all([api('users.php'), api('tags.php?team_id='+teamId)]);
        if (!stillCurrent()) return;
        state.modalContexts[teamId] = {
          members: (people.users || []).filter(u => Number(u.team_id ?? teamId)===teamId && (u.is_active===undefined || !!Number(u.is_active))),
          tags: tags.tags || [],
        };
      }
      if (!stillCurrent()) return;
      const previousId = modal.contains(document.activeElement) ? document.activeElement.id : '';
      renderTaskModal(task, state.modalContexts[teamId]);
      if (!document.getElementById('prompt-modal').classList.contains('hidden') || modal.contains(document.activeElement)) return;
      (previousId && document.getElementById(previousId) || document.getElementById('tm-tab-'+taskDetailTab)
        || document.getElementById('close-task-modal'))?.focus();
    } catch (err) {
      if (!stillCurrent()) return;
      document.getElementById('task-modal-body').innerHTML = `<div class="load-error-box" role="alert">
        <p>Couldn't load this task: ${esc(err.message)}</p>
        <button type="button" class="btn btn-ghost" id="task-modal-retry">Try again</button>
      </div>`;
      const retryBtn = document.getElementById('task-modal-retry');
      if (retryBtn) retryBtn.addEventListener('click', () => openTaskModal(taskId));
    }
  }

  async function loadTaskAttachments(taskId) {
    const list=document.getElementById('tm-attachments');
    if(!list||Number(list.dataset.taskId)!==Number(taskId))return;
    try{
      const data=await api('attachments.php?task_id='+Number(taskId));
      if(!list.isConnected||Number(list.dataset.taskId)!==Number(taskId))return;
      const canManage=iHave(['administrator','manager']);
      list.innerHTML=(data.attachments||[]).length ? (data.attachments||[]).map(a=>{
        const id=Number(a.id);
        const canRemove=canManage||Number(a.uploaded_by)===Number(state.user.id);
        return `<div class="attachment-item"><div class="attachment-meta">
          <a href="api/attachments.php?download_id=${id}" class="attachment-link" download>${esc(a.filename)}</a>
          <small>${Math.max(1,Math.ceil(Number(a.size_bytes)/1024))} KB · ${esc(a.uploader||'Former member')} · ${esc(a.created_at)}</small></div>
          ${canRemove?`<button type="button" class="btn btn-ghost attachment-remove" data-attachment-id="${id}">Remove</button>`:''}</div>`;
      }).join('') : '<p class="dependency-empty">No attachments yet.</p>';
      list.querySelectorAll('.attachment-remove').forEach(button=>button.addEventListener('click',async()=>{
        if(!confirm('Remove this file from TaskFlow?'))return;
        button.disabled=true;
        try{await api('attachments.php',{method:'DELETE',body:{id:Number(button.dataset.attachmentId)}});
          toast('Attachment removed');loadTaskAttachments(taskId);
        }catch(e){toast(e.message,true);button.disabled=false;}
      }));
    }catch(error){if(list.isConnected)list.textContent=error.message;}
  }

  function renderTaskModal(task, context = {members: state.members, tags: state.tags}) {
    const body = document.getElementById('task-modal-body');
    const canAssignOthers = iHave(['administrator', 'manager']);
    const canManageDeps = canAssignOthers || Number(task.created_by) === Number(state.user.id)
      || (task.assignees || []).some(a => Number(a.id)===Number(state.user.id));
    const canEditTask = canManageDeps;
    const deps = task.dependencies || { depends_on:[], blocks:[] };
    const unfinished = Number(task.unfinished_blockers || 0);
    const assigneeOptions = context.members.map(m =>
      `<option value="${m.id}" ${task.assignees.some(a => a.id === m.id) ? 'selected' : ''}>${esc(m.name)}${m.roles && m.roles.length ? ' (' + esc(rolesLabel(m.roles)) + ')' : ''}</option>`
    ).join('');
    const tagOptions = context.tags.map(t =>
      `<option value="${t.id}" ${task.tags.some(x => x.id === t.id) ? 'selected' : ''}>${esc(t.name)}</option>`
    ).join('');

    const checklistTotal=(task.checklist||[]).length;
    const checklistDone=(task.checklist||[]).filter(item=>!!Number(item.is_done)).length;
    const checklistPct=checklistTotal ? Math.round(checklistDone/checklistTotal*100) : 0;
    const detailTabs=[
      ['details','Details'],['checklist',`Checklist <span class="task-tab-count">${checklistTotal}</span>`],
      ['files','Files &amp; activity'],['comments',`Discussion <span class="task-tab-count">${(task.comments||[]).length}</span>`]
    ];
    body.innerHTML = `
      <header class="task-detail-heading"><span>PROJECT TASK · #${Number(task.id)}</span><strong>${esc(task.title)}</strong></header>
      <div class="task-detail-tabbar" role="tablist" aria-label="Task information">
        ${detailTabs.map(([key,label])=>`<button type="button" id="tm-tab-${key}" class="task-detail-tab" data-task-tab="${key}" role="tab" aria-controls="tm-panel-${key}" aria-selected="${taskDetailTab===key}">${label}</button>`).join('')}
      </div>
      <section class="task-detail-panel" id="tm-panel-details" data-task-panel="details" role="tabpanel" aria-labelledby="tm-tab-details" ${taskDetailTab!=='details'?'hidden':''}>
      <label class="task-detail-field-label" for="tm-title">Task title</label>
      <input id="tm-title" value="${esc(task.title)}" style="font-size:19px;font-weight:700;border:none;width:100%;margin-bottom:10px;" />
      <textarea id="tm-desc" placeholder="Add a description…">${esc(task.description || '')}</textarea>

      <div class="field-row">
        <div class="field-group"><label>Status</label>
          <select id="tm-status">${['to_do','in_progress','review','done'].map(s => `<option value="${s}" ${task.status===s?'selected':''}>${s.replace('_',' ')}</option>`).join('')}</select>
        </div>
        <div class="field-group"><label>Priority</label>
          <select id="tm-priority">${['none','low','normal','high','urgent'].map(p => `<option value="${p}" ${task.priority===p?'selected':''}>${p}</option>`).join('')}</select>
        </div>
        <div class="field-group"><label>Start</label><input type="date" id="tm-start" value="${task.start_date || ''}"></div>
        <div class="field-group"><label>Due</label><input type="date" id="tm-due" value="${task.due_date || ''}"></div>
        <div class="field-group"><label for="tm-estimate">Estimate (hours)</label><input type="number" id="tm-estimate" min="0.0167" max="400" step="any" value="${hoursInput(task.estimate_minutes)}" placeholder="Not estimated"></div>
        <div class="field-group"><label for="tm-repeat-rule">Repeat after completion</label><select id="tm-repeat-rule" ${task.parent_task_id ? 'disabled' : ''}>${[['none','Does not repeat'],['daily','Every day'],['weekly','Every week'],['biweekly','Every two weeks'],['monthly','Every month']].map(([value,label])=>`<option value="${value}" ${value===(task.recurrence_rule || (Number(task.is_recurring_weekly)?'weekly':'none'))?'selected':''}>${label}</option>`).join('')}</select></div>
      </div>

      <div class="field-row">
        <div class="field-group" style="min-width:180px;"><label>Assignees ${canAssignOthers ? '' : '(Admin/Manager only)'}</label>
          <select id="tm-assignees" multiple size="3" ${canAssignOthers ? '' : 'disabled'}>${assigneeOptions}</select>
        </div>
        <div class="field-group" style="min-width:160px;"><label>Tags</label>
          <select id="tm-tags" multiple size="3">${tagOptions}</select>
          ${canEditTask ? '<button type="button" class="comment-delete" id="tm-new-tag" style="color:var(--accent);">+ new tag</button>' : ''}
        </div>
      </div>

      ${canEditTask ? '<button class="btn btn-primary" id="tm-save">Save changes</button>' : '<p class="dependency-help">Read-only task. Only its creator, assignees or managers can change it.</p>'}
      <button class="btn btn-ghost" id="tm-star" type="button" aria-pressed="${task.is_favorite ? 'true' : 'false'}">${task.is_favorite ? '★ Starred' : '☆ Star task'}</button>
      <button class="btn btn-ghost" id="tm-watch" type="button" aria-pressed="${!!task.is_watching}">${task.is_watching?'Watching task':'Watch task'} · ${Number(task.watcher_count||0)}</button>
      <button class="btn btn-ghost" id="tm-duplicate" type="button">Duplicate task</button>
      ${state.lists.filter(l=>Number(l.id)!==Number(task.list_id)).length?'<button class="btn btn-ghost" id="tm-copy-project" type="button">Copy to project…</button>':''}
      ${canAssignOthers ? '<button type="button" class="btn btn-ghost" id="tm-save-template">Save as template</button>' : ''}
      ${Number(task.recurrence_generated_from) ? '<p class="dependency-help">Created automatically from recurring task #'+Number(task.recurrence_generated_from)+'.</p>' : ''}
      ${(iHave(['administrator', 'manager']) || Number(task.created_by) === Number(state.user.id)) ? '<button class="btn btn-ghost" id="tm-delete" style="color:var(--danger);">Delete task</button>' : ''}

      ${task.milestone?`<p class="milestone-inline">Milestone <button type="button" id="tm-milestone" class="milestone-inline-link">${esc(task.milestone.title)}</button>${task.milestone.due_date?' · Target '+fmtDate(task.milestone.due_date):''}</p>`:''}

      <section class="task-review-inline" id="tm-review-section" aria-label="Task review">
        <div class="task-review-inline-head"><h3>Review</h3><span class="task-review-status task-review-status-${esc(task.review?.status||'none')}">${task.review?taskReviewStatusLabel(task.review.status):'Not requested'}</span></div>
        ${task.review?.request_note?`<p class="task-review-note">${esc(task.review.request_note)}</p>`:''}
        ${task.review?.reviewer_note?`<p class="task-review-note task-review-feedback"><strong>Feedback:</strong> ${esc(task.review.reviewer_note)}</p>`:''}
        <div class="task-review-inline-actions">
          ${canEditTask && task.status!=='done' && task.review?.status!=='pending' ? '<button type="button" class="btn btn-primary" id="tm-review-request">Request review</button>':''}
          ${task.review?.status==='pending' && (Number(task.review.submitted_by)===Number(state.user.id) || taskReviewManager())?'<button type="button" class="btn btn-ghost" id="tm-review-cancel">Cancel review</button>':''}
          <button type="button" class="btn btn-ghost" id="tm-review-queue">Open review queue</button>
        </div>
        ${task.review_events?.length?`<details class="task-review-history"><summary>Decision history (${task.review_events.length} recent)</summary>
           <ol>${task.review_events.map(item=>`<li><strong>${esc(item.actor_name)}</strong> ${esc(item.event_type.replaceAll('_',' '))} · ${esc(item.created_at)}
            ${item.note?`<p>${esc(item.note)}</p>`:''}</li>`).join('')}</ol></details>`:''}
      </section>

      <div class="section-title">Dependencies</div>
      <p class="dependency-help">${unfinished ? `${unfinished} unfinished prerequisite(s) must be completed before this task can be marked done.` : 'Complete prerequisite tasks first to unblock dependent work.'}</p>
      <div class="dependency-list" id="tm-blocker-list">
        ${(deps.depends_on || []).map(d => `<div class="dependency-item">
          <button type="button" class="dependency-open" data-dependency-id="${Number(d.id)}">#${Number(d.id)} ${esc(d.title)}</button>
          <span class="status-pill status-${esc(d.status)}">${esc(d.status.replace('_',' '))}</span>
          ${canManageDeps ? `<button type="button" class="dependency-remove" data-blocker-id="${Number(d.id)}" aria-label="Remove blocker ${esc(d.title)}">Remove</button>` : ''}
        </div>`).join('') || '<p class="dependency-empty">No blocking tasks.</p>'}
      </div>
      ${canManageDeps ? `<label class="dependency-label" for="tm-blocker-search">Add a prerequisite from this team</label>
        <input id="tm-blocker-search" type="search" maxlength="120" autocomplete="off" placeholder="Search for a task to block this one..." aria-label="Find a blocking task">
        <div id="tm-blocker-results" class="dependency-search-results" aria-live="polite"></div>` : ''}
      ${(deps.blocks || []).length ? `<p class="dependency-help">This task blocks: ${(deps.blocks || []).map(d => `<button type="button" class="dependency-open dependency-chip" data-dependency-id="${Number(d.id)}">#${Number(d.id)} ${esc(d.title)}</button>`).join(' ')}</p>` : ''}

      </section>
      <section class="task-detail-panel" id="tm-panel-checklist" data-task-panel="checklist" role="tabpanel" aria-labelledby="tm-tab-checklist" ${taskDetailTab!=='checklist'?'hidden':''}>
      <div class="task-checklist-head"><div><h3>Checklist</h3><p>Break work into small steps. Use the arrows to reorder them.</p></div>
      <span id="tm-checklist-count" class="task-checklist-count">${checklistDone} of ${checklistTotal} done</span></div>
      <div id="tm-checklist-progress" class="task-checklist-progress" role="progressbar" aria-label="Checklist completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${checklistPct}"><span style="width:${checklistPct}%"></span></div>
      <div id="tm-checklist" class="task-checklist-rows">${task.checklist.map((item,index)=>checklistItemHtml(item,canEditTask,index,checklistTotal)).join('') || '<p class="dependency-empty">No checklist items yet. Add your first step below.</p>'}</div>
      ${canEditTask ? `<form class="add-inline-form task-checklist-add" id="tm-add-checklist">
        <label for="tm-checklist-input" class="sr-only">New checklist step</label>
        <input placeholder="Add a checklist step…" id="tm-checklist-input" maxlength="255">
        <button class="btn btn-primary" type="submit">Add step</button>
      </form>
      <div class="task-checklist-toolbar"><button type="button" id="tm-paste-open" class="btn btn-ghost">Paste multiple steps</button><span>Up to 30 steps at once, 200 per task</span></div>
      <form id="tm-paste-form" class="task-checklist-paste hidden">
        <label for="tm-paste-text">Paste one step per line</label>
        <textarea id="tm-paste-text" maxlength="8000" rows="6" placeholder="Confirm requirements&#10;Prepare design&#10;Review and approve"></textarea>
        <div><button type="submit" class="btn btn-primary">Add steps</button><button type="button" class="btn btn-ghost" id="tm-paste-cancel">Cancel</button></div>
      </form>`:''}
      </section>
      <section class="task-detail-panel" id="tm-panel-files" data-task-panel="files" role="tabpanel" aria-labelledby="tm-tab-files" ${taskDetailTab!=='files'?'hidden':''}>
      <div class="section-title">Attachments</div>
      <p class="dependency-help">Files remain private to authorized team members. Downloads require sign-in.</p>
      <div id="tm-attachments" data-task-id="${Number(task.id)}" aria-live="polite">Loading attachments…</div>
      ${canEditTask?`<form id="tm-attachment-form" class="attachment-upload-form" enctype="multipart/form-data">
        <label for="tm-attachment-file">Attach a file (up to 8 MiB)</label>
        <input type="file" id="tm-attachment-file" accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.csv,.docx,.xlsx" required>
        <button class="btn btn-ghost" type="submit">Upload file</button>
      </form>`:''}
      <div class="section-title">Recent activity</div>
      <div class="task-activity-list">${(task.recent_activity || []).map(a=>`<div class="task-activity-item"><strong>${esc(a.actor)}</strong> ${esc((a.action||'').replaceAll('_',' '))} <span class="comment-time">${esc(a.created_at)}</span></div>`).join('') || '<p class="dependency-empty">No logged changes yet.</p>'}</div>

      <div class="section-title">Effort against estimate</div>
      <p class="dependency-help">${task.estimate_minutes?`Planned ${hoursText(task.estimate_minutes)}${task.logged_minutes!=null ? ` · Logged ${hoursText(task.logged_minutes)} (${Math.round(100*Number(task.logged_minutes)/Number(task.estimate_minutes))}%)` : ''}`:'No estimate set yet.'} Time may include entries from different weeks.</p>
      <div class="section-title">Time tracking</div>
      <p class="dependency-help">Manually log work spent on this task. Entries are attributed to your account. Managers can view team worklogs.</p>
      <div id="tm-time-panel" data-task-id="${Number(task.id)}" aria-live="polite">Loading time entries…</div>
      </section>
      <section class="task-detail-panel" id="tm-panel-comments" data-task-panel="comments" role="tabpanel" aria-labelledby="tm-tab-comments" ${taskDetailTab!=='comments'?'hidden':''}>
      <div class="task-discussion-head"><h3>Discussion <span class="task-comment-count">${Number(task.comments?.length||0)} recent</span></h3><p>Reply to a teammate or use the mention picker to notify someone by name.</p></div>
      <div class="task-comment-history" id="tm-comment-history" ${task.comments_next_before_id?'':'hidden'}>
        <button type="button" class="btn btn-ghost" id="tm-comment-older" data-before="${Number(task.comments_next_before_id||0)}">Load earlier comments</button>
      </div>
      <div id="tm-comments" class="task-discussion-list" aria-live="polite">${task.comments.map(commentHtml).join('') || '<p class="dependency-empty">No comments yet. Start the conversation.</p>'}</div>
      <form class="task-comment-composer" id="tm-add-comment">
        <div class="task-reply-banner" id="tm-reply-banner" hidden><span>Replying to <strong id="tm-reply-name"></strong></span><button type="button" class="btn btn-ghost" id="tm-reply-cancel">Cancel reply</button></div>
        <label for="tm-comment-input" class="task-comment-label">Write a comment</label>
        <textarea placeholder="Share an update or ask a question…" id="tm-comment-input" maxlength="3000" rows="3" required></textarea>
        <div class="task-comment-composer-actions"><label for="tm-mention-member">Mention teammate<select id="tm-mention-member"><option value="">Choose a teammate…</option>${context.members.filter(m=>Number(m.id)!==Number(state.user.id) && Number(m.is_active??1)===1).map(m=>`<option value="${Number(m.id)}">${esc(m.name)}</option>`).join('')}</select></label>
          <span class="task-comment-hint">Ctrl/⌘ + Enter to post</span><button class="btn btn-primary" type="submit">Post comment</button></div>
      </form>
      </section>
    `;

    body.querySelectorAll('[data-task-tab]').forEach(tab => tab.addEventListener('click', () => {
      const key=tab.dataset.taskTab;
      taskDetailTab=key;
      body.querySelectorAll('[data-task-tab]').forEach(node=>{
        node.setAttribute('aria-selected',String(node===tab));
        node.tabIndex=node===tab?0:-1;
      });
      body.querySelectorAll('[data-task-panel]').forEach(panel=>panel.hidden=panel.dataset.taskPanel!==key);
      // The content changes without a navigation event or extra server request.
    }));
    body.querySelectorAll('[data-task-tab]').forEach(tab=>tab.tabIndex=tab.dataset.taskTab===taskDetailTab?0:-1);
    body.querySelector('.task-detail-tabbar')?.addEventListener('keydown',event=>{
      const keys=['ArrowLeft','ArrowRight','Home','End'];if(!keys.includes(event.key))return;
      event.preventDefault();
      const tabs=[...body.querySelectorAll('[data-task-tab]')];
      const index=tabs.indexOf(document.activeElement);
      if(index<0)return;
      const next=event.key==='Home'?0:event.key==='End'?tabs.length-1:
        (index+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;
      tabs[next].focus();tabs[next].click();
    });

    body.querySelector('#tm-review-request')?.addEventListener('click',()=>{
      promptModal('Request task review',`<label for="p-review-note">What should your reviewer know? <small>(optional)</small></label>
        <textarea id="p-review-note" maxlength="1000" rows="3" placeholder="Summarize the changes for your reviewer" style="width:100%;min-height:95px;"></textarea>`,async()=>{
          const note=document.getElementById('p-review-note').value.trim();
          await api('task_reviews.php',{method:'POST',body:{team_id:Number(task.team_id),task_id:Number(task.id),action:'request',note}});
          toast('Review requested');await openTaskModal(task.id);
        });
    });
    body.querySelector('#tm-review-cancel')?.addEventListener('click',()=>{
      promptModal('Cancel task review',`<p>Move the task back to In progress and cancel the pending review?</p>`,async()=>{
        await api('task_reviews.php',{method:'POST',body:{team_id:Number(task.team_id),task_id:Number(task.id),
          action:'cancel',version:Number(task.review.version),note:''}});
        toast('Review cancelled');await openTaskModal(task.id);
      });
    });
    body.querySelector('#tm-review-queue')?.addEventListener('click',()=>{
      closeTaskModal();taskReviewScope=taskReviewManager()?'team':'mine';taskReviewStatus='pending';setView('task-reviews');
    });
    document.getElementById('tm-save')?.addEventListener('click', () => saveTask(task.id));
    if (!canEditTask) ['tm-title','tm-desc','tm-status','tm-priority','tm-start','tm-due','tm-estimate','tm-repeat-rule','tm-tags','tm-assignees'].forEach(id => {
      const input=document.getElementById(id);if(input) input.disabled=true;
    });
    document.getElementById('tm-star').addEventListener('click', async (event) => {
      const button = event.currentTarget;
      button.disabled = true;
      try {
        const saved = await api('favorites.php', {method:task.is_favorite ? 'DELETE' : 'POST',body:{task_id:Number(task.id)}});
        task.is_favorite = saved.is_favorite;
        button.textContent = saved.is_favorite ? '★ Starred' : '☆ Star task';
        button.setAttribute('aria-pressed', String(saved.is_favorite));
        if (state.currentView === 'my-work' && document.getElementById('my-work-mode')?.value === 'favorites') { myWorkCursor = null; loadMyWork(); }
      } catch(e) { toast(e.message, true); }
      finally { button.disabled = false; }
    });
    document.getElementById('tm-watch').addEventListener('click',async event=>{
      const button=event.currentTarget;button.disabled=true;
      try{
        const result=await api('task_watch.php',{method:task.is_watching?'DELETE':'POST',body:{task_id:Number(task.id)}});
        task.is_watching=!!result.is_watching;task.watcher_count=Number(result.watcher_count||0);
        button.textContent=(task.is_watching?'Watching task':'Watch task')+' · '+task.watcher_count;
        button.setAttribute('aria-pressed',String(task.is_watching));
        toast(task.is_watching?'You will receive task updates.':'Task notifications turned off.');
      }catch(err){toast(err.message,true);}finally{button.disabled=false;}
    });
    loadTaskWorklog(task.id);
    loadTaskAttachments(task.id);
    document.getElementById('tm-attachment-form')?.addEventListener('submit', async event=>{
      event.preventDefault();
      const form=event.currentTarget, file=form.querySelector('#tm-attachment-file').files?.[0];
      if(!file)return;
      if(file.size>8*1024*1024){toast('File must be 8 MiB or smaller.',true);return;}
      const data=new FormData();data.append('task_id',String(task.id));data.append('file',file);
      const button=form.querySelector('button');button.disabled=true;
      try{
        const response=await fetch('api/attachments.php',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':state.csrf},body:data});
        const result=await response.json();
        if(response.status===401)showAuthScreen();
        if(!response.ok)throw new Error(result.error||'Could not upload file.');
        form.reset();toast('Attachment uploaded');loadTaskAttachments(task.id);
      }catch(error){toast(error.message,true);}finally{button.disabled=false;}
    });
    document.getElementById('tm-duplicate').addEventListener('click', () => duplicateTask(task.id));
    document.getElementById('tm-copy-project')?.addEventListener('click',()=>{
      const targets=state.lists.filter(l=>Number(l.id)!==Number(task.list_id));
      if(!targets.length)return;
      promptModal('Copy task to project', `<p class="dependency-help">Copy the title, details, checklist and team labels. The copy starts as To do, without assignees or dates.</p>
        <label for="p-copy-list">Destination project</label><select id="p-copy-list" required>${targets.map(l=>`<option value="${Number(l.id)}">${esc(l.name)}</option>`).join('')}</select>`,async()=>{
        const destination=Number(document.getElementById('p-copy-list').value);
        if(!Number.isSafeInteger(destination)||!targets.some(l=>Number(l.id)===destination))throw new Error('Choose a valid project.');
        const result=await api('duplicate.php',{method:'POST',body:{id:task.id,list_id:destination}});
        toast('Task copied to selected project. Assignees and dates were reset.');
        await openTaskModal(result.id);
      });
    });
    document.getElementById('tm-save-template')?.addEventListener('click', () => {
      const items = (task.checklist || []).map(x => x.content);
      promptModal('Save task as template', `<p class="template-hint">Save a reusable task with checklist items. Assignees and dates will not be saved.</p>
        <label for="p-tpl-title">Template name</label><input id="p-tpl-title" maxlength="255" value="${esc(task.title)}" required>
        <label for="p-tpl-description">Description</label><textarea id="p-tpl-description" maxlength="5000">${esc(task.description || '')}</textarea>`, async () => {
        const title = document.getElementById('p-tpl-title').value.trim();
        if (!title) throw new Error('Enter a template name.');
        await api('templates.php', { method:'POST', body:{team_id:Number(task.team_id), title,
          description:document.getElementById('p-tpl-description').value, priority:task.priority, estimate_minutes:task.estimate_minutes || null, items} });
        toast('Template saved');
      });
    });
    document.getElementById('tm-delete')?.addEventListener('click', () => deleteTask(task.id));
    document.getElementById('tm-milestone')?.addEventListener('click',async()=>{
      closeTaskModal();
      if(Number(state.currentTeamId)!==Number(task.team_id)&&iHave(['administrator'])){
        state.currentTeamId=Number(task.team_id);
        const selector=document.getElementById('team-select');if(selector)selector.value=String(task.team_id);
        await loadTeamContext();
      }
      milestoneSelectedId=Number(task.milestone.id);
      milestoneListFilter='';
      await setView('milestones');
    });
    body.querySelectorAll('.dependency-open').forEach(btn => btn.addEventListener('click', () => openTaskModal(Number(btn.dataset.dependencyId))));
    body.querySelectorAll('.dependency-remove').forEach(btn => btn.addEventListener('click', async () => {
      if (!confirm('Remove this task prerequisite?')) return;
      try {
        await api('dependencies.php',{method:'DELETE',body:{task_id:Number(task.id),blocker_id:Number(btn.dataset.blockerId)}});
        toast('Prerequisite removed');openTaskModal(task.id);
      } catch(e) {toast(e.message,true);}
    }));
    const blockerSearch=body.querySelector('#tm-blocker-search');
    if (blockerSearch) {
      let querySerial=0;
      let debounce=null;
      blockerSearch.addEventListener('input', () => {
        clearTimeout(debounce);
        const serial=++querySerial;
        const value=blockerSearch.value.trim();
        const results=body.querySelector('#tm-blocker-results');
        if (value.length < 2) {results.textContent='Type at least two characters to search.';return;}
        debounce=setTimeout(async () => {
          try {
            const params=new URLSearchParams({team_id:String(task.team_id),q:value,limit:'12'});
            const response=await api('search.php?'+params);
            if (serial!==querySerial || document.getElementById('tm-blocker-search')!==blockerSearch) return;
            const existing=new Set((deps.depends_on||[]).map(d=>Number(d.id)));
            const candidates=response.tasks.filter(t=>Number(t.id)!==Number(task.id) && !existing.has(Number(t.id)));
            results.innerHTML=candidates.length ? candidates.map(t=>`<button type="button" class="dependency-suggestion" data-blocker-id="${Number(t.id)}"><strong>${esc(t.title)}</strong><small>${esc(t.list_name)} · ${esc(t.status.replaceAll('_',' '))}</small></button>`).join('') : '<p class="dependency-empty">No matching tasks.</p>';
            results.querySelectorAll('.dependency-suggestion').forEach(btn=>btn.addEventListener('click',async () => {
              btn.disabled=true;
              try {
                await api('dependencies.php',{method:'POST',body:{task_id:Number(task.id),blocker_id:Number(btn.dataset.blockerId)}});
                toast('Prerequisite added');openTaskModal(task.id);
              } catch(e) {toast(e.message,true);btn.disabled=false;}
            }));
          } catch(e) {if (serial===querySerial) results.textContent=e.message;}
        },250);
      });
    }
    document.getElementById('tm-add-checklist')?.addEventListener('submit', e => addChecklistItem(e, task.id));
    // Checklist power tools: bounded paste, inline rename/remove, stale-safe reordering.
    document.getElementById('tm-paste-open')?.addEventListener('click',()=>{
      document.getElementById('tm-paste-form').classList.remove('hidden');
      document.getElementById('tm-paste-text').focus();
    });
    document.getElementById('tm-paste-cancel')?.addEventListener('click',()=>document.getElementById('tm-paste-form').classList.add('hidden'));
    document.getElementById('tm-paste-form')?.addEventListener('submit',async event=>{
      event.preventDefault();
      const form=event.currentTarget, input=form.querySelector('#tm-paste-text');
      const rows=input.value.trim().split(/\r?\n/).map(x=>x.trim());
      if(rows.length<1||rows.length>30||rows.some(x=>!x||x.length>255||/[\x00-\x1F\x7F]/.test(x))){
        toast('Paste 1–30 nonempty steps, up to 255 characters per line.',true);return;
      }
      const button=form.querySelector('[type=submit]');button.disabled=true;
      try{
        const result=await api('checklist.php',{method:'POST',body:{task_id:Number(task.id),action:'add_many',items:rows}});
        toast(`${result.added_count} checklist steps added`);
        await openTaskModal(task.id);
      }catch(err){toast(err.message,true);button.disabled=false;}
    });
    body.querySelectorAll('[data-checklist-move]').forEach(button=>button.addEventListener('click',async()=>{
      const id=Number(button.dataset.checklistMove),direction=Number(button.dataset.direction);
      const oldIds=(task.checklist||[]).map(x=>Number(x.id));const index=oldIds.indexOf(id), next=index+direction;
      if(index<0||next<0||next>=oldIds.length)return;
      const order=[...oldIds];[order[index],order[next]]=[order[next],order[index]];
      button.disabled=true;
      try{
        await api('checklist.php',{method:'PUT',body:{action:'reorder',task_id:Number(task.id),order,expected_order:oldIds}});
        await openTaskModal(task.id);
      }catch(err){toast(err.message,true);button.disabled=false;}
    }));
    body.querySelectorAll('[data-checklist-edit]').forEach(button=>button.addEventListener('click',()=>{
      const id=Number(button.dataset.checklistEdit),item=task.checklist.find(x=>Number(x.id)===id);
      if(!item)return;
      const row=button.closest('.checklist-item');if(!row)return;
      row.querySelector('.checklist-label').classList.add('hidden');
      row.querySelector('.checklist-actions').classList.add('hidden');
      const form=row.querySelector('.checklist-edit-form');form.classList.remove('hidden');
      const input=form.querySelector('input');input.value=item.content;input.focus();input.select();
    }));
    body.querySelectorAll('.checklist-edit-form').forEach(form=>{
      const cancel=()=>{
        form.classList.add('hidden');form.closest('.checklist-item').querySelector('.checklist-label').classList.remove('hidden');
        form.closest('.checklist-item').querySelector('.checklist-actions').classList.remove('hidden');
      };
      form.querySelector('[data-checklist-cancel]').addEventListener('click',cancel);
      form.addEventListener('submit',async event=>{
        event.preventDefault();const itemId=Number(form.dataset.checklistItem),content=form.querySelector('input').value.trim();
        if(!content||content.length>255){toast('Enter 1–255 characters.',true);return;}
        const button=form.querySelector('[type=submit]');button.disabled=true;
        try{
          await api('checklist.php',{method:'PUT',body:{action:'edit_text',id:itemId,content}});
          toast('Checklist step updated');await openTaskModal(task.id);
        }catch(err){toast(err.message,true);button.disabled=false;}
      });
    });
    body.querySelectorAll('[data-checklist-remove]').forEach(button=>button.addEventListener('click',async()=>{
      if(!confirm('Remove this checklist step?'))return;
      button.disabled=true;
      try{
        await api('checklist.php',{method:'DELETE',body:{id:Number(button.dataset.checklistRemove)}});
        toast('Checklist step removed');await openTaskModal(task.id);
      }catch(err){toast(err.message,true);button.disabled=false;}
    }));
    const composer=document.getElementById('tm-add-comment');
    composer.addEventListener('submit', e => addComment(e, task.id));
    composer.querySelector('#tm-comment-input').addEventListener('keydown',e=>{
      if(e.key==='Enter'&&(e.ctrlKey||e.metaKey)){e.preventDefault();composer.requestSubmit();}
    });
    composer.querySelector('#tm-mention-member').addEventListener('change',e=>{
      const member=context.members.find(m=>Number(m.id)===Number(e.target.value));
      if(!member){return;}
      const input=composer.querySelector('#tm-comment-input');
      const duplicateNames=context.members.filter(m=>Number(m.is_active??1)===1 && m.name===member.name);
      const token=duplicateNames.length>1?`@[${member.name}](${Number(member.id)})`:`@[${member.name}]`;
      const before=input.value.slice(0,input.selectionStart),after=input.value.slice(input.selectionEnd);
      const prefix=before && !/\s$/.test(before)?' ':'';
      const suffix=after && !/^\s/.test(after)?' ':'';
      input.value=before+prefix+token+suffix+after;
      const caret=(before+prefix+token+suffix).length;
      input.focus();input.setSelectionRange(caret,caret);e.target.value='';
    });
    const clearReply=()=>{commentReplyTo=null;const banner=composer.querySelector('#tm-reply-banner');banner.hidden=true;};
    composer.querySelector('#tm-reply-cancel').addEventListener('click',clearReply);
    const discussion=body.querySelector('#tm-comments');
    discussion.addEventListener('click',async e=>{
      const button=e.target.closest('button');if(!button)return;
      const row=button.closest('[data-comment-id]');if(!row)return;
      const id=Number(row.dataset.commentId),comment=task.comments.find(c=>Number(c.id)===id);
      if(button.hasAttribute('data-comment-reply')){
        commentReplyTo={id,name:row.dataset.author||'teammate'};
        const banner=composer.querySelector('#tm-reply-banner');banner.hidden=false;
        composer.querySelector('#tm-reply-name').textContent=commentReplyTo.name;
        composer.querySelector('#tm-comment-input').focus();return;
      }
      if(button.hasAttribute('data-comment-edit')){
        row.querySelector('.comment-text').hidden=true;
        row.querySelector('.comment-actions').hidden=true;
        const form=row.querySelector('.comment-edit-form');form.hidden=false;
        form.querySelector('textarea').focus();return;
      }
      if(button.hasAttribute('data-comment-cancel')){
        row.querySelector('.comment-edit-form').hidden=true;
        row.querySelector('.comment-text').hidden=false;
        row.querySelector('.comment-actions').hidden=false;return;
      }
      if(button.hasAttribute('data-comment')){
        if(!window.confirm('Delete this comment? Replies remain on the task.'))return;
        button.disabled=true;
        try{await api('comments.php',{method:'DELETE',body:{id}});toast('Comment removed');await openTaskModal(task.id);}
        catch(error){toast(error.message,true);button.disabled=false;}return;
      }
    });
    discussion.addEventListener('submit',async e=>{
      const form=e.target.closest('.comment-edit-form');if(!form)return;
      e.preventDefault();const id=Number(form.closest('[data-comment-id]').dataset.commentId);
      const comment=task.comments.find(c=>Number(c.id)===id);
      if(!comment)return;
      const content=form.querySelector('textarea').value.trim();
      if(!content||new TextEncoder().encode(content).length>3000){toast('Comment must be 1–3,000 UTF-8 bytes.',true);return;}
      const submit=form.querySelector('[type=submit]');submit.disabled=true;
      try{await api('comments.php',{method:'PUT',body:{id,content,version:Number(comment.edit_version||1)}});
        toast('Comment updated');await openTaskModal(task.id);}
      catch(error){toast(error.message,true);submit.disabled=false;}
    });
    const older=body.querySelector('#tm-comment-older');
    older?.addEventListener('click',async()=>{
      const before=Number(older.dataset.before);if(!before)return;
      older.disabled=true;
      try{
        const page=await api(`comments.php?task_id=${Number(task.id)}&before_id=${before}`);
        if(Number(taskDetailCurrentId)!==Number(task.id)||!discussion.isConnected)return;
        if(page.comments?.length){
          task.comments.unshift(...page.comments);
          discussion.insertAdjacentHTML('afterbegin',page.comments.map(commentHtml).join(''));
        }
        const next=Number(page.next_before_id||0);older.dataset.before=String(next);
        body.querySelector('#tm-comment-history').hidden=!next;
        const count=body.querySelector('.task-comment-count');if(count)count.textContent=`${task.comments.length} loaded`;
      }catch(error){toast(error.message,true);}finally{older.disabled=false;}
    });

    document.getElementById('tm-new-tag')?.addEventListener('click', () => {
      promptModal('New tag', `<input id="p-tag-name" placeholder="Tag name" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;margin-bottom:8px;">
        <input id="p-tag-color" type="color" value="#7B68EE">`, async () => {
        const name = document.getElementById('p-tag-name').value.trim();
        if (!name) return;
        const color = document.getElementById('p-tag-color').value;
        await api('tags.php', { method: 'POST', body: { team_id: Number(task.team_id), name, color } });
        const tagData = await api('tags.php?team_id=' + Number(task.team_id));
        state.modalContexts[Number(task.team_id)] = { members: context.members, tags: tagData.tags };
        openTaskModal(task.id);
      });
    });

    body.querySelectorAll('.checklist-toggle').forEach(cb => {
      cb.addEventListener('change', async () => {
        try {
          await api('checklist.php', { method: 'PUT', body: { id: Number(cb.dataset.item), is_done: cb.checked ? 1 : 0 } });
          const item=task.checklist.find(x=>Number(x.id)===Number(cb.dataset.item));
          if(item)item.is_done=cb.checked?1:0;
          cb.closest('.checklist-item').classList.toggle('is-done',cb.checked);
          const done=task.checklist.filter(x=>!!Number(x.is_done)).length;
          document.getElementById('tm-checklist-count').textContent=`${done} of ${task.checklist.length} done`;
          const progress=document.getElementById('tm-checklist-progress'),percent=task.checklist.length?Math.round(done/task.checklist.length*100):0;
          progress.setAttribute('aria-valuenow',String(percent));progress.querySelector('span').style.width=percent+'%';
        } catch (err) {
          toast(err.message, true);
          cb.checked = !cb.checked; // revert the visual toggle since the save failed
        }
      });
    });
  }

  function checklistItemHtml(item, canEdit = true, index = 0, count = 0) {
    const id=Number(item.id);
    return `<div class="checklist-item ${item.is_done ? 'is-done' : ''}" data-checklist-row="${id}">
      <input type="checkbox" class="checklist-toggle" data-item="${id}" aria-label="Complete ${esc(item.content)}" ${item.is_done?'checked':''} ${canEdit?'':'disabled'}>
      <span class="checklist-label">${esc(item.content)}</span>
      ${canEdit?`<div class="checklist-actions" role="group" aria-label="Actions for ${esc(item.content)}">
        <button type="button" data-checklist-move="${id}" data-direction="-1" aria-label="Move ${esc(item.content)} up" title="Move up" ${index===0?'disabled':''}>↑</button>
        <button type="button" data-checklist-move="${id}" data-direction="1" aria-label="Move ${esc(item.content)} down" title="Move down" ${index===count-1?'disabled':''}>↓</button>
        <button type="button" data-checklist-edit="${id}" aria-label="Rename ${esc(item.content)}">Edit</button>
        <button type="button" class="checklist-remove" data-checklist-remove="${id}" aria-label="Remove ${esc(item.content)}">×</button>
      </div><form class="checklist-edit-form hidden" data-checklist-item="${id}">
        <input maxlength="255" required aria-label="Edit checklist step" value="${esc(item.content)}">
        <button type="submit" class="btn btn-primary">Save</button><button type="button" class="btn btn-ghost" data-checklist-cancel>Cancel</button>
      </form>`:''}
    </div>`;
  }
  function commentHtml(c) {
    const id=Number(c.id),mine=Number(c.user_id)===Number(state.user.id);
    const canDelete=mine||iHave(['administrator','manager']);
    const reply=Number(c.parent_comment_id)>0;
    return `<article class="comment ${reply?'task-comment-reply':''}" data-comment-id="${id}" data-author="${esc(c.name)}">
      <span class="avatar" style="background:${esc(c.avatar_color)}">${esc(initials(c.name))}</span>
      <div class="comment-body">
        <div class="comment-header">
          <span><strong>${esc(c.name)}</strong>${c.roles?.length?` <span class="comment-role">(${esc(rolesLabel(c.roles))})</span>`:''} <span class="comment-time">${esc(c.created_at)}</span>${c.edited_at?' <span class="comment-edited">Edited</span>':''}</span>
        </div>
        ${reply?`<div class="comment-reply-context">Reply to ${esc(c.parent_author_name||'previous comment')}</div>`:''}
        <div class="comment-text">${esc(c.content)}</div>
        <div class="comment-actions"><button type="button" data-comment-reply="${id}">Reply</button>${mine?`<button type="button" data-comment-edit="${id}">Edit</button>`:''}${canDelete?`<button type="button" class="comment-delete" data-comment="${id}">Delete</button>`:''}</div>
        ${mine?`<form class="comment-edit-form" data-comment-edit-form="${id}" hidden><label for="comment-edit-${id}">Edit your comment</label><textarea id="comment-edit-${id}" rows="3" maxlength="3000" required>${esc(c.content)}</textarea><div><button class="btn btn-primary" type="submit">Save</button><button class="btn btn-ghost" type="button" data-comment-cancel>Cancel</button></div></form>`:''}
      </div>
    </article>`;
  }

  async function saveTask(taskId) {
    let estimateMinutes;
    try { estimateMinutes = readEstimateHours('tm-estimate'); } catch(err) { toast(err.message,true); return; }
    const body = {
      id: taskId, estimate_minutes: estimateMinutes,
      title: document.getElementById('tm-title').value.trim(),
      description: document.getElementById('tm-desc').value,
      status: document.getElementById('tm-status').value,
      priority: document.getElementById('tm-priority').value,
      start_date: document.getElementById('tm-start').value,
      due_date: document.getElementById('tm-due').value,
      recurrence_rule: document.getElementById('tm-repeat-rule').value,
      tag_ids: Array.from(document.getElementById('tm-tags').selectedOptions).map(o => Number(o.value)),
    };
    if (iHave(['administrator', 'manager'])) {
      body.assignee_ids = Array.from(document.getElementById('tm-assignees').selectedOptions).map(o => Number(o.value));
    }
    try {
      const result = await api('task.php', { method: 'PUT', body });
      toast(result.recurrence_new_task_id ? 'Task completed. Next weekly task created.' : 'Task saved');
      closeTaskModal();
      refreshCurrentView();
    } catch (err) { toast(err.message, true); }
  }

  async function duplicateTask(taskId) {
    try {
      const result=await api('duplicate.php',{method:'POST',body:{id:taskId}});
      toast('Task duplicated. Dates and assignees were reset.');
      refreshCurrentView();
      openTaskModal(result.id);
    } catch(e) {toast(e.message,true);}
  }
  async function deleteTask(taskId) {
    if (!confirm('Delete this task? This cannot be undone.')) return;
    try {
      await api('task.php', { method: 'DELETE', body: { id: taskId } });
      closeTaskModal();
      refreshCurrentView();
    } catch (err) { toast(err.message, true); }
  }
  async function addChecklistItem(e, taskId) {
    e.preventDefault();
    const input = document.getElementById('tm-checklist-input');
    if (!input.value.trim()) return;
    try {
      await api('checklist.php', { method: 'POST', body: { task_id: taskId, content: input.value.trim() } });
      openTaskModal(taskId);
    } catch (err) { toast(err.message, true); }
  }
  async function addComment(e, taskId) {
    e.preventDefault();
    const input = document.getElementById('tm-comment-input');
    if (!input.value.trim()) return;
    try {
      await api('comments.php', { method: 'POST', body: { task_id: taskId, content: input.value.trim(), parent_id: commentReplyTo?.id||null } });
      input.value = '';commentReplyTo=null;
      openTaskModal(taskId);
    } catch (err) { toast(err.message, true); }
  }
  function closeTaskModal() {
    ++taskModalRequestSeq; // Invalidate any older task or people/tag response.
    taskDetailCurrentId=null; taskDetailTab='details';commentReplyTo=null;
    document.getElementById('task-modal').classList.add('hidden');
    syncDialogAccessibility();
    if (document.getElementById('prompt-modal').classList.contains('hidden')) returnDialogFocus(taskModalReturnFocus);
    taskModalReturnFocus = null;
    // Refresh a report after editing its task without resetting date/scope filters.
    if (state.currentView === 'time-reports' && document.getElementById('time-report-filters')) {
      timeReportCursor = null;loadTimeReport();
    }
  }
  document.getElementById('close-task-modal').addEventListener('click', closeTaskModal);
  document.getElementById('task-modal').addEventListener('click', e => { if (e.target.id === 'task-modal') closeTaskModal(); });

  function refreshCurrentView() {
    if (state.currentView === 'monthly') renderMonthly();
    else if (state.currentView === 'tasks') renderTasksView();
    else if (state.currentView === 'board') renderBoard();
    else if (state.currentView === 'my-work') renderMyWork();
    else if (state.currentView === 'search') renderSearchTasks();
    else if (state.currentView === 'calendar') renderCalendar();
    else if (state.currentView === 'workload') renderWorkload();
    else if (state.currentView === 'milestones') renderMilestones();
    else if (state.currentView === 'task-reviews') renderTaskReviews();
  }

  // ---------------------------------------------------------------
  // Generic prompt modal
  // ---------------------------------------------------------------
  let promptModalGeneration = 0;
  function promptModal(title, bodyHtml, onConfirm) {
    const modal = document.getElementById('prompt-modal');
    const generation = ++promptModalGeneration;
    const previousFocus = document.activeElement;
    document.getElementById('prompt-title').textContent = title;
    document.getElementById('prompt-body').innerHTML = bodyHtml;
    const errorText = document.getElementById('prompt-error');
    errorText.textContent = '';errorText.hidden = true;
    modal.classList.remove('hidden');
    const confirmBtn = document.getElementById('prompt-confirm');
    const cancelBtn = document.getElementById('prompt-cancel');
    modal.setAttribute('aria-busy','false');
    confirmBtn.disabled = false; cancelBtn.disabled = false;confirmBtn.textContent='Confirm';
    const firstInput = [...modal.querySelectorAll('input:not([disabled]),textarea:not([disabled]),select:not([disabled])')]
      .find(el => el.getClientRects().length > 0);
    (firstInput || confirmBtn).focus();
    syncDialogAccessibility();
    const cleanup = () => {
      if (generation !== promptModalGeneration) return;
      modal.classList.add('hidden');
      confirmBtn.onclick = null;cancelBtn.onclick = null;
      errorText.textContent = '';errorText.hidden = true;
      syncDialogAccessibility();
      returnDialogFocus(previousFocus);
    };
    confirmBtn.onclick = async () => {
      if (generation !== promptModalGeneration || confirmBtn.disabled) return;
      const originalLabel = confirmBtn.textContent;
      confirmBtn.disabled = true;cancelBtn.disabled = true;
      confirmBtn.textContent = 'Saving…';modal.setAttribute('aria-busy','true');
      // Disabling the clicked button must not drop keyboard focus behind the dialog.
      (firstInput || modal).focus();
      errorText.textContent = '';errorText.hidden = true;
      try {
        await onConfirm();
        cleanup();
      } catch (err) {
        if (generation !== promptModalGeneration) return;
        errorText.textContent = err.message || 'Something went wrong.';
        errorText.hidden = false;
        toast(errorText.textContent, true);
      } finally {
        if (generation === promptModalGeneration) {
          modal.setAttribute('aria-busy','false');
          confirmBtn.disabled = false;cancelBtn.disabled = false;
          confirmBtn.textContent = originalLabel;
          if (!modal.contains(document.activeElement) && !modal.classList.contains('hidden'))
            (firstInput || confirmBtn).focus();
        }
      }
    };
    cancelBtn.onclick = cleanup;
  }

  // ---------------------------------------------------------------
  // Boot
  // ---------------------------------------------------------------
  (async function init() {
    try {
      const pending = await api('mfa_status.php');
      if (pending.mfa_required) { state.csrf = pending.csrf_token; showMfaScreen(); return; }
      await bootstrapApp();
    } catch (e) {
      showAuthScreen();
      if (e.message && e.message !== 'Not authenticated.') {
        toast('Could not load your session: ' + e.message, true);
      }
    }
  })();
})();
