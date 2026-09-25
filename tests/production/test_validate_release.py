import importlib.util
import json
import os
import sys
import tempfile
import unittest
import urllib.error
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[2] / "scripts/production/validate_release.py"
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("validate_release", SCRIPT)
validator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(validator)
SHA = "a" * 40


class ReleaseValidationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.release = self.base / "releases/20260925-140125-aaaaaaa"
        self.shared = self.base / "shared"
        self.release.mkdir(parents=True)
        (self.shared / "storage").mkdir(parents=True)
        (self.shared / ".env").write_text("APP_ENV=production")
        for name in ("artisan", "vendor/autoload.php", "public/index.php", "RELEASE_SHA"):
            path = self.release / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(SHA if name == "RELEASE_SHA" else "x")
        (self.release / ".env").symlink_to(self.shared / ".env")
        (self.release / "storage").symlink_to(self.shared / "storage")
        cache = self.release / "bootstrap/cache"
        cache.mkdir(parents=True)
        cache.chmod(0o2770)
        self.release.chmod(0o2750)
        build = self.release / "public/build"
        (build / "assets").mkdir(parents=True)
        self.js = "/build/assets/app-new.js"
        self.css = "/build/assets/app-new.css"
        (build / "assets/app-new.js").write_text("js")
        (build / "assets/app-new.css").write_text("css")
        (build / "manifest.json").write_text(json.dumps({
            "resources/js/app.tsx": {"file": "assets/app-new.js", "css": ["assets/app-new.css"]},
            "resources/css/app.css": {"file": "assets/app-new.css"},
        }))
        self.group = patch.object(validator.grp, "getgrnam", return_value=SimpleNamespace(gr_gid=os.getgid()))
        self.group.start()
        self.addCleanup(self.group.stop)

    def test_preflight_accepts_valid_release(self):
        validator.preflight(self.release, self.shared)

    def test_preflight_rejects_invalid_permissions_and_links(self):
        self.release.chmod(0o2700)
        with self.assertRaisesRegex(ValueError, "root group/mode"):
            validator.preflight(self.release, self.shared)
        self.release.chmod(0o2750)
        (self.release / ".env").unlink()
        (self.release / ".env").symlink_to(self.base / "wrong.env")
        with self.assertRaisesRegex(ValueError, ".env symlink"):
            validator.preflight(self.release, self.shared)
        (self.release / ".env").unlink()
        (self.release / ".env").symlink_to(self.shared / ".env")
        (self.release / "storage").unlink()
        (self.release / "storage").symlink_to(self.base / "wrong-storage")
        with self.assertRaisesRegex(ValueError, "storage symlink"):
            validator.preflight(self.release, self.shared)

    def test_preflight_rejects_cache_and_manifest_problems(self):
        cache = self.release / "bootstrap/cache"
        cache.chmod(0o2750)
        with self.assertRaisesRegex(ValueError, "bootstrap/cache group/mode"):
            validator.preflight(self.release, self.shared)
        cache.chmod(0o2770)
        (self.release / "public/build/manifest.json").unlink()
        with self.assertRaisesRegex(ValueError, "Vite manifest"):
            validator.preflight(self.release, self.shared)

    def test_preflight_rejects_missing_manifest_asset_and_testing_env(self):
        (self.release / "public/build/assets/app-new.js").unlink()
        with self.assertRaisesRegex(ValueError, "Missing manifest asset"):
            validator.preflight(self.release, self.shared)
        (self.release / "public/build/assets/app-new.js").write_text("js")
        (self.release / ".env.testing").write_text("test")
        with self.assertRaisesRegex(ValueError, ".env.testing"):
            validator.preflight(self.release, self.shared)

    def responses(self, *, sha=SHA, login_status=200, asset_status=200, html=None):
        html = html or f'<script src="{self.js}"></script><link href="{self.css}" rel="stylesheet">'

        def response(path):
            if path == "/healthz":
                return json.dumps({"status": "ok", "release": sha}).encode()
            if path == "/login":
                if login_status != 200:
                    raise urllib.error.HTTPError(path, login_status, "error", {}, None)
                return html.encode()
            if asset_status != 200:
                raise urllib.error.HTTPError(path, asset_status, "error", {}, None)
            return b"asset"

        return response

    def test_smoke_checks_sha_login_manifest_and_assets(self):
        with patch.object(validator, "get", side_effect=self.responses()) as get:
            validator.smoke(self.release, SHA)
            self.assertEqual({call.args[0] for call in get.call_args_list},
                             {"/healthz", "/login", self.js, self.css})
        for response, error in (
            (self.responses(sha="b" * 40), "SHA mismatch"),
            (self.responses(login_status=403), "HTTP Error 403"),
            (self.responses(asset_status=404), "HTTP Error 404"),
            (self.responses(html='<script src="/build/assets/app-old.js"></script>'), "manifest entries"),
        ):
            with self.subTest(error=error), patch.object(validator, "get", side_effect=response):
                with self.assertRaisesRegex(Exception, error):
                    validator.smoke(self.release, SHA)


if __name__ == "__main__":
    unittest.main()
