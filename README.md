# Daytracker

Daytracker ist eine native Nextcloud-App für Nextcloud 33. Sie speichert pro angemeldetem Benutzer, Kalendertag und Kategorie genau eine Option, zum Beispiel Arbeitsort oder Aufenthaltsort.

## Zielumgebung

- Nextcloud 33
- Nextcloud AIO
- PostgreSQL
- PHP 8.1+
- App-ID: `daytracker`
- Namespace: `OCA\Daytracker`
- App-Verzeichnis: `/var/www/html/custom_apps/daytracker`
- Nextcloud-Container: `nextcloud-aio-nextcloud`
- Kein npm-Build
- Kein Vue
- Kein TypeScript
- Vanilla JavaScript

## Funktionsumfang

### Phase 1

- Native Nextcloud-App
- Hauptansicht mit Datumsauswahl
- Kategorien und Optionen als Buttons
- Automatisches Speichern nach Klick
- Hervorhebung gespeicherter Werte
- Keine Default-Hervorhebung für neue Tage
- Administration als div-basiertes Modal
- Kategorien und Optionen pflegen
- CSV-Export aus der Administration
- Benutzerbezogene Daten
- PostgreSQL-kompatibles Datenmodell

### Phase 2

- Nextcloud Dashboard Widget
- Datumsauswahl im Widget
- Schnellauswahl der ersten zwei Optionen je Kategorie
- Automatisches Speichern im Widget
- Link zur vollständigen App

## Datenmodell

Die App verwendet folgende Tabellen. Nextcloud ergänzt automatisch den konfigurierten Tabellenpräfix, typischerweise `oc_`.

### `daytracker_categories`

| Feld | Typ | Bedeutung |
| --- | --- | --- |
| `id` | integer | Primärschlüssel |
| `user_id` | string | Benutzer-ID |
| `name` | string | Kategorie-Name |
| `sort_order` | integer | Sortierung |

### `daytracker_options`

| Feld | Typ | Bedeutung |
| --- | --- | --- |
| `id` | integer | Primärschlüssel |
| `category_id` | integer | Kategorie-ID |
| `label` | string | Optionsbezeichnung |
| `sort_order` | integer | Sortierung |

### `daytracker_entries`

| Feld | Typ | Bedeutung |
| --- | --- | --- |
| `id` | integer | Primärschlüssel |
| `user_id` | string | Benutzer-ID |
| `entry_date` | string | Datum im Format `YYYY-MM-DD` |
| `category_id` | integer | Kategorie-ID |
| `option_id` | integer | Option-ID |
| `updated_at` | string | Änderungszeitpunkt im Format `YYYY-MM-DD HH:MM:SS` |

Für Tageswerte existiert ein Unique Index auf:

```text
user_id, entry_date, category_id
```

Damit kann pro Benutzer, Datum und Kategorie nur ein Eintrag existieren.

## Standardkatalog

Beim ersten Aufruf wird je Benutzer ein Standardkatalog selbstheilend angelegt.

### Arbeitsort

1. HomeOffice
2. Geschäftsstelle
3. Geschäftsreise mit RK
4. Geschäftsreise ohne RK
5. Urlaub
6. Krankheit
7. keine Arbeit

### Aufenthaltsort

1. Berlin
2. Hamburg
3. 50%/50%
4. sonstiges

Die Initialisierung prüft jede Kategorie und jede Option einzeln. Fehlende Daten werden ergänzt, vorhandene Daten werden nicht doppelt angelegt.

## API-Routen

| Methode | Route | Zweck | CSRF |
| --- | --- | --- | --- |
| GET | `/index.php/apps/daytracker/` | Hauptansicht | nicht erforderlich |
| GET | `/index.php/apps/daytracker/api/catalog` | Katalog laden | nicht erforderlich |
| POST | `/index.php/apps/daytracker/api/catalog` | Administration speichern | erforderlich |
| GET | `/index.php/apps/daytracker/api/day/YYYY-MM-DD` | Tageswerte laden | nicht erforderlich |
| POST | `/index.php/apps/daytracker/api/day/YYYY-MM-DD` | Tageswert speichern | erforderlich |
| GET | `/index.php/apps/daytracker/export.csv` | CSV exportieren | nicht erforderlich |

POST-Anfragen senden im JavaScript den Header:

```text
requesttoken: OC.requestToken
```

## CSV-Export

Der CSV-Export befindet sich ausschließlich im Administrationsmodal.

Eigenschaften:

- UTF-8 mit BOM
- Semikolon als Separator
- Dateiname: `daytracker-export.csv`
- Nur Daten des angemeldeten Benutzers
- Sortierung nach Datum und Kategorie

Spalten:

```text
Datum;Kategorie;Option;letztes Änderungsdatum
```

## Frontend-ID-Matrix

### IDs in `templates/main.php`

```text
dt-app
dt-admin-open
dt-date-prev
dt-date
dt-date-next
dt-state
dt-category-list
dt-admin-modal
dt-admin-overlay
dt-admin-panel
dt-admin-title
dt-admin-close
dt-admin-content
dt-admin-add-category
dt-csv-export
dt-admin-save
dt-admin-cancel
```

### IDs in `js/main.js`

```text
dt-app
dt-admin-open
dt-date-prev
dt-date
dt-date-next
dt-state
dt-category-list
dt-admin-modal
dt-admin-overlay
dt-admin-panel
dt-admin-close
dt-admin-content
dt-admin-add-category
dt-csv-export
dt-admin-save
dt-admin-cancel
```

### Modal-Entscheidung

Die App verwendet ein div-basiertes Modal.

```text
showModal(): nein
.close(): nein
<dialog>: nein
```

Das Modal wird geöffnet über:

```javascript
elements.adminModal.hidden = false;
```

Das Modal wird geschlossen über:

```javascript
elements.adminModal.hidden = true;
```

## Installation in Nextcloud AIO

### 1. App-Ordner erstellen

```bash
docker exec -u root nextcloud-aio-nextcloud mkdir -p /var/www/html/custom_apps/daytracker
```

### 2. Dateien kopieren

Kopiere den Inhalt des Ordners `daytracker/` aus allen ZIP-Paketen nach:

```text
/var/www/html/custom_apps/daytracker
```

Die Zielstruktur muss danach so aussehen:

```text
/var/www/html/custom_apps/daytracker/appinfo/info.xml
/var/www/html/custom_apps/daytracker/appinfo/routes.php
/var/www/html/custom_apps/daytracker/lib/AppInfo/Application.php
/var/www/html/custom_apps/daytracker/lib/Migration/Version1000Date20260817233000.php
/var/www/html/custom_apps/daytracker/lib/Controller/PageController.php
/var/www/html/custom_apps/daytracker/lib/Dashboard/DaytrackerWidget.php
/var/www/html/custom_apps/daytracker/templates/main.php
/var/www/html/custom_apps/daytracker/js/main.js
/var/www/html/custom_apps/daytracker/js/dashboard.js
/var/www/html/custom_apps/daytracker/css/style.css
/var/www/html/custom_apps/daytracker/img/app.svg
/var/www/html/custom_apps/daytracker/README.md
```

### 3. Rechte setzen

```bash
docker exec -u root nextcloud-aio-nextcloud chown -R www-data:www-data /var/www/html/custom_apps/daytracker
```

### 4. PHP-Syntax prüfen

```bash
docker exec -u www-data nextcloud-aio-nextcloud bash -lc '
cd /var/www/html/custom_apps/daytracker
find . -type f -name "*.php" -exec php -l {} \;
'
```

Erwartetes Ergebnis:

```text
No syntax errors detected in ./appinfo/routes.php
No syntax errors detected in ./lib/AppInfo/Application.php
No syntax errors detected in ./lib/Migration/Version1000Date20260817233000.php
No syntax errors detected in ./lib/Controller/PageController.php
No syntax errors detected in ./lib/Dashboard/DaytrackerWidget.php
No syntax errors detected in ./templates/main.php
```

### 5. App aktivieren

```bash
docker exec -u www-data nextcloud-aio-nextcloud php occ app:enable daytracker
```

### 6. Optional Reparatur ausführen

```bash
docker exec -u www-data nextcloud-aio-nextcloud php occ maintenance:repair
```

### 7. Container neu starten

```bash
docker restart nextcloud-aio-nextcloud
```

### 8. Browser hart neu laden

```text
Strg + F5
```

### 9. Test-URLs

Bitte ersetze `<deine-domain>` durch Deine Nextcloud-Domain.

```text
https://<deine-domain>/index.php/apps/daytracker/
https://<deine-domain>/index.php/apps/daytracker/api/catalog
https://<deine-domain>/index.php/apps/daytracker/api/day/2026-08-17
https://<deine-domain>/index.php/apps/daytracker/export.csv
```

## Prüfcheckliste

Nach Installation bitte prüfen:

1. `php -l` meldet keine Syntaxfehler.
2. `occ app:enable daytracker` macht Nextcloud nicht unbenutzbar.
3. Die App-Seite öffnet ohne JavaScript-Fehler.
4. Beide Kategorien erscheinen: Arbeitsort und Aufenthaltsort.
5. Alle Optionen erscheinen als Buttons.
6. Für einen neuen Tag ist kein Button aktiv.
7. Nach Klick auf eine Option erscheint kurz `Speichere ...`.
8. Danach erscheint `Gespeichert`.
9. Nach Reload ist der gespeicherte Button aktiv.
10. Pro Kategorie ist höchstens ein Button aktiv.
11. Datumswechsel lädt die Werte des gewählten Tages.
12. Administration ist beim Laden nicht sichtbar.
13. Administration öffnet per Button.
14. Administration schließt per Button, Overlay und ESC.
15. Administration zeigt Eingabefelder für Kategorien und Optionen.
16. Kategorie hinzufügen funktioniert.
17. Administration speichern lädt Katalog und Tageswerte neu.
18. CSV exportieren ist nur im Modal sichtbar.
19. CSV-Export öffnet `/index.php/apps/daytracker/export.csv`.
20. Dashboard Widget erscheint im Dashboard.
21. Dashboard Widget zeigt Datumsauswahl.
22. Dashboard Widget zeigt je Kategorie die ersten zwei Optionen.
23. Dashboard Widget speichert automatisch.
24. `window.daytrackerDebug.selectedByCategory` zeigt die geladenen Werte.

## Fehlerdiagnose

### JavaScript-Dateien werden scheinbar nicht aktualisiert

Browser hart neu laden:

```text
Strg + F5
```

Alternativ im privaten Fenster testen.

### App wird nicht gefunden

Prüfe die Struktur:

```bash
docker exec -u www-data nextcloud-aio-nextcloud bash -lc '
ls -la /var/www/html/custom_apps/daytracker
ls -la /var/www/html/custom_apps/daytracker/appinfo
'
```

### PHP-Syntaxfehler finden

```bash
docker exec -u www-data nextcloud-aio-nextcloud bash -lc '
cd /var/www/html/custom_apps/daytracker
find . -type f -name "*.php" -exec php -l {} \;
'
```

### App deaktivieren, falls nötig

```bash
docker exec -u www-data nextcloud-aio-nextcloud php occ app:disable daytracker
```

## Hinweise zur Wartbarkeit

- Keine minifizierten Einzeiler
- Keine Build-Abhängigkeit
- Keine globale Variable `values`
- Debug über `window.daytrackerDebug`
- Keine Default-Hervorhebung ohne gespeicherte Einträge
- Kein `showModal()`
- Kein `.close()`
- Keine Boolean-Spalte `highlighted`
