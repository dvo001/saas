# Installation und Updates

## Voraussetzungen

- PHP 8.2 oder neuer mit `ctype`, `fileinfo`, `gd`, `iconv`, `intl`, `mbstring`,
  `openssl`, `pdo_mysql`, `sodium` und `zip`
- MariaDB 10.6 oder neuer
- Composer 2
- Apache/LiteSpeed mit Rewrite-Unterstützung
- HTTPS und eine Domain, deren DocumentRoot direkt auf `public/` zeigt
- Schreibrechte des PHP-Prozesses für `var/` und `storage/`

Das Projektverzeichnis selbst darf nie öffentlich ausgeliefert werden. Uploads,
Exporte, Logs und Secrets liegen absichtlich außerhalb von `public/`.

## Erstinstallation mit dem Web-Installer

1. Release entpacken und Abhängigkeiten installieren:

   ```bash
   composer install --no-dev --classmap-authoritative --no-interaction
   ```

2. Domain/Virtual Host auf `<projekt>/public` richten.
3. Eine leere MariaDB-Datenbank mit eigenem Benutzer erstellen.
4. Sicherstellen, dass `var/`, `storage/` und für die einmalige Installation das
   Projektverzeichnis durch PHP beschreibbar sind.
5. `https://<domain>/install` aufrufen und Systemcheck, Datenbank, Mailversand samt
   gültiger Absenderadresse, Plattformdaten sowie den ersten Plattformadmin erfassen.
6. Nach Abschluss prüfen, dass `storage/installed.lock` existiert und in
   `.env.local` `APP_ENV=prod`, `APP_DEBUG=0` und `APP_INSTALLER_ENABLED=0` stehen.
7. Schreibrechte am Projektverzeichnis wieder entfernen; nur `var/` und `storage/`
   bleiben beschreibbar.

Der Installer erzeugt individuelle Secrets, führt alle Migrationen aus und sperrt
sich anschließend. `.env.local` muss mit Dateirechten `0600` privat bleiben.

## Manuelle Produktionskonfiguration

Falls der Web-Installer nicht verwendet werden kann, `.env.prod.example` als
Vorlage für die nicht versionierte `.env.local` verwenden. Alle Platzhalter müssen
ersetzt werden. Insbesondere müssen `DEFAULT_URI` die kanonische HTTPS-Adresse und
`APP_TRUSTED_HOST` ausschließlich die erlaubte Host-Domain enthalten.

```bash
cp .env.prod.example .env.local
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console cache:clear --env=prod
```

Ein Secret kann beispielsweise mit `openssl rand -hex 32` erzeugt werden. Werte
mit Sonderzeichen bleiben in `.env.local` einfach oder URL-kodiert. Die Datei wird
nicht committet.

## Cronjob

Alle fünf Minuten genügt ein einziger, überlappungssicherer Lauf:

```cron
*/5 * * * * cd /absoluter/pfad/zum/projekt && /usr/bin/php bin/console app:cron:run --env=prod >> var/log/cron.log 2>&1
```

Der Runner verarbeitet Trials, Abrechnung, Wartung, Exporte und Retention. Für eine
kontrollierte Löschvorschau kann vorab ausgeführt werden:

```bash
php bin/console app:cron:run --job=retention --preview --env=prod
```

## Update

Vor jedem Update Datenbank, `.env.local`, `storage/` und benutzererzeugte Dateien
sichern. Danach im Wartungsfenster:

Bei einem Update von Milestone 10 oder älter zuerst die neuen Werte aus
`.env.prod.example` übernehmen. Insbesondere ist `APP_TRUSTED_HOST` auf die
produktive Domain zu setzen; andernfalls lehnt die Anwendung Anfragen mit einem
nicht freigegebenen Hostnamen ab. Bestehende Secrets dürfen dabei nicht ersetzt
werden.

```bash
composer install --no-dev --classmap-authoritative --no-interaction
APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear
```

Nach dem Update Login, Cronstatus, Mailversand und mindestens einen typischen
Mandantenablauf prüfen. Ein Rollback benötigt immer den passenden Code **und** das
vor dem Update erstellte Datenbank-Backup.

## Release-Prüfung

Vor Veröffentlichung läuft lokal:

```bash
composer validate --strict
composer verify
APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup
```

Die vollständigen Isolationstests benötigen eine ausschließlich für Tests bestimmte,
frisch migrierte MariaDB:

```bash
RUN_DATABASE_TESTS=1 DATABASE_URL='mysql://…/saas_test?serverVersion=10.6.0-MariaDB&charset=utf8mb4' vendor/bin/phpunit --group database
```
