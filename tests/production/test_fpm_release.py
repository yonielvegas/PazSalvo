"""Exercise physical release identity with an isolated FPM, never system services."""

import os
import json
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
    def test_restart_replaces_cached_release_and_physical_dir_including_rollback(self):
        self.exercise_transition(restart=True)

    def test_completed_graceful_reload_also_refreshes_cache_in_local_fpm(self):
        self.exercise_transition(restart=False)

    def exercise_transition(self, *, restart):
        with tempfile.TemporaryDirectory(prefix="pazsalvo-fpm-") as directory:
            base = Path(directory)
            current = base / "current"
            for name in ("A", "B"):
                release = base / name
                (release / "public").mkdir(parents=True)
                controller = release / "app/Http/Controllers/HealthCheckController.php"
                controller.parent.mkdir(parents=True)
                # Use the exact release lookup performed by HealthCheckController.
                controller.write_text("""<?php echo json_encode([
                    'release' => trim(file_get_contents(dirname(__DIR__, 3).'/RELEASE_SHA')),
                    'directory' => __DIR__, 'worker' => getmypid(),
                    'opcache' => opcache_get_status(false),
                ]);
""")
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
php_admin_value[opcache.revalidate_path] = 0
; New fixture scripts must be cached immediately, without waiting for file age.
php_admin_value[opcache.file_update_protection] = 0
php_admin_value[realpath_cache_ttl] = 600
""")
            command = [FPM, "--nodaemonize", "--fpm-config", str(config)]
            if os.getuid() == 0:
                command.append("--allow-to-run-as-root")
            def start():
                return subprocess.Popen(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

            def stop(process):
                if process.poll() is None:
                    process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)

            def renew(process):
                if restart:
                    old_master = process.pid
                    stop(process)
                    replacement = start()
                    self.assertNotEqual(replacement.pid, old_master)
                    return replacement
                # Compare with an actually completed reload, not merely sending
                # SIGUSR2 as ExecReload does. This avoids attributing persistent
                # stale opcodes to reload if this FPM build does clear them.
                log = base / "fpm.log"
                ready_count = log.read_text().count("ready to handle connections")
                process.send_signal(signal.SIGUSR2)
                deadline = time.monotonic() + 5
                while log.read_text().count("ready to handle connections") <= ready_count:
                    if process.poll() is not None or time.monotonic() >= deadline:
                        self.fail("Isolated graceful reload did not complete: " + log.read_text())
                return process

            process = start()
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
                                return json.loads(output.split(b"\r\n\r\n", 1)[1])

                def first_ready_response():
                    # Wait only for transport readiness, never for an expected SHA:
                    # a stale successful response must fail the assertion immediately.
                    deadline = time.monotonic() + 5
                    while time.monotonic() < deadline:
                        if process.poll() is not None:
                            self.fail((base / "fpm.log").read_text())
                        try:
                            return fetch()
                        except (OSError, ConnectionError):
                            continue
                    self.fail("Isolated FPM did not accept a FastCGI request")

                def assert_release(response, name):
                    self.assertEqual(response['release'], name.lower() * 40)
                    self.assertEqual(response['directory'], str(base / name / 'app/Http/Controllers'))
                    self.assertTrue(response['opcache']['opcache_enabled'])
                    self.assertGreaterEqual(response['opcache']['opcache_statistics']['num_cached_scripts'], 2)

                old_response = first_ready_response()
                assert_release(old_response, "A")
                replacement = base / ".current"
                replacement.symlink_to(base / "B")
                replacement.replace(current)
                # Demonstrate stale physical __DIR__ despite current pointing to B.
                self.assertEqual(current.resolve(), base / "B")
                assert_release(fetch(), "A")
                process = renew(process)
                new_response = first_ready_response()
                assert_release(new_response, "B")
                self.assertNotEqual(new_response['worker'], old_response['worker'])
                replacement.symlink_to(base / "A")
                replacement.replace(current)
                assert_release(fetch(), "B")
                process = renew(process)
                restored_response = first_ready_response()
                assert_release(restored_response, "A")
                self.assertNotEqual(restored_response['worker'], new_response['worker'])
            finally:
                stop(process)


if __name__ == "__main__":
    unittest.main()
