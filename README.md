# Vereinssport Schweiz

Modulare SaaS-Plattform für kleine Schweizer Sportvereine. Version 1 wird für
Laufanlässe und Fussballturniere entwickelt.

Der aktuelle Stand ist **Milestone 1 (Application Core)**. Enthalten sind das
Symfony-Grundgerüst, sichere Konfiguration, versionierte Migrationen,
Fehlerreferenzen, deutsche i18n-Grundlage, responsives Light/Dark-Layout,
private Dateispeicherung, Web-Installer und versionierte Plattform-Grundwerte.

## Technischer Stack

- PHP 8.2 oder neuer
- Symfony 7.4 LTS, Twig und Doctrine
- MariaDB 10.6 oder neuer
- Apache; ausschließlich `public/` als DocumentRoot
- lokal ausgeliefertes Bootstrap 5.3 und Vanilla JavaScript
- keine dauerhaften Node-, WebSocket- oder Worker-Prozesse

Die Entscheidungen und Modulgrenzen sind in [docs/ARCHITEKTUR.md](docs/ARCHITEKTUR.md)
dokumentiert.

## Installation

1. Leere MariaDB-Datenbank und einen darauf berechtigten Benutzer erstellen.
2. Abhängigkeiten installieren:

   ```bash
   composer install --no-interaction
   ```

3. Apache-DocumentRoot auf `public/` setzen und Schreibrechte für `var/`,
   `storage/` sowie das Projektverzeichnis während der Installation gewähren.
4. `/install` im Browser öffnen.
5. Systemcheck, Datenbank, Plattformdaten und ersten Plattformadmin erfassen.

Der Installer führt die Doctrine-Migrationen aus, schreibt Secrets in die
nicht versionierte `.env.local` und erzeugt `storage/installed.lock`. Danach ist
er gesperrt und setzt `APP_INSTALLER_ENABLED=0`. Für eine bewusste erneute
Freigabe müssen Sperrdatei und Serverkonfiguration manuell angepasst werden.

Das Projektstammverzeichnis darf nicht als DocumentRoot verwendet werden. Die
Root-`.htaccess` sperrt den Zugriff vorsorglich vollständig.

## Lokale Entwicklung

```bash
composer install
php -S 127.0.0.1:8080 -t public
```

Danach `http://127.0.0.1:8080/install` öffnen. Für lokale Datenbankwerte kann
vorab eine nicht versionierte `.env.local` angelegt werden.

## Updates und Migrationen

```bash
composer install --no-dev --classmap-authoritative --no-interaction
APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear
```

`composer install` veröffentlicht auch die lokalen Bootstrap-Assets. Schema-
Updates erfolgen ausschließlich über `migrations/`; alte vollständige
Schemaimporte werden nicht mehr verwendet.

## Qualitätschecks

```bash
composer validate --strict
vendor/bin/phpunit
vendor/bin/phpstan analyse
php bin/console lint:container
php bin/console lint:yaml config translations
php bin/console lint:twig templates
```

Die verpflichtenden Mandanten-Isolationstests werden mit dem Mandantenmodell in
Milestone 2 eingeführt und blockieren danach jeden Merge.

## Private Dateien und Secrets

- Secrets gehören ausschließlich in `.env.local` oder Server-Umgebungsvariablen.
- Uploads liegen unter `storage/uploads/`, Exporte unter `storage/exports/`.
- Beide Verzeichnisse liegen außerhalb von `public/` und werden später nur über
  autorisierte Controller ausgeliefert.
- Produktionslogs liegen unter `var/log/` und enthalten für technische Fehler
  eine Benutzer-referenzierbare Fehler-ID.

Demo-Seeds werden niemals automatisch in Produktion geladen.
