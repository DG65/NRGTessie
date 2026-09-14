<?php
declare(strict_types=1);
class TessieConfigurator extends IPSModule
{
    private const WS_CLIENT_MODULE_ID = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    private const VEHICLE_MODULE_ID = '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}';
    // Eigene GUID (module.json "id") - für die Geschwister-Instanz-Synchronisierung des
    // Ausblenden-Zustands (siehe PropagateDismiss()).
    private const SELF_MODULE_ID = '{7F7B979E-0D9F-4E4A-9C0D-2A3B1B0A4D21}';
    private const API_BASE = 'https://api.tessie.com';
    // Zugangsschlüssel liegt in einem Attribut (Modul-Hoheit), nicht als Property. Die
    // Properties bleiben nur als Formular-Schreibkanal bzw. Altbestand bestehen und werden
    // in ApplyChanges sofort ins Attribut übernommen und geleert.
    private const ATTR_TOKEN = 'TokenSecret';
    private const ATTR_LAST_DISCOVERY_TS = 'LastDiscoveryTs';
    private const ATTR_PURPOSE_INTRO_GONE = 'PurposeIntroGone';
    private const ATTR_FORUM_HINT_GONE = 'ForumHintGone';
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-tessie-tesla-fahrzeuge-in-ip-symcon-steuerung-telemetrie-kachel/143995';
    private const LICENSE_URL = 'https://github.com/DG65/NRGTessie/blob/ems-integration/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyString('TelemetryToken', '');
        $this->RegisterAttributeString(self::ATTR_TOKEN, '');
        $this->RegisterAttributeInteger(self::ATTR_LAST_DISCOVERY_TS, 0);
        $this->RegisterAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE, false);
        $this->RegisterAttributeBoolean(self::ATTR_FORUM_HINT_GONE, false);
    }

    /**
     * Fragt das Tessie-Konto ab und liefert die 'values' fürs Fahrzeuge-Configurator-
     * Element, stempelt LastDiscoveryTs. Gemeinsam von GetConfigurationForm() (initiale
     * Anzeige) und RefreshVehicles() (Button-Klick im bereits offenen Formular) genutzt.
     */
    private function discoverVehicles(string $token): array
    {
        $values = [];
        if ($token === '') {
            return $values;
        }
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
        $this->WriteAttributeInteger(self::ATTR_LAST_DISCOVERY_TS, time());
        return $values;
    }

    /**
     * Sucht erneut und aktualisiert das BEREITS OFFENE Formular explizit per
     * UpdateFormField() - GetConfigurationForm() läuft nach einem Button-Klick NICHT
     * automatisch erneut (SUITE.md-Stolperfalle 12, live von Dietmar bei EMS gefunden).
     */
    public function RefreshVehicles(): void
    {
        $token = $this->getToken();
        $values = $this->discoverVehicles($token);
        $this->UpdateFormField('DiscoverySummary', 'caption', $this->getDiscoverySummaryLine(count($values)));
        $this->UpdateFormField('Vehicles', 'values', $values);
    }

    /**
     * Kopfzeile fürs Fahrzeuge-Panel nach der Verbund-Konvention "Einheitliche
     * Verbund-Status-Kopfzeile" (SUITE.md, 20.08.2026).
     */
    private function getDiscoverySummaryLine(int $count): string
    {
        $ts = (int)$this->ReadAttributeInteger(self::ATTR_LAST_DISCOVERY_TS);
        if ($ts === 0) {
            return 'ℹ️ Noch nicht gesucht – Zugangsschlüssel eintragen und übernehmen.';
        }
        $icon = $count > 0 ? '✅' : '⚠️';
        return sprintf('%s %d Fahrzeug(e) gefunden (zuletzt %s Uhr).', $icon, $count, date('H:i:s', $ts));
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
            $p = trim((string)$this->ReadPropertyString($propName));
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

        // Eine neu angelegte Instanz übernimmt den Ausblenden-Stand einer Geschwister-Instanz.
        $this->AdoptDismissFromSibling();

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

    /**
     * "Wozu dieses Modul?" – ganz vorn, noch vor dem Doku-Panel (SUITE.md Formular-Konvention
     * Punkt 0). Einmalig dismissible (kein Versionsbezug wie beim News-Banner): der Zweck
     * eines Moduls ändert sich nicht mit jedem Release. Gerade beim Configurator wichtig, da
     * er für einen neuen Nutzer der allererste Bildschirm überhaupt ist.
     */
    private function purposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE)) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Der Tessie Konfigurator ist der Einstiegspunkt: Zugangsschlüssel eintragen, Fahrzeuge deines Tessie-Kontos finden und per Klick die passende TessieVehicle-Instanz samt Telemetrie-Verbindung anlegen.'],
                ['type' => 'Label', 'caption' => 'Der Nutzen: du richtest nichts von Hand ein – Instanz, WebSocket-Verbindung und Datenpunkte entstehen automatisch. Nach dem Einrichten brauchst du dieses Modul nur noch, wenn ein weiteres Fahrzeug hinzukommt oder sich der Zugangsschlüssel ändert.'],
                ['type' => 'Label', 'caption' => 'Für die eigentliche Fahrzeugsteuerung/-anzeige ist TessieVehicle zuständig, für eine Kachel-Ansicht TessieVehicleTile.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'TESSIE_AckConfiguratorPurposeIntro($id);'],
            ],
        ];
    }

    /**
     * Eigener Methodenname statt AckPurposeIntro() - TessieVehicle nutzt bereits diesen Namen
     * und teilt sich mit TessieConfigurator den Prefix "TESSIE" (module.json); zwei Methoden
     * mit identischem Namen unter demselben Prefix würden die globale Funktion
     * TESSIE_AckPurposeIntro() doppelt deklarieren (Fatal Error beim Kernel-Laden).
     */
    public function AckConfiguratorPurposeIntro(): void
    {
        $this->WriteAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE, true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
        $this->PropagateDismiss('PurposeIntro');
    }

    /**
     * Ausblenden von "Wozu dieses Modul?" über alle Geschwister-Instanzen dieses Moduls
     * teilen (SUITE.md "Ausblenden über mehrere Instanzen desselben Moduls teilen",
     * 14.09.2026, Referenz MeterHub). Ruft bei jeder Geschwister-Instanz NUR den reinen
     * Übernahme-Schritt auf, nicht erneut die volle Ack-Methode - kein Ping-Pong möglich,
     * ganz ohne Prozessmerker.
     */
    private function PropagateDismiss(string $what, string $value = ''): void
    {
        foreach (IPS_GetInstanceListByModuleID(self::SELF_MODULE_ID) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                TESSIE_AdoptConfiguratorDismissState($sib, $what, $value);
            } catch (\Throwable $e) {
                // Eine Geschwister-Instanz mitten im Reload/Löschen darf das Ausblenden der
                // aufrufenden Instanz nicht mitreißen - @ hält Fatals nicht auf.
            }
        }
    }

    /**
     * Reiner Übernahme-Schritt für eine Geschwister-Instanz - siehe PropagateDismiss().
     * Eigener Methodenname statt AdoptDismissState() aus demselben Grund wie
     * AckConfiguratorPurposeIntro(): geteilter Prefix "TESSIE" mit TessieVehicle.
     */
    public function AdoptConfiguratorDismissState(string $what, string $value): void
    {
        switch ($what) {
            case 'PurposeIntro':
                $this->WriteAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE, true);
                $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
                break;
            case 'ForumHint':
                $this->WriteAttributeBoolean(self::ATTR_FORUM_HINT_GONE, true);
                $this->UpdateFormField('ForumHintPanel', 'visible', false);
                break;
        }
    }

    /** Für Geschwister-Instanzen, die beim erstmaligen Kontakt den Ausblenden-Stand übernehmen wollen. */
    public function GetConfiguratorDismissState(): array
    {
        return [
            'purposeIntroGone' => $this->ReadAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE),
            'forumHintGone'    => $this->ReadAttributeBoolean(self::ATTR_FORUM_HINT_GONE),
        ];
    }

    /**
     * Gegenrichtung zu PropagateDismiss(): eine neu angelegte Instanz sieht beim ersten
     * ApplyChanges() bei einer beliebigen Geschwister-Instanz nach und übernimmt deren Stand.
     */
    private function AdoptDismissFromSibling(): void
    {
        if ($this->ReadAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE) && $this->ReadAttributeBoolean(self::ATTR_FORUM_HINT_GONE)) {
            return;
        }
        foreach (IPS_GetInstanceListByModuleID(self::SELF_MODULE_ID) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                $state = TESSIE_GetConfiguratorDismissState($sib);
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_array($state)) {
                continue;
            }
            if (!$this->ReadAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE) && !empty($state['purposeIntroGone'])) {
                $this->WriteAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE, true);
            }
            if (!$this->ReadAttributeBoolean(self::ATTR_FORUM_HINT_GONE) && !empty($state['forumHintGone'])) {
                $this->WriteAttributeBoolean(self::ATTR_FORUM_HINT_GONE, true);
            }
            break;
        }
    }

    /**
     * Symcon-Forum-Hinweis – einmalig dismissible, kein Versionsbezug (Formular-Konvention
     * Punkt 4, SUITE.md, Referenz MeterHub).
     */
    private function forumHint(): ?array
    {
        if ($this->ReadAttributeBoolean(self::ATTR_FORUM_HINT_GONE)) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback im Symcon-Forum',
            'items' => [
                ['type' => 'Label', 'caption' => 'Tessie ist Beta – Rückmeldungen, Ideen und Erfahrungsberichte sind ausdrücklich willkommen im Community-Thread.'],
                ['type' => 'Button', 'caption' => 'Zum Forums-Thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'TESSIE_AckConfiguratorForumHint($id);'],
            ],
        ];
    }

    /**
     * Eigener Methodenname statt AckForumHint() - geteilter Prefix "TESSIE" mit TessieVehicle,
     * siehe AckConfiguratorPurposeIntro().
     */
    public function AckConfiguratorForumHint(): void
    {
        $this->WriteAttributeBoolean(self::ATTR_FORUM_HINT_GONE, true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
        $this->PropagateDismiss('ForumHint');
    }

    /**
     * "Über dieses Modul" – Lizenz-/Spenden-Hinweis, ganz unten NACH dem Forum-Hinweis.
     * Bewusst NICHT dismissible (Formular-Konvention Punkt 5, SUITE.md). Wortlaut
     * verbundweit identisch ("Variante A").
     */
    private function licenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    public function GetConfigurationForm()
    {
        $token = $this->getToken();
        $v = $this->moduleVersion();
        $dokuCaption = '📖 Dokumentation & Hilfe' . ($v !== '' ? ' (Modulversion ' . $v . ')' : '');

        // Fahrzeuge zuerst abfragen (Konto-Discovery), damit die Kopfzeile darunter
        // die aktuelle Trefferzahl kennt - siehe Verbund-Konvention "Einheitliche
        // Verbund-Status-Kopfzeile" (SUITE.md, 20.08.2026).
        $values = $this->discoverVehicles($token);

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
                    ['type' => 'Label', 'caption' => 'Ausführliche Dokumentation: github.com/DG65/NRGTessie. Das Modul ist ein privates Community-Projekt und steht in keiner Verbindung zu Tesla, Inc. oder Tessie.']
                ]
            ],
            ['type' => 'Label', 'label' => 'Tessie Konfigurator – Fahrzeuge'],
            // Eigenschaftsname 'Token' bleibt unverändert (Code-Bezeichner), nur die Beschriftung ist deutsch
            ['type' => 'PasswordTextBox', 'name' => 'Token', 'caption' => 'Tessie-Zugangsschlüssel (bei Tessie \'Access Token\'; gilt für Abfragen und Telemetrie) – leer lassen, um den bestehenden Schlüssel zu behalten'],
            ['type' => 'Button', 'caption' => '🔎 Fahrzeuge jetzt suchen', 'onClick' => 'TESSIE_RefreshVehicles($id);'],
            ['type' => 'Label', 'name' => 'DiscoverySummary', 'caption' => $this->getDiscoverySummaryLine(count($values))]
        ];

        // „Wozu dieses Modul?" ganz vorn.
        $purposeIntro = $this->purposeIntro();
        if ($purposeIntro !== null) {
            array_unshift($elements, $purposeIntro);
        }

        // Symcon-Forum-Hinweis (dismissible) und Lizenz-/Spenden-Hinweis (dauerhaft) ganz unten.
        $forumHint = $this->forumHint();
        if ($forumHint !== null) {
            $elements[] = $forumHint;
        }
        $elements[] = $this->licenseHint();

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
        return trim((string)$this->ReadAttributeString(self::ATTR_TOKEN));
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
