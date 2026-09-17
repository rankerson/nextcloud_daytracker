# Daytracker mit einem Befehl aktualisieren

`update-daytracker.py` läuft auf dem **Linux-Docker-Host** einer bestehenden Nextcloud-AIO-Installation. Es benötigt Python ab 3.9, Docker CLI, sudo/root und HTTPS-Zugriff auf GitHub. Zusätzliche Python-Pakete oder Git werden nicht benötigt. Das Skript aktualisiert eine bereits installierte App; es installiert keinen Nextcloud-Server.

## Herunterladen und starten

Auf dem Docker-Host ausführen:

```bash
curl --fail --location --proto '=https' --proto-redir '=https' \
  https://raw.githubusercontent.com/rankerson/nextcloud_daytracker/main/update-daytracker.py \
  --output update-daytracker.py

# Neueste stabile Veröffentlichung laut GitHub "Latest":
sudo python3 update-daytracker.py

# Alternativ eine bestimmte Version:
sudo python3 update-daytracker.py v3.0.3
```

Das Skript ist bewusst als einzelne Python-Datei ausgeführt. Für spätere Updates genügt derselbe Aufruf; es lädt die App jeweils selbst von GitHub. Aktualisierungen am Skript selbst erhältst du durch erneutes Herunterladen. Die App-Archive enthalten das Host-Skript nicht.

## Ablauf und Rückfragen

1. Container, Nextcloud-Status, installierte App-Version und PostgreSQL-Zuordnung prüfen.
2. Gewünschtes GitHub-Release laden, SHA-256 kontrollieren, Archivinhalt und App-ID/Version sowie Nextcloud-/PHP-Kompatibilität prüfen. Downgrades und Vorabversionen werden abgelehnt.
3. Zielversion, Container, Sicherungspfad und Wartungszeit anzeigen. Bei einer deaktivierten App fragen, ob sie anschließend aktiviert werden soll. Vor Beginn der Änderungen bestätigen lassen; Enter bedeutet Nein.
4. Neue Dateien außerhalb des App-Verzeichnisses bereitstellen, PHP-Syntax prüfen und Besitzer/Rechte auf `www-data:www-data`, Verzeichnisse `750`, Dateien `640` setzen.
5. Nextcloud in den Wartungsmodus setzen. Vor jedem Dateiaustausch die bisherige App, die Nextcloud-Konfiguration und einen vollständigen PostgreSQL-Dump sichern und die Sicherungen prüfen.
6. App-Verzeichnis vollständig austauschen; damit bleiben keine veralteten Dateien liegen. `occ upgrade` führt notwendige Updates und Migrationen aktiver Apps aus. Bei einer deaktivierten App führt `occ app:enable` die Installation und Migrationen des lokalen Pakets aus; auf Wunsch wird sie danach wieder deaktiviert. Bestehende Gruppenbeschränkungen einer aktiven App bleiben erhalten.
7. Registrierte Version und Aktivierungsstatus prüfen, Nextcloud-Container neu starten und Bereitschaft prüfen. Abschließend den Wartungsmodus ausschalten. Im Browser vollständig neu laden.

Alle `occ`-Befehle laufen als `www-data`. Historische Migrationen werden ausschließlich durch Nextcloud ausgeführt, nicht direkt per SQL oder `migration:execute`. Ein zusätzliches pauschales `maintenance:repair` ist nicht nötig; die App-Reparaturschritte gehören zum Nextcloud-Updateablauf. Der normale App-Store-Updater wird nicht aufgerufen.

## Optionen

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
- `database.dump`: vollständige Nextcloud-PostgreSQL-Datenbank im `pg_dump`-Custom-Format.
- `update.json`, `update.log`, `RECOVERY.txt`: Versionszuordnung, Befehlsausgaben und Hinweise zur Wiederherstellung.

Die Sicherung enthält **keine Benutzerdateien** und ersetzt nicht das reguläre AIO-Backup. Für den Datenbankdump muss ausreichend Platz vorhanden sein. Alte Sicherungen werden nicht automatisch gelöscht.

Fehler bei Download, Prüfsumme oder Kompatibilität verändern die Installation nicht. Bei einem Fehler vor Beginn möglicher Migrationen versucht das Skript, die bisherigen App-Dateien wiederherzustellen und den selbst gesetzten Wartungsmodus auszuschalten. Ein fehlgeschlagener Sicherungsschritt verhindert den Dateiaustausch.

Sobald eine Migration oder ein Upgrade gestartet wurde, erfolgt kein automatischer Rollback. Der Wartungsmodus bleibt aktiv; das Skript meldet Sicherungs- und Arbeitsverzeichnis. Erst den Fehler anhand der Protokolle klären. Falls eine Wiederherstellung nötig ist, müssen App-Dateien, Konfiguration und Datenbank zueinander passen. Die Wiederherstellung der vollständigen Datenbank betrifft auch andere Apps und gehört deshalb bewusst nicht zum automatischen Fehlerhandling.

Ein bereits aktiver Wartungsmodus oder ein vorher ausstehendes Upgrade führt zum Abbruch. Das Skript sperrt parallele eigene Updates desselben Containers auf diesem Host. AIO-Backups, Nextcloud-Serverupdates und manuelle App-Änderungen nicht gleichzeitig starten. Bei Stromausfall oder `kill -9` kann kein Skript aufräumen; in diesem Fall die verbliebenen Sicherungen/Arbeitsverzeichnisse prüfen, bevor der Wartungsmodus aufgehoben wird.
