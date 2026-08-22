# Deployment auf Cyon und klassischem Shared Hosting

Diese Anleitung ergänzt [INSTALLATION.md](INSTALLATION.md). Menübezeichnungen und
PHP-Pfade wurden im August 2026 gegen die offizielle Cyon-Dokumentation geprüft.

## 1. Release vorbereiten

Lokal aus dem freigegebenen Commit zunächst mit Entwicklungswerkzeugen prüfen und
danach das Produktionspaket bauen:

```bash
composer install --no-interaction
composer validate --strict
composer verify
composer install --no-dev --classmap-authoritative --no-interaction
```

Wenn Composer auf dem Hosting verfügbar ist, kann `composer install` nach dem
Upload dort ausgeführt werden. Andernfalls wird das lokal erzeugte `vendor/`
mitübertragen. Entwicklungsdateien, `.git/`, lokale Logs und eine lokale
`.env.local` gehören nicht ins Upload-Paket.

## 2. Domain und PHP einrichten

Im my.cyon-Kontrollpanel die Domain auf den Unterordner `<projekt>/public` richten.
Cyon dokumentiert das Ändern des Zielordners unter
[Domain auf einen Unterordner einrichten](https://www.cyon.ch/support/a/domain-auf-einen-unterordner-einrichten).
Das Projektstammverzeichnis ist kein zulässiges Webroot.

Unter **Erweitert → PHP-Versionsmanager** PHP 8.2 oder neuer auswählen. Die
verfügbaren Versionen und den Versionsmanager beschreibt Cyon unter
[Verfügbarkeit einer PHP-Version](https://www.cyon.ch/support/a/verfugbarkeit-einer-php-version)
und [PHP-Versionsmanager](https://www.cyon.ch/support/a/php-versionsmanager).
Benötigte PHP-Optionen können gemäß
[PHP-Konfiguration anpassen](https://www.cyon.ch/support/a/php-konfiguration-anpassen)
gesetzt werden.

HTTPS muss vor dem ersten produktiven Login aktiv sein. Die Anwendung setzt in
Produktion Secure-Cookies, HSTS und eine restriktive Content Security Policy.

## 3. Dateien, Datenbank und Secrets

1. Projekt außerhalb des öffentlich sichtbaren Zielordners hochladen.
2. Leere MariaDB-Datenbank und dedizierten Datenbankbenutzer anlegen.
3. `var/` und `storage/` für den PHP-Prozess beschreibbar machen; pauschale Rechte
   `0777` vermeiden.
4. Web-Installer verwenden oder `.env.prod.example` als `.env.local` ausfüllen.
5. `.env.local` auf möglichst restriktive Dateirechte setzen (`0600`).
6. `APP_TRUSTED_HOST` exakt auf die produktive Domain begrenzen und
   `APP_INSTALLER_ENABLED=0` nach der Installation kontrollieren.

Die mitgelieferte Root-`.htaccess` sperrt das Projektverzeichnis zusätzlich. Die
`public/.htaccess` aktiviert den Front Controller und deaktiviert Directory Listings.
Cyon erläutert die unterstützte Konfiguration unter
[.htaccess](https://www.cyon.ch/support/a/htaccess).

## 4. Cronjob bei Cyon

Unter **Erweitert → Cronjobs** einen Lauf alle fünf Minuten anlegen. Laut
[Cyon-Cronjob-Anleitung](https://www.cyon.ch/support/a/cronjob-erstellen-und-bearbeiten)
ist `/usr/bin/php` der allgemeine PHP-Interpreter:

```text
cd /home/<konto>/<projekt> && /usr/bin/php bin/console app:cron:run --env=prod >> var/log/cron.log 2>&1
```

Wenn CLI und Website unterschiedliche PHP-Versionen verwenden, den
versionsgebundenen Interpreter einsetzen, zum Beispiel
`/opt/alt/php83/usr/bin/php`. Den tatsächlich passenden Pfad anhand der gewählten
Version kontrollieren; Cyon beschreibt dies unter
[Cronjob funktioniert nicht](https://www.cyon.ch/support/a/cronjob-funktioniert-nicht).

Anschließend im Plattform-Systemstatus prüfen, ob der Lauf erfolgreich protokolliert
wurde. Der Job verwendet Locks und darf gefahrlos erneut gestartet werden.

## 5. Produktionscheck

```bash
APP_ENV=prod APP_DEBUG=0 php bin/console about
APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:migrations:status
APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup
APP_ENV=prod APP_DEBUG=0 php bin/console app:cron:run --job=maintenance
```

Danach prüfen:

- Das Hosting leitet HTTP auf HTTPS um und verwendet die kanonische Domain.
- `/install` ist gesperrt.
- Plattform- und Vereinslogin funktionieren einschließlich 2FA.
- Testmail, Upload und autorisierter Download funktionieren.
- `var/log/prod.log` und `var/log/cron.log` bleiben frei von Secrets.
- Externe Zugriffe auf `.env.local`, `storage/` und `var/` schlagen fehl.

## 6. Betrieb und Wiederherstellung

Regelmäßig Datenbank, `storage/` und `.env.local` sichern. Backups getrennt vom
Webspace speichern und eine Wiederherstellung testen. Vor Updates immer ein
konsistentes Backup erstellen. Fehler lassen sich über die angezeigte Fehlerreferenz
in `var/log/prod.log` korrelieren, ohne sensible Werte an Benutzer auszugeben.
