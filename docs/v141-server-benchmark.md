# Reproduce v1.4.1 measurements on an isolated server

Do not run fixture builders or mutate Production. Prepare three equivalent isolated clones using the same data, theme/plugins, PHP, DB and worker configuration: baseline `ac7e206`, this branch, and plugin disabled. Use ≥10,000 catalog products/≥1,750 campaign selections, simple/variable products, eight actual widgets/125 verified cards, floors/missing floors and the required full scenario matrix. Keep each clone's state and order workload equivalent.

Install `tests/performance/server-observer.php` as an optional MU plugin on local/staging clones only. Set `EXIN_BENCH_LOG` to a writable path outside the public web root, and enable `SAVEQUERIES` before WordPress bootstrap on all three clones. This observer only handles correctly formatted benchmark request headers and never records SQL text/cookies. Its CPU/time scope starts at MU load; SQL is complete only with SAVEQUERIES enabled early. Trace overhead must be equal. It does not flush caches, switch plugins or prove financial parity.

```sh
python3 scripts/benchmark-v141-server.py \
 --baseline https://baseline.example.test \
 --candidate https://candidate.example.test \
 --disabled https://disabled.example.test \
 --path / --path '/?utm_source=benchmark' --path /shop/ \
 --path /product/your-real-product/ \
 --pairs 30 --output results.json --server-metrics combined-observer.jsonl
```

Collect/concatenate observer logs from the three clones before joining results. For remote machines, first run without `--server-metrics`, download their logs, then join by each sample's unique `id` (do not rerun requests just to join). Optional Netscape cookie jars: `--baseline-cookies`, `--candidate-cookies`, `--disabled-cookies`. Use separate equivalent accounts/carts and secure files; do not commit cookies. Check HTTP status, actual rendered cards, selected/ordinary price correctness and cache headers for every scenario. HTML 200 alone is insufficient; block/shop and checkout JS flows require browser coverage.

Explicitly disable full-page caching on isolated clones for application-cost measurements. Run Redis cold and warm cases separately with controlled clone-local keys; never globally flush a shared Redis server. Then perform the separate licensed theme/Elementor Pro/WP Rocket compatibility matrix. Test login, empty/nonempty cart, checkout, filters, query strings, campaign boundaries and delayed/recovered workers. No concurrent test processes during controlled latency runs. ABC/CBA ordering reduces order bias but does not establish equivalence.

The script requires ≥30 cycles/path and retains individual complete-response timings, p95, variance, status, byte count, cache headers and optional server metrics. Missing metrics remain null. Observer SQL caller times are SQL time, not PHP method CPU; guarded hook windows are inclusive. Measure process CPU/RSS and worker behavior separately on your server. Compare candidate-minus-disabled median ≤100ms, incremental SQL≤30, ≥90% comparable SQL reduction, p95/CPU/memory non-regression, and zero financial differences against the original acceptance criteria. A passing harness run does not itself approve release.

Local-only fixture: `v141-http-fixture.php` refuses any prefix except `exin_v141_`, DB `exin_test`, local environment and WP-CLI. `test-v141-upgrade-native.sh` prepares exact baseline source; `v141-http-servers.py` starts loopback-only variant routers inside the native worker. The supplied server harness does not depend on that private runtime.
