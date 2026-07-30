<?php
/**
 * Heimkino - Admin-Oberflaeche (v1.0.0)
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
$hk_tab = preg_match('/^tab-(settings|loxone|test|log)$/',
                     (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))
    ? $_POST['activetab'] : 'tab-settings';

$hk_cfg = hk_config_read();

/* ============ Loxone-Vorlage herunterladen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    list($name, $inhalt) = hk_vorlage($hk_cfg);
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
    $hk_hinweis = $code_r === 0 ? 'Anmeldung geloescht.' : '';
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
        $neu['heimkino']['aktionstoken'] = hk_token_erzeugen();
        if (isset($_POST['token_neu'])) {
            $hk_hinweis = 'Neues Aktionstoken erzeugt. Die Adressen im '
                        . 'Miniserver muessen angepasst werden.';
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
            . '<span class="hk-mono">JJJJ-MM-TT</span> haben. Der alte Wert bleibt stehen.';
    }

    if (hk_config_write($neu)) {
        $hk_saved = true;
        $pid = hk_dienst('restart');
        if ($hk_hinweis === '') {
            $hk_hinweis = $pid
                ? 'Der Dienst wurde neu gestartet.'
                : 'Der Dienst laeuft nicht - siehe Reiter Logdateien.';
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
.hk-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.hk-wrap, .hk-wrap * { text-shadow: none !important; }
.hk-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.hk-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.hk-wrap input[type=text], .hk-wrap input[type=password], .hk-wrap input[type=number], .hk-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.hk-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.hk-check { font-weight: 400 !important; font-size: 0.95em !important; color: #333 !important; }
.hk-row { display: flex; gap: 12px; flex-wrap: wrap; }
.hk-row > div { flex: 1; min-width: 180px; }
/* Die Rahmen-CSS von LoxBerry (jQuery Mobile) formatiert jedes <button> mit
   eigenem Hintergrund und eigenen Hover-Regeln. Ohne !important gewinnt sie,
   und dann steht weisse Schrift auf hellgrauem Grund - beim Ueberfahren sogar
   weiss auf weiss. Deshalb hier durchgesetzt, samt eigener Hover-Farben. */
.hk-wrap .hk-btn, .hk-wrap a.hk-btn, .hk-wrap button.hk-btn {
  background: #6dac20 !important; color: #fff !important; border: 0 !important;
  border-radius: 6px !important; padding: 10px 22px !important; font-size: 1em !important;
  cursor: pointer; margin-top: 18px; font-weight: 600 !important;
  text-shadow: none !important; box-shadow: none !important; opacity: 1 !important;
  text-decoration: none !important; }
.hk-wrap .hk-btn:hover, .hk-wrap a.hk-btn:hover, .hk-wrap button.hk-btn:hover,
.hk-wrap .hk-btn:focus, .hk-wrap a.hk-btn:focus, .hk-wrap button.hk-btn:focus {
  background: #5c9219 !important; color: #fff !important; }
.hk-wrap button { box-shadow: none !important; }
.hk-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.hk-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.hk-err { background: #ffebee; border: 1px solid #ef9a9a; }
.hk-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.hk-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; word-break: break-all; }
.hk-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.hk-ok-text { color: #4f7d17; font-weight: 600; }
.hk-err-text { color: #c62828; font-weight: 600; }
.hk-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.hk-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; }
.hk-tab.hk-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.hk-pane { display: none; padding-top: 4px; }
.hk-pane.hk-active { display: block; }
.hk-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.hk-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.hk-tbl { border-collapse: collapse; margin: 8px 0; width: 100%; }
.hk-tbl th, .hk-tbl td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; font-size: 0.9em; vertical-align: middle; }
.hk-tbl th { background: #f0f0f0; }
.hk-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.hk-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.hk-knopfreihe form { margin: 0; display: flex; }
.hk-wrap .hk-knopfreihe button {
  border: 0 !important; border-radius: 6px !important; padding: 9px 16px !important;
  font-size: 0.9em !important; cursor: pointer; color: #fff !important;
  font-weight: 600 !important; text-shadow: none !important;
  box-shadow: none !important; opacity: 1 !important; margin: 0 !important;
  width: auto !important; }
.hk-wrap .hk-g1 button { background: #6dac20 !important; }
.hk-wrap .hk-g1 button:hover, .hk-wrap .hk-g1 button:focus { background: #5c9219 !important; color: #fff !important; }
.hk-wrap .hk-g2 button { background: #546e7a !important; }
.hk-wrap .hk-g2 button:hover, .hk-wrap .hk-g2 button:focus { background: #435962 !important; color: #fff !important; }
.hk-wrap .hk-g3 button { background: #e0620d !important; }
.hk-wrap .hk-g3 button:hover, .hk-wrap .hk-g3 button:focus { background: #b84f0a !important; color: #fff !important; }
.hk-scheibe { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
.hk-gruen { background: #6dac20; }
.hk-rot { background: #c62828; }
.hk-grau { background: #9e9e9e; }
</style>

<div class="hk-wrap">
<h1 style="font-size:1.4em;margin:0 0 2px;">Heimkino</h1>
<p class="hk-small">LG-Beamer und Xbox vom Miniserver aus schalten. Version 1.0.0</p>

<?php if ($hk_saved) { ?>
  <div class="hk-alert hk-ok">Die Einstellungen wurden gespeichert.</div>
<?php } ?>
<?php if ($hk_hinweis !== '') { ?>
  <div class="hk-alert hk-info"><?php echo $hk_hinweis; ?></div>
<?php } ?>
<?php if ($hk_fehler) { ?>
  <div class="hk-alert hk-err"><?php
    echo count($hk_fehler) === 1
        ? $hk_fehler[0]
        : '<ul style="margin:0 0 0 18px;padding:0;"><li>'
          . implode('</li><li>', $hk_fehler) . '</li></ul>';
  ?></div>
<?php } ?>

<div class="hk-alert hk-info">
  <span class="hk-scheibe <?php echo $hk_pid ? 'hk-gruen' : 'hk-rot'; ?>"></span>
  <?php echo $hk_pid ? 'Der Dienst laeuft (PID ' . (int) $hk_pid . ').'
                     : 'Der Dienst laeuft nicht.'; ?>
  <?php if ($hk_alter !== null) {
      echo ' Letzte Abfrage vor ' . (int) $hk_alter . ' Sekunden.';
  } ?>
  <?php echo $hk_broker
      ? ' MQTT-Broker: ' . hk_e($hk_broker['host']) . ':' . (int) $hk_broker['port'] . '.'
      : ' <b>Kein MQTT-Broker in general.json</b> - ist das MQTT-Gateway eingerichtet?'; ?>
</div>

<div class="hk-tabs">
  <div class="hk-tab" data-ziel="tab-settings">Einstellungen</div>
  <div class="hk-tab" data-ziel="tab-loxone">Einbindung in Loxone</div>
  <div class="hk-tab" data-ziel="tab-test">Test</div>
  <div class="hk-tab" data-ziel="tab-log">Logdateien</div>
</div>

<!-- ============================ Einstellungen ============================ -->
<div class="hk-pane" id="tab-settings">
<form method="post" action="index.php">
<input type="hidden" name="activetab" value="tab-settings">

<h2>Allgemein</h2>
<label class="hk-check"><input type="checkbox" name="enabled" value="1"
  <?php echo hk_an($hk_cfg, 'heimkino', 'enabled') ? 'checked' : ''; ?>>
  Plugin eingeschaltet</label>
<label class="hk-check"><input type="checkbox" name="mqtt" value="1"
  <?php echo hk_an($hk_cfg, 'heimkino', 'mqtt') ? 'checked' : ''; ?>>
  Zustand per MQTT melden</label>

<div class="hk-row">
  <div>
    <label for="intervall">Abfragetakt in Sekunden</label>
    <input type="number" id="intervall" name="intervall" min="10" max="3600"
      value="<?php echo hk_e(hk_cfg($hk_cfg, 'heimkino', 'intervall', '60')); ?>">
    <div class="hk-small">Der Beamer nimmt nur eine Verbindung zur Zeit an.
      Ein zu kurzer Takt sperrt die Fernbedienung der App aus. 60 Sekunden
      sind eine gute Vorgabe.</div>
  </div>
  <div>
    <label for="themenpraefix">MQTT-Themenpraefix</label>
    <input type="text" id="themenpraefix" name="themenpraefix"
      value="<?php echo hk_e($hk_praefix); ?>">
    <div class="hk-small">Alle Themen liegen darunter, alle sind retained.</div>
  </div>
</div>

<h2>Beamer (LG, ab Baujahr 2018)</h2>

<div class="hk-alert hk-info">
<b>Einstellungen am Ger&auml;t &mdash; ohne diese vier bleibt das Plugin wirkungslos.</b>
<ol style="margin:6px 0 0 18px;padding:0;">
<li><b>Verstecktes Men&uuml;: Netzwerk-IP-Steuerung.</b> Mit der Fernbedienung
<i>Alle Einstellungen &rarr; Allgemein &rarr; Netzwerk</i> ansteuern, sodass
<i>Netzwerk</i> markiert ist &mdash; und <b>nicht</b> &ouml;ffnen, nichts
anklicken. Dann z&uuml;gig die Ziffern <b>8&nbsp;2&nbsp;8&nbsp;8&nbsp;8</b>
tippen. Es klappt ein zus&auml;tzliches Men&uuml; auf, das im normalen Betrieb
nicht sichtbar ist.
<div class="hk-small">Klappt es nicht: langsam getippt, oder es war schon eine
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
<div class="hk-small" style="margin-top:8px;"><b>Zwei Automatiken, die St&ouml;rungen
vort&auml;uschen</b> (<i>Allgemein &rarr; System &rarr; Zus&auml;tzliche
Einstellungen</i>): die <i>Ausschaltautomatik</i> (schaltet nach vier Stunden
ohne Bedienung ab) und der <i>Bildschirmschoner</i>. Wer sich fragt, warum der
Beamer &bdquo;von selbst&ldquo; ausgeht, findet die Antwort meist hier und nicht
in Loxone.</div>
</div>

<label class="hk-check"><input type="checkbox" name="beamer_aktiv" value="1"
  <?php echo hk_an($hk_cfg, 'beamer', 'aktiv') ? 'checked' : ''; ?>>
  Beamer verwenden</label>
<div class="hk-row">
  <div>
    <label for="beamer_ip">IP-Adresse</label>
    <input type="text" id="beamer_ip" name="beamer_ip" placeholder="192.168.x.y"
      value="<?php echo hk_e(hk_cfg($hk_cfg, 'beamer', 'ip', '')); ?>">
  </div>
  <div>
    <label for="beamer_mac">MAC-Adresse</label>
    <input type="text" id="beamer_mac" name="beamer_mac" placeholder="AA:BB:CC:DD:EE:FF"
      value="<?php echo hk_e(hk_cfg($hk_cfg, 'beamer', 'mac', '')); ?>">
    <div class="hk-small">Nur fuer den Testknopf. Das Einschalten macht Loxone
      selbst mit <span class="hk-mono">wol://</span>.</div>
  </div>
</div>
<div class="hk-row">
  <div>
    <label for="beamer_keycode">Keycode (8 Zeichen)</label>
    <input type="text" id="beamer_keycode" name="beamer_keycode" maxlength="8"
      placeholder="ABCD1234" style="text-transform:uppercase"
      value="<?php echo hk_e(hk_cfg($hk_cfg, 'beamer', 'keycode', '')); ?>">
    <div class="hk-small">Am Geraet erzeugen: Alle Einstellungen &rarr;
      Allgemein &rarr; Netzwerk, dann <b>82888</b> auf der Fernbedienung
      tippen &rarr; Netzwerk-IP-Steuerung einschalten &rarr; Keycode erzeugen.
      Ohne ihn nimmt das Geraet keinen Befehl an.</div>
  </div>
  <div>
    <label for="beamer_port">Port</label>
    <input type="number" id="beamer_port" name="beamer_port" min="1" max="65535"
      value="<?php echo hk_e(hk_cfg($hk_cfg, 'beamer', 'port', '9761')); ?>">
  </div>
  <div>
    <label for="beamer_zeitgrenze">Zeitgrenze in Sekunden</label>
    <input type="number" id="beamer_zeitgrenze" name="beamer_zeitgrenze" min="1" max="60"
      value="<?php echo hk_e(hk_cfg($hk_cfg, 'beamer', 'zeitgrenze', '5')); ?>">
  </div>
</div>

<h2>Xbox</h2>

<div class="hk-alert hk-info">
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
<div class="hk-small" style="margin-top:8px;">Die Konsole muss im Ruhezustand am
Netzwerk h&auml;ngen. Feste Adresse in der Fritz!Box vergeben; im Ruhezustand
verl&auml;ngert WLAN die Weckzeit merklich.</div>
</div>

<label class="hk-check"><input type="checkbox" name="xbox_aktiv" value="1"
  <?php echo hk_an($hk_cfg, 'xbox', 'aktiv') ? 'checked' : ''; ?>>
  Xbox verwenden</label>
<label for="xbox_geraete_id">XBOX-Netzwerk-Ger&auml;teidentit&auml;t</label>
<input type="text" id="xbox_geraete_id" name="xbox_geraete_id"
  value="<?php echo hk_e(hk_cfg($hk_cfg, 'xbox', 'geraete_id', '')); ?>">
<div class="hk-alert hk-info" style="margin-top:6px;">
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
<div class="hk-small" style="margin-top:6px;">Hier geh&ouml;rt die
<b>Ger&auml;teidentit&auml;t</b> hin, nicht der <b>Name</b> der Konsole.
„XBOX-Heimkino" ist ein Name und wird nicht angenommen.</div>
</div>

<label for="xbox_geheimnis_ablauf">Clientgeheimnis g&uuml;ltig bis
  <span class="hk-small">(JJJJ-MM-TT, leer = keine Warnung)</span></label>
<input type="date" id="xbox_geheimnis_ablauf" name="xbox_geheimnis_ablauf"
  value="<?php echo hk_e($hk_ablauf); ?>">
<div class="hk-alert <?php
    echo in_array($hk_ablauf_art, array('abgelaufen', 'bald'), true)
         ? 'hk-err' : 'hk-info'; ?>" style="margin-top:6px;">
<b>Der geheime Clientschl&uuml;ssel h&auml;lt h&ouml;chstens 24 Monate.</b> Azure
vergibt keine l&auml;ngere Frist &mdash; auch nicht mit eigenem Datum. L&auml;uft
er ab, antwortet Microsoft mit
<span class="hk-mono">invalid_client</span> und die Konsole l&auml;sst sich nicht
mehr aus Loxone wecken. Das passiert zwei Jahre nach der Einrichtung, wenn
niemand mehr daran denkt.
<div style="margin-top:6px;"><?php echo $hk_ablauf_text; ?></div>
<div class="hk-small" style="margin-top:6px;">Der Dienst meldet das Datum und die
Restlaufzeit per MQTT
(<span class="hk-mono"><?php echo hk_e($hk_praefix); ?>/xbox/geheimnis_ablauf</span>
und <span class="hk-mono">&hellip;/xbox/geheimnis_tage</span>) &mdash; damit kann
Loxone rechtzeitig eine Nachricht schicken, statt dass es beim n&auml;chsten
Filmabend auffällt. Ab 60 Tagen Restlaufzeit steht zus&auml;tzlich eine Warnung
im Protokoll.</div>
<div class="hk-small" style="margin-top:6px;"><b>Erneuern:</b> in Azure unter
<i>Zertifikate &amp; Geheimnisse</i> ein neues Geheimnis anlegen, die Spalte
<i>Wert</i> unten eintragen, die Anmeldung wiederholen &mdash; und das alte
Geheimnis l&ouml;schen. Die Anwendungskennung bleibt dieselbe, die
Ger&auml;teidentit&auml;t auch.</div>
</div>

<h2>Aktionstoken</h2>
<p class="hk-small">Der Miniserver ruft die Aktionen ueber eine Adresse im
unangemeldeten Bereich auf. Damit das nicht jedes Ger&auml;t im Netz kann, geh&ouml;rt
in jede Adresse dieses Token. Es wird beim ersten Speichern erzeugt.</p>
<div class="hk-mono"><?php echo $hk_token !== '' ? hk_e($hk_token)
    : 'wird beim ersten Speichern erzeugt'; ?></div>
<label class="hk-check" style="margin-top:8px;">
  <input type="checkbox" name="token_neu" value="1">
  Neues Token erzeugen <span class="hk-small">(die Adressen im Miniserver
  muessen danach angepasst werden)</span></label>

<button type="submit" name="save" value="1" class="hk-btn">Speichern</button>
</form>

<h2>Xbox: Anmeldung bei Microsoft</h2>
<div class="hk-alert hk-info">
Das unauthentifizierte Weckpaket auf UDP&nbsp;5050, mit dem sich Xbox-One-Konsolen
wecken liessen, wird von neueren Firmwarestaenden ignoriert. Nachgemessen an
einer Series&nbsp;X: Paketinhalt, Zieladresse und Geraetekennung waren richtig,
die Konsole reagierte nicht &mdash; die Xbox-App weckt dieselbe Konsole ohne
Weiteres. Sie geht ueber den Cloud-Dienst von Microsoft, und diesen Weg geht
dieses Plugin auch. Der Preis: eine eigene App-Registrierung, eine einmalige
Anmeldung und eine Internetverbindung.
</div>

<div class="hk-alert hk-err">
<b>Voraussetzung, an der die meisten scheitern:</b> die Registrierung braucht ein
<b>Verzeichnis</b> (Mandant). Ein persoenliches Microsoft-Konto hat keines. Unter
<i>App-Registrierungen</i> steht dann „diesem Konto zugeordnet, jedoch in keinem
Verzeichnis enthalten", und <i>Neue Registrierung</i> oeffnet kein Formular,
sondern ein Fenster mit einem einzigen Knopf: <i>Abbrechen</i>.
<div style="margin-top:8px;">Ein Verzeichnis bekommt man ueber eine
<b>Azure-Registrierung</b> (kostenloses Konto, verlangt Zahlungsdaten zur
Identitaetspruefung) oder das <b>M365-Entwicklerprogramm</b> (kostenlos, Zugang
eingeschraenkt).</div>
<div style="margin-top:8px;"><b>Und danach unbedingt abmelden und neu
anmelden.</b> Die Portalsitzung traegt die Verzeichniszugehoerigkeit in sich;
eine Sitzung von vor der Registrierung kennt das neue Verzeichnis nicht. Das
Portal meldet dann <span class="hk-mono">AADSTS16000 &hellip; does not exist in
tenant</span> und unter <i>Portaleinstellungen &rarr; Verzeichnisse +
Abonnements</i> steht „Keine Verzeichnisse gefunden".</div>
</div>

<div class="hk-step">
  <b>Schritt 0 &ndash; pruefen, ob das Verzeichnis wirklich da ist.</b> Im
  Azure-Portal oben rechts auf den Kontonamen, dann <i>Verzeichnis wechseln</i>.
  Unter <i>Alle Verzeichnisse</i> muss eines aufgelistet und ausgewaehlt sein.
  Steht dort „Keine Verzeichnisse gefunden", ist die Azure-Registrierung nicht
  abgeschlossen oder auf einem anderen Konto gelaufen &mdash; alles Weitere ist
  dann zwecklos.
</div>

<div class="hk-alert hk-info">
<b>HDMI-CEC weckt die Konsole nicht &mdash; nachgesehen, nicht vermutet.</b>
Unter <i>Allgemein &rarr; TV- &amp; A/V-Energieoptionen &rarr; HDMI-CEC</i>
kennt die Xbox genau diese Richtungen:
<ul style="margin:6px 0 6px 18px;padding:0;">
<li>Konsole schaltet andere Geraete <b>ein</b></li>
<li>Konsole schaltet andere Geraete <b>aus</b></li>
<li>Andere Geraete koennen die Konsole <b>deaktivieren</b></li>
</ul>
Eine Zeile <i>andere Geraete koennen die Konsole einschalten</i> gibt es nicht.
Die Konsole nimmt von aussen nur den Ausschaltbefehl an. CEC kann sie also
<b>ausschalten</b>, aber nicht wecken &mdash; unabhaengig von Kabel und
Verstaerker.
<div class="hk-small">Praktische Folge: <b>xbox-aus</b> aus diesem Plugin ist
oft ueberfluessig, weil der Verstaerker das per CEC schon erledigt. Fuer das
<b>Wecken</b> bleiben der Controller oder der Cloud-Weg unten.</div>
</div>

<div class="hk-step">
  <b>Schritt 1 &ndash; Anwendung registrieren.</b>
  <i>App-Registrierungen &rarr; Neue Registrierung</i>.
  <ul style="margin:6px 0 6px 18px;padding:0;">
  <li><b>Name:</b> frei waehlbar, z. B. <span class="hk-mono">LoxBerry Heimkino</span></li>
  <li><b>Unterst&uuml;tzte Kontotypen:</b> <b>Nur pers&ouml;nliche Microsoft-Konten</b> &mdash; die
      Konsole haengt an einem persoenlichen Konto, nicht am Verzeichnis. Das
      Verzeichnis wird nur gebraucht, um die Anwendung ueberhaupt anlegen zu
      duerfen.</li>
  <li><b>Umleitungs-URI:</b> Typ <b>Web</b>, Adresse genau
      <span class="hk-mono"><?php echo hk_e($hk_xb['rueckleitung']); ?></span></li>
  </ul>
  Dann <i>Registrieren</i>.
</div>

<div class="hk-step">
  <b>Schritt 2 &ndash; Kennung abschreiben.</b> Auf der Seite <i>&Uuml;bersicht</i>
  der Anwendung steht <b>Anwendungs-ID (Client)</b>. Diese Nummer unten einsetzen.
  Sie ist kein Geheimnis.
  <div class="hk-small">Nicht zu verwechseln mit <i>Verzeichnis-ID (Mandant)</i> oder
  <i>Objekt-ID</i>, die auf derselben Seite direkt darunter stehen.</div>
</div>

<div class="hk-step">
  <b>Schritt 3 &ndash; Geheimnis erzeugen.</b> Links <i>Zertifikate &amp;
  Geheimnisse</i>, Reiter <i>Geheime Clientschluessel</i>,
  <i>Neuer geheimer Clientschluessel</i>. Beschreibung frei, <i>G&uuml;ltig bis</i> nach Wunsch &mdash; nach Ablauf
  ist ein neues Geheimnis und eine neue Anmeldung f&auml;llig.
  <div class="hk-alert hk-err" style="margin-top:8px;">
  Die Tabelle zeigt danach vier Spalten: <i>Beschreibung</i>, <i>G&uuml;ltig bis</i>,
  <b>Wert</b> und <i>Geheime ID</i>.
  <b>Gebraucht wird die Spalte „Wert".</b> Die Spalte „Geheime ID" ist es
  <b>nicht</b> &mdash; sie sieht aus wie eine Kennung und wird deshalb gern
  verwechselt.
  <div class="hk-small" style="margin-top:6px;">Die Spalte „Wert" ist <b>nur
  dieses eine Mal</b> sichtbar. Wer die Seite verlaesst, sieht sie nie wieder und
  muss ein neues Geheimnis anlegen &mdash; das alte kann man dann loeschen.</div>
  </div>
</div>


<form method="post" action="index.php">
<input type="hidden" name="activetab" value="tab-settings">
<div class="hk-row">
  <div>
    <label for="client_id">Anwendungskennung (Client-ID)</label>
    <input type="text" id="client_id" name="client_id"
      value="<?php echo hk_e($hk_xb['client_id']); ?>">
  </div>
  <div>
    <label for="client_secret">Geheimer Clientschl&uuml;ssel &mdash; Spalte <i>Wert</i></label>
    <input type="password" id="client_secret" name="client_secret"
      placeholder="<?php echo $hk_xb['geheim'] ? 'gespeichert - leer lassen, um es zu behalten' : ''; ?>">
  </div>
</div>
<label for="rueckleitung">Umleitungs-URI</label>
<input type="text" id="rueckleitung" name="rueckleitung"
  value="<?php echo hk_e($hk_xb['rueckleitung']); ?>">
<button type="submit" name="xbox_app" value="1" class="hk-btn">Kennung speichern</button>
</form>

<?php if ($hk_anmelde !== '') { ?>
<div class="hk-step">
  <b>Schritt 2 &ndash; anmelden.</b> Diese Adresse in einem Browser oeffnen und
  mit dem Microsoft-Konto anmelden, an dem die Konsole haengt.
  <p><a href="<?php echo hk_e($hk_anmelde); ?>" target="_blank" rel="noopener"
    class="hk-btn" style="display:inline-block;text-decoration:none;">Anmeldeseite
    oeffnen</a></p>
  <div class="hk-small">Der Browser landet danach auf einer Seite, die nicht
  laedt &mdash; das ist richtig so. Entscheidend ist die Adresszeile: sie
  enthaelt <span class="hk-mono">?code=&hellip;</span>. Die ganze Adresse
  kopieren und unten einsetzen.</div>
</div>

<form method="post" action="index.php">
<input type="hidden" name="activetab" value="tab-settings">
<label for="code">Zurueckgeleitete Adresse oder Code</label>
<input type="text" id="code" name="code"
  placeholder="http://localhost/auth/callback?code=...">
<button type="submit" name="xbox_code" value="1" class="hk-btn">Anmeldung
  abschliessen</button>
</form>
<div class="hk-alert hk-info">
Meldet Microsoft hier <span class="hk-mono">invalid_client &mdash; The provided
value for the 'client_secret' parameter is not valid</span>, dann liegt es an
einem von zwei Dingen:
<ol style="margin:6px 0 0 18px;padding:0;">
<li><b>Es wurde die falsche Spalte kopiert.</b> Weitaus haeufigster Fall.
<i>Geheime ID</i> statt <i>Wert</i>. Der Reiter <b>Test &rarr; Anmeldedaten
pruefen</b> sagt, welche Form das gespeicherte Geheimnis hat &mdash; ohne es
anzuzeigen.</li>
<li><b>Das Geheimnis ist abgelaufen</b> &mdash; Spalte <i>G&uuml;ltig bis</i>.
Dann ein neues anlegen.</li>
</ol>
<div class="hk-small" style="margin-top:6px;">Bleibt es dabei, obwohl die Spalte
<i>Wert</i> frisch kopiert wurde, hilft der andere Anmeldedienst: in
<span class="hk-mono">xbox_auth.json</span> den Eintrag
<span class="hk-mono">"dienst": "v2"</span> setzen. Dann laeuft die Anmeldung
ueber <span class="hk-mono">login.microsoftonline.com</span> statt
<span class="hk-mono">login.live.com</span> &mdash; manche Registrierungen nimmt
nur der eine oder nur der andere an.</div>
</div>
<form method="post" action="index.php" style="display:none">
</form>
<?php } ?>

<p style="margin-top:14px;">
  <span class="hk-scheibe <?php echo $hk_xb['angemeldet'] ? 'hk-gruen' : 'hk-grau'; ?>"></span>
  <?php echo $hk_xb['angemeldet']
      ? 'Angemeldet. Das Erneuerungstoken liegt vor, eine neue Anmeldung ist '
        . 'erst noetig, wenn Microsoft es verwirft.'
      : 'Noch nicht angemeldet.'; ?>
</p>
<?php if ($hk_xb['angemeldet']) { ?>
<div class="hk-knopfreihe hk-g3">
  <form method="post" action="index.php">
    <input type="hidden" name="activetab" value="tab-settings">
    <button type="submit" name="xbox_vergessen" value="1">Anmeldung loeschen</button>
  </form>
</div>
<?php } ?>
</div>

<!-- ========================= Einbindung in Loxone ========================= -->
<div class="hk-pane" id="tab-loxone">

<h2>Zustand lesen &ndash; ueber MQTT</h2>
<p class="hk-small">Der Dienst meldet jeden Wert <b>retained</b> an den Broker.
Das MQTT-Gateway von LoxBerry leitet sie an den Miniserver weiter. Alle Themen
liegen unter <span class="hk-mono"><?php echo hk_e($hk_praefix); ?>/</span>.</p>

<table class="hk-tbl">
<tr><th>Thema</th><th>Bedeutung</th></tr>
<?php foreach (hk_themen() as $thema => $bedeutung) { ?>
<tr><td class="hk-mono"><?php echo hk_e($hk_praefix . '/' . $thema); ?></td>
    <td><?php echo hk_e($bedeutung); ?></td></tr>
<?php } ?>
</table>

<div class="hk-knopfreihe hk-g1">
  <form method="post" action="index.php">
    <input type="hidden" name="activetab" value="tab-loxone">
    <button type="submit" name="download" value="mqtt_in">Vorlage der
      Eingaenge herunterladen</button>
  </form>
</div>
<p class="hk-small">Die Datei laesst sich in Loxone Config unter
<i>Virtuelle Eingaenge</i> einlesen. Sie legt die Eingaenge mit den richtigen
Namen an; die Werte kommen dann vom MQTT-Gateway.</p>

<h2>Schalten &ndash; ueber virtuelle Ausgaenge</h2>
<?php if ($hk_token === '') { ?>
<div class="hk-alert hk-err">Es gibt noch kein Aktionstoken. Einmal im Reiter
<i>Einstellungen</i> speichern, dann erscheinen hier die vollstaendigen
Adressen.</div>
<?php } else { ?>
<p class="hk-small">In Loxone Config einen <b>virtuellen Ausgang</b> anlegen.
Bei ihm steht nur die Adresse des LoxBerry, die Befehle kommen in die
<b>virtuellen Ausgangsbefehle</b> darunter.</p>

<table class="hk-tbl">
<tr><th style="width:22%">Feld</th><th>Wert</th></tr>
<tr><td>Adresse des Ausgangs</td>
    <td class="hk-mono">http://<?php echo hk_e(gethostname() ?: 'loxberry'); ?></td></tr>
</table>
<p class="hk-small">Statt des Rechnernamens geht auch die IP des LoxBerry. Kein
Benutzer und kein Passwort &mdash; der Aktionsendpunkt liegt im unangemeldeten
Bereich und prueft stattdessen das Token.</p>

<table class="hk-tbl">
<tr><th style="width:22%">Ausgangsbefehl</th><th>Befehl bei EIN (Methode GET)</th></tr>
<?php foreach (hk_aktionen() as $aktion => $bezeichnung) { ?>
<tr><td><?php echo hk_e($bezeichnung); ?></td>
    <td class="hk-mono"><?php echo hk_e(hk_aktionsadresse($hk_cfg, $aktion)); ?></td></tr>
<?php } ?>
</table>
<div class="hk-alert hk-info">
Ein virtueller Ausgangsbefehl feuert bei der Flanke 0&rarr;1, nicht dauerhaft.
Fuer das <b>Einschalten des Beamers</b> braucht es dieses Plugin nicht: Loxone
kann Wake-on-LAN selbst. Ein virtueller Ausgang mit der Adresse
<span class="hk-mono">wol://</span> und der MAC ohne Trennzeichen als Befehl
genuegt und ist der kuerzere Weg.
</div>
<?php } ?>
</div>

<!-- ================================ Test ================================ -->
<div class="hk-pane" id="tab-test">

<?php if ($hk_test_titel !== '') { ?>
<div class="hk-alert hk-ok"><b><?php echo hk_e($hk_test_titel); ?></b></div>
<?php echo $hk_test_text; ?>
<?php } ?>

<h2>Nachsehen</h2>
<div class="hk-knopfreihe hk-g1">
<?php
$ansehen = array(
    'umgebung'          => 'Umgebung pruefen',
    'anmeldedaten'      => 'Anmeldedaten pruefen',
    'krypto'            => 'Verschluesselung pruefen',
    'beamer_erreichbar' => 'Beamer erreichbar?',
    'beamer_status'     => 'Beamer: Zustand',
    'beamer_ipcontrol'  => 'Beamer: IP-Steuerung',
    'xbox_status'       => 'Xbox: Zustand',
    'xbox_konsolen'     => 'Konsolen suchen',
);
foreach ($ansehen as $wert => $text) { ?>
  <form method="post" action="index.php">
    <input type="hidden" name="activetab" value="tab-test">
    <button type="submit" name="test" value="<?php echo hk_e($wert); ?>"><?php
      echo hk_e($text); ?></button>
  </form>
<?php } ?>
</div>

<h2>Technik</h2>
<div class="hk-knopfreihe hk-g2">
  <form method="post" action="index.php">
    <input type="hidden" name="activetab" value="tab-test">
    <button type="submit" name="test" value="dienst_neu">Dienst neu starten</button>
  </form>
</div>

<h2>Schalten</h2>
<p class="hk-small">Diese Knoepfe wirken sofort auf die Geraete.</p>
<div class="hk-knopfreihe hk-g3">
<?php
$aktionen = array(
    'beamer_aus' => 'Beamer ausschalten',
    'beamer_wol' => 'Beamer per WoL einschalten',
    'xbox_an'    => 'Xbox wecken',
    'xbox_aus'   => 'Xbox ausschalten',
);
foreach ($aktionen as $wert => $text) { ?>
  <form method="post" action="index.php">
    <input type="hidden" name="activetab" value="tab-test">
    <button type="submit" name="test" value="<?php echo hk_e($wert); ?>"><?php
      echo hk_e($text); ?></button>
  </form>
<?php } ?>
</div>

<h2>Frist des Clientgeheimnisses</h2>
<p><span class="hk-scheibe <?php
   echo $hk_ablauf_art === 'ok' ? 'hk-gruen'
      : ($hk_ablauf_art === 'leer' ? 'hk-grau' : 'hk-rot'); ?>"></span>
<?php echo $hk_ablauf_text; ?></p>

<h2>Letzter Stand des Dienstes</h2>
<?php if ($hk_z === null) { ?>
<div class="hk-alert hk-info">Der Dienst hat noch keinen Zustand abgelegt.</div>
<?php } else { ?>
<table class="hk-tbl">
<tr><th style="width:30%">Groesse</th><th>Wert</th></tr>
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
<tr><td>Letzter Fehler</td><td class="hk-err-text"><?php echo hk_e($f); ?></td></tr>
<?php } ?>
</table>
<?php } ?>
</div>

<!-- ============================== Logdateien ============================== -->
<div class="hk-pane" id="tab-log">
<h2>Protokoll</h2>
<p class="hk-small">Neueste Zeile oben. Datei:
<span class="hk-mono"><?php echo hk_e($hk_p['log']); ?></span></p>
<?php if (!$hk_zeilen) { ?>
<div class="hk-alert hk-info">Die Protokolldatei ist leer oder nicht lesbar.</div>
<?php } else { ?>
<div class="hk-log"><?php
  foreach ($hk_zeilen as $zeile) { echo hk_e($zeile) . "\n"; }
?></div>
<?php } ?>
<div class="hk-knopfreihe hk-g1" style="margin-top:12px;">
  <form method="post" action="index.php">
    <input type="hidden" name="activetab" value="tab-log">
    <button type="submit" name="nichts" value="1">Neu laden</button>
  </form>
</div>
</div>

</div>

<script>
(function () {
  var start = <?php echo json_encode($hk_tab); ?>;
  var reiter = document.querySelectorAll('.hk-tab');
  var seiten = document.querySelectorAll('.hk-pane');
  function zeige(ziel) {
    for (var i = 0; i < reiter.length; i++) {
      reiter[i].classList.toggle('hk-active', reiter[i].getAttribute('data-ziel') === ziel);
    }
    for (var j = 0; j < seiten.length; j++) {
      seiten[j].classList.toggle('hk-active', seiten[j].id === ziel);
    }
    var felder = document.querySelectorAll('input[name="activetab"]');
    for (var k = 0; k < felder.length; k++) { felder[k].value = ziel; }
  }
  for (var i = 0; i < reiter.length; i++) {
    reiter[i].addEventListener('click', function () {
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
