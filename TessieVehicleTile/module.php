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
        'act_sentry'            => ['name' => 'Sentry Mode',                 'kind' => 'toggle'],
        'act_valet'             => ['name' => 'Valet-Modus',                 'kind' => 'toggle'],
        'act_defrost'           => ['name' => 'Max Defrost',                 'kind' => 'toggle'],
        'act_steering_wheel'    => ['name' => 'Lenkradheizung',              'kind' => 'toggle'],
        'act_cop_enabled'       => ['name' => 'Innenraum-Überhitzeschutz',   'kind' => 'toggle'],
        'act_cop_fan_only'      => ['name' => 'Überhitzeschutz: nur Lüfter', 'kind' => 'toggle'],
        'act_bio_defense'       => ['name' => 'Bio Defense Mode',            'kind' => 'toggle'],
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
        if ($Message === VM_UPDATE) {
            $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
        }
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
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
            'bg'        => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorBackground')),
            'box'       => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorBox')),
            'text'      => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorText')),
            'textmuted' => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorTextMuted')),
            'font'      => $this->FontStack($this->ReadPropertyString('FontFamily')),
            'scale'     => $this->FontScaleValue(),
            'controls'  => $this->ReadPropertyBoolean('ShowControls')
        ];
        $showButtons = $this->ReadPropertyBoolean('ShowControls');

        $cCharging = $this->ColorHex($this->ReadPropertyInteger('ColorCharging'), '#27d07f');
        $cReady    = $this->ColorHex($this->ReadPropertyInteger('ColorReady'), '#2bb3c0');
        $cIdle     = $this->ColorHex($this->ReadPropertyInteger('ColorIdle'), '#7a8a99');

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
        $configured = $this->ReadPropertyInteger('SourceInstance');
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
        $v = $this->ReadPropertyFloat('FontScale');
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
