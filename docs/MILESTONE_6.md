# Milestone 6 – Veranstaltungen und Vorlagen

## Umgesetzt

- Sportartenneutrales Veranstaltungsmodell mit Modul, Zeitraum, Ort, primärer Leitung, Notizen, Konfigurationssnapshot und optimistischer Sperre
- Irreversible Statusmaschine: Entwurf → Vorbereitung → Laufend → Abgeschlossen sowie endgültiger Abbruch und Archivierung
- Pflichtbegründung und ausdrückliche Sicherheitsbestätigung für Abbruch; Bestätigung auch für Abschluss und Archivierung
- Mandantensichere Übersicht und Veranstaltungskontext für Owner/Admin sowie zugewiesene Veranstaltungsrollen
- Serverseitige Lizenzprüfung bereits bei der Erstellung einer Veranstaltung
- Strukturelles Duplizieren ohne operative Teilnehmer-, Mannschafts- oder Resultatdaten
- Endgültige Löschung ausschließlich für archivierte Veranstaltungen, nur durch Owner/Admin und mit Auditnachweis
- Globale, versionierte und deaktivierbare Plattformvorlagen
- Vereinsvorlagen für Owner, Admin und Veranstaltungsleiter; verwendete Vorlagen bleiben referenziell erhalten
- Versionierte Modulstandardwerte; Vorlagen ergänzen diese Standards, bestehende Events behalten immer ihren Snapshot
- Navigation für Veranstaltungskontext, Vereinsvorlagen sowie Plattformvorlagen und Modulstandards

## Architektur

Beim Erstellen wird die Konfiguration als Snapshot gespeichert. Modulstandards bilden die Basis, danach überschreiben Vorlagenwerte die entsprechenden Schlüssel. Spätere Änderungen wirken dadurch ausschließlich auf neue Veranstaltungen.

Die fachunabhängigen Statusregeln liegen in `EventStatusMachine`. Die Fachmodule der Milestones 8 und 9 prüfen ihre zusätzlichen Start- und Abschlussbedingungen vor dem zentralen Statuswechsel.

## Milestone-Grenze

Teilnehmer, Mannschaften und externe Organisationen folgen in Milestone 7. Sportartspezifische Strukturen und Bedingungen folgen in Milestone 8 und 9.
