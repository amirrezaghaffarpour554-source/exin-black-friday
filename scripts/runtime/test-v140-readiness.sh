#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
mkdir -p work/v140
ready_prefix="exin140_ready_$(date +%s)_"
printf '%s' "$ready_prefix" > work/v140/readiness-prefix.txt
ready_exec="putenv(\"WORDPRESS_TABLE_PREFIX=$ready_prefix\");"
client=(bash scripts/test-v101-native.sh)
"${client[@]}" wp "--exec=$ready_exec" core install --url=http://exin-readiness.invalid --title='Isolated readiness' --admin_user=fixture --admin_password=isolated-fixture-only --admin_email=fixture@example.invalid --skip-email
"${client[@]}" wp "--exec=$ready_exec" plugin activate woocommerce
"${client[@]}" wp "--exec=$ready_exec" option update woocommerce_custom_orders_table_enabled yes
"${client[@]}" wp "--exec=$ready_exec" plugin activate exin-black-friday
"${client[@]}" wp "--exec=$ready_exec" eval-file "$PWD/tests/integration/v140-readiness.php"
"${client[@]}" wp "--exec=$ready_exec" eval-file "$PWD/tests/integration/v140-readiness-overlap.php"
