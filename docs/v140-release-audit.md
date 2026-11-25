# PR #16 focused release-blocking audit

**Release readiness: FAIL / Draft retained.** Confirmed code defects below are fixed and their affected tests pass. Redis-enabled/failure behavior, interactive browser checkout, the full supported-version/theme matrix and production traffic capacity remain unverified. No merge, deployment or Production access was performed.

## Confirmed defects and minimal fixes

- **Due Retry abandoned behind a completed cursor:** batch traversal completion previously returned before revisiting pending retry reservations. Recovery now rotates through at most 25 pending entries, using the existing `perform()` budget, stage checks and financial locks. The main cursor is retained. Fresh counters are re-read before persisting the wake cursor, avoiding lost results. The original failing financial assertion remains unchanged and passes. Two obsolete fixture assumptions were corrected explicitly: pagination membership is prepared in draft (PR #13 restriction), and the exact current schema is 6, not 3. Four additional assertions cover 26 pending products, the 25-entry bound, rotation, counters and floors.
- **Wrong-cadence/far-future watchdog accepted as healthy:** keeper selection and periodic/final verification now require an empty-argument, exact-group, 60-second recurring action due no more than 60 seconds ahead.
- **Runner claim/start racing cancellation:** separate claim/status reads did not make AS `cancel_action()` conditional. Recovery now takes its own supported AS claim; it cancels only pending recurring duplicates it owns, releases that claim in `finally`, and preserves pre-existing claims/running actions and unrelated hooks. Temporary running/successor overlap remains legitimate; later reconciliation resolves it.
- **Newer recovery flag could be lost through a stale option cache:** acknowledgement now reads the raw DB token and atomically deletes only that exact value. Failure uses `INSERT IGNORE` rather than overwriting a newer token. Only relevant option cache entries/groups are invalidated. A second-connection replacement test primes the old WP cache and proves the newer flag survives.
- **Public automatic schedule helper memo omitted timing:** start/end/timezone now join the key. Production automatic pricing already bypassed this helper, but the helper must remain correct for changed timing/last-day schedules.

The independent reviewer inspected the entire original PR diff and relevant financial/recovery call sites. A negative control using the original `d01556d` Scheduler fails the unchanged wrong-cadence assertion; current code passes. No pricing formula, floor calculation, schema, capability/nonce policy, restoration strategy or dependency changed in this audit.

## Verified tests

| Check | Actual result |
|---|---|
| Retry/backoff, interrupted writes, audit failures, bounded pending traversal | 32 PASS |
| Independent watchdog/race/flag audit | 16 PASS |
| Existing recovery/concurrent-process/populated-state tests | 19 PASS |
| Financial schedule parity against exact optimized base | 3,297 identical outputs |
| FloorGuard / live floors / conflict recovery | 63 / 49 / 151 PASS |
| Automatic/Hybrid/stock regression | 37 assertions PASS |
| Core/migration / shipping-tax / Store API-HPOS-Blocks controllers | 84 / 52 / 19 assertions PASS |
| Domain / automatic-plan units | 3,207 / 531 assertions PASS |
| Shipped-JS browser tests, mocked REST | 28 + 19 + 51 PASS |
| Cross-process baseline → candidate → baseline rollback | 43 PASS |
| Real HTTP with 10,000 actual Woo products | 216 requests, 18 contexts, 252 checks PASS |

PHP syntax, Python compilation, Bash syntax, JS syntax and deterministic package integrity were checked. CI is reported separately from local test execution; no successful CI workflow is implied.

## Active upgrade and rollback preservation

`scripts/test-release-upgrade-native.sh` uses only isolated `exin_upgrade_audit_` tables and separate WordPress processes. The exact optimized base `64d7458` (v1.3.2) boots normally, followed by the candidate v1.4.0 and then the old base again. It does not switch the shared plugin symlink. The fixture contains 72 selected products/variations, 54 positive floors, 18 missing floors, owned prices, a pending write intent and retry, execution progress, sale dates, stock and an HPOS order.

All 11 raw-state sections retain their exact hashes: campaign/item records, complete product posts/metadata, HPOS order/address/operational/metadata tables, order items/metadata and store/stock flags. Schema remains 6. There is no destructive or additive migration in this audit.

An initial baseline-only control exposed Woo's deferred parent synchronization: it replaced two parent `_price` metadata rows with the same `100.00` value and new metadata IDs at shutdown. The fixture drains Woo's supported deferred-sync method before capturing the settled baseline. No rows/fields were excluded and strict comparisons were retained. This is fixture completion before the upgrade, not a relaxed preservation assertion. Host ZIP replacement and real Production records are not claimed verified.

## Real HTTP performance and limits

The final measurements and source hashes are in [v140-http-audit-results.json](v140-http-audit-results.json); the raw per-request data is retained there. Methods and all 18 context summaries are included. The HTTP router is test-only, uses loopback and dedicated `exin_release_` tables, and loads the exact baseline or candidate independently. Actual WooCommerce count and selected campaign count are both 10,000 (9,000 positive floors, 1,000 missing). Both empty and populated anonymous carts and authenticated administration are exercised. REST pagination returns 25 rows with total 10,000; current floor price is checked in the real HTTP cart. Complete campaign/item hashes are unchanged before/after the HTTP reads.

These are instrumented, single-worker PHP built-in server measurements on the same shared host, using WP7.1.4/Woo11.3/MariaDB/HPOS and TwentyTwentyOne. Each context/variant has one warmup and five measured requests; nearest-rank P95 is the maximum of five samples. Database/OS caches are not flushed, no persistent object cache or PHP opcache is enabled, and this is not a production-capacity or cold-database benchmark. The fixture heartbeat is refreshed before each variant to measure healthy requests.

Server SQL/CPU/time/peak are captured at WordPress shutdown `PHP_INT_MAX`, after ordinary WP/Woo/AS shutdown callbacks. Complete-response HTTP client latency is reported separately. Later raw PHP shutdown callbacks, if any, are outside that server hook. Earlier measurements captured too early at PHP shutdown registration order and were discarded as final server totals. Baseline asset completeness is controlled to avoid filemtime warnings in campaign administration.

Latency improvements are **not universal** and the overall 80% HTTP-overhead target remains unproven. In particular, product administration and populated-cart frontend requests retain many duplicate campaign queries. Those are documented bottlenecks; this audit does not expand into another caching/architecture refactor or weaken fresh financial validation to remove them.

## Remaining blockers and CI

- No Redis server/client, PHP Redis extension or WordPress persistent object-cache drop-in is installed. Redis-enabled, unavailable-Redis and persistent-cache behavior are **unverified**, not passing.
- HTTP checkout page and cart controllers are exercised, and native Store API tests create an offline BACS HPOS order. A complete interactive browser checkout/live gateway flow is **not** certified by those checks.
- Full compatibility and traffic/concurrent-HTTP capacity testing remains outside what this isolated single-worker environment proves. The full 100/1,000/2,000/10,000 populated-catalog scale matrix is not established by the 10,000-product HTTP run plus calculation microbenchmarks.
- No GitHub Actions workflow is present in this branch. Available remote workflow/status results are checked on the pushed head and reported in the PR; no workflow is fabricated or silently described as passed.
- Real campaign #2/#4 data, Production host services and Redis/CDN integration were not accessed.

## Upgrade / rollback instructions

The [existing Persian runbook](v140-performance.md#راهنمای-ارتقا-و-بازگشت) remains applicable **after release blockers are cleared**. Verify the delivered SHA256, back up the database and the complete compatible PR #15 plugin directory, pause/drain every Cron/CLI/HTTP queue runner, enter maintenance and replace files with PHP-FPM stopped/restarted as appropriate for the host. Do not deactivate/reactivate the plugin, restart campaigns, reset floors or force price application as a health check.

Rollback to the backed-up compatible PR #15 files with the same queue/maintenance precautions. **Do not restore the database**: keep new orders, floors, approved snapshots and execution progress. Keep schema 6; no reverse migration is needed. The two financial Restore compatibility fixes from PR #14 must remain present in the rollback build. The cross-process rollback test verifies these data formats against PR #15, not arbitrary older releases. Verify a timely minute watchdog and a refreshing heartbeat after resuming the original runners. No host deployment/rollback command was executed.

Rolling back also restores the prior Retry/watchdog defects; monitor pending reservations and watchdog health, and prefer a reviewed forward fix when possible. Data-format compatibility is not a claim that older operational bugs disappear.
