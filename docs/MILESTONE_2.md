# Milestone 2 – Auth, Mandanten und Rollen

Status: lokal abgeschlossen

Branch: `milestone/2-auth-tenancy`

## Lieferumfang

- dreistufige Vereinsregistrierung mit global eindeutigem Namen und Slug
- vorgeschlagener, vor der Bestätigung änderbarer Slug
- gehashte Einmaltokens, E-Mail-Bestätigung und exakt 14 × 24 Stunden Trial
- Erinnerungs-/Löschkommando für unbestätigte Registrierungen nach fünf/sieben Tagen
- mandantenspezifischer Login unter `/v/{slug}/login`
- 12-Zeichen-Passwortregeln, 60-Minuten-Reset und 7-Tage-Einladungen
- TOTP-2FA mit verschlüsselt gespeichertem Secret; obligatorisch für Owner/Admin
- Inaktivitätsgrenzen von zwei beziehungsweise acht Stunden und Remember-me bis 30 Tage
- Login-Drosselung, progressive Kontosperre und sichere Owner-Entsperrung per E-Mail
- Benutzer einladen, deaktivieren, reaktivieren, entsperren und anonymisiert löschen
- Schutz des letzten Owners/Administrators
- bestätigungspflichtiger Ownerwechsel mit erneuter Passwortprüfung
- veranstaltungsspezifische Rollen und genau eine primäre Event-Leitung
- Benachrichtigung bei Wechsel der primären Event-Leitung
- Audit-Einträge für sicherheitsrelevante Vorgänge

## Mandantensicherheit

Die Mandanten-ID wird ausschließlich aus dem URL-Slug und der authentifizierten
Zuordnung abgeleitet. Vier Schichten verhindern Querverbindungen:

1. unveränderlicher `TenantContext` pro Request,
2. explizite Tenant-Parameter in Repositories und Use-Cases,
3. automatischer Doctrine-SQL-Filter für tenantgebundene Entitäten,
4. zusammengesetzte MariaDB-Fremdschlüssel `(tenant_id, id)`.

Auch die Remember-me-Identität enthält die öffentliche Tenant-UUID. Dadurch kann
ein Cookie bei identischer E-Mail-Adresse nicht in einen anderen Verein übernommen
werden.

## Betriebsaufgaben

Der Hoster ruft mindestens täglich auf:

```bash
php bin/console app:registrations:maintain
```

Das Kommando verschickt zwei Tage vor Ablauf eine Erinnerung und löscht nach sieben
Tagen weiterhin unbestätigte Registrierungen. Erneut versendete Links verlängern die
ursprüngliche Frist nicht.

## Prüfergebnisse

Erfolgreich ausgeführt:

```text
Composer-Validierung
PHP-Syntaxprüfung
Symfony Container-/YAML-/Twig-Lint
Doctrine Mapping-Validierung
PHPStan Level 7: 0 Fehler
PHPUnit ohne Datenbank: 15 aktive Tests, 33 Assertions
frische Migration gegen isolierte MariaDB: 2 Migrationen
MariaDB-Isolationssuite: 4 Tests, 26 Assertions (ORM-Filter, Registrierung,
Einmaltoken, Cross-Tenant-FK und Eventrollen)
```

Die Datenbanktests werden mit `RUN_DATABASE_TESTS=1` und einer frisch migrierten,
entbehrlichen Testdatenbank aktiviert. Ohne diese Freigabe werden sie bewusst als
übersprungen markiert; die CI muss sie aktivieren und behandelt ein Überspringen als
Fehler.
