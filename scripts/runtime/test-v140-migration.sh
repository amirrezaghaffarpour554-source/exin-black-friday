#!/usr/bin/env bash
# Fresh prefix in the isolated native DB; never touch Production or reset existing tables.
set -euo pipefail
cd "$(dirname "$0")/../.."
mkdir -p work/v140 work/dist
migration_prefix="exin140_upgrade_$(date +%s)_"
migration_exec="putenv(\"WORDPRESS_TABLE_PREFIX=$migration_prefix\");putenv(\"EXIN140_BASELINE_PATH=$PWD/work/v140/migration-baseline.json\");"
client=(bash scripts/test-v101-native.sh)
trap '"${client[@]}" setup use-source' EXIT
cp releases/v1.3.2/exin-black-friday-v1.3.2.zip work/dist/
"${client[@]}" setup prepare-package "$PWD/work/dist/exin-black-friday-v1.3.2.zip"
"${client[@]}" setup use-package
"${client[@]}" wp "--exec=$migration_exec" core install --url=http://exin140.invalid --title='Isolated migration fixture' --admin_user=exin140 --admin_password=exin-fixture-only-140 --admin_email=fixture@example.invalid --skip-email
"${client[@]}" wp "--exec=$migration_exec" plugin activate woocommerce
"${client[@]}" wp "--exec=$migration_exec" option update woocommerce_custom_orders_table_enabled yes
"${client[@]}" wp "--exec=$migration_exec" plugin activate exin-black-friday
"${client[@]}" wp "--exec=$migration_exec" eval-file "$PWD/tests/integration/v140-seed.php"
"${client[@]}" setup use-source
"${client[@]}" wp "--exec=$migration_exec" eval-file "$PWD/tests/integration/v140-migration.php"

if [ "${EXIN140_TEST_ROLLBACK:-0}" = 1 ]; then
  "${client[@]}" wp "--exec=$migration_exec putenv(\"EXIN140_ROLLBACK_PHASE=prepare\");" eval-file "$PWD/tests/integration/v140-rollback.php"
  "${client[@]}" setup use-package
  "${client[@]}" wp "--exec=$migration_exec putenv(\"EXIN140_ROLLBACK_PHASE=old\");" eval-file "$PWD/tests/integration/v140-rollback.php"
  "${client[@]}" setup use-source
  "${client[@]}" wp "--exec=$migration_exec putenv(\"EXIN140_ROLLBACK_PHASE=forward\");" eval-file "$PWD/tests/integration/v140-rollback.php"
fi
