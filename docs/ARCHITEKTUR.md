# Architektur – SaaS-Plattform für Schweizer Sportvereine

Status: Milestone 2

Stand: 21. August 2026  
Zielversion: Version 1

## 1. Entscheidungsübersicht

Die bestehende Anwendung wird nicht inkrementell erweitert. Ab Milestone 1 entsteht
im bestehenden Repository eine neue, modulare Anwendung. Der bisherige Code bleibt
nur so lange als fachliche Referenz vorhanden, bis die jeweilige Funktion in der
neuen Architektur ersetzt ist.

| Bereich | Entscheidung |
| --- | --- |
| Backend | PHP 8.2+, Symfony 7.4 LTS |
| Rendering | Serverseitige Twig-Templates |
| Datenzugriff | Doctrine ORM und DBAL, MariaDB 10.6+ |
| Frontend | lokal ausgeliefertes Bootstrap 5.3, eigenes CSS, Vanilla JavaScript |
| Module | expliziter Modulvertrag, getrennte Namespaces und Migrationen |
| Hintergrundarbeit | idempotente Symfony-Console-Kommandos über Hosting-Cron |
| Tests | PHPUnit; Isolationstests verpflichtend |
| Dateien | private Speicherung außerhalb von `public/`, Auslieferung per Controller |
| Zeitzone/Sprache | `Europe/Zurich`, Deutsch; Übersetzungsschlüssel ab Beginn |

## 2. Analyse des Ausgangszustands

### Bestehendes Repository `dvo001/saas`

Der Prototyp ist eine weitgehend monolithische PHP-Anwendung. Routing,
Authentifizierung, Autorisierung, Datenzugriff und HTML-Ausgabe liegen größtenteils
in `public/index.php`. Einzelne Services kapseln Laufberechnungen. Das Schema enthält
bereits Ideen für Mandanten, Veranstaltungen und mehrere Sportarten, erzwingt die
Isolation aber nicht durchgängig über eine zentrale Zugriffsschicht.

Übernehmbar sind fachliche Begriffe, Beispieldaten und getestete Rechenregeln. Nicht
übernommen werden die monolithische Struktur, globale Hilfsfunktionen, direktes PDO
in Request-Handlern, statische Konfigurationsdateien mit potenziellen Secrets und die
generische Sportresultat-Erfassung als Ersatz für echte Module.

### Fachreferenz `dvo001/Laufanlass`

Die Referenz bildet folgenden Ablauf ab:

1. Veranstaltung und überschneidungsfreie Jahrgangskategorien konfigurieren.
2. Teilnehmende mit eindeutiger Laufzettelnummer erfassen.
3. Bis zu zwei Qualifikationszeiten und Laufstatus erfassen.
4. Beste Qualifikationszeit und Rang berechnen.
5. Finalisten vorschlagen und ausdrücklich bestätigen.
6. Finalzeit oder Finalstatus erfassen.
7. Qualifikations-/Endranglisten, Laufzettel und CSV ausgeben.

Die Regeln werden in Milestone 8 als neue Domain-Services implementiert. Datenmodell
und Code der Referenz werden nicht kopiert. Insbesondere werden Zeiten gemäß neuer
Spezifikation in Hundertstelsekunden statt in Zehntelsekunden gespeichert.

## 3. Begründung des Stacks

Symfony 7.4 LTS ist ein modular einsetzbares, langfristig gepflegtes Framework und
passt zu klassischem PHP-FPM/Apache-Hosting ohne dauerhaften Prozess. Security,
Formulare, CSRF, Übersetzung, Rate Limiting, Konsole, Mailer und Locking sind als
aufeinander abgestimmte Open-Source-Komponenten verfügbar. Die Anwendung bleibt
damit wartbarer als ein eigener Micro-Framework-Unterbau, ohne eine SPA oder einen
Node-Server zu benötigen.

Twig übernimmt konsequentes Auto-Escaping und serverseitiges Rendering. Bootstrap
5.3 bietet responsive Komponenten und native Farbschemata. Die Assets werden im
Release lokal ausgeliefert; Produktion ist weder von einem CDN noch von einem
Node-Build abhängig. Kleine Interaktionen und Polling werden mit ES-Modulen in
Vanilla JavaScript umgesetzt.

Doctrine bündelt Transaktionen, Migrationen und parametrisierte Abfragen. Repository-
Methoden erhalten den Mandantenkontext explizit; globale, ungefilterte Finder sind in
mandantenbezogenen Bereichen verboten.

## 4. Schichten und Abhängigkeiten

```text
HTTP/CLI
  -> Application (Use Cases, Commands, Queries)
      -> Domain (Regeln, Werteobjekte, Ports)
          <- Infrastructure (Doctrine, Mail, PDF, Dateien, Payment)
  -> Presentation (Controller, Forms, Twig, JSON/Polling)
```

Der Kern kennt keine Sportregeln. Sportmodule dürfen öffentliche Kernverträge
verwenden, aber weder andere Sportmodule noch deren Tabellen oder Services. Direkte
Abhängigkeiten werden durch Architekturtests überwacht.

## 5. Modulvertrag

Jedes produktive Sportmodul implementiert `SportModuleInterface` und wird als
Symfony-Service markiert. Der Vertrag liefert mindestens:

```php
interface SportModuleInterface
{
    public function key(): string;
    public function nameTranslationKey(): string;
    public function permissions(): array;
    public function navigation(): array;
    public function eventConfigurationType(): string;
    public function lifecycleGuard(): EventLifecycleGuardInterface;
}
```

Routen, Controller, Templates, Services und Doctrine-Mappings liegen im jeweiligen
Modulverzeichnis. Migrationen bleiben global versioniert, benennen das Modul aber im
Dateinamen. Ein `SportModuleRegistry` sammelt markierte Module, prüft eindeutige Keys
und stellt Metadaten bereit. Zugriff setzt stets sowohl Vereins-/Eventberechtigung als
auch eine aktive Modullizenz voraus.

Version 1 registriert nur `running_event` und `football_tournament`. Weitere Sportarten
werden nicht als Platzhalter in der Produktoberfläche angezeigt.

## 6. Zielverzeichnisstruktur

```text
assets/                     Quell-CSS und JavaScript
bin/console                 CLI-Einstieg
config/                     Framework- und Servicekonfiguration, keine Secrets
migrations/                 global geordnete Doctrine-Migrationen
public/                     einziger DocumentRoot, Front Controller und Assets
src/
  Core/
    Domain/
    Application/
    Infrastructure/
    Presentation/
  Module/
    RunningEvent/
    FootballTournament/
  Shared/
templates/                  Kernlayouts und gemeinsame Komponenten
tests/
  Architecture/
  Integration/TenantIsolation/
  Unit/
translations/
var/                        Cache und Logs, nicht öffentlich
storage/                    Uploads, Exporte und Dokumente, nicht öffentlich
```

Deployment-spezifische Werte kommen aus `.env.local` beziehungsweise echten
Umgebungsvariablen. `.env` enthält nur ungefährliche Defaults, `.env.local` wird
ignoriert. Der Root-Webserver darf keine Ausweichroute auf interne Dateien anbieten.

## 7. Datenmodell (fachliche Skizze)

Die endgültigen Tabellen entstehen schrittweise über Migrationen. Primärschlüssel
sind intern numerisch; öffentlich exponierte Ressourcen erhalten zusätzlich nicht
erratbare UUIDv7-Bezeichner.

### Kern

- `tenants`, `tenant_users` mit `UNIQUE (tenant_id, email)` und genau einem Owner
- `platform_admins` strikt getrennt von Vereinsbenutzern
- `roles`, `user_event_assignments`, `authentication_factors`, `login_attempts`
- `events`, `event_versions`, `event_templates`
- `sport_modules`, `tenant_module_licenses`
- `subscriptions`, `subscription_items`, `prices`, `coupons`
- `invoices`, `invoice_lines`, `payments`, `payment_attempts`
- `participants`, `teams`, `team_members`, `external_organizations`
- `notifications`, `audit_entries`, `support_sessions`
- `stored_files`, `document_versions`, `export_jobs`
- `scheduled_settings`, `cron_runs`, `deletion_receipts`

### Laufmodul

- `running_categories`, `running_entries`, `running_qualification_runs`
- `running_finalists`, `running_final_results`

### Fussballmodul

- `football_categories`, `football_teams`, `football_groups`
- `football_fields`, `football_matches`, `football_match_events`
- `football_tiebreak_decisions`, `football_brackets`

Jede mandantenbezogene Tabelle trägt `tenant_id`, selbst wenn der Mandant über eine
Elterntabelle ableitbar wäre. Fremdschlüssel und zusammengesetzte Indizes beginnen
mit `tenant_id`. Wo MariaDB es erlaubt, verhindern zusammengesetzte Fremdschlüssel
Querverweise zwischen Mandanten zusätzlich auf Datenbankebene.

Geld wird als ganzzahlige Rappen plus ISO-Währung gespeichert. Zeitpunkte werden in
UTC gespeichert und in `Europe/Zurich` dargestellt. Unveränderliche Rechnungs- und
Dokumentdaten werden als versionierter Snapshot gespeichert.

## 8. Mandantensicherheit

Nach erfolgreichem Login wird der Mandant ausschließlich aus der URL und der
authentifizierten Benutzerzuordnung aufgelöst. Eine vom Client gesendete `tenant_id`
wird nie als Autorisierung verwendet.

Die Schutzschichten sind:

1. `TenantContext` als requestgebundener, unveränderlicher Kontext.
2. Mandantenpflicht in allen Repository-Schnittstellen und Schreib-Use-Cases.
3. Doctrine-Filter als zusätzliche Lesesicherung, nicht als alleiniger Schutz.
4. Voter/Policies für Rolle, Eventzuweisung, Lizenz und Objektmandant.
5. Zusammengesetzte Datenbank-Constraints gegen mandantenübergreifende Referenzen.
6. Integrationstests pro Route, Repository und Dateiabruf mit zwei Mandanten.

Tests prüfen mindestens Listen, Detailseiten, manipulierbare IDs, Writes, Exporte,
Uploads, Polling und indirekte Beziehungen. Ein Zugriff auf fremde Objekte liefert
404, damit deren Existenz nicht offengelegt wird.

## 9. Security-Konzept

- Symfony PasswordHasher mit zeitgemäßen `password_hash`-Algorithmen.
- CSRF-Tokens für Zustandsänderungen; keine Änderung per GET.
- Secure-, HttpOnly- und SameSite-Cookies; Session-ID-Wechsel bei Login und
  Rechteänderung; serverseitige Inaktivitäts- und Maximallaufzeit.
- E-Mail-, Reset- und Einladungstokens zufällig, kurzlebig und nur gehasht gespeichert.
- TOTP-2FA mit verschlüsseltem Secret und einmalig verwendbaren, gehashten Recovery-Codes.
- Rate Limits und progressive Sperren für Login, Reset, Einladung und 2FA.
- Re-Authentifizierung für Ownerwechsel, Billing, 2FA-Abschaltung und Löschungen.
- Formulare mit serverseitiger Validierung; Twig-Auto-Escaping; parametrisierter
  Datenzugriff; restriktive Security-Header.
- Uploads werden nach Größe, dekodierbarem Inhalt und serverseitig ermitteltem MIME
  geprüft, umbenannt und privat gespeichert. SVG ist ausgeschlossen.
- Logs enthalten Fehlerreferenzen, aber keine Passwörter, Tokens, Zahlungsdetails
  oder unnötige Personendaten.
- Support-Impersonation ist zeitlich begrenzt, deutlich sichtbar, begründet und
  vollständig auditiert; besonders kritische Aktionen bleiben gesperrt.

## 10. Migrationen und Installation

Doctrine Migrations ist die einzige Quelle für das Schema. Migrationen sind
vorwärtsgerichtet, transaktional soweit MariaDB-DDL dies zulässt und werden vor dem
Deployment in einer frischen sowie einer Datenbank des vorherigen Releases geprüft.
Destruktive Umbauten folgen dem Expand-Migrate-Contract-Muster.

Der Web-Installer ist nur aktiv, wenn noch keine Installationssperre existiert und
`APP_INSTALLER_ENABLED=1` serverseitig gesetzt wurde. Er prüft PHP-Erweiterungen,
Schreibrechte und Datenbank, führt Migrationen aus und erstellt Grunddaten sowie den
ersten Plattformadmin in einer Transaktion. Danach wird eine Sperre außerhalb von
`public/` geschrieben. Eine erneute Freigabe ist nur serverseitig möglich.

Demo-Seeds sind ausschließlich in `dev`/`test` verfügbar und müssen ausdrücklich
aufgerufen werden.

## 11. Cron- und Jobarchitektur

Jeder Job ist ein idempotentes Symfony-Console-Kommando, zum Beispiel
`app:cron:run --job=subscription-expiry`. Der Hoster startet alle fünf Minuten einen
Dispatcher. Datenbankbasierte Locks verhindern parallele Läufe. Lange Mengen werden
in begrenzten Batches verarbeitet und können im nächsten Lauf fortgesetzt werden.

`cron_runs` protokolliert Job, Start, Ende, Status, Zähler und eine nicht sensitive
Fehlerreferenz. Der letzte erfolgreiche Lauf ermöglicht Überfälligkeitswarnungen.
Löschjobs unterstützen zwingend `--dry-run`; manuelle kritische Läufe verwenden
denselben Application-Service wie der Cron und erzeugen Audit-Einträge.

Asynchrone Arbeit benötigt keinen Worker: Eine Datenbank-Outbox hält Mail- und
Exportaufträge, die durch eigene Cron-Kommandos abgearbeitet werden. Wiederholungen
verwenden exponentielles Backoff und eine begrenzte Versuchszahl.

## 12. Payment-Abstraktion

Der Kern definiert einen `PaymentGatewayInterface` mit Operationen zum Erstellen
eines Bezahlvorgangs, Prüfen/Verarbeiten signierter Callbacks und Erstatten. Fachliche
Abo- und Rechnungszustände hängen nur von internen Payment-Entitäten ab, nie von
Providerobjekten.

Version 1 enthält:

- `InvoicePaymentGateway` für manuelle Zahlung per Schweizer Rechnung.
- mindestens einen Online-Adapter, erst nach separater Providerauswahl.
- eine unveränderliche Provider-Referenz und idempotente Ereignis-ID pro Callback.
- einen periodischen Reconciliation-Job als Absicherung gegen verlorene Callbacks.

QR-Rechnungen werden aus eingefrorenen Rechnungsdaten erzeugt. Provider-Secrets
liegen ausschließlich in der Umgebung; rohe Zahlungsdaten werden weder gespeichert
noch geloggt. Weitere Anbieter können als Infrastructure-Adapter ergänzt werden.

## 13. Fehler, Audit und Nebenläufigkeit

Unbehandelte Fehler erzeugen eine zufällige Referenz, die Benutzer sehen und
Plattformadmins im strukturierten Log suchen können. Produktionsantworten enthalten
keine Stacktraces.

Audit-Einträge werden im selben Commit wie die fachliche Änderung geschrieben und
enthalten Akteur, Mandant, Aktion, Objekt, Zeitpunkt sowie minimierte alte/neue Werte.
Versionierte Aggregate führen eine `version`-Spalte. Updates verlangen die gelesene
Version; bei Abweichung wird ein Konflikt mit Vergleichsansicht gemeldet.

## 14. Qualitätssicherung und Delivery

Jeder Milestone erhält einen eigenen Branch und Pull Request. Der Mindestcheck ist:

```bash
composer validate --strict
composer install --no-interaction
vendor/bin/phpunit
vendor/bin/phpstan analyse
bin/console doctrine:migrations:migrate --no-interaction --env=test
bin/console lint:container
bin/console lint:twig templates
```

Zusätzlich werden alle PHP-Dateien syntaktisch geprüft und die Anwendung über einen
HTTP-Smoke-Test gestartet. Milestone 2 führt die verpflichtende Isolationstest-Matrix
ein; danach blockiert sie jeden Merge.

Releases werden mit `composer install --no-dev --classmap-authoritative` gebaut. Es
gibt keine Produktionsabhängigkeit von Node, einem Queue-Worker, WebSockets oder
proprietären Pflichtdiensten.

## 15. Umsetzung ab Milestone 1

Milestone 1 ersetzt das Projektgerüst vollständig und liefert einen installierbaren
Application Core mit Konfiguration, Logging/Fehlerreferenzen, Migrationen, deutscher
i18n-Grundlage, responsivem Light/Dark-Layout, sicherer Dateistruktur, Web-Installer
und versionierten Plattform-Basiseinstellungen. Fachliche Auth-, Billing- und
Sportmodulfunktionen beginnen erst in den dafür vorgesehenen Milestones.

Beim Übergang werden Referenzdateien nicht unkontrolliert gelöscht: Jede Entfernung
erfolgt im Milestone-1-Commit nachvollziehbar, nachdem entsprechende Erkenntnisse in
Spezifikation oder Architektur gesichert sind.
