#!/usr/bin/env bash
# Fallback only: no daemon startup, host install, production data or persistent DB.
set -euo pipefail
test_root="$(cd "$(dirname "$0")/../.." && pwd)"
if [ ! -x "$test_root/work/v101/native-php/usr/local/bin/php" ] || [ ! -x "$test_root/work/v101/native-db-root/usr/sbin/mariadbd" ]; then
  printf '%s\n' 'Verified native PHP/MariaDB runtime files are absent. Use scripts/test-v101-runtime.sh up (Docker) or prepare the documented trusted native artifacts.' >&2
  exit 2
fi
mkdir -p "$test_root/work/v101/jobs"
python3 - "$test_root" <<'PYGUARD'
from pathlib import Path
import os,sys,time
base=Path(sys.argv[1])/'work/v101'
pidfile=base/'native-worker.pid'
if pidfile.exists():
 try:
  pid=int(pidfile.read_text());os.kill(pid,0)
  if 'native-worker.py' in (Path('/proc')/str(pid)/'cmdline').read_bytes().decode():raise SystemExit('Native worker is already running; use scripts/test-v101-native.sh')
 except (OSError,ValueError):pass
stale=list((base/'jobs').glob('*.request.json'))+list((base/'jobs').glob('*.running.json'))
if stale:
 archive=base/'jobs-interrupted'/str(time.time_ns());archive.mkdir(parents=True)
 for entry in stale:entry.rename(archive/entry.name)
PYGUARD
exec python3 "$test_root/scripts/runtime/native-worker.py"
