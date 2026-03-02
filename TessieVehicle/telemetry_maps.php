<?php
// Referenzdatei: feste Zuordnung (Key -> Profil/Typ). Das Modul nutzt die Konstanten in module.php.

// Beispiel: Innenraum-Überhitzeschutz
private const TELEMETRY_PROFILE_MAP = [
    'CabinOverheatProtectionMode' => 'Tessie.CabinOverheatProtectionMode',
    'CabinOverheatProtectionTemperatureLimit' => 'Tessie.CabinOverheatProtectionTempLimit'
];

private const TELEMETRY_TYPE_MAP = [
    'CabinOverheatProtectionMode' => VARIABLETYPE_INTEGER,
    'CabinOverheatProtectionTemperatureLimit' => VARIABLETYPE_INTEGER
];
