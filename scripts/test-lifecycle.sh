#!/usr/bin/env bash
set -euo pipefail
test_root="$(cd "$(dirname "$0")/.." && pwd)"
export EXIN_TEST_ROOT="$test_root"
test_compose=(docker compose -f "$test_root/tests/integration/compose.yaml")
test_wp=("${test_compose[@]}" exec -T wordpress php /usr/local/bin/wp --allow-root)
"${test_wp[@]}" --skip-plugins=woocommerce eval 'if (class_exists("WooCommerce")) { throw new RuntimeException("WooCommerce unexpectedly loaded"); } echo "PASS dependency absence: WooCommerce\n";'
"${test_wp[@]}" --skip-plugins=elementor eval 'if (class_exists("Elementor\\Widget_Base") || !shortcode_exists("exin_bf_products")) { throw new RuntimeException("Missing Elementor fallback"); } echo "PASS dependency absence: Elementor\n";'
"${test_wp[@]}" eval-file /var/www/html/wp-content/plugins/exin-black-friday/tests/integration/lifecycle.php
test_uninstall_prefix="exin_uninstall_$(date +%s)_"
test_uninstall_wp=("${test_compose[@]}" exec -T -e WORDPRESS_TABLE_PREFIX="$test_uninstall_prefix" wordpress php /usr/local/bin/wp --allow-root)
if ! "${test_uninstall_wp[@]}" core is-installed; then
  "${test_uninstall_wp[@]}" core install --url=http://localhost:8880 --title='EXIN isolated uninstall' --admin_user=uninstall_test --admin_password=local-uninstall-only --admin_email=uninstall@example.invalid --skip-email
fi
"${test_uninstall_wp[@]}" plugin activate woocommerce exin-black-friday
"${test_uninstall_wp[@]}" eval-file /var/www/html/wp-content/plugins/exin-black-friday/tests/integration/uninstall.php
