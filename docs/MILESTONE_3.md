# Milestone 3 – Plattformadministration

Status: lokal abgeschlossen

Branch: `milestone/3-platform-administration`

## Lieferumfang

- strikt getrennte Plattform-Oberfläche und eigener Security-Firewall
- obligatorische TOTP-2FA und Zwei-Stunden-Inaktivitätsgrenze für Plattformadmins
- Einladungen mit gehashten Einmaltokens sowie Aktivieren, Deaktivieren und Entsperren
- Schutz des letzten aktiven Plattformadmins und serverseitiges Notfall-Recovery
- Vereinsübersicht ohne direkte Bearbeitung von Vereinsbenutzern
- begründeter, auditierter Supportmodus für höchstens zwei Stunden
- Owner-Schalter zum vollständigen Deaktivieren des Supportzugriffs
- permanentes Support-Warnband und explizites Beenden des Supportmodus
- Plattform- und Vereins-Audit mit Aktions-, Akteur-, Vereins- und Zeitraumfiltern
- versionierte zentrale Einstellungen für Plattform, Betreiber, Mail und Cronintervalle
- geplante oder sofortige Wartungsfenster, Absage und 503-Wartungsseite
- Systemstatus für Plattformadmins sowie Vereins-Owner/-Admins
- persistente Cronlauf-Historie mit Status und Fehlerreferenz

## Sicherheitsregeln

Supportzugriffe erzeugen keinen echten Vereinsbenutzer. Nach erfolgreicher
Plattform-2FA wird für ein freigegebenes Wartungsfenster eine kurzlebige Identität
mit Administratorrechten erzeugt. Das zufällige Sitzungstoken liegt nur im Browser;
in der Datenbank wird ausschliesslich ein HMAC gespeichert. Deaktiviert der Owner
den Zugriff, wird eine laufende Supportidentität beim nächsten Request ungültig.

Plattform-Einstellungen werden nicht überschrieben. Jede Änderung legt eine neue,
zeitlich gültige Version an und protokolliert alten und neuen Wert im Audit.

## Notfall-Recovery

Wenn genau ein bestätigter Plattformadmin existiert, kann dieser ausschliesslich
serverseitig entsperrt werden:

```bash
PLATFORM_EMERGENCY_RECOVERY_TOKEN='mindestens-32-zufaellige-zeichen' \
  php bin/console app:platform-admin:emergency-unlock \
  --email='admin@example.ch' --token='mindestens-32-zufaellige-zeichen'
```

Das Token muss nach Verwendung in der Hosting-Konfiguration rotiert werden. Das
Kommando ist nicht über HTTP verfügbar und erzeugt einen Audit-Eintrag.

## Datenbank

Migration `Version20260821020000` ergänzt Plattformadmin-Metadaten, gehashte
Einladungstokens, Support-Sitzungen, Wartungsfenster und Cronläufe. Die Migration
muss vor dem Deployment auf einer frischen MariaDB-Testdatenbank ausgeführt werden.
