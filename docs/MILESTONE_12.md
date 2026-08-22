# Milestone 12 – Version-1-Release-Abnahme

Dieser Milestone schließt die Lücke zwischen implementiertem Funktionsumfang und
einer reproduzierbar geprüften Version-1-Veröffentlichung.

## Änderungen

- Web-Installer mit expliziter, sicherer Bootstrap-Konfiguration für die echte Domain
- Trusted-Host-Prüfung während der uninstallierten Entwicklungsphase entkoppelt und
  nach Installation im Produktionsmodus strikt aktiviert
- erneute Passwort- und TOTP-Bestätigung für finanzielle Aktionen mit zehn Minuten
  Gültigkeit und Rate Limit
- zentrale zusätzliche Prüfung, dass angemeldeter Benutzer und URL-Mandant
  übereinstimmen
- HTTP-Akzeptanztests für Web-Installer, Registrierungswizard, Login und manipulierte
  Mandanten-Slugs
- MariaDB-CI mit frischen Migrationen und verpflichtender Datenbank-/HTTP-Suite
- öffentliche Version-1-Startseite, Navigation, Installation und Architektur auf den
  tatsächlichen Release-Stand gebracht

## Release-Gate

Ein Version-1-Tag wird erst erstellt, wenn der GitHub-Workflow **V1 release checks**
grün ist. Lokal ohne Testdatenbank dürfen Datenbanktests übersprungen werden; in CI
ist `RUN_DATABASE_TESTS=1` zwingend gesetzt.
