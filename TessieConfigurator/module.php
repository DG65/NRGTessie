<?php
declare(strict_types=1);
class TessieConfigurator extends IPSModule
{
    private const WS_CLIENT_MODULE_ID = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    private const VEHICLE_MODULE_ID = '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}';
    private const API_BASE = 'https://api.tessie.com';
    // Zugangsschlüssel liegt in einem Attribut (Modul-Hoheit), nicht als Property. Die
    // Properties bleiben nur als Formular-Schreibkanal bzw. Altbestand bestehen und werden
    // in ApplyChanges sofort ins Attribut übernommen und geleert.
    private const ATTR_TOKEN = 'TokenSecret';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyString('TelemetryToken', '');
        $this->RegisterAttributeString(self::ATTR_TOKEN, '');
    }

    /**
     * Übernimmt einen per Formular eingegebenen bzw. aus altem Bestand vorhandenen
     * Zugangsschlüssel ins Attribut und leert die Properties. Eine nicht-leere Property
     * gewinnt IMMER gegen einen bereits gespeicherten Attribut-Wert (Nutzer hat etwas
     * eingetragen = will ihn ersetzen); sind alle Properties leer, bleibt ein vorhandener
     * Attribut-Wert unangetastet (Formular erneut übernehmen, ohne etwas einzutragen,
     * darf den gespeicherten Schlüssel nicht löschen).
     */
    private function migrateTokenToAttribute(): void
    {
        $incoming = '';
        foreach (['Token', 'ApiToken', 'TelemetryToken'] as $propName) {
            $p = trim($this->ReadPropertyString($propName));
            if ($p === '') {
                continue;
            }
            if ($incoming === '') {
                $incoming = $p;
            }
            IPS_SetProperty($this->InstanceID, $propName, '');
        }
        if ($incoming !== '') {
            $this->WriteAttributeString(self::ATTR_TOKEN, $incoming);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);

        // Zugangsschlüssel sofort ins Attribut übernehmen (siehe Konstante ATTR_TOKEN oben).
        $this->migrateTokenToAttribute();

        $token = $this->getToken();
        if ($token === '') {
            return;
        }

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

    /** Modulversion aus library.json (Repo-Wurzel), leer wenn nicht lesbar. */
    private function moduleVersion(): string
    {
        $raw = @file_get_contents(__DIR__ . '/../library.json');
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) ? (string)($d['version'] ?? '') : '';
    }

    public function GetConfigurationForm()
    {
        $token = $this->getToken();
        $v = $this->moduleVersion();
        $dokuCaption = '📖 Dokumentation & Hilfe' . ($v !== '' ? ' (Modulversion ' . $v . ')' : '');

        $elements = [
            [
                'type' => 'ExpansionPanel',
                'caption' => $dokuCaption,
                'expanded' => false,
                'items' => [
                    ['type' => 'Label', 'caption' => '── Funktionsweise ──'],
                    ['type' => 'Label', 'caption' => 'Der Konfigurator listet alle Fahrzeuge deines Tessie-Kontos auf und legt per Klick auf \'Erstellen\' die passende TessieVehicle-Instanz samt Telemetrie-Verbindung (WebSocket) an. Ein einziger Zugangsschlüssel genügt sowohl für die Abfragen als auch für die Telemetrie.'],
                    ['type' => 'Label', 'caption' => '── Zugangsschlüssel erstellen ──'],
                    ['type' => 'Label', 'caption' => 'Auf my.tessie.com anmelden, dort unter Einstellungen → API einen Zugangsschlüssel erzeugen (in der Tessie-Oberfläche heißt er \'Access Token\') und hier eintragen. Gespeichert wird er ausschließlich lokal in deiner IP-Symcon-Installation. Nach \'Änderungen übernehmen\' erscheinen die Fahrzeuge in der Liste. Das Feld zeigt aus Sicherheitsgründen nie den gespeicherten Wert an und bleibt beim erneuten Öffnen leer – ein bestehender Schlüssel bleibt dabei erhalten, solange nichts eingetragen wird; zum Ändern einfach den neuen Schlüssel eintragen.'],
                    ['type' => 'Label', 'caption' => '── Weitere Hilfe ──'],
                    ['type' => 'Label', 'caption' => 'Ausführliche Dokumentation: github.com/DG65/Tessie. Das Modul ist ein privates Community-Projekt und steht in keiner Verbindung zu Tesla, Inc. oder Tessie.']
                ]
            ],
            ['type' => 'Label', 'label' => 'Tessie Konfigurator – Fahrzeuge'],
            // Eigenschaftsname 'Token' bleibt unverändert (Code-Bezeichner), nur die Beschriftung ist deutsch
            ['type' => 'PasswordTextBox', 'name' => 'Token', 'caption' => 'Tessie-Zugangsschlüssel (bei Tessie \'Access Token\'; gilt für Abfragen und Telemetrie) – leer lassen, um den bestehenden Schlüssel zu behalten']
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
                    'name' => $name,
                    'address' => $vin,
                    'instanceID' => $instanceId,
                    'create' => $create
                ];
            }
        }

        $form = [
            'elements' => $elements,
            'actions' => [
                [
                    'type' => 'Configurator',
                    'name' => 'Vehicles',
                    'caption' => 'Fahrzeuge',
                    'rowCount' => 12,
                    'delete' => true,
                    'values' => $values
                ]
            ]
        ];

        return json_encode($form);
    }

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

            $desiredUrl = 'wss://streaming.tessie.com/' . rawurlencode($vin) . '?access_token=' . rawurlencode($token);
            $desired = [
                'Active' => true,
                'VerifyCertificate' => true,
                'Type' => 0,
                'URL' => $desiredUrl,
                'Headers' => '[]'
            ];

            $cfg = $this->readInstanceConfig($wsId);

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

            $cfg = $this->readInstanceConfig($vehId);

            $needApply = false;
            if (($cfg['ApiToken'] ?? '') !== $token) {
                IPS_SetProperty($vehId, 'ApiToken', $token);
                $needApply = true;
            }
            if (($cfg['ApiBase'] ?? '') !== self::API_BASE) {
                IPS_SetProperty($vehId, 'ApiBase', self::API_BASE);
                $needApply = true;
            }

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
            $cfg = $this->readInstanceConfig($iid);
            if (count($cfg) === 0) {
                continue;
            }

            $url = (string)($cfg['URL'] ?? '');
            if ($url === '') {
                continue;
            }

            // Fix: ODER statt UND
            if (stripos($url, $needle1) !== false || stripos($url, $needle2) !== false) {
                return $iid;
            }
        }

        return 0;
    }

    private function buildCreateChain(string $vin, string $name, string $token): array
    {
        $vehicleCfg = [
            'VIN' => $vin,
            'ApiToken' => $token,
            'ApiBase' => self::API_BASE,
            // Kompatibilität: wird vom TessieConfigurator/anderen Tools gelesen
            'InstanceInterface' => '[]'
        ];

        $wsUrl = 'wss://streaming.tessie.com/' . rawurlencode($vin) . '?access_token=' . rawurlencode($token);
        $wsCfg = [
            'Active' => true,
            'VerifyCertificate' => true,
            'Type' => 0,
            'URL' => $wsUrl,
            'Headers' => '[]'
        ];

        return [
            [
                'moduleID' => self::VEHICLE_MODULE_ID,
                'configuration' => $vehicleCfg,
                'name' => $name
            ],
            [
                'moduleID' => self::WS_CLIENT_MODULE_ID,
                'configuration' => $wsCfg,
                'name' => 'Tessie Telemetrie ' . $vin
            ]
        ];
    }

    /** Liest den Zugangsschlüssel aus dem Attribut (siehe migrateTokenToAttribute). */
    private function getToken(): string
    {
        return trim($this->ReadAttributeString(self::ATTR_TOKEN));
    }

    private function fetchVehicles(string $token): array
    {
        $data = $this->apiRequest($token, 'GET', '/api/1/vehicles');
        $payload = $data['response'] ?? $data;

        if (!is_array($payload)) return [];
        if (isset($payload['vehicles']) && is_array($payload['vehicles'])) return $payload['vehicles'];
        if (array_keys($payload) === range(0, count($payload) - 1)) return $payload;

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

    // Liest die Instanz-Konfiguration sicher; IPS_GetConfiguration kann (z.B. während
    // einer Instanz-Erstellung) null/false liefern -> dann leeres Array statt Fatal.
    private function readInstanceConfig(int $instanceID): array
    {
        $raw = @IPS_GetConfiguration($instanceID);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $cfg = json_decode($raw, true);
        return is_array($cfg) ? $cfg : [];
    }

    private function findVehicleInstance(string $vin): int
    {
        $instances = IPS_GetInstanceListByModuleID(self::VEHICLE_MODULE_ID);
        foreach ($instances as $iid) {
            $cfg = $this->readInstanceConfig($iid);
            if ((string)($cfg['VIN'] ?? '') === $vin) {
                return $iid;
            }
        }
        return 0;
    }
}
