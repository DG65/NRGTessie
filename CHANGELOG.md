# Changelog

Alle nennenswerten Änderungen an diesem Modul. Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/).

## [2.16.1] - 2026-07-13
### Behoben
- Klimahaltung: Bezeichnung fuer Wert 3 von "Camping" auf "Camp-Modus" korrigiert (Name aus dem Fahrzeugmenue). Der Wert selbst war bereits korrekt (Kommando set_climate_keeper_mode nutzt mode=3 fuer Camp Mode); nur die Anzeige-Beschriftung war irrefuehrend. Hintergrund: die Fahrzeug-Telemetrie meldet diesen Zustand als "ClimateKeeperModeStateParty" zurueck (laut Tesla Fleet-Telemetry-Protokoll seit dessen Einfuehrung), waehrend das Schreib-Kommando weiterhin "Camp Mode" heisst - syncFromTelemetry() erkennt beide Begriffe und mappt sie auf denselben Wert.

## [2.16.0] - 2026-07-04
### Hinzugefuegt
- Automationen-Editor der Kachel: Der Vergleichswert im "Wenn"-Bereich wird jetzt als Dropdown mit Klartext angeboten, sobald der gewaehlte Datenpunkt bekannte Profil-/Presentation-Werte hat (Sitzheizung Fahrer/Beifahrer, Klimahaltung, Innenraum-Ueberhitzeschutz-Limit) - analog zum bereits vorhandenen Dropdown fuer "Wert setzen" auf der Ziel-Seite (nach dem in StromGedachtWidget bewaehrten Muster). Zwei Verbesserungen dabei: Profilwerte werden sofort abgefragt, sobald eine Bedingungszeile aufgebaut wird (auch bei der automatischen Vorauswahl, nicht erst beim manuellen Wechsel); und wenn Profilwerte eintreffen waehrend die Bedingung noch auf "wird EIN/AUS" steht, springt sie automatisch auf "= (gleich)", da EIN/AUS bei einem mehrwertigen Datenpunkt keinen Sinn ergibt. Im klassischen Instanzformular (dort technisch nicht als Dropdown moeglich) listet ein Hilfetext die bekannten Werte zum Nachschlagen.

## [2.15.0] - 2026-07-03
### Behoben
- "Aktueller Standort" blieb nach Anlegen/Aendern eines Standorts (z. B. ueber die Kachel) bis zur naechsten GPS-Telemetriemeldung auf dem alten Wert stehen (z. B. weiterhin "Unterwegs" trotz neu eingerichtetem, zutreffendem Standort). ApplyChanges wertet die Standort-Erkennung jetzt sofort mit der zuletzt bekannten Position neu aus.
### Hinzugefuegt
- Optionales Icon/Emoji je weiterem Standort (Feld 'Icon' in der Standortliste bzw. Icon-Eingabe mit Vorschlaegen im Kachel-Editor) statt des festen Pin-Symbols - erscheint in der Standortliste und in der "Aktueller Standort"-Anzeige der Kachel. Zuhause bleibt bei 🏠.

## [2.14.0] - 2026-07-02
### Hinzugefuegt
- Buttons vollstaendig in der Kachel verwaltbar: Stift-Symbol neben "Buttons" schaltet in eine Verwaltungsansicht (Funktion/Beschriftung aendern via Editor-Overlay, Reihenfolge per Auf/Ab-Pfeile, Loeschen mit Rueckfrage, "+ Neuer Button"). Schreibt direkt die eigene Buttons-Eigenschaft der Kachel-Instanz, keine Quellen-Instanz noetig. Neue Public-API: TESSIE TILE-eigene Methoden GetButtonCatalog/SetButtonConfig/DeleteButtonConfig/MoveButtonConfig.
- Automationen: mehrere Bedingungen pro Regel, mit UND verknuepft (z. B. "Wenn Zu Hause wird EIN UND Ladestand < 30 % -> Ladeport oeffnen"). Neues Speicherformat 'Conditions' (Liste von {Source,Op,Compare}), Regel feuert erst wenn ALLE Bedingungen gleichzeitig erfuellt sind (weiterhin flankengesteuert; Durchfahrt/aendert-sich-Bedingungen bleiben momentan). Bestehende Regeln im alten flachen Format funktionieren unveraendert weiter. Der Kachel-Regeleditor erlaubt beliebig viele UND-Bedingungen ("+ Bedingung"); die klassische Formular-Liste bleibt auf eine Bedingung beschraenkt und zeigt bei Mehrfachregeln nur die erste (siehe Doku-Hinweis im Formular).

## [2.13.0] - 2026-07-01
### Hinzugefuegt
- Bedien-Buttons der Kachel sind jetzt frei konfigurierbar: neue Liste "Buttons" waehlt Anzahl, Reihenfolge (Drag & Drop) und Funktion aus einem Katalog von 19 Aktionen (Verriegeln, Klima, Laden, Sentry Mode, Valet-Modus, Max Defrost, Lenkradheizung, Innenraum-Ueberhitzeschutz, Bio Defense Mode, HomeLink, vorderer/hinterer Kofferraum, Lichthupe, Hupe, Ladeport oeffnen/schliessen, Fenster lueften/schliessen) mit optionaler eigener Beschriftung je Button. Bestehende Kacheln zeigen nach dem Update unveraendert die bisherigen drei Buttons (Verriegeln/Klima/Laden). Ein Button erscheint nur, wenn der zugehoerige Datenpunkt in der TessieVehicle-Quelle aktiviert ist.
### Geaendert
- Die Kachel beobachtet (VM_UPDATE) jetzt nur noch die tatsaechlich konfigurierten Button-Variablen statt eines festen Satzes.

## [2.12.0] - 2026-06-30
### Hinzugefuegt
- Standort-Verwaltung in der Kachel: Neuer Bereich "Standorte" unter den Automationen - Standort-Erkennung per Schalter aktivieren, Standorte anlegen/bearbeiten/loeschen (Name, Koordinaten, Umkreis). Die aktuelle Fahrzeugposition laesst sich per Knopf als Standort uebernehmen. Zuhause ist bearbeitbar (leer = Systemstandort); die Kartenauswahl bleibt im Instanzformular. Neue Public-API: TESSIE_GetGeofenceConfig, TESSIE_SetGeofenceEnabled, TESSIE_SetHomeGeofence, TESSIE_SetGeofence, TESSIE_DeleteGeofence.

## [2.11.1] - 2026-06-29
### Geaendert
- Das separate Panel "Standort-Erkennung (Geofence)" entfaellt: Die Standort-Definition (Aktivierung, Zuhause, weitere Standorte mit Umkreis) ist jetzt als Abschnitt "Standorte (Geofence)" direkt im Panel "Automationen (Wenn -> Dann)" integriert - alles Standortbezogene an einem Ort. Eigenschaften unveraendert, keine Neukonfiguration noetig.

## [2.11.0] - 2026-06-28
### Geaendert
- Standort-Aktionen in die Automationen integriert (Doppelung entfernt): Die separate Liste "Standort-Aktionen" entfaellt. Standort-Ereignisse laufen jetzt ueber die Wenn->Dann-Regeln - "wird EIN" auf einer Standort-Variable = Einfahrt, "wird AUS" = Ausfahrt, neue Bedingung "Durchfahrt (Standort)" = Ein- und Ausfahrt binnen 15 Minuten. Die Standorte selbst (Name/Position/Umkreis) werden weiterhin in der Standort-Erkennung definiert. Bestehende GeoActions-Regeln muessen einmalig als Automation neu angelegt werden.
### Korrigiert
- Kachel-Editor: Beim erneuten Oeffnen einer Regel wurde die Zielvariable nur als #ID angezeigt, wenn sie nicht unter den ersten 200 Listeneintraegen war. Jetzt wird der Name/Pfad aus der Gesamtliste nachgeschlagen; #ID erscheint nur noch bei tatsaechlich unbekannten Variablen.

## [2.10.2] - 2026-06-27
### Geaendert
- Feedback-Hinweis verlinkt jetzt direkt auf den Modulvorstellungs-Thread in der Symcon-Community statt auf die Kategorie.

## [2.10.1] - 2026-06-26
### Hinzugefuegt
- Einmaliger Feedback-Hinweis am Ende des TessieVehicle-Formulars (Bewertung im Module Store / Rueckmeldung in der Symcon-Community, mit Link). Ueber den Button "Nicht mehr anzeigen" verschwindet er dauerhaft (Attribut, kein Uebernehmen noetig).

## [2.10.0] - 2026-06-25
### Korrigiert
- Automationen/Standort-Aktionen: Die dynamischen Auswahllisten (Datenpunkt bzw. Standort) blieben im Formular leer. Ursache: Iteration per Referenz ueber ($element['columns'] ?? []) - der ??-Ausdruck liefert eine Kopie, die Optionen gingen verloren. Jetzt wird direkt ueber $element['columns'] iteriert.
### Hinzugefuegt
- Regel-Editor in der Kachel: Wenn->Dann-Regeln lassen sich komplett in der Kachel anlegen, bearbeiten und loeschen ('+ Neue Regel', Stift, Papierkorb mit Rueckfrage). Der Editor bietet alle Datenpunkte als Quelle, schaltbare Variablen (mit Aktion) als Ziel mit Suchfeld ueber Name/Pfad, und bei 'Wert setzen' die im Profil bzw. der Darstellung definierten Werte als Auswahlliste (TESSIE_GetDataActionEditor, TESSIE_GetTargetValueOptions, TESSIE_SetDataAction, TESSIE_DeleteDataAction).

## [2.9.0] - 2026-06-24
### Hinzugefuegt
- Automationen (Wenn -> Dann): generische Regeln ueber beliebige Datenpunkte des Fahrzeugs (Property DataActions). Bedingungen: wird EIN/AUS, =, !=, >, >=, <, <=, aendert sich; Aktionen: Einschalten/Ausschalten/Umschalten/Wert setzen auf eine beliebige Zielvariable (RequestAction bzw. SetValue). Flankengesteuert - eine Regel feuert beim Eintreten der Bedingung, nicht bei jeder Datenmeldung; nach Uebernehmen wird der Ausgangszustand ohne Ausloesen neu eingelesen (Attribut RuleState). Datenpunkt-Auswahl dynamisch aus der Datenpunktliste + Geofence-Variablen.
- Kachel: neuer Bereich "Automationen" - listet die Wenn->Dann-Regeln der Quelle mit menschenlesbarem Text und schaltet sie per Toggle aktiv/inaktiv (TESSIE_GetDataActions/TESSIE_SetDataActionActive). Abschaltbar ueber die neue Option "Automationen anzeigen".
### Geaendert
- Standort-Aktionen nutzen intern denselben Aktions-Executor wie die neuen Automationen (applyActionToVariable).

## [2.8.0] - 2026-06-23
### Hinzugefuegt
- Standort-Aktionen: Regelliste in der Standort-Erkennung (Property GeoActions) - beim Ein-, Aus- oder Durchfahren eines Standorts wird eine beliebige Zielvariable geschaltet (Einschalten/Ausschalten/Umschalten/Wert setzen). Durchfahrt = Einfahrt mit Ausfahrt binnen 15 Minuten. Ausfuehrung per RequestAction (Variablen mit Aktion) bzw. SetValue; ausgeloest nur beim Zonenwechsel, die erste Positionsmeldung initialisiert nur den Zustand. Die Standort-Auswahl der Liste wird dynamisch aus den konfigurierten Geofences befuellt.
- Kachel: zeigt den aktuellen Standort (Variable stat_location_name der Quelle) als Pin-Pill in der Statuszeile.

## [2.7.0] - 2026-06-22
### Hinzugefuegt
- In-Modul-Dokumentation: Aufklappbares Panel "Dokumentation & Hilfe" als erstes Formularelement in allen drei Instanzen (TessieVehicle, TessieVehicleTile, TessieConfigurator) - erklaert Funktionsweise, Datenpunkte-Liste, Standort-Erkennung, Linkstruktur, Kachel-Optionen und Token-Erstellung, inklusive erklaerender Grafiken (Datenfluss Tessie-Cloud, Geofence-Prinzip).

## [2.6.1] - 2026-06-21
### Korrigiert (Review-Anpassung)
- Translate() wird jetzt korrekt mit englischen Quellbegriffen aufgerufen ("On the road", "Away") und die deutschen Uebersetzungen ("Unterwegs", "Abwesend") stehen in der locale.json. Vorher wurden die deutschen Begriffe direkt als Quellstring uebergeben.

## [2.6.0] - 2026-06-20
### Hinzugefuegt
- Standort-Erkennung: Bleibt "Standort Zuhause" leer, wird automatisch der Systemstandort aus der Kern-Instanz "Location Control" uebernommen (dort ist das Zuhause bei der Symcon-Installation ohnehin gepflegt). Eine eigene Angabe im Modul hat weiterhin Vorrang.

## [2.5.0] - 2026-06-19
### Hinzugefuegt
- Standort-Erkennung mit mehreren Geofences: Zusaetzlich zum Zuhause-Standort koennen beliebige weitere Standorte (Name, Kartenposition, Radius) als Liste konfiguriert werden. Je Standort entsteht eine Boolean-Variable (stat_geo_*), dazu eine neue Textvariable "Aktueller Standort" (stat_location_name) mit dem Namen des naechsten passenden Standorts bzw. "Unterwegs". Bei ueberlappenden Zonen gewinnt der naechstgelegene Standort. Nicht mehr konfigurierte Geofence-Variablen werden ausgeblendet statt geloescht.
### Geaendert
- Panel "Zuhause-Erkennung" heisst jetzt "Standort-Erkennung (Geofence)"; die Variable "Zu Hause" (stat_at_home) bleibt unveraendert kompatibel.

## [2.4.0] - 2026-06-18
### Hinzugefuegt
- Zuhause-Erkennung (Geofence): Neue optionale Status-Variable "Zu Hause" (stat_at_home). Aus der GPS-Position (Telemetrie "Fahrzeugposition") und einem konfigurierbaren Standort + Radius wird per Haversine-Distanz automatisch true/false gesetzt. Konfiguration ueber neues aufklappbares Panel "Zuhause-Erkennung" (Aktivierung, Standort per Karte, Radius in Metern). Ersetzt externe Skript-/Ereignis-Loesungen (Bounding-Box). Variable bleibt bei Deaktivierung erhalten (nur ausgeblendet), Auswertung unabhaengig von der Auto-Discovery der Telemetrie.

## [2.3.5] - 2026-06-17
### Geaendert (Review-Anpassung)
- Kachel (TessieVehicleTile): Button "Stil zuruecksetzen" (ResetStyle) schreibt die Farben/Schrift-Eigenschaften nicht mehr direkt per IPS_SetProperty+IPS_ApplyChanges, sondern setzt sie nur in der offenen Konfiguration via UpdateFormField. Der Nutzer uebernimmt selbst (vom Symcon-Review empfohlenes Muster).

## [2.3.4] - 2026-06-16
### Geaendert (Review-Anpassung)
- Formular-Buttons (Reset Standardliste, Telemetrie alle/nur wichtige einblenden) schreiben nicht mehr direkt die Eigenschaft per IPS_SetProperty+IPS_ApplyChanges, sondern aktualisieren nur die offene Konfiguration via UpdateFormField. Der Nutzer bestaetigt selbst mit "Aenderungen uebernehmen" (vom Symcon-Review empfohlenes Muster). Gemeinsamer Helfer buildFormRows() fuer die Anzeigespalten.

## [2.3.3] - 2026-06-16
### Geaendert (Review-Anpassungen)
- Vendor in allen Modulen auf "Tessie Technology LLC" (Entwickler der genutzten API) gesetzt.
- Ablageort der Instanz: Die Eigenschaft InstanceLocation wurde entfernt (Eigenschaften liegen in der Hoheit des Nutzers; die Instanz wurde zuvor bei jedem Uebernehmen zurueckgeschoben). Das SelectCategory bleibt, wird in GetConfigurationForm mit dem aktuellen Parent gefuellt und verschiebt die Instanz nur einmalig per onChange (TESSIE_SetInstanceLocation). Wird die Instanz spaeter regulaer verschoben, zeigt das Feld beim naechsten Laden den tatsaechlichen Ort.
### Hinweis
- Der vom Reviewer genannte ensureVisibleVarsMerged-Punkt (Property-Schreiben in ApplyChanges) war bereits zuvor behoben: kein Property-Schreiben mehr, loadValuesFromConfiguration=false, zusaetzliche Werte werden bei GetConfigurationForm in die values der Liste eingetragen.

## [2.3.2] - 2026-06-16
### Geaendert
- Dokumentation aktualisiert: Installation jetzt ueber den Module Store (Suche "Tessie"); veralteten Beta-Branch-Hinweis entfernt; TessieVehicleTile als optionalen Schritt ergaenzt; Hinweis zum Kostenvorteil der Tessie-API gegenueber der nutzungspflichtigen Tesla Fleet API.

## [2.3.1] - 2026-06-16
### Geaendert
- Kachel (TessieVehicleTile): Fahrzeugname nicht mehr doppelt - der Name in der Karte entfaellt (er steht bereits im Kachel-Titel); die Verriegelt-Pille rueckt in die Statuszeile. Neue Option "Kachelname automatisch vom Fahrzeug uebernehmen" (Standard an) benennt die Kachel-Instanz nach dem verbundenen Fahrzeug.

## [2.3.0] - 2026-06-16
### Hinzugefuegt
- Neues Modul **TessieVehicleTile**: eigenstaendige HTML-Kachel (eigene Instanz) fuer die Kacheln-Visualisierung, nach Vorbild des TibberGridRewardTile. Zeigt Akku-Ring/Ladestand, Reichweite, Temperaturen, Verriegelungs-/Klima-/Ladestatus, Lade-Details und Standort einer TessieVehicle-Instanz; optionale Bedien-Buttons (Verriegeln/Klima/Laden) werden an die Quelle weitergereicht. Quelle wird automatisch erkannt (oder per SelectInstance gewaehlt); Live-Updates via VM_UPDATE; Farben/Schrift konfigurierbar, sonst IPS-Theme.
### Geaendert
- Der in 2.2.0 direkt in TessieVehicle integrierte Kachel-Ansatz (GetVisualizationTile im Geraet) wurde wieder entfernt und durch das separate Tile-Modul ersetzt - so bleibt die Datenlogik der Geraete-Instanz unberuehrt.

## [2.2.0] - 2026-06-16
### Hinzugefuegt
- HTML-Visualisierungskachel (HTML-SDK) direkt in TessieVehicle: Akku-Ring mit Ladestand, Reichweite, Innen-/Aussentemperatur, Verriegelungs-/Klima-/Ladestatus, Lade-Details (Leistung, Ladestrom, Limit, Zeit bis voll), Standort mit Karten-Link sowie Bedien-Buttons (Verriegeln, Klima, Laden). Aktivierung per SetVisualizationType(1); Live-Updates per UpdateVisualizationValue bei Telemetrie/Aktion; Buttons via requestAction -> RequestAction. Layout in TessieVehicle/module.html.

## [2.1.7] - 2026-06-16
### Geaendert
- Dokumentation ueberarbeitet: README mit Markenhinweis (kein Bezug zu Tesla/Tessie), Haftungsausschluss, Changelog-Verweis und genauerer Konfigurationsbeschreibung. Vollstaendige MIT-Lizenz in LICENSE (Copyright Dietmar Gureth). Dokumentations-Link (url) in library.json und module.json auf das GitHub-Repository umgestellt.

## [2.1.6] - 2026-06-16
### Geaendert
- Deutsche Namen fuer "Climate Keeper Mode" -> "Klimahaltung", "Front-Trunk oeffnen" -> "Vorderer Kofferraum oeffnen", "Rear-Trunk oeffnen/schliessen" -> "Heckklappe oeffnen/schliessen". Bestehende Variablen werden umbenannt, sofern ihr Name noch der alte englische Default ist (eigene Umbenennungen bleiben erhalten).

## [2.1.5] - 2026-06-16
### Behoben
- GPS-Koordinaten (Fahrzeug-/Zielposition, Lat/Lon) wurden ohne Nachkommastellen angezeigt, obwohl Float. Diese Variablen bekamen die Standard-Praesentation mit 0 Stellen. Jetzt 6 Nachkommastellen (~0,11 m Genauigkeit).

## [2.1.4] - 2026-06-16
### Behoben
- Die per Liste festgelegte Reihenfolge wirkte sich nicht auf die Variablen im Objektbaum aus (das eigentliche Problem hinter "laesst sich nicht verschieben"). Ursache: MaintainVariable setzt die Position nur bei der Neuanlage, nicht fuer bestehende Variablen. ensureVariables fuehrt die Position jetzt explizit per IPS_SetPosition nach (nur bei Abweichung), analog HeishaMon. Drag&Drop bzw. die Listenreihenfolge schlaegt damit auf die Objektbaum-Reihenfolge durch.

## [2.1.3] - 2026-06-14
### Behoben
- Drag & Drop der Datenpunkte ging weiterhin nicht, obwohl die Liste strukturell mit HeishaMon identisch war. Entscheidender Unterschied: HeishaMon definiert das Formular in einer statischen form.json und injiziert nur die Werte, Tessie baute das komplette Formular dynamisch in GetConfigurationForm. Die IPS-Konsole aktiviert die Sortierung offenbar nur fuer Listen aus der form.json. Tessie nutzt jetzt dasselbe Muster: Struktur in TessieVehicle/form.json, GetConfigurationForm injiziert nur values + Toggle-Status.

## [2.1.2] - 2026-06-14
### Behoben
- Datenpunkte ließen sich nicht per Drag & Drop sortieren (HeishaMon konnte es, Tessie nicht). Ursache gefunden: Tessie schrieb die Listen-Property `VisibleVars` per `IPS_SetProperty` waehrend `ApplyChanges` (Funktion `ensureVisibleVarsMerged`) - das bringt die an die Property gebundene Liste in einen Zustand, in dem IPS das Umsortieren sperrt. HeishaMon macht das nicht. Aufruf entfernt; `loadValuesFromConfiguration` wie bei HeishaMon auf `false`. Vollstaendigkeit/Positionen kommen weiter aus getEffectiveList().

## [2.1.1] - 2026-06-14
### Geaendert
- Reihenfolge wieder per Drag & Drop (editierbare Zahlenspalte aus 2.1.0 entfernt - war unpraktisch). Hinweis: Falls sich die Liste nicht verschieben laesst, liegt es an inkonsistentem gespeichertem Zustand aus aelteren Versionen - dann einmal "Reset: Standardliste wiederherstellen" druecken oder die Instanz neu anlegen.

## [2.1.0] - 2026-06-14
### Geaendert
- Reihenfolge der Datenpunkte jetzt ueber eine editierbare Spalte "Reihenfolge" (Zahl, kleinere = weiter oben) statt Drag & Drop. Drag&Drop (changeOrder) war in IPS 9.0 hier nicht zuverlaessig; die Spalte mit Sortierung nutzt nur dokumentierte, stabile List-Features. Variablen-Positionen und Links folgen der Reihenfolge.

## [2.0.5] - 2026-06-14
### Behoben
- Configurator: Fatal `json_decode(): Argument #1 must be string` beim Anlegen/Sync von Fahrzeugen, wenn `IPS_GetConfiguration()` waehrend einer Instanz-Erstellung null lieferte. Alle Konfig-Zugriffe laufen jetzt ueber einen abgesicherten Helfer (readInstanceConfig). Toter `IPS_GetProperty('InstanceInterface')`-Fallback entfernt (verursachte die Warnung "InstanceInterface is not available").

## [2.0.4] - 2026-06-14
### Behoben
- Datenpunkte ließen sich weiterhin nicht per Drag & Drop sortieren. Ursache: bei der Angleichung an HeishaMon war `loadValuesFromConfiguration: false` gesetzt, wodurch die Liste rein aus berechneten Werten statt aus der Property gefüllt wurde - die geänderte Reihenfolge konnte so nicht gespeichert werden. Jetzt `true`: die Liste ist property-gestützt (Reihenfolge wird gespeichert), die berechneten Spalten Name/Gruppe/Empfangen werden weiterhin ergänzt.

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
