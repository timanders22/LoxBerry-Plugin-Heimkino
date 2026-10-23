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
 * Seit 1.3.30 (Gruppe 9 / MQTT): nur mit dem Haken "HTTP-Endpunkt fuer
 * Loxone annehmen" (endpunkt_ein; neu aus, nach einem Update mit Token an),
 * sonst 403 GRUND=ENDPUNKT_AUS. Das Musterprojekt schaltet ueber MQTT.
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

/* ---------- HTTP-Endpunkt hinter einem eigenen Haken (Gruppe 9, 1.3.30) ----------
 *
 * Entscheidung 1 und E3 (ENTSCHEIDUNGEN_GRUPPE9_HEIMKINO.md, Entwurf 3.7): das
 * Musterprojekt holt Werte und sendet Befehle ueber MQTT (Befehlsbruecke). Dieser
 * Endpunkt bleibt fuer bestehende Anlagen und Fremdsysteme mit Token, aber nur
 * mit dem Haken "HTTP-Endpunkt fuer Loxone annehmen" (endpunkt_ein; neu aus,
 * nach einem Update mit gesetztem Aktionstoken an). Ohne ihn gilt die Sperre fuer
 * ALLE Aufrufe, auch den Selbsttest - VOR dem Token, nichts wird angelegt. */
if (!hk_endpunkt_an($cfg)) {
    if (isset($_GET['selftest']) && is_string($_GET['selftest']) && $_GET['selftest'] === '1') {
        hk_ende(403, 'SELFTEST;OK=0;ERR=ENDPUNKT_AUS', 'endpunkt_aus');
    }
    hk_ende(403, 'FEHLER;OK=0;GRUND=ENDPUNKT_AUS - Reiter Einbindung in Loxone, Haken "HTTP-Endpunkt fuer '
                 . 'Loxone annehmen".', 'endpunkt_aus');
}

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

/* Gruppe 9 (1.3.30): ab hier steht der Rumpf als hk_steuern() in hk_lib.php - DIESELBE Funktion ruft die
 * Befehlsbruecke (Quelle 'mqtt'). Aktion und Wert, Gleichwert-Unterdrueckung 60 s (X-7), Xbox-Bremse 10 s (C10) und
 * Merker stehen dort; die Antworten sind byte-gleich die bis 1.3.29. Neu: der Merker endpunkt_zuletzt (wann zuletzt
 * ein Aufruf mit Token kam - Reiter Test) und BELEGT nach 3 s ohne Gleichwert-Sperre (H15). */
hk_endpunkt_merken();
$hk_e = hk_steuern((isset($_GET['aktion']) && is_string($_GET['aktion'])) ? $_GET['aktion'] : '',
                   (isset($_GET['wert']) && is_string($_GET['wert'])) ? $_GET['wert'] : '', 'http');
if ($hk_e['retry'] !== null) {
    header('Retry-After: ' . (int) $hk_e['retry']);
}
hk_ende($hk_e['code'], $hk_e['text'], $hk_e['grund']);
