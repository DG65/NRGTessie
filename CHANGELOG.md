# Changelog

Alle nennenswerten Änderungen an diesem Modul. Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/).

## [2.0.3] – 2026-06-14
### Behoben
- Datenpunkte ließen sich nicht per Drag & Drop sortieren und einige Variablen hatten Position 0. Ursache: die gespeicherte Liste war unvollständig (neue Kern-Variablen aus Updates fehlten). Neu: eine vollständige „effektive Liste" (Defaults + Telemetrie) als Basis für Anzeige, Position und Aktiv-Status; Spaltenkonfiguration an HeishaMon angeglichen (nur Ident wird persistiert).

## [2.0.2] – 2026-06-14
### Hinzugefügt
- Datenpunkt-Liste: Spalte **Empfangen** (zeigt „Ja", sobald die Variable einen Wert erhalten hat) — analog HeishaMon.

## [2.0.1] – 2026-06-14
### Geändert
- Instanzkonfiguration optisch an das HeishaMon-Modul angeglichen: Intro-/Erklärtexte, Datenpunkt-Liste mit Spalten **Aktiv | Name | Gruppe | Ident** (Checkbox zuerst, neue Gruppen-Spalte = Domäne) und die Link-Einstellungen gebündelt im aufklappbaren Bereich **Linkstruktur**.

## [2.0.0] – 2026-06-14
### Geändert
- **Variablendarstellung auf IPS-9.0-Presentations umgestellt** (Switch/Slider/Enumeration/Value) statt Variablenprofile. Enum-Beschriftungen deutsch (z. B. Climate Keeper, Sitzheizung, Überhitzeschutz-Limit).
- Mindest-Kompatibilität auf IP-Symcon **9.0** angehoben.
### Entfernt
- Ungenutzte Altlasten aus dem Repository (doppelte `module.php` im Wurzelverzeichnis, Referenzdateien `telemetry_maps.php` / `telemetry_profiles.json`).
- `ensureProfiles()` (durch Presentations ersetzt).

## [1.2.7] – 2026-06-14
### Hinzugefügt
- Generischer Klartext-Fallback für unbekannte Enum-/Code-Werte ohne `locale.json`-Eintrag (CamelCase → Wörter, z. B. `Apollo19CapKit` → „Apollo 19 Cap Kit").
### Geändert
- Telemetrie-Keys, die bereits über eigene Aktions-Variablen abgebildet sind (z. B. `ClimateKeeperMode`, `CabinOverheatProtectionTemperatureLimit`), werden nicht mehr als `stat_tel_`-Duplikat gepflegt, sondern automatisch ausgeblendet und nicht verlinkt.

## [1.2.6] – 2026-06-14
### Geändert
- Enum-Profilbeschriftungen auf Deutsch (`Tessie.ClimateKeeperMode`, `Tessie.COPTemp`); Assoziationen werden auch auf bestehende Profile angewendet.
- Weitere Raddesign-Codes in `locale.json` (Apollo/Gemini CapKit).

## [1.2.5] – 2026-06-14
### Hinzugefügt
- Lesbare Labels für gängige Tesla-Raddesign-Codes.
### Geändert
- Wert-Migration übersetzt jetzt auch bereits gespeicherte Klartext-Enums (z. B. `WheelType`), nicht nur Roh-JSON.

## [1.2.4] – 2026-06-14
### Hinzugefügt
- `migrateTelemetryValues()`: macht beim Übernehmen bereits gespeicherte Roh-JSON-/stale Telemetriewerte sofort lesbar (idempotent).

## [1.2.3] – 2026-06-14
### Behoben
- Sonderfälle `ClimateKeeperMode` / `CabinOverheatProtectionTemperatureLimit` setzen die Aktions-Variablen jetzt auch aus Enum-Strings (vorher blieben sie leer).

## [1.2.2] – 2026-06-14
### Behoben
- Weitere Telemetrie-Wertformen lesbar: `invalid`-Marker, `longValue`/`floatValue`, Zeitstempel als Datum, verschachtelte Bool-Objekte (z. B. Türen) als Liste.

## [1.2.1] – 2026-06-14
### Behoben
- Tesla-Enum-Wrapper (`{"…Value":"…"}`) werden ausgepackt und über `locale.json` lesbar gemacht statt als roher JSON-String gespeichert.

## [1.2.0] – 2026-06-14
### Hinzugefügt
- HTTP-Fehlererkennung (Status ≥ 400) in den API-Aufrufen.
### Geändert
- Abgewählte Variablen werden ausgeblendet statt gelöscht (Objekt-ID und Archivdaten bleiben erhalten).
- Linkbaum auf reine Domänen-Gruppierung umgestellt (jede Variable genau einmal: Laden / Klima / Sicherheit / Sonstiges).

## [1.1.4] – 2026-02-26
- Ausgangsstand (Beta): Steuerung und Telemetrie, Configurator, Auto-Variablen, Variablenprofile.
