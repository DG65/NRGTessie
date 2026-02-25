<?php
declare(strict_types=1);

class TessieConfigurator extends IPSModule
{
    // Symcon Core WebSocket Client (I/O)
    private const WS_CLIENT_MODULE_ID = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    // TessieVehicle module GUID
    private const VEHICLE_MODULE_ID   = '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}';
    // Fixed ApiBase
    private const API_BASE = 'https://api.tessie.com';

    public function Create()
    {
        parent::Create();
        // Single token (used for REST + Telemetry)
        $this->RegisterPropertyString('Token', '');

        // Backward compatibility (optional): old properties read as fallback if still set
        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyString('TelemetryToken', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);

        // Only sync on "Übernehmen"
        $token = $this->getToken();
        if ($token === '') {
            return;
        }

        // Fetch VINs and softly sync existing WS clients + vehicle instances
        $vehicles = $this->fetchVehicles($token);
        $vins = [];
        foreach ($vehicles as $v) {
            $vin = (string)($v['vin'] ?? '');
            if ($vin !== '') {
                $vins[] = $vin;
            }
        }
        $vins = array_values(array_unique($vins));

        $this->syncExistingWSClientsOnApply($token, $vins);
        $this->syncExistingVehicleInstancesOnApply($token, $vins);
    }

    public function GetConfigurationForm()
    {
        $token = $this->getToken();

        $elements = [
            ['type' => 'Label', 'label' => 'Tessie Configurator – Fahrzeuge'],
            ['type' => 'ValidationTextBox', 'name' => 'Token', 'caption' => 'Tessie Token (ein Token für REST + Telemetrie)']
        ];

        $values = [];
        if ($token !== '') {
            $vehicles = $this->fetchVehicles($token);
            foreach ($vehicles as $v) {
                $vin = (string)($v['vin'] ?? '');
                if ($vin === '') {
                    continue;
                }
                $name = (string)($v['display_name'] ?? $v['name'] ?? $vin);
                $instanceId = $this->findVehicleInstance($vin);
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
            'actions'  => [
                [
                    'type'     => 'Configurator',
                    'name'     => 'Vehicles',
                    'caption'  => 'Fahrzeuge',
                    'rowCount' => 12,
                    'delete'   => true,
                    'values'   => $values
                ]
            ]
        ];

        return json_encode($form);
    }

    // ----------------------------
    // ApplyChanges-only soft sync
    // ----------------------------
    private function syncExistingWSClientsOnApply(string $token, array $vins): void
    {
        if (count($vins) === 0) {
            return;
        }

        $wsInstances = IPS_GetInstanceListByModuleID(self::WS_CLIENT_MODULE_ID);

        foreach ($vins as $vin) {
            $wsId = $this->findWSClientForVin($vin, $wsInstances);
            if ($wsId <= 0) {
                continue;
            }

            $desiredUrl = 'wss://streaming.tessie.com/' . rawurlencode($vin)
                . '?access_token=' . rawurlencode($token);

            $desired = [
                'Active'            => true,
                'VerifyCertificate' => true,
                'Type'              => 0, // Text (JSON)
                'URL'               => $desiredUrl,
                'Headers'           => '[]'
            ];

            $cfg = json_decode(IPS_GetConfiguration($wsId), true);
            if (!is_array($cfg)) {
                $cfg = [];
            }

            $needApply = false;
            foreach ($desired as $k => $v) {
                $cur = $cfg[$k] ?? null;
                if (is_bool($v)) {
                    $cur = (bool)$cur;
                }
                if ($cur !== $v) {
                    IPS_SetProperty($wsId, $k, $v);
                    $needApply = true;
                }
            }

            if ($needApply) {
                IPS_ApplyChanges($wsId);
            }
        }
    }

    private function syncExistingVehicleInstancesOnApply(string $token, array $vins): void
    {
        if (count($vins) === 0) {
            return;
        }

        foreach ($vins as $vin) {
            $vehId = $this->findVehicleInstance($vin);
            if ($vehId <= 0) {
                continue;
            }

            $cfg = json_decode(IPS_GetConfiguration($vehId), true);
            if (!is_array($cfg)) {
                $cfg = [];
            }

            $needApply = false;

            if (($cfg['ApiToken'] ?? '') !== $token) {
                IPS_SetProperty($vehId, 'ApiToken', $token);
                $needApply = true;
            }
            if (($cfg['ApiBase'] ?? '') !== self::API_BASE) {
                IPS_SetProperty($vehId, 'ApiBase', self::API_BASE);
                $needApply = true;
            }
            // NOTE: TelemetryEnabled is intentionally NOT touched anymore.

            if ($needApply) {
                IPS_ApplyChanges($vehId);
            }
        }
    }

    private function findWSClientForVin(string $vin, array $wsInstances): int
    {
        $needle1 = 'streaming.tessie.com/' . $vin;
        $needle2 = 'streaming.tessie.com/' . rawurlencode($vin);

        foreach ($wsInstances as $iid) {
            $cfg = json_decode(IPS_GetConfiguration($iid), true);
            if (!is_array($cfg)) {
                continue;
            }
            $url = (string)($cfg['URL'] ?? '');
            if ($url === '') {
                continue;
            }

            // ✅ BUGFIX: fehlendes ODER (||)
            if (stripos($url, $needle1) !== false || stripos($url, $needle2) !== false) {
                return $iid;
            }
        }

        return 0;
    }

    // ----------------------------
    // Create chain (used by Configurator list)
    // ----------------------------
    private function buildCreateChain(string $vin, string $name, string $token): array
    {
        $vehicleCfg = [
            'VIN'     => $vin,
            'ApiToken'=> $token,
            'ApiBase' => self::API_BASE
            // TelemetryEnabled intentionally removed
        ];

        $wsUrl = 'wss://streaming.tessie.com/' . rawurlencode($vin)
            . '?access_token=' . rawurlencode($token);

        $wsCfg = [
            'Active'            => true,
            'VerifyCertificate' => true,
            'Type'              => 0, // Text
            'URL'               => $wsUrl,
            'Headers'           => '[]'
        ];

        return [
            [
                'moduleID'       => self::VEHICLE_MODULE_ID,
                'configuration'  => $vehicleCfg,
                'name'           => $name
            ],
            [
                'moduleID'       => self::WS_CLIENT_MODULE_ID,
                'configuration'  => $wsCfg,
                'name'           => 'Tessie Telemetry ' . $vin
            ]
        ];
    }

    // ----------------------------
    // REST helpers
    // ----------------------------
    private function getToken(): string
    {
        $t = trim($this->ReadPropertyString('Token'));
        if ($t !== '') {
            return $t;
        }
        $old = trim($this->ReadPropertyString('ApiToken'));
        if ($old !== '') {
            return $old;
        }
        $oldTel = trim($this->ReadPropertyString('TelemetryToken'));
        if ($oldTel !== '') {
            return $oldTel;
        }
        return '';
    }

    private function fetchVehicles(string $token): array
    {
        $data = $this->apiRequest($token, 'GET', '/api/1/vehicles');

        $payload = $data['response'] ?? $data;
        if (!is_array($payload)) {
            return [];
        }

        if (isset($payload['vehicles']) && is_array($payload['vehicles'])) {
            return $payload['vehicles'];
        }

        if (array_keys($payload) === range(0, count($payload) - 1)) {
            return $payload;
        }

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
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

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

    private function findVehicleInstance(string $vin): int
    {
        $instances = IPS_GetInstanceListByModuleID(self::VEHICLE_MODULE_ID);
        foreach ($instances as $iid) {
            $cfg = json_decode(IPS_GetConfiguration($iid), true);
            if (is_array($cfg) && (string)($cfg['VIN'] ?? '') === $vin) {
                return $iid;
            }
        }
        return 0;
    }
}
