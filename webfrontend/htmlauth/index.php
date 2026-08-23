<?php
/**
 * Heimkino - Admin-Oberflaeche
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Die Versionsnummer steht hier bewusst NICHT. Sie kommt aus der
 * Plugindatenbank von LoxBerry, siehe hk_version() in hk_lib.php.
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-Globals (u.a. $cfg als stdClass) und
 * wuerde gleichnamige Plugin-Variablen ueberschreiben - deshalb tragen hier
 * ALLE Variablen ein hk_-Praefix.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
// '0', nicht '1'. Eine PHP-Warnung mitten in der Seite nennt Pfade und
// Zeilennummern und verschiebt das Markup; der unangemeldete Endpunkt macht
// es seit jeher richtig.
ini_set('display_errors', '0');

require_once __DIR__ . '/hk_lib.php';

$hk_p = hk_paths();
if ($hk_p['home'] !== '' && file_exists($hk_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $hk_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $hk_p['home'] . '/libs/phplib/loxberry_web.php';
    $hk_p = hk_paths();   // nach dem Einbinden neu holen
}

$hk_saved   = false;
$hk_fehler  = array();   // alle Beanstandungen, nicht nur die letzte
$hk_hinweis = array();

$hk_cfg = hk_config_read();

/* ==================================================================
 * Aktionstoken beim ersten Oeffnen erzeugen.
 *
 * Es steckt in den Adressen im Miniserver - danach wird es nur auf
 * ausdruecklichen Wunsch neu gewuerfelt. Und es ist die Wurzel des
 * Formularmerkmals unten, muss also VOR dem Wachposten feststehen.
 * ================================================================== */
if (trim((string) hk_cfg($hk_cfg, 'heimkino', 'aktionstoken', '')) === ''
    && hk_config_lage() !== 'keine_vorgaben') {
    try {
        $hk_cfg['heimkino']['aktionstoken'] = hk_token_erzeugen();
        hk_config_write($hk_cfg);
        $hk_cfg = hk_config_read();
    } catch (RuntimeException $e) {
        // Lieber gar kein Token als ein erratbares: der Aktionsendpunkt
        // weist dann jeden Aufruf ab, und das ist die richtige Antwort.
        $hk_fehler[] = hk_tf('FEHLER.KEIN_ZUFALL', array('%1' => hk_e($e->getMessage())));
    }
}

/* ==================================================================
 * Wachposten gegen fremde Absender - VOR allen Handlern.
 *
 * htmlauth/ schuetzt gegen den unangemeldeten Aufruf, NICHT dagegen, dass
 * der Browser eines ANGEMELDETEN Bedieners ein Formular abschickt, das auf
 * einer fremden Seite steht: die Anmeldung schickt er automatisch mit,
 * SameSite greift nicht. Ohne dieses Merkmal liesse sich von aussen
 * "Neues Token erzeugen" ausloesen - danach beantwortet der Endpunkt jeden
 * virtuellen Ausgang mit 403, und ein virtueller Ausgang wertet die Antwort
 * nicht aus: der Ausfall bliebe still.
 *
 * Einen einzelnen Handler kann man beim Erweitern vergessen, einen
 * Wachposten am Eingang nicht.
 * ================================================================== */
$hk_fmt = hk_formtoken($hk_cfg);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hk_csrf_ok = true;
    if ($hk_fmt === '') {
        $hk_csrf_ok = false;
        $hk_fehler[] = hk_t('FEHLER.CSRF_KEIN_TOKEN');
    } elseif (!hk_formtoken_ok($hk_cfg)) {
        $hk_csrf_ok = false;
        $hk_fehler[] = hk_t('FEHLER.CSRF');
    }
    if (!$hk_csrf_ok) {
        // $_POST leeren, damit danach KEIN Handler mehr anlaeuft, ohne dass
        // jeder einzelne davon wissen muesste. Den aktiven Reiter behalten -
        // die Meldung soll dort stehen, wo der Bediener war.
        $hk_behalten = isset($_POST['activetab']) ? $_POST['activetab'] : null;
        $_POST = array();
        if ($hk_behalten !== null) { $_POST['activetab'] = $hk_behalten; }
    }
}

/* Aktiver Reiter. Die Positivliste steht ausgeschrieben - so findet
 * hausstandard_pruefen.py sie; dass sie von Leiste und Bereichen abweichen
 * KANN, ist der Preis, und dagegen steht keine Hoffnung, sondern die
 * Pruefzeile "Passen Reiterleiste, Bereiche und Positivliste zusammen?" im
 * Reiter Test. */
$hk_reiter = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$hk_tab = 'tab-settings';
if (isset($_POST['activetab']) && is_string($_POST['activetab'])
    && in_array((string) $_POST['activetab'], $hk_reiter, true)) {
    $hk_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && is_string($_GET['form'])
          && in_array('tab-' . (string) $_GET['form'], $hk_reiter, true)) {
    $hk_tab = 'tab-' . (string) $_GET['form'];
}

/* ============ Loxone-Vorlage herunterladen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    list($hk_dname, $hk_dinhalt) = $_POST['download'] === 'vo'
        ? hk_vo_vorlage($hk_cfg)
        : hk_vorlage($hk_cfg);
    header('Content-Type: application/x-download');
    // Die Anfuehrungszeichen um den Dateinamen sind Pflicht: ohne sie
    // bricht jeder Name, der ein Leerzeichen enthaelt.
    header('Content-Disposition: attachment; filename="' . $hk_dname . '"');
    header('Content-Length: ' . strlen($hk_dinhalt));
    echo $hk_dinhalt;
    exit;
}

/* ============ Test-Aktionen ============ */
$hk_test_titel = '';
$hk_test_text  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test'])
    && is_string($_POST['test'])) {
    require_once __DIR__ . '/hk_test.php';
    list($hk_test_titel, $hk_test_text) = hk_test_ausfuehren((string) $_POST['test']);
    $hk_tab = 'tab-test';
}

/* ============ Xbox: Anwendungskennung ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['xbox_app'])) {
    $hk_geheim = isset($_POST['client_secret']) ? trim((string) $_POST['client_secret']) : '';
    // Eine GUID kann nicht die Spalte "Wert" sein. Lieber hier abweisen als
    // den Benutzer in ein invalid_client von Microsoft laufen lassen.
    //
    // Beanstandet wird NUR diese eine Zeile: die gueltige Anwendungskennung
    // und die Umleitungs-URI werden trotzdem gespeichert. Bis 1.2.11
    // verhinderte eine Beanstandung das ganze Speichern.
    if ($hk_geheim !== '' && hk_ist_guid($hk_geheim)) {
        $hk_fehler[] = hk_t('FEHLER.GEHEIMNIS_GUID');
        $hk_geheim = '';
    }
    $hk_ok = hk_xbox_app_speichern(
        isset($_POST['client_id']) ? $_POST['client_id'] : '',
        $hk_geheim,
        isset($_POST['rueckleitung']) ? $_POST['rueckleitung'] : '');
    if ($hk_ok) {
        $hk_hinweis[] = hk_t('MELD.KENNUNG_GESPEICHERT');
    } else {
        $hk_fehler[] = hk_tf('FEHLER.AUTH_SCHREIBEN', array('%1' => hk_e($hk_p['auth'])));
    }
    // Wurde ein neues Geheimnis hinterlegt und steht noch kein Ablaufdatum in
    // der Konfiguration, wird das Azure-Hoechstmass von 24 Monaten eingetragen.
    // Niemand soll sich einen Termin zwei Jahre im Voraus merken muessen.
    if ($hk_ok && $hk_geheim !== ''
        && trim(hk_cfg($hk_cfg, 'xbox', 'geheimnis_ablauf', '')) === '') {
        $hk_mit = $hk_cfg;
        $hk_mit['xbox']['geheimnis_ablauf'] = hk_ablauf_vorschlag();
        if (hk_config_write($hk_mit)) {
            $hk_cfg = hk_config_read();
            $hk_hinweis[] = hk_tf('MELD.FRIST_EINGETRAGEN', array(
                '%1' => hk_e(date('d.m.Y', strtotime(hk_cfg($hk_cfg, 'xbox', 'geheimnis_ablauf', ''))))));
        }
    }
    $hk_tab = 'tab-settings';
}

/* ============ Xbox: Code einloesen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['xbox_code'])) {
    $hk_code = trim((string) (isset($_POST['code']) ? $_POST['code'] : ''));
    if ($hk_code === '') {
        $hk_fehler[] = hk_t('FEHLER.KEIN_CODE');
    } elseif (!hk_xbox_code_hinterlegen($hk_code)) {
        $hk_fehler[] = hk_tf('FEHLER.CODE_SCHREIBEN', array('%1' => hk_e($hk_p['code'])));
    } else {
        // Der Code geht ueber eine Datei mit Rechten 0600, NICHT als
        // Argument: Argumente stehen in /proc/<pid>/cmdline und sind fuer
        // jeden lokalen Benutzer lesbar. Zusammen mit dem Clientgeheimnis
        // laesst sich aus dem Code ein Erneuerungstoken loesen.
        list($hk_rc, $hk_aus) = hk_cmd(array('xbox-code'));
        if ($hk_rc === 0) {
            $hk_hinweis[] = hk_t('MELD.ANMELDUNG_OK');
        } else {
            $hk_fehler[] = hk_tf('FEHLER.ANMELDUNG', array('%1' => hk_e($hk_aus)));
        }
    }
    $hk_tab = 'tab-settings';
}

/* ============ Xbox: Anmeldung loeschen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['xbox_vergessen'])) {
    list($hk_rc, $hk_aus) = hk_cmd(array('xbox-vergessen'));
    if ($hk_rc === 0) {
        $hk_hinweis[] = hk_t('MELD.ANMELDUNG_GELOESCHT');
    } else {
        $hk_fehler[] = hk_e($hk_aus);
    }
    $hk_tab = 'tab-settings';
}

/* ============ MQTT speichern - eigener Handler, eigener Reiter ============
 *
 * MQTT wohnt vollstaendig im Reiter MQTT (Beschluss 14.08.2026). Bis 1.2.11
 * lagen Haken und Themenpraefix im Einstellungsformular, waehrend der
 * MQTT-Reiter kein einziges Eingabefeld hatte.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mqtt'])) {
    $hk_neu = $hk_cfg;
    $hk_neu['heimkino']['mqtt'] = isset($_POST['mqtt']) ? '1' : '0';

    $hk_praefix_roh = isset($_POST['themenpraefix']) && is_string($_POST['themenpraefix'])
        ? trim((string) $_POST['themenpraefix']) : '';
    // ABWEISEN, nicht zurechtbiegen. Bis 1.2.11 wurden unerlaubte Zeichen
    // stillschweigend entfernt und ein vollstaendig weggefilterter Wert
    // wortlos auf "heimkino" zurueckgesetzt - damit aenderten sich in einem
    // Zug alle MQTT-Themen, das einzutragende Abo und saemtliche Titel der
    // virtuellen Eingaenge, und der Bediener sah nur "gespeichert".
    if ($hk_praefix_roh === '') {
        $hk_fehler[] = hk_t('FEHLER.PRAEFIX_LEER');
    } elseif (!preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $hk_praefix_roh)) {
        $hk_fehler[] = hk_t('FEHLER.PRAEFIX');
    } else {
        $hk_neu['heimkino']['themenpraefix'] = $hk_praefix_roh;
    }

    if (hk_config_write($hk_neu)) {
        $hk_saved = true;
        $hk_cfg = hk_config_read();
        $hk_pid_neu = hk_dienst('restart');
        $hk_hinweis[] = $hk_pid_neu ? hk_t('MELD.DIENST_NEU') : hk_t('MELD.DIENST_AUS');
    } else {
        $hk_fehler[] = hk_tf('FEHLER.CFG_SCHREIBEN', array('%1' => hk_e($hk_p['config'])));
    }
    $hk_tab = 'tab-mqtt';
}

/* ============ Dienst steuern ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dienst'])
    && is_string($_POST['dienst'])
    && in_array($_POST['dienst'], array('start', 'stop', 'restart'), true)) {
    $hk_pid_neu = hk_dienst((string) $_POST['dienst']);
    $hk_hinweis[] = $hk_pid_neu
        ? hk_tf('PRUEF.DIENST_JA', array('%1' => (string) $hk_pid_neu))
        : hk_t('PRUEF.DIENST_NEIN');
    $hk_tab = 'tab-settings';
}

/* ============ Einstellungen speichern ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $hk_neu = $hk_cfg;

    /* Steuerzeichen entfernen - und zwar OHNE /u.
     *
     * Bis 1.2.11 stand hier preg_replace('/[\x00-\x1F\x7F"\']+/u', ...). Mit
     * /u gibt preg_replace bei ungueltigem UTF-8 NULL zurueck, trim(null)
     * ergibt '' - und ein aus einer Latin-1-Quelle eingefuegtes Zeichen
     * loeschte das ganze Feld, waehrend die Seite "gespeichert" meldete.
     * Der Zweig "war da, ist jetzt weg" konnte das nicht auffangen, weil
     * der Ausgangswert selbst schon leer war.
     */
    $hk_saeubern = function ($s) {
        if (!is_string($s)) { return ''; }
        return trim(preg_replace('/[\x00-\x1F\x7F"\']+/', '', $s));
    };
    $hk_ganz = function ($wert, $vorgabe, $min, $max, &$fehler, $schluessel) {
        if (!is_string($wert) && !is_numeric($wert)) { return (string) $vorgabe; }
        if (!preg_match('/^-?[0-9]+$/', trim((string) $wert))) {
            $fehler[] = hk_t($schluessel);
            return (string) $vorgabe;
        }
        $n = (int) $wert;
        if ($n < $min || $n > $max) {
            $fehler[] = hk_t($schluessel);
            return (string) $vorgabe;
        }
        return (string) $n;
    };

    $hk_neu['heimkino']['enabled'] = isset($_POST['enabled']) ? '1' : '0';
    $hk_neu['heimkino']['nachfassen'] = isset($_POST['nachfassen']) ? '1' : '0';
    $hk_neu['heimkino']['intervall'] = $hk_ganz(
        isset($_POST['intervall']) ? $_POST['intervall'] : '',
        hk_cfg($hk_cfg, 'heimkino', 'intervall', '60'), 10, 3600,
        $hk_fehler, 'FEHLER.INTERVALL');

    // Token nur auf ausdruecklichen Wunsch neu wuerfeln - es steckt in den
    // Adressen im Miniserver.
    if (isset($_POST['token_neu'])) {
        try {
            $hk_neu['heimkino']['aktionstoken'] = hk_token_erzeugen();
            $hk_hinweis[] = hk_t('MELD.TOKEN_NEU');
        } catch (RuntimeException $e) {
            $hk_fehler[] = hk_tf('FEHLER.KEIN_ZUFALL', array('%1' => hk_e($e->getMessage())));
        }
    }

    /* --- Beamer --- */
    $hk_neu['beamer']['aktiv'] = isset($_POST['beamer_aktiv']) ? '1' : '0';

    $hk_ip = $hk_saeubern(isset($_POST['beamer_ip']) ? $_POST['beamer_ip'] : '');
    if ($hk_ip === '' || preg_match('/^[A-Za-z0-9._-]+$/', $hk_ip)) {
        $hk_neu['beamer']['ip'] = $hk_ip;
    } else {
        $hk_fehler[] = hk_t('FEHLER.BEAMER_IP');
    }

    // Die MAC wird zur Anzeige in Zweiergruppen gesetzt - das ist eine
    // Darstellungsfrage und keine Umschrift des Wertes: Hexziffern sind
    // gross wie klein derselbe Wert, und die Trennzeichen sind bedeutungslos.
    // Anders als beim Keycode, wo genau diese acht Zeichen in die
    // Schluesselableitung gehen.
    $hk_mac_roh = $hk_saeubern(isset($_POST['beamer_mac']) ? $_POST['beamer_mac'] : '');
    if ($hk_mac_roh === '') {
        $hk_neu['beamer']['mac'] = '';
    } elseif (preg_match('/^[0-9A-Fa-f]{2}([:.-]?[0-9A-Fa-f]{2}){5}$/', $hk_mac_roh)) {
        $hk_hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $hk_mac_roh));
        $hk_neu['beamer']['mac'] = implode(':', str_split($hk_hex, 2));
    } else {
        $hk_fehler[] = hk_t('FEHLER.BEAMER_MAC');
    }

    // Der Keycode wird NICHT grossgeschrieben, sondern geprueft. Aus genau
    // diesen acht Zeichen leitet PBKDF2 den Schluessel ab; eine stille
    // Umschrift waere eine Umschrift des Schluessels. Die Vorlage
    // (lgtv-ip-control) prueft ebenfalls /[A-Z0-9]{8}/ und wandelt nichts.
    $hk_key = $hk_saeubern(isset($_POST['beamer_keycode']) ? $_POST['beamer_keycode'] : '');
    if ($hk_key === '' || preg_match('/^[A-Z0-9]{8}$/', $hk_key)) {
        $hk_neu['beamer']['keycode'] = $hk_key;
    } else {
        $hk_fehler[] = hk_t('FEHLER.BEAMER_KEYCODE');
    }

    $hk_neu['beamer']['port'] = $hk_ganz(
        isset($_POST['beamer_port']) ? $_POST['beamer_port'] : '',
        hk_cfg($hk_cfg, 'beamer', 'port', '9761'), 1, 65535,
        $hk_fehler, 'FEHLER.BEAMER_PORT');
    $hk_neu['beamer']['zeitgrenze'] = $hk_ganz(
        isset($_POST['beamer_zeitgrenze']) ? $_POST['beamer_zeitgrenze'] : '',
        hk_cfg($hk_cfg, 'beamer', 'zeitgrenze', '5'), 1, 60,
        $hk_fehler, 'FEHLER.BEAMER_ZEITGRENZE');
    $hk_neu['beamer']['zusatzwerte'] = isset($_POST['beamer_zusatzwerte']) ? '1' : '0';

    /* --- Kino-Szene ---
     *
     * Eingang und Bildmodus werden gegen die Wortliste der Vorlage gehalten,
     * nicht gegen ein Muster: ein Wert, den das Geraet nicht kennt, kaeme als
     * unlesbare Antwort zurueck statt als Fehler. Leer heisst "diesen Schritt
     * ueberspringen". Laesst sich die Liste nicht holen (lg_beamer.py nicht
     * aufrufbar), wird nur die Form geprueft - und die Oberflaeche sagt das
     * dann auch, statt eine Auswahl vorzutaeuschen, die sie nicht hat.
     */
    $hk_neu['szene']['aktiv'] = isset($_POST['szene_aktiv']) ? '1' : '0';
    $hk_woerter_h = hk_woerter();
    $hk_pruefe_wort = function ($feld, $art, $schluessel) use (&$hk_fehler, $hk_woerter_h) {
        $w = isset($_POST[$feld]) && is_string($_POST[$feld]) ? trim((string) $_POST[$feld]) : '';
        if ($w === '') { return ''; }
        $liste = isset($hk_woerter_h[$art]) ? $hk_woerter_h[$art] : array();
        if ($liste) {
            if (in_array($w, $liste, true)) { return $w; }
        } elseif (preg_match('/^[A-Za-z0-9_]{1,32}$/', $w)) {
            return $w;
        }
        $hk_fehler[] = hk_tf($schluessel, array('%1' => hk_e($w),
            '%2' => hk_e(implode(', ', $liste))));
        return null;
    };
    $hk_se = $hk_pruefe_wort('szene_eingang', 'eingang', 'FEHLER.SZENE_EINGANG');
    if ($hk_se !== null) { $hk_neu['szene']['eingang'] = $hk_se; }
    $hk_sb = $hk_pruefe_wort('szene_bildmodus', 'bildmodus', 'FEHLER.SZENE_BILDMODUS');
    if ($hk_sb !== null) { $hk_neu['szene']['bildmodus'] = $hk_sb; }
    $hk_neu['szene']['warten_beamer'] = $hk_ganz(
        isset($_POST['szene_warten_beamer']) ? $_POST['szene_warten_beamer'] : '',
        hk_cfg($hk_cfg, 'szene', 'warten_beamer', '120'), 10, 600,
        $hk_fehler, 'FEHLER.SZENE_WARTEN');
    $hk_neu['szene']['warten_xbox'] = $hk_ganz(
        isset($_POST['szene_warten_xbox']) ? $_POST['szene_warten_xbox'] : '',
        hk_cfg($hk_cfg, 'szene', 'warten_xbox', '90'), 10, 600,
        $hk_fehler, 'FEHLER.SZENE_WARTEN');

    /* --- Xbox --- */
    $hk_neu['xbox']['aktiv'] = isset($_POST['xbox_aktiv']) ? '1' : '0';
    // Die Kennung ist eine undurchsichtige Zeichenkette. Sie wird NICHT in
    // Grossbuchstaben gewandelt und es werden keine Zeichen entfernt. Die
    // erste Fassung warf Bindestriche weg und schrieb alles gross - das
    // verdirbt eine gueltige Kennung, ohne dass man es sieht.
    $hk_kennung = $hk_saeubern(isset($_POST['xbox_geraete_id']) ? $_POST['xbox_geraete_id'] : '');
    if ($hk_kennung === '' || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $hk_kennung)) {
        $hk_neu['xbox']['geraete_id'] = $hk_kennung;
    } else {
        $hk_fehler[] = hk_t('FEHLER.XBOX_ID');
    }

    // Ablaufdatum. Leer ist erlaubt - dann warnt das Plugin nicht. Ein
    // unlesbares Datum wird abgewiesen, statt still eine Warnung zu
    // verschlucken, die in zwei Jahren gebraucht wird.
    $hk_frist = isset($_POST['xbox_geheimnis_ablauf']) && is_string($_POST['xbox_geheimnis_ablauf'])
        ? trim((string) $_POST['xbox_geheimnis_ablauf']) : '';
    if ($hk_frist === '') {
        $hk_neu['xbox']['geheimnis_ablauf'] = '';
    } elseif (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $hk_frist)
              && strtotime($hk_frist) !== false) {
        $hk_neu['xbox']['geheimnis_ablauf'] = $hk_frist;
    } else {
        $hk_fehler[] = hk_t('FEHLER.FRIST');
    }

    // Gespeichert wird IMMER, auch wenn eine Zeile beanstandet wurde: die
    // beanstandete behaelt ihren alten Wert, alle uebrigen werden
    // uebernommen. Wer alles verwirft, laesst den Bediener seine ganze
    // Eingabe noch einmal machen.
    if (hk_config_write($hk_neu)) {
        $hk_saved = true;
        $hk_cfg = hk_config_read();
        $hk_fmt = hk_formtoken($hk_cfg);   // wechselt mit dem Aktionstoken
        $hk_pid_neu = hk_dienst('restart');
        $hk_hinweis[] = $hk_pid_neu ? hk_t('MELD.DIENST_NEU') : hk_t('MELD.DIENST_AUS');
    } else {
        $hk_fehler[] = hk_tf('FEHLER.CFG_SCHREIBEN', array('%1' => hk_e($hk_p['config'])));
    }
    $hk_tab = 'tab-settings';
}

/* ============ Anzeige vorbereiten ============ */
hk_cfg_vervollstaendigen($hk_cfg);
$hk_ablauf = hk_cfg($hk_cfg, 'xbox', 'geheimnis_ablauf', '');
list($hk_ablauf_art, $hk_ablauf_tage, $hk_ablauf_datum) = hk_ablauf_lage($hk_ablauf);
$hk_praefix = hk_cfg($hk_cfg, 'heimkino', 'themenpraefix', 'heimkino');
$hk_pid     = hk_dienst_pid();
$hk_z       = hk_zustand();
$hk_alter   = hk_zustand_alter();
$hk_broker  = hk_mqtt_broker();
$hk_gwf     = hk_mqtt_fassung();
$hk_zeilen  = hk_log_ende();
$hk_xb      = hk_xbox_zustand();
$hk_anmelde = hk_xbox_anmeldeadresse();
$hk_token   = hk_cfg($hk_cfg, 'heimkino', 'aktionstoken', '');
$hk_host    = hk_hostname();

require_once __DIR__ . '/hk_test.php';
$hk_pruefzeilen = hk_test_zeilen($hk_cfg);

$hk_frame = class_exists('LBWeb', false);
if ($hk_frame) {
    LBWeb::lbheader('Heimkino', 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss.
   Wortgleich aus VORLAGE_hausstandard.css.html uebernommen. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit - man
   sieht ein schmales Feld in einem breiten weissen Kasten. Deshalb wird
   ausschliesslich der Behaelter begrenzt. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDEN Knopf mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Statuskacheln - bewusst ein anderer Name als sm-knopfreihe. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar. MIT diesen zwei Zeilen und
   OHNE serverseitiges sm-active ist die Seite vollstaendig leer, sobald das
   Skript nicht laeuft. Die Klasse steht deshalb schon im ausgelieferten
   HTML - an der Leiste UND am Bereich. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
/* Jede Tabelle mit mehr als sechs Spalten oder mit Eingabefeldern kommt in
   einen Rollbehaelter: .sm-tbl hat width:100% und .sm-wrap ein max-width
   ohne Ueberlauf - eine zu breite Spalte waere sonst UNERREICHBAR. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
/* Ein <select> ueber die volle Breite mit data-role="none" sieht aus wie ein
   Textfeld. Die Raute im SVG wird als %23 geschrieben: eine rohe Raute
   beendet in einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
/* Eigene Zutaten dieses Plugins - erlaubt, aber sie gehoeren benannt (Regel
   vom 22.08.2026, die Klassenliste gegen die Vorlage zaehlen):
   sm-check   - Beschriftung neben einem Haken, kein eigenes Feld-Label
   sm-reihe   - zwei bis drei Felder nebeneinander
   sm-scheibe - runder Zustandspunkt vor einer Zeile, dazu die drei
                Farbvarianten sm-gruen, sm-rot und sm-grau
   sm-log     - dunkler Protokollkasten
   Umgekehrt fehlt aus der Vorlage nur sm-tabelle - die tote Klasse, die am
   19.08.2026 zurueckgenommen wurde. Gemessen mit dem Zweizeiler aus der
   Vorlage. */
.sm-check { display: block; font-weight: 400; font-size: 0.95em; color: #333; margin: 10px 0 4px; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.sm-wrap input[type=text], .sm-wrap input[type=password], .sm-wrap input[type=number], .sm-wrap input[type=date] {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-reihe { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-reihe > div { flex: 1; min-width: 180px; }
.sm-scheibe { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
.sm-scheibe.sm-gruen { background: #6dac20; }
.sm-scheibe.sm-rot   { background: #c62828; }
.sm-scheibe.sm-grau  { background: #9e9e9e; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
</style>

<div class="sm-wrap">
<h1 style="font-size:1.4em;margin:0 0 2px;">Heimkino</h1>
<p class="sm-hilfe"><?= hk_te('KOPF.UNTERTITEL') ?><?php
  $hk_ver = hk_version();
  echo $hk_ver !== '' ? ' &mdash; ' . hk_te('KOPF.VERSION') . ' ' . hk_e($hk_ver) : ''; ?></p>

<?php if ($hk_saved) { ?>
  <div class="sm-hinweis"><?= hk_te('MELD.GESPEICHERT') ?></div>
<?php } ?>
<?php if ($hk_hinweis) { ?>
  <div class="sm-hinweis"><?php echo implode('<br>', $hk_hinweis); ?></div>
<?php } ?>
<?php if ($hk_fehler) { ?>
  <div class="sm-warnung"><?php
    echo count($hk_fehler) === 1
        ? $hk_fehler[0]
        : '<ul style="margin:0 0 0 18px;padding:0;"><li>'
          . implode('</li><li>', $hk_fehler) . '</li></ul>';
  ?></div>
<?php } ?>

<div class="sm-kacheln">
  <div class="sm-kachel"><b><?php
    echo $hk_pid ? '<span class="sm-an">' . hk_te('ALLGEMEIN.LAEUFT') . '</span>'
                 : '<span class="sm-aus">' . hk_te('ALLGEMEIN.GESTOPPT') . '</span>'; ?></b>
    <?= hk_te('KACHEL.DIENST') ?><?php echo $hk_pid ? ' &middot; PID ' . (int) $hk_pid : ''; ?></div>
  <div class="sm-kachel"><b><?php
    echo $hk_alter === null ? '&ndash;' : (int) $hk_alter . '&nbsp;s'; ?></b>
    <?= hk_te('KACHEL.LETZTE_ABFRAGE') ?></div>
  <div class="sm-kachel"><b><?php
    echo $hk_broker ? hk_e($hk_broker['host']) : '&ndash;'; ?></b>
    <?= hk_te('KACHEL.BROKER') ?></div>
</div>

<!-- Reiterleiste: echte Verweise, JavaScript faengt den Klick ab. Der Link
     traegt die Adresse - jeder Reiter ist damit verlinkbar und die
     Zurueck-Taste tut das Erwartete. Das Skript verhindert nur das Neuladen.
     WELCHER REITER OFFEN IST, ENTSCHEIDET DER SERVER: sm-active steht schon
     im ausgelieferten HTML. Ausgeschrieben, nicht in einer Schleife erzeugt -
     eine Schleife macht hausstandard_pruefen.py blind. -->
<div class="sm-tabs">
	<a class="sm-tab<?= $hk_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= hk_te('REITER.EINSTELLUNGEN') ?></a>
	<a class="sm-tab<?= $hk_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= $hk_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= hk_te('REITER.LOXONE') ?></a>
	<a class="sm-tab<?= $hk_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= hk_te('REITER.TEST') ?></a>
	<a class="sm-tab<?= $hk_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= hk_te('REITER.LOG') ?></a>
</div>

<!-- ============================ Einstellungen ============================ -->
<div class="sm-seite<?= $hk_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= hk_te('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= hk_te('LEGENDE.AKTION') ?></span>
</div>

<h2><?= hk_te('SET.H_DIENST') ?></h2>
<p class="sm-hilfe"><?php
  echo $hk_pid ? hk_tf('PRUEF.DIENST_JA', array('%1' => (string) $hk_pid))
               : hk_t('PRUEF.DIENST_NEIN'); ?></p>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= hk_te('ALLGEMEIN.K_DIENST_START') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= hk_te('ALLGEMEIN.K_DIENST_NEU') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= hk_te('ALLGEMEIN.K_DIENST_STOP') ?></button>
  </form>
</div>
<p class="sm-hilfe"><?php echo hk_t('SET.WAECHTER_HINWEIS'); ?></p>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= hk_te('SET.H_ALLGEMEIN') ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="enabled" value="1"
  <?php echo hk_an($hk_cfg, 'heimkino', 'enabled') ? 'checked' : ''; ?>>
  <?= hk_te('SET.EINGESCHALTET') ?></label>
<label class="sm-check"><input data-role="none" type="checkbox" name="nachfassen" value="1"
  <?php echo hk_an($hk_cfg, 'heimkino', 'nachfassen') ? 'checked' : ''; ?>>
  <?= hk_te('SET.NACHFASSEN') ?></label>
<div class="sm-hilfe"><?php echo hk_t('SET.NACHFASSEN_HILFE'); ?></div>

<div class="sm-feld">
  <label for="intervall"><?= hk_te('SET.INTERVALL') ?></label>
  <input data-role="none" type="number" id="intervall" name="intervall" min="10" max="3600"
    value="<?= hk_e(hk_cfg($hk_cfg, 'heimkino', 'intervall', '60')) ?>">
  <div class="sm-hilfe"><?php echo hk_t('SET.INTERVALL_HILFE'); ?></div>
</div>

<h2><?= hk_te('SET.H_BEAMER') ?></h2>
<div class="sm-hinweis"><?php echo hk_t('BEAMER.EINRICHTUNG'); ?></div>

<label class="sm-check"><input data-role="none" type="checkbox" name="beamer_aktiv" value="1"
  <?php echo hk_an($hk_cfg, 'beamer', 'aktiv') ? 'checked' : ''; ?>>
  <?= hk_te('SET.BEAMER_VERWENDEN') ?></label>
<div class="sm-reihe">
  <div class="sm-feld">
    <label for="beamer_ip"><?= hk_te('FELD.BEAMER_IP') ?></label>
    <input data-role="none" type="text" id="beamer_ip" name="beamer_ip" placeholder="192.168.x.y"
      value="<?= hk_e(hk_cfg($hk_cfg, 'beamer', 'ip', '')) ?>">
  </div>
  <div class="sm-feld">
    <label for="beamer_mac"><?= hk_te('FELD.BEAMER_MAC') ?></label>
    <input data-role="none" type="text" id="beamer_mac" name="beamer_mac" placeholder="AA:BB:CC:DD:EE:FF"
      value="<?= hk_e(hk_cfg($hk_cfg, 'beamer', 'mac', '')) ?>">
    <div class="sm-hilfe"><?php echo hk_t('FELD.BEAMER_MAC_HILFE'); ?></div>
  </div>
</div>
<div class="sm-reihe">
  <div class="sm-feld">
    <label for="beamer_keycode"><?= hk_te('FELD.BEAMER_KEYCODE') ?></label>
    <input data-role="none" type="text" id="beamer_keycode" name="beamer_keycode" maxlength="8"
      placeholder="ABCD1234"
      value="<?= hk_e(hk_cfg($hk_cfg, 'beamer', 'keycode', '')) ?>">
    <div class="sm-hilfe"><?php echo hk_t('FELD.BEAMER_KEYCODE_HILFE'); ?></div>
  </div>
  <div class="sm-feld">
    <label for="beamer_port"><?= hk_te('FELD.BEAMER_PORT') ?></label>
    <input data-role="none" type="number" id="beamer_port" name="beamer_port" min="1" max="65535"
      value="<?= hk_e(hk_cfg($hk_cfg, 'beamer', 'port', '9761')) ?>">
  </div>
  <div class="sm-feld">
    <label for="beamer_zeitgrenze"><?= hk_te('FELD.BEAMER_ZEITGRENZE') ?></label>
    <input data-role="none" type="number" id="beamer_zeitgrenze" name="beamer_zeitgrenze" min="1" max="60"
      value="<?= hk_e(hk_cfg($hk_cfg, 'beamer', 'zeitgrenze', '5')) ?>">
  </div>
</div>
<label class="sm-check"><input data-role="none" type="checkbox" name="beamer_zusatzwerte" value="1"
  <?php echo hk_an($hk_cfg, 'beamer', 'zusatzwerte') ? 'checked' : ''; ?>>
  <?= hk_te('SET.ZUSATZWERTE') ?></label>
<div class="sm-hilfe"><?php echo hk_t('SET.ZUSATZWERTE_HILFE'); ?></div>

<h2><?= hk_te('SET.H_SZENE') ?></h2>
<div class="sm-hinweis"><?php echo hk_t('SET.SZENE_HILFE'); ?></div>
<label class="sm-check"><input data-role="none" type="checkbox" name="szene_aktiv" value="1"
  <?php echo hk_an($hk_cfg, 'szene', 'aktiv') ? 'checked' : ''; ?>>
  <?= hk_te('SET.SZENE_AKTIV') ?></label>
<?php $hk_w = hk_woerter(); ?>
<div class="sm-reihe">
  <div class="sm-feld">
    <label for="szene_eingang"><?= hk_te('FELD.SZENE_EINGANG') ?></label>
<?php if (!empty($hk_w['eingang'])) { ?>
    <select data-role="none" id="szene_eingang" name="szene_eingang">
      <option value=""><?= hk_te('ALLGEMEIN.KEINE_AUSWAHL') ?></option>
<?php   foreach ($hk_w['eingang'] as $hk_o) { ?>
      <option value="<?= hk_e($hk_o) ?>"<?php
        echo hk_cfg($hk_cfg, 'szene', 'eingang', '') === $hk_o ? ' selected' : ''; ?>><?= hk_e($hk_o) ?></option>
<?php   } ?>
    </select>
<?php } else { ?>
    <input data-role="none" type="text" id="szene_eingang" name="szene_eingang"
      value="<?= hk_e(hk_cfg($hk_cfg, 'szene', 'eingang', '')) ?>">
    <div class="sm-hilfe"><?php echo hk_t('FELD.SZENE_KEINE_WOERTER'); ?></div>
<?php } ?>
  </div>
  <div class="sm-feld">
    <label for="szene_bildmodus"><?= hk_te('FELD.SZENE_BILDMODUS') ?></label>
<?php if (!empty($hk_w['bildmodus'])) { ?>
    <select data-role="none" id="szene_bildmodus" name="szene_bildmodus">
      <option value=""><?= hk_te('ALLGEMEIN.KEINE_AUSWAHL') ?></option>
<?php   foreach ($hk_w['bildmodus'] as $hk_o) { ?>
      <option value="<?= hk_e($hk_o) ?>"<?php
        echo hk_cfg($hk_cfg, 'szene', 'bildmodus', '') === $hk_o ? ' selected' : ''; ?>><?= hk_e($hk_o) ?></option>
<?php   } ?>
    </select>
<?php } else { ?>
    <input data-role="none" type="text" id="szene_bildmodus" name="szene_bildmodus"
      value="<?= hk_e(hk_cfg($hk_cfg, 'szene', 'bildmodus', '')) ?>">
<?php } ?>
  </div>
</div>
<div class="sm-reihe">
  <div class="sm-feld">
    <label for="szene_warten_beamer"><?= hk_te('FELD.SZENE_WARTEN_BEAMER') ?></label>
    <input data-role="none" type="number" id="szene_warten_beamer" name="szene_warten_beamer" min="10" max="600"
      value="<?= hk_e(hk_cfg($hk_cfg, 'szene', 'warten_beamer', '120')) ?>">
  </div>
  <div class="sm-feld">
    <label for="szene_warten_xbox"><?= hk_te('FELD.SZENE_WARTEN_XBOX') ?></label>
    <input data-role="none" type="number" id="szene_warten_xbox" name="szene_warten_xbox" min="10" max="600"
      value="<?= hk_e(hk_cfg($hk_cfg, 'szene', 'warten_xbox', '90')) ?>">
  </div>
</div>

<h2><?= hk_te('SET.H_XBOX') ?></h2>
<div class="sm-hinweis"><?php echo hk_t('XBOX.EINRICHTUNG'); ?></div>

<label class="sm-check"><input data-role="none" type="checkbox" name="xbox_aktiv" value="1"
  <?php echo hk_an($hk_cfg, 'xbox', 'aktiv') ? 'checked' : ''; ?>>
  <?= hk_te('SET.XBOX_VERWENDEN') ?></label>
<div class="sm-feld">
  <label for="xbox_geraete_id"><?= hk_te('FELD.XBOX_ID') ?></label>
  <input data-role="none" type="text" id="xbox_geraete_id" name="xbox_geraete_id"
    value="<?= hk_e(hk_cfg($hk_cfg, 'xbox', 'geraete_id', '')) ?>">
  <div class="sm-hilfe"><?php echo hk_t('FELD.XBOX_ID_HILFE'); ?></div>
</div>

<div class="sm-feld">
  <label for="xbox_geheimnis_ablauf"><?= hk_te('FELD.FRIST') ?></label>
  <input data-role="none" type="date" id="xbox_geheimnis_ablauf" name="xbox_geheimnis_ablauf"
    value="<?= hk_e($hk_ablauf) ?>">
  <div class="<?php echo in_array($hk_ablauf_art, array('abgelaufen', 'bald', 'unlesbar'), true)
      ? 'sm-warnung' : 'sm-hinweis'; ?>">
    <?php echo hk_t('FELD.FRIST_HILFE'); ?>
    <div style="margin-top:6px;"><?php echo hk_tf('FRIST.' . strtoupper($hk_ablauf_art),
        array('%1' => hk_e($hk_ablauf_datum),
              '%2' => (string) (int) abs((int) $hk_ablauf_tage))); ?></div>
  </div>
</div>

<h2><?= hk_te('SET.H_TOKEN') ?></h2>
<p class="sm-hilfe"><?php echo hk_t('TOKEN.HILFE'); ?></p>
<div class="sm-mono"><?php echo $hk_token !== '' ? hk_e($hk_token) : hk_te('TOKEN.KEINES'); ?></div>
<label class="sm-check" style="margin-top:8px;">
  <input data-role="none" type="checkbox" name="token_neu" value="1">
  <?= hk_te('TOKEN.NEU') ?></label>

<div style="margin-top:28px;">
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save" value="1"><?= hk_te('ALLGEMEIN.K_SPEICHERN') ?></button>
  </div>
</div>
</form>

<h2 style="margin-top:60px;"><?= hk_te('XBOX.H_ANMELDUNG') ?></h2>
<div class="sm-hinweis"><?php echo hk_t('XBOX.CLOUD_WARUM'); ?></div>
<div class="sm-warnung"><?php echo hk_t('XBOX.VERZEICHNIS'); ?></div>
<div class="sm-step"><?php echo hk_t('XBOX.SCHRITT0'); ?></div>
<div class="sm-step"><?php echo hk_tf('XBOX.SCHRITT1',
    array('%1' => hk_e($hk_xb['rueckleitung']))); ?></div>
<div class="sm-step"><?php echo hk_t('XBOX.SCHRITT2'); ?></div>
<div class="sm-step"><?php echo hk_t('XBOX.SCHRITT3'); ?></div>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<div class="sm-reihe">
  <div class="sm-feld">
    <label for="client_id"><?= hk_te('FELD.CLIENT_ID') ?></label>
    <input data-role="none" type="text" id="client_id" name="client_id"
      value="<?= hk_e($hk_xb['client_id']) ?>">
  </div>
  <div class="sm-feld">
    <label for="client_secret"><?= hk_te('FELD.CLIENT_SECRET') ?></label>
    <input data-role="none" type="password" id="client_secret" name="client_secret"
      placeholder="<?php echo $hk_xb['geheim'] ? hk_te('FELD.CLIENT_SECRET_DA') : ''; ?>">
  </div>
</div>
<div class="sm-feld">
  <label for="rueckleitung"><?= hk_te('FELD.RUECKLEITUNG') ?></label>
  <input data-role="none" type="text" id="rueckleitung" name="rueckleitung"
    value="<?= hk_e($hk_xb['rueckleitung']) ?>">
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="xbox_app" value="1"><?= hk_te('ALLGEMEIN.K_KENNUNG_SPEICHERN') ?></button>
</div>
</form>

<?php if ($hk_anmelde !== '') { ?>
<div class="sm-step"><?php echo hk_t('XBOX.SCHRITT4'); ?>
  <p><a data-role="none" class="sm-btn sm-b-lesen" href="<?= hk_e($hk_anmelde) ?>"
     target="_blank" rel="noopener"><?= hk_te('ALLGEMEIN.K_ANMELDESEITE') ?></a></p>
  <div class="sm-hilfe"><?php echo hk_t('XBOX.SCHRITT4_HILFE'); ?></div>
</div>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<div class="sm-feld">
  <label for="code"><?= hk_te('FELD.CODE') ?></label>
  <input data-role="none" type="text" id="code" name="code"
    placeholder="http://localhost/auth/callback?code=...">
  <div class="sm-hilfe"><?php echo hk_t('FELD.CODE_HILFE'); ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="xbox_code" value="1"><?= hk_te('ALLGEMEIN.K_ANMELDUNG_FERTIG') ?></button>
</div>
</form>
<div class="sm-hinweis"><?php echo hk_t('XBOX.INVALID_CLIENT'); ?></div>
<?php } ?>

<p style="margin-top:14px;">
  <span class="sm-scheibe <?php echo $hk_xb['angemeldet'] ? 'sm-gruen' : 'sm-grau'; ?>"></span>
  <?php echo $hk_xb['angemeldet'] ? hk_te('XBOX.ANGEMELDET') : hk_te('XBOX.NICHT_ANGEMELDET'); ?>
</p>
<?php if ($hk_xb['angemeldet']) { ?>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="xbox_vergessen" value="1"><?= hk_te('ALLGEMEIN.K_ANMELDUNG_LOESCHEN') ?></button>
  </form>
</div>
<?php } ?>
</div>

<!-- ================================= MQTT ================================= -->
<div class="sm-seite<?= $hk_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= hk_te('LEGENDE.AKTION') ?></span>
</div>

<h2><?= hk_te('MQTT.H_EINSTELLUNGEN') ?></h2>
<p class="sm-hilfe"><?php echo hk_t('MQTT.KERNBESTANDTEIL'); ?></p>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<label class="sm-check"><input data-role="none" type="checkbox" name="mqtt" value="1"
  <?php echo hk_an($hk_cfg, 'heimkino', 'mqtt') ? 'checked' : ''; ?>>
  <?= hk_te('MQTT.MELDEN') ?></label>
<div class="sm-feld">
  <label for="themenpraefix"><?= hk_te('MQTT.PRAEFIX') ?></label>
  <input data-role="none" type="text" id="themenpraefix" name="themenpraefix"
    value="<?= hk_e($hk_praefix) ?>">
  <div class="sm-hilfe"><?php echo hk_t('MQTT.PRAEFIX_HILFE'); ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save_mqtt" value="1"><?= hk_te('ALLGEMEIN.K_SPEICHERN') ?></button>
</div>
</form>

<h2><?= hk_te('MQTT.H_ZUSTAND') ?></h2>
<?php if (!$hk_broker) { ?>
<div class="sm-warnung"><?php echo hk_t('MQTT.KEIN_BROKER'); ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th style="width:34%"><?= hk_te('MQTT.SP_GROESSE') ?></th><th><?= hk_te('MQTT.SP_WERT') ?></th></tr>
<tr><td><?= hk_te('MQTT.BROKER') ?></td><td class="sm-mono"><?= hk_e($hk_broker['host'] . ':' . $hk_broker['port']) ?></td></tr>
<tr><td><?= hk_te('MQTT.FASSUNG') ?></td><td><?php
  echo $hk_gwf > 0 ? (int) $hk_gwf : hk_te('MQTT.FASSUNG_UNBEKANNT'); ?></td></tr>
<tr><td><?= hk_te('MQTT.LOKAL') ?></td><td><?php
  echo $hk_broker['lokal'] ? hk_te('ALLGEMEIN.JA') : hk_t('MQTT.LOKAL_NEIN'); ?></td></tr>
<tr><td><?= hk_te('MQTT.AUTOSTART') ?></td><td><?php
  echo $hk_broker['autostart'] ? hk_te('ALLGEMEIN.JA') : hk_t('MQTT.AUTOSTART_NEIN'); ?></td></tr>
<tr><td><?= hk_te('MQTT.BENUTZER') ?></td><td class="sm-mono"><?php
  echo $hk_broker['benutzer'] !== '' ? hk_e($hk_broker['benutzer']) : '&ndash;'; ?></td></tr>
<tr><td><?= hk_te('MQTT.MELDUNG') ?></td><td><?php
  echo hk_an($hk_cfg, 'heimkino', 'mqtt') ? hk_te('MQTT.MELDUNG_AN') : hk_t('MQTT.MELDUNG_AUS'); ?></td></tr>
</table>
<?php } ?>

<h2><?= hk_te('MQTT.H_ABO') ?></h2>
<?php
/* Der Satz "Ohne diesen Eintrag kommt am Miniserver nichts an" gilt NUR fuer
   Gateway V1. Unter V2 schaltet der LoxBerry-Kern auf der Abonnement-Seite
   die Knoepfe ab - von Hand eintragen kann man dort nichts mehr, und der
   unbedingte Satz schickte jeden V2-Anwender zu einem Eingabefeld, das es
   nicht mehr gibt. Ist die Fassung nicht lesbar, stehen BEIDE Saetze da:
   einen von beiden zu behaupten waere fuer die Haelfte der Anlagen falsch. */
if ($hk_gwf >= 2) { ?>
<div class="sm-hinweis"><?php echo hk_t('MQTT.ABO_V2'); ?></div>
<?php } elseif ($hk_gwf === 1) { ?>
<div class="sm-warnung"><?php echo hk_t('MQTT.ABO_PFLICHT'); ?></div>
<?php } else { ?>
<div class="sm-warnung"><?php echo hk_t('MQTT.ABO_PFLICHT'); ?></div>
<div class="sm-hilfe"><?php echo hk_t('MQTT.ABO_V2'); ?></div>
<?php } ?>
<pre class="sm-pre"><?= hk_e($hk_praefix) ?>/#</pre>

<h2><?= hk_te('MQTT.H_THEMEN') ?></h2>
<p class="sm-hilfe"><?php echo hk_t('MQTT.THEMEN_RETAINED'); ?></p>
<table class="sm-tbl">
<tr><th style="width:38%"><?= hk_te('MQTT.SP_THEMA') ?></th><th style="width:10%"><?= hk_te('MQTT.SP_ART') ?></th><th><?= hk_te('MQTT.SP_BEDEUTUNG') ?></th></tr>
<?php foreach (hk_themen() as $hk_thema => $hk_e_thema) { ?>
<tr><td class="sm-mono"><?= hk_e($hk_praefix . '/' . $hk_thema) ?></td>
    <td><?= hk_te('ART.' . strtoupper($hk_e_thema['art'])) ?></td>
    <td><?= hk_e($hk_e_thema['text']) ?></td></tr>
<?php } ?>
</table>
</div>

<!-- ========================= Einbindung in Loxone ========================= -->
<div class="sm-seite<?= $hk_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= hk_te('LEGENDE.TECHNIK') ?></span>
</div>

<h2><?= hk_te('LOX.H_SCHRITTWEISE') ?></h2>
<p class="sm-hilfe"><?php echo hk_t('LOX.EINLEITUNG'); ?></p>

<h2><?= hk_te('LOX.H_SCHRITT1') ?></h2>
<?php if ($hk_gwf >= 2) { ?>
<div class="sm-hinweis"><?php echo hk_t('MQTT.ABO_V2'); ?></div>
<?php } elseif ($hk_gwf === 1) { ?>
<div class="sm-warnung"><?php echo hk_t('MQTT.ABO_PFLICHT'); ?></div>
<?php } else { ?>
<div class="sm-warnung"><?php echo hk_t('MQTT.ABO_PFLICHT'); ?></div>
<div class="sm-hilfe"><?php echo hk_t('MQTT.ABO_V2'); ?></div>
<?php } ?>
<pre class="sm-pre"><?= hk_e($hk_praefix) ?>/#</pre>

<h2><?= hk_te('LOX.H_SCHRITT2') ?></h2>
<p class="sm-hilfe"><?php echo hk_t('LOX.SCHRITT2'); ?></p>
<table class="sm-tbl">
<tr><th style="width:38%"><?= hk_te('MQTT.SP_THEMA') ?></th><th style="width:10%"><?= hk_te('MQTT.SP_ART') ?></th><th><?= hk_te('MQTT.SP_BEDEUTUNG') ?></th></tr>
<?php foreach (hk_themen() as $hk_thema => $hk_e_thema) { ?>
<tr><td class="sm-mono"><?= hk_e($hk_praefix . '/' . $hk_thema) ?></td>
    <td><?= hk_te('ART.' . strtoupper($hk_e_thema['art'])) ?></td>
    <td><?= hk_e($hk_e_thema['text']) ?></td></tr>
<?php } ?>
</table>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="mqtt_in"><?= hk_te('ALLGEMEIN.K_VORLAGE_EINGAENGE') ?></button>
  </form>
</div>
<p class="sm-hilfe"><?php echo hk_t('LOX.VORLAGE_HINWEIS'); ?></p>

<h2><?= hk_te('LOX.H_SCHRITT3') ?></h2>
<?php if ($hk_token === '') { ?>
<div class="sm-warnung"><?php echo hk_t('LOX.KEIN_TOKEN'); ?></div>
<?php } else { ?>
<p class="sm-hilfe"><?php echo hk_t('LOX.SCHRITT3'); ?></p>
<table class="sm-tbl">
<tr><th style="width:26%"><?= hk_te('LOX.SP_FELD') ?></th><th><?= hk_te('LOX.SP_WERT') ?></th></tr>
<tr><td><?= hk_te('LOX.ADRESSE_AUSGANG') ?></td><td class="sm-mono">http://<?= hk_e($hk_host) ?></td></tr>
</table>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="vo"><?= hk_te('ALLGEMEIN.K_VORLAGE_VO') ?></button>
  </form>
</div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:26%"><?= hk_te('LOX.SP_BEFEHL') ?></th><th><?= hk_te('LOX.SP_EIN') ?></th></tr>
<?php foreach (hk_aktionen() as $hk_a => $hk_s) { ?>
<tr><td><?= hk_te($hk_s) ?></td>
    <td class="sm-mono"><?= hk_e(hk_aktionsadresse($hk_cfg, $hk_a)) ?></td></tr>
<?php } ?>
<?php foreach (hk_aktionen_mit_wert() as $hk_a => $hk_s) { ?>
<tr><td><?= hk_te($hk_s) ?></td>
    <td class="sm-mono"><?= hk_e(hk_aktionsadresse($hk_cfg, $hk_a, 'WERT')) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hinweis"><?php echo hk_t('LOX.FLANKE'); ?></div>
<?php if (hk_an($hk_cfg, 'szene', 'aktiv')) { ?>
<div class="sm-hinweis"><?php echo hk_t('LOX.SZENE'); ?></div>
<?php } ?>
<?php } ?>

<h2><?= hk_te('LOX.H_SCHRITT4') ?></h2>
<p class="sm-hilfe"><?php echo hk_t('LOX.SCHRITT4'); ?></p>
<?php if ($hk_token !== '') { ?>
<pre class="sm-pre">http://<?= hk_e($hk_host) . hk_e(hk_selftestadresse($hk_cfg)) ?></pre>
<p class="sm-hilfe"><?php echo hk_t('LOX.SELFTEST_ANTWORT'); ?></p>
<?php } ?>

<h2><?= hk_te('LOX.H_SCHRITT5') ?></h2>
<div class="sm-warnung"><?php echo hk_tf('LOX.AUSFALL', array('%1' => hk_e($hk_praefix))); ?></div>

<h2><?= hk_te('LOX.H_SCHRITT6') ?></h2>
<p class="sm-hilfe"><?php echo hk_t('LOX.SCHRITT6'); ?></p>

<h2><?= hk_te('LOX.H_SCHRITT7') ?></h2>
<p class="sm-hilfe"><?php echo hk_t('LOX.BAUSTEINE_EINLEITUNG'); ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?= hk_te('LOX.SP_BAUSTEIN') ?></th><th><?= hk_te('LOX.SP_NAME') ?></th><th><?= hk_te('LOX.SP_PARAMETER') ?></th><th><?= hk_te('LOX.SP_VERBINDEN') ?></th></tr>
<tr><td>1</td><td><?= hk_te('LOX.VI') ?></td><td class="sm-mono"><?= hk_e($hk_praefix) ?>_beamer_an</td><td><?= hk_te('ART.DIGITAL') ?></td><td>&mdash;</td></tr>
<tr><td>2</td><td><?= hk_te('LOX.VI') ?></td><td class="sm-mono"><?= hk_e($hk_praefix) ?>_beamer_erreichbar</td><td><?= hk_te('ART.DIGITAL') ?></td><td>&mdash;</td></tr>
<tr><td>3</td><td><?= hk_te('LOX.VI') ?></td><td class="sm-mono"><?= hk_e($hk_praefix) ?>_xbox_an</td><td><?= hk_te('ART.DIGITAL') ?></td><td>&mdash;</td></tr>
<tr><td>4</td><td><?= hk_te('LOX.VI') ?></td><td class="sm-mono"><?= hk_e($hk_praefix) ?>_xbox_angemeldet</td><td><?= hk_te('ART.DIGITAL') ?></td><td>&mdash;</td></tr>
<tr><td>5</td><td><?= hk_te('LOX.VI') ?></td><td class="sm-mono"><?= hk_e($hk_praefix) ?>_xbox_geheimnis_tage</td><td><?= hk_te('ART.ANALOG') ?>, MinVal -10000</td><td>&mdash;</td></tr>
<tr><td>6</td><td><?= hk_te('LOX.VI') ?></td><td class="sm-mono"><?= hk_e($hk_praefix) ?>_service_online</td><td><?= hk_te('ART.DIGITAL') ?></td><td>&mdash;</td></tr>
<tr><td>7</td><td><?= hk_te('LOX.VI') ?></td><td class="sm-mono"><?= hk_e($hk_praefix) ?>_service_zeitstempel</td><td><?= hk_te('ART.ANALOG') ?></td><td>&mdash;</td></tr>
<tr><td>8</td><td><?= hk_te('LOX.MERKER') ?></td><td>Kino-Modus</td><td><?= hk_te('LOX.P_VISU') ?></td><td>&mdash;</td></tr>
<tr><td>9</td><td><?= hk_te('LOX.FLANKE_AUF') ?></td><td>Kino startet</td><td>&mdash;</td><td>#8</td></tr>
<tr><td>10</td><td><?= hk_te('LOX.FLANKE_AB') ?></td><td>Kino endet</td><td>&mdash;</td><td>#8</td></tr>
<tr><td>11</td><td><?= hk_te('LOX.VO') ?></td><td>Heimkino</td><td>http://<?= hk_e($hk_host) ?></td><td><span class="sm-mono">beamer-wol</span>, <span class="sm-mono">xbox-an</span> &larr; #9; <span class="sm-mono">beamer-aus</span>, <span class="sm-mono">xbox-aus</span> &larr; #10</td></tr>
<tr><td>12</td><td><?= hk_te('LOX.EINSCHALTVERZ') ?></td><td>Beamer kam nicht hoch</td><td>90 s</td><td>#8 UND NICHT #1</td></tr>
<tr><td>13</td><td><?= hk_te('LOX.SCHWELLWERT') ?></td><td>Xbox-Geheimnis</td><td><?= hk_te('LOX.P_SCHWELLE') ?></td><td>#5</td></tr>
<tr><td>14</td><td><?= hk_te('LOX.MELDUNG') ?></td><td>Xbox-Geheimnis erneuern</td><td>&mdash;</td><td>&larr; #13</td></tr>
<tr><td>15</td><td><?= hk_te('LOX.STATUS') ?></td><td>Heimkino</td><td><?= hk_te('LOX.P_VISU') ?></td><td>v1 = #1, v2 = #3</td></tr>
</table>
</div>
<div class="sm-hinweis"><?php echo hk_t('LOX.BAUSTEINE_HINWEISE'); ?></div>

<h2><?= hk_te('LOX.H_SCHRITT8') ?></h2>
<p class="sm-hilfe"><?php echo hk_tf('LOX.GEGENPROBE', array('%1' => hk_e($hk_praefix))); ?></p>
</div>

<!-- ================================ Test ================================ -->
<div class="sm-seite<?= $hk_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= hk_te('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= hk_te('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= hk_te('LEGENDE.AKTION') ?></span>
</div>

<h2><?= hk_te('TEST.H_SELBSTPRUEFUNG') ?></h2>
<table class="sm-tbl">
<tr><th style="width:6%"></th><th style="width:40%"><?= hk_te('TEST.SP_FRAGE') ?></th><th><?= hk_te('TEST.SP_ANTWORT') ?></th></tr>
<?php foreach ($hk_pruefzeilen as $hk_zeile) { ?>
<tr><td style="text-align:center;"><?php
  echo $hk_zeile[0] === 1 ? '<span class="sm-an">&#10004;</span>'
     : ($hk_zeile[0] === 2 ? '<span style="color:#546e7a;">?</span>'
                           : '<span class="sm-aus">&#10008;</span>'); ?></td>
  <td><?= hk_e($hk_zeile[1]) ?></td><td><?= hk_e($hk_zeile[2]) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?php echo hk_t('TEST.SELBSTPRUEFUNG_HINWEIS'); ?></p>

<?php if ($hk_test_titel !== '') { ?>
<h2><?= hk_e($hk_test_titel) ?></h2>
<?php echo $hk_test_text; ?>
<?php } ?>

<h2><?= hk_te('TEST.H_NACHSEHEN') ?></h2>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="umgebung"><?= hk_te('TEST.K_UMGEBUNG') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="anmeldedaten"><?= hk_te('TEST.K_ANMELDEDATEN') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="krypto"><?= hk_te('TEST.K_KRYPTO') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="beamer_erreichbar"><?= hk_te('TEST.K_ERREICHBAR') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="beamer_status"><?= hk_te('TEST.K_STATUS') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="beamer_ipcontrol"><?= hk_te('TEST.K_IPCONTROL') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="xbox_status"><?= hk_te('TEST.K_XBOX_STATUS') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="xbox_konsolen"><?= hk_te('TEST.K_KONSOLEN') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="beamer_mac"><?= hk_te('TEST.K_MAC') ?></button>
  </form>
</div>

<h2><?= hk_te('TEST.H_TECHNIK') ?></h2>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="mqtt_in"><?= hk_te('ALLGEMEIN.K_VORLAGE_EINGAENGE') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="vo"><?= hk_te('ALLGEMEIN.K_VORLAGE_VO') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="xbox_roh"><?= hk_te('TEST.K_XBOX_ROH') ?></button>
  </form>
</div>

<h2><?= hk_te('TEST.H_SCHALTEN') ?></h2>
<p class="sm-hilfe"><?= hk_te('TEST.SCHALTEN_HINWEIS') ?></p>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="beamer_aus"><?= hk_te('AKTION.BEAMER_AUS') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="beamer_wol"><?= hk_te('AKTION.BEAMER_WOL') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="xbox_an"><?= hk_te('AKTION.XBOX_AN') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="xbox_aus"><?= hk_te('AKTION.XBOX_AUS') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="beamer_bild_aus"><?= hk_te('AKTION.BEAMER_BILD_AUS') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="beamer_bild_an"><?= hk_te('AKTION.BEAMER_BILD_AN') ?></button>
  </form>
<?php if (hk_an($hk_cfg, 'szene', 'aktiv')) { ?>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="kino_an"><?= hk_te('AKTION.KINO_AN') ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="kino_aus"><?= hk_te('AKTION.KINO_AUS') ?></button>
  </form>
<?php } ?>
</div>

<h2><?= hk_te('TEST.H_LETZTER_STAND') ?></h2>
<?php if ($hk_z === null) { ?>
<div class="sm-hinweis"><?= hk_te('TEST.KEIN_ZUSTAND') ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th style="width:34%"><?= hk_te('MQTT.SP_GROESSE') ?></th><th><?= hk_te('MQTT.SP_WERT') ?></th></tr>
<tr><td><?= hk_te('STAND.ZEITPUNKT') ?></td><td><?= hk_e(isset($hk_z['zeit_text']) ? $hk_z['zeit_text'] : '') ?></td></tr>
<tr><td><?= hk_te('STAND.BEAMER_VERWENDET') ?></td><td><?= hk_te(!empty($hk_z['beamer']['aktiv']) ? 'ALLGEMEIN.JA' : 'ALLGEMEIN.NEIN') ?></td></tr>
<tr><td><?= hk_te('STAND.BEAMER_ERREICHBAR') ?></td><td><?= hk_te(!empty($hk_z['beamer']['erreichbar']) ? 'ALLGEMEIN.JA' : 'ALLGEMEIN.NEIN') ?></td></tr>
<tr><td><?= hk_te('STAND.BEAMER_ZUSTAND') ?></td><td><?= hk_e(isset($hk_z['beamer']['status']) ? $hk_z['beamer']['status'] : '') ?></td></tr>
<tr><td><?= hk_te('STAND.BEAMER_GRUND') ?></td><td><?= hk_e(isset($hk_z['beamer']['grund_text']) ? $hk_z['beamer']['grund_text'] : '') ?></td></tr>
<tr><td><?= hk_te('STAND.BEAMER_QUELLE') ?></td><td><?= hk_e(isset($hk_z['beamer']['app']) ? $hk_z['beamer']['app'] : '') ?></td></tr>
<tr><td><?= hk_te('STAND.XBOX_VERWENDET') ?></td><td><?= hk_te(!empty($hk_z['xbox']['aktiv']) ? 'ALLGEMEIN.JA' : 'ALLGEMEIN.NEIN') ?></td></tr>
<tr><td><?= hk_te('STAND.XBOX_ZUSTAND') ?></td><td><?= hk_e(isset($hk_z['xbox']['status']) ? $hk_z['xbox']['status'] : '') ?></td></tr>
<tr><td><?= hk_te('STAND.XBOX_ANGEMELDET') ?></td><td><?= hk_te(!empty($hk_z['xbox']['angemeldet']) ? 'ALLGEMEIN.JA' : 'ALLGEMEIN.NEIN') ?></td></tr>
<?php if (isset($hk_z['betrieb']) && is_array($hk_z['betrieb'])) { ?>
<tr><td><?= hk_te('STAND.BETRIEB_BEAMER') ?></td><td><?php
  echo hk_e((string) $hk_z['betrieb']['beamer_h']) . ' h &middot; '
     . hk_e((string) $hk_z['betrieb']['beamer_heute']) . ' min'; ?></td></tr>
<tr><td><?= hk_te('STAND.BETRIEB_XBOX') ?></td><td><?php
  echo hk_e((string) $hk_z['betrieb']['xbox_h']) . ' h &middot; '
     . hk_e((string) $hk_z['betrieb']['xbox_heute']) . ' min'; ?></td></tr>
<?php } ?>
<?php if (!empty($hk_z['xbox']['quelle'])) { ?>
<tr><td><?= hk_te('STAND.XBOX_QUELLE') ?></td><td><?php
  echo $hk_z['xbox']['quelle'] === 'liste' ? hk_te('STAND.QUELLE_LISTE') : hk_te('STAND.QUELLE_DIREKT'); ?></td></tr>
<?php } ?>
<?php $hk_f = (isset($hk_z['beamer']['fehler']) ? $hk_z['beamer']['fehler'] : '');
  if ($hk_f === '' && isset($hk_z['xbox']['fehler'])) { $hk_f = $hk_z['xbox']['fehler']; }
  if ($hk_f !== '') { ?>
<tr><td><?= hk_te('STAND.LETZTER_FEHLER') ?></td><td class="sm-aus"><?= hk_e($hk_f) ?></td></tr>
<?php } ?>
</table>
<?php } ?>
</div>

<!-- ============================== Logdateien ============================== -->
<div class="sm-seite<?= $hk_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= hk_te('LEGENDE.LESEN') ?></span>
</div>
<h2><?= hk_te('REITER.LOG') ?></h2>
<?php
/* Die Protokollverwaltung von LoxBerry zeigt ALLE Dateien dieses Plugins,
   nicht nur heimkino.log - also auch cron.err des Waechters. */
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
}
?>
<p class="sm-hilfe"><?= hk_te('LOG.NEUESTE_OBEN') ?>
<span class="sm-mono"><?= hk_e($hk_p['log']) ?></span></p>
<?php if (!$hk_zeilen) { ?>
<div class="sm-hinweis"><?= hk_te('LOG.LEER') ?></div>
<?php } else { ?>
<div class="sm-log"><?php
  foreach ($hk_zeilen as $hk_zl) { echo hk_e($hk_zl) . "\n"; }
?></div>
<?php } ?>
<div class="sm-knopfreihe" style="margin-top:12px;">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="fmt" value="<?= hk_e($hk_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="nichts" value="1"><?= hk_te('ALLGEMEIN.K_NEU_LADEN') ?></button>
  </form>
</div>
</div>

</div>

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	// Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
	// versteckten activetab-Felder aus und ist ansonsten wirkungslos.
	zeige(<?= json_encode($hk_tab) ?>);
})();
</script>

<?php
if ($hk_frame) {
    LBWeb::lbfooter();
}
