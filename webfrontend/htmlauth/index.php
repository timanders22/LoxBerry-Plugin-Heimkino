<?php
/**
 * Heimkino - Admin-Oberflaeche
 *
 * Die Versionsnummer steht hier bewusst NICHT. Sie kommt aus der
 * Plugindatenbank von LoxBerry, siehe hk_version() in hk_lib.php.
 * Reiter: Einstellungen | Einbindung in Loxone | Test | Logdateien
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

require_once __DIR__ . '/hk_lib.php';

$hk_p = hk_paths();
if ($hk_p['home']) {
    $sdk = $hk_p['home'] . '/libs/phplib/loxberry_system.php';
    if (file_exists($sdk)) {
        require_once $sdk;
        require_once $hk_p['home'] . '/libs/phplib/loxberry_web.php';
    }
}

$hk_saved   = false;
$hk_fehler  = array();   // alle Beanstandungen, nicht nur die letzte
$hk_hinweis = '';
/* Wer einen Reiter hinzufuegt, muss DREI Stellen mitziehen: die
   Reiterleiste, den Bereich (sm-pane mit gleicher id) und diese
   Positivliste. Fehlt der Name hier, springt die Seite nach jedem Absenden
   zurueck auf Einstellungen. */
$hk_muster = '/^tab-(settings|mqtt|loxone|test|log)$/';
$hk_tab = preg_match($hk_muster,
                     (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))
    ? (string) $_POST['activetab'] : 'tab-settings';
// Die Reiter sind echte Verweise. Wer sie anklickt oder ein Lesezeichen
// darauf setzt, landet ueber ?form= im richtigen Bereich - auch dann, wenn
// im Browser kein JavaScript laeuft. Bis 1.1.1 waren es <div>-Elemente, und
// sm-active setzte ausschliesslich das JavaScript: ohne JavaScript stand
// jeder Bereich auf display:none, die Seite war also LEER.
if (isset($_GET['form'])) {
    $hk_wunsch = 'tab-' . preg_replace('/[^a-z]/', '', (string) $_GET['form']);
    if (preg_match($hk_muster, $hk_wunsch)) { $hk_tab = $hk_wunsch; }
}
/** Klasse fuer den gerade sichtbaren Reiter bzw. Bereich. */
function hk_aktiv($id) { global $hk_tab; return $hk_tab === $id ? ' sm-active' : ''; }

$hk_cfg = hk_config_read();

/* ============ Loxone-Vorlage herunterladen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    list($name, $inhalt) = $_POST['download'] === 'vo' && function_exists('hk_vo_vorlage')
        ? hk_vo_vorlage($hk_cfg)
        : hk_vorlage($hk_cfg);
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename=' . $name);
    header('Content-Length: ' . strlen($inhalt));
    echo $inhalt;
    exit;
}

/* ============ Test-Aktionen ============ */
$hk_test_titel = '';
$hk_test_text  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test'])) {
    require_once __DIR__ . '/hk_test.php';
    list($hk_test_titel, $hk_test_text) = hk_test_ausfuehren((string) $_POST['test']);
    $hk_tab = 'tab-test';
}

/* ============ Xbox: Anwendungskennung ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['xbox_app'])) {
    $geheim = isset($_POST['client_secret']) ? trim((string) $_POST['client_secret']) : '';
    // Eine GUID kann nicht die Spalte "Wert" sein. Lieber hier abweisen als
    // den Benutzer in ein invalid_client von Microsoft laufen lassen.
    if ($geheim !== '' && hk_ist_guid($geheim)) {
        $hk_fehler[] = 'Das eingegebene Geheimnis ist eine GUID und damit die Spalte '
            . '<b>Geheime ID</b>. Gebraucht wird die Spalte <b>Wert</b> aus derselben '
            . 'Zeile. Sie ist nur unmittelbar nach dem Anlegen sichtbar &mdash; sonst '
            . 'ein neues Geheimnis anlegen und das alte l&ouml;schen.';
        $geheim = '';
    }
    $ok = $hk_fehler ? false : hk_xbox_app_speichern(
        isset($_POST['client_id']) ? $_POST['client_id'] : '',
        $geheim,
        isset($_POST['rueckleitung']) ? $_POST['rueckleitung'] : '');
    $hk_hinweis = $ok
        ? 'Anwendungskennung gespeichert. Jetzt der Anmeldung folgen.'
        : '';
    if (!$ok && !$hk_fehler) {
        $hk_fehler[] = 'Die Anmeldedatei konnte nicht geschrieben werden: '
                  . hk_e($hk_p['auth']);
    }
    // Wurde ein neues Geheimnis hinterlegt und steht noch kein Ablaufdatum in
    // der Konfiguration, wird das Azure-Hoechstmass von 24 Monaten eingetragen.
    // Niemand soll sich einen Termin zwei Jahre im Voraus merken muessen.
    if ($ok && $geheim !== ''
        && trim(hk_cfg($hk_cfg, 'xbox', 'geheimnis_ablauf', '')) === '') {
        $mit_datum = $hk_cfg;
        $mit_datum['xbox']['geheimnis_ablauf'] = hk_ablauf_vorschlag();
        if (hk_config_write($mit_datum)) {
            $hk_cfg = hk_config_read();
            $hk_hinweis .= ' Als Ablauf wurden 24 Monate eingetragen ('
                . hk_e(date('d.m.Y', strtotime(hk_cfg($hk_cfg, 'xbox', 'geheimnis_ablauf', ''))))
                . '). Wer in Azure eine k&uuml;rzere Frist gew&auml;hlt hat, korrigiert das unten.';
        }
    }
    $hk_tab = 'tab-settings';
}

/* ============ Xbox: Code einloesen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['xbox_code'])) {
    $code = trim((string) (isset($_POST['code']) ? $_POST['code'] : ''));
    if ($code === '') {
        $hk_fehler[] = 'Es wurde kein Code und keine Adresse eingegeben.';
    } else {
        list($code_r, $ausgabe) = hk_cmd(array('xbox-code', $code));
        if ($code_r === 0) {
            $hk_hinweis = 'Anmeldung bei Microsoft erfolgreich. Jetzt im '
                        . 'Reiter Test die Konsolen suchen.';
        } else {
            $hk_fehler[] = 'Die Anmeldung hat nicht geklappt: ' . hk_e($ausgabe);
        }
    }
    $hk_tab = 'tab-settings';
}

/* ============ Xbox: Anmeldung loeschen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['xbox_vergessen'])) {
    list($code_r, $ausgabe) = hk_cmd(array('xbox-vergessen'));
    $hk_hinweis = $code_r === 0 ? 'Anmeldung gel&ouml;scht.' : '';
    if ($code_r !== 0) { $hk_fehler[] = hk_e($ausgabe); }
    $hk_tab = 'tab-settings';
}

/* ============ Einstellungen speichern ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $neu = $hk_cfg;

    $saeubern = function ($s) {
        return trim(preg_replace('/[\x00-\x1F\x7F"\']+/u', '', (string) $s));
    };
    $ganz = function ($wert, $vorgabe, $min, $max) {
        if (!is_numeric($wert)) { return (string) $vorgabe; }
        $n = (int) $wert;
        return ($n >= $min && $n <= $max) ? (string) $n : (string) $vorgabe;
    };

    $neu['heimkino']['enabled']   = isset($_POST['enabled']) ? '1' : '0';
    $neu['heimkino']['mqtt']      = isset($_POST['mqtt']) ? '1' : '0';
    $neu['heimkino']['intervall'] = $ganz($_POST['intervall'] ?? '', 60, 10, 3600);

    $praefix = preg_replace('/[^A-Za-z0-9_\/-]+/', '',
                            $saeubern($_POST['themenpraefix'] ?? ''));
    $neu['heimkino']['themenpraefix'] = $praefix !== '' ? $praefix : 'heimkino';

    // Token einmal erzeugen und dann behalten. Wer es neu wuerfelt, muss die
    // Adressen in Loxone anpassen - deshalb nur auf ausdruecklichen Wunsch.
    if (isset($_POST['token_neu']) || $neu['heimkino']['aktionstoken'] === '') {
        // hk_token_erzeugen() bricht seit 1.2.0 mit einer Ausnahme ab, wenn
        // das System keinen sicheren Zufall liefert. Abgefangen wird sie
        // HIER - sonst zerlegte sie die Oberflaeche mitten im Speichern, und
        // zwar an einer Stelle, an der niemand danach sucht.
        try {
            $neu['heimkino']['aktionstoken'] = hk_token_erzeugen();
            if (isset($_POST['token_neu'])) {
                $hk_hinweis = 'Neues Aktionstoken erzeugt. Die Adressen im '
                            . 'Miniserver m&uuml;ssen angepasst werden.';
            }
        } catch (RuntimeException $e) {
            // Lieber gar kein Token als ein erratbares: der Aktionsendpunkt
            // weist dann jeden Aufruf ab, und das ist die richtige Antwort.
            $neu['heimkino']['aktionstoken'] = '';
            $hk_fehler[] = 'Es liess sich kein sicheres Aktionstoken erzeugen ('
                         . hk_e($e->getMessage()) . '). Ohne Token weist der '
                         . 'Aktionsendpunkt jeden Aufruf ab - das ist Absicht, '
                         . 'denn ein erratbares Token waere schlimmer als keines.';
        }
    }

    /* --- Beamer --- */
    $neu['beamer']['aktiv'] = isset($_POST['beamer_aktiv']) ? '1' : '0';

    $ip = $saeubern($_POST['beamer_ip'] ?? '');
    $neu['beamer']['ip'] = ($ip === '' || preg_match('/^[A-Za-z0-9._-]+$/', $ip))
        ? $ip : '';
    if ($ip !== '' && $neu['beamer']['ip'] === '') {
        $hk_fehler[] = 'Die IP-Adresse des Beamers sieht nicht wie eine Adresse '
                  . 'oder ein Rechnername aus und wurde verworfen.';
    }

    $mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '',
                                   $saeubern($_POST['beamer_mac'] ?? '')));
    if ($mac === '') {
        $neu['beamer']['mac'] = '';
    } elseif (strlen($mac) === 12) {
        $neu['beamer']['mac'] = implode(':', str_split($mac, 2));
    } else {
        $neu['beamer']['mac'] = '';
        $hk_fehler[] = 'Die MAC-Adresse hat nicht 12 Hexstellen und wurde verworfen.';
    }

    $key = strtoupper($saeubern($_POST['beamer_keycode'] ?? ''));
    if ($key === '' || preg_match('/^[A-Z0-9]{8}$/', $key)) {
        $neu['beamer']['keycode'] = $key;
    } else {
        $hk_fehler[] = 'Der Keycode muss aus genau 8 Zeichen A-Z und 0-9 bestehen. '
                  . 'Der alte Wert bleibt stehen.';
    }

    $neu['beamer']['port']       = $ganz($_POST['beamer_port'] ?? '', 9761, 1, 65535);
    $neu['beamer']['zeitgrenze'] = $ganz($_POST['beamer_zeitgrenze'] ?? '', 5, 1, 60);

    /* --- Xbox --- */
    $neu['xbox']['aktiv'] = isset($_POST['xbox_aktiv']) ? '1' : '0';
    // Die Kennung ist eine undurchsichtige Zeichenkette. Sie wird deshalb
    // NICHT in Grossbuchstaben gewandelt und es werden nur Zeichen entfernt,
    // die in einer Adresse nichts zu suchen haben. Die erste Fassung warf
    // Bindestriche weg und schrieb alles gross - das verdirbt eine gueltige
    // Kennung, ohne dass man es sieht.
    $kennung = $saeubern($_POST['xbox_geraete_id'] ?? '');
    if ($kennung === '' || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $kennung)) {
        $neu['xbox']['geraete_id'] = $kennung;
    } else {
        $hk_fehler[] = 'Die XBOX-Netzwerk-Ger&auml;teidentit&auml;t enth&auml;lt Zeichen, die dort nicht '
            . 'vorkommen k&ouml;nnen (erlaubt sind Buchstaben, Ziffern, Punkt, '
            . 'Doppelpunkt, Bindestrich und Unterstrich). Der alte Wert bleibt stehen.';
    }

    // Ablaufdatum des Azure-Clientgeheimnisses. Leer ist erlaubt - dann warnt
    // das Plugin nicht. Ein unlesbares Datum wird abgewiesen, statt still eine
    // Warnung zu verschlucken, die in zwei Jahren gebraucht wird.
    $frist = trim((string) ($_POST['xbox_geheimnis_ablauf'] ?? ''));
    if ($frist === '') {
        $neu['xbox']['geheimnis_ablauf'] = '';
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $frist)
              && strtotime($frist) !== false) {
        $neu['xbox']['geheimnis_ablauf'] = $frist;
    } else {
        $hk_fehler[] = 'Das Ablaufdatum des Clientgeheimnisses muss die Form '
            . '<span class="sm-mono">JJJJ-MM-TT</span> haben. Der alte Wert bleibt stehen.';
    }

    if (hk_config_write($neu)) {
        $hk_saved = true;
        $pid = hk_dienst('restart');
        if ($hk_hinweis === '') {
            $hk_hinweis = $pid
                ? 'Der Dienst wurde neu gestartet.'
                : 'Der Dienst l&auml;uft nicht - siehe Reiter Logdateien.';
        }
        $hk_cfg = hk_config_read();
    } else {
        $hk_fehler[] = 'Die Konfigurationsdatei konnte nicht geschrieben werden: '
                  . hk_e($hk_p['config']);
    }
}

/* ============ Anzeige vorbereiten ============ */
$hk_ablauf      = hk_cfg($hk_cfg, 'xbox', 'geheimnis_ablauf', '');
list($hk_ablauf_art, $hk_ablauf_tage, $hk_ablauf_text) = hk_ablauf_lage($hk_ablauf);
$hk_praefix = hk_cfg($hk_cfg, 'heimkino', 'themenpraefix', 'heimkino');
$hk_pid     = hk_dienst_pid();
$hk_z       = hk_zustand();
$hk_alter   = hk_zustand_alter();
$hk_broker  = hk_mqtt_broker();
$hk_zeilen  = hk_log_tail();
$hk_xb      = hk_xbox_zustand();
$hk_anmelde = hk_xbox_anmeldeadresse();
$hk_token   = hk_cfg($hk_cfg, 'heimkino', 'aktionstoken', '');

$hk_frame = class_exists('LBWeb', false);
if ($hk_frame) {
    LBWeb::lbheader('Heimkino', 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=password], .sm-wrap input[type=number], .sm-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.sm-check { font-weight: 400 !important; font-size: 0.95em !important; color: #333 !important; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 180px; }
/* Die Rahmen-CSS von LoxBerry (jQuery Mobile) formatiert jedes <button> mit
   eigenem Hintergrund und eigenen Hover-Regeln. Ohne !important gewinnt sie,
   und dann steht wei&szlig;e Schrift auf hellgrauem Grund - beim Ueberfahren sogar
   wei&szlig; auf wei&szlig;. Deshalb hier durchgesetzt, samt eigener Hover-Farben. */
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
  background: #6dac20 !important; color: #fff !important; border: 0 !important;
  border-radius: 6px !important; padding: 10px 22px !important; font-size: 1em !important;
  cursor: pointer; margin-top: 18px; font-weight: 600 !important;
  text-shadow: none !important; box-shadow: none !important; opacity: 1 !important;
  text-decoration: none !important; }
.sm-wrap .sm-btn:hover, .sm-wrap a.sm-btn:hover, .sm-wrap button.sm-btn:hover,
.sm-wrap .sm-btn:focus, .sm-wrap a.sm-btn:focus, .sm-wrap button.sm-btn:focus {
  background: #5c9219 !important; color: #fff !important; }
.sm-wrap button { box-shadow: none !important; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; word-break: break-all; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-ok-text { color: #4f7d17; font-weight: 600; }
.sm-err-text { color: #c62828; font-weight: 600; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important;
  display: inline-block; text-decoration: none !important; text-shadow: none !important; }
.sm-tab:visited, .sm-tab:hover { text-decoration: none !important; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; width: 100%; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; font-size: 0.9em; vertical-align: middle; }
.sm-tbl th { background: #f0f0f0; }
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe button {
  border: 0 !important; border-radius: 6px !important; padding: 9px 16px !important;
  font-size: 0.9em !important; cursor: pointer; color: #fff !important;
  font-weight: 600 !important; text-shadow: none !important;
  box-shadow: none !important; opacity: 1 !important; margin: 0 !important;
  width: auto !important; }
.sm-wrap .sm-b-lesen button,   .sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-b-lesen button:hover,   .sm-wrap .sm-b-lesen button:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-b-technik button, .sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-b-technik button:hover, .sm-wrap .sm-b-technik button:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-b-aktion button,  .sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-b-aktion button:hover,  .sm-wrap .sm-b-aktion button:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-scheibe { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
.sm-gruen { background: #6dac20; }
.sm-rot { background: #c62828; }
.sm-grau { background: #9e9e9e; }
</style>

<div class="sm-wrap">
<h1 style="font-size:1.4em;margin:0 0 2px;">Heimkino</h1>
<p class="sm-small">LG-Beamer und Xbox vom Miniserver aus schalten.<?php
  $hk_ver = hk_version();
  echo $hk_ver !== '' ? ' Version ' . hk_e($hk_ver) : ''; ?></p>

<?php if ($hk_saved) { ?>
  <div class="sm-alert sm-ok">Die Einstellungen wurden gespeichert.</div>
<?php } ?>
<?php if ($hk_hinweis !== '') { ?>
  <div class="sm-alert sm-info"><?php echo $hk_hinweis; ?></div>
<?php } ?>
<?php if ($hk_fehler) { ?>
  <div class="sm-alert sm-err"><?php
    echo count($hk_fehler) === 1
        ? $hk_fehler[0]
        : '<ul style="margin:0 0 0 18px;padding:0;"><li>'
          . implode('</li><li>', $hk_fehler) . '</li></ul>';
  ?></div>
<?php } ?>

<div class="sm-alert sm-info">
  <span class="sm-scheibe <?php echo $hk_pid ? 'sm-gruen' : 'sm-rot'; ?>"></span>
  <?php echo $hk_pid ? 'Der Dienst l&auml;uft (PID ' . (int) $hk_pid . ').'
                     : 'Der Dienst l&auml;uft nicht.'; ?>
  <?php if ($hk_alter !== null) {
      echo ' Letzte Abfrage vor ' . (int) $hk_alter . ' Sekunden.';
  } ?>
  <?php echo $hk_broker
      ? ' MQTT-Broker: ' . hk_e($hk_broker['host']) . ':' . (int) $hk_broker['port'] . '.'
      : ' <b>Kein MQTT-Broker in general.json</b> - ist das MQTT-Gateway eingerichtet?'; ?>
</div>

<div class="sm-tabs">
  <a class="sm-tab<?php echo hk_aktiv('tab-settings'); ?>" data-ziel="tab-settings" href="index.php?form=settings"><?php echo hk_e(hk_t('REITER.EINSTELLUNGEN')); ?></a>
  <a class="sm-tab<?php echo hk_aktiv('tab-mqtt'); ?>" data-ziel="tab-mqtt" href="index.php?form=mqtt"><?php echo hk_e(hk_t('REITER.MQTT')); ?></a>
  <a class="sm-tab<?php echo hk_aktiv('tab-loxone'); ?>" data-ziel="tab-loxone" href="index.php?form=loxone"><?php echo hk_e(hk_t('REITER.LOXONE')); ?></a>
  <a class="sm-tab<?php echo hk_aktiv('tab-test'); ?>" data-ziel="tab-test" href="index.php?form=test"><?php echo hk_e(hk_t('REITER.TEST')); ?></a>
  <a class="sm-tab<?php echo hk_aktiv('tab-log'); ?>" data-ziel="tab-log" href="index.php?form=log"><?php echo hk_e(hk_t('REITER.LOG')); ?></a>
</div>

<!-- ============================ Einstellungen ============================ -->
<div class="sm-pane<?php echo hk_aktiv('tab-settings'); ?>" id="tab-settings">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo hk_e(hk_t('LEGENDE.AKTION')); ?></span>
</div>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>Allgemein</h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="enabled" value="1"
  <?php echo hk_an($hk_cfg, 'heimkino', 'enabled') ? 'checked' : ''; ?>>
  Plugin eingeschaltet</label>
<label class="sm-check"><input data-role="none" type="checkbox" name="mqtt" value="1"
  <?php echo hk_an($hk_cfg, 'heimkino', 'mqtt') ? 'checked' : ''; ?>>
  Zustand per MQTT melden</label>

<div class="sm-row">
  <div>
    <label for="intervall">Abfragetakt in Sekunden</label>
    <input data-role="none" type="number" id="intervall" name="intervall" min="10" max="3600"
      value="<?php echo hk_e(hk_cfg($hk_cfg, 'heimkino', 'intervall', '60')); ?>">
    <div class="sm-small">Der Beamer nimmt nur eine Verbindung zur Zeit an.
      Ein zu kurzer Takt sperrt die Fernbedienung der App aus. 60 Sekunden
      sind eine gute Vorgabe.</div>
  </div>
  <div>
    <label for="themenpraefix">MQTT-Themenpr&auml;fix</label>
    <input data-role="none" type="text" id="themenpraefix" name="themenpraefix"
      value="<?php echo hk_e($hk_praefix); ?>">
    <div class="sm-small">Alle Themen liegen darunter, alle sind retained.</div>
  </div>
</div>

<h2>Beamer (LG, ab Baujahr 2018)</h2>

<div class="sm-alert sm-info">
<b>Einstellungen am Ger&auml;t &mdash; ohne diese vier bleibt das Plugin wirkungslos.</b>
<ol style="margin:6px 0 0 18px;padding:0;">
<li><b>Verstecktes Men&uuml;: Netzwerk-IP-Steuerung.</b> Mit der Fernbedienung
<i>Alle Einstellungen &rarr; Allgemein &rarr; Netzwerk</i> ansteuern, sodass
<i>Netzwerk</i> markiert ist &mdash; und <b>nicht</b> &ouml;ffnen, nichts
anklicken. Dann z&uuml;gig die Ziffern <b>8&nbsp;2&nbsp;8&nbsp;8&nbsp;8</b>
tippen. Es klappt ein zus&auml;tzliches Men&uuml; auf, das im normalen Betrieb
nicht sichtbar ist.
<div class="sm-small">Klappt es nicht: langsam getippt, oder es war schon eine
Ebene tiefer. Einmal ganz aus dem Men&uuml; heraus und neu ansteuern. Bei
&auml;lteren Ger&auml;ten hei&szlig;t der Punkt <i>Netzwerkverbindung</i> statt
<i>Netzwerk</i>.</div></li>
<li><b>Netzwerk-IP-Steuerung einschalten.</b> Danach <b>Keycode erzeugen</b>
&mdash; acht Zeichen, unten eintragen. Ohne Keycode nimmt das Ger&auml;t keinen
Befehl an; ein neu erzeugter Keycode macht den alten ungültig.</li>
<li><b>Schnellstart+ einschalten</b> (<i>Allgemein &rarr; Ger&auml;te &rarr;
Zus&auml;tzliche Einstellungen</i>). Ohne Schnellstart+ trennt das Ger&auml;t im
Standby die Netzwerkschnittstelle &mdash; dann antwortet Port 9761 nicht mehr,
und Wake-on-LAN kommt gar nicht erst an.</li>
<li><b>Kabel statt Funk und feste Adresse.</b> LAN ist im Standby zuverl&auml;ssiger
als WLAN. Die Adresse in der Fritz!Box festnageln: wandert sie, schl&auml;gt alles
fehl, ohne dass die Ursache sichtbar w&uuml;rde.</li>
</ol>
<div class="sm-small" style="margin-top:8px;"><b>Zwei Automatiken, die St&ouml;rungen
vort&auml;uschen</b> (<i>Allgemein &rarr; System &rarr; Zus&auml;tzliche
Einstellungen</i>): die <i>Ausschaltautomatik</i> (schaltet nach vier Stunden
ohne Bedienung ab) und der <i>Bildschirmschoner</i>. Wer sich fragt, warum der
Beamer &bdquo;von selbst&ldquo; ausgeht, findet die Antwort meist hier und nicht
in Loxone.</div>
</div>

<label class="sm-check"><input data-role="none" type="checkbox" name="beamer_aktiv" value="1"
  <?php echo hk_an($hk_cfg, 'beamer', 'aktiv') ? 'checked' : ''; ?>>
  Beamer verwenden</label>
<div class="sm-row">
  <div>
    <label for="beamer_ip">IP-Adresse</label>
    <input data-role="none" type="text" id="beamer_ip" name="beamer_ip" placeholder="192.168.x.y"
      value="<?php echo hk_e(hk_cfg($hk_cfg, 'beamer', 'ip', '')); ?>">
  </div>
  <div>
    <label for="beamer_mac">MAC-Adresse</label>
    <input data-role="none" type="text" id="beamer_mac" name="beamer_mac" placeholder="AA:BB:CC:DD:EE:FF"
      value="<?php echo hk_e(hk_cfg($hk_cfg, 'beamer', 'mac', '')); ?>">
    <div class="sm-small">Nur f&uuml;r den Testknopf. Das Einschalten macht Loxone
      selbst mit <span class="sm-mono">wol://</span>.</div>
  </div>
</div>
<div class="sm-row">
  <div>
    <label for="beamer_keycode">Keycode (8 Zeichen)</label>
    <input data-role="none" type="text" id="beamer_keycode" name="beamer_keycode" maxlength="8"
      placeholder="ABCD1234" style="text-transform:uppercase"
      value="<?php echo hk_e(hk_cfg($hk_cfg, 'beamer', 'keycode', '')); ?>">
    <div class="sm-small">Am Ger&auml;t erzeugen: Alle Einstellungen &rarr;
      Allgemein &rarr; Netzwerk, dann <b>82888</b> auf der Fernbedienung
      tippen &rarr; Netzwerk-IP-Steuerung einschalten &rarr; Keycode erzeugen.
      Ohne ihn nimmt das Ger&auml;t keinen Befehl an.</div>
  </div>
  <div>
    <label for="beamer_port">Port</label>
    <input data-role="none" type="number" id="beamer_port" name="beamer_port" min="1" max="65535"
      value="<?php echo hk_e(hk_cfg($hk_cfg, 'beamer', 'port', '9761')); ?>">
  </div>
  <div>
    <label for="beamer_zeitgrenze">Zeitgrenze in Sekunden</label>
    <input data-role="none" type="number" id="beamer_zeitgrenze" name="beamer_zeitgrenze" min="1" max="60"
      value="<?php echo hk_e(hk_cfg($hk_cfg, 'beamer', 'zeitgrenze', '5')); ?>">
  </div>
</div>

<h2>Xbox</h2>

<div class="sm-alert sm-info">
<b>Einstellungen an der Konsole.</b> Ohne die ersten beiden kommt kein
Weckbefehl an &mdash; auch nicht &uuml;ber die Cloud.
<ol style="margin:6px 0 0 18px;padding:0;">
<li><b>Energiesparmodus auf <i>Ruhezustand</i></b> (<i>Profil &amp; System &rarr;
Einstellungen &rarr; Allgemein &rarr; Energiesparmodus</i>, fr&uuml;her
<i>Energiesparen &amp; Start</i>). Steht dort <i>Energiesparen</i>, ist die
Konsole im Aus wirklich aus und durch nichts zu wecken.</li>
<li><b>Fernstart / Remote-Features einschalten</b> (<i>Ger&auml;te &amp;
Verbindungen &rarr; Remote-Features</i>): <i>Remote-Features aktivieren</i>. Das
ist derselbe Schalter, den die Xbox-App braucht.</li>
<li><b>Ger&auml;teidentit&auml;t ablesen</b> (<i>System &rarr; Konsoleninfo</i>):
Feld <b>XBOX-Netzwerk-Ger&auml;teidentit&auml;t</b>, 16 Zeichen. Geh&ouml;rt unten
ins Feld &mdash; nicht die <i>Konsolen-ID</i>, nicht die <i>Globale
Ger&auml;te-ID</i>, nicht die <i>Seriennummer</i>.</li>
<li><b>HDMI-CEC</b> (<i>Allgemein &rarr; TV- &amp; A/V-Energieoptionen &rarr;
HDMI-CEC</i>): dort <i>HDMI-CEC aktivieren</i> und <i>Konsole schaltet andere
Ger&auml;te aus</i>. Zum <b>Wecken</b> taugt CEC nicht &mdash; die Begr&uuml;ndung
steht weiter unten.</li>
</ol>
<div class="sm-small" style="margin-top:8px;">Die Konsole muss im Ruhezustand am
Netzwerk h&auml;ngen. Feste Adresse in der Fritz!Box vergeben; im Ruhezustand
verl&auml;ngert WLAN die Weckzeit merklich.</div>
</div>

<label class="sm-check"><input data-role="none" type="checkbox" name="xbox_aktiv" value="1"
  <?php echo hk_an($hk_cfg, 'xbox', 'aktiv') ? 'checked' : ''; ?>>
  Xbox verwenden</label>
<label for="xbox_geraete_id">XBOX-Netzwerk-Ger&auml;teidentit&auml;t</label>
<input data-role="none" type="text" id="xbox_geraete_id" name="xbox_geraete_id"
  value="<?php echo hk_e(hk_cfg($hk_cfg, 'xbox', 'geraete_id', '')); ?>">
<div class="sm-alert sm-info" style="margin-top:6px;">
<b>Diese Ger&auml;teidentit&auml;t steht nicht in Azure.</b> Azure kennt nur die Anwendung, nicht
deine Konsole. Es gibt zwei Quellen:
<ul style="margin:6px 0 0 18px;padding:0;">
<li><b>Reiter Test &rarr; Konsolen suchen</b> &mdash; sobald die Anmeldung steht,
holt das Plugin die Liste aller Konsolen des Kontos samt Identit&auml;t. Der bequeme
Weg.</li>
<li><b>An der Konsole:</b> Einstellungen &rarr; System &rarr; <i>Konsoleninfo</i>,
Feld <b>XBOX-Netzwerk-Ger&auml;teidentit&auml;t</b> &mdash; 16 Zeichen. Nicht die
<i>Konsolen-ID</i>, nicht die <i>Globale Ger&auml;te-ID</i> und nicht die
<i>Seriennummer</i>, die auf derselben Seite stehen.</li>
</ul>
<div class="sm-small" style="margin-top:6px;">Hier geh&ouml;rt die
<b>Ger&auml;teidentit&auml;t</b> hin, nicht der <b>Name</b> der Konsole.
„XBOX-Heimkino" ist ein Name und wird nicht angenommen.</div>
</div>

<label for="xbox_geheimnis_ablauf">Clientgeheimnis g&uuml;ltig bis
  <span class="sm-small">(JJJJ-MM-TT, leer = keine Warnung)</span></label>
<input data-role="none" type="date" id="xbox_geheimnis_ablauf" name="xbox_geheimnis_ablauf"
  value="<?php echo hk_e($hk_ablauf); ?>">
<div class="sm-alert <?php
    echo in_array($hk_ablauf_art, array('abgelaufen', 'bald'), true)
         ? 'sm-err' : 'sm-info'; ?>" style="margin-top:6px;">
<b>Der geheime Clientschl&uuml;ssel h&auml;lt h&ouml;chstens 24 Monate.</b> Azure
vergibt keine l&auml;ngere Frist &mdash; auch nicht mit eigenem Datum. L&auml;uft
er ab, antwortet Microsoft mit
<span class="sm-mono">invalid_client</span> und die Konsole l&auml;sst sich nicht
mehr aus Loxone wecken. Das passiert zwei Jahre nach der Einrichtung, wenn
niemand mehr daran denkt.
<div style="margin-top:6px;"><?php echo $hk_ablauf_text; ?></div>
<div class="sm-small" style="margin-top:6px;">Der Dienst meldet das Datum und die
Restlaufzeit per MQTT
(<span class="sm-mono"><?php echo hk_e($hk_praefix); ?>/xbox/geheimnis_ablauf</span>
und <span class="sm-mono">&hellip;/xbox/geheimnis_tage</span>) &mdash; damit kann
Loxone rechtzeitig eine Nachricht schicken, statt dass es beim n&auml;chsten
Filmabend auffällt. Ab 60 Tagen Restlaufzeit steht zus&auml;tzlich eine Warnung
im Protokoll.</div>
<div class="sm-small" style="margin-top:6px;"><b>Erneuern:</b> in Azure unter
<i>Zertifikate &amp; Geheimnisse</i> ein neues Geheimnis anlegen, die Spalte
<i>Wert</i> unten eintragen, die Anmeldung wiederholen &mdash; und das alte
Geheimnis l&ouml;schen. Die Anwendungskennung bleibt dieselbe, die
Ger&auml;teidentit&auml;t auch.</div>
</div>

<h2>Aktionstoken</h2>
<p class="sm-small">Der Miniserver ruft die Aktionen &uuml;ber eine Adresse im
unangemeldeten Bereich auf. Damit das nicht jedes Ger&auml;t im Netz kann, geh&ouml;rt
in jede Adresse dieses Token. Es wird beim ersten Speichern erzeugt.</p>
<div class="sm-mono"><?php echo $hk_token !== '' ? hk_e($hk_token)
    : 'wird beim ersten Speichern erzeugt'; ?></div>
<label class="sm-check" style="margin-top:8px;">
  <input data-role="none" type="checkbox" name="token_neu" value="1">
  <!-- margin-left, nicht ein Leerzeichen im Text: .sm-check ist im
       Hausstandard "display: inline-flex", und ein Flex-Behaelter VERWIRFT
       den Zwischenraum zwischen seinen Elementen. Deshalb stand auf dem
       Bildschirm "erzeugen(die Adressen", obwohl im Quelltext ein
       Leerzeichen steht.
       An der laufenden Anlage nachgemessen: Text endet bei x=85, die
       Klammer begann bei x=85 - Luecke 0. Mit "display:inline" am Span
       aendert sich daran NICHTS (Flex-Elemente werden ohnehin
       blockartig); erst margin-left ergibt die Luecke, gemessen 5 px. -->
  Neues Token erzeugen <span class="sm-small" style="margin-left:.35em;">(die
  Adressen im Miniserver m&uuml;ssen danach angepasst werden)</span></label>

<!-- Der Knopf gehoert in eine eigene Zeile mit Abstand, nicht unmittelbar
     hinter den Text. -->
<div style="margin-top:28px;text-align:center;">
  <button data-role="none" type="submit" name="save" value="1" class="sm-btn"><?php echo hk_t('ALLGEMEIN.K_SPEICHERN'); ?></button>
</div>
</form>

<!-- Rund fuenf Leerzeilen Abstand, bevor der naechste Abschnitt beginnt. -->
<h2 style="margin-top:120px;">Xbox: Anmeldung bei Microsoft</h2>
<div class="sm-alert sm-info">
Das unauthentifizierte Weckpaket auf UDP&nbsp;5050, mit dem sich Xbox-One-Konsolen
wecken lie&szlig;en, wird von neueren Firmwarest&auml;nden ignoriert. Nachgemessen an
einer Series&nbsp;X: Paketinhalt, Zieladresse und Ger&auml;tekennung waren richtig,
die Konsole reagierte nicht &mdash; die Xbox-App weckt dieselbe Konsole ohne
Weiteres. Sie geht &uuml;ber den Cloud-Dienst von Microsoft, und diesen Weg geht
dieses Plugin auch. Der Preis: eine eigene App-Registrierung, eine einmalige
Anmeldung und eine Internetverbindung.
</div>

<div class="sm-alert sm-err">
<b>Voraussetzung, an der die meisten scheitern:</b> die Registrierung braucht ein
<b>Verzeichnis</b> (Mandant). Ein pers&ouml;nliches Microsoft-Konto hat keines. Unter
<i>App-Registrierungen</i> steht dann „diesem Konto zugeordnet, jedoch in keinem
Verzeichnis enthalten", und <i>Neue Registrierung</i> &ouml;ffnet kein Formular,
sondern ein Fenster mit einem einzigen Knopf: <i>Abbrechen</i>.
<div style="margin-top:8px;">Ein Verzeichnis bekommt man &uuml;ber eine
<b>Azure-Registrierung</b> (kostenloses Konto, verlangt Zahlungsdaten zur
Identit&auml;tspr&uuml;fung) oder das <b>M365-Entwicklerprogramm</b> (kostenlos, Zugang
eingeschr&auml;nkt).</div>
<div style="margin-top:8px;"><b>Und danach unbedingt abmelden und neu
anmelden.</b> Die Portalsitzung tr&auml;gt die Verzeichniszugeh&ouml;rigkeit in sich;
eine Sitzung von vor der Registrierung kennt das neue Verzeichnis nicht. Das
Portal meldet dann <span class="sm-mono">AADSTS16000 &hellip; does not exist in
tenant</span> und unter <i>Portaleinstellungen &rarr; Verzeichnisse +
Abonnements</i> steht „Keine Verzeichnisse gefunden".</div>
</div>

<div class="sm-step">
  <b>Schritt 0 &ndash; pr&uuml;fen, ob das Verzeichnis wirklich da ist.</b> Im
  Azure-Portal oben rechts auf den Kontonamen, dann <i>Verzeichnis wechseln</i>.
  Unter <i>Alle Verzeichnisse</i> muss eines aufgelistet und ausgew&auml;hlt sein.
  Steht dort „Keine Verzeichnisse gefunden", ist die Azure-Registrierung nicht
  abgeschlossen oder auf einem anderen Konto gelaufen &mdash; alles Weitere ist
  dann zwecklos.
</div>

<div class="sm-alert sm-info">
<b>HDMI-CEC weckt die Konsole nicht &mdash; nachgesehen, nicht vermutet.</b>
Unter <i>Allgemein &rarr; TV- &amp; A/V-Energieoptionen &rarr; HDMI-CEC</i>
kennt die Xbox genau diese Richtungen:
<ul style="margin:6px 0 6px 18px;padding:0;">
<li>Konsole schaltet andere Ger&auml;te <b>ein</b></li>
<li>Konsole schaltet andere Ger&auml;te <b>aus</b></li>
<li>Andere Ger&auml;te k&ouml;nnen die Konsole <b>deaktivieren</b></li>
</ul>
Eine Zeile <i>andere Ger&auml;te k&ouml;nnen die Konsole einschalten</i> gibt es nicht.
Die Konsole nimmt von au&szlig;en nur den Ausschaltbefehl an. CEC kann sie also
<b>ausschalten</b>, aber nicht wecken &mdash; unabh&auml;ngig von Kabel und
Verst&auml;rker.
<div class="sm-small">Praktische Folge: <b>xbox-aus</b> aus diesem Plugin ist
oft &uuml;berfl&uuml;ssig, weil der Verst&auml;rker das per CEC schon erledigt. F&uuml;r das
<b>Wecken</b> bleiben der Controller oder der Cloud-Weg unten.</div>
</div>

<div class="sm-step">
  <b>Schritt 1 &ndash; Anwendung registrieren.</b>
  <i>App-Registrierungen &rarr; Neue Registrierung</i>.
  <ul style="margin:6px 0 6px 18px;padding:0;">
  <li><b>Name:</b> frei w&auml;hlbar, z. B. <span class="sm-mono">LoxBerry Heimkino</span></li>
  <li><b>Unterst&uuml;tzte Kontotypen:</b> <b>Nur pers&ouml;nliche Microsoft-Konten</b> &mdash; die
      Konsole h&auml;ngt an einem pers&ouml;nlichen Konto, nicht am Verzeichnis. Das
      Verzeichnis wird nur gebraucht, um die Anwendung &uuml;berhaupt anlegen zu
      d&uuml;rfen.</li>
  <li><b>Umleitungs-URI:</b> Typ <b>Web</b>, Adresse genau
      <span class="sm-mono"><?php echo hk_e($hk_xb['rueckleitung']); ?></span></li>
  </ul>
  Dann <i>Registrieren</i>.
</div>

<div class="sm-step">
  <b>Schritt 2 &ndash; Kennung abschreiben.</b> Auf der Seite <i>&Uuml;bersicht</i>
  der Anwendung steht <b>Anwendungs-ID (Client)</b>. Diese Nummer unten einsetzen.
  Sie ist kein Geheimnis.
  <div class="sm-small">Nicht zu verwechseln mit <i>Verzeichnis-ID (Mandant)</i> oder
  <i>Objekt-ID</i>, die auf derselben Seite direkt darunter stehen.</div>
</div>

<div class="sm-step">
  <b>Schritt 3 &ndash; Geheimnis erzeugen.</b> Links <i>Zertifikate &amp;
  Geheimnisse</i>, Reiter <i>Geheime Clientschl&uuml;ssel</i>,
  <i>Neuer geheimer Clientschl&uuml;ssel</i>. Beschreibung frei, <i>G&uuml;ltig bis</i> nach Wunsch &mdash; nach Ablauf
  ist ein neues Geheimnis und eine neue Anmeldung f&auml;llig.
  <div class="sm-alert sm-err" style="margin-top:8px;">
  Die Tabelle zeigt danach vier Spalten: <i>Beschreibung</i>, <i>G&uuml;ltig bis</i>,
  <b>Wert</b> und <i>Geheime ID</i>.
  <b>Gebraucht wird die Spalte „Wert".</b> Die Spalte „Geheime ID" ist es
  <b>nicht</b> &mdash; sie sieht aus wie eine Kennung und wird deshalb gern
  verwechselt.
  <div class="sm-small" style="margin-top:6px;">Die Spalte „Wert" ist <b>nur
  dieses eine Mal</b> sichtbar. Wer die Seite verl&auml;sst, sieht sie nie wieder und
  muss ein neues Geheimnis anlegen &mdash; das alte kann man dann l&ouml;schen.</div>
  </div>
</div>


<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<div class="sm-row">
  <div>
    <label for="client_id">Anwendungskennung (Client-ID)</label>
    <input data-role="none" type="text" id="client_id" name="client_id"
      value="<?php echo hk_e($hk_xb['client_id']); ?>">
  </div>
  <div>
    <label for="client_secret">Geheimer Clientschl&uuml;ssel &mdash; Spalte <i>Wert</i></label>
    <input data-role="none" type="password" id="client_secret" name="client_secret"
      placeholder="<?php echo $hk_xb['geheim'] ? 'gespeichert - leer lassen, um es zu behalten' : ''; ?>">
  </div>
</div>
<!-- Der Knopf steht ABSICHTLICH vor der Umleitungs-URI, mit je rund fuenf
     Leerzeilen Abstand. Am Verhalten aendert das nichts: es ist EIN Formular,
     der Knopf schickt alle Felder ab - auch das darunter liegende. -->
<div style="margin-top:60px;margin-bottom:60px;text-align:center;">
  <button data-role="none" type="submit" name="xbox_app" value="1" class="sm-btn"><?php echo hk_t('ALLGEMEIN.K_KENNUNG_SPEICHERN'); ?></button>
</div>

<label for="rueckleitung">Umleitungs-URI</label>
<input data-role="none" type="text" id="rueckleitung" name="rueckleitung"
  value="<?php echo hk_e($hk_xb['rueckleitung']); ?>">
</form>

<?php if ($hk_anmelde !== '') { ?>
<div class="sm-step">
  <b>Schritt 2 &ndash; anmelden.</b> Diese Adresse in einem Browser &ouml;ffnen und
  mit dem Microsoft-Konto anmelden, an dem die Konsole h&auml;ngt.
  <p><a href="<?php echo hk_e($hk_anmelde); ?>" target="_blank" rel="noopener"
    class="sm-btn" style="display:inline-block;text-decoration:none;">Anmeldeseite
    &ouml;ffnen</a></p>
  <div class="sm-small">Der Browser landet danach auf einer Seite, die nicht
  l&auml;dt &mdash; das ist richtig so. Entscheidend ist die Adresszeile: sie
  enth&auml;lt <span class="sm-mono">?code=&hellip;</span>. Die ganze Adresse
  kopieren und unten einsetzen.</div>
</div>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<label for="code">Zur&uuml;ckgeleitete Adresse oder Code</label>
<input data-role="none" type="text" id="code" name="code"
  placeholder="http://localhost/auth/callback?code=...">
<button data-role="none" type="submit" name="xbox_code" value="1" class="sm-btn"><?php echo hk_t('ALLGEMEIN.K_ANMELDUNG_FERTIG'); ?></button>
</form>
<div class="sm-alert sm-info">
Meldet Microsoft hier <span class="sm-mono">invalid_client &mdash; The provided
value for the 'client_secret' parameter is not valid</span>, dann liegt es an
einem von zwei Dingen:
<ol style="margin:6px 0 0 18px;padding:0;">
<li><b>Es wurde die falsche Spalte kopiert.</b> Weitaus h&auml;ufigster Fall.
<i>Geheime ID</i> statt <i>Wert</i>. Der Reiter <b>Test &rarr; Anmeldedaten
pr&uuml;fen</b> sagt, welche Form das gespeicherte Geheimnis hat &mdash; ohne es
anzuzeigen.</li>
<li><b>Das Geheimnis ist abgelaufen</b> &mdash; Spalte <i>G&uuml;ltig bis</i>.
Dann ein neues anlegen.</li>
</ol>
<div class="sm-small" style="margin-top:6px;">Bleibt es dabei, obwohl die Spalte
<i>Wert</i> frisch kopiert wurde, hilft der andere Anmeldedienst: in
<span class="sm-mono">xbox_auth.json</span> den Eintrag
<span class="sm-mono">"dienst": "v2"</span> setzen. Dann l&auml;uft die Anmeldung
&uuml;ber <span class="sm-mono">login.microsoftonline.com</span> statt
<span class="sm-mono">login.live.com</span> &mdash; manche Registrierungen nimmt
nur der eine oder nur der andere an.</div>
</div>
<form method="post" action="index.php" style="display:none">
</form>
<?php } ?>

<p style="margin-top:14px;">
  <span class="sm-scheibe <?php echo $hk_xb['angemeldet'] ? 'sm-gruen' : 'sm-grau'; ?>"></span>
  <?php echo $hk_xb['angemeldet']
      ? 'Angemeldet. Das Erneuerungstoken liegt vor, eine neue Anmeldung ist '
        . 'erst n&ouml;tig, wenn Microsoft es verwirft.'
      : 'Noch nicht angemeldet.'; ?>
</p>
<?php if ($hk_xb['angemeldet']) { ?>
<div class="sm-knopfreihe sm-b-aktion">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" type="submit" name="xbox_vergessen" value="1"><?php echo hk_t('ALLGEMEIN.K_ANMELDUNG_LOESCHEN'); ?></button>
  </form>
</div>
<?php } ?>
</div>

<!-- ================================= MQTT ================================= -->
<div class="sm-pane<?php echo hk_aktiv('tab-mqtt'); ?>" id="tab-mqtt">
<h2><?php echo hk_e(hk_t('MQTT.H_ZUSTAND')); ?></h2>
<p class="sm-small"><?php echo hk_t('MQTT.KERNBESTANDTEIL'); ?></p>

<?php if (!$hk_broker) { ?>
<div class="sm-alert sm-warn"><?php echo hk_t('MQTT.KEIN_BROKER'); ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th style="width:34%"><?php echo hk_e(hk_t('MQTT.SP_GROESSE')); ?></th><th><?php echo hk_e(hk_t('MQTT.SP_WERT')); ?></th></tr>
<tr><td><?php echo hk_e(hk_t('MQTT.BROKER')); ?></td><td class="sm-mono"><?php
  echo hk_e($hk_broker['host'] . ':' . $hk_broker['port']); ?></td></tr>
<tr><td><?php echo hk_e(hk_t('MQTT.LOKAL')); ?></td><td><?php
  echo $hk_broker['lokal'] ? hk_e(hk_t('ALLGEMEIN.JA')) : hk_t('MQTT.LOKAL_NEIN'); ?></td></tr>
<tr><td><?php echo hk_e(hk_t('MQTT.AUTOSTART')); ?></td><td><?php
  echo $hk_broker['autostart'] ? hk_e(hk_t('ALLGEMEIN.JA')) : hk_t('MQTT.AUTOSTART_NEIN'); ?></td></tr>
<tr><td><?php echo hk_e(hk_t('MQTT.BENUTZER')); ?></td><td class="sm-mono"><?php
  echo $hk_broker['benutzer'] !== '' ? hk_e($hk_broker['benutzer']) : '&ndash;'; ?></td></tr>
<tr><td><?php echo hk_e(hk_t('MQTT.MELDUNG')); ?></td><td><?php
  echo hk_an($hk_cfg, 'heimkino', 'mqtt')
     ? hk_e(hk_t('MQTT.MELDUNG_AN'))
     : hk_t('MQTT.MELDUNG_AUS'); ?></td></tr>
</table>
<?php } ?>

<h2><?php echo hk_e(hk_t('MQTT.H_ABO')); ?></h2>
<p class="sm-small"><?php echo hk_t('MQTT.ABO_HINWEIS'); ?></p>
<pre class="sm-mono" style="background:#f4f4f4;border:1px solid #ccc;padding:10px;"><?php
echo hk_e($hk_praefix); ?>/#</pre>

<h2><?php echo hk_e(hk_t('MQTT.H_THEMEN')); ?></h2>
<p class="sm-small"><?php echo hk_t('MQTT.THEMEN_RETAINED'); ?></p>
<table class="sm-tbl">
<tr><th style="width:44%"><?php echo hk_e(hk_t('MQTT.SP_THEMA')); ?></th><th><?php echo hk_e(hk_t('MQTT.SP_BEDEUTUNG')); ?></th></tr>
<?php foreach (hk_themen() as $thema => $bedeutung) { ?>
<tr><td class="sm-mono"><?php echo hk_e($hk_praefix . '/' . $thema); ?></td>
    <td><?php echo $bedeutung; ?></td></tr>
<?php } ?>
</table>

<p class="sm-small"><?php echo hk_t('MQTT.REGELWEG'); ?></p>
</div>

<!-- ========================= Einbindung in Loxone ========================= -->
<div class="sm-pane<?php echo hk_aktiv('tab-loxone'); ?>" id="tab-loxone">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo hk_e(hk_t('LEGENDE.LESEN')); ?></span>
</div>

<h2>Einbindung in Loxone &ndash; Schritt f&uuml;r Schritt</h2>
<p class="sm-small">Das Plugin fragt Beamer und Xbox ab und meldet jeden Zustand als eigenes
MQTT-Thema (Schritt&nbsp;1 und&nbsp;2). Umgekehrt nimmt es &uuml;ber einfache Adressen Befehle
entgegen (Schritt&nbsp;3). Daraus l&auml;sst sich eine Kino-Szene bauen, die auf Knopfdruck
alles einschaltet und beim Verlassen wieder abr&auml;umt.</p>

<h2>Schritt 1: Abo im MQTT-Gateway eintragen</h2>
<p class="sm-small"><b>Ohne diesen Eintrag kommt am Miniserver nichts an.</b> Einzutragen unter
<i>System-Einstellungen &rarr; MQTT Gateway &rarr; Abonnements</i>:</p>
<pre class="sm-mono" style="background:#f4f4f4;border:1px solid #ccc;padding:10px;"><?php
echo hk_e($hk_praefix); ?>/#</pre>

<h2>Schritt 2: Zustand lesen &ndash; &uuml;ber MQTT</h2>
<p class="sm-small">Der Dienst meldet jeden Wert <b>retained</b> an den Broker.
Das MQTT-Gateway von LoxBerry leitet sie an den Miniserver weiter. Alle Themen
liegen unter <span class="sm-mono"><?php echo hk_e($hk_praefix); ?>/</span>.</p>

<table class="sm-tbl">
<tr><th>Thema</th><th>Bedeutung</th></tr>
<?php foreach (hk_themen() as $thema => $bedeutung) { ?>
<tr><td class="sm-mono"><?php echo hk_e($hk_praefix . '/' . $thema); ?></td>
    <td><?php echo hk_e($bedeutung); ?></td></tr>
<?php } ?>
</table>

<div class="sm-knopfreihe sm-b-lesen">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" type="submit" name="download" value="mqtt_in"><?php echo hk_t('ALLGEMEIN.K_VORLAGE_EINGAENGE'); ?></button>
  </form>
</div>
<p class="sm-small">Die Datei l&auml;sst sich in Loxone Config unter
<i>Virtuelle Eing&auml;nge</i> einlesen. Sie legt die Eing&auml;nge mit den richtigen
Namen an; die Werte kommen dann vom MQTT-Gateway.</p>

<h2>Schritt 3: Schalten &ndash; &uuml;ber virtuelle Ausg&auml;nge</h2>
<?php if ($hk_token === '') { ?>
<div class="sm-alert sm-err">Es gibt noch kein Aktionstoken. Einmal im Reiter
<i>Einstellungen</i> speichern, dann erscheinen hier die vollst&auml;ndigen
Adressen.</div>
<?php } else { ?>
<p class="sm-small">In Loxone Config einen <b>virtuellen Ausgang</b> anlegen.
Bei ihm steht nur die Adresse des LoxBerry, die Befehle kommen in die
<b>virtuellen Ausgangsbefehle</b> darunter.</p>

<table class="sm-tbl">
<tr><th style="width:22%">Feld</th><th>Wert</th></tr>
<tr><td>Adresse des Ausgangs</td>
    <td class="sm-mono">http://<?php echo hk_e(gethostname() ?: 'loxberry'); ?></td></tr>
</table>
<div class="sm-knopfreihe sm-b-lesen">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" type="submit" name="download" value="vo"><?php echo hk_t('ALLGEMEIN.K_VORLAGE_VO'); ?></button>
  </form>
</div>
<p class="sm-small">Die Datei l&auml;sst sich in Loxone Config unter <i>Virtuelle Ausg&auml;nge</i>
einlesen. Sie legt die Aktionsaufrufe samt Aktionstoken an.</p>
<p class="sm-small">Statt des Rechnernamens geht auch die IP des LoxBerry. Kein
Benutzer und kein Passwort &mdash; der Aktionsendpunkt liegt im unangemeldeten
Bereich und pr&uuml;ft stattdessen das Token.</p>

<table class="sm-tbl">
<tr><th style="width:22%">Ausgangsbefehl</th><th>Befehl bei EIN (Methode GET)</th></tr>
<?php foreach (hk_aktionen() as $aktion => $bezeichnung) { ?>
<tr><td><?php echo hk_e($bezeichnung); ?></td>
    <td class="sm-mono"><?php echo hk_e(hk_aktionsadresse($hk_cfg, $aktion)); ?></td></tr>
<?php } ?>
</table>
<div class="sm-alert sm-info">
Ein virtueller Ausgangsbefehl feuert bei der Flanke 0&rarr;1, nicht dauerhaft.
F&uuml;r das <b>Einschalten des Beamers</b> braucht es dieses Plugin nicht: Loxone
kann Wake-on-LAN selbst. Ein virtueller Ausgang mit der Adresse
<span class="sm-mono">wol://</span> und der MAC ohne Trennzeichen als Befehl
gen&uuml;gt und ist der k&uuml;rzere Weg.
</div>
<?php } ?>

<h2>Schritt 4: Kachel in der App</h2>
<p class="sm-small">Einen <i>Status</i>-Baustein anlegen: <span class="sm-mono">v1</span> mit
<span class="sm-mono">beamer_an</span>, <span class="sm-mono">v2</span> mit
<span class="sm-mono">xbox_an</span> verbinden. Zwei Statustexte gen&uuml;gen &mdash; einer f&uuml;r
&bdquo;Kino l&auml;uft&ldquo;, einer f&uuml;r &bdquo;alles aus&ldquo;. H&auml;kchen
<i>Visualisierung</i> setzen, fertig.</p>

<h2>Schritt 5: Das Clientgeheimnis l&auml;uft ab</h2>
<p class="sm-small">Das Geheimnis der Xbox-Anmeldung bei Microsoft hat ein Ablaufdatum. L&auml;uft es
ab, meldet sich die Konsole nicht mehr &mdash; ohne jede Fehlermeldung in der App. Das Plugin liefert
deshalb <span class="sm-mono">xbox_geheimnis_tage</span>: Tage bis zum Ablauf, negativ bedeutet
abgelaufen. Ein Schwellwertschalter darauf und eine Benachrichtigung ersparen die b&ouml;se
&Uuml;berraschung. Aufbau in Schritt&nbsp;6, Zeilen 12 und 13.</p>

<h2>Schritt 6: Komplette Baustein-Liste zum 1:1-Nachbauen</h2>
<p class="sm-small">So sieht die vollst&auml;ndige Logik auf der Programmierseite aus (jede Zeile =
ein Baustein). Alle Bausteine findet man in Loxone Config &uuml;ber die Baustein-Suche (F5):</p>
<table class="sm-tbl">
<tr><th>#</th><th>Baustein (Typ)</th><th>Name (Vorschlag)</th><th>Parameter</th><th>Eing&auml;nge verbinden mit</th></tr>
<tr><td>1</td><td>Virtueller Eingang</td><td class="sm-mono"><?php echo hk_e($hk_praefix); ?>_beamer_an</td><td>digital</td><td>&mdash; (kommt &uuml;ber das Gateway)</td></tr>
<tr><td>2</td><td>Virtueller Eingang</td><td class="sm-mono"><?php echo hk_e($hk_praefix); ?>_beamer_erreichbar</td><td>digital</td><td>&mdash;</td></tr>
<tr><td>3</td><td>Virtueller Eingang</td><td class="sm-mono"><?php echo hk_e($hk_praefix); ?>_xbox_an</td><td>digital</td><td>&mdash;</td></tr>
<tr><td>4</td><td>Virtueller Eingang</td><td class="sm-mono"><?php echo hk_e($hk_praefix); ?>_xbox_angemeldet</td><td>digital</td><td>&mdash;</td></tr>
<tr><td>5</td><td>Virtueller Eingang</td><td class="sm-mono"><?php echo hk_e($hk_praefix); ?>_xbox_geheimnis_tage</td><td>analog, Einheit Tage</td><td>&mdash;</td></tr>
<tr><td>6</td><td>Virtueller Eingang</td><td class="sm-mono"><?php echo hk_e($hk_praefix); ?>_service_online</td><td>digital</td><td>&mdash;</td></tr>
<tr><td>7</td><td>Merker (remanent, Visu)</td><td>Kino-Modus</td><td>Visualisierung EIN &mdash; der Schalter in der App</td><td>&mdash; (Bedienung)</td></tr>
<tr><td>8</td><td>Flankenerkennung (steigend)</td><td>Kino startet</td><td>&mdash;</td><td>Eingang = #7</td></tr>
<tr><td>9</td><td>Flankenerkennung (fallend)</td><td>Kino endet</td><td>&mdash;</td><td>Eingang = #7</td></tr>
<tr><td>10</td><td>Virtueller Ausgang + Befehle</td><td>Heimkino</td><td>Adresse des LoxBerry, Befehle wie in Schritt&nbsp;3</td><td><span class="sm-mono">beamer-wol</span> und <span class="sm-mono">xbox-an</span> &larr; #8, <span class="sm-mono">beamer-aus</span> und <span class="sm-mono">xbox-aus</span> &larr; #9</td></tr>
<tr><td>11</td><td>Einschaltverz&ouml;gerung</td><td>Beamer kam nicht hoch</td><td>90&nbsp;s</td><td>Eingang = #7 UND NICHT #1 &rarr; Benachrichtigung</td></tr>
<tr><td>12</td><td>Schwellwertschalter</td><td>Xbox-Geheimnis l&auml;uft ab</td><td>Ein <b>14</b> / Aus <b>21</b> (Ein &lt; Aus = schaltet beim <b>Unter</b>schreiten ein)</td><td>Eingang = #5</td></tr>
<tr><td>13</td><td>Benachrichtigung</td><td>Xbox-Geheimnis erneuern</td><td>Text z.&nbsp;B. &bdquo;Das Clientgeheimnis der Xbox-Anmeldung l&auml;uft in weniger als 14 Tagen ab.&ldquo;</td><td>&larr; #12</td></tr>
<tr><td>14</td><td>Status</td><td>Heimkino</td><td>Statustext siehe Schritt&nbsp;4, Visualisierung EIN</td><td>v1 = #1, v2 = #3</td></tr>
</table>
<div class="sm-alert sm-info">
<b>Zu #10:</b> ein virtueller Ausgangsbefehl feuert bei der Flanke 0&rarr;1, nicht dauerhaft. Deshalb
die beiden Flankenbausteine #8 und #9 &mdash; ein Merker allein w&uuml;rde beim Einschalten genau
einmal ausl&ouml;sen und beim Ausschalten gar nicht.<br>
<b>Zu #11:</b> der Beamer braucht nach dem Einschalten rund eine Minute, bis er auf Port 9761
antwortet. Eine k&uuml;rzere Verz&ouml;gerung meldet einen Fehler, der keiner ist.<br>
<b>Zu #13:</b> der Benachrichtigungs-Baustein sendet nur bei einem Wechsel von Aus auf Ein. Niemals
mehrere Quellen direkt an seinen Eingang legen &mdash; erst &uuml;ber einen ODER-Baustein
zusammenf&uuml;hren.
</div>
</div>

<!-- ================================ Test ================================ -->
<div class="sm-pane<?php echo hk_aktiv('tab-test'); ?>" id="tab-test">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo hk_e(hk_t('LEGENDE.LESEN')); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo hk_e(hk_t('LEGENDE.AKTION')); ?></span>
</div>

<?php if ($hk_test_titel !== '') { ?>
<div class="sm-alert sm-ok"><b><?php echo hk_e($hk_test_titel); ?></b></div>
<?php echo $hk_test_text; ?>
<?php } ?>

<h2>Nachsehen</h2>
<div class="sm-knopfreihe sm-b-lesen">
<?php
$ansehen = array(
    'umgebung'          => 'Umgebung pr&uuml;fen',
    'anmeldedaten'      => 'Anmeldedaten pr&uuml;fen',
    'krypto'            => 'Verschl&uuml;sselung pr&uuml;fen',
    'beamer_erreichbar' => 'Beamer erreichbar?',
    'beamer_status'     => 'Beamer: Zustand',
    'beamer_ipcontrol'  => 'Beamer: IP-Steuerung',
    'xbox_status'       => 'Xbox: Zustand',
    'xbox_konsolen'     => 'Konsolen suchen',
);
foreach ($ansehen as $wert => $text) { ?>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" type="submit" name="test" value="<?php echo hk_e($wert); ?>"><?php
      echo hk_e($text); ?></button>
  </form>
<?php } ?>
</div>

<h2>Technik</h2>
<div class="sm-knopfreihe sm-b-aktion">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" type="submit" name="test" value="dienst_neu"><?php echo hk_t('ALLGEMEIN.K_DIENST_NEU'); ?></button>
  </form>
</div>

<h2>Schalten</h2>
<p class="sm-small">Diese Kn&ouml;pfe wirken sofort auf die Ger&auml;te.</p>
<div class="sm-knopfreihe sm-b-aktion">
<?php
$aktionen = array(
    'beamer_aus' => 'Beamer ausschalten',
    'beamer_wol' => 'Beamer per WoL einschalten',
    'xbox_an'    => 'Xbox wecken',
    'xbox_aus'   => 'Xbox ausschalten',
);
foreach ($aktionen as $wert => $text) { ?>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" type="submit" name="test" value="<?php echo hk_e($wert); ?>"><?php
      echo hk_e($text); ?></button>
  </form>
<?php } ?>
</div>

<h2>Frist des Clientgeheimnisses</h2>
<p><span class="sm-scheibe <?php
   echo $hk_ablauf_art === 'ok' ? 'sm-gruen'
      : ($hk_ablauf_art === 'leer' ? 'sm-grau' : 'sm-rot'); ?>"></span>
<?php echo $hk_ablauf_text; ?></p>

<h2>Letzter Stand des Dienstes</h2>
<?php if ($hk_z === null) { ?>
<div class="sm-alert sm-info">Der Dienst hat noch keinen Zustand abgelegt.</div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th style="width:30%">Gr&ouml;&szlig;e</th><th>Wert</th></tr>
<tr><td>Zeitpunkt</td><td><?php echo hk_e($hk_z['zeit_text'] ?? ''); ?></td></tr>
<tr><td>Beamer verwendet</td><td><?php
  echo !empty($hk_z['beamer']['aktiv']) ? 'ja' : 'nein'; ?></td></tr>
<tr><td>Beamer erreichbar</td><td><?php
  echo !empty($hk_z['beamer']['erreichbar']) ? 'ja' : 'nein'; ?></td></tr>
<tr><td>Beamer Zustand</td><td><?php
  echo hk_e($hk_z['beamer']['status'] ?? ''); ?></td></tr>
<tr><td>Beamer Quelle</td><td><?php
  echo hk_e($hk_z['beamer']['app'] ?? ''); ?></td></tr>
<tr><td>Xbox verwendet</td><td><?php
  echo !empty($hk_z['xbox']['aktiv']) ? 'ja' : 'nein'; ?></td></tr>
<tr><td>Xbox Zustand</td><td><?php
  echo hk_e($hk_z['xbox']['status'] ?? ''); ?></td></tr>
<tr><td>Xbox angemeldet</td><td><?php
  echo !empty($hk_z['xbox']['angemeldet']) ? 'ja' : 'nein'; ?></td></tr>
<?php if (!empty($hk_z['xbox']['quelle'])) { ?>
<tr><td>Xbox Quelle der Auskunft</td><td><?php
  echo $hk_z['xbox']['quelle'] === 'liste'
      ? 'Konsolenliste (Ersatzweg &mdash; die direkte Statusabfrage wird vom '
        . 'Gateway abgewiesen)'
      : 'direkte Statusabfrage'; ?></td></tr>
<?php } ?>
<?php $f = ($hk_z['beamer']['fehler'] ?? '') ?: ($hk_z['xbox']['fehler'] ?? '');
  if ($f !== '') { ?>
<tr><td>Letzter Fehler</td><td class="sm-err-text"><?php echo hk_e($f); ?></td></tr>
<?php } ?>
</table>
<?php } ?>
</div>

<!-- ============================== Logdateien ============================== -->
<div class="sm-pane<?php echo hk_aktiv('tab-log'); ?>" id="tab-log">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo hk_e(hk_t('LEGENDE.LESEN')); ?></span>
</div>
<h2>Protokoll</h2>
<p class="sm-small">Neueste Zeile oben. Datei:
<span class="sm-mono"><?php echo hk_e($hk_p['log']); ?></span></p>
<?php if (!$hk_zeilen) { ?>
<div class="sm-alert sm-info">Die Protokolldatei ist leer oder nicht lesbar.</div>
<?php } else { ?>
<div class="sm-log"><?php
  foreach ($hk_zeilen as $zeile) { echo hk_e($zeile) . "\n"; }
?></div>
<?php } ?>
<div class="sm-knopfreihe sm-b-lesen" style="margin-top:12px;">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" type="submit" name="nichts" value="1"><?php echo hk_t('ALLGEMEIN.K_NEU_LADEN'); ?></button>
  </form>
</div>
</div>

</div>

<script>
(function () {
  var start = <?php echo json_encode($hk_tab); ?>;
  var reiter = document.querySelectorAll('.sm-tab');
  var seiten = document.querySelectorAll('.sm-pane');
  function zeige(ziel) {
    for (var i = 0; i < reiter.length; i++) {
      reiter[i].classList.toggle('sm-active', reiter[i].getAttribute('data-ziel') === ziel);
    }
    for (var j = 0; j < seiten.length; j++) {
      seiten[j].classList.toggle('sm-active', seiten[j].id === ziel);
    }
    var felder = document.querySelectorAll('input[name="activetab"]');
    for (var k = 0; k < felder.length; k++) { felder[k].value = ziel; }
  }
  for (var i = 0; i < reiter.length; i++) {
    reiter[i].addEventListener('click', function (ereignis) {
      // Ohne JavaScript folgt der Browser dem href, und der Server liefert
      // den richtigen Reiter. Mit JavaScript geht es schneller ohne
      // Neuladen - deshalb hier den Verweis abfangen.
      if (ereignis && ereignis.preventDefault) { ereignis.preventDefault(); }
      zeige(this.getAttribute('data-ziel'));
    });
  }
  zeige(start);
})();
</script>

<?php
if ($hk_frame) {
    LBWeb::lbfooter();
}
