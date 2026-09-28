"""Exercise physical release identity with an isolated FPM, never system services."""

import os
import pwd
import shutil
import signal
import socket
import struct
import subprocess
import tempfile
import time
import unittest
from pathlib import Path


FPM = shutil.which("php-fpm8.3")


def record(kind, body=b""):
    return struct.pack("!BBHHBB", 1, kind, 1, len(body), 0, 0) + body


@unittest.skipUnless(FPM, "Isolated PHP 8.3-FPM binary not installed")
class FpmReleaseTest(unittest.TestCase):
    def test_cached_release_changes_only_after_fpm_reload_including_rollback(self):
        with tempfile.TemporaryDirectory(prefix="pazsalvo-fpm-") as directory:
            base = Path(directory)
            current = base / "current"
            for name in ("A", "B"):
                release = base / name
                (release / "public").mkdir(parents=True)
                controller = release / "app/Http/Controllers/HealthCheckController.php"
                controller.parent.mkdir(parents=True)
                # Use the exact release lookup performed by HealthCheckController.
                controller.write_text("<?php echo trim(file_get_contents(dirname(__DIR__, 3).'/RELEASE_SHA'));\n")
                (release / "RELEASE_SHA").write_text(name.lower() * 40)
                (release / "public/index.php").write_text(
                    "<?php require __DIR__.'/../app/Http/Controllers/HealthCheckController.php';\n"
                )
            current.symlink_to(base / "A")
            endpoint = str(base / "fpm.sock")
            config = base / "fpm.conf"
            user = pwd.getpwuid(os.getuid()).pw_name
            config.write_text(f"""[global]
pid = {base}/fpm.pid
error_log = {base}/fpm.log
daemonize = no
[fixture]
user = {user}
listen = {endpoint}
pm = static
pm.max_children = 1
php_admin_value[opcache.enable] = 1
php_admin_value[opcache.validate_timestamps] = 0
php_admin_value[realpath_cache_ttl] = 600
""")
            command = [FPM, "--nodaemonize", "--fpm-config", str(config)]
            if os.getuid() == 0:
                command.append("--allow-to-run-as-root")
            process = subprocess.Popen(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            try:
                def fetch():
                    params = {"SCRIPT_FILENAME": str(current / "public/index.php"),
                              "REQUEST_METHOD": "GET", "SCRIPT_NAME": "/healthz",
                              "SERVER_PROTOCOL": "HTTP/1.1", "SERVER_NAME": "pazysalvo.aaud.gob.pa"}
                    encoded = b"".join(bytes((len(key), len(value))) + key.encode() + value.encode()
                                       for key, value in params.items())
                    with socket.socket(socket.AF_UNIX) as connection:
                        connection.settimeout(0.5)
                        connection.connect(endpoint)
                        connection.sendall(record(1, struct.pack("!HB5x", 1, 0)) +
                                           record(4, encoded) + record(4) + record(5))
                        output = b""

                        def read(size):
                            data = b""
                            while len(data) < size:
                                chunk = connection.recv(size - len(data))
                                if not chunk:
                                    raise ConnectionError("FPM closed FastCGI response")
                                data += chunk
                            return data

                        while True:
                            _, kind, _, size, padding, _ = struct.unpack("!BBHHBB", read(8))
                            body = read(size)
                            read(padding)
                            if kind == 6:
                                output += body
                            if kind == 3:
                                return output.split(b"\r\n\r\n", 1)[1].decode().strip()

                def await_sha(expected):
                    deadline = time.monotonic() + 5
                    actual = None
                    while time.monotonic() < deadline:
                        if process.poll() is not None:
                            self.fail((base / "fpm.log").read_text())
                        try:
                            actual = fetch()
                            if actual == expected:
                                return
                        except (OSError, ConnectionError):
                            continue
                    self.fail(f"Expected SHA {expected}, served {actual}")

                await_sha("a" * 40)
                replacement = base / ".current"
                replacement.symlink_to(base / "B")
                replacement.replace(current)
                # Demonstrate stale physical __DIR__ despite current pointing to B.
                self.assertEqual(current.resolve(), base / "B")
                self.assertEqual(fetch(), "a" * 40)
                # systemd's php8.3-fpm ExecReload sends this same graceful signal.
                process.send_signal(signal.SIGUSR2)
                await_sha("b" * 40)
                replacement.symlink_to(base / "A")
                replacement.replace(current)
                self.assertEqual(fetch(), "b" * 40)
                process.send_signal(signal.SIGUSR2)
                await_sha("a" * 40)
            finally:
                if process.poll() is None:
                    process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)


if __name__ == "__main__":
    unittest.main()
