# Milestone 1 – Technischer Application Core

Status: lokal abgeschlossen
Branch: `milestone/1-application-core`

## Lieferumfang

- Symfony-7.4-LTS-Projekt mit PHP 8.2+, Twig und Doctrine
- ausschließlich `public/` als DocumentRoot; Root-Zugriff ist gesperrt
- nicht versionierte Environment-Konfiguration über `.env.local`
- rotierende Logs, Fehlerreferenzen und Redaction sensibler Log-Kontexte
- Doctrine-Migrationssystem und erste Kernmigration
- versionierte, zeitlich gültige Plattform-Grundwerte
- deutsche Übersetzungsgrundlage und Plattformzeitzone `Europe/Zurich`
- responsives, touch-taugliches Layout mit lokalem Bootstrap und Light/Dark-Modus
- private Verzeichnisse für Uploads und Exporte
- Web-Installer mit Systemcheck, Datenbanktest, Migrationen, Grundwerten,
  optionaler Mailer-Konfiguration und erstem Plattformadmin
- dauerhafte Installationssperre mit zusätzlichem serverseitigem Schalter
- Composer-, PHPUnit-, PHPStan- und Symfony-Lint-Konfiguration

Der bisherige Prototypcode und seine vollständigen Schemaimporte wurden entfernt.
Die fachlichen Erkenntnisse bleiben in Spezifikation und Architektur dokumentiert.

## Installations-Sicherheitsmodell

Der Installer ist nur verfügbar, wenn `APP_INSTALLER_ENABLED=1` gesetzt ist und
`storage/installed.lock` nicht existiert. Die Datenbank muss bereits angelegt sein;
der eingegebene Benutzer benötigt nur Rechte auf dieser Datenbank.

Bei erfolgreicher Installation werden:

1. die Datenbankverbindung geprüft,
2. `.env.local` mit Modus `0600` geschrieben,
3. alle versionierten Migrationen ausgeführt,
4. der erste Plattformadmin und 14 Grundwerte transaktional angelegt,
5. der Installer in `.env.local` deaktiviert,
6. `storage/installed.lock` mit Modus `0600` erzeugt.

MariaDB führt DDL mit impliziten Commits aus. Migrationen werden deshalb nicht in
eine vorgetäuschte globale Transaktion eingeschlossen; fachliche Seed-Daten werden
danach in einer echten separaten Transaktion geschrieben.

## Prüfergebnisse

Erfolgreich ausgeführt:

```text
composer validate --strict
PHP-Syntaxprüfung aller neuen PHP-Dateien
Symfony Container-Lint (dev und prod)
Symfony YAML-Lint
Twig-Lint
Doctrine Mapping-Validierung
PHPStan Level 7: 0 Fehler
PHPUnit: 7 Tests, 13 Assertions
HTTP-Smoke-Test: / -> 302 /install; /install -> 200
```

Zusätzlich wurde der vollständige Installer gegen eine isolierte MariaDB-11.8-
Instanz geprüft. Ergebnis:

```text
1 Migration protokolliert
1 Plattformadmin angelegt
14 Plattform-Grundwerte angelegt
Dashboard nach Installation: HTTP 200
Installer nach Installation: HTTP 404
```

Die temporäre Datenbank, Testkonfiguration und Sperrdatei wurden nach dem Test
entfernt.

## Sicherheitsbefund aus dem Altbestand

Im bisherigen Repository waren reale Datenbankzugangsdaten in versionierten
PHP-Konfigurationsdateien enthalten. Diese Dateien sind im Milestone entfernt.
Da Git-Historie bereits veröffentlichte Werte bewahrt, müssen die betroffenen
Datenbankpasswörter beim Hoster rotiert werden; das Entfernen im neuen Commit allein
macht bestehende Zugangsdaten nicht wieder geheim.

## Übergang zu Milestone 2

Milestone 2 ergänzt Vereinsregistrierung, Mandanten-Slug, E-Mail-Bestätigung,
Login/Reset, 2FA, Sessionsperren, Rollen, Veranstaltungszuweisungen und Ownerwechsel.
Mit dem Mandantenmodell wird zugleich die verpflichtende Isolationstest-Matrix
eingeführt.
