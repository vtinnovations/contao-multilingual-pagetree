# Server diagnostics / Server-Diagnose

Run these checks only on the deployed Contao server. They are not local development requirements. Do not publish command output containing domains, usernames, paths, licence keys, or other installation data.

Führen Sie diese Prüfungen ausschließlich auf dem eingesetzten Contao-Server aus. Sie sind keine Anforderungen an die lokale Entwicklung. Veröffentlichen Sie keine Ausgaben mit Domains, Benutzernamen, Pfaden, Lizenzschlüsseln oder anderen Installationsdaten.

## Deutsch

### Contao-Setup

```bash
php vendor/bin/contao-setup -vvv
php vendor/bin/contao-console debug:container --env=prod
php vendor/bin/contao-console debug:router --env=prod
php vendor/bin/contao-console contao:migrate --dry-run -vvv
```

Suchen Sie bei einem Fehler nach der ersten echten Exception. Vorher ausgegebene Hinweise anderer Erweiterungen müssen nicht die Ursache sein.

### Lizenzprüfung nicht möglich

Prüfen Sie, ob die für das Bundle erforderlichen PHP-Erweiterungen im verwendeten Plesk-PHP aktiviert sind. Prüfen Sie außerdem DNS, ausgehende HTTPS-Verbindungen, Systemzeit, CA-Zertifikate und Schreibrechte des Contao-`var/`-Verzeichnisses. Deaktivieren Sie niemals die TLS-Zertifikatsprüfung. Notieren Sie die im Backend angezeigte Referenz und gleichen Sie sie mit dem dedizierten Bundle-Logkanal ab. Protokollieren Sie keine Lizenzschlüssel oder Antwortinhalte.

### Funktionsprüfung nach Installation oder Update

1. Contao-Setup und Migration vollständig ausführen.
2. Produktionscache leeren.
3. Website-Startpunkt bearbeiten und Domain sowie Lizenzstatus prüfen.
4. Sprachen über die Globus-Aktion konfigurieren.
5. Sprachregister an Seiten und unterstützten Inhaltsdatensätzen prüfen.
6. Sprachwechsler, kanonische URLs und Metadaten im Frontend prüfen.
7. Integritätsscan für den betroffenen Startpunkt ausführen.
8. Mit einem eingeschränkten Redakteur und einem zweiten Website-Startpunkt die Rechte- und Root-Trennung prüfen.

## English

### Contao setup

```bash
php vendor/bin/contao-setup -vvv
php vendor/bin/contao-console debug:container --env=prod
php vendor/bin/contao-console debug:router --env=prod
php vendor/bin/contao-console contao:migrate --dry-run -vvv
```

When a command fails, identify the first real exception. Earlier notices from unrelated extensions are not necessarily the cause.

### Licence verification cannot run

Check that the PHP extensions required by the bundle are enabled for the selected Plesk PHP runtime. Also check DNS, outbound HTTPS access, system time, CA certificates, and write access to Contao’s `var/` directory. Never disable TLS certificate validation. Retain the reference displayed in the backend and correlate it with the dedicated bundle log channel. Do not log licence keys or response contents.

### Functional checks after installation or upgrade

1. Complete Contao setup and migrations.
2. Clear the production cache.
3. Edit a website root and inspect its domain and licence status.
4. Configure languages through the globe action.
5. Check language tabs on pages and supported content records.
6. Verify the language switcher, canonical URLs, and metadata in the frontend.
7. Run an integrity scan for the affected root.
8. Use a restricted editor and a second website root to verify permissions and root isolation.

### Root licence workflow verification

1. Upload or install the new latest ZIP.
2. Run Contao setup.
3. Clear the production cache.
4. Log in as an administrator.
5. Edit an unlicensed website root.
6. Confirm Language settings contains only the licence-required notice and no licence controls.
7. Open the separate **Contao Multilingual Pagetree licence** section of the same form.
8. Confirm the complete licence-key interface is visible there and nowhere else.
9. Confirm this section is immediately before Access rights.
10. Activate the valid licence.
11. Reload Page Settings.
12. Confirm licence status persists.
13. Confirm Language settings now shows exactly one **Manage additional languages** action.
14. Confirm the licence-required notice has disappeared.
15. Test the same workflow as an authorised non-administrator.
16. Test as an unauthorised non-administrator and confirm the licence section is absent.
17. Attempt direct licence POST requests without permission and confirm they are denied.
