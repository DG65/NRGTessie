<?php
declare(strict_types=1);

class TessieVehicle extends IPSModule
{
    private const TIMER_UPDATE = 'UpdateTimer';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyString('VIN', '');
        $this->RegisterPropertyString('ApiBase', 'https://api.tessie.com');

        $this->RegisterPropertyInteger('UpdateInterval', 300);
        $this->RegisterPropertyBoolean('DebugHTTP', false);

        $this->RegisterPropertyString('UnitSystem', 'Auto'); // Auto|Metric|Imperial

        // Metric ist Standard, RAW optional
        $this->RegisterPropertyBoolean('AutoMetric', true);
        $this->RegisterPropertyBoolean('AutoAllData', false);
        $this->RegisterPropertyInteger('AutoAllDataMaxVars', 0);
        $this->RegisterPropertyString('AutoAllDataPrefix', '');

        $this->RegisterAttributeString('DetectedUnitSystem', '');
        $this->RegisterAttributeInteger('LastTelemetryTs', 0);

        $this->RegisterTimer(self::TIMER_UPDATE, 0, 'TESSIE_Update($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $interval = (int)$this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval(self::TIMER_UPDATE, $interval > 0 ? $interval * 1000 : 0);

        if ((bool)$this->ReadPropertyBoolean('AutoMetric')) {
            $this->ensureAllDataCategory(true);
        }
        if ((bool)$this->ReadPropertyBoolean('AutoAllData')) {
            $this->ensureAllDataCategory(false);
        }

        $this->ensureDetectedUnitSystem();

        $this->SetStatus(102);
    }

    public function Update(): void
    {
        $token = trim((string)$this->ReadPropertyString('ApiToken'));
        $vin = trim((string)$this->ReadPropertyString('VIN'));
        if ($token === '' || $vin === '') {
            return;
        }

        $state = $this->apiRequest($token, 'GET', '/' . rawurlencode($vin) . '/state?use_cache=true', null);
        if (is_array($state) && count($state) > 0) {
            $det = $this->detectUnitSystemFromState($state);
            if ($det !== '') {
                $this->WriteAttributeString('DetectedUnitSystem', $det);
            }

            if ((bool)$this->ReadPropertyBoolean('DebugHTTP')) {
                $this->SendDebug('UnitSystem', 'Detected=' . $this->getDetectedUnitSystem() . ' (mode=' . $this->ReadPropertyString('UnitSystem') . ')', 0);
            }

            if ((bool)$this->ReadPropertyBoolean('AutoMetric')) {
                $this->syncAllDataFromState($state, true);
            }
            if ((bool)$this->ReadPropertyBoolean('AutoAllData')) {
                $this->syncAllDataFromState($state, false);
            }
        }
    }

    public function ReceiveData($JSONString): void
    {
        $this->WriteAttributeInteger('LastTelemetryTs', time());

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
            return;
        }

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return;
        }

        $this->ensureDetectedUnitSystem();

        if ((bool)$this->ReadPropertyBoolean('AutoMetric')) {
            $this->syncAllDataFromTelemetry($payload['data'], true);
        }
        if ((bool)$this->ReadPropertyBoolean('AutoAllData')) {
            $this->syncAllDataFromTelemetry($payload['data'], false);
        }
    }

    // -------- Unit system --------

    private function ensureDetectedUnitSystem(): void
    {
        $current = trim((string)$this->ReadAttributeString('DetectedUnitSystem'));
        if ($current !== '') {
            return;
        }

        $mode = (string)$this->ReadPropertyString('UnitSystem');
        if ($mode === 'Metric') {
            $this->WriteAttributeString('DetectedUnitSystem', 'metric');
            return;
        }
        if ($mode === 'Imperial') {
            $this->WriteAttributeString('DetectedUnitSystem', 'imperial');
            return;
        }

        $token = trim((string)$this->ReadPropertyString('ApiToken'));
        $vin = trim((string)$this->ReadPropertyString('VIN'));
        if ($token === '' || $vin === '') {
            return;
        }

        $state = $this->apiRequest($token, 'GET', '/' . rawurlencode($vin) . '/state?use_cache=true', null);
        if (is_array($state) && count($state) > 0) {
            $det = $this->detectUnitSystemFromState($state);
            if ($det !== '') {
                $this->WriteAttributeString('DetectedUnitSystem', $det);
            }
        }

        if (trim((string)$this->ReadAttributeString('DetectedUnitSystem')) === '') {
            $this->WriteAttributeString('DetectedUnitSystem', 'metric');
        }

        if ((bool)$this->ReadPropertyBoolean('DebugHTTP')) {
            $this->SendDebug('UnitSystem', 'Initialized=' . $this->getDetectedUnitSystem() . ' (mode=Auto)', 0);
        }
    }

    private function detectUnitSystemFromState(array $state): string
    {
        $gui = $state['gui_settings'] ?? null;
        if (is_array($gui)) {
            $dist = strtolower((string)($gui['gui_distance_units'] ?? ''));
            $temp = strtoupper((string)($gui['gui_temperature_units'] ?? ''));
            if (strpos($dist, 'mi') !== false || $temp === 'F') return 'imperial';
            if (strpos($dist, 'km') !== false || $temp === 'C') return 'metric';
        }
        return '';
    }

    private function getDetectedUnitSystem(): string
    {
        $det = trim((string)$this->ReadAttributeString('DetectedUnitSystem'));
        return $det !== '' ? $det : 'metric';
    }

    private function shouldConvertImperial(): bool
    {
        return $this->getDetectedUnitSystem() === 'imperial';
    }

    // -------- Conversion --------

    private function mphToKmh(float $mph): float { return $mph * 1.609344; }
    private function miToKm(float $mi): float { return $mi * 1.609344; }
    private function fToC(float $f): float { return ($f - 32.0) * 5.0 / 9.0; }
    private function psiToBar(float $psi): float { return $psi * 0.0689476; }

    private function classifyKey(string $name): string
    {
        $n = strtolower($name);
        if (strpos($n, 'temp') !== false) return 'temp';
        if (strpos($n, 'speed') !== false || strpos($n, 'charge_rate') !== false) return 'speed';
        if (strpos($n, 'range') !== false || strpos($n, 'odometer') !== false || strpos($n, 'distance') !== false || strpos($n, 'miles') !== false) return 'distance';
        if (strpos($n, 'tpms') !== false || strpos($n, 'tire_pressure') !== false || (strpos($n, 'pressure') !== false && strpos($n, 'tire') !== false)) return 'pressure';
        return '';
    }

    private function convertScalarIfNeeded(string $name, $value)
    {
        if (!$this->shouldConvertImperial()) return $value;
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) return $value;
        $v = (float)$value;
        switch ($this->classifyKey($name)) {
            case 'temp': return $this->fToC($v);
            case 'speed': return $this->mphToKmh($v);
            case 'distance': return $this->miToKm($v);
            case 'pressure': return $this->psiToBar($v);
            default: return $value;
        }
    }

    // -------- Telemetry scalar --------

    private function telemetryValueToScalar(array $val)
    {
        if (array_key_exists('booleanValue', $val)) return (bool)$val['booleanValue'];
        if (array_key_exists('intValue', $val)) return (int)$val['intValue'];
        if (array_key_exists('doubleValue', $val)) return (float)$val['doubleValue'];
        if (array_key_exists('stringValue', $val)) {
            $s = (string)$val['stringValue'];
            if (is_numeric($s)) return (strpos($s, '.') !== false) ? (float)$s : (int)$s;
            if (strcasecmp($s, 'true') === 0) return true;
            if (strcasecmp($s, 'false') === 0) return false;
            return $s;
        }
        if (array_key_exists('locationValue', $val) && is_array($val['locationValue'])) return $val['locationValue'];
        return null;
    }

    // -------- Auto variables --------

    private function ensureAllDataCategory(bool $metric): int
    {
        $ident = $metric ? 'TESSIE_ALLDATA_METRIC' : 'TESSIE_ALLDATA';
        $name = $metric ? 'Alle Datenpunkte (metrisch)' : 'Alle Datenpunkte (RAW)';
        $cid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($cid <= 0) {
            $cid = IPS_CreateCategory();
            IPS_SetParent($cid, $this->InstanceID);
            IPS_SetIdent($cid, $ident);
            IPS_SetName($cid, $name);
        } else {
            if (IPS_GetName($cid) !== $name) IPS_SetName($cid, $name);
        }
        return $cid;
    }

    private function syncAllDataFromTelemetry(array $dataItems, bool $metric): void
    {
        $cid = $this->ensureAllDataCategory($metric);
        $prefix = trim((string)$this->ReadPropertyString('AutoAllDataPrefix'));

        foreach ($dataItems as $item) {
            if (!is_array($item)) continue;
            $key = (string)($item['key'] ?? '');
            $val = $item['value'] ?? null;
            if ($key === '' || !is_array($val)) continue;

            $scalar = $this->telemetryValueToScalar($val);
            $base = ($metric ? 'metric.telemetry.' : 'telemetry.') . $key;

            if (is_array($scalar)) {
                foreach ($scalar as $k2 => $v2) {
                    $full = $prefix . $base . '.' . $k2;
                    $out = $metric ? $this->convertScalarIfNeeded($key, $v2) : $v2;
                    $this->upsertAutoVar($cid, $full, $out);
                }
            } else {
                $full = $prefix . $base;
                $out = $metric ? $this->convertScalarIfNeeded($key, $scalar) : $scalar;
                $this->upsertAutoVar($cid, $full, $out);
            }
        }
    }

    private function syncAllDataFromState(array $state, bool $metric): void
    {
        $cid = $this->ensureAllDataCategory($metric);
        $prefix = trim((string)$this->ReadPropertyString('AutoAllDataPrefix'));
        $base = $metric ? ($prefix . 'metric.state') : ($prefix . 'state');
        $flat = $this->flatten($state, $base);

        foreach ($flat as $k => $v) {
            $out = $metric ? $this->convertScalarIfNeeded($k, $v) : $v;
            $this->upsertAutoVar($cid, $k, $out);
        }
    }

    private function flatten($value, string $pathPrefix): array
    {
        $out = [];
        $recurse = function ($v, string $path) use (&$out, &$recurse): void {
            if (is_array($v)) {
                foreach ($v as $k => $vv) {
                    $seg = is_int($k) ? ('[' . $k . ']') : (string)$k;
                    $recurse($vv, $path . '.' . $seg);
                }
                return;
            }
            $out[$path] = $v;
        };
        $recurse($value, $pathPrefix);
        return $out;
    }

    private function upsertAutoVar(int $parentCatId, string $fullKey, $value): void
    {
        if (!IPS_ObjectExists($parentCatId)) return;

        $max = (int)$this->ReadPropertyInteger('AutoAllDataMaxVars');
        if ($max > 0) {
            $count = 0;
            foreach (IPS_GetChildrenIDs($parentCatId) as $cid) {
                $obj = IPS_GetObject($cid);
                if (($obj['ObjectType'] ?? 0) === OBJECTTYPE_VARIABLE) $count++;
            }
            if ($count >= $max) return;
        }

        $ident = 'D_' . substr(sha1($fullKey), 0, 20);
        $vid = @IPS_GetObjectIDByIdent($ident, $parentCatId);

        $name = $fullKey;
        $vartype = VARIABLETYPE_STRING;

        if (is_bool($value)) {
            $vartype = VARIABLETYPE_BOOLEAN;
        } elseif (is_int($value)) {
            $vartype = VARIABLETYPE_INTEGER;
        } elseif (is_float($value)) {
            $vartype = VARIABLETYPE_FLOAT;
        } elseif (is_string($value) && is_numeric($value)) {
            $vartype = VARIABLETYPE_FLOAT;
            $value = (float)$value;
        } elseif ($value === null) {
            $vartype = VARIABLETYPE_STRING;
            $value = '';
        } else {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            } else {
                $value = (string)$value;
            }
        }

        if ($vid <= 0) {
            $vid = IPS_CreateVariable($vartype);
            IPS_SetParent($vid, $parentCatId);
            IPS_SetIdent($vid, $ident);
            IPS_SetName($vid, $name);
        } else {
            $var = IPS_GetVariable($vid);
            if (($var['VariableType'] ?? null) !== $vartype) {
                IPS_DeleteVariable($vid);
                $vid = IPS_CreateVariable($vartype);
                IPS_SetParent($vid, $parentCatId);
                IPS_SetIdent($vid, $ident);
                IPS_SetName($vid, $name);
            }
            if (IPS_GetName($vid) !== $name) IPS_SetName($vid, $name);
        }

        if ($vartype === VARIABLETYPE_BOOLEAN) {
            @SetValueBoolean($vid, (bool)$value);
        } elseif ($vartype === VARIABLETYPE_INTEGER) {
            @SetValueInteger($vid, (int)$value);
        } elseif ($vartype === VARIABLETYPE_FLOAT) {
            @SetValueFloat($vid, (float)$value);
        } else {
            @SetValueString($vid, (string)$value);
        }
    }

    // -------- HTTP --------

    private function apiRequest(string $token, string $method, string $path, $body): array
    {
        $base = rtrim((string)$this->ReadPropertyString('ApiBase'), '/');
        if ($path === '' || $path[0] !== '/') $path = '/' . $path;
        $url = $base . $path;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
        if ($body !== null) {
            $jsonBody = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            $headers[] = 'Content-Type: application/json';
        } else {
            if (strtoupper($method) === 'POST') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, '');
                $headers[] = 'Content-Length: 0';
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ((bool)$this->ReadPropertyBoolean('DebugHTTP')) {
            $this->SendDebug('ApiRequestURL', strtoupper($method) . ' ' . $url, 0);
        }

        $resp = curl_exec($ch);
        if ($resp === false) {
            $this->SendDebug('ApiRequest', 'cURL error: ' . curl_error($ch), 0);
            curl_close($ch);
            return [];
        }
        curl_close($ch);

        $json = json_decode($resp, true);
        return is_array($json) ? $json : [];
    }
}
