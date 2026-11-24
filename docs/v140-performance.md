# EXIN 1.4.0 performance candidate

Release gate: **BLOCKED / Draft, not certified Production-ready.** No Production access, merge or deployment. Base `64d7458fa7b863d49e0821fddf671515bc3f0a48` is PR #15 on top of #14/#13. Branch: `feature/v1.4.0-performance-architecture`. Schema stays **6**; plugin/header/stable tag are **1.4.0**.

## Implemented architecture and audit

`Plugin::boot()` formerly acquired the recovery lock on every init. It now calls `Scheduler::maybe_recover()`: a fresh 180-second heartbeat and recent verification take the read path. Periodic verification every 60 seconds checks supported AS APIs without advisory locks or scheduler writes when healthy (one health-checkpoint option write). Missing/stale/upgrade/error/flag paths use existing connection-scoped locking and a 30-second request throttle. Explicit recovery bypasses that throttle. Delayed AS initialization retries on AS init. Success consumes the observed recovery flag; failure retains it. Activation/deactivation write unique flag tokens. No dependency on Redis or the watchdog itself to initiate recovery.

Recovery scans at most 100 exact-group pending watchdog actions. It keeps one recurring chain, cancels only unclaimed pending recurring duplicates through the AS store API, leaves claimed/running actions, nonrecurring jobs and other hooks untouched. A running action may create a temporary successor overlap; later verification reconciles it. Overflow retains the recovery flag for another bounded pass. A pending successor can be recreated even alongside a running action; no direct scheduler SQL deletion. Historical canceled actions remain for AS retention.

`FloorGuard::scheduled()` previously serialized the whole campaign, including potentially thousands of pending-result entries, on every call. Keys now contain only the exact schedule inputs: base, floor, precision, days, strategy, power, rounding and discount plan. The schedule cache holds at most 128 entries. Financial calculations are unchanged. Existing PR #15 anonymous-request caching and Timeline optimization remain. Cart/checkout still perform fresh ownership/dynamic-price validation. No final-price cache was added, and cached eligibility was not extended to unproven admin/AJAX/REST contexts.

Source audit covered bootstrap, repository, locks, FloorGuard/PriceState/DiscountPlan/Timeline, Dashboard/AdminController, Storefront/Cart/Orders/Shipping/NewProducts/Notifications/CatalogCache. Dashboard assets already load only on its own screen. Hook registration alone did not justify deferred constructors. Repository financial reads remain authoritative; no speculative persistent repository cache was introduced. EXPLAIN on populated local ownership lookup uses `product_status` with `ref` access; the item composite primary key already covers campaign/product lookup. No index or repository SQL change was justified by these measurements. Retention/notification contracts, batch sizing and financial locks remain unchanged pending profiling.

## Reproducible measured evidence

Official WP 7.1.4 / Woo 11.3.0 / PHP 8.2.35 / MariaDB 11.8.6 / HPOS, local `exin_test`, no Redis drop-in. Both variants loaded via the same isolated WP CLI wrapper, same database and benchmark source. Base source was extracted from the exact Git object. Initial Action Scheduler migration was completed through its supported CLI. Raw measurements: `docs/v140-benchmark-results.json`; workload: `tests/integration/performance-benchmark.php`.

These are **microbenchmarks**, not HTTP or Production measurements. Recovery has 20 warm repeated samples and 10 option-cache-eviction samples. Schedule workloads have five samples; nearest-rank P95 is the maximum for five samples. The numbers 100/1,000/2,000/10,000 count price-calculation iterations, **not fully populated catalog HTTP tests**. A realistic 1,738-entry pending-results array exercises key serialization. WP bootstrap is outside timed regions. Whole-process peak includes WP/Woo and both tested loops.

| Workload | Base median/P95 ms | Candidate median/P95 ms |
|---|---:|---:|
| Healthy recovery, warm | 0.691 / 1.044 | 0.007 / 0.030 |
| 100 schedule calls | 6.029 / 20.428 | 0.429 / 4.516 |
| 1,000 schedule calls | 67.672 / 209.149 | 43.682 / 44.802 |
| 2,000 schedule calls | 135.318 / 147.264 | 84.875 / 99.194 |
| 10,000 schedule calls | 663.445 / 724.977 | 446.236 / 463.608 |

Warm recovery SQL: 5 → 0 queries, 2 → 0 writes, 1 → 0 advisory lock acquisitions. Option-cache-eviction median: **0.432 → 0.555 ms**, queries **5 → 5**, candidate still has zero recovery locks/writes. Thus cold-path query reduction is **not demonstrated** and latency increased in this microbenchmark. Whole-process peak: **173,867,008 → 131,923,968 bytes**. Warm recovery is about 99% faster; 10,000 schedule calls about 33% faster. The overall 80% overhead target is **not established**.

## Validation and remaining gates

- New recovery tests: missing chain, flag consumption/failure preservation, throttle, periodic verification, duplicate/claimed/running watchdogs, unrelated retry preservation, two independent competing PHP recoveries, populated migration/recovery hashes and unchanged schema. 19 checks pass.
- 3,200 exact financial schedule outputs match the optimized base.
- Existing suites passed: FloorGuard 63; live-floor 49 including 1,738 selected products and two concurrent writers; conflict recovery 151; automatic/Hybrid/bulk/stock 37 assertions; core/migration 12 cases / 84 assertions; shipping/tax 14 cases / 52 assertions; Store API/Blocks controllers/HPOS/offline checkout 19 checks.
- Unit suites: 17 cases / 3,207 assertions and automatic plans 29 cases / 531 assertions pass. Browser: 28 live-floor + 19 recovery + 51 workspace checks pass; shipped JS, mocked REST. PHP lint and JS syntax checked separately.
- **Failure retained:** `retry-backoff.php` fails “Watchdog recovers due retry two without resetting the budget.” The identical failure was reproduced on exact base `64d7458`, with assertions unchanged. It remains a release blocker, not a passing test.
- Store API initially rejected the wrong test prefix; rerunning the existing native harness on isolated `exin_store_api_` tables passed. Recovery claim testing initially encountered an incomplete AS migration; after supported AS migration it passed. Financial-parity fixture initially lacked timeline fields; corrected fixture now compares 3,200 outputs.
- **Unverified:** full anonymous/authenticated HTTP homepage/shop/landing/product/admin/product-list/order-list/AJAX/REST/cart/checkout before/after matrix; real populated 100/1,000/2,000/10,000 catalogs; HTTP P95, plugin-attributable CPU, lock hold duration, background throughput; Redis enabled/failure modes; all compatibility versions/themes/Elementor; exhaustive failure windows around runner claims. Existing concurrent financial tests are not a proof of every AS race.
- CI status is external and reported in the PR; no full CI pass is implied. No full Production clone or real campaign #2/#4 data was accessed.

## راهنمای ارتقا و بازگشت

این بسته فعلاً برای کپی ایزوله و بررسی است؛ به دلیل موارد بالا تأیید انتشار Production ندارد. کف، Snapshot، مالکیت، نسخه و پیشرفت کمپین‌ها را قبل/بعد مقایسه کنید. نیازی به ورود دوبارهٔ کف‌ها یا فعال‌سازی دوبارهٔ کمپین نیست.

برای استقرار آتی پس از رفع Gateها، از نصب سازگار PR #15 شروع کنید. نام سرویس و مسیرها باید با هاست تطبیق داده شوند. ابتدا تمام Runnerهای Cron/CLI/HTTP صف را متوقف و کارهای جاری را تخلیه کنید. خود افزونه را deactivate نکنید؛ این کار زمان‌بندی‌ها را لغو می‌کند.

```bash
set -euo pipefail
SITE='/absolute/path/to/wordpress'
ZIP='/absolute/path/to/exin-black-friday-v1.4.0.zip'
BACKUP='/private/backups/exin-v140-unique-directory'
FPM='YOUR_PHP_FPM_SERVICE'
LIVE="$SITE/wp-content/plugins/exin-black-friday"
test ! -e "$BACKUP"
test -f "$LIVE/src/Application/ConflictRecovery.php"
test "$(wp --path="$SITE" --skip-plugins --skip-themes option get exin_bf_schema_version)" = '6'
# ابتدا SHA256 فایل را با SHA256SUMS تحویلی تطبیق دهید.
mkdir -m 700 -p "$BACKUP"
wp --path="$SITE" --skip-plugins --skip-themes maintenance-mode activate
sudo systemctl stop "$FPM"
wp --path="$SITE" --skip-plugins --skip-themes db export "$BACKUP/before.sql"
chmod 600 "$BACKUP/before.sql"
cp -a "$LIVE" "$BACKUP/plugin"
unzip -q "$ZIP" -d "$BACKUP/staged"
rsync -a --delete "$BACKUP/staged/exin-black-friday/" "$LIVE/"
sudo systemctl start "$FPM"
wp --path="$SITE" --skip-plugins --skip-themes maintenance-mode deactivate
# Runnerهای قبلی را از سر بگیرید؛ کمپین را restart نکنید.
wp --path="$SITE" eval 'echo count(as_get_scheduled_actions(["hook"=>"exin_bf_tick","group"=>"exin-black-friday","status"=>"pending","per_page"=>100],"ids")),PHP_EOL;'
wp --path="$SITE" option get exin_bf_last_tick
```

یک زنجیرهٔ pending انتظار می‌رود؛ هنگام اجرای Worker ممکن است هم‌پوشانی موقت وجود داشته باشد. Heartbeat باید تازه شود و خطای بوت/پرچم بازیابی پایدار نماند. با اجرای دستی قیمت‌ها تست سلامت نگیرید.

برای بازگشت به Backup سازگار PR #15، Runnerها را دوباره متوقف و تخلیه کنید و متغیرهای بالا را نگه دارید:

```bash
test -f "$BACKUP/plugin/src/Application/ConflictRecovery.php"
wp --path="$SITE" --skip-plugins --skip-themes maintenance-mode activate
sudo systemctl stop "$FPM"
rsync -a --delete "$BACKUP/plugin/" "$LIVE/"
sudo systemctl start "$FPM"
wp --path="$SITE" --skip-plugins --skip-themes maintenance-mode deactivate
# Runnerها را از سر بگیرید. دیتابیس را Restore یا Reset نکنید.
```

این نسخه Schema جدید ندارد؛ migration no-op روی دیتابیس پُر با Hash کمپین‌ها/آیتم‌ها بررسی شده است. بازگشت فایل‌ها به PR #15 از همان Schema و ساختار Snapshot استفاده می‌کند؛ گزینه‌های سلامت اضافی توسط نسخهٔ قبلی نادیده گرفته می‌شوند. تاریخچهٔ لغو زنجیره‌های تکراری AS حفظ شده است. بازگشت به نسخه‌های قبل از اصلاح Restore در PR #14 مشمول این تضمین نیست. اجرای واقعی rollback میزبان انجام نشده است.
