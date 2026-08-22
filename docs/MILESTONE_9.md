# Milestone 9 – Fussballturnier

## Umgesetzt

- Vollständig mandantensicheres Fussballmodul auf der allgemeinen Veranstaltungs-, Team- und Teilnehmerarchitektur
- Frei editierbare Kategorien mit Alter, Geschlecht, Kadergrösse, Feldstärke, Spiel- und Pausenzeit, Verlängerung, Gruppengrösse, Qualifikation und Finalmodus
- Editierbare Schweizer Junioren-Vorlagen A–G als Startpunkt; die Werte bleiben veranstaltungsspezifisch änderbar
- Automatische eindeutige Teamnummern bei leerer Eingabe sowie manuelle Änderung von Nummer und Teamname mit optimistischer Versionsprüfung
- Kaderbegrenzung je Kategorie und auditierte Spielerwechsel auch nach Turnierstart
- Ausgewogene, deterministische Gruppeneinteilung ohne Setzlisten sowie manuelle Umteilung vor der Spielplanerstellung
- Round-Robin-Spielplan mit genau einer Begegnung je Paarung
- Mehrere parallele Spielfelder, Verfügbarkeitsfenster, Sperrzeiten und Mindestpausen
- Drei deterministische Strategien: Feldnutzung, kompakter Ablauf und ausgeglichene Belastung
- Konkrete Fehlermeldung, wenn Feld-, Zeit- oder Pausenbedingungen keine vollständige Planung zulassen
- Manuelles Verschieben mit Warnungen für Belegung, Feldsperre, Verfügbarkeit, Teamüberschneidung und Mindestpause; Übersteuerung muss ausdrücklich bestätigt werden
- Mannschaftsrückzug mit getrennter Entscheidung für offene und bereits gespielte Begegnungen
- Konfigurierbare Punkt- und Forfaitwertung (Standard 3/1/0 und 3:0)
- Rangfolge nach Punkten, Tordifferenz, erzielten Toren, Direktbegegnung, Team-Fairplay und auditiertem Losentscheid
- Konfigurierbare Gruppensieger, Gruppenzweite und beste Drittplatzierte; bei ungleichen Gruppen kann das Resultat gegen den Letzten für den Vergleich herausgerechnet werden
- Final, Halbfinal plus Final oder Viertelfinal plus Halbfinal plus Final, optional mit Spiel um Platz 3
- Automatische, konfliktfreie Terminierung der Finalrunde nach den Gruppenspielen; Folgebegegnungen werden aus Sieger oder Verlierer der vorherigen Partie aufgelöst
- K.-o.-Entscheid wahlweise direkt im Penaltyschiessen oder nach konfigurierbarer Verlängerung; reguläres Resultat und Penaltyresultat bleiben getrennt
- Gruppen- und Schlussranglisten sowie sieben TCPDF-Dokumentansichten für Gesamt-, Kategorie-, Feld- und Zeitspielplan, Gruppenranglisten, Finalrunde und Schlussranglisten
- Getrennte Freigaben für Spielplan und Ranglisten mit unveränderlichen, versionierten Snapshots; reine Leser sehen ausschliesslich den jeweils freigegebenen Stand
- Polling erkennt Änderungen anderer Arbeitsplätze; optimistische Sperren verhindern stilles Überschreiben
- Modulbedingungen verlangen vor dem Start einen freigegebenen Spielplan und vor dem Abschluss abgeschlossene Finalrunden sowie freigegebene Ranglisten

## Fachliche Vorlagen

Die optionalen Junioren-Vorlagen orientieren sich an den [publizierten Altersklassen des Schweizerischen Fussballverbands für die Saison 2025/26](https://org.football.ch/portaldata/28/Resources/dokumente/de/05_junioren_breitenfussball/5.3_Ausfuehrungsbestimmungen_Kinder_und_Jugendfussball_2025_26.pdf). Da Altersklassen, Spielformen und Verbandsvorgaben saisonal ändern können, sind sie bewusst nur editierbare Ausgangswerte und keine fest verdrahteten Regeln. Ergänzend wurde das [aktuelle Juniorenreglement](https://org.football.ch/Portaldata/28/Resources/offizielle_mitteilungen/2024/Juniorenreglement_Juli_2025_D_markup_nur_Aenderungen.pdf) berücksichtigt.

## Architektur und Sicherheit

Der Spielplangenerator, die Verschiebungsprüfung, Ranglistenlogik, gruppenübergreifende Drittplatziertenwertung und Finalbaum-Erzeugung sind isolierte Domain Services. Alle operativen Tabellen tragen `tenant_id` und `event_id`; zusammengesetzte Fremdschlüssel erzwingen die Veranstaltungs- und Mandantengrenze auch in der Datenbank. Jeder Zugriff prüft zusätzlich Veranstaltungsrechte und die Lizenz des Fussballmoduls.

Schreibaktionen verwenden CSRF-Schutz, serverseitige Rechteprüfung und bei konkurrierend bearbeiteten Datensätzen Versionsnummern. Freigaben speichern vollständige JSON-Snapshots, sodass spätere Entwurfsänderungen den veröffentlichten Stand nicht verändern.

## Bedienung

Im Veranstaltungskontext eines lizenzierten Fussballturniers öffnet „Fussballmodul öffnen“ das gemeinsame Setup- und Wettkampf-Dashboard. Der typische Ablauf ist: Kategorien und Teams zuordnen, Gruppen erzeugen, Felder und Zeiten erfassen, Spielplan erzeugen und freigeben, Resultate eintragen, nötige Losentscheide auslösen, Finalrunde erzeugen und schliesslich Ranglisten freigeben.
