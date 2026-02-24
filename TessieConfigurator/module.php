<?php
declare(strict_types=1);

class TessieConfigurator extends IPSModule
{
    private const WS_CLIENT_MODULE_ID = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    private const VEHICLE_MODULE_ID   = '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}';
    private const API_BASE = 'https://api.tessie.com';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyString('TelemetryToken', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // In Symcon 9.0: I/O Instanzen sind nicht immer sofort verfügbar,
        // daher KEIN Auto-Sync von WS Instanzen hier.
        $token = $this->getToken();
        if ($token === '') {
            $this->SetStatus(104);
            return;
        }
        $this->SetStatus(102);
    }

    public function GetConfigurationForm(): string
    {
        $token = $this->getToken();

        $elements = [
            ['type' => 'Label', 'label' => 'Tessie Configurator – Fahrzeuge'],
            ['type' => 'ValidationTextBox', 'name' => 'Token', 'caption' => 'Tessie Token (ein Token für REST + Telemetrie)'],
            ['type' => 'Label', 'label' => 'Telemetrie: Token wird kompatibel per ?access_token=... in der WS-URL genutzt (keine Custom-Headers).']
        ];

        $values = [];
        if ($token !== '') {
            foreach ($this->fetchVehicles($token) as $v) {
                $vin = (string)($v['vin'] ?? '');
                if ($vin === '') {
                    continue;
                }

                $name = (string)($v['display_name'] ?? $v['name'] ?? $vin);
                if ($name === '') {
                    $name = $vin;
                }

                $instanceId = $this->findVehicleInstance($vin);

                // Create chain: IMPORTANT order (Vehicle first, then WS Client)
                $create = $this->buildCreateChain($vin, $name, $token);

                $values[] = [
                    'name'       => $name,
                    'address'    => $vin,
                    'instanceID' => $instanceId,
                    'create'     => $create
                ];
            }
        }

        $form = [
            'elements' => $elements,
            'actions'  => [[
                'type'     => 'Configurator',
                'name'     => 'Vehicles',
                'caption'  => 'Fahrzeuge',
                'rowCount' => 12,
                'delete'   => true,
                'values'   => $values
            ]]
        ];

        return json_encode($form);
    }

    private function buildCreateChain(string $vin, string $name, string $token): array
    {
        $vehicleCfg = [
            'VIN'      => $vin,
            'ApiToken' => $token,
            'ApiBase'  => self::API_BASE
        ];

        // Tessie Telemetry: token in URL supported [2](https://developer.tessie.com/reference/get-weather)
        $wsUrl = 'wss://streaming.tessie.com/' . rawurlencode($vin) . '?access_token=' . rawurlencode($token);

        $wsCfg = [
            'Active'            => true,
            'VerifyCertificate' => true,
            'Type'              => 0,
            'URL'               => $wsUrl
        ];

        // ✅ Reihenfolge geändert: erst Vehicle, dann WS Client
        // Damit Symcon nicht versucht, dem I/O Client einen Parent zu geben.
        return [
            [
                'moduleID'      => self::VEHICLE_MODULE_ID,
                'configuration' => $vehicleCfg,
                'name'          => $name
            ],
            [
                'moduleID'      => self::WS_CLIENT_MODULE_ID,
                'configuration' => $wsCfg,
                'name'          => 'Tessie Telemetry ' . $vin
            ]
        ];
    }

    private function getToken(): string
    {
        $t = trim($this->ReadPropertyString('Token'));
        if ($t !== '') return $t;

        $old = trim($this->ReadPropertyString('ApiToken'));
        if ($old !== '') return $old;

        $oldTel = trim($this->ReadPropertyString('TelemetryToken'));
        if ($oldTel !== '') return $oldTel;

        return '';
    }

    private function fetchVehicles(string $token): array
    {
        // Prefer Tessie API: GET /vehicles
        $data = $this->apiRequest($token, 'GET', '/vehicles');
        if (isset($data['results']) && is_array($data['results'])) {
            $out = [];
            foreach ($data['results'] as $row) {
                if (!is_array($row)) continue;
                $vin = (string)($row['vin'] ?? '');
                if ($vin === '') continue;

                $last = $row['last_state'] ?? [];
                $dn = '';
                if (is_array($last)) {
                    $dn = (string)($last['display_name'] ?? ($last['vehicle_state']['vehicle_name'] ?? ''));
                }

                $out[] = ['vin' => $vin, 'display_name' => $dn];
            }
            return $out;
        }

        // Fallback: Fleet layer
        $data = $this->apiRequest($token, 'GET', '/api/1/vehicles');
        $payload = $data['response'] ?? $data;
        if (isset($payload['vehicles']) && is_array($payload['vehicles'])) return $payload['vehicles'];
        if (is_array($payload) && array_keys($payload) === range(0, count($payload) - 1)) return $payload;

        return [];
    }

    private function apiRequest(string $token, string $method, string $path): array
    {
        $url = rtrim(self::API_BASE, '/') . $path;
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $this->SendDebug('ApiRequest', 'cURL error: ' . curl_error($ch), 0);
            curl_close($ch);
            return [];
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($resp, true);
        if (!is_array($json)) {
            $this->SendDebug('ApiRequest', 'HTTP ' . $code . ' non-JSON: ' . substr($resp, 0, 500), 0);
            return [];
        }
        return $json;
    }

    private function findVehicleInstance(string $vin): int
    {
        $instances = IPS_GetInstanceListByModuleID(self::VEHICLE_MODULE_ID);
        foreach ($instances as $iid) {
            $cfgJson = IPS_GetConfiguration($iid);
            if (!is_string($cfgJson) || $cfgJson === '') continue;
            $cfg = json_decode($cfgJson, true);
            if (is_array($cfg) && (string)($cfg['VIN'] ?? '') === $vin) return $iid;
        }
        return 0;
    }
}
