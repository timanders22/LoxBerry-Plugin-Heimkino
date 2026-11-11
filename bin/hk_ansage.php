<?php
/**
 * Heimkino - Bruecke vom Python-Dienst zur gemeinsamen Sprachausgabe (seit 1.3.32).
 * Aufruf: php hk_ansage.php <tage> <pluginordner>. Legt nur hin und kehrt sofort zurueck; es spricht ein eigener Vorgang.
 * Ausgabe: eine ASCII-Zeile, nie ein Text oder Token. Rueckgabe 0 gelegt oder nichts zu tun, 1 nicht ausfuehrbar.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$hk_t0 = isset($argv[1]) ? (string) $argv[1] : '';
$hk_o = isset($argv[2]) ? (string) $argv[2] : '';
if (preg_match('/^-?[0-9]{1,6}\z/', $hk_t0) !== 1 || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $hk_o) !== 1) {
    echo "ANSAGE;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
// 1.3.37: als LoxBerry-Wurzel gilt nur ein Ordner mit config/plugins, data/plugins und config/system/general.json.
// Bis 1.3.36 genuegte config/plugins, und der Rueckfall (drei Ebenen ueber bin/plugins/<ordner>) galt ungeprueft.
$hk_wurzel = function ($h) {
    return is_string($h) && $h !== '' && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')
        && is_file($h . '/config/system/general.json');
};
$hk_h = getenv('LBHOMEDIR');
if (!$hk_wurzel($hk_h)) {
    $hk_h = dirname(dirname(dirname(__DIR__)));
    if (!$hk_wurzel($hk_h)) {
        echo "ANSAGE;STAND=0;KENNUNG=WURZEL\n";
        exit(1);
    }
}
$hk_lib = $hk_h . '/webfrontend/htmlauth/plugins/' . $hk_o . '/hk_lib.php';
if (!is_file($hk_lib)) {
    echo "ANSAGE;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
putenv('LBHOMEDIR=' . $hk_h);
putenv('LBPPLUGINDIR=' . $hk_o);
require_once $hk_lib;
echo 'ANSAGE;STAND=1;KENNUNG=' . strtoupper((string) hk_ansage_xbox((int) $hk_t0)) . "\n";
exit(0);
