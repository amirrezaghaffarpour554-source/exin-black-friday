/* Shared Persian calendar and exact amount editing for EXIN administration. */
(function (global) {
    'use strict';
    const __ = global.wp && global.wp.i18n ? global.wp.i18n.__ : (text) => text;
    const MAX_MINOR = 9223372036854775807n;
    const BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const DIGITS = '۰۱۲۳۴۵۶۷۸۹';
    const calendarInstances = new Set();
    const calendarBindings = new WeakMap();
    let documentEventsBound = false;
    function normalizeDigits(value) {
        return String(value ?? '').replace(/[۰-۹٠-٩]/g, (digit) => String(digit.charCodeAt(0) >= 0x06f0 ? digit.charCodeAt(0) - 0x06f0 : digit.charCodeAt(0) - 0x0660));
    }
    function persianDigits(value) { return String(value).replace(/[0-9]/g, (digit) => DIGITS[Number(digit)]); }
    function canonicalInput(value) {
        return normalizeDigits(value).trim().replace(/[٬،\u00a0\u202f\u2009]/g, ',').replace(/٫/g, '.');
    }
    function normalizeAmount(value, precision) {
        if (!Number.isInteger(precision) || precision < 0 || precision > 9 || String(value).length > 256) throw new Error('invalid_money_precision_or_length');
        let text = canonicalInput(value);
        const pattern = text.includes(',') ? /^[0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]+)?$/ : /^[0-9]+(?:\.[0-9]+)?$/;
        if (!pattern.test(text)) throw new Error('invalid_money_input');
        text = text.replace(/,/g, '');
        const [integer, decimal = ''] = text.split('.');
        if (decimal.length > precision) throw new Error('money_precision_exceeded');
        const whole = integer.replace(/^0+(?=\d)/, '');
        const fraction = decimal.padEnd(precision, '0');
        if (BigInt(whole + fraction) > MAX_MINOR) throw new Error('money_input_overflow');
        return whole + (precision ? '.' + fraction : '');
    }
    function formatAmount(value) {
        const normalized = canonicalInput(value);
        const plain = normalized.replace(/,/g, '');
        if (!/^-?[0-9]*(?:\.[0-9]*)?$/.test(plain)) return normalized;
        if (!plain || plain === '-') return plain;
        const negative = plain.startsWith('-');
        const unsigned = negative ? plain.slice(1) : plain;
        const [whole = '', fraction] = unsigned.split('.');
        const grouped = (whole || (fraction !== undefined ? '0' : '')).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return (negative ? '-' : '') + grouped + (fraction === undefined ? '' : '.' + fraction);
    }
    function validatePrecision(precision) {
        if (!Number.isInteger(precision) || precision < 0 || precision > 8) throw new Error('invalid_store_precision');
    }
    function isToman(currency) { return ['IRR', 'IRT', 'TMN'].includes(String(currency).toUpperCase()); }
    function displayPrecision(currency, precision) {
        validatePrecision(precision);
        return precision + (String(currency).toUpperCase() === 'IRR' ? 1 : 0);
    }
    function minorToDecimal(minor, precision, trim) {
        const negative = minor < 0n;
        let digits = String(negative ? -minor : minor).padStart(precision + 1, '0');
        let text = precision ? digits.slice(0, -precision) + '.' + digits.slice(-precision) : digits;
        if (trim && text.includes('.')) text = text.replace(/0+$/, '').replace(/\.$/, '');
        return (negative && text !== '0' ? '-' : '') + text;
    }
    function toStore(amount, unit, currency, precision) {
        validatePrecision(precision);
        if (!['store', 'toman'].includes(unit) || (unit === 'toman' && !isToman(currency))) throw new Error('unsupported_amount_unit');
        const amountPrecision = precision + (unit === 'toman' && String(currency).toUpperCase() === 'IRR' ? 1 : 0);
        const normalized = normalizeAmount(amount, amountPrecision);
        const minor = BigInt(normalized.replace('.', ''));
        return minorToDecimal(minor, precision, false);
    }
    function fromStore(amount, currency, precision) {
        validatePrecision(precision);
        const text = String(amount);
        if (!/^-?[0-9]+(?:\.[0-9]+)?$/.test(text)) throw new Error('invalid_store_money');
        const negative = text.startsWith('-');
        const normalized = normalizeAmount(negative ? text.slice(1) : text, precision);
        const minor = BigInt(normalized.replace('.', '')) * (negative ? -1n : 1n);
        return minorToDecimal(minor, displayPrecision(currency, precision), true);
    }
    function cursorPosition(text, significant) {
        if (significant <= 0) return 0;
        let seen = 0;
        for (let i = 0; i < text.length; i++) {
            if (text[i] !== ',') seen++;
            if (seen === significant) return i + 1;
        }
        return text.length;
    }
    function bindAmountInput(input) {
        if (!input || input.dataset.exinAmountBound) return input;
        input.dataset.exinAmountBound = '1';
        input.type = 'text';
        input.inputMode = 'decimal';
        input.dir = 'ltr';
        const update = (finish) => {
            const old = input.value;
            const selectionStart = input.selectionStart ?? old.length;
            const selectionEnd = input.selectionEnd ?? selectionStart;
            const significantBefore = canonicalInput(old.slice(0, selectionStart)).replace(/,/g, '').length;
            const significantEnd = canonicalInput(old.slice(0, selectionEnd)).replace(/,/g, '').length;
            input.value = formatAmount(finish ? old.replace(/[.٫]$/, '') : old);
            if (global.document && global.document.activeElement === input) input.setSelectionRange(cursorPosition(input.value, significantBefore), cursorPosition(input.value, significantEnd));
        };
        input.addEventListener('input', () => update(false));
        input.addEventListener('blur', () => update(true));
        update(false);
        return input;
    }
    function validateYear(year) {
        if (!Number.isInteger(year) || year < 1200 || year > 1600) throw new Error('unsupported_jalali_year');
    }
    function yearInfo(year) {
        if (!Number.isInteger(year) || year < 1 || year >= 3178) throw new Error('unsupported_jalali_year');
        const gregorianYear = year + 621;
        let leapJalali = -14;
        let previous = BREAKS[0];
        let jump = 0;
        for (let i = 1; i < BREAKS.length; i++) {
            const next = BREAKS[i];
            jump = next - previous;
            if (year < next) break;
            leapJalali += Math.trunc(jump / 33) * 8 + Math.trunc((jump % 33) / 4);
            previous = next;
        }
        let offset = year - previous;
        leapJalali += Math.trunc(offset / 33) * 8 + Math.trunc(((offset % 33) + 3) / 4);
        if (jump % 33 === 4 && jump - offset === 4) leapJalali++;
        const leapGregorian = Math.trunc(gregorianYear / 4) - Math.trunc(((Math.trunc(gregorianYear / 100) + 1) * 3) / 4) - 150;
        const march = 20 + leapJalali - leapGregorian;
        if (jump - offset < 6) offset = offset - jump + Math.trunc((jump + 4) / 33) * 33;
        let leap = ((offset + 1) % 33 - 1) % 4;
        if (leap === -1) leap = 4;
        return {gregorianYear, march, leap};
    }
    function isLeap(year) { validateYear(year); return yearInfo(year).leap === 0; }
    function monthLength(year, month) {
        validateYear(year);
        if (!Number.isInteger(month) || month < 1 || month > 12) throw new Error('invalid_jalali_month');
        return month <= 6 ? 31 : month <= 11 || isLeap(year) ? 30 : 29;
    }
    function parseJalali(value) {
        const match = normalizeDigits(value).trim().match(/^([0-9]{1,4})[/-]([0-9]{1,2})[/-]([0-9]{1,2})$/);
        if (!match) throw new Error('invalid_jalali_date');
        const [, yearText, monthText, dayText] = match;
        const year = Number(yearText), month = Number(monthText), day = Number(dayText);
        if (day < 1 || day > monthLength(year, month)) throw new Error('invalid_jalali_day');
        return {year, month, day};
    }
    function gregorianDate(year, month, day) {
        const date = new Date(Date.UTC(year, month - 1, day));
        if (date.getUTCFullYear() !== year || date.getUTCMonth() + 1 !== month || date.getUTCDate() !== day) throw new Error('invalid_gregorian_date');
        return date;
    }
    function dateText(date, separator = '/') { return `${String(date.year).padStart(4, '0')}${separator}${String(date.month).padStart(2, '0')}${separator}${String(date.day).padStart(2, '0')}`; }
    function toGregorian(value) {
        const {year, month, day} = parseJalali(value);
        const info = yearInfo(year);
        const date = gregorianDate(info.gregorianYear, 3, info.march);
        const offset = (month <= 7 ? (month - 1) * 31 : (month - 1) * 30 + 6) + day - 1;
        date.setUTCDate(date.getUTCDate() + offset);
        return dateText({year: date.getUTCFullYear(), month: date.getUTCMonth() + 1, day: date.getUTCDate()}, '-');
    }
    function toJalali(value) {
        const match = String(value).match(/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/);
        if (!match) throw new Error('invalid_gregorian_date');
        const year = Number(match[1]), month = Number(match[2]), day = Number(match[3]);
        const date = gregorianDate(year, month, day);
        let jalaliYear = year - 621;
        let info = yearInfo(jalaliYear);
        let start = gregorianDate(year, 3, info.march);
        if (date < start) {
            jalaliYear--;
            info = yearInfo(jalaliYear);
            start = gregorianDate(year - 1, 3, info.march);
        }
        validateYear(jalaliYear);
        const offset = Math.round((date - start) / 86400000);
        return dateText({year: jalaliYear, month: offset < 186 ? Math.trunc(offset / 31) + 1 : Math.trunc((offset - 186) / 30) + 7, day: offset < 186 ? offset % 31 + 1 : (offset - 186) % 30 + 1});
    }
    function today(timezone) {
        const parts = new Intl.DateTimeFormat('en-CA', {timeZone: timezone || 'Asia/Tehran', year: 'numeric', month: '2-digit', day: '2-digit'}).formatToParts(new Date());
        const fields = Object.fromEntries(parts.map((part) => [part.type, part.value]));
        return toJalali(`${fields.year}-${fields.month}-${fields.day}`);
    }
    function closeDisconnected() {
        for (const instance of calendarInstances) {
            if (!instance.input.isConnected) {
                instance.close(false);
                calendarInstances.delete(instance);
            }
        }
    }
    function mountDatePicker(input, options = {}) {
        if (!input || !global.document) return null;
        if (calendarBindings.has(input)) return calendarBindings.get(input);
        const document = global.document;
        input.type = 'text';
        input.dir = 'ltr';
        input.autocomplete = 'off';
        input.placeholder = '۱۴۰۵/۰۹/۰۶';
        if (options.value !== undefined) input.value = persianDigits(options.value);
        const parent = input.parentElement;
        parent.classList.add('exin-bf-calendar-field');
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'exin-bf-calendar-toggle';
        toggle.textContent = __('انتخاب از تقویم شمسی', 'exin-black-friday');
        toggle.setAttribute('aria-haspopup', 'dialog');
        toggle.setAttribute('aria-expanded', 'false');
        const panel = document.createElement('div');
        panel.className = 'exin-bf-calendar';
        panel.hidden = true;
        panel.dir = 'rtl';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', __('تقویم شمسی', 'exin-black-friday'));
        parent.append(toggle, panel);
        let viewed;
        const currentSelection = () => { try { return parseJalali(input.value); } catch (_) { return null; } };
        function close(returnFocus = true) {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            if (returnFocus && input.isConnected) input.focus();
        }
        function select(date) {
            const ascii = dateText(date);
            input.value = persianDigits(ascii);
            input.dispatchEvent(new Event('input', {bubbles: true}));
            input.dispatchEvent(new Event('change', {bubbles: true}));
            if (typeof options.onChange === 'function') options.onChange(ascii);
            close();
        }
        function moveMonth(direction) {
            let month = viewed.month + direction, year = viewed.year;
            if (month === 0) { month = 12; year--; }
            if (month === 13) { month = 1; year++; }
            if (year < 1200 || year > 1600) return;
            viewed = {year, month, day: 1};
            render();
        }
        function render(focusDay) {
            panel.replaceChildren();
            const header = document.createElement('div');
            header.className = 'exin-bf-calendar-header';
            const previous = document.createElement('button');
            previous.type = 'button';
            previous.textContent = '‹';
            previous.setAttribute('aria-label', __('ماه قبل', 'exin-black-friday'));
            previous.disabled = viewed.year === 1200 && viewed.month === 1;
            previous.addEventListener('click', () => moveMonth(-1));
            const heading = document.createElement('strong');
            heading.textContent = MONTHS[viewed.month - 1] + ' ' + persianDigits(viewed.year);
            heading.setAttribute('aria-live', 'polite');
            const next = document.createElement('button');
            next.type = 'button';
            next.textContent = '›';
            next.setAttribute('aria-label', __('ماه بعد', 'exin-black-friday'));
            next.disabled = viewed.year === 1600 && viewed.month === 12;
            next.addEventListener('click', () => moveMonth(1));
            header.append(previous, heading, next);
            panel.append(header);
            const grid = document.createElement('div');
            grid.className = 'exin-bf-calendar-grid';
            grid.setAttribute('role', 'grid');
            const weekdayNames = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
            for (const name of weekdayNames) {
                const label = document.createElement('span');
                label.className = 'exin-bf-calendar-weekday';
                label.textContent = name[0];
                label.setAttribute('title', name);
                label.setAttribute('role', 'columnheader');
                grid.append(label);
            }
            const firstGregorian = toGregorian(dateText({...viewed, day: 1}));
            const firstDate = new Date(firstGregorian + 'T00:00:00Z');
            const leading = (firstDate.getUTCDay() + 1) % 7;
            for (let i = 0; i < leading; i++) {
                const blank = document.createElement('span');
                blank.setAttribute('aria-hidden', 'true');
                grid.append(blank);
            }
            const selected = currentSelection();
            for (let day = 1; day <= monthLength(viewed.year, viewed.month); day++) {
                const date = {...viewed, day};
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = persianDigits(day);
                button.dataset.jalaliDate = dateText(date);
                button.setAttribute('aria-label', persianDigits(dateText(date)));
                button.setAttribute('role', 'gridcell');
                button.setAttribute('aria-selected', String(Boolean(selected && selected.year === date.year && selected.month === date.month && selected.day === day)));
                button.addEventListener('click', () => select(date));
                button.addEventListener('keydown', (event) => {
                    const offsets = {ArrowLeft: 1, ArrowRight: -1, ArrowUp: -7, ArrowDown: 7};
                    if (!Object.prototype.hasOwnProperty.call(offsets, event.key)) return;
                    event.preventDefault();
                    const target = new Date(toGregorian(dateText(date)) + 'T00:00:00Z');
                    target.setUTCDate(target.getUTCDate() + offsets[event.key]);
                    try {
                        const moved = parseJalali(toJalali(dateText({year: target.getUTCFullYear(), month: target.getUTCMonth() + 1, day: target.getUTCDate()}, '-')));
                        viewed = moved;
                        render(moved.day);
                    } catch (_) { /* The supported calendar range bounds keyboard navigation. */ }
                });
                grid.append(button);
            }
            panel.append(grid);
            const closeButton = document.createElement('button');
            closeButton.type = 'button';
            closeButton.className = 'exin-bf-calendar-close';
            closeButton.textContent = __('بستن تقویم', 'exin-black-friday');
            closeButton.addEventListener('click', () => close());
            panel.append(closeButton);
            if (focusDay) panel.querySelector(`[data-jalali-date="${dateText({...viewed, day: focusDay})}"]`)?.focus();
        }
        function open() {
            for (const instance of calendarInstances) if (instance.input !== input) instance.close(false);
            viewed = currentSelection() || parseJalali(today(options.timezone));
            render();
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            // Start keyboard navigation inside the calendar, including Escape.
            (panel.querySelector('[data-jalali-date][aria-selected="true"]') || panel.querySelector('[data-jalali-date]'))?.focus();
        }
        toggle.addEventListener('click', () => { if (panel.hidden) open(); else close(); });
        input.addEventListener('change', () => { const date = currentSelection(); if (date) input.value = persianDigits(dateText(date)); });
        panel.addEventListener('keydown', (event) => { if (event.key === 'Escape') { event.preventDefault(); close(); } });
        const instance = {input, parent, panel, open, close};
        calendarInstances.add(instance);
        calendarBindings.set(input, instance);
        if (!documentEventsBound) {
            documentEventsBound = true;
            document.addEventListener('pointerdown', (event) => {
                closeDisconnected();
                for (const bound of calendarInstances) if (!bound.panel.hidden && !bound.parent.contains(event.target)) bound.close(false);
            });
        }
        return instance;
    }
    function attach(root = global.document) {
        if (!root) return;
        closeDisconnected();
        root.querySelectorAll('input[data-exin-money]').forEach(bindAmountInput);
        root.querySelectorAll('input[data-exin-jalali]').forEach((input) => mountDatePicker(input, {timezone: input.dataset.timezone || 'Asia/Tehran'}));
    }
    global.ExinBFHelpers = Object.freeze({normalizeDigits, persianDigits, normalizeAmount, formatAmount, bindAmountInput, isToman, displayPrecision, fromStore, toStore, isLeap, monthLength, parseJalali, toGregorian, toJalali, mountDatePicker, attach});
})(globalThis);
