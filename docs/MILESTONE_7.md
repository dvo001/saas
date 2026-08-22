# Milestone 7 – Teilnehmer-, Team- und Organisationsstämme

## Umgesetzt

- Sportartenneutrale Teilnehmerstammdaten mit optionaler externer Organisation, Geburtsdatum/-jahr, Wertungsgeschlecht, Kontakt-, externen und internen Feldern
- Pro Verein separat aktivierbarer Teilnehmer- und Mannschaftsstamm
- Veranstaltungsteilnehmer als unveränderlicher fachlicher Snapshot mit getrennten sportartspezifischen JSON-Daten
- Mannschaftsstamm und Veranstaltungsteams mit Nummer, Name, Organisation, Kategorie und Kontaktinformationen
- Datenbankseitig erzwungen: Ein Teilnehmer gehört innerhalb einer Veranstaltung maximal einer Mannschaft an
- Externe Organisationen der Typen Verein, Schule, Firma, Verband und Sonstige
- Löschen aus dem Teilnehmerstamm mit optionaler Anonymisierung historischer Event-Snapshots
- Löschen aus dem Mannschaftsstamm ohne Verlust historischer Veranstaltungsteams
- Audit für Stamm-, Event-, Team-, Import- und Anonymisierungsaktionen
- Schreibsperre für abgeschlossene, abgebrochene und archivierte Veranstaltungen
- Optimistische Versionsfelder auf allen gleichzeitig bearbeitbaren Datensätzen
- Feste Lauf-CSV-Vorlage mit BOM, Validierung, Vorschau, Fehlern, Kategorien und Duplikaterkennung
- Duplikatstrategien Überspringen, neu anlegen oder bestehenden Eventteilnehmer aktualisieren
- CSV-Fehlerbericht als Download; maximal 2 MB große `.csv`-Uploads

## Datenmodell

Vereinsstämme und Veranstaltungssnapshots sind bewusst getrennt. Änderungen oder Löschungen im Stamm verändern historische Meldungen und Resultate nicht automatisch. Eine ausdrücklich gewählte Anonymisierung entfernt die personenbezogenen Snapshot-Felder, lässt aber die technische Resultatzuordnung bestehen.

Sportartspezifische Angaben wie Schulklasse, Startnummer oder Kategorie liegen nicht in der neutralen Person, sondern im `sport_data`-Snapshot der Veranstaltungsteilnahme.

## Milestone-Grenze

Das Laufmodul erweitert den festen CSV-Grundservice in Milestone 8 um seine vollständige Kategorien- und Meldeverarbeitung. Mannschaftsspezifische Turnierregeln folgen in Milestone 9.
