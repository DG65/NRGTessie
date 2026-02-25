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
