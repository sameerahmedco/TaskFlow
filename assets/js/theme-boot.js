/* TaskFlow 1.22: no-flash appearance initialization; Light is the default. */
(() => {
  'use strict';
  const options=['light','dark','system','ocean','forest','lavender','sunset','midnight'];
  let pref='light';
  try { pref=localStorage.getItem('taskflow-appearance') || 'light'; } catch (_) {}
  if (!options.includes(pref)) pref='light';
  const systemDark=typeof window.matchMedia==='function' && window.matchMedia('(prefers-color-scheme: dark)').matches;
  const resolved=pref==='system' ? (systemDark?'dark':'light') : (pref==='dark'||pref==='midnight'?'dark':'light');
  document.documentElement.dataset.theme=resolved;
  document.documentElement.dataset.themePreference=pref;
  document.documentElement.dataset.palette=pref==='system' ? resolved : pref;
})();
