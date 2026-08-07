# Betriebshandbuch

*English version: [RUNBOOK.en.md](RUNBOOK.en.md)*

Kompakte Betriebsanweisungen für Contao Multilingual Pagetree im Produktivbetrieb.
Die Befehle gelten für eine Contao Managed Edition; bei abweichender Installation
ist der Konsolenpfad anzupassen.

## Vor der Installation

- Prüfen, ob die Zielumgebung der unterstützten Matrix entspricht ([README.md](../README.md) → Voraussetzungen).
- Datenbank **und** Verzeichnis `files/` sichern.
- Die bestehende Mehrsprachigkeit sichten und je Website-Root festhalten, welche
  Sprachen ausgeliefert werden sollen.
- Je Website-Root festlegen, welche Sprache die Standardsprache (Fallback) ist.
- Den Lizenzstatus jedes Website-Roots prüfen, dessen Sprachen bearbeitet werden sollen.
- Zuerst auf einer Staging-Umgebung mit einer Kopie der Produktivdaten installieren
  und prüfen.

## Nach der Installation

```bash
vendor/bin/contao-console cache:clear
vendor/bin/contao-console contao:migrate
```

1. Sprachen je Website-Root konfigurieren (Seitenstruktur → Globus-Aktion am Root).
   Genau eine Sprache je Root muss die Fallback-Sprache sein.
2. Je Nicht-Standardsprache den Modus der Seitenverfügbarkeit wählen (strikt oder Fallback).
3. Je Nicht-Standardsprache den Inhaltsmodus wählen (verbunden oder frei).
4. Je Sprache die Sprach-URL konfigurieren: Protokoll, Domain und Einstiegspunkt.
   Jeden zusätzlichen Hostnamen in DNS und Webserver-Konfiguration auf die
   Installation zeigen lassen und ein Zertifikat dafür ausstellen.
5. Das Frontend-Modul Sprachumschalter in das Layout aufnehmen, sofern Besucher es
   benötigen.
6. Die kanonischen Routen jeder Sprache gegen ihre konfigurierte Zuordnung prüfen –
   eine Sprache mit eigener Domain und leerem Einstiegspunkt wird ab der Wurzel
   dieser Domain ausgeliefert und darf ihren Sprachcode nicht enthalten.
7. Sprachumschalter, Canonical-Tag, `hreflang` und `x-default` auf einer Seite und
   auf einer Detailseite (News/Event/FAQ) prüfen.
8. Cache aufwärmen und prüfen, ob ein zweiter Aufruf dieselbe Sprache ausliefert.
9. Einen rein lesenden Integritätsscan ausführen und die Protokolle sichten.

```bash
vendor/bin/contao-console contao-multilingual-pagetree:integrity:scan --root=<rootPageId>
```

## Vor einem Upgrade

- Datenbank und Dateien sichern.
- `UPGRADE.md` und `CHANGELOG.md` lesen.
- Den Integritätsscan ausführen und Befunde der Stufen `critical` und `error` beheben.
- Das Upgrade auf einer CI-unterstützten Umgebung im Staging nachstellen.

## Nach einem Upgrade

```bash
vendor/bin/contao-console contao:migrate
vendor/bin/contao-console contao:migrate     # der zweite Durchlauf muss ohne Änderungen enden
vendor/bin/contao-console cache:clear
vendor/bin/contao-console contao-multilingual-pagetree:integrity:scan --root=<rootPageId>
```

Anschließend Routen, Frontend-Metadaten, verbundene und freie Inhalte sowie die
Übersetzungsformulare im Backend prüfen. Nur die tatsächlich benötigten Caches
leeren; ein vollständiger Produktiv-Cache-Neuaufbau ist nur erforderlich, wenn sich
die Routen- oder die Sprachkonfiguration eines Website-Roots geändert hat.

## Regelmäßige Aufgaben

| Aufgabe | Befehl |
| --- | --- |
| Rein lesender Integritätsscan einer Website | `integrity:scan --root=<id>` |
| Eine Sprache prüfen | `integrity:scan --root=<id> --language=de` |
| Maschinenlesbarer Bericht | `integrity:scan --root=<id> --format=json` |
| Reparatur als Probelauf | `integrity:repair --root=<id>` |
| Nicht destruktive Reparaturen anwenden | `integrity:repair --root=<id> --execute` |
| Destruktive Reparaturen anwenden | `integrity:repair --root=<id> --execute --force` |
| Verbliebene Paketdaten ausweisen | `data-report` |
| Lizenzstatus anzeigen | `registration` |

Rückgabewerte: `0` unauffällig, `1` Warnungen oder reparierbare Befunde, `2` Fehler
oder kritische Befunde, `3` Scan- oder Ausführungsfehler. Für die Überwachung
auswerten.

## Störungsbehebung

### Eine übersetzte Seite liefert 404

Erwartetes Verhalten, wenn die Sprache den Modus **strikt** verwendet und keine
veröffentlichte Seitenübersetzung vorliegt. Zuerst den Verfügbarkeitsmodus der
Sprache prüfen, dann den Veröffentlichungsstatus der Übersetzung und ihren
Zeitraum `start`/`stop`. Auf den Fallback-Modus umstellen, wenn stattdessen der
Quellinhalt ausgeliefert werden soll. Der Integritätsscan meldet verwaiste
Übersetzungen und Übersetzungen ohne Alias.

### Die eigene Domain einer Sprache liefert an ihrer Wurzel 404

Prüfen, ob der auf dem Sprachdatensatz gespeicherte Hostname exakt dem
angeforderten Hostnamen entspricht. Der Vergleich ist exakt: eine `www`-Variante,
eine übergeordnete Domain und eine Nachbar-Subdomain sind jeweils andere
Hostnamen und werden bewusst nicht zugeordnet. Anschließend prüfen, ob die Sprache
veröffentlicht ist, ob der Hostname auf diese Installation zeigt und ob der Cache
seit der Änderung neu aufgebaut wurde.

### Eine doppelte oder unerwartete Route erscheint

Den Integritätsscan ausführen und auf `duplicate_alias` und `duplicate_translation`
achten. Eine frühere Fallback-URL leitet dauerhaft weiter, sobald ein übersetzter
Alias existiert; diese Weiterleitung ist beabsichtigt. Nach dem Auflösen von
Alias-Konflikten den Routen- bzw. Seiten-Cache leeren.

### Eine bisher funktionierende Sprach-URL funktioniert nicht mehr

Der Umzug einer Sprache auf eine eigene Domain oder die Änderung ihres
Einstiegspunkts setzt die bisherige Adresse außer Kraft. Bestehende Links werden
nicht umgeschrieben. Für die bisherige Adresse eine Contao-Weiterleitungsseite
oder eine Webserver-Regel einrichten.

### Inhalte erscheinen in der falschen Sprache

Den Inhaltsmodus der Sprache prüfen. Im freien Modus werden ausschließlich die
freien Datensätze dieser Sprache ausgegeben, und die Quellstruktur dient nie als
Fallback. Im verbundenen Modus werden ausschließlich Quelldatensätze ausgegeben.
In der Scan-Ausgabe auf `cross_language_relation` und `cross_site_relation` achten –
diese werden in Quarantäne gestellt, nicht gelöscht.

### Ein Detaildatensatz ist in einer Sprache nicht verfügbar

Detaildatensätze benötigen stets eine eigene veröffentlichte Übersetzung, auch wenn
die Leseseite über den Seiten-Fallback verfügbar ist. Das ist beabsichtigt.
Veröffentlichungsstatus und Alias der News-, Event- bzw. FAQ-Übersetzung prüfen.

### `hreflang` wirkt veraltet

Die Metadaten werden je Aufruf aus der ermittelten Verfügbarkeit erzeugt; eine
veraltete Ausgabe deutet daher fast immer auf eine gecachte Seite hin. Den Cache
der betroffenen Seite bzw. des Roots invalidieren. Prüfen, ob die Übersetzung
veröffentlicht ist und im Veröffentlichungszeitraum liegt.

### Eine Migration ist fehlgeschlagen

Migrationen sind wiederholbar: die gemeldete Ursache beheben und `contao:migrate`
erneut ausführen. Keine Migration löscht mehrdeutige Daten; Duplikate werden
stattdessen vom Integritätsscanner gemeldet. Bleibt der Fehler bestehen, die
Sicherung zurückspielen und einen Fehlerbericht mit der Konsolenausgabe eröffnen –
sie enthält weder Zugangsdaten noch Inhalte.

### Eine Integritätsreparatur ist fehlgeschlagen

Die Ausführung wird innerhalb einer Transaktion zurückgerollt und als
`rolled_back` gemeldet; eine teilweise Ausführung wird genau so ausgewiesen und nie
als Erfolg. Den Scan erneut ausführen: ein Plan, der vor der Datenänderung erstellt
wurde, wird bewusst als veraltet abgelehnt. Danach erneut Vorschau ansehen und
bestätigen.

### Eine Warnung zu website-übergreifenden Beziehungen erscheint

Ein Datensatz verweist auf einen Datensatz eines anderen Website-Roots. Das System
stellt ihn in Quarantäne (er wird nicht mehr ausgegeben, seine Daten bleiben
erhalten) und stellt die Beziehung nie durch Raten wieder her. Die richtige
Zuordnung muss redaktionell entschieden werden.

### Falsch gewählter Inhaltsmodus

Ein Moduswechsel löscht keine Daten. Die Datensätze des jeweils anderen Modus
bleiben gespeichert und inaktiv; ein Zurückschalten stellt die Ausgabe wieder her.
Der Bestätigungsdialog nennt die Anzahl der inaktiv werdenden Datensätze.

### Probleme mit dem Routen-Cache

Den Contao-/Symfony-Cache leeren. Die Routenerzeugung liest die Sprachkonfiguration
je Website-Root und führt nie einen Integritätsscan aus; ein dauerhaftes Problem
weist daher auf einen Datenzustand hin – dann den Scan ausführen.

### Lizenzierte Funktionen sind nicht verfügbar

Siehe [Lizenzverwaltung](PRODUCT-REGISTRATION.de.md). Den Lizenzstatus des
betroffenen Website-Roots und dessen konfigurierte Domain prüfen. Schlägt eine
Prüfung fehl, bleiben die eingeschränkten Funktionen deaktiviert; bestehende
Übersetzungen werden weiterhin ausgeliefert.

## Überwachung

Protokollkanäle: `contao_multilingual_pagetree` und
`contao_multilingual_pagetree_integrity`. Auf `error` und `critical` alarmieren.
Normales Fallback-Verhalten und fehlende optionale Übersetzungen werden nicht als
Fehler protokolliert. Die Protokolle enthalten Codes, Tabellennamen,
Datensatz-IDs, Root-IDs und Sprachcodes – nie übersetzte Inhalte, Lizenzschlüssel,
Token oder Zugangsdaten.
