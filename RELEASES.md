# Versionen und Releases

## Aktuell: v3.0.3

[v3.0.3](https://github.com/rankerson/nextcloud_daytracker/releases/tag/v3.0.3) erweitert die Unterstützung auf Nextcloud 33–34 und korrigiert den CSV-Export für aktuelle PHP-Versionen. Die Veröffentlichung erfolgt erst nach erfolgreichen Browser- und API-Tests auf Nextcloud 33 und 34.0.4 mit PostgreSQL. Einzelheiten stehen in `release-notes/3.0.3.md`.

## Historische Versionen

Die früheren Versionsordner sind als eigenständige Git-Tags erhalten. Jeder Tag enthält die App direkt im Hauptverzeichnis sowie die Repository-Lizenz.

| Version | Tag-Commit | Ursprünglicher Ordner |
| --- | --- | --- |
| [v1.1.3](https://github.com/rankerson/nextcloud_daytracker/releases/tag/v1.1.3) | `8dcecadbcb9c64146cac7a5ecacc48f837a9307a` | `daytracker_Version 1.1.3` |
| [v2.0.5](https://github.com/rankerson/nextcloud_daytracker/releases/tag/v2.0.5) | `64b23da1d3e94d8968a56ef10a613a558037e797` | `daytracker_Version 2.0.5` |
| [v3.0.0](https://github.com/rankerson/nextcloud_daytracker/releases/tag/v3.0.0) | `83f48f0377955402adfadc48d35aba0e98ed1514` | `daytracker_Version 3.0.0` |
| [v3.0.2](https://github.com/rankerson/nextcloud_daytracker/releases/tag/v3.0.2) | `151e4b4fa193c4708b2a90ea0b0c72c90f8dfe42` | `daytracker_Version 3.0.2` |

Referenz für die ursprünglichen Ordner ist Commit `f084db23e7c48af51a621e22400383cbaecc36ae`. Alle Dateien stimmen anhand ihrer Git-Blob-Hashes mit den jeweiligen Tags überein. Die Versionsnummern in `appinfo/info.xml` entsprechen den Tags. Die zusätzlich enthaltene `LICENSE` stammt unverändert aus dem Repository.

Der Workflow **Publish historical releases** prüft diese Zuordnung und die erzeugten Archive erneut und veröffentlicht die vier historischen Versionen. Bestehende Releases werden übersprungen. Der Workflow kann unter **Actions → Publish historical releases → Run workflow** erneut gestartet werden.

Jedes Release erhält ein Archiv `daytracker-VERSION.tar.gz` mit dem App-Ordner `daytracker/` und eine SHA-256-Prüfsumme. Für Installation und Upgrades gilt die README des jeweiligen Tags. Die Veröffentlichung umfasst keinen neuen Nextcloud-Laufzeittest. `v3.0.2` ist die neueste dieser vier Versionen.

Neue Versionen künftig über eigene Commits, Tags und Releases veröffentlichen; keine neuen Versionsordner anlegen. Bestehende Tags bleiben unverändert.
