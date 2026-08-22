# Milestone 8 – Laufanlass

## Umgesetzt

- Vollständig mandantensicheres Laufmodul auf der allgemeinen Veranstaltungs- und Teilnehmerarchitektur
- Kategorien nach Jahrgang von/bis und Geschlecht, Überlappungsprüfung sowie automatische Neuzuordnung vor Abschluss
- Sichtbare Kennzeichnung nicht zugeordneter Teilnehmer und Sperre des Veranstaltungsstarts bei offenen Zuordnungen
- Frei konfigurierbare Anzahl Qualifikationsläufe (Standard 2) und Finalisten (Standard 3)
- Versionierte Modulstandards werden beim erstmaligen Öffnen übernommen; bestehende Events behalten ihre Einstellungen
- Zehntel- oder Hundertstelsekunden als Integer ohne Floating-Point-Wertung
- Status `gültig`, `DNS`, `DNF` und `DSQ` für Qualifikation und Finale
- Eindeutige automatisch vorgeschlagene und manuell änderbare Start-/Laufzettelnummer
- Schnelle Qualifikationserfassung mit eigenem Feld für jeden Lauf jedes Teilnehmers
- Polling aktualisiert unberührte Arbeitsansichten automatisch; bei lokalen Eingaben warnt die Oberfläche vor fremden Änderungen
- Optimistische Versionsprüfung verhindert stilles Überschreiben paralleler Konfigurations-, Startnummern-, Kategorie- und Zeiteingaben
- Live berechnete Qualifikationsrangfolge: beste Zeit, danach zweitbeste, danach alle weiteren Zeiten
- Vollständiger Gleichstand erst bei identischen Zeitvektoren; geteilte Ränge
- Berechneter Finalistenvorschlag mit automatischer Erweiterung aller Gleichplatzierten an der Finalgrenze
- Ausdrückliche Finalistenbestätigung und gesperrte Finalstartreihenfolge nach Qualifikationsrang
- Reset ausschließlich durch Owner/Admin, mit Pflichtbegründung, Audit und nur vor erster Finalzeit
- Finale mit eigener Eingabe; Schlussrang ausschließlich nach Finalzeit und geteilten Rängen
- Veranstaltungen ohne Finale verwenden direkt die Qualifikationsrangliste als Schlussrangliste
- Start-/Laufzettel, Qualifikationsranglisten, Finalistenlisten und Schlussranglisten als echte TCPDF-Dokumente pro Kategorie
- Druckbare Webansichten sowie Gesamtübersichten über alle Kategorien
- Modulbedingungen verhindern Start bzw. Abschluss bei unvollständigen Kategorien, Ergebnissen oder Finaldaten

## Fachliche Referenz

Die Abläufe des öffentlichen Referenzrepositories `dvo001/Laufanlass` wurden analysiert. Übernommen wurden die sinnvollen Arbeitsabläufe für Kategorien, schnelle Zeitnahme, Finalisten, Ranglisten und Laufzettel. Fest verdrahtete Werte der Referenz wurden entsprechend der SaaS-Spezifikation dynamisch modelliert; Referenzcode wurde nicht kopiert.

## Bedienung

Im Veranstaltungskontext einer lizenzierten Laufveranstaltung öffnet „Laufmodul öffnen“ die Konfiguration und operative Erfassung. Zeiten akzeptieren beispielsweise `1:23.4`, `83.4`, `DNS`, `DNF` oder `DSQ`. Ranglisten werden beim Laden aus den aktuell gespeicherten Integerzeiten neu berechnet.
