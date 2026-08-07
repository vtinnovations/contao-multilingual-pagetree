<p align="center">
  <img src=".github/assets/vt-one-logo.png" alt="V&amp;T Innovations" width="280">
</p>

<h1 align="center">Contao Multilingual Pagetree</h1>

<p align="center">Mehrsprachige Contao-Websites in einem gemeinsamen Seitenbaum verwalten.</p>

<p align="center">
  <a href="https://packagist.org/packages/vtinnovations/contao-multilingual-pagetree"><img src="https://img.shields.io/packagist/v/vtinnovations/contao-multilingual-pagetree" alt="Packagist version"></a>
  <img src="https://img.shields.io/badge/PHP-%5E8.1-777BB4?logo=php&amp;logoColor=white" alt="PHP ^8.1">
  <img src="https://img.shields.io/badge/Contao-%5E5.0-F47C00?logo=contao&amp;logoColor=white" alt="Contao ^5.0">
  <img src="https://img.shields.io/badge/licence-proprietary-blue" alt="Proprietary licence">
</p>

<p align="center"><em>English version: <a href="README.en.md">README.en.md</a></em></p>

---

Contao Multilingual Pagetree erweitert Contao um einen redaktionellen Übersetzungs-Workflow, ohne für jede Sprache einen getrennten Seitenbaum anzulegen. Alle Sprachen einer Website teilen sich eine Seitenstruktur; Übersetzungen werden über Sprachregister direkt in den gewohnten Contao-Bearbeitungsformularen gepflegt. Bei Bedarf können Inhalte einer Sprache auch unabhängig gepflegt werden.

- **Paket:** `vtinnovations/contao-multilingual-pagetree`
- **Typ:** `contao-bundle`
- **Namensraum:** `Vtinnovations\ContaoMultilingualPagetree`
- **Lizenz:** proprietär

## Funktionsumfang

- ein gemeinsamer Seitenbaum für alle konfigurierten Sprachen
- Sprachregister direkt in den Contao-Bearbeitungsformularen
- verbundene Übersetzungen mit den Feldzuständen **Vererben**, **Eigene Übersetzung** und **Bewusst leer**
- freie Sprachinhalte mit unabhängigen Artikeln und Inhaltselementen
- übersetzte Aliase für Seiten, Nachrichten, Termine und FAQ
- Sprach-URLs je Sprache: Protokoll, eigene Domain und Einstiegspfad
- gleiche Domain mit Pfadpräfixen, getrennte Domains oder eine Mischung aus beidem
- Sprachwechsler als Frontend-Modul mit Verfügbarkeitsprüfung
- kanonische URLs, `hreflang` und `x-default`
- redaktioneller Prüfstatus nach Änderungen der Ausgangssprache
- Integritätsprüfung mit Vorschau vor Reparaturen
- strikte oder rückfallende Seitenverfügbarkeit je Zielsprache
- getrennte Verwaltung je Website-Startpunkt in Installationen mit mehreren Websites

## Status

Das Paket ist funktionsfähig implementiert und noch nicht versioniert veröffentlicht. Der Changelog führt den Stand des `main`-Branches unter `## [Unreleased]`.

## Voraussetzungen

| Anforderung | Version |
| --- | --- |
| PHP | `^8.1` |
| Contao | `^5.0` (`contao/core-bundle`) |
| Composer | für Installation und Aktualisierung |
| PHPUnit | `^10.5` (nur Entwicklung) |

Die Integrationen für News, Kalender und FAQ werden nur aktiv, wenn das jeweilige Contao-Bundle installiert ist.

## Installation

```bash
composer require vtinnovations/contao-multilingual-pagetree
vendor/bin/contao-console cache:clear
vendor/bin/contao-console contao:migrate
```

Alternativ über den Contao Manager. Ersetzen Sie das Paketverzeichnis bei einem Update vollständig, damit entfernte Dateien nicht bestehen bleiben.

Die Datenbankaktualisierung ist erforderlich: Das Paket legt eigene Tabellen und Spalten an.

## Dateisystem und Laufzeitverzeichnisse

Das Paket schreibt ausschließlich unterhalb von `var/` und damit außerhalb des öffentlichen Verzeichnisses:

| Verzeichnis | Zweck |
| --- | --- |
| `var/contao-multilingual-pagetree/state/` | interner Betriebszustand |
| `var/contao-multilingual-pagetree/licences/` | gespeicherter Lizenzstatus je Website-Startpunkt |

Beide Verzeichnisse müssen für den PHP-Prozess beschreibbar sein. Ein noch nicht vorhandenes Verzeichnis ist ein gültiger Ausgangszustand.

## Zugriff im Backend

| Einstiegspunkt | Ort |
| --- | --- |
| Sprachverwaltung | **Seitenstruktur** → Globus-Aktion am Website-Startpunkt |
| Lizenzbereich | **Seitenstruktur** → Website-Startpunkt bearbeiten |
| Sprachregister | Bearbeitungsformular unterstützter Datensätze |
| Berechtigungen | **Benutzer** und **Benutzergruppen** |

Die Sprachverwaltung eines Startpunkts bietet je Sprache: Sprachcode, Bezeichnung, Flagge, Sprach-URL, Seitenverfügbarkeit, Inhaltsübersetzungsmodus und Veröffentlichung.

## Sprach-URLs

Jede Sprache kann Protokoll, Domain und Einstiegspfad eigenständig festlegen. Alle drei Felder sind optional.

| Domain | Einstiegspfad | Ergebnis |
| --- | --- | --- |
| *(leer)* | *(leer)* | bisherige Adressbildung: Ausgangssprache ohne Präfix, andere Sprachen unter ihrem Sprachcode |
| *(leer)* | `/en` | Domain der Website-Wurzel plus `/en` |
| `www.example.ru` | *(leer)* | Stammverzeichnis dieser Domain |
| `www.example.ru` | `/ru` | diese Domain plus `/ru` |
| beliebig | `/` | Stammverzeichnis der jeweils wirksamen Domain |

Ein leeres Feld und ein ausdrückliches `/` sind unterschiedliche Zustände. Beim Speichern werden mehrdeutige Zuordnungen abgelehnt – etwa zwei veröffentlichte Sprachen mit gleichem Hostnamen und gleichem Einstiegspfad, Zuordnungen, die erst nach Normalisierung gleich sind, Unterscheidungen allein über das Protokoll oder ein Hostname, der bereits zu einer anderen Website-Wurzel gehört.

Details und Beispiele: [Benutzerhandbuch](docs/USER-GUIDE.de.md).

## Übersetzung von Inhalten

**Verbundener Modus** – Struktur, Typ und Reihenfolge bleiben bei der Ausgangssprache; übersetzt werden freigegebene Felder. Inhaltselemente werden dabei im gewohnten Formular der Ausgangssprache bearbeitet; nur die Werte gehören zur gewählten Sprache.

**Freier Modus** – die Zielsprache besitzt eigene Artikel und Inhaltselemente. Quellinhalte werden nicht automatisch ausgegeben.

Ein Moduswechsel löscht keine Inhalte.

## Seitenverfügbarkeit

| Modus | Verhalten |
| --- | --- |
| **Rückfall auf die Standardsprache** | Seiten und Inhalte ohne Übersetzung geben den Quellinhalt unter der Sprach-URL aus |
| **Strikt** | Seiten ohne verfügbare Übersetzung sind nicht erreichbar; nicht übersetzte Inhalte geben keinen Quelltext aus |

Die Einstellung gilt je Zielsprache und bestimmt auch das Verhalten nicht übersetzter Inhaltsfelder.

## Berechtigungen

Der Zugriff folgt den nativen Contao-Mechanismen: Administratoren haben immer Zugriff; andere Backend-Benutzer benötigen das Modul Seitenstruktur, die passende Seitenfreigabe sowie die normalen Tabellen- und Feldrechte. Eine eigene paketbezogene Lizenzberechtigung gibt es nicht.

Alle schreibenden Vorgänge werden serverseitig geprüft. Eine im Formular ausgeblendete Schaltfläche gilt nicht als Berechtigung.

## Lizenzierung

Das Paket benötigt eine gültige Lizenz je Website-Startpunkt. Maßgeblich ist die exakt konfigurierte Domain des Startpunkts.

| Funktion | Ohne Lizenz | Free | Pro |
| --- | --- | --- | --- |
| Zusätzliche Sprachen anlegen und bearbeiten | Nicht verfügbar | Verfügbar | Verfügbar |
| Übersetzungen bearbeiten | Nicht verfügbar | Verfügbar | Verfügbar |
| Redaktioneller Prüfstatus | Nicht verfügbar | Verfügbar | Verfügbar |
| Freier Inhaltsmodus | Nicht verfügbar | Nicht verfügbar | Nur Pro |
| Integritätsreparatur | Nicht verfügbar | Nicht verfügbar | Nur Pro |
| Frontend-Ausgabe bestehender Übersetzungen | Verfügbar | Verfügbar | Verfügbar |

Bedienung, Zustände und Fehlerbehandlung: [Lizenzverwaltung](docs/PRODUCT-REGISTRATION.de.md).

## Sicherheitsmodell

- Berechtigungen werden serverseitig durchgesetzt, nicht über die Sichtbarkeit von Bedienelementen.
- Schreibende Backend-Aktionen laufen über POST mit Contao-Anfrage-Token.
- Sprach-, Wurzel- und Datensatzbezüge werden gegen die gespeicherte Konfiguration geprüft; manipulierte Werte werden abgelehnt.
- Website-Startpunkte sind gegeneinander isoliert; sprachspezifische Domains werden exakt verglichen, ohne Platzhalter und ohne Übernahme übergeordneter Domains.
- Lizenzdaten liegen außerhalb des öffentlichen Verzeichnisses.
- Definierte Lizenzvorgänge kontaktieren einen vertrauenswürdigen HTTPS-Dienst; ausgetauschte Daten werden authentifiziert und auf Unversehrtheit geprüft.
- Schlägt eine Prüfung fehl, bleiben eingeschränkte Funktionen deaktiviert.
- Lizenzschlüssel und vollständige Authentifizierungsdaten erscheinen weder in der Browser-Ausgabe noch in regulären Protokollen.

Interne Prüf-, Kommunikations- und Speichermechanismen werden bewusst nicht öffentlich dokumentiert.

## Externe Kommunikation

Das Paket kontaktiert externe Dienste ausschließlich bei ausdrücklich ausgelösten Lizenzvorgängen sowie beim serverseitig authentifizierten Aktualisierungsendpunkt. Das Öffnen der Seiteneinstellungen löst keine externe Prüfung aus. Frontend-Auslieferung und redaktionelle Arbeit finden ohne externe Aufrufe statt.

## Protokollierung

Das Paket schreibt in eigene Monolog-Kanäle. Lizenzvorgänge protokollieren Ergebniskategorien und Referenzen, keine Schlüssel und keine vollständigen Antwortinhalte.

## Konsolenbefehle

```bash
vendor/bin/contao-console contao-multilingual-pagetree:integrity:scan
vendor/bin/contao-console contao-multilingual-pagetree:integrity:repair
vendor/bin/contao-console contao-multilingual-pagetree:data-report
vendor/bin/contao-console contao-multilingual-pagetree:registration
```

Die Integritätsprüfung verändert keine Daten. Prüfen Sie die Vorschau, bevor Sie Reparaturen bestätigen; mehrdeutige Beziehungen werden nicht automatisch aufgelöst.

## Frontend-Integration

Das Frontend-Modul **Sprachwechsler** (`language_switcher`, Kategorie *Verschiedenes*) bietet horizontale oder vertikale Flaggen, Beschriftungen oder beides. Die vorhandenen Optionen für nicht verfügbare Sprachen, das Ausblenden der aktiven Sprache und eigene Modultemplates bleiben erhalten. Die Seitenverfügbarkeit wird je zusätzlicher Sprache des Startpunkts festgelegt: Nicht übersetzte Seiten werden entweder ausgeblendet und liefern einen echten 404 oder zeigen die Standardseite bei weiterhin aktiver Zielsprache. Der Inhaltsrückfall wird getrennt festgelegt: Fehlende verbundene Inhaltsübersetzungen werden ausgelassen oder ohne Kopie aus der Quelle dargestellt. Inhaltselemente zeigen keine redaktionellen Prüfaktionen mehr; die Seitenprüfung bleibt erhalten.

Kanonische Adressen, `hreflang` und `x-default` werden automatisch ausgegeben und verwenden jeweils Protokoll, Hostnamen und Einstiegspfad der Zielsprache.

## Deployment

```bash
composer install --no-dev --optimize-autoloader
vendor/bin/contao-console contao:migrate
vendor/bin/contao-console cache:clear --env=prod
vendor/bin/contao-console cache:warmup --env=prod
```

Nach Änderungen an Sprach-URLs ist ein Cache-Neuaufbau erforderlich, da Zuordnungen und Pfadpräfixe zwischengespeichert werden.

## Cache leeren

```bash
vendor/bin/contao-console cache:clear
vendor/bin/contao-console cache:clear --env=prod
```

Im Contao Manager entspricht dies der Aktion zum Leeren des Anwendungscaches.

## Tests

```bash
composer test
composer test:unit
composer test:integration
composer lint
composer security
```

## Fehlerbehebung

| Symptom | Prüfung |
| --- | --- |
| Zusätzliche Sprachen nicht anlegbar | Lizenzstatus des Startpunkts und konfigurierte Domain prüfen |
| Sprach-URL greift nicht | Cache neu aufbauen; Domain- und Einstiegspfad-Felder prüfen |
| Sprache über eigene Domain nicht erreichbar | exakten Hostnamen prüfen; `www`-Varianten und übergeordnete Domains gelten nicht |
| Speichern einer Sprach-URL abgelehnt | Meldung lesen: Hostname und Einstiegspfad sind bereits vergeben oder mehrdeutig |
| Übersetzungen erscheinen nicht im Frontend | Veröffentlichung der Sprache und Seitenverfügbarkeit prüfen |
| Unerwartete Datenlage | `integrity:scan` ausführen und Vorschau prüfen |

Weitere Schritte: [Betriebshandbuch](docs/RUNBOOK.de.md) und [Serverdiagnose](docs/SERVER-SETUP-DIAGNOSTICS.md).

## Bekannte Einschränkungen

- Für Inhaltselemente wird eine Übersetzung nur in Feldern gespeichert, für die eine Spalte in der Übersetzungsablage besteht. Felder aus Fremd-Erweiterungen werden im gewohnten Formular angezeigt, sind aber erst nach Registrierung über den vorgesehenen Erweiterungspunkt übersetzbar.
- Wird eine Sprache nachträglich auf eine eigene Domain umgestellt, verlieren zuvor gültige Adressen mit Sprachcode ihre Route. Für dauerhafte Weiterleitungen sind eine Contao-Weiterleitungsseite oder eine Webserver-Regel vorgesehen.
- Die Integritätsreparatur löst mehrdeutige Beziehungen nicht selbstständig auf.
- Das Paket setzt Contao 5 voraus; Contao 4 wird nicht unterstützt.

## Dokumentation

| Dokument | Deutsch | Englisch |
| --- | --- | --- |
| Benutzerhandbuch | [USER-GUIDE.de.md](docs/USER-GUIDE.de.md) | [USER-GUIDE.en.md](docs/USER-GUIDE.en.md) |
| Lizenzverwaltung | [PRODUCT-REGISTRATION.de.md](docs/PRODUCT-REGISTRATION.de.md) | [PRODUCT-REGISTRATION.en.md](docs/PRODUCT-REGISTRATION.en.md) |
| Betriebshandbuch | [RUNBOOK.de.md](docs/RUNBOOK.de.md) | [RUNBOOK.en.md](docs/RUNBOOK.en.md) |
| Erweiterungspunkte | – | [EXTENSION-POINTS.md](docs/EXTENSION-POINTS.md) |
| Serverdiagnose | – | [SERVER-SETUP-DIAGNOSTICS.md](docs/SERVER-SETUP-DIAGNOSTICS.md) |
| Manuelle Prüfung | – | [MANUAL-VERIFICATION.md](docs/MANUAL-VERIFICATION.md) |
| Änderungen | [CHANGELOG.md](CHANGELOG.md) | – |
| Aktualisierung | [UPGRADE.md](UPGRADE.md) | – |

## Deinstallation

Sichern Sie zuvor Datenbank und Dateien. Das Entfernen des Composer-Pakets löscht keine gespeicherten Übersetzungsdaten. Prüfen Sie den Datenbestand vorher mit `integrity:scan` oder `data-report`.

## Lizenz und Urheberrecht

Proprietär. Copyright: V&T Innovations Team, [www.v-t.one](https://www.v-t.one).

Support: [Issue-Tracker](https://github.com/vtinnovations/contao-multilingual-pagetree/issues).
