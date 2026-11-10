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
    // Grün für automatisch übernommene Werte (🔗-Statuszeilen, SUITE.md "Wert kommt automatisch"); -1 = Standardfarbe.
    private const AUTO_COLOR = 0x2E8B3D;
    // library.json "id" - für IPS_GetLibrary() in AckNews() (tatsächlich installierte Version).
    private const LIBRARY_GUID = '{A9E66B36-2E8F-4F52-9E4D-1A1D2F0E6F40}';
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
        'act_locked'            => ['name' => 'Door lock',                'kind' => 'lock'],
        'act_climate'           => ['name' => 'Climate control',                 'kind' => 'climate'],
        'act_charging'          => ['name' => 'Start/stop charging',                       'kind' => 'charge'],
        'act_sentry'            => ['name' => 'Sentry mode',                'kind' => 'toggle'],
        'act_valet'             => ['name' => 'Valet mode',                 'kind' => 'toggle'],
        'act_defrost'           => ['name' => 'Max defrost',             'kind' => 'toggle'],
        'act_steering_wheel'    => ['name' => 'Steering wheel heater',              'kind' => 'toggle'],
        'act_cop_enabled'       => ['name' => 'Cabin overheat protection',   'kind' => 'toggle'],
        'act_cop_fan_only'      => ['name' => 'Overheat protection: fan only', 'kind' => 'toggle'],
        'act_bio_defense'       => ['name' => 'Bioweapon defense mode',       'kind' => 'toggle'],
        'act_homelink'          => ['name' => 'Trigger HomeLink',           'kind' => 'momentary'],
        'act_front_trunk'       => ['name' => 'Open front trunk',  'kind' => 'momentary'],
        'act_rear_trunk'        => ['name' => 'Open/close rear trunk', 'kind' => 'momentary'],
        'act_flash'             => ['name' => 'Flash lights',                   'kind' => 'momentary'],
        'act_honk'              => ['name' => 'Honk horn',                       'kind' => 'momentary'],
        'act_open_charge_port'  => ['name' => 'Open charge port',            'kind' => 'momentary'],
        'act_close_charge_port' => ['name' => 'Close charge port',         'kind' => 'momentary'],
        'act_vent_windows'      => ['name' => 'Vent windows',             'kind' => 'momentary'],
        'act_close_windows'     => ['name' => 'Close windows',          'kind' => 'momentary']
    ];

    // Default-Belegung der Buttons-Liste: identisch zu den bisher fest verdrahteten
    // drei Buttons, damit bestehende Kacheln nach dem Update unverändert aussehen.
    private const DEFAULT_BUTTONS = '[{"Ident":"act_locked","Label":""},{"Ident":"act_climate","Label":""},{"Ident":"act_charging","Label":""}]';

    // Sichtbare Texte der Kachel (module.html): englische Quelltexte. Die Uebersetzung wird einmal mit der
    // ersten Nachricht an die Kachel geschickt (d.texts) - siehe GetFullUpdateMessage(). Der Pruefstand
    // gleicht diese Liste mit module.html und locale.json ab.
    private const KACHEL_TEXTE = [
        'Charging power', 'Charge current (actual/max)', 'Charge limit', 'Time to full', 'Open map', 'Buttons',
        '+ New button', 'Edit button', 'Function', 'Custom label (optional)', 'Cancel', 'Save', 'Edit location',
        'Name', 'Icon (optional, for the tile)', 'Latitude', 'Longitude', '📍 Use current vehicle position',
        'Radius (m)', 'Edit rule', 'If (several conditions are combined with AND)', '+ Condition (AND)',
        'Then target variable',
        'Switchable variables (with an action) are offered. Other targets can be chosen in the instance form.',
        'Action', 'Switch on', 'Switch off', 'Toggle', 'Set value', 'Value', 'Edit buttons', 'empty = automatic',
        'e.g. Work', '📍 (default)', 'e.g. 48.340874', 'e.g. 8.032679', 'Search (name or path) …', 'Home', 'Locked',
        'Unlocked', 'inside', 'outside', 'Climate', 'on', 'off', 'Map', 'Automations', '+ New rule', 'Delete rule',
        'Confirm?', 'Deactivate rule: ', 'Activate rule: ', 'Locations', '+ New location',
        'Deactivate location detection', 'Activate location detection', 'Delete location', '(system location)',
        '(no location!)', '(invalid!)', 'No buttons yet – tap "+ New button".', 'Move up', 'Move down',
        'Delete button', 'Done', 'New button', 'Edit home', 'New location',
        'Leave empty = system location from the core instance "Location". Map selection is available in the instance form.',
        'Enter coordinates or use the vehicle position. Map selection is available in the instance form.',
        'Remove condition', 'Comparison value', 'IF', 'AND', 'New rule', 'becomes ON (true)', 'becomes OFF (false)',
        '= (equals)', '≠ (not equal)', '> (greater than)', '≥ (greater than or equal to)', '< (less than)', '≤ (less than or equal to)',
        'changes', 'Drives past (location)'
    ];

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
    private const ATTR_FORUM_HINT_GONE = 'ForumHintGone';
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-tessie-tesla-fahrzeuge-in-ip-symcon-steuerung-telemetrie-kachel/143995';
    private const LICENSE_URL = 'https://github.com/DG65/NRGTessie/blob/main/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    // NEWS_VERSIONS statt einzelnem NEWS_VERSION/NEWS_ITEMS (Verbund-Konvention, Dashboard/
    // Dietmar 23.09.2026, SUITE.md "Einheitliche Formular-Optik") - siehe TessieVehicle für die
    // ausführliche Begründung. Alter Block bleibt unter seiner letzten bisherigen Nummer (2.33.0).
    private const NEWS_VERSIONS = [
        '2.33.0' => [
            'New at the very bottom of the form: a separate feedback panel for the forum thread, and "About this module" with license information and a donation link.',
            'If you have several vehicle tiles: you only need to dismiss "What is this module for?" and "What is new?" on one instance, not on each one separately.',
            '👋 New introduction at the very top of the form: a short explanation of what this tile shows and what it is good for.',
            'Create, edit and delete the If→Then rules of the source right here – including several AND conditions.',
            'Manage the locations (geofence) of the source right here, with an icon of its own per location.',
            'Control buttons: choose the number, order and labels yourself (pencil icon next to "Buttons").',
            'The comparison value of a rule appears as a selection list with plain text when the data point has fixed values.'
        ],
        '2.34.0' => [
            'Now shows live which vehicle was detected automatically (name, charge level, source) instead of merely claiming it – with several vehicles or no match, a notice with a selection field appears instead.'
        ],
        '2.39.0' => [
            'Now bilingual (German/English): the tile and its form follow the language of your Symcon installation.'
        ]
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
        $this->RegisterAttributeBoolean(self::ATTR_FORUM_HINT_GONE, false);

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
                    $element['caption'] = sprintf($this->t('📖 Documentation & help (module version %s)'), $v);
                }
                break;
            }
        }
        unset($element);

        // Live berechnete Statuszeile zur (automatischen) Datenquelle statt statischem Satz.
        // Kommt die Quelle automatisch, verschwindet das Auswahlfeld in einem eingeklappten
        // Überschreiben-Panel (SUITE.md "Wert kommt automatisch"), statt leer daneben zu stehen.
        $source = $this->sourceStatus();
        $this->setLabelCaption($form['elements'], 'SourceStatus', $source['line'], strpos($source['line'], '🔗') === 0 ? self::AUTO_COLOR : -1);
        if ($source['auto']) {
            $this->foldFieldIntoOverridePanel($form['elements'], 'SourceInstance', '✏️ Select a data source manually instead');
        }

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

        // Symcon-Forum-Hinweis (dismissible) und Lizenz-/Spenden-Hinweis (dauerhaft) ganz unten.
        $forumHint = $this->forumHint();
        if ($forumHint !== null) {
            $form['elements'][] = $forumHint;
        }
        $form['elements'][] = $this->licenseHint();

        return json_encode($form);
    }

    /** Setzt Beschriftung (und ggf. Farbe) des Elements mit dem Namen $name, rekursiv über alle `items`. */
    private function setLabelCaption(array &$items, string $name, string $caption, ?int $color = null): bool
    {
        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['name'] ?? '') === $name) {
                $item['caption'] = $caption;
                if ($color !== null) {
                    $item['color'] = $color;
                }
                return true;
            }
            if (isset($item['items']) && is_array($item['items']) && $this->setLabelCaption($item['items'], $name, $caption, $color)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Packt das Feld $name in ein eingeklapptes Panel (Überschreiben bleibt möglich, das Feld
     * steht aber nicht mehr leer neben dem automatisch ermittelten Wert). Rekursiv über `items`.
     */
    private function foldFieldIntoOverridePanel(array &$items, string $name, string $panelCaption): bool
    {
        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['name'] ?? '') === $name) {
                $item = ['type' => 'ExpansionPanel', 'caption' => $panelCaption, 'expanded' => false, 'items' => [$item]];
                return true;
            }
            if (isset($item['items']) && is_array($item['items']) && $this->foldFieldIntoOverridePanel($item['items'], $name, $panelCaption)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Statuszeile zur Datenquelle (Formular-Konvention "Verbund-Verbindungen sichtbar machen",
     * SUITE.md): 🔗 automatisch übernommen bzw. ✏️ eigene Auswahl mit übernommenen Werten,
     * ⚠️ verbunden ohne brauchbare Werte bzw. mehrdeutig, ℹ️ nicht gefunden. Spiegelt die Logik
     * von ResolveSource() wider. 'auto' = die Quelle kommt automatisch (Feld leer), das
     * Auswahlfeld wird dann eingeklappt.
     *
     * @return array{line: string, auto: bool}
     */
    private function sourceStatus(): array
    {
        $configured = (int)$this->ReadPropertyInteger('SourceInstance');
        $list = IPS_GetInstanceListByModuleID(self::SOURCE_MODULE);
        $stale = ($configured > 0 && !IPS_InstanceExists($configured))
            ? sprintf($this->t('The selected data source #%d no longer exists. '), $configured) : '';

        $src = $this->ResolveSource();
        if ($src <= 0) {
            if (count($list) === 0) {
                return ['auto' => false, 'line' => sprintf($this->t('ℹ️ %sNo TessieVehicle instance found – the tile shows no values until one exists. Create the vehicle through the Tessie Configurator first.'), $stale)];
            }
            $names = [];
            foreach ($list as $iid) {
                $names[] = sprintf($this->t('#%d "%s"'), $iid, IPS_GetName($iid));
            }
            return ['auto' => false, 'line' => sprintf($this->t('⚠️ %s%d TessieVehicle instances found (%s) – please choose the data source below. Until one is chosen, the tile shows no values.'), $stale, count($list), implode(', ', $names))];
        }

        $manual = ($configured > 0 && $configured === $src);
        $auto = ($configured <= 0);
        $how = $manual ? $this->t('own selection') : $this->t('detected automatically, the only TessieVehicle instance');
        $mark = $stale !== '' ? '⚠️ ' : ($manual ? '✏️ ' : '🔗 ');
        $head = sprintf($this->t('Data source: #%d "%s"'), $src, IPS_GetName($src));

        $json = @TESSIE_GetVehicleState($src);
        $state = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($state)) {
            return ['auto' => $auto, 'line' => sprintf($this->t('⚠️ %s%s (%s) found, but it returns no vehicle state.'), $stale, $head, $how)];
        }

        $details = [];
        $vin = trim((string)($state['vin'] ?? ''));
        if ($vin !== '') {
            $details[] = sprintf($this->t('VIN %s'), $vin);
        }
        $details[] = sprintf($this->t('contract version %s'), (string)($state['contractVersion'] ?? '?'));
        $details[] = $how;

        $soc = $state['soc'] ?? null;
        $socID = (int)($state['socID'] ?? 0);
        if (!is_numeric($soc) || $socID <= 0) {
            return ['auto' => $auto, 'line' => sprintf($this->t('⚠️ %s%s (%s) connected, but there is no charge level yet – telemetry or the data point "Charge level" is missing or has not been received yet.'), $stale, $head, implode(', ', $details))];
        }

        $taken = sprintf($this->t('Charge level %s %% (source: variable #%d)'), number_format((float)$soc, 0, '.', ''), $socID);
        if (array_key_exists('connected', $state)) {
            $taken .= $state['connected'] ? $this->t(', charging cable plugged in') : $this->t(', charging cable not plugged in');
        }
        return ['auto' => $auto, 'line' => sprintf($this->t('%s%s%s (%s). Adopted: %s.'), $mark, $stale, $head, implode(', ', $details), $taken)];
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
                ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'TESSIETILE_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint(): void
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
            'caption' => '👋  What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'This tile shows the charge level, range, temperatures and vehicle status of your Tesla at a glance – including control buttons for lock, climate and more, right in the tile visualization.'],
                ['type' => 'Label', 'caption' => 'The benefit: a compact, visually customizable overview for the WebFront without having to assemble variables yourself – the data source is detected automatically.'],
                ['type' => 'Label', 'caption' => 'The actual data connection to the vehicle is provided by the TessieVehicle instance; configuration and vehicle search go through TessieConfigurator.'],
                ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'TESSIETILE_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE, true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
        $this->PropagateDismiss('PurposeIntro');
    }

    /** Reine Versionszahl ohne Beta-/Build-Zusatz - siehe TessieVehicle::BaseVersion(). */
    /** Translate() mit Prozess-Cache (jeder Quelltext wird nur einmal uebersetzt, SUITE.md Stolperstein 24). */
    private function t(string $source): string
    {
        static $cache = [];
        return $cache[$source] ??= $this->Translate($source);
    }

    private function BaseVersion(string $v): string
    {
        return preg_replace('/-.*$/', '', $v) ?? $v;
    }

    /**
     * „Was ist neu"-Banner: erscheint nach einem Update (Attribut startet leer), bis der Nutzer
     * „Verstanden" klickt - zeigt nur die Versionen, die neuer sind als die zuletzt bestätigte,
     * gruppiert nach Version (SUITE.md "NEWS_VERSIONS-Array", Dashboard/Dietmar 23.09.2026).
     */
    private function newsBanner(): ?array
    {
        $seen = $this->ReadAttributeString(self::ATTR_SEEN_NEWS);
        $pending = [];
        foreach (self::NEWS_VERSIONS as $ver => $lines) {
            if ($seen === '' || version_compare($ver, $seen, '>')) {
                $pending[$ver] = $lines;
            }
        }
        if (count($pending) === 0) {
            return null;
        }
        uksort($pending, 'version_compare');
        $items = [['type' => 'Label', 'caption' => 'New since the last store version – please take a quick look:']];
        $multi = count($pending) > 1;
        foreach ($pending as $ver => $lines) {
            if ($multi) {
                $items[] = ['type' => 'Label', 'caption' => sprintf($this->t('Version %s:'), $ver)];
            }
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => '• ' . $this->t($line)];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'TESSIETILE_AckNews($id);'];
        $latest = array_key_last($pending);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => sprintf($this->t('🆕 New up to version %s'), $latest), 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $lib = @IPS_GetLibrary(self::LIBRARY_GUID);
        $ver = is_array($lib) ? $this->BaseVersion((string)($lib['Version'] ?? '')) : '';
        if ($ver === '') {
            $ver = (string) array_key_last(self::NEWS_VERSIONS);
        }
        $this->WriteAttributeString(self::ATTR_SEEN_NEWS, $ver);
        $this->UpdateFormField('NewsPanel', 'visible', false);
        $this->PropagateDismiss('News', $ver);
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
            case 'ForumHint':
                $this->WriteAttributeBoolean(self::ATTR_FORUM_HINT_GONE, true);
                $this->UpdateFormField('ForumHintPanel', 'visible', false);
                break;
        }
    }

    /** Für Geschwister-Instanzen, die beim erstmaligen Kontakt den Ausblenden-Stand übernehmen wollen. */
    public function GetDismissState(): array
    {
        return [
            'purposeIntroGone' => $this->ReadAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE),
            'seenNews'         => $this->ReadAttributeString(self::ATTR_SEEN_NEWS),
            'forumHintGone'    => $this->ReadAttributeBoolean(self::ATTR_FORUM_HINT_GONE),
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
        $latestNews = (string) array_key_last(self::NEWS_VERSIONS);
        $mySeen = $this->ReadAttributeString(self::ATTR_SEEN_NEWS);
        if ($this->ReadAttributeBoolean(self::ATTR_PURPOSE_INTRO_GONE)
            && $mySeen !== '' && version_compare($mySeen, $latestNews, '>=')
            && $this->ReadAttributeBoolean(self::ATTR_FORUM_HINT_GONE)) {
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
            // Nur vorziehen (ältere -> neuere News-Version), nie einen schon weiter
            // fortgeschrittenen eigenen Stand überschreiben.
            $sibSeen = (string)($state['seenNews'] ?? '');
            if ($sibSeen !== '' && ($mySeen === '' || version_compare($sibSeen, $mySeen, '>'))) {
                $this->WriteAttributeString(self::ATTR_SEEN_NEWS, $sibSeen);
                $mySeen = $sibSeen;
            }
            if (!$this->ReadAttributeBoolean(self::ATTR_FORUM_HINT_GONE) && !empty($state['forumHintGone'])) {
                $this->WriteAttributeBoolean(self::ATTR_FORUM_HINT_GONE, true);
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
        $module .= '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage(true)) . ');</script>';
        return $module;
    }

    // ---------------------------------------------------------------------
    // Datenaufbereitung
    // ---------------------------------------------------------------------

    private function GetFullUpdateMessage(bool $withTexts = false): string
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
        if ($withTexts) {
            // Uebersetzungen der Kachel-Oberflaeche nur beim Laden der Kachel mitschicken (JS: T()).
            $style['locale'] = $this->t('en-US');
            $style['texts'] = array_combine(self::KACHEL_TEXTE, array_map([$this, 't'], self::KACHEL_TEXTE));
        }
        $showButtons = $this->ReadPropertyBoolean('ShowControls');

        $cCharging = $this->ColorHex((int)$this->ReadPropertyInteger('ColorCharging'), '#27d07f');
        $cReady    = $this->ColorHex((int)$this->ReadPropertyInteger('ColorReady'), '#2bb3c0');
        $cIdle     = $this->ColorHex((int)$this->ReadPropertyInteger('ColorIdle'), '#7a8a99');

        $src = $this->ResolveSource();
        if ($src <= 0 || !IPS_InstanceExists($src)) {
            return json_encode(array_merge($style, [
                'name'       => 'Tesla',
                'stateLabel' => $this->t('No data source'),
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
            'stateLabel'  => $this->t($charging ? 'Charging' : 'Ready'),
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
                    $caption = ($custom !== '') ? $custom : ($state ? $this->t('Unlock') : $this->t('Lock'));
                    $on = false; // historisch: Schloss-Button hebt sich farblich nicht ab
                    $value = !$state;
                    break;
                case 'climate':
                    $caption = ($custom !== '') ? $custom : ($state ? $this->t('Climate off') : $this->t('Climate on'));
                    $on = $state;
                    $value = !$state;
                    break;
                case 'charge':
                    $caption = ($custom !== '') ? $custom : ($state ? $this->t('Stop charging') : $this->t('Start charging'));
                    $on = $state;
                    $value = !$state;
                    break;
                case 'toggle':
                    $caption = ($custom !== '') ? $custom : sprintf($this->t($state ? 'Switch off %s' : 'Switch on %s'), $this->t($cat['name']));
                    $on = $state;
                    $value = !$state;
                    break;
                case 'momentary':
                default:
                    $caption = ($custom !== '') ? $custom : $this->t($cat['name']);
                    $on = false;
                    $value = true;
                    break;
            }

            $out[] = ['i' => $ident, 'idx' => $btn['idx'], 'n' => $this->t($cat['name']), 'label' => $custom, 'c' => $caption, 'on' => $on, 'v' => $value];
        }
        return $out;
    }

    /** Katalog aller wählbaren Button-Funktionen für den Kachel-Editor: [{v:Ident, c:Name}]. */
    public function GetButtonCatalog(): string
    {
        $out = [];
        foreach (self::BUTTON_CATALOG as $ident => $cat) {
            $out[] = ['v' => $ident, 'c' => $this->t($cat['name'])];
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
