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

    // Quell-Variablen, auf deren Änderung die Kachel reagieren soll
    private const WATCH_IDENTS = [
        'act_locked', 'act_climate', 'act_charging', 'act_charge_limit',
        'stat_tel_Soc', 'stat_tel_RatedRange', 'stat_tel_InsideTemp', 'stat_tel_OutsideTemp',
        'stat_ac_charging_power', 'stat_charge_amps_actual', 'stat_charge_amps_max',
        'stat_tel_TimeToFullCharge', 'stat_tel_Location_lat', 'stat_tel_Location_lon'
    ];

    // Aktions-Buttons der Kachel -> Idents der Aktions-Variablen in der Quelle
    private const ACTION_MAP = [
        'lock'    => 'act_locked',
        'climate' => 'act_climate',
        'charge'  => 'act_charging'
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
            foreach (self::WATCH_IDENTS as $ident) {
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
        if ($src <= 0 || !isset(self::ACTION_MAP[$Ident])) {
            return;
        }
        $vid = @IPS_GetObjectIDByIdent(self::ACTION_MAP[$Ident], $src);
        if ($vid > 0) {
            @RequestAction($vid, $Value); // globale IPS-Funktion -> löst die Aktion der Quelle aus
        }
    }

    /**
     * Button-Aktion: alle Farben und Schrifteinstellungen auf Standard zurücksetzen.
     */
    public function ResetStyle(): void
    {
        $id = $this->InstanceID;
        IPS_SetProperty($id, 'ColorCharging', self::DEF_CHARGING);
        IPS_SetProperty($id, 'ColorReady', self::DEF_READY);
        IPS_SetProperty($id, 'ColorIdle', self::DEF_IDLE);
        IPS_SetProperty($id, 'ColorBackground', self::DEF_BACKGROUND);
        IPS_SetProperty($id, 'ColorBox', self::DEF_BOX);
        IPS_SetProperty($id, 'ColorText', self::DEF_TEXT);
        IPS_SetProperty($id, 'ColorTextMuted', self::DEF_TEXTMUTED);
        IPS_SetProperty($id, 'FontFamily', self::DEF_FONT);
        IPS_SetProperty($id, 'FontScale', self::DEF_SCALE);
        IPS_ApplyChanges($id);
        $this->ReloadForm();
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
                'controls'   => false
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
            'lon'         => $this->ReadSourceValue($src, 'stat_tel_Location_lon')
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
