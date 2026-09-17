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
    backup = run("sudo", "find", "/var/backups/daytracker-ci", "-name", "daytracker.dump").strip()
    assert not run("sudo", "find", "/var/backups/daytracker-ci", "-name", "database.dump").strip()
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
    run(*command, "v3.0.3", "--yes", "--reinstall", "--full-db-backup")
    assert occ("config:app:get", "daytracker", "enabled") == "no"
    assert data() == "Daten bleiben erhalten" and not status()["maintenance"]
    full = run("sudo", "find", "/var/backups/daytracker-ci", "-name", "database.dump").strip()
    assert len(full.splitlines()) == 1
    second = str(Path(full).parent)
    # Restore the second backup after changing schema, data, sequence and app state.
    def sql(query):
        return run("docker", "exec", "db", "psql", "-X", "-U", "nextcloud", "-d", "nextcloud", "-v", "ON_ERROR_STOP=1", "-Atc", query).strip()

    def shared_state():
        return [sql(f"SELECT COALESCE(json_agg(t ORDER BY row_to_json(t)::text),'[]') FROM {table} t WHERE {key}='daytracker'")
                for table, key in (("oc_appconfig", "appid"), ("oc_migrations", "app"), ("oc_preferences", "appid"))]

    saved = shared_state()
    sequence = sql("SELECT pg_get_serial_sequence('oc_daytracker_entries','id')")
    sequence_state = sql(f"SELECT last_value, is_called FROM {sequence}")
    occ("maintenance:mode", "--on")
    sql("UPDATE oc_daytracker_entries SET text_value='changed'; ALTER TABLE oc_daytracker_entries ADD COLUMN rollback_test integer; "
        "UPDATE oc_appconfig SET configvalue='9.9.9' WHERE appid='daytracker' AND configkey='installed_version'; "
        "DELETE FROM oc_migrations WHERE app='daytracker'; DELETE FROM oc_preferences WHERE appid='daytracker'; "
        "INSERT INTO oc_appconfig (appid,configkey,configvalue) VALUES ('other_test_app','sentinel','keep');")
    sql(f"SELECT setval('{sequence}',90000,true)")
    source = subprocess.Popen(["sudo", "cat", second + "/restore-daytracker.sql"], stdout=subprocess.PIPE)
    restored = subprocess.run(["docker", "exec", "-i", "db", "psql", "-X", "-U", "nextcloud", "-d", "nextcloud",
                               "--single-transaction", "-v", "ON_ERROR_STOP=1", "--file=-"], stdin=source.stdout)
    source.stdout.close()
    assert source.wait() == 0 and restored.returncode == 0
    assert data() == "Daten bleiben erhalten"
    assert shared_state() == saved
    assert sql(f"SELECT last_value, is_called FROM {sequence}") == sequence_state
    assert sql("SELECT configvalue FROM oc_appconfig WHERE appid='other_test_app' AND configkey='sentinel'") == "keep"
    assert sql("SELECT count(*) FROM information_schema.columns WHERE table_name='oc_daytracker_entries' AND column_name='rollback_test'") == "0"
    # Replacing the app directory also removes files introduced after the backup.
    run("docker", "exec", "nextcloud", "touch", "/var/www/html/custom_apps/daytracker/rollback-test.txt")
    stage = run("docker", "exec", "nextcloud", "mktemp", "-d", "/var/www/html/.daytracker-restore-XXXXXXXX").strip()
    run("sudo", "docker", "cp", second + "/app.tar.gz", "nextcloud:" + stage + "/app.tar.gz")
    run("docker", "exec", "nextcloud", "sh", "-ec",
        'tar -xzf "$1/app.tar.gz" -C "$1"; mv /var/www/html/custom_apps/daytracker "$1/failed"; '
        'mv "$1/custom_apps/daytracker" /var/www/html/custom_apps/daytracker; '
        'chown -R www-data:www-data /var/www/html/custom_apps/daytracker', "sh", stage)
    run("docker", "restart", "nextcloud")
    occ("maintenance:mode", "--off")
    assert occ("config:app:get", "daytracker", "installed_version") == "3.0.3"
    assert occ("config:app:get", "daytracker", "enabled") == "no"
    run("docker", "exec", "nextcloud", "test", "!", "-e", "/var/www/html/custom_apps/daytracker/rollback-test.txt")
    print("PASS: updates, scoped/full backups and scoped rollback preserving other apps, app state, schema, data and sequences")


if __name__ == "__main__":
    main()
