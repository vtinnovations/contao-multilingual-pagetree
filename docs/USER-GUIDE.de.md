# Benutzerhandbuch – Deutsch

*English version: [USER-GUIDE.en.md](USER-GUIDE.en.md)*

## Grundprinzip

Jeder Contao-Website-Startpunkt besitzt eine Ausgangssprache und beliebig viele konfigurierte Zielsprachen. Die Zielsprachen werden über die Globus-Aktion am Startpunkt verwaltet. Ein Startpunkt bildet dabei immer eine eigene Website-Grenze.

## Ersteinrichtung

1. Öffnen Sie **Seitenstruktur** und bearbeiten Sie den Website-Startpunkt.
2. Prüfen Sie die primäre Domain.
3. Aktivieren Sie im Lizenzbereich die für diese Domain ausgestellte Lizenz.
4. Speichern Sie den Startpunkt und öffnen Sie die Sprachverwaltung über das Globus-Symbol.
5. Legen Sie die Zielsprachen an. Genau eine Sprache ist die Ausgangs- beziehungsweise Fallback-Sprache.
6. Wählen Sie für jede Zielsprache den Verfügbarkeits- und Inhaltsmodus.

## Übersetzungen bearbeiten

Unterstützte Datensätze zeigen Sprachregister in ihrem normalen Bearbeitungsformular. Für jedes übersetzbare Feld wählen Sie:

- **Aus Ausgangssprache übernehmen:** Der aktuelle Quellwert wird verwendet.
- **Eigene Übersetzung verwenden:** Der eingegebene Sprachwert wird verwendet.
- **Bewusst leer lassen:** Das Feld bleibt in dieser Sprache leer.

Technische, strukturelle und veröffentlichungsbezogene Felder bleiben geschützt. Veröffentlichungsstatus und Veröffentlichungszeitraum können – soweit angeboten – pro Sprache gesteuert werden.

Dies gilt für Seiten, Artikel, Nachrichten, Termine und FAQ. Inhaltselemente werden anders bearbeitet, siehe **Inhaltselemente übersetzen**.

## Verbundene und freie Inhalte

Im verbundenen Modus bleiben Struktur, Typ und Reihenfolge bei der Ausgangssprache. Nur freigegebene Felder werden übersetzt. Im freien Modus besitzt die Zielsprache eigene Artikel und Inhaltselemente; Quellinhalte werden nicht automatisch ausgegeben.

Ein Moduswechsel löscht keine Inhalte. Die Oberfläche zeigt vor dem Wechsel, welche Datensätze anschließend aktiv oder inaktiv sind.

## Inhaltselemente übersetzen

Wählen Sie die Sprache über die Sprachregister oben im Formular. Das Formular einer Zielsprache ist dasselbe Formular wie in der Ausgangssprache: dieselben Abschnitte, dieselbe Feldreihenfolge, derselbe Editor und dieselben Auswahlfelder. Sie übersetzen direkt in den gewohnten Feldern.

Es gibt keine zusätzlichen Auswahlfelder pro Feld und keinen gesonderten Abschnitt für übersetzbare Inhalte. Das aktive Sprachregister zeigt bereits, welche Sprache Sie bearbeiten.

Solange für ein Feld noch keine Übersetzung gespeichert ist, zeigt das Formular den Text der Ausgangssprache an. Erst wenn Sie diesen Text ändern und speichern, wird er zur Übersetzung. Ein unverändert übernommener Text bleibt mit der Ausgangssprache verbunden und folgt späteren Änderungen dort weiterhin.

Felder, die zur Struktur des Elements gehören – etwa Elementtyp, Bildauswahl, Bildgröße oder CSS-Angaben – werden im verbundenen Modus von der Ausgangssprache bestimmt und sind deshalb nicht bearbeitbar.

Sie bearbeiten dabei dasselbe Inhaltselement wie in der Ausgangssprache – nur die Werte gehören zur gewählten Sprache. Ein Textelement bleibt deshalb in jeder Sprache ein Textelement und zeigt dasselbe Formular. Im freien Modus wählen Sie den Elementtyp wie gewohnt selbst.

## Nicht übersetzte Inhalte

Was mit noch nicht übersetzten Inhalten geschieht, bestimmt die Einstellung **Inhaltsübersetzungsmodus** der jeweiligen Sprache in den Spracheinstellungen des Website-Startpunkts:

- **Standardinhalt anzeigen, wenn keine Übersetzung vorhanden ist:** Nicht übersetzte Inhalte werden aus der Ausgangssprache ausgegeben, ohne sie zu kopieren.
- **Inhalte ohne Übersetzung nicht anzeigen:** Nicht übersetzte Inhaltselemente werden ausgelassen.

Wenn Sie ein Feld bewusst leeren und speichern, bleibt es in dieser Sprache leer – auch bei aktivem Rückfall.

## Prüfung nach Quelländerungen

Der Prüfstatus für Seiten zeigt, ob eine Übersetzung noch ungeprüft, aktuell oder nach einer Änderung der Ausgangssprache erneut zu prüfen ist. **Als geprüft markieren** speichert den aktuellen redaktionellen Stand, ändert aber weder Veröffentlichung noch Routing. Inhaltselement-Sprachtabs enthalten bewusst keine Prüfaktionen.

## Sprachwechsler und URLs

Ohne weitere Einstellungen bleibt die Standardsprache ohne Sprachpräfix, und Zielsprachen verwenden ein Präfix wie `/de/`. Die **Seitenverfügbarkeit** je zusätzlicher Sprache blendet nicht übersetzte Seiten mit echtem 404 aus oder zeigt die Standardseite bei weiterhin aktiver Zielsprache. Der vorhandene Sprachwechsler bietet Flaggen, Beschriftungen oder beides horizontal und vertikal; die Optionen für nicht verfügbare Sprachen, das Ausblenden der aktiven Sprache und eigene `mod_*`-Templates bleiben erhalten.

## Sprach-URL: Protokoll, Domain und Einstiegspfad

Jede Sprache eines Website-Startpunkts kann im Abschnitt **Sprach-URL** eine eigene Adresse erhalten. Alle drei Felder sind optional.

### Protokoll

- **Von der Website-Wurzel übernehmen** (Standard): Die Sprache verwendet das Protokoll, das am Startpunkt eingestellt ist.
- **HTTPS** oder **HTTP**: Die Sprache verwendet fest dieses Protokoll.

Das Protokoll allein unterscheidet niemals zwei Sprachen. Zwei Sprachen mit demselben Hostnamen und demselben Einstiegspfad dürfen sich nicht nur im Protokoll unterscheiden.

### Domain

Leer lassen, um die Domain der Website-Wurzel zu verwenden. Ansonsten geben Sie genau einen Hostnamen ein, zum Beispiel `www.xyz.de` oder `de.example.org`.

Der Hostname wird exakt übernommen: Groß-/Kleinschreibung und ein versehentlicher Schlusspunkt werden bereinigt, sonst nichts. `example.com` und `www.example.com` bleiben zwei verschiedene Adressen; ein `www` wird weder ergänzt noch entfernt. Protokolle, Pfade, Query-Strings, Fragmente, Ports und Platzhalter werden abgewiesen.

### Einstiegspfad

Was ein leeres Feld bedeutet, hängt davon ab, ob die Sprache eine eigene Domain hat:

- **mit eigener Domain:** Die Sprache liegt im Stammverzeichnis dieser Domain. Aus der Domain `bauland-ru.taheri.cool` wird `https://bauland-ru.taheri.cool` – der Sprachcode wird *nicht* angehängt.
- **ohne eigene Domain:** Die bisherige Adressbildung bleibt erhalten – Standardsprache ohne Präfix, jede andere Sprache unter ihrem Sprachcode.

Ein leeres Feld und ein ausdrückliches `/` sind **nicht** dasselbe.

- `/` bedeutet: Diese Sprache liegt im Stammverzeichnis ihrer Domain.
- `/de` bedeutet: Diese Sprache liegt unter diesem Pfadpräfix.

Bequeme Eingaben werden normalisiert: `de` wird zu `/de`, `/de/` wird zu `/de`. Ein Einstiegspfad greift immer auf vollständigen Pfadsegmenten: `/de` gilt für `/de`, `/de/` und `/de/ueber-uns`, aber niemals für `/demo` oder `/development`.

### Beispiele

Gleiche Domain mit Einstiegspfaden:

| Sprache | Domain | Einstiegspfad | Adresse |
| --- | --- | --- | --- |
| Englisch | *(leer)* | `/` | `https://www.xyz.com/` |
| Deutsch | *(leer)* | `/de` | `https://www.xyz.com/de` |
| Russisch | *(leer)* | `/ru` | `https://www.xyz.com/ru` |

Eigene Domain ohne Einstiegspfad:

| Sprache | Domain | Einstiegspfad | Adresse |
| --- | --- | --- | --- |
| Deutsch | *(leer)* | *(leer)* | `https://bauland.taheri.cool` |
| Englisch | *(leer)* | `/en` | `https://bauland.taheri.cool/en` |
| Russisch | `bauland-ru.taheri.cool` | *(leer)* | `https://bauland-ru.taheri.cool` |

Getrennte Domains:

| Sprache | Domain | Einstiegspfad | Adresse |
| --- | --- | --- | --- |
| Englisch | *(leer)* | `/` | `https://www.xyz.com/` |
| Deutsch | `www.xyz.de` | `/` | `https://www.xyz.de/` |
| Russisch | `www.xyz.ru` | `/` | `https://www.xyz.ru/` |

Gemischt:

| Sprache | Domain | Einstiegspfad | Adresse |
| --- | --- | --- | --- |
| Englisch | *(leer)* | `/` | `https://www.xyz.com/` |
| Deutsch | `www.xyz.de` | `/de` | `https://www.xyz.de/de` |
| Russisch | *(leer)* | `/ru` | `https://www.xyz.com/ru` |

### Was nicht erlaubt ist

Damit eine eingehende Anfrage eindeutig bleibt, wird beim Speichern abgewiesen:

- zwei veröffentlichte Sprachen mit demselben Hostnamen **und** demselben Einstiegspfad,
- zwei Zuordnungen, die sich erst nach der Normalisierung als gleich erweisen,
- zwei Sprachen, die sich nur im Protokoll unterscheiden,
- mehrere Sprachen, die `/` auf demselben Hostnamen beanspruchen,
- ein Hostname, der bereits zu einem anderen Website-Startpunkt gehört.

Zwei Sprachen dürfen `/` nur dann gleichzeitig verwenden, wenn sich ihre Hostnamen unterscheiden. Mehrdeutige Konfigurationen werden nicht aufgelöst, sondern mit einer Meldung abgelehnt.

Kanonische Adressen, `hreflang`, `x-default`, der Sprachwechsler und die Detailumschaltung für Nachrichten, Termine und FAQ verwenden immer Protokoll, Hostnamen und Einstiegspfad der Zielsprache.

## Integrität und Reparatur

Der Integritätsscan meldet unter anderem fehlende Quellen, doppelte Übersetzungen, ungültige Aliase und Beziehungen über Website-Grenzen hinweg. Der Scan verändert keine Daten. Prüfen Sie immer die Vorschau, bevor Sie Reparaturen bestätigen. Mehrdeutige Beziehungen werden nicht automatisch geraten oder zusammengeführt.

## Lizenzverwaltung

Die Lizenz wird im Bearbeitungsformular des jeweiligen Website-Startpunkts verwaltet. Die dort angezeigte Domain muss der Domain entsprechen, für die der Schlüssel ausgestellt wurde. **Aktivieren** richtet einen Startpunkt ohne Lizenz ein; **Ersetzen** tauscht den Schlüssel eines bereits lizenzierten Startpunkts; **Aktualisieren** ruft den Status erneut ab; **Prüfen** prüft den gespeicherten Status lokal erneut; **Entfernen** entfernt nur den gespeicherten Lizenzstatus und keine redaktionellen Inhalte.

Bei einer Fehlermeldung notieren Sie die angezeigte Referenz und geben Sie sie an den Administrator weiter. Lizenzschlüssel gehören nicht in Tickets, Screenshots oder Protokolle.

## Deaktivierung und Deinstallation

Sichern Sie vor Änderungen Datenbank und Dateien. Deaktivieren Sie zuerst redaktionelle Änderungen und prüfen Sie den Datenbestand mit dem Bericht beziehungsweise Integritätsscan. Das Entfernen des Composer-Pakets löscht keine gespeicherten Übersetzungsdaten automatisch.
