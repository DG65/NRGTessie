<?php
// Prüfstand für die Verbund-Formularkonvention (SUITE.md "Verbund-Verbindungen sichtbar machen"
// und "Wert kommt automatisch"). Aufruf: php tests/formular_status_test.php  (Exit-Code 1 bei Fehler)
// Simuliert die IP-Symcon-Umgebung minimal; prüft das ausgelieferte Formular-JSON.
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

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
function IPS_GetObjectIDByIdent($ident, $parent) { return false; }
function IPS_ObjectExists($id) { return false; }
function IPS_VariableExists($id) { return false; }
function IPS_GetChildrenIDs($id) { return []; }
function IPS_GetObject($id) { return ['ObjectIdent' => '', 'ObjectName' => '', 'ObjectType' => 2, 'ParentID' => 0, 'ChildrenIDs' => []]; }
function IPS_GetVariable($id) { return ['VariableType' => 3, 'VariableUpdated' => 0]; }
function GetValue($id) { return null; }

$root = dirname(__DIR__);
require $root . '/TessieVehicleTile/module.php';
require $root . '/TessieVehicle/module.php';
require $root . '/TessieConfigurator/module.php';

$fails = 0;
$checks = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $fails, $checks;
    $checks++;
    if (!$ok) {
        $fails++;
    }
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . ($ok ? '' : '  -> ' . $detail) . "\n";
}
/** Sucht rekursiv ein Element; liefert [Element, Elternliste-Name] oder null. */
function findEl(array $items, string $name, string $parent = ''): ?array
{
    foreach ($items as $i) {
        if (!is_array($i)) {
            continue;
        }
        if (($i['name'] ?? '') === $name) {
            return ['el' => $i, 'parent' => $parent];
        }
        if (isset($i['items']) && ($r = findEl($i['items'], $name, (string)($i['caption'] ?? '')))) {
            return $r;
        }
    }
    return null;
}
/** Feld liegt in einem eingeklappten Überschreiben-Panel (nicht direkt sichtbar)? */
function isFolded(array $elements, string $field): bool
{
    $f = findEl($elements, $field);
    return $f !== null && strpos($f['parent'], '✏️') === 0;
}
function caption(array $elements, string $name): string
{
    $f = findEl($elements, $name);
    return $f === null ? '<<fehlt>>' : (string)$f['el']['caption'];
}
function colorOf(array $elements, string $name): ?int
{
    $f = findEl($elements, $name);
    return $f === null ? null : ($f['el']['color'] ?? null);
}
$vehicleState = fn(?float $soc) => json_encode(['vin' => '5YJ3E1EA7KF000001', 'contractVersion' => '1.5', 'soc' => $soc, 'socID' => $soc === null ? 0 : 12345, 'connected' => true]);

// ---------------- Kachel: Datenquelle ----------------
function tileForm(int $configured): array
{
    $m = new TessieVehicleTile(99);
    $m->props['SourceInstance'] = $configured;
    return json_decode($m->GetConfigurationForm(), true)['elements'];
}
echo "== Kachel ==\n";

$GLOBALS['INST'] = [];
$e = tileForm(0);
check('Kachel: keine Instanz -> ℹ️, Feld sichtbar', strpos(caption($e, 'SourceStatus'), 'ℹ️') === 0 && !isFolded($e, 'SourceInstance'), caption($e, 'SourceStatus'));

$GLOBALS['INST'] = [111 => 'Mein Tesla']; $GLOBALS['STATE'][111] = $vehicleState(82.4);
$e = tileForm(0);
$c = caption($e, 'SourceStatus');
check('Kachel: eine Instanz automatisch -> 🔗 mit Wert und Quelle', strpos($c, '🔗') === 0 && strpos($c, '82 %') !== false && strpos($c, '#12345') !== false, $c);
check('Kachel: automatisch -> Auswahlfeld eingeklappt', isFolded($e, 'SourceInstance'));
check('Kachel: 🔗-Zeile grün (0x2E8B3D)', colorOf($e, 'SourceStatus') === 0x2E8B3D, var_export(colorOf($e, 'SourceStatus'), true));
check('Kachel: statischer Erkennungssatz entfernt', strpos(json_encode($e, JSON_UNESCAPED_UNICODE), 'wird automatisch erkannt, wenn es genau eine') === false);

$e = tileForm(111);
$c = caption($e, 'SourceStatus');
check('Kachel: eigene Auswahl -> ✏️, Feld sichtbar', strpos($c, '✏️') === 0 && !isFolded($e, 'SourceInstance'), $c);
check('Kachel: ✏️-Zeile Standardfarbe (-1)', colorOf($e, 'SourceStatus') === -1, var_export(colorOf($e, 'SourceStatus'), true));

$GLOBALS['STATE'][111] = $vehicleState(null);
$e = tileForm(0);
check('Kachel: automatisch, aber kein Ladestand -> ⚠️', strpos(caption($e, 'SourceStatus'), '⚠️') === 0, caption($e, 'SourceStatus'));

$GLOBALS['INST'] = [111 => 'Mein Tesla', 222 => 'Zweitwagen']; $GLOBALS['STATE'][111] = $vehicleState(82.4);
$e = tileForm(0);
check('Kachel: mehrere ohne Auswahl -> ⚠️, Feld sichtbar', strpos(caption($e, 'SourceStatus'), '⚠️') === 0 && !isFolded($e, 'SourceInstance'), caption($e, 'SourceStatus'));

$e = tileForm(999);
check('Kachel: gewählte Instanz weg -> ⚠️, Feld sichtbar (korrigierbar)', strpos(caption($e, 'SourceStatus'), '⚠️') === 0 && !isFolded($e, 'SourceInstance'), caption($e, 'SourceStatus'));

$GLOBALS['INST'] = [111 => 'Mein Tesla'];
$e = tileForm(999);
check('Kachel: gewählte weg, eine vorhanden -> ⚠️, Feld sichtbar', strpos(caption($e, 'SourceStatus'), '⚠️') === 0 && !isFolded($e, 'SourceInstance'), caption($e, 'SourceStatus'));

// ---------------- Fahrzeug: Standort Zuhause ----------------
echo "== Fahrzeug ==\n";
function homeStatus(string $own, ?string $sys): array
{
    $m = new TessieVehicle(5);
    $m->props = ['HomeLocation' => $own, 'HomeRadius' => 150];
    $GLOBALS['SYSLOC'] = $sys;
    $r = new ReflectionMethod($m, 'homeSourceStatus');
    return $r->invoke($m);
}
function foldedHome(bool $auto): bool
{
    $m = new TessieVehicle(5);
    $form = json_decode(file_get_contents(dirname(__DIR__) . '/TessieVehicle/form.json'), true);
    $els = $form['elements'];
    if ($auto) {
        $r = new ReflectionMethod($m, 'foldFieldIntoOverridePanel');
        $r->invokeArgs($m, [&$els, 'HomeLocation', '✏️ Eigenen Standort stattdessen verwenden']);
    }
    return isFolded($els, 'HomeLocation');
}
$loc = fn($la, $lo) => json_encode(['latitude' => $la, 'longitude' => $lo]);

$s = homeStatus($loc(49.1, 8.4), $loc(48.0, 9.0));
check('Fahrzeug: eigene Angabe -> ✏️, Feld bleibt', strpos($s['line'], '✏️') === 0 && $s['auto'] === false, $s['line']);
$s = homeStatus('', $loc(48.7, 9.1));
check('Fahrzeug: Systemstandort -> 🔗 mit Koordinaten und Quelle', strpos($s['line'], '🔗') === 0 && strpos($s['line'], '48,70000') !== false && strpos($s['line'], 'Systemstandort') !== false && $s['auto'] === true, $s['line']);
$s = homeStatus($loc(0, 0), $loc(48.7, 9.1));
check('Fahrzeug: eigene 0/0 zählt als leer -> Systemstandort', $s['auto'] === true, $s['line']);
$s = homeStatus('', null);
check('Fahrzeug: nichts ermittelbar -> ℹ️, Feld bleibt', strpos($s['line'], 'ℹ️') === 0 && $s['auto'] === false, $s['line']);
check('Fahrzeug: automatisch -> Feld eingeklappt', foldedHome(true));
check('Fahrzeug: nicht automatisch -> Feld direkt sichtbar', !foldedHome(false));
$form = json_decode(file_get_contents($root . '/TessieVehicle/form.json'), true);
check('Fahrzeug: Statuslabel im form.json rekursiv auffindbar', findEl($form['elements'], 'HomeSourceStatus') !== null);

// Verdrahtung im echten GetConfigurationForm() (mit den Stubs oben)
function vehicleForm(string $own, ?string $sys): ?array
{
    $m = new TessieVehicle(5);
    $m->props = ['HomeLocation' => $own, 'HomeRadius' => 150, 'VIN' => 'X'];
    $GLOBALS['SYSLOC'] = $sys;
    try {
        $f = json_decode($m->GetConfigurationForm(), true);
        return is_array($f) ? $f['elements'] : null;
    } catch (Throwable $e) {
        echo '     (GetConfigurationForm: ' . get_class($e) . ': ' . $e->getMessage() . ")\n";
        return null;
    }
}
$e = vehicleForm('', $loc(48.7, 9.1));
$lastColor = $e === null ? null : colorOf($e, 'HomeSourceStatus');
check('Fahrzeug (echtes Formular): Systemstandort -> 🔗-Zeile im Formular, Feld eingeklappt', $e !== null && strpos(caption($e, 'HomeSourceStatus'), '🔗') === 0 && isFolded($e, 'HomeLocation'), $e === null ? 'Formular nicht erzeugbar' : caption($e, 'HomeSourceStatus'));
$e = vehicleForm($loc(49.1, 8.4), $loc(48.7, 9.1));
check('Fahrzeug (echtes Formular): 🔗-Zeile grün (vorheriger Fall)', $lastColor === 0x2E8B3D, var_export($lastColor, true));
check('Fahrzeug (echtes Formular): eigene Angabe -> ✏️-Zeile, Feld sichtbar', $e !== null && strpos(caption($e, 'HomeSourceStatus'), '✏️') === 0 && !isFolded($e, 'HomeLocation'), $e === null ? 'Formular nicht erzeugbar' : caption($e, 'HomeSourceStatus'));
$e = vehicleForm('', null);
check('Fahrzeug (echtes Formular): ✏️/ℹ️-Zeilen Standardfarbe (-1)', $e !== null && colorOf($e, 'HomeSourceStatus') === -1, var_export($e === null ? null : colorOf($e, 'HomeSourceStatus'), true));
check('Fahrzeug (echtes Formular): nichts -> ℹ️-Zeile, Feld sichtbar', $e !== null && strpos(caption($e, 'HomeSourceStatus'), 'ℹ️') === 0 && !isFolded($e, 'HomeLocation'), $e === null ? 'Formular nicht erzeugbar' : caption($e, 'HomeSourceStatus'));


// ---------------- Configurator: Zugangsschlüssel ----------------
echo "== Configurator ==\n";
const GEHEIMNIS = 'sk-test-geheimer-schluessel-123';
function tokenLine(string $token, int $http, string $curlError, int $vehicles): string
{
    $m = new TessieConfigurator(3);
    $m->attrs['TokenSecret'] = $token;
    foreach (['lastHttpCode' => $http, 'lastApiError' => $curlError] as $prop => $val) {
        $rp = new ReflectionProperty($m, $prop);
        $rp->setValue($m, $val);
    }
    $rc = new ReflectionMethod($m, 'recordCheckResult');
    $rc->invoke($m);
    $rl = new ReflectionMethod($m, 'tokenStatusLine');
    return $rl->invoke($m, $token, $vehicles);
}
$l = tokenLine('', 0, '', 0);
check('Configurator: kein Schlüssel -> ℹ️', strpos($l, 'ℹ️') === 0, $l);
$l = tokenLine(GEHEIMNIS, 200, '', 2);
check('Configurator: akzeptiert -> ✅ mit Zeitpunkt, Schlüssel nicht angezeigt', strpos($l, '✅') === 0 && strpos($l, 'zuletzt erfolgreich geprüft') !== false && strpos($l, GEHEIMNIS) === false, $l);
$l = tokenLine(GEHEIMNIS, 401, '', 0);
check('Configurator: HTTP 401 -> ⚠️ abgelehnt mit Grund', strpos($l, '⚠️') === 0 && strpos($l, 'abgelehnt (HTTP 401)') !== false && strpos($l, GEHEIMNIS) === false, $l);
$l = tokenLine(GEHEIMNIS, 0, 'keine Verbindung zu Tessie (Could not resolve host)', 0);
check('Configurator: keine Verbindung -> ⚠️ mit Grund', strpos($l, '⚠️') === 0 && strpos($l, 'keine Verbindung') !== false, $l);
$l = tokenLine(GEHEIMNIS, 200, '', 0);
check('Configurator: akzeptiert, aber keine Fahrzeuge -> ⚠️', strpos($l, '⚠️') === 0 && strpos($l, 'keine Fahrzeuge') !== false, $l);
$l = tokenLine(GEHEIMNIS, 500, '', 0);
check('Configurator: HTTP 500 -> ⚠️ mit Code', strpos($l, '⚠️') === 0 && strpos($l, 'HTTP 500') !== false, $l);
$m = new TessieConfigurator(3);
$f = json_decode($m->GetConfigurationForm(), true);
check('Configurator (echtes Formular, ohne Schlüssel): TokenStatus-Zeile vorhanden, ℹ️', $f !== null && strpos(caption($f['elements'], 'TokenStatus'), 'ℹ️') === 0, $f === null ? 'Formular nicht erzeugbar' : caption($f['elements'], 'TokenStatus'));

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails > 0 ? 1 : 0);
