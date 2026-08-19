# Tessie für IP-Symcon

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-2.26.0-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Anbindung von Tesla-Fahrzeugen an IP-Symcon über die [Tessie-API](https://developer.tessie.com/) – Steuerung per REST und Live-Telemetrie per WebSocket.

> Inoffizielles Community-Modul. Nicht von Tesla, Inc. oder Tessie entwickelt, unterstützt oder freigegeben. Siehe [Markenhinweis](#markenhinweis) und [Haftungsausschluss](#haftungsausschluss).

> Teil des **NRG-Stack** – welche Modulstände zusammen getestet sind, listet das Suite-Manifest: [SUITE.md](https://github.com/DG65/NRGEMS/blob/main/SUITE.md). Das Modul ist auch eigenständig voll nutzbar.

## Funktionen

- **Fahrzeugsteuerung** über die Tessie-REST-API: Ver-/Entriegeln, Klima ein/aus, Solltemperaturen, Sitz-/Lenkradheizung, Laden starten/stoppen, Ladelimit, Ladestrom, Ladeport, Fenster lüften/schließen, Hupe, Lichthupe, Wächtermodus/Valet-Modus, vorderer Kofferraum/Heckklappe, HomeLink, Innenraum-Überhitzeschutz, Klimahaltung u. a.
- **Live-Telemetrie** über den Tessie-WebSocket-Stream (Zugangsschlüssel wird als `?access_token=…` in der URL mitgegeben).
- **Automatische Telemetrie-Variablen**: neue Datenpunkte werden bei Bedarf angelegt; Werte werden **lesbar** aufbereitet
  - Enum-Zustände als Klartext (z. B. `DefrostModeStateOff` → „Entfroster: aus") über `locale.json`, mit generischem Fallback für unbekannte Codes,
  - Einheiten metrisch (mph→km/h, mi→km), GPS-Koordinaten mit 6 Nachkommastellen,
  - Zeitstempel als Datum,
  - verschachtelte Status (z. B. Türen) als lesbare Liste.
- **IP-Symcon-9.0-Presentations** für die Darstellung (Schalter/Slider/Auswahl/Wert) statt Variablenprofilen.
- **Konfigurierbare Datenpunktliste**: ein-/ausblenden und per Ziehen und Ablegen sortieren; die Reihenfolge wirkt auf Variablen und Links. Abgewählte Datenpunkte werden **ausgeblendet statt gelöscht** – Objekt-ID und Archivdaten bleiben erhalten.
- **Optionale Linkstruktur**: gruppierter Kategoriebaum (Laden / Klima / Sicherheit / Sonstiges) mit Verknüpfungen auf die aktiven Variablen.
- **Standort-Erkennung (Geofence)**: optionale Status-Variablen aus GPS-Position und konfigurierbaren Standorten (Zuhause + beliebige weitere, je mit Name, Radius und optionalem Icon/Emoji für die Kachel-Anzeige). Je Standort eine Boolean-Variable plus eine Textvariable „Aktueller Standort" (Standortname bzw. „Unterwegs") – ideal als Auslöser für Automationen (z. B. Laden nur zu Hause).
- **Automationen (Wenn → Dann)**: generische Regeln über beliebige Datenpunkte – „Wenn Ladestand ≥ 80 → Wallbox-Freigabe ausschalten", „Wenn Aktueller Standort = Zuhause → Garagentor einschalten". Bedingungen: wird EIN/AUS, =, ≠, >, ≥, <, ≤, ändert sich, Durchfahrt (Standort). Datenpunkte mit bekannten Werten (Sitzheizung, Klimahaltung, Überhitzeschutz-Limit) zeigen den Vergleichswert in der Kachel als Dropdown mit Klartext statt Freitext, die Bedingung springt dabei automatisch von 'wird EIN/AUS' auf '=' – auch **mehrere Bedingungen mit UND verknüpft** (z. B. „Wenn Zu Hause wird EIN UND Ladestand < 30 → Ladeport öffnen"), einstellbar über den Kachel-Editor. Standort-Ereignisse laufen ebenfalls hierüber: „wird EIN" auf einer Standort-Variable = Einfahrt, „wird AUS" = Ausfahrt, „Durchfahrt" = Ein- und Ausfahrt binnen 15 Minuten. Die Kachel zeigt den aktuellen Standort als 📍-Pill an. Flankengesteuert (feuert beim Eintreten der Bedingung, nicht bei jeder Meldung). Regeln **und Standorte** lassen sich **komplett in der Kachel verwalten**: anlegen, bearbeiten, löschen, aktivieren/deaktivieren – inkl. Suchfeld für die Zielvariable, Auswahlliste der Profil-/Darstellungswerte bei „Wert setzen" und Übernahme der aktuellen **Fahrzeugposition** als neuer Standort (hinfahren, speichern, fertig).

## Enthaltene Module

| Modul | Typ | Beschreibung |
|------|-----|--------------|
| **TessieConfigurator** | 4 (Configurator) | Liest die Fahrzeuge zum Tessie-Zugangsschlüssel aus und legt je Fahrzeug eine TessieVehicle-Instanz samt WebSocket-Client an. |
| **TessieVehicle** | 3 (Device) | Steuerung und Telemetrie eines Fahrzeugs. Hängt unter einem MQTT/WebSocket-Client. |
| **TessieVehicleTile** | 3 (Visualisierung) | Eigenständige HTML-Kachel für die Kacheln-Visualisierung: zeigt Ladestand, Reichweite, Status, Lade-Details und Standort einer TessieVehicle-Instanz und bietet optional Bedien-Schaltflächen. Bewusst von der Datenlogik getrennt. |

Präfix der öffentlichen Funktionen: `TESSIE`.

## Voraussetzungen

- IP-Symcon ab Version 9.0
- Ein [Tessie](https://tessie.com/)-Konto mit **Zugangsschlüssel** (bei Tessie „Access Token") – er deckt Abfragen und Telemetrie gemeinsam ab

> Warum Tessie statt der offiziellen Tesla-API? Tessie ist ein etablierter Drittanbieter-Dienst, der den Zugriff über einen einfachen Zugangsschlüssel kapselt. Die **Tesla Fleet API** verlangt von Drittanbietern nutzungsabhängige Gebühren (pro Befehl/Abruf), eine eigene Entwickler-/Domain-Registrierung und ist deutlich aufwändiger einzurichten. Über das Tessie-Abo ist die API hingegen ohne zusätzliche Pro-Nutzung-Kosten und mit minimalem Setup nutzbar – das macht die Anbindung in IP-Symcon praktikabel.

## Installation

1. Im IP-Symcon **Module Store** nach **„Tessie"** suchen und installieren.
   _Alternativ_ unter **Kern-Instanzen → Modules** das Repository manuell hinzufügen: `https://github.com/DG65/NRGTessie`
2. Eine Instanz des **Tessie Configurator** anlegen und den Tessie-Zugangsschlüssel eintragen.
3. Im Configurator die gefundenen Fahrzeuge anlegen – TessieVehicle und WebSocket-Client werden automatisch erzeugt.
4. Optional eine Instanz **TessieVehicleTile** für die Kachel-Visualisierung anlegen (siehe unten).

## Konfiguration (TessieVehicle)

- **Update-Intervall** für REST-Statusabfragen (Sekunden; 0 = aus, Telemetrie läuft unabhängig weiter).
- **Datenpunkte**: Liste zum Ein-/Ausblenden und Sortieren (Ziehen und Ablegen). Spalten: Aktiv, Name, Gruppe, Ident, Empfangen.
- **Linkstruktur** (aufklappbar): Zielkategorie, Links erzeugen/automatisch bereinigen.
- **Automationen (Wenn → Dann)** (aufklappbar): Regelliste plus Abschnitt **Standorte (Geofence)** – aktivieren, Standort Zuhause (Karte) und Radius wählen — bleibt „Standort Zuhause" leer, wird automatisch der **Systemstandort** aus der Kern-Instanz „Location" übernommen; darunter beliebige **weitere Standorte** (Name, Kartenposition, Radius) als Liste. Je Standort entsteht eine Boolean-Variable („Zu Hause" bzw. Standortname), dazu die Textvariable „Aktueller Standort". Bei überlappenden Zonen gewinnt der nächstgelegene Standort. Benötigt aktive GPS-Telemetrie (Datenpunkt „Fahrzeugposition"). Bei Deaktivierung/Entfernen bleiben die Variablen erhalten (nur ausgeblendet).
- Schaltflächen: Standardliste zurücksetzen, Telemetrie alle/​nur wichtige ein-/ausblenden, Telemetrie-Namen aktualisieren.

## Hinweise

- Telemetrie-Werte werden **beim Empfang** aufbereitet; selten gesendete/stale Werte werden zusätzlich beim **Übernehmen** der Instanz nachträglich lesbar gemacht.
- Fehlt für einen Enum-Code die Übersetzung, greift ein generischer Fallback (CamelCase → Wörter). Gezielte Übersetzungen lassen sich in `TessieVehicle/locale.json` ergänzen.
- Der Instanzstatus (Symbol im Objektbaum) zeigt Betriebsprobleme an, ohne dass Log-/Debug-Zugriff nötig ist: **API-Fehler** (Zugangsschlüssel ungültig oder Tessie-API wiederholt nicht erreichbar) und **Telemetrie seit über 15 Minuten nicht aktualisiert** (Warnung, kein harter Fehler – z. B. wenn Teslas Push-Übertragung im Hintergrund abbricht).

## Kachel (TessieVehicleTile)

Für eine schöne Darstellung in der **Kacheln-Visualisierung** eine Instanz des Moduls **TessieVehicleTile** anlegen. Die Datenquelle wird automatisch erkannt, wenn es genau eine TessieVehicle-Instanz gibt – bei mehreren wählst du sie manuell aus. Die Kachel zeigt Akku-Ring/Ladestand, Reichweite, Temperaturen, Verriegelungs-/Klima-/Ladestatus, Lade-Details und Standort (mit Karten-Link) und bietet optional frei konfigurierbare Bedien-Schaltflächen: Anzahl, Reihenfolge und Funktion wählst du aus einem Katalog von 19 Aktionen (Verriegeln, Klima, Laden, Wächtermodus, Valet-Modus, Kofferraum/Heckklappe, Hupe, Lichthupe, Ladeport, Fenster, Sitz-/Lenkradheizung, Überhitzeschutz, Biowaffen-Schutzmodus u. a.) – jeweils mit optionaler eigener Beschriftung. Eine Schaltfläche erscheint nur, wenn der zugehörige Datenpunkt in der Quelle aktiviert ist. Über den Stift ✎ neben „Schaltflächen" lässt sich dieselbe Konfiguration auch direkt in der Kachel verwalten: hinzufügen, Funktion/Beschriftung ändern, Reihenfolge per ▲▼, löschen. Farben und Schrift sind konfigurierbar; ohne feste Farbe übernimmt die Kachel das IP-Symcon-Theme (hell/dunkel).

Status-/Telemetriewerte (Ladestand, Reichweite, Temperaturen, Standort …) erscheinen nur, wenn die entsprechenden Datenpunkte in der TessieVehicle-Instanz aktiviert sind.

## Integration in andere Module

`TESSIE_GetVehicleState($id)` liefert eine herstellerneutrale Zustandsabfrage als JSON – gedacht für Module wie eine Wallbox-/Stromfluss-Kachel, die ein Fahrzeug automatisch statt per manuell eingetragener Variablen-ID zuordnen wollen:

```json
{
  "contractVersion": "1.3",
  "instanceID": 12345,
  "name": "Mein Auto",
  "vin": "5YJ...",
  "socID": 67890,
  "soc": 92.0,
  "connected": true,
  "chargingID": 67891,
  "charging": false,
  "chargeLimit": 80,
  "chargeAmpsRequest": 16,
  "chargeAmpsMax": 16,
  "atHome": true,
  "scheduledChargingActive": false,
  "scheduledDeparture": "",
  "energyRemainingKwh": 68.4,
  "batteryCapacityKwh": 74.3
}
```

- `contractVersion`: Version dieses Datenvertrags als `Major.Minor` (aktuell `1.3`). Konsumenten prüfen die Major-Version auf Kompatibilität – innerhalb derselben Major werden nur additive Felder ergänzt, ein Bruch erhöht die Major. Fehlt ein Feld, gilt die jeweils niedrigere Version.
- `socID`: Variablen-ID des Ladestands (0, falls der Datenpunkt nicht aktiviert ist)
- `soc`: gemessener Ist-Ladestand in % (verlässlich; Ziel-SoC und Deadline hält das steuernde Modul selbst)
- `connected`: ob ein Ladekabel gesteckt ist (nicht: ob gerade aktiv geladen wird) – ermittelt primär über den Datenpunkt „Ladestatus (Detail)", ersatzweise über den Ladestatus; `null`, falls keine der beiden Quellen verfügbar ist
- `chargingID`: Variablen-ID des rohen Telemetrie-Ladestatus (`stat_tel_DetailedChargeState`, String; 0 falls der Datenpunkt nicht vorhanden ist) – eine echte, dauerhaft geloggte IPS-Variable mit eigenem `VariableChanged`-Ereignis, gedacht für Konsumenten, die auf den Änderungs-Zeitstempel angewiesen sind (z. B. eine zeitbasierte Korrelation Wallbox↔Fahrzeug). Bewusst nicht die Aktionsvariable des Lade-Schalters: deren Zeitstempel ändert sich nur bei über IP-Symcon ausgelösten Befehlen, nicht bei Ladevorgängen, die z. B. über die Tesla-App oder geplantes Laden gestartet wurden
- `charging`: ob gerade aktiv geladen wird, aus der Telemetrie abgeleitet (`stat_tel_DetailedChargeState` enthält „Charging") – reiner Momentanwert aus diesem Aufruf, für Änderungserkennung `chargingID` verwenden
- `chargeLimit`: eingestelltes Ladelimit in %
- `chargeAmpsRequest` / `chargeAmpsMax`: angeforderter bzw. maximaler Ladestrom in A
- `atHome`: ob das Fahrzeug am Standort „Zuhause" ist – nur belegt, wenn die Standort-Erkennung aktiv ist, sonst `null` (die Planungsvoraussetzung ist typischerweise „angesteckt **und** zuhause")
- `scheduledChargingActive`: ob das Fahrzeug **selbst** ein geplantes Laden/eine geplante Abfahrt aktiv hat – wichtig als Zwei-Regler-Hinweis: ist dies `true`, steuert das Auto seine Ladung parallel, ein externer Regler (EMS/Wallbox) sollte sich zurückziehen oder den Nutzer bitten, die fahrzeuginterne Planung abzuschalten. `null`, falls der Datenpunkt nicht verfügbar ist
- `scheduledDeparture`: fahrzeuggeplante Abfahrtszeit als reine Information (formatierter Text, leer wenn nicht gesetzt)
- `energyRemainingKwh`: Restenergie in kWh, direkt aus der Telemetrie
- `batteryCapacityKwh`: Batteriekapazität in kWh, hochgerechnet aus Restenergie und SoC – Tesla liefert die tatsächliche Kapazität über keine verfügbare API direkt, daher eine Näherung. `null` bei sehr niedrigem SoC (Rundungsfehler nehmen dort stark zu)

Alle Zusatzfelder sind `null`, wenn der zugehörige Datenpunkt in der Instanz nicht aktiviert bzw. noch nicht empfangen wurde. Rein additive, lesende Funktion – ändert nichts am Modulverhalten. Fremde Module sollten den Aufruf hinter `function_exists('TESSIE_GetVehicleState')` absichern.

> Hinweis: Eine Tesla-API kennt kein Feld dafür, **wer** einen Ladebefehl ausgelöst hat. Ob eine Fremdsteuerung (z. B. Tibber) aktiv eingreift, ist darüber nicht zuverlässig erkennbar – dafür ist eine bewusste Kennzeichnung im steuernden Modul vorgesehen. `scheduledChargingActive` deckt nur die fahrzeugeigene Planung ab.

## Changelog

Alle Änderungen sind in [CHANGELOG.md](CHANGELOG.md) dokumentiert (Format nach [Keep a Changelog](https://keepachangelog.com/de/)).

## Markenhinweis

„Tesla" ist eine Marke der Tesla, Inc. „Tessie" ist ein Dienst der Tessie bzw. der jeweiligen Rechteinhaber. Dieses Projekt steht in **keiner** Verbindung zu Tesla, Inc. oder Tessie und wird von diesen weder unterstützt noch geprüft. Alle Marken gehören ihren jeweiligen Eigentümern und werden hier nur zur Beschreibung der Kompatibilität genannt.

## Haftungsausschluss

Dieses Modul steuert ein reales Fahrzeug (u. a. Ver-/Entriegeln, Fenster, Kofferraum/Heckklappe, Laden, Klima). Die Nutzung erfolgt **auf eigene Gefahr**.

- Die Software wird **ohne jede Gewährleistung** bereitgestellt (siehe [Lizenz](#lizenz)). Es wird keine Haftung für Schäden, Fehlfunktionen, Datenverlust, unbeabsichtigte Fahrzeugaktionen oder Folgen aus der Nutzung übernommen, soweit gesetzlich zulässig.
- Funktion und Verfügbarkeit hängen von der Tessie-API, dem Fahrzeug und der Netzwerkverbindung ab und können sich jederzeit ändern.
- Sicherheitsrelevante Aktionen (z. B. Entriegeln, Fenster/Kofferraum öffnen) sollten nicht ungeprüft automatisiert werden. Stelle sicher, dass keine Personen, Tiere oder Gegenstände gefährdet werden.
- Der Zugangsschlüssel gewährt weitreichenden Zugriff auf das Fahrzeug – bitte entsprechend vertraulich behandeln.

## Lizenz

Veröffentlicht unter der **PolyForm Noncommercial License 1.0.0** – © 2026 Dietmar Gureth (DG65). Private und nicht-kommerzielle Nutzung ist frei; **gewerbliche Nutzung erfordert eine gesonderte Lizenz** (Kontakt: DG65). Spenden sind willkommen und rein freiwillig. Vollständiger Text in [LICENSE](LICENSE).

Ältere Versionen, die unter der MIT-Lizenz veröffentlicht wurden, bleiben für den jeweiligen Stand MIT-lizenziert; die neue Lizenz gilt ab dieser Fassung nach vorn.
