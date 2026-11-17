#!/usr/bin/env bash
set -euo pipefail
test_root="$(cd "$(dirname "$0")/.." && pwd)"
export EXIN_TEST_ROOT="$test_root"
case "${EXIN_TEST_RUNTIME:-v101}" in
  v101)
    test_compose=(docker compose -f "$test_root/tests/integration/v101-compose.yaml")
    test_store_wp=("${test_compose[@]}" exec -T -e WORDPRESS_TABLE_PREFIX=exin_store_api_ wordpress php /usr/local/bin/wp --allow-root)
    test_url=http://localhost:8891
    ;;
  v100)
    test_compose=(docker compose -f "$test_root/tests/integration/compose.yaml")
    test_store_wp=("${test_compose[@]}" exec -T -e WORDPRESS_TABLE_PREFIX=exin_store_api_ wordpress php /usr/local/bin/wp --allow-root)
    test_url=http://localhost:8880
    ;;
  native)
    test_store_wp=(bash "$test_root/scripts/test-v101-native.sh" wp '--exec=putenv("WORDPRESS_TABLE_PREFIX=exin_store_api_");')
    test_url=http://localhost:8891
    ;;
  *)
    echo 'EXIN_TEST_RUNTIME must be v101, v100 or native.' >&2
    exit 2
    ;;
esac
if ! "${test_store_wp[@]}" core is-installed; then
  "${test_store_wp[@]}" core install --url="$test_url" --title='EXIN isolated Store API' --admin_user=store_test --admin_password=local-store-only --admin_email=store@example.invalid --skip-email
fi
"${test_store_wp[@]}" plugin activate woocommerce exin-black-friday
"${test_store_wp[@]}" option update woocommerce_custom_orders_table_enabled yes
"${test_store_wp[@]}" eval-file /var/www/html/wp-content/plugins/exin-black-friday/tests/integration/store-api.php
