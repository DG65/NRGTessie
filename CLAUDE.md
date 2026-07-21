# Hinweise für die Arbeit an diesem Repository

## Branch-Workflow

- **`beta`**: laufende Entwicklung und schnelle Auslieferung per direkter GitHub-URL-Installation (Dietmar + Testerkreis, kein Review nötig). **Hier committen und pushen, solange nicht ausdrücklich anders vereinbart.**
- **`main`**: Store-geprüfter Stand. Wird über den IP-Symcon Module Store bezogen (fremde Nutzer). Nur bei einer bewussten neuen Store-Einreichung nach expliziter Bestätigung durch Dietmar aktualisieren (Merge/Fast-Forward main←beta).

`beta` wurde am 21.07.2026 additiv von `main` abgezweigt (identischer Commit zum Zeitpunkt der Abzweigung) — kein Verlust, keine Divergenz zu dem Zeitpunkt.

## Verwandte Repositories (loser Verbund, kein Kopplungszwang)

An mehreren DG65-Modulrepos wird teilweise **gleichzeitig in getrennten Sitzungen** gearbeitet: MeterHub, Prognose, InverterHub, HeishaMon, StromGedacht, TibberGridRewards, EMS. Vereinbarte Spielregeln:

1. **Kein eigenmächtiges Arbeiten in fremden Repos.** Wird dort etwas gebraucht, anfragen statt selbst committen.
2. **Öffentliche Funktionen sind der Vertrag.** Ändert sich eine Signatur, wird das angekündigt.
3. **Commit-Hygiene:** kein `git add -A` (nur eigene Dateien stagen), vor dem Commit `git pull --rebase`.

**Kopplung zu InverterHub (Wechselrichter/Wallbox, github.com/DG65/InverterHub):** rein konfigurativ, keine Code-Abhängigkeit. Deren Stromfluss-Kachel hat eine herstellerneutrale Fahrzeugtabelle (Bezeichnung, Verbunden-Bedingung, SOC-Variable) — Nutzer tragen dort Tessie-Variablen-IDs manuell ein, oder nutzen den Getter unten.

### Öffentliche Schnittstelle für Fremdmodule

`TESSIE_GetVehicleState($id): string` (JSON) — siehe [README.md](README.md#integration-in-andere-module) für den vollständigen Vertrag:

```json
{ "instanceID": 41537, "name": "Schneeflocke", "vin": "5YJ...", "socID": 16201, "soc": 92.0, "connected": true }
```

Rein additiv/lesend. Aufrufer sollten `function_exists('TESSIE_GetVehicleState')` prüfen.

**Wichtig:** Da InverterHub Variablen-**IDs** speichert, übersteht eine Umbenennung von Telemetrie-Variablen das problemlos — ein **Neuanlegen** (neue Objekt-ID, z. B. weil ein Datenpunkt gelöscht und neu erzeugt wird) aber nicht. Falls das ansteht: InverterHub-Seite vorher informieren, damit sie es im Changelog erwähnen können.

## Weitere Hinweise

Siehe `README.md` für Nutzerdokumentation, `CHANGELOG.md` für die Versionshistorie. Enum-/Profilwerte, die von Tesla stammen (z. B. Klimahaltung, Ladestatus), vor Änderungen möglichst gegen die offizielle [Tesla Fleet Telemetry Proto-Definition](https://github.com/teslamotors/fleet-telemetry/blob/main/protos/vehicle_data.proto) verifizieren statt zu raten — hat sich beim Klimahaltung-„Camping"/„Party"-Fall bewährt (siehe CHANGELOG 2.16.1).
