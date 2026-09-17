# Daytracker für Nextcloud 33 und 34

Daytracker ist eine native Nextcloud-App zur benutzerbezogenen Erfassung von Tageswerten nach Kategorien und Zeitscheiben.

Für Updates einer vorhandenen AIO-Installation: [Schnellstart](#bestehende-aio-installation-aktualisieren-empfohlen) oder [ausführliche Update-Anleitung](UPDATE.md).

## Update auf 3.0.3

Nextcloud 34 wurde zuvor allein durch `max-version="33"` blockiert. Version 3.0.3 erlaubt Nextcloud 33–34. Die verwendeten Bootstrap-, Dashboard-, Datenbank- und HTTP-APIs wurden mit dem Nextcloud-34-Quellcode und dem [Upgrade-Leitfaden](https://docs.nextcloud.com/server/stable/developer_manual/release_notes/previous/upgrade_to_34.html) abgeglichen. Die App benötigt keine der dort entfernten Frontend-Bibliotheken. Automatisierte Integrationstests prüfen Installation, API und Browserfunktionen mit PostgreSQL auf Nextcloud 33 und 34.0.4; ein Test in deiner produktiven AIO-Instanz ist damit nicht ersetzt.

## Zielumgebung

- Nextcloud 33 oder 34 (einschließlich 34.0.4)
- Nextcloud AIO
- PHP 8.2 oder höher (zusätzlich gelten die Anforderungen der Nextcloud-Version)
- PostgreSQL
- App-ID `daytracker`
- Namespace `OCA\Daytracker`
- Container `nextcloud-aio-nextcloud`
- App-Pfad `/var/www/html/custom_apps/daytracker`
- Vanilla JavaScript ohne Build-Prozess

## Funktionsumfang

- Tagesansicht
- Wochenansicht von Montag bis Sonntag
- Kategorien mit Auswahl, Freitext oder beidem
- frei konfigurierbare Zeitscheiben
- transaktionale Administration
- benutzerbezogene Datenhaltung
- CSV-Export mit UTF-8-BOM und Semikolon
- Nextcloud-Dashboard-Widget
- CSRF-geschützte Schreibzugriffe

## Bestehendes Datenmodell

Die App verwendet ausschließlich die vorhandenen Tabellen:

```text
daytracker_categories
daytracker_options
daytracker_entries
daytracker_timeslices
```

Bei einem Nextcloud-Tabellenpräfix `oc_` erscheinen diese in PostgreSQL als:

```text
oc_daytracker_categories
oc_daytracker_options
oc_daytracker_entries
oc_daytracker_timeslices
```

Die vorhandenen Primärschlüssel und Fremdschlüsselbeziehungen bleiben erhalten. Kategorien, Optionen und Zeitscheiben werden über ihre technischen IDs referenziert. Sichtbare Namen und Labels sind keine Zuordnungsschlüssel.

Diese Version enthält keine neue Migration. Vorhandene historische Migrationen unter `lib/Migration/` müssen erhalten bleiben.

## Dateien der vollständigen Version

```text
daytracker/
├── appinfo/
│   ├── info.xml
│   └── routes.php
├── css/
│   └── style.css
├── img/
│   └── app.svg
├── js/
│   ├── dashboard.js
│   └── main.js
├── lib/
│   ├── AppInfo/
│   │   └── Application.php
│   ├── Controller/
│   │   └── PageController.php
│   ├── Dashboard/
│   │   └── DaytrackerWidget.php
│   └── Migration/
│       └── vorhandene historische Migrationen, unverändert
├── templates/
│   └── main.php
└── README.md
```

## Installation und Upgrade

### Bestehende AIO-Installation aktualisieren (empfohlen)

[update-daytracker.py](update-daytracker.py) übernimmt Download, Prüfsumme, Sicherung, Rechte, Dateiaustausch, notwendige Migrationen und Neustart. Voraussetzung: eine bereits installierte Daytracker-App, Linux-Docker-Host mit Python ab 3.9, Docker CLI, `curl`, sudo/root und HTTPS-Zugriff auf GitHub. Die Befehle auf dem **Docker-Host** ausführen.

**1. Skript herunterladen oder aktualisieren:**

```bash
curl --fail --location --proto '=https' --proto-redir '=https' \
  https://raw.githubusercontent.com/rankerson/nextcloud_daytracker/main/update-daytracker.py \
  --output update-daytracker.py
```

**2. Optional vorab prüfen, ohne den Server zu verändern:**

```bash
sudo python3 update-daytracker.py --dry-run
```

**3. Neueste stabile Veröffentlichung installieren:**

```bash
sudo python3 update-daytracker.py
```

Das Skript zeigt den geplanten Ablauf und fragt vor Änderungen nach. Bei einer deaktivierten App fragt es zusätzlich nach der Aktivierung. **Wurde Daytracker durch das Update auf Nextcloud 34 deaktiviert, diese Aktivierungsfrage mit Ja beantworten.** Während des Updates wird Nextcloud vorübergehend in den Wartungsmodus versetzt.

Alternativ gezielt eine Version installieren:

```bash
sudo python3 update-daytracker.py v3.0.3
```

Ohne Versionsangabe wird GitHubs `latest` verwendet. Ist die gewünschte Version bereits installiert, endet das Skript ohne Änderungen. Für eine erneute Installation samt Aktivierung:

```bash
sudo python3 update-daytracker.py --reinstall --enable
```

Sicherungen liegen standardmäßig auf dem Host unter `/var/backups/daytracker/`. Nach erfolgreichem Abschluss Daytracker im Browser öffnen und mit `Strg + F5` vollständig neu laden. Zusätzliche manuelle `occ`- oder Migrationsbefehle sind danach nicht erforderlich.

Die [ausführliche Update-Anleitung](UPDATE.md) beschreibt Containernamen, unbeaufsichtigte Updates, Sicherungsumfang und Fehlerbehandlung. Für spätere Updates das Skript erneut herunterladen, um auch Verbesserungen am Updater zu erhalten.

### Erstinstallation der App

Das Update-Skript setzt eine vorhandene Daytracker-Installation voraus. Für die Erstinstallation in einer bestehenden Nextcloud-AIO-Instanz:

1. Das zur Nextcloud-Version passende [Release](https://github.com/rankerson/nextcloud_daytracker/releases) auswählen und das App-Archiv `daytracker-<VERSION>.tar.gz` sowie die zugehörige `.sha256`-Datei herunterladen. Version 3.0.3 unterstützt Nextcloud 33 und 34.
2. Mit `sha256sum -c daytracker-<VERSION>.tar.gz.sha256` die Prüfsumme kontrollieren und das Archiv entpacken. Den Platzhalter durch die gewählte Versionsnummer ersetzen.
3. Den vollständigen Ordner `daytracker/` nach `/var/www/html/custom_apps/daytracker` im Nextcloud-Container kopieren. Er enthält auch alle historischen Migrationen.
4. Besitzer auf `www-data:www-data`, Verzeichnisrechte auf `750` und Dateirechte auf `640` setzen.
5. Die App aktivieren; Nextcloud führt dabei die erforderlichen Migrationen aus:

```bash
docker exec -u www-data nextcloud-aio-nextcloud php occ app:enable daytracker
```

Anschließend Daytracker im Browser öffnen. Besteht bereits eine Installation, den oben beschriebenen Updateablauf mit Sicherung verwenden.

## Datenbankprüfung

### Tabellen anzeigen

Für eine direkte Prüfung ist die PostgreSQL-Konsole des zugehörigen AIO-Datenbankcontainers zu verwenden. Containername und Zugangsdaten sind der eigenen AIO-Konfiguration zu entnehmen.

In PostgreSQL können die Spalten der vier Tabellen mit folgender Abfrage geprüft werden:

```sql
SELECT table_name, column_name, data_type, is_nullable
FROM information_schema.columns
WHERE table_name IN (
    'oc_daytracker_categories',
    'oc_daytracker_options',
    'oc_daytracker_entries',
    'oc_daytracker_timeslices'
)
ORDER BY table_name, ordinal_position;
```

Erwartete Spalten:

```text
oc_daytracker_categories:
  id, user_id, name, sort_order, dashboard_limit, dashboard_enabled, input_mode

oc_daytracker_options:
  id, category_id, label, sort_order

oc_daytracker_entries:
  id, user_id, entry_date, timeslice_id, category_id,
  option_id, text_value, updated_at

oc_daytracker_timeslices:
  id, user_id, name, sort_order
```

### Eindeutigkeitsregel prüfen

```sql
SELECT user_id, entry_date, timeslice_id, category_id, COUNT(*)
FROM oc_daytracker_entries
GROUP BY user_id, entry_date, timeslice_id, category_id
HAVING COUNT(*) > 1;
```

Die Abfrage soll keine Zeilen zurückgeben.

### Verwaiste Kategoriebezüge prüfen

```sql
SELECT e.id
FROM oc_daytracker_entries e
LEFT JOIN oc_daytracker_categories c ON c.id = e.category_id
WHERE c.id IS NULL;
```

### Verwaiste Optionsbezüge prüfen

```sql
SELECT e.id
FROM oc_daytracker_entries e
LEFT JOIN oc_daytracker_options o ON o.id = e.option_id
WHERE e.option_id IS NOT NULL
  AND o.id IS NULL;
```

### Optionen in falschen Kategorien prüfen

```sql
SELECT e.id, e.category_id, e.option_id, o.category_id AS option_category_id
FROM oc_daytracker_entries e
JOIN oc_daytracker_options o ON o.id = e.option_id
WHERE e.category_id <> o.category_id;
```

### Verwaiste Zeitscheibenbezüge prüfen

```sql
SELECT e.id
FROM oc_daytracker_entries e
LEFT JOIN oc_daytracker_timeslices t ON t.id = e.timeslice_id
WHERE t.id IS NULL;
```

Diese Prüfungen lesen ausschließlich Daten. Sie nehmen keine Reparatur und keine Neuordnung von IDs vor.

## API-Testpfade

Mit eigener Domain aufrufen:

```text
/index.php/apps/daytracker/
/index.php/apps/daytracker/api/catalog
/index.php/apps/daytracker/api/day/YYYY-MM-DD
/index.php/apps/daytracker/export.csv
```

Beispiel mit Platzhalter:

```text
https://<deine-domain>/index.php/apps/daytracker/
https://<deine-domain>/index.php/apps/daytracker/api/catalog
https://<deine-domain>/index.php/apps/daytracker/api/day/2026-08-20
https://<deine-domain>/index.php/apps/daytracker/export.csv
```

Die beiden POST-Endpunkte sind nicht für einen direkten Browseraufruf vorgesehen. Sie benötigen einen gültigen Nextcloud-CSRF-Token im Header `requesttoken`.

## Browser-Testcheckliste

### Hauptansicht

- Daytracker lädt ohne HTTP-500-Fehler.
- Die Browserkonsole bleibt frei von JavaScript-Fehlern.
- Der Status wechselt von `Lade ...` zu `Bereit`.
- Tag und Woche lassen sich umschalten.
- Datum zurück, Datum vor und Heute funktionieren.
- Die gewählte Zeitscheibe bleibt innerhalb der Ansicht aktiv.
- Die Hauptansicht nutzt die verfügbare Seitenbreite.

### Tagesansicht

- Jede Kategorie wird als eigene kompakte Karte angezeigt.
- Ein leerer Tag hebt keine Option hervor.
- Klick auf eine Option speichert automatisch.
- Nach dem Speichern ist genau eine Option der Kategorie hervorgehoben.
- Freitext lässt sich mehrzeilig eingeben.
- Freitextfelder lassen sich vertikal vergrößern.
- Der kleine Speichern-Button speichert Freitext.
- Bei `both` bleiben Option und Freitext gemeinsam erhalten.
- Der Status wechselt über `Speichere ...` zu `Gespeichert`.

### Wochenansicht

- Montag bis Sonntag werden angezeigt.
- Kategorien werden zeilenweise dargestellt.
- Optionswerte lassen sich pro Tag pflegen.
- Freitext lässt sich pro Tag pflegen.
- Aktive Optionen werden korrekt hervorgehoben.
- Die Matrix scrollt bei Bedarf horizontal.
- Es existiert kein zusätzlicher vertikaler App-Scrollbereich.
- Alle Kategorien sind über den normalen Nextcloud-Seitenscroll erreichbar.

### Administration

- Das Modal ist beim initialen Laden unsichtbar.
- Administration öffnet sich über den Button.
- Schließen funktioniert über X, Schließen, Overlay und Escape.
- Kategorien lassen sich hinzufügen, umbenennen, sortieren und löschen.
- Optionen lassen sich hinzufügen, umbenennen, sortieren und löschen.
- Zeitscheiben lassen sich hinzufügen, umbenennen, sortieren und löschen.
- Mindestens eine Zeitscheibe bleibt erhalten.
- Technische IDs sind sichtbar, aber nicht editierbar.
- Neue Elemente zeigen `neu` und erhalten serverseitig ihre ID.
- Der Dialog springt nach Hinzufügen, Löschen oder Verschieben nicht nach oben.
- Speichern zeigt `Administration gespeichert`.
- Nach Umbenennung bleiben vorhandene Tageswerte zugeordnet.
- Gelöschte Kategorien und Optionen erscheinen nach erneutem Laden nicht wieder.
- Der CSV-Link ist nur im Administrationsmodal vorhanden.

### Dashboard

- Das Widget `Daytracker heute` kann zum Dashboard hinzugefügt werden.
- Datum zurück, Datepicker und Datum vor funktionieren.
- Die Zeitscheibenauswahl funktioniert.
- Das Widget bleibt innerhalb seiner verfügbaren Breite.
- Kategorieüberschriften und Optionsbuttons sind gut lesbar.
- `dashboard_limit = 0` zeigt keine Optionsbuttons.
- `dashboard_limit = 2` zeigt die ersten beiden Optionen.
- Eine gespeicherte sichtbare Option ist hervorgehoben.
- Eine gespeicherte nicht sichtbare Option wird als Text zusammengefasst.
- Ein gespeicherter Freitext wird kompakt zusammengefasst.
- Klick auf eine Option speichert automatisch.
- Der Link zur vollständigen App funktioniert.

### Benutzertrennung

Die Prüfung erfolgt sinnvollerweise mit zwei Testbenutzern:

- Benutzer A sieht keine Kategorien von Benutzer B.
- Benutzer A sieht keine Zeitscheiben von Benutzer B.
- Benutzer A sieht keine Tageswerte von Benutzer B.
- Benutzer A kann keine fremden technischen IDs über die API verwenden.
- CSV-Exporte enthalten nur Daten des angemeldeten Benutzers.

## Protokollprüfung

Nextcloud-Protokoll während eines Tests beobachten:

```bash
docker exec -u www-data nextcloud-aio-nextcloud php occ log:watch
```

Falls `log:watch` in der konkreten Installation nicht verfügbar ist, kann das Nextcloud-Protokoll direkt geprüft werden:

```bash
docker exec -u www-data nextcloud-aio-nextcloud bash -lc '
tail -n 200 /var/www/html/data/nextcloud.log
'
```

## Wiederherstellung nach einem fehlgeschlagenen Update

Das Skript meldet im Fehlerfall den Sicherungspfad. Dort stehen `update.log` und `RECOVERY.txt` für Diagnose und Wiederherstellung bereit. Vor Beginn möglicher Migrationen versucht es, die alten App-Dateien wiederherzustellen. Nach Beginn eines Upgrades oder einer Migration bleibt Nextcloud im Wartungsmodus; ein automatischer Rollback erfolgt dann nicht.

In diesem Fall zunächst den Fehler klären. Eine Wiederherstellung muss zusammenpassende App-Dateien, Konfiguration und Datenbank verwenden; nur alte App-Dateien zurückzukopieren genügt nach Datenbankänderungen nicht. App-Sicherungen außerhalb von `custom_apps/` aufbewahren, damit Nextcloud sie nicht als weitere App erkennt.

Sicherungsumfang und Verhalten bei Abbrüchen sind unter [Sicherungen und Fehler](UPDATE.md#sicherungen-und-fehler) beschrieben.

## Sicherheitsmerkmale

- POST-Routen bleiben CSRF-geschützt.
- JavaScript sendet `requesttoken: OC.requestToken`.
- JSON wird über `file_get_contents('php://input')` gelesen.
- Kategorieabfragen werden über `user_id` abgesichert.
- Optionen werden über die benutzereigene Kategorie validiert.
- Zeitscheiben werden über `user_id` validiert.
- Löschungen erfolgen benutzerbezogen und transaktional.
- CSV-Export ist benutzerbezogen.
- CSV-Formelpräfixe werden im Export entschärft.

## Keine automatische Wiederherstellung gelöschter Katalogwerte

Standarddaten werden nur bei einer echten Erstinitialisierung angelegt. Ein benutzerbezogener Nextcloud-Konfigurationsmarker verhindert, dass später gelöschte Kategorien oder Optionen automatisch erneut erscheinen.

Die App legt keine Standardwerte anhand sichtbarer Namen erneut an und ordnet keine bestehenden Tageswerte über Namen oder Array-Positionen um.
