/* Actual admin scripts and DOM in Chromium; REST is mocked, not a WordPress E2E claim. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '../..');
const output = path.join(root, 'work/v101/admin-browser');
fs.mkdirSync(output, {recursive: true});
const clone = value => JSON.parse(JSON.stringify(value));
const calls = [], errors = [];
let campaign = null, items = [];
const serverTime = Date.parse('2026-11-16T08:00:00Z') / 1000;
const product = (id, extra = {}) => ({id, product_id: id, name: `EXIN fixture ${id}`, sku: `EXIN-${id}`, type: 'simple', price: '1000000', stock_status: 'instock', stock_quantity: 15, in_stock: true, backorders: false, image: '', categories: [1], status: 'publish', parent_id: 0, selectable: true, has_variations: false, ...extra});
const products = [...Array.from({length: 260}, (_, index) => product(index + 1)), product(1000, {type: 'variable', selectable: false, has_variations: true}), product(1001, {type: 'variation', price: '900000', parent_id: 1000}), product(1002, {type: 'variation', parent_id: 1000, stock_status: 'outofstock', stock_quantity: 0, in_stock: false})];
const health = {php: '8.2.35', wordpress: '7.1.4', woocommerce: '11.3.0', action_scheduler: true, object_cache: true, timezone: 'Asia/Tehran', elementor: '3.34.0', shipping_methods: [{id: 11, label: 'تهران — نرخ ثابت'}], shipping_warnings: [], order_statuses: {'wc-processing': 'در حال انجام', 'wc-completed': 'تکمیل‌شده', 'wc-failed': 'ناموفق', 'wc-cancelled': 'لغوشده', 'wc-refunded': 'مستردشده', 'wc-exin-paid': 'پرداخت ویژه'}};
const normalize = text => String(text).replace(/[۰-۹٠-٩]/g, digit => String(digit.charCodeAt(0) >= 0x6f0 ? digit.charCodeAt(0) - 0x6f0 : digit.charCodeAt(0) - 0x660)).replace(/[,٬،]/g, '');
const toRial = text => (BigInt(normalize(text || '0')) * 10n).toString();
const shell = `<!doctype html><html lang="fa"><head><meta charset="utf-8"><style>body{margin:0}</style><link rel="stylesheet" href="/assets/admin.css"><link rel="stylesheet" href="/assets/admin-helpers.css"></head><body><div class="exin-bf" id="exin-bf-admin" dir="rtl"><header class="exin-bf-header"><h1>بلک فرایدی اکسین</h1><button type="button" class="exin-bf-button" data-command="add">انتخاب محصول</button></header><div id="exin-bf-draft-status"></div><nav class="exin-bf-nav"><button data-view="dashboard">نمای کلی</button><button data-view="products">محصولات</button><button data-view="settings">راه‌اندازی</button><button data-view="diagnostics">عیب‌یابی</button></nav><div id="exin-bf-message" hidden></div><main id="exin-bf-content"></main><dialog id="exin-bf-dialog" class="exin-bf-dialog"><div class="exin-bf-dialog-head"><h2 id="exin-bf-dialog-title"></h2><button type="button" data-command="close-dialog">بستن</button></div><div id="exin-bf-dialog-content"></div></dialog></div><script>window.wp={i18n:{__:(text)=>text}};window.exinBFAdmin={root:'http://exin.test/?rest_route=/exin-bf/v1/admin/',nonce:'local-mocked-rest-nonce',currency:'تومان',currency_code:'IRR',display_unit:'toman',precision:'0',default_subsidy_cap:'1500000'};</script><script src="/assets/admin-helpers.js"></script><script src="/assets/admin.js"></script></body></html>`;
(async () => {
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true, args: ['--no-sandbox']});
    const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
    let checks = 0;
    const check = (value, message) => { assert.ok(value, message); checks++; console.log('PASS ' + message); };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('http://exin.test/**', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.pathname.startsWith('/assets/')) {
            const file = path.join(root, url.pathname.slice(1));
            return route.fulfill({status: 200, contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(file, 'utf8')});
        }
        if (!url.searchParams.has('rest_route')) return route.fulfill({status: 200, contentType: 'text/html', body: shell});
        const endpoint = url.searchParams.get('rest_route').replace('/exin-bf/v1/admin/', '');
        const body = request.method() === 'POST' ? request.postDataJSON() : null;
        calls.push({endpoint, method: request.method(), body: clone(body), query: Object.fromEntries(url.searchParams)});
        let result;
        if (endpoint === 'state') result = {campaign: clone(campaign), items: clone(items.slice((Number(url.searchParams.get('page') || 1) - 1) * 25, Number(url.searchParams.get('page') || 1) * 25)), count: items.length, page: 1, server_time: serverTime, health, logs: [], analytics: {orders: 3, units: 4, item_revenue: '1200000', order_revenue: '1600000', complete: true, top_products: [], recent_orders: []}};
        else if (endpoint === 'product-filters') result = {categories: [{id: 1, name: 'گوشی', slug: 'phones'}], taxonomies: [{name: 'product_brand', label: 'برند', kind: 'brand', terms: [{id: 10, name: 'EXIN', slug: 'exin'}]}, {name: 'pa_color', label: 'رنگ', kind: 'attribute', terms: [{id: 11, name: 'آبی', slug: 'blue'}]}]};
        else if (endpoint === 'products') {
            const parent = Number(url.searchParams.get('parent_id')), type = url.searchParams.get('type'), term = url.searchParams.get('search') || '';
            let matches = products.filter(value => parent ? value.parent_id === parent : value.parent_id === 0);
            if (!parent && type !== 'all') matches = matches.filter(value => value.type === type);
            if (term) matches = matches.filter(value => value.name.includes(term) || value.sku.includes(term));
            if (url.searchParams.get('stock') === 'instock') matches = matches.filter(value => value.in_stock);
            const cursor = Number(url.searchParams.get('page') || 1);
            result = {products: matches.slice((cursor - 1) * 20, cursor * 20).map(value => ({...value, included: items.some(item => item.product_id === value.id)})), total: matches.length, page: cursor, pages: Math.ceil(matches.length / 20), has_more: cursor * 20 < matches.length};
        } else if (endpoint === 'campaign') {
            const settings = {...body.settings, currency: 'IRR', precision: 0, shipping_threshold: toRial(body.settings.shipping_threshold), shipping_subsidy_cap: toRial(body.settings.shipping_subsidy_cap), order_statuses: campaign?.settings.order_statuses || ['processing', 'completed']};
            campaign = {...body, id: body.id || 1, settings, status: 'draft', version: (campaign?.version || 0) + 1, start_utc: '2026-11-27 20:29:00', end_utc: '2026-12-04 20:29:00', last_success: null, last_error: ''};
            result = {campaign: clone(campaign)};
        } else if (endpoint.endsWith('/preview')) result = {schedule: ['900000', '833333', '766666', '700000', '633333', '566666', toRial(body.floor_price)]};
        else if (endpoint.endsWith('/bulk')) {
            result = {results: body.items.map(row => {
                if (body.action === 'add') {
                    const source = products.find(value => value.id === row.product_id);
                    items.push({campaign_id: campaign.id, product_id: row.product_id, base_price: source.price, floor_price: toRial(row.floor_price), schedule: [], snapshot: {}, expected: null, intent: null, status: 'included', priority: 0, featured: 0, product: {...source, stock: source.stock_quantity}});
                } else items = items.filter(value => value.product_id !== row.product_id);
                return {product_id: row.product_id, ok: true};
            })};
        } else if (endpoint.includes('/item/')) {
            const item = items.find(value => value.product_id === Number(endpoint.split('/').pop()));
            if (body.floor_price) item.floor_price = toRial(body.floor_price);
            if (Object.hasOwn(body, 'featured')) item.featured = body.featured ? 1 : 0;
            if (Object.hasOwn(body, 'priority')) item.priority = body.priority;
            result = {item};
        } else if (endpoint.endsWith('/reorder')) {
            body.items.forEach(row => { items.find(value => value.product_id === row.product_id).priority = row.priority; });
            items.sort((left, right) => right.featured - left.featured || left.priority - right.priority || left.product_id - right.product_id);
            result = {updated: body.items.length};
        } else if (endpoint.endsWith('/report-settings')) { campaign.settings.order_statuses = body.order_statuses; result = {order_statuses: body.order_statuses}; }
        else if (endpoint.endsWith('/action')) { campaign.status = body.action === 'schedule' ? 'scheduled' : body.action === 'pause' ? 'paused' : 'active'; result = {campaign}; }
        else throw new Error('Unexpected mocked REST endpoint: ' + endpoint);
        return route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify(result)});
    });
    try {
        await page.goto('http://exin.test/wp-admin/');
        await page.locator('#exin-bf-settings').waitFor();
        check(await page.locator('[data-step]').count() === 3, 'Setup presents exactly three clear steps');
        check(await page.locator('#bf-start_jalali').inputValue() === '۱۴۰۵/۰۹/۰۶', 'Date field displays Jalali Persian digits');
        check(await page.locator('#bf-start_time').getAttribute('type') === 'text', 'Clock uses explicit24-hour text rather than native AM/PM UI');
        check(await page.locator('#bf-shipping_subsidy_cap').inputValue() === '150,000', 'IRR default1500000 appears as150000Toman with grouping');
        check(!(await page.locator('body').innerText()).includes('هدف سفارش'), 'Order-goal setting is absent');
        await page.locator('.exin-bf-calendar-toggle').click();
        await page.locator('[data-jalali-date="1405/09/07"]').click();
        await page.locator('#bf-title').fill('کمپین آزمایشی پایدار');
        await page.locator('#bf-start_time').fill('۲۳:۵۹');
        await page.locator('#exin-bf-settings summary').click();
        await page.locator('#bf-shipping_threshold').fill('۱۲۳۴۵');
        check(await page.locator('#bf-shipping_threshold').inputValue() === '12,345', 'Persian amount input groups while typing');
        await page.locator('[name="free_shipping"]').check();
        await page.locator('[name="shipping_instance"]').check();
        await page.locator('[data-view="products"]').click();
        await page.locator('.exin-bf-table').waitFor();
        const saved = calls.filter(value => value.endpoint === 'campaign').at(-1).body;
        check(saved.amount_unit === 'toman' && saved.settings.shipping_subsidy_cap === '150,000', 'Financial payload declares Toman without client-side double conversion');
        check(saved.start_time === '23:59' && saved.start_jalali === '۱۴۰۵/۰۹/۰۷', 'Jalali selection and Persian24-hour time serialize correctly');
        check(saved.settings.shipping_threshold === '12,345', 'Server receives grouped user input for authoritative normalization');
        check(calls.every(value => !value.endpoint.endsWith('/action')), 'Draft autosave/navigation never sends a financial activation action');
        await page.locator('[data-view="settings"]').click();
        await page.locator('#exin-bf-settings').waitFor();
        check(await page.locator('#bf-title').inputValue() === 'کمپین آزمایشی پایدار', 'Draft data survives switching tabs');
        await page.locator('#bf-title').fill('پیش‌نویس با ذخیره خودکار');
        await page.waitForFunction(() => document.getElementById('exin-bf-draft-status').dataset.state === 'saved');
        check(campaign.title === 'پیش‌نویس با ذخیره خودکار', 'Debounced autosave persists edited title without submit');
        await page.locator('header [data-command="add"]').click();
        await page.locator('[data-picker-select="1"]').waitFor();
        check(await page.locator('[data-picker-filter]').count() >= 3, 'Product picker has server-backed category/stock/type filters');
        check(await page.locator('[data-picker-taxonomy]').count() === 2, 'Registered brand and attribute selectors appear');
        await page.locator('[data-picker-taxonomy="product_brand"]').selectOption('exin');
        await Promise.all([
            page.waitForResponse(response => response.url().includes('tax_filters') && decodeURIComponent(response.url()).includes('blue')),
            page.locator('[data-picker-taxonomy="pa_color"]').selectOption('blue')
        ]);
        const catalogCall = calls.filter(value => value.endpoint === 'products').at(-1);
        check(JSON.parse(catalogCall.query.tax_filters).product_brand === 'exin' && JSON.parse(catalogCall.query.tax_filters).pa_color === 'blue', 'Combined brand and attribute filters are sent as bounded map');
        await page.locator('[data-picker-select="1"]').check();
        await page.locator('[data-picker-select="2"]').check();
        await page.locator('[data-picker-floor="1"]').fill('۴۰٬۰۰۰');
        await page.locator('[data-picker-floor="2"]').fill('60,000');
        await page.locator('[data-picker-filter="type"]').selectOption('variable');
        await page.locator('[data-picker-parent="1000"]').waitFor();
        check(await page.locator('[data-picker-select="1000"]').isDisabled(), 'Variable parent is navigation-only and cannot be enrolled financially');
        await page.locator('[data-picker-parent="1000"]').click();
        await page.locator('[data-picker-select="1001"]').check();
        await page.locator('[data-picker-floor="1001"]').fill('۵۰٬۰۰۰');
        await page.locator('[data-command="picker-preview"][data-id="1001"]').click();
        await page.locator('#exin-bf-preview-1001 > span').nth(6).waitFor();
        check(await page.locator('#exin-bf-preview-1001 > span').count() === 7, 'Each selected variation has seven-day private preview');
        check((await page.locator('#exin-bf-preview-1001 > span').nth(6).innerText()).includes('50,000'), 'Preview displays exact grouped Toman floor');
        await page.locator('[data-command="picker-save"]').click();
        await page.locator('[data-product-row="1001"]').waitFor();
        check(items.length === 3 && items.find(value => value.product_id === 1001).floor_price === '500000', 'Bulk selection preserves independent variation floor in canonical mocked store units');
        check(calls.filter(value => value.endpoint.endsWith('/bulk')).every(value => value.body.items.length <= 25), 'All enrollment requests are bounded to25products');
        check(calls.every(value => !value.endpoint.endsWith('/action')), 'Selection and previews do not activate campaign');
        await page.locator('header [data-command="add"]').click();
        await page.locator('[data-picker-select="1"]').waitFor();
        check(await page.locator('[data-picker-select="1"]').isDisabled(), 'Server membership marks previously selected items across sessions');
        check((await page.locator('[data-command="picker-select-filtered"]').innerText()).includes('۲۰۰'), 'Filtered group-selection button explicitly states its200 limit');
        await page.locator('[data-command="picker-select-filtered"]').click();
        await page.waitForFunction(() => document.querySelectorAll('[data-picker-floor]').length === 200);
        check(await page.locator('[data-picker-floor]').count() === 200, 'Filtered selection is bounded to200 un-enrolled products');
        await page.locator('#bf-common_floor').fill('40,000');
        await page.locator('[data-command="picker-apply-floor"]').click();
        await page.locator('[data-command="picker-save"]').click();
        await page.locator('dialog[open]').waitFor({state: 'hidden'});
        await page.locator('.exin-bf-table').waitFor();
        check(items.length === 203, 'Two hundred products save in bounded batches without duplicating existing members');
        check(calls.filter(value => value.endpoint.endsWith('/bulk')).every(value => value.body.items.length <=25), 'Large group selection never exceeds25 financial rows per request');
        await page.locator('header [data-command="add"]').click();
        await page.locator('[data-picker-select="1"]').waitFor();
        await page.locator('[data-command="picker-select-filtered"]').click();
        await page.waitForFunction(() => document.querySelectorAll('[data-picker-floor]').length === 58);
        check(await page.locator('[data-picker-floor]').count() === 58, 'Next selection continues beyond the first200 members rather than reselecting them');
        await page.locator('[data-command="close-dialog"]').first().click();
        // A large existing campaign must not trap filtered enrollment in its first500 rows.
        for (let index = 0; index < 605; index++) {
            const source = product(2000 + index, {name: `SCAN RESUME ${index}`, sku: `SCAN-${index}`});
            products.push(source);
            if (index < 600) items.push({campaign_id: campaign.id, product_id: source.id, base_price: source.price, floor_price: '400000', status: 'included', priority: 0, featured: 0, product: {...source, stock: source.stock_quantity}});
        }
        await page.locator('header [data-command="add"]').click();
        await page.locator('#exin-bf-product-search').fill('SCAN');
        await page.locator('[data-picker-select="2000"]').waitFor();
        const beforeScan = calls.filter(value => value.endpoint === 'products').length;
        await page.locator('[data-command="picker-select-filtered"]').click();
        await page.waitForFunction(() => document.getElementById('exin-bf-message').textContent.includes('۵۰۰'));
        check(calls.filter(value => value.endpoint === 'products').length - beforeScan === 25, 'Existing-member scan is bounded to25catalog pages per click');
        check(await page.locator('[data-picker-floor]').count() === 0 && (await page.locator('#exin-bf-message').innerText()).includes('دوباره'), 'Bounded scan explicitly explains how to continue after500 existing members');
        await page.locator('[data-command="picker-select-filtered"]').click();
        await page.waitForFunction(() => document.querySelectorAll('[data-picker-floor]').length === 5);
        check(await page.locator('[data-picker-floor]').count() === 5, 'Next bounded scan reaches new products beyond600 existing members');
        await page.locator('[data-command="close-dialog"]').first().click();
        await page.locator('header [data-command="add"]').click();
        await page.locator('#exin-bf-product-search').fill('SCAN');
        await page.locator('[data-picker-select="2000"]').waitFor();
        await page.locator('[data-command="picker-select-filtered"]').click();
        await page.waitForFunction(() => document.querySelectorAll('[data-picker-floor]').length === 5);
        check(await page.locator('[data-picker-floor]').count() === 5, 'Cancelling unsaved selection does not skip its products on reopening');
        await page.locator('[data-command="close-dialog"]').first().click();
        await page.locator('[data-featured="1"]').check();
        await page.locator('[data-product-row="1"][draggable="true"]').waitFor();
        await page.locator('[data-featured="2"]').check();
        await page.locator('[data-product-row="2"][draggable="true"]').waitFor();
        await Promise.all([page.waitForResponse(response => response.url().includes('reorder')), page.locator('[data-command="move-up"][data-id="2"]').click()]);
        await page.waitForFunction(() => !document.getElementById('exin-bf-content').matches('[aria-busy="true"]'));
        const reorder = calls.filter(value => value.endpoint.endsWith('/reorder')).at(-1);
        check(reorder.body.items[0].product_id === 2, 'Accessible featured-order control sends deterministic manual order');
        await page.locator('[data-view="diagnostics"]').click();
        await page.locator('#exin-bf-report-settings').waitFor();
        check(await page.locator('[name="order_status"][value="exin-paid"]').count() === 1, 'Custom registered order statuses are offered in diagnostics');
        check(await page.locator('[name="order_status"][value="refunded"]').count() === 0, 'Refunded status cannot be selected as active sales');
        await page.locator('[name="order_status"][value="exin-paid"]').check();
        await Promise.all([
            page.waitForResponse(response => response.url().includes('report-settings')),
            page.locator('#exin-bf-report-settings button[type="submit"]').click()
        ]);
        check(campaign.settings.order_statuses.includes('exin-paid'), 'Reporting policy saves through its separate endpoint');
        await page.locator('[data-view="products"]').click();
        await page.locator('[data-step="3"]').click();
        await page.locator('#exin-bf-confirm-activate').waitFor();
        check(await page.locator('#exin-bf-activate').isDisabled(), 'Review cannot activate before explicit manager confirmation');
        await page.locator('#exin-bf-confirm-activate').check();
        await page.locator('#exin-bf-activate').click();
        await page.locator('.exin-bf-hero [data-status="scheduled"]').waitFor();
        const activation = calls.find(value => value.endpoint.endsWith('/action'));
        check(activation.body.action === 'schedule' && activation.body.confirm_activate === true, 'Only explicit review confirmation schedules the campaign');
        check(calls.filter(value => value.endpoint.endsWith('/action')).length === 1, 'No duplicate or implicit activation requests');
        await page.setViewportSize({width: 390, height: 844});
        await page.locator('[data-view="products"]').click();
        await page.locator('[data-command="item-edit"][data-id="1"]').click();
        await page.locator('dialog[open]').waitFor();
        check(await page.locator('#bf-floor_price').isDisabled(), 'Scheduled campaign keeps financial floor locked');
        check(await page.locator('dialog[open]').evaluate(element => element.getBoundingClientRect().width <=390), 'Edit dialog fits390px viewport');
        await page.locator('[data-command="close-dialog"]').first().click();
        check(await page.locator('body').evaluate(element => element.scrollWidth <=390), 'Mobile admin has no page-level horizontal overflow');
        check(errors.length === 0, 'No JavaScript exceptions through the admin workflow');
        const report = {suite: 'v101-admin-browser-mocked-rest', boundary: 'Actual JS/helpers/DOM in Chromium with mocked REST; not a real WordPress checkout or server integration test.', checks, passed: checks, failed: 0, viewport: [[1440,1000],[390,844]], errors};
        fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify(report, null, 2) + '\n');
        console.log('EXIN_ADMIN_BROWSER_RESULT ' + JSON.stringify(report));
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
