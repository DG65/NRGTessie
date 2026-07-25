# [Modul] Tessie – Tesla-Fahrzeuge in IP-Symcon (Steuerung, Telemetrie & Kachel)

Hallo zusammen,

ich möchte euch mein Modul **Tessie for IP-Symcon** vorstellen, das ab sofort im **Module Store** verfügbar ist (einfach im Store nach *Tessie* suchen).

## Worum geht es?

Das Modul bindet **Tesla-Fahrzeuge** über die [Tessie-API](https://tessie.com/) in IP-Symcon ein – mit **Steuerung per REST** und **Live-Telemetrie per WebSocket**. Statt sich von Hand durch APIs und Datenpunkte zu hangeln, legt ein Konfigurator die Fahrzeuge automatisch an; die relevanten Werte erscheinen als saubere, deutschsprachige Variablen und lassen sich direkt schalten.

[HIER SCREENSHOT EINFÜGEN – die Kachel (am besten die Ansicht mit zwei Fahrzeugen)]
*Die mitgelieferte Kachel: Ladestand, Reichweite, Temperaturen, Status, Lade-Details und Standort auf einen Blick – inkl. Bedien-Buttons.*

## Wozu braucht man das?

Wer ein Tesla-Fahrzeug und IP-Symcon hat, will beides natürlich verheiraten. Typische Anwendungsfälle:

- **Visualisieren** – Ladestand, Reichweite, Innen-/Außentemperatur, Verriegelung und Ladestatus auf einen Blick.
- **Automatisieren** – das Laden ans eigene **PV-/Batteriesystem** oder an **dynamische Stromtarife** koppeln, Ladelimit/Ladestrom regeln, vor der Abfahrt **vorklimatisieren**, Aktionen je nach **Standort** (zu Hause/unterwegs).
- **Archivieren** – Ladevorgänge, Verbrauch und Reichweite über die Zeit loggen.
- **Überwachen** – z. B. melden, wenn das Auto unverriegelt ist oder ein Ladevorgang endet.

Bei mir läuft das Fahrzeug zusammen mit PV, Batterie und einem Energiemanagement – da ist das Laden einer der größten und am besten verschiebbaren Verbraucher.

## Das Highlight: praktikabel über Tessie – ohne Nutzungskosten der Tesla-API

Das ist für mich der entscheidende Punkt. Die offizielle **Tesla Fleet API** verlangt von Drittanbietern eine eigene Entwickler-/Domain-Registrierung mit hinterlegtem Public Key und rechnet **nutzungsabhängige Gebühren** ab (pro Befehl/Abruf) – für eine private Heimautomatisierung unverhältnismäßig aufwändig und potenziell teuer.

**Tessie** kapselt das: Mit dem Tessie-Abo bekommt man **ein einfaches Token**, über das sowohl REST-Steuerung als auch Live-Telemetrie laufen – **ohne zusätzliche Pro-Nutzung-Kosten der Tesla-API und mit minimalem Setup**. Genau das macht die Anbindung in Symcon überhaupt erst alltagstauglich.

## Schicke Kachel inklusive

Mitgeliefert wird ein eigenes Modul **TessieVehicleTile** – eine eigenständige HTML-Kachel für die Kacheln-Visualisierung (Akku-Ring, Reichweite, Temperaturen, Lade-Details, Standort, Bedien-Buttons). Die Datenquelle wird automatisch erkannt, Farben/Schrift sind frei einstellbar, ansonsten übernimmt die Kachel das IP-Symcon-Theme (hell/dunkel). Sie ist bewusst von der Datenlogik getrennt – ein Problem in der Kachel kann die Datenverbindung nicht stören.

## Funktionsumfang

[HIER SCREENSHOT EINFÜGEN – Konfigurationsdialog mit der Datenpunkt-Liste]
*Die Konfiguration: deutschsprachige Datenpunkte mit Gruppen-Zuordnung und Anzeige der empfangenen Werte, ein-/ausblendbar und per Drag & Drop sortierbar. Darunter der ausklappbare Bereich für die Linkstruktur.*

- **Steuerung** (REST): Ver-/Entriegeln, Klima ein/aus, Solltemperaturen, Sitz-/Lenkradheizung, Laden starten/stoppen, Ladelimit, Ladestrom, Ladeport, Fenster lüften/schließen, Hupe/Lichthupe, Sentry-/Valet-Modus, vorderer Kofferraum/Heckklappe, HomeLink, Innenraum-Überhitzeschutz, Klimahaltung u. v. m.
- **Live-Telemetrie** über den Tessie-WebSocket-Stream – Werte in Echtzeit statt reinem Polling.
- **Automatische Variablen** mit **lesbarer** Aufbereitung: Enum-Zustände als Klartext, metrische Einheiten (km/h, km, °C, bar), Zeitstempel als Datum, GPS mit ausreichend Nachkommastellen.
- **Datenpunkt-Auswahl**: jeden Datenpunkt ein-/ausblenden (abgewählte werden nur versteckt – Objekt-ID und Archivdaten bleiben erhalten), Spalte „Empfangen" zeigt aktive Werte.
- **Sortierung per Drag & Drop** mit sinnvoller Standard-Reihenfolge.

[HIER SCREENSHOT EINFÜGEN – Objektbaum mit der gruppierten Linkstruktur]
*Optional erzeugt das Modul einen aufgeräumten Kategoriebaum (Laden, Klima, Sicherheit, Sonstiges) mit voll bedienbaren Links.*

- **Optionale Linkstruktur**: gruppierter Kategoriebaum an einem frei wählbaren Ort.
- **IP-Symcon-9.0-Presentations** für die Darstellung (Schalter/Slider/Auswahl).
- **Vollständig deutsche Übersetzung**.

## Voraussetzungen

- IP-Symcon ab **Version 9.0**
- Ein **Tessie-Konto mit API-Token** (deckt REST und Telemetrie ab)

## Einrichtung in Kürze

1. Instanz **Tessie Configurator** anlegen, den Tessie-Token eintragen.
2. Die gefundenen Fahrzeuge anlegen – TessieVehicle und WebSocket-Client werden automatisch erzeugt.
3. Optional eine Instanz **TessieVehicleTile** für die Kachel-Visualisierung anlegen.

## Open Source, Marken & Haftung

Der Code steht unter der **MIT-Lizenz** und liegt offen auf GitHub: https://github.com/DG65/NRGTessie

Inoffizielles Community-Modul – keine Verbindung zu Tesla, Inc. oder Tessie. Wichtig: Die Nutzung erfolgt **auf eigenes Risiko**. Das Modul schickt echte Befehle an das Fahrzeug (Verriegeln, Fenster, Kofferraum, Laden, Klima). Ich übernehme keine Haftung für Schäden oder Folgeschäden – sicherheitsrelevante Aktionen bitte nicht ungeprüft automatisieren.

## Feedback

Über Rückmeldungen, Ideen und Erfahrungsberichte freue ich mich – gern hier im Thread.

Viele Grüße
Dietmar
