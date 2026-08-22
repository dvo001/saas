# Milestone 4 – Abos, Module und Billing

## Umgesetzt

- Plattformweiter Produkt- und Modulkatalog mit aktivierbaren Produkten
- Historisierte, ab einem Zeitpunkt gültige Preisversionen
- Hauptabo mit zwölf Monaten Laufzeit und genau einem Hauptmodul
- Zusatzmodule zum vollen Preis und mit gemeinsamem Laufzeitende
- Billing-Profil mit Pflichtfeldern und vorgemerkter Änderung der Rechnungs-E-Mail
- Transaktionale Buchung mit unveränderlichen Preis-, Adress-, Rabatt-, Frist- und MwSt.-Snapshots
- Prozentuale Erstbuchungs- und vereinsgebundene Kulanzgutscheine mit einmaliger, atomarer Einlösung
- Jährliche, konkurenzsicher fortgeschriebene Rechnungsnummern und eigene Sequenz für Gutschriften
- Schweizer QR-Rechnung als PDF mit SCOR-Referenz und zentral versionierten Kreditor-/MwSt.-Daten
- Rechnungsarchiv im Verein sowie manuelle Zahlungsverbuchung durch Plattformadmins
- Kündigung zum Laufzeitende und Owner-gesteuerte automatische Verlängerungsoption mit Sieben-Tage-Sperrfrist
- Payment-Provider-Schnittstelle ohne vorgetäuschten Live-Provider
- Lifecycle für Zahlungsfrist, Mahnfrist, Sperrung, automatische Entsperrung und 90 Tage Aufbewahrung
- Serverseitiger, mandantensicherer Lizenzservice für Trial und bezahlte Module
- Audit-Einträge für Buchung, Profil, Kündigung, Verlängerung, Zahlung, Gutschein und Kulanz
- CLI-Einstieg `app:billing:lifecycle` für die spätere Einbindung in den Cronrunner aus Milestone 5

## Bewusste Milestone-Grenzen

Der Versand von Rechnungs-, Mahn-, Ablauf- und Bestätigungs-E-Mails, das interne Notification Center sowie zeitgesteuerte Verlängerungsankündigungen werden in Milestone 5 an die hier vorhandenen Zustände und Auditereignisse angeschlossen. Ereignis-/Modulcontroller ab Milestone 6 verwenden `LicenseService::denyUnlessLicensed()` für die serverseitige Zugriffssperre.

## Betrieb

Vor der ersten PDF-Erzeugung müssen unter Plattformadministration → Einstellungen der MwSt.-Satz sowie Zahlungsempfänger, Adresse und eine gültige IBAN beziehungsweise QR-IBAN versioniert gespeichert werden. Der Lifecycle-Befehl ist idempotent und darf bis zur Einführung des zentralen Cronrunners regelmässig durch den Hoster ausgeführt werden.
