# Lizenzverwaltung

*English version: [PRODUCT-REGISTRATION.en.md](PRODUCT-REGISTRATION.en.md)*

Diese öffentliche Anleitung beschreibt ausschließlich Bedienung und erwartetes Verhalten. Interne Prüf-, Kommunikations-, Speicher- und Auslieferungsmechanismen werden hier bewusst nicht dokumentiert.

## Lizenz erhalten und aktivieren

Contao Multilingual Pagetree benötigt eine gültige Lizenz von [www.v-t.one](https://www.v-t.one).

1. Stellen Sie sicher, dass am Contao-Website-Startpunkt die korrekte primäre Domain eingetragen ist.
2. Öffnen Sie **Seitenstruktur → Website-Startpunkt bearbeiten**.
3. Geben Sie den Lizenzschlüssel im Lizenzbereich ein.
4. Wählen Sie **Lizenz aktivieren**.
5. Nach erfolgreicher Aktivierung werden Status, Lizenzdomain, Laufzeit und Aktivierungszustand aktualisiert angezeigt.

Die Lizenz gilt für genau den angezeigten Website-Startpunkt und dessen konfigurierte Domain. Eine abweichende Domain benötigt eine dafür ausgestellte Lizenz.

## Mehrere Domains einer Lizenz

Eine Lizenz kann für mehrere Domains ausgestellt sein. Maßgeblich ist immer die exakte Domain: `example.com`, `www.example.com` und `shop.example.com` sind drei verschiedene Domains. Eine Lizenz für die eine gilt niemals automatisch für die andere.

Jeder Website-Startpunkt wird einzeln aktiviert – mit derselben Lizenz, sofern seine konfigurierte Domain zu den ausgestellten Domains gehört. Der gespeicherte Status bleibt dabei je Startpunkt getrennt.

Stammt der gespeicherte Status noch aus einer älteren Version dieses Produkts, meldet die Oberfläche **Aktualisierung erforderlich**. Führen Sie in diesem Fall einmalig **Lizenz aktualisieren** aus; der bisher gespeicherte Status bleibt bis dahin unverändert erhalten.

## Aktionen

- **Lizenz aktivieren:** erstmalige Aktivierung dieses Website-Startpunkts.
- **Lizenz ersetzen:** einen vorhandenen Schlüssel durch einen anderen ersetzen.
- **Lizenz aktualisieren:** den Lizenzstatus bewusst neu abrufen.
- **Lizenz prüfen:** den bereits gespeicherten Lizenzstatus lokal erneut prüfen, ohne ihn neu abzurufen.
- **Lizenz entfernen:** gespeicherten Lizenzstatus dieses Startpunkts entfernen. Inhalte und Übersetzungen bleiben bestehen.

Das bloße Öffnen der Seiteneinstellungen startet keine externe Prüfung.

## Fehlerbehandlung

Die Oberfläche unterscheidet unter anderem fehlende oder ungültige Schlüssel, abweichende Domain beziehungsweise Produktzuordnung, noch nicht gültige oder abgelaufene Lizenzen, nicht unterstützte Antworten, Verbindungsprobleme und lokale Speicherfehler.

Notieren Sie bei Bedarf die angezeigte **Referenz**. Geben Sie niemals den Lizenzschlüssel, Antwortinhalte oder Zugangsdaten in Support-Tickets oder Protokollen weiter.

Eine vorübergehende Nichterreichbarkeit ändert einen zuvor gültigen lokalen Status nicht automatisch.

## Berechtigungsübersicht

| Funktion | Ohne Lizenz | Free | Pro |
| --- | --- | --- | --- |
| Zusätzliche Sprachen anlegen und bearbeiten | Nicht verfügbar | Verfügbar | Verfügbar |
| Übersetzungen bearbeiten | Nicht verfügbar | Verfügbar | Verfügbar |
| Redaktioneller Prüfstatus | Nicht verfügbar | Verfügbar | Verfügbar |
| Freier Inhaltsmodus | Nicht verfügbar | Nicht verfügbar | Nur Pro |
| Integritätsreparatur | Nicht verfügbar | Nicht verfügbar | Nur Pro |
| Frontend-Ausgabe bestehender Übersetzungen | Verfügbar | Verfügbar | Verfügbar |

Die Tabelle beschreibt ausschließlich den für Administratoren sichtbaren Zugriff.

Zurück zur [Projektübersicht](../README.md).
