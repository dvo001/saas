# Milestone 5 – Benachrichtigungen und Cronjobs

## Umgesetzt

- Mandantensicheres internes Notification Center für handlungsrelevante Meldungen mit Lesestatus und Deduplizierung
- Zentraler SMTP-Versand über Symfony Mailer mit versioniertem Systemabsender, optionalem Reply-To und datensparsamer Zustellhistorie
- Shared-Hosting-tauglicher Cronrunner `app:cron:run`, der ohne dauerhaften Worker arbeitet
- Isolierte, idempotente Jobs für Trial, Billing/Mahnungen, Wartung, Exporte und Aufbewahrung
- Cronhistorie mit Start, Ende, Status, Ergebnisdaten und nicht sensitiver Fehlerreferenz
- Erkennung fehlgeschlagener und überfälliger Jobs im Plattform-Systemstatus
- Alarm-E-Mail an aktive Plattformadministratoren bei fehlgeschlagenen Jobs
- Interne und per E-Mail versandte Hinweise zu Trial-Ablauf, überfälligen Rechnungen, drohender Sperrung, Wartung und Löschfristen
- Asynchrone Exportwarteschlange mit sieben Tagen Dateiaufbewahrung und automatischer Bereinigung
- 30-Tage-Trial- und 90-Tage-Abo-Aufbewahrungsjob mit Sieben-Tage-Warnung, Modularchivfristen, Vorschaumodus und permanentem, nicht personenbezogenem Löschprotokoll
- Gesetzlich aufzubewahrende Rechnungen bleiben beim Löschen erhalten; der zugehörige Vereinsaccount wird anonymisiert

## Betrieb

Ein Cronjob des Hosters soll alle fünf Minuten laufen:

```sh
php /absoluter/pfad/bin/console app:cron:run --env=prod
```

Einzelne Jobs können mit `--job=trials`, `billing`, `maintenance`, `exports` oder `retention` manuell gestartet werden. Vor einer manuellen Löschung ist zwingend die Vorschau auszuführen:

```sh
php bin/console app:cron:run --job=retention --preview --env=prod
```

SMTP wird über `MAILER_DSN` konfiguriert. Der sichtbare Systemabsender wird unter Plattformadministration → Einstellungen versioniert gepflegt. Cronintervalle dienen der Überwachung und werden dort ebenfalls konfiguriert.

## Milestone-Grenze

Die Exportwarteschlange und Dateibereinigung sind vorhanden. Fachliche Mandanten- und Abrechnungsexporte werden in Milestone 10 an diese Infrastruktur angeschlossen.
