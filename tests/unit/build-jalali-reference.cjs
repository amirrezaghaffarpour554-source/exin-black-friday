/* Independent calendar vectors from the runtime's ICU Persian calendar. */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const formatter = new Intl.DateTimeFormat('en-u-ca-persian-nu-latn', {timeZone: 'UTC', year: 'numeric', month: 'numeric', day: 'numeric'});
const vectors = [];
for (let year = 1821; year <= 2222; year += 7) {
    for (const [month, day] of [[1, 1], [2, 28], [2, 29], [3, 20], [3, 21], [6, 30], [9, 23], [11, 27], [12, 31]]) {
        const date = new Date(Date.UTC(year, month - 1, day));
        if (date.getUTCMonth() + 1 !== month || date.getUTCDate() !== day) continue;
        const fields = Object.fromEntries(formatter.formatToParts(date).map(part => [part.type, part.value]));
        const jy = Number(fields.year);
        if (jy < 1200 || jy > 1600) continue;
        vectors.push([year, month, day, jy, Number(fields.month), Number(fields.day)]);
    }
}
for (const [year, month, day] of [[2024, 3, 20], [2025, 3, 20], [2025, 3, 21], [2026, 11, 27], [2026, 3, 20], [2026, 3, 21]]) {
    const fields = Object.fromEntries(formatter.formatToParts(new Date(Date.UTC(year, month - 1, day))).map(part => [part.type, part.value]));
    vectors.push([year, month, day, Number(fields.year), Number(fields.month), Number(fields.day)]);
}
const target = path.join(__dirname, 'jalali-reference.json');
fs.writeFileSync(target, JSON.stringify({source: 'Independent ECMA-402 ICU Persian calendar in UTC', vectors}, null, 2) + '\n');
console.log(`Wrote ${vectors.length} independent ICU calendar reference vectors`);
