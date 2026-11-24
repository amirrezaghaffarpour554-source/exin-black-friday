#!/usr/bin/env bash
# Local-only active upgrade and file-rollback equivalent, isolated database prefix.
set -euo pipefail
upgrade_root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$upgrade_root"
mkdir -p work/performance
if [ ! -f work/performance/base/exin-black-friday.php ]; then
 python3 - <<'PY'
import pathlib,subprocess,tarfile,io
root=pathlib.Path('work/performance/base');root.mkdir(exist_ok=True)
data=subprocess.check_output(['git','archive','64d7458fa7b863d49e0821fddf671515bc3f0a48'])
with tarfile.open(fileobj=io.BytesIO(data)) as t:
 for member in t.getmembers():
  if member.isfile() and (member.name.startswith('src/') or member.name in ('autoload.php','exin-black-friday.php')):
   p=root/member.name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(t.extractfile(member).read())
PY
fi
native=(bash scripts/test-v101-native.sh wp '--exec=putenv("WORDPRESS_TABLE_PREFIX=exin_upgrade_audit_");')
if ! "${native[@]}" core is-installed; then
 "${native[@]}" core install --url=http://localhost:8891 --title='EXIN isolated upgrade audit' --admin_user=upgrade_test --admin_password=local-upgrade-only --admin_email=test@example.invalid --skip-email
fi
"${native[@]}" plugin activate woocommerce
"${native[@]}" option update woocommerce_custom_orders_table_enabled yes
bash scripts/test-v101-native.sh wp --skip-plugins=exin-black-friday '--exec=putenv("EXIN_UPGRADE_PHASE=install_loader");' eval-file tests/integration/release-upgrade.php
for upgrade_phase in prepare verify_new verify_old; do
 upgrade_source="$upgrade_root/work/performance/base"
 if [ "$upgrade_phase" = verify_new ]; then upgrade_source="$upgrade_root"; fi
 upgrade_exec="putenv(\"WORDPRESS_TABLE_PREFIX=exin_upgrade_audit_\");putenv(\"EXIN_UPGRADE_ROOT=$upgrade_source\");putenv(\"EXIN_UPGRADE_PHASE=$upgrade_phase\");"
 bash scripts/test-v101-native.sh wp --skip-plugins=exin-black-friday "--exec=$upgrade_exec" eval-file tests/integration/release-upgrade.php
done
