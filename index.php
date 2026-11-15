<?php
require_once __DIR__ . '/includes/functions.php';
if (!APP_INSTALLED) {
    header('Location: install/index.php', true, 302);
    exit;
}
start_secure_session();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#101b2c">
<meta name="description" content="TaskFlow is a calm, secure and self-hosted workspace for projects, tasks and teams.">
<title>TaskFlow | Work beautifully together</title>
<link rel="icon" type="image/png" href="assets/img/favicon-64.png">
<link rel="stylesheet" href="assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/style.css') ?: time(); ?>">
<link rel="stylesheet" href="assets/css/design-system.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/design-system.css') ?: time(); ?>">
</head>
<body>

<!-- ===================== SIGN IN ===================== -->
<div id="auth-screen" class="auth-screen auth-split">
  <div class="auth-story" aria-label="About TaskFlow">
    <div class="auth-story-brand"><img class="brand-mark" src="assets/img/taskflow-logo-192.png" alt=""><span>TaskFlow</span><span class="auth-story-version">1.0.0</span></div>
    <div class="auth-story-inner">
      <div class="auth-story-eyebrow"><span class="signal-dot"></span> Your work, in one place</div>
      <h1>Great work starts<br>with <em>clear thinking.</em></h1>
      <p>A focused home for your tasks, teammates and momentum. Everything you need; nothing in the way.</p>
      <div class="auth-preview" aria-hidden="true">
        <div class="auth-preview-header"><span class="auth-preview-dots"><i></i><i></i><i></i></span><span>TEAM OVERVIEW</span><span class="auth-preview-avatar">TF</span></div>
        <div class="auth-preview-heading">Your team's focus <span>This week</span></div>
        <div class="auth-preview-metrics"><div><strong>24</strong><small>In progress</small></div><div><strong>08</strong><small>In review</small></div><div><strong>62%</strong><small>Complete</small></div></div>
        <div class="auth-preview-task"><span class="auth-preview-check done">✓</span><span>Project kickoff and planning</span><small>Done</small></div>
        <div class="auth-preview-task"><span class="auth-preview-check"></span><span>Review team milestones</span><small>Today</small></div>
        <div class="auth-preview-task"><span class="auth-preview-check"></span><span>Share launch checklist</span><small>Next</small></div>
      </div>
    </div>
    <div class="auth-story-bottom">Thoughtful by design <span>•</span> Private by default</div>
  </div>
  <div class="auth-login-pane">
    <div class="auth-card">
      <div class="auth-mobile-brand"><img class="brand-mark" src="assets/img/taskflow-logo-192.png" alt=""><strong>TaskFlow</strong></div>
      <span class="auth-form-eyebrow">WELCOME BACK</span>
      <h2 class="auth-heading">Sign in to your workspace</h2>
      <p class="auth-subtitle">Pick up where your team left off.</p>
      <form id="login-form" class="auth-form">
        <label>Email address
          <input type="email" name="email" required autocomplete="email" placeholder="you@organization.com">
        </label>
        <label>Password
          <input type="password" name="password" required autocomplete="current-password" placeholder="Your password">
        </label>
        <label class="auth-checkbox-row">
          <input type="checkbox" name="remember"> Keep me signed in on this device
        </label>
        <p class="auth-error" id="login-error" role="alert" aria-live="polite"></p>
        <button type="submit" class="btn btn-primary btn-block">Sign in <span aria-hidden="true">→</span></button>
      </form>
      <p class="auth-footnote">Accounts are managed by your organization administrator.</p>
      <div class="auth-trust"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 8 4v6c0 5.1-3.2 8.8-8 10-4.8-1.2-8-4.9-8-10V6l8-4Z"/><path d="m9 12 2 2 4-4"/></svg><span>Protected sign-in and private team data</span></div>
    </div>
  </div>
</div>

<!-- ===================== AUTHENTICATOR CHALLENGE ===================== -->
<div id="mfa-screen" class="auth-screen hidden">
  <div class="auth-card">
    <div class="auth-logo"><img class="brand-mark" src="assets/img/taskflow-logo-192.png" alt=""> Verify your sign-in</div>
    <p class="auth-footnote">Enter the six-digit code from your authenticator app or one of your unused recovery codes.</p>
    <form id="mfa-form" class="auth-form" autocomplete="off">
      <label>Verification or recovery code
        <input id="mfa-code" name="code" type="text" required autocomplete="one-time-code" inputmode="text" maxlength="30" autocapitalize="characters" spellcheck="false">
      </label>
      <p class="auth-error" id="mfa-error" role="alert"></p>
      <button type="submit" class="btn btn-primary btn-block">Verify and sign in</button>
      <button type="button" id="mfa-cancel" class="btn btn-ghost">Start over</button>
    </form>
  </div>
</div>

<!-- ===================== FORCED PASSWORD CHANGE ===================== -->
<div id="pw-screen" class="auth-screen hidden">
  <div class="auth-card">
    <div class="auth-logo"><img class="brand-mark" src="assets/img/taskflow-logo-192.png" alt=""> Set a new password</div>
    <form id="pw-form" class="auth-form">
      <label>New password <span class="hint">(12 to 72 bytes)</span>
        <input type="password" name="new_password" required minlength="12" maxlength="72" autocomplete="new-password">
      </label>
      <label>Confirm new password
        <input type="password" name="confirm_password" required minlength="12" maxlength="72" autocomplete="new-password">
      </label>
      <p class="auth-error" id="pw-error"></p>
      <button type="submit" class="btn btn-primary btn-block">Set password &amp; continue</button>
    </form>
  </div>
</div>

<!-- ===================== WORKSPACE ===================== -->
<div id="app" class="app hidden">
  <a class="skip-to-content" href="#view-container">Skip to content</a>
  <aside id="workspace-sidebar" class="sidebar" aria-label="Workspace navigation">
    <div class="sidebar-top">
      <div class="brand"><img class="brand-mark" src="assets/img/taskflow-logo-192.png" alt=""><span>TaskFlow</span><span class="brand-version">1.0.0</span></div>
      <div class="workspace-card">
        <div class="workspace-card-label">WORKSPACE</div>
        <div class="workspace-card-name" id="org-name"></div>
        <div class="team-switcher hidden" id="team-switcher"><label class="sr-only" for="team-select">Active team</label><select id="team-select"></select></div>
      </div>
    </div>
    <div class="sidebar-menu-scroll">
      <nav class="sidebar-nav" id="team-nav" aria-label="Team navigation">
      <span class="nav-group-label">YOUR WORK</span>
      <button type="button" class="nav-item" data-view="my-work"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#target"></use></svg><span>My Work</span></button>
      <button type="button" class="nav-item" data-view="board"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#columns"></use></svg><span>Board</span></button>
      <button type="button" class="nav-item" data-view="tasks"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#check-square"></use></svg><span>Tasks</span></button>
      <button type="button" class="nav-item" data-view="search"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#search"></use></svg><span>Find tasks</span></button>
      <button type="button" class="nav-item" data-view="inbox"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#bell"></use></svg><span>Inbox</span><span id="inbox-badge" class="inbox-badge hidden" aria-label="Unread notifications"></span></button>
      <span class="nav-group-label">PLANNING</span>
      <button type="button" class="nav-item" data-view="monthly"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#calendar"></use></svg><span>Monthly plan</span></button>
      <button type="button" class="nav-item" data-view="priorities"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#flag"></use></svg><span>Weekly goals</span></button>
      <button type="button" class="nav-item" data-view="team-dashboard"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#bar-chart"></use></svg><span>Team insights</span></button>
      <span class="nav-group-label">COLLABORATE</span>
      <button type="button" class="nav-item" data-view="chat"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#message"></use></svg><span>Team chat</span></button>
      <button type="button" class="nav-item" data-view="roster"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#users"></use></svg><span>People</span></button>
      <button type="button" class="nav-item" data-view="activity"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#clock"></use></svg><span>Activity</span></button>
      <span class="nav-group-label">ACCOUNT</span>
      <button type="button" class="nav-item" data-view="my-account"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#settings"></use></svg><span>My account</span></button>
      </nav>
      <div class="sidebar-section-title" id="admin-section-title">ORGANIZATION</div>
      <nav class="sidebar-nav hidden" id="admin-nav" aria-label="Administration">
      <button type="button" class="nav-item" data-view="org-dashboard"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#building"></use></svg><span>Overview</span></button>
      <button type="button" class="nav-item" data-view="teams"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#layers"></use></svg><span>Teams</span></button>
      <button type="button" class="nav-item" data-view="users"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#user-plus"></use></svg><span>Accounts</span></button>
      <button type="button" class="nav-item" data-view="org-activity"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#activity"></use></svg><span>Audit activity</span></button>
      <button type="button" class="nav-item" data-view="advanced-settings"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#sliders"></use></svg><span>Settings</span></button>
      </nav>
    </div>
    <div class="sidebar-bottom">
      <div class="me-chip" id="me-chip"></div>
      <div class="sidebar-bottom-actions">
        <button id="change-pw-btn" class="icon-btn" title="Change password" aria-label="Change password"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#key"></use></svg></button>
        <button id="logout-btn" class="btn btn-ghost logout-btn" title="Log out">Sign out</button>
      </div>
      <div class="sidebar-version">TaskFlow <span>v1.0.0</span></div>
    </div>
  </aside>
  <main class="main" id="main-content">
    <header class="topbar">
      <button class="mobile-menu-btn" id="mobile-menu-btn" aria-label="Open navigation menu" aria-expanded="false" aria-controls="workspace-sidebar"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#menu"></use></svg></button>
      <div class="topbar-heading"><div class="topbar-overline" id="view-overline">WORKSPACE / OVERVIEW</div><h1 id="view-title">My Work</h1></div>
      <div class="topbar-utilities">
        <button class="topbar-search" id="topbar-search" type="button" aria-label="Search tasks"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#search"></use></svg><span>Search anything</span><kbd>⌘ K</kbd></button>
        <button class="theme-switch" id="theme-switch" type="button" aria-label="Switch appearance" title="Switch appearance"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#moon"></use></svg></button>
        <button class="btn btn-primary header-create-btn" id="quick-add-task" type="button"><svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/img/icons.svg#plus"></use></svg><span>New task</span></button>
      </div>
    </header>
    <div class="view-toolbar" id="view-toolbar"><div class="view-description" id="view-description">A clear view of what needs your attention.</div><div class="topbar-actions" id="topbar-actions"></div></div>
    <div id="view-container" class="view-container" tabindex="-1"></div>
  </main>
</div>
<div class="sidebar-backdrop" id="sidebar-backdrop"></div>

<!-- ===================== TASK DETAIL MODAL ===================== -->
<div id="task-modal" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-label="Task details">
  <div class="modal task-modal">
    <button class="modal-close" id="close-task-modal" aria-label="Close task details">&#10005;</button>
    <div id="task-modal-body"></div>
  </div>
</div>

<!-- ===================== GENERIC PROMPT MODAL ===================== -->
<div id="prompt-modal" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="prompt-title">
  <div class="modal prompt-modal">
    <h3 id="prompt-title"></h3>
    <div id="prompt-body"></div>
    <div class="prompt-actions">
      <button class="btn btn-ghost" id="prompt-cancel">Cancel</button>
      <button class="btn btn-primary" id="prompt-confirm">Confirm</button>
    </div>
  </div>
</div>

<div id="toast" class="toast hidden" role="status" aria-live="polite"></div>

<script src="assets/js/app.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/app.js') ?: time(); ?>"></script>
</body>
</html>
