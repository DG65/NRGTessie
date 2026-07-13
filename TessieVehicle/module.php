<?php
declare(strict_types=1);

class TessieVehicle extends IPSModule
{
    // -------------------- Variable Idents (Aktionen) --------------------
    private const ACT_LOCKED                = 'act_locked';
    private const ACT_CLIMATE               = 'act_climate';
    private const ACT_START_CHARGING        = 'act_charging';
    private const ACT_CHARGE_LIMIT          = 'act_charge_limit';
    private const ACT_CHARGING_AMPS_REQUEST = 'act_charging_amps';
    private const ACT_FLASH                 = 'act_flash';
    private const ACT_HONK                  = 'act_honk';


    private const ACT_SENTRY_MODE            = 'act_sentry';
    private const ACT_VALET_MODE             = 'act_valet';
    private const ACT_DEFROST                = 'act_defrost';
    private const ACT_STEERING_WHEEL_HEATER  = 'act_steering_wheel';
    private const ACT_TEMP_DRIVER            = 'act_temp_driver';
    private const ACT_TEMP_PASSENGER         = 'act_temp_passenger';
    private const ACT_SEAT_HEAT_DRIVER       = 'act_seat_heat_driver';
    private const ACT_SEAT_HEAT_PASSENGER    = 'act_seat_heat_passenger';
    private const ACT_OPEN_CHARGE_PORT       = 'act_open_charge_port';
    private const ACT_CLOSE_CHARGE_PORT      = 'act_close_charge_port';
    private const ACT_VENT_WINDOWS           = 'act_vent_windows';
    private const ACT_CLOSE_WINDOWS          = 'act_close_windows';
    private const ACT_CLIMATE_KEEPER_MODE               = 'act_climate_keeper_mode';
    private const ACT_COP_ENABLED               = 'act_cop_enabled';
    private const ACT_COP_FAN_ONLY               = 'act_cop_fan_only';
    private const ACT_COP_TEMP               = 'act_cop_temp';
    private const ACT_BIO_DEFENSE               = 'act_bio_defense';
    private const ACT_HOMELINK               = 'act_homelink';
    private const ACT_FRONT_TRUNK               = 'act_front_trunk';
    private const ACT_REAR_TRUNK               = 'act_rear_trunk';


    // -------------------- Umrechnung (Einheiten) --------------------
    private const MI_TO_KM   = 1.609344;
    private const MPH_TO_KMH = 1.609344;

    // -------------------- Variable Idents (Status) --------------------
    private const STAT_CHARGING_AMPS_ACTUAL = 'stat_charge_amps_actual';
    private const STAT_CHARGING_AMPS_MAX    = 'stat_charge_amps_max';
    private const STAT_AC_CHARGING_POWER    = 'stat_ac_charging_power';
    private const STAT_AT_HOME              = 'stat_at_home';
    private const STAT_LOCATION_NAME        = 'stat_location_name';
    private const GEO_IDENT_PREFIX          = 'stat_geo_';

    // -------------------- Timer --------------------
    private const TIMER_UPDATE = 'UpdateTimer';

    // -------------------- Kategorien (Links) --------------------
    private const PURPOSE_CHARGING = 'Laden';
    private const PURPOSE_CLIMATE  = 'Klima';
    private const PURPOSE_SECURITY = 'Sicherheit';
    private const PURPOSE_OTHER    = 'Sonstiges';

    // -------------------- Attribute --------------------
    private const ATTR_VEHICLE_NAME        = 'VehicleName';
    private const ATTR_LAST_LINKS_LOCATION = 'LastLinksLocation';
    private const ATTR_TELEMETRY_REGISTRY  = 'TelemetryRegistry';
    private const ATTR_GEO_STATE           = 'GeoState';
    private const ATTR_RULE_STATE          = 'RuleState';
    private const ATTR_REVIEW_HINT_GONE    = 'ReviewHintDismissed';

    // Durchfahrt = Einfahrt mit anschließender Ausfahrt binnen dieser Zeit
    private const GEO_PASS_MAX_SECONDS = 900;

    // Durchfahrts-Ereignisse der aktuellen Datenmeldung (Ident => true);
    // von updateHomeStatus gefüllt, von evaluateDataActions (Op 'pass') konsumiert
    private $geoPassEvents = [];

    // -------------------- Ident-Prefixe (Link-Baum) --------------------
    private const IDENT_ROOT_PREFIX = 'TESSIE_LINKROOT_';
    private const IDENT_PURP_PREFIX = 'PURP_';
    private const IDENT_LINK_PREFIX = 'LNK_';

    // -------------------- Property --------------------
    private const PROP_VISIBLE_VARS = 'VisibleVars';

    // (versteckt, kein eigenes Formularfeld)
    private const PROP_AUTO_CREATE_TELEMETRY_VARS  = 'AutoCreateTelemetryVars';
    private const PROP_AUTO_PROFILE_TELEMETRY_VARS = 'AutoProfileTelemetryVars';
    private const PROP_TELEMETRY_DEBUG_EVERY_KEY   = 'TelemetryDebugEveryKey';
    private const PROP_TELEMETRY_DEFAULT_ENABLED   = 'TelemetryDefaultEnabled';

    public function Create()
    {
        parent::Create();

        // API
        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyString('VIN', '');
        $this->RegisterPropertyString('ApiBase', 'https://api.tessie.com');
        // Kompatibilität: wird vom TessieConfigurator verwendet
        $this->RegisterPropertyString('InstanceInterface', '[]');

        // Kompatibilität: Property existiert (Telemetrie wird intern verarbeitet)
        $this->RegisterPropertyBoolean('TelemetryEnabled', true);

        // Update-Intervall
        $this->RegisterPropertyInteger('UpdateInterval', 300);

        // Debug
        $this->RegisterPropertyBoolean('DebugHTTP', false);

        // Links / Ablageorte
        // Hinweis: 'InstanceLocation' ist KEINE Eigenschaft mehr – das SelectCategory wird in
        // GetConfigurationForm mit dem aktuellen Parent gefüllt und verschiebt die Instanz nur
        // einmalig per onChange (TESSIE_SetInstanceLocation).
        $this->RegisterPropertyInteger('LinksLocation', 0);
        $this->RegisterPropertyBoolean('CreateLinks', true);
        $this->RegisterPropertyBoolean('CleanupLinks', true);

        // Standort-Erkennung (Geofence): setzt Status-Variablen, sobald die
        // Fahrzeugposition innerhalb des Radius um einen der Standorte liegt.
        $this->RegisterPropertyBoolean('HomeDetection', false);
        $this->RegisterPropertyString('HomeLocation', '');
        $this->RegisterPropertyInteger('HomeRadius', 100);
        // Weitere Standorte: [{Name, Location(JSON lat/lon), Radius}, ...]
        $this->RegisterPropertyString('GeofenceList', '[]');
        // Automationen (Wenn -> Dann): [{Active, Source(Ident), Op, Compare, Target, Action, Value}, ...]
        // Standort-Ereignisse laufen ebenfalls hierüber: wird EIN/AUS auf der
        // Standort-Variable = Einfahrt/Ausfahrt, Op 'pass' = Durchfahrt.
        $this->RegisterPropertyString('DataActions', '[]');

        // Eine Liste für ALLE Variablen (Bestand + Telemetrie)
        $this->RegisterPropertyString(self::PROP_VISIBLE_VARS, json_encode($this->getDefaultVisibleVars()));

        // Telemetrie: neue Keys erkennen (versteckt)
        $this->RegisterPropertyBoolean(self::PROP_AUTO_CREATE_TELEMETRY_VARS, true);
        $this->RegisterPropertyBoolean(self::PROP_AUTO_PROFILE_TELEMETRY_VARS, true);
        $this->RegisterPropertyBoolean(self::PROP_TELEMETRY_DEBUG_EVERY_KEY, false);

        // Standard: neue Telemetrie-Variablen in der Liste deaktiviert
        $this->RegisterPropertyBoolean(self::PROP_TELEMETRY_DEFAULT_ENABLED, false);

        // Intern
        $this->RegisterTimer(self::TIMER_UPDATE, 0, 'TESSIE_Update($_IPS["TARGET"]);');
        $this->RegisterAttributeString(self::ATTR_VEHICLE_NAME, '');
        $this->RegisterAttributeInteger(self::ATTR_LAST_LINKS_LOCATION, 0);
        $this->RegisterAttributeString(self::ATTR_TELEMETRY_REGISTRY, json_encode(new stdClass()));
        $this->RegisterAttributeString(self::ATTR_GEO_STATE, '{}');
        $this->RegisterAttributeString(self::ATTR_RULE_STATE, '{}');
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
    }


    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $interval = (int)$this->ReadPropertyInteger('UpdateInterval');
        if ($interval < 0) {
            $interval = 0;
        }
        $this->SetTimerInterval(self::TIMER_UPDATE, $interval > 0 ? $interval * 1000 : 0);

        $this->refreshTelemetryRegistryNames();
        $this->ensureVariables();

        try {
            $this->ensureLinkTree();
        } catch (Throwable $e) {
            $this->LogMessage('ensureLinkTree fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
        }

        // Bereits gespeicherte Roh-JSON-Telemetriewerte (z.B. von vor einem Update oder
        // selten gesendete/stale Datenpunkte) nachträglich lesbar machen
        $this->migrateTelemetryValues();

        // Automations-Baseline: Zustände neu einlesen, ohne auszulösen
        // (verhindert Fehlauslösungen durch bereits erfüllte Bedingungen)
        try { $this->evaluateDataActions(false); } catch (Throwable $e) { /* ignorieren */ }

        $this->SetStatus(102);
    }

    // Einmalige/idempotente Nachbesserung: gespeicherte Telemetriewerte, die noch als
    // Roh-JSON ({"...Value":...}) vorliegen, erneut durch den Parser schicken. Greift nur
    // bei String-Variablen mit JSON-Inhalt; bereits lesbare Werte bleiben unberührt.
    private function migrateTelemetryValues(): void
    {
        $registry = $this->getTelemetryRegistry();
        foreach ($registry as $ident => $meta) {
            if (!is_string($ident) || $ident === '' || !is_array($meta)) continue;

            $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($vid <= 0) continue;
            if ((IPS_GetVariable($vid)['VariableType'] ?? null) !== VARIABLETYPE_STRING) continue;

            $cur = (string)GetValue($vid);
            $trim = ltrim($cur);
            if ($trim === '') continue;

            if ($trim[0] === '{' || $trim[0] === '[') {
                // Roh-JSON erneut durch den Parser schicken
                $decoded = json_decode($cur, true);
                if (!is_array($decoded)) continue;

                $key = (string)($meta['key'] ?? $ident);
                [$type, $value] = $this->telemetryInferTypeAndValue($key, $decoded);

                // Nicht deutbar -> der Parser gibt erneut JSON zurück, dann nicht überschreiben
                if (is_string($value) && $value === json_encode($decoded)) continue;

                $this->safeSetValueIfExists($ident, $value);
            } else {
                // Klartext-Enum (z.B. WheelType "Induction20Black") nachträglich lesbar machen
                $t = $this->readableEnumString($cur);
                if ($t !== '' && $t !== $cur) {
                    $this->safeSetValueIfExists($ident, $t);
                }
            }
        }
    }

    // Dynamisches Formular: eine Liste "Anzuzeigende Variablen" inkl. Telemetrie-Einträgen
    // Reichert eine Basisliste (Ident/Name/Enabled) um die Anzeigespalten an: übersetzter
    // Name, Gruppe (Domäne) und "Empfangen". Genutzt von GetConfigurationForm und den Buttons.
    private function buildFormRows(array $base): array
    {
        $registry = $this->getTelemetryRegistry();
        $names = [];
        foreach ($this->getDefaultVisibleVars() as $d) {
            $names[(string)($d['Ident'] ?? '')] = (string)($d['Name'] ?? '');
        }
        foreach ($registry as $tid => $m) {
            if (is_array($m)) $names[(string)$tid] = (string)($m['name'] ?? $tid);
        }
        foreach ($base as &$row) {
            if (!is_array($row)) continue;
            $ident = (string)($row['Ident'] ?? '');
            $row['Name']   = $this->Translate($names[$ident] ?? (string)($row['Name'] ?? $ident));
            $row['Gruppe'] = $this->purposeForIdent($ident, $registry);
            $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            $row['Empfangen'] = ($vid > 0 && (int)(@IPS_GetVariable($vid)['VariableUpdated'] ?? 0) > 0) ? 'Ja' : '';
        }
        unset($row);
        return $base;
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            $form = ['elements' => [], 'actions' => [], 'status' => []];
        }

        $fullList = $this->buildFormRows($this->getEffectiveList());

        // Telemetrie-Status für den Sammelbutton
        $hasTelemetry = false;
        $allTelemetryOn = true;
        foreach ($fullList as $row) {
            if (!is_array($row) || strpos((string)($row['Ident'] ?? ''), 'stat_tel_') !== 0) continue;
            $hasTelemetry = true;
            if (!(bool)($row['Enabled'] ?? false)) $allTelemetryOn = false;
        }
        if (!$hasTelemetry) $allTelemetryOn = false;

        // Datenpunkt-Auswahl für die Automationsliste: alle Datenpunkte + Geofence-Variablen
        $sourceOptions = $this->getAutomationSourceOptions($fullList);

        // form.json-Elemente befüllen (rekursiv, Listen liegen z. T. in ExpansionPanels):
        // Datenpunkt-Liste, aktueller Ablageort, Datenpunkt-Optionen der Automationsliste
        $patch = function (array &$elements) use (&$patch, $fullList, $sourceOptions) {
            foreach ($elements as &$element) {
                if (!is_array($element)) continue;
                $elName = $element['name'] ?? '';
                if ($elName === 'VisibleVars') {
                    $element['values'] = $fullList;
                } elseif ($elName === 'InstanceLocation') {
                    // Aktuellen Parent anzeigen; verschoben wird nur per onChange (siehe SetInstanceLocation)
                    $element['value'] = IPS_GetParent($this->InstanceID);
                } elseif ($elName === 'DataActions' && isset($element['columns']) && is_array($element['columns'])) {
                    // Achtung: nicht über ($element['columns'] ?? []) iterieren – der ??-Ausdruck
                    // liefert eine Kopie, Referenz-Änderungen gingen verloren.
                    foreach ($element['columns'] as &$col) {
                        if (($col['name'] ?? '') === 'Source') {
                            $col['edit']['options'] = $sourceOptions;
                        }
                    }
                    unset($col);
                }
                if (isset($element['items']) && is_array($element['items'])) {
                    $patch($element['items']);
                }
            }
            unset($element);
        };
        $patch($form['elements']);

        // Einmaliger Feedback-Hinweis: erscheint, bis er per Button ausgeblendet wird
        // (Attribut, keine Eigenschaft – der Nutzer muss nichts übernehmen)
        if (!$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type'  => 'RowLayout',
                'name'  => 'ReviewHint',
                'items' => [
                    [
                        'type'    => 'Label',
                        'caption' => '⭐ Gefällt dir dieses Modul? Über eine Bewertung im Module Store oder eine Rückmeldung in der Symcon-Community freue ich mich!'
                    ],
                    [
                        'type'    => 'Label',
                        'link'    => true,
                        'caption' => 'https://community.symcon.de/t/modul-tessie-tesla-fahrzeuge-in-ip-symcon-steuerung-telemetrie-kachel/143995'
                    ],
                    [
                        'type'    => 'Button',
                        'caption' => 'Nicht mehr anzeigen',
                        'onClick' => 'TESSIE_DismissReviewHint($id);'
                    ]
                ]
            ];
        }

        // Telemetrie-Sammelbutton dynamisch beschriften
        foreach (($form['actions'] ?? []) as &$action) {
            if (($action['name'] ?? '') === 'ToggleTelemetry') {
                $action['caption'] = $allTelemetryOn ? 'Telemetrie: alle ausblenden' : 'Telemetrie: alle einblenden';
                $action['confirm'] = $allTelemetryOn ? 'Wirklich alle Telemetrie-Datenpunkte ausblenden?' : '';
                $action['enabled'] = $hasTelemetry;
                break;
            }
        }
        unset($action);

        return json_encode($form);
    }

    /**
     * onChange des "Ablageort Instanz"-Feldes: verschiebt die Instanz einmalig an die gewählte
     * Kategorie. Keine Eigenschaft – beim nächsten Laden zeigt das Feld den tatsächlichen Parent.
     */
    public function SetInstanceLocation(int $categoryID): void
    {
        if ($categoryID > 0 && IPS_ObjectExists($categoryID) && IPS_GetParent($this->InstanceID) != $categoryID) {
            @IPS_SetParent($this->InstanceID, $categoryID);
        }
    }

    /**
     * Blendet den Feedback-Hinweis dauerhaft aus (Attribut, kein Übernehmen nötig).
     */
    public function DismissReviewHint(): void
    {
        $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
        $this->UpdateFormField('ReviewHint', 'visible', false);
    }

    public function ResetVisibleVars()
    {
        // Nur die offene Konfiguration zurücksetzen; der Nutzer bestätigt selbst mit „Änderungen übernehmen".
        $this->UpdateFormField('VisibleVars', 'values', json_encode($this->buildFormRows($this->getDefaultVisibleVars())));
        $this->UpdateFormField('ToggleTelemetry', 'caption', 'Telemetrie: alle einblenden');
        $this->UpdateFormField('ToggleTelemetry', 'confirm', '');
    }

    public function ToggleAllTelemetry(): void
    {
        $base = $this->getEffectiveList();
        $hasTelemetry = false;
        $allOn = true;
        foreach ($base as $row) {
            if (!is_array($row) || strpos((string)($row['Ident'] ?? ''), 'stat_tel_') !== 0) {
                continue;
            }
            $hasTelemetry = true;
            if (!(bool)($row['Enabled'] ?? false)) {
                $allOn = false;
            }
        }
        if (!$hasTelemetry) {
            return;
        }
        $this->SetAllTelemetryEnabled(!$allOn);
    }


    public function SetAllTelemetryEnabled(bool $enabled): void
    {
        $base = $this->getEffectiveList();
        foreach ($base as &$row) {
            if (is_array($row) && strpos((string)($row['Ident'] ?? ''), 'stat_tel_') === 0) {
                $row['Enabled'] = $enabled;
            }
        }
        unset($row);
        // Nur die offene Liste anpassen; Anwendung erst beim „Übernehmen" durch den Nutzer.
        $this->UpdateFormField('VisibleVars', 'values', json_encode($this->buildFormRows($base)));
        $this->UpdateFormField('ToggleTelemetry', 'caption', $enabled ? 'Telemetrie: alle ausblenden' : 'Telemetrie: alle einblenden');
        $this->UpdateFormField('ToggleTelemetry', 'confirm', $enabled ? 'Wirklich alle Telemetrie-Datenpunkte ausblenden?' : '');
    }

    public function SetImportantTelemetryEnabled(): void
    {
        $importantKeys = [
            'Soc',
            'ChargeLimitSoc',
            'ChargeAmps',
            'ChargeCurrentRequest',
            'ChargeCurrentRequestMax',
            'ACChargingPower',
            'DCChargingPower',
            'EnergyRemaining',
            'RatedRange',
            'Odometer',
            'InsideTemp',
            'OutsideTemp',
            'HvacLeftTemperatureRequest',
            'CabinOverheatProtectionMode',
            'CabinOverheatProtectionTemperatureLimit',
            'Locked',
            'SentryMode',
            'ValetModeEnabled',
            'ChargePortDoorOpen',
            'Location'
        ];
        $important = array_flip($importantKeys);

        $registry = $this->getTelemetryRegistry();
        $base = $this->getEffectiveList();

        foreach ($base as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $ident = (string)($row['Ident'] ?? '');
            if (strpos($ident, 'stat_tel_') !== 0) {
                continue;
            }
            $key = (isset($registry[$ident]) && is_array($registry[$ident])) ? (string)($registry[$ident]['key'] ?? '') : '';
            $row['Enabled'] = ($key !== '' && isset($important[$key]));
        }
        unset($row);

        // Nur die offene Liste anpassen; Anwendung erst beim „Übernehmen" durch den Nutzer.
        $this->UpdateFormField('VisibleVars', 'values', json_encode($this->buildFormRows($base)));
        $this->UpdateFormField('ToggleTelemetry', 'caption', 'Telemetrie: alle einblenden');
        $this->UpdateFormField('ToggleTelemetry', 'confirm', '');
    }

    public function RenameTelemetryVariables(): void
    {
        $registry = $this->getTelemetryRegistry();
        if (count($registry) === 0) {
            return;
        }

        foreach ($registry as $ident => $meta) {
            if (!is_string($ident) || strpos($ident, 'stat_tel_') !== 0) {
                continue;
            }
            if (!is_array($meta)) {
                continue;
            }

            $key = (string)($meta['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $name = $this->Translate($key);
            if (str_ends_with($ident, '_lat')) {
                $name .= ' – ' . $this->Translate('Latitude');
            } elseif (str_ends_with($ident, '_lon')) {
                $name .= ' – ' . $this->Translate('Longitude');
            }

            $varId = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($varId > 0 && IPS_GetName($varId) !== $name) {
                IPS_SetName($varId, $name);
            }
        }

        try {
            $this->ensureLinkTree(true);
        } catch (Throwable $e) {
            // ignorieren
        }
    }

    private function getDefaultVisibleVars(): array
    {
        return [
            ['Ident' => self::ACT_LOCKED,               'Name' => 'Verriegelt',                      'Enabled' => true],
            ['Ident' => self::ACT_SENTRY_MODE,          'Name' => 'Sentry Mode',                     'Enabled' => true],
            ['Ident' => self::ACT_VALET_MODE,           'Name' => 'Valet-Modus',                     'Enabled' => true],
            ['Ident' => self::ACT_FLASH,                'Name' => 'Licht blinken',                   'Enabled' => true],
            ['Ident' => self::ACT_HONK,                 'Name' => 'Hupe',                            'Enabled' => true],
            ['Ident' => self::ACT_VENT_WINDOWS,         'Name' => 'Fenster lüften',                  'Enabled' => true],
            ['Ident' => self::ACT_CLOSE_WINDOWS,        'Name' => 'Fenster schließen',               'Enabled' => true],

            ['Ident' => self::ACT_CLIMATE,              'Name' => 'Klima',                           'Enabled' => true],
            
            ['Ident' => self::ACT_CLIMATE_KEEPER_MODE, 'Name' => 'Klimahaltung', 'Enabled' => true],
            ['Ident' => self::ACT_COP_ENABLED, 'Name' => 'Innenraum-Überhitzeschutz', 'Enabled' => true],
            ['Ident' => self::ACT_COP_FAN_ONLY, 'Name' => 'Innenraum-Überhitzeschutz: nur Lüfter', 'Enabled' => true],
            ['Ident' => self::ACT_COP_TEMP, 'Name' => 'Innenraum-Überhitzeschutz: Temperaturlimit', 'Enabled' => true],
            ['Ident' => self::ACT_BIO_DEFENSE, 'Name' => 'Bio Defense Mode', 'Enabled' => true],
            ['Ident' => self::ACT_HOMELINK, 'Name' => 'HomeLink auslösen', 'Enabled' => false],
            ['Ident' => self::ACT_FRONT_TRUNK, 'Name' => 'Vorderer Kofferraum öffnen', 'Enabled' => false],
            ['Ident' => self::ACT_REAR_TRUNK, 'Name' => 'Heckklappe öffnen/schließen', 'Enabled' => false],

['Ident' => self::ACT_TEMP_DRIVER,          'Name' => 'Solltemperatur Fahrer (°C)',      'Enabled' => true],
            ['Ident' => self::ACT_TEMP_PASSENGER,       'Name' => 'Solltemperatur Beifahrer (°C)',   'Enabled' => true],
            ['Ident' => self::ACT_DEFROST,              'Name' => 'Max Defrost',                      'Enabled' => true],
            ['Ident' => self::ACT_STEERING_WHEEL_HEATER,'Name' => 'Lenkradheizung',                  'Enabled' => true],
            ['Ident' => self::ACT_SEAT_HEAT_DRIVER,     'Name' => 'Sitzheizung Fahrer',              'Enabled' => true],
            ['Ident' => self::ACT_SEAT_HEAT_PASSENGER,  'Name' => 'Sitzheizung Beifahrer',           'Enabled' => true],

            ['Ident' => self::ACT_START_CHARGING,       'Name' => 'Laden',                           'Enabled' => true],
            ['Ident' => self::ACT_CHARGE_LIMIT,         'Name' => 'Ladelimit (%)',                   'Enabled' => true],
            ['Ident' => self::ACT_CHARGING_AMPS_REQUEST,'Name' => 'Ladestrom Soll (A)',              'Enabled' => true],
            ['Ident' => self::ACT_OPEN_CHARGE_PORT,     'Name' => 'Ladeport öffnen/entriegeln',      'Enabled' => true],
            ['Ident' => self::ACT_CLOSE_CHARGE_PORT,    'Name' => 'Ladeport schließen',              'Enabled' => true],

            ['Ident' => self::STAT_CHARGING_AMPS_ACTUAL,'Name' => 'Ladestrom Ist (A)',               'Enabled' => true],
            ['Ident' => self::STAT_CHARGING_AMPS_MAX,   'Name' => 'Ladestrom Max (A)',               'Enabled' => true],
            ['Ident' => self::STAT_AC_CHARGING_POWER,   'Name' => 'AC Ladeleistung (kW)',            'Enabled' => true]
        ];
    }

    // Timer
    public function Update()
    {
        $token = trim($this->ReadPropertyString('ApiToken'));
        $vin = trim($this->ReadPropertyString('VIN'));
        if ($token === '' || $vin === '') {
            return;
        }

        $status = $this->getVehicleStatus($vin, $token);
        if ($status !== '') {
            $this->SendDebug('Fahrzeugstatus', $status, 0);
        }
    }

    // Telemetrie ReceiveData
    public function ReceiveData($JSONString)
    {
        $packet = json_decode($JSONString, true);
        if (!is_array($packet)) {
            return;
        }
        $buf = (string)($packet['Buffer'] ?? '');
        if ($buf === '') {
            return;
        }

        $payload = json_decode($buf, true);
        if (!is_array($payload)) {
            $this->SendDebug('Telemetrie', 'Kein JSON im Buffer: ' . substr($buf, 0, 300), 0);
            return;
        }

        if (isset($payload['errors'])) {
            $this->SendDebug('Telemetrie-Fehler', json_encode($payload['errors']), 0);
            return;
        }
        if (isset($payload['status']) && isset($payload['connectionId'])) {
            $this->SendDebug('Telemetrie-Verbindung', json_encode($payload), 0);
            return;
        }

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return;
        }

        $this->syncFromTelemetry($payload['data']);
    }

    // RequestAction
    public function RequestAction($Ident, $Value)
    {
        $token = trim($this->ReadPropertyString('ApiToken'));
        $vin = trim($this->ReadPropertyString('VIN'));
        if ($token === '' || $vin === '') {
            throw new Exception('ApiToken oder VIN fehlt.');
        }

        switch ((string)$Ident) {
            case self::ACT_LOCKED:
                $wantLocked = (bool)$Value;
                $this->sendCommand($vin, $token, $wantLocked ? 'lock' : 'unlock');
                $this->safeSetValue(self::ACT_LOCKED, $wantLocked);
                break;

            case self::ACT_CLIMATE:
                $on = (bool)$Value;
                $this->sendCommand($vin, $token, $on ? 'start_climate' : 'stop_climate');
                $this->safeSetValue(self::ACT_CLIMATE, $on);
                break;

            case self::ACT_START_CHARGING:
                $on = (bool)$Value;
                $this->sendCommand($vin, $token, $on ? 'start_charging' : 'stop_charging');
                $this->safeSetValue(self::ACT_START_CHARGING, $on);
                break;

            case self::ACT_CHARGE_LIMIT:
                $percent = (int)$Value;
                if ($percent < 0) $percent = 0;
                if ($percent > 100) $percent = 100;
                $this->sendCommand($vin, $token, 'set_charge_limit', ['percent' => $percent]);
                $this->safeSetValue(self::ACT_CHARGE_LIMIT, $percent);
                break;

            case self::ACT_CHARGING_AMPS_REQUEST:
                $amps = (int)$Value;
                if ($amps < 0) $amps = 0;
                if ($amps > 48) $amps = 48;
                $this->sendCommand($vin, $token, 'set_charging_amps', ['amps' => $amps]);
                $this->safeSetValue(self::ACT_CHARGING_AMPS_REQUEST, $amps);
                break;

            case self::ACT_FLASH:
                if ((bool)$Value) {
                    $this->sendCommand($vin, $token, 'flash');
                }
                $this->safeSetValue(self::ACT_FLASH, false);
                break;

            case self::ACT_HONK:
                if ((bool)$Value) {
                    $this->sendCommand($vin, $token, 'honk');
                }
                $this->safeSetValue(self::ACT_HONK, false);
                break;


            case self::ACT_SENTRY_MODE:
                $on = (bool)$Value;
                $this->sendCommand($vin, $token, $on ? 'enable_sentry' : 'disable_sentry');
                $this->safeSetValue(self::ACT_SENTRY_MODE, $on);
                break;

            case self::ACT_VALET_MODE:
                $on = (bool)$Value;
                $this->sendCommand($vin, $token, $on ? 'enable_valet' : 'disable_valet');
                $this->safeSetValue(self::ACT_VALET_MODE, $on);
                break;

            case self::ACT_DEFROST:
                $on = (bool)$Value;
                $this->sendCommand($vin, $token, $on ? 'start_max_defrost' : 'stop_max_defrost');
                $this->safeSetValue(self::ACT_DEFROST, $on);
                break;

            case self::ACT_STEERING_WHEEL_HEATER:
                $on = (bool)$Value;
                $this->sendCommand($vin, $token, $on ? 'start_steering_wheel_heater' : 'stop_steering_wheel_heater');
                $this->safeSetValue(self::ACT_STEERING_WHEEL_HEATER, $on);
                break;

            case self::ACT_TEMP_DRIVER:
            case self::ACT_TEMP_PASSENGER:
                $temp = (float)$Value;
                if ($temp < 15) $temp = 15;
                if ($temp > 28) $temp = 28;
                $this->sendCommand($vin, $token, 'set_temperatures', ['temperature' => $temp]);
                $this->safeSetValue(self::ACT_TEMP_DRIVER, $temp);
                $this->safeSetValue(self::ACT_TEMP_PASSENGER, $temp);
                break;

            case self::ACT_SEAT_HEAT_DRIVER:
                $lvl = (int)$Value;
                if ($lvl < 0) $lvl = 0;
                if ($lvl > 3) $lvl = 3;
                $this->sendCommand($vin, $token, 'set_seat_heat', ['seat' => 'front_left', 'level' => $lvl]);
                $this->safeSetValue(self::ACT_SEAT_HEAT_DRIVER, $lvl);
                break;

            case self::ACT_SEAT_HEAT_PASSENGER:
                $lvl = (int)$Value;
                if ($lvl < 0) $lvl = 0;
                if ($lvl > 3) $lvl = 3;
                $this->sendCommand($vin, $token, 'set_seat_heat', ['seat' => 'front_right', 'level' => $lvl]);
                $this->safeSetValue(self::ACT_SEAT_HEAT_PASSENGER, $lvl);
                break;

            case self::ACT_OPEN_CHARGE_PORT:
                if ((bool)$Value) {
                    $this->sendCommand($vin, $token, 'open_charge_port');
                }
                $this->safeSetValue(self::ACT_OPEN_CHARGE_PORT, false);
                break;

            case self::ACT_CLOSE_CHARGE_PORT:
                if ((bool)$Value) {
                    $this->sendCommand($vin, $token, 'close_charge_port');
                }
                $this->safeSetValue(self::ACT_CLOSE_CHARGE_PORT, false);
                break;

            case self::ACT_VENT_WINDOWS:
                if ((bool)$Value) {
                    $this->sendCommand($vin, $token, 'vent_windows');
                }
                $this->safeSetValue(self::ACT_VENT_WINDOWS, false);
                break;

            case self::ACT_CLOSE_WINDOWS:
                if ((bool)$Value) {
                    $this->sendCommand($vin, $token, 'close_windows');
                }
                $this->safeSetValue(self::ACT_CLOSE_WINDOWS, false);
                break;
            case self::ACT_CLIMATE_KEEPER_MODE:
                $mode = (int)$Value;
                if ($mode < 0) $mode = 0;
                if ($mode > 3) $mode = 3;
                $this->sendCommand($vin, $token, 'set_climate_keeper_mode', ['mode' => $mode]);
                $this->safeSetValue(self::ACT_CLIMATE_KEEPER_MODE, $mode);
                break;

            case self::ACT_COP_ENABLED:
                $on = (bool)$Value;
                $fanOnlyId = @IPS_GetObjectIDByIdent(self::ACT_COP_FAN_ONLY, $this->InstanceID);
                $fanOnly = ($fanOnlyId > 0) ? (bool)@GetValueBoolean($fanOnlyId) : false;
                $this->sendCommand($vin, $token, 'set_cabin_overheat_protection', ['on' => $on, 'fan_only' => $fanOnly]);
                $this->safeSetValue(self::ACT_COP_ENABLED, $on);
                break;

            case self::ACT_COP_FAN_ONLY:
                $fanOnly = (bool)$Value;
                $onId = @IPS_GetObjectIDByIdent(self::ACT_COP_ENABLED, $this->InstanceID);
                $on = ($onId > 0) ? (bool)@GetValueBoolean($onId) : true;
                $this->sendCommand($vin, $token, 'set_cabin_overheat_protection', ['on' => $on, 'fan_only' => $fanOnly]);
                $this->safeSetValue(self::ACT_COP_FAN_ONLY, $fanOnly);
                break;

            case self::ACT_COP_TEMP:
                $lvl = (int)$Value;
                if ($lvl < 1) $lvl = 1;
                if ($lvl > 3) $lvl = 3;
                $this->sendCommand($vin, $token, 'set_cop_temp', ['cop_temp' => $lvl]);
                $this->safeSetValue(self::ACT_COP_TEMP, $lvl);
                break;

            case self::ACT_BIO_DEFENSE:
                $on = (bool)$Value;
                $this->sendCommand($vin, $token, 'set_bioweapon_mode', ['on' => $on]);
                $this->safeSetValue(self::ACT_BIO_DEFENSE, $on);
                break;

            case self::ACT_HOMELINK:
                if ((bool)$Value) {
                    $this->sendCommand($vin, $token, 'trigger_homelink');
                }
                $this->safeSetValue(self::ACT_HOMELINK, false);
                break;

            case self::ACT_FRONT_TRUNK:
                if ((bool)$Value) {
                    $this->sendCommand($vin, $token, 'activate_front_trunk');
                }
                $this->safeSetValue(self::ACT_FRONT_TRUNK, false);
                break;

            case self::ACT_REAR_TRUNK:
                if ((bool)$Value) {
                    $this->sendCommand($vin, $token, 'activate_rear_trunk');
                }
                $this->safeSetValue(self::ACT_REAR_TRUNK, false);
                break;




            default:
                throw new Exception('Unbekannte Aktion: ' . (string)$Ident);
        }
    }

    // Commands
    private function sendCommand(string $vin, string $token, string $command, array $queryParams = []): void
    {
        $this->ensureAwake($vin, $token);

        $params = $queryParams;
        $params['wait_for_completion'] = 'true';

        $path = '/' . rawurlencode($vin) . '/command/' . rawurlencode($command);
        if (count($params) > 0) {
            $path .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        $resp = $this->apiRequest($token, 'POST', $path, null);
        $ok = (bool)($resp['result'] ?? ($resp['response']['result'] ?? false));
        $this->SendDebug('Befehl', ($ok ? 'OK: ' : 'Fehlgeschlagen: ') . $command . ($ok ? '' : (' ' . json_encode($resp))), 0);
    }

    private function ensureAwake(string $vin, string $token): void
    {
        $status = $this->getVehicleStatus($vin, $token);
        if ($status === 'awake') {
            return;
        }
        $path = '/' . rawurlencode($vin) . '/wake';
        $resp = $this->apiRequest($token, 'POST', $path, null);
        $this->SendDebug('Aufwecken', 'Antwort=' . json_encode($resp), 0);
    }

    private function getVehicleStatus(string $vin, string $token): string
    {
        $path = '/' . rawurlencode($vin) . '/status';
        $resp = $this->apiRequest($token, 'GET', $path, null);
        $st = $resp['status'] ?? '';
        return is_string($st) ? $st : '';
    }


    private function convertTelemetryToMetric(string $key, int $type, $value)
    {
        if ($type !== VARIABLETYPE_FLOAT && $type !== VARIABLETYPE_INTEGER) {
            return $value;
        }

        $k = strtolower($key);

        // VehicleSpeed kommt in der Telemetrie typischerweise in mph
        if ($k === 'vehiclespeed') {
            return (float)$value * self::MPH_TO_KMH;
        }

        // mph -> km/h
        if (strpos($k, 'mph') !== false) {
            return (float)$value * self::MPH_TO_KMH;
        }

        // miles -> km (Odometer/Range/Miles*)
        if (strpos($k, 'odometer') !== false || strpos($k, 'range') !== false || strpos($k, 'miles') !== false) {
            return (float)$value * self::MI_TO_KM;
        }

        return $value;
    }

    // Telemetrie: bestehende Mappings + Registry + Werte nur setzen, wenn Variable existiert (Enabled)
    private function syncFromTelemetry(array $dataItems): void
    {
        $locked = null;
        $limit  = null;
        $req    = null;
        $act    = null;
        $max    = null;
        $acp    = null;
        $vehicleName = null;

        $autoCreate = (bool)$this->ReadPropertyBoolean(self::PROP_AUTO_CREATE_TELEMETRY_VARS);
        $debugEveryKey = (bool)$this->ReadPropertyBoolean(self::PROP_TELEMETRY_DEBUG_EVERY_KEY);

        $registry = $this->getTelemetryRegistry();
        $registryChanged = false;

        foreach ($dataItems as $item) {
            if (!is_array($item)) continue;
            $key = (string)($item['key'] ?? '');
            $val = $item['value'] ?? null;
            if ($key === '' || !is_array($val)) continue;

            if ($debugEveryKey) {
                $this->SendDebug('Telemetrie-Key', $key . ' => ' . json_encode($val), 0);
            }

            // Bestehende Mappings
            if ($key === 'Locked') {
                $b = $this->telemetryGetBool($val);
                if ($b !== null) $locked = $b;
                continue;
            }
            if ($key === 'ChargeLimitSoc') {
                $n = $this->telemetryGetNumber($val);
                if ($n !== null) $limit = (int)round($n);
                continue;
            }
            if ($key === 'ChargeCurrentRequest') {
                $n = $this->telemetryGetNumber($val);
                if ($n !== null) $req = (int)round($n);
                continue;
            }
            if ($key === 'ChargeAmps') {
                $n = $this->telemetryGetNumber($val);
                if ($n !== null) $act = (float)$n;
                continue;
            }
            if ($key === 'ChargeCurrentRequestMax') {
                $n = $this->telemetryGetNumber($val);
                if ($n !== null) $max = (int)round($n);
                continue;
            }
            if ($key === 'ACChargingPower') {
                $n = $this->telemetryGetNumber($val);
                if ($n !== null) $acp = (float)$n;
                continue;
            }
            if ($key === 'VehicleName') {
                $s = $this->telemetryGetString($val);
                if ($s !== null && $s !== '') $vehicleName = $s;
                continue;
            }


            if ($key === 'ClimateKeeperMode') {
                // Tesla sendet entweder eine Zahl oder einen Enum-String (z.B. "ClimateKeeperModeStateOff")
                $n = $this->telemetryGetNumber($val);
                if ($n === null) {
                    $s = strtolower((string)$this->telemetryFirstEnumString($val));
                    if (strpos($s, 'off') !== false) $n = 0;
                    elseif (strpos($s, 'dog') !== false) $n = 2;
                    elseif (strpos($s, 'party') !== false || strpos($s, 'camp') !== false) $n = 3;
                    elseif (strpos($s, 'keep') !== false || strpos($s, 'on') !== false) $n = 1;
                }
                if ($n !== null) {
                    $this->safeSetValueIfExists(self::ACT_CLIMATE_KEEPER_MODE, (int)round($n));
                }
                continue;
            }
            if ($key === 'CabinOverheatProtectionTemperatureLimit') {
                // Zahl oder Enum-String (z.B. "ClimateOverheatProtectionTempLimitLow")
                $n = $this->telemetryGetNumber($val);
                if ($n === null) {
                    $s = strtolower((string)$this->telemetryFirstEnumString($val));
                    if (strpos($s, 'low') !== false) $n = 1;
                    elseif (strpos($s, 'medium') !== false) $n = 2;
                    elseif (strpos($s, 'high') !== false) $n = 3;
                }
                if ($n !== null) {
                    $this->safeSetValueIfExists(self::ACT_COP_TEMP, (int)round($n));
                }
                continue;
            }
            if ($key === 'ValetModeEnabled') {
                $b = $this->telemetryGetBool($val);
                if ($b !== null) $this->safeSetValueIfExists(self::ACT_VALET_MODE, $b);
                continue;
            }

            if ($key === 'SentryMode') {
                if (isset($val['sentryModeStateValue'])) {
                    $sv = strtolower((string)$val['sentryModeStateValue']);
                    $isOn = (strpos($sv, 'off') === false);
                    $this->safeSetValueIfExists(self::ACT_SENTRY_MODE, $isOn);
                }
                continue;
            }

            // Zuhause-Erkennung: Position immer auswerten, unabhängig von Auto-Discovery.
            if (array_key_exists('locationValue', $val) && is_array($val['locationValue'])) {
                $loc = $val['locationValue'];
                if (isset($loc['latitude'], $loc['longitude'])) {
                    $this->updateHomeStatus((float)$loc['latitude'], (float)$loc['longitude']);
                }
            }

            if (!$autoCreate) {
                continue;
            }

            // Auto-Discovery
            $baseIdent = 'stat_tel_' . $this->makeIdent($key);

            // Location -> Lat/Lon
            if (array_key_exists('locationValue', $val) && is_array($val['locationValue'])) {
                $identLat = $baseIdent . '_lat';
                $identLon = $baseIdent . '_lon';

                if (!isset($registry[$identLat])) {
                    $registry[$identLat] = $this->makeRegistryEntry($key, $this->translateTelemetryKey($key) . ' – ' . $this->Translate('Latitude'), VARIABLETYPE_FLOAT);
                    $registryChanged = true;
                }
                if (!isset($registry[$identLon])) {
                    $registry[$identLon] = $this->makeRegistryEntry($key, $this->translateTelemetryKey($key) . ' – ' . $this->Translate('Longitude'), VARIABLETYPE_FLOAT);
                    $registryChanged = true;
                }

                // Werte nur setzen, wenn Variablen existieren (Enabled)
                $loc = $val['locationValue'];
                if (is_array($loc)) {
                    if (isset($loc['latitude']))  $this->safeSetValueIfExists($identLat, (float)$loc['latitude']);
                    if (isset($loc['longitude'])) $this->safeSetValueIfExists($identLon, (float)$loc['longitude']);
                }

                continue;
            }

            [$type, $value] = $this->telemetryInferTypeAndValue($key, $val);

            // Einheiten umrechnen (mi->km, mph->km/h)
            $value = $this->convertTelemetryToMetric($key, $type, $value);

            if (!isset($registry[$baseIdent])) {
                $registry[$baseIdent] = $this->makeRegistryEntry($key, $this->translateTelemetryKey($key), $type);
                $registryChanged = true;
            }

            $this->safeSetValueIfExists($baseIdent, $value);
        }

        if ($registryChanged) {
            $this->WriteAttributeString(self::ATTR_TELEMETRY_REGISTRY, json_encode($registry));
        }

        // Bestehende Werte setzen
        if ($locked !== null) $this->safeSetValueIfExists(self::ACT_LOCKED, $locked);
        if ($limit  !== null) $this->safeSetValueIfExists(self::ACT_CHARGE_LIMIT, $limit);
        if ($req    !== null) $this->safeSetValueIfExists(self::ACT_CHARGING_AMPS_REQUEST, $req);
        if ($act    !== null) $this->safeSetValueIfExists(self::STAT_CHARGING_AMPS_ACTUAL, $act);
        if ($max    !== null) $this->safeSetValueIfExists(self::STAT_CHARGING_AMPS_MAX, $max);
        if ($acp    !== null) $this->safeSetValueIfExists(self::STAT_AC_CHARGING_POWER, $acp);

        if ($vehicleName !== null) {
            $old = $this->ReadAttributeString(self::ATTR_VEHICLE_NAME);
            if ($old !== $vehicleName) {
                $this->WriteAttributeString(self::ATTR_VEHICLE_NAME, $vehicleName);
                try { $this->ensureLinkTree(true); } catch (Throwable $e) { /* ignorieren */ }
            }
        }

        // Wenn->Dann-Automationen nach jedem Datenpaket auswerten (flankengesteuert)
        try { $this->evaluateDataActions(); } catch (Throwable $e) {
            $this->SendDebug('Automation', 'Fehler: ' . $e->getMessage(), 0);
        }
    }

    private function safeSetValueIfExists(string $ident, $value): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id <= 0) {
            return;
        }
        $type = IPS_GetVariable($id)['VariableType'] ?? null;
        if ($type === VARIABLETYPE_BOOLEAN) {
            @SetValueBoolean($id, (bool)$value);
        } elseif ($type === VARIABLETYPE_INTEGER) {
            @SetValueInteger($id, (int)$value);
        } elseif ($type === VARIABLETYPE_FLOAT) {
            @SetValueFloat($id, (float)$value);
        } else {
            @SetValueString($id, (string)$value);
        }
    }

    private function telemetryInferTypeAndValue(string $key, array $val): array
    {
        // Tesla meldet fehlende/ungültige Werte als {"invalid":true}
        if (array_key_exists('invalid', $val)) {
            return [VARIABLETYPE_STRING, ''];
        }

        if (array_key_exists('booleanValue', $val)) return [VARIABLETYPE_BOOLEAN, (bool)$val['booleanValue']];
        if (array_key_exists('intValue', $val)) return $this->telemetryScalar($key, $val['intValue']);
        if (array_key_exists('longValue', $val)) return $this->telemetryScalar($key, $val['longValue']);
        if (array_key_exists('doubleValue', $val)) return [VARIABLETYPE_FLOAT, (float)$val['doubleValue']];
        if (array_key_exists('floatValue', $val)) return [VARIABLETYPE_FLOAT, (float)$val['floatValue']];
        if (array_key_exists('stringValue', $val)) return $this->telemetryScalar($key, $val['stringValue']);

        // Generischer Tesla-Wrapper: erster Schlüssel auf "...Value"
        foreach ($val as $k => $inner) {
            if (!is_string($k) || substr($k, -5) !== 'Value') continue;

            // Verschachteltes Objekt, z.B. {"doorValue":{"DriverFront":false,...}}:
            // boolesche Map -> lesbare Liste der aktiven/offenen Einträge (leer = nichts aktiv)
            if (is_array($inner)) {
                $allBool = true;
                $active = [];
                foreach ($inner as $sub => $state) {
                    if (!is_bool($state)) { $allBool = false; break; }
                    if ($state) $active[] = $this->Translate((string)$sub);
                }
                if ($allBool) {
                    return [VARIABLETYPE_STRING, implode(', ', $active)];
                }
                continue;
            }

            return $this->telemetryScalar($key, $inner);
        }

        return [VARIABLETYPE_STRING, json_encode($val)];
    }

    // Einen skalaren Telemetrie-Wert in [Typ, Wert] übersetzen: Enum-Strings über
    // locale.json lesbar, große Zeitstempel (Schlüssel enthält "time") als Datum.
    private function telemetryScalar(string $key, $inner): array
    {
        if (is_bool($inner)) {
            return [VARIABLETYPE_BOOLEAN, $inner];
        }
        $isTime = stripos($key, 'time') !== false;
        if (is_int($inner) || is_float($inner)) {
            if ($isTime && (float)$inner > 1000000000) {
                return [VARIABLETYPE_STRING, date('d.m.Y H:i', (int)$inner)];
            }
            return [is_int($inner) ? VARIABLETYPE_INTEGER : VARIABLETYPE_FLOAT, $inner];
        }
        $s = trim((string)$inner);
        if (is_numeric($s)) {
            if ($isTime && (float)$s > 1000000000) {
                return [VARIABLETYPE_STRING, date('d.m.Y H:i', (int)$s)];
            }
            if (strpos($s, '.') === false && stripos($s, 'e') === false) {
                return [VARIABLETYPE_INTEGER, (int)$s];
            }
            return [VARIABLETYPE_FLOAT, (float)$s];
        }
        return [VARIABLETYPE_STRING, $this->readableEnumString((string)$inner)];
    }

    // Enum-/Code-String lesbar machen: zuerst locale.json, sonst generischer Fallback
    private function readableEnumString(string $s): string
    {
        $t = $this->Translate($s);
        if ($t !== $s) {
            return $t;
        }
        return $this->prettifyCode($s);
    }

    // Fallback für unbekannte CamelCase-/Code-Werte ohne locale-Eintrag:
    // "Induction20Black" -> "Induction 20 Black", "Apollo19CapKit" -> "Apollo 19 Cap Kit".
    // Nur einzelne Tokens (keine Leerzeichen, nur Buchstaben/Ziffern) werden zerlegt.
    private function prettifyCode(string $s): string
    {
        if ($s === '' || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $s)) {
            return $s;
        }
        $r = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $s);
        $r = preg_replace('/(?<=[A-Za-z])(?=[0-9])/', ' ', (string)$r);
        $r = preg_replace('/(?<=[0-9])(?=[A-Za-z])/', ' ', (string)$r);
        return (string)$r;
    }

    // Ersten skalaren String-Wert aus einem Telemetrie-Wrapper holen (stringValue oder *Value)
    private function telemetryFirstEnumString(array $val): ?string
    {
        if (isset($val['stringValue']) && is_string($val['stringValue'])) {
            return $val['stringValue'];
        }
        foreach ($val as $k => $inner) {
            if (is_string($k) && substr($k, -5) === 'Value' && is_string($inner)) {
                return $inner;
            }
        }
        return null;
    }

    private function getTelemetryRegistry(): array
    {
        $raw = $this->ReadAttributeString(self::ATTR_TELEMETRY_REGISTRY);
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    private function makeRegistryEntry(string $key, string $name, int $type): array
    {
        $profile = '';
        if ((bool)$this->ReadPropertyBoolean(self::PROP_AUTO_PROFILE_TELEMETRY_VARS)) {
            $profile = $this->guessProfileForTelemetryKey($key, $type);
        }
        return ['key' => $key, 'name' => $name, 'type' => $type, 'profile' => $profile];
    }

    private function translateTelemetryKey(string $key): string
    {
        // Übersetzung über locale.json. Wenn kein Eintrag existiert, gibt Translate den Originaltext zurück.
        return $this->Translate($key);
    }


    private function getVisibleList(): array
    {
        $arr = json_decode($this->ReadPropertyString(self::PROP_VISIBLE_VARS), true);
        return is_array($arr) ? $arr : [];
    }

    // Vollständige Liste: gespeicherte Reihenfolge + fehlende Kern-Standardvariablen
    // (z.B. nach einem Modul-Update neu hinzugekommene) + Telemetrie. Basis für
    // Anzeige und Positions-/Aktiv-Zuordnung, unabhängig davon, was gerade gespeichert ist.
    private function getEffectiveList(): array
    {
        $base = $this->getVisibleList();
        $present = [];
        foreach ($base as $row) {
            if (is_array($row)) {
                $id = (string)($row['Ident'] ?? '');
                if ($id !== '') $present[$id] = true;
            }
        }
        foreach ($this->getDefaultVisibleVars() as $def) {
            $id = (string)($def['Ident'] ?? '');
            if ($id !== '' && !isset($present[$id])) {
                $base[] = $def;
                $present[$id] = true;
            }
        }
        return $this->mergeTelemetryIntoVisibleVars($base);
    }

    private function mergeTelemetryIntoVisibleVars(array $list): array
    {
        $registry = $this->getTelemetryRegistry();
        if (count($registry) === 0) return $list;

        $present = [];
        foreach ($list as $row) {
            if (!is_array($row)) continue;
            $id = (string)($row['Ident'] ?? '');
            if ($id !== '') $present[$id] = true;
        }

        $defaultEnabled = (bool)$this->ReadPropertyBoolean(self::PROP_TELEMETRY_DEFAULT_ENABLED);

        foreach ($registry as $ident => $meta) {
            if (!is_string($ident) || $ident === '') continue;
            if (isset($present[$ident])) continue;
            $name = is_array($meta) ? (string)($meta['name'] ?? $ident) : $ident;
            $list[] = ['Ident' => $ident, 'Name' => $name, 'Enabled' => $defaultEnabled];
            $present[$ident] = true;
        }
        return array_values($list);
    }

    private function getEnabledMap(): array
    {
        $map = [];
        foreach ($this->getEffectiveList() as $row) {
            if (!is_array($row)) continue;
            $ident = (string)($row['Ident'] ?? '');
            if ($ident === '') continue;
            $map[$ident] = (bool)($row['Enabled'] ?? true);
        }
        return $map;
    }

    private function getOrderPosMap(int $step = 10): array
    {
        $posMap = [];
        $pos = $step;
        foreach ($this->getEffectiveList() as $row) {
            if (!is_array($row)) continue;
            $ident = (string)($row['Ident'] ?? '');
            if ($ident === '') continue;
            $posMap[$ident] = $pos;
            $pos += $step;
        }
        return $posMap;
    }

    private function safeSetValue(string $ident, $value): void
    {
        $this->safeSetValueIfExists($ident, $value);
    }

    private function telemetryGetNumber(array $val): ?float
    {
        if (array_key_exists('doubleValue', $val)) return (float)$val['doubleValue'];
        if (array_key_exists('intValue', $val)) return (float)$val['intValue'];
        if (array_key_exists('stringValue', $val)) {
            $s = trim((string)$val['stringValue']);
            if ($s !== '' && is_numeric($s)) return (float)$s;
        }
        return null;
    }

    private function telemetryGetBool(array $val): ?bool
    {
        if (array_key_exists('booleanValue', $val)) return (bool)$val['booleanValue'];
        if (array_key_exists('stringValue', $val)) {
            $s = strtolower(trim((string)$val['stringValue']));
            if ($s == 'true' || $s == '1') return true;
            if ($s == 'false' || $s == '0') return false;
        }
        return null;
    }

    private function telemetryGetString(array $val): ?string
    {
        if (array_key_exists('stringValue', $val)) return (string)$val['stringValue'];
        if (array_key_exists('doubleValue', $val)) return (string)$val['doubleValue'];
        if (array_key_exists('intValue', $val)) return (string)$val['intValue'];
        if (array_key_exists('booleanValue', $val)) return ((bool)$val['booleanValue'] ? 'true' : 'false');
        return null;
    }


    private function refreshTelemetryRegistryNames(): void
    {
        $registry = $this->getTelemetryRegistry();
        if (count($registry) === 0) {
            return;
        }

        $changed = false;
        foreach ($registry as $ident => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $key = (string)($meta['key'] ?? '');
            if ($key === '') {
                continue;
            }

            // Namen immer aus Übersetzung ableiten
            $name = $this->translateTelemetryKey($key);
            if (is_string($ident)) {
                if (str_ends_with($ident, '_lat')) {
                    $name .= ' – ' . $this->Translate('Latitude');
                } elseif (str_ends_with($ident, '_lon')) {
                    $name .= ' – ' . $this->Translate('Longitude');
                }
            }

            if (($meta['name'] ?? '') !== $name) {
                $registry[$ident]['name'] = $name;
                $changed = true;
            }
        }

        if ($changed) {
            $this->WriteAttributeString(self::ATTR_TELEMETRY_REGISTRY, json_encode($registry));
        }
    }

    // -------- Presentations (IPS 9.0) statt Variablenprofile --------
    private function presSwitch(string $on, string $off): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'CAPTION_ON' => $on, 'CAPTION_OFF' => $off];
    }

    private function presSlider(float $min, float $max, float $step, string $suffix, int $digits): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => $min, 'MAX' => $max, 'STEP_SIZE' => $step, 'SUFFIX' => $suffix, 'DIGITS' => $digits];
    }

    private function presValue(string $suffix = '', int $digits = 0): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => $suffix, 'DIGITS' => $digits];
    }

    private function presEnum(array $map): array
    {
        $options = [];
        foreach ($map as $v => $caption) {
            $options[] = ['Value' => $v, 'Caption' => $caption, 'IconActive' => false, 'Icon' => '', 'ColorActive' => false, 'ColorValue' => -1];
        }
        return ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($options)];
    }

    // Bildet die früheren Variablenprofile auf IPS-9.0-Presentations ab.
    // $settable steuert Eingabe-Darstellung (Slider) vs. reine Anzeige (Wert).
    private function presFor(string $profile, bool $settable): array
    {
        switch ($profile) {
            case '~Lock':                    return $this->presSwitch('Verriegelt', 'Entriegelt');
            case '~Switch':                  return $this->presSwitch('An', 'Aus');
            case 'Tessie.AtHome':            return $this->presSwitch('Zu Hause', 'Unterwegs');
            case 'Tessie.PercentInt':        return $settable ? $this->presSlider(0, 100, 1, ' %', 0) : $this->presValue(' %', 0);
            case 'Tessie.Amps':              return $settable ? $this->presSlider(0, 48, 1, ' A', 0) : $this->presValue(' A', 0);
            case 'Tessie.AmpsFloat':         return $this->presValue(' A', 1);
            case 'Tessie.kW':                return $this->presValue(' kW', 2);
            case 'Tessie.kWh':               return $this->presValue(' kWh', 2);
            case 'Tessie.Voltage':           return $this->presValue(' V', 1);
            case 'Tessie.PressureBar':       return $this->presValue(' bar', 2);
            case 'Tessie.Degrees':           return $this->presValue(' °', 1);
            case 'Tessie.SpeedKmh':          return $this->presValue(' km/h', 1);
            case 'Tessie.DistanceKm':        return $this->presValue(' km', 1);
            case 'Tessie.TempC':             return $this->presValue(' °C', 1);
            case 'Tessie.TempSetC':          return $settable ? $this->presSlider(15, 28, 0.5, ' °C', 1) : $this->presValue(' °C', 1);
            case 'Tessie.SeatHeatLevel':     return $this->presEnum([0 => 'Aus', 1 => 'Niedrig', 2 => 'Mittel', 3 => 'Hoch']);
            case 'Tessie.ClimateKeeperMode': return $this->presEnum([0 => 'Aus', 1 => 'Behalten', 2 => 'Hund', 3 => 'Camping']);
            case 'Tessie.COPTemp':           return $this->presEnum([1 => 'Niedrig', 2 => 'Mittel', 3 => 'Hoch']);
            default:                         return $this->presValue('', 0);
        }
    }

    
    private function guessProfileForTelemetryKey(string $key, int $type): string
    {
        $k = strtolower($key);

        if ($type !== VARIABLETYPE_STRING && (strpos($k, 'soc') !== false || strpos($k, 'percent') !== false)) {
            return 'Tessie.PercentInt';
        }
        if ($type !== VARIABLETYPE_STRING && (strpos($k, 'temp') !== false || strpos($k, 'temperature') !== false)) {
            return 'Tessie.TempC';
        }
        if ($type !== VARIABLETYPE_STRING && (strpos($k, 'power') !== false || strpos($k, 'kw') !== false)) {
            return 'Tessie.kW';
        }
        if ($type !== VARIABLETYPE_STRING && (strpos($k, 'energy') !== false || strpos($k, 'kwh') !== false)) {
            return 'Tessie.kWh';
        }
        if ($type !== VARIABLETYPE_STRING && strpos($k, 'voltage') !== false) {
            return 'Tessie.Voltage';
        }
        if ($type !== VARIABLETYPE_STRING && strpos($k, 'tpmspressure') !== false) {
            return 'Tessie.PressureBar';
        }
        if ($type !== VARIABLETYPE_STRING && strpos($k, 'heading') !== false) {
            return 'Tessie.Degrees';
        }
        if ($type !== VARIABLETYPE_STRING && (strpos($k, 'mph') !== false || $k === 'vehiclespeed')) {
            return 'Tessie.SpeedKmh';
        }
        if ($type !== VARIABLETYPE_STRING && (strpos($k, 'odometer') !== false || strpos($k, 'range') !== false || strpos($k, 'miles') !== false)) {
            return 'Tessie.DistanceKm';
        }

        return '';
    }


    /**
     * Alle konfigurierten Geofences (Zuhause + weitere Standorte) als Liste:
     * [['ident' => ..., 'name' => ..., 'lat' => ..., 'lon' => ..., 'radius' => ...], ...]
     * Einträge ohne gültige Position werden übersprungen.
     */
    private function getGeofences(): array
    {
        $fences = [];

        $parse = function ($locJson) {
            $loc = is_array($locJson) ? $locJson : json_decode((string)$locJson, true);
            if (!is_array($loc) || !isset($loc['latitude'], $loc['longitude'])) {
                return null;
            }
            $lat = (float)$loc['latitude'];
            $lon = (float)$loc['longitude'];
            if (($lat == 0.0 && $lon == 0.0) || !is_finite($lat) || !is_finite($lon)) {
                return null; // kein Standort gewählt
            }
            return [$lat, $lon];
        };

        // Zuhause (fester erster Eintrag, kompatibel zu 2.4.0).
        // Ohne eigene Angabe wird der Systemstandort aus der Kern-Instanz
        // "Location Control" übernommen (dort ist das Zuhause ohnehin gepflegt).
        $home = $parse($this->ReadPropertyString('HomeLocation'));
        if ($home === null) {
            $home = $parse($this->getSystemLocation());
        }
        if ($home !== null) {
            $fences[] = [
                'ident'  => self::STAT_AT_HOME,
                'name'   => 'Zuhause',
                'lat'    => $home[0],
                'lon'    => $home[1],
                'radius' => max(1, (int)$this->ReadPropertyInteger('HomeRadius'))
            ];
        }

        // Weitere Standorte aus der Liste
        $list = json_decode((string)$this->ReadPropertyString('GeofenceList'), true);
        if (is_array($list)) {
            foreach ($list as $row) {
                if (!is_array($row)) continue;
                $name = trim((string)($row['Name'] ?? ''));
                $pos = $parse($row['Location'] ?? '');
                if ($name === '' || $pos === null) continue;
                $fences[] = [
                    'ident'  => self::GEO_IDENT_PREFIX . strtolower($this->makeIdent($name)),
                    'name'   => $name,
                    'lat'    => $pos[0],
                    'lon'    => $pos[1],
                    'radius' => max(1, (int)($row['Radius'] ?? 100))
                ];
            }
        }

        return $fences;
    }

    /**
     * Systemstandort aus der Kern-Instanz "Location Control" (JSON {latitude, longitude}).
     * Leerer String, wenn keine Instanz vorhanden oder kein Standort gepflegt ist.
     */
    private function getSystemLocation(): string
    {
        $ids = @IPS_GetInstanceListByModuleID('{45E97A63-F870-408A-B259-2933F7EABF74}');
        if (!is_array($ids) || count($ids) === 0) {
            return '';
        }
        $loc = @IPS_GetProperty($ids[0], 'Location');
        return is_string($loc) ? $loc : '';
    }

    /**
     * Standort-Erkennung (Geofence): setzt je Standort die Boolean-Variable und
     * die Textvariable "Aktueller Standort" (Name des nächsten passenden Standorts,
     * sonst "Unterwegs"). No-op, solange die Erkennung deaktiviert ist.
     */
    private function updateHomeStatus(float $lat, float $lon): void
    {
        if (!$this->ReadPropertyBoolean('HomeDetection')) {
            return;
        }
        if (!is_finite($lat) || !is_finite($lon)) {
            return;
        }

        $bestName = '';
        $bestDist = PHP_FLOAT_MAX;

        // Vorheriger Zonen-Zustand für die Übergangs-Erkennung (Einfahrt/Ausfahrt/Durchfahrt)
        $state = json_decode($this->ReadAttributeString(self::ATTR_GEO_STATE), true);
        if (!is_array($state)) {
            $state = [];
        }
        $stateChanged = false;
        $now = time();
        $seen = [];

        foreach ($this->getGeofences() as $f) {
            $dist = $this->haversineMeters($lat, $lon, $f['lat'], $f['lon']);
            $inside = ($dist <= $f['radius']);
            $seen[$f['ident']] = true;

            $vid = @IPS_GetObjectIDByIdent($f['ident'], $this->InstanceID);
            if ($vid > 0 && GetValueBoolean($vid) !== $inside) {
                SetValueBoolean($vid, $inside);
            }

            // Übergänge auswerten – die erste Positionsmeldung nach Anlage der Zone
            // initialisiert nur den Zustand. Ein-/Ausfahrt lösen die Automationen
            // über die Standort-Variable aus (wird EIN/AUS); Durchfahrten werden
            // hier erkannt und für Op 'pass' vorgemerkt.
            if (!isset($state[$f['ident']])) {
                $state[$f['ident']] = ['in' => $inside, 'since' => $now];
                $stateChanged = true;
            } elseif ((bool)$state[$f['ident']]['in'] !== $inside) {
                if (!$inside) {
                    $dwell = $now - (int)($state[$f['ident']]['since'] ?? $now);
                    if ($dwell <= self::GEO_PASS_MAX_SECONDS) {
                        $this->geoPassEvents[$f['ident']] = true;
                    }
                }
                $state[$f['ident']] = ['in' => $inside, 'since' => $now];
                $stateChanged = true;
            }

            // Bei überlappenden Zonen gewinnt der nächstgelegene Standort
            if ($inside && $dist < $bestDist) {
                $bestDist = $dist;
                $bestName = $f['name'];
            }
        }

        // Zustände entfernter Zonen aufräumen
        foreach (array_keys($state) as $ident) {
            if (!isset($seen[$ident])) {
                unset($state[$ident]);
                $stateChanged = true;
            }
        }
        if ($stateChanged) {
            $this->WriteAttributeString(self::ATTR_GEO_STATE, json_encode($state));
        }

        $nameVid = @IPS_GetObjectIDByIdent(self::STAT_LOCATION_NAME, $this->InstanceID);
        if ($nameVid > 0) {
            $text = ($bestName !== '') ? $bestName : $this->Translate('On the road');
            if (GetValueString($nameVid) !== $text) {
                SetValueString($nameVid, $text);
            }
        }
    }

    /**
     * Führt eine Aktion (on|off|toggle|value) auf einer Zielvariable aus:
     * per RequestAction, falls die Variable eine Aktion hat, sonst per SetValue.
     * $context beschreibt den Auslöser für Debug/Log.
     */
    private function applyActionToVariable(int $vid, string $action, string $rawValue, string $context): bool
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            $this->SendDebug('Aktion', sprintf('%s: Zielvariable #%d existiert nicht', $context, $vid), 0);
            return false;
        }

        $var = IPS_GetVariable($vid);
        switch ($action) {
            case 'off':    $value = false; break;
            case 'toggle': $value = !(bool)GetValue($vid); break;
            case 'value':  $value = $this->castToVariableType($rawValue, (int)$var['VariableType']); break;
            case 'on':
            default:       $value = true; break;
        }
        // Bool-Aktionen auf Nicht-Bool-Variablen sinnvoll abbilden (0/1)
        if (is_bool($value) && (int)$var['VariableType'] !== VARIABLETYPE_BOOLEAN) {
            $value = $this->castToVariableType($value ? '1' : '0', (int)$var['VariableType']);
        }

        $hasAction = ((int)$var['VariableAction'] > 0 || (int)$var['VariableCustomAction'] > 0);
        $ok = $hasAction ? @RequestAction($vid, $value) : @SetValue($vid, $value);

        $this->SendDebug('Aktion', sprintf(
            '%s -> %s #%d = %s (%s)',
            $context, $hasAction ? 'RequestAction' : 'SetValue',
            $vid, json_encode($value), ($ok === false) ? 'FEHLER' : 'ok'
        ), 0);
        if ($ok === false) {
            $this->LogMessage(sprintf('Aktion "%s" auf Variable #%d fehlgeschlagen', $context, $vid), KL_WARNING);
        }
        return $ok !== false;
    }

    /** Wandelt den Regel-Wert (Text) in den Typ der Zielvariable um. */
    private function castToVariableType(string $raw, int $type)
    {
        $raw = trim($raw);
        switch ($type) {
            case VARIABLETYPE_BOOLEAN:
                return in_array(strtolower($raw), ['1', 'true', 'ein', 'on', 'ja', 'an'], true);
            case VARIABLETYPE_INTEGER:
                return (int)$raw;
            case VARIABLETYPE_FLOAT:
                return (float)str_replace(',', '.', $raw);
            default:
                return $raw;
        }
    }

    // ---------------------------------------------------------------------
    // Automationen (Wenn -> Dann): generische Regeln über beliebige Datenpunkte
    // ---------------------------------------------------------------------

    /**
     * Wertet alle Wenn->Dann-Regeln aus. Flankengesteuert: eine Regel feuert nur,
     * wenn ihre Bedingung von unerfüllt auf erfüllt wechselt (bzw. bei 'change',
     * wenn sich der Wert ändert) – nicht bei jeder Datenmeldung erneut.
     * $fire=false aktualisiert nur den Zustand ohne auszulösen (Baseline nach
     * Übernehmen, verhindert Fehlauslösungen durch alte Flanken).
     */
    private function evaluateDataActions(bool $fire = true): void
    {
        $rules = json_decode((string)$this->ReadPropertyString('DataActions'), true);
        if (!is_array($rules)) {
            $rules = [];
        }
        $state = json_decode($this->ReadAttributeString(self::ATTR_RULE_STATE), true);
        if (!is_array($state)) {
            $state = [];
        }
        $stateChanged = false;

        foreach ($rules as $i => $rule) {
            if (!is_array($rule)) continue;
            $key = (string)$i;

            $srcIdent = (string)($rule['Source'] ?? '');
            $vid = ($srcIdent !== '') ? @IPS_GetObjectIDByIdent($srcIdent, $this->InstanceID) : 0;
            if ($vid <= 0) continue;

            $op = (string)($rule['Op'] ?? 'true');

            // Durchfahrt (nur Standort-Variablen): Ereignis kommt aus updateHomeStatus,
            // kein Flanken-Tracking über RuleState nötig
            if ($op === 'pass') {
                if ($fire && isset($this->geoPassEvents[$srcIdent]) && (bool)($rule['Active'] ?? true)) {
                    $this->applyActionToVariable(
                        (int)($rule['Target'] ?? 0),
                        (string)($rule['Action'] ?? 'on'),
                        (string)($rule['Value'] ?? ''),
                        'Automation ' . $this->describeDataAction($rule)
                    );
                }
                continue;
            }

            $cur = GetValue($vid);
            $cond = $this->evalRuleCondition($cur, $op, (string)($rule['Compare'] ?? ''));
            $serial = json_encode($cur);

            $prev = $state[$key] ?? null;
            if ($op === 'change') {
                $fireNow = ($prev !== null) && (($prev['val'] ?? null) !== $serial);
            } else {
                // Erste Auswertung nach Anlage: nur Baseline, kein Auslösen
                $fireNow = ($prev !== null) && !(bool)($prev['cond'] ?? false) && $cond;
            }

            if ($prev === null || ($prev['cond'] ?? null) !== $cond || ($prev['val'] ?? null) !== $serial) {
                $state[$key] = ['cond' => $cond, 'val' => $serial];
                $stateChanged = true;
            }

            if ($fire && $fireNow && (bool)($rule['Active'] ?? true)) {
                $this->applyActionToVariable(
                    (int)($rule['Target'] ?? 0),
                    (string)($rule['Action'] ?? 'on'),
                    (string)($rule['Value'] ?? ''),
                    'Automation ' . $this->describeDataAction($rule)
                );
            }
        }

        // Zustände gelöschter Regeln aufräumen
        foreach (array_keys($state) as $k) {
            if (!isset($rules[(int)$k])) {
                unset($state[$k]);
                $stateChanged = true;
            }
        }
        if ($stateChanged) {
            $this->WriteAttributeString(self::ATTR_RULE_STATE, json_encode($state));
        }

        // Durchfahrts-Ereignisse sind verbraucht
        $this->geoPassEvents = [];
    }

    /** Prüft, ob der aktuelle Wert die Bedingung erfüllt. */
    private function evalRuleCondition($cur, string $op, string $cmp): bool
    {
        switch ($op) {
            case 'true':   return (bool)$cur === true;
            case 'false':  return (bool)$cur === false;
            case 'change': return false; // Sonderfall, wird über den Wertvergleich behandelt
        }

        $cmp = trim($cmp);
        if (is_bool($cur)) {
            $cur = $cur ? 1 : 0;
        }
        $numeric = is_numeric($cur) && is_numeric(str_replace(',', '.', $cmp));
        if ($numeric) {
            $a = (float)$cur;
            $b = (float)str_replace(',', '.', $cmp);
        } else {
            $a = (string)$cur;
            $b = $cmp;
        }

        switch ($op) {
            case 'eq': return $numeric ? (abs($a - $b) < 1e-9) : (strcasecmp($a, $b) === 0);
            case 'ne': return $numeric ? (abs($a - $b) >= 1e-9) : (strcasecmp($a, $b) !== 0);
            case 'gt': return $numeric && $a > $b;
            case 'ge': return $numeric && $a >= $b;
            case 'lt': return $numeric && $a < $b;
            case 'le': return $numeric && $a <= $b;
        }
        return false;
    }

    /** Menschenlesbare Beschreibung einer Regel, z. B. für Kachel und Debug. */
    private function describeDataAction(array $rule): string
    {
        $opText = [
            'true' => 'wird EIN', 'false' => 'wird AUS', 'change' => 'ändert sich',
            'pass' => 'Durchfahrt',
            'eq' => '=', 'ne' => '≠', 'gt' => '>', 'ge' => '≥', 'lt' => '<', 'le' => '≤'
        ];

        $srcIdent = (string)($rule['Source'] ?? '');
        $srcVid = ($srcIdent !== '') ? @IPS_GetObjectIDByIdent($srcIdent, $this->InstanceID) : 0;
        $srcName = ($srcVid > 0) ? IPS_GetName($srcVid) : $srcIdent;

        $op = (string)($rule['Op'] ?? 'true');
        $cond = $opText[$op] ?? $op;
        if (!in_array($op, ['true', 'false', 'change', 'pass'], true)) {
            $cond .= ' ' . (string)($rule['Compare'] ?? '');
        }

        $tVid = (int)($rule['Target'] ?? 0);
        $tName = ($tVid > 0 && IPS_VariableExists($tVid)) ? IPS_GetName($tVid) : ('#' . $tVid);
        switch ((string)($rule['Action'] ?? 'on')) {
            case 'off':    $do = $tName . ' ausschalten'; break;
            case 'toggle': $do = $tName . ' umschalten'; break;
            case 'value':  $do = $tName . ' = ' . (string)($rule['Value'] ?? ''); break;
            default:       $do = $tName . ' einschalten'; break;
        }

        return sprintf('Wenn %s %s → %s', $srcName, $cond, $do);
    }

    /** Datenpunkt-Optionen für Regelquellen: alle Datenpunkte + Geofence-Variablen. */
    private function getAutomationSourceOptions(?array $fullList = null): array
    {
        if ($fullList === null) {
            $fullList = $this->buildFormRows($this->getEffectiveList());
        }
        $options = [];
        foreach ($fullList as $row) {
            if (!is_array($row)) continue;
            $ident = (string)($row['Ident'] ?? '');
            if ($ident === '') continue;
            $options[] = ['caption' => (string)($row['Name'] ?? $ident), 'value' => $ident];
        }
        if ($this->ReadPropertyBoolean('HomeDetection')) {
            $options[] = ['caption' => 'Aktueller Standort', 'value' => self::STAT_LOCATION_NAME];
            foreach ($this->getGeofences() as $f) {
                $options[] = [
                    'caption' => ($f['ident'] === self::STAT_AT_HOME) ? 'Zu Hause' : $f['name'],
                    'value'   => $f['ident']
                ];
            }
        }
        return $options;
    }

    /**
     * Daten für den Regel-Editor der Kachel: Datenpunkte (Quellen) und
     * schaltbare Zielvariablen mit Objektbaum-Pfad. JSON:
     * {sources:[{v,c}], targets:[{v,c,p}]}
     */
    public function GetDataActionEditor(): string
    {
        $sources = [];
        foreach ($this->getAutomationSourceOptions() as $o) {
            $sources[] = ['v' => $o['value'], 'c' => $o['caption']];
        }

        // Schaltbare Variablen (mit Aktion) als sinnvolle Ziele; nach Pfad sortiert.
        // Beliebige weitere Ziele lassen sich im Instanzformular wählen.
        $targets = [];
        foreach (IPS_GetVariableList() as $vid) {
            $var = IPS_GetVariable($vid);
            if ((int)$var['VariableAction'] <= 0 && (int)$var['VariableCustomAction'] <= 0) {
                continue;
            }
            $targets[] = ['v' => $vid, 'c' => IPS_GetName($vid), 'p' => IPS_GetLocation($vid)];
            if (count($targets) >= 1000) break;
        }
        usort($targets, function ($a, $b) { return strcasecmp($a['p'], $b['p']); });

        return json_encode(['sources' => $sources, 'targets' => $targets]);
    }

    /**
     * Auswählbare Werte einer Zielvariable (Presentation-Enumeration/-Switch bzw.
     * Legacy-Profil-Assoziationen) als JSON [{v, c}]. Leer, wenn frei einzugeben.
     */
    public function GetTargetValueOptions(int $VariableID): string
    {
        if ($VariableID <= 0 || !IPS_VariableExists($VariableID)) {
            return '[]';
        }
        $out = [];
        $var = IPS_GetVariable($VariableID);

        $pres = @IPS_GetVariablePresentation($VariableID);
        if (is_array($pres)) {
            $p = $pres['PRESENTATION'] ?? '';
            if ($p === VARIABLE_PRESENTATION_ENUMERATION) {
                $opts = json_decode((string)($pres['OPTIONS'] ?? '[]'), true);
                if (is_array($opts)) {
                    foreach ($opts as $o) {
                        if (is_array($o) && isset($o['Value'])) {
                            $out[] = ['v' => $o['Value'], 'c' => (string)($o['Caption'] ?? $o['Value'])];
                        }
                    }
                }
            } elseif ($p === VARIABLE_PRESENTATION_SWITCH) {
                $out[] = ['v' => 1, 'c' => (string)($pres['CAPTION_ON'] ?? 'Ein')];
                $out[] = ['v' => 0, 'c' => (string)($pres['CAPTION_OFF'] ?? 'Aus')];
            }
        }

        // Legacy-Variablenprofil
        if (count($out) === 0) {
            $profile = ($var['VariableCustomProfile'] !== '') ? $var['VariableCustomProfile'] : $var['VariableProfile'];
            if ($profile !== '' && IPS_VariableProfileExists($profile)) {
                foreach (IPS_GetVariableProfile($profile)['Associations'] as $a) {
                    $out[] = ['v' => $a['Value'], 'c' => (string)$a['Name']];
                }
            }
        }

        if (count($out) === 0 && (int)$var['VariableType'] === VARIABLETYPE_BOOLEAN) {
            $out = [['v' => 1, 'c' => 'Ein'], ['v' => 0, 'c' => 'Aus']];
        }
        return json_encode($out);
    }

    /**
     * Legt eine Regel an oder überschreibt sie ($Index < 0 = anhängen).
     * $RuleJSON: {Active, Source, Op, Compare, Target, Action, Value}
     */
    public function SetDataAction(int $Index, string $RuleJSON): void
    {
        $in = json_decode($RuleJSON, true);
        if (!is_array($in)) {
            return;
        }
        $ops = ['true', 'false', 'eq', 'ne', 'gt', 'ge', 'lt', 'le', 'change', 'pass'];
        $acts = ['on', 'off', 'toggle', 'value'];
        $rule = [
            'Active'  => (bool)($in['Active'] ?? true),
            'Source'  => (string)($in['Source'] ?? ''),
            'Op'      => in_array(($in['Op'] ?? ''), $ops, true) ? (string)$in['Op'] : 'true',
            'Compare' => (string)($in['Compare'] ?? ''),
            'Target'  => (int)($in['Target'] ?? 0),
            'Action'  => in_array(($in['Action'] ?? ''), $acts, true) ? (string)$in['Action'] : 'on',
            'Value'   => (string)($in['Value'] ?? '')
        ];
        if ($rule['Source'] === '' || $rule['Target'] <= 0) {
            return;
        }

        $rules = json_decode((string)$this->ReadPropertyString('DataActions'), true);
        if (!is_array($rules)) {
            $rules = [];
        }
        if ($Index >= 0 && isset($rules[$Index])) {
            $rules[$Index] = $rule;
        } else {
            $rules[] = $rule;
        }
        IPS_SetProperty($this->InstanceID, 'DataActions', json_encode(array_values($rules)));
        IPS_ApplyChanges($this->InstanceID);
    }

    /**
     * Standort-Konfiguration für die Kachel:
     * {enabled, home:{lat,lon,radius,fromSystem}, fences:[{i,name,lat,lon,radius}]}
     */
    public function GetGeofenceConfig(): string
    {
        $parse = function ($locJson) {
            $loc = json_decode((string)$locJson, true);
            if (!is_array($loc) || !isset($loc['latitude'], $loc['longitude'])) return null;
            $lat = (float)$loc['latitude'];
            $lon = (float)$loc['longitude'];
            if (($lat == 0.0 && $lon == 0.0) || !is_finite($lat) || !is_finite($lon)) return null;
            return [$lat, $lon];
        };

        $own = $parse($this->ReadPropertyString('HomeLocation'));
        $sys = ($own === null) ? $parse($this->getSystemLocation()) : null;
        $eff = $own ?? $sys;
        $home = [
            'lat'        => $eff[0] ?? null,
            'lon'        => $eff[1] ?? null,
            'radius'     => max(1, (int)$this->ReadPropertyInteger('HomeRadius')),
            'fromSystem' => ($own === null && $sys !== null)
        ];

        $fences = [];
        $list = json_decode((string)$this->ReadPropertyString('GeofenceList'), true);
        if (is_array($list)) {
            foreach ($list as $i => $row) {
                if (!is_array($row)) continue;
                $pos = $parse($row['Location'] ?? '');
                $fences[] = [
                    'i'      => $i,
                    'name'   => (string)($row['Name'] ?? ''),
                    'lat'    => $pos[0] ?? null,
                    'lon'    => $pos[1] ?? null,
                    'radius' => max(1, (int)($row['Radius'] ?? 100))
                ];
            }
        }

        return json_encode([
            'enabled' => $this->ReadPropertyBoolean('HomeDetection'),
            'home'    => $home,
            'fences'  => $fences
        ]);
    }

    /** Schaltet die Standort-Erkennung ein/aus (z. B. aus der Kachel). */
    public function SetGeofenceEnabled(bool $Enabled): void
    {
        if ($this->ReadPropertyBoolean('HomeDetection') === $Enabled) {
            return;
        }
        IPS_SetProperty($this->InstanceID, 'HomeDetection', $Enabled);
        IPS_ApplyChanges($this->InstanceID);
    }

    /**
     * Setzt den Zuhause-Standort ({lat, lon, radius}); lat/lon leer/null = Systemstandort.
     */
    public function SetHomeGeofence(string $JSON): void
    {
        $in = json_decode($JSON, true);
        if (!is_array($in)) {
            return;
        }
        $lat = isset($in['lat']) && $in['lat'] !== '' && $in['lat'] !== null ? (float)$in['lat'] : null;
        $lon = isset($in['lon']) && $in['lon'] !== '' && $in['lon'] !== null ? (float)$in['lon'] : null;
        $loc = ($lat !== null && $lon !== null && is_finite($lat) && is_finite($lon))
            ? json_encode(['latitude' => $lat, 'longitude' => $lon])
            : '';
        IPS_SetProperty($this->InstanceID, 'HomeLocation', $loc);
        IPS_SetProperty($this->InstanceID, 'HomeRadius', min(5000, max(10, (int)($in['radius'] ?? 100))));
        IPS_ApplyChanges($this->InstanceID);
    }

    /**
     * Legt einen weiteren Standort an oder überschreibt ihn ($Index < 0 = anhängen).
     * $JSON: {name, lat, lon, radius}
     */
    public function SetGeofence(int $Index, string $JSON): void
    {
        $in = json_decode($JSON, true);
        if (!is_array($in)) {
            return;
        }
        $name = trim((string)($in['name'] ?? ''));
        $lat = (float)($in['lat'] ?? NAN);
        $lon = (float)($in['lon'] ?? NAN);
        if ($name === '' || $name === 'Zuhause' || !is_finite($lat) || !is_finite($lon) || ($lat == 0.0 && $lon == 0.0)) {
            return;
        }
        $row = [
            'Name'     => $name,
            'Location' => json_encode(['latitude' => $lat, 'longitude' => $lon]),
            'Radius'   => min(5000, max(10, (int)($in['radius'] ?? 100)))
        ];

        $list = json_decode((string)$this->ReadPropertyString('GeofenceList'), true);
        if (!is_array($list)) {
            $list = [];
        }
        if ($Index >= 0 && isset($list[$Index])) {
            $list[$Index] = $row;
        } else {
            $list[] = $row;
        }
        IPS_SetProperty($this->InstanceID, 'GeofenceList', json_encode(array_values($list)));
        IPS_ApplyChanges($this->InstanceID);
    }

    /** Löscht einen weiteren Standort (z. B. aus der Kachel). */
    public function DeleteGeofence(int $Index): void
    {
        $list = json_decode((string)$this->ReadPropertyString('GeofenceList'), true);
        if (!is_array($list) || !isset($list[$Index])) {
            return;
        }
        unset($list[$Index]);
        IPS_SetProperty($this->InstanceID, 'GeofenceList', json_encode(array_values($list)));
        IPS_ApplyChanges($this->InstanceID);
    }

    /** Löscht eine Regel (z. B. aus der Kachel). */
    public function DeleteDataAction(int $Index): void
    {
        $rules = json_decode((string)$this->ReadPropertyString('DataActions'), true);
        if (!is_array($rules) || !isset($rules[$Index])) {
            return;
        }
        unset($rules[$Index]);
        IPS_SetProperty($this->InstanceID, 'DataActions', json_encode(array_values($rules)));
        IPS_ApplyChanges($this->InstanceID);
    }

    /**
     * Regeln als JSON für die Kachel: [{i, text, active}, ...]
     */
    public function GetDataActions(): string
    {
        $rules = json_decode((string)$this->ReadPropertyString('DataActions'), true);
        $out = [];
        if (is_array($rules)) {
            foreach ($rules as $i => $rule) {
                if (!is_array($rule)) continue;
                $out[] = [
                    'i'      => $i,
                    'text'   => $this->describeDataAction($rule),
                    'active' => (bool)($rule['Active'] ?? true),
                    // Rohfelder, damit der Kachel-Editor die Regel laden kann
                    'rule'   => [
                        'Source'  => (string)($rule['Source'] ?? ''),
                        'Op'      => (string)($rule['Op'] ?? 'true'),
                        'Compare' => (string)($rule['Compare'] ?? ''),
                        'Target'  => (int)($rule['Target'] ?? 0),
                        'Action'  => (string)($rule['Action'] ?? 'on'),
                        'Value'   => (string)($rule['Value'] ?? '')
                    ]
                ];
            }
        }
        return json_encode($out);
    }

    /**
     * Aktiviert/deaktiviert eine Regel (z. B. aus der Kachel). Schreibt die
     * Eigenschaft und übernimmt sie – wie eine Änderung im Formular.
     */
    public function SetDataActionActive(int $Index, bool $Active): void
    {
        $rules = json_decode((string)$this->ReadPropertyString('DataActions'), true);
        if (!is_array($rules) || !isset($rules[$Index]) || !is_array($rules[$Index])) {
            return;
        }
        if ((bool)($rules[$Index]['Active'] ?? true) === $Active) {
            return;
        }
        $rules[$Index]['Active'] = $Active;
        IPS_SetProperty($this->InstanceID, 'DataActions', json_encode($rules));
        IPS_ApplyChanges($this->InstanceID);
    }

    /** Entfernung zweier Koordinaten in Metern (Haversine). */
    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371000.0; // m
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function ensureVariables(): void
    {
        $enabled = $this->getEnabledMap();
        $posMap  = $this->getOrderPosMap(10);

        $keep = fn(string $ident) => ($enabled[$ident] ?? true);
        $pos  = fn(string $ident) => ($posMap[$ident] ?? 0);

        // Links löschen, wenn deaktiviert
        foreach (array_keys($posMap) as $ident) {
            if (!$keep($ident)) {
                $this->deleteManagedLinksForIdent($ident);
            }
        }

        // Core Variablen
        $this->MaintainVariable(self::ACT_LOCKED, 'Verriegelt', VARIABLETYPE_BOOLEAN, $this->presFor('~Lock', true), $pos(self::ACT_LOCKED), true);
        if ($keep(self::ACT_LOCKED)) $this->EnableAction(self::ACT_LOCKED);

        $this->MaintainVariable(self::ACT_CLIMATE, 'Klima', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_CLIMATE), true);
        if ($keep(self::ACT_CLIMATE)) $this->EnableAction(self::ACT_CLIMATE);


        $this->MaintainVariable(self::ACT_CLIMATE_KEEPER_MODE, 'Klimahaltung', VARIABLETYPE_INTEGER, $this->presFor('Tessie.ClimateKeeperMode', true), $pos(self::ACT_CLIMATE_KEEPER_MODE), true);
        if ($keep(self::ACT_CLIMATE_KEEPER_MODE)) $this->EnableAction(self::ACT_CLIMATE_KEEPER_MODE);

        $this->MaintainVariable(self::ACT_COP_ENABLED, 'Innenraum-Überhitzeschutz', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_COP_ENABLED), true);
        if ($keep(self::ACT_COP_ENABLED)) $this->EnableAction(self::ACT_COP_ENABLED);

        $this->MaintainVariable(self::ACT_COP_FAN_ONLY, 'Innenraum-Überhitzeschutz: nur Lüfter', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_COP_FAN_ONLY), true);
        if ($keep(self::ACT_COP_FAN_ONLY)) $this->EnableAction(self::ACT_COP_FAN_ONLY);

        $this->MaintainVariable(self::ACT_COP_TEMP, 'Innenraum-Überhitzeschutz: Temperaturlimit', VARIABLETYPE_INTEGER, $this->presFor('Tessie.COPTemp', true), $pos(self::ACT_COP_TEMP), true);
        if ($keep(self::ACT_COP_TEMP)) $this->EnableAction(self::ACT_COP_TEMP);

        $this->MaintainVariable(self::ACT_BIO_DEFENSE, 'Bio Defense Mode', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_BIO_DEFENSE), true);
        if ($keep(self::ACT_BIO_DEFENSE)) $this->EnableAction(self::ACT_BIO_DEFENSE);

        $this->MaintainVariable(self::ACT_HOMELINK, 'HomeLink auslösen', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_HOMELINK), true);
        if ($keep(self::ACT_HOMELINK)) $this->EnableAction(self::ACT_HOMELINK);

        $this->MaintainVariable(self::ACT_FRONT_TRUNK, 'Vorderer Kofferraum öffnen', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_FRONT_TRUNK), true);
        if ($keep(self::ACT_FRONT_TRUNK)) $this->EnableAction(self::ACT_FRONT_TRUNK);

        $this->MaintainVariable(self::ACT_REAR_TRUNK, 'Heckklappe öffnen/schließen', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_REAR_TRUNK), true);
        if ($keep(self::ACT_REAR_TRUNK)) $this->EnableAction(self::ACT_REAR_TRUNK);

        $this->MaintainVariable(self::ACT_START_CHARGING, 'Laden', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_START_CHARGING), true);
        if ($keep(self::ACT_START_CHARGING)) $this->EnableAction(self::ACT_START_CHARGING);

        $this->MaintainVariable(self::ACT_CHARGE_LIMIT, 'Ladelimit (%)', VARIABLETYPE_INTEGER, $this->presFor('Tessie.PercentInt', true), $pos(self::ACT_CHARGE_LIMIT), true);
        if ($keep(self::ACT_CHARGE_LIMIT)) $this->EnableAction(self::ACT_CHARGE_LIMIT);

        $this->MaintainVariable(self::ACT_CHARGING_AMPS_REQUEST, 'Ladestrom Soll (A)', VARIABLETYPE_INTEGER, $this->presFor('Tessie.Amps', true), $pos(self::ACT_CHARGING_AMPS_REQUEST), true);
        if ($keep(self::ACT_CHARGING_AMPS_REQUEST)) $this->EnableAction(self::ACT_CHARGING_AMPS_REQUEST);

        $this->MaintainVariable(self::ACT_FLASH, 'Licht blinken', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_FLASH), true);
        if ($keep(self::ACT_FLASH)) $this->EnableAction(self::ACT_FLASH);

        $this->MaintainVariable(self::ACT_HONK, 'Hupe', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_HONK), true);
        if ($keep(self::ACT_HONK)) $this->EnableAction(self::ACT_HONK);

        $this->MaintainVariable(self::ACT_SENTRY_MODE, 'Sentry Mode', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_SENTRY_MODE), true);
        if ($keep(self::ACT_SENTRY_MODE)) $this->EnableAction(self::ACT_SENTRY_MODE);

        $this->MaintainVariable(self::ACT_VALET_MODE, 'Valet-Modus', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_VALET_MODE), true);
        if ($keep(self::ACT_VALET_MODE)) $this->EnableAction(self::ACT_VALET_MODE);

        $this->MaintainVariable(self::ACT_VENT_WINDOWS, 'Fenster lüften', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_VENT_WINDOWS), true);
        if ($keep(self::ACT_VENT_WINDOWS)) $this->EnableAction(self::ACT_VENT_WINDOWS);

        $this->MaintainVariable(self::ACT_CLOSE_WINDOWS, 'Fenster schließen', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_CLOSE_WINDOWS), true);
        if ($keep(self::ACT_CLOSE_WINDOWS)) $this->EnableAction(self::ACT_CLOSE_WINDOWS);

        $this->MaintainVariable(self::ACT_DEFROST, 'Max Defrost', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_DEFROST), true);
        if ($keep(self::ACT_DEFROST)) $this->EnableAction(self::ACT_DEFROST);

        $this->MaintainVariable(self::ACT_STEERING_WHEEL_HEATER, 'Lenkradheizung', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_STEERING_WHEEL_HEATER), true);
        if ($keep(self::ACT_STEERING_WHEEL_HEATER)) $this->EnableAction(self::ACT_STEERING_WHEEL_HEATER);

        $this->MaintainVariable(self::ACT_TEMP_DRIVER, 'Solltemperatur Fahrer (°C)', VARIABLETYPE_FLOAT, $this->presFor('Tessie.TempSetC', true), $pos(self::ACT_TEMP_DRIVER), true);
        if ($keep(self::ACT_TEMP_DRIVER)) $this->EnableAction(self::ACT_TEMP_DRIVER);

        $this->MaintainVariable(self::ACT_TEMP_PASSENGER, 'Solltemperatur Beifahrer (°C)', VARIABLETYPE_FLOAT, $this->presFor('Tessie.TempSetC', true), $pos(self::ACT_TEMP_PASSENGER), true);
        if ($keep(self::ACT_TEMP_PASSENGER)) $this->EnableAction(self::ACT_TEMP_PASSENGER);

        $this->MaintainVariable(self::ACT_SEAT_HEAT_DRIVER, 'Sitzheizung Fahrer', VARIABLETYPE_INTEGER, $this->presFor('Tessie.SeatHeatLevel', true), $pos(self::ACT_SEAT_HEAT_DRIVER), true);
        if ($keep(self::ACT_SEAT_HEAT_DRIVER)) $this->EnableAction(self::ACT_SEAT_HEAT_DRIVER);

        $this->MaintainVariable(self::ACT_SEAT_HEAT_PASSENGER, 'Sitzheizung Beifahrer', VARIABLETYPE_INTEGER, $this->presFor('Tessie.SeatHeatLevel', true), $pos(self::ACT_SEAT_HEAT_PASSENGER), true);
        if ($keep(self::ACT_SEAT_HEAT_PASSENGER)) $this->EnableAction(self::ACT_SEAT_HEAT_PASSENGER);

        $this->MaintainVariable(self::ACT_OPEN_CHARGE_PORT, 'Ladeport öffnen/entriegeln', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_OPEN_CHARGE_PORT), true);
        if ($keep(self::ACT_OPEN_CHARGE_PORT)) $this->EnableAction(self::ACT_OPEN_CHARGE_PORT);

        $this->MaintainVariable(self::ACT_CLOSE_CHARGE_PORT, 'Ladeport schließen', VARIABLETYPE_BOOLEAN, $this->presFor('~Switch', true), $pos(self::ACT_CLOSE_CHARGE_PORT), true);
        if ($keep(self::ACT_CLOSE_CHARGE_PORT)) $this->EnableAction(self::ACT_CLOSE_CHARGE_PORT);


        $this->MaintainVariable(self::STAT_CHARGING_AMPS_ACTUAL, 'Ladestrom Ist (A)', VARIABLETYPE_FLOAT, $this->presFor('Tessie.AmpsFloat', false), $pos(self::STAT_CHARGING_AMPS_ACTUAL), true);
        $this->MaintainVariable(self::STAT_CHARGING_AMPS_MAX, 'Ladestrom Max (A)', VARIABLETYPE_INTEGER, $this->presFor('Tessie.Amps', false), $pos(self::STAT_CHARGING_AMPS_MAX), true);
        $this->MaintainVariable(self::STAT_AC_CHARGING_POWER, 'AC Ladeleistung (kW)', VARIABLETYPE_FLOAT, $this->presFor('Tessie.kW', false), $pos(self::STAT_AC_CHARGING_POWER), true);

        // Standort-Erkennung (Geofence): Status-Variablen nur anlegen/einblenden, wenn aktiviert.
        // Nicht Teil der VisibleVars-Liste – wird komplett über die Eigenschaften gesteuert.
        $geoActiveIdents = [];
        if ($this->ReadPropertyBoolean('HomeDetection')) {
            $geoPos = (count($posMap) ? max($posMap) : 0) + 10;

            // Textvariable "Aktueller Standort" (Name des Geofence bzw. "Unterwegs")
            $this->MaintainVariable(self::STAT_LOCATION_NAME, 'Aktueller Standort', VARIABLETYPE_STRING, $this->presValue(), $geoPos, true);
            $geoActiveIdents[self::STAT_LOCATION_NAME] = true;
            $geoPos += 10;

            // Je Standort eine Boolean-Variable ("Zu Hause" = fester Zuhause-Eintrag)
            foreach ($this->getGeofences() as $f) {
                if ($f['ident'] === self::STAT_AT_HOME) {
                    $name = 'Zu Hause';
                    $pres = $this->presFor('Tessie.AtHome', false);
                } else {
                    $name = $f['name'];
                    $pres = $this->presSwitch($f['name'], $this->Translate('Away'));
                }
                $this->MaintainVariable($f['ident'], $name, VARIABLETYPE_BOOLEAN, $pres, $geoPos, true);
                $geoActiveIdents[$f['ident']] = true;
                $geoPos += 10;
            }
        }

        // Nicht (mehr) konfigurierte Geofence-Variablen ausblenden statt löschen
        // (Objekt-ID und Archivdaten bleiben erhalten; greift auch bei Deaktivierung/Umbenennung)
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $obj = IPS_GetObject($cid);
            $ident = (string)($obj['ObjectIdent'] ?? '');
            $isGeo = ($ident === self::STAT_AT_HOME || $ident === self::STAT_LOCATION_NAME
                || strpos($ident, self::GEO_IDENT_PREFIX) === 0);
            if (!$isGeo) continue;
            $hide = !isset($geoActiveIdents[$ident]);
            if (($obj['ObjectIsHidden'] ?? false) != $hide) {
                IPS_SetHidden($cid, $hide);
            }
        }

        // Telemetrie-Variablen aus Registry (nur wenn User aktiviert)
        $registry = $this->getTelemetryRegistry();
        foreach ($registry as $ident => $meta) {
            if (!is_string($ident) || $ident === '') continue;
            if (!is_array($meta)) continue;

            $isEnabled = isset($enabled[$ident]) ? (bool)$enabled[$ident] : false;
            $name = (string)($meta['name'] ?? $ident);
            $type = (int)($meta['type'] ?? VARIABLETYPE_STRING);
            $profile = (string)($meta['profile'] ?? '');
            $position = $posMap[$ident] ?? 0;

            // Über Aktions-Variablen abgebildete Keys nicht als Telemetrie-Duplikat pflegen
            if ($this->isSupersededTelemetryIdent($ident, $registry)) continue;

            // Neu nur anlegen, solange aktiviert; bereits vorhandene bleiben erhalten
            // und werden unten ggf. ausgeblendet (so bleiben Objekt-ID und Archivdaten erhalten)
            $exists = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) > 0;
            if (!$exists && !$isEnabled) continue;

            // GPS-Koordinaten (Lat/Lon) brauchen genug Nachkommastellen (~1 m -> 6 Stellen)
            $isCoord = (substr($ident, -4) === '_lat' || substr($ident, -4) === '_lon');
            $presentation = $isCoord ? $this->presValue('', 6) : $this->presFor($profile, false);
            $this->MaintainVariable($ident, $name, $type, $presentation, $position, true);
        }

        // Abgewählte Variablen ausblenden statt löschen (Objekt-ID und Archivdaten bleiben erhalten);
        // über Aktions-Variablen abgebildete Telemetrie-Duplikate werden immer ausgeblendet
        foreach ($posMap as $ident => $pos) {
            $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($vid <= 0) continue;
            $hidden = $this->isSupersededTelemetryIdent($ident, $registry) ? true : !($enabled[$ident] ?? true);
            $obj = IPS_GetObject($vid);
            if (($obj['ObjectIsHidden'] ?? false) != $hidden) {
                IPS_SetHidden($vid, $hidden);
            }
            // Reihenfolge der Liste auf die Variablen-Position übertragen. MaintainVariable
            // setzt die Position nur bei der Neuanlage, daher hier explizit nachführen
            // (nur bei Abweichung, sonst Update-Sturm).
            if (($obj['ObjectPosition'] ?? 0) != $pos) {
                IPS_SetPosition($vid, $pos);
            }
        }

        // Bestehende Variablen mit altem englischen Namen umbenennen (vom Nutzer
        // angepasste Namen bleiben erhalten, da nur exakte alte Defaults ersetzt werden)
        $renames = [
            self::ACT_CLIMATE_KEEPER_MODE => ['Climate Keeper Mode' => 'Klimahaltung'],
            self::ACT_FRONT_TRUNK         => ['Front-Trunk öffnen' => 'Vorderer Kofferraum öffnen'],
            self::ACT_REAR_TRUNK          => ['Rear-Trunk öffnen/schließen' => 'Heckklappe öffnen/schließen']
        ];
        foreach ($renames as $ident => $map) {
            $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($vid <= 0) continue;
            $cur = IPS_GetName($vid);
            if (isset($map[$cur])) {
                IPS_SetName($vid, $map[$cur]);
            }
        }
    }

    // Linktree (deine vorhandene Logik, nur Strings sind bereits DE)
    private function ensureLinkTree(bool $forceRename = false): void
    {
        if (!(bool)$this->ReadPropertyBoolean('CreateLinks')) return;

        $linksParent = (int)$this->ReadPropertyInteger('LinksLocation');
        if ($linksParent <= 0 || !IPS_ObjectExists($linksParent)) return;

        if ((bool)$this->ReadPropertyBoolean('CleanupLinks')) {
            $this->cleanupOldRootIfNeeded($linksParent);
        }

        $enabled = $this->getEnabledMap();
        $posMap = $this->getOrderPosMap(10);

        $vehicleName = trim($this->ReadAttributeString(self::ATTR_VEHICLE_NAME));
        $rootName = $vehicleName !== '' ? $vehicleName : IPS_GetName($this->InstanceID);

        $rootIdent = self::IDENT_ROOT_PREFIX . $this->InstanceID;
        $rootId = @IPS_GetObjectIDByIdent($rootIdent, $linksParent);
        if ($rootId <= 0) {
            $rootId = IPS_CreateCategory();
            IPS_SetParent($rootId, $linksParent);
            IPS_SetIdent($rootId, $rootIdent);
        }

        if ($forceRename || IPS_GetName($rootId) !== $rootName) {
            IPS_SetName($rootId, $rootName);
        }

        // Reine Domänen-Gruppierung: jede Variable genau einmal.
        // Kategorien werden erst angelegt, wenn sie wirklich einen Link bekommen.
        $purposes = [self::PURPOSE_CHARGING, self::PURPOSE_CLIMATE, self::PURPOSE_SECURITY, self::PURPOSE_OTHER];
        $validPurposeIdents = [];
        foreach ($purposes as $p) {
            $validPurposeIdents[self::IDENT_PURP_PREFIX . $this->makeIdent($p)] = true;
        }

        // Veraltete Purpose-Kategorien aus früheren Versionen entfernen (z.B. Aktionen/Status)
        if ((bool)$this->ReadPropertyBoolean('CleanupLinks')) {
            foreach (IPS_GetChildrenIDs($rootId) as $cid) {
                $obj = IPS_GetObject($cid);
                if (($obj['ObjectType'] ?? 0) !== OBJECTTYPE_CATEGORY) continue;
                $cident = IPS_GetIdent($cid);
                if (strpos($cident, self::IDENT_PURP_PREFIX) !== 0) continue;
                if (isset($validPurposeIdents[$cident])) continue;
                foreach (IPS_GetChildrenIDs($cid) as $sub) {
                    $this->deleteObjectSafe($sub);
                }
                $this->deleteObjectSafe($cid);
            }
        }

        $desired = [];
        $purposeIds = [];

        $createLink = function(string $purpose, string $varIdent) use (&$desired, &$purposeIds, $enabled, $posMap, $rootId) {
            if (isset($enabled[$varIdent]) && !$enabled[$varIdent]) return;
            $varId = @IPS_GetObjectIDByIdent($varIdent, $this->InstanceID);
            if ($varId <= 0) return;
            if (!isset($purposeIds[$purpose])) {
                $purposeIds[$purpose] = $this->ensureCategoryUnder($rootId, $purpose, self::IDENT_PURP_PREFIX . $this->makeIdent($purpose));
            }
            $pid = $purposeIds[$purpose];
            $linkIdent = self::IDENT_LINK_PREFIX . $this->makeIdent($varIdent);
            $pos = $posMap[$varIdent] ?? 999999;
            $this->ensureLinkUnder($pid, $varId, $linkIdent, IPS_GetName($varId), $pos);
            $desired[$pid][] = $linkIdent;
        };

        // Jede sichtbare Variable genau einer Domäne zuordnen (Duplikate überspringen)
        $registry = $this->getTelemetryRegistry();
        foreach ($posMap as $ident => $pos) {
            if ($this->isSupersededTelemetryIdent($ident, $registry)) continue;
            $createLink($this->purposeForIdent($ident, $registry), $ident);
        }

        if ((bool)$this->ReadPropertyBoolean('CleanupLinks')) {
            foreach ($purposes as $p) {
                $pid = @IPS_GetObjectIDByIdent(self::IDENT_PURP_PREFIX . $this->makeIdent($p), $rootId);
                if ($pid <= 0) continue;
                $this->cleanupLinksUnder($pid, $desired[$pid] ?? []);
                // leer gewordene Domänen-Kategorie entfernen
                if (count(IPS_GetChildrenIDs($pid)) === 0) {
                    $this->deleteObjectSafe($pid);
                }
            }
        }

        $this->WriteAttributeInteger(self::ATTR_LAST_LINKS_LOCATION, $linksParent);
    }

    // Domäne einer Variable für die Linkbaum-Gruppierung (genau eine pro Variable)
    private function purposeForIdent(string $ident, array $registry): string
    {
        static $coreMap = null;
        if ($coreMap === null) {
            $coreMap = [
                self::ACT_START_CHARGING        => self::PURPOSE_CHARGING,
                self::ACT_CHARGE_LIMIT          => self::PURPOSE_CHARGING,
                self::ACT_CHARGING_AMPS_REQUEST => self::PURPOSE_CHARGING,
                self::ACT_OPEN_CHARGE_PORT      => self::PURPOSE_CHARGING,
                self::ACT_CLOSE_CHARGE_PORT     => self::PURPOSE_CHARGING,
                self::STAT_CHARGING_AMPS_ACTUAL => self::PURPOSE_CHARGING,
                self::STAT_CHARGING_AMPS_MAX    => self::PURPOSE_CHARGING,
                self::STAT_AC_CHARGING_POWER    => self::PURPOSE_CHARGING,
                self::ACT_CLIMATE               => self::PURPOSE_CLIMATE,
                self::ACT_CLIMATE_KEEPER_MODE   => self::PURPOSE_CLIMATE,
                self::ACT_COP_ENABLED           => self::PURPOSE_CLIMATE,
                self::ACT_COP_FAN_ONLY          => self::PURPOSE_CLIMATE,
                self::ACT_COP_TEMP              => self::PURPOSE_CLIMATE,
                self::ACT_BIO_DEFENSE           => self::PURPOSE_CLIMATE,
                self::ACT_TEMP_DRIVER           => self::PURPOSE_CLIMATE,
                self::ACT_TEMP_PASSENGER        => self::PURPOSE_CLIMATE,
                self::ACT_DEFROST               => self::PURPOSE_CLIMATE,
                self::ACT_STEERING_WHEEL_HEATER => self::PURPOSE_CLIMATE,
                self::ACT_SEAT_HEAT_DRIVER      => self::PURPOSE_CLIMATE,
                self::ACT_SEAT_HEAT_PASSENGER   => self::PURPOSE_CLIMATE,
                self::ACT_LOCKED                => self::PURPOSE_SECURITY,
                self::ACT_SENTRY_MODE           => self::PURPOSE_SECURITY,
                self::ACT_VALET_MODE            => self::PURPOSE_SECURITY,
                self::ACT_FLASH                 => self::PURPOSE_SECURITY,
                self::ACT_HONK                  => self::PURPOSE_SECURITY,
                self::ACT_VENT_WINDOWS          => self::PURPOSE_SECURITY,
                self::ACT_CLOSE_WINDOWS         => self::PURPOSE_SECURITY
            ];
        }
        if (isset($coreMap[$ident])) {
            return $coreMap[$ident];
        }
        if (strpos($ident, 'stat_tel_') === 0) {
            $meta = $registry[$ident] ?? null;
            $key = is_array($meta) ? (string)($meta['key'] ?? $meta['name'] ?? $ident) : $ident;
            return $this->classifyTelemetryKeyToPurpose($key);
        }
        return self::PURPOSE_OTHER;
    }

    // Telemetrie-Keys, die bereits über eigene (Aktions-)Variablen abgebildet sind:
    // ihre auto-entdeckten stat_tel_-Duplikate werden ausgeblendet und nicht verlinkt.
    private function isSupersededTelemetryIdent(string $ident, array $registry): bool
    {
        static $handled = [
            'Locked', 'ChargeLimitSoc', 'ChargeCurrentRequest', 'ChargeAmps',
            'ChargeCurrentRequestMax', 'ACChargingPower', 'VehicleName',
            'ClimateKeeperMode', 'CabinOverheatProtectionTemperatureLimit',
            'ValetModeEnabled', 'SentryMode'
        ];
        $meta = $registry[$ident] ?? null;
        if (!is_array($meta)) return false;
        return in_array((string)($meta['key'] ?? ''), $handled, true);
    }

    private function classifyTelemetryKeyToPurpose(string $key): string
    {
        $k = strtolower($key);
        if (strpos($k, 'charge') !== false || strpos($k, 'charging') !== false || strpos($k, 'battery') !== false || strpos($k, 'soc') !== false || strpos($k, 'energy') !== false || strpos($k, 'range') !== false || strpos($k, 'pack') !== false) {
            return self::PURPOSE_CHARGING;
        }
        if (strpos($k, 'hvac') !== false || strpos($k, 'temp') !== false || strpos($k, 'climate') !== false || strpos($k, 'cabin') !== false || strpos($k, 'seat') !== false || strpos($k, 'defrost') !== false) {
            return self::PURPOSE_CLIMATE;
        }
        if (strpos($k, 'lock') !== false || strpos($k, 'door') !== false || strpos($k, 'window') !== false || strpos($k, 'sentry') !== false || strpos($k, 'pin') !== false || strpos($k, 'valet') !== false || strpos($k, 'alarm') !== false) {
            return self::PURPOSE_SECURITY;
        }
        return self::PURPOSE_OTHER;
    }


    private function deleteObjectSafe(int $objectId): void
    {
        if ($objectId <= 0 || !IPS_ObjectExists($objectId)) {
            return;
        }
        if (function_exists('IPS_DeleteObject')) {
            IPS_DeleteObject($objectId);
            return;
        }
        // Fallback nur falls vorhanden
        if (function_exists('IPS_Delete')) {
            IPS_Delete($objectId);
        }
    }

    private function cleanupOldRootIfNeeded(int $currentLinksParent): void
    {
        $last = (int)$this->ReadAttributeInteger(self::ATTR_LAST_LINKS_LOCATION);
        if ($last <= 0 || $last === $currentLinksParent) return;
        if (!IPS_ObjectExists($last)) return;
        $rootIdent = self::IDENT_ROOT_PREFIX . $this->InstanceID;
        $oldRootId = @IPS_GetObjectIDByIdent($rootIdent, $last);
        if ($oldRootId > 0) {
            $obj = IPS_GetObject($oldRootId);
            if (($obj['ObjectType'] ?? 0) === OBJECTTYPE_CATEGORY) {
                $this->deleteObjectSafe($oldRootId);
            }
        }
    }

    private function cleanupLinksUnder(int $parentId, array $keepIdents): void
    {
        $keep = array_flip($keepIdents);
        foreach (IPS_GetChildrenIDs($parentId) as $cid) {
            $obj = IPS_GetObject($cid);
            if (($obj['ObjectType'] ?? 0) !== OBJECTTYPE_LINK) continue;
            $ident = IPS_GetIdent($cid);
            if (strpos($ident, self::IDENT_LINK_PREFIX) !== 0) continue;
            if (!isset($keep[$ident])) {
                $this->deleteObjectSafe($cid);
            }
        }
    }

    private function ensureCategoryUnder(int $parentId, string $name, string $ident): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentId);
        if ($id <= 0) {
            $id = IPS_CreateCategory();
            IPS_SetParent($id, $parentId);
            IPS_SetIdent($id, $ident);
        }
        if (IPS_GetName($id) !== $name) {
            IPS_SetName($id, $name);
        }
        return $id;
    }

    private function ensureLinkUnder(int $parentId, int $targetId, string $ident, string $name, int $pos): void
    {
        if ($targetId <= 0 || !IPS_ObjectExists($targetId)) return;
        $id = @IPS_GetObjectIDByIdent($ident, $parentId);
        if ($id <= 0) {
            $id = IPS_CreateLink();
            IPS_SetParent($id, $parentId);
            IPS_SetIdent($id, $ident);
        }
        IPS_SetName($id, $name);
        IPS_SetLinkTargetID($id, $targetId);
        IPS_SetPosition($id, $pos);
    }

    private function makeIdent(string $s): string
    {
        $s = preg_replace('/[^a-zA-Z0-9_]/', '_', $s);
        $s = preg_replace('/_+/', '_', (string)$s);
        $s = trim((string)$s, '_');
        if ($s === '') $s = 'X';
        return substr($s, 0, 64);
    }

    private function deleteManagedLinksForIdent(string $varIdent): void
    {
        // minimaler Delete (wie bisher)
        if (!(bool)$this->ReadPropertyBoolean('CreateLinks')) return;
        $linksParent = (int)$this->ReadPropertyInteger('LinksLocation');
        if ($linksParent <= 0 || !IPS_ObjectExists($linksParent)) return;
        $rootIdent = self::IDENT_ROOT_PREFIX . $this->InstanceID;
        $rootId = @IPS_GetObjectIDByIdent($rootIdent, $linksParent);
        if ($rootId <= 0 || !IPS_ObjectExists($rootId)) return;

        $linkIdent = self::IDENT_LINK_PREFIX . $this->makeIdent($varIdent);
        $purposeNames = [self::PURPOSE_CHARGING, self::PURPOSE_CLIMATE, self::PURPOSE_SECURITY, self::PURPOSE_OTHER];
        foreach ($purposeNames as $p) {
            $pid = @IPS_GetObjectIDByIdent(self::IDENT_PURP_PREFIX . $this->makeIdent($p), $rootId);
            if ($pid <= 0) continue;

            $lid = @IPS_GetObjectIDByIdent($linkIdent, $pid);
            if ($lid > 0 && IPS_ObjectExists($lid)) {
                $obj = IPS_GetObject($lid);
                if (($obj['ObjectType'] ?? 0) === OBJECTTYPE_LINK) {
                    $this->deleteObjectSafe($lid);
                }
            }
        }
    }

    private function apiRequest(string $token, string $method, string $path, $body): array
    {
        $base = rtrim(trim($this->ReadPropertyString('ApiBase')), '/');
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $url = $base . $path;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $methodUpper = strtoupper($method);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $methodUpper);

        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
        $hasJsonBody = !($body === null || (is_array($body) && count($body) === 0));
        if ($hasJsonBody) {
            $jsonBody = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            $headers[] = 'Content-Type: application/json';
        } else {
            if ($methodUpper === 'POST') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, '');
                $headers[] = 'Content-Length: 0';
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ((bool)$this->ReadPropertyBoolean('DebugHTTP')) {
            $this->SendDebug('API-URL', $methodUpper . ' ' . $url, 0);
        }

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            $this->SendDebug('API-Anfrage', 'cURL-Fehler: ' . $err, 0);
            return [];
        }

        $json = json_decode($resp, true);
        if (!is_array($json)) {
            $this->SendDebug('API-Anfrage', 'HTTP ' . $code . ' – keine JSON-Antwort: ' . substr($resp, 0, 500), 0);
            return [];
        }
        if ($code >= 400) {
            $this->SendDebug('API-Anfrage', 'HTTP ' . $code . ' ' . $methodUpper . ' ' . $path . ': ' . substr($resp, 0, 500), 0);
            $this->LogMessage('Tessie API HTTP ' . $code . ' bei ' . $methodUpper . ' ' . $path, KL_WARNING);
            return [];
        }
        return $json;
    }
}
