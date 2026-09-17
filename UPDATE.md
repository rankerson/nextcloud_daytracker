# Daytracker mit einem Befehl aktualisieren

`update-daytracker.py` läuft auf dem **Linux-Docker-Host** einer Nextcloud-AIO-Installation. Es benötigt Python ab 3.9, Docker CLI, sudo/root und HTTPS-Zugriff auf GitHub. Zusätzliche Python-Pakete oder Git werden nicht benötigt. Das Skript installiert Daytracker erstmalig oder aktualisiert eine vorhandene App; es installiert keinen Nextcloud-Server.

## Herunterladen und starten

Die folgenden Befehle auf dem **Docker-Host** ausführen. Für den Download wird zusätzlich `curl` benötigt.

**1. Skript im Home-Verzeichnis des Docker-Hosts herunterladen oder aktualisieren (nicht in `custom_apps`):**

```bash
cd ~
curl --fail --location --proto '=https' --proto-redir '=https' \
  https://raw.githubusercontent.com/rankerson/nextcloud_daytracker/main/update-daytracker.py \
  --output update-daytracker.py
```

**2. Optional Download und Voraussetzungen prüfen, ohne den Server zu verändern:**

```bash
sudo python3 update-daytracker.py --dry-run
```

**3. Neueste stabile Veröffentlichung laut GitHub „Latest“ installieren:**

```bash
sudo python3 update-daytracker.py
```

Alternativ eine bestimmte Version installieren:

```bash
sudo python3 update-daytracker.py v3.0.3
```

Das Skript ist als einzelne Python-Datei ausgeführt. Für spätere Updates genügt derselbe Aufruf; es lädt die App jeweils selbst von GitHub. Aktualisierungen am Skript selbst erhältst du durch erneutes Herunterladen. Die App-Archive enthalten das Host-Skript nicht.

### Installation oder Update erkennen

Das Skript prüft selbst, ob `/var/www/html/custom_apps/daytracker` und eine registrierte Daytracker-Version vorhanden sind:

- Fehlt die App, wird das ausgewählte Release installiert. Nextcloud führt anschließend mit `occ app:enable` die Erstinstallation und Migrationen aus.
- Ist die App vorhanden, wird sie vollständig ersetzt und aktualisiert. Vorhandene Aktivierungs- und Gruppenbeschränkungen bleiben erhalten.
- Existieren App-Dateien, aber keine Registrierung, behandelt das Skript dies als Erstinstallation. Ein widersprüchlicher registrierter Versionsstand führt aus Sicherheitsgründen zum Abbruch.

Bei einer Erstinstallation wird vor der Änderung gefragt, ob die App aktiviert werden soll. Mit `--yes --enable` lässt sich eine unbeaufsichtigte Installation direkt aktivieren.

### Nach dem Update auf Nextcloud 34 deaktivierte App

Beim normalen Aufruf die Frage nach der Aktivierung mit Ja beantworten und den angezeigten Updateablauf bestätigen. Das Skript ersetzt die alte App durch das ausgewählte kompatible Release und aktiviert sie nach den erforderlichen Migrationen.

Ist dieses Release bereits installiert, wird der normale Aufruf ohne Änderung beendet. Für erneute Installation und Aktivierung verwenden:

```bash
sudo python3 update-daytracker.py --reinstall --enable
```

Nach erfolgreichem Abschluss Daytracker im Browser öffnen und mit `Strg + F5` vollständig neu laden. Zusätzliche manuelle `occ`- oder Migrationsbefehle sind nicht erforderlich.

### Standardwerte

| Einstellung | Standard |
| --- | --- |
| Zielversion | GitHub `latest` (stabile Veröffentlichung) |
| Nextcloud-Container | `nextcloud-aio-nextcloud` |
| PostgreSQL-Container | `nextcloud-aio-database` |
| App-Verzeichnis im Container | `/var/www/html/custom_apps/daytracker` |
| Sicherungsverzeichnis auf dem Host | `/var/backups/daytracker` |

Andere Containernamen und den Sicherungsort über die unten beschriebenen Optionen angeben.

## Ablauf und Rückfragen

1. Container, Nextcloud-Status, vorhandene App-Dateien, registrierte App-Version und PostgreSQL-Zuordnung prüfen.
2. Gewünschtes GitHub-Release laden, SHA-256 kontrollieren, Archivinhalt und App-ID/Version sowie Nextcloud-/PHP-Kompatibilität prüfen. Downgrades und Vorabversionen werden abgelehnt.
3. Zielversion, Container, Sicherungspfad und Wartungszeit anzeigen. Bei einer deaktivierten App fragen, ob sie anschließend aktiviert werden soll. Vor Beginn der Änderungen bestätigen lassen; Enter bedeutet Nein.
4. Neue Dateien außerhalb des App-Verzeichnisses bereitstellen, PHP-Syntax prüfen und Besitzer/Rechte auf `www-data:www-data`, Verzeichnisse `750`, Dateien `640` setzen.
5. Nextcloud in den Wartungsmodus setzen. Vor jedem Dateiaustausch die bisherige App, die Nextcloud-Konfiguration und gezielt die Daytracker-Datenbankobjekte sichern und die Sicherungen prüfen. Mit `--full-db-backup` zusätzlich einen vollständigen PostgreSQL-Dump erstellen.
6. Bei einer Erstinstallation das neue App-Verzeichnis anlegen, bei einem Update vollständig austauschen; damit bleiben keine veralteten Dateien liegen. `occ app:enable` führt bei der Erstinstallation oder einer deaktivierten App die erforderlichen Migrationen aus. Bei einer aktiven App führt `occ upgrade` notwendige Updates und Migrationen aus. Bestehende Gruppenbeschränkungen bleiben erhalten.
7. Registrierte Version und Aktivierungsstatus prüfen, Nextcloud-Container neu starten und Bereitschaft prüfen. Abschließend den Wartungsmodus ausschalten. Im Browser vollständig neu laden.

Alle `occ`-Befehle laufen als `www-data`. Historische Migrationen werden ausschließlich durch Nextcloud ausgeführt, nicht direkt per SQL oder `migration:execute`. Ein zusätzliches pauschales `maintenance:repair` ist nicht nötig; die App-Reparaturschritte gehören zum Nextcloud-Updateablauf. Der normale App-Store-Updater wird nicht aufgerufen.

## Optionen

Standardmäßig werden nur die Daytracker-Daten gesichert. Für einen zusätzlichen vollständigen Nextcloud-Datenbankdump:

```bash
sudo python3 update-daytracker.py --full-db-backup
```

Die Auswahl wird vor der Bestätigung angezeigt und in `update.json` vermerkt. Sie gilt auch mit `--yes`; ohne `--full-db-backup` entsteht kein vollständiger Dump.

```bash
# Nur prüfen und den Ablauf anzeigen; keine Änderungen im Container:
sudo python3 update-daytracker.py --dry-run

# Ohne Rückfragen; eine deaktivierte App auch wieder aktivieren:
sudo python3 update-daytracker.py --yes --enable

# Gleiche Version nochmals installieren:
sudo python3 update-daytracker.py v3.0.3 --reinstall

# Andere Containernamen und Sicherungsort auf dem Host:
sudo python3 update-daytracker.py \
  --container nextcloud-aio-nextcloud \
  --database-container nextcloud-aio-database \
  --backup-dir /var/backups/daytracker

sudo python3 update-daytracker.py --help
```

Ohne Versionsnummer oder mit `latest` wird GitHubs neuestes stabiles Release verwendet. Fehlen dort die erwarteten App-Archive oder passt die Version nicht zur installierten Nextcloud, bricht das Skript ab; es weicht nicht stillschweigend auf ein anderes Release aus. Ist die Version bereits installiert, endet es ohne Serveränderung. Für eine erneute Installation samt Aktivierung `--reinstall --enable` verwenden.

`--yes` bestätigt nur den angekündigten Updateablauf. Eine zuvor deaktivierte App bleibt damit ohne `--enable` deaktiviert. Ohne Terminal wird eine ausstehende Rückfrage abgelehnt.

`--no-restart` überspringt den Neustart. Verwende das nur, wenn du das Neuladen des PHP-Opcode-Caches selbst sicherstellst; andernfalls können Webanfragen noch alten PHP-Code ausführen.

## Sicherungen und Fehler

Standardmäßig entsteht pro Update ein nur für root zugänglicher Unterordner unter `/var/backups/daytracker/` auf dem Host:

- `app.tar.gz`: bisherige App inklusive veralteter Dateien für eine Wiederherstellung.
- `config.tar.gz`: Nextcloud-Konfiguration, einschließlich Zugangsdaten; vertraulich aufbewahren.
- `daytracker.dump`: alle Tabellen mit dem konfigurierten Präfix und `daytracker_` sowie ihre eigenen ID-Sequenzen; Schema, Daten, Indizes und Constraints im PostgreSQL-Custom-Format.
- `daytracker-state.sql`: ausschließlich Daytracker-Zeilen aus `appconfig`, `migrations` und `preferences`, einschließlich Aktivierungsstatus und Initialisierungsmarkern.
- `restore-daytracker.sql`: aus dem Dump erzeugte SQL-Datei plus App-Zustand zur gemeinsamen Rücksicherung in einer Transaktion.
- `database.dump`: nur mit `--full-db-backup`; vollständige Nextcloud-PostgreSQL-Datenbank im Custom-Format.
- `update.json`, `update.log`, `RECOVERY.txt`: Versionszuordnung, Befehlsausgaben und Hinweise zur Wiederherstellung.

Die Sicherung enthält **keine Benutzerdateien** und ersetzt nicht das reguläre AIO-Backup. Die gezielte Sicherung setzt die Nextcloud-Tabellen im PostgreSQL-Schema `public` voraus und berücksichtigt das konfigurierte Tabellenpräfix. Für die Sicherungen muss ausreichend Platz vorhanden sein. Alte Sicherungen werden nicht automatisch gelöscht.

Fehler bei Download, Prüfsumme oder Kompatibilität verändern die Installation nicht. Bei einem Fehler vor Beginn möglicher Migrationen versucht das Skript, die bisherigen App-Dateien wiederherzustellen und den selbst gesetzten Wartungsmodus auszuschalten. Ein fehlgeschlagener Sicherungsschritt verhindert den Dateiaustausch.

Sobald eine Migration oder ein Upgrade gestartet wurde, erfolgt kein automatischer Rollback. Der Wartungsmodus bleibt aktiv; das Skript meldet Sicherungs- und Arbeitsverzeichnis. Erst den Fehler anhand der Protokolle klären. Falls eine Wiederherstellung nötig ist, müssen App-Dateien und Daytracker-Datenbankzustand zueinander passen. Die gezielte Rücksicherung verändert keine Zeilen anderer Apps. Eine optionale vollständige Datenbankrücksicherung betrifft dagegen auch andere Apps. Beide werden nicht automatisch ausgelöst.

Ein bereits aktiver Wartungsmodus oder ein vorher ausstehendes Upgrade führt zum Abbruch. Das Skript sperrt parallele eigene Updates desselben Containers auf diesem Host. AIO-Backups, Nextcloud-Serverupdates und manuelle App-Änderungen nicht gleichzeitig starten. Bei Stromausfall oder `kill -9` kann kein Skript aufräumen; in diesem Fall die verbliebenen Sicherungen/Arbeitsverzeichnisse prüfen, bevor der Wartungsmodus aufgehoben wird.

## Gezielte Wiederherstellung

Die Rücksicherung ist ein bewusster, manueller Schritt nach der Fehleranalyse. Sie setzt dieselbe Nextcloud-Version, dasselbe Tabellenpräfix und unveränderte Strukturen der gemeinsamen Nextcloud-Tabellen voraus. Alle seit der Sicherung erfolgten Daytracker-Änderungen werden verworfen. Andere Apps werden nicht zurückgesetzt. Nach fehlgeschlagenen Migrationen eventuell neu hinzugekommene Daytracker-Tabellen zuerst prüfen; die SQL-Datei ersetzt die gesicherten Tabellen, entfernt aber keine später hinzugekommenen Tabellen. Keine `CASCADE`-Löschungen verwenden.

Auf dem Docker-Host eine Root-Bash öffnen (`sudo bash`). Die folgenden Schritte einzeln ausführen; bei einem Fehler stoppen und den Wartungsmodus beibehalten. Sicherungspfad und Containernamen anhand von `update.json` einsetzen:

```bash
backup=/var/backups/daytracker/DEINE-SICHERUNG
nc=nextcloud-aio-nextcloud
db=nextcloud-aio-database
cat "$backup/update.json" "$backup/RECOVERY.txt"
test -s "$backup/restore-daytracker.sql"
tar -tzf "$backup/app.tar.gz" >/dev/null
docker exec -u www-data "$nc" php occ maintenance:mode --on
```

Den alten App-Ordner außerhalb von `custom_apps` bereitstellen. Das Arbeitsverzeichnis und die bisherige Installation bleiben für die Fehleranalyse erhalten:

```bash
stage=$(docker exec "$nc" mktemp -d /var/www/html/.daytracker-restore-XXXXXXXX)
docker cp "$backup/app.tar.gz" "$nc:$stage/app.tar.gz"
docker exec "$nc" sh -ec '
  test "$(stat -c %d "$1")" = "$(stat -c %d /var/www/html/custom_apps/daytracker)"
  tar -xzf "$1/app.tar.gz" -C "$1"
  test -f "$1/custom_apps/daytracker/appinfo/info.xml"
' sh "$stage"
```

Daytracker-Datenbankzustand in einer Transaktion zurückspielen. Bei SQL-Fehlern wird die Transaktion nicht übernommen; der Fehler muss vor dem Dateiaustausch geklärt werden:

```bash
docker exec -i "$db" sh -ec '
  export PGPASSWORD="${POSTGRES_PASSWORD:?}"
  exec psql -X --username="${POSTGRES_USER:?}" --dbname="${POSTGRES_DB:?}" \
    --single-transaction -v ON_ERROR_STOP=1 --file=-
' < "$backup/restore-daytracker.sql"
```

Nach erfolgreicher SQL-Rücksicherung den App-Ordner vollständig ersetzen:

```bash
docker exec "$nc" sh -ec '
  mv /var/www/html/custom_apps/daytracker "$1/failed"
  mv "$1/custom_apps/daytracker" /var/www/html/custom_apps/daytracker
  chown -R www-data:www-data /var/www/html/custom_apps/daytracker
' sh "$stage"
docker restart "$nc"
```

Warten, bis der Container bereit ist. Dann `occ status` und die registrierte App-Version prüfen:

```bash
docker exec -u www-data "$nc" php occ status
docker exec -u www-data "$nc" php occ config:app:get daytracker installed_version
docker exec -u www-data "$nc" php occ config:app:get daytracker enabled
```

Die Version muss `from` und der Aktivierungsstatus `enabled_before` aus `update.json` entsprechen. Erst wenn kein Upgrade mehr aussteht und die App-Dateien dazu passen, den Wartungsmodus ausschalten:

```bash
docker exec -u www-data "$nc" php occ maintenance:mode --off
```

Eine zuvor deaktivierte App bleibt deaktiviert. Insbesondere darf eine zurückgespielte alte, mit der aktuellen Nextcloud-Version inkompatible App nicht erzwungen aktiviert werden. `config.tar.gz` wird beim gezielten Rollback nicht pauschal zurückgespielt. Der optionale vollständige `database.dump` ist für diesen Ablauf nicht erforderlich.

## Automatische Prüfung

Der GitHub-Workflow **Test AIO updater** prüft Offline-Fehlerfälle und führt echte Updates von Daytracker 3.0.2 auf 3.0.3 in separaten Nextcloud-33- und Nextcloud-34.0.4-Containern mit PostgreSQL durch. Er prüft vorhandene Datensätze, Sicherungsarchive, Rechte, Neustart, Auswahl von `latest` beziehungsweise einer konkreten Version und den Aktivierungszustand. Das sind isolierte Docker-Tests; die individuelle produktive AIO-Installation wird dabei nicht angefasst.
