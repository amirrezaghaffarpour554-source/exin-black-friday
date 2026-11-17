#!/usr/bin/env bash
# Replaces only the disposable site's plugin symlink; never an installed shop.
set -euo pipefail
test_root="$(cd "$(dirname "$0")/../.." && pwd)"
test_cli=("$test_root/scripts/test-v101-native.sh" wp '--exec=putenv("WORDPRESS_TABLE_PREFIX=exin_upgrade_");')
"$test_root/scripts/test-v101-native.sh" setup prepare-legacy
trap '"$test_root/scripts/test-v101-native.sh" setup use-source >/dev/null' EXIT
"$test_root/scripts/test-v101-native.sh" setup use-legacy
if ! "${test_cli[@]}" core is-installed; then
  "${test_cli[@]}" core install --url=http://localhost:8891 --title='EXIN actual release upgrade' --admin_user=exin_test --admin_password=exin-local-only --admin_email=test@example.invalid --skip-email
fi
"${test_cli[@]}" plugin activate woocommerce
"${test_cli[@]}" option update woocommerce_custom_orders_table_enabled yes
"${test_cli[@]}" option update timezone_string Asia/Tehran
"${test_cli[@]}" plugin activate exin-black-friday
"${test_cli[@]}" eval-file "$test_root/tests/integration/v101-upgrade.php" prepare
"$test_root/scripts/test-v101-native.sh" setup use-source
"${test_cli[@]}" eval-file "$test_root/tests/integration/v101-upgrade.php" verify
