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
 *   /plugins/heimkino/index.php?selftest=1&token=<TOKEN>
 *
 * Antwort: Klartext, eine Zeile. HTTP 200 bei Erfolg - auch mit
 * UNVERAENDERT=1, wenn derselbe Sollwert innerhalb von 60 s schon hinausging
 * (dann wird nichts gesendet) -, sonst 400/403/429/500/503.
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
 * liefen seit jeher in genau diesen 500er.
 */
/* Seit 1.3.14 genau EIN Kandidat, je nach Lage (Bauart ZendureSolarFlow
 * 0.9.26): installiert (<...>/html/plugins/<ordner>) der Nachbarbaum,
 * sonst das ausgepackte Archiv. Bis 1.3.13 wurden drei der Reihe nach
 * probiert; aus einem Archiv dicht unter "/" war der erste
 * //htmlauth/plugins/html/hk_lib.php ab der Laufwerkswurzel, und was dort
 * laege, liefe als Bibliothek (Muster 2 der Nachlese; gelesen). */
$hk_lib_gefunden = false;
if (basename(dirname(__DIR__)) === 'plugins') {
    $hk_kandidaten = array(dirname(dirname(dirname(__DIR__))) . '/htmlauth/plugins/' . basename(__DIR__) . '/hk_lib.php');
} else {
    $hk_kandidaten = array(dirname(__DIR__) . '/htmlauth/hk_lib.php');
}
foreach ($hk_kandidaten as $hk_kandidat) {
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

/* Jeder Ausgang schreibt eine Protokollzeile mit der Adresse des Anrufers,
 * gebremst auf eine je Minute und Grund (seit 1.3.15, C5; Regeln/03). Bis
 * 1.3.14 war "der Miniserver ruft nicht an" nicht von "er ruft an und wird
 * abgewiesen" zu unterscheiden (Befund code 11). Das Token steht nie darin. */
$hk_aktion_roh = (isset($_GET['aktion']) && is_string($_GET['aktion'])) ? $_GET['aktion'] : '';

function hk_ende($code, $text, $grund = 'ok')
{
    hk_endpunkt_protokoll($code, $grund, $GLOBALS['hk_aktion_roh']);
    http_response_code($code);
    echo $text . "\n";
    exit;
}

$cfg = hk_config_read();
$soll = hk_cfg($cfg, 'heimkino', 'aktionstoken', '');
// Ein Feld kann als Feld ankommen (?token[]=x). (string) darauf ergaebe
// "Array" samt Meldung - erst is_string, dann alles andere.
$ist = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';
// Wertmuster auch fuer das Token, mit \z statt $ (seit 1.3.15, C5;
// Regeln/05 "Eine Positivliste in PHP endet mit \z").
if (!preg_match('/^[A-Za-z0-9_.-]{1,64}\z/', $ist)) {
    $ist = '';
}

/* ---------- Selbsttest: Token pruefen, ohne etwas auszuloesen ----------
 *
 * Hausregel: jeder Aktionsendpunkt beantwortet ?selftest=1&token=..., ohne
 * dass etwas passiert. Sonst gibt es nur zwei schlechte Moeglichkeiten:
 * entweder man schaltet wirklich - dann faehrt der Beamer herunter -, oder
 * man erfaehrt nie, ob die Adresse im Miniserver noch stimmt.
 *
 * Drei Festlegungen, alle bis 1.2.11 nicht eingehalten:
 *
 * 1. Der WORTLAUT steht fest. Bis 1.2.11 kam bei falschem Token
 *    "Token falsch." und ohne eingerichtetes Token ein ganzer Ratschlag -
 *    eine maschinelle Pruefung, die auf SELFTEST;OK= sieht, bekam damit
 *    gerade im Fehlerfall nichts Verwertbares.
 * 2. Der Zweig steht VOR der Abschaltpruefung. Bis 1.2.11 antwortete ein
 *    abgeschaltetes Plugin mit 503, und das Token liess sich dann gar nicht
 *    mehr pruefen - also genau die Frage, fuer die der Selbsttest da ist.
 * 3. Geprueft wird der WERT, nicht nur das Vorhandensein. Bis 1.2.11 galt
 *    isset(): ?selftest=0 antwortete ebenfalls mit OK=1, und eine Adresse,
 *    an der versehentlich selftest=0 hing, blieb dauerhaft wirkungslos.
 *
 * Kein Geraetekontakt, kein Schreibzugriff. Der Selbsttest beantwortet genau
 * eine Frage: stimmt das Token.
 */
if (isset($_GET['selftest']) && is_string($_GET['selftest'])
    && $_GET['selftest'] === '1') {
    if ($soll === '') {
        hk_ende(403, 'SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET', 'selftest_kein_token');
    }
    // Dieselbe Abweisung wie sonst auch - der Selbsttest ist keine Abkuerzung
    // an der Sicherheit vorbei. hash_equals vergleicht in gleichbleibender
    // Zeit; ein einfaches == liesse sich ueber die Antwortzeit Zeichen fuer
    // Zeichen erraten.
    if (!hash_equals($soll, $ist)) {
        hk_ende(403, 'SELFTEST;OK=0;ERR=TOKEN', 'selftest_token_falsch');
    }
    hk_ende(200, 'SELFTEST;OK=1;TOKEN=OK', 'selftest');
}

if (!hk_an($cfg, 'heimkino', 'enabled')) {
    hk_ende(503, 'Das Plugin ist in den Einstellungen abgeschaltet.', 'abgeschaltet');
}

if ($soll === '') {
    hk_ende(403, 'Kein Aktionstoken eingerichtet. Reiter Einstellungen aufrufen '
                 . 'und einmal speichern - dann wird eines erzeugt.', 'kein_token');
}

if (!hash_equals($soll, $ist)) {
    hk_ende(403, 'Token falsch.', 'token_falsch');
}

$aktion = (isset($_GET['aktion']) && is_string($_GET['aktion'])) ? $_GET['aktion'] : '';
$erlaubt = array_keys(hk_aktionen());
$mit_wert = array_keys(hk_aktionen_mit_wert());

/* Der Mindestabstand fuer die Xbox (C10) steht seit dem Nachtrag vom
 * 01.10.2026 HINTER der Gleichwert-Unterdrueckung, wie bei EVCC: derselbe
 * Wert ist UNVERAENDERT=1, erst ein anderer Wert laeuft in die 10-s-Bremse. */

/* Erst die Aktion und ihren Wert pruefen, dann die Gleichwert-Unterdrueckung
 * (X-7), dann senden. Bis 1.3.17 wurde im selben Zweig geprueft und gesendet. */
$hk_wert = '';
if (in_array($aktion, $erlaubt, true)) {
    $hk_argumente = array($aktion);
} elseif (in_array($aktion, $mit_wert, true)) {
    $wert = (isset($_GET['wert']) && is_string($_GET['wert'])) ? $_GET['wert'] : '';
    // Nur Buchstaben, Ziffern und Unterstrich - alles andere hat in einem
    // Geraetebefehl nichts zu suchen. Gross- und Kleinschreibung bleibt
    // dabei ERHALTEN: der Bildmodus filmMaker der LG-Steuerung ist gemischt
    // geschrieben, und ein Kleinschreiben zerstoerte einen gueltigen Wert.
    // \z statt $ seit 1.3.15 (C5): "volumeup%0A" bestand das Muster, und der
    // Zeilenumbruch landete im Geraetebefehl (Befund code 6).
    if (!preg_match('/^[A-Za-z0-9_]{1,32}\z/', $wert)) {
        hk_ende(400, 'Der Wert enthaelt unerlaubte Zeichen.', 'wert');
    }
    $hk_wert = $wert;
    $hk_argumente = array($aktion, $wert);
} else {
    hk_ende(400, 'Unbekannte Aktion. Erlaubt: '
                 . implode(', ', array_merge($erlaubt, $mit_wert)), 'unbekannt');
}

/* Gleichwert-Unterdrueckung (X-7, B-Nachzug 01.10.2026, Entscheidung Nr. 19;
 * Vorbild EVCC 0.9.37 und Marstek 1.1.19). Sollwert-Befehle - Beamer aus,
 * Kino-Szene, Lautstaerke, Eingang, Bildmodus, Energiesparstufe, seit dem
 * Nachtrag auch Bild an/aus, Ton an/aus und Xbox an/aus - gehen mit DEMSELBEN
 * Wert innerhalb von 60 s nicht erneut hinaus: HTTP 200 mit UNVERAENDERT=1,
 * gesendet wird nichts. Tasten, Anwendungen und beamer-wol (Wake-on-LAN ohne
 * Rueckmeldung, Entscheidung Nr. 20) gehen immer hinaus; die Xbox-Bremse
 * bleibt (unten). Kein zusaetzliches 429. Der Merker bleibt waehrend des Befehls
 * gesperrt; laesst er sich nicht oeffnen, faellt es geschlossen aus (503). */
$hk_gw_schl = hk_gleichwert_schluessel($aktion);
$hk_gw = null;
$hk_gw_merker = array();
if ($hk_gw_schl !== '') {
    $hk_gw = hk_gleichwert_oeffnen($aktion);
    if ($hk_gw === false) {
        hk_ende(503, 'Die Gleichwert-Sperre ist nicht verfuegbar (Datenordner oder Merkerdatei '
                     . 'fehlt) - es wird nichts gesendet.', 'gleichwert_fehlt');
    }
    $hk_gw_merker = hk_gleichwert_lesen($hk_gw);
    $hk_seit = hk_gleichwert_seit($hk_gw_merker, $hk_gw_schl, hk_gleichwert_wert($aktion, $hk_wert));
    if ($hk_seit >= 0) {
        hk_gleichwert_schliessen($hk_gw, null);
        hk_ende(200, 'UNVERAENDERT=1;AKTION=' . $aktion . ($hk_wert !== '' ? ';WERT=' . $hk_wert : '')
                     . ';SEIT_S=' . $hk_seit . ' - derselbe Befehl ging vor ' . $hk_seit
                     . ' s hinaus; es wurde nichts gesendet.', 'unveraendert');
    }
}

/* Mindestabstand fuer die Xbox (seit 1.3.15, C10): jeder Aufruf geht an die
 * Microsoft-Cloud, und ein flatternder Ausgang in Loxone loeste bis 1.3.14
 * jede Sekunde einen aus (Befund code 12). 10 s fuer beide Befehle zusammen;
 * sonst 429 mit Grund. Ohne Datenordner faellt die Bremse geschlossen aus.
 * Seit dem Nachtrag 01.10.2026 hinter der Gleichwert-Unterdrueckung. */
if ($aktion === 'xbox-an' || $aktion === 'xbox-aus') {
    list($hk_frei, $hk_rest) = hk_xbox_bremse(10);
    if (!$hk_frei) {
        if ($hk_gw !== null && $hk_gw !== false) {
            hk_gleichwert_schliessen($hk_gw, null);
        }
        if ($hk_rest < 0) {
            hk_ende(503, 'Die Befehlsbremse fuer die Xbox ist nicht verfuegbar '
                         . '(Datenordner fehlt) - es wird nichts gesendet.', 'bremse_fehlt');
        }
        header('Retry-After: ' . (int) $hk_rest);
        hk_ende(429, 'Zu schnell: xbox-an und xbox-aus hoechstens alle 10 s. Noch '
                     . (int) $hk_rest . ' s warten - der Befehl wurde nicht gesendet.', 'bremse');
    }
}

list($code, $ausgabe) = hk_cmd($hk_argumente);

/* Merker nachfuehren. Taste und Anwendung sperren erst jetzt - sie werden nie
 * unterdrueckt; laesst sich der Merker dann nicht oeffnen, bleibt es still,
 * denn ohne Merker fallen die Sollwerte ohnehin geschlossen aus. */
if ($hk_gw === null && hk_gleichwert_betrifft($aktion)) {
    $hk_gw = hk_gleichwert_oeffnen();
    $hk_gw_merker = $hk_gw === false ? array() : hk_gleichwert_lesen($hk_gw);
}
if ($hk_gw !== null && $hk_gw !== false) {
    hk_gleichwert_schliessen($hk_gw, hk_gleichwert_nachher($hk_gw_merker, $aktion, $hk_wert, $code === 0));
}
/* Die Kino-Szene schaltet auch die Xbox: danach verfaellt deren Merker
 * (Nachtrag). Laesst er sich nicht oeffnen, bleibt es still - ohne Merker
 * faellt die Xbox ohnehin geschlossen aus. */
if ($aktion === 'kino-an' || $aktion === 'kino-aus') {
    $hk_gx = hk_gleichwert_oeffnen('xbox-an');
    if ($hk_gx !== false) {
        hk_gleichwert_schliessen($hk_gx, array());
    }
}

if ($code === 0) {
    hk_ende(200, trim($ausgabe) !== '' ? trim($ausgabe) : 'OK', 'ausgefuehrt');
}
hk_ende(500, trim($ausgabe) !== '' ? trim($ausgabe) : 'Fehler', 'fehlgeschlagen');
