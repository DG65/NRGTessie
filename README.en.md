# Tessie for Symcon

[Deutsche Fassung: README.md](README.md)

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Module version](https://img.shields.io/badge/Module_Version-2.39.1-blue)
![Symcon version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGTessie/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGTessie/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Connects Tesla vehicles to Symcon through the [Tessie API](https://developer.tessie.com/) – control via REST and live telemetry via WebSocket.

> Unofficial community module. Not developed, supported or approved by Tesla, Inc. or Tessie. See [Trademark notice](#trademark-notice) and [Disclaimer](#disclaimer).

> Part of the **NRG-Stack**, the energy module family by DG65. The module is fully usable on its own.

## Features

- **Vehicle control** through the Tessie REST API: lock/unlock, climate on/off, target temperatures, seat/steering wheel heating, start/stop charging, charge limit, charge current, charge port, vent/close windows, horn, flash lights, sentry mode/valet mode, front trunk/rear trunk, HomeLink, cabin overheat protection, climate keeper and more.
- **Live telemetry** through the Tessie WebSocket stream (the access token is passed as `?access_token=…` in the URL).
- **Automatic telemetry variables**: new data points are created on demand; values are prepared in a **readable** form
  - enum states as plain text (e.g. `DefrostModeStateOff` → "Defrost: off") via `locale.json`, with a generic fallback for unknown codes,
  - metric units (mph→km/h, mi→km), GPS coordinates with 6 decimal places,
  - timestamps as dates,
  - nested states (e.g. doors) as a readable list.
- **Symcon 9.0 presentations** for display (switch/slider/selection/value) instead of variable profiles.
- **Configurable data point list**: show/hide and sort by drag and drop; the order applies to variables and links. Deselected data points are **hidden instead of deleted** – the object ID and archive data are preserved.
- **Optional link structure**: grouped category tree (Charging / Climate / Security / Other) with links to the active variables.
- **Location detection (geofence)**: optional status variables derived from the GPS position and configurable locations (home plus any number of further places, each with a name, radius and optional icon/emoji for the tile display). Each location gets a boolean variable, plus a text variable "Current location" (location name or "On the road") – ideal as a trigger for automations (e.g. only charge at home).
- **Automations (If → Then)**: generic rules over any data point – "If charge level ≥ 80 → switch off wallbox charging enable", "If current location = Home → switch on garage door". Conditions: becomes ON/OFF, =, ≠, >, ≥, <, ≤, changes, drives past (location). For data points with known values (seat heater, climate keeper, overheat protection limit) the tile shows the comparison value as a dropdown with plain text instead of free text, and the condition automatically switches from "becomes ON/OFF" to "=" – also **several conditions combined with AND** (e.g. "If At home becomes ON AND charge level < 30 → open charge port"), configurable in the tile editor. Location events also run through this: "becomes ON" on a location variable = arrival, "becomes OFF" = departure, "drives past" = arrival and departure within 15 minutes. The tile shows the current location as a 📍 pill. Edge-triggered (fires when the condition becomes true, not on every message). Rules **and locations** can be **managed entirely in the tile**: create, edit, delete, enable/disable – including a search field for the target variable, a selection list of the profile/presentation values for "Set value", and taking over the current **vehicle position** as a new location (drive there, save, done).

## Included modules

| Module | Type | Description |
|------|-----|--------------|
| **TessieConfigurator** | 4 (Configurator) | Reads the vehicles belonging to the Tessie access token and creates a TessieVehicle instance with a WebSocket client for each vehicle. |
| **TessieVehicle** | 3 (Device) | Control and telemetry of one vehicle. Sits under an MQTT/WebSocket client. |
| **TessieVehicleTile** | 3 (Visualization) | Standalone HTML tile for the tile visualization: shows charge level, range, status, charging details and location of a TessieVehicle instance and optionally offers control buttons. Deliberately separate from the data logic. |

Prefix of the public functions: `TESSIE`.

## Requirements

- Symcon version 9.0 or higher
- A [Tessie](https://tessie.com/) account with an **access token** – it covers queries and telemetry together

> Why Tessie instead of the official Tesla API? Tessie is an established third-party service that wraps access behind a simple access token. The **Tesla Fleet API** charges third parties usage-based fees (per command/request), requires your own developer/domain registration and is considerably harder to set up. With a Tessie subscription the API can be used without additional per-use costs and with minimal setup – which makes the integration practical in Symcon.

## Installation

1. Search for **"NRG-Stack Tessie"** in the Symcon **Module Store** and install it.
   _Alternatively_ add the repository manually under **Core Instances → Modules**: `https://github.com/DG65/NRGTessie`
2. Create an instance of the **Tessie Configurator** and enter the Tessie access token.
3. Create the vehicles found in the Configurator – TessieVehicle and the WebSocket client are created automatically.
4. Optionally create a **TessieVehicleTile** instance for the tile visualization (see below).

## Configuration (TessieVehicle)

- **Update interval** for REST status queries (seconds; 0 = off, telemetry keeps running independently).
- **Data points**: list for showing/hiding and sorting (drag and drop). Columns: Active, Name, Group, Ident, Received.
- **Link structure** (collapsible): target category, create links / clean up automatically.
- **Automations (If → Then)** (collapsible): rule list plus a **Locations (geofence)** section – enable, choose the home location (map) and radius — if "Home location" is left empty, the **system location** from the core instance "Location" is used automatically; below that, any number of **further locations** (name, map position, radius) as a list. Each location creates a boolean variable ("At home" or the location name), plus the text variable "Current location". With overlapping zones the nearest location wins. Requires active GPS telemetry (data point "Vehicle position"). When disabled/removed the variables are kept (only hidden).
- Buttons: reset default list, show all/only important telemetry, refresh telemetry names, force apply, **Wake vehicle now** (Tessie API "/wake" – useful when telemetry has been asleep for a longer time, e.g. recognizable by the instance status "Telemetry not updated", and current data is needed).

## Notes

- Telemetry values are prepared **on receipt**; rarely sent/stale values are additionally made readable retroactively when the instance is **applied**.
- If an enum code has no translation, a generic fallback applies (CamelCase → words). Specific translations can be added in `TessieVehicle/locale.json`.
- **`stat_tel_DoorState`** (data point "Door/hatch status"): comma-separated list of the **currently open** doors/hatches, an empty value = everything closed. Possible entries: "Driver door front", "Door rear left", "Passenger door front", "Door rear right", "Frunk" (front trunk), "Trunk" (rear hatch) – verified live against a real vehicle. Relevant for consumers that want to react automatically: `act_front_trunk`/`act_rear_trunk` send Tesla's plain toggle command without a direction (no "close", only "trigger") – before triggering automatically you should therefore always check via `stat_tel_DoorState` whether the hatch is open at all. The frunk cannot be closed through the Tesla API at all, only opened – so after opening it stays in the list until it is closed by hand.
- The instance status (icon in the object tree) shows operating problems without needing log/debug access: **API error** (access token invalid or Tessie API repeatedly unreachable) and **telemetry not updated for more than 15 minutes** (warning, not a hard error – e.g. when Tesla's push transmission breaks off in the background).

## Language

The module is bilingual (German/English): all texts the user sees – form, messages, variable names, status lines and the tile – follow the language of the Symcon installation (console language / Locale option). English is the source language, German is provided through the `locale.json` files of the three modules. Identifiers (idents, property and function names, keys of the `TESSIE_GetVehicleState` contract) are language-independent and never change with the language. Variables that already exist keep their names; new variables are named in the language active when they are created.

## Tile (TessieVehicleTile)

For a nice display in the **tile visualization**, create an instance of the **TessieVehicleTile** module. The data source is detected automatically if there is exactly one TessieVehicle instance – with several you choose it manually. The tile shows battery ring/charge level, range, temperatures, lock/climate/charging status, charging details and location (with map link) and optionally offers freely configurable control buttons: you choose the number, order and function from a catalog of 19 actions (lock, climate, charging, sentry mode, valet mode, trunk/rear hatch, horn, flash lights, charge port, windows, seat/steering wheel heating, overheat protection, bioweapon defense mode and more) – each with an optional custom label. A button only appears if the corresponding data point is enabled in the source. With the pencil ✎ next to "Buttons" the same configuration can also be managed directly in the tile: add, change function/label, order with ▲▼, delete. Colors and font are configurable; without a fixed color the tile adopts the Symcon theme (light/dark).

Status/telemetry values (charge level, range, temperatures, location …) only appear if the corresponding data points are enabled in the TessieVehicle instance.

## Integration into other modules

`TESSIE_GetVehicleState($id)` returns a manufacturer-neutral state query as JSON – intended for modules such as a wallbox/power flow tile that want to assign a vehicle automatically instead of via a manually entered variable ID:

```json
{
  "contractVersion": "1.6",
  "instanceID": 12345,
  "name": "My car",
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
  "batteryCapacityKwh": 74.3,
  "distanceToHomeKm": 105.1,
  "headingHome": false,
  "expectedHomeArrivalSocPercent": null,
  "rangeKm": 312.4,
  "vehicleStatus": "asleep"
}
```

- `contractVersion`: version of this data contract as `Major.Minor` (currently `1.6`). Consumers check the major version for compatibility – within the same major only additive fields are added, a break raises the major. If a field is missing, the next lower version applies.
- `socID`: variable ID of the charge level (0 if the data point is not enabled)
- `soc`: measured actual charge level in % (reliable; target SoC and deadline are held by the controlling module itself)
- `connected`: whether a charging cable is plugged in (not: whether charging is currently active) – determined primarily from the data point "Charge state (detail)", alternatively from the charge state; `null` if neither source is available
- `chargingID`: variable ID of the raw telemetry charge state (`stat_tel_DetailedChargeState`, string; 0 if the data point does not exist) – a real, permanently logged Symcon variable with its own `VariableChanged` event, intended for consumers that depend on the change timestamp (e.g. a time-based correlation wallbox↔vehicle). Deliberately not the action variable of the charging switch: its timestamp only changes for commands triggered through Symcon, not for charging sessions started e.g. through the Tesla app or scheduled charging
- `charging`: whether charging is currently active, derived from telemetry (`stat_tel_DetailedChargeState` contains "Charging") – a pure snapshot from this call, use `chargingID` for change detection
- `chargeLimit`: configured charge limit in %
- `chargeAmpsRequest` / `chargeAmpsMax`: requested and maximum charge current in A
- `atHome`: whether the vehicle is at the "Home" location – only set if location detection is active, otherwise `null` (the planning prerequisite is typically "plugged in **and** at home")
- `scheduledChargingActive`: whether the vehicle **itself** has scheduled charging/a scheduled departure active – important as a two-controller hint: if this is `true`, the car controls its charging in parallel, and an external controller (EMS/wallbox) should step back or ask the user to switch off the in-vehicle schedule. `null` if the data point is not available
- `scheduledDeparture`: departure time scheduled in the vehicle as pure information (formatted text, empty if not set)
- `energyRemainingKwh`: remaining energy in kWh, directly from telemetry
- `batteryCapacityKwh`: battery capacity in kWh, extrapolated from remaining energy and SoC – Tesla provides the actual capacity through no available API directly, so this is an approximation. `null` at very low SoC (rounding errors increase sharply there)
- `distanceToHomeKm`: distance from the current vehicle position to home in km (home coordinates generically from the HomeLocation property, otherwise system location fallback – as with location detection). `null` without configured home coordinates or without current position telemetry
- `headingHome`: whether the **current navigation destination** matches the home coordinates (500 m tolerance) – not whether navigation is active at all. `null` without active navigation or without home coordinates
- `expectedHomeArrivalSocPercent`: Tesla's own arrival SoC forecast, but **only** taken over if `headingHome === true` – for a different destination the forecast would be for that other destination and would be misleading in charge planning for charging at home. `null` otherwise
- `rangeKm`: Tesla's own range estimate in km at the **current** SoC (not normalized to 100 % SoC), directly from telemetry (`stat_tel_RatedRange`, miles→km conversion already done on receipt). No consumption value (kWh/100 km) is available – telemetry provides no raw value for it, and a self-calculated estimate was deliberately not added. `null` if the data point is not enabled/received
- `vehicleStatus`: raw sleep/wake state of the vehicle according to Tessie's own status query – one of `"asleep"`, `"waiting_for_sleep"` or `"awake"` (the only three values according to the official Tessie API documentation). Last known value from the module's own REST status query (update interval), `null` until the first successful query. Deliberately the raw value instead of an own assessment "healthy"/"suspicious": whether missing telemetry is unremarkable for a sleeping vehicle is decided by the respective consumer (e.g. a family-wide health check), not by this module – missing telemetry **despite** `"awake"`, on the other hand, points to a real problem (telemetry stream disconnected although the vehicle is reachable)

All additional fields are `null` if the corresponding data point is not enabled in the instance or has not been received yet. A purely additive, read-only function – changes nothing about the module's behavior. Other modules should guard the call with `function_exists('TESSIE_GetVehicleState')`.

> Note: The Tesla API has no field for **who** triggered a charge command. Whether an external controller (e.g. Tibber) is actively intervening cannot be reliably recognized from it – the controlling module is meant to mark this deliberately. `scheduledChargingActive` only covers the vehicle's own schedule.

## Changelog

All changes are documented in [CHANGELOG.md](CHANGELOG.md) (in German; format after [Keep a Changelog](https://keepachangelog.com/)).

## Trademark notice

"Tesla" is a trademark of Tesla, Inc. "Tessie" is a service of Tessie or the respective rights holders. This project has **no** connection to Tesla, Inc. or Tessie and is neither supported nor reviewed by them. All trademarks belong to their respective owners and are mentioned here only to describe compatibility.

## Disclaimer

This module controls a real vehicle (including lock/unlock, windows, trunk/rear hatch, charging, climate). Use is **at your own risk**.

- The software is provided **without any warranty** (see [License](#license)). No liability is accepted for damage, malfunctions, data loss, unintended vehicle actions or consequences of use, to the extent permitted by law.
- Function and availability depend on the Tessie API, the vehicle and the network connection and may change at any time.
- Safety-relevant actions (e.g. unlocking, opening windows/trunk) should not be automated without checking. Make sure that no people, animals or objects are endangered.
- The access token grants far-reaching access to the vehicle – please treat it confidentially.

## License

Released under the **PolyForm Noncommercial License 1.0.0** – © 2026 Dietmar Gureth (DG65). Private and non-commercial use is free; **commercial use requires a separate license** (contact: DG65). Donations are welcome and entirely voluntary. Full text in [LICENSE](LICENSE).

Older versions that were released under the MIT license remain MIT-licensed for the respective state; the new license applies from this version onward.
