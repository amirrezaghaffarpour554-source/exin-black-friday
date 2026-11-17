/* global exinBFAdmin, ExinBFHelpers, wp */
(() => {
    'use strict';
    const root = document.getElementById('exin-bf-admin');
    if (!root) return;
    const cfg = exinBFAdmin;
    const helpers = ExinBFHelpers;
    const content = document.getElementById('exin-bf-content');
    const message = document.getElementById('exin-bf-message');
    const draftStatus = document.getElementById('exin-bf-draft-status');
    const dialog = document.getElementById('exin-bf-dialog');
    const modal = document.getElementById('exin-bf-dialog-content');
    const title = document.getElementById('exin-bf-dialog-title');
    const labels = {
        save: wp.i18n.__('ذخیره پیش‌نویس', 'exin-black-friday'),
        saved: wp.i18n.__('پیش‌نویس ذخیره شد؛ قیمت فروشگاه تغییر نکرد.', 'exin-black-friday'),
        saving: wp.i18n.__('در حال ذخیره پیش‌نویس…', 'exin-black-friday'),
        dirty: wp.i18n.__('تغییرات در انتظار ذخیره است.', 'exin-black-friday'),
        incomplete: wp.i18n.__('اطلاعات ناتمام در این صفحه حفظ شده است؛ برای ذخیره، فیلدهای نامعتبر را اصلاح کنید.', 'exin-black-friday'),
        connection: wp.i18n.__('پاسخ سرور معتبر نیست؛ اتصال را بررسی کنید.', 'exin-black-friday'),
        failed: wp.i18n.__('عملیات انجام نشد.', 'exin-black-friday'),
        products: wp.i18n.__('محصولات', 'exin-black-friday'),
        settings: wp.i18n.__('تنظیمات جشنواره', 'exin-black-friday'),
        review: wp.i18n.__('بررسی و فعال‌سازی', 'exin-black-friday'),
        add: wp.i18n.__('انتخاب محصول از ووکامرس', 'exin-black-friday'),
        previous: wp.i18n.__('قبلی', 'exin-black-friday'),
        next: wp.i18n.__('بعدی', 'exin-black-friday'),
        close: wp.i18n.__('بستن', 'exin-black-friday'),
        all: wp.i18n.__('همه', 'exin-black-friday'),
        featured: wp.i18n.__('ویژه', 'exin-black-friday'),
        stock: wp.i18n.__('موجودی', 'exin-black-friday'),
        floor: wp.i18n.__('کف قیمت', 'exin-black-friday'),
        preview: wp.i18n.__('پیش‌نمایش روزانه', 'exin-black-friday'),
        invalidMoney: wp.i18n.__('مبلغ معتبر وارد کنید؛ کف باید مثبت و حداکثر برابر قیمت معتبر محصول باشد.', 'exin-black-friday'),
        draftOnly: wp.i18n.__('انتخاب محصول و تغییر کف فقط در پیش‌نویس مجاز است.', 'exin-black-friday'),
        selectFirst: wp.i18n.__('ابتدا محصولی انتخاب کنید.', 'exin-black-friday'),
        noResults: wp.i18n.__('محصولی با این فیلترها پیدا نشد.', 'exin-black-friday'),
        search: wp.i18n.__('نام یا SKU محصول', 'exin-black-friday'),
        searching: wp.i18n.__('در حال دریافت محصولات…', 'exin-black-friday'),
        selectionLimit: wp.i18n.__('در هر نوبت حداکثر ۲۰۰ محصول انتخاب کنید؛ پس از ذخیره می‌توانید ادامه دهید.', 'exin-black-friday'),
        scanLimit: wp.i18n.__('در این نوبت حداکثر ۵۰۰ نتیجه بررسی شد؛ برای بررسی محصولات بعدی، دوباره «انتخاب نتایج فیلترشده» را بزنید.', 'exin-black-friday'),
        activation: wp.i18n.__('فعال‌سازی فقط با تأیید صریح شما انجام می‌شود.', 'exin-black-friday'),
        requestSaved: wp.i18n.__('درخواست ثبت شد؛ نتیجه را در وضعیت کمپین و عیب‌یابی بررسی کنید.', 'exin-black-friday')
    };
    const statuses = {
        draft: wp.i18n.__('پیش‌نویس', 'exin-black-friday'), scheduled: wp.i18n.__('زمان‌بندی‌شده', 'exin-black-friday'),
        active: wp.i18n.__('فعال', 'exin-black-friday'), paused: wp.i18n.__('متوقف', 'exin-black-friday'),
        completed: wp.i18n.__('پایان‌یافته', 'exin-black-friday'), failed: wp.i18n.__('ناموفق', 'exin-black-friday'),
        included: wp.i18n.__('انتخاب‌شده', 'exin-black-friday'), pending: wp.i18n.__('در صف', 'exin-black-friday'),
        sold_out: wp.i18n.__('ناموجود', 'exin-black-friday'), conflict: wp.i18n.__('تعارض', 'exin-black-friday'), removed: wp.i18n.__('حذف‌شده', 'exin-black-friday')
    };
    let state = null, view = 'dashboard', page = 1, requestVersion = 0, serverOffset = 0, countdownTimer;
    let draft = null, editRevision = 0, savedRevision = 0, savePromise = null, autosaveTimer, previousFocus;
    let picker = null, pickerRequest = 0, pickerTimer, filterMetadata = null, draggedId = null;
    const filteredScanPages = new Map();
    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, char => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char]));
    const status = value => `<span class="exin-bf-status" data-status="${esc(value)}">${esc(statuses[value] || value)}</span>`;
    const button = (label, command, cls = '', extra = '') => `<button type="button" class="exin-bf-button ${cls}" data-command="${esc(command)}" ${extra}>${esc(label)}</button>`;
    const campaignPath = suffix => `campaign/${state.campaign.id}/${suffix}`;
    const displayAmount = value => value === null || value === undefined || value === '' ? '' : helpers.formatAmount(helpers.fromStore(String(value), cfg.currency_code, cfg.precision));
    const price = value => `<span class="exin-bf-price">${esc(value === null || value === undefined || value === '' ? '—' : displayAmount(value))} ${esc(cfg.currency)}</span>`;
    const unitPayload = () => ({amount_unit: cfg.display_unit || 'store'});
    const integer = value => Number(helpers.normalizeDigits(value));
    function notice(text, error = false) {
        message.textContent = text;
        message.hidden = false;
        message.dataset.error = String(error);
        if (dialog.open && error) {
            let box = modal.querySelector('.exin-bf-modal-error');
            if (!box) { box = document.createElement('div'); box.className = 'exin-bf-warning exin-bf-modal-error'; box.setAttribute('role', 'alert'); modal.prepend(box); }
            box.textContent = text;
        }
    }
    function draftNotice(text, kind = 'saved') {
        if (!draftStatus) return;
        draftStatus.textContent = text;
        draftStatus.dataset.state = kind;
    }
    async function api(path, data) {
        const url = new URL(cfg.root, window.location.href);
        const relative = new URL(path, 'https://exin.invalid/');
        if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route') + relative.pathname.slice(1));
        else url.pathname += relative.pathname.slice(1);
        relative.searchParams.forEach((value, key) => url.searchParams.set(key, value));
        const response = await fetch(url.toString(), {
            method: data === undefined ? 'GET' : 'POST', credentials: 'same-origin',
            headers: {'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json'},
            body: data === undefined ? undefined : JSON.stringify(data)
        });
        let value;
        try { value = await response.json(); } catch (_) { throw new Error(labels.connection); }
        if (!response.ok) throw new Error(value.message || labels.failed);
        return value;
    }
    function utcParts(value, timezone) {
        const date = new Date(String(value).replace(' ', 'T') + 'Z');
        const parts = new Intl.DateTimeFormat('en-CA', {timeZone: timezone || 'Asia/Tehran', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'}).formatToParts(date);
        const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
        return {date: helpers.toJalali(`${values.year}-${values.month}-${values.day}`), time: `${values.hour}:${values.minute}`};
    }
    function localDate(value, timezone) {
        if (!value) return '—';
        try { const parts = utcParts(value, timezone); return `${helpers.persianDigits(parts.date)} · ${parts.time}`; }
        catch (_) { return '—'; }
    }
    function hydrateDraft(campaign) {
        const editing = campaign && campaign.status !== 'completed';
        const settings = editing ? campaign.settings || {} : {};
        const starts = editing ? utcParts(campaign.start_utc, campaign.timezone) : {date: '1405/09/06', time: '00:00'};
        return {
            id: campaign?.status === 'draft' ? campaign.id : null,
            title: editing ? campaign.title : wp.i18n.__('بلک فرایدی اکسین', 'exin-black-friday'),
            start_jalali: helpers.persianDigits(starts.date), start_time: starts.time,
            timezone: editing ? campaign.timezone : 'Asia/Tehran', days: editing ? String(campaign.days) : '7',
            strategy: editing ? campaign.strategy : 'linear', power: editing ? String(campaign.power) : '2', rounding: editing ? campaign.rounding : 'half_up',
            free_shipping: !!settings.free_shipping, shipping_threshold: displayAmount(settings.shipping_threshold ?? '0'),
            shipping_subsidy_cap: displayAmount(settings.shipping_subsidy_cap ?? cfg.default_subsidy_cap),
            shipping_instances: (settings.shipping_instances || []).map(Number), inactive_output: settings.inactive_output || 'message',
            retention_days: String(settings.retention_days || 90)
        };
    }
    async function load() {
        const revision = ++requestVersion;
        content.setAttribute('aria-busy', 'true');
        try {
            const result = await api(`state?page=${page}&view=${encodeURIComponent(view)}`);
            if (revision !== requestVersion) return;
            state = result;
            serverOffset = result.server_time * 1000 - Date.now();
            if (!draft || (savedRevision === editRevision && !savePromise)) draft = hydrateDraft(state.campaign);
            render();
        } catch (error) { notice(error.message, true); }
        finally { if (revision === requestVersion) content.setAttribute('aria-busy', 'false'); }
    }
    function captureSettings() {
        const form = document.getElementById('exin-bf-settings');
        if (!form || form.querySelector('fieldset')?.disabled) return;
        const fields = new FormData(form);
        const next = {
            ...draft, title: fields.get('title'), start_jalali: fields.get('start_jalali'), start_time: fields.get('start_time'),
            timezone: fields.get('timezone'), days: fields.get('days'), strategy: fields.get('strategy'), power: fields.get('power'), rounding: fields.get('rounding'),
            free_shipping: fields.has('free_shipping'), shipping_threshold: fields.get('shipping_threshold'), shipping_subsidy_cap: fields.get('shipping_subsidy_cap'),
            shipping_instances: fields.getAll('shipping_instance').map(Number), inactive_output: fields.get('inactive_output'), retention_days: fields.get('retention_days')
        };
        if (JSON.stringify(next) !== JSON.stringify(draft)) { draft = next; editRevision++; draftNotice(labels.dirty, 'dirty'); }
    }
    function draftPayload() {
        if (!draft || !draft.title.trim()) throw new Error(labels.incomplete);
        try { helpers.parseJalali(draft.start_jalali); }
        catch (_) { throw new Error(wp.i18n.__('تاریخ شمسی معتبر بین سال ۱۲۰۰ تا ۱۶۰۰ وارد کنید.', 'exin-black-friday')); }
        const time = helpers.normalizeDigits(draft.start_time);
        if (!/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/.test(time)) throw new Error(labels.incomplete);
        const days = integer(draft.days), power = integer(draft.power), retention = integer(draft.retention_days);
        if (!Number.isInteger(days) || days < 1 || days > 366 || !Number.isInteger(power) || power < 1 || power > 8 || !Number.isInteger(retention) || retention < 7 || retention > 365) throw new Error(labels.incomplete);
        try {
            helpers.toStore(draft.shipping_threshold || '0', cfg.display_unit, cfg.currency_code, cfg.precision);
            if (draft.shipping_subsidy_cap !== '') helpers.toStore(draft.shipping_subsidy_cap, cfg.display_unit, cfg.currency_code, cfg.precision);
        } catch (_) { throw new Error(labels.invalidMoney); }
        if (draft.free_shipping && (!draft.shipping_instances.length || draft.shipping_subsidy_cap === '')) throw new Error(wp.i18n.__('برای ارسال جشنواره، روش نرخ ثابت و سقف یارانه را تعیین کنید.', 'exin-black-friday'));
        const payload = {
            ...unitPayload(), title: draft.title.trim(), start_jalali: draft.start_jalali, start_time: time, timezone: draft.timezone,
            days, strategy: draft.strategy, power, rounding: draft.rounding,
            settings: {free_shipping: draft.free_shipping, shipping_threshold: draft.shipping_threshold || '0', shipping_subsidy_cap: draft.shipping_subsidy_cap,
                shipping_instances: draft.shipping_instances, inactive_output: draft.inactive_output, retention_days: retention}
        };
        if (draft.id) payload.id = Number(draft.id);
        return payload;
    }
    async function saveDraft(force = false) {
        clearTimeout(autosaveTimer);
        captureSettings();
        if (state?.campaign && !['draft', 'completed'].includes(state.campaign.status)) return state.campaign;
        if (savePromise) await savePromise;
        if (!force && savedRevision === editRevision && draft?.id) return state.campaign;
        const payload = draftPayload();
        const revision = editRevision;
        draftNotice(labels.saving, 'saving');
        savePromise = api('campaign', payload);
        try {
            const result = await savePromise;
            state.campaign = result.campaign;
            draft.id = result.campaign.id;
            savedRevision = revision;
            draftNotice(savedRevision === editRevision ? labels.saved : labels.dirty, savedRevision === editRevision ? 'saved' : 'dirty');
            return result.campaign;
        } catch (error) { draftNotice(error.message, 'error'); throw error; }
        finally { savePromise = null; }
    }
    function scheduleDraftSave() {
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(async () => {
            try { await saveDraft(); }
            catch (error) { draftNotice(error.message, 'error'); }
        }, 750);
    }
    async function navigate(next, nextPage = 1) {
        captureSettings();
        if (savedRevision !== editRevision) {
            try {
                await saveDraft();
                if (savedRevision !== editRevision) await saveDraft();
            } catch (error) { draftNotice(error.message, 'error'); }
        }
        view = next; page = nextPage; await load();
    }
    function steps(active) {
        return `<nav class="exin-bf-steps" aria-label="${esc(wp.i18n.__('مراحل راه‌اندازی', 'exin-black-friday'))}">${[[1, labels.settings, 'settings'], [2, labels.products, 'products'], [3, labels.review, 'review']].map(([step, label, destination]) => `<button type="button" data-command="${destination}" data-step="${step}" ${step === active ? 'aria-current="step"' : ''}><span>${helpers.persianDigits(step)}</span>${esc(label)}</button>`).join('')}</nav>`;
    }
    function render() {
        clearInterval(countdownTimer);
        root.querySelectorAll('[data-view]').forEach(node => {
            if (node.dataset.view === view || (view === 'review' && node.dataset.view === 'settings')) node.setAttribute('aria-current', 'page');
            else node.removeAttribute('aria-current');
        });
        if (view === 'diagnostics') renderDiagnostics();
        else if (view === 'settings' || !state.campaign) renderSettings();
        else if (view === 'products') renderProducts();
        else if (view === 'review') renderReview();
        else renderDashboard();
        helpers.attach(content);
    }
    function actions() {
        const campaign = state.campaign;
        let html = campaign.status === 'draft' ? button(labels.review, 'review', 'exin-bf-primary') : '';
        if (campaign.status === 'scheduled') html += button(wp.i18n.__('شروع در زمان مقرر', 'exin-black-friday'), 'start', 'exin-bf-primary');
        if (['active', 'scheduled'].includes(campaign.status)) html += button(wp.i18n.__('توقف بدون بازیابی', 'exin-black-friday'), 'pause');
        if (campaign.status === 'paused') html += button(wp.i18n.__('ادامه کمپین', 'exin-black-friday'), 'resume', 'exin-bf-primary');
        if (['active', 'scheduled', 'paused', 'failed', 'completed'].includes(campaign.status)) html += button(wp.i18n.__('توقف و بازیابی قیمت‌ها', 'exin-black-friday'), 'restore', 'exin-bf-danger');
        return html;
    }
    function renderDashboard() {
        const campaign = state.campaign, analytics = state.analytics || {};
        content.innerHTML = `${campaign.status === 'draft' ? steps(3) : ''}<section class="exin-bf-card exin-bf-hero"><div>${status(campaign.status)}<h2>${esc(campaign.title)}</h2><p>${esc(localDate(campaign.start_utc, campaign.timezone))} ← ${esc(localDate(campaign.end_utc, campaign.timezone))} · ${esc(campaign.timezone)}</p><div class="exin-bf-actions">${actions()}</div></div><div id="exin-bf-countdown" class="exin-bf-countdown" role="timer"></div></section>
            <div class="exin-bf-stats">${stat(wp.i18n.__('محصول منتخب', 'exin-black-friday'), state.count)}${stat(wp.i18n.__('سفارش مرتبط', 'exin-black-friday'), analytics.orders ?? 0)}${stat(wp.i18n.__('درآمد اقلام کمپین', 'exin-black-friday'), analytics.item_revenue ?? '0', true)}${stat(wp.i18n.__('واحد فروخته‌شده', 'exin-black-friday'), analytics.units ?? 0)}</div>
            <section class="exin-bf-card"><h2>${esc(wp.i18n.__('فروش واقعی', 'exin-black-friday'))}</h2><p>${esc(wp.i18n.__('مبلغ کل سفارش‌های مرتبط', 'exin-black-friday'))}: ${price(analytics.order_revenue ?? '0')}</p><p class="exin-bf-note">${esc(wp.i18n.__('درآمد اقلام کمپین از کل سفارش جداست و مرجوعی‌ها کسر می‌شوند. وضعیت‌های گزارش در عیب‌یابی تنظیم می‌شوند.', 'exin-black-friday'))}</p>${analytics.complete === false ? `<p class="exin-bf-warning">${esc(wp.i18n.__('گزارش هنوز کامل نیست؛ دادهٔ فعلی موقت است.', 'exin-black-friday'))} ${button(wp.i18n.__('ادامه محاسبه گزارش', 'exin-black-friday'), 'refresh')}</p>` : ''}</section>
            ${campaign.last_error ? `<p class="exin-bf-warning">${esc(campaign.last_error)} ${button(wp.i18n.__('بررسی عیب‌یابی', 'exin-black-friday'), 'diagnostics')}</p>` : ''}
            <div class="exin-bf-orders"><section class="exin-bf-card"><h2>${esc(wp.i18n.__('پرفروش‌ترین محصولات', 'exin-black-friday'))}</h2>${renderList(analytics.top_products || [], item => `${esc(item.name)} · ${esc(item.units)} ${esc(wp.i18n.__('واحد', 'exin-black-friday'))}`)}</section><section class="exin-bf-card"><h2>${esc(wp.i18n.__('آخرین سفارش‌های مرتبط', 'exin-black-friday'))}</h2>${renderList(analytics.recent_orders || [], order => `#${esc(order.number || order.order_id)} · ${esc(order.status)} · ${price(order.total)}`)}</section></div>`;
        countdownTimer = setInterval(updateCountdown, 1000); updateCountdown();
    }
    function stat(label, value, monetary = false) { return `<section class="exin-bf-stat"><span>${esc(label)}</span><strong>${monetary ? price(value) : esc(helpers.persianDigits(value))}</strong></section>`; }
    function renderList(items, renderer) { return items.length ? `<ul class="exin-bf-list">${items.slice(0, 10).map(item => `<li>${renderer(item)}</li>`).join('')}</ul>` : `<p class="exin-bf-note">${esc(wp.i18n.__('هنوز داده‌ای ثبت نشده است.', 'exin-black-friday'))}</p>`; }
    function updateCountdown() {
        const node = document.getElementById('exin-bf-countdown');
        if (!node || !state.campaign) return;
        const campaign = state.campaign;
        if (['completed', 'paused', 'failed'].includes(campaign.status)) { node.textContent = statuses[campaign.status]; return; }
        const now = Date.now() + serverOffset;
        const start = new Date(campaign.start_utc.replace(' ', 'T') + 'Z').getTime();
        const end = new Date(campaign.end_utc.replace(' ', 'T') + 'Z').getTime();
        const seconds = Math.max(0, Math.floor(((now < start ? start : end) - now) / 1000));
        node.textContent = [Math.floor(seconds / 86400), Math.floor(seconds % 86400 / 3600), Math.floor(seconds % 3600 / 60), seconds % 60].map(value => String(value).padStart(2, '0')).join(' : ');
    }
    function field(label, name, value, options = {}) {
        const attributes = `${options.required ? 'required' : ''} ${options.money ? 'data-exin-money' : ''} ${options.jalali ? 'data-exin-jalali' : ''} ${options.extra || ''}`;
        return `<div class="exin-bf-field"><label for="bf-${esc(name)}">${esc(label)}</label><input id="bf-${esc(name)}" name="${esc(name)}" value="${esc(value)}" type="${esc(options.type || 'text')}" ${attributes}>${options.help ? `<small>${esc(options.help)}</small>` : ''}</div>`;
    }
    function renderSettings() {
        const campaign = state.campaign;
        const locked = campaign && !['draft', 'completed'].includes(campaign.status);
        const value = draft || hydrateDraft(campaign);
        const methods = state.health.shipping_methods || [];
        content.innerHTML = `${steps(1)}<div class="exin-bf-toolbar"><div><h2>${esc(labels.settings)}</h2><p class="exin-bf-note">${esc(wp.i18n.__('پیش‌نویس هنگام ویرایش ذخیره می‌شود. فعال‌سازی مرحله‌ای جداست و ذخیره قیمت فروشگاه را تغییر نمی‌دهد.', 'exin-black-friday'))}</p></div>${campaign ? status(campaign.status) : ''}</div>
            ${locked ? `<p class="exin-bf-warning">${esc(wp.i18n.__('تنظیمات مالی کمپین شروع‌شده قفل است؛ وضعیت‌های گزارش در عیب‌یابی قابل ویرایش‌اند.', 'exin-black-friday'))}</p>` : ''}
            <form id="exin-bf-settings"><fieldset ${locked ? 'disabled' : ''}><section class="exin-bf-card"><div class="exin-bf-form">
            ${field(wp.i18n.__('نام جشنواره', 'exin-black-friday'), 'title', value.title, {required: true, extra: 'maxlength="100"'})}
            ${field(wp.i18n.__('تاریخ شروع شمسی', 'exin-black-friday'), 'start_jalali', value.start_jalali, {required: true, jalali: true, extra: `data-timezone="${esc(value.timezone)}"`})}
            ${field(wp.i18n.__('ساعت شروع · ۲۴ساعته', 'exin-black-friday'), 'start_time', value.start_time, {required: true, extra: 'dir="ltr" inputmode="numeric" placeholder="00:00" maxlength="5"', help: wp.i18n.__('مانند ۲۳:۵۹؛ این ساعت، زمان تغییر قیمت هر روز هم هست.', 'exin-black-friday')})}
            ${field(wp.i18n.__('مدت جشنواره (روز)', 'exin-black-friday'), 'days', value.days, {required: true, extra: 'inputmode="numeric"'})}
            <div class="exin-bf-field"><label for="bf-strategy">${esc(wp.i18n.__('الگوی کاهش قیمت', 'exin-black-friday'))}</label><select id="bf-strategy" name="strategy"><option value="linear" ${value.strategy === 'linear' ? 'selected' : ''}>${esc(wp.i18n.__('خطی', 'exin-black-friday'))}</option><option value="progressive" ${value.strategy === 'progressive' ? 'selected' : ''}>${esc(wp.i18n.__('تدریجی', 'exin-black-friday'))}</option></select><small>${esc(wp.i18n.__('روز اول قیمت مبنا و ابتدای روز آخر کف قیمت برقرار است.', 'exin-black-friday'))}</small></div></div></section>
            <section class="exin-bf-card"><details><summary>${esc(wp.i18n.__('ارسال جشنواره و تنظیمات پیشرفته', 'exin-black-friday'))}</summary><label class="exin-bf-check"><input type="checkbox" name="free_shipping" ${value.free_shipping ? 'checked' : ''}>${esc(wp.i18n.__('فعال‌کردن یارانهٔ ارسال برای اقلام جشنواره', 'exin-black-friday'))}</label><div class="exin-bf-form">
            ${field(wp.i18n.__('حداقل جمع اقلام جشنواره', 'exin-black-friday'), 'shipping_threshold', value.shipping_threshold, {money: true, help: wp.i18n.__('صفر یعنی بدون حداقل؛ مبلغ پس از کوپن و بدون مالیات هر بسته ملاک است.', 'exin-black-friday')})}
            ${field(wp.i18n.__('سقف یارانهٔ کل ارسال', 'exin-black-friday'), 'shipping_subsidy_cap', value.shipping_subsidy_cap, {money: true, help: cfg.currency})}
            <div class="exin-bf-field"><label>${esc(wp.i18n.__('روش‌های نرخ ثابت مجاز', 'exin-black-friday'))}</label>${methods.map(method => `<label class="exin-bf-check"><input type="checkbox" name="shipping_instance" value="${esc(method.id)}" ${value.shipping_instances.includes(Number(method.id)) ? 'checked' : ''}>${esc(method.label)}</label>`).join('') || `<small>${esc(wp.i18n.__('روش نرخ ثابت فعالی وجود ندارد؛ روش‌های دیگر تغییر نمی‌کنند.', 'exin-black-friday'))}</small>`}</div>
            ${field(wp.i18n.__('منطقه زمانی', 'exin-black-friday'), 'timezone', value.timezone, {required: true})}
            ${field(wp.i18n.__('توان الگوی تدریجی', 'exin-black-friday'), 'power', value.power, {required: true, extra: 'inputmode="numeric"'})}
            <div class="exin-bf-field"><label for="bf-rounding">${esc(wp.i18n.__('روش گردکردن', 'exin-black-friday'))}</label><select id="bf-rounding" name="rounding">${[['half_up', wp.i18n.__('نزدیک‌ترین؛ نیم رو به بالا', 'exin-black-friday')], ['floor', wp.i18n.__('رو به پایین', 'exin-black-friday')], ['ceil', wp.i18n.__('رو به بالا', 'exin-black-friday')]].map(([key, text]) => `<option value="${key}" ${value.rounding === key ? 'selected' : ''}>${esc(text)}</option>`).join('')}</select></div>
            ${field(wp.i18n.__('نگهداری رخدادها (روز)', 'exin-black-friday'), 'retention_days', value.retention_days, {required: true, extra: 'inputmode="numeric"'})}
            <div class="exin-bf-field"><label for="bf-inactive_output">${esc(wp.i18n.__('نمایش کمپین غیرفعال', 'exin-black-friday'))}</label><select id="bf-inactive_output" name="inactive_output"><option value="message" ${value.inactive_output === 'message' ? 'selected' : ''}>${esc(wp.i18n.__('پیام وضعیت', 'exin-black-friday'))}</option><option value="empty" ${value.inactive_output === 'empty' ? 'selected' : ''}>${esc(wp.i18n.__('خروجی خالی', 'exin-black-friday'))}</option></select></div></div>
            <p class="exin-bf-note">${esc(wp.i18n.__('سقف، بودجهٔ کل سبد برای هزینهٔ پایهٔ ارسال بدون مالیات است؛ بین بسته‌های واجد شرایط تقسیم می‌شود. مشتری فقط مابه‌التفاوت را می‌پردازد.', 'exin-black-friday'))}</p>${cfg.display_unit !== 'toman' ? `<p class="exin-bf-note">${esc(wp.i18n.__('تبدیل ارزی انجام نمی‌شود؛ مبلغ‌ها در ارز فروشگاه‌اند و سقف باید صریح تعیین شود.', 'exin-black-friday'))}</p>` : ''}</details></section>
            <div class="exin-bf-actions"><button class="exin-bf-button" type="submit">${esc(labels.save)}</button>${button(wp.i18n.__('ذخیره و انتخاب محصولات', 'exin-black-friday'), 'step-products', 'exin-bf-primary')}</div></fieldset></form>`;
    }
    function renderReview() {
        const campaign = state.campaign;
        const draftCampaign = campaign.status === 'draft';
        content.innerHTML = `${steps(3)}<section class="exin-bf-card"><h2>${esc(labels.review)}</h2><dl class="exin-bf-review"><dt>${esc(wp.i18n.__('نام جشنواره', 'exin-black-friday'))}</dt><dd>${esc(campaign.title)}</dd><dt>${esc(wp.i18n.__('بازهٔ ایران / منطقهٔ کمپین', 'exin-black-friday'))}</dt><dd>${esc(localDate(campaign.start_utc, campaign.timezone))} ← ${esc(localDate(campaign.end_utc, campaign.timezone))} · ${esc(campaign.timezone)}</dd><dt>${esc(wp.i18n.__('محصول منتخب', 'exin-black-friday'))}</dt><dd>${helpers.persianDigits(state.count)}</dd><dt>${esc(wp.i18n.__('الگوی قیمت', 'exin-black-friday'))}</dt><dd>${esc(campaign.strategy === 'linear' ? wp.i18n.__('خطی', 'exin-black-friday') : wp.i18n.__('تدریجی', 'exin-black-friday'))}</dd></dl>
            <p class="exin-bf-note">${esc(wp.i18n.__('قیمت‌های آینده فقط در پیش‌نمایش مدیریت دیده می‌شوند. محصولات ناموجود یا غیرقابل خرید در جشنواره نمایش داده نمی‌شوند.', 'exin-black-friday'))}</p><div class="exin-bf-actions">${button(labels.settings, 'settings')}${button(labels.products, 'products')}</div></section>
            <section class="exin-bf-card"><h2>${esc(wp.i18n.__('تأیید نهایی مدیر', 'exin-black-friday'))}</h2><p>${esc(labels.activation)}</p><label class="exin-bf-check"><input type="checkbox" id="exin-bf-confirm-activate" ${!draftCampaign || !state.count ? 'disabled' : ''}>${esc(wp.i18n.__('تنظیمات، کف قیمت‌ها و زمان شروع را بررسی کردم و فعال‌سازی این کمپین را تأیید می‌کنم.', 'exin-black-friday'))}</label>${button(wp.i18n.__('تأیید و فعال‌سازی کمپین', 'exin-black-friday'), 'activate', 'exin-bf-primary', 'id="exin-bf-activate" disabled')}${!state.count ? `<p class="exin-bf-warning">${esc(wp.i18n.__('ابتدا حداقل یک محصول با کف معتبر انتخاب کنید.', 'exin-black-friday'))}</p>` : ''}${!draftCampaign ? status(campaign.status) : ''}</section>`;
    }
    function stockText(product) {
        if (product.stock_quantity !== null && product.stock_quantity !== undefined) return helpers.persianDigits(product.stock_quantity);
        if (product.stock !== null && product.stock !== undefined) return helpers.persianDigits(product.stock);
        return product.stock_status === 'instock' ? wp.i18n.__('موجود', 'exin-black-friday') : product.stock_status === 'onbackorder' ? wp.i18n.__('پیش‌سفارش؛ در نمایش جشنواره پنهان است', 'exin-black-friday') : wp.i18n.__('ناموجود', 'exin-black-friday');
    }
    function renderProducts() {
        content.innerHTML = `${state.campaign.status === 'draft' ? steps(2) : ''}<div class="exin-bf-toolbar"><div><h2>${esc(labels.products)} <span class="exin-bf-status">${helpers.persianDigits(state.count)}</span></h2><p class="exin-bf-note">${esc(wp.i18n.__('تنوع‌ها مستقل‌اند. ردیف‌های ویژه را بکشید یا اولویت عددی بدهید؛ ترتیب عادی بر اساس تخفیف روز، فروش و تازگی است.', 'exin-black-friday'))}</p></div><div class="exin-bf-actions">${button(labels.add, 'add', 'exin-bf-primary')}${button(wp.i18n.__('حذف انتخاب‌شده‌ها', 'exin-black-friday'), 'bulk-remove', 'exin-bf-danger')}${button(labels.review, 'review')}</div></div>
            <div class="exin-bf-filter"><input id="exin-bf-filter-text" class="exin-bf-search" type="search" placeholder="${esc(wp.i18n.__('جست‌وجو در صفحه جاری', 'exin-black-friday'))}" aria-label="${esc(labels.search)}"><select id="exin-bf-filter-status" aria-label="${esc(wp.i18n.__('فیلتر وضعیت', 'exin-black-friday'))}"><option value="">${esc(labels.all)}</option>${Object.entries(statuses).map(([key, text]) => `<option value="${key}">${esc(text)}</option>`).join('')}</select></div>
            <section class="exin-bf-card"><div class="exin-bf-table-scroll"><table class="exin-bf-table"><thead><tr><th><input type="checkbox" id="exin-bf-select-all" aria-label="${esc(wp.i18n.__('انتخاب ردیف‌های صفحه', 'exin-black-friday'))}"></th><th>${esc(wp.i18n.__('محصول', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('قیمت مبنا', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('قیمت فعلی', 'exin-black-friday'))}</th><th>${esc(labels.floor)}</th><th>${esc(wp.i18n.__('تخفیف فعلی', 'exin-black-friday'))}</th><th>${esc(labels.stock)}</th><th>${esc(wp.i18n.__('وضعیت', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('ویژه و ترتیب', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('عملیات', 'exin-black-friday'))}</th></tr></thead><tbody>${(state.items || []).map(productRow).join('') || `<tr><td colspan="10">${esc(wp.i18n.__('محصولی اضافه نشده است.', 'exin-black-friday'))}</td></tr>`}</tbody></table></div></section>
            <div class="exin-bf-pagination">${button(labels.previous, 'previous', '', page <= 1 ? 'disabled' : '')}<span>${helpers.persianDigits(page)}</span>${button(labels.next, 'next', '', page * 25 >= state.count ? 'disabled' : '')}</div><details class="exin-bf-advanced"><summary>${esc(wp.i18n.__('ابزار پیشرفته: ورود با شناسه', 'exin-black-friday'))}</summary><p>${esc(wp.i18n.__('روش اصلی، انتخاب‌گر ووکامرس است؛ این ابزار برای اپراتور باتجربه باقی مانده است.', 'exin-black-friday'))}</p>${button(wp.i18n.__('ورود گروهی با شناسه', 'exin-black-friday'), 'bulk-add')}</details>`;
    }
    function discountText(item) {
        try {
            const base = BigInt(helpers.toStore(String(item.base_price), 'store', cfg.currency_code, cfg.precision).replace('.', ''));
            const current = BigInt(helpers.toStore(String(item.product?.price), 'store', cfg.currency_code, cfg.precision).replace('.', ''));
            return helpers.persianDigits(base > current && base > 0n ? (base - current) * 100n / base : 0n) + '٪';
        } catch (_) { return '—'; }
    }
    function productRow(item) {
        const product = item.product || {};
        const conflict = item.status === 'conflict';
        return `<tr data-product-row="${esc(item.product_id)}" data-name="${esc((product.name || '') + ' ' + (product.sku || ''))}" data-status="${esc(item.status)}" draggable="${item.featured ? 'true' : 'false'}"><td><input type="checkbox" class="exin-bf-row-select" value="${esc(item.product_id)}" aria-label="${esc(wp.i18n.__('انتخاب محصول', 'exin-black-friday'))}"></td><td><div class="exin-bf-product">${product.image ? `<img src="${esc(product.image)}" alt="" loading="lazy">` : ''}<div>${esc(product.name || wp.i18n.__('محصول حذف‌شده', 'exin-black-friday'))}<small>#${esc(item.product_id)} · ${esc(product.sku || '—')}</small></div></div></td><td>${price(item.base_price)}</td><td>${price(product.price)}</td><td>${price(item.floor_price)}</td><td>${esc(discountText(item))}</td><td>${esc(stockText(product))}</td><td>${status(item.status)}</td><td><div class="exin-bf-priority"><span class="exin-bf-drag" aria-hidden="true">${item.featured ? '⠿' : ''}</span><input class="exin-bf-sort" type="number" min="0" max="999999" value="${esc(item.priority || 0)}" data-priority="${esc(item.product_id)}" aria-label="${esc(wp.i18n.__('اولویت عددی', 'exin-black-friday'))}"><label><input type="checkbox" data-featured="${esc(item.product_id)}" ${item.featured ? 'checked' : ''}>${esc(labels.featured)}</label>${item.featured ? `${button('↑', 'move-up', '', `data-id="${item.product_id}" aria-label="${esc(wp.i18n.__('جابه‌جایی به بالا', 'exin-black-friday'))}"`)}${button('↓', 'move-down', '', `data-id="${item.product_id}" aria-label="${esc(wp.i18n.__('جابه‌جایی به پایین', 'exin-black-friday'))}"`)}` : ''}</div></td><td><div class="exin-bf-actions">${conflict ? `${button(wp.i18n.__('بررسی مجدد ایمن', 'exin-black-friday'), 'item-retry', '', `data-id="${item.product_id}"`)}${button(wp.i18n.__('حفظ قیمت بیرونی', 'exin-black-friday'), 'item-release', '', `data-id="${item.product_id}"`)}` : button(wp.i18n.__('ویرایش', 'exin-black-friday'), 'item-edit', '', `data-id="${item.product_id}"`)}${item.status === 'paused' ? button(wp.i18n.__('ادامه محصول', 'exin-black-friday'), 'item-resume', '', `data-id="${item.product_id}"`) : ['active', 'included', 'sold_out'].includes(item.status) ? button(wp.i18n.__('توقف محصول', 'exin-black-friday'), 'item-pause', '', `data-id="${item.product_id}"`) : ''}${button(wp.i18n.__('حذف', 'exin-black-friday'), 'item-remove', 'exin-bf-danger', `data-id="${item.product_id}"`)}</div></td></tr>`;
    }
    function renderDiagnostics() {
        const health = state.health, campaign = state.campaign;
        const checks = [[wp.i18n.__('نسخه PHP', 'exin-black-friday'), health.php], [wp.i18n.__('وردپرس', 'exin-black-friday'), health.wordpress], [wp.i18n.__('ووکامرس', 'exin-black-friday'), health.woocommerce], ['Action Scheduler', health.action_scheduler ? wp.i18n.__('در دسترس', 'exin-black-friday') : wp.i18n.__('نیازمند بررسی', 'exin-black-friday')], [wp.i18n.__('کش شیء خارجی', 'exin-black-friday'), health.object_cache ? wp.i18n.__('فعال', 'exin-black-friday') : wp.i18n.__('غیرفعال', 'exin-black-friday')], ['Elementor', health.elementor || '—'], [wp.i18n.__('منطقه زمانی', 'exin-black-friday'), health.timezone], [wp.i18n.__('آخرین اجرای موفق', 'exin-black-friday'), localDate(campaign?.last_success, campaign?.timezone)]];
        const blocked = ['pending', 'failed', 'cancelled', 'checkout-draft', 'refunded'];
        content.innerHTML = `<div class="exin-bf-toolbar"><h2>${esc(wp.i18n.__('گزارش و عیب‌یابی', 'exin-black-friday'))}</h2><div class="exin-bf-actions">${button(wp.i18n.__('تازه‌سازی', 'exin-black-friday'), 'refresh')}${campaign ? button(wp.i18n.__('بررسی و بازیابی صف', 'exin-black-friday'), 'recover') : ''}</div></div><section class="exin-bf-card"><div class="exin-bf-health">${checks.map(([label, value]) => `<div>${esc(label)}<strong>${esc(value)}</strong></div>`).join('')}</div></section>
            ${(health.shipping_warnings || []).map(text => `<p class="exin-bf-warning">${esc(text)}</p>`).join('')}
            ${campaign ? `<section class="exin-bf-card"><h2>${esc(wp.i18n.__('وضعیت‌های سفارش در آمار', 'exin-black-friday'))}</h2><form id="exin-bf-report-settings"><div class="exin-bf-status-choices">${Object.entries(health.order_statuses || {}).filter(([key]) => !blocked.includes(key.replace(/^wc-/, ''))).map(([key, label]) => `<label class="exin-bf-check"><input type="checkbox" name="order_status" value="${esc(key.replace(/^wc-/, ''))}" ${(campaign.settings.order_statuses || ['processing', 'completed']).includes(key.replace(/^wc-/, '')) ? 'checked' : ''}>${esc(label)}</label>`).join('')}</div><p class="exin-bf-note">${esc(wp.i18n.__('وضعیت‌های ثبت‌شدهٔ سفارشی نیز قابل انتخاب‌اند. سفارش لغوشده، ناموفق و کاملاً مستردشده در فروش محاسبه نمی‌شود.', 'exin-black-friday'))}</p><button type="submit" class="exin-bf-button">${esc(wp.i18n.__('ذخیره تنظیمات گزارش', 'exin-black-friday'))}</button></form></section>` : ''}
            <section class="exin-bf-card"><h2>${esc(wp.i18n.__('آخرین رخدادها', 'exin-black-friday'))}</h2><div class="exin-bf-table-scroll"><table class="exin-bf-table"><thead><tr><th>${esc(wp.i18n.__('زمان', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('سطح', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('عملیات', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('نتیجه', 'exin-black-friday'))}</th></tr></thead><tbody>${(state.logs || []).map(log => `<tr><td>${esc(localDate(log.created_at, campaign?.timezone))}</td><td>${esc(log.level)}</td><td>${esc(log.action)}</td><td><code>${esc(log.error_code || log.result || '')}</code></td></tr>`).join('') || `<tr><td colspan="4">—</td></tr>`}</tbody></table></div></section>`;
    }
    function openModal(label, html) {
        previousFocus = document.activeElement; title.textContent = label; modal.innerHTML = html;
        if (!dialog.open) dialog.showModal();
        helpers.attach(modal);
        modal.querySelector('input,button,textarea')?.focus();
    }
    function closeModal(force = false) {
        if (picker?.busy && !force) { notice(labels.saving); return; }
        pickerRequest++; picker = null; dialog.close(); previousFocus?.focus();
    }
    async function requireDraft() {
        if (!state.campaign) { await saveDraft(true); }
        if (state.campaign.status !== 'draft') throw new Error(labels.draftOnly);
        if (savedRevision !== editRevision) await saveDraft();
    }
    async function openPicker() {
        await requireDraft();
        picker = {selected: new Map(), rows: [], page: 1, total: 0, hasMore: false, parent: 0, filters: {search: '', category: '', stock: 'all', type: 'all', tax_filters: {}}};
        openModal(labels.add, `<div id="exin-bf-picker"><div class="exin-bf-picker-filters"><label>${esc(labels.search)}<input type="search" id="exin-bf-product-search" autocomplete="off"></label><label>${esc(wp.i18n.__('دسته‌بندی', 'exin-black-friday'))}<select data-picker-filter="category"><option value="">${esc(labels.all)}</option></select></label><label>${esc(labels.stock)}<select data-picker-filter="stock"><option value="all">${esc(labels.all)}</option><option value="instock">${esc(wp.i18n.__('موجود', 'exin-black-friday'))}</option><option value="outofstock">${esc(wp.i18n.__('ناموجود', 'exin-black-friday'))}</option></select></label><label>${esc(wp.i18n.__('نوع محصول', 'exin-black-friday'))}<select data-picker-filter="type">${[['all', labels.all], ['simple', wp.i18n.__('ساده', 'exin-black-friday')], ['variable', wp.i18n.__('متغیر', 'exin-black-friday')], ['variation', wp.i18n.__('تنوع', 'exin-black-friday')]].map(([key, text]) => `<option value="${key}">${esc(text)}</option>`).join('')}</select></label><div id="exin-bf-taxonomy-filters"></div></div><div class="exin-bf-actions">${button(wp.i18n.__('انتخاب محصولات این صفحه', 'exin-black-friday'), 'picker-select-page')}${button(wp.i18n.__('انتخاب نتایج فیلترشده · حداکثر ۲۰۰', 'exin-black-friday'), 'picker-select-filtered')}${button(wp.i18n.__('بازگشت به همه محصولات', 'exin-black-friday'), 'picker-parent-back')}</div><div id="exin-bf-search-results" class="exin-bf-picker-results" aria-live="polite"></div><div id="exin-bf-picker-pagination" class="exin-bf-pagination"></div><section class="exin-bf-picker-selection"><h3>${esc(wp.i18n.__('کف مستقل محصولات انتخاب‌شده', 'exin-black-friday'))} <span id="exin-bf-selection-count" class="exin-bf-status">۰</span></h3><div class="exin-bf-common-floor">${field(wp.i18n.__('کف مشترک اختیاری', 'exin-black-friday'), 'common_floor', '', {money: true})}${button(wp.i18n.__('اعمال به انتخاب‌شده‌ها', 'exin-black-friday'), 'picker-apply-floor')}</div><div id="exin-bf-picker-selected"></div><div class="exin-bf-actions">${button(wp.i18n.__('ذخیره محصولات منتخب', 'exin-black-friday'), 'picker-save', 'exin-bf-primary')}${button(labels.close, 'close-dialog')}</div><p class="exin-bf-note">${esc(labels.selectionLimit)}</p></section></div>`);
        renderPickerSelection();
        try {
            filterMetadata = filterMetadata || await api('product-filters');
            if (!picker) return;
            modal.querySelector('[data-picker-filter="category"]').innerHTML = `<option value="">${esc(labels.all)}</option>${(filterMetadata.categories || []).map(term => `<option value="${esc(term.id)}">${esc(term.name)}</option>`).join('')}`;
            document.getElementById('exin-bf-taxonomy-filters').innerHTML = (filterMetadata.taxonomies || []).map(taxonomy => `<label>${esc(taxonomy.label)}<select data-picker-taxonomy="${esc(taxonomy.name)}"><option value="">${esc(labels.all)}</option>${taxonomy.terms.map(term => `<option value="${esc(term.slug)}">${esc(term.name)}</option>`).join('')}</select>${taxonomy.truncated ? `<small>${esc(wp.i18n.__('فهرست به ۱۰۰ مقدار محدود شده است.', 'exin-black-friday'))}</small>` : ''}</label>`).join('');
            await searchProducts(1);
        } catch (error) { notice(error.message, true); }
    }
    function pickerQuery(searchPage) {
        const query = new URLSearchParams({search: picker.filters.search.trim(), category: picker.filters.category, stock: picker.filters.stock, type: picker.parent ? 'variation' : picker.filters.type, parent_id: String(picker.parent), page: String(searchPage), campaign_id: String(state.campaign.id), tax_filters: JSON.stringify(picker.filters.tax_filters)});
        return `products?${query}`;
    }
    async function searchProducts(searchPage = 1) {
        if (!picker) return;
        const version = ++pickerRequest;
        const result = document.getElementById('exin-bf-search-results');
        if (picker.filters.search.trim().length === 1) { result.textContent = wp.i18n.__('حداقل دو نویسه وارد کنید یا جست‌وجو را خالی بگذارید.', 'exin-black-friday'); return; }
        result.textContent = labels.searching;
        try {
            const data = await api(pickerQuery(searchPage));
            if (version !== pickerRequest || !picker || !dialog.open) return;
            picker.rows = data.products || []; picker.page = searchPage; picker.total = data.total || 0; picker.hasMore = !!data.has_more;
            renderPickerResults();
        } catch (error) { if (version === pickerRequest) result.textContent = error.message; }
    }
    function knownMember(id) { return !!picker?.rows.find(product => Number(product.id) === Number(id) && product.included) || state.items.some(item => Number(item.product_id) === Number(id) && item.status !== 'removed'); }
    function renderPickerResults() {
        const result = document.getElementById('exin-bf-search-results');
        result.innerHTML = picker.rows.map(product => `<div class="exin-bf-picker-row" data-picker-row="${product.id}"><input type="checkbox" data-picker-select="${product.id}" ${picker.selected.has(product.id) ? 'checked' : ''} ${!product.selectable || knownMember(product.id) ? 'disabled' : ''} aria-label="${esc(product.name)}">${product.image ? `<img src="${esc(product.image)}" alt="" loading="lazy">` : '<span class="exin-bf-picker-placeholder" aria-hidden="true">◇</span>'}<div><strong>${esc(product.name)}</strong><small>${esc(product.sku || '—')} · #${product.id} · ${esc(stockText(product))}</small><span>${price(product.price)}</span>${knownMember(product.id) ? `<small>${esc(wp.i18n.__('عضو کمپین است', 'exin-black-friday'))}</small>` : ''}</div>${product.has_variations ? `<button type="button" class="exin-bf-button" data-picker-parent="${product.id}">${esc(wp.i18n.__('انتخاب تنوع‌ها', 'exin-black-friday'))}</button>` : ''}</div>`).join('') || `<p>${esc(labels.noResults)}</p>`;
        document.getElementById('exin-bf-picker-pagination').innerHTML = `${button(labels.previous, 'picker-previous', '', picker.page <= 1 ? 'disabled' : '')}<span>${helpers.persianDigits(picker.page)} · ${helpers.persianDigits(picker.total)} ${esc(wp.i18n.__('نتیجه', 'exin-black-friday'))}</span>${button(labels.next, 'picker-next', '', !picker.hasMore ? 'disabled' : '')}`;
    }
    function renderPickerSelection() {
        const selected = document.getElementById('exin-bf-picker-selected');
        document.getElementById('exin-bf-selection-count').textContent = helpers.persianDigits(picker.selected.size);
        selected.innerHTML = [...picker.selected.values()].map(row => `<div class="exin-bf-selected-row" data-selected-row="${row.product.id}"><strong>${esc(row.product.name)}</strong><label>${esc(labels.floor)} (${esc(cfg.currency)})<input type="text" data-exin-money data-picker-floor="${row.product.id}" value="${esc(row.floor)}" inputmode="decimal" aria-label="${esc(labels.floor + ' ' + row.product.name)}"></label><div>${button(labels.preview, 'picker-preview', '', `data-id="${row.product.id}"`)}<button type="button" class="exin-bf-button" data-picker-remove="${row.product.id}">${esc(wp.i18n.__('حذف از انتخاب', 'exin-black-friday'))}</button></div><div class="exin-bf-preview" id="exin-bf-preview-${row.product.id}"></div></div>`).join('') || `<p>${esc(labels.selectFirst)}</p>`;
        helpers.attach(selected);
    }
    function chooseProduct(product, selected) {
        if (!product || !product.selectable || product.included || knownMember(product.id)) return;
        if (selected) {
            if (picker.selected.size >= 200 && !picker.selected.has(product.id)) throw new Error(labels.selectionLimit);
            if (!picker.selected.has(product.id)) picker.selected.set(product.id, {product, floor: ''});
        } else picker.selected.delete(product.id);
    }
    function checkedFloor(row) {
        try {
            const canonical = helpers.toStore(row.floor, cfg.display_unit, cfg.currency_code, cfg.precision);
            const base = helpers.toStore(row.product.price, 'store', cfg.currency_code, cfg.precision);
            const minor = BigInt(canonical.replace('.', '')), baseMinor = BigInt(base.replace('.', ''));
            if (minor <= 0n || minor > baseMinor) throw new Error(labels.invalidMoney);
            return canonical;
        } catch (_) { throw new Error(labels.invalidMoney + ' ' + row.product.name); }
    }
    async function previewSelected(id) {
        const row = picker?.selected.get(Number(id));
        if (!row) return;
        checkedFloor(row);
        const result = await api(campaignPath('preview'), {...unitPayload(), product_id: Number(id), floor_price: row.floor});
        if (!picker || !dialog.open) return;
        document.getElementById(`exin-bf-preview-${id}`).innerHTML = (result.schedule || []).map((value, index) => `<span><small>${esc(wp.i18n.__('روز', 'exin-black-friday'))} ${helpers.persianDigits(index + 1)}</small>${price(value)}</span>`).join('');
    }
    async function selectFiltered() {
        const generation = pickerRequest;
        const key = JSON.stringify([state.campaign.id, picker.parent, picker.filters]);
        let cursor = picker.scan?.key === key ? picker.scan.next : filteredScanPages.get(key) || 1;
        let more = true, scannedPages = 0;
        while (more && picker && picker.selected.size < 200 && scannedPages < 25) {
            const data = await api(pickerQuery(cursor));
            if (!picker || generation !== pickerRequest) return;
            scannedPages++;
            let completePage = true;
            for (const product of data.products || []) {
                if (picker.selected.size >= 200) { completePage = false; break; }
                chooseProduct(product, true);
            }
            more = completePage ? !!data.has_more : true;
            if (completePage) cursor++;
        }
        if (picker) {
            picker.scan = {key, next: more ? cursor : 1};
            // Empty scans may advance immediately; selected rows advance only after a successful save.
            if (!picker.selected.size) filteredScanPages.set(key, picker.scan.next);
            renderPickerResults(); renderPickerSelection();
            if (more) notice(scannedPages >= 25 && picker.selected.size < 200 ? labels.scanLimit : labels.selectionLimit);
        }
    }
    async function saveSelected() {
        const rows = [...picker.selected.values()];
        if (!rows.length) throw new Error(labels.selectFirst);
        rows.forEach(checkedFloor);
        const controls = [...modal.querySelectorAll('input,select,button')].map(node => [node, node.disabled]);
        picker.busy = true;
        controls.forEach(([node]) => { node.disabled = true; });
        try {
            for (let offset = 0; offset < rows.length; offset += 25) {
                const result = await api(campaignPath('bulk'), {...unitPayload(), action: 'add', items: rows.slice(offset, offset + 25).map(row => ({product_id: row.product.id, floor_price: row.floor}))});
                for (const row of result.results || []) if (row.ok) picker.selected.delete(Number(row.product_id));
            }
        } finally {
            picker.busy = false;
            controls.forEach(([node, disabled]) => { if (node.isConnected) node.disabled = disabled; });
        }
        if (picker.selected.size) { renderPickerSelection(); notice(wp.i18n.__('برخی محصولات ذخیره نشدند؛ انتخاب و کف آنها حفظ شده است. تعارض یا قیمت جاری را بررسی کنید.', 'exin-black-friday'), true); }
        else {
            if (picker.scan) filteredScanPages.set(picker.scan.key, picker.scan.next);
            closeModal(); await navigate('products'); notice(wp.i18n.__('محصولات انتخاب‌شده ذخیره شدند؛ قیمت فروشگاه تغییر نکرد.', 'exin-black-friday'));
        }
    }
    function openEdit(id) {
        const item = state.items.find(row => Number(row.product_id) === Number(id));
        if (!item) return;
        const locked = state.campaign.status !== 'draft';
        openModal(wp.i18n.__('ویرایش محصول', 'exin-black-friday'), `<form id="exin-bf-edit-form" data-id="${id}"><p>${esc(item.product?.name || id)}</p><div class="exin-bf-form">${field(labels.floor, 'floor_price', displayAmount(item.floor_price), {money: true, required: true, extra: locked ? 'disabled' : '', help: cfg.currency})}${field(wp.i18n.__('اولویت عددی', 'exin-black-friday'), 'priority', item.priority || 0, {type: 'number', required: true, extra: 'min="0" max="999999"'})}<label><input type="checkbox" name="featured" ${item.featured ? 'checked' : ''}>${esc(labels.featured)}</label></div><button type="submit" class="exin-bf-button exin-bf-primary">${esc(wp.i18n.__('ذخیره تغییرات', 'exin-black-friday'))}</button></form>`);
    }
    async function saveVisualOrder() {
        const rows = [...content.querySelectorAll('[data-product-row]')].filter(row => row.draggable);
        const items = rows.map((row, index) => ({product_id: Number(row.dataset.productRow), priority: (page - 1) * 25 + index + 1}));
        if (items.length) { await api(campaignPath('reorder'), {items}); await load(); notice(wp.i18n.__('ترتیب محصولات ویژه ذخیره شد.', 'exin-black-friday')); }
    }
    function openBulkIds() {
        if (state.campaign?.status !== 'draft') throw new Error(labels.draftOnly);
        openModal(wp.i18n.__('ابزار پیشرفتهٔ ورود شناسه', 'exin-black-friday'), `<form id="exin-bf-bulk-form"><label>${esc(wp.i18n.__('هر خط: شناسه؛ کف قیمت', 'exin-black-friday'))}<textarea name="rows" rows="8" dir="ltr" placeholder="123;150,000" required></textarea></label><p class="exin-bf-note">${esc(wp.i18n.__('بین شناسه و مبلغ از نقطه‌ویرگول استفاده کنید؛ مبلغ می‌تواند جداکنندهٔ هزارگان داشته باشد.', 'exin-black-friday'))}</p><button type="submit" class="exin-bf-button">${esc(labels.save)}</button></form>`);
    }
    async function command(name, node) {
        if (['dashboard', 'settings', 'products', 'review', 'diagnostics'].includes(name)) { await navigate(name); return; }
        if (name === 'step-products') { await saveDraft(true); await navigate('products'); return; }
        if (name === 'add') { await openPicker(); return; }
        if (name === 'close-dialog') { closeModal(); return; }
        if (name === 'refresh') { await load(); return; }
        if (name === 'previous' || name === 'next') { await navigate('products', page + (name === 'next' ? 1 : -1)); return; }
        if (name === 'picker-previous' || name === 'picker-next') { await searchProducts(picker.page + (name === 'picker-next' ? 1 : -1)); return; }
        if (name === 'picker-parent-back') { picker.parent = 0; await searchProducts(1); return; }
        if (name === 'picker-select-page') { picker.rows.forEach(product => chooseProduct(product, true)); renderPickerResults(); renderPickerSelection(); return; }
        if (name === 'picker-select-filtered') { await selectFiltered(); return; }
        if (name === 'picker-preview') { await previewSelected(node.dataset.id); return; }
        if (name === 'picker-apply-floor') { const amount = document.getElementById('bf-common_floor').value; picker.selected.forEach(row => { row.floor = amount; }); renderPickerSelection(); return; }
        if (name === 'picker-save') { await saveSelected(); return; }
        if (name === 'item-edit') { openEdit(node.dataset.id); return; }
        if (name === 'bulk-add') { openBulkIds(); return; }
        if (name === 'move-up' || name === 'move-down') {
            const row = content.querySelector(`[data-product-row="${node.dataset.id}"]`);
            const siblings = [...row.parentElement.children].filter(candidate => candidate.draggable);
            const index = siblings.indexOf(row), target = siblings[index + (name === 'move-up' ? -1 : 1)];
            if (target) { target.parentElement.insertBefore(row, name === 'move-up' ? target : target.nextSibling); await saveVisualOrder(); }
            return;
        }
        if (name === 'bulk-remove') {
            const items = [...root.querySelectorAll('.exin-bf-row-select:checked')].map(input => ({product_id: Number(input.value)}));
            if (!items.length) throw new Error(labels.selectFirst);
            if (!window.confirm(wp.i18n.__('محصولات انتخاب‌شده حذف و قیمت‌های تحت کنترل پس از بررسی مالکیت بازیابی شوند؟', 'exin-black-friday'))) return;
            let failures = 0;
            for (let offset = 0; offset < items.length; offset += 25) {
                const result = await api(campaignPath('bulk'), {action: 'remove', items: items.slice(offset, offset + 25), confirm_remove: true});
                failures += result.results.filter(row => !row.ok).length;
            }
            filteredScanPages.clear();
            await load(); notice(failures ? wp.i18n.__('برخی محصولات به علت تعارض حذف نشدند؛ وضعیت را بررسی کنید.', 'exin-black-friday') : labels.requestSaved, !!failures); return;
        }
        if (['item-remove', 'item-retry', 'item-release', 'item-pause', 'item-resume'].includes(name)) {
            const payload = {action: name === 'item-remove' ? 'remove' : name === 'item-retry' ? 'retry' : name === 'item-pause' ? 'pause' : name === 'item-resume' ? 'resume' : 'keep_external'};
            if (name === 'item-remove') { if (!window.confirm(wp.i18n.__('حذف و بازیابی امن قیمت این محصول تأیید می‌شود؟', 'exin-black-friday'))) return; payload.confirm_remove = true; }
            if (name === 'item-release') { if (!window.confirm(wp.i18n.__('قیمت بیرونی حفظ و کنترل افزونه واگذار شود؟', 'exin-black-friday'))) return; payload.confirm_release = true; }
            await api(campaignPath(`item/${node.dataset.id}`), payload);
            if (name === 'item-remove') filteredScanPages.clear();
            await load(); notice(labels.requestSaved); return;
        }
        if (name === 'activate') {
            if (!document.getElementById('exin-bf-confirm-activate')?.checked) throw new Error(labels.activation);
            await saveDraft();
            const starts = new Date(state.campaign.start_utc.replace(' ', 'T') + 'Z').getTime();
            await api(campaignPath('action'), {action: Date.now() + serverOffset >= starts ? 'start' : 'schedule', confirm_activate: true});
            view = 'dashboard'; await load(); notice(labels.requestSaved); return;
        }
        if (['start', 'schedule', 'pause', 'resume', 'restore', 'recover'].includes(name)) {
            const payload = {action: name};
            if (['start', 'schedule'].includes(name)) { if (!window.confirm(wp.i18n.__('شروع یا زمان‌بندی این کمپین و برنامهٔ قیمت بررسی و تأیید می‌شود؟', 'exin-black-friday'))) return; payload.confirm_activate = true; }
            if (name === 'pause' && !window.confirm(wp.i18n.__('فقط تغییرات آینده متوقف شوند؟ قیمت جاری بدون بازیابی باقی می‌ماند.', 'exin-black-friday'))) return;
            if (name === 'resume' && !window.confirm(wp.i18n.__('اجرای قیمت صحیح زمان جاری از سر گرفته شود؟', 'exin-black-friday'))) return;
            if (name === 'restore') { if (!window.confirm(wp.i18n.__('کمپین متوقف و بازیابی قیمت‌های تحت کنترل آغاز شود؟ تغییرات بیرونی بازنویسی نمی‌شوند.', 'exin-black-friday'))) return; payload.confirm_restore = true; }
            await api(campaignPath('action'), payload); await load(); notice(labels.requestSaved);
        }
    }
    root.addEventListener('click', async event => {
        const nav = event.target.closest('[data-view]');
        if (nav) { await navigate(nav.dataset.view); return; }
        const parent = event.target.closest('[data-picker-parent]');
        if (parent) { picker.parent = Number(parent.dataset.pickerParent); await searchProducts(1); return; }
        const remove = event.target.closest('[data-picker-remove]');
        if (remove) { picker.selected.delete(Number(remove.dataset.pickerRemove)); renderPickerResults(); renderPickerSelection(); return; }
        const node = event.target.closest('[data-command]');
        if (!node || node.disabled) return;
        node.disabled = true;
        try { await command(node.dataset.command, node); }
        catch (error) { notice(error.message || labels.failed, true); }
        finally { if (node.isConnected && node.id !== 'exin-bf-activate') node.disabled = false; }
    });
    root.addEventListener('input', event => {
        const node = event.target;
        if (node.closest('#exin-bf-settings')) { captureSettings(); scheduleDraftSave(); }
        if (node.id === 'exin-bf-product-search' && picker) {
            picker.filters.search = node.value; pickerRequest++; clearTimeout(pickerTimer); pickerTimer = setTimeout(() => searchProducts(1), 300);
        }
        if (node.dataset.pickerFloor && picker) picker.selected.get(Number(node.dataset.pickerFloor)).floor = node.value;
        if (node.id === 'exin-bf-filter-text') filterRows();
    });
    root.addEventListener('focusout', event => {
        const node = event.target;
        if (!node.matches('[data-exin-money]')) return;
        if (node.closest('#exin-bf-settings')) { captureSettings(); scheduleDraftSave(); }
        if (node.dataset.pickerFloor && picker?.selected.has(Number(node.dataset.pickerFloor))) picker.selected.get(Number(node.dataset.pickerFloor)).floor = node.value;
    });
    root.addEventListener('change', async event => {
        const node = event.target;
        try {
            if (node.closest('#exin-bf-settings')) { captureSettings(); scheduleDraftSave(); }
            if (node.dataset.pickerFilter && picker) { picker.filters[node.dataset.pickerFilter] = node.value; picker.parent = 0; await searchProducts(1); }
            if (node.dataset.pickerTaxonomy && picker) {
                const next = {...picker.filters.tax_filters};
                if (node.value) next[node.dataset.pickerTaxonomy] = node.value; else delete next[node.dataset.pickerTaxonomy];
                if (Object.keys(next).length > 5) { node.value = ''; throw new Error(wp.i18n.__('حداکثر پنج فیلتر برند یا ویژگی را هم‌زمان انتخاب کنید.', 'exin-black-friday')); }
                picker.filters.tax_filters = next; await searchProducts(1);
            }
            if (node.dataset.pickerSelect && picker) { chooseProduct(picker.rows.find(product => product.id === Number(node.dataset.pickerSelect)), node.checked); renderPickerSelection(); }
            if (node.id === 'exin-bf-confirm-activate') document.getElementById('exin-bf-activate').disabled = !node.checked;
            if (node.id === 'exin-bf-filter-status') filterRows();
            if (node.id === 'exin-bf-select-all') root.querySelectorAll('.exin-bf-row-select').forEach(input => { if (!input.closest('tr').hidden) input.checked = node.checked; });
            if (node.dataset.featured || node.dataset.priority) {
                const id = node.dataset.featured || node.dataset.priority;
                const payload = {action: 'update'};
                if (node.dataset.featured) payload.featured = node.checked; else payload.priority = integer(node.value);
                await api(campaignPath(`item/${id}`), payload); await load();
            }
        } catch (error) { notice(error.message, true); }
    });
    function filterRows() {
        const term = document.getElementById('exin-bf-filter-text').value.toLowerCase(), filter = document.getElementById('exin-bf-filter-status').value;
        content.querySelectorAll('[data-product-row]').forEach(row => { row.hidden = !row.dataset.name.toLowerCase().includes(term) || !!(filter && row.dataset.status !== filter); });
    }
    root.addEventListener('dragstart', event => {
        const row = event.target.closest('[data-product-row]');
        if (!row?.draggable || event.target.closest('input,button')) { event.preventDefault(); return; }
        draggedId = row.dataset.productRow; row.classList.add('exin-bf-dragging');
        event.dataTransfer.setData('text/plain', draggedId); event.dataTransfer.effectAllowed = 'move';
    });
    root.addEventListener('dragover', event => { if (draggedId && event.target.closest('[data-product-row][draggable="true"]')) event.preventDefault(); });
    root.addEventListener('drop', async event => {
        const target = event.target.closest('[data-product-row][draggable="true"]');
        if (!draggedId || !target || target.dataset.productRow === draggedId) return;
        event.preventDefault();
        const source = content.querySelector(`[data-product-row="${draggedId}"]`);
        target.parentElement.insertBefore(source, event.clientY < target.getBoundingClientRect().top + target.offsetHeight / 2 ? target : target.nextSibling);
        try { await saveVisualOrder(); } catch (error) { notice(error.message, true); await load(); }
        draggedId = null;
    });
    root.addEventListener('dragend', () => { draggedId = null; root.querySelectorAll('.exin-bf-dragging').forEach(row => row.classList.remove('exin-bf-dragging')); });
    root.addEventListener('submit', async event => {
        const form = event.target;
        if (!form.id.startsWith('exin-bf-')) return;
        event.preventDefault(); const submit = form.querySelector('[type="submit"]'); submit.disabled = true;
        try {
            if (form.id === 'exin-bf-settings') { await saveDraft(true); notice(labels.saved); }
            if (form.id === 'exin-bf-edit-form') {
                const payload = {...unitPayload(), action: 'update', priority: integer(form.elements.priority.value), featured: form.elements.featured.checked};
                if (!form.elements.floor_price.disabled) payload.floor_price = form.elements.floor_price.value;
                await api(campaignPath(`item/${form.dataset.id}`), payload); closeModal(); await load(); notice(labels.requestSaved);
            }
            if (form.id === 'exin-bf-report-settings') {
                await api(campaignPath('report-settings'), {order_statuses: new FormData(form).getAll('order_status')}); await load(); notice(labels.requestSaved);
            }
            if (form.id === 'exin-bf-bulk-form') {
                const lines = form.elements.rows.value.trim().split(/\r?\n/).filter(Boolean);
                if (!lines.length || lines.length > 25) throw new Error(wp.i18n.__('هر نوبت بین یک تا ۲۵ ردیف مجاز است.', 'exin-black-friday'));
                const items = lines.map(line => { const [id, ...parts] = line.split(';'); if (parts.length !== 1 || !/^\d+$/.test(helpers.normalizeDigits(id.trim()))) throw new Error(wp.i18n.__('قالب هر خط باید شناسه؛کف‌قیمت باشد.', 'exin-black-friday')); return {product_id: integer(id.trim()), floor_price: parts[0].trim()}; });
                const result = await api(campaignPath('bulk'), {...unitPayload(), action: 'add', items});
                const failed = result.results.filter(row => !row.ok); if (failed.length) throw new Error(wp.i18n.__('برخی ردیف‌ها ذخیره نشدند؛ قیمت و تعارض را بررسی کنید.', 'exin-black-friday'));
                closeModal(); await navigate('products'); notice(labels.requestSaved);
            }
        } catch (error) { notice(error.message, true); }
        finally { if (submit.isConnected) submit.disabled = false; }
    });
    dialog.addEventListener('cancel', event => { if (picker?.busy) { event.preventDefault(); return; } pickerRequest++; picker = null; });
    window.addEventListener('beforeunload', event => { if (savedRevision !== editRevision || savePromise) { event.preventDefault(); event.returnValue = ''; } });
    load();
})();
