#!/usr/bin/env bash
set -euo pipefail
test_root="$(cd "$(dirname "$0")/.." && pwd)"
export EXIN_TEST_ROOT="$test_root"
test_compose=(docker compose -f "$test_root/tests/integration/compose.yaml")
test_store_wp=("${test_compose[@]}" exec -T -e WORDPRESS_TABLE_PREFIX=exin_store_api_ wordpress php /usr/local/bin/wp --allow-root)
if ! "${test_store_wp[@]}" core is-installed; then
  "${test_store_wp[@]}" core install --url=http://localhost:8880 --title='EXIN isolated Store API' --admin_user=store_test --admin_password=local-store-only --admin_email=store@example.invalid --skip-email
fi
"${test_store_wp[@]}" plugin activate woocommerce exin-black-friday
"${test_store_wp[@]}" option update woocommerce_custom_orders_table_enabled yes
"${test_store_wp[@]}" eval-file /var/www/html/wp-content/plugins/exin-black-friday/tests/integration/store-api.php
