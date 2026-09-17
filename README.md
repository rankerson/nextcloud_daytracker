# Daytracker für Nextcloud 33 und 34

Daytracker ist eine native Nextcloud-App zur benutzerbezogenen Erfassung von Tageswerten nach Kategorien und Zeitscheiben.

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

### 1. Sicherung erstellen

Vor dem Austausch der Dateien muss das produktive App-Verzeichnis gesichert werden:

```bash
docker exec -u root nextcloud-aio-nextcloud bash -lc '
set -e
stamp=$(date +%Y%m%d-%H%M%S)
mkdir -p /var/www/daytracker-backups
cp -a /var/www/html/custom_apps/daytracker "/var/www/daytracker-backups/daytracker-${stamp}"
echo "Sicherung: /var/www/daytracker-backups/daytracker-${stamp}"
'
```

Eine zusätzliche PostgreSQL-Sicherung entsprechend dem bestehenden AIO-Sicherungskonzept wird empfohlen, bevor produktive Dateien ersetzt werden.

### 2. Release installieren

Das vollständige Archiv [daytracker-3.0.3.tar.gz](https://github.com/rankerson/nextcloud_daytracker/releases/tag/v3.0.3) herunterladen und anhand der beigefügten SHA-256-Datei prüfen. Es enthält genau einen App-Ordner `daytracker/`; mehrere Pakete müssen nicht mehr zusammengeführt werden.

Auf dem Docker-Host in ein leeres Arbeitsverzeichnis entpacken:

```bash
sha256sum -c daytracker-3.0.3.tar.gz.sha256
tar -xzf daytracker-3.0.3.tar.gz
```

Nach der Sicherung das vorhandene App-Verzeichnis durch den vollständigen neuen Ordner ersetzen. Die Sicherung außerhalb von `custom_apps/` aufbewahren, damit Nextcloud sie nicht als zweite App erkennt. Das Archiv enthält alle historischen Migrationen unter `lib/Migration/`.

Zielpfad im AIO-Container:

```text
/var/www/html/custom_apps/daytracker
```

### 3. Rechte setzen

```bash
docker exec -u root nextcloud-aio-nextcloud chown -R www-data:www-data /var/www/html/custom_apps/daytracker
```

Optional können die Verzeichnis- und Dateirechte normalisiert werden:

```bash
docker exec -u root nextcloud-aio-nextcloud bash -lc '
find /var/www/html/custom_apps/daytracker -type d -exec chmod 750 {} \;
find /var/www/html/custom_apps/daytracker -type f -exec chmod 640 {} \;
'
```

### 4. PHP-Syntax prüfen

```bash
docker exec -u www-data nextcloud-aio-nextcloud bash -lc '
cd /var/www/html/custom_apps/daytracker
find . -type f -name "*.php" -exec php -l {} \;
'
```

Jede Datei muss mit `No syntax errors detected` bestätigt werden. Bei einem Syntaxfehler darf das Upgrade nicht fortgesetzt werden.

### 5. JavaScript-Syntax prüfen

Falls Node.js im Nextcloud-Container verfügbar ist:

```bash
docker exec -u www-data nextcloud-aio-nextcloud bash -lc '
cd /var/www/html/custom_apps/daytracker
node --check js/main.js
node --check js/dashboard.js
'
```

Falls Node.js dort nicht verfügbar ist, müssen beide Dateien vor dem Upload lokal mit `node --check` geprüft werden.

### 6. Upgrade ausführen

```bash
docker exec -u www-data nextcloud-aio-nextcloud php occ upgrade
```

### 7. App-Status prüfen

```bash
docker exec -u www-data nextcloud-aio-nextcloud php occ app:list | grep -A3 -B3 daytracker
```

Falls die App noch nicht aktiviert ist:

```bash
docker exec -u www-data nextcloud-aio-nextcloud php occ app:enable daytracker
```

### 8. Reparatur ausführen

```bash
docker exec -u www-data nextcloud-aio-nextcloud php occ maintenance:repair
```

### 9. Container neu starten

```bash
docker restart nextcloud-aio-nextcloud
```

### 10. Browsercache aktualisieren

Die Daytracker-Seite öffnen und anschließend einen vollständigen Browser-Reload ausführen:

```text
Strg + F5
```

## Datenbankprüfung

### Tabellen anzeigen

```bash
docker exec -u www-data nextcloud-aio-nextcloud php occ db:convert-type --help >/dev/null
```

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

## Rückfallanleitung

### 1. App deaktivieren

```bash
docker exec -u www-data nextcloud-aio-nextcloud php occ app:disable daytracker
```

### 2. Aktuellen Stand sichern

```bash
docker exec -u root nextcloud-aio-nextcloud bash -lc '
stamp=$(date +%Y%m%d-%H%M%S)
mv /var/www/html/custom_apps/daytracker "/var/www/html/custom_apps/daytracker.failed-${stamp}"
'
```

### 3. Sicherung zurückkopieren

Den zuvor erzeugten Sicherungspfad einsetzen:

```bash
docker exec -u root nextcloud-aio-nextcloud bash -lc '
cp -a /var/www/daytracker-backups/daytracker-<ZEITSTEMPEL> /var/www/html/custom_apps/daytracker
chown -R www-data:www-data /var/www/html/custom_apps/daytracker
'
```

### 4. App wieder aktivieren

```bash
docker exec -u www-data nextcloud-aio-nextcloud php occ app:enable daytracker
```

### 5. Reparatur und Neustart

```bash
docker exec -u www-data nextcloud-aio-nextcloud php occ maintenance:repair
docker restart nextcloud-aio-nextcloud
```

Anschließend im Browser `Strg + F5` ausführen.

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
