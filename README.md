# Tessie für IP-Symcon

Anbindung von Tesla-Fahrzeugen an IP-Symcon über die [Tessie-API](https://developer.tessie.com/) – Steuerung per REST und Live-Telemetrie per WebSocket.

> Inoffizielles Community-Modul. Nicht von Tesla, Inc. oder Tessie entwickelt, unterstützt oder freigegeben. Siehe [Markenhinweis](#markenhinweis) und [Haftungsausschluss](#haftungsausschluss).

## Funktionen

- **Fahrzeugsteuerung** über die Tessie-REST-API: Ver-/Entriegeln, Klima ein/aus, Solltemperaturen, Sitz-/Lenkradheizung, Laden starten/stoppen, Ladelimit, Ladestrom, Ladeport, Fenster lüften/schließen, Hupe, Lichthupe, Sentry-/Valet-Modus, vorderer Kofferraum/Heckklappe, HomeLink, Innenraum-Überhitzeschutz, Klimahaltung u. a.
- **Live-Telemetrie** über den Tessie-WebSocket-Stream (Token kompatibel als `?access_token=…` in der URL).
- **Automatische Telemetrie-Variablen**: neue Datenpunkte werden bei Bedarf angelegt; Werte werden **lesbar** aufbereitet
  - Enum-Zustände als Klartext (z. B. `DefrostModeStateOff` → „Entfroster: aus") über `locale.json`, mit generischem Fallback für unbekannte Codes,
  - Einheiten metrisch (mph→km/h, mi→km), GPS-Koordinaten mit 6 Nachkommastellen,
  - Zeitstempel als Datum,
  - verschachtelte Status (z. B. Türen) als lesbare Liste.
- **IP-Symcon-9.0-Presentations** für die Darstellung (Schalter/Slider/Auswahl/Wert) statt Variablenprofilen.
- **Konfigurierbare Datenpunktliste**: ein-/ausblenden und per Drag & Drop sortieren; die Reihenfolge wirkt auf Variablen und Links. Abgewählte Datenpunkte werden **ausgeblendet statt gelöscht** – Objekt-ID und Archivdaten bleiben erhalten.
- **Optionale Linkstruktur**: gruppierter Kategoriebaum (Laden / Klima / Sicherheit / Sonstiges) mit Verknüpfungen auf die aktiven Variablen.

## Enthaltene Module

| Modul | Typ | Beschreibung |
|------|-----|--------------|
| **TessieConfigurator** | 4 (Configurator) | Liest die Fahrzeuge zum Tessie-Token aus und legt je Fahrzeug eine TessieVehicle-Instanz samt WebSocket-Client an. |
| **TessieVehicle** | 3 (Device) | Steuerung und Telemetrie eines Fahrzeugs. Hängt unter einem MQTT/WebSocket-Client. |
| **TessieVehicleTile** | 3 (Visualisierung) | Eigenständige HTML-Kachel für die Kacheln-Visualisierung: zeigt Ladestand, Reichweite, Status, Lade-Details und Standort einer TessieVehicle-Instanz und bietet optional Bedien-Buttons. Bewusst von der Datenlogik getrennt. |

Präfix der öffentlichen Funktionen: `TESSIE`.

## Voraussetzungen

- IP-Symcon ab Version 9.0
- Ein [Tessie](https://tessie.com/)-Konto mit **API-Token** (deckt REST und Telemetrie ab)

## Installation

1. In IP-Symcon unter **Kern-Instanzen → Modules** das Repository hinzufügen: `https://github.com/DG65/Tessie`
2. Kanal wählen: **Beta** (aktiv gepflegt) oder **main** (stabil).
3. Eine Instanz des **Tessie Configurator** anlegen, den Tessie-Token eintragen.
4. Im Configurator die gefundenen Fahrzeuge anlegen – TessieVehicle und WebSocket-Client werden automatisch erzeugt.

## Konfiguration (TessieVehicle)

- **Update-Intervall** für REST-Statusabfragen (Sekunden; 0 = aus, Telemetrie läuft unabhängig weiter).
- **Datenpunkte**: Liste zum Ein-/Ausblenden und Sortieren (Drag & Drop). Spalten: Aktiv, Name, Gruppe, Ident, Empfangen.
- **Linkstruktur** (aufklappbar): Zielkategorie, Links erzeugen/automatisch bereinigen.
- Buttons: Standardliste zurücksetzen, Telemetrie alle/​nur wichtige ein-/ausblenden, Telemetrie-Namen aktualisieren.

## Hinweise

- Telemetrie-Werte werden **beim Empfang** aufbereitet; selten gesendete/stale Werte werden zusätzlich beim **Übernehmen** der Instanz nachträglich lesbar gemacht.
- Fehlt für einen Enum-Code die Übersetzung, greift ein generischer Fallback (CamelCase → Wörter). Gezielte Übersetzungen lassen sich in `TessieVehicle/locale.json` ergänzen.

## Kachel (TessieVehicleTile)

Für eine schöne Darstellung in der **Kacheln-Visualisierung** eine Instanz des Moduls **TessieVehicleTile** anlegen. Die Datenquelle wird automatisch erkannt, wenn es genau eine TessieVehicle-Instanz gibt – bei mehreren wählst du sie manuell aus. Die Kachel zeigt Akku-Ring/Ladestand, Reichweite, Temperaturen, Verriegelungs-/Klima-/Ladestatus, Lade-Details und Standort (mit Karten-Link) und bietet optional Bedien-Buttons (Verriegeln, Klima, Laden). Farben und Schrift sind konfigurierbar; ohne feste Farbe übernimmt die Kachel das IP-Symcon-Theme (hell/dunkel).

Status-/Telemetriewerte (Ladestand, Reichweite, Temperaturen, Standort …) erscheinen nur, wenn die entsprechenden Datenpunkte in der TessieVehicle-Instanz aktiviert sind.

## Changelog

Alle Änderungen sind in [CHANGELOG.md](CHANGELOG.md) dokumentiert (Format nach [Keep a Changelog](https://keepachangelog.com/de/)).

## Markenhinweis

„Tesla" ist eine Marke der Tesla, Inc. „Tessie" ist ein Dienst der Tessie bzw. der jeweiligen Rechteinhaber. Dieses Projekt steht in **keiner** Verbindung zu Tesla, Inc. oder Tessie und wird von diesen weder unterstützt noch geprüft. Alle Marken gehören ihren jeweiligen Eigentümern und werden hier nur zur Beschreibung der Kompatibilität genannt.

## Haftungsausschluss

Dieses Modul steuert ein reales Fahrzeug (u. a. Ver-/Entriegeln, Fenster, Kofferraum/Heckklappe, Laden, Klima). Die Nutzung erfolgt **auf eigene Gefahr**.

- Die Software wird **ohne jede Gewährleistung** bereitgestellt (siehe [Lizenz](#lizenz)). Es wird keine Haftung für Schäden, Fehlfunktionen, Datenverlust, unbeabsichtigte Fahrzeugaktionen oder Folgen aus der Nutzung übernommen, soweit gesetzlich zulässig.
- Funktion und Verfügbarkeit hängen von der Tessie-API, dem Fahrzeug und der Netzwerkverbindung ab und können sich jederzeit ändern.
- Sicherheitsrelevante Aktionen (z. B. Entriegeln, Fenster/Kofferraum öffnen) sollten nicht ungeprüft automatisiert werden. Stelle sicher, dass keine Personen, Tiere oder Gegenstände gefährdet werden.
- Der API-Token gewährt weitreichenden Zugriff auf das Fahrzeug – bitte entsprechend vertraulich behandeln.

## Lizenz

Veröffentlicht unter der **MIT-Lizenz** – © 2026 Dietmar Gureth. Vollständiger Text in [LICENSE](LICENSE).
