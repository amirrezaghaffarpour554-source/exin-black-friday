"""Validate release boundary and byte-for-byte reproducibility."""
from pathlib import Path
import importlib.util
import json
import zipfile

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("build", root / "scripts/build.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
target = module.build()
first = target.read_bytes()
module.build()
assert target.read_bytes() == first, "Release build must be deterministic"
with zipfile.ZipFile(target) as archive:
    names = archive.namelist()
    assert names and all(n.startswith("exin-black-friday/") for n in names)
    assert "exin-black-friday/exin-black-friday.php" in names
    forbidden = {"vendor", "node_modules", "tests", "work", "scripts", ".git", ".env"}
    assert all(not forbidden.intersection(Path(n).parts) for n in names)
    assert archive.testzip() is None
    for name in names:
        assert ".." not in Path(name).parts and not name.startswith("/")
        assert archive.read(name) == (root / name.removeprefix("exin-black-friday/")).read_bytes()
manifest = json.loads((target.parent / "manifest.json").read_text())
assert len(manifest) == len(names)
print(f"PASS: package integrity, single-root layout, development-file exclusion, reproducibility ({len(names)} files)")
