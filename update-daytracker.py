#!/usr/bin/env python3
"""Update Daytracker releases on a Linux Nextcloud AIO Docker host (stdlib only)."""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import signal
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET

REPO = "rankerson/nextcloud_daytracker"
APP = "/var/www/html/custom_apps/daytracker"
VERSION = re.compile(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\Z")


class UpdateError(Exception):
    pass


def version_tuple(value):
    if not VERSION.fullmatch(value):
        raise UpdateError(f"Ungültige stabile Versionsnummer: {value!r}")
    return tuple(map(int, value.split(".")))


def confirm(question):
    if not sys.stdin.isatty():
        raise UpdateError("Keine interaktive Eingabe. Bewusst mit --yes starten oder ein Terminal verwenden.")
    return input(question + " [j/N] ").strip().lower() in ("j", "ja", "y", "yes")


def download(url, destination, limit):
    """HTTPS only; bounded downloads and retries without shell interpolation."""
    if not url.startswith("https://"):
        raise UpdateError("Download muss HTTPS verwenden.")
    for attempt in range(3):
        try:
            accept = "application/vnd.github+json" if url.startswith("https://api.github.com/") else "application/octet-stream"
            request = urllib.request.Request(url, headers={"User-Agent": "daytracker-updater", "Accept": accept})
            with urllib.request.urlopen(request, timeout=30) as response, destination.open("wb") as output:
                if not response.url.startswith("https://"):
                    raise UpdateError("Unsichere Download-Weiterleitung.")
                total = 0
                while chunk := response.read(1024 * 1024):
                    total += len(chunk)
                    if total > limit:
                        raise UpdateError("Download überschreitet die zulässige Größe.")
                    output.write(chunk)
            return
        except (urllib.error.URLError, TimeoutError) as error:
            if attempt == 2 or (isinstance(error, urllib.error.HTTPError) and error.code in (403, 404)):
                raise UpdateError(f"GitHub-Download fehlgeschlagen: {url}: {error}") from error
            time.sleep(2 ** attempt)


def unpack(archive, destination):
    """Validate the entire tar before writing; reject traversal, links and devices."""
    with tarfile.open(archive, "r:gz") as package:
        members = package.getmembers()
        if len(members) > 10000 or sum(m.size for m in members) > 256 * 1024 * 1024:
            raise UpdateError("Release-Archiv ist ungewöhnlich groß.")
        seen = set()
        for member in members:
            path = PurePosixPath(member.name)
            if (path.is_absolute() or not path.parts or path.parts[0] != "daytracker"
                    or ".." in path.parts or "\\" in member.name
                    or not (member.isfile() or member.isdir()) or path in seen):
                raise UpdateError(f"Unzulässiger Archiveintrag: {member.name!r}")
            seen.add(path)
        for member in members:
            target = destination.joinpath(*PurePosixPath(member.name).parts)
            if member.isdir():
                target.mkdir(parents=True, exist_ok=True)
            else:
                target.parent.mkdir(parents=True, exist_ok=True)
                with package.extractfile(member) as source, target.open("xb") as output:
                    shutil.copyfileobj(source, output)
        app = destination / "daytracker"
        for required in ("appinfo/info.xml", "appinfo/routes.php", "lib/AppInfo/Application.php", "templates/main.php"):
            if not (app / required).is_file():
                raise UpdateError(f"Unvollständiges Release: {required} fehlt.")
        return app


def compatible(actual, dependency):
    if dependency is None:
        return True
    parts = tuple(map(int, actual.split(".")))
    for attribute, compare in (("min-version", lambda a, b: a >= b), ("max-version", lambda a, b: a <= b)):
        if dependency.get(attribute):
            bound = tuple(map(int, dependency.get(attribute).split(".")))
            if not compare(parts[:len(bound)], bound):
                return False
    return True


def prepare_release(requested, work, current, server, php):
    suffix = "latest" if requested == "latest" else "tags/v" + requested
    metadata = work / "release.json"
    download(f"https://api.github.com/repos/{REPO}/releases/{suffix}", metadata, 2 * 1024 * 1024)
    release = json.loads(metadata.read_text(encoding="utf-8"))
    target = release["tag_name"].removeprefix("v")
    version_tuple(target)
    if release.get("draft") or release.get("prerelease") or (requested != "latest" and target != requested):
        raise UpdateError("Release ist kein passendes veröffentlichtes stabiles Release.")
    if version_tuple(target) < version_tuple(current):
        raise UpdateError(f"Downgrade {current} → {target} verweigert. Stattdessen eine vollständige Sicherung wiederherstellen.")
    filename = f"daytracker-{target}.tar.gz"
    assets = {asset["name"]: asset for asset in release.get("assets", [])}
    for name, limit in ((filename, 64 * 1024 * 1024), (filename + ".sha256", 8192)):
        expected_url = f"https://github.com/{REPO}/releases/download/v{target}/{name}"
        if name not in assets or assets[name]["browser_download_url"] != expected_url:
            raise UpdateError(f"Release-Datei fehlt oder URL ist unerwartet: {name}")
        download(expected_url, work / name, limit)
    checksum = (work / (filename + ".sha256")).read_text().strip()
    match = re.fullmatch(r"([a-fA-F0-9]{64}) [ *]" + re.escape(filename), checksum)
    digest = hashlib.sha256((work / filename).read_bytes()).hexdigest()
    if not match or match.group(1).lower() != digest:
        raise UpdateError("SHA-256-Prüfsumme stimmt nicht überein; keine Installation.")
    app = unpack(work / filename, work / "extracted")
    info = ET.parse(app / "appinfo/info.xml").getroot()
    if info.findtext("id") != "daytracker" or info.findtext("version") != target:
        raise UpdateError("App-ID oder Versionsnummer des Archivs stimmt nicht mit dem Release überein.")
    if not compatible(server, info.find("dependencies/nextcloud")):
        raise UpdateError(f"Daytracker {target} unterstützt den installierten Nextcloud-Server {server} nicht.")
    if not compatible(php, info.find("dependencies/php")):
        raise UpdateError(f"Daytracker {target} unterstützt PHP {php} nicht.")
    databases = [node.text.strip() for node in info.findall("dependencies/database")]
    if databases and "pgsql" not in databases:
        raise UpdateError("Dieses Release unterstützt PostgreSQL nicht.")
    return target, app, digest


class Docker:
    def __init__(self, container, database):
        self.container, self.database = container, database
        self.log = None

    def command(self, *args, output=None, input_file=None):
        result = subprocess.run(["docker", *args], stdin=input_file,
                                stdout=output if output is not None else subprocess.PIPE,
                                stderr=subprocess.PIPE, check=False)
        if self.log:
            try:
                with self.log.open("a", encoding="utf-8") as log:
                    log.write(time.strftime("%Y-%m-%d %H:%M:%S ") + json.dumps(["docker", *args]) + "\n")
                    if output is None:
                        log.write(result.stdout.decode(errors="replace"))
                    log.write(result.stderr.decode(errors="replace") + f"\nExit: {result.returncode}\n")
            except OSError as error:
                # Full backup disk must not prevent recovery Docker commands.
                print(f"Protokoll konnte nicht geschrieben werden: {error}", file=sys.stderr)
        if result.returncode:
            detail = result.stderr.decode(errors="replace")
            if output is None:
                detail += result.stdout.decode(errors="replace")
            raise UpdateError(f"Docker-Befehl fehlgeschlagen (Exit {result.returncode}): {detail.strip()}")
        return result.stdout.decode(errors="replace").strip() if output is None else ""

    def shell(self, script, *args):
        return self.command("exec", "--user", "root", self.container, "sh", "-ec", script, "sh", *args)

    def occ(self, *args):
        return self.command("exec", "--user", "www-data", "--workdir", "/var/www/html", self.container,
                            "php", "occ", "--no-interaction", *args)

    def status(self):
        # occ may print a maintenance notice before the JSON document.
        text = self.occ("status", "--output=json")
        return json.loads(text[text.index("{"):])

    def backup(self, directory):
        for name, folder in (("app.tar.gz", "custom_apps/daytracker"), ("config.tar.gz", "config")):
            partial = directory / (name + ".partial")
            with partial.open("xb") as output:
                self.command("exec", "--user", "root", self.container, "tar", "-czf", "-", "-C", "/var/www/html", folder, output=output)
            with tarfile.open(partial, "r:gz") as archive:
                if not archive.getmembers():
                    raise UpdateError("Dateisicherung ist leer.")
            partial.rename(directory / name)
        partial = directory / "database.dump.partial"
        with partial.open("xb") as output:
            self.command("exec", self.database, "sh", "-ec",
                         'export PGPASSWORD="${POSTGRES_PASSWORD:?}"; exec pg_dump --format=custom --username="${POSTGRES_USER:?}" --dbname="${POSTGRES_DB:?}"', output=output)
        with partial.open("rb") as input_file, open(os.devnull, "wb") as output:
            self.command("exec", "-i", self.database, "pg_restore", "--list", input_file=input_file, output=output)
        partial.rename(directory / "database.dump")


class Updater:
    def __init__(self, docker, args):
        self.docker, self.args = docker, args
        self.stage = None
        self.maintenance = False
        self.exchange_started = False
        self.migration_started = False
        self.backup_dir = None

    def preflight(self):
        for container in (self.args.container, self.args.database_container):
            if self.docker.command("inspect", "--format", "{{.State.Running}}", container) != "true":
                raise UpdateError(f"Container läuft nicht: {container}")
        status = self.docker.status()
        if not status.get("installed") or status.get("maintenance") or status.get("needsDbUpgrade"):
            raise UpdateError("Nextcloud ist nicht bereit: Installation, Wartungsmodus oder ein anderes ausstehendes Upgrade prüfen.")
        self.docker.shell('test -d "$1"; test ! -L "$1"; test "$(readlink -f "$1")" = "$1"', APP)
        php = self.docker.command("exec", self.args.container, "php", "-r", 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION.".".PHP_RELEASE_VERSION;')
        info = self.docker.command("exec", self.args.container, "php", "-r",
                                   '$x=simplexml_load_file($argv[1]); echo json_encode([(string)$x->id,(string)$x->version]);', APP + "/appinfo/info.xml")
        app_id, current = json.loads(info)
        registered = self.docker.occ("config:app:get", "daytracker", "installed_version")
        if app_id != "daytracker" or current != registered:
            raise UpdateError("Dateien und registrierte Daytracker-Version stimmen nicht überein. Vorherigen Updateversuch zuerst klären.")
        version_tuple(current)
        if self.docker.occ("config:system:get", "dbtype") != "pgsql":
            raise UpdateError("Dieses AIO-Skript benötigt PostgreSQL.")
        db_name = self.docker.occ("config:system:get", "dbname")
        actual_db = self.docker.command("exec", self.args.database_container, "sh", "-ec", 'printf %s "${POSTGRES_DB:?}"; command -v pg_dump >/dev/null; command -v pg_restore >/dev/null')
        if db_name != actual_db:
            raise UpdateError("Datenbankname und gewählter Datenbankcontainer stimmen nicht überein.")
        enabled = self.docker.occ("config:app:get", "daytracker", "enabled")
        if enabled not in ("yes", "no"):
            groups = json.loads(enabled)
            if not isinstance(groups, list) or not groups or not all(isinstance(g, str) and g for g in groups):
                raise UpdateError("Unbekannter App-Aktivierungszustand.")
        return current, status["versionstring"], php, enabled

    def perform(self, app, target, current, enabled, digest):
        # Prepare and lint outside custom_apps, before the maintenance window.
        self.stage = self.docker.shell("mktemp -d /var/www/html/.daytracker-update-XXXXXXXX")
        if not re.fullmatch(r"/var/www/html/\.daytracker-update-[A-Za-z0-9]+", self.stage):
            self.stage = None
            raise UpdateError("Unerwarteter temporärer Containerpfad.")
        self.docker.command("cp", str(app), self.args.container + ":" + self.stage + "/daytracker")
        self.docker.shell('chown -R www-data:www-data "$1"; find "$1" -type d -exec chmod 750 {} +; find "$1" -type f -exec chmod 640 {} +', self.stage + "/daytracker")
        self.docker.shell('find "$1" -name "*.php" -type f -exec sh -ec \'for f do php -l "$f" >/dev/null || exit 1; done\' sh {} +', self.stage + "/daytracker")
        root = Path(self.args.backup_dir).resolve()
        root.mkdir(mode=0o700, parents=True, exist_ok=True)
        self.backup_dir = Path(tempfile.mkdtemp(prefix=time.strftime("%Y%m%d-%H%M%S-") + self.args.container + "-", dir=root))
        self.docker.log = self.backup_dir / "update.log"
        (self.backup_dir / "update.json").write_text(json.dumps({"from": current, "to": target, "sha256": digest,
            "container": self.args.container, "database_container": self.args.database_container, "enabled_before": enabled,
            "app_path": APP, "stage": self.stage}, indent=2) + "\n", encoding="utf-8")
        (self.backup_dir / "RECOVERY.txt").write_text(
            "Diese Sicherung enthält App-Dateien, Nextcloud-Konfiguration und die GESAMTE Nextcloud-Datenbank.\n"
            "Sie ist kein vollständiges AIO-/Nutzdaten-Backup.\n"
            "Nach begonnenen Migrationen NICHT nur alte App-Dateien zurückkopieren.\n"
            "Nextcloud im Wartungsmodus lassen und den Fehler anhand des Updater-Logs und nextcloud.log klären.\n"
            "Bei nötiger Wiederherstellung App/Config und Datenbank gemeinsam durch einen Administrator wiederherstellen.\n"
            "database.dump ist ein pg_dump-Custom-Archiv für pg_restore; dessen Wiederherstellung betrifft alle Apps.\n"
            "Keine automatische Datenbankwiederherstellung; keine Passwörter in Befehlszeilen kopieren.\n", encoding="utf-8")
        self.maintenance = True  # Also recover if the command is interrupted after changing config.
        print("Wartungsmodus einschalten und Sicherung erstellen …", flush=True)
        self.docker.occ("maintenance:mode", "--on")
        self.docker.backup(self.backup_dir)
        print(f"Sicherung abgeschlossen: {self.backup_dir}", flush=True)
        self.exchange_started = True
        self.docker.shell('mv "$1" "$2/previous"; mv "$2/daytracker" "$1"', APP, self.stage)
        if enabled == "no":
            # app:enable installs the local package and runs its migrations/repair steps.
            self.migration_started = True
            print(self.docker.occ("app:enable", "daytracker"), flush=True)
            if not self.args.enable:
                print(self.docker.occ("app:disable", "daytracker"), flush=True)
        elif self.docker.status().get("needsDbUpgrade"):
            self.migration_started = True
            print(self.docker.occ("upgrade"), flush=True)
        if self.docker.occ("config:app:get", "daytracker", "installed_version") != target:
            raise UpdateError("Nextcloud meldet nach dem Update nicht die erwartete App-Version.")
        final_enabled = self.docker.occ("config:app:get", "daytracker", "enabled")
        expected_enabled = "yes" if enabled == "no" and self.args.enable else enabled
        if final_enabled != expected_enabled:
            raise UpdateError("App-Aktivierungszustand ist unerwartet; bitte prüfen.")
        if not self.args.no_restart:
            print("Nextcloud-Container neu starten (PHP-Opcode-Cache) …", flush=True)
            self.docker.command("restart", "--time", "30", self.args.container)
            for attempt in range(60):
                try:
                    status = self.docker.status()
                    if status.get("installed") and not status.get("needsDbUpgrade"):
                        break
                except UpdateError:
                    pass
                time.sleep(2)
            else:
                raise UpdateError("Nextcloud ist nach dem Neustart nicht bereit.")
        status = self.docker.status()
        if status.get("needsDbUpgrade") or not status.get("installed"):
            raise UpdateError("Nextcloud meldet nach dem Update ein ausstehendes Upgrade.")
        self.docker.occ("maintenance:mode", "--off")
        self.maintenance = False
        print(f"Erfolgreich: Daytracker {target}; Status: {final_enabled}. Browser vollständig neu laden.")
        if self.args.no_restart:
            print("Neustart übersprungen. Bei deaktivierter Opcode-Zeitstempelprüfung PHP-FPM/Container vor Nutzung neu starten.")

    def recover(self):
        if self.migration_started:
            try:
                self.docker.occ("maintenance:mode", "--on")
            except Exception as error:
                print(f"Wartungsmodus konnte nicht bestätigt werden: {error}", file=sys.stderr)
            print("Migration/Upgrade wurde bereits begonnen. Kein automatischer Datei- oder Datenbank-Rollback.\n"
                  f"Wartungsmodus beibehalten; Sicherung: {self.backup_dir}; Container-Arbeitsordner: {self.stage}", file=sys.stderr)
            return
        try:
            if self.exchange_started:
                self.docker.shell('if [ -d "$2/previous" ]; then if [ -e "$1" ]; then mv "$1" "$2/failed"; fi; mv "$2/previous" "$1"; fi', APP, self.stage)
            if self.maintenance:
                self.docker.occ("maintenance:mode", "--off")
                self.maintenance = False
            self.cleanup()
            print("Abbruch vor Migration: bisherige App-Dateien bleiben erhalten/wurden zurückgesetzt.", file=sys.stderr)
        except Exception as error:
            print(f"Automatische Wiederherstellung unvollständig: {error}\nSicherung: {self.backup_dir}; Container-Arbeitsordner: {self.stage}", file=sys.stderr)

    def cleanup(self):
        if self.stage:
            self.docker.shell('case "$1" in /var/www/html/.daytracker-update-*) rm -rf -- "$1" ;; *) exit 1 ;; esac', self.stage)
            self.stage = None


def arguments(argv=None):
    parser = argparse.ArgumentParser(description="Daytracker auf dem Linux-AIO-Docker-Host aktualisieren. Ohne Version: neuestes stabiles GitHub-Release.")
    parser.add_argument("version", nargs="?", default="latest", help="latest, 3.0.3 oder v3.0.3; keine Downgrades")
    parser.add_argument("--container", default="nextcloud-aio-nextcloud")
    parser.add_argument("--database-container", default="nextcloud-aio-database")
    parser.add_argument("--backup-dir", default="/var/backups/daytracker", help="Sicherungen auf dem Docker-Host")
    parser.add_argument("--yes", action="store_true", help="Update ohne Rückfrage; deaktivierte App bleibt ohne --enable deaktiviert")
    parser.add_argument("--enable", action="store_true", help="eine bisher deaktivierte App nach dem Update aktivieren")
    parser.add_argument("--reinstall", action="store_true", help="gleiche Version erneut installieren")
    parser.add_argument("--no-restart", action="store_true", help="Nextcloud-Container nicht neu starten")
    parser.add_argument("--dry-run", action="store_true", help="nur Download, Prüfung und Plan; keine Serveränderungen")
    args = parser.parse_args(argv)
    args.version = args.version.removeprefix("v")
    if args.version != "latest":
        version_tuple(args.version)
    for name in (args.container, args.database_container):
        if not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_.-]*", name):
            raise UpdateError("Ungültiger Containername.")
    return args


def main(argv=None):
    if sys.version_info < (3, 9):
        raise UpdateError("Python 3.9 oder neuer wird benötigt.")
    args = arguments(argv)
    if sys.platform != "linux" or os.geteuid() != 0:
        raise UpdateError("Auf dem Linux-Docker-Host mit sudo/root ausführen.")
    if not shutil.which("docker"):
        raise UpdateError("Docker CLI fehlt.")
    import fcntl
    os.umask(0o077)
    lock_root = Path("/run/daytracker-updater")
    lock_root.mkdir(mode=0o700, exist_ok=True)
    if lock_root.is_symlink() or lock_root.stat().st_uid != 0 or lock_root.stat().st_mode & 0o077:
        raise UpdateError("Unsicheres Lock-Verzeichnis /run/daytracker-updater.")
    with (lock_root / (args.container + ".lock")).open("w") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise UpdateError("Für diesen Container läuft bereits ein Daytracker-Update.") from error
        updater = Updater(Docker(args.container, args.database_container), args)
        current, server, php, enabled = updater.preflight()
        with tempfile.TemporaryDirectory(prefix="daytracker-download-") as directory:
            target, app, digest = prepare_release(args.version, Path(directory), current, server, php)
            print(f"Nextcloud {server}, PHP {php}\nDaytracker: {current} → {target}\nRelease: https://github.com/{REPO}/releases/tag/v{target}\nSHA-256: {digest}")
            if current == target and not args.reinstall:
                print("Diese Version ist bereits installiert. Für erneute Installation/Aktivierung --reinstall verwenden.")
                return 0
            print(f"Container: {args.container}; PostgreSQL: {args.database_container}\nSicherungen auf dem Host: {Path(args.backup_dir).resolve()}\n"
                  "Die gesamte Nextcloud wird für Sicherung, Dateiaustausch und Migrationen in den Wartungsmodus versetzt.\n"
                  "Die Datenbanksicherung umfasst die gesamte Nextcloud-Datenbank, keine Benutzerdateien.\n"
                  + ("Container-Neustart wird übersprungen." if args.no_restart else "Der Nextcloud-Container wird danach neu gestartet."))
            if args.dry_run:
                print("Prüflauf erfolgreich; keine Serveränderungen.")
                return 0
            if enabled == "no" and not args.enable and not args.yes:
                args.enable = confirm("Daytracker ist deaktiviert. Nach erfolgreichem Update aktivieren?")
            if not args.yes and not confirm("Update mit den oben genannten Sicherungen und der Wartungszeit starten?"):
                print("Abgebrochen; keine Serveränderungen.")
                return 0
            try:
                updater.perform(app, target, current, enabled, digest)
            except (Exception, KeyboardInterrupt):
                updater.recover()
                raise
            try:
                updater.cleanup()
            except Exception as error:
                print(f"Update erfolgreich, temporärer Containerordner konnte nicht entfernt werden: {error}", file=sys.stderr)
            print(f"Sicherungen bleiben erhalten: {updater.backup_dir}")
    return 0


if __name__ == "__main__":
    def interrupted(signum, frame):
        raise KeyboardInterrupt
    signal.signal(signal.SIGTERM, interrupted)
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        print("Update unterbrochen. Hinweise zur Wiederherstellung oben beachten.", file=sys.stderr)
        sys.exit(130)
    except Exception as error:
        print(f"FEHLER: {error}", file=sys.stderr)
        sys.exit(1)
