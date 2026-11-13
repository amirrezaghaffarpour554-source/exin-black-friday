#!/usr/bin/env bash
# Installs the release ZIP into a fresh WordPress tree, never the source bind mount.
set -euo pipefail
test_root="$(cd "$(dirname "$0")/.." && pwd)"
export EXIN_TEST_ROOT="$test_root"
test_compose=(docker compose -f "$test_root/tests/integration/compose.yaml")
test_path=/srv/exin-package-wp
test_prefix="exin_zip_$(date +%s)_"
test_zip="${1:-$test_root/work/dist/exin-black-friday-v1.0.0.zip}"
test_wp=("${test_compose[@]}" exec -T wordpress php /usr/local/bin/wp --allow-root --path="$test_path")
test -f "$test_zip"
test_sha="$(sha256sum "$test_zip" | cut -d ' ' -f 1)"
echo "EXIN_PACKAGE_ARCHIVE_SHA256 $test_sha"
"${test_compose[@]}" exec -T wordpress sh -c 'rm -rf /srv/exin-package-wp && cp -a /usr/src/wordpress /srv/exin-package-wp'
"${test_compose[@]}" cp "$test_zip" wordpress:/tmp/exin-package.zip
"${test_compose[@]}" cp "$test_root/tests/integration/package-install.php" wordpress:/tmp/exin-package-test.php
"${test_wp[@]}" config create --dbname=exin_test --dbuser=exin --dbpass=local-test-only --dbhost=db --dbprefix="$test_prefix" --skip-check --extra-php <<'PHP'
define('WP_ENVIRONMENT_TYPE', 'local');
define('DISABLE_WP_CRON', true);
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
PHP
"${test_wp[@]}" core install --url=http://localhost:8880 --title='EXIN clean ZIP install' --admin_user=zip_test --admin_password=local-zip-only --admin_email=zip@example.invalid --skip-email
"${test_wp[@]}" plugin install /tmp/woocommerce.zip --activate
"${test_wp[@]}" option update woocommerce_custom_orders_table_enabled yes
"${test_wp[@]}" option update timezone_string Asia/Tehran
"${test_wp[@]}" plugin install /tmp/exin-package.zip --activate
"${test_wp[@]}" eval-file /tmp/exin-package-test.php
"${test_wp[@]}" --skip-plugins=woocommerce eval 'if (class_exists("WooCommerce")) { throw new RuntimeException("WooCommerce unexpectedly loaded"); } echo "PASS installed ZIP without WooCommerce: no fatal\n";'
"${test_wp[@]}" --skip-plugins=elementor eval 'if (class_exists("Elementor\\Widget_Base") || !shortcode_exists("exin_bf_products")) { throw new RuntimeException("Elementor-free fallback failed"); } echo "PASS installed ZIP without Elementor: shortcodes registered\n";'
echo 'EXIN_PACKAGE_DEPENDENCIES_RESULT {"checks":2,"passed":2,"failed":0}'
"${test_compose[@]}" exec -T wordpress sh -c 'rm -rf /srv/exin-package-wp'
