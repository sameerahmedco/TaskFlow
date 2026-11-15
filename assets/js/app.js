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
    let data = {};
    try { data = await res.json(); } catch (e) {}

    if (res.status === 401 && !options.preserveMfa) { showAuthScreen(); throw new Error(data.error || 'Not authenticated'); }
    if (!res.ok) throw new Error(data.error || 'Something went wrong.');
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
    return d.innerHTML;
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
    document.getElementById('auth-screen').classList.remove('hidden');
    document.getElementById('pw-screen').classList.add('hidden');
    document.getElementById('mfa-screen').classList.add('hidden');
    document.getElementById('app').classList.add('hidden');
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
    await loadTeamContext();
    setView(['org-dashboard','teams','users','org-activity','search','my-work','inbox','board'].includes(state.currentView) ? state.currentView : 'monthly');
  }

  async function loadTeamContext() {
    if (!state.currentTeamId) return;
    try {
      const listData = await api('lists.php?team_id=' + state.currentTeamId);
      state.lists = listData.lists;
      state.currentListId = state.lists.length ? state.lists[0].id : null;
      const tagData = await api('tags.php?team_id=' + state.currentTeamId);
      state.tags = tagData.tags;
    } catch (err) {
      console.error('loadTeamContext failed:', err);
      toast('Could not load this team\u2019s lists: ' + err.message, true);
      state.lists = [];
    }
  }

  // ---------------------------------------------------------------
  // View switching
  // ---------------------------------------------------------------
  function setView(view) {
    state.currentView = view;
    document.querySelectorAll('.nav-item').forEach(b => b.classList.toggle('active', b.dataset.view === view));
    const titles = {
      'my-work': 'My Work', board: 'Board', inbox: 'Inbox', monthly: 'Monthly', tasks: 'Tasks', search: 'Search Tasks', 'team-dashboard': 'Team Dashboard', priorities: 'Weekly Goals',
      roster: 'Team Members', activity: 'Activity', 'org-dashboard': 'Organization Dashboard',
      teams: 'Teams', users: 'Users', 'org-activity': 'Organization Activity', chat: 'Chat',
      'advanced-settings': 'Advanced Settings', 'my-account': 'My Account',
    };
    document.getElementById('view-title').textContent = titles[view] || 'TaskFlow';
    const adminViews = ['org-dashboard','teams','users','org-activity','advanced-settings'];
    const collaborate = ['chat','roster','activity'];
    const planning = ['monthly','priorities','team-dashboard'];
    document.getElementById('view-overline').textContent = adminViews.includes(view) ? 'ORGANIZATION / ADMINISTRATION' : collaborate.includes(view) ? 'WORKSPACE / COLLABORATION' : planning.includes(view) ? 'WORKSPACE / PLANNING' : 'WORKSPACE / YOUR WORK';
    const descriptions = {
      'my-work':'Your priorities, without the noise.',
      board:'Move work forward, one card at a time.',
      tasks:'Plan and manage the details that matter.',
      search:'Find exactly what your team is working on.',
      inbox:'Updates and reminders, all in one place.',
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
      'my-work': renderMyWork, board: renderBoard, inbox: renderInbox, monthly: renderMonthly, tasks: renderTasksView, search: renderSearchTasks, 'team-dashboard': () => renderDashboard('team'),
      priorities: renderPriorities, roster: renderRoster, activity: () => renderActivity(state.currentTeamId),
      'org-dashboard': () => renderDashboard('org'), teams: renderTeamsAdmin, users: renderUsersAdmin,
      'org-activity': () => renderActivity(null), chat: renderChat, 'advanced-settings': renderAdvancedSettings,
      'my-account': renderMyAccount,
    };
    return (renderers[view] || (() => {}))();
  }

  // TaskFlow 1.0: lightweight shell controls (no third-party runtime).
  const themeStorageKey = 'taskflow-appearance';
  function setAppearance(value) {
    const theme = value === 'dark' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', theme);
    const switcher = document.getElementById('theme-switch');
    switcher.setAttribute('aria-label', theme === 'dark' ? 'Switch to light appearance' : 'Switch to dark appearance');
    switcher.setAttribute('title', theme === 'dark' ? 'Use light appearance' : 'Use dark appearance');
    const iconName = theme === 'dark' ? 'sun' : 'moon';
    switcher.querySelector('use').setAttribute('href', 'assets/img/icons.svg#' + iconName);
    try { localStorage.setItem(themeStorageKey, theme); } catch (_) { /* private mode is fine */ }
  }
  let savedAppearance = 'light';
  try { savedAppearance = localStorage.getItem(themeStorageKey) || 'light'; } catch (_) { /* blocked storage */ }
  setAppearance(savedAppearance);
  document.getElementById('theme-switch').addEventListener('click', () => {
    setAppearance(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
  });
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
      if (!document.getElementById('prompt-modal').classList.contains('hidden')) {
        document.getElementById('prompt-cancel').click();
      } else if (!document.getElementById('task-modal').classList.contains('hidden')) {
        closeTaskModal();
      } else { closeMobileSidebar(); }
    }
  });
  // Keyboard shortcut: Ctrl/Cmd+K opens team-scoped task search.
  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k' && state.user && state.currentTeamId) {
      e.preventDefault();
      setView('search');
      document.getElementById('task-search-q')?.focus();
    }
  });

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
    `;
    if (document.getElementById('list-picker')) {
      document.getElementById('list-picker').addEventListener('change', e => {
        state.currentListId = Number(e.target.value);
        renderTasksView();
      });
    }
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
        <button class="tab-btn ${state.taskTab === 'add' ? 'active' : ''}" data-tab="add">+ Add Task</button>
        <button class="tab-btn ${state.taskTab === 'view' ? 'active' : ''}" data-tab="view">View Tasks</button>
        <button class="tab-btn ${state.taskTab === 'pending' ? 'active' : ''}" data-tab="pending">Pending</button>
        <button class="tab-btn ${state.taskTab === 'completed' ? 'active' : ''}" data-tab="completed">Completed</button>
      </div>
      <div id="tasks-tab-body"></div>
    `;
    container.querySelectorAll('.tab-btn').forEach(btn => {
      btn.addEventListener('click', () => { state.taskTab = btn.dataset.tab; renderTasksView(); });
    });

    if (state.members.length === 0 || state.members[0].teamIdLoaded !== state.currentTeamId) {
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

  function renderAddTaskForm() {
    const body = document.getElementById('tasks-tab-body');
    const canAssignOthers = iHave(['administrator', 'manager']);
    const assigneeOptions = state.members.filter(m => (m.team_id === undefined || Number(m.team_id) === Number(state.currentTeamId)) && (m.is_active == null || Number(m.is_active) === 1)).map(m => `<option value="${m.id}">${esc(m.name)}${m.roles && m.roles.length ? ' (' + esc(rolesLabel(m.roles)) + ')' : ''}</option>`).join('');

    body.innerHTML = `
      <form id="add-task-form" class="add-task-form">
        <div class="field-group"><label>Title</label><input type="text" id="at-title" required placeholder="Task title"></div>
        <div class="field-group"><label>Description</label><textarea id="at-desc" placeholder="Optional details"></textarea></div>
        <div class="field-row">
          <div class="field-group"><label>Priority</label>
            <select id="at-priority">${['none','low','normal','high','urgent'].map(p => `<option value="${p}">${p}</option>`).join('')}</select>
          </div>
          <div class="field-group"><label>Due date</label><input type="date" id="at-due"></div>
          ${canAssignOthers ? `<div class="field-group"><label>Assign to</label><select id="at-assignees" multiple size="3">${assigneeOptions}</select></div>` : ''}
        </div>
        <button type="submit" class="btn btn-primary">Create task</button>
      </form>
    `;
    document.getElementById('add-task-form').addEventListener('submit', async e => {
      e.preventDefault();
      const title = document.getElementById('at-title').value.trim();
      if (!title) return;
      const body = {
        list_id: state.currentListId, title,
        description: document.getElementById('at-desc').value,
        priority: document.getElementById('at-priority').value,
        due_date: document.getElementById('at-due').value,
      };
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

  const bulkSelectedTasks = new Set();
  function updateBulkSelection(body) {
    const count=body.querySelector('#bulk-count');
    if (!count) return;
    count.textContent=bulkSelectedTasks.size + ' selected';
    body.querySelector('#bulk-apply').disabled=bulkSelectedTasks.size===0;
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
        <td><span class="status-pill status-${esc(t.status)}">${esc(t.status.replace('_', ' '))}</span></td>
        <td><span class="priority-pill priority-${esc(t.priority)}">${esc(t.priority)}</span></td>
        <td>${t.due_date ? fmtDate(t.due_date) : '<span style="color:var(--text-faint)">—</span>'}</td>
        <td>${tags}</td>
        <td><span class="avatar-row">${assignees}</span></td>
      </tr>`;
    }).join('');

    if (offset === 0) {
      bulkSelectedTasks.clear();
      body.innerHTML = `<div class="bulk-toolbar" id="bulk-toolbar">
        <strong id="bulk-count">0 selected</strong>
        <label>Action <select id="bulk-field" aria-label="Bulk action"><option value="status">Change status</option><option value="priority">Change priority</option></select></label>
        <select id="bulk-value" aria-label="New value"></select>
        <button type="button" class="btn btn-primary" id="bulk-apply" disabled>Apply to selected</button>
        <span class="bulk-hint">50-task maximum. All selected tasks must be editable.</span>
      </div><div class="table-scroll"><table class="task-table">
        <thead><tr><th><input id="bulk-select-page" type="checkbox" aria-label="Select visible editable tasks (maximum 50)"></th><th>Task</th><th>Status</th><th>Priority</th><th>Due</th><th>Tags</th><th>Assignees</th></tr></thead>
        <tbody>${rows}</tbody>
      </table></div>`;
      const selectOptions = {
        status:[['to_do','To do'],['in_progress','In progress'],['review','Review'],['done','Done']],
        priority:[['none','None'],['low','Low'],['normal','Normal'],['high','High'],['urgent','Urgent']]
      };
      const updateValues = () => {
        const field = document.getElementById('bulk-field').value;
        document.getElementById('bulk-value').innerHTML = selectOptions[field].map(([v,label]) => `<option value="${v}">${label}</option>`).join('');
      };
      updateValues();
      body.querySelector('#bulk-field').addEventListener('change', updateValues);
      body.querySelector('#bulk-apply').addEventListener('click', async () => {
        if (!bulkSelectedTasks.size || bulkSelectedTasks.size > 50) return;
        const field = body.querySelector('#bulk-field').value;
        const value = body.querySelector('#bulk-value').value;
        if (!confirm(`Apply ${field}: ${value.replaceAll('_',' ')} to ${bulkSelectedTasks.size} selected tasks?`)) return;
        const button=body.querySelector('#bulk-apply');button.disabled=true;
        try {
          const response = await api('bulk.php',{ method:'POST',body:{
            team_id:Number(state.currentTeamId), task_ids:Array.from(bulkSelectedTasks),field,value
          }});
          toast(`${response.changed} task(s) changed`);
          renderTasksView();
        } catch(e) {toast(e.message,true);updateBulkSelection(body);}
      });
      body.onclick = event => {
        if (event.target.closest('.task-bulk-cell') || event.target.closest('.bulk-toolbar') || event.target.closest('thead')) return;
        const row = event.target.closest('.task-row');
        if (row) openTaskModal(Number(row.dataset.task));
      };
      body.onchange = event => {
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

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const content = input.value.trim();
      if (!content) return;
      try {
        await onSend(content);
        input.value = '';
        closeSuggestions();
        const el = document.getElementById('chat-messages');
        // Pull the message we just sent in immediately rather than waiting for the next poll.
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
      } catch (err) { toast(err.message, true); }
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
    const container = document.getElementById('view-container');
    container.innerHTML = '<p>Loading…</p>';
    const url = teamId ? `activity.php?team_id=${teamId}&limit=50` : `activity.php?team_id=0&limit=50`;
    let data;
    try {
      data = await api(url);
    } catch (err) {
      container.innerHTML = `<div class="load-error-box">
        <p>Couldn't load activity: ${esc(err.message)}</p>
        <button type="button" class="btn btn-ghost" id="activity-retry">Try again</button>
      </div>`;
      const retryBtn = document.getElementById('activity-retry');
      if (retryBtn) retryBtn.addEventListener('click', () => renderActivity(teamId));
      return;
    }
    if (!data.activity.length) {
      container.innerHTML = `<div class="empty-state"><h3>No activity yet</h3></div>`;
      return;
    }
    container.innerHTML = data.activity.map(a => `
      <div class="activity-item">
        <span class="avatar" style="background:${esc(a.avatar_color)}">${esc(initials(a.name))}</span>
        <div>
          <div class="activity-text"><strong>${esc(a.name)}</strong> ${esc(readableAction(a.action))} ${a.detail ? '— ' + esc(a.detail) : ''} ${a.team_name ? `<span style="color:var(--text-faint);">(${esc(a.team_name)})</span>` : ''}</div>
          <div class="activity-time">${esc(a.created_at)}</div>
        </div>
      </div>`).join('');
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
  // PERSONAL WORK: user's own tasks with server-side team boundaries.
  // ---------------------------------------------------------------
  let myWorkCursor = null;
  async function renderMyWork() {
    myWorkCursor = null;
    const container = document.getElementById('view-container');
    container.innerHTML = `<div class="feature-intro"><h2>Your work, without the noise</h2><p>Tasks you created or were assigned to. Past-team work stays private.</p></div>
      <form class="search-form" id="my-work-filters">
        <select id="my-work-mode" aria-label="My work type"><option value="all">Created or assigned</option><option value="assigned">Assigned to me</option><option value="created">Created by me</option></select>
        <select id="my-work-due" aria-label="Due date"><option value="all">Any due date</option><option value="overdue">Overdue</option><option value="today">Due today</option><option value="week">Next 7 days</option><option value="upcoming">Upcoming</option><option value="none">Without due date</option></select>
        <select id="my-work-status" aria-label="Completion"><option value="open">Open tasks</option><option value="done">Completed</option><option value="all">All statuses</option></select>
      </form><div class="search-results" id="my-work-results" aria-live="polite"></div>`;
    container.querySelectorAll('#my-work-filters select').forEach(el => el.addEventListener('change', () => { myWorkCursor = null; loadMyWork(); }));
    await loadMyWork();
  }
  async function loadMyWork() {
    const result = document.getElementById('my-work-results');
    if (!result || state.currentView !== 'my-work') return;
    const append = !!myWorkCursor;
    const params = new URLSearchParams({ mode:document.getElementById('my-work-mode').value,
      due:document.getElementById('my-work-due').value, status:document.getElementById('my-work-status').value, limit:'30' });
    if (myWorkCursor) params.set('before_id',String(myWorkCursor));
    if (!append) result.innerHTML = '<p>Loading your tasks…</p>';
    try {
      const data = await api('my_work.php?' + params);
      if (!document.getElementById('my-work-results') || state.currentView !== 'my-work') return;
      result.querySelector('#my-work-more')?.remove();
      if (!append) result.innerHTML = '';
      if (!data.tasks.length && !append) { result.innerHTML = '<div class="empty-state"><h3>All clear</h3><p>No tasks match your filters.</p></div>'; return; }
      const list = document.createElement('div');
      list.className='search-result-list';
      list.innerHTML = data.tasks.map(t => `<button class="search-result" type="button" data-task-id="${Number(t.id)}">
        <span><strong>${esc(t.title)}</strong><small>${esc(t.team_name)} · ${esc(t.list_name)} · ${esc(t.status.replaceAll('_',' '))}</small></span>
        <span class="priority-pill priority-${esc(t.priority)}">${esc(t.priority)}</span>
        <span class="search-due ${isOverdue(t) ? 'due-overdue':''}">${t.due_date ? fmtDate(t.due_date) : 'No due date'}</span>
      </button>`).join('');
      list.querySelectorAll('[data-task-id]').forEach(btn => btn.addEventListener('click', () => openTaskModal(Number(btn.dataset.taskId))));
      result.appendChild(list);
      myWorkCursor = data.next_before_id;
      if (myWorkCursor) {
        const more=document.createElement('button');more.id='my-work-more';more.className='btn btn-ghost';more.textContent='Load more work';
        more.addEventListener('click',loadMyWork);result.appendChild(more);
      }
    } catch(e) { if (!append) result.innerHTML = `<p role="alert">${esc(e.message)}</p>`; else toast(e.message,true); }
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
    container.innerHTML = `<div class="feature-intro"><h2>Team board</h2><p>Move your own or assigned work through the workflow. Managers can move all team tasks.</p></div>
      <div class="board-toolbar"><label><input type="checkbox" id="board-mine" ${boardMine?'checked':''}> Only my work</label><button type="button" class="btn btn-ghost" id="board-refresh">Refresh board</button></div>
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
    try { await api('task.php',{method:'PUT',body:{id,status}}); toast('Task moved'); await renderBoard(); }
    catch(e) { toast(e.message,true); await renderBoard(); }
  }

  // ---------------------------------------------------------------
  // IN-APP INBOX: respects user identity, with bounded pagination.
  // ---------------------------------------------------------------
  let inboxCursor=null;
  async function refreshInboxBadge() {
    try {
      const data=await api('inbox.php?limit=1');
      const badge=document.getElementById('inbox-badge');
      if (badge) { badge.textContent=data.unread_count > 99 ? '99+' : String(data.unread_count);badge.classList.toggle('hidden',!data.unread_count); }
    } catch(e) { /* optional badge: never interrupt other work */ }
  }
  async function renderInbox() {
    inboxCursor=null;
    const container=document.getElementById('view-container');
    container.innerHTML=`<div class="inbox-toolbar"><div class="feature-intro"><h2>Inbox</h2><p>Team activity and mentions, even with browser alerts disabled.</p></div>
      <label><input id="inbox-unread" type="checkbox"> Unread only</label><button type="button" id="inbox-mark-all" class="btn btn-ghost">Mark latest 500 read</button></div>
      <div id="inbox-results" class="search-results" aria-live="polite"></div>`;
    document.getElementById('inbox-unread').addEventListener('change',()=>{ inboxCursor=null; loadInbox(); });
    document.getElementById('inbox-mark-all').addEventListener('click',async ()=>{
      try {const result=await api('inbox.php',{method:'POST',body:{action:'read_all'}});
        await refreshInboxBadge();inboxCursor=null;await loadInbox();
        if (result.unread_count>0) toast(`${result.unread_count} older notifications are still unread`);
      } catch(e) { toast(e.message,true); }
    });
    await loadInbox();
  }
  async function loadInbox() {
    const container=document.getElementById('inbox-results');
    if (!container || state.currentView!=='inbox') return;
    const append=!!inboxCursor;
    const params=new URLSearchParams({limit:'25',unread:document.getElementById('inbox-unread').checked?'1':'0'});
    if (inboxCursor) params.set('before_id',String(inboxCursor));
    if (!append) container.innerHTML='<p>Loading notifications…</p>';
    try {
      const data=await api('inbox.php?'+params);
      if (state.currentView!=='inbox' || !document.getElementById('inbox-results')) return;
      if (!append) container.innerHTML='';
      container.querySelector('#inbox-load-more')?.remove();
      if (!data.notifications.length&&!append) {container.innerHTML='<div class="empty-state"><h3>Your inbox is clear</h3><p>Notifications will appear here as work happens.</p></div>';return;}
      const list=document.createElement('div');list.className='inbox-list';
      list.innerHTML=data.notifications.map(n => `<article class="inbox-item ${n.is_read?'':'unread'}" data-note-id="${Number(n.id)}">
        <div class="inbox-marker" aria-hidden="true"></div><div class="inbox-content"><strong>${esc(n.title)}</strong>
        <p>${esc(n.body)}</p><small>${esc(n.created_at)} · ${esc(n.category.replaceAll('_',' '))}</small></div>
        ${n.is_read?'':`<button type="button" class="btn btn-ghost inbox-read" data-note-id="${Number(n.id)}">Mark read</button>`}</article>`).join('');
      list.querySelectorAll('.inbox-read').forEach(btn => btn.addEventListener('click', async () => {
        try { await api('inbox.php',{method:'POST',body:{action:'read',id:Number(btn.dataset.noteId)}});
          btn.closest('.inbox-item').classList.remove('unread');btn.remove();await refreshInboxBadge();
        } catch(e) {toast(e.message,true);}
      }));
      container.appendChild(list);
      inboxCursor=data.next_before_id;
      if (inboxCursor) {const more=document.createElement('button');more.className='btn btn-ghost';more.id='inbox-load-more';more.textContent='Load older notifications';
        more.addEventListener('click',loadInbox);container.appendChild(more);}
      const badge=document.getElementById('inbox-badge');
      if (badge) { badge.textContent=data.unread_count>99?'99+':String(data.unread_count);badge.classList.toggle('hidden',!data.unread_count); }
    } catch(e) {if (!append) container.innerHTML=`<p role="alert">${esc(e.message)}</p>`;else toast(e.message,true);}
  }

  // ---------------------------------------------------------------
  // SEARCH: server-side pagination and same-team authorization.
  // ---------------------------------------------------------------
  let taskSearchCursor = null;
  async function renderSearchTasks() {
    const container = document.getElementById('view-container');
    if (!state.currentTeamId) { container.innerHTML = emptyTeamState(); return; }
    const canExport = iHave(['administrator','manager']);
    const exportBtn = canExport ? `<a class="btn btn-ghost" id="search-export" href="api/export.php?team_id=${Number(state.currentTeamId)}" download>Export team CSV</a>` : '';
    container.innerHTML = `<div class="settings-box"><div class="settings-box-header"><h2>Find work in your team</h2><p>Search titles and descriptions. Results are limited to your authorized team.</p></div>
      <form id="task-search-form" class="search-form">
        <input id="task-search-q" type="search" maxlength="120" placeholder="Search tasks…" aria-label="Search task titles and descriptions">
        <select id="task-search-status" aria-label="Task status"><option value="all">Any status</option><option value="to_do">To do</option><option value="in_progress">In progress</option><option value="review">Review</option><option value="done">Done</option></select>
        <select id="task-search-priority" aria-label="Priority"><option value="all">Any priority</option><option value="urgent">Urgent</option><option value="high">High</option><option value="normal">Normal</option><option value="low">Low</option><option value="none">None</option></select>
        <label class="search-mine"><input id="task-search-mine" type="checkbox"> My work</label>
        <button class="btn btn-primary" type="submit">Search</button>${exportBtn}
      </form></div>
      <div id="task-search-results" class="search-results" aria-live="polite"></div>`;
    document.getElementById('task-search-form').addEventListener('submit', e => { e.preventDefault(); taskSearchCursor=null; loadTaskSearch(); });
    document.getElementById('task-search-status').addEventListener('change', () => { taskSearchCursor=null; loadTaskSearch(); });
    document.getElementById('task-search-priority').addEventListener('change', () => { taskSearchCursor=null; loadTaskSearch(); });
    document.getElementById('task-search-mine').addEventListener('change', () => { taskSearchCursor=null; loadTaskSearch(); });
    await loadTaskSearch();
  }
  async function loadTaskSearch() {
    const results = document.getElementById('task-search-results');
    if (!results) return;
    const params = new URLSearchParams({
      team_id:String(state.currentTeamId), q:document.getElementById('task-search-q').value.trim(),
      status:document.getElementById('task-search-status').value,
      priority:document.getElementById('task-search-priority').value,
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
    document.getElementById('topbar-actions').innerHTML = `<button class="btn btn-primary" id="new-user-btn">+ New account</button>`;
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
      <div class="field-group" style="margin-bottom:8px;"><input id="u-name" placeholder="Full name" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;"></div>
      <div class="field-group" style="margin-bottom:8px;"><input id="u-email" type="email" placeholder="Email" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;"></div>
      <div class="field-group" style="margin-bottom:12px;"><input id="u-pass" type="text" placeholder="Temporary password (min 8 chars)" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;"></div>
      <div class="field-group" style="margin-bottom:8px;">
        <label style="display:block;margin-bottom:6px;">Roles (select one or more — e.g. Administrator AND Manager)</label>
        <div class="role-checkbox-group">${roleCheckboxesHtml(['member'])}</div>
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
      try {
        await api('users.php', { method: 'POST', body: { name, email, password, roles, team_id } });
        toast('Account created');
        renderUsersAdmin();
      } catch (err) { toast(err.message, true); }
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
      <input id="u-newpass" type="text" placeholder="Reset password (leave blank to keep current)" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:8px;">
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
  // TASK MODAL
  // ---------------------------------------------------------------
  async function openTaskModal(taskId) {
    const modal = document.getElementById('task-modal');
    modal.classList.remove('hidden');
    document.getElementById('task-modal-body').innerHTML = '<p>Loading…</p>';
    try {
      const { task } = await api('task.php?id=' + taskId);
      const teamId=Number(task.team_id);
      if (!state.modalContexts[teamId]) {
        const [people, tags] = await Promise.all([api('users.php'), api('tags.php?team_id='+teamId)]);
        state.modalContexts[teamId] = {
          members: (people.users || []).filter(u => Number(u.team_id ?? teamId)===teamId && (u.is_active===undefined || !!Number(u.is_active))),
          tags: tags.tags || [],
        };
      }
      renderTaskModal(task, state.modalContexts[teamId]);
    } catch (err) {
      document.getElementById('task-modal-body').innerHTML = `<div class="load-error-box">
        <p>Couldn't load this task: ${esc(err.message)}</p>
        <button type="button" class="btn btn-ghost" id="task-modal-retry">Try again</button>
      </div>`;
      const retryBtn = document.getElementById('task-modal-retry');
      if (retryBtn) retryBtn.addEventListener('click', () => openTaskModal(taskId));
    }
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

    body.innerHTML = `
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
      <button class="btn btn-ghost" id="tm-duplicate" type="button">Duplicate task</button>
      ${(iHave(['administrator', 'manager']) || Number(task.created_by) === Number(state.user.id)) ? '<button class="btn btn-ghost" id="tm-delete" style="color:var(--danger);">Delete task</button>' : ''}

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

      <div class="section-title">Checklist</div>
      <div id="tm-checklist">${task.checklist.map(item => checklistItemHtml(item, canEditTask)).join('') || '<p style="color:var(--text-faint);font-size:13px;">No checklist items yet.</p>'}</div>
      ${canEditTask ? `<form class="add-inline-form" id="tm-add-checklist">
        <input placeholder="Add checklist item…" id="tm-checklist-input">
        <button class="btn btn-ghost" type="submit">Add</button>
      </form>` : ''}

      <div class="section-title">Recent activity</div>
      <div class="task-activity-list">${(task.recent_activity || []).map(a=>`<div class="task-activity-item"><strong>${esc(a.actor)}</strong> ${esc((a.action||'').replaceAll('_',' '))} <span class="comment-time">${esc(a.created_at)}</span></div>`).join('') || '<p class="dependency-empty">No logged changes yet.</p>'}</div>

      <div class="section-title">Comments</div>
      <div id="tm-comments">${task.comments.map(commentHtml).join('') || '<p style="color:var(--text-faint);font-size:13px;">No comments yet.</p>'}</div>
      <form class="add-inline-form" id="tm-add-comment">
        <input placeholder="Write a comment… use @Name to mention" id="tm-comment-input">
        <button class="btn btn-ghost" type="submit">Post</button>
      </form>
    `;

    document.getElementById('tm-save')?.addEventListener('click', () => saveTask(task.id));
    if (!canEditTask) ['tm-title','tm-desc','tm-status','tm-priority','tm-start','tm-due','tm-tags','tm-assignees'].forEach(id => {
      const input=document.getElementById(id);if(input) input.disabled=true;
    });
    document.getElementById('tm-duplicate').addEventListener('click', () => duplicateTask(task.id));
    document.getElementById('tm-delete')?.addEventListener('click', () => deleteTask(task.id));
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
    document.getElementById('tm-add-comment').addEventListener('submit', e => addComment(e, task.id));
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
        } catch (err) {
          toast(err.message, true);
          cb.checked = !cb.checked; // revert the visual toggle since the save failed
        }
      });
    });
    body.querySelectorAll('.comment-delete[data-comment]').forEach(btn => {
      btn.addEventListener('click', async () => {
        try {
          await api('comments.php', { method: 'DELETE', body: { id: Number(btn.dataset.comment) } });
          openTaskModal(task.id);
        } catch (err) { toast(err.message, true); }
      });
    });
  }

  function checklistItemHtml(item, canEdit = true) {
    return `<div class="checklist-item ${item.is_done ? 'is-done' : ''}">
      <input type="checkbox" class="checklist-toggle" data-item="${item.id}" ${item.is_done ? 'checked' : ''} ${canEdit ? '' : 'disabled'}>
      <span>${esc(item.content)}</span>
    </div>`;
  }
  function commentHtml(c) {
    const canDelete = c.user_id === state.user.id || iHave(['administrator', 'manager']);
    return `<div class="comment">
      <span class="avatar" style="background:${esc(c.avatar_color)}">${esc(initials(c.name))}</span>
      <div class="comment-body">
        <div class="comment-header">
          <span>${esc(c.name)}${c.roles && c.roles.length ? ` <span style="color:var(--text-faint);font-weight:400;">(${esc(rolesLabel(c.roles))})</span>` : ''} <span class="comment-time">${esc(c.created_at)}</span></span>
          ${canDelete ? `<button class="comment-delete" data-comment="${c.id}">Delete</button>` : ''}
        </div>
        <div class="comment-text">${esc(c.content)}</div>
      </div>
    </div>`;
  }

  async function saveTask(taskId) {
    const body = {
      id: taskId,
      title: document.getElementById('tm-title').value.trim(),
      description: document.getElementById('tm-desc').value,
      status: document.getElementById('tm-status').value,
      priority: document.getElementById('tm-priority').value,
      start_date: document.getElementById('tm-start').value,
      due_date: document.getElementById('tm-due').value,
      tag_ids: Array.from(document.getElementById('tm-tags').selectedOptions).map(o => Number(o.value)),
    };
    if (iHave(['administrator', 'manager'])) {
      body.assignee_ids = Array.from(document.getElementById('tm-assignees').selectedOptions).map(o => Number(o.value));
    }
    try {
      await api('task.php', { method: 'PUT', body });
      toast('Task saved');
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
      await api('comments.php', { method: 'POST', body: { task_id: taskId, content: input.value.trim() } });
      input.value = '';
      openTaskModal(taskId);
    } catch (err) { toast(err.message, true); }
  }
  function closeTaskModal() { document.getElementById('task-modal').classList.add('hidden'); }
  document.getElementById('close-task-modal').addEventListener('click', closeTaskModal);
  document.getElementById('task-modal').addEventListener('click', e => { if (e.target.id === 'task-modal') closeTaskModal(); });

  function refreshCurrentView() {
    if (state.currentView === 'monthly') renderMonthly();
    else if (state.currentView === 'tasks') renderTasksView();
    else if (state.currentView === 'board') renderBoard();
    else if (state.currentView === 'my-work') renderMyWork();
    else if (state.currentView === 'search') renderSearchTasks();
  }

  // ---------------------------------------------------------------
  // Generic prompt modal
  // ---------------------------------------------------------------
  function promptModal(title, bodyHtml, onConfirm) {
    const modal = document.getElementById('prompt-modal');
    document.getElementById('prompt-title').textContent = title;
    document.getElementById('prompt-body').innerHTML = bodyHtml;
    modal.classList.remove('hidden');
    const confirmBtn = document.getElementById('prompt-confirm');
    const cancelBtn = document.getElementById('prompt-cancel');
    const cleanup = () => { modal.classList.add('hidden'); confirmBtn.onclick = null; cancelBtn.onclick = null; };
    confirmBtn.onclick = async () => {
      const originalLabel = confirmBtn.textContent;
      confirmBtn.disabled = true;
      confirmBtn.textContent = 'Working…';
      try {
        await onConfirm();
        cleanup();
      } catch (err) {
        toast(err.message || 'Something went wrong.', true);
      } finally {
        confirmBtn.disabled = false;
        confirmBtn.textContent = originalLabel;
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
