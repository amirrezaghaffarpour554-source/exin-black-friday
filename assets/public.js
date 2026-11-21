(function () {
  'use strict';
  const anchors = new Map();
  const pending = new Map();
  const config = window.exinBFPublic || {};
  function elements() { return document.querySelectorAll('[data-exin-bf-countdown]'); }
  function draw() {
    elements().forEach(function (node) {
      const id = Number(node.dataset.campaign);
      const anchor = anchors.get(id);
      if (!anchor) return;
      const now = anchor.server + (performance.now() - anchor.local) / 1000;
      if (!anchor.active || id !== anchor.id || now >= anchor.end) {
        node.textContent = config.ended || '';
        return;
      }
      const target = node.dataset.target === 'stage' ? anchor.stageEnd : anchor.end;
      if (now >= target) { node.textContent = config.ended || ''; anchors.delete(id); sync(); return; }
      const left = Math.max(0, Math.floor(target - now));
      const days = Math.floor(left / 86400);
      const hours = String(Math.floor(left / 3600) % 24).padStart(2, '0');
      const minutes = String(Math.floor(left / 60) % 60).padStart(2, '0');
      const seconds = String(left % 60).padStart(2, '0');
      node.textContent = days + ' ' + (config.days || 'days') + ' ' + hours + ':' + minutes + ':' + seconds;
    });
    badges();
  }
  function badges() { document.querySelectorAll('[data-exin-bf-badge]').forEach(node => { const anchor = anchors.get(Number(node.dataset.campaign)); if (anchor) node.hidden = !anchor.active || anchor.server + (performance.now() - anchor.local) / 1000 >= anchor.end; }); }
  function sync() {
    if (!config.timeUrl) return;
    const ids = new Set(Array.from(document.querySelectorAll('[data-exin-bf-countdown],[data-exin-bf-badge]'), node => Number(node.dataset.campaign)));
    ids.forEach(function (id) {
      const existing = anchors.get(id);
      if (!Number.isSafeInteger(id) || id < 1 || pending.has(id) || (existing && performance.now() - existing.local < 60000)) return;
      const started = performance.now();
      const url = new URL(config.timeUrl, window.location.href);
      url.searchParams.set('campaign_id', String(id));
      const request = fetch(url.toString(), { cache: 'no-store', credentials: 'same-origin' })
        .then(function (response) { if (!response.ok) throw new Error('server time'); return response.json(); })
        .then(function (data) {
          if (!Number.isFinite(data.server_time)) throw new Error('server time');
          anchors.set(id, { server: data.server_time + (performance.now() - started) / 2000, local: performance.now(), active: data.active === true, id: Number(data.campaign_id), end: Number(data.ends_at), stageEnd: Number(data.stage_ends_at || data.ends_at) });
          draw();
        })
        .catch(function () { elements().forEach(function (node) { if (Number(node.dataset.campaign) === id) node.textContent = config.unavailable || ''; }); })
        .finally(function () { pending.delete(id); });
      pending.set(id, request);
    });
    draw();
  }
  function init() { sync(); window.setInterval(draw, 1000); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  document.addEventListener('visibilitychange', function () { if (!document.hidden) sync(); });
  if (window.elementorFrontend && window.elementorFrontend.hooks) window.elementorFrontend.hooks.addAction('frontend/element_ready/global', sync);
}());
