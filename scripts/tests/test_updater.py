"""Offline regression tests: release validation and transaction failure handling."""
import argparse
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[2] / "update-daytracker.py"
spec = importlib.util.spec_from_file_location("updater", SCRIPT)
u = importlib.util.module_from_spec(spec)
spec.loader.exec_module(u)


def archive(path, extra=None):
    info = b'<info><id>daytracker</id><version>3.0.3</version><dependencies><php min-version="8.2"/><nextcloud min-version="33" max-version="34"/><database>pgsql</database></dependencies></info>'
    with tarfile.open(path, "w:gz") as tar:
        for name, data in {"daytracker/appinfo/info.xml": info, "daytracker/appinfo/routes.php": b"<?php",
                           "daytracker/lib/AppInfo/Application.php": b"<?php", "daytracker/templates/main.php": b"<?php"}.items():
            entry = tarfile.TarInfo(name)
            entry.size = len(data)
            tar.addfile(entry, io.BytesIO(data))
        if extra:
            tar.addfile(extra)


class FakeDocker:
    def __init__(self, failure=None, enabled="yes"):
        self.failure = failure
        self.enabled = enabled
        self.installed = "3.0.2"
        self.calls = []
        self.swapped = False
        self.maintenance = False

    def shell(self, script, *args):
        self.calls.append(("shell", script))
        if script.startswith("mktemp"):
            return "/var/www/html/.daytracker-update-test123"
        if script.startswith('test "$(stat -c %d') and self.failure == "filesystem":
            raise u.UpdateError("different filesystems")
        if script.startswith('mv "$1"'):
            self.swapped = True
            if self.failure == "swap":
                raise u.UpdateError("swap failed")
        if script.startswith('if [ -d "$2/previous"'):
            self.swapped = False
        return ""

    def command(self, *args):
        self.calls.append(args)
        if args[0] == "restart" and self.failure == "restart":
            raise u.UpdateError("restart failed")
        return ""

    def status(self):
        return {"installed": True, "needsDbUpgrade": self.installed != "3.0.3", "maintenance": self.maintenance}

    def occ(self, *args):
        self.calls.append(args)
        if args[0] == "maintenance:mode":
            self.maintenance = args[1] == "--on"
        if args[0] in ("upgrade", "app:enable"):
            if self.failure == "migration":
                raise u.UpdateError("migration failed")
            self.installed = "3.0.3"
        if args[0] == "app:enable":
            self.enabled = "yes"
        if args[0] == "app:disable":
            self.enabled = "no"
        if args[0] == "config:app:get":
            return self.installed if args[2] == "installed_version" else self.enabled
        return "OK"

    def backup(self, directory, *, full_database=False):
        self.calls.append(("backup",))
        if self.failure == "backup":
            raise u.UpdateError("disk full")


class UpdaterTests(unittest.TestCase):
    def test_scoped_backup_includes_sequences_and_only_app_state(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            docker = u.Docker("nextcloud", "db")
            queries, commands = [], []

            def sql(query):
                queries.append(query)
                if "c.relname" in query:
                    return "\n".join("nc_daytracker_" + name for name in ("categories", "entries", "options", "timeslices"))
                if "s.relname" in query:
                    return "nc_daytracker_entries_id_seq"
                return json.dumps([{"appid": "daytracker", "configvalue": "O'Brien\\test\nvalue"}])

            def command(*args, output=None, input_file=None):
                commands.append(args)
                if output:
                    output.write(b"-- dump\n")
                return ""

            with patch.object(docker, "occ", return_value="nc_"), patch.object(docker, "sql", side_effect=sql), patch.object(docker, "command", side_effect=command):
                docker.backup_daytracker(root)
            dump = next(call for call in commands if "pg_dump" in call)
            self.assertIn('"public"."nc_daytracker_entries_id_seq"', dump)
            self.assertNotIn('"public"."nc_appconfig"', dump)
            state = (root / "daytracker-state.sql").read_text(encoding="utf-8")
            self.assertIn("O''Brien", state)
            self.assertEqual(state.count("DELETE FROM"), 3)
            self.assertIn("WHERE app='daytracker'", state)
            self.assertEqual(state.count("WHERE appid='daytracker'"), 2)
            self.assertIn(state, (root / "restore-daytracker.sql").read_text(encoding="utf-8"))

    def test_missing_tables_abort_backup(self):
        with tempfile.TemporaryDirectory() as temp:
            docker = u.Docker("nextcloud", "db")
            with patch.object(docker, "occ", return_value="oc_"), patch.object(docker, "sql", return_value="oc_daytracker_entries"), patch.object(docker, "command") as command:
                with self.assertRaises(u.UpdateError):
                    docker.backup_daytracker(Path(temp))
                command.assert_not_called()

    def test_full_database_backup_is_opt_in(self):
        self.assertFalse(u.arguments([]).full_db_backup)
        self.assertTrue(u.arguments(["--full-db-backup"]).full_db_backup)

    def test_versions_and_major_bounds(self):
        import xml.etree.ElementTree as ET
        dep = ET.fromstring('<nextcloud min-version="33" max-version="34"/>')
        self.assertTrue(u.compatible("34.0.4", dep))
        self.assertFalse(u.compatible("35.0.0", dep))
        self.assertFalse(u.compatible("32.0.9", dep))
        self.assertGreater(u.version_tuple("3.0.10"), u.version_tuple("3.0.9"))
        for value in ("3.0.3;id", "../3.0.3", "3.0.3-rc1", "03.0.3"):
            with self.assertRaises(u.UpdateError):
                u.version_tuple(value)

    def test_archive_rejects_traversal_and_links(self):
        for name, kind in (("daytracker/../../escape", tarfile.REGTYPE), ("/escape", tarfile.REGTYPE),
                           ("daytracker/link", tarfile.SYMTYPE), ("daytracker/device", tarfile.CHRTYPE)):
            with self.subTest(name=name), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                member = tarfile.TarInfo(name)
                member.type = kind
                member.linkname = "/etc/passwd"
                archive(root / "app.tar.gz", member)
                with self.assertRaises(u.UpdateError):
                    u.unpack(root / "app.tar.gz", root / "out")
                self.assertFalse((root / "out").exists())

    def release_fixture(self, root, checksum_bad=False, target="3.0.3"):
        bundle = root / "fixture.tar.gz"
        archive(bundle)
        content = bundle.read_bytes()
        filename = f"daytracker-{target}.tar.gz"
        digest = "0" * 64 if checksum_bad else hashlib.sha256(content).hexdigest()
        release = {"tag_name": "v" + target, "assets": [
            {"name": name, "browser_download_url": f"https://github.com/{u.REPO}/releases/download/v{target}/{name}"}
            for name in (filename, filename + ".sha256")]}

        def fake_download(url, destination, limit):
            if "api.github.com" in url:
                destination.write_text(json.dumps(release))
            elif url.endswith(".sha256"):
                destination.write_text(f"{digest}  {filename}\n")
            else:
                destination.write_bytes(content)
        return fake_download

    def test_latest_and_explicit_version(self):
        for requested in ("latest", "3.0.3"):
            with self.subTest(requested=requested), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                with patch.object(u, "download", self.release_fixture(root)):
                    target, app, digest = u.prepare_release(requested, root, "3.0.2", "34.0.4", "8.5.0")
                    self.assertEqual(target, "3.0.3")
                    self.assertTrue((app / "appinfo/info.xml").exists())

    def test_checksum_and_incompatible_server_rejected(self):
        for bad_checksum, server in ((True, "34.0.4"), (False, "35.0.0")):
            with self.subTest(checksum=bad_checksum), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                with patch.object(u, "download", self.release_fixture(root, checksum_bad=bad_checksum)):
                    with self.assertRaises(u.UpdateError):
                        u.prepare_release("latest", root, "3.0.2", server, "8.5.0")

    def test_downgrade_is_rejected_before_asset_download(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            with patch.object(u, "download", self.release_fixture(root)):
                with self.assertRaisesRegex(u.UpdateError, "Downgrade"):
                    u.prepare_release("latest", root, "3.0.10", "34.0.4", "8.5.0")
            self.assertFalse((root / "daytracker-3.0.3.tar.gz").exists())

    def transaction(self, root, failure=None, enabled="yes", enable=False):
        docker = FakeDocker(failure, enabled)
        args = argparse.Namespace(backup_dir=root, container="nextcloud-test", database_container="db", no_restart=False, enable=enable, full_db_backup=False)
        return u.Updater(docker, args), docker

    def test_success_preserves_active_and_disabled_state(self):
        for enabled, enable in (("yes", False), ("no", False), ("no", True), ('["team"]', False)):
            with self.subTest(enabled=enabled, enable=enable), tempfile.TemporaryDirectory() as temp:
                updater, docker = self.transaction(temp, enabled=enabled, enable=enable)
                updater.perform(Path(temp), "3.0.3", "3.0.2", enabled, "0" * 64)
                self.assertFalse(docker.maintenance)
                self.assertEqual(docker.installed, "3.0.3")
                self.assertEqual(docker.enabled, "yes" if enable else enabled)
                self.assertLess(docker.calls.index(("backup",)), next(i for i, call in enumerate(docker.calls) if call[0] == "shell" and call[1].startswith('mv "$1"')))

    def test_failures_before_migration_restore_files_and_maintenance(self):
        for failure in ("backup", "swap"):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as temp:
                updater, docker = self.transaction(temp, failure=failure)
                with self.assertRaises(u.UpdateError):
                    updater.perform(Path(temp), "3.0.3", "3.0.2", "yes", "0" * 64)
                updater.recover()
                self.assertFalse(docker.swapped)
                self.assertFalse(docker.maintenance)
                self.assertEqual(docker.installed, "3.0.2")

    def test_cross_filesystem_exchange_refused_before_maintenance(self):
        with tempfile.TemporaryDirectory() as temp:
            updater, docker = self.transaction(temp, failure="filesystem")
            with self.assertRaises(u.UpdateError):
                updater.perform(Path(temp), "3.0.3", "3.0.2", "yes", "0" * 64)
            updater.recover()
            self.assertFalse(docker.swapped)
            self.assertNotIn(("backup",), docker.calls)
            self.assertNotIn(("maintenance:mode", "--on"), docker.calls)

    def test_migration_or_restart_failure_keeps_maintenance_and_new_files(self):
        for failure in ("migration", "restart"):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as temp:
                updater, docker = self.transaction(temp, failure=failure)
                with self.assertRaises(u.UpdateError):
                    updater.perform(Path(temp), "3.0.3", "3.0.2", "yes", "0" * 64)
                updater.recover()
                self.assertTrue(docker.swapped)
                self.assertTrue(docker.maintenance)
                self.assertIsNotNone(updater.stage)


if __name__ == "__main__":
    unittest.main()
