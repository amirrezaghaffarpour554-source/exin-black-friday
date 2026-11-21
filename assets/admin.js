/* global exinBFAdmin, ExinBFHelpers, wp */
(() => {
    'use strict';
    const root = document.getElementById('exin-bf-admin');
    if (!root) return;
    const cfg = exinBFAdmin;
    // wp_localize_script serializes scalar values as strings, including precision zero.
    cfg.precision = Number(cfg.precision);
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
        settings: wp.i18n.__('تخفیف و زمان‌بندی', 'exin-black-friday'),
        review: wp.i18n.__('بررسی و فعال‌سازی', 'exin-black-friday'),
        add: wp.i18n.__('انتخاب محصول از ووکامرس', 'exin-black-friday'),
        previous: wp.i18n.__('قبلی', 'exin-black-friday'),
        next: wp.i18n.__('بعدی', 'exin-black-friday'),
        close: wp.i18n.__('بستن', 'exin-black-friday'),
        all: wp.i18n.__('همه', 'exin-black-friday'),
        featured: wp.i18n.__('ویژه', 'exin-black-friday'),
        stock: wp.i18n.__('موجودی', 'exin-black-friday'),
        floor: wp.i18n.__('کف قیمت', 'exin-black-friday'),
        preview: wp.i18n.__('پیش‌نمایش مراحل', 'exin-black-friday'),
        invalidMoney: wp.i18n.__('مبلغ معتبر وارد کنید؛ کف نباید منفی یا بیشتر از قیمت مبنا باشد.', 'exin-black-friday'),
        draftOnly: wp.i18n.__('انتخاب محصول و تغییر کف فقط در پیش‌نویس مجاز است.', 'exin-black-friday'),
        selectFirst: wp.i18n.__('ابتدا محصولی انتخاب کنید.', 'exin-black-friday'),
        noResults: wp.i18n.__('محصولی با این فیلترها پیدا نشد.', 'exin-black-friday'),
        search: wp.i18n.__('نام یا SKU محصول', 'exin-black-friday'),
        searching: wp.i18n.__('در حال دریافت محصولات…', 'exin-black-friday'),
        activation: wp.i18n.__('فعال‌سازی فقط با تأیید صریح شما انجام می‌شود.', 'exin-black-friday'),
        requestSaved: wp.i18n.__('درخواست ثبت شد؛ نتیجه را در وضعیت کمپین و عیب‌یابی بررسی کنید.', 'exin-black-friday')
    };
    const statuses = {
        draft: wp.i18n.__('پیش‌نویس', 'exin-black-friday'), scheduled: wp.i18n.__('زمان‌بندی‌شده', 'exin-black-friday'),
        active: wp.i18n.__('فعال', 'exin-black-friday'), paused: wp.i18n.__('متوقف', 'exin-black-friday'),
        archived: wp.i18n.__('آرشیوشده', 'exin-black-friday'), completed: wp.i18n.__('پایان‌یافته', 'exin-black-friday'), failed: wp.i18n.__('ناموفق', 'exin-black-friday'),
        included: wp.i18n.__('انتخاب‌شده', 'exin-black-friday'), pending: wp.i18n.__('در صف', 'exin-black-friday'),
        sold_out: wp.i18n.__('ناموجود', 'exin-black-friday'), conflict: wp.i18n.__('تعارض', 'exin-black-friday'), removed: wp.i18n.__('حذف‌شده', 'exin-black-friday')
    };
    let state = null, view = 'dashboard', page = 1, requestVersion = 0, serverOffset = 0, countdownTimer;
    let draft = null, editRevision = 0, savedRevision = 0, savePromise = null, autosaveTimer, previousFocus;
    let picker = null, pickerRequest = 0, pickerTimer, filterMetadata = null, draggedId = null;
    const selectionKey = `exin-bf-selected:${cfg.root}`;
    let selectedCampaign = Number(new URL(window.location.href).searchParams.get('campaign_id')) || Number(storageGet(localStorage, selectionKey)) || 0;
    let historyPage = 1, pendingPage = 1;
    let campaigns = [], campaignListPage = 1, campaignListMore = false, jobBusy = false, jobState = null;
    function storageGet(store, key) { try { return store.getItem(key); } catch (_) { return null; } }
    function storageSet(store, key, value) { try { value === null ? store.removeItem(key) : store.setItem(key, String(value)); } catch (_) { /* Storage may be disabled by the browser. */ } }
    const planModes = {auto_progressive:'افزایشی خودکار تا سقف هر محصول',auto_descending:'کاهشی خودکار',auto_rotation:'چرخشی متوازن',auto_random:'شانسی قطعی',auto_wave:'موجی',auto_hybrid:'ترکیبی؛ پایه و ویژه',fixed_percent: 'درصد ثابت', fixed_amount: 'مبلغ ثابت', linear: 'کاهش خطی تا کف', stepped: 'درصد افزایشی یا کاهشی', custom: 'برنامه دستی مرحله‌ها'};
    const jobKey = () => `exin-bf-selection-job:${cfg.root}:${selectedCampaign}`;
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
        const settings = campaign?.settings || {}, plan = settings.discount_plan || null;
        const starts = campaign ? utcParts(campaign.start_utc, campaign.timezone) : utcParts(new Date(Date.now() + 300000).toISOString().slice(0, 19).replace('T', ' '), 'Asia/Tehran');
        const points = plan?.points || [{offset_minutes: 0, kind: 'percent', value: '5'}, {offset_minutes: 1440, kind: 'percent', value: '10'}, {offset_minutes: 2880, kind: 'percent', value: '15'}];
        return {
            id: campaign?.id || null, title: campaign?.title || 'کمپین جدید اکسین',
            start_jalali: helpers.persianDigits(starts.date), start_time: starts.time,
            timezone: campaign?.timezone || 'Asia/Tehran', days: String(plan?.mode?.startsWith('auto_') && plan.duration_minutes ? Math.ceil(plan.duration_minutes / 1440) : campaign?.days || 7),
            strategy: campaign?.strategy || 'linear', power: String(campaign?.power || 2), rounding: campaign?.rounding || 'half_up',
            campaign_type: settings.campaign_type || (campaign ? 'black_friday' : 'general'), priority: String(settings.priority || 0),
            discount_mode: plan?.mode || (campaign ? 'legacy' : 'fixed_percent'), interval: plan?.interval || 'daily',
            steps: String(plan?.steps || campaign?.days || 7), interval_minutes: String(plan?.interval_minutes || 60),
            initial_discount: plan?.mode === 'fixed_amount' ? displayAmount(plan.initial_discount) : String(plan?.initial_discount ?? '5'),
            final_discount: String(plan?.final_discount ?? '15'), reach_floor: !!plan?.reach_floor,
            duration_minutes: String(plan?.duration_minutes || 4320),
            auto_min: plan?.intensity_min ?? '10', auto_max: plan?.intensity_max ?? '100', auto_base_max: plan?.base_max ?? '40', auto_curve: plan?.curve || 'linear', auto_bands: String(plan?.bands || 3), auto_wave_period: String(plan?.wave_period || 4), auto_selection_mode: plan?.selection_mode || 'percent', auto_selection_value: String(plan?.selection_value || 25), auto_selector: plan?.selector || 'priority', auto_last_day: plan?.last_day || 'disabled', auto_descending_end: plan?.descending_end || 'continue', auto_seed: plan?.seed || '',
            points: points.map(point => ({...point, value: point.kind === 'amount' ? displayAmount(point.value) : String(point.value)})),
            existing_sale_policy: settings.existing_sale_policy || (!campaign ? 'preserve' : 'reject'),
            free_shipping: !!settings.free_shipping, shipping_threshold: displayAmount(settings.shipping_threshold ?? '0'),
            shipping_subsidy_cap: displayAmount(settings.shipping_subsidy_cap ?? cfg.default_subsidy_cap),
            shipping_instances: (settings.shipping_instances || []).map(Number), inactive_output: settings.inactive_output || 'message',
            retention_days: String(settings.retention_days || 90)
        };
    }
    function rememberCampaign(id) {
        selectedCampaign = Number(id) || 0;
        storageSet(localStorage, selectionKey, selectedCampaign || null);
        const url = new URL(window.location.href);
        selectedCampaign ? url.searchParams.set('campaign_id', selectedCampaign) : url.searchParams.delete('campaign_id');
        window.history.replaceState(null, '', url);
    }
    function renderCampaignNav() {
        const host = document.getElementById('exin-bf-campaigns');
        if (!host) return;
        const list = [...campaigns];
        if (state.campaign && !list.some(row => Number(row.id) === Number(state.campaign.id))) list.unshift(state.campaign);
        host.innerHTML = `<div class="exin-bf-campaign-selector"><label for="exin-bf-campaign-select">کمپین و تاریخچه</label><select id="exin-bf-campaign-select">${list.length ? list.map(row => `<option value="${Number(row.id)}" ${Number(row.id) === selectedCampaign ? 'selected' : ''}>${esc(row.title)} · ${esc(statuses[row.status] || row.status)}</option>`).join('') : '<option value="">هنوز کمپینی ساخته نشده است</option>'}</select>${state.campaign ? button('کپی به پیش‌نویس جدید', 'copy-campaign') : ''}${button('قبلی', 'campaigns-previous', '', campaignListPage <= 1 ? 'disabled' : '')}${button('بعدی', 'campaigns-next', '', !campaignListMore ? 'disabled' : '')}</div>`;
    }
    async function campaignList(nextPage = 1) {
        const result = await api(`campaigns?page=${nextPage}`);
        campaigns = result.campaigns || []; campaignListPage = nextPage; campaignListMore = !!result.has_more;
        renderCampaignNav();
    }
    async function newCampaign() {
        if (jobBusy) throw new Error('ابتدا پردازش گروهی جاری را تمام کنید.');
        if (savePromise || savedRevision !== editRevision) await saveDraft();
        clearTimeout(autosaveTimer); content.innerHTML = ''; state.campaign = null; state.items = []; state.count = 0; state.simulation = null;
        rememberCampaign(0); draft = hydrateDraft(null); editRevision = 1; savedRevision = 0;
        await saveDraft(true); view = 'products'; page = 1; await load();
    }
    async function load() {
        const revision = ++requestVersion;
        content.setAttribute('aria-busy', 'true');
        try {
            const result = await api(`state?page=${page}&view=${encodeURIComponent(view)}${selectedCampaign ? `&campaign_id=${selectedCampaign}` : ''}`);
            if (revision !== requestVersion) return;
            state = result;
            if (result.campaigns) { campaigns = result.campaigns; campaignListMore = campaigns.length >= 50; }
            if (result.selection_job?.token && !(result.selection_job.done || result.selection_job.complete)) storageSet(sessionStorage, `exin-bf-selection-job:${cfg.root}:${result.campaign?.id}`, result.selection_job.token);
            if (result.campaign?.id) rememberCampaign(result.campaign.id);
            if (view === 'review' && state.campaign) state.simulation = await api(campaignPath('simulation'), {});
            renderCampaignNav();
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
            timezone: fields.get('timezone'), days: fields.has('auto_duration_days') ? fields.get('auto_duration_days') : fields.get('days'), strategy: fields.get('strategy'), power: fields.get('power'), rounding: fields.get('rounding'),
            free_shipping: fields.has('free_shipping'), shipping_threshold: fields.get('shipping_threshold'), shipping_subsidy_cap: fields.get('shipping_subsidy_cap'),
            shipping_instances: fields.getAll('shipping_instance').map(Number), inactive_output: fields.get('inactive_output'), retention_days: fields.get('retention_days'),
            campaign_type: fields.get('campaign_type'), priority: fields.get('campaign_priority'), discount_mode: fields.get('discount_mode'),
            interval: fields.get('interval') || draft.interval, steps: fields.get('steps') || draft.steps,
            interval_minutes: fields.get('interval_minutes') || draft.interval_minutes,
            initial_discount: fields.get('initial_discount') ?? draft.initial_discount, final_discount: fields.get('final_discount') ?? draft.final_discount,
            reach_floor: fields.has('reach_floor'), duration_minutes: fields.get('duration_minutes') || draft.duration_minutes,
            existing_sale_policy: fields.has('preserve_existing_sale') ? 'preserve' : 'reject',
            points: form.querySelector('[data-plan-point-row]') ? [...form.querySelectorAll('[data-plan-point-row]')].map(row => ({offset_minutes: row.querySelector('[data-point-offset]').value, kind: row.querySelector('[data-point-kind]').value, value: row.querySelector('[data-point-value]').value})) : draft.points
        };
        for (const key of ['auto_min','auto_max','auto_base_max','auto_curve','auto_bands','auto_wave_period','auto_selection_mode','auto_selection_value','auto_selector','auto_last_day','auto_descending_end','auto_seed']) { if (fields.has(key)) next[key] = fields.get(key); }
        const stageCount = form.querySelector('[name="auto_steps"]');
        if (stageCount) stageCount.value = automaticSteps(next);
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
                shipping_instances: draft.shipping_instances, inactive_output: draft.inactive_output, retention_days: retention, campaign_type: draft.campaign_type, priority: integer(draft.priority),
                existing_sale_policy: draft.existing_sale_policy}
        };
        if (draft.discount_mode !== 'legacy') {
            const automatic = draft.discount_mode.startsWith('auto_'), intervalMinutes = automatic && draft.interval === 'daily' ? 1440 : integer(draft.interval_minutes);
            const steps = automatic ? automaticSteps(draft) : integer(draft.steps);
            if (!automatic && (!Number.isInteger(steps) || steps < 1 || steps > 366)) throw new Error('تعداد مراحل باید بین ۱ تا ۳۶۶ باشد.');
            const plan = {mode: draft.discount_mode, interval: draft.interval, steps, interval_minutes: intervalMinutes,
                initial_discount: draft.initial_discount, final_discount: draft.final_discount, reach_floor: draft.reach_floor};
            if (draft.discount_mode === 'custom') {
                plan.points = draft.points.map(point => ({offset_minutes: integer(point.offset_minutes), kind: point.kind, value: point.value}));
                plan.duration_minutes = integer(draft.duration_minutes);
            }
            if (automatic) Object.assign(plan,{duration_minutes:days * 1440,intensity_min:draft.auto_min,intensity_max:draft.auto_max,base_max:draft.auto_base_max,curve:draft.auto_curve,bands:integer(draft.auto_bands),wave_period:integer(draft.auto_wave_period),selection_mode:draft.auto_selection_mode,selection_value:integer(draft.auto_selection_value),selector:draft.auto_selector,last_day:draft.auto_last_day,descending_end:draft.auto_descending_end,seed:draft.auto_seed});
            payload.settings.discount_plan = plan;
        }
        if (draft.id) payload.id = Number(draft.id);
        return payload;
    }
    async function saveDraft(force = false) {
        if (jobBusy) throw new Error('تا پایان پردازش گروهی، تنظیمات کمپین را تغییر ندهید.');
        clearTimeout(autosaveTimer);
        captureSettings();
        if (state?.campaign && state.campaign.status !== 'draft') return state.campaign;
        if (savePromise) await savePromise;
        if (!force && savedRevision === editRevision && draft?.id) return state.campaign;
        const payload = draftPayload();
        const revision = editRevision;
        draftNotice(labels.saving, 'saving');
        savePromise = api('campaign', payload);
        try {
            const result = await savePromise;
            state.campaign = result.campaign;
            rememberCampaign(result.campaign.id);
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
        if (jobBusy) throw new Error('پردازش گروهی در حال انجام است.');
        captureSettings();
        if (savedRevision !== editRevision) {
            try {
                await saveDraft();
                if (savePromise || savedRevision !== editRevision) await saveDraft();
            } catch (error) { draftNotice(error.message, 'error'); }
        }
        view = next; page = nextPage; await load();
    }
    function steps(active) {
        const currentStep = (draft?.discount_mode || state.campaign?.settings.discount_plan?.mode || '').startsWith('auto_') ? ({1:1,2:3,3:5}[active] || active) : active;
        return `<nav class="exin-bf-steps" aria-label="${esc(wp.i18n.__('مراحل راه‌اندازی', 'exin-black-friday'))}">${((draft?.discount_mode || state.campaign?.settings.discount_plan?.mode || '').startsWith('auto_') ? [[1,'انتخاب محصولات','products'],[2,'کف قیمت‌ها','products'],[3,'روش خودکار','settings'],[4,'زمان‌بندی','settings'],[5,labels.review,'review']] : [[1, 'محدوده محصولات', 'products'], [2, labels.settings, 'settings'], [3, labels.review, 'review']]).map(([step, label, destination]) => `<button type="button" data-command="${destination}" data-step="${step}" ${step === currentStep ? 'aria-current="step"' : ''}><span>${helpers.persianDigits(step)}</span>${esc(label)}</button>`).join('')}</nav>`;
    }
    function render() {
        clearInterval(countdownTimer);
        root.querySelectorAll('[data-view]').forEach(node => {
            if (node.dataset.view === view || (view === 'review' && node.dataset.view === 'settings')) node.setAttribute('aria-current', 'page');
            else node.removeAttribute('aria-current');
        });
        if (view === 'diagnostics') renderDiagnostics();
        else if (!state.campaign) renderEmpty();
        else if (view === 'operations') renderOperations();
        else if (view === 'history') renderHistory();
        else if (view === 'settings') renderSettings();
        else if (view === 'products') renderProducts();
        else if (view === 'review') renderReview();
        else renderDashboard();
        if (view === 'dashboard' && state.campaign && state.analytics?.complete) content.insertAdjacentHTML('beforeend', `<section class="exin-bf-card"><h3>کم‌فروش‌ترین اقلام جشنواره</h3>${salesRows(state.analytics.least_products || [])}</section>`);
        helpers.attach(content);
    }
    function actions() {
        const campaign = state.campaign;
        let html = campaign.status === 'draft' ? button(labels.review, 'review', 'exin-bf-primary') : '';
        if (campaign.status === 'scheduled') html += button(wp.i18n.__('شروع در زمان مقرر', 'exin-black-friday'), 'start', 'exin-bf-primary');
        if (['active', 'scheduled'].includes(campaign.status)) html += button(wp.i18n.__('توقف بدون بازیابی', 'exin-black-friday'), 'pause');
        if (campaign.status === 'paused') html += button(wp.i18n.__('ادامه کمپین', 'exin-black-friday'), 'resume', 'exin-bf-primary');
        if (['active', 'scheduled', 'paused', 'failed', 'completed'].includes(campaign.status)) html += button(wp.i18n.__('توقف و بازیابی قیمت‌ها', 'exin-black-friday'), 'restore', 'exin-bf-danger');
        if (['draft', 'completed', 'failed'].includes(campaign.status)) html += button('آرشیو پس از تسویهٔ قیمت‌ها', 'archive');
        return html;
    }
    function renderDashboard() {
        const campaign = state.campaign, analytics = state.analytics || {};
        content.innerHTML = `${campaign.status === 'draft' ? steps(3) : ''}<section class="exin-bf-card exin-bf-hero"><div>${status(campaign.status)}<h2>${esc(campaign.title)}</h2><p>${esc(localDate(campaign.start_utc, campaign.timezone))} ← ${esc(localDate(campaign.end_utc, campaign.timezone))} · ${esc(campaign.timezone)}</p><div class="exin-bf-actions">${actions()}</div></div><div id="exin-bf-countdown" class="exin-bf-countdown" role="timer"></div></section>
            <div class="exin-bf-stats">${stat(wp.i18n.__('محصول منتخب', 'exin-black-friday'), state.count)}${stat(wp.i18n.__('سفارش مرتبط', 'exin-black-friday'), analytics.orders ?? 0)}${stat(wp.i18n.__('درآمد اقلام کمپین', 'exin-black-friday'), analytics.item_revenue ?? '0', true)}${stat(wp.i18n.__('واحد فروخته‌شده', 'exin-black-friday'), analytics.units ?? 0)}</div>
            <section class="exin-bf-card"><h2>${esc(wp.i18n.__('فروش واقعی', 'exin-black-friday'))}</h2><p>${esc(wp.i18n.__('مبلغ کل سفارش‌های مرتبط', 'exin-black-friday'))}: ${price(analytics.order_revenue ?? '0')}</p><p class="exin-bf-note">${esc(wp.i18n.__('درآمد اقلام کمپین از کل سفارش جداست و مرجوعی‌ها کسر می‌شوند. وضعیت‌های گزارش در عیب‌یابی تنظیم می‌شوند.', 'exin-black-friday'))}</p>${analytics.complete === false ? `<p class="exin-bf-warning">${esc(wp.i18n.__('گزارش هنوز کامل نیست؛ دادهٔ فعلی موقت است.', 'exin-black-friday'))} ${button(wp.i18n.__('ادامه محاسبه گزارش', 'exin-black-friday'), 'refresh')}</p>` : ''}</section>
            ${campaign.last_error ? `<p class="exin-bf-warning">${esc(campaign.last_error)} ${button(wp.i18n.__('بررسی عیب‌یابی', 'exin-black-friday'), 'diagnostics')}</p>` : ''}
            <div class="exin-bf-orders"><section class="exin-bf-card"><h2>${esc(wp.i18n.__('پرفروش‌ترین محصولات', 'exin-black-friday'))}</h2>${salesRows(analytics.top_products || [])}</section><section class="exin-bf-card"><h2>${esc(wp.i18n.__('آخرین سفارش‌های مرتبط', 'exin-black-friday'))}</h2>${renderList(analytics.recent_orders || [], order => `#${esc(order.number || order.order_id)} · ${esc(order.status)} · ${price(order.total)}`)}</section></div>`;
        countdownTimer = setInterval(updateCountdown, 1000); updateCountdown();
    }
    function stat(label, value, monetary = false) { return `<section class="exin-bf-stat"><span>${esc(label)}</span><strong>${monetary ? price(value) : esc(helpers.persianDigits(value))}</strong></section>`; }
    function renderList(items, renderer) { return items.length ? `<ul class="exin-bf-list">${items.slice(0, 10).map(item => `<li>${renderer(item)}</li>`).join('')}</ul>` : `<p class="exin-bf-note">${esc(wp.i18n.__('هنوز داده‌ای ثبت نشده است.', 'exin-black-friday'))}</p>`; }
    function updateCountdown() {
        const node = document.getElementById('exin-bf-countdown');
        if (!node || !state.campaign) return;
        const campaign = state.campaign;
        if (['completed', 'archived', 'paused', 'failed'].includes(campaign.status)) { node.textContent = statuses[campaign.status]; return; }
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
    function renderEmpty() {
        content.innerHTML = `${steps(1)}<section class="exin-bf-card exin-bf-empty"><h2>کمپین تخفیف جدید</h2><p>محدوده محصولات را انتخاب کنید، تخفیف و زمان‌بندی را تعیین کنید و پس از پیش‌نمایش فعال کنید.</p>${button('شروع ساخت کمپین', 'new-campaign', 'exin-bf-primary')}</section>`;
    }
    function options(values, selected) { return values.map(([key, label]) => `<option value="${esc(key)}" ${key === selected ? 'selected' : ''}>${esc(label)}</option>`).join(''); }
    function automaticSteps(value) {
        const days = integer(value.days), minutes = value.interval === 'daily' ? 1440 : integer(value.interval_minutes);
        return Number.isInteger(days) && days > 0 && Number.isInteger(minutes) && minutes > 0 ? Math.ceil(days * 1440 / minutes) : '';
    }
    function automaticSettings(value) {
        return `<div class="exin-bf-field"><label>زمان‌بندی خودکار<select id="bf-interval" name="interval">${options([['daily','روزانه؛ هر ۲۴ ساعت'],['hourly','ساعتی با فاصلهٔ قابل تنظیم'],['flash','Flash؛ حداقل ۵ دقیقه و وابسته به ظرفیت']],value.interval)}</select></label></div>
        ${field('مدت کمپین (روز)','auto_duration_days',value.days,{required:true,extra:'inputmode="numeric" min="1" max="366"',help:'مدت کمپین مستقل از فاصلهٔ تغییر تخفیف است.'})}
        ${field('تعداد مراحل خودکار','auto_steps',automaticSteps(value),{extra:'readonly',help:'از مدت و فاصله محاسبه می‌شود؛ سیاست روز آخر ممکن است یک مرز اضافه کند.'})}
        ${value.interval !== 'daily' ? field(value.interval === 'hourly' ? 'فاصلهٔ ساعتی (دقیقه؛ مضرب ۶۰)' : 'فاصلهٔ Flash (دقیقه)','interval_minutes',value.interval_minutes,{required:true,extra:'inputmode="numeric"',help:'فاصلهٔ Flash هنگام فعال‌سازی متناسب با تعداد محصولات و بودجهٔ پردازش بررسی می‌شود.'}) : ''}
        <p class="exin-bf-note">شدت، درصدی از فاصلهٔ قیمت اصلی تا کف همان محصول است؛ درصد تخفیف واقعی هر محصول خودکار محاسبه می‌شود. ابتدا کف مثبت محصولات را در «محصولات» تعیین کنید. Regular Price و Snapshot اصلی حفظ می‌شوند.</p>
        ${field('کمترین شدت (% از سقف مجاز)','auto_min',value.auto_min,{money:true})}${field('بیشترین شدت (% از سقف مجاز)','auto_max',value.auto_max,{money:true})}
        ${value.discount_mode === 'auto_hybrid' ? field('بیشترین شدت تخفیف پایهٔ افزایشی','auto_base_max',value.auto_base_max,{money:true,help:'قیمت ویژه و پایه جمع نمی‌شوند؛ تصمیم نهایی یکی است.'}) : ''}
        <div class="exin-bf-field"><label>رفتار روز آخر جشنواره<select name="auto_last_day">${options([['disabled','غیرفعال؛ بدون سیاست اضافه'],['continue','ادامهٔ روش انتخاب‌شده'],['all_floor','همهٔ محصولات موجود تا کف'],['remaining_floor','تا کف برای محصولات بدون نوبت ویژه پیش از روز آخر']],value.auto_last_day)}</select></label><small>۲۴ ساعت پایانی؛ پایان کمپین همیشه بازیابی ایمن Snapshot دارد.</small></div>
        <details class="exin-bf-picker-advanced"><summary>انتخاب نوبت و تنظیمات پیشرفتهٔ خودکار</summary><div class="exin-bf-form">
        <label>تعداد/درصد گروه ویژه<select name="auto_selection_mode">${options([['percent','درصد محصولات واجد شرایط'],['count','تعداد محصول']],value.auto_selection_mode)}</select></label>${field('اندازهٔ گروه ویژه','auto_selection_value',value.auto_selection_value,{extra:'inputmode="numeric"',help:'برای چرخشی، شانسی و ترکیبی؛ محصولات ناموجود در نوبت تازه انتخاب نمی‌شوند.'})}
        <label>انتخاب ویژه در ترکیبی<select name="auto_selector">${options([['priority','اولویت و نوبت متوازن'],['random','شانسی با Seed ثابت']],value.auto_selector)}</select></label>
        <label>افزایش شدت پایه<select name="auto_curve">${options([['linear','خطی'],['stepped','پله‌ای خودکار']],value.auto_curve)}</select></label>${field('تعداد پله‌های شدت','auto_bands',value.auto_bands,{extra:'inputmode="numeric"'})}${field('دورهٔ موج (مرحله)','auto_wave_period',value.auto_wave_period,{extra:'inputmode="numeric"'})}
        <label>آخرین مرحلهٔ روش کاهشی<select name="auto_descending_end">${options([['continue','ادامهٔ کاهش تا شدت کمینه'],['regular','قیمت اصلی پیش از بازیابی نهایی'],['minimum','شدت کمینه']],value.auto_descending_end)}</select></label>
        ${field('Seed ذخیره‌شده','auto_seed',value.auto_seed,{extra:'readonly',help:'با ذخیرهٔ نخست تولید می‌شود؛ اجرای دوباره قرعه‌کشی تازه نمی‌کند.'})}</div></details>`;
    }
    function renderSettings() {
        const campaign = state.campaign, locked = campaign.status !== 'draft', value = draft || hydrateDraft(campaign);
        const methods = state.health.shipping_methods || [], legacy = value.discount_mode === 'legacy', automatic = value.discount_mode.startsWith('auto_');
        content.innerHTML = `${steps(2)}<div class="exin-bf-toolbar"><div><h2>${esc(labels.settings)}</h2><p class="exin-bf-note">ذخیرهٔ پیش‌نویس قیمت فروشگاه را تغییر نمی‌دهد؛ تخفیف اولیه از لحظهٔ شروع فعال می‌شود.</p></div>${status(campaign.status)}</div>
            ${locked ? '<p class="exin-bf-warning">تنظیمات مالی این نسخه قفل است. پس از بازیابی قیمت‌ها از «کپی به پیش‌نویس جدید» برای ویرایش و اجرای مجدد استفاده کنید.</p>' : ''}
            <form id="exin-bf-settings"><fieldset ${locked ? 'disabled' : ''}><section class="exin-bf-card"><div class="exin-bf-form">
            ${field('نام کمپین', 'title', value.title, {required: true, extra: 'maxlength="100"'})}
            <div class="exin-bf-field"><label for="bf-campaign_type">نوع کمپین</label><select id="bf-campaign_type" name="campaign_type">${options([['general', 'تخفیف عمومی'], ['black_friday', 'جشنوارهٔ بلک فرایدی']], value.campaign_type)}</select></div>
            ${field('تاریخ شروع شمسی', 'start_jalali', value.start_jalali, {required: true, jalali: true, extra: `data-timezone="${esc(value.timezone)}"`})}
            ${field('ساعت شروع · ۲۴ساعته', 'start_time', value.start_time, {required: true, extra: 'dir="ltr" inputmode="numeric" maxlength="5"'})}
            <div class="exin-bf-field"><label for="bf-discount_mode">روش تخفیف</label><select id="bf-discount_mode" name="discount_mode">${options([...Object.entries(planModes), ...(legacy ? [['legacy', 'برنامهٔ قبلی این کمپین']] : [])], value.discount_mode)}</select></div>
            ${!legacy && !automatic && value.discount_mode !== 'custom' ? `${field(value.discount_mode === 'fixed_amount' ? 'مبلغ تخفیف' : 'تخفیف اولیه (%)', 'initial_discount', value.initial_discount, {money: true, help: value.discount_mode === 'fixed_amount' ? cfg.currency : 'از ۰ تا ۱۰۰ درصد'})}${value.discount_mode === 'stepped' ? field('درصد مرحلهٔ آخر', 'final_discount', value.final_discount, {money: true, help: 'برای تخفیف کاهشی، مقدار آخر را کمتر از مقدار اولیه بگذارید.'}) : ''}<div class="exin-bf-field"><label for="bf-interval">بازهٔ تغییر</label><select id="bf-interval" name="interval">${options([['daily', 'روزانه در ساعت شروع'], ['hourly', 'ساعتی'], ['custom', 'فاصلهٔ دلخواه']], value.interval)}</select></div>${field('تعداد مراحل', 'steps', value.steps, {required: true, extra: 'inputmode="numeric" min="1" max="366"'})}${value.interval === 'custom' ? field('فاصلهٔ مراحل (دقیقه)', 'interval_minutes', value.interval_minutes, {required: true, extra: 'inputmode="numeric"'}) : ''}` : ''}
            ${automatic ? automaticSettings(value) : ''}
            ${legacy ? `${field('مدت کمپین (روز)', 'days', value.days, {required: true})}<div class="exin-bf-field"><label for="bf-strategy">الگوی قبلی</label><select name="strategy" id="bf-strategy">${options([['linear', 'خطی'], ['progressive', 'تدریجی']], value.strategy)}</select></div>` : `<input type="hidden" name="days" value="${esc(value.days)}"><input type="hidden" name="strategy" value="${esc(value.strategy)}">`}
            </div>${value.discount_mode === 'custom' ? `<section class="exin-bf-manual-plan"><h3>مرحله‌های دستی</h3><p class="exin-bf-note">زمان هر مرحله، تعداد دقیقه از شروع کمپین است. مرحلهٔ اول باید دقیقهٔ صفر باشد. هر مقدار از قیمت مبنای معتبر محاسبه می‌شود.</p>${value.points.map((point, index) => `<div class="exin-bf-plan-point" data-plan-point-row="${index}"><label>دقیقه از شروع<input data-point-offset inputmode="numeric" value="${esc(point.offset_minutes)}"></label><label>روش<select data-point-kind>${options([['percent', 'درصد'], ['amount', `مبلغ (${cfg.currency})`]], point.kind)}</select></label><label>تخفیف<input data-point-value data-exin-money inputmode="decimal" value="${esc(point.value)}"></label>${button('حذف مرحله', 'point-remove', '', `data-index="${index}" ${index === 0 ? 'disabled' : ''}`)}</div>`).join('')}${button('افزودن مرحله', 'point-add')}${field('پایان کمپین (دقیقه از شروع)', 'duration_minutes', value.duration_minutes, {required: true, extra: 'inputmode="numeric"', help: 'باید بعد از آخرین مرحله باشد.'})}</section>` : ''}</section>
            <section class="exin-bf-card"><details><summary>اولویت و کنترل‌های تخفیف</summary><div class="exin-bf-form">${field('اولویت کمپین', 'campaign_priority', value.priority, {extra: 'inputmode="numeric"', help: 'عدد بزرگ‌تر اولویت بیشتر دارد؛ تخفیف‌ها روی هم انباشته نمی‌شوند.'})}${!automatic ? `<label class="exin-bf-check"><input type="checkbox" name="reach_floor" ${value.reach_floor ? 'checked' : ''}>مرحلهٔ آخر دقیقاً به کف برسد</label>` : ''}<label class="exin-bf-check"><input type="checkbox" name="preserve_existing_sale" ${value.existing_sale_policy === 'preserve' ? 'checked' : ''}>تخفیف قبلی برای بازیابی حفظ شود؛ تخفیف کمپین از قیمت اصلی محاسبه می‌شود</label></div><p class="exin-bf-note">قیمت اصلی و تخفیف قبلی برای بازیابی نگه‌داری می‌شوند. قیمت تحت کنترل کمپین دیگر بازنویسی نمی‌شود.</p></details></section>
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
            <div class="exin-bf-actions">${button('بازگشت به محصولات', 'products')}<button class="exin-bf-button" type="submit">${esc(labels.save)}</button>${button('ذخیره و پیش‌نمایش', 'step-review', 'exin-bf-primary')}</div></fieldset></form>`;
    }
    function renderReview() {
        const campaign = state.campaign, simulation = state.simulation;
        const allowed = campaign.status === 'draft' && state.count > 0 && simulation && !simulation.blocked;
        content.innerHTML = `${steps(3)}<section class="exin-bf-card"><h2>${esc(labels.review)}</h2><dl class="exin-bf-review"><dt>نام کمپین</dt><dd>${esc(campaign.title)}</dd><dt>شروع و پایان ایران</dt><dd>${esc(localDate(simulation?.start_utc || campaign.start_utc, campaign.timezone))} ← ${esc(localDate(simulation?.end_utc || campaign.end_utc, campaign.timezone))}</dd><dt>محصولات مشمول / موجود</dt><dd>${helpers.persianDigits(simulation?.count ?? state.count)} / ${helpers.persianDigits(simulation?.in_stock ?? '—')}</dd><dt>روش تخفیف</dt><dd>${esc(planModes[campaign.settings.discount_plan?.mode] || 'برنامهٔ قبلی کمپین')}</dd></dl>${simulationMarkup(simulation)}${simulation?.page ? `<p>صفحهٔ محصولات ${helpers.persianDigits(simulation.page)} · کل مراحل ${helpers.persianDigits(simulation.total_stages || 0)} · ردشده در این صفحه ${helpers.persianDigits(simulation.rejected_on_page || 0)}</p><div class="exin-bf-actions">${simulation.page > 1 ? button('محصولات قبلی','auto-preview-prev') : ''}${simulation.has_more ? button('محصولات بعدی','auto-preview-next') : ''}${simulation.next_stage !== null ? button('مرحله‌های بعدی','auto-preview-stages') : ''}${simulation.stage_start ? button('از مرحلهٔ نخست','auto-preview-first') : ''}</div>` : ''}<div class="exin-bf-actions">${button(labels.products, 'products')}${button(labels.settings, 'settings')}</div></section>
            <section class="exin-bf-card"><h2>تأیید نهایی مدیر</h2><p>${esc(labels.activation)}</p><label class="exin-bf-check"><input type="checkbox" id="exin-bf-confirm-activate" ${!allowed ? 'disabled' : ''}>محصولات، قیمت روز اول، مراحل بعد و زمان شروع را بررسی کردم و فعال‌سازی را تأیید می‌کنم.</label>${button('تأیید و فعال‌سازی کمپین', 'activate', 'exin-bf-primary', 'id="exin-bf-activate" disabled')}${!state.count ? '<p class="exin-bf-warning">ابتدا محصولات مشمول را انتخاب کنید.</p>' : ''}${!simulation ? '<p class="exin-bf-warning">پیش‌نمایش معتبر دریافت نشده است؛ پیش از فعال‌سازی دوباره بررسی کنید.</p>' : ''}</section>`;
    }
    function simulationMarkup(simulation) {
        if (!simulation) return '';
        return `${(simulation.warnings || []).map(warning => `<p class="exin-bf-warning">${esc(typeof warning === 'string' ? warning : warning.message || warning.code || '')}</p>`).join('')}<p class="exin-bf-note">این شبیه‌سازی قیمت فروشگاه را تغییر نمی‌دهد. کف هر محصول در همهٔ مراحل رعایت می‌شود.</p>${(simulation.samples || []).map((sample, index) => `<details class="exin-bf-simulation" ${index === 0 ? 'open' : ''}><summary>${esc(sample.name || `#${sample.product_id}`)} · مبنا ${price(sample.base_price)} · کف ${price(sample.floor_price)}${sample.maximum_discount_percent !== undefined ? ` · سقف ${esc(sample.maximum_discount_percent)}٪` : ''}</summary><div class="exin-bf-preview">${(sample.schedule || []).map((amount, stage) => `<span><small>مرحله ${helpers.persianDigits((simulation.stage_start || 0) + stage + 1)}</small>${price(amount)}${sample.details?.[stage] ? `<small>${esc(sample.details[stage].discount_percent)}٪ از قیمت اصلی</small>` : ''}</span>`).join('')}</div></details>`).join('')}`;
    }
    function stockText(product) {
        if (product.stock_quantity !== null && product.stock_quantity !== undefined) return helpers.persianDigits(product.stock_quantity);
        if (product.stock !== null && product.stock !== undefined) return helpers.persianDigits(product.stock);
        return product.stock_status === 'instock' ? wp.i18n.__('موجود', 'exin-black-friday') : product.stock_status === 'onbackorder' ? wp.i18n.__('پیش‌سفارش؛ در نمایش جشنواره پنهان است', 'exin-black-friday') : wp.i18n.__('ناموجود', 'exin-black-friday');
    }
    function renderProducts() {
        content.innerHTML = `${state.campaign.status === 'draft' ? steps(1) : ''}<div class="exin-bf-toolbar"><div><h2>${esc(labels.products)} <span class="exin-bf-status">${helpers.persianDigits(state.count)}</span></h2><p class="exin-bf-note">${esc(wp.i18n.__('تنوع‌ها مستقل‌اند. ردیف‌های ویژه را بکشید یا اولویت عددی بدهید؛ ترتیب عادی بر اساس تخفیف روز، فروش و تازگی است.', 'exin-black-friday'))}</p></div><div class="exin-bf-actions">${button(labels.add, 'add', 'exin-bf-primary')}${state.campaign.status === 'draft' ? button('تخفیف و زمان‌بندی ←', 'settings') : ''}${button(wp.i18n.__('حذف انتخاب‌شده‌ها', 'exin-black-friday'), 'bulk-remove', 'exin-bf-danger')}${button(labels.review, 'review')}</div></div>
            ${['draft','active'].includes(state.campaign.status) ? `<section class="exin-bf-card exin-bf-scope"><h3>محدودهٔ محصولات</h3><p class="exin-bf-note">برای هزاران محصول، انتخاب و پردازش دسته‌ای روی سرور انجام می‌شود؛ همهٔ محصولات در مرورگر بارگذاری نمی‌شوند.</p><div class="exin-bf-actions">${button('تمام محصولات فروشگاه', 'scope-all')}${button('تمام محصولات موجود', 'scope-instock')}${button('انتخاب با فیلترها', 'add')}${state.count ? button('حذف تمام انتخاب‌های کمپین', 'selection-remove-all', 'exin-bf-danger') : ''}</div>${storageGet(sessionStorage, jobKey()) ? `<p class="exin-bf-warning">یک پردازش گروهی ناتمام دارید. ${button('ادامهٔ پردازش', 'selection-resume')}</p>` : ''}<div id="exin-bf-job-progress" role="status" aria-live="polite"></div></section>` : ''}
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

    function salesRows(rows) {
        return `<div class="exin-bf-table-scroll"><table class="exin-bf-table"><thead><tr><th>محصول</th><th>سفارش</th><th>تعداد فروش پس از مرجوعی</th><th>درآمد اقلام</th></tr></thead><tbody>${rows.map(row => `<tr><td>${esc(row.name || row.product_id)}</td><td>${helpers.persianDigits(row.orders || 0)}</td><td>${helpers.persianDigits(row.units || 0)}</td><td>${price(row.revenue || '0')}</td></tr>`).join('')}</tbody></table></div>`;
    }
    function renderOperations() {
        const value = state.campaign.settings || {}, notifications = value.notifications || {}, rule = value.new_product_rules || null;
        const check = (name, label, fallback = false) => `<label class="exin-bf-check"><input type="checkbox" name="${name}" ${value[name] ?? fallback ? 'checked' : ''}>${label}</label>`;
        const notify = (name, label) => `<label class="exin-bf-check"><input type="checkbox" name="notify_${name}" ${notifications[name] ? 'checked' : ''}>${label}</label>`;
        content.innerHTML = `<form id="exin-bf-operations"><h2>نمایش، سبد و اعلان‌های کمپین</h2><p class="exin-bf-note">این تنظیمات برنامهٔ قیمت و نسخهٔ مالی کمپین فعال را بازنویسی نمی‌کنند.</p>
        <section class="exin-bf-card"><details open><summary>برچسب و صفحهٔ جشنواره</summary><div class="exin-bf-form">${check('badge_enabled','برچسب اختصاصی فعال',true)}${field('متن برچسب','badge_text',value.badge_text || (value.campaign_type === 'general' ? 'تخفیف EXIN' : 'بلک فرایدی'),{required:true})}${field('رنگ برچسب','badge_color',value.badge_color || '#c72937',{type:'color'})}<label>موقعیت<select name="badge_position">${options([['inline','درون کارت؛ بدون هم‌پوشانی'],['top-right','بالا راست'],['top-left','بالا چپ']],value.badge_position || 'inline')}</select></label>${check('discount_badge','نمایش درصد کنار برچسب',true)}<label>منبع محصولات<select name="public_scope">${options([['single','این کمپین'],['all','همه کمپین‌های فعال؛ بدون تکرار']],value.public_scope || 'single')}</select></label>${check('include_store_products','نمایش سایر محصولات فروشگاه پس از اقلام جشنواره')}${check('show_filters','فیلتر قیمت، درصد تخفیف و دسته‌بندی')}<label>شمارش معکوس<select name="countdown_target">${options([['campaign','پایان کمپین'],['stage','پایان مرحلهٔ جاری']],value.countdown_target || 'campaign')}</select></label></div><p class="exin-bf-note">صفحهٔ Elementor موجود تغییر نمی‌کند. محصولات ویژه با ترتیب فعلی بالاتر می‌مانند؛ ویجت «محصولات مرتبط و مکمل» نیز از روابط WooCommerce استفاده می‌کند.</p></details></section>
        <section class="exin-bf-card"><details><summary>کوپن و پیام کش‌بک</summary><div class="exin-bf-form"><label>کوپن روی اقلام جشنواره<select name="coupon_floor_policy">${options([['allow','رفتار عادی WooCommerce'],['protect','محافظت: کوپن روی اقلام این کمپین اعمال نشود']],value.coupon_floor_policy || 'allow')}</select></label>${check('cashback_message_enabled','نمایش پیام تبلیغاتی کش‌بک')}${field('پیام کش‌بک','cashback_message',value.cashback_message || '۴٪ کش‌بک خرید نقدی طبق شرایط فروشگاه؛ محاسبه و پرداخت توسط سیستم مستقل فروشگاه.')}</div><p class="exin-bf-note">این افزونه کش‌بک را محاسبه یا پرداخت نمی‌کند. حفاظت کف مربوط به قیمت قلم پس از کوپن و پیش از مالیات/هزینه/کش‌بک است؛ سود نهایی پس از کش‌بک مفهوم جداگانه‌ای دارد.</p></details></section>
        <section class="exin-bf-card"><details><summary>محصولات جدید</summary><div class="exin-bf-form"><label>رفتار محصول تازه منتشرشده<select name="new_products">${options([['exclude','وارد نشود؛ پیش‌فرض حفظ‌شدهٔ نسخهٔ قبل'],['manual','فقط پس از تأیید مدیر'],['auto','ورود خودکار با قواعد زیر']],value.new_products || 'exclude')}</select></label><label>محدودهٔ قواعد<select name="new_scope">${options([['saved','قواعد آخرین انتخاب گروهی'],['all','تمام محصولات تازه منتشرشده'],['instock','فقط محصولات تازهٔ موجود']],rule?.filters?.scope || 'saved')}</select></label><label>کف محصولات جدید<select name="new_floor_mode">${options([['none','بدون کف مستقل برای کمپین درصدی'],['percent','درصد پایین‌تر از مبنا'],['amount','مبلغ پایین‌تر از مبنا'],['shared','کف مشترک']],rule?.policy?.mode || 'none')}</select></label>${field('مقدار قاعدهٔ کف (درصد یا '+cfg.currency+')','new_floor_value',rule?.policy?.mode === 'percent' ? rule.policy.value : displayAmount(rule?.policy?.value || '0'),{money:true})}</div><p class="exin-bf-note">فقط رویداد انتشار جدید پردازش می‌شود؛ ارتقا محصولات موجود را دوباره وارد نمی‌کند. انتخاب «قواعد آخرین انتخاب گروهی» محدوده و کف ذخیره‌شده را حفظ می‌کند؛ برای ورود خودکار، کف مستقلِ وابسته به شناسه قابل استفاده نیست.</p><div id="exin-bf-new-pending"></div></details></section>
        <section class="exin-bf-card"><details><summary>اعلان‌ها و اتصال سرویس خارجی</summary><div class="exin-bf-form">${notify('enabled','اعلان‌ها فعال')}${notify('start','شروع کمپین')}${notify('stage','شروع مرحلهٔ تخفیف')}${notify('error','خطا و تأخیر پردازش')}${notify('admin_email','ارسال ایمیل خطا به مدیر فروشگاه')}</div><p class="exin-bf-note">پیامک داخلی یا وابستگی به ارائه‌دهنده وجود ندارد. آداپتور دارای تنظیمات معتبر باید آمادگی را در فیلتر <code>exin_bf_notification_provider_ready</code> اعلام و هوک <code>exin_bf_notification</code> را دریافت کند. در نبود آن پیامکی ارسال نمی‌شود؛ اعلان‌ها در اجرای دوباره تکرار نمی‌شوند.</p></details></section>
        <button type="submit" class="exin-bf-button exin-bf-primary">ذخیرهٔ تنظیمات اجرایی</button></form>`;
        loadPending();
    }
    async function loadPending() {
        const id = state.campaign?.id;
        try {
            const result = await api(campaignPath(`new-products?page=${pendingPage}`));
            if (view !== 'operations' || state.campaign?.id !== id) return;
            const host = document.getElementById('exin-bf-new-pending');
            if (!host) return;
            host.innerHTML = `<h3>محصولات منتظر بررسی</h3>${(result.products || []).map(row => `<label class="exin-bf-check"><input type="checkbox" class="exin-bf-new-select" value="${row.id}">${esc(row.name)} · ${price(row.price)}</label>`).join('') || '<p>محصولی در این صفحه منتظر نیست.</p>'}<div class="exin-bf-actions">${button('تأیید ورود با قواعد مرحلهٔ جاری','new-approve')}${button('رد انتخاب‌ها','new-reject')}${pendingPage > 1 ? button('قبلی','new-prev') : ''}${result.has_more ? button('بعدی','new-next') : ''}</div>`;
        } catch (error) { notice(error.message,true); }
    }
    async function renderHistory() {
        const id = state.campaign.id;
        content.innerHTML = '<p>در حال دریافت تاریخچه…</p>';
        try {
            const result = await api(campaignPath(`history?page=${historyPage}`));
            if (view !== 'history' || state.campaign.id !== id) return;
            content.innerHTML = `<section class="exin-bf-card"><h2>تاریخچهٔ قیمت و اقدامات مدیر</h2><div class="exin-bf-table-scroll"><table class="exin-bf-table"><thead><tr><th>زمان ایران</th><th>عملیات</th><th>محصول</th><th>قیمت قبلی</th><th>قیمت جدید</th><th>ارز</th><th>نتیجه / خطا</th></tr></thead><tbody>${result.rows.map(row => `<tr><td>${esc(localDate(row.created_at,'Asia/Tehran'))}</td><td>${esc(row.action)}</td><td>${esc(row.product_id || '—')}</td><td>${esc(row.before_price ?? '—')}</td><td>${esc(row.after_price ?? '—')}</td><td>${esc(row.currency || '—')}</td><td>${esc(row.result)} ${esc(row.error_code)}</td></tr>`).join('')}</tbody></table></div><div class="exin-bf-actions">${historyPage > 1 ? button('قبلی','history-prev') : ''}${result.has_more ? button('بعدی','history-next') : ''}</div></section>`;
        } catch (error) { notice(error.message,true); }
    }
    function renderDiagnostics() {
        const health = state.health, campaign = state.campaign;
        const checks = [[wp.i18n.__('نسخه PHP', 'exin-black-friday'), health.php], [wp.i18n.__('وردپرس', 'exin-black-friday'), health.wordpress], [wp.i18n.__('ووکامرس', 'exin-black-friday'), health.woocommerce], ['Action Scheduler', health.action_scheduler ? wp.i18n.__('در دسترس', 'exin-black-friday') : wp.i18n.__('نیازمند بررسی', 'exin-black-friday')], [wp.i18n.__('کش شیء خارجی', 'exin-black-friday'), health.object_cache ? wp.i18n.__('فعال', 'exin-black-friday') : wp.i18n.__('غیرفعال', 'exin-black-friday')], ['Elementor', health.elementor || '—'], [wp.i18n.__('منطقه زمانی', 'exin-black-friday'), health.timezone], [wp.i18n.__('آخرین اجرای موفق', 'exin-black-friday'), localDate(campaign?.last_success, campaign?.timezone)]];
        const lastRun = campaign?.settings?.last_run;
        const blocked = ['pending', 'failed', 'cancelled', 'checkout-draft', 'refunded'];
        content.innerHTML = `<div class="exin-bf-toolbar"><h2>${esc(wp.i18n.__('گزارش و عیب‌یابی', 'exin-black-friday'))}</h2><div class="exin-bf-actions">${button(wp.i18n.__('تازه‌سازی', 'exin-black-friday'), 'refresh')}${campaign ? button(wp.i18n.__('بررسی و بازیابی صف', 'exin-black-friday'), 'recover') : ''}</div></div><section class="exin-bf-card"><div class="exin-bf-health">${checks.map(([label, value]) => `<div>${esc(label)}<strong>${esc(value)}</strong></div>`).join('')}</div></section>
            ${health.failed_jobs ? '<p class="exin-bf-warning">کار ناموفق ثبت‌شده در صف جشنواره وجود دارد؛ Action Scheduler و تاریخچه را بررسی کنید.</p>' : ''}${health.late_watchdog ? '<p class="exin-bf-warning">آخرین بررسی صف قدیمی است؛ Action Scheduler و کرون را بررسی کنید.</p>' : ''}${health.notification_error ? '<p class="exin-bf-warning">اتصال اعلان‌ها خطا داشته است؛ تنظیمات آداپتور را بررسی کنید.</p>' : ''}${(health.shipping_warnings || []).map(text => `<p class="exin-bf-warning">${esc(text)}</p>`).join('')}
            ${campaign ? `<section class="exin-bf-card"><h2>${esc(wp.i18n.__('وضعیت‌های سفارش در آمار', 'exin-black-friday'))}</h2><form id="exin-bf-report-settings"><div class="exin-bf-status-choices">${Object.entries(health.order_statuses || {}).filter(([key]) => !blocked.includes(key.replace(/^wc-/, ''))).map(([key, label]) => `<label class="exin-bf-check"><input type="checkbox" name="order_status" value="${esc(key.replace(/^wc-/, ''))}" ${(campaign.settings.order_statuses || ['processing', 'completed']).includes(key.replace(/^wc-/, '')) ? 'checked' : ''}>${esc(label)}</label>`).join('')}</div><p class="exin-bf-note">${esc(wp.i18n.__('وضعیت‌های ثبت‌شدهٔ سفارشی نیز قابل انتخاب‌اند. سفارش لغوشده، ناموفق و کاملاً مستردشده در فروش محاسبه نمی‌شود.', 'exin-black-friday'))}</p><button type="submit" class="exin-bf-button">${esc(wp.i18n.__('ذخیره تنظیمات گزارش', 'exin-black-friday'))}</button></form></section>` : ''}
            ${lastRun ? `<section class="exin-bf-card"><h2>نتیجهٔ آخرین پردازش قیمت</h2><div class="exin-bf-stats">${stat('قیمت تغییرکرده', lastRun.changed || 0)}${stat('بدون تغییر', lastRun.unchanged || 0)}${stat('کنارگذاشته‌شده', lastRun.skipped || 0)}${stat('خطا', lastRun.errors || 0)}</div><p class="exin-bf-note">موفقیت عملیات به‌تنهایی به معنی تغییر واقعی قیمت نیست.</p></section>` : ''}<section class="exin-bf-card"><h2>${esc(wp.i18n.__('آخرین رخدادها', 'exin-black-friday'))}</h2><div class="exin-bf-table-scroll"><table class="exin-bf-table"><thead><tr><th>${esc(wp.i18n.__('زمان', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('سطح', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('عملیات', 'exin-black-friday'))}</th><th>${esc(wp.i18n.__('نتیجه', 'exin-black-friday'))}</th></tr></thead><tbody>${(state.logs || []).map(log => `<tr><td>${esc(localDate(log.created_at, campaign?.timezone))}</td><td>${esc(log.level)}</td><td>${esc(log.action)}</td><td><code>${esc(log.error_code || log.result || '')}</code></td></tr>`).join('') || `<tr><td colspan="4">—</td></tr>`}</tbody></table></div></section>`;
    }
    function openModal(label, html) {
        previousFocus = document.activeElement; title.textContent = label; modal.innerHTML = html;
        if (!dialog.open) dialog.showModal();
        helpers.attach(modal);
        modal.querySelector('input,button,textarea')?.focus();
    }
    function closeModal(force = false) {
        if (picker?.busy && !force) { notice(labels.saving); return; }
        pickerRequest++; picker = null; dialog.close(); modal.innerHTML = ''; previousFocus?.focus();
    }
    async function requireDraft() {
        if (!state.campaign) { await saveDraft(true); }
        if (state.campaign.status !== 'draft') throw new Error(labels.draftOnly);
        if (savePromise || savedRevision !== editRevision) await saveDraft();
    }
    async function requireSelection() {
        if (state.campaign?.status === 'active' && !state.campaign.settings.restoring) return;
        await requireDraft();
    }
    async function openPicker() {
        await requireSelection();
        picker = {selected: new Map(), rows: [], page: 1, total: 0, hasMore: false, parent: 0, filters: {search: '', category: '', stock: 'all', type: 'all', publication: 'publish', tax_filters: {}}, floorPolicy: {mode: (state.campaign.status === 'active' || state.campaign.settings.discount_plan?.mode.startsWith('auto_')) ? 'shared' : state.campaign.settings.discount_plan ? 'none' : 'independent', value: ''}};
        openModal(labels.add, `<div id="exin-bf-picker"><div class="exin-bf-picker-filters"><label>${esc(labels.search)}<input type="search" id="exin-bf-product-search" autocomplete="off"></label><label>دسته‌بندی<select data-picker-filter="category"><option value="">${esc(labels.all)}</option></select></label><label>وضعیت انتشار<select data-picker-filter="publication">${options([['publish','منتشرشده'],['draft','پیش‌نویس؛ ورود پس از انتشار'],['pending','در انتظار بررسی'],['private','خصوصی']],'publish')}</select></label><div id="exin-bf-brand-filters"></div><label>${esc(labels.stock)}<select data-picker-filter="stock">${options([['all', 'همه'], ['instock', 'موجود'], ['outofstock', 'ناموجود']], 'all')}</select></label><label>نوع محصول<select data-picker-filter="type">${options([['all', 'همه'], ['simple', 'ساده'], ['variable', 'متغیر'], ['variation', 'تنوع']], 'all')}</select></label></div><details class="exin-bf-picker-advanced"><summary>فیلترهای پیشرفته</summary><div class="exin-bf-picker-filters" id="exin-bf-taxonomy-filters"></div></details>
            <section class="exin-bf-floor-policy"><h3>روش تعیین کف قیمت</h3><div class="exin-bf-form"><label>روش<select id="exin-bf-floor-policy-mode">${options([...((state.campaign.status === 'active' || state.campaign.settings.discount_plan?.mode.startsWith('auto_')) ? [] : [['none', 'بدون کف مستقل؛ محاسبه از مبنای معتبر']]), ['percent', 'درصد پایین‌تر از قیمت مبنا'], ['amount', 'مبلغ پایین‌تر از قیمت مبنا'], ['shared', 'کف مشترک'], ['previous', 'استفاده از کف قبلی'], ['independent', 'کف مستقل هر محصول']], picker.floorPolicy.mode)}</select></label><label>مقدار <span id="exin-bf-floor-policy-unit">${esc(cfg.currency)}</span><input id="exin-bf-floor-policy-value" data-exin-money inputmode="decimal" value="" ${['none','independent','previous'].includes(picker.floorPolicy.mode) ? 'disabled' : ''}></label></div><p class="exin-bf-note">${state.campaign.status === 'active' ? 'کف معتبر و مثبت برای هر محصول جدید الزامی است. ردیف‌ها و کف‌های قبلی تغییر نمی‌کنند؛ محصول جدید از مرحلهٔ فعلی تخفیف می‌گیرد. برای استثنا، محصول را انتخاب و کف اختصاصی آن را وارد کنید.' : 'در تخفیف عمومی درصدی، ورود کف مستقل اجباری نیست. کف قبلی فقط از دادهٔ ثبت‌شده خوانده می‌شود.'}</p></section>
            <div class="exin-bf-actions">${button('انتخاب صفحه جاری', 'picker-select-page')}${button('تمام نتایج فیلترشده', 'picker-select-filtered')}${button('تمام محصولات سایت', 'picker-select-all')}${button('تمام محصولات موجود', 'picker-select-instock')}${button('پاک‌کردن انتخاب‌های این پنجره', 'picker-clear')}${button('بازگشت به همه محصولات', 'picker-parent-back')}</div><div id="exin-bf-search-results" class="exin-bf-picker-results" aria-live="polite"></div><div id="exin-bf-picker-pagination" class="exin-bf-pagination"></div>
            <section class="exin-bf-picker-selection"><h3>انتخاب‌های فعلی <span id="exin-bf-selection-count" class="exin-bf-status">۰</span></h3><div id="exin-bf-picker-selected"></div><div class="exin-bf-actions">${button('پیش‌نمایش و ثبت انتخاب‌ها', 'picker-save', 'exin-bf-primary')}${button(labels.close, 'close-dialog')}</div></section><section id="exin-bf-selection-preview" aria-live="polite"></section><div id="exin-bf-picker-job-progress" role="status" aria-live="polite"></div></div>`);
        renderPickerSelection();
        try {
            filterMetadata = filterMetadata || await api('product-filters');
            if (!picker) return;
            modal.querySelector('[data-picker-filter="category"]').innerHTML = `<option value="">${esc(labels.all)}</option>${(filterMetadata.categories || []).map(term => `<option value="${esc(term.id)}">${esc(term.name)}</option>`).join('')}`;
            const taxes = filterMetadata.taxonomies || [], brands = taxes.filter(taxonomy => !taxonomy.name.startsWith('pa_'));
            const taxField = taxonomy => `<label>${esc(taxonomy.label)}<select data-picker-taxonomy="${esc(taxonomy.name)}"><option value="">${esc(labels.all)}</option>${taxonomy.terms.map(term => `<option value="${esc(term.slug)}">${esc(term.name)}</option>`).join('')}</select>${taxonomy.truncated ? '<small>فهرست به ۱۰۰ مقدار محدود است؛ فیلتر دقیق سمت سرور انجام می‌شود.</small>' : ''}</label>`;
            document.getElementById('exin-bf-brand-filters').innerHTML = brands.slice(0, 1).map(taxField).join('') || '<label>برند<select disabled><option>برندی ثبت نشده است</option></select></label>';
            document.getElementById('exin-bf-taxonomy-filters').innerHTML = taxes.filter(taxonomy => taxonomy !== brands[0]).map(taxField).join('') || '<p class="exin-bf-note">ویژگی ثبت‌شدهٔ دیگری وجود ندارد.</p>';
            await searchProducts(1);
        } catch (error) { notice(error.message, true); }
    }
    function floorPolicyPayload() {
        const mode = document.getElementById('exin-bf-floor-policy-mode')?.value || picker?.floorPolicy.mode || 'none';
        const value = document.getElementById('exin-bf-floor-policy-value')?.value ?? picker?.floorPolicy.value ?? '';
        const floors = {};
        picker?.selected.forEach(row => { if (mode === 'independent' || ((state.campaign.status === 'active' || state.campaign.settings.discount_plan?.mode.startsWith('auto_')) && String(row.floor).trim() !== '')) floors[row.product.id] = row.floor; });
        return {mode, value, floors};
    }
    function scopeFilters() { return {...picker.filters, parent_id: picker.parent}; }
    function invalidateSelectionPreview() {
        if (!picker) return;
        picker.pending = null;
        const preview = document.getElementById('exin-bf-selection-preview');
        if (preview) preview.innerHTML = '';
    }
    async function prepareSelection(filters, action = 'add', remaining = []) {
        await requireSelection();
        if (!picker) return;
        const allPolicy = floorPolicyPayload();
        const policy = {...allPolicy, floors: filters.ids ? Object.fromEntries(filters.ids.map(id => [id, allPolicy.floors[id]]).filter(([, value]) => value !== undefined)) : allPolicy.floors};
        const result = await api(campaignPath('simulation'), {...unitPayload(), filters, floor_policy: policy});
        if (!picker) return;
        picker.pending = {filters, policy, allPolicy, action, remaining};
        const preview = document.getElementById('exin-bf-selection-preview');
        preview.innerHTML = `<section class="exin-bf-card"><h3>پیش‌نمایش انتخاب گروهی</h3><p>محصول مشمول: <strong>${helpers.persianDigits(result.count ?? result.total ?? 0)}</strong>${remaining.length ? ' · ادامهٔ انتخاب‌های دستی در دسته‌های بعدی ثبت می‌شود.' : ''}</p>${simulationMarkup(result)}${button(action === 'remove' ? 'تأیید حذف و بازیابی امن' : state.campaign.status === 'active' ? 'افزودن فقط محصولات جدید با تخفیف مرحلهٔ فعلی' : 'ثبت این محدوده در پیش‌نویس', 'selection-confirm', action === 'remove' ? 'exin-bf-danger' : 'exin-bf-primary')}</section>`;
        preview.scrollIntoView({block: 'nearest', behavior: 'smooth'});
    }
    function jobProgress(job) {
        jobState = job;
        const host = document.getElementById('exin-bf-picker-job-progress') || document.getElementById('exin-bf-job-progress');
        const failed = Number(job.failed ?? (Array.isArray(job.errors) ? job.errors.length : job.errors) ?? 0);
        if (host) host.innerHTML = `<p class="exin-bf-note">پردازش‌شده: ${helpers.persianDigits(job.processed || 0)} از ${helpers.persianDigits(job.total || 0)} · افزوده: ${helpers.persianDigits(job.added || 0)} · حذف: ${helpers.persianDigits(job.removed || 0)} · بدون تغییر: ${helpers.persianDigits(job.skipped || 0)} · ردشده: ${helpers.persianDigits(job.rejected || 0)} · خطا: ${helpers.persianDigits(failed)}</p><progress max="${Math.max(1, Number(job.total || 0))}" value="${Number(job.processed || 0)}"></progress>${(Array.isArray(job.errors) ? job.errors : []).map(error => `<p class="exin-bf-warning">${esc(typeof error === 'string' ? error : error.message || error.error || error.code || `#${error.product_id || ''}`)}</p>`).join('')}`;
    }
    async function processJob(existingToken, pending) {
        let token = existingToken, job;
        const controls = [...root.querySelectorAll('input,select,button')].map(node => [node, node.disabled]);
        controls.forEach(([node]) => { node.disabled = true; });
        jobBusy = true; if (picker) picker.busy = true;
        clearTimeout(autosaveTimer);
        try {
            if (!token) {
                const result = await api(campaignPath('selection-jobs'), {...unitPayload(), filters: pending.filters, floor_policy: pending.policy, action: pending.action, confirm_remove: pending.action === 'remove'});
                job = result.job || result; token = job.token || job.id;
                if (!token) throw new Error(labels.connection);
                storageSet(sessionStorage, jobKey(), token); jobProgress(job);
            }
            while (!job || !(job.complete || job.done || ['completed', 'done'].includes(job.status))) {
                const result = await api(campaignPath(`selection-jobs/${encodeURIComponent(token)}`), {});
                job = result.job || result; jobProgress(job);
                if (job.status === 'failed' || job.status === 'cancelled') throw new Error(job.message || 'پردازش گروهی متوقف شد؛ وضعیت کمپین را بررسی کنید.');
            }
            storageSet(sessionStorage, jobKey(), null);
            return job;
        } finally { jobBusy = false; if (picker) picker.busy = false; controls.forEach(([node, disabled]) => { if (node.isConnected) node.disabled = disabled; }); }
    }
    async function confirmSelection() {
        const pending = picker?.pending;
        if (!pending) throw new Error('پیش‌نمایش محدوده را دوباره دریافت کنید.');
        if (JSON.stringify(pending.allPolicy) !== JSON.stringify(floorPolicyPayload())) throw new Error('روش کف تغییر کرده است؛ پیش‌نمایش را دوباره بررسی کنید.');
        let failures = 0;
        for (const filters of [pending.filters, ...pending.remaining]) {
            const policy = {...pending.allPolicy};
            if (filters.ids && policy.floors) policy.floors = Object.fromEntries(filters.ids.map(id => [id, policy.floors[id]]).filter(([, value]) => value !== undefined));
            const job = await processJob(null, {...pending, filters, policy}); failures += Number(job.failed || 0) + Number(job.rejected || 0);
        }
        if (!failures) { closeModal(true); await navigate('products'); notice(state.campaign.status === 'active' ? 'محصولات جدید با کف معتبر و تخفیف مرحلهٔ فعلی اضافه شدند؛ محصولات قبلی تغییر نکردند.' : 'انتخاب گروهی ثبت شد؛ قیمت واقعی فروشگاه تغییر نکرد.'); }
        else { await load(); notice('پردازش تمام شد؛ برخی محصولات به علت قیمت یا تداخل ثبت نشدند. خطاهای نمونه را در پنجره بررسی کنید.', true); }
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
        const independent = picker.floorPolicy.mode === 'independent';
        const individual = independent || state.campaign.status === 'active' || state.campaign.settings.discount_plan?.mode.startsWith('auto_');
        document.getElementById('exin-bf-selection-count').textContent = helpers.persianDigits(picker.selected.size);
        selected.innerHTML = [...picker.selected.values()].map(row => `<div class="exin-bf-selected-row" data-selected-row="${row.product.id}"><strong>${esc(row.product.name)}</strong>${individual ? `<label>${esc(labels.floor)} (${esc(cfg.currency)})<input type="text" data-exin-money data-picker-floor="${row.product.id}" value="${esc(row.floor)}" inputmode="decimal" aria-label="${esc(labels.floor + ' ' + row.product.name)}"></label>` : '<span class="exin-bf-note">کف طبق روش گروهی محاسبه می‌شود.</span>'}<div>${independent ? button(labels.preview, 'picker-preview', '', `data-id="${row.product.id}"`) : ''}<button type="button" class="exin-bf-button" data-picker-remove="${row.product.id}">حذف از انتخاب</button></div><div class="exin-bf-preview" id="exin-bf-preview-${row.product.id}"></div></div>`).join('') || `<p>${esc(labels.selectFirst)}</p>`;
        helpers.attach(selected);
    }
    function chooseProduct(product, selected) {
        if (!product || !product.selectable || product.included || knownMember(product.id)) return;
        if (selected) {
            if (!picker.selected.has(product.id)) picker.selected.set(product.id, {product, floor: (state.campaign.status === 'active' || state.campaign.settings.discount_plan?.mode.startsWith('auto_')) ? '' : state.campaign.settings.discount_plan ? '0' : ''});
        } else picker.selected.delete(product.id);
    }
    function checkedFloor(row) {
        try {
            const canonical = helpers.toStore(row.floor, cfg.display_unit, cfg.currency_code, cfg.precision);
            const base = helpers.toStore(row.product.regular_price || row.product.price, 'store', cfg.currency_code, cfg.precision);
            const minor = BigInt(canonical.replace('.', '')), baseMinor = BigInt(base.replace('.', ''));
            if (minor < 0n || minor > baseMinor || ((state.campaign.status === 'active' || !state.campaign.settings.discount_plan) && minor === 0n)) throw new Error(labels.invalidMoney);
            return canonical;
        } catch (_) { throw new Error(labels.invalidMoney + ' ' + row.product.name); }
    }
    async function previewSelected(id) {
        const row = picker?.selected.get(Number(id));
        if (!row) return;
        checkedFloor(row);
        const result = await api(campaignPath('preview'), {...unitPayload(), product_id: Number(id), floor_price: row.floor});
        if (!picker || !dialog.open) return;
        document.getElementById(`exin-bf-preview-${id}`).innerHTML = (result.schedule || []).map((value, index) => `<span><small>${esc(wp.i18n.__('مرحله', 'exin-black-friday'))} ${helpers.persianDigits(index + 1)}</small>${price(value)}</span>`).join('');
    }
    async function selectFiltered() { await prepareSelection(scopeFilters()); }
    async function saveSelected() {
        const ids = [...picker.selected.keys()];
        if (!ids.length) throw new Error(labels.selectFirst);
        const filters = [];
        for (let offset = 0; offset < ids.length; offset += 1000) filters.push({ids: ids.slice(offset, offset + 1000)});
        await prepareSelection(filters[0], 'add', filters.slice(1));
    }
    function openEdit(id) {
        const item = state.items.find(row => Number(row.product_id) === Number(id));
        if (!item) return;
        const locked = state.campaign.status !== 'draft';
        openModal(wp.i18n.__('ویرایش محصول', 'exin-black-friday'), `<form id="exin-bf-edit-form" data-id="${id}"><p>${esc(item.product?.name || id)}</p><div class="exin-bf-form">${field(labels.floor, 'floor_price', displayAmount(item.floor_price), {money: true, required: true, extra: locked ? 'disabled' : '', help: cfg.currency})}${field(wp.i18n.__('اولویت عددی', 'exin-black-friday'), 'priority', item.priority || 0, {type: 'number', required: true, extra: 'min="0" max="999999"'})}<label><input type="checkbox" name="floor_override" ${item.floor_override ? 'checked' : ''} ${locked ? 'disabled' : ''}>حفظ این کف اختصاصی در تغییرات گروهی</label><label><input type="checkbox" name="featured" ${item.featured ? 'checked' : ''}>${esc(labels.featured)}</label></div><button type="submit" class="exin-bf-button exin-bf-primary">${esc(wp.i18n.__('ذخیره تغییرات', 'exin-black-friday'))}</button></form>`);
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
        if (jobBusy) throw new Error('پردازش گروهی در حال انجام است؛ نتیجه را همین‌جا دنبال کنید.');
        if (name === 'new-campaign') { await newCampaign(); return; }
        if (name === 'copy-campaign') {
            if (savePromise || savedRevision !== editRevision) await saveDraft();
            const result = await api(campaignPath('copy'), {});
            state.campaign = result.campaign; draft = hydrateDraft(result.campaign); rememberCampaign(result.campaign.id);
            editRevision = 0; savedRevision = 0; page = 1;
            if (result.job?.token) await processJob(result.job.token);
            view = 'products'; await load(); notice('نسخهٔ جدید به صورت پیش‌نویس ساخته شد؛ فعال‌سازی جداگانه لازم است.'); return;
        }
        if (name === 'campaigns-previous' || name === 'campaigns-next') { await campaignList(campaignListPage + (name === 'campaigns-next' ? 1 : -1)); return; }
        if (name === 'point-add') {
            captureSettings();
            if (draft.points.length >= 366) throw new Error('حداکثر ۳۶۶ مرحله قابل تعریف است.');
            const last = draft.points[draft.points.length - 1];
            draft.points.push({offset_minutes: integer(last?.offset_minutes || 0) + 1440, kind: 'percent', value: last?.value || '5'});
            editRevision++; render(); return;
        }
        if (name === 'point-remove') { captureSettings(); if (Number(node.dataset.index) > 0) draft.points.splice(Number(node.dataset.index), 1); editRevision++; render(); return; }
        if (name === 'step-review') { await saveDraft(true); await navigate('review'); return; }
        if (name === 'history-prev' || name === 'history-next') { historyPage += name === 'history-next' ? 1 : -1; await renderHistory(); return; }
        if (name === 'new-prev' || name === 'new-next') { pendingPage += name === 'new-next' ? 1 : -1; await loadPending(); return; }
        if (name === 'new-approve' || name === 'new-reject') { const ids = [...root.querySelectorAll('.exin-bf-new-select:checked')].map(node => Number(node.value)); if (!ids.length) throw new Error('محصولی انتخاب نشده است.'); const result = await api(campaignPath('new-products'), {ids,action:name === 'new-approve' ? 'approve' : 'reject',confirm_enroll:name === 'new-approve'}); await loadPending(); notice(result.results.some(row => !row.ok) ? 'برخی محصولات به دلیل قیمت، قواعد کف یا تداخل وارد نشدند؛ تاریخچه را بررسی کنید.' : 'بررسی محصولات ثبت شد.'); return; }
        if (name.startsWith('auto-preview-')) {
            const current = state.simulation || {}; let page = current.page || 1, stage_start = current.stage_start || 0;
            if (name === 'auto-preview-next') page++; if (name === 'auto-preview-prev') page--;
            if (name === 'auto-preview-stages') stage_start = current.next_stage || 0; if (name === 'auto-preview-first') stage_start = 0;
            state.simulation = await api(campaignPath('simulation'),{page,stage_start});render();return;
        }
        if (name === 'scope-all' || name === 'scope-instock') { await openPicker(); if (state.campaign.status === 'active') { notice('ابتدا کف گروهی یا کف اختصاصی محصولات جدید را تعیین کنید؛ سپس محدوده را پیش‌نمایش و تأیید کنید.'); return; } await prepareSelection(name === 'scope-instock' ? {stock: 'instock'} : {}); return; }
        if (name === 'picker-select-all' || name === 'picker-select-instock') { await prepareSelection(name === 'picker-select-instock' ? {stock: 'instock'} : {}); return; }
        if (name === 'picker-clear') { picker.selected.clear(); invalidateSelectionPreview(); renderPickerResults(); renderPickerSelection(); return; }
        if (name === 'selection-confirm') { await confirmSelection(); return; }
        if (name === 'selection-resume') {
            const token = storageGet(sessionStorage, jobKey()); if (!token) return;
            await processJob(token); await load(); notice('پردازش گروهی به پایان رسید.'); return;
        }
        if (name === 'selection-remove-all') {
            if (!window.confirm('تمام محصولات این کمپین حذف و قیمت‌های تحت کنترل با رعایت تعارض بازیابی شوند؟')) return;
            await requireSelection();
            const result = await processJob(null, {filters: {campaign_only: true}, policy: {mode: 'none'}, action: 'remove'});
            await load(); notice(result.failed ? 'برخی ردیف‌ها حذف نشدند؛ خطاها را بررسی کنید.' : 'انتخاب‌های کمپین حذف شدند.', !!result.failed); return;
        }
        if (['dashboard', 'settings', 'products', 'review', 'diagnostics'].includes(name)) { await navigate(name); return; }
        if (name === 'step-products') { await saveDraft(true); await navigate('products'); return; }
        if (name === 'add') { await openPicker(); return; }
        if (name === 'close-dialog') { closeModal(); return; }
        if (name === 'refresh') { await load(); return; }
        if (name === 'previous' || name === 'next') { await navigate('products', page + (name === 'next' ? 1 : -1)); return; }
        if (name === 'picker-previous' || name === 'picker-next') { await searchProducts(picker.page + (name === 'picker-next' ? 1 : -1)); return; }
        if (name === 'picker-parent-back') { picker.parent = 0; await searchProducts(1); return; }
        if (name === 'picker-select-page') { invalidateSelectionPreview(); picker.rows.forEach(product => chooseProduct(product, true)); renderPickerResults(); renderPickerSelection(); return; }
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
            await load(); notice(failures ? wp.i18n.__('برخی محصولات به علت تعارض حذف نشدند؛ وضعیت را بررسی کنید.', 'exin-black-friday') : labels.requestSaved, !!failures); return;
        }
        if (['item-remove', 'item-retry', 'item-release', 'item-pause', 'item-resume'].includes(name)) {
            const payload = {action: name === 'item-remove' ? 'remove' : name === 'item-retry' ? 'retry' : name === 'item-pause' ? 'pause' : name === 'item-resume' ? 'resume' : 'keep_external'};
            if (name === 'item-remove') { if (!window.confirm(wp.i18n.__('حذف و بازیابی امن قیمت این محصول تأیید می‌شود؟', 'exin-black-friday'))) return; payload.confirm_remove = true; }
            if (name === 'item-release') { if (!window.confirm(wp.i18n.__('قیمت بیرونی حفظ و کنترل افزونه واگذار شود؟', 'exin-black-friday'))) return; payload.confirm_release = true; }
            await api(campaignPath(`item/${node.dataset.id}`), payload);
            await load(); notice(labels.requestSaved); return;
        }
        if (name === 'activate') {
            if (!document.getElementById('exin-bf-confirm-activate')?.checked) throw new Error(labels.activation);
            await saveDraft();
            const starts = new Date(state.campaign.start_utc.replace(' ', 'T') + 'Z').getTime();
            await api(campaignPath('action'), {action: Date.now() + serverOffset >= starts ? 'start' : 'schedule', confirm_activate: true});
            view = 'dashboard'; await load(); notice(labels.requestSaved); return;
        }
        if (['start', 'schedule', 'pause', 'resume', 'restore', 'recover', 'archive'].includes(name)) {
            const payload = {action: name};
            if (name === 'archive' && !window.confirm('کمپین پس از تأیید پایان مالکیت قیمت‌ها آرشیو شود؟ تاریخچه و اطلاعات بازیابی نگه‌داری می‌شوند.')) return;
            if (['start', 'schedule'].includes(name)) { if (!window.confirm(wp.i18n.__('شروع یا زمان‌بندی این کمپین و برنامهٔ قیمت بررسی و تأیید می‌شود؟', 'exin-black-friday'))) return; payload.confirm_activate = true; }
            if (name === 'pause' && !window.confirm(wp.i18n.__('فقط تغییرات آینده متوقف شوند؟ قیمت جاری بدون بازیابی باقی می‌ماند.', 'exin-black-friday'))) return;
            if (name === 'resume' && !window.confirm(wp.i18n.__('اجرای قیمت صحیح زمان جاری از سر گرفته شود؟', 'exin-black-friday'))) return;
            if (name === 'restore') { if (!window.confirm(wp.i18n.__('کمپین متوقف و بازیابی قیمت‌های تحت کنترل آغاز شود؟ تغییرات بیرونی بازنویسی نمی‌شوند.', 'exin-black-friday'))) return; payload.confirm_restore = true; }
            await api(campaignPath('action'), payload); await load(); notice(labels.requestSaved);
        }
    }
    root.addEventListener('click', async event => {
        const nav = event.target.closest('[data-view]');
        if (nav) { try { await navigate(nav.dataset.view); } catch (error) { notice(error.message, true); } return; }
        const parent = event.target.closest('[data-picker-parent]');
        if (parent) { invalidateSelectionPreview(); picker.parent = Number(parent.dataset.pickerParent); await searchProducts(1); return; }
        const remove = event.target.closest('[data-picker-remove]');
        if (remove) { invalidateSelectionPreview(); picker.selected.delete(Number(remove.dataset.pickerRemove)); renderPickerResults(); renderPickerSelection(); return; }
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
        if (node.dataset.pickerFloor && picker) { picker.selected.get(Number(node.dataset.pickerFloor)).floor = node.value; invalidateSelectionPreview(); }
        if (node.id === 'exin-bf-floor-policy-value' && picker) { picker.floorPolicy.value = node.value; invalidateSelectionPreview(); }
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
            if (node.id === 'exin-bf-campaign-select') {
                if (jobBusy) { node.value = selectedCampaign; throw new Error('ابتدا پردازش گروهی را کامل کنید.'); }
                const next = Number(node.value);
                if (savePromise || savedRevision !== editRevision) await saveDraft();
                rememberCampaign(next); draft = null; editRevision = 0; savedRevision = 0; page = 1; view = 'dashboard'; await load(); return;
            }
            if (node.closest('#exin-bf-settings')) {
                captureSettings();
                if (node.id === 'bf-interval' && draft.discount_mode.startsWith('auto_')) draft.interval_minutes = draft.interval === 'flash' ? '5' : draft.interval === 'hourly' ? '60' : '1440';
                if (['bf-discount_mode', 'bf-interval'].includes(node.id)) { render(); helpers.attach(content); }
                scheduleDraftSave();
            }
            if (node.id === 'exin-bf-floor-policy-mode' && picker) {
                picker.floorPolicy.mode = node.value;
                const value = document.getElementById('exin-bf-floor-policy-value'); value.disabled = ['none', 'independent', 'previous'].includes(node.value);
                document.getElementById('exin-bf-floor-policy-unit').textContent = node.value === 'percent' ? '(٪)' : cfg.currency;
                invalidateSelectionPreview(); renderPickerSelection();
            }
            if (node.dataset.pickerFilter && picker) { invalidateSelectionPreview(); picker.filters[node.dataset.pickerFilter] = node.value; picker.parent = 0; await searchProducts(1); }
            if (node.dataset.pickerTaxonomy && picker) {
                const next = {...picker.filters.tax_filters};
                if (node.value) next[node.dataset.pickerTaxonomy] = node.value; else delete next[node.dataset.pickerTaxonomy];
                if (Object.keys(next).length > 5) { node.value = ''; throw new Error(wp.i18n.__('حداکثر پنج فیلتر برند یا ویژگی را هم‌زمان انتخاب کنید.', 'exin-black-friday')); }
                picker.filters.tax_filters = next; invalidateSelectionPreview(); await searchProducts(1);
            }
            if (node.dataset.pickerSelect && picker) { invalidateSelectionPreview(); chooseProduct(picker.rows.find(product => product.id === Number(node.dataset.pickerSelect)), node.checked); renderPickerSelection(); }
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
            if (form.id === 'exin-bf-operations') {
                const data = new FormData(form), payload = {...unitPayload()};
                for (const key of ['badge_enabled','discount_badge','cashback_message_enabled','include_store_products','show_filters']) payload[key] = data.has(key);
                for (const key of ['badge_text','badge_color','badge_position','public_scope','countdown_target','coupon_floor_policy','new_products','cashback_message']) payload[key] = data.get(key);
                payload.notifications = Object.fromEntries(['enabled','start','stage','error','admin_email'].map(key => [key,data.has('notify_'+key)]));
                if (data.get('new_scope') !== 'saved') payload.new_product_rules = {filters:{scope:data.get('new_scope')},policy:{mode:data.get('new_floor_mode'),value:data.get('new_floor_value') || '0'}};
                await api(campaignPath('presentation'),payload); await load(); notice('تنظیمات اجرایی ذخیره شد؛ برنامهٔ مالی تغییر نکرد.');
            }
            if (form.id === 'exin-bf-edit-form') {
                const payload = {...unitPayload(), action: 'update', priority: integer(form.elements.priority.value), featured: form.elements.featured.checked};
                if (form.elements.floor_override && !form.elements.floor_override.disabled) payload.floor_override = form.elements.floor_override.checked;
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
    dialog.addEventListener('cancel', event => { if (picker?.busy) { event.preventDefault(); return; } pickerRequest++; picker = null; modal.innerHTML = ''; });
    window.addEventListener('beforeunload', event => { if (savedRevision !== editRevision || savePromise || jobBusy) { event.preventDefault(); event.returnValue = ''; } });
    load();
})();
