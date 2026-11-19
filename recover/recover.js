(() => {
  'use strict';
  const status = document.getElementById('recover-status');
  const requestForm = document.getElementById('recover-request-form');
  const resetForm = document.getElementById('recover-reset-form');
  const requestButton = document.getElementById('recover-request-btn');
  const resetButton = document.getElementById('recover-reset-btn');
  const hash = window.location.hash;
  // The capability stays in the fragment, never the HTTP request URL, and is
  // erased from browser history as soon as this page loads.
  const token = new URLSearchParams(hash.startsWith('#') ? hash.slice(1) : '').get('token') || '';
  window.history.replaceState(null, '', window.location.pathname);
  const request = async (action, body = {}) => {
    const response = await fetch('../api/password_recovery.php', {
      method:'POST', credentials:'omit', cache:'no-store',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action,...body}),
    });
    let data = {};
    try { data = await response.json(); } catch (_) { /* no HTML error disclosure */ }
    if (!response.ok) throw new Error(data.error || 'The request could not be completed.');
    return data;
  };
  if (hash) {
    requestForm.hidden = true;
    if (!/^[a-f0-9]{64}$/.test(token)) {
      status.textContent = 'The recovery link is invalid. Request a new link or contact your administrator.';
    } else {
      status.textContent = 'Checking your recovery link…';
      request('inspect',{token}).then(() => {
        resetForm.hidden = false;
        status.textContent = 'Your link is valid. Choose a new password.';
      }).catch(err => { status.textContent = err.message; });
    }
  }
  requestForm.addEventListener('submit', async e => {
    e.preventDefault();
    requestButton.disabled = true;
    status.textContent = 'Checking your request…';
    try {
      const result = await request('request', {email:document.getElementById('recover-email').value.trim()});
      status.textContent = result.message;
      requestForm.hidden = true;
    } catch (err) { status.textContent = err.message; requestButton.disabled = false; }
  });
  resetForm.addEventListener('submit', async e => {
    e.preventDefault();
    const password = document.getElementById('recover-password').value;
    const confirm_password = document.getElementById('recover-confirm').value;
    if (password !== confirm_password) { status.textContent = 'Passwords do not match.'; return; }
    const bytes = new TextEncoder().encode(password).length;
    if (bytes < 12 || bytes > 72) { status.textContent = 'Use a password of 12–72 bytes.'; return; }
    resetButton.disabled = true;
    status.textContent = 'Updating your password…';
    try {
      const result = await request('reset', {token,password,confirm_password});
      status.textContent = result.message;
      resetForm.hidden = true;
    } catch (err) { status.textContent = err.message; resetButton.disabled = false; }
  });
})();
