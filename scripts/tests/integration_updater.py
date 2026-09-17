"""Run only in the disposable GitHub Actions Docker test job."""
import json
import os
from pathlib import Path
import subprocess


def run(*args, ok=True):
    result = subprocess.run(args, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    print(result.stdout, flush=True)
    if ok:
        assert result.returncode == 0, (args, result.returncode)
    else:
        assert result.returncode != 0, args
    return result.stdout


def occ(*args):
    return run("docker", "exec", "-u", "www-data", "nextcloud", "php", "occ", *args).strip()


def status():
    text = occ("status", "--output=json")
    return json.loads(text[text.index("{"):])


def data():
    return run("docker", "exec", "db", "psql", "-U", "nextcloud", "-d", "nextcloud", "-Atc",
               "SELECT text_value FROM oc_daytracker_entries WHERE user_id='admin' AND entry_date='2026-09-17'").strip()


def main():
    command = ["sudo", "python3", "update-daytracker.py", "--container", "nextcloud", "--database-container", "db",
               "--backup-dir", "/var/backups/daytracker-ci"]
    requested = ["v3.0.3"] if os.environ["NC_VERSION"] == "33" else []
    before = occ("config:app:get", "daytracker", "installed_version")
    assert before == "3.0.2"
    assert data() == "Daten bleiben erhalten"
    run(*command, *requested, "--dry-run")
    assert occ("config:app:get", "daytracker", "installed_version") == before
    assert not status()["maintenance"]
    # Refuse non-interactive updates without explicit consent.
    run(*command, *requested, ok=False)
    assert occ("config:app:get", "daytracker", "installed_version") == before
    run(*command, *requested, "--yes", "--enable")
    assert occ("config:app:get", "daytracker", "installed_version") == "3.0.3"
    assert occ("config:app:get", "daytracker", "enabled") == "yes"
    assert not status()["maintenance"] and not status()["needsDbUpgrade"]
    assert data() == "Daten bleiben erhalten"
    run("docker", "exec", "nextcloud", "test", "!", "-e", "/var/www/html/custom_apps/daytracker/obsolete-file.txt")
    run("docker", "exec", "nextcloud", "sh", "-ec",
        'test "$(stat -c %U:%G /var/www/html/custom_apps/daytracker/appinfo/info.xml)" = www-data:www-data')
    # Check that an actual PostgreSQL dump can be read and the old app was backed up.
    backup = run("sudo", "find", "/var/backups/daytracker-ci", "-name", "database.dump").strip()
    assert len(backup.splitlines()) == 1
    parent = str(Path(backup).parent)
    run("sudo", "test", "-s", parent + "/config.tar.gz")
    run("sudo", "test", "-s", parent + "/update.log")
    old_xml = run("sudo", "tar", "-xOf", parent + "/app.tar.gz", "custom_apps/daytracker/appinfo/info.xml")
    assert "<version>3.0.2</version>" in old_xml
    dump = subprocess.Popen(["sudo", "cat", backup], stdout=subprocess.PIPE)
    restore = subprocess.run(["docker", "exec", "-i", "db", "pg_restore", "--list"], stdin=dump.stdout, stdout=subprocess.PIPE)
    dump.stdout.close()
    assert dump.wait() == 0 and restore.returncode == 0 and b"daytracker_entries" in restore.stdout
    no_op = run(*command, *requested, "--yes")
    assert "bereits installiert" in no_op
    run(*command, "v3.0.0", "--yes", ok=False)
    # Reinstall a disabled app: retain its disabled state unless --enable was requested.
    occ("app:disable", "daytracker")
    run(*command, "v3.0.3", "--yes", "--reinstall")
    assert occ("config:app:get", "daytracker", "enabled") == "no"
    assert data() == "Daten bleiben erhalten" and not status()["maintenance"]
    print("PASS: real update, latest/explicit selection, consent, backups, data preservation, rights, restart, no-op, downgrade refusal and disabled state")


if __name__ == "__main__":
    main()
