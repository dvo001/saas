# Milestone 11 – UI-, Security- und Deployment-Härtung

Milestone 11 schließt die Version-1-Implementierung ab.

## Umgesetzt

- mobile Formulare, Tabellen, Aktionsleisten, Navigation, Fokuszustände und
  reduzierte Animationen nachgearbeitet
- Revisions-Polling für Liveansichten mit Sichtbarkeits-/Offline-Pause,
  ETag-Prüfung und schrittweisem Backoff vereinheitlicht
- CSP-inkompatible Inline-Handler entfernt und strikte Security-Header ergänzt
- kritische E-Mail-Links auf CSRF-geschützte Bestätigungs-POSTs umgestellt
- zusätzliche Rate Limits für Recovery, Einladungen, 2FA, Registrierung und
  kritische Aktionen aktiviert
- absolute sowie inaktive Session-Laufzeiten, Session-Regeneration und sichere
  Produktionscookies durchgesetzt
- CSV-Uploads anhand Größe, UTF-8-Inhalt, MIME, Zeilenanzahl und Zeilenlänge geprüft
- Spreadsheet-Formeln in CSV-Exporten neutralisiert
- Trusted Hosts, Installer-Sperre, private Cache-Regeln und HSTS gehärtet
- SQL-Zugriffe auf vorbereitete Statements und dynamische Bezeichner auf feste
  Whitelists geprüft
- Mandantenisolation erneut durch schnelle Architekturtests und die
  datenbankgestützte Cross-Tenant-Suite abgesichert
- Produktionsvorlage, Installations-, Update- und Cyon-Shared-Hosting-Anleitung
  ergänzt

## Abnahme

```bash
composer validate --strict
composer verify
APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup
```

Für die endgültige Deployment-Freigabe muss zusätzlich die in
[INSTALLATION.md](INSTALLATION.md) beschriebene MariaDB-Isolationssuite laufen.
