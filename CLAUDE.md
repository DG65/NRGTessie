# Hinweise für die Arbeit an diesem Repository

## Branch-Workflow

> **⚠️ Verschärfung während der laufenden EMS-Integrationsphase (Dietmar, 25.07.2026): AUSNAHMSLOS alles auf `ems-integration` pushen — keine Ausnahme mehr für "sichere" Fixes auf `beta`.** Gilt, bis Dietmar/EMS das Ende der Phase ansagen; dann diesen Hinweis entfernen und zur Regel unten zurückkehren.

- **`beta`**: laufende Entwicklung und schnelle Auslieferung per direkter GitHub-URL-Installation (Dietmar + Testerkreis, kein Review nötig). Normalerweise hier committen und pushen — **außer während der EMS-Integrationsphase, siehe Warnhinweis oben.**
- **`main`**: Store-geprüfter Stand. Wird über den IP-Symcon Module Store bezogen (fremde Nutzer). Nur bei einer bewussten neuen Store-Einreichung nach expliziter Bestätigung durch Dietmar aktualisieren (Merge/Fast-Forward main←beta).
- **`ems-integration`**: Verbund-weiter Branch (identischer Name in allen Modul-Repos, Dietmar 25.07.2026), ursprünglich nur für riskante Fixes aus der EMS-Anbindung gedacht, seit der Verschärfung oben **der einzige Push-Ziel-Branch während der laufenden Integrationsphase**. Von `beta` abgezweigt (Fast-Forward auf `beta`-Stand gehalten, zuletzt 25.07.2026). Nach Ende der Phase: Merge zurück nach `beta`.

`beta` wurde am 21.07.2026 additiv von `main` abgezweigt (identischer Commit zum Zeitpunkt der Abzweigung) — kein Verlust, keine Divergenz zu dem Zeitpunkt.

## Verwandte Repositories (loser Verbund, kein Kopplungszwang)

An mehreren DG65-Modulrepos wird teilweise **gleichzeitig in getrennten Sitzungen** gearbeitet: MeterHub, Prognose, InverterHub, HeishaMon, StromGedacht, TibberGridRewards, EMS. Vereinbarte Spielregeln:

1. **Kein eigenmächtiges Arbeiten in fremden Repos.** Wird dort etwas gebraucht, anfragen statt selbst committen.
2. **Öffentliche Funktionen sind der Vertrag.** Ändert sich eine Signatur, wird das angekündigt.
3. **Commit-Hygiene:** kein `git add -A` (nur eigene Dateien stagen), vor dem Commit `git pull --rebase`.

**Kopplung zu InverterHub (Wechselrichter/Wallbox, github.com/DG65/NRGInverterHub):** rein konfigurativ, keine Code-Abhängigkeit. Deren Stromfluss-Kachel hat eine herstellerneutrale Fahrzeugtabelle (Bezeichnung, Verbunden-Bedingung, SOC-Variable) — Nutzer tragen dort Tessie-Variablen-IDs manuell ein, oder nutzen den Getter unten.

### Öffentliche Schnittstelle für Fremdmodule

`TESSIE_GetVehicleState($id): string` (JSON) — siehe [README.md](README.md#integration-in-andere-module) für den vollständigen Vertrag:

```json
{ "contractVersion": "1.4", "instanceID": 12345, "name": "Mein Auto", "vin": "5YJ...", "socID": 67890, "soc": 92.0, "connected": true, "chargingID": 67891, "charging": false, "chargeLimit": 80, "atHome": true, "scheduledChargingActive": false, "energyRemainingKwh": 68.4, "batteryCapacityKwh": 74.3, "distanceToHomeKm": 105.1, "headingHome": false, "expectedHomeArrivalSocPercent": null }
```

Rein additiv/lesend. Aufrufer sollten `function_exists('TESSIE_GetVehicleState')` prüfen.

**Vertragsversionierung (NRG-Stack-Konvention, [SUITE.md](https://github.com/DG65/NRGEMS/blob/main/SUITE.md)):** `contractVersion` = `Major.Minor` (String). **Additive** Felder erhöhen die Minor; eine **brechende** Änderung (Feld umbenennen/entfernen, Bedeutung/Einheit ändern) erhöht die Major — und nur dann. Kompatibilität gilt ausschließlich innerhalb derselben Major (blue'Log-Prinzip). Aktuell **1.4** (1.0 = name/vin/socID/soc/connected; 1.1 = + charging/chargeLimit/chargeAmps*/atHome/scheduledChargingActive/scheduledDeparture; 1.2 = + energyRemainingKwh/batteryCapacityKwh — `batteryCapacityKwh` ist aus Restenergie/SoC hochgerechnet, Tesla liefert keine Kapazität direkt; 1.3 = + chargingID — Variablen-ID von `stat_tel_DetailedChargeState` (Telemetrie-Rohwert, String) mit eigenem `VariableChanged`-Ereignis, für Konsumenten die auf Änderungs-Zeitstempel statt auf den reinen Momentanwert angewiesen sind, z. B. NRGDashboardTile::AssignVehicles() für die Wallbox-Fahrzeug-Korrelation — bewusst nicht die Aktionsvariable des Lade-Schalters, deren Zeitstempel nur bei über IP-Symcon ausgelösten Befehlen aktualisiert wird; 1.4 = + distanceToHomeKm/headingHome/expectedHomeArrivalSocPercent — für eine EMS-Preis-Reserve bei erwarteter Heimkehr. `headingHome` prüft das aktuelle Navigationsziel gegen die Heimkoordinaten (500 m Toleranz, generisch aus HomeLocation-Property/Systemstandort-Fallback), NICHT ob überhaupt navigiert wird. `expectedHomeArrivalSocPercent` übernimmt Teslas eigene Ankunfts-SoC-Prognose nur, wenn `headingHome === true` — sonst wäre es die Prognose fürs aktuelle Fremdziel und irreführend (Live-Fund: Dietmars Fahrt nach Philippsburg wurde anfangs fälschlich als Heimfahrt interpretiert). Eine "ist eine Rückfahrt heute noch plausibel?"-Reichweitenschwelle liegt bewusst NICHT hier, sondern bei EMS als eigene Geschäftsregel auf `distanceToHomeKm`). Fehlt ein Feld beim Konsumenten-Abgleich, gilt die jeweils niedrigere Version. **Vor jeder Vertragsänderung:** Minor hochzählen (additiv) bzw. bei Bruch Major + Ankündigung an den Verbund (EMS/InverterHub).

**Wichtig:** Da InverterHub Variablen-**IDs** speichert, übersteht eine Umbenennung von Telemetrie-Variablen das problemlos — ein **Neuanlegen** (neue Objekt-ID, z. B. weil ein Datenpunkt gelöscht und neu erzeugt wird) aber nicht. Falls das ansteht: InverterHub-Seite vorher informieren, damit sie es im Changelog erwähnen können.

## Sprachregel: alles Nutzersichtbare auf Deutsch

Verbund-Regel (Anweisung von Dietmar, 22.07.2026): keine vermeidbaren Anglizismen, keine englischen Ausdrücke oder Sätze in nutzersichtbaren Texten.

**Deutsch ist alles, was der Nutzer sieht:** Formularbeschriftungen, Hinweis-/Warntexte, Bestätigungsdialoge, Fehler- und Statusmeldungen, Rückgabe-Texte, Log-Meldungen, Variablen- und Profilnamen, Kachel-Oberfläche, README.

Ersetzungen: Button → Schaltfläche, Drag & Drop → Ziehen und Ablegen, Link → Verknüpfung, Event → Ereignis, Dry-Run → Probelauf, Token → Zugangsschlüssel.

**Vorgehen: satzweise umformulieren, nicht Wörter tauschen.** Wer den Satz neu schreibt, baut die typischen Fehler gar nicht erst ein (im Verbund von ChargerHub bestätigt). Drei Fallen aus der Praxis der anderen Module:
1. **Genus-Kongruenz** — „Button" (m) → „Schaltfläche" (f) bricht Artikel und Adjektivendungen: „Der Button" → „**Die** Schaltfläche", „einen zuverlässigen…" → „eine zuverlässig**e**…".
2. **Objekt-Verwechslung bei „scannen"** — unterscheiden, ob etwas *abgesucht* wird (→ durchsuchen/absuchen) oder etwas *gefunden* werden soll (→ finden). Englisch steht beide Male dasselbe Wort.
3. **Fachbegriffe nicht überdehnen** (Liste unten).

**Fachbegriffe, die hier bewusst englisch bleiben:** SoC, Supercharger, API, REST, WebSocket, Telemetrie-Feldnamen, IP-Symcon-Begriffe (WebFront, SelectVariable, Presentation, Ident) sowie Produkt-/Markennamen (Tesla, Tessie, HomeLink). Faustregel: eindeutschen, wo es das Verständnis verbessert; stehen lassen, wo der englische Begriff der Fachbegriff oder ein Produktname **ist**.

**Begriffe aus fremden Oberflächen:** deutsch zuerst, Originalbezeichnung in Klammern als Suchhilfe — z. B. „Zugangsschlüssel (bei Tessie 'Access Token')". So bleibt auffindbar, wonach man beim Anbieter suchen muss.

**Ausgenommen (bleibt englisch, Umbenennen würde Verträge brechen):**
- Bezeichner im Code: Klassen-, Methoden-, Variablen-, Property-Namen und **vor allem IDENTS** (z. B. bleibt die Eigenschaft `Token` im Konfigurator so heißen — nur ihre Beschriftung ist deutsch)
- Formularelementtypen (`"type": "Button"`, `"type": "List"`) sind Code, keine Anzeigetexte
- **IDENTS SIND API und werden nie umbenannt** (Verbund-Konvention) — Anzeigenamen dürfen sich ändern, Idents nie
- feststehende IP-Symcon-/Technikbegriffe sowie die Feldnamen der Tessie-/Tesla-API
- Produkt-/Markennamen (Tesla, Tessie, HomeLink)

**Besonderheit hier:** Tesla-API-Zustände (`ClimateKeeperModeStateParty`, `DetailedChargeStateCharging` …) sind Schnittstelle und bleiben als Schlüssel unverändert — beim **Anzeigen** greift die Übersetzung über `locale.json` bzw. deutsche Profil-Beschriftungen. Für Feature-Namen die Bezeichnungen der deutschen Tesla-Bedienungsanleitung verwenden (Wächtermodus, Biowaffen-Schutzmodus, Max. Entfrosten, Camp-Modus).

Achtung `$this->Translate()`: übersetzt **von Englisch** in die Serversprache — der Quellstring im Code bleibt also englisch, die deutsche Fassung gehört in `locale.json`. Das ist kein Verstoß gegen die Sprachregel.

Werden Anzeigenamen bestehender Variablen geändert, gehört ein Eintrag in die `$renames`-Tabelle in `ensureVariables()` dazu (benennt nur um, wenn der alte Default noch unverändert ist — vom Nutzer angepasste Namen bleiben erhalten).

## Emojis (Verbund-Regel, Dietmar 23.07.2026)

Ersetzt jede frühere „keine Emojis"-Regel. Emojis sind erwünscht, wo sie Nutzen stiften:

1. **Panel-Icon** — ein Zeichen am Anfang einer ExpansionPanel-Überschrift (📖 🔌 📊), als Ersatz für das fehlende `icon`-Feld.
2. **Status-/Aufmerksamkeitssymbol** (✅ ❌ ⚠️ 💡 ℹ️) dort, wo etwas beim Lesen Aufmerksamkeit erfordert oder herausgestellt werden soll (Status, Warnungen, wichtige Hinweise) — für Fokus und Auflockerung.

Faktenlage: Kein Symcon-Store-Review hat Emojis je beanstandet; die frühere „keine Emojis"-Regel war präventiv und ist aufgehoben. **Beobachtungsklausel:** Sollte je ein Stable-Review Emojis bemängeln, entscheidet der Verbund neu (Rückfall: gemeinsam emoji-frei).

Bestand hier (ausdrücklich erwünscht, nicht ausbauen): 📖 Dokumentation-Panels in allen drei Modulen, Standort-Icons (🏠 Zuhause, 📍/🏢 weitere Standorte) in der Kachel.

## Einheitliche Formular-Optik (Verbund-Regel, Dietmar 24.07.2026, Referenz InverterHub)

Reihenfolge von oben, für alle drei Module (`TessieVehicle`, `TessieVehicleTile`, `TessieConfigurator`):

1. **„🆕 Neu in Version X.Y.Z"** — aufgeklappt, pro Version dismissible. Attribut `SeenNews` speichert die zuletzt bestätigte Version; erscheint erneut, sobald `NEWS_VERSION` (Klassenkonstante) hochgezählt wird. Keine Versionsnummer im Banner-Fließtext, nur im Panel-Titel. Aufbau/Muster: `newsBanner()` + `AckNews()` in jedem der drei Module (TessieConfigurator hat aktuell keins, weil seit dem letzten Store-Stand nichts Nennenswertes hinzukam — bei Bedarf nachziehen).
2. **„📖 Dokumentation & Hilfe"** — eingeklappt. **Hier** steht die Modulversion im Titel (`moduleVersion()` liest sie zur Laufzeit aus `library.json`, damit sie nie von Hand nachgepflegt werden muss), NICHT im Neu-Banner.
3. Fachpanels (Automationen, Standorte, Statusfarben, …). Neue/wichtige Felder bekommen bei Bedarf ein `🆕`-Präfix im Label.
4. Symcon-Forum-/Feedback-Hinweis **nach den Haupteinstellungen** (= ans Ende von `$form['elements']` angehängt, vor dem `array_unshift` des Neu-Banners), einmalig dismissible über Attribut `ReviewHintDismissed`. Bewusst nur in `TessieVehicle` (die am häufigsten geöffnete Instanz) — nicht in allen drei Modulen dupliziert, sonst sieht ein Nutzer mit mehreren Instanztypen dieselbe Bitte mehrfach.

**Beim nächsten kuratierten Update:** `NEWS_VERSION` und `NEWS_ITEMS` in `TessieVehicle/module.php` und `TessieVehicleTile/module.php` aktualisieren (kurze, nutzerrelevante Highlights seit dem letzten Banner-Stand, keine vollständige Changelog-Kopie).

**Pflege ist Pflicht bei JEDEM Fix/Update, nicht nur bei großen Releases (Dietmar, 24.07.2026):** Bei jeder Änderung an einem der drei Module prüfen: „Gehört das ins Neu-Banner?" — das Ergebnis darf „nein" sein (nicht jeder Bugfix ist banner-würdig), aber die Prüfung selbst muss jedes Mal stattfinden, nicht nur bei größeren Versionssprüngen. Bei „ja": `NEWS_ITEMS` ergänzen und `NEWS_VERSION` auf die neue Modulversion anheben (sonst zeigt das Banner eine veraltete Versionsnummer im Titel, obwohl der Punkt schon drinsteht).

**Layout-Qualität generell (Dietmar, 24.07.2026):** logische Gruppierung zusammengehöriger Felder, Bedienung Schritt für Schritt ohne Scroll-Zickzack (kein Hin- und Herspringen zwischen weit auseinanderliegenden, inhaltlich zusammengehörigen Elementen), Feldkanten auf einer Linie statt kreuz und quer (einheitliche Spaltenbreiten/Einrückung innerhalb eines Panels). Bei jeder Formular-Änderung mitdenken, nicht nur beim Neuaufbau.

## Weitere Hinweise

Siehe `README.md` für Nutzerdokumentation, `CHANGELOG.md` für die Versionshistorie. Enum-/Profilwerte, die von Tesla stammen (z. B. Klimahaltung, Ladestatus), vor Änderungen möglichst gegen die offizielle [Tesla Fleet Telemetry Proto-Definition](https://github.com/teslamotors/fleet-telemetry/blob/main/protos/vehicle_data.proto) verifizieren statt zu raten — hat sich beim Klimahaltung-„Camping"/„Party"-Fall bewährt (siehe CHANGELOG 2.16.1).


## Verbund-Manifest SUITE.md — Bezugsquelle (19.08.2026)

Primärquelle für alle Verbund-Konventionen ist `SUITE.md` im EMS-Repo
(https://github.com/DG65/NRGEMS — während der EMS-Integrationsphase ist der
Branch `ems-integration` der aktuellste Stand, nicht `main`). In diesem Repo
liegt eine automatisch synchronisierte READ-ONLY-Kopie als `SUITE.md` im
Repo-Root — dort lokal grep'en/lesen. NIEMALS die Kopie hier editieren:
Änderungen gehören ins EMS-Repo; der Sync (GitHub Action `sync-suite` im
EMS-Repo) überschreibt lokale Änderungen kommentarlos.

Fallback, falls die Kopie (noch) fehlt oder veraltet wirkt:
https://raw.githubusercontent.com/DG65/NRGEMS/ems-integration/SUITE.md
