#!/usr/bin/env bash
set -euo pipefail
test_root="$(cd "$(dirname "$0")/.." && pwd)"
test_runtime="$test_root/work/runtime"
export EXIN_TEST_ROOT="$test_root"
compose=(docker compose -f "$test_root/tests/integration/compose.yaml")
case "${1:-test}" in
  up)
    mkdir -p "$test_runtime"
    [ -f "$test_runtime/wp-cli.phar" ] || curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$test_runtime/wp-cli.phar"
    [ -f "$test_runtime/woocommerce.zip" ] || curl -fsSL https://github.com/woocommerce/woocommerce/releases/download/10.3.0/woocommerce.zip -o "$test_runtime/woocommerce.zip"
    "${compose[@]}" up -d
    for test_wait in $(seq 1 60); do
      if "${compose[@]}" exec -T wordpress test -f /var/www/html/wp-config.php; then break; fi
      sleep 1
    done
    if ! "${compose[@]}" exec -T wordpress php /usr/local/bin/wp --allow-root core is-installed; then
      "${compose[@]}" exec -T wordpress php /usr/local/bin/wp --allow-root core install --url=http://localhost:8880 --title='EXIN isolated integration' --admin_user=exin_test --admin_password=exin-local-only --admin_email=test@example.invalid --skip-email
    fi
    if "${compose[@]}" exec -T wordpress php /usr/local/bin/wp --allow-root plugin is-installed woocommerce; then
      "${compose[@]}" exec -T wordpress php /usr/local/bin/wp --allow-root plugin activate woocommerce
    else
      "${compose[@]}" exec -T wordpress php /usr/local/bin/wp --allow-root plugin install /tmp/woocommerce.zip --activate
    fi
    "${compose[@]}" exec -T wordpress php /usr/local/bin/wp --allow-root option update timezone_string Asia/Tehran
    "${compose[@]}" exec -T wordpress php /usr/local/bin/wp --allow-root option update woocommerce_custom_orders_table_enabled yes
    "${compose[@]}" exec -T wordpress php /usr/local/bin/wp --allow-root plugin activate exin-black-friday
    ;;
  elementor)
    [ -f "$test_runtime/elementor.zip" ] || curl -fsSL https://github.com/elementor/elementor/releases/download/3.34.0/elementor-3.34.0.zip -o "$test_runtime/elementor.zip"
    "${compose[@]}" cp "$test_runtime/elementor.zip" wordpress:/tmp/elementor.zip
    if "${compose[@]}" exec -T wordpress php /usr/local/bin/wp --allow-root plugin is-installed elementor; then
      "${compose[@]}" exec -T wordpress php /usr/local/bin/wp --allow-root plugin activate elementor
    else
      "${compose[@]}" exec -T wordpress php /usr/local/bin/wp --allow-root plugin install /tmp/elementor.zip --activate
    fi
    ;;
  wp)
    shift
    "${compose[@]}" exec -T wordpress php /usr/local/bin/wp --allow-root "$@"
    ;;
  php)
    shift
    "${compose[@]}" exec -T wordpress php "$@"
    ;;
  test)
    "${compose[@]}" exec -T wordpress php /usr/local/bin/wp --allow-root eval-file /var/www/html/wp-content/plugins/exin-black-friday/tests/integration/run.php
    ;;
  down)
    "${compose[@]}" down -v
    ;;
  *) exit 2 ;;
esac
