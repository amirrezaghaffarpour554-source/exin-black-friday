#!/usr/bin/env bash
# Run the verified official PHP 8.2 binary from its local rootfs without Docker.
set -euo pipefail
runtime_root="$(cd "$(dirname "$0")/.." && pwd)/work/v101/native-php"
if [ ! -x "$runtime_root/usr/local/bin/php" ]; then
  printf '%s\n' 'Native PHP runtime is not prepared; see work/v101/runtime recovery notes.' >&2
  exit 2
fi
extension_root="$runtime_root/usr/local/lib/php/extensions/no-debug-non-zts-20220829"
exec "$runtime_root/lib/ld-musl-x86_64.so.1" --library-path "$runtime_root/lib:$runtime_root/usr/lib" "$runtime_root/usr/local/bin/php" -n -d "extension_dir=$extension_root" -d extension=mysqli -d extension=bcmath -d extension=sodium -d openssl.cafile=/etc/ssl/certs/ca-certificates.crt -d curl.cainfo=/etc/ssl/certs/ca-certificates.crt "$@"
