(function () {
  'use strict';
  let anchor = null;
  let pending = null;
  const config = window.exinBFPublic || {};
  function elements() { return document.querySelectorAll('[data-exin-bf-countdown]'); }
  function draw() {
    if (!anchor) return;
    const now = anchor.server + (performance.now() - anchor.local) / 1000;
    elements().forEach(function (node) {
      if (!anchor.active || Number(node.dataset.campaign) !== anchor.id || now >= anchor.end) {
        node.textContent = config.ended || '';
        return;
      }
      const left = Math.max(0, Math.floor(anchor.end - now));
      const days = Math.floor(left / 86400);
      const hours = String(Math.floor(left / 3600) % 24).padStart(2, '0');
      const minutes = String(Math.floor(left / 60) % 60).padStart(2, '0');
      const seconds = String(left % 60).padStart(2, '0');
      node.textContent = days + ' ' + (config.days || 'days') + ' ' + hours + ':' + minutes + ':' + seconds;
    });
  }
  function sync() {
    if (pending || !elements().length || !config.timeUrl) return;
    const started = performance.now();
    pending = fetch(config.timeUrl, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (response) { if (!response.ok) throw new Error('server time'); return response.json(); })
      .then(function (data) {
        if (!Number.isFinite(data.server_time)) throw new Error('server time');
        anchor = { server: data.server_time + (performance.now() - started) / 2000, local: performance.now(), active: data.active === true, id: Number(data.campaign_id), end: Number(data.ends_at) };
        draw();
      })
      .catch(function () { elements().forEach(function (node) { node.textContent = config.unavailable || ''; }); })
      .finally(function () { pending = null; });
  }
  function init() { sync(); window.setInterval(draw, 1000); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  document.addEventListener('visibilitychange', function () { if (!document.hidden && (!anchor || performance.now() - anchor.local > 60000)) sync(); });
  if (window.elementorFrontend && window.elementorFrontend.hooks) window.elementorFrontend.hooks.addAction('frontend/element_ready/global', sync);
}());
