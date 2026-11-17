#!/usr/bin/env bash
# Install the exact final artifact; keep proof outside the ZIP to avoid self-reference.
set -euo pipefail
test_root="$(cd "$(dirname "$0")/../.." && pwd)"
test_archive="${1:?Pass the exact final local release ZIP path}"
test_proof="${2:-$test_root/releases/v1.0.1/package-install.json}"
test_cli=("$test_root/scripts/test-v101-native.sh" wp '--exec=putenv("WORDPRESS_TABLE_PREFIX=exin_package_");')
"$test_root/scripts/test-v101-native.sh" setup prepare-package "$test_archive"
trap '"$test_root/scripts/test-v101-native.sh" setup use-source >/dev/null' EXIT
"$test_root/scripts/test-v101-native.sh" setup use-package
if ! "${test_cli[@]}" core is-installed; then
  "${test_cli[@]}" core install --url=http://localhost:8891 --title='EXIN final ZIP install' --admin_user=exin_test --admin_password=exin-local-only --admin_email=test@example.invalid --skip-email
fi
"${test_cli[@]}" plugin activate woocommerce
"${test_cli[@]}" option update woocommerce_custom_orders_table_enabled yes
"${test_cli[@]}" plugin activate exin-black-friday
"${test_cli[@]}" eval-file "$test_root/scripts/runtime/package-proof.php" prepare
"${test_cli[@]}" plugin deactivate exin-black-friday
"${test_cli[@]}" plugin activate exin-black-friday
"${test_cli[@]}" eval-file "$test_root/scripts/runtime/package-proof.php" verify > "$test_root/work/v101/package-lifecycle.json"
"$test_root/scripts/test-v101-native.sh" setup package-proof "$test_archive" > "$test_root/work/v101/package-files.json"
python3 - "$test_root/work/v101/package-lifecycle.json" "$test_root/work/v101/package-files.json" "$test_proof" <<'PY'
from pathlib import Path
from datetime import datetime,timezone
import json,sys
result=json.loads(Path(sys.argv[2]).read_text())
result['runtime']=json.loads(Path(sys.argv[1]).read_text())
result['verified_at_utc']=datetime.now(timezone.utc).isoformat()
target=Path(sys.argv[3]);target.parent.mkdir(parents=True,exist_ok=True)
target.write_text(json.dumps(result,indent=2)+'\n')
print(str(target)+': '+result['archive_sha256']+'; '+str(result['installed_files_verified'])+' installed files match exact ZIP')
PY
