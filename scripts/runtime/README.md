The primary reproducible test stack is `scripts/test-v101-runtime.sh up`: official WordPress 7.1.4 with PHP 8.2, WooCommerce 11.3.0, MariaDB, Nginx and Redis. It uses only local synthetic data and loopback HTTP. `scripts/test-v101-runtime.sh store-api` delegates the Store API regression launcher. The `upgrade` command installs the actual retained v1.0.0 ZIP in a separate `exin_upgrade_` table prefix, then replaces its files and checks the v1.0.1 migration in a fresh PHP process.

The native helpers provide a fallback for this managed environment, where the Docker daemon is unavailable. They do not start a daemon or install host packages. They require the previously verified native artifacts in `work/v101/native-php` and `work/v101/native-db-root`, and the official compressed WordPress/WooCommerce archives in `work/v101/runtime`. Artifact source/version/hash evidence is retained in `work/v101/runtime/native-*-provenance.json` and `native-core-manifest.json`. These large generated artifacts are deliberately excluded from Git and the installable plugin.

Run `scripts/runtime/start-native.sh` in a persistent session with write access to the private `/dev/shm/exin-v101` directory. Keep that session alive: this environment gives each execution a private RAM namespace. From another terminal run:

```sh
scripts/test-v101-native.sh setup
scripts/test-v101-native.sh setup web
scripts/test-v101-native.sh wp eval-file /workspace/exin-black-friday/tests/integration/v101-core.php
```

The JSON queue accepts argv arrays for `wp`, `php` and `setup`, serializes commands in the worker namespace, preserves exit status, and removes completed job files. It rewrites `/var/www/html` arguments to the RAM WordPress root. The real MariaDB process uses a private Unix socket, networking disabled, and a volatile RAM datadir. The sample `exin_test` login is synthetic. The supplied MU guard prevents external HTTP and mail. No Production files, database or credentials are used.

For a separate synthetic WordPress installation, pass `--exec='putenv("WORDPRESS_TABLE_PREFIX=exin_store_api_");'` on **every** WP CLI invocation. Prefixes isolate options and tables; tests that change the active plugin symlink or default campaign still require an exclusive test lease. Run the old-release test with `scripts/runtime/test-native-upgrade.sh` only while browser and other suites are idle; its EXIT trap restores the repository plugin link.

`setup web` uses PHP's built-in HTTP server on `127.0.0.1:8891`; it does not prove Nginx, Redis, Woodmart or WP Rocket compatibility. RAM data is disposable and disappears when the worker/environment stops. The source archives persist so the same isolated runtime can be recreated without repeating large downloads. Start-up helpers refuse unexpected non-symlink replacements. Final release ZIP installation evidence is written outside the ZIP under `releases/v1.0.1/`.
