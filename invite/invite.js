(() => {
  'use strict';
  const status = document.getElementById('invite-status');
  const details = document.getElementById('invite-details');
  const form = document.getElementById('invite-form');
  const button = document.getElementById('invite-submit');
  // Read the capability only from the fragment, which never goes to web server logs.
  const hash = window.location.hash;
  const token = new URLSearchParams(hash.startsWith('#') ? hash.slice(1) : '').get('token') || '';
  window.history.replaceState(null, '', window.location.pathname);
  const request = async (action, body = {}) => {
    const response = await fetch('../api/invite_accept.php', {
      method: 'POST', credentials: 'omit',
      headers: {'Content-Type':'application/json'},
      cache:'no-store',
      body:JSON.stringify({action, token, ...body}),
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Invitation is unavailable.');
    return data;
  };
  async function init() {
    if (!/^[a-f0-9]{64}$/.test(token)) {
      status.textContent = 'Missing or invalid invitation. Open the original link again, or ask your administrator for a new one.';
      return;
    }
    try {
      const info = await request('inspect');
      const greeting = document.createElement('p');
      greeting.textContent = `${info.name} · ${info.email}`;
      const team = document.createElement('p');
      team.textContent = `Access: ${info.roles.join(', ')}${info.team_name ? ' · '+info.team_name : ''}`;
      details.append(greeting, team);
      details.hidden = false;
      form.hidden = false;
      status.textContent = 'Your invitation is valid. Set your password to continue.';
    } catch (e) { status.textContent = e.message; }
  }
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const password = document.getElementById('invite-password').value;
    const confirm_password = document.getElementById('invite-password-confirm').value;
    if (password !== confirm_password) { status.textContent = 'Passwords do not match.'; return; }
    // UTF-8 byte count matches the server's password policy.
    const bytes = new TextEncoder().encode(password).length;
    if (bytes < 12 || bytes > 72) { status.textContent = 'Password must be 12–72 bytes.'; return; }
    button.disabled = true;
    status.textContent = 'Activating account…';
    try {
      const data = await request('accept', {password,confirm_password});
      form.hidden = true;
      details.hidden = true;
      status.textContent = data.message;
    } catch (e) {
      status.textContent = e.message;
      button.disabled = false;
    }
  });
  init();
})();
