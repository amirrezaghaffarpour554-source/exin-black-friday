/* Browser helper arithmetic tested against independent ICU and BigInt references. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const project = path.resolve(__dirname, '../..');
const context = vm.createContext({});
vm.runInContext(fs.readFileSync(path.join(project, 'assets/admin-helpers.js'), 'utf8'), context);
const helpers = context.ExinBFHelpers;
const reference = JSON.parse(fs.readFileSync(path.join(__dirname, 'jalali-reference.json'), 'utf8'));
let checks = 0;
let cases = 0;
const failures = [];
function equal(actual, expected, label) {
    checks++;
    assert.equal(actual, expected, label);
}
function rejects(callback, label) {
    checks++;
    assert.throws(callback, undefined, label);
}
function test(name, callback) {
    cases++;
    try {
        callback();
        console.log('PASS ' + name);
    } catch (error) {
        failures.push(name + ': ' + error.message);
        console.error('FAIL ' + failures.at(-1));
    }
}
const ymd = (y, m, d, separator = '/') => [String(y).padStart(4, '0'), String(m).padStart(2, '0'), String(d).padStart(2, '0')].join(separator);

test('JavaScript calendar agrees with all independent ICU vectors in both directions', () => {
    for (const [gy, gm, gd, jy, jm, jd] of reference.vectors) {
        equal(helpers.toJalali(ymd(gy, gm, gd, '-')), ymd(jy, jm, jd), 'ICU Gregorian->Jalali');
        equal(helpers.toGregorian(ymd(jy, jm, jd)), ymd(gy, gm, gd, '-'), 'ICU Jalali->Gregorian');
    }
});
test('Target date and Persian/Arabic digits are exact', () => {
    equal(helpers.toGregorian('۱۴۰۵/۰۹/۰۶'), '2026-11-27');
    equal(helpers.toGregorian('١٤٠٥/٠٩/٠٦'), '2026-11-27');
    equal(helpers.toJalali('2026-11-27'), '1405/09/06');
    equal(helpers.persianDigits('1405/09/06'), '۱۴۰۵/۰۹/۰۶');
    equal(helpers.normalizeDigits('۱۴۰۵/٠٩/۰۶'), '1405/09/06');
});
test('Calendar month edges round-trip through the whole supported range', () => {
    for (let year = 1200; year <= 1600; year += 4) {
        for (let month = 1; month <= 12; month++) {
            for (const day of [1, helpers.monthLength(year, month)]) {
                const date = ymd(year, month, day);
                equal(helpers.toJalali(helpers.toGregorian(date)), date);
            }
        }
    }
});
test('Invalid dates and unsupported years are rejected', () => {
    equal(helpers.isLeap(1403), true);
    equal(helpers.isLeap(1404), false);
    equal(helpers.toGregorian('1403/12/30'), '2025-03-20');
    for (const date of ['1404/12/30', '1405/07/31', '1405/00/01', '1405/01/00', '1199/12/29', '1601/01/01', '<b>1405/09/06</b>']) {
        rejects(() => helpers.toGregorian(date), date);
    }
    for (const date of ['2025-02-29', '2026-13-01', '2026-11-00', '2026/11/27']) rejects(() => helpers.toJalali(date), date);
});
test('Monetary input supports localized digits and validates grouping', () => {
    equal(helpers.normalizeAmount('۱٬۲۳۴٬۵۶۷', 0), '1234567');
    equal(helpers.normalizeAmount('١٬٢٣٤٫٥٠', 2), '1234.50');
    equal(helpers.normalizeAmount('1,234.5', 2), '1234.50');
    equal(helpers.normalizeAmount('0', 2), '0.00');
    equal(helpers.normalizeAmount('1\u202f000\u202f000', 0), '1000000');
    equal(helpers.formatAmount('۱۲۳۴۵۶۷٫۵۰'), '1,234,567.50');
    for (const amount of ['12,34', '1,,000', '1,000,00', '-1', '+1', '1e6', '1.2.3', '.50', '1 000', '<script>1</script>', '']) {
        rejects(() => helpers.normalizeAmount(amount, 2), amount);
    }
    rejects(() => helpers.normalizeAmount('1.001', 2));
    rejects(() => helpers.normalizeAmount('1', 10));
    rejects(() => helpers.normalizeAmount('9223372036854775808', 0));
});
test('Toman conversion uses exact integer decimal shifts, never foreign exchange', () => {
    equal(helpers.toStore('۱۵۰٬۰۰۰', 'toman', 'IRR', 0), '1500000');
    equal(helpers.toStore('150000', 'toman', 'IRR', 2), '1500000.00');
    equal(helpers.toStore('150000', 'toman', 'IRT', 0), '150000');
    equal(helpers.toStore('150000', 'toman', 'TMN', 0), '150000');
    equal(helpers.fromStore('1500001', 'IRR', 0), '150000.1');
    equal(helpers.fromStore('-1', 'IRR', 0), '-0.1');
    equal(helpers.fromStore('9007199254740993', 'IRR', 0), '900719925474099.3');
    equal(helpers.toStore('900719925474099.3', 'toman', 'IRR', 0), '9007199254740993');
    equal(helpers.fromStore('92233720368.54775807', 'IRR', 8), '9223372036.854775807');
    equal(helpers.toStore('9223372036.854775807', 'toman', 'IRR', 8), '92233720368.54775807');
    equal(helpers.toStore('1,234.50', 'store', 'USD', 2), '1234.50');
    equal(helpers.isToman('USD'), false);
    rejects(() => helpers.toStore('150000', 'toman', 'USD', 2));
    rejects(() => helpers.toStore('150000.01', 'toman', 'IRR', 0));
    rejects(() => helpers.toStore('922337203685477580.8', 'toman', 'IRR', 0));
});

const result = {suite: 'v101-helper-arithmetic', node: process.version, icu_vectors: reference.vectors.length, cases, assertions: checks, passed: cases - failures.length, failed: failures.length};
console.log('EXIN_HELPER_ARITHMETIC_RESULT ' + JSON.stringify(result));
process.exitCode = failures.length ? 1 : 0;
