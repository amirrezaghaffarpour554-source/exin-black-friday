/* global exinBFAdmin, wp */
(() => {
    'use strict';
    const root = document.getElementById('exin-bf-admin');
    if (!root) return;
    const cfg = exinBFAdmin;
    const content = document.getElementById('exin-bf-content');
    const message = document.getElementById('exin-bf-message');
    const dialog = document.getElementById('exin-bf-dialog');
    const modal = document.getElementById('exin-bf-dialog-content');
    const title = document.getElementById('exin-bf-dialog-title');
    let state = null;
    let view = 'dashboard';
    let page = 1;
    let requestVersion = 0;
    let searchVersion = 0;
    let selectedProduct = null;
    let previousFocus = null;
    let serverOffset = 0;
    let countdownTimer;
    const statuses = {
        draft: wp.i18n.__('پیش‌نویس', 'exin-black-friday'), scheduled: wp.i18n.__('زمان‌بندی‌شده', 'exin-black-friday'), active: wp.i18n.__('فعال', 'exin-black-friday'), paused: wp.i18n.__('متوقف', 'exin-black-friday'),
        completed: wp.i18n.__('پایان‌یافته', 'exin-black-friday'), failed: wp.i18n.__('ناموفق', 'exin-black-friday'), included: wp.i18n.__('انتخاب‌شده', 'exin-black-friday'), pending: wp.i18n.__('در صف', 'exin-black-friday'),
        sold_out: wp.i18n.__('ناموجود', 'exin-black-friday'), conflict: wp.i18n.__('تعارض', 'exin-black-friday'), removed: wp.i18n.__('حذف‌شده', 'exin-black-friday')
    };
    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char]));
    const status = (value) => `<span class="exin-bf-status" data-status="${esc(value)}">${esc(statuses[value] || value)}</span>`;
    const price = (value) => `<span class="exin-bf-price">${esc(value ?? '—')} ${esc(cfg.currency)}</span>`;
    const button = (label, command, cls = '', extra = '') => `<button type="button" class="exin-bf-button ${cls}" data-command="${esc(command)}" ${extra}>${esc(label)}</button>`;
    const campaignPath = (suffix) => `campaign/${state.campaign.id}/${suffix}`;
    function notice(text, error = false) {
        message.textContent = text;
        message.hidden = false;
        message.dataset.error = error ? 'true' : 'false';
        message.scrollIntoView({block: 'nearest', behavior: 'smooth'});
        if (dialog.open && error) {
            let dialogMessage = modal.querySelector('.exin-bf-modal-error');
            if (!dialogMessage) { dialogMessage = document.createElement('div'); dialogMessage.className = 'exin-bf-warning exin-bf-modal-error'; dialogMessage.setAttribute('role', 'alert'); modal.prepend(dialogMessage); }
            dialogMessage.textContent = text;
            dialogMessage.scrollIntoView({block: 'nearest'});
        }
    }
    async function api(path, data) {
        const url = new URL(cfg.root, window.location.href);
        const relative = new URL(path, 'https://exin.invalid/');
        if (url.searchParams.has('rest_route')) {
            url.searchParams.set('rest_route', url.searchParams.get('rest_route') + relative.pathname.slice(1));
            relative.searchParams.forEach((value, key) => url.searchParams.set(key, value));
        } else {
            url.pathname += relative.pathname.slice(1);
            relative.searchParams.forEach((value, key) => url.searchParams.set(key, value));
        }
        const response = await fetch(url.toString(), {
            method: data === undefined ? 'GET' : 'POST', credentials: 'same-origin',
            headers: {'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json'},
            body: data === undefined ? undefined : JSON.stringify(data)
        });
        let value;
        try { value = await response.json(); } catch (_) { throw new Error(wp.i18n.__('پاسخ سرور معتبر نیست؛ اتصال را بررسی کنید.', 'exin-black-friday')); }
        if (!response.ok) throw new Error(value.message || wp.i18n.__('عملیات انجام نشد.', 'exin-black-friday'));
        return value;
    }
    async function load() {
        const version = ++requestVersion;
        content.setAttribute('aria-busy', 'true');
        try {
            const result = await api(`state?page=${page}&view=${encodeURIComponent(view)}`);
            if (version !== requestVersion) return;
            state = result;
            serverOffset = state.server_time * 1000 - Date.now();
            render();
        } catch (error) { notice(error.message, true); }
        finally { if (version === requestVersion) content.setAttribute('aria-busy', 'false'); }
    }
    function render() {
        clearInterval(countdownTimer);
        root.querySelectorAll('[data-view]').forEach((node) => {
            if (node.dataset.view === view) node.setAttribute('aria-current', 'page');
            else node.removeAttribute('aria-current');
        });
        if (view === 'settings') renderSettings();
        else if (view === 'diagnostics') renderDiagnostics();
        else if (!state.campaign) renderSetup();
        else if (view === 'products') renderProducts();
        else renderDashboard();
    }
    function renderSetup() {
        content.innerHTML = `<section class="exin-bf-card exin-bf-empty"><span class="exin-bf-status">${esc(wp.i18n.__('شروع سریع · ۵ مرحله', 'exin-black-friday'))}</span><h2>${esc(wp.i18n.__('کمپین بعدی را آماده کنید', 'exin-black-friday'))}</h2><p>${esc(wp.i18n.__('زمان کمپین را تعیین کنید، الگوی کاهش قیمت و ارسال را انتخاب کنید، محصولات را اضافه کنید و پس از بررسی، زمان‌بندی را فعال کنید. هیچ صفحه یا تنظیم فروشگاه به‌طور خودکار تغییر نمی‌کند.', 'exin-black-friday'))}</p>${button(wp.i18n.__('ساخت پیش‌نویس کمپین', 'exin-black-friday'), 'settings', 'exin-bf-primary')}</section>`;
    }
    function actions() {
        const c = state.campaign;
        let result = '';
        if (c.status === 'draft') {
            const begins = new Date(c.start_utc.replace(' ', 'T') + 'Z').getTime();
            const current = Date.now() + serverOffset;
            result += current >= begins
                ? button(wp.i18n.__('شروع کمپین', 'exin-black-friday'), 'start', 'exin-bf-primary')
                : button(wp.i18n.__('فعال‌کردن زمان‌بندی', 'exin-black-friday'), 'schedule', 'exin-bf-primary');
        }
        if (c.status === 'scheduled') result += button(wp.i18n.__('بررسی و شروع در زمان مقرر', 'exin-black-friday'), 'start', 'exin-bf-primary');
        if (['active', 'scheduled'].includes(c.status)) result += button(wp.i18n.__('توقف بدون بازیابی', 'exin-black-friday'), 'pause');
        if (c.status === 'paused') result += button(wp.i18n.__('ادامه کمپین', 'exin-black-friday'), 'resume', 'exin-bf-primary');
        if (['active', 'paused', 'failed', 'scheduled', 'completed'].includes(c.status)) result += button(wp.i18n.__('توقف و بازیابی قیمت‌ها', 'exin-black-friday'), 'restore', 'exin-bf-danger');
        return result;
    }
    function renderDashboard() {
        const c = state.campaign;
        const a = state.analytics || {};
        const orders = a.order_count ?? a.orders ?? 0;
        const units = a.units_sold ?? a.units ?? 0;
        const revenue = a.campaign_revenue ?? a.item_revenue ?? '0';
        const total = a.related_order_total ?? a.order_revenue ?? a.order_total ?? '0';
        const goal = c.settings?.target_orders || 500;
        content.innerHTML = `<section class="exin-bf-card exin-bf-hero"><div>${status(c.status)}<h2>${esc(c.title)}</h2><p>${esc(localDate(c.start_utc, c.timezone))} ← ${esc(localDate(c.end_utc, c.timezone))} · ${esc(c.timezone)}</p><div class="exin-bf-actions">${actions()}</div></div><div class="exin-bf-countdown" id="exin-bf-countdown" aria-label="${esc(wp.i18n.__('زمان باقی‌مانده', 'exin-black-friday'))}"></div></section>
            <div class="exin-bf-stats">${stat(wp.i18n.__('محصول منتخب', 'exin-black-friday'), state.count)}${stat(wp.i18n.__('سفارش مرتبط', 'exin-black-friday'), orders)}${stat(wp.i18n.__('درآمد اقلام کمپین', 'exin-black-friday'), revenue, true)}${stat(wp.i18n.__('واحد فروخته‌شده', 'exin-black-friday'), units)}</div>
            <section class="exin-bf-card"><div class="exin-bf-toolbar"><h2>${esc(wp.i18n.__('هدف فروش', 'exin-black-friday'))}</h2><strong>${esc(orders)} / ${esc(goal)}</strong></div><progress class="exin-bf-progress" max="${esc(goal)}" value="${esc(Math.min(Number(orders) || 0, Number(goal)))}">${esc(orders)}</progress><p class="exin-bf-note">${esc(wp.i18n.__('مبلغ کل سفارش‌های مرتبط', 'exin-black-friday'))}: ${price(total)} · ${esc(wp.i18n.__('درآمد اقلام از کل سفارش جدا محاسبه می‌شود؛ مرجوعی‌ها کسر می‌شوند.', 'exin-black-friday'))}</p>${a.complete === false ? `<p class="exin-bf-warning">${esc(wp.i18n.__('گزارش در حال تکمیل است؛ اعداد فعلی موقت‌اند. برای ادامه محاسبه، «ادامه محاسبه گزارش» را بزنید.', 'exin-black-friday'))} ${button(wp.i18n.__('ادامه محاسبه گزارش', 'exin-black-friday'), 'refresh')}</p>` : ''}</section>
            ${c.last_error ? `<p class="exin-bf-warning">${esc(wp.i18n.__('آخرین خطا', 'exin-black-friday'))}: ${esc(c.last_error)} ${button(wp.i18n.__('بررسی عیب‌یابی', 'exin-black-friday'), 'diagnostics')}</p>` : ''}
            <div class="exin-bf-orders"><section class="exin-bf-card"><h2>${esc(wp.i18n.__('پرفروش‌ترین محصولات', 'exin-black-friday'))}</h2>${renderBestsellers(a.top_products || a.bestsellers || [])}</section><section class="exin-bf-card"><h2>${esc(wp.i18n.__('آخرین سفارش‌های مرتبط', 'exin-black-friday'))}</h2>${renderOrders(a.recent_orders || [])}</section></div>
            <div class="exin-bf-toolbar"><p class="exin-bf-note">${esc(wp.i18n.__('آخرین اجرای موفق', 'exin-black-friday'))}: ${esc(localDate(c.last_success, c.timezone))}</p>${button(wp.i18n.__('مشاهده محصولات', 'exin-black-friday'), 'products')}</div>`;
        countdownTimer = setInterval(updateCountdown, 1000);
        updateCountdown();
    }
    function stat(label, value, isPrice = false) {
        return `<section class="exin-bf-stat"><span>${esc(label)}</span><strong>${isPrice ? price(value) : esc(value)}</strong></section>`;
    }
    function renderBestsellers(products) {
        if (!products || !Object.keys(products).length) return `<p class="exin-bf-note">${esc(wp.i18n.__('هنوز داده‌ای ثبت نشده است.', 'exin-black-friday'))}</p>`;
        const values = Array.isArray(products) ? products : Object.entries(products).map(([id, data]) => ({product_id: id, ...data}));
        return `<ul class="exin-bf-list">${values.slice(0, 10).map((p) => `<li>${esc(p.name || `${wp.i18n.__('محصول', 'exin-black-friday')} #${p.product_id || p.id}`)} <small> · ${esc(p.units ?? p.quantity ?? 0)} ${esc(wp.i18n.__('واحد', 'exin-black-friday'))}</small></li>`).join('')}</ul>`;
    }
    function renderOrders(orders) {
        if (!Array.isArray(orders) || !orders.length) return `<p class="exin-bf-note">${esc(wp.i18n.__('هنوز سفارشی ثبت نشده است.', 'exin-black-friday'))}</p>`;
        return `<ul class="exin-bf-list">${orders.slice(0, 10).map((order) => `<li>#${esc(order.number || order.order_id || order.id)} · ${esc(order.status)} · ${price(order.total)}</li>`).join('')}</ul>`;
    }
    function localDate(value, timezone) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'));
        if (Number.isNaN(date.getTime())) return String(value);
        try { return new Intl.DateTimeFormat('fa-IR', {dateStyle: 'medium', timeStyle: 'short', timeZone: timezone || 'Asia/Tehran'}).format(date); }
        catch (_) { return String(value); }
    }
    function updateCountdown() {
        const node = document.getElementById('exin-bf-countdown');
        if (!node || !state.campaign) return;
        const c = state.campaign;
        const start = new Date(c.start_utc.replace(' ', 'T') + 'Z').getTime();
        const end = new Date(c.end_utc.replace(' ', 'T') + 'Z').getTime();
        const now = Date.now() + serverOffset;
        if (['completed', 'paused', 'failed'].includes(c.status)) { node.textContent = statuses[c.status]; return; }
        const seconds = Math.max(0, Math.floor(((now < start ? start : end) - now) / 1000));
        const parts = [Math.floor(seconds / 86400), Math.floor(seconds % 86400 / 3600), Math.floor(seconds % 3600 / 60), seconds % 60];
        node.textContent = parts.map((n) => String(n).padStart(2, '0')).join(' : ');
    }
    function renderProducts() {
        const rows = state.items || [];
        content.innerHTML = `<div class="exin-bf-toolbar"><div><h2>${esc(wp.i18n.__('محصولات کمپین', 'exin-black-friday'))} <span class="exin-bf-status">${esc(state.count)}</span></h2><span class="exin-bf-note">${esc(wp.i18n.__('هر تنوع محصول، یک ردیف مستقل دارد. قیمت‌ها در واحد پول فروشگاه هستند.', 'exin-black-friday'))}</span></div><div class="exin-bf-actions">${button(wp.i18n.__('افزودن گروهی', 'exin-black-friday'), 'bulk-add')}${button(wp.i18n.__('حذف انتخاب‌شده‌ها', 'exin-black-friday'), 'bulk-remove', 'exin-bf-danger')}${button(wp.i18n.__('افزودن محصول', 'exin-black-friday'), 'add', 'exin-bf-primary')}</div></div>
            <div class="exin-bf-filter"><input id="exin-bf-filter-text" class="exin-bf-search" type="search" placeholder="${esc(wp.i18n.__('جست‌وجو در صفحه جاری', 'exin-black-friday'))}" aria-label="${esc(wp.i18n.__('جست‌وجوی محصولات صفحه جاری', 'exin-black-friday'))}"><select id="exin-bf-filter-status" aria-label="${esc(wp.i18n.__('فیلتر وضعیت', 'exin-black-friday'))}"><option value="">${esc(wp.i18n.__('همه وضعیت‌ها', 'exin-black-friday'))}</option>${Object.entries(statuses).map(([value, label]) => `<option value="${esc(value)}">${esc(label)}</option>`).join('')}</select></div>
            <section class="exin-bf-card" style="margin-top:16px"><div class="exin-bf-table-scroll"><table class="exin-bf-table"><thead><tr><th><input type="checkbox" id="exin-bf-select-all" aria-label="${esc(wp.i18n.__('انتخاب همه محصولات صفحه', 'exin-black-friday'))}"></th><th>${esc(wp.i18n.__('محصول', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('قیمت مبنا', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('قیمت فعلی', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('کف قیمت', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('تخفیف فعلی', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('موجودی', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('وضعیت', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('ترتیب / ویژه', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('عملیات', 'exin-black-friday'))}</th></tr></thead><tbody>${rows.map(productRow).join('') || `<tr><td colspan="10">${esc(wp.i18n.__('محصولی اضافه نشده است.', 'exin-black-friday'))}</td></tr>`}</tbody></table></div></section>
            <div class="exin-bf-pagination">${button(wp.i18n.__('صفحه قبل', 'exin-black-friday'), 'previous', '', page <= 1 ? 'disabled' : '')}<span>${esc(page)}</span>${button(wp.i18n.__('صفحه بعد', 'exin-black-friday'), 'next', '', !state.has_more ? 'disabled' : '')}</div>`;
    }
    function productRow(item) {
        const p = item.product || {};
        const current = Number(p.price);
        const base = Number(item.base_price);
        const discount = base > 0 && current >= 0 ? Math.max(0, Math.round((1 - current / base) * 100)) : 0;
        const commands = item.status === 'conflict' ? `${button(wp.i18n.__('بررسی مجدد ایمن', 'exin-black-friday'), 'item-retry', '', `data-id="${esc(item.product_id)}"`)} ${button(wp.i18n.__('حفظ قیمت بیرونی', 'exin-black-friday'), 'item-release', '', `data-id="${esc(item.product_id)}"`)}` : button(wp.i18n.__('ویرایش', 'exin-black-friday'), 'item-edit', '', `data-id="${esc(item.product_id)}"`);
        return `<tr data-product-row="${esc(item.product_id)}" data-name="${esc((p.name || '') + ' ' + (p.sku || '') + ' ' + item.product_id)}" data-status="${esc(item.status)}"><td><input type="checkbox" class="exin-bf-row-select" value="${esc(item.product_id)}" aria-label="${esc(wp.i18n.__('انتخاب محصول', 'exin-black-friday'))}"></td><td><div class="exin-bf-product">${p.image ? `<img src="${esc(p.image)}" alt="" loading="lazy">` : ''}<div>${esc(p.name || wp.i18n.__('محصول حذف‌شده', 'exin-black-friday'))}<small>#${esc(item.product_id)} · ${esc(p.sku || '—')}</small></div></div></td><td>${price(item.base_price)}</td><td>${price(p.price)}</td><td>${price(item.floor_price)}</td><td>${esc(discount)}٪</td><td>${esc(p.stock ?? (p.stock_status === 'instock' ? wp.i18n.__('موجود', 'exin-black-friday') : p.stock_status === 'onbackorder' ? wp.i18n.__('پیش‌سفارش مجاز', 'exin-black-friday') : wp.i18n.__('ناموجود', 'exin-black-friday')))}</td><td>${status(item.status)}</td><td><input class="exin-bf-sort" type="number" min="0" max="999999" value="${esc(item.priority || 0)}" data-priority="${esc(item.product_id)}" aria-label="${esc(wp.i18n.__('اولویت نمایش', 'exin-black-friday'))}"><label><input type="checkbox" data-featured="${esc(item.product_id)}" ${item.featured ? 'checked' : ''} aria-label="${esc(wp.i18n.__('محصول ویژه', 'exin-black-friday'))}">★</label></td><td><div class="exin-bf-actions">${commands}${item.status === 'paused' ? button(wp.i18n.__('ادامه', 'exin-black-friday'), 'item-resume', '', `data-id="${esc(item.product_id)}"`) : ['active', 'included', 'sold_out'].includes(item.status) ? button(wp.i18n.__('توقف', 'exin-black-friday'), 'item-pause', '', `data-id="${esc(item.product_id)}"`) : ''}${button(wp.i18n.__('حذف', 'exin-black-friday'), 'item-remove', 'exin-bf-danger', `data-id="${esc(item.product_id)}"`)}</div></td></tr>`;
    }
    function formField(label, name, value, type = 'text', help = '', extra = '') {
        return `<div class="exin-bf-field"><label for="bf-${esc(name)}">${esc(label)}</label><input id="bf-${esc(name)}" name="${esc(name)}" type="${esc(type)}" value="${esc(value)}" ${extra}>${help ? `<small>${esc(help)}</small>` : ''}</div>`;
    }
    function renderSettings() {
        const c = state.campaign;
        const editing = c && c.status === 'draft';
        const completed = c && c.status === 'completed';
        const s = editing ? c.settings || {} : {};
        const start = editing ? utcToLocalInput(c.start_utc, c.timezone) : '2026-11-27T00:00';
        const methods = state.health.shipping_methods || [];
        const locked = c && !editing && !completed;
        content.innerHTML = `<div class="exin-bf-toolbar"><div><h2>${esc(wp.i18n.__('راه‌اندازی کمپین', 'exin-black-friday'))}</h2><span class="exin-bf-note">${esc(wp.i18n.__('۱. زمان · ۲. الگو · ۳. ارسال · ۴. محصولات · ۵. بررسی و فعال‌سازی', 'exin-black-friday'))}</span></div>${c ? status(c.status) : ''}</div>
            ${locked ? `<p class="exin-bf-warning">${esc(wp.i18n.__('کمپین شروع‌شده قفل است. پس از پایان و بررسی بازیابی، کمپین بعدی را ایجاد کنید.', 'exin-black-friday'))}</p>` : ''}
            <form id="exin-bf-settings"><fieldset ${locked ? 'disabled' : ''} style="border:0;padding:0;margin:0"><section class="exin-bf-card"><h2>${esc(wp.i18n.__('۱ و ۲ · زمان و کاهش قیمت', 'exin-black-friday'))}</h2><div class="exin-bf-form">
            ${formField(wp.i18n.__('عنوان کمپین', 'exin-black-friday'), 'title', editing ? c.title : wp.i18n.__('بلک فرایدی ۱۴۰۵ اکسین', 'exin-black-friday'), 'text', '', 'required maxlength="100"')}
            ${formField(wp.i18n.__('زمان شروع', 'exin-black-friday'), 'start_local', start, 'datetime-local', wp.i18n.__('زمان شروع، ساعت تغییر قیمت هر روز نیز هست.', 'exin-black-friday'), 'required')}
            ${formField(wp.i18n.__('منطقه زمانی', 'exin-black-friday'), 'timezone', editing ? c.timezone : 'Asia/Tehran', 'text', wp.i18n.__('مانند Asia/Tehran یا UTC', 'exin-black-friday'), 'required')}
            ${formField(wp.i18n.__('تعداد روزها', 'exin-black-friday'), 'days', editing ? c.days : 7, 'number', wp.i18n.__('روز اول قیمت مبنا؛ ابتدای روز آخر کف قیمت. کمپین یک‌روزه از کف شروع می‌شود.', 'exin-black-friday'), 'required min="1" max="366"')}
            <div class="exin-bf-field"><label for="bf-strategy">${esc(wp.i18n.__('الگوی قیمت', 'exin-black-friday'))}</label><select id="bf-strategy" name="strategy"><option value="linear" ${!editing || c.strategy === 'linear' ? 'selected' : ''}>${esc(wp.i18n.__('خطی · کاهش تقریباً برابر', 'exin-black-friday'))}</option><option value="progressive" ${editing && c.strategy === 'progressive' ? 'selected' : ''}>${esc(wp.i18n.__('تدریجی · کاهش بیشتر در روزهای آخر', 'exin-black-friday'))}</option></select></div>
            ${formField(wp.i18n.__('هدف سفارش', 'exin-black-friday'), 'target_orders', s.target_orders || 500, 'number', '', 'required min="1" max="10000000"')}
            </div></section><section class="exin-bf-card"><h2>${esc(wp.i18n.__('۳ · ارسال رایگان اختیاری', 'exin-black-friday'))}</h2><label class="exin-bf-check"><input type="checkbox" name="free_shipping" ${s.free_shipping ? 'checked' : ''}>${esc(wp.i18n.__('فعال‌کردن ارسال رایگان مشروط', 'exin-black-friday'))}</label><p class="exin-bf-note">${esc(wp.i18n.__('مبلغ اقلام کمپین پس از کوپن و بدون مالیات، در هر بسته ارسال محاسبه می‌شود. فقط روش‌های نرخ ثابت انتخابی رایگان می‌شوند.', 'exin-black-friday'))}</p><div class="exin-bf-form">
            ${formField(wp.i18n.__('حداقل مبلغ واجد شرایط', 'exin-black-friday'), 'shipping_threshold', s.shipping_threshold || '0', 'text', cfg.currency, 'inputmode="decimal" required')}
            <div class="exin-bf-field"><label>${esc(wp.i18n.__('روش‌های نرخ ثابت مجاز', 'exin-black-friday'))}</label>${methods.length ? methods.map((m) => `<label class="exin-bf-check"><input type="checkbox" name="shipping_instance" value="${esc(m.id)}" ${(s.shipping_instances || []).map(Number).includes(Number(m.id)) ? 'checked' : ''}>${esc(m.label)}</label>`).join('') : `<small>${esc(wp.i18n.__('روش نرخ ثابت فعالی وجود ندارد؛ ابتدا آن را در ووکامرس بسازید.', 'exin-black-friday'))}</small>`}</div></div></section>
            <section class="exin-bf-card"><details><summary>${esc(wp.i18n.__('تنظیمات پیشرفته', 'exin-black-friday'))}</summary><div class="exin-bf-form">
            ${formField(wp.i18n.__('توان الگوی تدریجی', 'exin-black-friday'), 'power', editing ? c.power : 2, 'number', wp.i18n.__('توان صحیح ۱ تا ۸؛ پیش‌فرض ۲.', 'exin-black-friday'), 'min="1" max="8" required')}
            <div class="exin-bf-field"><label for="bf-rounding">${esc(wp.i18n.__('گردکردن در کوچک‌ترین واحد پول', 'exin-black-friday'))}</label><select id="bf-rounding" name="rounding">${[['half_up', wp.i18n.__('نزدیک‌ترین؛ نیم رو به بالا', 'exin-black-friday')], ['floor', wp.i18n.__('رو به پایین', 'exin-black-friday')], ['ceil', wp.i18n.__('رو به بالا', 'exin-black-friday')]].map(([v, l]) => `<option value="${v}" ${editing && c.rounding === v ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></div>
            ${formField(wp.i18n.__('نگهداری گزارش رخدادها (روز)', 'exin-black-friday'), 'retention_days', s.retention_days || 90, 'number', '', 'min="7" max="365" required')}
            <div class="exin-bf-field"><label for="bf-inactive">${esc(wp.i18n.__('خروجی کمپین غیرفعال', 'exin-black-friday'))}</label><select id="bf-inactive" name="inactive_output"><option value="message">${esc(wp.i18n.__('پیام وضعیت', 'exin-black-friday'))}</option><option value="empty" ${s.inactive_output === 'empty' ? 'selected' : ''}>${esc(wp.i18n.__('خالی', 'exin-black-friday'))}</option></select></div>
            <label class="exin-bf-check"><input type="checkbox" name="show_out_of_stock" ${s.show_out_of_stock !== false ? 'checked' : ''}>${esc(wp.i18n.__('نمایش محصولات ناموجود با وضعیت واقعی', 'exin-black-friday'))}</label>
            <div class="exin-bf-field"><label>${esc(wp.i18n.__('وضعیت‌های سفارش در گزارش', 'exin-black-friday'))}</label>${Object.entries(state.health.order_statuses || {}).filter(([key]) => !['wc-pending', 'wc-failed', 'wc-cancelled', 'wc-checkout-draft'].includes(key)).map(([key, label]) => `<label class="exin-bf-check"><input type="checkbox" name="order_status" value="${esc(key.replace('wc-', ''))}" ${(s.order_statuses || ['processing', 'completed']).includes(key.replace('wc-', '')) ? 'checked' : ''}>${esc(label)}</label>`).join('')}</div>
            </div></details></section><div class="exin-bf-actions"><button type="submit" class="exin-bf-button exin-bf-primary">${esc(editing ? wp.i18n.__('ذخیره پیش‌نویس', 'exin-black-friday') : wp.i18n.__('ساخت پیش‌نویس و افزودن محصول', 'exin-black-friday'))}</button></div></fieldset></form>`;
    }
    function utcToLocalInput(value, timezone) {
        try {
            const date = new Date(value.replace(' ', 'T') + 'Z');
            const parts = new Intl.DateTimeFormat('en-CA', {timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'}).formatToParts(date);
            const m = Object.fromEntries(parts.map((p) => [p.type, p.value]));
            return `${m.year}-${m.month}-${m.day}T${m.hour}:${m.minute}`;
        } catch (_) { return '2026-11-27T00:00'; }
    }
    function renderDiagnostics() {
        const h = state.health;
        const c = state.campaign;
        const checks = [[wp.i18n.__('نسخه PHP', 'exin-black-friday'), h.php], [wp.i18n.__('نسخه وردپرس', 'exin-black-friday'), h.wordpress], [wp.i18n.__('نسخه ووکامرس', 'exin-black-friday'), h.woocommerce],
            [wp.i18n.__('Action Scheduler', 'exin-black-friday'), h.action_scheduler ? wp.i18n.__('در دسترس', 'exin-black-friday') : wp.i18n.__('نیازمند بررسی', 'exin-black-friday')], [wp.i18n.__('منطقه زمانی وردپرس', 'exin-black-friday'), h.timezone || 'UTC'],
            [wp.i18n.__('کش شیء خارجی', 'exin-black-friday'), h.object_cache ? wp.i18n.__('فعال', 'exin-black-friday') : wp.i18n.__('غیرفعال', 'exin-black-friday')], [wp.i18n.__('Elementor', 'exin-black-friday'), h.elementor || wp.i18n.__('اختیاری؛ نصب نیست', 'exin-black-friday')],
            [wp.i18n.__('WP Cron مبتنی بر بازدید', 'exin-black-friday'), h.cron_disabled ? wp.i18n.__('غیرفعال؛ Cron سرور را بررسی کنید', 'exin-black-friday') : wp.i18n.__('فعال؛ Cron سرور توصیه می‌شود', 'exin-black-friday')],
            [wp.i18n.__('آخرین اجرای موفق', 'exin-black-friday'), c ? localDate(c.last_success, c.timezone) : '—'], [wp.i18n.__('نسخه تنظیمات', 'exin-black-friday'), c?.version || '—']];
        content.innerHTML = `<div class="exin-bf-toolbar"><div><h2>${esc(wp.i18n.__('سلامت اجرا و بازیابی', 'exin-black-friday'))}</h2><span class="exin-bf-note">${esc(wp.i18n.__('آماده‌بودن Scheduler به‌تنهایی اجرای Cron سرور را تضمین نمی‌کند.', 'exin-black-friday'))}</span></div><div class="exin-bf-actions">${button(wp.i18n.__('تازه‌سازی سلامت', 'exin-black-friday'), 'refresh')}${c ? button(wp.i18n.__('بازیابی صف و تلاش مجدد ایمن', 'exin-black-friday'), 'recover', 'exin-bf-primary') : ''}</div></div>
            <section class="exin-bf-card"><div class="exin-bf-health">${checks.map(([label, value]) => `<div>${esc(label)}<strong>${esc(value)}</strong></div>`).join('')}</div></section>
            ${c?.last_error ? `<p class="exin-bf-warning">${esc(wp.i18n.__('آخرین خطا', 'exin-black-friday'))}: ${esc(c.last_error)}</p>` : ''}
            <section class="exin-bf-card"><h2>${esc(wp.i18n.__('تعارض قیمت', 'exin-black-friday'))}</h2><p class="exin-bf-note">${esc(wp.i18n.__('اگر قیمت بیرون از افزونه تغییر کند، بازنویسی متوقف می‌شود. «بررسی مجدد ایمن» همچنان مالکیت را بررسی می‌کند؛ «حفظ قیمت بیرونی» کنترل افزونه را واگذار می‌کند و قیمت را تغییر نمی‌دهد. این ابزار قیمت را اجباری بازنویسی نمی‌کند.', 'exin-black-friday'))}</p>${button(wp.i18n.__('بررسی محصولات و تعارض‌ها', 'exin-black-friday'), 'products')}</section>
            <section class="exin-bf-card"><h2>${esc(wp.i18n.__('آخرین رخدادها', 'exin-black-friday'))}</h2><div class="exin-bf-table-scroll"><table class="exin-bf-table"><thead><tr><th>${esc(wp.i18n.__('زمان', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('سطح', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('عملیات', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('شناسه‌ها / نتیجه', 'exin-black-friday'))}</th></tr></thead><tbody>${(state.logs || []).map((log) => `<tr><td>${esc(localDate(log.created_at || log.timestamp, c?.timezone))}</td><td>${esc(log.level)}</td><td><code>${esc(log.action)}</code></td><td><code>${esc(typeof log.context === 'string' ? log.context : JSON.stringify(log.context || {campaign_id: log.campaign_id, product_id: log.product_id, error_code: log.error_code, result: log.result}))}</code></td></tr>`).join('') || `<tr><td colspan="4">${esc(wp.i18n.__('رخدادی ثبت نشده است.', 'exin-black-friday'))}</td></tr>`}</tbody></table></div></section>`;
    }
    function openModal(label, html) {
        previousFocus = document.activeElement;
        title.textContent = label;
        modal.innerHTML = html;
        if (!dialog.open) dialog.showModal();
        const first = modal.querySelector('input,button,textarea');
        if (first) first.focus();
    }
    function closeModal() { searchVersion++; dialog.close(); if (previousFocus) previousFocus.focus(); }
    function requireDraft() {
        if (!state.campaign) { view = 'settings'; render(); notice(wp.i18n.__('ابتدا پیش‌نویس کمپین را بسازید.', 'exin-black-friday')); return false; }
        if (state.campaign.status !== 'draft') { notice(wp.i18n.__('افزودن محصول و تغییر کف قیمت فقط در پیش‌نویس مجاز است.', 'exin-black-friday'), true); return false; }
        return true;
    }
    function openAdd() {
        if (!requireDraft()) return;
        selectedProduct = null;
        openModal(wp.i18n.__('افزودن محصول · انتخاب و کف قیمت', 'exin-black-friday'), `<div class="exin-bf-field"><label for="exin-bf-product-search">${esc(wp.i18n.__('جست‌وجوی نام یا SKU', 'exin-black-friday'))}</label><input type="search" id="exin-bf-product-search" placeholder="${esc(wp.i18n.__('حداقل ۲ نویسه…', 'exin-black-friday'))}" autocomplete="off"></div><div id="exin-bf-search-results" class="exin-bf-results" aria-live="polite"></div><div id="exin-bf-add-fields"></div>`);
    }
    let searchTimer;
    async function searchProducts(term, searchPage = 1) {
        const version = ++searchVersion;
        const results = document.getElementById('exin-bf-search-results');
        if (!results) return;
        if (term.trim().length < 2) { results.textContent = ''; return; }
        results.textContent = wp.i18n.__('در حال جست‌وجو…', 'exin-black-friday');
        try {
            const data = await api(`products?search=${encodeURIComponent(term.trim())}&page=${searchPage}`);
            if (version !== searchVersion || !dialog.open) return;
            const products = data.products || data.items || [];
            results.innerHTML = products.map((p) => `<button class="exin-bf-result" type="button" data-choose-product="${esc(p.id || p.product_id)}">${p.image ? `<img src="${esc(p.image)}" alt="" loading="lazy">` : ''}<span>${esc(p.name)}<small>#${esc(p.id || p.product_id)} · ${esc(p.sku || '—')} · ${esc(p.type)} · ${esc(p.price)} ${esc(cfg.currency)}</small></span></button>`).join('') || `<p>${esc(wp.i18n.__('محصول پشتیبانی‌شده‌ای پیدا نشد. تنوع محصول را با SKU دقیق جست‌وجو کنید.', 'exin-black-friday'))}</p>`;
            results._products = products;
            if (searchPage > 1 || data.has_more) {
                results.insertAdjacentHTML('beforeend', `<div class="exin-bf-actions">${searchPage > 1 ? button(wp.i18n.__('نتایج قبل', 'exin-black-friday'), 'search-previous', '', `data-page="${searchPage - 1}"`) : ''}${data.has_more ? button(wp.i18n.__('نتایج بعد', 'exin-black-friday'), 'search-next', '', `data-page="${searchPage + 1}"`) : ''}</div>`);
            }
        } catch (error) { if (version === searchVersion) results.textContent = error.message; }
    }
    function chooseProduct(id) {
        const results = document.getElementById('exin-bf-search-results');
        selectedProduct = results._products.find((p) => Number(p.id || p.product_id) === Number(id));
        if (!selectedProduct) return;
        results.innerHTML = `<p>${esc(selectedProduct.name)} · #${esc(id)} · ${price(selectedProduct.price)}</p>`;
        document.getElementById('exin-bf-add-fields').innerHTML = `<form id="exin-bf-add-form" data-id="${esc(id)}"><div class="exin-bf-form">${formField(wp.i18n.__('کف قیمت فروش', 'exin-black-friday'), 'floor_price', '', 'text', wp.i18n.__('عدد مثبت، حداکثر برابر قیمت معتبر محصول.', 'exin-black-friday'), 'inputmode="decimal" required autofocus')}</div><div id="exin-bf-preview" class="exin-bf-preview" aria-live="polite"></div><div class="exin-bf-actions">${button(wp.i18n.__('پیش‌نمایش بدون ذخیره', 'exin-black-friday'), 'preview')}<button type="submit" class="exin-bf-button exin-bf-primary">${esc(wp.i18n.__('ذخیره محصول', 'exin-black-friday'))}</button></div></form>`;
        document.getElementById('bf-floor_price').focus();
    }
    async function preview() {
        const form = document.getElementById('exin-bf-add-form');
        if (!form || !form.reportValidity()) return;
        const data = await api(campaignPath('preview'), {product_id: Number(form.dataset.id), floor_price: form.elements.floor_price.value.trim()});
        const schedule = Array.isArray(data) ? data : data.schedule || data.prices || [];
        document.getElementById('exin-bf-preview').innerHTML = schedule.map((value, i) => `<span><small>${esc(wp.i18n.__('روز', 'exin-black-friday'))} ${i + 1}</small>${price(typeof value === 'object' ? value.price : value)}</span>`).join('');
    }
    function openEdit(id) {
        const item = state.items.find((row) => Number(row.product_id) === Number(id));
        if (!item) return;
        const financial = state.campaign.status === 'draft';
        openModal(wp.i18n.__('ویرایش محصول', 'exin-black-friday'), `<form id="exin-bf-edit-form" data-id="${esc(id)}"><p>${esc(item.product?.name || id)}</p><div class="exin-bf-form">${formField(wp.i18n.__('کف قیمت', 'exin-black-friday'), 'floor_price', item.floor_price, 'text', financial ? '' : wp.i18n.__('پس از شروع، کف قیمت قفل است.', 'exin-black-friday'), `inputmode="decimal" required ${financial ? '' : 'disabled'}`)}${formField(wp.i18n.__('اولویت نمایش', 'exin-black-friday'), 'priority', item.priority || 0, 'number', '', 'min="0" max="999999" required')}<label class="exin-bf-check"><input type="checkbox" name="featured" ${item.featured ? 'checked' : ''}>${esc(wp.i18n.__('محصول ویژه', 'exin-black-friday'))}</label></div><button type="submit" class="exin-bf-button exin-bf-primary">${esc(wp.i18n.__('ذخیره تغییرات', 'exin-black-friday'))}</button></form>`);
    }
    function openBulk() {
        if (!requireDraft()) return;
        openModal(wp.i18n.__('افزودن گروهی · حداکثر ۲۵ محصول', 'exin-black-friday'), `<form id="exin-bf-bulk-form"><div class="exin-bf-field"><label for="exin-bf-bulk-input">${esc(wp.i18n.__('هر خط: شناسه محصول یا تنوع، کف قیمت', 'exin-black-friday'))}</label><textarea id="exin-bf-bulk-input" name="rows" rows="9" dir="ltr" placeholder="123,150000\n124,230000" required></textarea><small>${esc(wp.i18n.__('قیمت و مالکیت هر محصول جداگانه اعتبارسنجی می‌شود. نتیجه ناموفق هر ردیف اعلام خواهد شد.', 'exin-black-friday'))}</small></div><button type="submit" class="exin-bf-button exin-bf-primary">${esc(wp.i18n.__('ثبت محصولات', 'exin-black-friday'))}</button></form>`);
    }
    async function command(name, node) {
        if (['dashboard', 'products', 'settings', 'diagnostics'].includes(name)) { view = name; page = 1; await load(); return; }
        if (name === 'add') { openAdd(); return; }
        if (name === 'close-dialog') { closeModal(); return; }
        if (name === 'refresh') { await load(); return; }
        if (name === 'bulk-add') { openBulk(); return; }
        if (name === 'preview') { await preview(); return; }
        if (name === 'item-edit') { openEdit(node.dataset.id); return; }
        if (name === 'next' || name === 'previous') { page += name === 'next' ? 1 : -1; await load(); return; }
        if (name.startsWith('search-')) { await searchProducts(document.getElementById('exin-bf-product-search').value, Number(node.dataset.page)); return; }
        if (name === 'bulk-remove') {
            const items = [...root.querySelectorAll('.exin-bf-row-select:checked')].map((el) => ({product_id: Number(el.value)}));
            if (!items.length) throw new Error(wp.i18n.__('ابتدا محصولی انتخاب کنید.', 'exin-black-friday'));
            if (!window.confirm(wp.i18n.__('محصولات انتخاب‌شده حذف شوند؟ قیمت‌های تحت کنترل پیش از حذف، با بررسی مالکیت بازیابی می‌شوند.', 'exin-black-friday'))) return;
            const result = await api(campaignPath('bulk'), {action: 'remove', items, confirm_remove: true});
            await load();
            noticeBulk(result); return;
        }
        if (['item-remove', 'item-retry', 'item-release', 'item-pause', 'item-resume'].includes(name)) {
            const data = {action: name === 'item-remove' ? 'remove' : name === 'item-retry' ? 'retry' : name === 'item-pause' ? 'pause' : name === 'item-resume' ? 'resume' : 'keep_external'};
            if (name === 'item-remove') {
                if (!window.confirm(wp.i18n.__('محصول حذف و قیمت تحت کنترل با بررسی مالکیت بازیابی شود؟', 'exin-black-friday'))) return;
                data.confirm_remove = true;
            }
            if (name === 'item-release') {
                if (!window.confirm(wp.i18n.__('قیمت بیرونی حفظ و کنترل این محصول واگذار شود؟ پس از این تصمیم افزونه قیمت این محصول را بازیابی نمی‌کند.', 'exin-black-friday'))) return;
                data.confirm_release = true;
            }
            await api(campaignPath(`item/${node.dataset.id}`), data);
            await load(); notice(wp.i18n.__('عملیات محصول ثبت شد.', 'exin-black-friday')); return;
        }
        if (['start', 'schedule', 'pause', 'resume', 'restore', 'recover'].includes(name)) {
            const data = {action: name};
            if (name === 'restore') {
                if (!window.confirm(wp.i18n.__('تغییرات آینده متوقف و بازیابی قیمت همه محصولات آغاز شود؟ تغییرات بیرونی بازنویسی نمی‌شوند و به‌صورت تعارض گزارش می‌شوند.', 'exin-black-friday'))) return;
                data.confirm_restore = true;
            }
            if (name === 'pause' && !window.confirm(wp.i18n.__('فقط تغییرات آینده متوقف شوند؟ قیمت‌های فعلی بدون بازیابی باقی می‌مانند.', 'exin-black-friday'))) return;
            await api(campaignPath('action'), data);
            await load(); notice(wp.i18n.__('درخواست ثبت شد. نتیجه اجرا را در وضعیت کمپین و عیب‌یابی بررسی کنید.', 'exin-black-friday'));
        }
    }
    function noticeBulk(result) {
        const failed = (result.results || []).filter((row) => !row.ok);
        if (!failed.length) notice(wp.i18n.__('همه محصولات با موفقیت پردازش شدند.', 'exin-black-friday'));
        else notice(`${wp.i18n.__('این محصولات پردازش نشدند', 'exin-black-friday')}: ${failed.map((row) => `#${row.product_id}`).join('، ')}. ${wp.i18n.__('قیمت و تعارض را در فهرست بررسی کنید.', 'exin-black-friday')}`, true);
    }
    root.addEventListener('click', async (event) => {
        const nav = event.target.closest('[data-view]');
        if (nav) { view = nav.dataset.view; page = 1; await load(); return; }
        const choose = event.target.closest('[data-choose-product]');
        if (choose) { chooseProduct(choose.dataset.chooseProduct); return; }
        const node = event.target.closest('[data-command]');
        if (!node || node.disabled) return;
        node.disabled = true;
        try { await command(node.dataset.command, node); }
        catch (error) { notice(error.message, true); }
        finally { node.disabled = false; }
    });
    root.addEventListener('input', (event) => {
        if (event.target.id === 'exin-bf-product-search') {
            clearTimeout(searchTimer);
            searchVersion++;
            searchTimer = setTimeout(() => searchProducts(event.target.value), 300);
        }
        if (event.target.id === 'exin-bf-filter-text') filterRows();
    });
    function filterRows() {
        const term = document.getElementById('exin-bf-filter-text').value.toLowerCase();
        const filter = document.getElementById('exin-bf-filter-status').value;
        root.querySelectorAll('[data-product-row]').forEach((row) => {
            row.hidden = !row.dataset.name.toLowerCase().includes(term) || (filter && row.dataset.status !== filter);
        });
    }
    root.addEventListener('change', async (event) => {
        const node = event.target;
        if (node.id === 'exin-bf-filter-status') { filterRows(); return; }
        if (node.id === 'exin-bf-select-all') { root.querySelectorAll('.exin-bf-row-select').forEach((el) => { if (!el.closest('tr').hidden) el.checked = node.checked; }); return; }
        if (!node.dataset.featured && !node.dataset.priority) return;
        const data = {action: 'update'};
        const id = node.dataset.featured || node.dataset.priority;
        if (node.dataset.featured) data.featured = node.checked;
        else data.priority = Number(node.value);
        node.disabled = true;
        try { await api(campaignPath(`item/${id}`), data); await load(); }
        catch (error) { notice(error.message, true); await load(); }
        finally { node.disabled = false; }
    });
    root.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!form.id.startsWith('exin-bf-')) return;
        event.preventDefault();
        const submit = form.querySelector('[type=submit]');
        submit.disabled = true;
        try {
            if (form.id === 'exin-bf-settings') {
                const fields = new FormData(form);
                const data = {
                    title: fields.get('title'), start_local: fields.get('start_local'), timezone: fields.get('timezone'),
                    days: Number(fields.get('days')), strategy: fields.get('strategy'), power: Number(fields.get('power')), rounding: fields.get('rounding'),
                    settings: {free_shipping: fields.has('free_shipping'), shipping_threshold: fields.get('shipping_threshold'),
                        shipping_instances: fields.getAll('shipping_instance').map(Number), show_out_of_stock: fields.has('show_out_of_stock'),
                        inactive_output: fields.get('inactive_output'), order_statuses: fields.getAll('order_status'),
                        target_orders: Number(fields.get('target_orders')), retention_days: Number(fields.get('retention_days'))}
                };
                if (state.campaign?.status === 'draft') data.id = Number(state.campaign.id);
                await api('campaign', data);
                view = 'products'; page = 1; await load(); notice(wp.i18n.__('پیش‌نویس ذخیره شد. محصول را اضافه و سپس زمان‌بندی را فعال کنید.', 'exin-black-friday'));
            } else if (form.id === 'exin-bf-add-form') {
                await api(campaignPath('items'), {product_id: Number(form.dataset.id), floor_price: form.elements.floor_price.value.trim()});
                closeModal(); view = 'products'; await load(); notice(wp.i18n.__('محصول اضافه شد.', 'exin-black-friday'));
            } else if (form.id === 'exin-bf-edit-form') {
                const data = {action: 'update', priority: Number(form.elements.priority.value), featured: form.elements.featured.checked};
                if (!form.elements.floor_price.disabled) data.floor_price = form.elements.floor_price.value.trim();
                await api(campaignPath(`item/${form.dataset.id}`), data);
                closeModal(); await load(); notice(wp.i18n.__('تغییرات ذخیره شد.', 'exin-black-friday'));
            } else if (form.id === 'exin-bf-bulk-form') {
                const lines = form.elements.rows.value.trim().split(/\r?\n/).filter((line) => line.trim());
                if (!lines.length || lines.length > 25) throw new Error(wp.i18n.__('هر نوبت ۱ تا ۲۵ ردیف وارد کنید.', 'exin-black-friday'));
                const items = lines.map((line) => {
                    const values = line.split(',');
                    if (values.length !== 2 || !/^\d+$/.test(values[0].trim())) throw new Error(wp.i18n.__('قالب هر خط باید شناسه،کف‌قیمت باشد.', 'exin-black-friday'));
                    return {product_id: Number(values[0].trim()), floor_price: values[1].trim()};
                });
                const result = await api(campaignPath('bulk'), {action: 'add', items});
                closeModal(); view = 'products'; await load(); noticeBulk(result);
            }
        } catch (error) { notice(error.message, true); }
        finally { submit.disabled = false; }
    });
    dialog.addEventListener('cancel', () => { searchVersion++; });
    load();
})();
