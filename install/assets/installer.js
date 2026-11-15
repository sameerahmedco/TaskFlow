(() => {
  'use strict';
  const form = document.getElementById('setup-form');
  if (!form) return;
  const panels = Array.from(form.querySelectorAll('.step-panel'));
  const indicators = Array.from(document.querySelectorAll('.steps li'));
  const next = document.getElementById('next');
  const back = document.getElementById('back');
  const finish = document.getElementById('finish');
  let step = 0;
  function show(index) {
    step = index;
    panels.forEach((panel, i) => { panel.hidden = i !== step; });
    indicators.forEach((indicator, i) => {
      indicator.classList.toggle('active', i === step);
      indicator.classList.toggle('complete', i < step);
      if (i === step) indicator.setAttribute('aria-current', 'step');
      else indicator.removeAttribute('aria-current');
    });
    const counter = document.getElementById('step-counter');
    if (counter) counter.textContent = 'Step ' + (step + 1) + ' of ' + panels.length;
    back.hidden = step === 0;
    next.hidden = step === panels.length - 1;
    finish.hidden = step !== panels.length - 1;
    window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  }
  next.addEventListener('click', () => {
    const inputs = Array.from(panels[step].querySelectorAll('input[required]'));
    for (const el of inputs) if (!el.reportValidity()) return;
    show(Math.min(step + 1, panels.length - 1));
  });
  back.addEventListener('click', () => show(Math.max(step - 1, 0)));
  form.addEventListener('submit', (e) => {
    const password = form.elements.admin_password;
    if (password.value !== form.elements.confirm_password.value) {
      e.preventDefault();
      form.elements.confirm_password.setCustomValidity('Passwords do not match');
      form.elements.confirm_password.reportValidity();
      form.elements.confirm_password.setCustomValidity('');
    }
  });
  show(0);
})();
