#!/usr/bin/env python3
"""Reproducibly package one TaskFlow v3 source ZIP and one slim deployment ZIP.

No third-party packages. Refuses to package any local secrets or symlink.
Usage: python3 bin/build-release.py [--out /path/to/output-directory]
"""
from __future__ import annotations
import argparse
import hashlib
import os
import sys
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED

ROOT = Path(__file__).resolve().parent.parent
PUBLIC_VERSION = "v3"
SECRET_NAMES = {".env", ".env.local", ".installed", ".setup-key", "config.local.php", "id_rsa", "id_ed25519"}
FORBIDDEN_SUFFIXES = {".pyc", ".log", ".swp", ".sqlite", ".sqlite3", ".db", ".pem", ".key", ".p12", ".pfx"}
SKIP_DIRS = {".git", "dist", "__pycache__", ".pytest_cache", "node_modules", "vendor", ".venv", "venv"}
RUNTIME_SKIP_DIRS = {"tests", ".github"}
RUNTIME_EXACT = {"README.md", "SECURITY.md", "CHANGELOG.md", "Dockerfile", ".dockerignore", ".gitignore", ".gitattributes", "bin/build-release.py"}
RUNTIME_DOCS_KEEP = {
    "docs/UPGRADE.md", "docs/DEPLOYMENT.md", "docs/RELEASE-v3.md", "docs/RELEASE-CHECKLIST.md", "docs/RELEASE-PUBLIC.md",
    "docs/STAGING-ACCEPTANCE.md",
    "docs/deployment/nginx-taskflow.conf.example",
    "docs/deployment/clamav-attachment-scanning.md",
}
REQUIRED_SOURCE = {
    "VERSION", "README.md", "index.php", "schema.sql", ".htaccess", "bin/release-preflight.php",
    "api/task.php", "api/task_reviews.php", "config/config.php", "config/config.local.php.example",
    "includes/transport_policy.php", "includes/remember_cookie.php", "includes/attachment_scan.php",
    "docs/migrations/v3-discussions.sql", "docs/migrations/v3-task-reviews.sql", ".github/workflows/ci.yml",
    "docs/STAGING-ACCEPTANCE.md", "docs/deployment/clamav-attachment-scanning.md",
    "tests/mysql_http_seed.php", "tests/authenticated_http_smoke.py",
}
REQUIRED_RUNTIME = {
    "VERSION", "index.php", "schema.sql", ".htaccess", "bin/release-preflight.php",
    "api/task.php", "api/task_reviews.php", "config/config.php", "config/config.local.php.example",
    "includes/transport_policy.php", "includes/remember_cookie.php", "includes/attachment_scan.php",
    "docs/migrations/v3-discussions.sql", "docs/migrations/v3-task-reviews.sql",
    "docs/STAGING-ACCEPTANCE.md", "docs/deployment/clamav-attachment-scanning.md",
}


def file_list(runtime: bool) -> list[tuple[Path, str]]:
    results = []
    for source in ROOT.rglob("*"):
        rel = source.relative_to(ROOT)
        if any(part in SKIP_DIRS for part in rel.parts):
            continue
        if source.is_symlink():
            raise ValueError(f"Refusing symlink: {rel}")
        if not source.is_file():
            continue
        path = rel.as_posix()
        if source.name in SECRET_NAMES or source.suffix.lower() in FORBIDDEN_SUFFIXES:
            raise ValueError(f"Refusing sensitive or runtime file: {path}")
        if runtime:
            if any(part in RUNTIME_SKIP_DIRS for part in rel.parts) or path in RUNTIME_EXACT:
                continue
            if rel.parts[0] == "docs" and not (path in RUNTIME_DOCS_KEEP or path.startswith("docs/migrations/")):
                continue
        results.append((source, path))
    return sorted(results, key=lambda x: x[1])


def make_zip(path: Path, entries: list[tuple[Path, str]], required: set[str]) -> str:
    names = {name for _, name in entries}
    missing = required - names
    if missing:
        raise ValueError("Required release files are missing: " + ", ".join(sorted(missing)))
    with ZipFile(path, "w", compression=ZIP_DEFLATED, compresslevel=8) as archive:
        for src, name in entries:
            data = src.read_bytes()
            entry = ZipInfo(name, (2024, 1, 1, 0, 0, 0))
            entry.compress_type = ZIP_DEFLATED
            entry.external_attr = ((0o755 if name.startswith("bin/") and os.access(src, os.X_OK) else 0o644) << 16)
            archive.writestr(entry, data, compress_type=ZIP_DEFLATED, compresslevel=8)
    with ZipFile(path) as archive:
        assert archive.testzip() is None, "ZIP CRC validation failed"
        assert {item.filename for item in archive.infolist()} == names, "ZIP entry list mismatch"
        assert archive.read("VERSION").strip() == PUBLIC_VERSION.encode(), "Unexpected public version"
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser(description="Build reproducible TaskFlow v3 release archives")
    parser.add_argument("--out", default=str(ROOT / "dist"), help="output folder")
    args = parser.parse_args()
    out = Path(args.out).resolve()
    # Only the known, excluded dist/ directory may live under the source tree.
    # Any other in-tree destination would be bundled on the *second* run and
    # break reproducibility, potentially leaking previously generated files.
    if out == ROOT or out in ROOT.parents or (ROOT in out.parents and out != ROOT / "dist"):
        parser.error("Use an external output directory or the default source dist/ folder")
    if (ROOT / "VERSION").read_text().strip() != PUBLIC_VERSION:
        parser.error("Release version drift: expected v3")
    try:
        source_files = file_list(runtime=False)
        runtime_files = file_list(runtime=True)
        out.mkdir(parents=True, exist_ok=True)
        source_zip = out / "TaskFlow-v3-Public-GitHub-Ready.zip"
        runtime_zip = out / "TaskFlow-v3-Public-Deploy.zip"
        source_hash = make_zip(source_zip, source_files, REQUIRED_SOURCE)
        runtime_hash = make_zip(runtime_zip, runtime_files, REQUIRED_RUNTIME)
        (out / "SHA256SUMS.txt").write_text(
            f"{source_hash}  {source_zip.name}\n{runtime_hash}  {runtime_zip.name}\n", encoding="ascii"
        )
        print(f"SOURCE {source_zip.name}: {len(source_files)} files, {source_zip.stat().st_size:,} bytes")
        print(f"RUNTIME {runtime_zip.name}: {len(runtime_files)} files, {runtime_zip.stat().st_size:,} bytes")
        print("SHA256 manifests written; neither archive contains local credentials or runtime secrets.")
        return 0
    except (ValueError, OSError, AssertionError) as exc:
        print(f"Release build refused: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
