<?php

declare(strict_types=1);

/**
 * TessieVehicleTile
 *
 * Eigenständige HTML-SDK-Kachel für die Tile-Visualisierung. Liest die Variablen einer
 * TessieVehicle-Instanz (Quelle) und stellt sie als randlose, frei gestaltbare Status-Kachel dar.
 * Bedien-Buttons (Verriegeln, Klima, Laden) werden an die Aktions-Variablen der Quelle weitergereicht.
 *
 * Bewusst von der Datenlogik getrennt (Vorbild da8ter / TibberGridRewardTile): Ein Problem in der
 * Kachel kann die WebSocket-/Datenverbindung der Quell-Instanz nicht beeinträchtigen.
 */
class TessieVehicleTile extends IPSModule
{
    // GUID des Datenmoduls TessieVehicle (für die Quellen-Auswahl)
    private const SOURCE_MODULE = '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}';
    // Eigene GUID (module.json "id") - für die Geschwister-Instanz-Synchronisierung des
    // Ausblenden-Zustands (siehe PropagateDismiss()).
    private const SELF_MODULE_ID = '{ACAFF26A-C6AB-4D45-B51B-3832BE5C2CFA}';

    // Immer beobachtete Quell-Variablen (Telemetrie/Status); die Aktions-Idents der
    // konfigurierten Buttons kommen in getWatchIdents() dynamisch dazu.
    private const BASE_WATCH_IDENTS = [
        'act_charge_limit',
        'stat_tel_Soc', 'stat_tel_RatedRange', 'stat_tel_InsideTemp', 'stat_tel_OutsideTemp',
        'stat_ac_charging_power', 'stat_charge_amps_actual', 'stat_charge_amps_max',
        'stat_tel_TimeToFullCharge', 'stat_tel_Location_lat', 'stat_tel_Location_lon',
        'stat_location_name'
    ];

    // Katalog der in der Kachel wählbaren Buttons: Ident der Aktions-Variable in der
    // TessieVehicle-Quelle => Anzeigename (Formular) + Verhalten.
    // kind 'lock'/'climate'/'charge': historische Spezial-Beschriftung (Rückwärtskompatibilität);
    // 'toggle': Ein/Aus-Variable, Beschriftung "<Name> einschalten/ausschalten";
    // 'momentary': löst nur aus, kein dauerhafter Zustand (Modul setzt selbst auf false zurück).
    private const BUTTON_CATALOG = [
        'act_locked'            => ['name' => 'Verriegelung',                'kind' => 'lock'],
        'act_climate'           => ['name' => 'Klimaanlage',                 'kind' => 'climate'],
        'act_charging'          => ['name' => 'Laden',                       'kind' => 'charge'],
        'act_sentry'            => ['name' => 'Wächtermodus',                'kind' => 'toggle'],
        'act_valet'             => ['name' => 'Valet-Modus',                 'kind' => 'toggle'],
        'act_defrost'           => ['name' => 'Max. Entfrosten',             'kind' => 'toggle'],
        'act_steering_wheel'    => ['name' => 'Lenkradheizung',              'kind' => 'toggle'],
        'act_cop_enabled'       => ['name' => 'Innenraum-Überhitzeschutz',   'kind' => 'toggle'],
        'act_cop_fan_only'      => ['name' => 'Überhitzeschutz: nur Lüfter', 'kind' => 'toggle'],
        'act_bio_defense'       => ['name' => 'Biowaffen-Schutzmodus',       'kind' => 'toggle'],
        'act_homelink'          => ['name' => 'HomeLink auslösen',           'kind' => 'momentary'],
        'act_front_trunk'       => ['name' => 'Vorderer Kofferraum öffnen',  'kind' => 'momentary'],
        'act_rear_trunk'        => ['name' => 'Heckklappe öffnen/schließen', 'kind' => 'momentary'],
        'act_flash'             => ['name' => 'Lichthupe',                   'kind' => 'momentary'],
        'act_honk'              => ['name' => 'Hupe',                       'kind' => 'momentary'],
        'act_open_charge_port'  => ['name' => 'Ladeport öffnen',            'kind' => 'momentary'],
        'act_close_charge_port' => ['name' => 'Ladeport schließen',         'kind' => 'momentary'],
        'act_vent_windows'      => ['name' => 'Fenster lüften',             'kind' => 'momentary'],
        'act_close_windows'     => ['name' => 'Fenster schließen',          'kind' => 'momentary']
    ];

    // Default-Belegung der Buttons-Liste: identisch zu den bisher fest verdrahteten
    // drei Buttons, damit bestehende Kacheln nach dem Update unverändert aussehen.
    private const DEFAULT_BUTTONS = '[{"Ident":"act_locked","Label":""},{"Ident":"act_climate","Label":""},{"Ident":"act_charging","Label":""}]';

    // Standardwerte (auch für „Zurücksetzen")
    private const DEF_CHARGING = 0x27D07F;
    private const DEF_READY     = 0x2BB3C0;
    private const DEF_IDLE      = 0x7A8A99;
    private const DEF_BACKGROUND = -1;
    private const DEF_BOX        = -1;
    private const DEF_TEXT       = -1;
    private const DEF_TEXTMUTED  = -1;
    private const DEF_FONT       = 'system';
    private const DEF_SCALE      = 1.0;

    private const ATTR_SEEN_NEWS = 'SeenNews';
    private const ATTR_PURPOSE_INTRO_GONE = 'PurposeIntroGone';

    // „Was ist neu"-Banner: Versionsnummer, bis zu der die Neuigkeiten hier zusammengefasst sind.
    // Beim nächsten kuratierten Update hochzählen und NEWS_ITEMS ersetzen.
    private const NEWS_VERSION = '2.32.0';
    private const NEWS_ITEMS = [
        'Hast du mehrere Fahrzeug-Kacheln: „Wozu dieses Modul?" und „Was ist neu?" musst du nur noch an einer Instanz wegklicken, nicht an jeder einzeln.',
        '👋 Neue Zweck-Einführung ganz oben im Formular: kurz erklärt, was diese Kachel zeigt und wozu sie gut ist.',
        'Wenn→Dann-Regeln der Quelle direkt hier anlegen, bearbeiten und löschen – inklusive mehrerer UND-Bedingungen.',
        'Standorte (Geofence) der Quelle direkt hier verwalten, mit eigenem Icon je Standort.',
        'Bedien-Schaltflächen: Anzahl, Reihenfolge und Beschriftung selbst wählen (Stift-Symbol neben „Schaltflächen").',
        'Vergleichswert einer Regel erscheint als Auswahlliste mit Klartext, wenn der Datenpunkt feste Werte hat.'
    ];

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('SourceInstance', 0);
        $this->RegisterPropertyInteger('ColorCharging', self::DEF_CHARGING);
        $this->RegisterPropertyInteger('ColorReady', self::DEF_READY);
        $this->RegisterPropertyInteger('ColorIdle', self::DEF_IDLE);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyInteger('ColorBox', self::DEF_BOX);
        $this->RegisterPropertyInteger('ColorText', self::DEF_TEXT);
        $this->RegisterPropertyInteger('ColorTextMuted', self::DEF_TEXTMUTED);
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);
        $this->RegisterPropertyFloat('FontScale', self::DEF_SCALE);
        $this->RegisterPropertyBoolean('ShowControls', true);
        $this->RegisterPropertyString('Buttons', self::DEFAULT_BUTTONS);
        $this->RegisterPropertyBoolean('ShowAutomations', true);
        $this->RegisterPropertyBoolean('AdoptVehicleName', true);
        $this->RegisterAttributeString(self::ATTR_SEEN_NEWS, '');
        $this->RegisterAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE, false);

        $this->SetVisualizationType(1);
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        // Vor allem anderen: eine neu angelegte Instanz übernimmt den Ausblenden-Stand
        // (Wozu/News) einer Geschwister-Instanz, statt bereits Bestätigtes erneut zu zeigen.
        $this->AdoptDismissFromSibling();

        $this->SetVisualizationType(1);

        // Bisherige VM_UPDATE-Registrierungen lösen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $msg) {
                if ($msg === VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }

        // Auf Änderungen der Quell-Variablen lauschen, damit die Kachel sich aktualisiert
        $src = $this->ResolveSource();
        if ($src > 0 && IPS_InstanceExists($src)) {
            foreach ($this->getWatchIdents() as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $src);
                if ($vid !== false && $vid > 0) {
                    $this->RegisterReference($vid);
                    $this->RegisterMessage($vid, VM_UPDATE);
                }
            }
            $this->SetStatus(102);

            // Optional: Kachel-Instanz nach dem verbundenen Fahrzeug benennen
            if ($this->ReadPropertyBoolean('AdoptVehicleName')) {
                $vehicleName = IPS_GetName($src);
                if ($vehicleName !== '' && IPS_GetName($this->InstanceID) !== $vehicleName) {
                    IPS_SetName($this->InstanceID, $vehicleName);
                }
            }
        } else {
            $this->SetStatus(104);
        }

        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE) {
            return;
        }
        // Store-Review-Konvention 9c: waehrend eines Kernel-Reloads (z.B. laufendes
        // Modul-Update) koennen ReadPropertyXXX()-Aufrufe kurzzeitig false statt des
        // erwarteten Typs liefern - mit strict_types=1 wuerde das zu einem TypeError
        // fuehren, der MessageSink() abreissen laesst.
        if (IPS_GetKernelRunlevel() !== KR_READY || !IPS_InstanceExists($this->InstanceID)) {
            return;
        }
        try {
            $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
        } catch (Throwable $e) {
            // ignorieren - Instanz laedt gerade neu
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            $form = ['elements' => [], 'actions' => [], 'status' => []];
        }

        // Versionsnummer gehört ins Doku-Panel (nicht ins Neu-Banner).
        foreach ($form['elements'] as &$element) {
            if (is_array($element) && ($element['type'] ?? '') === 'ExpansionPanel' && strpos((string)($element['caption'] ?? ''), '📖') === 0) {
                $v = $this->moduleVersion();
                if ($v !== '') {
                    $element['caption'] = '📖 Dokumentation & Hilfe (Modulversion ' . $v . ')';
                }
                break;
            }
        }
        unset($element);

        // „Was ist neu"-Banner nach einem Update ganz oben.
        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        // „Wozu dieses Modul?" ganz vorn, noch vor dem News-Banner.
        $purposeIntro = $this->purposeIntro();
        if ($purposeIntro !== null) {
            array_unshift($form['elements'], $purposeIntro);
        }

        return json_encode($form);
    }

    /** Modulversion aus library.json (Repo-Wurzel), leer wenn nicht lesbar. */
    private function moduleVersion(): string
    {
        $raw = @file_get_contents(__DIR__ . '/../library.json');
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) ? (string)($d['version'] ?? '') : '';
    }

    /**
     * "Wozu dieses Modul?" – ganz vorn, noch vor dem News-Banner (SUITE.md Formular-Konvention
     * Punkt 0). Anders als das News-Panel einmalig dismissible (kein Versionsbezug): der Zweck
     * eines Moduls ändert sich nicht mit jedem Release.
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
                ['type' => 'Label', 'caption' => 'Diese Kachel zeigt Ladestand, Reichweite, Temperaturen und Fahrzeugstatus deines Tesla auf einen Blick – inklusive Bedien-Buttons für Verriegelung, Klima und mehr, direkt in der Kacheln-Visualisierung.'],
                ['type' => 'Label', 'caption' => 'Der Nutzen: eine kompakte, optisch anpassbare Übersicht fürs WebFront, ohne selbst Variablen zusammenstellen zu müssen – die Datenquelle wird automatisch erkannt.'],
                ['type' => 'Label', 'caption' => 'Die eigentliche Datenverbindung zum Fahrzeug liefert die Instanz TessieVehicle; die Konfiguration/Fahrzeugsuche läuft über TessieConfigurator.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'TESSIETILE_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE, true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
        $this->PropagateDismiss('PurposeIntro');
    }

    /**
     * „Was ist neu"-Banner: erscheint nach einem Update (Attribut startet leer),
     * bis der Nutzer „Verstanden" klickt. Eine Neuinstallation sieht es einmalig.
     */
    private function newsBanner(): ?array
    {
        if ($this->ReadAttributeString(self::ATTR_SEEN_NEWS) === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => 'Neu seit dem letzten Store-Stand – bitte kurz ansehen:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'TESSIETILE_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::NEWS_VERSION, 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString(self::ATTR_SEEN_NEWS, self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
        $this->PropagateDismiss('News', self::NEWS_VERSION);
    }

    /**
     * Ausblenden von "Wozu dieses Modul?"/"Was ist Neu?" über alle Geschwister-Instanzen
     * dieses Moduls teilen (SUITE.md "Ausblenden über mehrere Instanzen desselben Moduls
     * teilen", 14.09.2026, Referenz MeterHub). Ruft bei jeder Geschwister-Instanz NUR den
     * reinen Übernahme-Schritt auf (AdoptDismissState), nicht erneut die volle Ack-Methode -
     * dadurch kein Ping-Pong möglich, ganz ohne Prozessmerker.
     */
    private function PropagateDismiss(string $what, string $value = ''): void
    {
        foreach (IPS_GetInstanceListByModuleID(self::SELF_MODULE_ID) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                TESSIETILE_AdoptDismissState($sib, $what, $value);
            } catch (\Throwable $e) {
                // Eine Geschwister-Instanz mitten im Reload/Löschen darf das Ausblenden der
                // aufrufenden Instanz nicht mitreißen - @ hält Fatals nicht auf.
            }
        }
    }

    /** Reiner Übernahme-Schritt für eine Geschwister-Instanz - siehe PropagateDismiss(). */
    public function AdoptDismissState(string $what, string $value): void
    {
        switch ($what) {
            case 'PurposeIntro':
                $this->WriteAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE, true);
                $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
                break;
            case 'News':
                $this->WriteAttributeString(self::ATTR_SEEN_NEWS, $value);
                $this->UpdateFormField('NewsPanel', 'visible', false);
                break;
        }
    }

    /** Für Geschwister-Instanzen, die beim erstmaligen Kontakt den Ausblenden-Stand übernehmen wollen. */
    public function GetDismissState(): array
    {
        return [
            'purposeIntroGone' => $this->ReadAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE),
            'seenNews'         => $this->ReadAttributeString(self::ATTR_SEEN_NEWS),
        ];
    }

    /**
     * Gegenrichtung zu PropagateDismiss(): eine neu angelegte Instanz sieht beim ersten
     * ApplyChanges() bei einer beliebigen Geschwister-Instanz nach und übernimmt deren Stand.
     * Zieht nur vor (false→true, ältere→neuere News-Version), überschreibt nie einen schon
     * weiter fortgeschrittenen eigenen Stand.
     */
    private function AdoptDismissFromSibling(): void
    {
        if ($this->ReadAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE)
            && $this->ReadAttributeString(self::ATTR_SEEN_NEWS) === self::NEWS_VERSION) {
            return;
        }
        foreach (IPS_GetInstanceListByModuleID(self::SELF_MODULE_ID) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                $state = TESSIETILE_GetDismissState($sib);
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_array($state)) {
                continue;
            }
            if (!$this->ReadAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE) && !empty($state['purposeIntroGone'])) {
                $this->WriteAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE, true);
            }
            if ($this->ReadAttributeString(self::ATTR_SEEN_NEWS) !== self::NEWS_VERSION && ($state['seenNews'] ?? '') === self::NEWS_VERSION) {
                $this->WriteAttributeString(self::ATTR_SEEN_NEWS, self::NEWS_VERSION);
            }
            break;
        }
    }

    /**
     * Aktion aus der Kachel: an die entsprechende Aktions-Variable der Quell-Instanz weiterreichen.
     */
    public function RequestAction($Ident, $Value)
    {
        $src = $this->ResolveSource();
        if ($src <= 0) {
            return;
        }

        // Automations-Verwaltung aus der Kachel (Regeln der Quelle)
        if ($Ident === 'rule') {
            $data = json_decode((string)$Value, true);
            if (is_array($data) && isset($data['i'])) {
                @TESSIE_SetDataActionActive($src, (int)$data['i'], (bool)($data['on'] ?? false));
                $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
            }
            return;
        }
        if ($Ident === 'ruleEditor') {
            // Editor-Daten (Datenpunkte + schaltbare Zielvariablen) an die Kachel schicken
            $editor = json_decode((string)@TESSIE_GetDataActionEditor($src), true);
            $this->UpdateVisualizationValue(json_encode(['editor' => is_array($editor) ? $editor : ['sources' => [], 'targets' => []]]));
            return;
        }
        if ($Ident === 'targetOpts') {
            // Auswählbare Werte (Profil/Presentation) der gewählten Zielvariable
            $vid = (int)$Value;
            $opts = json_decode((string)@TESSIE_GetTargetValueOptions($src, $vid), true);
            $this->UpdateVisualizationValue(json_encode(['targetOpts' => ['vid' => $vid, 'options' => is_array($opts) ? $opts : []]]));
            return;
        }
        if ($Ident === 'condOpts') {
            // Profilwerte des gewählten Wenn-Datenpunkts (z. B. Sitzheizung, Klimahaltung)
            // für den Vergleichswert-Dropdown im Regel-Editor; leer = freie Eingabe
            $source = (string)$Value;
            $vid = ($source !== '') ? @IPS_GetObjectIDByIdent($source, $src) : false;
            $opts = ($vid !== false && $vid > 0) ? json_decode((string)@TESSIE_GetTargetValueOptions($src, $vid), true) : [];
            $this->UpdateVisualizationValue(json_encode(['condOpts' => ['source' => $source, 'options' => is_array($opts) ? $opts : []]]));
            return;
        }
        if ($Ident === 'ruleSave') {
            $data = json_decode((string)$Value, true);
            if (is_array($data) && isset($data['rule'])) {
                @TESSIE_SetDataAction($src, (int)($data['i'] ?? -1), json_encode($data['rule']));
                $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
            }
            return;
        }
        if ($Ident === 'ruleDelete') {
            @TESSIE_DeleteDataAction($src, (int)$Value);
            $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
            return;
        }

        // Standort-Verwaltung aus der Kachel (Geofences der Quelle)
        if ($Ident === 'geoEnable') {
            @TESSIE_SetGeofenceEnabled($src, (bool)$Value);
            $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
            return;
        }
        if ($Ident === 'homeSave') {
            @TESSIE_SetHomeGeofence($src, (string)$Value);
            $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
            return;
        }
        if ($Ident === 'fenceSave') {
            $data = json_decode((string)$Value, true);
            if (is_array($data) && isset($data['fence'])) {
                @TESSIE_SetGeofence($src, (int)($data['i'] ?? -1), json_encode($data['fence']));
                $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
            }
            return;
        }
        if ($Ident === 'fenceDelete') {
            @TESSIE_DeleteGeofence($src, (int)$Value);
            $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
            return;
        }

        // Button-Verwaltung aus der Kachel: eigene Property, kein Aufruf über die Quelle
        if ($Ident === 'btnEditor') {
            $catalog = json_decode((string)$this->GetButtonCatalog(), true);
            $this->UpdateVisualizationValue(json_encode(['btnCatalog' => is_array($catalog) ? $catalog : []]));
            return;
        }
        if ($Ident === 'btnSave') {
            $data = json_decode((string)$Value, true);
            if (is_array($data) && isset($data['button'])) {
                $this->SetButtonConfig((int)($data['i'] ?? -1), json_encode($data['button']));
                $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
            }
            return;
        }
        if ($Ident === 'btnDelete') {
            $this->DeleteButtonConfig((int)$Value);
            $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
            return;
        }
        if ($Ident === 'btnMove') {
            $data = json_decode((string)$Value, true);
            if (is_array($data) && isset($data['idx'], $data['dir'])) {
                $this->MoveButtonConfig((int)$data['idx'], (string)$data['dir']);
                $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
            }
            return;
        }

        // Konfigurierbare Buttons: Ident ist die Aktions-Variable der Quelle selbst
        // (aus BUTTON_CATALOG, siehe getConfiguredButtons/buildButtonPayload)
        if (!isset(self::BUTTON_CATALOG[$Ident])) {
            return;
        }
        $vid = @IPS_GetObjectIDByIdent($Ident, $src);
        if ($vid > 0) {
            @RequestAction($vid, $Value); // globale IPS-Funktion -> löst die Aktion der Quelle aus
        }
    }

    /**
     * Button-Aktion: alle Farben und Schrifteinstellungen auf Standard zurücksetzen.
     */
    public function ResetStyle(): void
    {
        // Nur die offene Konfiguration setzen; der Nutzer bestätigt selbst mit
        // „Änderungen übernehmen" (vom Symcon-Review empfohlenes Muster).
        $this->UpdateFormField('ColorCharging', 'value', self::DEF_CHARGING);
        $this->UpdateFormField('ColorReady', 'value', self::DEF_READY);
        $this->UpdateFormField('ColorIdle', 'value', self::DEF_IDLE);
        $this->UpdateFormField('ColorBackground', 'value', self::DEF_BACKGROUND);
        $this->UpdateFormField('ColorBox', 'value', self::DEF_BOX);
        $this->UpdateFormField('ColorText', 'value', self::DEF_TEXT);
        $this->UpdateFormField('ColorTextMuted', 'value', self::DEF_TEXTMUTED);
        $this->UpdateFormField('FontFamily', 'value', self::DEF_FONT);
        $this->UpdateFormField('FontScale', 'value', self::DEF_SCALE);
    }

    public function GetVisualizationTile()
    {
        $module = file_get_contents(__DIR__ . '/module.html');
        // handleMessage() ist erst im HTML definiert -> initialen Aufruf ans Ende hängen.
        $module .= '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');</script>';
        return $module;
    }

    // ---------------------------------------------------------------------
    // Datenaufbereitung
    // ---------------------------------------------------------------------

    private function GetFullUpdateMessage(): string
    {
        $style = [
            'bg'        => $this->ColorOrEmpty((int)$this->ReadPropertyInteger('ColorBackground')),
            'box'       => $this->ColorOrEmpty((int)$this->ReadPropertyInteger('ColorBox')),
            'text'      => $this->ColorOrEmpty((int)$this->ReadPropertyInteger('ColorText')),
            'textmuted' => $this->ColorOrEmpty((int)$this->ReadPropertyInteger('ColorTextMuted')),
            'font'      => $this->FontStack((string)$this->ReadPropertyString('FontFamily')),
            'scale'     => $this->FontScaleValue(),
            'controls'  => $this->ReadPropertyBoolean('ShowControls')
        ];
        $showButtons = $this->ReadPropertyBoolean('ShowControls');

        $cCharging = $this->ColorHex((int)$this->ReadPropertyInteger('ColorCharging'), '#27d07f');
        $cReady    = $this->ColorHex((int)$this->ReadPropertyInteger('ColorReady'), '#2bb3c0');
        $cIdle     = $this->ColorHex((int)$this->ReadPropertyInteger('ColorIdle'), '#7a8a99');

        $src = $this->ResolveSource();
        if ($src <= 0 || !IPS_InstanceExists($src)) {
            return json_encode(array_merge($style, [
                'name'       => 'Tesla',
                'stateLabel' => 'Keine Datenquelle',
                'accent'     => $cIdle,
                'cls'        => 'idle',
                'controls'   => false,
                'buttons'    => []
            ]));
        }

        $charging = $this->ReadSourceBool($src, 'act_charging');
        $accent = $charging ? $cCharging : $cReady;

        return json_encode(array_merge($style, [
            'name'        => IPS_GetName($src),
            'accent'      => $accent,
            'cls'         => $charging ? 'live' : 'idle',
            'stateLabel'  => $charging ? 'Lädt' : 'Bereit',
            'locked'      => $this->ReadSourceBool($src, 'act_locked'),
            'climate'     => $this->ReadSourceBool($src, 'act_climate'),
            'charging'    => $charging,
            'soc'         => $this->ReadSourceValue($src, 'stat_tel_Soc'),
            'range'       => $this->ReadSourceValue($src, 'stat_tel_RatedRange'),
            'insideTemp'  => $this->ReadSourceValue($src, 'stat_tel_InsideTemp'),
            'outsideTemp' => $this->ReadSourceValue($src, 'stat_tel_OutsideTemp'),
            'acPower'     => $this->ReadSourceValue($src, 'stat_ac_charging_power'),
            'ampsActual'  => $this->ReadSourceValue($src, 'stat_charge_amps_actual'),
            'ampsMax'     => $this->ReadSourceValue($src, 'stat_charge_amps_max'),
            'chargeLimit' => $this->ReadSourceValue($src, 'act_charge_limit'),
            'timeToFull'  => $this->ReadSourceValue($src, 'stat_tel_TimeToFullCharge'),
            'lat'         => $this->ReadSourceValue($src, 'stat_tel_Location_lat'),
            'lon'         => $this->ReadSourceValue($src, 'stat_tel_Location_lon'),
            'location'    => $this->ReadSourceValue($src, 'stat_location_name'),
            'rules'       => $this->ReadSourceRules($src),
            'geo'         => $this->ReadSourceGeo($src),
            'buttons'     => $showButtons ? $this->buildButtonPayload($src) : []
        ]));
    }

    private function ResolveSource(): int
    {
        $configured = (int)$this->ReadPropertyInteger('SourceInstance');
        if ($configured > 0 && IPS_InstanceExists($configured)) {
            return $configured;
        }
        $list = IPS_GetInstanceListByModuleID(self::SOURCE_MODULE);
        if (count($list) === 1) {
            return (int) $list[0];
        }
        return 0;
    }

    /**
     * Wenn->Dann-Regeln der Quelle für die Kachel ([{i,text,active,rule}] oder null).
     * Leeres Array = Automationen aktiv, aber noch keine Regel (Kachel zeigt dann
     * nur den "+ Neue Regel"-Knopf).
     */
    private function ReadSourceRules(int $instanceID): ?array
    {
        if (!$this->ReadPropertyBoolean('ShowAutomations')) {
            return null;
        }
        $json = @TESSIE_GetDataActions($instanceID);
        $rules = is_string($json) ? json_decode($json, true) : null;
        return is_array($rules) ? $rules : null;
    }

    /** Standort-Konfiguration der Quelle für die Kachel (oder null, wenn ausgeblendet). */
    private function ReadSourceGeo(int $instanceID): ?array
    {
        if (!$this->ReadPropertyBoolean('ShowAutomations')) {
            return null;
        }
        $json = @TESSIE_GetGeofenceConfig($instanceID);
        $geo = is_string($json) ? json_decode($json, true) : null;
        return is_array($geo) ? $geo : null;
    }

    /** Alle für die Beobachtung relevanten Idents: Basiswerte + konfigurierte Button-Aktionen. */
    private function getWatchIdents(): array
    {
        $idents = self::BASE_WATCH_IDENTS;
        foreach ($this->getConfiguredButtons() as $btn) {
            $idents[] = $btn['ident'];
        }
        return array_values(array_unique($idents));
    }

    /** Rohe Buttons-Liste (Property), unabhängig von Gültigkeit – für Verwaltung (Speichern/Löschen/Verschieben). */
    private function getButtonRowsRaw(): array
    {
        $raw = json_decode((string)$this->ReadPropertyString('Buttons'), true);
        return is_array($raw) ? array_values($raw) : [];
    }

    /**
     * Konfigurierte Buttons aus der Property 'Buttons', auf bekannte Katalog-Idents gefiltert.
     * 'idx' ist die Position in der rohen Property-Liste (für Bearbeiten/Löschen/Verschieben aus der Kachel).
     */
    private function getConfiguredButtons(): array
    {
        $out = [];
        foreach ($this->getButtonRowsRaw() as $idx => $row) {
            if (!is_array($row)) continue;
            $ident = (string)($row['Ident'] ?? '');
            if (!isset(self::BUTTON_CATALOG[$ident])) continue;
            $out[] = ['idx' => $idx, 'ident' => $ident, 'label' => trim((string)($row['Label'] ?? ''))];
        }
        return $out;
    }

    /**
     * Button-Payload für die Kachel: [{i:Ident, c:Beschriftung, on:hervorgehoben, v:zu sendender Wert}, ...]
     * Buttons für (noch) nicht vorhandene Variablen (Datenpunkt deaktiviert/nicht empfangen) entfallen.
     */
    private function buildButtonPayload(int $src): array
    {
        $out = [];
        foreach ($this->getConfiguredButtons() as $btn) {
            $ident = $btn['ident'];
            $cat = self::BUTTON_CATALOG[$ident];
            $vid = @IPS_GetObjectIDByIdent($ident, $src);
            if ($vid <= 0) {
                continue;
            }
            $state = (bool)GetValue($vid);
            $custom = $btn['label'];

            switch ($cat['kind']) {
                case 'lock':
                    $caption = ($custom !== '') ? $custom : ($state ? 'Entriegeln' : 'Verriegeln');
                    $on = false; // historisch: Schloss-Button hebt sich farblich nicht ab
                    $value = !$state;
                    break;
                case 'climate':
                    $caption = ($custom !== '') ? $custom : ($state ? 'Klima aus' : 'Klima ein');
                    $on = $state;
                    $value = !$state;
                    break;
                case 'charge':
                    $caption = ($custom !== '') ? $custom : ($state ? 'Laden stop' : 'Laden start');
                    $on = $state;
                    $value = !$state;
                    break;
                case 'toggle':
                    $caption = ($custom !== '') ? $custom : ($cat['name'] . ($state ? ' ausschalten' : ' einschalten'));
                    $on = $state;
                    $value = !$state;
                    break;
                case 'momentary':
                default:
                    $caption = ($custom !== '') ? $custom : $cat['name'];
                    $on = false;
                    $value = true;
                    break;
            }

            $out[] = ['i' => $ident, 'idx' => $btn['idx'], 'n' => $cat['name'], 'label' => $custom, 'c' => $caption, 'on' => $on, 'v' => $value];
        }
        return $out;
    }

    /** Katalog aller wählbaren Button-Funktionen für den Kachel-Editor: [{v:Ident, c:Name}]. */
    public function GetButtonCatalog(): string
    {
        $out = [];
        foreach (self::BUTTON_CATALOG as $ident => $cat) {
            $out[] = ['v' => $ident, 'c' => $cat['name']];
        }
        return json_encode($out);
    }

    /**
     * Legt einen Button an oder überschreibt ihn ($Index < 0 = anhängen).
     * $JSON: {Ident, Label}. Eigene Property der Kachel – kein Aufruf über die Quelle nötig.
     */
    public function SetButtonConfig(int $Index, string $JSON): void
    {
        $in = json_decode($JSON, true);
        if (!is_array($in)) {
            return;
        }
        $ident = (string)($in['Ident'] ?? '');
        if (!isset(self::BUTTON_CATALOG[$ident])) {
            return;
        }
        $row = ['Ident' => $ident, 'Label' => trim((string)($in['Label'] ?? ''))];

        $rows = $this->getButtonRowsRaw();
        if ($Index >= 0 && isset($rows[$Index])) {
            $rows[$Index] = $row;
        } else {
            $rows[] = $row;
        }
        IPS_SetProperty($this->InstanceID, 'Buttons', json_encode(array_values($rows)));
        IPS_ApplyChanges($this->InstanceID);
    }

    /** Löscht einen Button (z. B. aus der Kachel). */
    public function DeleteButtonConfig(int $Index): void
    {
        $rows = $this->getButtonRowsRaw();
        if (!isset($rows[$Index])) {
            return;
        }
        unset($rows[$Index]);
        IPS_SetProperty($this->InstanceID, 'Buttons', json_encode(array_values($rows)));
        IPS_ApplyChanges($this->InstanceID);
    }

    /** Verschiebt einen Button um eine Position ('up'/'down') für die Reihenfolge. */
    public function MoveButtonConfig(int $Index, string $Direction): void
    {
        $rows = $this->getButtonRowsRaw();
        $target = $Index + (($Direction === 'up') ? -1 : 1);
        if (!isset($rows[$Index]) || !isset($rows[$target])) {
            return;
        }
        $tmp = $rows[$Index];
        $rows[$Index] = $rows[$target];
        $rows[$target] = $tmp;
        IPS_SetProperty($this->InstanceID, 'Buttons', json_encode(array_values($rows)));
        IPS_ApplyChanges($this->InstanceID);
    }

    private function ReadSourceValue(int $instanceID, string $ident)
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $instanceID);
        if ($vid === false || $vid <= 0) {
            return null;
        }
        return GetValue($vid);
    }

    private function ReadSourceBool(int $instanceID, string $ident): ?bool
    {
        $v = $this->ReadSourceValue($instanceID, $ident);
        return $v === null ? null : (bool) $v;
    }

    private function FontStack(string $key): string
    {
        switch ($key) {
            case 'arial':     return 'Arial, Helvetica, sans-serif';
            case 'verdana':   return 'Verdana, Geneva, sans-serif';
            case 'tahoma':    return 'Tahoma, Geneva, sans-serif';
            case 'trebuchet': return '"Trebuchet MS", Helvetica, sans-serif';
            case 'georgia':   return 'Georgia, "Times New Roman", serif';
            case 'courier':   return '"Courier New", Courier, monospace';
            case 'system':
            default:          return "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
        }
    }

    private function FontScaleValue(): float
    {
        $v = (float)$this->ReadPropertyFloat('FontScale');
        if ($v < 0.5) {
            $v = 0.5;
        }
        if ($v > 2.5) {
            $v = 2.5;
        }
        return $v;
    }

    private function ColorHex(int $value, string $fallback): string
    {
        if ($value < 0) {
            return $fallback;
        }
        return sprintf('#%06X', $value & 0xFFFFFF);
    }

    private function ColorOrEmpty(int $value): string
    {
        return $value < 0 ? '' : sprintf('#%06X', $value & 0xFFFFFF);
    }
}
