<?php
/**
 * Heimkino - Aktionsendpunkt fuer den Miniserver
 *
 * Liegt bewusst im unangemeldeten Bereich, damit Loxone ihn ohne
 * Zugangsdaten aufrufen kann - aber jeder Aufruf braucht das Token aus den
 * Einstellungen. Ohne Token wird nichts ausgefuehrt: sonst koennte jedes
 * Geraet im Netz den Beamer ausschalten.
 *
 * Aufruf:
 *   /plugins/heimkino/index.php?token=<TOKEN>&aktion=beamer-aus
 *
 * Antwort: Klartext, eine Zeile. HTTP 200 bei Erfolg, sonst 400/403/500.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

/* Die Bibliothek liegt im ANGEMELDETEN Bereich, dieser Endpunkt nicht.
 *
 * Bis 1.2.10 stand hier schlicht __DIR__ . '/../htmlauth/hk_lib.php'. Das
 * stimmt im ausgepackten Archiv, wo html/ und htmlauth/ nebeneinanderliegen -
 * auf einem installierten LoxBerry aber nicht: dort werden beide in getrennte
 * Baeume gelegt (webfrontend/html/plugins/<ordner>/ und
 * webfrontend/htmlauth/plugins/<ordner>/). Der Pfad zeigte deshalb ins Leere,
 * PHP brach mit einem schweren Fehler ab, und der Aufrufer bekam eine leere
 * Antwort mit HTTP 500 - ohne jeden Hinweis, was fehlt.
 *
 * Belegt am 15.08.2026: beide Loxone-Ausgaenge (Beamer aus, Xbox wecken)
 * liefen seit jeher in genau diesen 500er. Dieselbe Kandidatensuche benutzt
 * das Intercom-Plugin seit laengerem aus demselben Grund.
 */
$hk_lib_gefunden = false;
foreach (array(
    dirname(dirname(dirname(__DIR__))) . '/htmlauth/plugins/' . basename(__DIR__) . '/hk_lib.php',
    dirname(dirname(__DIR__)) . '/htmlauth/plugins/' . basename(__DIR__) . '/hk_lib.php',
    dirname(__DIR__) . '/htmlauth/hk_lib.php',
) as $hk_kandidat) {
    if (is_file($hk_kandidat)) {
        require_once $hk_kandidat;
        $hk_lib_gefunden = true;
        break;
    }
}
if (!$hk_lib_gefunden) {
    /* Sagen, was fehlt, statt mit einem leeren 500 zu enden. Diesen Endpunkt
     * ruft der Miniserver auf - dort sieht niemand ein Apache-Protokoll. */
    http_response_code(500);
    echo "hk_lib.php nicht gefunden. Erwartet unter htmlauth/plugins/"
         . basename(__DIR__) . "/hk_lib.php\n";
    echo "Abhilfe: Plugin neu installieren.\n";
    exit;
}

function hk_ende($code, $text)
{
    http_response_code($code);
    echo $text . "\n";
    exit;
}

$cfg = hk_config_read();

if (!hk_an($cfg, 'heimkino', 'enabled')) {
    hk_ende(503, 'Das Plugin ist in den Einstellungen abgeschaltet.');
}

$soll = hk_cfg($cfg, 'heimkino', 'aktionstoken', '');
if ($soll === '') {
    hk_ende(403, 'Kein Aktionstoken eingerichtet. Reiter Einstellungen aufrufen '
                 . 'und einmal speichern - dann wird eines erzeugt.');
}

$ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
// hash_equals vergleicht in gleichbleibender Zeit; ein einfaches == liesse
// sich ueber die Antwortzeit Zeichen fuer Zeichen erraten.
if (!hash_equals($soll, $ist)) {
    hk_ende(403, 'Token falsch.');
}

/* ---------- Selbsttest: Token pruefen, ohne etwas auszuloesen ----------
 * Hausregel: jeder Aktionsendpunkt beantwortet ?selftest=1&token=... , ohne
 * dass etwas passiert. Sonst laesst sich nicht feststellen, ob die Adresse im
 * Miniserver noch stimmt, ohne wirklich zu schalten.
 */
if (isset($_GET['selftest'])) {
    hk_ende(200, 'SELFTEST;OK=1;TOKEN=OK');
}

$aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : '';
$erlaubt = array_keys(hk_aktionen());
$mit_wert = array('beamer-taste', 'beamer-eingang');

if (in_array($aktion, $erlaubt, true)) {
    list($code, $ausgabe) = hk_cmd(array($aktion));
} elseif (in_array($aktion, $mit_wert, true)) {
    $wert = isset($_GET['wert']) ? (string) $_GET['wert'] : '';
    // Nur Buchstaben, Ziffern und Unterstrich - alles andere hat in einem
    // Geraetebefehl nichts zu suchen.
    if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $wert)) {
        hk_ende(400, 'Der Wert enthaelt unerlaubte Zeichen.');
    }
    list($code, $ausgabe) = hk_cmd(array($aktion, $wert));
} else {
    hk_ende(400, 'Unbekannte Aktion. Erlaubt: '
                 . implode(', ', array_merge($erlaubt, $mit_wert)));
}

if ($code === 0) {
    hk_ende(200, trim($ausgabe) !== '' ? trim($ausgabe) : 'OK');
}
hk_ende(500, trim($ausgabe) !== '' ? trim($ausgabe) : 'Fehler');
