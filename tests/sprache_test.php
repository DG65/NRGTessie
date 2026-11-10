<?php
// Prüfstand Zweisprachigkeit DE/EN (SUITE.md Store-Checkliste Regel 9).
// Aufruf: php tests/sprache_test.php            -> statische Prüfungen + deutscher Lauf, danach englischer Lauf
//         TESSIE_TEST_LANG=en php tests/sprache_test.php en -> nur der englische Teil
// Exit-Code 1 bei Fehler.
declare(strict_types=1);

$mode = $argv[1] ?? 'all';
if ($mode === 'all') {
    // Jeder Lauf in eigenem Prozess: die Übersetzungs-Caches der Module (t()) gelten pro Prozess.
    $rc = 0;
    foreach ([['de', 'static'], ['de', 'de'], ['en', 'en']] as [$lang, $part]) {
        $cmd = 'TESSIE_TEST_LANG=' . $lang . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $part;
        passthru($cmd, $code);
        $rc = $rc || $code;
    }
    exit($rc ? 1 : 0);
}

require __DIR__ . '/stubs.php';
foreach (['SWITCH', 'SLIDER', 'VALUE_PRESENTATION', 'VALUE_INPUT', 'ENUMERATION'] as $p) {
    if (!defined('VARIABLE_PRESENTATION_' . $p)) { define('VARIABLE_PRESENTATION_' . $p, '{TEST-' . $p . '}'); }
}
$root = dirname(__DIR__);
require $root . '/TessieVehicleTile/module.php';
require $root . '/TessieVehicle/module.php';
require $root . '/TessieConfigurator/module.php';

$fails = 0; $checks = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $fails, $checks;
    $checks++;
    if (!$ok) { $fails++; }
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . ($ok ? '' : '  -> ' . $detail) . "\n";
}

const MODULE_DIRS = ['TessieVehicle', 'TessieVehicleTile', 'TessieConfigurator'];
// Erlaubte "deutsche" Literale im Code: Kennungen, Legacy-Erkennung, Eingabe-Schreibweisen, Links
const ALLOWED_GERMAN = ['Laden', 'Klima', 'Sicherheit', 'Sonstiges', 'Front-Trunk öffnen', 'Rear-Trunk öffnen/schließen', 'ein', 'ja', 'an'];
// Texte, die in beiden Sprachen gleich lauten und deshalb keinen Locale-Eintrag brauchen
const SAME_IN_BOTH = ['Name', 'Ident', 'Icon', 'Radius (m)', 'Arial', 'Verdana', 'Tahoma', 'Trebuchet MS', 'HomeLink', 'Version %s:', 'VIN %s'];
const DE_WORDS = 'der die das und oder nicht mit für ein eine ist wird werden bei auf von zu zum zur im am aus noch kein keine nur aktiv fahrzeug laden ladung standort fehler klima zugang schlüssel einstellung regel kachel hat sind sich als wenn dann beim nach über unter wieder alle jede gelöscht sowie dein deine nutzer hinweis meldung datenpunkt wert werte liste zeile bitte gleich gesendet verbindung fahrzeuge ausschalten einschalten umschalten sonstiges sicherheit unterwegs abwesend zuhause hause wach gespeichert akzeptiert abgelehnt gefunden gesucht änderungen übernehmen schaltfläche schaltflächen neue neuer bearbeiten speichern abbrechen funktion beschriftung eigene suchen pfad aktion verriegelt entriegelt innen außen sicher fertig oben unten leer lassen kern instanz formular koordinaten eintragen';

function deWords(): array { static $w = null; return $w ??= array_flip(explode(' ', DE_WORDS)); }
function looksGerman(string $s): bool
{
    if (preg_match('/[äöüÄÖÜß]/u', $s)) { return true; }
    if (preg_match('#^(https?://|[A-Za-z_]+\()#', $s)) { return false; }
    foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if (isset(deWords()[$w])) { return true; }
    }
    return false;
}
function locale(string $dir): array
{
    $f = dirname(__DIR__) . "/$dir/locale.json";
    return file_exists($f) ? (json_decode(file_get_contents($f), true)['translations']['de'] ?? []) : [];
}
function placeholders(string $s): array
{
    preg_match_all('/%(?:\d+\$)?[sd]|%%/', $s, $m); sort($m[0]); return $m[0];
}
/** Alle String-Literale (ohne Kommentare) einer PHP-Datei: [Zeile => Text] */
function phpStrings(string $file): array
{
    $out = [];
    foreach (token_get_all(file_get_contents($file)) as $t) {
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $q = $t[1][0];
            $inner = substr($t[1], 1, -1);
            $inner = $q === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner) : stripcslashes($inner);
            $out[] = [$t[2], $inner];
        }
    }
    return $out;
}
function jsonCaptions($o, array &$acc): void
{
    if (is_array($o)) {
        foreach ($o as $k => $v) {
            if (in_array($k, ['caption', 'label', 'confirm', 'title', 'placeholder'], true) && is_string($v) && trim($v) !== '') { $acc[] = $v; }
            else { jsonCaptions($v, $acc); }
        }
    }
}
function conso($o, array $loc)
{
    if (!is_array($o)) { return $o; }
    foreach ($o as $k => $v) {
        if (in_array($k, ['caption', 'label', 'confirm', 'title'], true) && is_string($v) && isset($loc[$v])) { $o[$k] = $loc[$v]; }
        else { $o[$k] = conso($v, $loc); }
    }
    return $o;
}

if ($mode === 'static') {
    echo "== Statisch (sprachunabhängig) ==\n";
    foreach (MODULE_DIRS as $dir) {
        $loc = locale($dir);
        $php = "$root/$dir/module.php";

        // 1) kein Deutsch im Code
        $bad = [];
        foreach (phpStrings($php) as [$line, $s]) {
            if (in_array($s, ALLOWED_GERMAN, true) || str_contains($s, 'community.symcon.de')) { continue; }
            if (looksGerman($s)) { $bad[] = "$line: " . mb_substr($s, 0, 60); }
        }
        check("$dir/module.php: kein deutscher Text im Code (außer Kennungen)", count($bad) === 0, implode(' | ', array_slice($bad, 0, 5)));

        // 2) Formular-Dateien und Kachel-HTML
        foreach (["$dir/form.json" => 'json', "$dir/module.html" => 'html'] as $rel => $kind) {
            $f = "$root/$rel";
            if (!file_exists($f)) { continue; }
            $strings = [];
            if ($kind === 'json') { jsonCaptions(json_decode(file_get_contents($f), true), $strings); }
            else {
                $h = file_get_contents($f);
                preg_match_all('/>([^<>{}]*[A-Za-z][^<>{}]*)</', explode('<script', $h)[0], $m1);
                preg_match_all('/(?:title|placeholder)="([^"]+)"/', $h, $m2);
                preg_match_all("/\\bT\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*\\)/", $h, $m3);
                $strings = array_merge(array_map('trim', $m1[1]), $m2[1], $m3[1]);
            }
            $de = array_filter($strings, 'looksGerman');
            check("$rel: kein deutscher Text (englische Quelltexte)", count($de) === 0, implode(' | ', array_slice(array_map(fn($x) => mb_substr($x, 0, 50), $de), 0, 4)));
            $miss = array_values(array_unique(array_filter($strings, fn($s) => !isset($loc[$s]) && !in_array(trim($s), SAME_IN_BOTH, true))));
            check("$rel: jeder Text steht in locale.json", count($miss) === 0, implode(' | ', array_slice(array_map(fn($x) => mb_substr($x, 0, 60), $miss), 0, 4)));
        }

        // 3) PHP: Translate()/t()-Aufrufe und statische Formular-Captions stehen in locale.json
        $src = file_get_contents($php);
        preg_match_all("/(?:->t|->Translate)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m);
        preg_match_all("/'(?:caption|label|confirm)'\\s*=>\\s*(?:\\\$[a-z]+\\s*\\?\\s*)?'((?:[^'\\\\]|\\\\.)*)'/", $src, $m2);
        $need = array_map(fn($x) => str_replace("\\'", "'", $x), array_merge($m[1], $m2[1]));
        $need = array_filter($need, fn($x) => preg_match('/[A-Za-z]{2}/', $x) && !str_starts_with($x, '#') && !in_array($x, SAME_IN_BOTH, true));
        $miss = array_values(array_unique(array_filter($need, fn($x) => !isset($loc[$x]) && !isset(TessieTelemetryNamesEn::NAMES[$x]))));
        check("$dir/module.php: Translate()-Quelltexte und Formular-Captions stehen in locale.json", count($miss) === 0, implode(' | ', array_slice(array_map(fn($x) => mb_substr($x, 0, 60), $miss), 0, 5)));

        // 4) Platzhalter in Quelle und Übersetzung gleich
        $badPh = [];
        foreach ($loc as $en => $de) {
            if (placeholders($en) !== placeholders($de)) { $badPh[] = mb_substr($en, 0, 50); }
        }
        check("$dir/locale.json: Platzhalter (%s, %d, %%) in Englisch und Deutsch gleich", count($badPh) === 0, implode(' | ', $badPh));

        // 5) keine verwaisten Einträge (außer Telemetrie-Schlüssel der Fahrzeug-Instanz)
        $all = $src . (file_exists("$root/$dir/form.json") ? file_get_contents("$root/$dir/form.json") : '') . (file_exists("$root/$dir/module.html") ? file_get_contents("$root/$dir/module.html") : '');
        $orph = [];
        foreach ($loc as $en => $de) {
            if (isset(TessieTelemetryNamesEn::NAMES[$en]) || in_array($en, ['On the road', 'Away'], true)) { continue; }
            $needle = json_encode($en, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $needleBody = substr($needle, 1, -1);
            $variants = [$en, str_replace("'", "\\'", $en), $needleBody, str_replace('"', '\\"', $en)];
            $found = false;
            foreach ($variants as $v) { if ($v !== '' && str_contains($all, $v)) { $found = true; break; } }
            if (!$found) { $orph[] = mb_substr($en, 0, 50); }
        }
        check("$dir/locale.json: keine verwaisten Einträge", count($orph) === 0, implode(' | ', array_slice($orph, 0, 6)));
    }

    // Kachel: Textliste im PHP == Texte in module.html
    $ref = new ReflectionClassConstant(TessieVehicleTile::class, 'KACHEL_TEXTE');
    $list = $ref->getValue();
    $h = file_get_contents("$root/TessieVehicleTile/module.html");
    [$head, , $script] = [explode('<script', $h)[0], 0, substr($h, strpos($h, '<script'))];
    $keys = [];
    preg_match_all('/>([^<>{}]*[A-Za-z][^<>{}]*)</', $head, $a); foreach ($a[1] as $x) { $keys[] = trim($x); }
    preg_match_all('/(?:title|placeholder)="([^"]*[A-Za-z][^"]*)"/', $head, $b); foreach ($b[1] as $x) { $keys[] = $x; }
    preg_match_all("/\\bT\\(\\s*(?:'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\")\\s*\\)/", $script, $c);
    foreach ($c[1] as $i => $x) { $keys[] = str_replace(["\\'", '\\"'], ["'", '"'], $x !== '' ? $x : $c[2][$i]); }
    preg_match_all("/\\{\\s*v:\\s*'[a-z]+',\\s*c:\\s*'([^']+)'\\s*\\}/", $script, $d); foreach ($d[1] as $x) { $keys[] = $x; }
    $keys = array_values(array_unique($keys)); sort($keys);
    $l2 = $list; sort($l2);
    check('Kachel: KACHEL_TEXTE (PHP) == Texte in module.html', $keys === $l2, 'nur HTML: ' . implode(' | ', array_slice(array_diff($keys, $l2), 0, 4)) . ' / nur PHP: ' . implode(' | ', array_slice(array_diff($l2, $keys), 0, 4)));

    // Telemetrie: englische Tabelle deckt alle deutschen Schlüssel ab
    $loc = locale('TessieVehicle');
    $teleDe = array_filter(array_keys($loc), fn($k) => isset(TessieTelemetryNamesEn::NAMES[$k]));
    $apiKeys = array_filter(array_keys($loc), fn($k) => preg_match('/^[A-Z][A-Za-z0-9]+$/', $k) && !str_contains($k, ' ') && strlen($k) > 3 && preg_match('/[a-z][A-Z]|^[A-Z][a-z]+[0-9]|^[A-Z]{2}[a-z]/', $k));
    $gapEn = array_diff(array_keys(TessieTelemetryNamesEn::NAMES), array_keys($loc));
    check('Telemetrie: jeder englische Name hat eine deutsche Entsprechung (und umgekehrt)', count($gapEn) === 0 && count($teleDe) === count(TessieTelemetryNamesEn::NAMES), implode(',', array_slice($gapEn, 0, 5)));

    // Auswertung gespeicherter Telemetrie-Texte: nur der gemeinte Zustand enthält das Stichwort
    $N = TessieTelemetryNamesEn::NAMES;
    foreach (['en' => $N, 'de' => array_intersect_key($loc, $N)] as $lang => $tbl) {
        $charging = $lang === 'en' ? 'charging' : mb_strtolower($loc['charging']);
        $hits = array_keys(array_filter($tbl, fn($v, $k) => str_starts_with($k, 'DetailedChargeState') && $k !== 'DetailedChargeState' && str_contains(mb_strtolower($v), $charging), ARRAY_FILTER_USE_BOTH));
        check("Ladestatus ($lang): nur 'Charging' enthält das Stichwort '$charging'", $hits === ['DetailedChargeStateCharging'], implode(',', $hits));
        $off = $lang === 'en' ? 'off' : mb_strtolower($loc['off']);
        $hits = array_keys(array_filter($tbl, fn($v, $k) => str_starts_with($k, 'ScheduledChargingMode') && $k !== 'ScheduledChargingMode' && str_contains(mb_strtolower($v), $off), ARRAY_FILTER_USE_BOTH));
        check("Geplantes Laden ($lang): nur 'Off' enthält das Stichwort '$off'", $hits === ['ScheduledChargingModeOff'], implode(',', $hits));
    }

    // Kennungen: die Kategorie-Konstanten bleiben unverändert (daraus entstehen die Idents der Kategorien)
    $rc = new ReflectionClass(TessieVehicle::class);
    check('Kennungen: PURPOSE_*-Konstanten unverändert (Laden/Klima/Sicherheit/Sonstiges)',
        [$rc->getConstant('PURPOSE_CHARGING'), $rc->getConstant('PURPOSE_CLIMATE'), $rc->getConstant('PURPOSE_SECURITY'), $rc->getConstant('PURPOSE_OTHER')] === ['Laden', 'Klima', 'Sicherheit', 'Sonstiges']);
    echo "\n$checks Prüfungen, $fails Fehler\n";
    exit($fails > 0 ? 1 : 0);
}

// ----------------------------------------------------------------------------------------------
// Formular-/Nutzdaten erzeugen (de: Konsole simulieren und gegen die Referenzausgabe des Moduls vor der
// Umstellung vergleichen; en: Formular ohne Übersetzung auf deutsche Reste prüfen)
// ----------------------------------------------------------------------------------------------
function formOf(string $mod): string
{
    $GLOBALS['INST'] = [111 => 'Mein Tesla'];
    $GLOBALS['STATE'] = [111 => json_encode(['vin' => '5YJ', 'contractVersion' => '1.6', 'soc' => 82.4, 'socID' => 12345, 'connected' => true])];
    $GLOBALS['SYSLOC'] = null;
    $m = new $mod(5);
    if ($mod === 'TessieVehicle') { $m->props = ['HomeLocation' => '', 'HomeRadius' => 150, 'VIN' => 'X', 'HomeDetection' => true, 'UpdateInterval' => 300]; }
    $form = json_decode($m->GetConfigurationForm(), true);
    if ($GLOBALS['LANG'] === 'de') { $form = conso($form, locale($mod)); }
    return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
function payloadOf(int $charging): string
{
    $vars = ['act_charging' => [1, (bool)$charging], 'act_locked' => [2, true], 'act_climate' => [3, false], 'act_sentry' => [4, true], 'act_valet' => [5, false],
             'act_honk' => [6, false], 'act_vent_windows' => [7, false], 'act_cop_enabled' => [8, true], 'act_front_trunk' => [9, false], 'stat_tel_Soc' => [10, 82.4], 'stat_location_name' => [11, 'Zuhause']];
    $GLOBALS['IDENTMAP'] = []; $GLOBALS['VALUES'] = [];
    foreach ($vars as $ident => [$id, $v]) { $GLOBALS['IDENTMAP'][$ident] = $id; $GLOBALS['VALUES'][$id] = $v; }
    $GLOBALS['INST'] = [111 => 'Mein Tesla'];
    $m = new TessieVehicleTile(5);
    $m->props = ['SourceInstance' => 111, 'ShowControls' => true, 'ShowAutomations' => false,
        'Buttons' => '[{"Ident":"act_locked","Label":""},{"Ident":"act_climate","Label":""},{"Ident":"act_charging","Label":""},{"Ident":"act_sentry","Label":""},{"Ident":"act_valet","Label":""},{"Ident":"act_honk","Label":""},{"Ident":"act_vent_windows","Label":""},{"Ident":"act_cop_enabled","Label":""},{"Ident":"act_front_trunk","Label":""}]'];
    $r = new ReflectionMethod($m, 'GetFullUpdateMessage');
    $msg = json_decode($r->invoke($m), true);
    unset($msg['texts'], $msg['locale']);
    $out = ['msg' => $msg, 'catalog' => json_decode($m->GetButtonCatalog(), true)];
    $GLOBALS['IDENTMAP'] = []; $GLOBALS['VALUES'] = [];
    return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

if ($mode === 'de') {
    echo "== Deutsch: Ausgabe identisch zur Referenz vor der Umstellung (tests/golden) ==\n";
    foreach (MODULE_DIRS as $mod) {
        $golden = file_get_contents(__DIR__ . "/golden/{$mod}_de.json");
        $now = formOf($mod);
        if (getenv('TESSIE_DUMP_DIR')) { file_put_contents(getenv('TESSIE_DUMP_DIR') . "/{$mod}_de.json", $now); }
        check("$mod: deutsches Formular unverändert", $now === $golden, 'Abweichung - Zeilen: ' . implode(',', array_slice(array_keys(array_diff_assoc(explode("\n", $now), explode("\n", $golden))), 0, 6)));
    }
    foreach ([0, 1] as $c) {
        $golden = file_get_contents(__DIR__ . "/golden/TessieVehicleTile_payload_charging$c.json");
        $now = payloadOf($c);
        check("Kachel-Nutzdaten (lädt=$c): Beschriftungen und Buttons unverändert", $now === $golden, 'Abweichung');
    }
    // Kachel-Texte kommen vollständig und deutsch an
    $m = new TessieVehicleTile(5);
    $r = new ReflectionMethod($m, 'GetFullUpdateMessage');
    $first = json_decode($r->invoke($m, true), true);
    $nTexte = count((new ReflectionClassConstant(TessieVehicleTile::class, 'KACHEL_TEXTE'))->getValue());
    check('Kachel: erste Nachricht enthält alle ' . $nTexte . ' Texte', count($first['texts'] ?? []) === $nTexte, count($first['texts'] ?? []) . ' statt ' . $nTexte);
    check('Kachel: texts[Home] = Zuhause, locale = de-DE', ($first['texts']['Home'] ?? '') === 'Zuhause' && ($first['locale'] ?? '') === 'de-DE');
    // Sprach-Abhängigkeiten der Auswertung (Stichwörter aus locale.json)
    $v = new TessieVehicle(5);
    $t = new ReflectionMethod($v, 't');
    check('Stichwort "charging" -> lädt', $t->invoke($v, 'charging') === 'lädt');
    $rc = new ReflectionMethod($v, 'isChargingActive');
    foreach (['Ladestatus: lädt' => true, 'Ladestatus: vollständig geladen' => false, 'Ladestatus: keine Ladeleistung' => false, 'Ladestatus: getrennt' => false] as $txt => $expect) {
        $GLOBALS['IDENTMAP'] = ['stat_tel_DetailedChargeState' => 50]; $GLOBALS['VALUES'] = [50 => $txt];
        check("Ladeerkennung (de): '$txt' -> " . ($expect ? 'lädt' : 'lädt nicht'), $rc->invoke($v) === $expect);
    }
    $rs = new ReflectionMethod($v, 'isScheduledChargingActive');
    foreach (['Geplantes Laden: aus' => false, 'Geplantes Laden: Startzeit' => true, 'Geplantes Laden: nach Abfahrtszeit' => true] as $txt => $expect) {
        $GLOBALS['IDENTMAP'] = ['stat_tel_ScheduledChargingMode' => 51]; $GLOBALS['VALUES'] = [51 => $txt];
        check("Geplantes Laden (de): '$txt' -> " . ($expect ? 'aktiv' : 'nicht aktiv'), $rs->invoke($v) === $expect);
    }
    $GLOBALS['IDENTMAP'] = []; $GLOBALS['VALUES'] = [];
    echo "\n$checks Prüfungen, $fails Fehler\n";
    exit($fails > 0 ? 1 : 0);
}

if ($mode === 'en') {
    echo "== Englisch: keine deutschen Reste ==\n";
    foreach (MODULE_DIRS as $mod) {
        $form = json_decode(formOf($mod), true);
        $strings = [];
        jsonCaptions($form, $strings);
        // Datenzeilen der Liste (Namen/Gruppen)
        $walk = function ($o) use (&$walk, &$strings) {
            if (is_array($o)) { foreach ($o as $k => $v) { if (in_array($k, ['Name', 'Gruppe'], true) && is_string($v)) { $strings[] = $v; } else { $walk($v); } } }
        };
        $walk($form);
        $de = array_values(array_unique(array_filter($strings, 'looksGerman')));
        check("$mod: englisches Formular ohne deutsche Reste", count($de) === 0, implode(' | ', array_slice(array_map(fn($x) => mb_substr($x, 0, 60), $de), 0, 5)));
    }
    $p = json_decode(payloadOf(1), true);
    $labels = [$p['msg']['stateLabel']];
    foreach ($p['msg']['buttons'] as $b) { $labels[] = $b['c']; $labels[] = $b['n']; }
    foreach ($p['catalog'] as $c) { $labels[] = $c['c']; }
    $de = array_values(array_filter($labels, 'looksGerman'));
    check('Kachel-Nutzdaten (en): Beschriftungen ohne deutsche Reste', count($de) === 0, implode(' | ', $de));
    check('Kachel (en): Zustand "Charging", Button "Stop charging"', $p['msg']['stateLabel'] === 'Charging' && in_array('Stop charging', $labels, true));

    // Telemetrie-Namen: Englisch aus der Tabelle, keine Rohschlüssel
    $v = new TessieVehicle(5);
    $f = new ReflectionMethod($v, 'translateTelemetryKey');
    $raw = []; foreach (array_keys(TessieTelemetryNamesEn::NAMES) as $k) { if ($f->invoke($v, $k) !== TessieTelemetryNamesEn::NAMES[$k]) { $raw[] = $k; } }
    check('Telemetrie (en): alle ' . count(TessieTelemetryNamesEn::NAMES) . ' Schlüssel liefern den englischen Namen', count($raw) === 0, implode(',', array_slice($raw, 0, 5)));
    $de = array_values(array_filter(TessieTelemetryNamesEn::NAMES, 'looksGerman'));
    check('Telemetrie (en): englische Namen ohne deutsche Wörter', count($de) === 0, implode(' | ', array_slice($de, 0, 4)));
    // Auswertung gespeicherter Telemetrie-Texte: Laden wird erkannt, andere Zustände nicht
    $rc = new ReflectionMethod($v, 'isChargingActive');
    foreach (['Charge state: charging' => true, 'Charge state: complete' => false, 'Charge state: no power' => false, 'Charge state: disconnected' => false] as $txt => $expect) {
        $GLOBALS['IDENTMAP'] = ['stat_tel_DetailedChargeState' => 50]; $GLOBALS['VALUES'] = [50 => $txt];
        check("Ladeerkennung (en): '$txt' -> " . ($expect ? 'lädt' : 'lädt nicht'), $rc->invoke($v) === $expect);
    }
    $rs = new ReflectionMethod($v, 'isScheduledChargingActive');
    foreach (['Scheduled charging: off' => false, 'Scheduled charging: start time' => true, 'Scheduled charging: by departure time' => true] as $txt => $expect) {
        $GLOBALS['IDENTMAP'] = ['stat_tel_ScheduledChargingMode' => 51]; $GLOBALS['VALUES'] = [51 => $txt];
        check("Geplantes Laden (en): '$txt' -> " . ($expect ? 'aktiv' : 'nicht aktiv'), $rs->invoke($v) === $expect);
    }
    $GLOBALS['IDENTMAP'] = []; $GLOBALS['VALUES'] = [];
    echo "\n$checks Prüfungen, $fails Fehler\n";
    exit($fails > 0 ? 1 : 0);
}
