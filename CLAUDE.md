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

## Weitere Hinweise

Siehe `README.md` für Nutzerdokumentation, `CHANGELOG.md` für die Versionshistorie. Enum-/Profilwerte, die von Tesla stammen (z. B. Klimahaltung, Ladestatus), vor Änderungen möglichst gegen die offizielle [Tesla Fleet Telemetry Proto-Definition](https://github.com/teslamotors/fleet-telemetry/blob/main/protos/vehicle_data.proto) verifizieren statt zu raten — hat sich beim Klimahaltung-„Camping"/„Party"-Fall bewährt (siehe CHANGELOG 2.16.1).
