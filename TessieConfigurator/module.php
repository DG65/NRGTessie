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
    // Ergebnis der letzten Schlüsselprüfung (Abfrage beim Tessie-Konto): letzter Erfolg und
    // Grund des letzten Fehlschlags (leer = letzte Prüfung war erfolgreich).
    private const ATTR_LAST_OK_TS = 'LastOkTs';
    private const ATTR_LAST_ERROR = 'LastCheckError';
    private const ATTR_PURPOSE_INTRO_GONE = 'PurposeIntroGone';
    private const ATTR_FORUM_HINT_GONE = 'ForumHintGone';
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-tessie-tesla-fahrzeuge-in-ip-symcon-steuerung-telemetrie-kachel/143995';
    private const LICENSE_URL = 'https://github.com/DG65/NRGTessie/blob/main/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    // Ergebnis des letzten apiRequest() (nur innerhalb desselben Aufrufs relevant).
    private int $lastHttpCode = 0;
    private string $lastApiError = '';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyString('TelemetryToken', '');
        $this->RegisterAttributeString(self::ATTR_TOKEN, '');
        $this->RegisterAttributeInteger(self::ATTR_LAST_DISCOVERY_TS, 0);
        $this->RegisterAttributeInteger(self::ATTR_LAST_OK_TS, 0);
        $this->RegisterAttributeString(self::ATTR_LAST_ERROR, '');
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
        $this->lastHttpCode = 0;
        $this->lastApiError = '';
        $vehicles = $this->fetchVehicles($token);
        $this->recordCheckResult();
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

    /** Hält das Ergebnis der Kontoabfrage fest: Zeitpunkt des letzten Erfolgs bzw. Grund des Fehlschlags. */
    private function recordCheckResult(): void
    {
        $code = $this->lastHttpCode;
        if ($this->lastApiError !== '') {
            $reason = $this->lastApiError;
        } elseif ($code === 401 || $code === 403) {
            $reason = sprintf($this->Translate('rejected by Tessie (HTTP %d)'), $code);
        } elseif ($code < 200 || $code >= 300) {
            $reason = sprintf($this->Translate('Tessie responded with HTTP %d'), $code);
        } else {
            $reason = '';
        }
        if ($reason === '') {
            $this->WriteAttributeInteger(self::ATTR_LAST_OK_TS, time());
        }
        $this->WriteAttributeString(self::ATTR_LAST_ERROR, $reason);
    }

    /**
     * Statuszeile zum Zugangsschlüssel (Formular-Konvention "Verbund-Verbindungen sichtbar
     * machen", SUITE.md). Das Feld bleibt nach dem Übernehmen absichtlich leer (Schlüssel liegt
     * im Attribut) - die Zeile beantwortet, ob es geklappt hat. Der Schlüssel selbst wird nie
     * angezeigt.
     */
    private function tokenStatusLine(string $token, int $vehicleCount): string
    {
        if ($token === '') {
            return $this->Translate('ℹ️ No access token saved – enter it above and press "Apply changes".');
        }
        $error = (string)$this->ReadAttributeString(self::ATTR_LAST_ERROR);
        $okTs = (int)$this->ReadAttributeInteger(self::ATTR_LAST_OK_TS);
        $last = $okTs > 0 ? sprintf($this->Translate(', last successfully checked: %s'), date($this->Translate('Y-m-d H:i:s'), $okTs)) : '';
        if ($error !== '') {
            return sprintf($this->Translate('⚠️ Access token saved, but the check against the Tessie account failed: %s%s.'), $error, $last);
        }
        if ($vehicleCount === 0) {
            return sprintf($this->Translate('⚠️ Access token saved and accepted by Tessie, but the account returns no vehicles%s.'), $last);
        }
        return sprintf($this->Translate('✅ Access token saved (not displayed for security reasons) and accepted by Tessie%s.'), $last);
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
        $this->UpdateFormField('TokenStatus', 'caption', $this->tokenStatusLine($token, count($values)));
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
            return $this->Translate('ℹ️ Not searched yet – enter the access token and apply the changes.');
        }
        $icon = $count > 0 ? '✅' : '⚠️';
        return sprintf($this->Translate('%s %d vehicle(s) found (last search at %s).'), $icon, $count, date('H:i:s', $ts));
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
            'caption' => '👋  What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'The Tessie Configurator is the starting point: enter your access token, find the vehicles of your Tessie account and create the matching TessieVehicle instance, including its telemetry connection, with a single click.'],
                ['type' => 'Label', 'caption' => 'The benefit: you set nothing up by hand – the instance, the WebSocket connection and the data points are created automatically. Once everything is set up, you only need this module again when another vehicle is added or the access token changes.'],
                ['type' => 'Label', 'caption' => 'TessieVehicle handles the actual vehicle control and display; TessieVehicleTile provides a tile view.'],
                ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'TESSIE_AckConfiguratorPurposeIntro($id);'],
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
     * Öffnen des Formulars (GetConfigurationForm) bei einer beliebigen Geschwister-Instanz nach und übernimmt deren Stand.
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
            'caption' => '💬  Feedback in the Symcon forum',
            'items' => [
                ['type' => 'Label', 'caption' => 'Tessie is in beta – feedback, ideas and field reports are very welcome in the community thread.'],
                ['type' => 'Button', 'caption' => 'To the forum thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'TESSIE_AckConfiguratorForumHint($id);'],
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
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born out of genuine enthusiasm for my own installation — and a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use needs a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Do you like the module and still want to leave something? I would be happy about a small donation — completely voluntary, nothing owed in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    public function GetConfigurationForm()
    {
        // Eine neu angelegte Instanz übernimmt den Ausblenden-Stand einer Geschwister-Instanz.
        // Hier statt in ApplyChanges(): beim Neuladen der Module (Update/Symcon-Start) sind die
        // anderen Instanzen dort evtl. noch nicht geladen; wird das Formular geöffnet, ist
        // definitiv Normalbetrieb (Store-Review-Hinweis 2.38.3).
        $this->AdoptDismissFromSibling();

        $token = $this->getToken();
        $v = $this->moduleVersion();
        $dokuCaption = $v !== ''
            ? sprintf($this->Translate('📖 Documentation & help (module version %s)'), $v)
            : '📖 Documentation & help';

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
                    ['type' => 'Label', 'caption' => '── How it works ──'],
                    ['type' => 'Label', 'caption' => 'The configurator lists all vehicles of your Tessie account and, with a click on \'Create\', sets up the matching TessieVehicle instance including its telemetry connection (WebSocket). A single access token is enough for both queries and telemetry.'],
                    ['type' => 'Label', 'caption' => '── Creating an access token ──'],
                    ['type' => 'Label', 'caption' => 'Log in at my.tessie.com, create an access token under Settings → API (Tessie\'s interface calls it \'Access Token\') and enter it here. It is stored only locally in your Symcon installation. After \'Apply changes\' the vehicles appear in the list. For security reasons the field never shows the stored value and is empty when you reopen it – an existing token is kept as long as nothing is entered; to change it, simply enter the new token.'],
                    ['type' => 'Label', 'caption' => '── More help ──'],
                    ['type' => 'Label', 'caption' => 'Detailed documentation: github.com/DG65/NRGTessie. This module is a private community project and is not affiliated with Tesla, Inc. or Tessie.']
                ]
            ],
            ['type' => 'Label', 'label' => 'Tessie Configurator – vehicles'],
            // The property name 'Token' stays unchanged (code identifier); only the caption is translated
            ['type' => 'PasswordTextBox', 'name' => 'Token', 'caption' => 'Tessie access token (used for queries and telemetry) – leave empty to keep the existing token'],
            ['type' => 'Label', 'name' => 'TokenStatus', 'caption' => $this->tokenStatusLine($token, count($values))],
            ['type' => 'Button', 'caption' => '🔎 Search for vehicles now', 'onClick' => 'TESSIE_RefreshVehicles($id);'],
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
                    'caption' => 'Vehicles',
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
                'name' => $this->Translate('Tessie telemetry') . ' ' . $vin
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

        $this->lastHttpCode = $code;
        if ($resp === false) {
            $this->SendDebug('API request', 'cURL error: ' . $err, 0);
            $this->lastApiError = $this->Translate('no connection to Tessie') . ($err !== '' ? ' (' . $err . ')' : '');
            return [];
        }

        $json = json_decode($resp, true);
        if (!is_array($json)) {
            $this->SendDebug('API request', 'HTTP ' . $code . ' – no JSON response: ' . substr($resp, 0, 500), 0);
            $this->lastApiError = sprintf($this->Translate('no valid response from Tessie (HTTP %d)'), $code);
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
