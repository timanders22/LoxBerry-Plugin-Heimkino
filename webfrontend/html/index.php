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
 * liefen seit jeher in genau diesen 500er.
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
$soll = hk_cfg($cfg, 'heimkino', 'aktionstoken', '');
// Ein Feld kann als Feld ankommen (?token[]=x). (string) darauf ergaebe
// "Array" samt Meldung - erst is_string, dann alles andere.
$ist = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';

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
        hk_ende(403, 'SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET');
    }
    // Dieselbe Abweisung wie sonst auch - der Selbsttest ist keine Abkuerzung
    // an der Sicherheit vorbei. hash_equals vergleicht in gleichbleibender
    // Zeit; ein einfaches == liesse sich ueber die Antwortzeit Zeichen fuer
    // Zeichen erraten.
    if (!hash_equals($soll, $ist)) {
        hk_ende(403, 'SELFTEST;OK=0;ERR=TOKEN');
    }
    hk_ende(200, 'SELFTEST;OK=1;TOKEN=OK');
}

if (!hk_an($cfg, 'heimkino', 'enabled')) {
    hk_ende(503, 'Das Plugin ist in den Einstellungen abgeschaltet.');
}

if ($soll === '') {
    hk_ende(403, 'Kein Aktionstoken eingerichtet. Reiter Einstellungen aufrufen '
                 . 'und einmal speichern - dann wird eines erzeugt.');
}

if (!hash_equals($soll, $ist)) {
    hk_ende(403, 'Token falsch.');
}

$aktion = (isset($_GET['aktion']) && is_string($_GET['aktion'])) ? $_GET['aktion'] : '';
$erlaubt = array_keys(hk_aktionen());
$mit_wert = array_keys(hk_aktionen_mit_wert());

if (in_array($aktion, $erlaubt, true)) {
    list($code, $ausgabe) = hk_cmd(array($aktion));
} elseif (in_array($aktion, $mit_wert, true)) {
    $wert = (isset($_GET['wert']) && is_string($_GET['wert'])) ? $_GET['wert'] : '';
    // Nur Buchstaben, Ziffern und Unterstrich - alles andere hat in einem
    // Geraetebefehl nichts zu suchen. Gross- und Kleinschreibung bleibt
    // dabei ERHALTEN: der Bildmodus filmMaker der LG-Steuerung ist gemischt
    // geschrieben, und ein Kleinschreiben zerstoerte einen gueltigen Wert.
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
