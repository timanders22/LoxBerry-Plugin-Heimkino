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

require_once __DIR__ . '/../htmlauth/hk_lib.php';

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
