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

    // -------------------- Umrechnung (Einheiten) --------------------
    private const MI_TO_KM   = 1.609344;
    private const MPH_TO_KMH = 1.609344;


    // -------------------- Telemetrie: feste Profil-/Typzuordnung (Hotfix) --------------------
    private const TELEMETRY_PROFILE_MAP = [
        'CabinOverheatProtectionMode' => 'Tessie.CabinOverheatProtectionMode',
        'CabinOverheatProtectionTemperatureLimit' => 'Tessie.CabinOverheatProtectionTempLimit'
    ];

    private const TELEMETRY_TYPE_MAP = [
        'CabinOverheatProtectionMode' => VARIABLETYPE_INTEGER,
        'CabinOverheatProtectionTemperatureLimit' => VARIABLETYPE_INTEGER
    ];

    // -------------------- Variable Idents (Status) --------------------
    private const STAT_CHARGING_AMPS_ACTUAL = 'stat_charge_amps_actual';
    private const STAT_CHARGING_AMPS_MAX    = 'stat_charge_amps_max';
    private const STAT_AC_CHARGING_POWER    = 'stat_ac_charging_power';

    // -------------------- Timer --------------------
    private const TIMER_UPDATE = 'UpdateTimer';

    // -------------------- Kategorien (Links) --------------------
    private const PURPOSE_ACTIONS  = 'Aktionen';
    private const PURPOSE_STATUS   = 'Status';
    private const PURPOSE_CHARGING = 'Laden';
    private const PURPOSE_CLIMATE  = 'Klima';
    private const PURPOSE_SECURITY = 'Sicherheit';

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

    public function ApplyChanges()
    {
        parent::ApplyChanges();

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

        $this->SetStatus(102);
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

        $elements[] = [
            'type' => 'List',
            'name' => 'VisibleVars',
            'caption' => 'Anzuzeigende Variablen',
            'rowCount' => 12,
            'add' => false,
            'delete' => false,
            'changeOrder' => true,
            'loadValuesFromConfiguration' => false,
            'values' => $fullList,
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
                'type' => 'Label',
                'caption' => "Ident/Name sind schreibgeschützt. Du änderst nur 'Anzeigen'. Reihenfolge per Drag & Drop."
            ]
        ];

        return json_encode(['elements' => $elements, 'actions' => $actions]);
    }

    public function ResetVisibleVars()
    {
        IPS_SetProperty($this->InstanceID, self::PROP_VISIBLE_VARS, json_encode($this->getDefaultVisibleVars()));
        IPS_ApplyChanges($this->InstanceID);
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

            [$type, $value] = $this->telemetryInferTypeAndValue($val);

            // Feste Typzuordnung (überschreibt Inferenz)
            if (isset(self::TELEMETRY_TYPE_MAP[$key])) {
                $type = self::TELEMETRY_TYPE_MAP[$key];
            }

            // Enum -> Integer (Innenraum-Überhitzeschutz)
            if ($type === VARIABLETYPE_INTEGER && ($key === 'CabinOverheatProtectionMode' || $key === 'CabinOverheatProtectionTemperatureLimit')) {
                $value = $this->convertOverheatEnumToInt($key, $val, $value);
            }

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

    private function telemetryInferTypeAndValue(array $val): array
    {
        if (array_key_exists('booleanValue', $val)) return [VARIABLETYPE_BOOLEAN, (bool)$val['booleanValue']];
        if (array_key_exists('intValue', $val)) return [VARIABLETYPE_INTEGER, (int)$val['intValue']];
        if (array_key_exists('longValue', $val)) {
            $lv = $val['longValue'];
            if (is_string($lv) && is_numeric($lv)) $lv = (int)$lv;
            return [VARIABLETYPE_INTEGER, (int)$lv];
        }
        if (array_key_exists('doubleValue', $val)) return [VARIABLETYPE_FLOAT, (float)$val['doubleValue']];
        if (array_key_exists('stringValue', $val)) {
            $sv = (string)$val['stringValue'];
            if (is_numeric(trim($sv))) return [VARIABLETYPE_FLOAT, (float)$sv];
            return [VARIABLETYPE_STRING, $sv];
        }
        foreach ($val as $vk => $vv) {
            if (is_string($vk) && substr($vk, -5) === 'Value') {
                if (is_string($vv)) return [VARIABLETYPE_STRING, $vv];
                if (is_bool($vv)) return [VARIABLETYPE_BOOLEAN, $vv];
                if (is_int($vv)) return [VARIABLETYPE_INTEGER, $vv];
                if (is_float($vv)) return [VARIABLETYPE_FLOAT, $vv];
            }
        }
        return [VARIABLETYPE_STRING, json_encode($val)];
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
            if (isset(self::TELEMETRY_PROFILE_MAP[$key])) {
                $profile = self::TELEMETRY_PROFILE_MAP[$key];
            } else {
                $profile = $this->guessProfileForTelemetryKey($key, $type);
            }
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



    private function convertOverheatEnumToInt(string $key, array $val, $fallback): int
    {
        // Modus: 0=Aus, 1=Lüfter, 2=Klima
        if ($key === 'CabinOverheatProtectionMode') {
            $s = (string)($val['cabinOverheatProtectionModeValue'] ?? $fallback ?? '');
            $ls = strtolower($s);
            if (strpos($ls, 'off') !== false) {
                return 0;
            }
            if (strpos($ls, 'fan') !== false) {
                return 1;
            }
            // Default: "On" / unbekannt -> Klima
            return 2;
        }

        // Temperaturlimit: 0=niedrig, 1=hoch
        if ($key === 'CabinOverheatProtectionTemperatureLimit') {
            $s = (string)($val['cabinOverheatProtectionTemperatureLimitValue'] ?? $fallback ?? '');
            $ls = strtolower($s);
            if (strpos($ls, 'low') !== false) {
                return 0;
            }
            if (strpos($ls, 'high') !== false) {
                return 1;
            }
            // Default: hoch
            return 1;
        }

        return (int)$fallback;
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

    }

    
    private function guessProfileForTelemetryKey(string $key, int $type): string
    {
        if (isset(self::TELEMETRY_PROFILE_MAP[$key])) {
            return self::TELEMETRY_PROFILE_MAP[$key];
        }
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
        $this->MaintainVariable(self::ACT_LOCKED, 'Verriegelt', VARIABLETYPE_BOOLEAN, '~Lock', $pos(self::ACT_LOCKED), $keep(self::ACT_LOCKED));
        if ($keep(self::ACT_LOCKED)) $this->EnableAction(self::ACT_LOCKED);

        $this->MaintainVariable(self::ACT_CLIMATE, 'Klima', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_CLIMATE), $keep(self::ACT_CLIMATE));
        if ($keep(self::ACT_CLIMATE)) $this->EnableAction(self::ACT_CLIMATE);

        $this->MaintainVariable(self::ACT_START_CHARGING, 'Laden', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_START_CHARGING), $keep(self::ACT_START_CHARGING));
        if ($keep(self::ACT_START_CHARGING)) $this->EnableAction(self::ACT_START_CHARGING);

        $this->MaintainVariable(self::ACT_CHARGE_LIMIT, 'Ladelimit (%)', VARIABLETYPE_INTEGER, 'Tessie.PercentInt', $pos(self::ACT_CHARGE_LIMIT), $keep(self::ACT_CHARGE_LIMIT));
        if ($keep(self::ACT_CHARGE_LIMIT)) $this->EnableAction(self::ACT_CHARGE_LIMIT);

        $this->MaintainVariable(self::ACT_CHARGING_AMPS_REQUEST, 'Ladestrom Soll (A)', VARIABLETYPE_INTEGER, 'Tessie.Amps', $pos(self::ACT_CHARGING_AMPS_REQUEST), $keep(self::ACT_CHARGING_AMPS_REQUEST));
        if ($keep(self::ACT_CHARGING_AMPS_REQUEST)) $this->EnableAction(self::ACT_CHARGING_AMPS_REQUEST);

        $this->MaintainVariable(self::ACT_FLASH, 'Licht blinken', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_FLASH), $keep(self::ACT_FLASH));
        if ($keep(self::ACT_FLASH)) $this->EnableAction(self::ACT_FLASH);

        $this->MaintainVariable(self::ACT_HONK, 'Hupe', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_HONK), $keep(self::ACT_HONK));
        if ($keep(self::ACT_HONK)) $this->EnableAction(self::ACT_HONK);

        $this->MaintainVariable(self::ACT_SENTRY_MODE, 'Sentry Mode', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_SENTRY_MODE), $keep(self::ACT_SENTRY_MODE));
        if ($keep(self::ACT_SENTRY_MODE)) $this->EnableAction(self::ACT_SENTRY_MODE);

        $this->MaintainVariable(self::ACT_VALET_MODE, 'Valet-Modus', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_VALET_MODE), $keep(self::ACT_VALET_MODE));
        if ($keep(self::ACT_VALET_MODE)) $this->EnableAction(self::ACT_VALET_MODE);

        $this->MaintainVariable(self::ACT_VENT_WINDOWS, 'Fenster lüften', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_VENT_WINDOWS), $keep(self::ACT_VENT_WINDOWS));
        if ($keep(self::ACT_VENT_WINDOWS)) $this->EnableAction(self::ACT_VENT_WINDOWS);

        $this->MaintainVariable(self::ACT_CLOSE_WINDOWS, 'Fenster schließen', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_CLOSE_WINDOWS), $keep(self::ACT_CLOSE_WINDOWS));
        if ($keep(self::ACT_CLOSE_WINDOWS)) $this->EnableAction(self::ACT_CLOSE_WINDOWS);

        $this->MaintainVariable(self::ACT_DEFROST, 'Max Defrost', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_DEFROST), $keep(self::ACT_DEFROST));
        if ($keep(self::ACT_DEFROST)) $this->EnableAction(self::ACT_DEFROST);

        $this->MaintainVariable(self::ACT_STEERING_WHEEL_HEATER, 'Lenkradheizung', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_STEERING_WHEEL_HEATER), $keep(self::ACT_STEERING_WHEEL_HEATER));
        if ($keep(self::ACT_STEERING_WHEEL_HEATER)) $this->EnableAction(self::ACT_STEERING_WHEEL_HEATER);

        $this->MaintainVariable(self::ACT_TEMP_DRIVER, 'Solltemperatur Fahrer (°C)', VARIABLETYPE_FLOAT, 'Tessie.TempSetC', $pos(self::ACT_TEMP_DRIVER), $keep(self::ACT_TEMP_DRIVER));
        if ($keep(self::ACT_TEMP_DRIVER)) $this->EnableAction(self::ACT_TEMP_DRIVER);

        $this->MaintainVariable(self::ACT_TEMP_PASSENGER, 'Solltemperatur Beifahrer (°C)', VARIABLETYPE_FLOAT, 'Tessie.TempSetC', $pos(self::ACT_TEMP_PASSENGER), $keep(self::ACT_TEMP_PASSENGER));
        if ($keep(self::ACT_TEMP_PASSENGER)) $this->EnableAction(self::ACT_TEMP_PASSENGER);

        $this->MaintainVariable(self::ACT_SEAT_HEAT_DRIVER, 'Sitzheizung Fahrer', VARIABLETYPE_INTEGER, 'Tessie.SeatHeatLevel', $pos(self::ACT_SEAT_HEAT_DRIVER), $keep(self::ACT_SEAT_HEAT_DRIVER));
        if ($keep(self::ACT_SEAT_HEAT_DRIVER)) $this->EnableAction(self::ACT_SEAT_HEAT_DRIVER);

        $this->MaintainVariable(self::ACT_SEAT_HEAT_PASSENGER, 'Sitzheizung Beifahrer', VARIABLETYPE_INTEGER, 'Tessie.SeatHeatLevel', $pos(self::ACT_SEAT_HEAT_PASSENGER), $keep(self::ACT_SEAT_HEAT_PASSENGER));
        if ($keep(self::ACT_SEAT_HEAT_PASSENGER)) $this->EnableAction(self::ACT_SEAT_HEAT_PASSENGER);

        $this->MaintainVariable(self::ACT_OPEN_CHARGE_PORT, 'Ladeport öffnen/entriegeln', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_OPEN_CHARGE_PORT), $keep(self::ACT_OPEN_CHARGE_PORT));
        if ($keep(self::ACT_OPEN_CHARGE_PORT)) $this->EnableAction(self::ACT_OPEN_CHARGE_PORT);

        $this->MaintainVariable(self::ACT_CLOSE_CHARGE_PORT, 'Ladeport schließen', VARIABLETYPE_BOOLEAN, '~Switch', $pos(self::ACT_CLOSE_CHARGE_PORT), $keep(self::ACT_CLOSE_CHARGE_PORT));
        if ($keep(self::ACT_CLOSE_CHARGE_PORT)) $this->EnableAction(self::ACT_CLOSE_CHARGE_PORT);


        $this->MaintainVariable(self::STAT_CHARGING_AMPS_ACTUAL, 'Ladestrom Ist (A)', VARIABLETYPE_FLOAT, 'Tessie.AmpsFloat', $pos(self::STAT_CHARGING_AMPS_ACTUAL), $keep(self::STAT_CHARGING_AMPS_ACTUAL));
        $this->MaintainVariable(self::STAT_CHARGING_AMPS_MAX, 'Ladestrom Max (A)', VARIABLETYPE_INTEGER, 'Tessie.Amps', $pos(self::STAT_CHARGING_AMPS_MAX), $keep(self::STAT_CHARGING_AMPS_MAX));
        $this->MaintainVariable(self::STAT_AC_CHARGING_POWER, 'AC Ladeleistung (kW)', VARIABLETYPE_FLOAT, 'Tessie.kW', $pos(self::STAT_AC_CHARGING_POWER), $keep(self::STAT_AC_CHARGING_POWER));

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

            $this->MaintainVariable($ident, $name, $type, $profile, $position, $isEnabled);
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

        $purposes = [self::PURPOSE_ACTIONS, self::PURPOSE_STATUS, self::PURPOSE_CHARGING, self::PURPOSE_CLIMATE, self::PURPOSE_SECURITY];
        $purposeIds = [];
        foreach ($purposes as $p) {
            $pid = $this->ensureCategoryUnder($rootId, $p, self::IDENT_PURP_PREFIX . $this->makeIdent($p));
            $purposeIds[$p] = $pid;
        }

        $desired = [];

        $createLink = function(string $purpose, string $linkIdent, string $varIdent) use (&$desired, $purposeIds, $enabled, $posMap) {
            if (isset($enabled[$varIdent]) && !$enabled[$varIdent]) return;
            $varId = @IPS_GetObjectIDByIdent($varIdent, $this->InstanceID);
            if ($varId <= 0) return;
            $pos = $posMap[$varIdent] ?? 999999;
            $this->ensureLinkUnder($purposeIds[$purpose], $varId, $linkIdent, IPS_GetName($varId), $pos);
            $desired[$purposeIds[$purpose]][] = $linkIdent;
        };

        foreach ($posMap as $ident => $pos) {
            if (strpos($ident, 'act_') === 0) {
                $createLink(self::PURPOSE_ACTIONS, self::IDENT_LINK_PREFIX . 'ACT_' . $ident, $ident);
            }
        }
        foreach ($posMap as $ident => $pos) {
            if (strpos($ident, 'stat_') === 0) {
                $createLink(self::PURPOSE_STATUS, self::IDENT_LINK_PREFIX . 'STAT_' . $ident, $ident);
            }
        }

                $domain = [
            self::PURPOSE_CHARGING => [self::ACT_START_CHARGING, self::ACT_CHARGE_LIMIT, self::ACT_CHARGING_AMPS_REQUEST, self::ACT_OPEN_CHARGE_PORT, self::ACT_CLOSE_CHARGE_PORT, self::STAT_CHARGING_AMPS_ACTUAL, self::STAT_CHARGING_AMPS_MAX, self::STAT_AC_CHARGING_POWER],
            self::PURPOSE_CLIMATE => [self::ACT_CLIMATE, self::ACT_TEMP_DRIVER, self::ACT_TEMP_PASSENGER, self::ACT_DEFROST, self::ACT_STEERING_WHEEL_HEATER, self::ACT_SEAT_HEAT_DRIVER, self::ACT_SEAT_HEAT_PASSENGER],
            self::PURPOSE_SECURITY => [self::ACT_LOCKED, self::ACT_SENTRY_MODE, self::ACT_VALET_MODE, self::ACT_FLASH, self::ACT_HONK, self::ACT_VENT_WINDOWS, self::ACT_CLOSE_WINDOWS]
        ];
        foreach ($posMap as $ident => $pos) {
            foreach ($domain as $purpose => $identsInDomain) {
                if (!in_array($ident, $identsInDomain, true)) continue;
                $createLink($purpose, self::IDENT_LINK_PREFIX . 'DOM_' . $this->makeIdent($purpose) . '_' . $ident, $ident);
            }
        }

        // Telemetrie-Links nach Schlüssel klassifizieren
        $registry = $this->getTelemetryRegistry();
        foreach ($registry as $ident => $meta) {
            if (!is_string($ident) || $ident === '') continue;
            if (strpos($ident, 'stat_tel_') !== 0) continue;
            if (!isset($enabled[$ident]) || !$enabled[$ident]) continue;

            $key = is_array($meta) ? (string)($meta['key'] ?? $meta['name'] ?? $ident) : $ident;
            $purps = $this->classifyTelemetryKeyToPurposes($key);
            foreach ($purps as $purpose) {
                $createLink($purpose, self::IDENT_LINK_PREFIX . 'TELDOM_' . $this->makeIdent($purpose) . '_' . $ident, $ident);
            }
        }

        if ((bool)$this->ReadPropertyBoolean('CleanupLinks')) {
            foreach ($purposeIds as $pid) {
                $keep = $desired[$pid] ?? [];
                $this->cleanupLinksUnder($pid, $keep);
            }
        }

        $this->WriteAttributeInteger(self::ATTR_LAST_LINKS_LOCATION, $linksParent);
    }

    private function classifyTelemetryKeyToPurposes(string $key): array
    {
        $k = strtolower($key);
        $purposes = [];
        if (strpos($k, 'charge') !== false || strpos($k, 'charging') !== false || strpos($k, 'battery') !== false || strpos($k, 'soc') !== false || strpos($k, 'energy') !== false || strpos($k, 'range') !== false || strpos($k, 'pack') !== false) {
            $purposes[] = self::PURPOSE_CHARGING;
        }
        if (strpos($k, 'hvac') !== false || strpos($k, 'temp') !== false || strpos($k, 'climate') !== false || strpos($k, 'cabin') !== false || strpos($k, 'seat') !== false || strpos($k, 'defrost') !== false) {
            $purposes[] = self::PURPOSE_CLIMATE;
        }
        if (strpos($k, 'lock') !== false || strpos($k, 'door') !== false || strpos($k, 'window') !== false || strpos($k, 'sentry') !== false || strpos($k, 'pin') !== false || strpos($k, 'valet') !== false || strpos($k, 'alarm') !== false) {
            $purposes[] = self::PURPOSE_SECURITY;
        }
        return array_values(array_unique($purposes));
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
                IPS_Delete($oldRootId);
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
                IPS_Delete($cid);
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

        $purposeNames = [self::PURPOSE_ACTIONS, self::PURPOSE_STATUS, self::PURPOSE_CHARGING, self::PURPOSE_CLIMATE, self::PURPOSE_SECURITY];
        foreach ($purposeNames as $p) {
            $pid = @IPS_GetObjectIDByIdent(self::IDENT_PURP_PREFIX . $this->makeIdent($p), $rootId);
            if ($pid <= 0) continue;

            $idents = [
                self::IDENT_LINK_PREFIX . 'ACT_' . $varIdent,
                self::IDENT_LINK_PREFIX . 'STAT_' . $varIdent,
                self::IDENT_LINK_PREFIX . 'DOM_' . $this->makeIdent($p) . '_' . $varIdent,
                self::IDENT_LINK_PREFIX . 'TELDOM_' . $this->makeIdent($p) . '_' . $varIdent
            ];

            foreach ($idents as $lidIdent) {
                $lid = @IPS_GetObjectIDByIdent($lidIdent, $pid);
                if ($lid > 0 && IPS_ObjectExists($lid)) {
                    $obj = IPS_GetObject($lid);
                    if (($obj['ObjectType'] ?? 0) === OBJECTTYPE_LINK) {
                        IPS_Delete($lid);
                    }
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
        return $json;
    }
}
