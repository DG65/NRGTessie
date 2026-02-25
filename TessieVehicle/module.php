<?php
declare(strict_types=1);

class TessieVehicle extends IPSModule
{
    // -------------------- Variable Idents (Actions) --------------------
    private const ACT_LOCKED                = 'act_locked';
    private const ACT_CLIMATE               = 'act_climate';
    private const ACT_START_CHARGING        = 'act_charging';
    private const ACT_CHARGE_LIMIT          = 'act_charge_limit';
    private const ACT_CHARGING_AMPS_REQUEST = 'act_charging_amps';
    private const ACT_FLASH                 = 'act_flash';
    private const ACT_HONK                  = 'act_honk';

    // -------------------- Variable Idents (Status - existing) --------------------
    private const STAT_CHARGING_AMPS_ACTUAL = 'stat_charge_amps_actual';
    private const STAT_CHARGING_AMPS_MAX    = 'stat_charge_amps_max';
    private const STAT_AC_CHARGING_POWER    = 'stat_ac_charging_power';

    // -------------------- Timer --------------------
    private const TIMER_UPDATE = 'UpdateTimer';

    // -------------------- Purpose categories (Links) --------------------
    private const PURPOSE_ACTIONS   = 'Aktionen';
    private const PURPOSE_STATUS    = 'Status';
    private const PURPOSE_CHARGING  = 'Laden';
    private const PURPOSE_CLIMATE   = 'Klima';
    private const PURPOSE_SECURITY  = 'Sicherheit';

    // -------------------- Attributes --------------------
    private const ATTR_VEHICLE_NAME        = 'VehicleName';
    private const ATTR_LAST_LINKS_LOCATION = 'LastLinksLocation';

    // -------------------- Ident prefixes for managed link tree --------------------
    private const IDENT_ROOT_PREFIX = 'TESSIE_LINKROOT_';
    private const IDENT_PURP_PREFIX = 'PURP_';
    private const IDENT_LINK_PREFIX = 'LNK_';

    // -------------------- Properties --------------------
    private const PROP_VISIBLE_VARS            = 'VisibleVars';
    private const PROP_VISIBLE_TELEMETRY_VARS  = 'TelemetryVisibleVars';

    // -------------------- Telemetry Auto Variables --------------------
    private const TELEMETRY_PARENT_IDENT = 'TelemetryVars';     // category under instance
    private const TELEMETRY_IDENT_PREFIX = 'tel_';              // ident prefix for auto telemetry vars

    private const PROP_AUTO_CREATE_TELEMETRY_VARS   = 'AutoCreateTelemetryVars';
    private const PROP_AUTO_PROFILE_TELEMETRY_VARS  = 'AutoProfileTelemetryVars';
    private const PROP_TELEMETRY_DEBUG_EVERY_KEY    = 'TelemetryDebugEveryKey';

    // Default: neue Telemetrie-Variablen NICHT automatisch verlinken (sonst explodiert der Linkbaum)
    private const PROP_TELEMETRY_DEFAULT_ENABLED    = 'TelemetryDefaultEnabledInLinks';

    public function Create()
    {
        parent::Create();

        // API (vom Configurator gesetzt, im Form ausgeblendet)
        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyString('VIN', '');
        $this->RegisterPropertyString('ApiBase', 'https://api.tessie.com');

        // Kompatibilität: ältere Create-Chains setzen diese Eigenschaft.
        // Intern wird Telemetrie immer verarbeitet, aber die Property muss existieren.
        $this->RegisterPropertyBoolean('TelemetryEnabled', true);

        // Update-Intervall (bleibt)
        $this->RegisterPropertyInteger('UpdateInterval', 300);

        // Debug optional (im Form ausgeblendet)
        $this->RegisterPropertyBoolean('DebugHTTP', false);

        // Links / Ablageorte
        $this->RegisterPropertyInteger('InstanceLocation', 0);
        $this->RegisterPropertyInteger('LinksLocation', 0);
        $this->RegisterPropertyBoolean('CreateLinks', true);
        $this->RegisterPropertyBoolean('CleanupLinks', true);

        // Default VisibleVars (Ident/Name read-only im Form, Enabled + Reihenfolge usergesteuert)
        $this->RegisterPropertyString(self::PROP_VISIBLE_VARS, json_encode($this->getDefaultVisibleVars()));

        // Telemetry Link-Liste (Ident/Name read-only, Enabled+Order steuerbar)
        // Einträge werden automatisch ergänzt, sobald neue Telemetrie-Variablen entstehen.
        $this->RegisterPropertyString(self::PROP_VISIBLE_TELEMETRY_VARS, json_encode([]));

        // Maximal:
        $this->RegisterPropertyBoolean(self::PROP_AUTO_CREATE_TELEMETRY_VARS, true);
        $this->RegisterPropertyBoolean(self::PROP_AUTO_PROFILE_TELEMETRY_VARS, true);
        $this->RegisterPropertyBoolean(self::PROP_TELEMETRY_DEBUG_EVERY_KEY, false);

        // Default Enabled für neue Telemetrie-Einträge in der Link-Liste
        $this->RegisterPropertyBoolean(self::PROP_TELEMETRY_DEFAULT_ENABLED, false);

        // Internal
        $this->RegisterTimer(self::TIMER_UPDATE, 0, 'TESSIE_Update($_IPS["TARGET"]);');
        $this->RegisterAttributeString(self::ATTR_VEHICLE_NAME, '');
        $this->RegisterAttributeInteger(self::ATTR_LAST_LINKS_LOCATION, 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Timer
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
        $this->ensureVariables();
        $this->ensureTelemetryCategory();

        // Links
        try {
            $this->ensureLinkTree();
        } catch (Throwable $e) {
            $this->LogMessage('ensureLinkTree failed: ' . $e->getMessage(), KL_WARNING);
        }

        $this->SetStatus(102);
    }

    // ------------------------------------------------------------
    // Button Action (Form): Reset VisibleVars auf Standard
    // ------------------------------------------------------------
    public function ResetVisibleVars()
    {
        IPS_SetProperty($this->InstanceID, self::PROP_VISIBLE_VARS, json_encode($this->getDefaultVisibleVars()));
        IPS_ApplyChanges($this->InstanceID);
    }

    // ------------------------------------------------------------
    // Button Action (Form): Reset Telemetry VisibleVars (leert Liste)
    // ------------------------------------------------------------
    public function ResetTelemetryVisibleVars()
    {
        IPS_SetProperty($this->InstanceID, self::PROP_VISIBLE_TELEMETRY_VARS, json_encode([]));
        IPS_ApplyChanges($this->InstanceID);
    }

    private function getDefaultVisibleVars(): array
    {
        return [
            ['Ident' => self::ACT_LOCKED,                'Name' => 'Verriegelt',           'Enabled' => true],
            ['Ident' => self::ACT_CLIMATE,               'Name' => 'Klima',                'Enabled' => true],
            ['Ident' => self::ACT_START_CHARGING,        'Name' => 'Laden',                'Enabled' => true],
            ['Ident' => self::ACT_CHARGE_LIMIT,          'Name' => 'Ladelimit (%)',        'Enabled' => true],
            ['Ident' => self::ACT_CHARGING_AMPS_REQUEST, 'Name' => 'Ladestrom Soll (A)',   'Enabled' => true],
            ['Ident' => self::ACT_FLASH,                 'Name' => 'Licht blinken',        'Enabled' => true],
            ['Ident' => self::ACT_HONK,                  'Name' => 'Hupe',                 'Enabled' => true],
            ['Ident' => self::STAT_CHARGING_AMPS_ACTUAL, 'Name' => 'Ladestrom Ist (A)',    'Enabled' => true],
            ['Ident' => self::STAT_CHARGING_AMPS_MAX,    'Name' => 'Ladestrom Max (A)',    'Enabled' => true],
            ['Ident' => self::STAT_AC_CHARGING_POWER,    'Name' => 'AC Ladeleistung (kW)', 'Enabled' => true]
        ];
    }

    // -------- Timer Update --------
    public function Update()
    {
        $token = trim($this->ReadPropertyString('ApiToken'));
        $vin   = trim($this->ReadPropertyString('VIN'));
        if ($token === '' || $vin === '') {
            return;
        }

        // bewusst leicht: nur Status pollen, Telemetrie liefert "Maximal" [3](https://github.com/tessie)[4](https://github.com/DG65/Symcon-Go-e-Modbus)
        $status = $this->getVehicleStatus($vin, $token);
        if ($status !== '') {
            $this->SendDebug('Status', $status, 0);
        }
    }

    // -------- Telemetry ReceiveData --------
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
            $this->SendDebug('Telemetry', 'Non-JSON buffer: ' . substr($buf, 0, 300), 0);
            return;
        }

        // Telemetry ist Key/Value-Stream und kann sehr viele Felder liefern. [3](https://github.com/tessie)[4](https://github.com/DG65/Symcon-Go-e-Modbus)
        $this->SendDebug('Telemetry', $buf, 0);

        if (isset($payload['errors'])) {
            $this->SendDebug('TelemetryErrors', json_encode($payload['errors']), 0);
            return;
        }
        if (isset($payload['status']) && isset($payload['connectionId'])) {
            $this->SendDebug('TelemetryConnection', json_encode($payload), 0);
            return;
        }

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return;
        }

        $this->syncFromTelemetry($payload['data']);
    }

    // -------- RequestAction --------
    public function RequestAction($Ident, $Value)
    {
        $token = trim($this->ReadPropertyString('ApiToken'));
        $vin   = trim($this->ReadPropertyString('VIN'));
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
                if ($percent < 0)   $percent = 0;
                if ($percent > 100) $percent = 100;
                $this->sendCommand($vin, $token, 'set_charge_limit', ['percent' => $percent]);
                $this->safeSetValue(self::ACT_CHARGE_LIMIT, $percent);
                break;

            case self::ACT_CHARGING_AMPS_REQUEST:
                $amps = (int)$Value;
                if ($amps < 0)  $amps = 0;
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

            default:
                throw new Exception('Unbekannte Aktion: ' . (string)$Ident);
        }
    }

    // -------- Commands --------
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
        $this->SendDebug('Command', ($ok ? 'OK: ' : 'Failed: ') . $command . ($ok ? '' : (' ' . json_encode($resp))), 0);
    }

    private function ensureAwake(string $vin, string $token): void
    {
        $status = $this->getVehicleStatus($vin, $token);
        if ($status === 'awake') {
            return;
        }
        $path = '/' . rawurlencode($vin) . '/wake';
        $resp = $this->apiRequest($token, 'POST', $path, null);
        $this->SendDebug('Wake', 'result=' . json_encode($resp), 0);
    }

    private function getVehicleStatus(string $vin, string $token): string
    {
        $path = '/' . rawurlencode($vin) . '/status';
        $resp = $this->apiRequest($token, 'GET', $path, null);
        $st = $resp['status'] ?? '';
        return is_string($st) ? $st : '';
    }

    // --------------------------------------------------------------------
    // Telemetry -> Variables (MAXIMAL)
    // - Mappt bekannte Keys weiterhin auf bestehende Variablen
    // - Legt für ALLE anderen Keys automatisch Variablen an (tel_<Key>)
    // - führt TelemetryVisibleVars-Liste (für Links)
    // --------------------------------------------------------------------
    private function syncFromTelemetry(array $dataItems): void
    {
        // "Bekannte" für Actions/Status
        $locked = null;
        $limit  = null;
        $req    = null;
        $act    = null;
        $max    = null;
        $acp    = null;
        $vehicleName = null;

        $debugEveryKey = (bool)$this->ReadPropertyBoolean(self::PROP_TELEMETRY_DEBUG_EVERY_KEY);
        $autoCreate    = (bool)$this->ReadPropertyBoolean(self::PROP_AUTO_CREATE_TELEMETRY_VARS);

        $teleListChanged = false;

        foreach ($dataItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = (string)($item['key'] ?? '');
            $val = $item['value'] ?? null;

            if ($key === '' || !is_array($val)) {
                continue;
            }

            if ($debugEveryKey) {
                $this->SendDebug('TelemetryKey', $key . ' => ' . json_encode($val), 0);
            }

            // ---- Explizites Mapping für bestehende "Core" Variablen ----
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
                if ($s !== null) $vehicleName = $s;
                continue;
            }

            // ---- MAXIMAL: alles andere als Telemetrievariable ablegen ----
            if ($autoCreate) {
                // Upsert Variable(n), liefert ident=>[Name,Key]
                $identsCreated = $this->telemetryUpsertAutoVar($key, $val);

                // füge neue Telemetrie-Idents in Link-Liste ein (Enabled default: false)
                foreach ($identsCreated as $ident => $meta) {
                    $nameForList = (string)($meta['Name'] ?? $ident);
                    $keyForList  = (string)($meta['Key'] ?? $key);

                    if ($this->telemetryVisibleListEnsureEntry($ident, $nameForList, $keyForList)) {
                        $teleListChanged = true;
                    }
                }
            }
        }

        // Persistiere Link-Liste NUR wenn sich etwas geändert hat
        if ($teleListChanged) {
            IPS_SetProperty($this->InstanceID, self::PROP_VISIBLE_TELEMETRY_VARS, json_encode($this->getTelemetryVisibleList()));
            // kein ApplyChanges hier. Links aktualisieren wir direkt:
            try {
                $this->ensureLinkTree();
            } catch (Throwable $e) {
                $this->LogMessage('ensureLinkTree after telemetry list update failed: ' . $e->getMessage(), KL_WARNING);
            }
        }

        // Setze die bestehenden Kern-Variablen
        if ($locked !== null) $this->safeSetValue(self::ACT_LOCKED, $locked);
        if ($limit  !== null) $this->safeSetValue(self::ACT_CHARGE_LIMIT, $limit);
        if ($req    !== null) $this->safeSetValue(self::ACT_CHARGING_AMPS_REQUEST, $req);
        if ($act    !== null) $this->safeSetValue(self::STAT_CHARGING_AMPS_ACTUAL, $act);
        if ($max    !== null) $this->safeSetValue(self::STAT_CHARGING_AMPS_MAX, $max);
        if ($acp    !== null) $this->safeSetValue(self::STAT_AC_CHARGING_POWER, $acp);

        // VehicleName als Attribut (für Link-Tree Root)
        if ($vehicleName !== null && trim($vehicleName) !== '') {
            $old = $this->ReadAttributeString(self::ATTR_VEHICLE_NAME);
            if ($old !== $vehicleName) {
                $this->WriteAttributeString(self::ATTR_VEHICLE_NAME, $vehicleName);
                $this->ensureLinkTree(true);
            }
        }
    }

    // ---------------- Telemetry value helpers ----------------
    private function telemetryGetNumber(array $val): ?float
    {
        if (array_key_exists('doubleValue', $val)) return (float)$val['doubleValue'];
        if (array_key_exists('intValue', $val))    return (float)$val['intValue'];
        if (array_key_exists('stringValue', $val)) {
            $s = trim((string)$val['stringValue']);
            if ($s === '') return null;
            if (is_numeric($s)) return (float)$s;
        }
        return null;
    }

    private function telemetryGetBool(array $val): ?bool
    {
        if (array_key_exists('booleanValue', $val)) return (bool)$val['booleanValue'];
        if (array_key_exists('stringValue', $val)) {
            $s = strtolower(trim((string)$val['stringValue']));
            if ($s === 'true' || $s === '1') return true;
            if ($s === 'false' || $s === '0') return false;
        }
        return null;
    }

    private function telemetryGetString(array $val): ?string
    {
        if (array_key_exists('stringValue', $val)) return (string)$val['stringValue'];
        if (array_key_exists('doubleValue', $val)) return (string)$val['doubleValue'];
        if (array_key_exists('intValue', $val))    return (string)$val['intValue'];
        if (array_key_exists('booleanValue', $val))return ((bool)$val['booleanValue'] ? 'true' : 'false');
        return null;
    }

    // ---------------- Auto telemetry variable management ----------------
    private function ensureTelemetryCategory(): void
    {
        $this->getTelemetryCategoryId();
    }

    private function getTelemetryCategoryId(): int
    {
        $id = @IPS_GetObjectIDByIdent(self::TELEMETRY_PARENT_IDENT, $this->InstanceID);
        if ($id > 0 && IPS_ObjectExists($id)) {
            return $id;
        }
        $id = IPS_CreateCategory();
        IPS_SetParent($id, $this->InstanceID);
        IPS_SetIdent($id, self::TELEMETRY_PARENT_IDENT);
        IPS_SetName($id, 'Telemetrie');
        IPS_SetPosition($id, 1000);
        return $id;
    }

    /**
     * Creates/updates telemetry vars.
     * Returns ident => ['Name'=>..., 'Key'=>...] entries for link-list sync.
     * Telemetry payload is data[{key,value}], and value can be stringValue/locationValue/... [3](https://github.com/tessie)[4](https://github.com/DG65/Symcon-Go-e-Modbus)
     */
    private function telemetryUpsertAutoVar(string $key, array $val): array
    {
        $parentCat = $this->getTelemetryCategoryId();
        $autoProfile = (bool)$this->ReadPropertyBoolean(self::PROP_AUTO_PROFILE_TELEMETRY_VARS);

        $created = [];

        // Location: zwei Variablen (lat/lon)
        if (array_key_exists('locationValue', $val) && is_array($val['locationValue'])) {
            $loc = $val['locationValue'];
            $lat = isset($loc['latitude']) ? (float)$loc['latitude'] : null;
            $lon = isset($loc['longitude']) ? (float)$loc['longitude'] : null;

            $identBase = self::TELEMETRY_IDENT_PREFIX . $this->makeIdent($key);
            $identLat  = $identBase . '_lat';
            $identLon  = $identBase . '_lon';

            $this->telemetryEnsureVar($parentCat, $identLat, $key . ' Latitude', VARIABLETYPE_FLOAT, '', 0);
            $this->telemetryEnsureVar($parentCat, $identLon, $key . ' Longitude', VARIABLETYPE_FLOAT, '', 0);

            if ($lat !== null) $this->telemetrySafeSet($identLat, $lat, $parentCat);
            if ($lon !== null) $this->telemetrySafeSet($identLon, $lon, $parentCat);

            $created[$identLat] = ['Name' => $key . ' Latitude',  'Key' => $key];
            $created[$identLon] = ['Name' => $key . ' Longitude', 'Key' => $key];
            return $created;
        }

        // Bestimme Typ & Wert
        $value = null;
        $type  = VARIABLETYPE_STRING;

        if (array_key_exists('booleanValue', $val)) {
            $type  = VARIABLETYPE_BOOLEAN;
            $value = (bool)$val['booleanValue'];
        } elseif (array_key_exists('intValue', $val)) {
            $type  = VARIABLETYPE_INTEGER;
            $value = (int)$val['intValue'];
        } elseif (array_key_exists('doubleValue', $val)) {
            $type  = VARIABLETYPE_FLOAT;
            $value = (float)$val['doubleValue'];
        } elseif (array_key_exists('stringValue', $val)) {
            $sv = (string)$val['stringValue'];
            if (is_numeric(trim($sv))) {
                $type  = VARIABLETYPE_FLOAT;
                $value = (float)$sv;
            } else {
                $type  = VARIABLETYPE_STRING;
                $value = $sv;
            }
        } else {
            $type  = VARIABLETYPE_STRING;
            $value = json_encode($val);
        }

        $ident = self::TELEMETRY_IDENT_PREFIX . $this->makeIdent($key);
        $name  = $key;

        $profile = '';
        if ($autoProfile) {
            $profile = $this->guessProfileForTelemetryKey($key, $type);
        }

        $this->telemetryEnsureVar($parentCat, $ident, $name, $type, $profile, 0);
        $this->telemetrySafeSet($ident, $value, $parentCat);

        $created[$ident] = ['Name' => $name, 'Key' => $key];
        return $created;
    }

    private function telemetryEnsureVar(int $parentCat, string $ident, string $name, int $type, string $profile, int $pos): void
    {
        $varId = @IPS_GetObjectIDByIdent($ident, $parentCat);
        if ($varId > 0 && IPS_ObjectExists($varId)) {
            if (IPS_GetName($varId) !== $name) {
                IPS_SetName($varId, $name);
            }
            $v = IPS_GetVariable($varId);
            $curType = (int)($v['VariableType'] ?? -1);

            if ($curType !== $type) {
                IPS_Delete($varId);
                $varId = 0;
            } else {
                if ($profile !== '') {
                    @IPS_SetVariableCustomProfile($varId, $profile);
                }
                if ($pos > 0) {
                    IPS_SetPosition($varId, $pos);
                }
                return;
            }
        }

        $varId = IPS_CreateVariable($type);
        IPS_SetParent($varId, $parentCat);
        IPS_SetIdent($varId, $ident);
        IPS_SetName($varId, $name);
        if ($pos > 0) {
            IPS_SetPosition($varId, $pos);
        }
        if ($profile !== '') {
            @IPS_SetVariableCustomProfile($varId, $profile);
        }
    }

    private function telemetrySafeSet(string $ident, $value, int $parentCat): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentCat);
        if ($id <= 0) return;

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

    // ---------------- Telemetry Visible List (Links) ----------------
    private function getTelemetryVisibleList(): array
    {
        $arr = json_decode($this->ReadPropertyString(self::PROP_VISIBLE_TELEMETRY_VARS), true);
        return is_array($arr) ? $arr : [];
    }

    /**
     * Ensure telemetry list has entry for ident.
     * Stores extra fields (Key) even if form doesn't display them. [2](https://adsoba-my.sharepoint.com/personal/d_gureth_adsoba_de/Documents/Microsoft%20Copilot%20Chat-Dateien/form.json)
     */
    private function telemetryVisibleListEnsureEntry(string $ident, string $name, string $key): bool
    {
        $list = $this->getTelemetryVisibleList();

        foreach ($list as $idx => $row) {
            if (is_array($row) && (string)($row['Ident'] ?? '') === $ident) {
                $changed = false;
                if ((string)($row['Name'] ?? '') !== $name) {
                    $list[$idx]['Name'] = $name;
                    $changed = true;
                }
                if ((string)($row['Key'] ?? '') !== $key) {
                    $list[$idx]['Key'] = $key;
                    $changed = true;
                }
                if ($changed) {
                    IPS_SetProperty($this->InstanceID, self::PROP_VISIBLE_TELEMETRY_VARS, json_encode($list));
                }
                return $changed;
            }
        }

        $defaultEnabled = (bool)$this->ReadPropertyBoolean(self::PROP_TELEMETRY_DEFAULT_ENABLED);
        $list[] = ['Ident' => $ident, 'Name' => $name, 'Key' => $key, 'Enabled' => $defaultEnabled];

        IPS_SetProperty($this->InstanceID, self::PROP_VISIBLE_TELEMETRY_VARS, json_encode($list));
        return true;
    }

    private function getTelemetryEnabledMap(): array
    {
        $map = [];
        foreach ($this->getTelemetryVisibleList() as $row) {
            if (!is_array($row)) continue;
            $ident = (string)($row['Ident'] ?? '');
            if ($ident === '') continue;
            $map[$ident] = (bool)($row['Enabled'] ?? false);
        }
        return $map;
    }

    private function getTelemetryOrderPosMap(int $step = 10): array
    {
        $posMap = [];
        $pos = $step;
        foreach ($this->getTelemetryVisibleList() as $row) {
            if (!is_array($row)) continue;
            $ident = (string)($row['Ident'] ?? '');
            if ($ident === '') continue;
            $posMap[$ident] = $pos;
            $pos += $step;
        }
        return $posMap;
    }

    private function getTelemetryKeyMap(): array
    {
        $map = [];
        foreach ($this->getTelemetryVisibleList() as $row) {
            if (!is_array($row)) continue;
            $ident = (string)($row['Ident'] ?? '');
            if ($ident === '') continue;
            $map[$ident] = (string)($row['Key'] ?? $row['Name'] ?? $ident);
        }
        return $map;
    }

    // -------- Existing VisibleVars helpers --------
    private function getVisibleList(): array
    {
        $arr = json_decode($this->ReadPropertyString(self::PROP_VISIBLE_VARS), true);
        return is_array($arr) ? $arr : [];
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

    // -------- Komfort: Links sofort löschen, wenn Variable deaktiviert wird --------
    private function deleteManagedLinksForIdent(string $varIdent): void
    {
        if (!(bool)$this->ReadPropertyBoolean('CreateLinks')) return;

        $linksParent = (int)$this->ReadPropertyInteger('LinksLocation');
        if ($linksParent <= 0 || !IPS_ObjectExists($linksParent)) return;

        $rootIdent = self::IDENT_ROOT_PREFIX . $this->InstanceID;
        $rootId = @IPS_GetObjectIDByIdent($rootIdent, $linksParent);
        if ($rootId <= 0 || !IPS_ObjectExists($rootId)) return;

        $purposeNames = [
            self::PURPOSE_ACTIONS,
            self::PURPOSE_STATUS,
            self::PURPOSE_CHARGING,
            self::PURPOSE_CLIMATE,
            self::PURPOSE_SECURITY
        ];

        $purposeIds = [];
        foreach ($purposeNames as $p) {
            $pid = @IPS_GetObjectIDByIdent(self::IDENT_PURP_PREFIX . $this->makeIdent($p), $rootId);
            if ($pid > 0 && IPS_ObjectExists($pid)) {
                $purposeIds[$p] = $pid;
            }
        }

        $toTry = [];
        // Core
        $toTry[self::PURPOSE_ACTIONS][]  = self::IDENT_LINK_PREFIX . 'ACT_' . $varIdent;
        $toTry[self::PURPOSE_STATUS][]   = self::IDENT_LINK_PREFIX . 'STAT_' . $varIdent;
        $toTry[self::PURPOSE_CHARGING][] = self::IDENT_LINK_PREFIX . 'DOM_' . $this->makeIdent(self::PURPOSE_CHARGING) . '_' . $varIdent;
        $toTry[self::PURPOSE_CLIMATE][]  = self::IDENT_LINK_PREFIX . 'DOM_' . $this->makeIdent(self::PURPOSE_CLIMATE)  . '_' . $varIdent;
        $toTry[self::PURPOSE_SECURITY][] = self::IDENT_LINK_PREFIX . 'DOM_' . $this->makeIdent(self::PURPOSE_SECURITY) . '_' . $varIdent;

        // Telemetrie (Variante A): nur kategorisierte Links – kein Telemetrie-Sammelordner
        $toTry[self::PURPOSE_STATUS][]   = self::IDENT_LINK_PREFIX . 'TELSTAT_' . $varIdent;
        $toTry[self::PURPOSE_CHARGING][] = self::IDENT_LINK_PREFIX . 'TELDOM_' . $this->makeIdent(self::PURPOSE_CHARGING) . '_' . $varIdent;
        $toTry[self::PURPOSE_CLIMATE][]  = self::IDENT_LINK_PREFIX . 'TELDOM_' . $this->makeIdent(self::PURPOSE_CLIMATE)  . '_' . $varIdent;
        $toTry[self::PURPOSE_SECURITY][] = self::IDENT_LINK_PREFIX . 'TELDOM_' . $this->makeIdent(self::PURPOSE_SECURITY) . '_' . $varIdent;

        foreach ($toTry as $purpose => $idents) {
            if (!isset($purposeIds[$purpose])) continue;
            $pid = $purposeIds[$purpose];
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

    // -------- Variables & profiles (MaintainVariable wie bisher) --------
    private function ensureVariables(): void
    {
        $enabled = $this->getEnabledMap();
        $posMap  = $this->getOrderPosMap(10);

        $keep = fn(string $ident) => ($enabled[$ident] ?? true);
        $pos  = fn(string $ident) => ($posMap[$ident] ?? 0);

        // Komfort: erst Links löschen für alles, was deaktiviert wird
        foreach (array_keys($posMap) as $ident) {
            if (!$keep($ident)) {
                $this->deleteManagedLinksForIdent($ident);
            }
        }

        // Actions
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

        // Status
        $this->MaintainVariable(self::STAT_CHARGING_AMPS_ACTUAL, 'Ladestrom Ist (A)', VARIABLETYPE_FLOAT, 'Tessie.AmpsFloat', $pos(self::STAT_CHARGING_AMPS_ACTUAL), $keep(self::STAT_CHARGING_AMPS_ACTUAL));
        $this->MaintainVariable(self::STAT_CHARGING_AMPS_MAX, 'Ladestrom Max (A)', VARIABLETYPE_INTEGER, 'Tessie.Amps', $pos(self::STAT_CHARGING_AMPS_MAX), $keep(self::STAT_CHARGING_AMPS_MAX));
        $this->MaintainVariable(self::STAT_AC_CHARGING_POWER, 'AC Ladeleistung (kW)', VARIABLETYPE_FLOAT, 'Tessie.kW', $pos(self::STAT_AC_CHARGING_POWER), $keep(self::STAT_AC_CHARGING_POWER));
    }

    private function ensureProfiles(): void
    {
        // Bestehende Profile
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

        // Auto-Profil Set (Heuristik; Fleet Telemetry hat sehr viele Felder) [4](https://github.com/DG65/Symcon-Go-e-Modbus)[3](https://github.com/tessie)
        if (!IPS_VariableProfileExists('Tessie.PercentFloat')) {
            IPS_CreateVariableProfile('Tessie.PercentFloat', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.PercentFloat', '', ' %');
            IPS_SetVariableProfileValues('Tessie.PercentFloat', 0, 100, 0);
            IPS_SetVariableProfileDigits('Tessie.PercentFloat', 1);
            IPS_SetVariableProfileIcon('Tessie.PercentFloat', 'Intensity');
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
        if (!IPS_VariableProfileExists('Tessie.TempC')) {
            IPS_CreateVariableProfile('Tessie.TempC', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.TempC', '', ' °C');
            IPS_SetVariableProfileDigits('Tessie.TempC', 1);
            IPS_SetVariableProfileIcon('Tessie.TempC', 'Temperature');
        }
        if (!IPS_VariableProfileExists('Tessie.Miles')) {
            IPS_CreateVariableProfile('Tessie.Miles', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.Miles', '', ' mi');
            IPS_SetVariableProfileDigits('Tessie.Miles', 2);
            IPS_SetVariableProfileIcon('Tessie.Miles', 'Distance');
        }
        if (!IPS_VariableProfileExists('Tessie.Mph')) {
            IPS_CreateVariableProfile('Tessie.Mph', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.Mph', '', ' mph');
            IPS_SetVariableProfileDigits('Tessie.Mph', 1);
            IPS_SetVariableProfileIcon('Tessie.Mph', 'Speed');
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
        $k = strtolower($key);

        if ($type !== VARIABLETYPE_STRING && (strpos($k, 'soc') !== false || (strpos($k, 'battery') !== false && strpos($k, 'level') !== false) || strpos($k, 'percent') !== false)) {
            return ($type === VARIABLETYPE_INTEGER) ? 'Tessie.PercentInt' : 'Tessie.PercentFloat';
        }
        if ($type !== VARIABLETYPE_STRING && (strpos($k, 'temp') !== false || strpos($k, 'inside') !== false || strpos($k, 'outside') !== false)) {
            return 'Tessie.TempC';
        }
        if ($type !== VARIABLETYPE_STRING && (strpos($k, 'power') !== false || strpos($k, 'kw') !== false)) {
            return 'Tessie.kW';
        }
        if ($type !== VARIABLETYPE_STRING && (strpos($k, 'energy') !== false || strpos($k, 'kwh') !== false)) {
            return 'Tessie.kWh';
        }
        if ($type !== VARIABLETYPE_STRING && (strpos($k, 'volt') !== false || strpos($k, 'voltage') !== false)) {
            return 'Tessie.Voltage';
        }
        if ($type !== VARIABLETYPE_STRING && (strpos($k, 'current') !== false || strpos($k, 'amps') !== false || strpos($k, 'amp') !== false)) {
            return ($type === VARIABLETYPE_INTEGER) ? 'Tessie.Amps' : 'Tessie.AmpsFloat';
        }
        if ($type !== VARIABLETYPE_STRING && strpos($k, 'heading') !== false) {
            return 'Tessie.Degrees';
        }
        if ($type !== VARIABLETYPE_STRING && (strpos($k, 'speed') !== false || strpos($k, 'mph') !== false)) {
            return 'Tessie.Mph';
        }
        if ($type !== VARIABLETYPE_STRING && (strpos($k, 'odometer') !== false || strpos($k, 'range') !== false || strpos($k, 'miles') !== false)) {
            return 'Tessie.Miles';
        }
        return '';
    }

    private function safeSetValue(string $ident, $value): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id <= 0) return;

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

    // -------- Link tree (Core + Telemetrie nur kategorisiert, Variante A) --------
    private function ensureLinkTree(bool $forceRename = false): void
    {
        if (!(bool)$this->ReadPropertyBoolean('CreateLinks')) return;

        $linksParent = (int)$this->ReadPropertyInteger('LinksLocation');
        if ($linksParent <= 0 || !IPS_ObjectExists($linksParent)) return;

        if ((bool)$this->ReadPropertyBoolean('CleanupLinks')) {
            $this->cleanupOldRootIfNeeded($linksParent);
        }

        // Core-Liste (wie bisher) [2](https://adsoba-my.sharepoint.com/personal/d_gureth_adsoba_de/Documents/Microsoft%20Copilot%20Chat-Dateien/form.json)[1](https://adsoba-my.sharepoint.com/personal/d_gureth_adsoba_de/Documents/Microsoft%20Copilot%20Chat-Dateien/module.php)
        $enabledCore = $this->getEnabledMap();
        $posMapCore  = $this->getOrderPosMap(10);

        // Telemetrie-Liste (gleiches Prinzip)
        $enabledTel = $this->getTelemetryEnabledMap();
        $posMapTel  = $this->getTelemetryOrderPosMap(10);
        $keyMapTel  = $this->getTelemetryKeyMap();

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

        // Purpose-Kategorien (OHNE Telemetrie-Sammelordner)
        $purposes = [
            self::PURPOSE_ACTIONS,
            self::PURPOSE_STATUS,
            self::PURPOSE_CHARGING,
            self::PURPOSE_CLIMATE,
            self::PURPOSE_SECURITY
        ];

        $purposeIds = [];
        foreach ($purposes as $p) {
            $pid = $this->ensureCategoryUnder($rootId, $p, self::IDENT_PURP_PREFIX . $this->makeIdent($p));
            $purposeIds[$p] = $pid;
        }

        $desired = [];

        // ---------- Core Links (wie bisher) ----------
        $createCoreLink = function (string $purpose, string $linkIdent, string $varIdent) use (&$desired, $purposeIds, $enabledCore, $posMapCore) {
            if (isset($enabledCore[$varIdent]) && !$enabledCore[$varIdent]) return;
            $varId = @IPS_GetObjectIDByIdent($varIdent, $this->InstanceID);
            if ($varId <= 0) return;

            $pos = $posMapCore[$varIdent] ?? 999999;
            $this->ensureLinkUnder($purposeIds[$purpose], $varId, $linkIdent, IPS_GetName($varId), $pos);
            $desired[$purposeIds[$purpose]][] = $linkIdent;
        };

        foreach ($posMapCore as $ident => $pos) {
            if (strpos($ident, 'act_') === 0) {
                $createCoreLink(self::PURPOSE_ACTIONS, self::IDENT_LINK_PREFIX . 'ACT_' . $ident, $ident);
            }
        }

        foreach ($posMapCore as $ident => $pos) {
            if (strpos($ident, 'stat_') === 0) {
                $createCoreLink(self::PURPOSE_STATUS, self::IDENT_LINK_PREFIX . 'STAT_' . $ident, $ident);
            }
        }

        $domain = [
            self::PURPOSE_CHARGING => [
                self::ACT_START_CHARGING,
                self::ACT_CHARGE_LIMIT,
                self::ACT_CHARGING_AMPS_REQUEST,
                self::STAT_CHARGING_AMPS_ACTUAL,
                self::STAT_CHARGING_AMPS_MAX,
                self::STAT_AC_CHARGING_POWER
            ],
            self::PURPOSE_CLIMATE => [ self::ACT_CLIMATE ],
            self::PURPOSE_SECURITY => [ self::ACT_LOCKED, self::ACT_FLASH, self::ACT_HONK ]
        ];

        foreach ($posMapCore as $ident => $pos) {
            foreach ($domain as $purpose => $identsInDomain) {
                if (!in_array($ident, $identsInDomain, true)) continue;
                $createCoreLink($purpose, self::IDENT_LINK_PREFIX . 'DOM_' . $this->makeIdent($purpose) . '_' . $ident, $ident);
            }
        }

        // ---------- Telemetry Links (nur kategorisiert: Status / Laden / Klima / Sicherheit) ----------
        $telemetryCatId = $this->getTelemetryCategoryId();

        $createTelLink = function (string $purpose, string $linkIdent, int $varId, string $varName, int $pos) use (&$desired, $purposeIds) {
            $this->ensureLinkUnder($purposeIds[$purpose], $varId, $linkIdent, $varName, $pos);
            $desired[$purposeIds[$purpose]][] = $linkIdent;
        };

        foreach ($posMapTel as $telIdent => $pos) {
            if (!($enabledTel[$telIdent] ?? false)) {
                $this->deleteManagedLinksForIdent($telIdent);
                continue;
            }

            $varId = @IPS_GetObjectIDByIdent($telIdent, $telemetryCatId);
            if ($varId <= 0) continue;

            $varName = IPS_GetName($varId);
            $key = (string)($keyMapTel[$telIdent] ?? $varName);

            $purps = $this->classifyTelemetryKeyToPurposes($key);

            if (in_array(self::PURPOSE_STATUS, $purps, true)) {
                $createTelLink(self::PURPOSE_STATUS, self::IDENT_LINK_PREFIX . 'TELSTAT_' . $telIdent, $varId, $varName, $pos);
            }
            if (in_array(self::PURPOSE_CHARGING, $purps, true)) {
                $createTelLink(self::PURPOSE_CHARGING, self::IDENT_LINK_PREFIX . 'TELDOM_' . $this->makeIdent(self::PURPOSE_CHARGING) . '_' . $telIdent, $varId, $varName, $pos);
            }
            if (in_array(self::PURPOSE_CLIMATE, $purps, true)) {
                $createTelLink(self::PURPOSE_CLIMATE, self::IDENT_LINK_PREFIX . 'TELDOM_' . $this->makeIdent(self::PURPOSE_CLIMATE) . '_' . $telIdent, $varId, $varName, $pos);
            }
            if (in_array(self::PURPOSE_SECURITY, $purps, true)) {
                $createTelLink(self::PURPOSE_SECURITY, self::IDENT_LINK_PREFIX . 'TELDOM_' . $this->makeIdent(self::PURPOSE_SECURITY) . '_' . $telIdent, $varId, $varName, $pos);
            }
        }

        // Cleanup: entferne nicht gewünschte Links pro Purpose
        if ((bool)$this->ReadPropertyBoolean('CleanupLinks')) {
            foreach ($purposeIds as $pid) {
                $keep = $desired[$pid] ?? [];
                $this->cleanupLinksUnder($pid, $keep);
            }
        }

        $this->WriteAttributeInteger(self::ATTR_LAST_LINKS_LOCATION, $linksParent);
    }

    /**
     * Heuristische Zuordnung von Telemetrie-Keys zu Kategorien.
     * Fleet Telemetry bietet viele Feldgruppen (Charging/Climate/Safety/Location/Vehicle State/Driving …). [4](https://github.com/DG65/Symcon-Go-e-Modbus)
     */
    private function classifyTelemetryKeyToPurposes(string $key): array
    {
        $k = strtolower($key);
        $purposes = [];

        // Default: Status
        $purposes[] = self::PURPOSE_STATUS;

        // Charging
        if (
            strpos($k, 'charge') !== false ||
            strpos($k, 'charging') !== false ||
            strpos($k, 'charger') !== false ||
            strpos($k, 'battery') !== false ||
            strpos($k, 'soc') !== false ||
            strpos($k, 'energy') !== false ||
            strpos($k, 'range') !== false ||
            strpos($k, 'pack') !== false ||
            strpos($k, 'supercharg') !== false ||
            strpos($k, 'fastcharger') !== false ||
            strpos($k, 'precondition') !== false ||
            strpos($k, 'bms') !== false
        ) {
            $purposes[] = self::PURPOSE_CHARGING;
        }

        // Climate
        if (
            strpos($k, 'hvac') !== false ||
            strpos($k, 'temp') !== false ||
            strpos($k, 'defrost') !== false ||
            strpos($k, 'climate') !== false ||
            strpos($k, 'cabin') !== false ||
            strpos($k, 'seat') !== false ||
            strpos($k, 'overheat') !== false ||
            strpos($k, 'steering') !== false ||
            strpos($k, 'bio') !== false
        ) {
            $purposes[] = self::PURPOSE_CLIMATE;
        }

        // Security/Safety
        if (
            strpos($k, 'lock') !== false ||
            strpos($k, 'door') !== false ||
            strpos($k, 'window') !== false ||
            strpos($k, 'sentry') !== false ||
            strpos($k, 'pin') !== false ||
            strpos($k, 'valet') !== false ||
            strpos($k, 'belt') !== false ||
            strpos($k, 'alarm') !== false ||
            strpos($k, 'homelink') !== false ||
            strpos($k, 'hazard') !== false
        ) {
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

    // -------- HTTP (Tessie API) --------
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

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ];

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
            $this->SendDebug('ApiRequestURL', $methodUpper . ' ' . $url, 0);
        }

        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            $this->SendDebug('ApiRequest', 'cURL error: ' . $err, 0);
            return [];
        }

        $json = json_decode($resp, true);
        if (!is_array($json)) {
            $this->SendDebug('ApiRequest', 'HTTP ' . $code . ' non-JSON: ' . substr($resp, 0, 500), 0);
            return [];
        }
        return $json;
    }
}
