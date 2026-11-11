<?php
// Gemeinsame IP-Symcon-Attrappen für die Prüfstände (tests/*.php). Sprache: TESSIE_TEST_LANG=de|en (Standard de).
// de = Translate() und Formular-Übersetzung nutzen die locale.json des jeweiligen Moduls, en = englische Quelltexte.
declare(strict_types=1);

// Variablentypen wie im Symcon-Kern (0 Boolean, 1 Integer, 2 Float, 3 String)
foreach (['BOOLEAN' => 0, 'INTEGER' => 1, 'FLOAT' => 2, 'STRING' => 3] as $n => $v) {
    if (!defined('VARIABLETYPE_' . $n)) { define('VARIABLETYPE_' . $n, $v); }
}
error_reporting(E_ALL & ~E_DEPRECATED);
$GLOBALS['LANG'] = getenv('TESSIE_TEST_LANG') ?: 'de';
$GLOBALS['LOCS'] = [];

class IPSModule
{
    public array $props = [];
    public array $attrs = [];
    public function __construct(public int $InstanceID = 1) {}
    public function ReadPropertyInteger($n) { return $this->props[$n] ?? 0; }
    public function ReadPropertyString($n) { return $this->props[$n] ?? ''; }
    public function ReadPropertyBoolean($n) { return $this->props[$n] ?? false; }
    public function ReadPropertyFloat($n) { return $this->props[$n] ?? 0.0; }
    public function ReadAttributeString($n) { return $this->attrs[$n] ?? ''; }
    public function ReadAttributeBoolean($n) { return $this->attrs[$n] ?? false; }
    public function ReadAttributeInteger($n) { return $this->attrs[$n] ?? 0; }
    public function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    public function WriteAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    public function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    public function Translate($t)
    {
        if ($GLOBALS['LANG'] !== 'de') { return $t; }
        $cls = static::class;
        if (!isset($GLOBALS['LOCS'][$cls])) {
            $f = dirname(__DIR__) . '/' . $cls . '/locale.json';
            $GLOBALS['LOCS'][$cls] = file_exists($f) ? (json_decode(file_get_contents($f), true)['translations']['de'] ?? []) : [];
        }
        return $GLOBALS['LOCS'][$cls][$t] ?? $t;
    }
    public function __call($n, $a) { return null; }
}

$GLOBALS['INST'] = [];    // id => name (TessieVehicle-Instanzen)
$GLOBALS['STATE'] = [];   // id => JSON von TESSIE_GetVehicleState
$GLOBALS['SYSLOC'] = null; // JSON-Property der Kern-Instanz Location oder null
function IPS_GetInstanceListByModuleID($guid)
{
    if ($guid === '{45E97A63-F870-408A-B259-2933F7EABF74}') {
        return $GLOBALS['SYSLOC'] === null ? [] : [7];
    }
    return array_keys($GLOBALS['INST']);
}
function IPS_GetProperty($id, $n) { return $GLOBALS['SYSLOC']; }
function IPS_InstanceExists($id) { return isset($GLOBALS['INST'][$id]); }
function IPS_GetName($id) { return $GLOBALS['INST'][$id] ?? ''; }
function IPS_GetParent($id) { return 0; }
function TESSIE_GetVehicleState($id) { return $GLOBALS['STATE'][$id] ?? false; }
$GLOBALS['IDENTMAP'] = []; // ident => Variablen-ID, fuer die Positions-Reconcile-Tests
$GLOBALS['OBJPOS'] = [];   // Variablen-ID => ObjectPosition
function IPS_GetObjectIDByIdent($ident, $parent) { return $GLOBALS['IDENTMAP'][$ident] ?? false; }
$GLOBALS['LIBVER'] = null; // simulierte installierte Bibliotheksversion oder null (IPS_GetLibrary schlägt fehl)
function IPS_GetLibrary($guid) { return $GLOBALS['LIBVER'] === null ? false : ['Version' => $GLOBALS['LIBVER']]; }
function IPS_ObjectExists($id) { return false; }
function IPS_VariableExists($id) { return false; }
function IPS_GetChildrenIDs($id) { return []; }
function IPS_GetObject($id) { return ['ObjectIdent' => '', 'ObjectName' => '', 'ObjectType' => 2, 'ParentID' => 0, 'ChildrenIDs' => [], 'ObjectPosition' => $GLOBALS['OBJPOS'][$id] ?? 0]; }
function IPS_GetVariable($id) { return ['VariableType' => 3, 'VariableUpdated' => 0]; }
$GLOBALS['VALUES'] = []; // Variablen-ID => Wert
function GetValue($id) { return $GLOBALS['VALUES'][$id] ?? null; }

// Presentation-Konstanten (in IP-Symcon GUID-Strings; hier nur als unterscheidbare Platzhalter)
foreach (['SWITCH', 'SLIDER', 'VALUE_PRESENTATION', 'VALUE_INPUT', 'ENUMERATION'] as $p) {
    if (!defined('VARIABLE_PRESENTATION_' . $p)) {
        define('VARIABLE_PRESENTATION_' . $p, '{TEST-' . $p . '}');
    }
}

function TESSIE_GetDataActions($id) { return '[]'; }
function TESSIE_GetGeofenceConfig($id) { return '{}'; }
if (!defined('KR_READY')) { define('KR_READY', 10); }
function IPS_GetKernelRunlevel() { return KR_READY; }
function GetValueString($id) { return (string)($GLOBALS['VALUES'][$id] ?? ''); }
function GetValueBoolean($id) { return (bool)($GLOBALS['VALUES'][$id] ?? false); }
function GetValueFloat($id) { return (float)($GLOBALS['VALUES'][$id] ?? 0.0); }
