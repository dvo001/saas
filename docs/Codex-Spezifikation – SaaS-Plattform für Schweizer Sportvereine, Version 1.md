
# 1. Auftrag und Ziel

Im bestehenden GitHub-Repository `dvo001/saas` soll eine neue, saubere SaaS-Plattform für kleinere Schweizer Sportvereine aufgebaut werden.

Der bisherige Code im Repository ist **nicht als technische Basis zu erhalten**. Codex darf die bestehende Architektur vollständig ersetzen. Vorhandene Dateien und bisherige Implementierungen dienen nur als Referenz und dürfen entfernt werden, wenn sie nicht zur neuen Architektur passen.

Das Repository:

`https://github.com/dvo001/saas`

wird weiterhin als Zielrepository verwendet.

Für das Sportmodul **Laufanlass** dient zusätzlich:

`https://github.com/dvo001/Laufanlass`

ausschließlich als **fachliche Referenz**. Codex soll dort vorhandene Abläufe und Funktionen analysieren, aber keinen bestehenden Code blind übernehmen. Das Laufmodul soll innerhalb der neuen SaaS-Architektur neu implementiert werden.

Version 1 enthält genau zwei produktive Sportmodule:

1. Laufanlass
2. Fussballturnier

Weitere Sportarten müssen durch die Architektur später ergänzt werden können, sind aber nicht Bestandteil von Version 1.

---

# 2. Zielgruppe

Die Plattform richtet sich primär an **kleine Schweizer Vereine und Organisationen**, die Sportveranstaltungen ohne komplexe Spezialsoftware verwalten möchten.

Version 1 ist auf den Schweizer Markt ausgerichtet:

- Währung: CHF
- Schweizer Rechnungsanforderungen
- Schweizer QR-Rechnung
- Schweizer Mehrwertsteuer
- Standard-Zeitzone der Plattform: Schweiz / `Europe/Zurich`
- Oberfläche zunächst nur Deutsch
- technische Vorbereitung auf Mehrsprachigkeit
- keine konfigurierbaren Mandanten-Zeitzonen in Version 1

---

# 3. Technischer Rahmen

Codex darf ein geeignetes PHP-Framework selbst auswählen und muss die Wahl dokumentieren und begründen.

Zwingende Rahmenbedingungen:

- PHP 8.x
- MariaDB
- Composer
- Apache
- klassisches Shared Hosting wie Cyon
- keine dauerhaft laufenden Node-, WebSocket- oder sonstigen Serverprozesse
- Cronjobs müssen über klassisches Hosting ausführbar sein
- keine proprietären oder kostenpflichtigen Pflichtdienste
- nur kostenlose Open-Source-Bibliotheken
- leichtgewichtiges Frontend
- kein schweres SPA wie React/Vue als Hauptarchitektur
- serverseitiges Rendering bevorzugt
- gezieltes JavaScript für Interaktivität
- Live-Aktualisierung über effizientes Polling
- aktuelle Versionen von Chrome, Firefox, Edge und Safari
- vollständig responsive für Desktop, Tablet und Smartphone

Codex darf CSS-/UI-Framework und leichtgewichtige JS-Hilfsmittel selbst wählen und die Entscheidung begründen.

---

# 4. Hosting- und Verzeichnisarchitektur

Nur `public/` darf Web-DocumentRoot sein.

Interne Bereiche müssen außerhalb des öffentlich erreichbaren Webroots liegen, insbesondere:

- Konfiguration
- Secrets
- Logs
- temporäre Dateien
- Exporte
- Benutzeruploads
- erzeugte interne Dokumente

Uploads dürfen nicht direkt über statische öffentliche Pfade erreichbar sein.

Dateien werden über kontrollierte Anwendungsrouten mit Berechtigungsprüfung ausgeliefert.

Datenbank-Credentials oder andere Secrets dürfen niemals in Git committed werden.

Konfiguration soll über nicht versionierte Environment-/Konfigurationswerte erfolgen.

---

# 5. Grundarchitektur: SaaS-Kern und Sportmodule

Die Anwendung besteht aus zwei klar getrennten Ebenen.

## 5.1 SaaS-Kern

Der Kern ist verantwortlich für:

- Mandanten
- Benutzer
- Rollen
- Authentifizierung
- 2FA
- Veranstaltungen
- Abonnements
- Lizenzen
- Sportmodule
- Rechnungen
- Zahlungen
- Gutscheine
- Benachrichtigungen
- Audit
- Teilnehmerstamm
- Mannschaftsstamm
- externe Organisationen
- PDF-Grunddienste
- CSV-Grunddienste
- Vorlagen
- FAQ
- rechtliche Texte
- Cronjobs
- Systemstatus
- Wartung
- Plattformadministration

Der SaaS-Kern darf keine sportartspezifische Wettkampflogik enthalten.

## 5.2 Sportmodule

Sportmodule sind unabhängige fachliche Module.

Jedes Sportmodul muss sich über einen klar definierten Modulvertrag beim Kern registrieren können.

Ein Modul soll eigene Komponenten mitbringen dürfen:

- Routen
- Controller
- Views
- Services
- Migrationen
- Navigation
- Berechtigungen
- Veranstaltungskonfiguration
- Startbedingungen
- Abschlussbedingungen
- PDFs
- Fachlogik

Neue Sportmodule sollen später möglichst ergänzt werden können, ohne bestehende Kernlogik anzupassen.

Sportmodule dürfen gemeinsame Kernservices verwenden.

Sportmodule dürfen nicht voneinander abhängig sein.

Auch komplexe Module wie ein zukünftiges Leichtathletik-Meeting müssen ihre eigene Fachlogik vollständig selbst enthalten.

---

# 6. Datenbank und Mandantentrennung

Version 1 verwendet **eine gemeinsame MariaDB-Datenbank**.

Mandantendaten werden konsequent über `tenant_id` getrennt.

Jede mandantenbezogene Datenbankoperation muss den aktiven Mandanten berücksichtigen.

Ein Benutzer aus Mandant A darf niemals:

- Daten von Mandant B lesen
- IDs von Mandant B erraten und aufrufen
- Datensätze von Mandant B verändern
- Dateien von Mandant B herunterladen
- APIs oder Polling-Endpunkte von Mandant B verwenden

Codex muss automatisierte **Mandanten-Isolationstests** implementieren.

Diese Tests sind verpflichtend.

Eine separate Archivdatenbank ist nicht Bestandteil von Version 1.

Archivierte Veranstaltungen verbleiben in derselben Datenbank und werden über Status getrennt.

---

# 7. Mandantenmodell

Ein Mandant entspricht genau:

- einem Verein
- einer Organisation

Ein Verein besitzt:

- genau einen Owner
- beliebig viele Vereinsbenutzer
- Sportmodullizenzen
- Veranstaltungen
- Stammdaten
- Abrechnung
- Einstellungen

Ein Benutzerkonto gehört genau einem Mandanten.

Die gleiche E-Mail-Adresse darf in mehreren Vereinen verwendet werden.

Daher gilt:

`UNIQUE (tenant_id, email)`

und nicht globale E-Mail-Eindeutigkeit.

---

# 8. Mandanten-URL und Login

Jeder Mandant erhält einen eindeutigen Slug.

Beispiel:

`/v/tv-meilen/login`

Der Slug wird bei der Registrierung aus dem Vereinsnamen vorgeschlagen.

Der zukünftige Owner darf ihn vor Aktivierung ändern.

Nach Freischaltung ist der Slug unveränderlich.

Der Vereinsname muss ebenfalls plattformweit eindeutig sein.

Der Vereinsname darf später geändert werden, sofern der neue Name eindeutig ist.

Der Slug bleibt dabei unverändert.

---

# 9. Rollenmodell

## 9.1 Vereinsrollen

Es gibt:

1. Owner
2. Administrator
3. Veranstaltungsleiter
4. Datenerfassung
5. Nur Lesen

## Owner

Besitzt alle Vereinsrechte.

Exklusive Rechte:

- automatische Verlängerung ändern
- Zahlungsart ändern
- Owner übertragen
- vollständigen Vereinsdatenexport herunterladen
- besonders kritische Mandantenaktionen

## Administrator

Hat Zugriff auf alle Veranstaltungen.

Darf:

- Benutzer verwalten
- Veranstaltungen verwalten
- Zusatzmodule buchen
- Hauptabo verlängern
- Rechnungsdaten ändern
- Rechnungen ansehen
- Abrechnung verwalten

Darf nicht:

- automatische Verlängerung ändern
- Zahlungsart ändern
- Owner-Rolle selbst übernehmen

## Veranstaltungsleiter

Kann bestimmten Veranstaltungen zugeordnet werden.

Innerhalb dieser Veranstaltungen:

- weitreichende Konfiguration
- Teilnehmer-/Mannschaftsverwaltung
- Wettkampfdurchführung
- Resultate
- Freigaben
- Abschluss

Kein Zugriff auf:

- Vereinsabrechnung
- Abos
- andere nicht zugewiesene Veranstaltungen
- allgemeine Vereinsadministration

Jede Veranstaltung besitzt genau einen **primären Veranstaltungsleiter**.

Weitere Benutzer dürfen Veranstaltungsleiterrechte für dieselbe Veranstaltung haben.

Owner/Admin können den primären Leiter ändern.

Alter und neuer Leiter werden intern benachrichtigt.

## Datenerfassung

Darf operative Wettkampfdaten bearbeiten:

- Teilnehmer
- Zeiten
- Resultate
- Spielstände

Darf nicht:

- Veranstaltungsgrundkonfiguration ändern
- Benutzerrechte ändern
- Veranstaltungen abschließen
- Abo-/Billing-Funktionen verwenden

Keine zusätzlichen Unterrechte in Version 1.

## Nur Lesen

Nur lesender Zugriff auf zugewiesene Veranstaltungen.

Keine Änderungen.

---

# 10. Veranstaltungsspezifische Rollen

Ein Benutzer kann bei verschiedenen Veranstaltungen unterschiedliche Rollen besitzen.

Beispiel:

- Anlass A: Veranstaltungsleiter
- Anlass B: Datenerfassung

Owner und Administratoren besitzen automatisch Zugriff auf alle Veranstaltungen.

Andere Rollen benötigen Veranstaltungszuweisungen.

---

# 11. Plattformadministratoren

Version 1 kennt genau eine Plattformrolle:

`Platform Admin`

Es dürfen mehrere Plattformadministratoren existieren.

Bestehende Plattformadministratoren können weitere per E-Mail einladen.

Der letzte verbleibende Plattformadministrator darf weder gelöscht noch deaktiviert werden.

Ein gesperrter Plattformadministrator darf nicht selbst entsperrt werden.

Ein zweiter Plattformadministrator muss ihn entsperren.

Für den Sonderfall, dass nur ein Plattformadministrator existiert, muss ein separates Notfall-Recovery-Verfahren existieren.

---

# 12. Support-/Impersonation-Modus

Plattformadministratoren dürfen für Supportzwecke in einen Mandanten wechseln.

Voraussetzungen:

- Verein darf Supportzugriff in Mandanteneinstellungen deaktivieren
- vor jedem Zugriff Pflichtbegründung
- vollständige Protokollierung
- Zugriff im Plattform-Audit
- Zugriff im Vereins-Audit

Während des Supportzugriffs muss dauerhaft ein auffälliger Banner sichtbar sein:

- Supportmodus aktiv
- aktueller Verein
- Rückkehr zur Plattformadministration

Normale Vereinsbenutzer dürfen aus der Plattformadmin-Oberfläche nicht direkt bearbeitet werden.

Dafür ist Impersonation erforderlich.

---

# 13. Authentifizierung

Login erfolgt über:

- Mandanten-Slug
- E-Mail-Adresse
- Passwort

E-Mail-Adresse ist innerhalb eines Mandanten eindeutig.

Passwortregeln:

- mindestens 12 Zeichen
- offensichtlich schwache/häufig verwendete Passwörter ablehnen

Passwort vergessen:

- Reset per E-Mail
- Token einmal verwendbar
- 60 Minuten gültig

Einladungslinks:

- 7 Tage gültig
- danach neue Einladung notwendig

---

# 14. Zwei-Faktor-Authentifizierung

Verpflichtend für:

- Plattformadministratoren
- Vereins-Owner
- Vereinsadministratoren
- sonstige später als sensitiv deklarierte Konten

Optional aktivierbar für:

- Veranstaltungsleiter
- Datenerfassung
- Nur Lesen

TOTP über übliche Authenticator-App vorsehen.

Sensible Aktionen verlangen zusätzliche Re-Authentifizierung.

---

# 15. Sessionregeln

Inaktivitätszeit:

- normale Benutzer: 8 Stunden
- sensible Rollen: 2 Stunden

Option:

`Auf diesem Gerät angemeldet bleiben`

maximal 30 Tage.

Ungültig nach:

- Passwortänderung
- Änderung des 2FA-Status
- Kontosperre

Keine Geräte-/Sessionübersicht in Version 1.

---

# 16. Login-Sperren

Nach mehreren fehlgeschlagenen Loginversuchen Konto temporär sperren.

Owner/Admin können normale Benutzer des eigenen Vereins entsperren.

Ein gesperrter Owner kann über sicheren E-Mail-Wiederherstellungsprozess selbst entsperren.

Plattformadmins benötigen zweiten Plattformadmin.

Alle Vorgänge auditieren.

---

# 17. Benutzerverwaltung

Neue Vereinsbenutzer werden per E-Mail eingeladen.

Der Benutzer:

- bestätigt die Einladung
- setzt eigenes Passwort
- aktiviert ggf. 2FA

Benutzer können:

- temporär deaktiviert
- wieder aktiviert
- vollständig gelöscht werden

Bei vollständiger Löschung:

- möglichst wenig personenbezogene Daten erhalten
- Audit-Verweise anonymisieren
- historische Vorgänge müssen nachvollziehbar bleiben

Letzter Owner oder letzter Administrator darf nicht entfernt werden, solange kein Ersatz existiert.

---

# 18. Owner-Übertragung

Owner kann Rolle an bestehenden Vereinsbenutzer übertragen.

Vorgang:

1. Owner initiiert
2. erneute Authentifizierung
3. neuer Owner erhält Bestätigung per E-Mail
4. erst nach Bestätigung wirksam
5. alter Owner wird Administrator
6. Audit-Eintrag

---

# 19. Vereinsregistrierung

Registrierung erfolgt über mehrstufigen Wizard:

1. Vereinsdaten
2. Owner-Daten
3. Mandanten-Slug
4. Test-Sportmodul
5. AGB/Datenschutz
6. E-Mail-Bestätigung

Probeabo startet erst nach E-Mail-Bestätigung.

Startzeit:

exakter Zeitpunkt der E-Mail-Bestätigung.

Dauer:

14 × 24 Stunden.

Pro E-Mail nur eine offene unbestätigte Vereinsregistrierung.

Nicht bestätigte Registrierung:

- nach 7 Tagen automatisch löschen
- 2 Tage vor Löschung Erinnerungs-E-Mail
- Bestätigungsmail kann erneut versendet werden
- nach Löschung darf E-Mail neu registrieren

---

# 20. Probeabo

Dauer: 14 Tage.

Bei Beginn wählt Verein genau **ein einfaches Sportmodul**.

Auswahl während Probezeit unveränderlich.

Probeabo ist funktional eingeschränkt.

Die konkreten Trial-Limits müssen zentral konfigurierbar sein.

Nach Ablauf:

- Account sperren
- Daten 30 Tage aufbewahren
- automatische Erinnerungs-/Löschwarnungen
- danach Daten löschen

Während dieser 30 Tage keine Selbstreaktivierung.

Reaktivierung nur durch Plattformadministrator.

Reaktivierung erfordert:

- Pflichtbegründung
- Dauer
- Entscheidung kostenpflichtiges Abo oder temporärer Zugang

Temporäre Dauer frei definierbar.

Temporärer Zugang aktiviert nur das zuletzt verwendete Sportmodul.

Während temporärem Zugang darf Verein selbst Jahresabo kaufen.

---

# 21. Wechsel vom Probeabo zum Jahresabo

Beim Kauf des Jahresabos darf der Verein ein anderes einfaches Modul auswählen.

Das Probe-Modul ist nicht bindend.

Wird ein anderes Modul gewählt:

- Daten des Probe-Moduls nach Warnung löschen

Gleiches gilt bei späterer Reaktivierung nach Probeablauf.

---

# 22. Jahresabo-Modell

Hauptabo:

- Laufzeit 12 Monate
- Start ab Freischaltung
- nicht kalenderjahrgebunden

Ein Hauptabo enthält genau **eine einfache Veranstaltungsart**.

Innerhalb des lizenzierten Moduls darf der Verein beliebig viele Veranstaltungen erstellen.

Version 1 einfache Module:

- Laufanlass
- Fussballturnier

Spätere komplexe Module wie Leichtathletik sollen höherpreisige Jahresmodule sein.

---

# 23. Zusatzmodule

Weitere Sportmodule können kostenpflichtig hinzugebucht werden.

Keine anteilige Preisberechnung.

Ein nachträglich gekauftes Modul läuft nur bis zum Ende des bestehenden Hauptabos.

Beispiel:

Hauptabo bis 31.12.

Zusatzmodul im Juli gekauft:

- voller Modulpreis
- endet ebenfalls 31.12.

Zusatzmodule können während laufender Periode nicht vorzeitig beendet werden.

Bei Hauptabo-Verlängerung werden Zusatzmodule standardmäßig mitverlängert, sofern Verein sie nicht abwählt.

---

# 24. Hauptmodulwechsel

Das im Hauptabo enthaltene Modul kann nur zur nächsten Verlängerung gewechselt werden.

Nicht während einer laufenden Jahresperiode.

---

# 25. Nicht verlängerte Zusatzmodule

Bei Nichtverlängerung eines Zusatzmoduls:

Verein wird gefragt, ob Daten aufbewahrt werden sollen.

Aufbewahrung:

- kostenlos
- frei wählbar
- maximal 365 Tage
- niemals länger als Existenz des Hauptaccounts

Während Aufbewahrung:

- Modul gesperrt
- vorhandene Ranglisten/PDFs lesbar
- normale Bearbeitung nicht möglich

---

# 26. Ablauf bezahltes Abo

Nach Ende eines nicht verlängerten bezahlten Hauptabos:

- Account sperren
- Daten 90 Tage aufbewahren
- Warnungen per E-Mail
- letzte Warnung 7 Tage vor Löschung
- danach automatische Löschung per Cron

Während der 90 Tage:

Verein darf selbst verlängern und reaktivieren.

---

# 27. Kündigung

Verein kann Jahresabo jederzeit kündigen.

Nutzung bleibt bis Ende der bereits bezahlten Laufzeit vollständig erhalten.

---

# 28. Automatische Verlängerung

Nur Owner darf automatische Verlängerung aktivieren/deaktivieren.

Administrator darf Status sehen.

Vor Verlängerung:

- 30 Tage vorher verbindliche Vorankündigung
- vollständige Auflistung von Hauptabo und Zusatzmodulen
- aktuelle Preise
- MwSt.
- Gesamtbetrag

Bis 7 Tage vor Ablauf:

- Zusatzmodule abwählbar
- automatische Verlängerung deaktivierbar

---

# 29. Zahlung per Rechnung

Rechnung ist vollwertige Zahlungsart.

Bei Neubuchung:

- sofortige Freischaltung
- Zahlungseingang später manuell verbuchen

Zahlungsfrist:

30 Tage.

Danach:

30 Tage Mahnfrist.

Version 1:

keine Mahngebühren.

Nach Ablauf beider Fristen:

gesamten Vereinsaccount sperren.

Entsperrung automatisch, sobald keine sperrrelevante überfällige Rechnung mehr offen ist.

---

# 30. Verlängerung per Rechnung

Verlängerungsrechnung 30 Tage vor Ende erzeugen.

Abo wird am bisherigen Enddatum automatisch um ein Jahr verlängert, auch wenn Rechnung noch offen ist.

Erst nach Zahlungs- und Mahnfrist Account sperren.

---

# 31. Online-Zahlungen

Zahlungslogik muss providerunabhängig abstrahiert werden.

Codex darf keinen konkreten kommerziellen Provider fest verdrahten.

Benötigt wird eine Payment-Provider-Schnittstelle für:

- Zahlung starten
- Zahlungsstatus
- wiederkehrende Zahlung
- Webhook-Verarbeitung
- Refund/Gutschrift-relevante Daten
- Provider-Referenzen

Version 1 darf keinen erfundenen „Fake-Live-Provider“ als echte Zahlungslösung darstellen.

Rechnungszahlung muss vollständig funktionsfähig sein.

Online-Zahlungsarchitektur muss vorbereitet sein.

Wenn später ein Provider gewählt wird, muss ein Adapter ergänzt werden können.

---

# 32. Fehlgeschlagene automatische Online-Zahlung

Wenn automatische Verlängerung online fehlschlägt:

- automatisch Rechnung erzeugen
- 30 Tage Zahlungsfrist
- Abo trotzdem zum regulären Datum verlängern
- E-Mail an Verein
- interne Benachrichtigung

---

# 33. Preise

Preise werden ausschließlich über Plattformadministration gepflegt.

Keine Preise fest im Code.

Preiskategorien mindestens:

- Hauptabo
- einfache Sportmodule
- spätere komplexe Sportmodule

Preisänderungen:

- nicht rückwirkend
- laufende Abos behalten Preis bis Laufzeitende
- neue Preise für Neubuchungen und nächste Verlängerung

Preishistorie führen.

---

# 34. Preisänderung vor Verlängerung

Wenn Preis geändert wurde und automatische Verlängerung aktiv ist:

mindestens 30 Tage vor Verlängerung über neuen Preis informieren.

---

# 35. Gutscheine

Version 1 unterstützt Gutscheine.

Regeln:

- ausschließlich prozentual
- keine festen CHF-Beträge
- normale Gutscheine nur für erste kostenpflichtige Buchung
- pro Gutschein genau ein Code
- einmal verwendbar
- nicht an E-Mail/Verein vorgebunden
- erster gültiger Einlöser verbraucht Code
- maximal ein Gutschein pro Buchung
- gilt auf gesamte erste Buchung

Rechnung muss Rabatt separat ausweisen.

---

# 36. Kulanzgutscheine

Plattformadmin darf Kulanzgutscheine vergeben.

Diese:

- sind prozentual
- können einem konkreten Verein zugeordnet werden
- gelten bis zur nächsten Verlängerung
- werden automatisch angewendet
- funktionieren bei manueller und automatischer Verlängerung
- maximal ein Gutschein pro Verlängerung
- gelten nur auf konkret betroffene Module

---

# 37. Kulanzverlängerungen

Plattformadmin darf bei Störungen:

- einzelnes Modul verlängern
- gesamtes Hauptabo verlängern

Pflicht:

- Grund
- Dauer
- Administrator
- Audit

---

# 38. Rechnungen

Automatisch PDF-Rechnung erzeugen.

Rechnungsnummer jährlich neu:

`2027-000001`

Rechnung nach Ausstellung unveränderbar.

Korrektur nur über:

- Storno/Gutschrift
- ggf. neue Rechnung

Gutschriften eigene Nummernserie:

`GS-2027-000001`

---

# 39. Schweizer QR-Rechnung

Bei Zahlungsart Rechnung:

vollständige Schweizer QR-Rechnung erzeugen.

Bank-/QR-Grunddaten zentral in Plattform-Einstellungen konfigurierbar.

---

# 40. Mehrwertsteuer

MwSt.-Satz zentral konfigurierbar.

Rechnung weist MwSt. separat aus.

Historisierung berücksichtigen.

Eine ausgestellte Rechnung behält immer:

- damaligen MwSt.-Satz
- damalige Preise
- damaligen Rabatt
- damalige Zahlungsfrist
- damaligen Endbetrag

---

# 41. Rechnungsstammdaten

Vor kostenpflichtiger Buchung Pflicht:

- Vereinsname
- Strasse/Hausnummer
- PLZ
- Ort
- Rechnungs-E-Mail
- verantwortliche Kontaktperson

Optional:

- abweichende Rechnungsadresse
- Rechnungsempfänger/Ansprechpartner
- Bestellnummer
- Kostenstelle
- Rechnungsreferenz

---

# 42. Rechnungs-E-Mail

Owner und Administrator dürfen Rechnungs-E-Mail ändern.

Neue Rechnungs-E-Mail wird erst nach E-Mail-Bestätigung aktiv.

Neue Rechnungen und Gutschriften:

- im Account archivieren
- PDF-Anhang per E-Mail senden

Fehlschlagender Mailversand:

- Rechnung bleibt gültig
- Fehler protokollieren
- Owner/Admin und Plattformadmin informieren

Kein manueller „erneut senden“-Button in Version 1.

---

# 43. Abrechnungsrechte

Owner und Administrator:

- Rechnungsarchiv
- Zahlungsstatus
- PDFs
- Rechnungsdaten
- Aboübersicht

Veranstaltungsleiter, Datenerfassung und Nur Lesen:

kein Billing-Zugriff.

---

# 44. Buchhaltungsexport

Plattformadmin kann generischen CSV-Export erzeugen für:

- Rechnungen
- Gutschriften
- Zahlungseingänge

Filter:

- freier Zeitraum
- Monat
- Quartal
- Kalenderjahr

Keine spezifische Bexio-/Abacus-/Banana-Anbindung in Version 1.

---

# 45. Gesetzliche Aufbewahrung

Rechnungen, Gutschriften und buchhaltungsrelevante Zahlungsdaten:

10 Jahre.

Auch nach Mandantenlöschung.

Plattform-Audit:

10 Jahre.

Vereins-Audit:

nur solange Mandant existiert.

---

# 46. Veranstaltungen – Grundmodell

Jede Veranstaltung besitzt sportartenunabhängig:

- Mandant
- Sportmodul
- Name
- Startdatum
- Enddatum
- Veranstaltungsort
- primärer Veranstaltungsleiter
- Status
- interne Notizen
- Zeitstempel

Mehrtagesevents sind möglich.

---

# 47. Veranstaltungsstatus

Status:

1. Entwurf
2. Vorbereitung
3. Laufend
4. Abgeschlossen
5. Abgebrochen
6. Archiviert

Module dürfen Bedingungen definieren für:

- Wechsel zu Laufend
- Wechsel zu Abgeschlossen

Abbruch:

- nur Owner/Admin/Veranstaltungsleiter
- Pflicht-Freitext für Grund
- zweite Sicherheitsbestätigung
- endgültig
- keine Rückkehr zu Laufend
- Resultate bleiben intern erhalten
- keine offiziellen Resultat-/Ranglisten-PDFs aus abgebrochenen Events

Archivierung möglich aus:

- Abgeschlossen
- Abgebrochen

Abgebrochene Veranstaltung muss dokumentierten Grund haben.

Archiviert ist endgültig.

Keine Rückkehr zu Abgeschlossen/Laufend.

---

# 48. Abschluss

Abschluss verlangt ausdrückliche Bestätigung.

Nach Abschluss:

- Grundkonfiguration gesperrt
- Kategorien gesperrt
- operative Resultate grundsätzlich gesperrt

Owner/Admin dürfen nachträgliche Resultatkorrektur mit Pflichtbegründung durchführen.

Dabei:

- Audit alter Wert
- Audit neuer Wert
- Benutzer
- Zeitpunkt
- Ranglisten neu berechnen
- bisherige End-PDFs als veraltet markieren
- interne Benachrichtigung an Owner/Admin/primären Veranstaltungsleiter

Nach neuer Freigabe:

- neue PDF-Version erzeugen
- alte ersetzte PDF löschen
- Versionshistorie als Metadaten/Audit behalten

Version direkt im PDF:

`Version 2 – erstellt am ...`

---

# 49. Archivierung

Archivierte Events:

- vollständig lesbar
- nicht mehr bearbeitbar
- Teilnehmer sichtbar
- Resultate sichtbar
- Enddokumente sichtbar

Beim Archivieren:

- ältere Freigabe-Snapshots löschen
- vorher deutliche Information anzeigen
- letzte freigegebene Endstände dauerhaft behalten

Archivierte Veranstaltung darf durch Owner/Admin endgültig gelöscht werden.

---

# 50. Löschen von Veranstaltungen

Owner/Admin dürfen Veranstaltung endgültig löschen.

Kein Papierkorb.

Vorher:

- klare Warnung
- ausdrückliche Sicherheitsbestätigung

Nach Löschung:

- Detaildaten entfernen
- personenbezogene Daten entfernen
- anonymisierte Auditdaten behalten
- Veranstaltungsname darf im Audit erhalten bleiben

---

# 51. Veranstaltung duplizieren

Eine Veranstaltung kann strukturell dupliziert werden.

Übernehmen:

- Konfiguration
- Kategorien
- sportmodulspezifische Struktur

Nicht übernehmen:

- Teilnehmer
- Mannschaften
- Zeiten
- Spielstände
- Resultate
- operative Daten

---

# 52. Veranstaltungsvorlagen

Es gibt:

1. globale Plattformvorlagen
2. vereinseigene Vorlagen

Globale Vorlagen:

- Plattformadmin verwaltet
- versioniert
- deaktivierbar
- verwendete Vorlagen nicht endgültig löschen
- deaktivierte Vorlagen für Vereine unsichtbar

Vereinseigene Vorlagen:

- Owner/Admin/Veranstaltungsleiter dürfen erstellen/ändern/löschen
- für alle berechtigten Vereinsbenutzer sichtbar
- keine Versionierung in Version 1

Bestehende Veranstaltungen ändern sich niemals durch spätere Vorlagenänderungen.

---

# 53. Modul-Standardwerte

Plattformadmin kann pro Sportmodul globale Standardwerte pflegen.

Beispiele Lauf:

- 2 Qualifikationsläufe
- 3 Finalisten

Beispiele Fussball:

- Sieg 3 Punkte
- Unentschieden 1
- Niederlage 0
- Forfait 3:0

Änderungen gelten nur für neue Veranstaltungen.

Bei Vorlage:

1. Vorlagenwert verwenden
2. fehlende Werte mit aktuellen globalen Standards ergänzen

---

# 54. Teilnehmermodell

Teilnehmer müssen sportartenneutral modelliert werden.

Stammdaten Person:

- Vorname
- Nachname
- Geburtsdatum bzw. für fachliche Module ableitbares Geburtsjahr
- Geschlecht/Wertungsgeschlecht
- optional externe Organisation
- Ort
- Land/Nationalität optional
- E-Mail optional
- Telefon optional
- externe ID optional
- interne Notizen
- Aktivstatus

Sportartspezifische Felder gehören nicht in die Personentabelle.

Beispiel:

Startnummer, Gewichtsklasse, Kategorie oder sportartspezifische Eigenschaften gehören zur Veranstaltungsteilnahme.

---

# 55. Optionaler Teilnehmerstamm

In Mandanteneinstellungen aktivierbar.

Wenn deaktiviert:

Teilnehmer nur pro Veranstaltung.

Wenn aktiviert:

Teilnehmer als wiederverwendbare Vereinsstammdaten.

Teilnehmer kann aus Stamm gelöscht werden.

Historische Resultate dürfen erhalten bleiben und müssen dann anonymisiert werden können.

---

# 56. Mannschaften

Grundfelder:

- Teamnummer innerhalb Veranstaltung
- Teamname
- externe Organisation
- Kategorie
- Kontaktperson
- Kontakt-E-Mail
- Kontakttelefon
- interne Notizen

Kein Teamlogo in Version 1.

Optionaler Mannschaftsstamm analog Teilnehmerstamm.

Mannschaft kann aus Stamm gelöscht werden, historische Resultate bleiben erhalten.

---

# 57. Teilnehmer-Team-Beziehung

Version 1:

Ein Einzelteilnehmer kann innerhalb derselben Veranstaltung maximal **einer Mannschaft** zugeordnet sein.

Datenmodell möglichst so wählen, dass spätere Erweiterung nicht unnötig erschwert wird.

---

# 58. Externe Organisationen

Mandanten können Stamm externer Organisationen verwalten.

Typen:

- Verein
- Schule
- Firma
- Verband
- Sonstige

Teilnehmer und Teams können solchen Organisationen zugeordnet werden.

---

# 59. CSV-Import

Version 1 nur CSV.

Keine Excel-Dateien.

Keine interaktive Spaltenzuordnung.

Jedes Modul verwendet feste CSV-Vorlage.

CSV-Vorlagen müssen zum Download angeboten werden.

Importprozess:

1. Datei hochladen
2. validieren
3. Vorschau
4. Duplikate anzeigen
5. Fehler anzeigen
6. Kategorien anzeigen
7. Benutzer bestätigt
8. gültige Zeilen importieren
9. fehlerhafte Zeilen überspringen
10. Fehlerbericht erzeugen

Fehlerbericht als CSV downloadbar.

---

# 60. Lauf-CSV

Feste Spalten:

- Vorname
- Nachname
- Geburtsjahr
- Geschlecht
- Ort
- Schulklasse optional
- externe ID optional

Duplikatprüfung:

Vorname + Nachname + Geburtsjahr.

Bei möglichem Duplikat:

- überspringen
- trotzdem neu anlegen
- bestehenden Teilnehmer aktualisieren

---

# 61. Gleichzeitiges Arbeiten

Mehrere Benutzer dürfen gleichzeitig in derselben Veranstaltung arbeiten.

Änderungen anderer Arbeitsplätze sollen automatisch sichtbar werden.

Technik:

effizientes Polling.

Keine WebSockets erforderlich.

---

# 62. Konflikterkennung

Wenn zwei Benutzer denselben Datensatz parallel bearbeiten:

nicht still „last write wins“.

Optimistic Concurrency verwenden, z. B. über:

- `updated_at`
- Versionsnummer
- Revision

Bei Konflikt:

- Warnung anzeigen
- Überschreiben verhindern
- Benutzer Entscheidung ermöglichen

---

# 63. Audit-Log

Wichtige Änderungen speichern:

- Mandant
- Veranstaltung
- Benutzer
- Aktion
- Entity
- alter Wert
- neuer Wert
- Zeitpunkt

Owner/Admin können Vereins-Audit sehen.

Filter:

- Veranstaltung
- Benutzer
- Zeitraum
- Aktion

Audit dient nur Nachvollziehbarkeit.

Keine Undo-Funktion.

---

# 64. Plattform-Audit

Erfasst insbesondere:

- Registrierungen
- Accountstatus
- Aboänderungen
- Modulbuchungen
- Zahlungen
- Rechnungsstatus
- Plattform-Einstellungen
- Wartung
- Cronjobs
- Supportzugriffe
- Löschjobs
- Kulanz
- Plattformadmin-Aktionen

---

# 65. Supporttransparenz

Supportzugriffe erscheinen auch im Vereins-Audit mit:

- Zeitpunkt
- Plattformadministrator
- Begründung

---

# 66. Benachrichtigungscenter

Internes Notification Center.

Nur wichtige/handlungsrelevante Meldungen.

Nicht jede Datenänderung.

Beispiele:

- Abo läuft ab
- Rechnung überfällig
- Account droht gesperrt zu werden
- Modul deaktiviert
- Wartung
- relevante nachträgliche Resultatkorrektur
- Leiterwechsel

Meldungen als gelesen markierbar.

---

# 67. E-Mail

Alle System-E-Mails über zentral konfigurierbares SMTP.

Absender:

zentraler Plattform-Absender.

Mandant darf eigene Reply-To-Adresse konfigurieren.

Neue Reply-To-Adresse erst nach Verifikation aktiv.

Owner kann festlegen, welche administrativen E-Mails zusätzlich an Vereinsadministratoren gehen.

---

# 68. UI-Design

Moderne, großzügige SaaS-Oberfläche.

Eigenschaften:

- klare Hierarchie
- Karten
- gute Abstände
- responsive
- touch-tauglich
- schnelle operative Masken
- Dark Mode
- Light Mode
- Einstellung pro Benutzer

Verein darf:

- Vereinslogo
- Vereinsname

anzeigen.

Grunddesign/Farbsystem bleibt zentral.

---

# 69. Navigation – SaaS-Bereich

Im normalen SaaS-Bereich:

globale Navigation als **Top-Menü**.

Typische Bereiche:

- Dashboard
- Veranstaltungen
- Stammdaten
- Benachrichtigungen
- Abrechnung
- Einstellungen

Rollenabhängig ausblenden.

---

# 70. Navigation – Sportmodul

Beim Öffnen eines konkreten Sportmoduls:

- globale Top-Navigation reduziert sich auf Hamburger-Menü
- Sportmodul übernimmt linke Navigation

Desktop:

linke Modulnavigation sichtbar.

Mobil:

Drawer/Hamburger.

Zusätzlich sichtbar:

- Verein
- Veranstaltung
- Sportmodul
- Breadcrumbs

Kein Direktwechsel zwischen Veranstaltungen im Modul.

Wechsel erfolgt über zentrale Veranstaltungsübersicht.

---

# 71. Veranstaltungsübersicht

Umschaltbar:

- Kartenansicht
- Tabellen-/Listenansicht

Benutzerpräferenz speichern.

Archivierte Veranstaltungen standardmäßig ausgeblendet.

Eigener Archivfilter/-bereich.

---

# 72. Vereins-Dashboard

Rollenabhängig mindestens:

- aktive Veranstaltungen
- kommende Veranstaltungen
- zuletzt bearbeitete Veranstaltungen
- wichtige Benachrichtigungen
- auslaufende Zugänge
- Abo-/Modulstatus für Owner/Admin
- offene Rechnungen für Owner/Admin

---

# 73. Plattformadmin-Oberfläche

Klar getrennt vom Vereinsbereich.

Eigene Navigation mit mindestens:

- Dashboard
- Vereine
- Abos/Module
- Rechnungen/Zahlungen
- Gutscheine/Kulanz
- Sportmodule
- globale Vorlagen
- FAQ/Rechtliches
- Systemstatus/Cronjobs
- Wartung
- Plattform-Einstellungen
- Audit

---

# 74. Plattform-Dashboard

Kennzahlen:

- aktive Vereine
- Probeabos
- auslaufende Abos
- gesperrte Accounts
- offene Rechnungen
- überfällige Rechnungen
- Umsatz

Umsätze analysieren nach:

- Monat
- Jahr
- Sportmodul

Zusätzlich aggregierte Nutzung:

- Veranstaltungen je Modul
- aktive Vereinsbenutzer
- Teilnehmer
- Mannschaften

Keine personenbezogenen Details in Plattformstatistiken.

Kein CSV-Export dieser allgemeinen Statistiken in Version 1.

---

# 75. Sportmodule verwalten

Sportmodule können durch Plattformadmin:

- aktiviert
- deaktiviert

werden.

Plattformweite Deaktivierung:

- blockiert Modul für alle Vereine
- Owner/Admin der betroffenen Vereine per E-Mail informieren
- zusätzlich interne Benachrichtigung
- optional voraussichtliches Enddatum angeben

Modul wird trotz temporärer technischer Deaktivierung regulär verlängert/verrechnet.

---

# 76. Lizenzprüfung

Nicht lizenzierte Module:

- Navigation ausblenden
- serverseitig direkten URL-Zugriff blockieren

Bei direktem Zugriff:

neutrale Meldung:

`Zugriff verweigert`

Kein Upselling auf dieser Fehlerseite.

---

# 77. Rechtliche Inhalte

Zentral verwaltbar:

- Impressum
- Datenschutz
- AGB

Plattformadmin kann Texte bearbeiten und veröffentlichen.

Rechtliche Texte müssen versioniert werden.

Alte Versionen unveränderlich aufbewahren.

Bei Registrierung/erster Buchung:

Owner akzeptiert aktuelle AGB + Datenschutz.

Speichern:

- Version
- Zeitpunkt
- Benutzer

Bei wesentlicher Änderung:

Owner muss beim nächsten Login neue Version akzeptieren.

Nur Owner, nicht alle Vereinsbenutzer.

---

# 78. FAQ

Zentral verwaltbares FAQ-/Hilfesystem.

Plattformadmin:

- erstellen
- bearbeiten
- veröffentlichen
- deaktivieren

FAQ soll auch Hinweise enthalten, dass Teilnehmer-Datenschutz-/Fotoeinwilligungen in Verantwortung des Vereins liegen.

Keine kontextbezogenen Hilfe-Verknüpfungen auf jeder Seite in Version 1.

---

# 79. Plattform-Einstellungen

Zentral konfigurierbar, mindestens:

- Plattformname
- Plattformlogo
- Betreiberangaben
- Basis-Domain
- SMTP
- System-Absender
- MwSt.
- Rechnungspräfixe
- QR-Rechnungsdaten
- Probeabo-Dauer
- Aufbewahrungsfristen
- Zahlungsfrist
- Mahnfrist
- Cronintervalle
- Logo-Maximalauflösung
- Modul-Standardwerte

Änderungen:

- Audit
- alter Wert
- neuer Wert
- Administrator

---

# 80. Zeitgesteuerte Plattform-Einstellungen

Geschäftsregeln können ein `gültig ab`-Datum besitzen.

Historie aller Werte erhalten.

Neue Regeln gelten ab Stichtag auch für laufende noch nicht abgeschlossene Vorgänge.

Ausnahmen:

- ausgestellte Rechnungen bleiben eingefroren
- laufende Abo-Preise bleiben bis Laufzeitende eingefroren

---

# 81. Logos

Upload:

- PNG
- JPEG
- maximal 2 MB

Maximale Pixelabmessung zentral konfigurierbar.

Beim Upload:

- Dateityp serverseitig validieren
- Bild dekodieren/prüfen
- skalieren
- optimieren
- Original muss nicht zusätzlich erhalten bleiben

Kein SVG.

Kein Speicherlimit pro Mandant in Version 1.

---

# 82. PDFs

Gemeinsames Veranstaltungs-PDF-Layout:

- Vereinslogo
- Veranstaltungsname
- Datum
- dezentes Plattformbranding
- Versionsnummer, sofern versioniertes Enddokument
- Erstellungszeitpunkt

Plattformname/Branding darf nicht hart codiert sein.

---

# 83. Dokumentversionierung

Freigegebene Ranglisten/Spielpläne:

- Version
- Zeitstempel
- freigebender Benutzer
- unveränderlicher Snapshot

Beim Archivieren:

- ältere Snapshots löschen
- aktuelle Enddokumente erhalten

---

# 84. Vollständiger Mandantenexport

Owner kann vollständigen Export anfordern.

Erstellung asynchron über Cronjob.

Nach Fertigstellung:

- interne Benachrichtigung
- E-Mail

Format:

ZIP.

Enthält:

- strukturierte CSV-Daten
- exportierbare Veranstaltungsdaten
- PDFs
- Rechnungen
- Gutschriften
- Zahlungsinformationen

Enthält nicht:

- Vereins-Audit

Download:

- 7 Tage gültig
- danach automatisch Datei löschen
- vor Download erneute Authentifizierung
- bei 2FA auch 2FA

---

# 85. Backup

Keine Backup-/Restore-Funktion in der Anwendung.

Backups sind Aufgabe des Hosters.

---

# 86. Systemfehler

Technische Fehler:

- serverseitig loggen
- Referenz-ID erzeugen

Benutzer sieht:

- verständliche allgemeine Fehlermeldung
- Fehlerreferenz

Nicht anzeigen:

- Stacktrace
- SQL
- Pfade
- Secrets

---

# 87. Wartungsmodus

Plattformadmin kann:

- Wartung planen
- Startzeit angeben
- erwartete Dauer angeben
- Text angeben
- verschieben
- absagen
- sofortige Notfallwartung starten

Vereins-Owner/Admin:

- E-Mail
- interne Meldung

Während Wartung:

Vereinsbenutzer sehen Wartungsseite.

Plattformadministratoren können weiterarbeiten.

---

# 88. Systemstatus

Für Owner/Admin interne Systemstatusseite.

Mindestens:

- Wartungsstatus
- Mailversand
- Cronstatus
- allgemeiner Plattformstatus

---

# 89. Cronjobs

Cronjobs für mindestens:

- Trial-Ablauf
- Trial-Löschfristen
- Abo-Ablauf
- 90-Tage-Löschung
- Modularchivfristen
- Mahnungen
- Accountsperrung
- Rechnungserinnerungen
- Exportjobs
- Exportdatei-Bereinigung
- unbestätigte Registrierungen
- Erinnerungsmails
- geplante Wartung
- geplante Einstellungen

Codex definiert sinnvolle Standardintervalle.

Intervalle über Plattformadmin konfigurierbar.

---

# 90. Cronüberwachung

Speichern:

- Jobname
- Start
- Ende
- Status
- Fehlerreferenz

Plattformadmin sieht Historie.

Überfällige/fehlgeschlagene Jobs:

- im Adminbereich markieren
- E-Mail an Plattformadministratoren

Bestimmte Jobs manuell startbar.

Kritische Jobs:

- zusätzliche Bestätigung
- Audit

Löschjobs:

vor manueller Ausführung Vorschau der betroffenen Mandanten/Datenmengen.

Nach Löschung:

nicht personenbezogenes permanentes Löschprotokoll.

---

# 91. Web-Installer

Version 1 besitzt Web-Installer.

Mindestens:

- Systemvoraussetzungen prüfen
- Datenbankverbindung
- Migrationen
- Plattform-Grunddaten
- SMTP-Grundkonfiguration optional
- erster Plattformadmin

Nach erfolgreicher Installation:

Installer dauerhaft sperren.

Nur durch bewusste manuelle Freigabe auf Server-/Konfigurationsebene wieder aktivierbar.

---

# 92. Migrationen

Versioniertes Migrationssystem verwenden.

Keine Notwendigkeit, bei Updates Schema komplett neu zu importieren.

Manuelle Softwareupdates in Version 1.

Anschließend Migrationen kontrolliert ausführen.

---

# 93. Demo-/Seed-Daten

Optionale Demo-/Entwicklungs-Seeds vorsehen.

Nie automatisch in Produktion laden.

Demo-Logins klar als unsicher kennzeichnen.

---

# 94. SPORTMODUL 1 – LAUFANLASS

Das Repository:

`https://github.com/dvo001/Laufanlass`

ist fachliche Referenz.

Codex soll alle dort sinnvollen Funktionen prüfen und gegen diese Spezifikation abgleichen.

Nicht blind übernehmen.

---

# 95. Laufkategorien

Kategorien basieren auf:

- Jahrgangsbereich von/bis
- Geschlecht

Teilnehmer werden automatisch anhand:

- Geburtsjahr
- Geschlecht

zugeordnet.

Wenn keine Kategorie passt:

Teilnehmer erfassen, aber deutlich als:

`keiner Kategorie zugeordnet`

markieren.

Kategorieänderungen vor Abschluss:

Teilnehmer automatisch neu zuordnen.

Nach Veranstaltungsabschluss:

Kategorieänderungen sperren.

---

# 96. Qualifikationsläufe

Anzahl pro Veranstaltung frei konfigurierbar.

Standard:

2.

Wertung:

beste gültige Zeit aus allen Qualifikationsläufen.

---

# 97. Zeitauflösung

Pro Laufveranstaltung konfigurierbar.

Mindestens:

- Zehntelsekunden
- Hundertstelsekunden

Intern keine Floating-Point-Zeitwertung verwenden.

Zeitwerte als kleinste konfigurierte Einheit bzw. robuste Integer-/Zeitstruktur speichern.

---

# 98. Qualifikationsrang bei Gleichstand

Bei gleicher bester Qualifikationszeit:

zweitbeste Qualifikationszeit vergleichen.

Bei weiterem Gleichstand:

drittbeste usw.

Erst wenn alle vorhandenen Qualifikationsläufe identisch sind:

vollständiger Gleichstand.

---

# 99. Finalisten

Anzahl Finalisten pro Veranstaltung konfigurierbar.

Standard:

3 pro Kategorie.

System berechnet Finalistenvorschlag.

Wenn am letzten Finalplatz nach Vergleich aller Qualifikationszeiten vollständiger Gleichstand besteht:

alle weiterhin gleichplatzierten Teilnehmer ins Finale aufnehmen.

Finalistenvorschlag muss durch Veranstaltungsleiter bestätigt werden.

Nach Bestätigung:

Auswahl sperren.

Owner/Admin dürfen Bestätigung nur zurücksetzen, wenn noch keine Finalzeiten erfasst wurden.

Pflicht:

- Begründung
- Audit

---

# 100. Finalstartreihenfolge

Finalisten innerhalb Kategorie:

Qualifikationsrangfolge.

Schnellster startet zuerst.

Diese Reihenfolge gilt als tatsächliche Finalstartreihenfolge.

---

# 101. Finale

Eigene schnelle Eingabemaske.

Nach Kategorie gruppiert.

Finalwertung:

nur Finalzeit zählt.

Qualifikationszeit beeinflusst Schlussrang nicht mehr.

Gleiche Finalzeit:

geteilter Rang.

---

# 102. Veranstaltung ohne Finale

Wenn Finale deaktiviert:

Schlussrangliste direkt aus bester Qualifikationszeit.

---

# 103. Laufstatus

Unterstützen:

- gültige Zeit
- DNS
- DNF
- DSQ

Für Qualifikation und Finale, soweit fachlich sinnvoll.

---

# 104. Start-/Laufzettelnummer

Jeder Teilnehmer erhält innerhalb Veranstaltung eindeutige Nummer.

Automatisch vorschlagen.

Manuell änderbar.

---

# 105. Laufzettel-PDF

PDF pro Teilnehmer bzw. geeignete Sammeldruckform.

Enthält:

- Startnummer
- Teilnehmer
- Kategorie
- Felder für konfigurierten Qualifikationsläufe

---

# 106. Schnellzeiterfassung

Spezielle schnelle Eingabemaske.

Pro Teilnehmer für **jeden Qualifikationslauf ein eigenes Eingabefeld**.

Keine globale „Lauf 1/Lauf 2“-Umschaltung.

Keine unmittelbare Rangberechnung nach jeder Eingabe anzeigen.

---

# 107. Ranglistenberechnung

Qualifikationsrangliste wird beim Öffnen/Aktualisieren aus den aktuell gespeicherten Zeiten berechnet.

Keine manuelle Aktion „Qualifikation auswerten“ erforderlich.

---

# 108. Lauf-Ranglisten

Web:

- Qualifikationsranglisten
- Finalisten
- Schlussranglisten
- Gesamtansicht über alle Kategorien

PDF:

- Qualifikationsrangliste pro Kategorie
- Finalistenliste pro Kategorie
- Schlussrangliste pro Kategorie

Keine Gesamt-PDF über alle Kategorien notwendig.

---

# 109. SPORTMODUL 2 – FUSSBALLTURNIER

Ziel:

typisches Schweizer Grümpel-/Vereinsturnier.

Modul soll nicht auf professionelle Ligaverwaltung ausgerichtet werden.

---

# 110. Fussballkategorien

System bietet Schweizer Standardkategorien als Ausgangsvorlagen.

Aber jede Kategorie ist frei:

- Name
- Altersbereich
- männlich
- weiblich
- mixed
- offen
- maximale Kadergrösse
- Spieler gleichzeitig auf Feld
- Spielzeit
- Mindestpause
- Verlängerungsdauer
- Turniermodus
- Gruppengrösse

Keine Kategorien hart verdrahten.

---

# 111. Mannschaften

Pro Fussballveranstaltung:

- eindeutige Teamnummer
- Teamname

Teamnummer:

- automatisch fortlaufend
- manuell änderbar
- innerhalb gesamter Veranstaltung eindeutig

Kader mit Einzelteilnehmern möglich.

Spieler dürfen nach Turnierstart noch Team wechseln.

Änderung auditieren.

---

# 112. Gruppen

Kategorie kann mehrere Gruppen haben.

Veranstalter definiert gewünschte maximale Gruppengrösse.

System berechnet geeignete Gruppenanzahl.

Automatische möglichst gleichmässige Teamverteilung.

Ungleich grosse Gruppen zulässig.

Danach manuelle Anpassung möglich.

Keine Setzlisten in Version 1.

---

# 113. Gruppenspielmodus

Version 1:

jeder gegen jeden einmal.

Keine Hin-/Rückrunde.

---

# 114. Spielfelder

Mehrere Spielfelder.

Pro Feld:

- Name
- Verfügbarkeitszeiten
- temporäre Sperrzeiten

Parallelspiele unterstützen.

---

# 115. Spielplanerstellung

Automatisch generieren.

Berücksichtigen:

- Kategorien
- Gruppen
- jeder gegen jeden
- Anzahl Felder
- Feldverfügbarkeit
- Feldsperren
- Spielzeit
- Mindestpause je Team
- keine Team-Doppelbelegung
- keine Feld-Doppelbelegung

Machbarkeit prüfen.

Bei unmöglichem Plan:

konkrete Konflikte anzeigen.

---

# 116. Spielplanoptimierung

Drei Strategien:

1. Feldnutzung optimiert
2. Kompakt
3. Ausgeglichen

Standard:

**Feldnutzung optimiert**

Feldnutzung optimiert:

Spielfelder möglichst sinnvoll und kontinuierlich belegen.

Kompakt:

Gesamtdauer minimieren.

Ausgeglichen:

Pausen für Mannschaften möglichst fair verteilen.

Keine mathematisch perfekte Optimierung erzwingen, wenn dies die Implementierung unverhältnismässig komplex macht.

Aber Algorithmus muss deterministisch, nachvollziehbar und praktisch brauchbar sein.

---

# 117. Änderungen am Spielplan

Nach automatischer Generierung einzelne Spiele manuell verschiebbar:

- andere Uhrzeit
- anderes Feld

System warnt bei:

- Feldkonflikt
- Teamkonflikt
- Mindestpause unterschritten

Keine vollständige automatische Neuplanung nach späteren Änderungen.

Keine Funktion „Spiel fixieren“ in Version 1.

---

# 118. Mannschaftsausfall

Noch nicht gespielte Spiele einer zurückgezogenen Mannschaft gesammelt:

- absagen
- streichen

Bereits gespielte Resultate:

Veranstalter wählt:

- bestehen lassen
- aus Wertung entfernen

Restlicher Spielplan wird nicht automatisch komplett neu aufgebaut.

---

# 119. Forfait

Unterstützen.

Standardergebnis:

3:0.

Pro Turnier konfigurierbar.

Forfait zählt als regulärer Sieg/Niederlage nach konfigurierter Punktelogik.

---

# 120. Punkte

Standard:

- Sieg 3
- Unentschieden 1
- Niederlage 0

Pro Turnier konfigurierbar.

---

# 121. Tabellenrangfolge

Bei Punktgleichheit Standard:

1. Tordifferenz
2. mehr erzielte Tore
3. direkter Vergleich
4. Fairplay-/Strafpunkte
5. Losentscheid

---

# 122. Karten/Fairplay

Version 1 auf Mannschaftsebene.

Pro Spiel:

- Gelbe Karten
- Rote Karten
- daraus berechnete Fairplay-/Strafpunkte

Keine Karten auf Einzelspieler-Ebene erforderlich.

---

# 123. Losentscheid

Plattform kann Losentscheid auslösen.

Audit:

- Zeitpunkt
- Benutzer
- betroffene Teams
- Ergebnis

---

# 124. Finalqualifikation

Automatisch aus Gruppenranglisten.

Konfigurierbar z. B.:

- Gruppensieger
- Gruppenzweite
- beste Gruppendritte

Gruppenübergreifende Vergleiche unterstützen.

Bei ungleich grossen Gruppen:

optional Resultate gegen letztplatziertes Team größerer Gruppen aus Vergleichswertung herausrechnen.

Vergleich beste Gruppendritte:

1. Punkte
2. Tordifferenz
3. erzielte Tore
4. Fairplay
5. Losentscheid

---

# 125. Finalrunden

Mindestens:

- nur Final
- Halbfinal + Final
- Viertelfinal + Halbfinal + Final

Optional:

Spiel um Platz 3.

Jede Kategorie darf eigenen Turnier-/Finalmodus haben.

Keine Platzierungsspiele 5–8 in Version 1.

---

# 126. K.-o.-Unentschieden

Pro Kategorie/Turnier konfigurierbar:

- direkt Penaltyschiessen
- Verlängerung + Penaltyschiessen

Verlängerungsdauer konfigurierbar.

Reguläres Ergebnis separat vom Penaltyresultat speichern.

Darstellung z. B.:

`1:1, 5:4 n.P.`

---

# 127. Fussball-PDFs

Erzeugen:

- Spielplan
- Spielplan nach Kategorie
- Spielplan nach Spielfeld
- Spielplan nach Zeit
- Gruppenranglisten
- Finalrunde
- Schlussranglisten

---

# 128. Freigaben im Fussballmodul

Spielplan und Ranglisten besitzen:

- Entwurf
- freigegeben

Nur-Lesen-Benutzer sehen nur freigegebene Fassungen.

Freigabe kann zurückgenommen werden.

Bei jeder neuen Freigabe:

Snapshot/Version erzeugen.

---

# 129. Nicht in Fussball Version 1

Nicht implementieren:

- Schiedsrichterplanung
- Hin-/Rückrunde
- Platzierungsspiele 5–8
- Setzlisten
- CSV-Import Mannschaften/Kader
- automatische komplette Neuplanung nach Turnierstart
- Fixieren einzelner Spiele

---

# 130. Sprache / i18n

Version 1 enthält nur Deutsch.

Architektur muss Texte zentral übersetzbar machen.

Keine hart codierten UI-Texte quer durch Fachlogik.

Spätere Sprachen sollen ohne Architekturumbau möglich sein.

---

# 131. Was ausdrücklich NICHT Version 1 ist

Nicht implementieren:

- Teilnehmer-Selbstanmeldung
- öffentliche Ranglistenlinks
- öffentliche Veranstaltungsseiten
- öffentliche REST-API
- Excel-Import
- Offline-Modus
- WebSockets als Voraussetzung
- mobile Offline-Synchronisierung
- Schiedsrichterplanung
- Judo-Modul
- Leichtathletik-Modul
- Aufgaben-/Checklistenfunktion
- globale Vereinssuche
- Kontext-Hilfe pro Seite
- Archiv-Datenbank
- automatische Backup-/Restore-Funktion
- automatischer Software-Updater
- detaillierte Sitzungs-/Geräteverwaltung
- Massengutscheine
- Mahngebühren
- sportmodulübergreifende Abhängigkeiten
- schweres SPA-Frontend
- kostenpflichtige Cloud-Pflichtdienste
- vollständige automatisierte Testabdeckung aller Geschäftsregeln

---

# 132. Tests

Keine umfassende automatische Test-Suite für sämtliche Geschäftsregeln zwingend.

Zwingend automatisiert:

**Mandanten-Isolationstests.**

Vor jedem Milestone-Merge außerdem mindestens:

- PHP Syntaxprüfung
- Framework-/Static-Check soweit verfügbar
- Migrationsprüfung
- Mandanten-Isolationstests
- grundlegender Smoke-Test der neuen Funktion

---

# 133. Git-Workflow

Codex arbeitet nicht direkt dauerhaft auf `main`.

Für jeden Milestone:

1. eigenen Branch erstellen
2. implementieren
3. lokal prüfen
4. Tests ausführen
5. aussagekräftige Commits
6. Pull Request gegen `main`
7. Prüfergebnisse im PR dokumentieren
8. nach erfolgreicher eigener Prüfung darf Codex PR selbst mergen

`main` muss nach jedem Merge lauffähig bleiben.

---

# 134. Codex-Arbeitsweise

Codex soll nicht versuchen, die gesamte Plattform in einem einzigen riesigen Schritt zu bauen.

Die Umsetzung erfolgt in klaren Milestones.

Nach jedem Milestone:

- Anwendung installierbar
- Migrationen konsistent
- vorhandene Funktionen weiterhin lauffähig
- keine halbfertigen Integrationen
- Dokumentation aktualisiert
- PR erstellt
- Tests dokumentiert
- Merge erst bei erfolgreichem Zustand

---

# 135. Empfohlene Milestones

## Milestone 0 – Analyse und Architekturentscheidung

Noch keine vollständige Fachimplementierung.

Codex soll:

- bestehendes `dvo001/saas` analysieren
- `dvo001/Laufanlass` fachlich analysieren
- geeigneten PHP-Stack auswählen
- Frameworkwahl begründen
- Frontend-/CSS-Wahl begründen
- Modularchitektur definieren
- Datenmodell skizzieren
- Verzeichnisstruktur definieren
- Security-Konzept definieren
- Migrationsstrategie definieren
- Cronarchitektur definieren
- Payment-Abstraktion definieren

Architekturdokument committen.

## Milestone 1 – technischer Application Core

Implementieren:

- neues Projektgerüst
- Framework
- Composer
- Config
- Logging
- Fehlerreferenzen
- Migrationen
- i18n-Grundlage
- Layout
- Light/Dark
- sichere Dateistruktur
- Web-Installer
- Plattform-Basiseinstellungen

## Milestone 2 – Auth, Mandanten und Rollen

Implementieren:

- Registrierung
- Mandanten-Slug
- E-Mail-Bestätigung
- Login
- Passwortreset
- 2FA
- Sessionregeln
- Sperren
- Benutzerverwaltung
- Rollen
- Veranstaltungszuweisungen
- Ownerwechsel
- Isolationstests

## Milestone 3 – Plattformadministration

Implementieren:

- separate Adminoberfläche
- Plattformadmins
- Vereine
- Supportzugriff
- Audit
- zentrale Einstellungen
- Wartungsmodus
- Systemstatus

## Milestone 4 – Abos, Module und Billing

Implementieren:

- Pläne
- Hauptabo
- Zusatzmodule
- Trial
- Laufzeiten
- Verlängerung
- Kündigung
- Rechnung
- QR-Rechnung
- MwSt.
- Zahlungsstatus
- Mahnung
- Sperrlogik
- Gutscheine
- Kulanz
- Payment-Provider-Abstraktion

## Milestone 5 – Benachrichtigungen und Cronjobs

Implementieren:

- internes Notification Center
- SMTP
- Cronrunner
- Cronhistorie
- Monitoring
- Mahnungen
- Aufbewahrungs-/Löschjobs
- Trialjobs
- Exportjobs
- Wartungsbenachrichtigungen

## Milestone 6 – Veranstaltungen und Vorlagen

Implementieren:

- Event-Grundmodell
- Statusmaschine
- Vorlagen
- globale Vorlagen
- Modulstandards
- Duplizieren
- Archivieren
- Löschen
- Veranstaltungskontext
- Navigation

## Milestone 7 – Teilnehmer-, Team- und Organisationsstämme

Implementieren:

- Teilnehmer
- optionaler Teilnehmerstamm
- Mannschaften
- optionaler Mannschaftsstamm
- externe Organisationen
- Anonymisierung bei Löschung
- CSV-Grundservice

## Milestone 8 – Laufanlass

Vollständiges Laufmodul gemäß dieser Spezifikation und fachlicher Referenz `dvo001/Laufanlass`.

## Milestone 9 – Fussballturnier

Vollständiges Fussballmodul gemäß dieser Spezifikation.

Spielplangenerator als klar isolierter Service.

## Milestone 10 – Dokumente, Exporte und Abschluss

Implementieren/vervollständigen:

- PDF-Dienste
- Enddokumentversionierung
- Mandantenexport
- Rechnungsarchiv
- Abrechnungs-CSV
- Datenaufbewahrung
- Löschprotokoll

## Milestone 11 – UI-/Security-/Deployment-Härtung

Abschließend:

- responsive Prüfung
- mobile Bedienbarkeit
- Polling optimieren
- CSRF
- XSS
- SQL-Injection
- Uploadsicherheit
- Rate Limits
- Auth-Härtung
- Mandantenisolation erneut prüfen
- Production-Konfiguration
- Installationsdokumentation
- Cyon-/Shared-Hosting-Anleitung

---

# 136. Sicherheitsanforderungen

Codex muss mindestens berücksichtigen:

- PDO/ORM Prepared Statements
- CSRF-Schutz
- korrektes HTML-Escaping
- Passwort-Hash über `password_hash`
- sichere Session-Cookies
- Secure/HttpOnly/SameSite
- Session-Regeneration nach Login
- sichere zufällige Tokens
- Tokens nur gehasht speichern, wo sinnvoll
- serverseitige Rollenprüfung
- serverseitige Lizenzprüfung
- Upload MIME/Content-Prüfung
- Pfad-Traversal verhindern
- keine Secrets in Git
- keine sensitiven Daten in Logs
- keine Mandanten-IDs aus Clientwerten ungeprüft übernehmen
- Rate Limits für Login/Reset/Einladungen
- Re-Authentifizierung bei kritischen Aktionen

---

# 137. Datenlöschung und Datenminimierung

Grundsatz:

möglichst wenige Datenleichen.

Bei Löschung:

- Personendaten tatsächlich entfernen oder anonymisieren
- nur gesetzlich notwendige Daten erhalten
- Audit nur im definierten Umfang
- Rechnungsdaten getrennt erhalten
- Löschvorgang dokumentieren

---

# 138. Akzeptanzkriterien für Version 1

Version 1 gilt erst als abgeschlossen, wenn mindestens folgendes durchgängig funktioniert:

1. Neue Installation über Web-Installer.
2. Erster Plattformadmin funktioniert.
3. Verein kann sich registrieren.
4. Owner bestätigt E-Mail.
5. 14-Tage-Trial startet.
6. Mandant hat eigenen Slug.
7. Benutzer verschiedener Mandanten sind strikt isoliert.
8. Owner kann Vereinsbenutzer einladen.
9. Rollen funktionieren.
10. 2FA funktioniert.
11. Verein kann Veranstaltungen erstellen.
12. lizenzierte Module werden angezeigt.
13. nicht lizenzierte Module werden serverseitig blockiert.
14. Trial-, Abo- und Aufbewahrungslogik funktioniert.
15. Rechnung mit QR-Zahlteil kann erzeugt werden.
16. Zahlungen können manuell verbucht werden.
17. Mahn-/Sperrprozess funktioniert.
18. Cronjobs funktionieren und werden überwacht.
19. Laufanlass vollständig nutzbar.
20. Fussballturnier vollständig nutzbar.
21. mehrere Benutzer können parallel arbeiten.
22. konkurrierende Änderungen werden erkannt.
23. PDFs funktionieren.
24. Audit funktioniert.
25. Supportzugriff funktioniert.
26. Plattformadminbereich funktioniert.
27. Mandantenexport funktioniert.
28. Archivierung funktioniert.
29. endgültige Löschung funktioniert.
30. Anwendung läuft ohne zusätzliche permanente Serverprozesse auf klassischem PHP/MariaDB-Hosting.

---

# 139. Entscheidungsfreiheit von Codex

Codex darf technische Detailentscheidungen selbst treffen, sofern sie nicht den oben festgelegten Anforderungen widersprechen.

Insbesondere darf Codex entscheiden:

- konkretes PHP-Framework
- konkretes CSS-Framework
- ORM oder Repository-Ansatz
- konkrete Klassenstruktur
- Modulregistrierung
- interne Event-/Hook-Mechanismen
- Cron-Implementierungsdetails
- PDF-Library
- QR-Rechnungs-Library
- TOTP-Library
- konkrete Polling-Technik
- Spielplanalgorithmus

Für wesentliche Architekturentscheidungen muss Codex die Wahl dokumentieren und begründen.

---

# 140. Verbindliche Priorität

Bei Zielkonflikten gilt folgende Priorität:

1. Mandantensicherheit
2. Datenintegrität
3. korrekte Abrechnung
4. korrekte Wettkampflogik
5. Wartbarkeit
6. Benutzerfreundlichkeit
7. Performance
8. optische Verfeinerung

---

# 141. Grundsatz für Codex

Nicht den bestehenden Prototyp „weiterflicken“.

Stattdessen:

**saubere neue Architektur im bestehenden Repository aufbauen.**

Vorhandene Ideen dürfen übernommen werden.

Vorhandener Code muss nicht kompatibel bleiben.

Das Ziel ist eine langlebige, modulare SaaS-Basis, auf der nach Version 1 weitere unabhängige Sportmodule ergänzt werden können.

Nach jedem Milestone muss `main` einen konsistenten, installierbaren und nutzbaren Stand darstellen.