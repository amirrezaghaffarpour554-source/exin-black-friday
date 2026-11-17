#!/usr/bin/env bash
# Exact v1.0.1 test matrix; synthetic data and persistent local volumes only.
set -euo pipefail
test_root="$(cd "$(dirname "$0")/.." && pwd)"
test_runtime="$test_root/work/v101/runtime"
export EXIN_TEST_ROOT="$test_root"
test_compose=(docker compose -f "$test_root/tests/integration/v101-compose.yaml")
test_wp=("${test_compose[@]}" exec -T wordpress php /usr/local/bin/wp --allow-root)
mkdir -p "$test_runtime"
case "${1:-up}" in
  up)
    [ -f "$test_root/work/runtime/wp-cli.phar" ] || curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$test_root/work/runtime/wp-cli.phar"
    [ -f "$test_runtime/woocommerce.zip" ] || curl -fsSL https://github.com/woocommerce/woocommerce/releases/download/11.3.0/woocommerce.zip -o "$test_runtime/woocommerce.zip"
    if [ ! -f "$test_runtime/redis-cache.zip" ]; then
      curl -fsSL https://codeload.github.com/rhubarbgroup/redis-cache/zip/refs/tags/2.7.0 -o "$test_runtime/redis-cache-source.zip"
      python3 - "$test_runtime/redis-cache-source.zip" "$test_runtime/redis-cache.zip" <<'PY'
import sys, zipfile
with zipfile.ZipFile(sys.argv[1]) as source, zipfile.ZipFile(sys.argv[2], 'w', zipfile.ZIP_DEFLATED) as output:
    for name in source.namelist():
        if not name.endswith('/'):
            output.writestr('redis-cache/' + name.split('/', 1)[1], source.read(name))
PY
    fi
    [ -f "$test_root/work/runtime/elementor.zip" ] || curl -fsSL https://github.com/elementor/elementor/releases/download/3.34.0/elementor-3.34.0.zip -o "$test_root/work/runtime/elementor.zip"
    "${test_compose[@]}" up -d
    for test_wait in $(seq 1 60); do
      if "${test_compose[@]}" exec -T wordpress test -f /var/www/html/wp-config.php; then break; fi
      sleep 1
    done
    if ! "${test_wp[@]}" core is-installed; then
      "${test_wp[@]}" core install --url=http://localhost:8891 --title='EXIN v1.0.1 isolated integration' --admin_user=exin_test --admin_password=exin-local-only --admin_email=test@example.invalid --skip-email
    fi
    for test_plugin in woocommerce redis-cache elementor; do
      if "${test_wp[@]}" plugin is-installed "$test_plugin"; then
        "${test_wp[@]}" plugin activate "$test_plugin"
      else
        "${test_wp[@]}" plugin install "/tmp/$test_plugin.zip" --activate
      fi
    done
    "${test_wp[@]}" option update timezone_string Asia/Tehran
    "${test_wp[@]}" option update woocommerce_custom_orders_table_enabled yes
    "${test_wp[@]}" redis enable
    if [ "${EXIN_V101_ACTIVATE_PLUGIN:-1}" = 1 ]; then
      "${test_wp[@]}" plugin activate exin-black-friday
    fi
    ;;
  wp)
    shift
    "${test_wp[@]}" "$@"
    ;;
  php)
    shift
    "${test_compose[@]}" exec -T wordpress php "$@"
    ;;
  store-api)
    shift
    EXIN_TEST_RUNTIME=v101 "$test_root/scripts/test-store-api.sh" "$@"
    ;;
  status)
    "${test_compose[@]}" ps
    "${test_wp[@]}" eval 'echo wp_json_encode(array("wordpress" => get_bloginfo("version"), "woocommerce" => WC_VERSION, "php" => PHP_VERSION, "database" => $GLOBALS["wpdb"]->db_version(), "redis" => wp_using_ext_object_cache(), "hpos" => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled())), PHP_EOL;'
    ;;
  upgrade)
    test_legacy="$test_runtime/legacy/exin-black-friday"
    mkdir -p "$test_runtime/legacy"
    python3 - "$test_root/releases/exin-black-friday-v1.0.0.zip" "$test_runtime/legacy" <<'PY'
import sys, zipfile
with zipfile.ZipFile(sys.argv[1]) as archive:
    archive.extractall(sys.argv[2])
PY
    trap '"${test_compose[@]}" up -d --force-recreate wordpress web >/dev/null' EXIT
    EXIN_V101_PLUGIN_SOURCE="$test_legacy" "${test_compose[@]}" up -d --force-recreate wordpress web
    "${test_compose[@]}" cp "$test_root/tests/integration/v101-upgrade.php" wordpress:/tmp/v101-upgrade.php
    test_upgrade_wp=("${test_wp[@]}" '--exec=putenv("WORDPRESS_TABLE_PREFIX=exin_upgrade_");')
    if ! "${test_upgrade_wp[@]}" core is-installed; then
      "${test_upgrade_wp[@]}" core install --url=http://localhost:8891 --title='EXIN actual release upgrade' --admin_user=exin_test --admin_password=exin-local-only --admin_email=test@example.invalid --skip-email
    fi
    "${test_upgrade_wp[@]}" plugin activate woocommerce
    "${test_upgrade_wp[@]}" option update woocommerce_custom_orders_table_enabled yes
    "${test_upgrade_wp[@]}" plugin activate exin-black-friday
    "${test_upgrade_wp[@]}" eval-file /tmp/v101-upgrade.php prepare
    "${test_compose[@]}" up -d --force-recreate wordpress web
    "${test_compose[@]}" cp "$test_root/tests/integration/v101-upgrade.php" wordpress:/tmp/v101-upgrade.php
    "${test_upgrade_wp[@]}" eval-file /tmp/v101-upgrade.php verify
    trap - EXIT
    ;;
  stop)
    "${test_compose[@]}" stop
    ;;
  *) exit 2 ;;
esac
