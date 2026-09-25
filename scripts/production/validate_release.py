#!/usr/bin/env python3
"""Validate a prepared release and the response actually served by Apache/PHP-FPM."""

import grp
import json
import os
import re
import stat
import sys
import urllib.error
import urllib.request
from html.parser import HTMLParser
from pathlib import Path, PurePosixPath
from urllib.parse import urlsplit

HOST = "pazsalvo.aaud.local"
ORIGIN = "http://127.0.0.1"
ENTRIES = ("resources/js/app.tsx", "resources/css/app.css")


def require(condition, message):
    if not condition:
        raise ValueError(message)


def manifest_files(release):
    build = release / "public/build"
    manifest = json.loads((build / "manifest.json").read_text())
    require(all(entry in manifest for entry in ENTRIES), "Missing Vite entry in manifest")
    for record in manifest.values():
        for name in [record.get("file"), *record.get("css", []), *record.get("assets", [])]:
            if name is None:
                continue
            path = PurePosixPath(name)
            require(not path.is_absolute() and ".." not in path.parts, "Unsafe manifest path")
            require((build / name).is_file(), f"Missing manifest asset: {name}")
    expected = set()
    for entry in ENTRIES:
        record = manifest[entry]
        expected.add("/build/" + record["file"])
        expected.update("/build/" + name for name in record.get("css", []))
    return expected


def preflight(release, shared):
    require(release.is_dir() and not release.is_symlink(), "Invalid release directory")
    require((release / "public/index.php").is_file(), "Missing public/index.php")
    require((release / "artisan").is_file(), "Missing artisan")
    require((release / "vendor/autoload.php").is_file(), "Missing Composer autoload")
    require(not (release / ".env.testing").exists(), "Unexpected .env.testing")
    require((release / ".env").is_symlink() and os.readlink(release / ".env") == str(shared / ".env"), "Invalid .env symlink")
    require((release / "storage").is_symlink() and os.readlink(release / "storage") == str(shared / "storage"), "Invalid storage symlink")
    group = grp.getgrnam("www-data").gr_gid
    root_stat = release.stat()
    require(root_stat.st_gid == group and stat.S_IMODE(root_stat.st_mode) == 0o2750, "Invalid release root group/mode")
    require((release / "public").stat().st_mode & stat.S_IXGRP, "Apache cannot traverse public")
    require((release / "public/index.php").stat().st_mode & stat.S_IRGRP, "Apache cannot read index.php")
    cache = release / "bootstrap/cache"
    require(cache.is_dir() and not cache.is_symlink(), "Invalid bootstrap/cache")
    cache_stat = cache.stat()
    require(cache_stat.st_gid == group and stat.S_IMODE(cache_stat.st_mode) == 0o2770, "Invalid bootstrap/cache group/mode")
    require((release / "public/build/manifest.json").is_file(), "Missing Vite manifest")
    manifest_files(release)


class AssetParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.assets = set()

    def handle_starttag(self, tag, attrs):
        for key, value in attrs:
            if key not in ("src", "href") or not value:
                continue
            url = urlsplit(value)
            try:
                same_site = (
                    (not url.scheme and not url.netloc)
                    or (
                        url.scheme in ("http", "https")
                        and url.hostname == HOST
                        and url.port in (None, 80 if url.scheme == "http" else 443)
                        and url.username is None
                        and url.password is None
                    )
                )
            except ValueError:
                continue
            if same_site and re.fullmatch(r"/build/assets/[^?#]+\.(?:js|css)", url.path):
                self.assets.add(url.path)


def get(path):
    request = urllib.request.Request(ORIGIN + path, headers={"Host": HOST, "Cache-Control": "no-cache"})
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, request, fp, code, msg, headers, new_url):
            return None

    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    with opener.open(request, timeout=10) as response:
        require(response.status == 200, f"HTTP {response.status}: {path}")
        return response.read()


def smoke(release, expected_sha, mode="strict"):
    require(mode in ("strict", "legacy-rollback"), "Invalid smoke validation mode")
    require(re.fullmatch(r"[a-f0-9]{40}", expected_sha), "Invalid expected SHA")
    require((release / "RELEASE_SHA").read_text().strip() == expected_sha, "Release SHA mismatch")
    expected_assets = manifest_files(release)
    def check_health():
        health = json.loads(get("/healthz"))
        require(health.get("status") == "ok", "Health check is degraded")
        if "release" in health:
            require(health["release"] == expected_sha, "Served release SHA mismatch")
        else:
            require(mode == "legacy-rollback", "Served release SHA missing")

    check_health()
    parser = AssetParser()
    parser.feed(get("/login").decode("utf-8"))
    require(expected_assets <= parser.assets, "HTML does not reference current manifest entries")
    require(any(path.endswith(".js") for path in parser.assets), "HTML has no JS asset")
    require(any(path.endswith(".css") for path in parser.assets), "HTML has no CSS asset")
    for path in sorted(parser.assets):
        get(path)
    check_health()


if __name__ == "__main__":
    try:
        command = sys.argv[1]
        if command == "preflight" and len(sys.argv) == 4:
            preflight(Path(sys.argv[2]), Path(sys.argv[3]))
        elif command == "smoke" and len(sys.argv) in (4, 5):
            smoke(Path(sys.argv[2]), sys.argv[3], sys.argv[4] if len(sys.argv) == 5 else "strict")
        else:
            raise ValueError("Usage: validate_release.py preflight RELEASE SHARED | smoke RELEASE SHA [strict|legacy-rollback]")
    except (ValueError, OSError, KeyError, json.JSONDecodeError, urllib.error.URLError) as error:
        print(f"Release validation failed: {error}", file=sys.stderr)
        sys.exit(1)
