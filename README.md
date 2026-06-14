# Tessie für IP-Symcon

Anbindung von Tesla-Fahrzeugen an IP-Symcon über die [Tessie-API](https://developer.tessie.com/) – Steuerung per REST und Live-Telemetrie per WebSocket.

## Funktionen

- **Fahrzeugsteuerung** über die Tessie-REST-API: Ver-/Entriegeln, Klima ein/aus, Solltemperaturen, Sitz-/Lenkradheizung, Laden starten/stoppen, Ladelimit, Ladestrom, Ladeport, Fenster lüften/schließen, Hupe, Lichthupe, Sentry-/Valet-Modus, Frunk/Kofferraum, HomeLink, Innenraum-Überhitzeschutz, Climate-Keeper u. a.
- **Live-Telemetrie** über den Tessie-WebSocket-Stream (Token kompatibel als `?access_token=…` in der URL).
- **Automatische Telemetrie-Variablen**: neue Datenpunkte werden bei Bedarf angelegt; Werte werden **lesbar** aufbereitet
  - Enum-Zustände als Klartext (z. B. `DefrostModeStateOff` → „Entfroster: aus") über `locale.json`, mit generischem Fallback für unbekannte Codes,
  - Einheiten metrisch (mph→km/h, mi→km),
  - Zeitstempel als Datum,
  - verschachtelte Status (z. B. Türen) als lesbare Liste.
- **Konfigurierbare Variablenliste**: Datenpunkte ein-/ausblenden und per Drag & Drop sortieren. Abgewählte Variablen werden **ausgeblendet statt gelöscht** – Objekt-ID und Archivdaten bleiben erhalten.
- **Optionale Linkstruktur**: gruppierter Kategoriebaum (Laden / Klima / Sicherheit / Sonstiges) mit Verknüpfungen auf die aktiven Variablen.

## Enthaltene Module

| Modul | Typ | Beschreibung |
|------|-----|--------------|
| **TessieConfigurator** | 4 (Configurator) | Liest die Fahrzeuge zum Tessie-Token aus und legt je Fahrzeug eine TessieVehicle-Instanz samt WebSocket-Client an. |
| **TessieVehicle** | 3 (Device) | Steuerung und Telemetrie eines Fahrzeugs. Hängt unter einem MQTT/WebSocket-Client. |

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
- **Ablageort** von Instanz und Links, **Links anlegen/bereinigen**.
- **Anzuzeigende Variablen**: Liste zum Ein-/Ausblenden und Sortieren.
- Buttons: Standardliste zurücksetzen, Telemetrie alle/​nur wichtige ein-/ausblenden, Telemetrie-Namen aktualisieren.

## Hinweise

- Telemetrie-Werte werden **beim Empfang** aufbereitet; selten gesendete/stale Werte werden zusätzlich beim **Übernehmen** der Instanz nachträglich lesbar gemacht.
- Fehlt für einen Enum-Code die Übersetzung, greift ein generischer Fallback (CamelCase → Wörter). Gezielte Übersetzungen lassen sich in `TessieVehicle/locale.json` ergänzen.

## Lizenz

Siehe [LICENSE](LICENSE).
