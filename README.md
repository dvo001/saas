# Sportanlaesse

Mandantenfaehige LAMP-Webapplikation fuer Sportanlaesse. Die Plattform ist fuer
mehrere Veranstalter/Organisationen und mehrere Sportarten vorbereitet, darunter
Laufanlaesse, Fussballturniere, Leichtathletik, Judo und freie Formate.

Der Lauf-Modus enthaelt bereits die vollstaendige Zeitmessungslogik mit
Teilnehmererfassung, Qualifikationslaeufen, Finalplaetzen, Finalzeiten,
Ranglisten, Laufzetteln und CSV-Export. Weitere Sportarten koennen als eigene
Wertungsmodule auf dem SaaS-Kern aufgebaut werden.

## SaaS- und Multi-Sport-Kern

- Organisationen/Mandanten mit eigener Datenisolation
- Benutzer mit Login und Mitgliedschaft pro Organisation
- Rollen-Grundlage: `owner`, `admin`, `operator`, `viewer`
- Plan-/Subscription-Grundlage: `starter`, `club`, `pro`
- Einladungs-, Subscription- und Audit-Log-Tabellen fuer Betrieb, Support und Compliance
- CSRF-Schutz fuer POST-Formulare und gehaertete Session-Cookies
- Team-Einladungen per Token-Link, Passwort-Reset-Grundflow und Installations-Claim fuer migrierte Daten
- Rollen- und Planlimit-Durchsetzung in den Schreibfluesse
- Sportartenkatalog: Lauf, Fussballturnier, Leichtathletik, Judo, andere Sportart
- Event-Metadaten je Sportart mit Wertungsmodi: Zeitwertung, Turnier, Punkte, K.-o.-Raster, freie Wertung
- bestehende Laufwertung nur fuer zeitbasierte Anlaesse sichtbar
- generische Multi-Sport-Erfassung fuer Teams/Starter, Disziplinen, Begegnungen/Kaempfe und Punkte-/Rangresultate

## Systemvoraussetzungen

- Ubuntu Server 24.04 LTS oder neuer
- Apache 2 mit Rewrite-Modul
- MariaDB oder MySQL
- PHP 8.1 oder neuer mit PDO MySQL
- Optional Composer und `dompdf/dompdf` fuer echte PDF-Ausgaben

## Installation Kurzfassung

```bash
cp config/database.example.php config/database.php
mysql -u root -p < database/schema.sql
php -S 127.0.0.1:8080 -t public
```

Fuer eine Serverinstallation auf Ubuntu 26.04 liegt ein Skript bereit:

```bash
chmod +x scripts/setup_sportlauf_lamp_ubuntu_26_04.sh
sudo scripts/setup_sportlauf_lamp_ubuntu_26_04.sh
```

Mit eigenem Datenbankpasswort:

```bash
sudo DB_PASS='EinStarkesPasswort' APP_DOMAIN='sportlauf.local' \
  scripts/setup_sportlauf_lamp_ubuntu_26_04.sh
```

## Datenbank

Beispiel:

```sql
CREATE DATABASE sportlauf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sportlauf_user'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON sportlauf.* TO 'sportlauf_user'@'localhost';
FLUSH PRIVILEGES;
```

Schema importieren:

```bash
mysql -u sportlauf_user -p sportlauf < database/schema.sql
mysql -u sportlauf_user -p sportlauf < database/seed.sql
```

Bei einer bestehenden Installation einmalig die Anlass-Konfiguration ergaenzen:

```bash
mysql -u sportlauf_user -p sportlauf < database/migrations/20260706_event_configuration.sql
```

Fuer den SaaS-/Multi-Sport-Umbau bestehende Installationen anschliessend migrieren:

```bash
mysql -u sportlauf_user -p sportlauf < database/migrations/20260809_saas_multisport.sql
```

Bei bestehenden Daten ohne Benutzer kann die erste Person die migrierte
Standard-Organisation einmalig ueber `/claim` uebernehmen.

Ein optionales Anlasslogo kann in `public/assets/img/` abgelegt und beim Anlass
beispielsweise als `/assets/img/mein-logo.png` eingetragen werden.

## Apache VirtualHost

`public/` muss der einzige DocumentRoot sein:

```apache
<VirtualHost *:80>
    ServerName sportlauf.local
    DocumentRoot /var/www/sportlauf/public

    <Directory /var/www/sportlauf/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## Externer Hoster

Fuer den Betrieb bei einem externen PHP/MySQL-Hoster gibt es eine eigene
Anleitung:

```text
docs/EXTERNER_HOSTER.md
```

Kurzfassung:

- Ideal: Domain-DocumentRoot auf `public/` setzen.
- Alternativ: Projekt in den Webordner hochladen; die Root-`.htaccess` leitet
  intern nach `public/index.php` weiter und sperrt private Ordner.
- `config/database.hosting.php` nach `config/database.php` kopieren und die
  Datenbankdaten des Hosters eintragen.
- `database/schema.sql` in phpMyAdmin/Adminer importieren.

## Bedienablauf

1. Benutzerkonto registrieren und Organisation erstellen.
2. Anlass mit Sportart, Disziplin/Format und Wertungsmodus erstellen.
3. Anlass in der linken Navigation auswaehlen.
4. Fuer Lauf-/Zeitwertungsanlaesse Jahrgangsgruppen erfassen oder von einem bestehenden Anlass uebernehmen.
5. Teilnehmer mit Laufzettel-ID erfassen.
6. Qualifikationszeiten separat oder per Schnellerfassung erfassen.
7. Qualifikationsrangliste pruefen.
8. Falls konfiguriert: Finalisten vorschlagen, bestaetigen und Finalzeiten erfassen.
9. Endrangliste drucken, als PDF anzeigen oder CSV exportieren.

Fuer Fussball, Leichtathletik, Judo und weitere Sportarten steht unter
`/sport-results` eine generische Erfassung fuer Teams/Starter, Disziplinen,
Begegnungen/Kaempfe und freie Resultate bereit. Sportartspezifische Auswertungen
koennen darauf aufbauend weiter spezialisiert werden.

## SaaS-Betrieb

- `owner`: Plan/Billing und Teamverwaltung
- `admin`: Teamverwaltung und Loeschaktionen
- `operator`: Anlaesse und Resultate bearbeiten
- `viewer`: Lesender Zugriff

Subscriptions werden aktuell intern/manuell verwaltet. Fuer automatisierte
Zahlungen kann spaeter ein Provider wie Stripe an die Tabelle `subscriptions`
und Provider-Webhook-Felder angeschlossen werden. Token-Links fuer Einladungen
und Passwort-Reset werden in der Oberflaeche erzeugt; fuer produktiven Betrieb
sollte daran ein Mailversand angeschlossen werden.

## PDF

Ohne weitere Abhaengigkeit liefert `/rankings/pdf` und `/sheets/pdf` eine
druckbare HTML-Ansicht. Fuer echte PDF-Dateien:

```bash
composer require dompdf/dompdf
```

## Tests

```bash
php tests/run.php
```

## Backup und Restore

```bash
mysqldump -u sportlauf_user -p sportlauf > sportlauf_backup_$(date +%Y%m%d_%H%M%S).sql
mysql -u sportlauf_user -p sportlauf < backup.sql
```

## Fehlersuche

```bash
sudo systemctl status apache2
sudo systemctl status mariadb
php -v
apache2ctl configtest
sudo tail -f /var/log/apache2/sportlauf_error.log
```
