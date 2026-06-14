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
        $this->RegisterPropertyInteger('InstanceLocation', 0);
        $this->RegisterPropertyInteger('LinksLocation', 0);
        $this->RegisterPropertyBoolean('CreateLinks', true);
        $this->RegisterPropertyBoolean('CleanupLinks', true);

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
    }


    private function ensureVisibleVarsMerged(): void
    {
        $base = $this->getVisibleList();
        $merged = $this->mergeTelemetryIntoVisibleVars($base);
        // Nur schreiben, wenn sich etwas ändert (Reihenfolge bleibt erhalten)
        if (json_encode($base) !== json_encode($merged)) {
            IPS_SetProperty($this->InstanceID, self::PROP_VISIBLE_VARS, json_encode($merged));
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->ensureVisibleVarsMerged();

        $interval = (int)$this->ReadPropertyInteger('UpdateInterval');
        if ($interval < 0) {
            $interval = 0;
        }
        $this->SetTimerInterval(self::TIMER_UPDATE, $interval > 0 ? $interval * 1000 : 0);

        // Instanz verschieben (optional)
        $instanceParent = (int)$this->ReadPropertyInteger('InstanceLocation');
        if ($instanceParent > 0 && IPS_ObjectExists($instanceParent)) {
            $currentParent = IPS_GetParent($this->InstanceID);
            if ($currentParent !== $instanceParent) {
                IPS_SetParent($this->InstanceID, $instanceParent);
            }
        }

        $this->ensureProfiles();
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
    public function GetConfigurationForm()
    {
        $elements = [
            [
                'type' => 'NumberSpinner',
                'name' => 'UpdateInterval',
                'caption' => 'Update Intervall (Sekunden)',
                'minimum' => 0,
                'maximum' => 3600
            ],
            [
                'type' => 'SelectCategory',
                'name' => 'InstanceLocation',
                'caption' => 'Ablageort Instanz (optional)'
            ],
            [
                'type' => 'SelectCategory',
                'name' => 'LinksLocation',
                'caption' => 'Ablageort Links (Root Kategorie)'
            ],
            [
                'type' => 'CheckBox',
                'name' => 'CreateLinks',
                'caption' => 'Links anlegen/aktualisieren'
            ],
            [
                'type' => 'CheckBox',
                'name' => 'CleanupLinks',
                'caption' => 'Links automatisch bereinigen'
            ]
        ];

        $baseList = $this->getVisibleList();
        $fullList = $this->mergeTelemetryIntoVisibleVars($baseList);

        // Toggle-Button: Telemetrie global ein-/ausblenden (deaktiviert, wenn keine Telemetrie-Einträge vorhanden sind)
        $hasTelemetry = false;
        $allTelemetryOn = true;
        foreach ($fullList as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ident = (string)($row['Ident'] ?? '');
            if (strpos($ident, 'stat_tel_') !== 0) {
                continue;
            }
            $hasTelemetry = true;
            if (!(bool)($row['Enabled'] ?? false)) {
                $allTelemetryOn = false;
                break;
            }
        }
        if (!$hasTelemetry) {
            $allTelemetryOn = false;
        }
        $toggleCaption = $allTelemetryOn ? 'Telemetrie: alle ausblenden' : 'Telemetrie: alle einblenden';
        $toggleConfirm = $allTelemetryOn ? 'Wirklich alle Telemetrie-Datenpunkte ausblenden?' : '';

        $elements[] = [
            'type' => 'List',
            'name' => 'VisibleVars',
            'caption' => 'Anzuzeigende Variablen',
            'rowCount' => 12,
            'add' => false,
            'delete' => false,
            'changeOrder' => true,
'columns' => [
                ['caption' => 'Ident', 'name' => 'Ident', 'width' => '220px', 'save' => true],
                ['caption' => 'Name',  'name' => 'Name',  'width' => 'auto',  'save' => true],
                [
                    'caption' => 'Anzeigen',
                    'name' => 'Enabled',
                    'width' => '90px',
                    'save' => true,
                    'edit' => ['type' => 'CheckBox']
                ]
            ]
        ];

        $actions = [
            [
                'type' => 'Button',
                'caption' => 'Reset: Standardliste wiederherstellen',
                'confirm' => 'Wirklich die Variablenliste und Reihenfolge auf Standard zurücksetzen?',
                'onClick' => 'TESSIE_ResetVisibleVars(' . $this->InstanceID . ');'
            ],

                [
            'type'    => 'Button',
            'caption' => $toggleCaption,
            'confirm' => $toggleConfirm,
            'enabled' => $hasTelemetry,
            'onClick' => 'TESSIE_ToggleAllTelemetry(' . $this->InstanceID . ');'
        ],
[
            'type'    => 'Button',
            'caption' => 'Telemetrie: nur wichtige einblenden',
            'confirm' => 'Nicht wichtige Telemetrie-Datenpunkte werden ausgeblendet. Fortfahren?',
            'onClick' => 'TESSIE_SetImportantTelemetryEnabled(' . $this->InstanceID . ');'
        ],
        [
            'type'    => 'Button',
            'caption' => 'Telemetrie: Namen aktualisieren',
            'confirm' => 'Telemetrie-Variablennamen im Objektbaum anhand locale.json aktualisieren?',
            'onClick' => 'TESSIE_RenameTelemetryVariables(' . $this->InstanceID . ');'
        ],

            [
                'type' => 'Label',
                'caption' => "Ident/Name sind schreibgeschützt. Du änderst nur 'Anzeigen'. Reihenfolge per Drag & Drop (danach Übernehmen)."
            ]
        ];

        return json_encode(['elements' => $elements, 'actions' => $actions]);
    }

    public function ResetVisibleVars()
    {
        IPS_SetProperty($this->InstanceID, self::PROP_VISIBLE_VARS, json_encode($this->getDefaultVisibleVars()));
        IPS_ApplyChanges($this->InstanceID);
    }

    public function ToggleAllTelemetry(): void
    {
        // Telemetrie-Einträge sind ggf. noch nicht in der gespeicherten Liste enthalten -> vorab mergen
        $list = $this->mergeTelemetryIntoVisibleVars($this->getVisibleList());

        $hasTelemetry = false;
        $allOn = true;

        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ident = (string)($row['Ident'] ?? '');
            if (strpos($ident, 'stat_tel_') !== 0) {
                continue;
            }
            $hasTelemetry = true;
            if (!(bool)($row['Enabled'] ?? false)) {
                $allOn = false;
                break;
            }
        }

        if (!$hasTelemetry) {
            return;
        }

        $this->SetAllTelemetryEnabled(!$allOn);
    }


    public function SetAllTelemetryEnabled(bool $enabled): void
    {
        // Telemetrie-Einträge sind ggf. noch nicht in der gespeicherten Liste enthalten -> vorab mergen
        $list = $this->mergeTelemetryIntoVisibleVars($this->getVisibleList());
        $changed = false;

        foreach ($list as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $ident = (string)($row['Ident'] ?? '');
            if ($ident === '') {
                continue;
            }
            if (strpos($ident, 'stat_tel_') === 0) {
                if (($row['Enabled'] ?? null) !== $enabled) {
                    $row['Enabled'] = $enabled;
                    $changed = true;
                }
            }
        }
        unset($row);

        if ($changed) {
            IPS_SetProperty($this->InstanceID, self::PROP_VISIBLE_VARS, json_encode($list));
            IPS_ApplyChanges($this->InstanceID);
        }
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
        $list = $this->mergeTelemetryIntoVisibleVars($this->getVisibleList());
        $changed = false;

        foreach ($list as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $ident = (string)($row['Ident'] ?? '');
            if ($ident === '' || strpos($ident, 'stat_tel_') !== 0) {
                continue;
            }

            $key = '';
            if (isset($registry[$ident]) && is_array($registry[$ident])) {
                $key = (string)($registry[$ident]['key'] ?? '');
            }

            $enable = ($key !== '' && isset($important[$key]));
            if (($row['Enabled'] ?? null) !== $enable) {
                $row['Enabled'] = $enable;
                $changed = true;
            }
        }
        unset($row);

        if ($changed) {
            IPS_SetProperty($this->InstanceID, self::PROP_VISIBLE_VARS, json_encode($list));
            IPS_ApplyChanges($this->InstanceID);
        }
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
            
            ['Ident' => self::ACT_CLIMATE_KEEPER_MODE, 'Name' => 'Climate Keeper Mode', 'Enabled' => true],
            ['Ident' => self::ACT_COP_ENABLED, 'Name' => 'Innenraum-Überhitzeschutz', 'Enabled' => true],
            ['Ident' => self::ACT_COP_FAN_ONLY, 'Name' => 'Innenraum-Überhitzeschutz: nur Lüfter', 'Enabled' => true],
            ['Ident' => self::ACT_COP_TEMP, 'Name' => 'Innenraum-Überhitzeschutz: Temperaturlimit', 'Enabled' => true],
            ['Ident' => self::ACT_BIO_DEFENSE, 'Name' => 'Bio Defense Mode', 'Enabled' => true],
            ['Ident' => self::ACT_HOMELINK, 'Name' => 'HomeLink auslösen', 'Enabled' => false],
            ['Ident' => self::ACT_FRONT_TRUNK, 'Name' => 'Front-Trunk öffnen', 'Enabled' => false],
            ['Ident' => self::ACT_REAR_TRUNK, 'Name' => 'Rear-Trunk öffnen/schließen', 'Enabled' => false],

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
        foreach ($this->getVisibleList() as $row) {
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
        foreach ($this->getVisibleList() as $row) {
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

    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('Tessie.PercentInt')) {
            IPS_CreateVariableProfile('Tessie.PercentInt', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Tessie.PercentInt', '', ' %');
            IPS_SetVariableProfileValues('Tessie.PercentInt', 0, 100, 1);
            IPS_SetVariableProfileDigits('Tessie.PercentInt', 0);
            IPS_SetVariableProfileIcon('Tessie.PercentInt', 'Intensity');
        }
        if (!IPS_VariableProfileExists('Tessie.Amps')) {
            IPS_CreateVariableProfile('Tessie.Amps', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Tessie.Amps', '', ' A');
            IPS_SetVariableProfileValues('Tessie.Amps', 0, 48, 1);
            IPS_SetVariableProfileDigits('Tessie.Amps', 0);
            IPS_SetVariableProfileIcon('Tessie.Amps', 'Electricity');
        }
        if (!IPS_VariableProfileExists('Tessie.AmpsFloat')) {
            IPS_CreateVariableProfile('Tessie.AmpsFloat', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.AmpsFloat', '', ' A');
            IPS_SetVariableProfileValues('Tessie.AmpsFloat', 0, 48, 0);
            IPS_SetVariableProfileDigits('Tessie.AmpsFloat', 1);
            IPS_SetVariableProfileIcon('Tessie.AmpsFloat', 'Electricity');
        }
        if (!IPS_VariableProfileExists('Tessie.kW')) {
            IPS_CreateVariableProfile('Tessie.kW', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.kW', '', ' kW');
            IPS_SetVariableProfileValues('Tessie.kW', 0, 30, 0);
            IPS_SetVariableProfileDigits('Tessie.kW', 2);
            IPS_SetVariableProfileIcon('Tessie.kW', 'Electricity');
        }

        if (!IPS_VariableProfileExists('Tessie.SpeedKmh')) {
            IPS_CreateVariableProfile('Tessie.SpeedKmh', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.SpeedKmh', '', ' km/h');
            IPS_SetVariableProfileDigits('Tessie.SpeedKmh', 1);
            IPS_SetVariableProfileIcon('Tessie.SpeedKmh', 'Speed');
        }

        if (!IPS_VariableProfileExists('Tessie.DistanceKm')) {
            IPS_CreateVariableProfile('Tessie.DistanceKm', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.DistanceKm', '', ' km');
            IPS_SetVariableProfileDigits('Tessie.DistanceKm', 1);
            IPS_SetVariableProfileIcon('Tessie.DistanceKm', 'Distance');
        }

        if (!IPS_VariableProfileExists('Tessie.TempC')) {
            IPS_CreateVariableProfile('Tessie.TempC', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.TempC', '', ' °C');
            IPS_SetVariableProfileDigits('Tessie.TempC', 1);
            IPS_SetVariableProfileIcon('Tessie.TempC', 'Temperature');
        }

        if (!IPS_VariableProfileExists('Tessie.TempSetC')) {
            IPS_CreateVariableProfile('Tessie.TempSetC', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.TempSetC', '', ' °C');
            IPS_SetVariableProfileValues('Tessie.TempSetC', 15, 28, 0.5);
            IPS_SetVariableProfileDigits('Tessie.TempSetC', 1);
            IPS_SetVariableProfileIcon('Tessie.TempSetC', 'Temperature');
        }

        if (!IPS_VariableProfileExists('Tessie.SeatHeatLevel')) {
            IPS_CreateVariableProfile('Tessie.SeatHeatLevel', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('Tessie.SeatHeatLevel', 0, 3, 1);
            IPS_SetVariableProfileDigits('Tessie.SeatHeatLevel', 0);
            IPS_SetVariableProfileIcon('Tessie.SeatHeatLevel', 'Flame');
            IPS_SetVariableProfileAssociation('Tessie.SeatHeatLevel', 0, 'Aus', '', 0xAAAAAA);
            IPS_SetVariableProfileAssociation('Tessie.SeatHeatLevel', 1, 'Niedrig', '', 0x66CCFF);
            IPS_SetVariableProfileAssociation('Tessie.SeatHeatLevel', 2, 'Mittel', '', 0xFFCC66);
            IPS_SetVariableProfileAssociation('Tessie.SeatHeatLevel', 3, 'Hoch', '', 0xFF6666);
        }

        if (!IPS_VariableProfileExists('Tessie.kWh')) {
            IPS_CreateVariableProfile('Tessie.kWh', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.kWh', '', ' kWh');
            IPS_SetVariableProfileDigits('Tessie.kWh', 2);
            IPS_SetVariableProfileIcon('Tessie.kWh', 'Energy');
        }

        if (!IPS_VariableProfileExists('Tessie.Voltage')) {
            IPS_CreateVariableProfile('Tessie.Voltage', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.Voltage', '', ' V');
            IPS_SetVariableProfileDigits('Tessie.Voltage', 1);
            IPS_SetVariableProfileIcon('Tessie.Voltage', 'Electricity');
        }

        if (!IPS_VariableProfileExists('Tessie.PressureBar')) {
            IPS_CreateVariableProfile('Tessie.PressureBar', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.PressureBar', '', ' bar');
            IPS_SetVariableProfileDigits('Tessie.PressureBar', 2);
            IPS_SetVariableProfileIcon('Tessie.PressureBar', 'Gauge');
        }

        if (!IPS_VariableProfileExists('Tessie.Degrees')) {
            IPS_CreateVariableProfile('Tessie.Degrees', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.Degrees', '', ' °');
            IPS_SetVariableProfileDigits('Tessie.Degrees', 1);
            IPS_SetVariableProfileIcon('Tessie.Degrees', 'Compass');
        }

    
        if (!IPS_VariableProfileExists('Tessie.ClimateKeeperMode')) {
            IPS_CreateVariableProfile('Tessie.ClimateKeeperMode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('Tessie.ClimateKeeperMode', 'Climate');
        }
        // Assoziationen außerhalb der Existenz-Sperre, damit bestehende Profile mit aktualisiert werden
        IPS_SetVariableProfileAssociation('Tessie.ClimateKeeperMode', 0, 'Aus', '', 0xAAAAAA);
        IPS_SetVariableProfileAssociation('Tessie.ClimateKeeperMode', 1, 'Behalten', '', 0x66CCFF);
        IPS_SetVariableProfileAssociation('Tessie.ClimateKeeperMode', 2, 'Hund', '', 0x66FF66);
        IPS_SetVariableProfileAssociation('Tessie.ClimateKeeperMode', 3, 'Camping', '', 0xFFCC66);

        if (!IPS_VariableProfileExists('Tessie.COPTemp')) {
            IPS_CreateVariableProfile('Tessie.COPTemp', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('Tessie.COPTemp', 'Temperature');
        }
        IPS_SetVariableProfileAssociation('Tessie.COPTemp', 1, 'Niedrig', '', 0x66CCFF);
        IPS_SetVariableProfileAssociation('Tessie.COPTemp', 2, 'Mittel', '', 0xFFCC66);
        IPS_SetVariableProfileAssociation('Tessie.COPTemp', 3, 'Hoch', '', 0xFF6666);
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
        $this->MaintainVariable(self::ACT_LOCKED, 'Verriegelt', VARIABLETYPE_BOOLEAN, '~Lock', $pos(self::ACT_LOCKED), true);
        if ($keep(self::ACT_LOCKED)) $this->EnableAction(self::ACT_LOCKED);

        $this->MaintainVariable(self::ACT_CLIMATE, 'Klima', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_CLIMATE), true);
        if ($keep(self::ACT_CLIMATE)) $this->EnableAction(self::ACT_CLIMATE);


        $this->MaintainVariable(self::ACT_CLIMATE_KEEPER_MODE, 'Climate Keeper Mode', VARIABLETYPE_INTEGER, 'Tessie.ClimateKeeperMode', $pos(self::ACT_CLIMATE_KEEPER_MODE), true);
        if ($keep(self::ACT_CLIMATE_KEEPER_MODE)) $this->EnableAction(self::ACT_CLIMATE_KEEPER_MODE);

        $this->MaintainVariable(self::ACT_COP_ENABLED, 'Innenraum-Überhitzeschutz', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_COP_ENABLED), true);
        if ($keep(self::ACT_COP_ENABLED)) $this->EnableAction(self::ACT_COP_ENABLED);

        $this->MaintainVariable(self::ACT_COP_FAN_ONLY, 'Innenraum-Überhitzeschutz: nur Lüfter', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_COP_FAN_ONLY), true);
        if ($keep(self::ACT_COP_FAN_ONLY)) $this->EnableAction(self::ACT_COP_FAN_ONLY);

        $this->MaintainVariable(self::ACT_COP_TEMP, 'Innenraum-Überhitzeschutz: Temperaturlimit', VARIABLETYPE_INTEGER, 'Tessie.COPTemp', $pos(self::ACT_COP_TEMP), true);
        if ($keep(self::ACT_COP_TEMP)) $this->EnableAction(self::ACT_COP_TEMP);

        $this->MaintainVariable(self::ACT_BIO_DEFENSE, 'Bio Defense Mode', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_BIO_DEFENSE), true);
        if ($keep(self::ACT_BIO_DEFENSE)) $this->EnableAction(self::ACT_BIO_DEFENSE);

        $this->MaintainVariable(self::ACT_HOMELINK, 'HomeLink auslösen', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_HOMELINK), true);
        if ($keep(self::ACT_HOMELINK)) $this->EnableAction(self::ACT_HOMELINK);

        $this->MaintainVariable(self::ACT_FRONT_TRUNK, 'Front-Trunk öffnen', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_FRONT_TRUNK), true);
        if ($keep(self::ACT_FRONT_TRUNK)) $this->EnableAction(self::ACT_FRONT_TRUNK);

        $this->MaintainVariable(self::ACT_REAR_TRUNK, 'Rear-Trunk öffnen/schließen', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_REAR_TRUNK), true);
        if ($keep(self::ACT_REAR_TRUNK)) $this->EnableAction(self::ACT_REAR_TRUNK);

        $this->MaintainVariable(self::ACT_START_CHARGING, 'Laden', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_START_CHARGING), true);
        if ($keep(self::ACT_START_CHARGING)) $this->EnableAction(self::ACT_START_CHARGING);

        $this->MaintainVariable(self::ACT_CHARGE_LIMIT, 'Ladelimit (%)', VARIABLETYPE_INTEGER, 'Tessie.PercentInt', $pos(self::ACT_CHARGE_LIMIT), true);
        if ($keep(self::ACT_CHARGE_LIMIT)) $this->EnableAction(self::ACT_CHARGE_LIMIT);

        $this->MaintainVariable(self::ACT_CHARGING_AMPS_REQUEST, 'Ladestrom Soll (A)', VARIABLETYPE_INTEGER, 'Tessie.Amps', $pos(self::ACT_CHARGING_AMPS_REQUEST), true);
        if ($keep(self::ACT_CHARGING_AMPS_REQUEST)) $this->EnableAction(self::ACT_CHARGING_AMPS_REQUEST);

        $this->MaintainVariable(self::ACT_FLASH, 'Licht blinken', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_FLASH), true);
        if ($keep(self::ACT_FLASH)) $this->EnableAction(self::ACT_FLASH);

        $this->MaintainVariable(self::ACT_HONK, 'Hupe', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_HONK), true);
        if ($keep(self::ACT_HONK)) $this->EnableAction(self::ACT_HONK);

        $this->MaintainVariable(self::ACT_SENTRY_MODE, 'Sentry Mode', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_SENTRY_MODE), true);
        if ($keep(self::ACT_SENTRY_MODE)) $this->EnableAction(self::ACT_SENTRY_MODE);

        $this->MaintainVariable(self::ACT_VALET_MODE, 'Valet-Modus', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_VALET_MODE), true);
        if ($keep(self::ACT_VALET_MODE)) $this->EnableAction(self::ACT_VALET_MODE);

        $this->MaintainVariable(self::ACT_VENT_WINDOWS, 'Fenster lüften', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_VENT_WINDOWS), true);
        if ($keep(self::ACT_VENT_WINDOWS)) $this->EnableAction(self::ACT_VENT_WINDOWS);

        $this->MaintainVariable(self::ACT_CLOSE_WINDOWS, 'Fenster schließen', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_CLOSE_WINDOWS), true);
        if ($keep(self::ACT_CLOSE_WINDOWS)) $this->EnableAction(self::ACT_CLOSE_WINDOWS);

        $this->MaintainVariable(self::ACT_DEFROST, 'Max Defrost', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_DEFROST), true);
        if ($keep(self::ACT_DEFROST)) $this->EnableAction(self::ACT_DEFROST);

        $this->MaintainVariable(self::ACT_STEERING_WHEEL_HEATER, 'Lenkradheizung', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_STEERING_WHEEL_HEATER), true);
        if ($keep(self::ACT_STEERING_WHEEL_HEATER)) $this->EnableAction(self::ACT_STEERING_WHEEL_HEATER);

        $this->MaintainVariable(self::ACT_TEMP_DRIVER, 'Solltemperatur Fahrer (°C)', VARIABLETYPE_FLOAT, 'Tessie.TempSetC', $pos(self::ACT_TEMP_DRIVER), true);
        if ($keep(self::ACT_TEMP_DRIVER)) $this->EnableAction(self::ACT_TEMP_DRIVER);

        $this->MaintainVariable(self::ACT_TEMP_PASSENGER, 'Solltemperatur Beifahrer (°C)', VARIABLETYPE_FLOAT, 'Tessie.TempSetC', $pos(self::ACT_TEMP_PASSENGER), true);
        if ($keep(self::ACT_TEMP_PASSENGER)) $this->EnableAction(self::ACT_TEMP_PASSENGER);

        $this->MaintainVariable(self::ACT_SEAT_HEAT_DRIVER, 'Sitzheizung Fahrer', VARIABLETYPE_INTEGER, 'Tessie.SeatHeatLevel', $pos(self::ACT_SEAT_HEAT_DRIVER), true);
        if ($keep(self::ACT_SEAT_HEAT_DRIVER)) $this->EnableAction(self::ACT_SEAT_HEAT_DRIVER);

        $this->MaintainVariable(self::ACT_SEAT_HEAT_PASSENGER, 'Sitzheizung Beifahrer', VARIABLETYPE_INTEGER, 'Tessie.SeatHeatLevel', $pos(self::ACT_SEAT_HEAT_PASSENGER), true);
        if ($keep(self::ACT_SEAT_HEAT_PASSENGER)) $this->EnableAction(self::ACT_SEAT_HEAT_PASSENGER);

        $this->MaintainVariable(self::ACT_OPEN_CHARGE_PORT, 'Ladeport öffnen/entriegeln', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_OPEN_CHARGE_PORT), true);
        if ($keep(self::ACT_OPEN_CHARGE_PORT)) $this->EnableAction(self::ACT_OPEN_CHARGE_PORT);

        $this->MaintainVariable(self::ACT_CLOSE_CHARGE_PORT, 'Ladeport schließen', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_CLOSE_CHARGE_PORT), true);
        if ($keep(self::ACT_CLOSE_CHARGE_PORT)) $this->EnableAction(self::ACT_CLOSE_CHARGE_PORT);


        $this->MaintainVariable(self::STAT_CHARGING_AMPS_ACTUAL, 'Ladestrom Ist (A)', VARIABLETYPE_FLOAT, 'Tessie.AmpsFloat', $pos(self::STAT_CHARGING_AMPS_ACTUAL), true);
        $this->MaintainVariable(self::STAT_CHARGING_AMPS_MAX, 'Ladestrom Max (A)', VARIABLETYPE_INTEGER, 'Tessie.Amps', $pos(self::STAT_CHARGING_AMPS_MAX), true);
        $this->MaintainVariable(self::STAT_AC_CHARGING_POWER, 'AC Ladeleistung (kW)', VARIABLETYPE_FLOAT, 'Tessie.kW', $pos(self::STAT_AC_CHARGING_POWER), true);

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
            $this->MaintainVariable($ident, $name, $type, $profile, $position, true);
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
