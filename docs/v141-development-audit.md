# v1.4.1 development audit — RELEASE BLOCKED

Development continued from `933ba1e`; functional comparison baseline is v1.4.0 `ac7e206bd4135ec646f7d3b12144f82408b4accd`. This report supersedes the initial infrastructure-only gate. No Production connection, merge or deployment occurred.

## Source audit and implementation

The source inventory covers every PHP file under `src/`, including Domain, Application, Infrastructure, Integration, REST, Admin, shipping/marketing and Elementor adapters. Static call-site counts are not execution frequencies. Runtime traces identify the bottleneck as repeated Woo price/sale getter calls through `FloorGuard::filter` → campaign ownership resolution → item reads → ownership/dynamic validation. Query-string requests bypass the deliberately narrow existing anonymous-catalog request cache. Dynamic guards still run on repeated getters. The SQL observer records caller query counts/time; hook windows are inclusive, not a sampled PHP CPU profiler.

Only three runtime modules changed:

- `CampaignRepository`: merge owner lookup plus campaign/winner resolution into one authoritative indexed LEFT JOIN. Retain lowest owner's precedence, orphan ownership blocking, live/restoring rules, priority tie order and complete decoded campaign shape. No persistent cache or index migration. EXPLAIN uses existing `items.product_status` and campaign PRIMARY indexes.
- `FloorGuard`: one bounded existence query for 4–500 variation IDs avoids hydrating children with no membership record anywhere. Every recorded status still follows the original financial guard. SQL failure and larger arrays fall back to the original path. No final-price cache or financial formula changes.
- `AdminController`: reuse an already hydrated simple product for stock; retain parent hydration for shared variation stock. Payload equality tested.

Existing pure schedule memoization already keys base, floor, precision, strategy and timing; it was not rebuilt. Catalog selection/pagination is already server-side. Bootstrap health/recovery, scheduler campaign/product locks, retries, transaction/CAS ownership, snapshots, Restore, order and shipping paths remain unchanged. Their repeated reads at mutation boundaries are safety checks, not automatically removable duplicates. No jobs per product, analytics, migrations, automatic floors or bulk repricing were added.

A materialized-price bypass was rejected: current expected-price fingerprints alone do not establish current stage and current manual-floor revision when a worker is delayed. Broadening cache eligibility or dropping dynamic validation without invalidation/concurrency evidence could introduce stale financial decisions. Remaining high query counts warrant a separately proven bounded catalog read context, with fresh checkout/worker reads and tested revision invalidation, rather than removing those guards here.

## Validation

Actual local runtime: PHP 8.2.35, WordPress 7.1.4, WooCommerce 11.3.0, MariaDB 11.8.6, HPOS. Restored private RAM worker. Supported Action Scheduler migration was completed before the recovery/watchdog tests; fresh hybrid-store setup initially failed those tests.

PASS: resolver parity 87; variation parity/fallback/bound 5; admin stock equality 3; floor/checkout guard 63; live floors 49 (1,738 products, two concurrent writers); conflict recovery 151; Retry 32; recovery 19; concurrent watchdog 16 (separate DB connection and real AS claim interleaving); full v1.3.0 integration 9 cases/162 assertions; core 12 cases/84 assertions; shipping 14 cases/52 assertions; Store API 19 checks; lifecycle 10. All seven PHP unit scripts passed; 3,297 scheduled financial outputs matched the prior optimized baseline exactly. Chromium shipped-UI/mocked-REST suites: floor 28, conflict 19, RTL workspace 51. No real campaign data was verified.

Active upgrade and file rollback: **43 checks passed**, v1.4.0 → v1.4.1 → v1.4.0, separate processes/private table prefix, 72 selections including variations, 54 positive floors/18 missing. All 11 raw hash sections unchanged: campaigns, membership/floors, product posts/meta, HPOS orders/addresses/operational/meta, order items/meta, settings. Expected/intent, retry and execution progress are included. This proves synthetic preservation, not the inaccessible real campaigns #2/#4.

Historical integration suites are not all green. v1.3.1 expects HTTP 400 where both exact baseline and candidate return HTTP 200 with per-row `ok:false`; prior floor remains unchanged. v1.1.0, v1.2.0, v1.2.2 and old catalog suites also fail on the exact v1.4.0 control (old automatic group-floor contracts, schema marker 4 versus 6, financial-setting freeze and catalog/theme assertions). These assertions were not weakened and failures were not silently discarded. Old catalog theme assertions still require reconciliation; they are not compatibility certification. The standalone manual-floor suite requires its own `exin_manual_*` prefix; a default-prefix invocation correctly refused to run. A subsequent fresh-prefix run reached `campaign_not_editable` in the unchanged selection service when the old fixture attempted direct active membership. This suite remains FAIL, not a preservation PASS. Additional invocation results are retained in the machine-readable test evidence. Extra browser runs passed v110 (12), v120 (6), v130 (33), v131 (12), v132 (9). Old v122/v101 browser suites timed out on absent legacy controls; real-WP browser entrypoints failed because their Docker/browser runtime was unavailable. UI assets were unchanged; these failures are recorded, not waived.

PHP syntax checks: 59 PHP/Python/Bash entries initially passed, including every `src` PHP file. ZIP integrity and deterministic rebuild passed: 112 files. GitHub CI availability is reported separately; no workflow success is inferred from local tests.

## Measured performance and outstanding gates

Fixture: 10,001 catalog products plus two variation children, 1,752 selected (1,576 positive floors, 176 missing), 8 Woo shortcode widgets rendering **125 verified cards**. Twenty Twenty-One, no Redis, opcode cache disabled, SAVEQUERIES enabled, single PHP HTTP worker. This is development evidence, not the licensed Production stack.

Thirty ABC/CBA cycles per path/variant, baseline/candidate/disabled, 360 measured requests (all HTTP 200), before the version-only bump and admin local-variable refinement:

| Path | Median ms base / candidate / disabled | p95 ms base / candidate / disabled | Median SQL base / candidate / disabled |
|---|---|---|---|
| Home | 729.4 / 707.5 / 486.3 | 810.8 / 787.3 / 550.4 | 445 / 318 / 113 |
| Home + UTM | 1572.4 / 1405.7 / 498.2 | 1880.1 / 1635.1 / 605.5 | 5216 / 3229 / 113 |
| Shop HTML shell | 374.6 / 371.3 / 367.8 | 393.2 / 413.5 / 413.8 | 91 / 91 / 84 |
| Product | 404.0 / 407.5 / 386.0 | 542.5 / 479.3 / 434.8 | 84 / 84 / 77 |

Home candidate CPU median 645.3ms versus baseline 653.7ms; both peak allocations 85.5MiB. UTM candidate CPU 937.7ms versus 963.7ms; peak allocations 91.5 versus 95.5MiB. CPU covers router entry to shutdown, not kernel/process startup. Process-wide server capacity is not measured. Shop returned zero server-rendered cards, so this is explicitly not a full shop user journey. Product median and shop p95 do not establish non-regression.

A further **180-request run verifies literal version 1.4.1**, 30 cycles per home/UTM variant, all HTTP 200 and all server metrics joined. It overlaps regression subprocesses, so its timings are diagnostic, not controlled acceptance evidence. Raw samples and both summaries are retained. Resolver-only microtest halves reads (168→84); isolated variation loop reduces 32→1 queries using the same resolver. Neither is claimed as a 90% whole-request reduction.

**Acceptance remains failed/unverified:** candidate-minus-disabled home median ≈221ms and SQL205; UTM ≈907ms and SQL3116. Required ≤100ms, ≤30 additional SQL and ≥90% comparable SQL reduction are not met. No claim of p95/CPU/memory non-regression across the full scenario matrix. Redis warm/cold, logged-in/nonempty-cart HTTP, required filter combinations, licensed Woodmart/Elementor Pro/WP Rocket and equivalent 8 Elementor widgets remain uncertified. Financial differences were zero in the passing covered fixtures, not established for every required Production scenario. None of the original gates is reduced or waived.

## Cleanup and rollback

Removed only four regenerable Python bytecode files (14,933 bytes). Profiling logs were losslessly gzipped and decoded byte-for-byte/JSON-validated before deleting their uncompressed copies; evidence and manifests remain. No source, Git history, fixtures or important evidence were deleted. Disk remains constrained.

ZIP v1.4.1 is for isolated review/testing, **not approved for Production**. Rollback rehearsal replaced candidate files with exact ac7e206 files without database rollback or reset, and preserved hashes. Before any future approved deployment retain the installed plugin ZIP and independent DB backup; if rollback is needed, pause this plugin's worker during file replacement, restore prior plugin files, clear only plugin/opcode assets as appropriate, resume the prior worker and inspect its recovery/Restore queue. Do not reset floors, ownership, execution progress or Woo prices. A database rollback against intervening real orders is not part of this procedure.
