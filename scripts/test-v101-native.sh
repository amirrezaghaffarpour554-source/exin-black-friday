#!/usr/bin/env bash
# Test-only queue into one private RAM namespace; the normal reproducible stack uses Docker.
set -euo pipefail
test_root="$(cd "$(dirname "$0")/.." && pwd)"
exec python3 "$test_root/scripts/runtime/native-client.py" "$@"
