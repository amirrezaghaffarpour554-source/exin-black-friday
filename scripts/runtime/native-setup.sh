#!/usr/bin/env bash
set -euo pipefail
test_root="$(cd "$(dirname "$0")/../.." && pwd)"
test_ram="${EXIN_NATIVE_RAM:?Native setup must run inside the private RAM worker}"
test_wp="$test_ram/wordpress"
[ "$test_ram" = /dev/shm/exin-v101 ]
case "${1:-}" in
  web|prepare-elementor|prepare-legacy|use-legacy|use-source|prepare-package|use-package|package-proof)
    exec python3 "$test_root/scripts/runtime/native-services.py" "$@" ;;
esac
mkdir -p "$test_ram/downloads"
test_core_zip="$test_root/work/v101/runtime/wordpress-7.1.4.zip"
if ! python3 - "$test_core_zip" <<'PYCHECK'
import sys,zipfile
p=sys.argv[1]
try:
 with zipfile.ZipFile(p) as z: assert z.testzip() is None
except Exception: raise SystemExit(1)
PYCHECK
then
 curl -fsSL --max-time 120 https://codeload.github.com/WordPress/WordPress/zip/refs/tags/7.1.4 -o "$test_core_zip.part"
 mv "$test_core_zip.part" "$test_core_zip"
fi
python3 - "$test_root" "$test_ram" <<'PY'
from pathlib import Path
import shutil,sys,zipfile,json
root=Path(sys.argv[1]);ram=Path(sys.argv[2]);wp=ram/'wordpress'
if not (wp/'wp-includes/version.php').exists():
 wp.mkdir(exist_ok=True)
 with zipfile.ZipFile(root/'work/v101/runtime/wordpress-7.1.4.zip') as z:
  source=z.namelist()[0].split('/')[0]+'/'
  for i in z.infolist():
   rel=i.filename.removeprefix(source)
   if not rel:continue
   assert '..' not in Path(rel).parts
   p=wp/rel
   if i.is_dir():p.mkdir(parents=True,exist_ok=True)
   else:p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(z.read(i))
local=root/'work/v101/native-wp'
if local.is_symlink():local.unlink()
elif local.exists():raise RuntimeError('Refusing to replace an unexpected non-symlink WordPress tree')
local.symlink_to(wp,target_is_directory=True)
data=root/'work/v101/native-db';target=ram/'native-db'
if not target.exists():
 if data.is_symlink():data.unlink()
 elif data.exists():
  assert not (data/'server.pid').exists(), 'Original synthetic DB must be cleanly stopped'
  shutil.copytree(data,target);shutil.rmtree(data)
 if not target.exists():target.mkdir()
if not data.is_symlink():data.symlink_to(target,target_is_directory=True)
mu=wp/'wp-content/mu-plugins';mu.mkdir(exist_ok=True)
shutil.copy2(root/'tests/integration/v101-mu-guard.php',mu/'exin-test-guard.php')
plugins=wp/'wp-content/plugins';plugins.mkdir(exist_ok=True)
link=plugins/'exin-black-friday'
if not link.exists():link.symlink_to(root,target_is_directory=True)
if not (plugins/'woocommerce/woocommerce.php').exists():
 with zipfile.ZipFile(root/'work/v101/runtime/woocommerce.zip') as z:z.extractall(plugins)
config=wp/'wp-config.php'
if not config.exists():
 config.write_text('''<?php
 define('DB_NAME','exin_test');define('DB_USER','root');define('DB_PASSWORD','');
 define('DB_HOST','localhost:'''+str(root/'work/v101/native-mariadb.sock')+'''');
 define('DB_CHARSET','utf8mb4');define('DB_COLLATE','');
 $table_prefix=getenv('WORDPRESS_TABLE_PREFIX') ?: 'wp_';
 define('WP_ENVIRONMENT_TYPE','local');define('DISABLE_WP_CRON',true);
 define('WP_DEBUG',true);define('WP_DEBUG_DISPLAY',false);
 define('WP_DEBUG_LOG','''+repr(str(root/'work/v101/native-wp-debug.log'))+''');
 define('WP_HTTP_BLOCK_EXTERNAL',true);define('WP_ACCESSIBLE_HOSTS','localhost,127.0.0.1');
 define('AUTH_KEY','exin-local-fixture-only');define('SECURE_AUTH_KEY','exin-local-fixture-only');
 define('LOGGED_IN_KEY','exin-local-fixture-only');define('NONCE_KEY','exin-local-fixture-only');
 define('AUTH_SALT','exin-local-fixture-only');define('SECURE_AUTH_SALT','exin-local-fixture-only');
 define('LOGGED_IN_SALT','exin-local-fixture-only');define('NONCE_SALT','exin-local-fixture-only');
 if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');require_once ABSPATH.'wp-settings.php';
 ''')
print('Full official WP7.1.4 and full Woo11.3 source ready in private RAM')
PY
python3 "$test_root/scripts/runtime/start-native-db.py"
for test_wait in $(seq 1 60); do
  if "$test_root/scripts/php-v101.sh" -r 'mysqli_report(MYSQLI_REPORT_OFF); $m=@new mysqli("localhost","root","",null,null,getcwd()."/work/v101/native-mariadb.sock");exit($m->connect_errno?1:0);'; then break; fi
  sleep .2
done
"$test_root/scripts/php-v101.sh" -r '$m=new mysqli("localhost","root","",null,null,getcwd()."/work/v101/native-mariadb.sock");$m->query("CREATE DATABASE IF NOT EXISTS exin_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");echo "Real MariaDB: ",$m->server_info," GET_LOCK=",$m->query("SELECT GET_LOCK(\"exin-native-proof\",0)")->fetch_row()[0],PHP_EOL;'
test_cli=("$test_root/scripts/php-v101.sh" "$test_root/work/runtime/wp-cli.phar" --path="$test_wp" --allow-root)
if ! "${test_cli[@]}" core is-installed; then
  "${test_cli[@]}" core install --url=http://localhost:8891 --title='EXIN isolated native v1.0.1' --admin_user=exin_test --admin_password=exin-local-only --admin_email=test@example.invalid --skip-email
fi
"${test_cli[@]}" plugin activate woocommerce
"${test_cli[@]}" option update timezone_string Asia/Tehran
"${test_cli[@]}" option update woocommerce_custom_orders_table_enabled yes
"${test_cli[@]}" plugin activate exin-black-friday
"${test_cli[@]}" eval 'echo wp_json_encode(array("wordpress"=>get_bloginfo("version"),"woocommerce"=>WC_VERSION,"php"=>PHP_VERSION,"database"=>$GLOBALS["wpdb"]->db_version(),"hpos"=>\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled())),PHP_EOL;'
