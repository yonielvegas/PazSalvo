#!/usr/bin/env python3
"""Validate a prepared release and the response actually served by Apache/PHP-FPM."""

import grp
import http.client
import json
import os
import re
import ssl
import stat
import sys
from html.parser import HTMLParser
from pathlib import Path, PurePosixPath
from urllib.parse import urlsplit

HOST = "pazysalvo.aaud.gob.pa"
ORIGIN = "https://127.0.0.1"
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


def ssl_context():
    """Trust store used to verify the institutional TLS certificate."""
    return ssl.create_default_context()


class PinnedHTTPSConnection(http.client.HTTPSConnection):
    """Reach the loopback origin while presenting HOST for SNI and certificate checks."""

    def __init__(self, connect_host, port, server_hostname, context, timeout):
        super().__init__(connect_host, port, timeout=timeout, context=context)
        self._server_hostname = server_hostname

    def connect(self):
        http.client.HTTPConnection.connect(self)
        self.sock = self._context.wrap_socket(self.sock, server_hostname=self._server_hostname)


def _send(path, host, origin, timeout):
    parts = urlsplit(origin)
    require(parts.scheme == "https", "Release validation must use HTTPS")
    require(parts.hostname is not None, "Release validation origin is missing a host")
    connection = PinnedHTTPSConnection(parts.hostname, parts.port or 443, host, ssl_context(), timeout)
    try:
        connection.request("GET", path, headers={"Host": host, "Cache-Control": "no-cache"})
        response = connection.getresponse()
        return response.status, response.getheader("Location"), response.read()
    finally:
        connection.close()


def get(path):
    status, location, body = _send(path, HOST, ORIGIN, timeout=10)
    if 300 <= status < 400:
        destination = location or "unknown"
        reason = "redirects to login" if location and "/login" in location else "unexpected redirect"
        raise ValueError(f"HTTP {status} for {path} ({reason}); Location: {destination}")
    require(status == 200, f"HTTP {status}: {path}")
    return body


def smoke(release, expected_sha, mode="strict"):
    require(mode in ("strict", "legacy-rollback"), "Invalid smoke validation mode")
    require(re.fullmatch(r"[a-f0-9]{40}", expected_sha), "Invalid expected SHA")
    require((release / "RELEASE_SHA").read_text().strip() == expected_sha, "Release SHA mismatch")
    expected_assets = manifest_files(release)
    def check_health():
        health = json.loads(get("/healthz"))
        require(health.get("status") == "ok", "Health check is degraded")
        if "release" in health:
            require(health["release"] == expected_sha,
                    f"Served release SHA mismatch: expected {expected_sha}, served {health['release']}")
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
    except (ValueError, OSError, KeyError, json.JSONDecodeError, http.client.HTTPException) as error:
        print(f"Release validation failed: {error}", file=sys.stderr)
        sys.exit(1)
