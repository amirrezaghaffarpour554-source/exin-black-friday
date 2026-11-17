#!/usr/bin/env python3
"""Build an allowlisted, reproducible WordPress install ZIP without dev files."""
from pathlib import Path
import hashlib
import json
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
DEST = ROOT / "work" / "dist"
ALLOW = ("exin-black-friday.php", "autoload.php", "uninstall.php", "src", "assets",
         "languages", "README.md", "CHANGELOG.md", "readme.txt", "LICENSE", "docs")


def build():
    DEST.mkdir(parents=True, exist_ok=True)
    required = ("exin-black-friday.php", "autoload.php", "uninstall.php", "src/Plugin.php",
                "README.md", "CHANGELOG.md", "readme.txt", "LICENSE")
    for name in required:
        if not (ROOT / name).is_file():
            raise RuntimeError(f"Required release file missing: {name}")
    files = []
    for name in ALLOW:
        path = ROOT / name
        if path.is_symlink():
            raise RuntimeError(f"Symlink not allowed: {name}")
        if path.is_dir():
            files.extend(p for p in path.rglob("*") if p.is_file())
        elif path.is_file():
            files.append(path)
    bootstrap = (ROOT / "exin-black-friday.php").read_text(encoding="utf-8")
    header = re.search(r"^\s*\* Version:\s*(\d+\.\d+\.\d+)\s*$", bootstrap, re.MULTILINE)
    constant = re.search(r"define\(\s*'EXIN_BF_VERSION',\s*'(\d+\.\d+\.\d+)'\s*\)", bootstrap)
    if not header or not constant or header.group(1) != constant.group(1):
        raise RuntimeError("Plugin header and runtime version must agree")
    version = header.group(1)
    readme = (ROOT / "readme.txt").read_text(encoding="utf-8")
    if not re.search(r"^Stable tag:\s*" + re.escape(version) + r"\s*$", readme, re.MULTILINE):
        raise RuntimeError("WordPress readme stable tag must match the plugin version")
    target = DEST / f"exin-black-friday-v{version}.zip"
    manifest = {}
    with zipfile.ZipFile(target, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted(files):
            if path.is_symlink() or not path.resolve().is_relative_to(ROOT):
                raise RuntimeError(f"Unsafe source path: {path}")
            rel = path.relative_to(ROOT).as_posix()
            if any(part.startswith(".") for part in Path(rel).parts):
                raise RuntimeError(f"Hidden file not allowed: {rel}")
            content = path.read_bytes()
            info = zipfile.ZipInfo("exin-black-friday/" + rel, date_time=(2026, 11, 17, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, content)
            manifest[rel] = hashlib.sha256(content).hexdigest()
    digest = hashlib.sha256(target.read_bytes()).hexdigest()
    (DEST / "SHA256SUMS").write_text(f"{digest}  {target.name}\n", encoding="utf-8")
    (DEST / "manifest.json").write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"{target}: {len(manifest)} files, {target.stat().st_size} bytes, sha256={digest}")
    return target


if __name__ == "__main__":
    build()
