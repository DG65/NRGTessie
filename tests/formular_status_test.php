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

// ---------------- NEWS_VERSIONS-Banner ----------------
echo "== Was-ist-Neu-Banner ==\n";
function newsBanner(string $seen): ?array
{
    $m = new TessieVehicle(5);
    $m->attrs['SeenNews'] = $seen;
    $r = new ReflectionMethod($m, 'newsBanner');
    return $r->invoke($m);
}
$b = newsBanner('');
check('Banner: nie bestätigt -> alle Versionen, Caption bis zur höchsten', $b !== null && strpos($b['caption'], '🆕 Neu bis Version 2.36.0') === 0, $b['caption'] ?? '<<null>>');
$b = newsBanner('2.34.0');
check('Banner: 2.34.0 gesehen -> nur 2.35.0/2.36.0, gruppiert', $b !== null && strpos(json_encode($b, JSON_UNESCAPED_UNICODE), 'Version 2.35.0:') !== false && strpos(json_encode($b, JSON_UNESCAPED_UNICODE), '2.33.0:') === false, $b === null ? 'null' : json_encode($b, JSON_UNESCAPED_UNICODE));
$b = newsBanner('2.36.0');
check('Banner: höchste Version gesehen -> kein Banner', $b === null, $b === null ? 'null' : $b['caption']);
$b = newsBanner('2.99.0-beta.3');
check('Banner: Beta-Suffix einer künftigen Version -> trotzdem kein Banner (version_compare ignoriert Suffix nicht, aber 2.99 > alles)', $b === null, $b === null ? 'null' : $b['caption']);

function ackNewsSets(?string $libVer): string
{
    $m = new TessieVehicle(5);
    $GLOBALS['LIBVER'] = $libVer;
    $m->AckNews();
    return $m->attrs['SeenNews'] ?? '<<fehlt>>';
}
check('AckNews: installierte Version (mit Beta-Suffix) -> SeenNews ohne Suffix', ackNewsSets('2.36.0-beta.2') === '2.36.0', ackNewsSets('2.36.0-beta.2'));
check('AckNews: IPS_GetLibrary liefert nichts -> Fallback auf höchsten NEWS_VERSIONS-Schlüssel', ackNewsSets(null) === '2.36.0', ackNewsSets(null));
$GLOBALS['LIBVER'] = null;

// ---------------- GetVehicleState(): vehicleStatus (contractVersion 1.6) ----------------
echo "== GetVehicleState ==\n";
function vehicleState(?string $status): array
{
    $m = new TessieVehicle(5);
    if ($status !== null) {
        $m->attrs['LastVehicleStatus'] = $status;
    }
    return json_decode($m->GetVehicleState(), true);
}
$s = vehicleState(null);
check('GetVehicleState: contractVersion 1.6', ($s['contractVersion'] ?? null) === '1.6', var_export($s['contractVersion'] ?? null, true));
check('GetVehicleState: vehicleStatus null vor erster Antwort', array_key_exists('vehicleStatus', $s) && $s['vehicleStatus'] === null, var_export($s['vehicleStatus'] ?? '<<fehlt>>', true));
$s = vehicleState('asleep');
check('GetVehicleState: vehicleStatus "asleep" nach Update()', ($s['vehicleStatus'] ?? null) === 'asleep', var_export($s['vehicleStatus'] ?? null, true));

// ---------------- reconcileOrderWithLivePositions() (SUITE.md 9m) ----------------
echo "== Positions-Reconcile (SUITE.md 9m) ==\n";
function reconciledIdents(array $storedOrderIdents, array $livePositions): array
{
    $m = new TessieVehicle(5);
    $GLOBALS['IDENTMAP'] = [];
    $GLOBALS['OBJPOS'] = [];
    $vid = 100;
    foreach ($livePositions as $ident => $pos) {
        $GLOBALS['IDENTMAP'][$ident] = $vid;
        $GLOBALS['OBJPOS'][$vid] = $pos;
        $vid++;
    }
    $rows = array_map(fn($ident) => ['Ident' => $ident], $storedOrderIdents);
    $r = new ReflectionMethod($m, 'reconcileOrderWithLivePositions');
    $result = $r->invoke($m, $rows);
    return array_map(fn($row) => $row['Ident'], $result);
}
// Gespeicherte Reihenfolge A,B,C - Nutzer hat im Objektbaum auf C,A,B umsortiert.
$out = reconciledIdents(['A', 'B', 'C'], ['C' => 10, 'A' => 20, 'B' => 30]);
check('Reconcile: Objektbaum-Umsortierung schlägt durch (C,A,B statt gespeichert A,B,C)', $out === ['C', 'A', 'B'], implode(',', $out));
// Unveränderte Live-Positionen entsprechen der gespeicherten Reihenfolge -> keine Änderung.
$out = reconciledIdents(['A', 'B', 'C'], ['A' => 10, 'B' => 20, 'C' => 30]);
check('Reconcile: unveränderte Live-Positionen -> Reihenfolge bleibt A,B,C', $out === ['A', 'B', 'C'], implode(',', $out));
// D ist noch nie angelegt (z.B. Telemetrie noch nie aktiviert) - bleibt an seinem relativen Platz, ans Ende gebündelt.
$out = reconciledIdents(['A', 'D', 'B'], ['A' => 20, 'B' => 10]);
check('Reconcile: nie angelegte Variable D bleibt hinten, echte nach Live-Position (B,A,D)', $out === ['B', 'A', 'D'], implode(',', $out));

// End-to-End: wirkt sich die Reconciliation tatsaechlich auf das ausgelieferte Formular aus?
$GLOBALS['IDENTMAP'] = ['act_sentry' => 501, 'act_locked' => 502];
$GLOBALS['OBJPOS'] = [501 => 10, 502 => 20]; // act_sentry liegt live VOR act_locked, gespeichert ist es umgekehrt (act_locked zuerst)
$m = new TessieVehicle(5);
$form = json_decode($m->GetConfigurationForm(), true);
$visibleVarsFound = findEl($form['elements'], 'VisibleVars');
$idents = array_map(fn($r) => $r['Ident'] ?? '', $visibleVarsFound['el']['values'] ?? []);
$posLocked = array_search('act_locked', $idents, true);
$posSentry = array_search('act_sentry', $idents, true);
check('Reconcile (echtes Formular): act_sentry (live vorn) erscheint vor act_locked', $posSentry !== false && $posLocked !== false && $posSentry < $posLocked, "sentry@$posSentry locked@$posLocked");
$GLOBALS['IDENTMAP'] = [];
$GLOBALS['OBJPOS'] = [];

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails > 0 ? 1 : 0);
