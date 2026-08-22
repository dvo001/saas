# Milestone 10 – Dokumente, Exporte und Abschluss

## Umgesetzt

- Einheitliches PDF-Branding mit Vereinslogo, Vereins- und Veranstaltungsname, Datum, konfigurierbarem Plattformnamen, Erstellzeitpunkt und Dokumentversion
- Sicherer Logo-Upload für Owner und Administratoren: ausschliesslich dekodierbare PNG/JPEG-Dateien bis 2 MB, serverseitige Skalierung und optimierte PNG-Ablage; die maximale Abmessung ist eine versionierte Plattform-Einstellung
- Automatische, unveränderliche Abschlussdokumente beim Statuswechsel auf „abgeschlossen“ für Laufveranstaltungen und Fussballturniere
- Version, vollständiger JSON-Snapshot, SHA-256-Prüfsumme und unveränderliche Angaben zur freigebenden Person je Abschlussdokument
- Geschützter PDF-Download auch während einer reinen Modul-Aufbewahrungsphase; ältere Dokument- und Publikationsversionen werden beim Archivieren entfernt
- Vollständiger, asynchron über den Cron-Lauf erzeugter Vereinsdatenexport als ZIP mit CSV-Daten, Veranstaltungs-PDFs sowie Rechnungen, Gutschriften und Zahlungen
- Expliziter Ausschluss des Tenant-Audit-Protokolls und aller Passwörter, TOTP-Geheimnisse und Token aus dem ZIP-Export
- Owner-only Exportanforderung, interne und E-Mail-Benachrichtigung, erneute Prüfung von Passwort und TOTP beim Download sowie automatische Löschung nach sieben Tagen
- Dauerhaftes Rechnungsarchiv für Owner und Administratoren; neue Rechnungen werden als QR-PDF gespeichert und per E-Mail mit PDF-Anhang versendet
- Fehlgeschlagener Rechnungsversand verändert die Gültigkeit der Rechnung nicht und erzeugt eine referenzierte Admin- sowie Systemmeldung
- Plattformweiter Buchhaltungs-CSV-Export für Rechnungen, Gutschriften und Zahlungen mit Monats-, Quartals-, Jahres- oder freiem Zeitraumfilter
- Zehnjährige gesetzliche Aufbewahrungsmetadaten für Rechnungen, Zahlungen und Plattform-Audit-Ereignisse
- 90-tägige Account-Aufbewahrung und modulbezogene Archivfristen; danach werden operative Daten gelöscht, während gesetzlich nötige Finanzbelege in einem anonymisierten Account-Rumpf verbleiben
- Endgültige Löschung nach Ablauf der gesetzlichen Fristen samt Dateibereinigung und permanentem, nicht-personenbezogenem `deletion_log`
- Manuelle Retention-Vorschau bleibt über den Cron-Befehl verfügbar

## Sicherheit und Datenfluss

Abschlussdokumente und Exporte liegen ausserhalb des öffentlichen Webroots mit restriktiven Dateirechten. Jeder Download prüft Mandant und Veranstaltung erneut. Der vollständige ZIP-Export darf nur vom Owner ausgelöst und nach einer frischen Passwort- und TOTP-Prüfung geladen werden. Sein Manifest dokumentiert ausdrücklich, dass das Tenant-Audit nicht enthalten ist.

Die Retention-Bereinigung entfernt zunächst nicht mehr benötigte Veranstaltungs-, Benutzer-, Stammdaten- und Exportdateien. Rechnungen, Gutschriften, Zahlungen und Plattform-Audit-Ereignisse werden anhand ihres eigenen `retention_until` erst nach zehn Jahren gelöscht. Der dauerhafte Löschungsnachweis enthält nur einen Hash der früheren Tenant-ID, den Löschgrund, aggregierte Zähler und den Zeitpunkt.

## Betrieb

Die Migration `Version20260821090000` muss vor dem ersten M10-Lauf eingespielt werden. `app:cron:run exports` erstellt und bereinigt ZIP-Exporte; `app:cron:run retention --preview` zeigt anstehende Bereinigungen ohne Änderungen, während der Lauf ohne `--preview` sie ausführt.
