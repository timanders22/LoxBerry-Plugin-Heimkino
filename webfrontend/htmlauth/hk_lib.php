<?php
/**
 * Heimkino - gemeinsame Funktionen der Oberflaeche
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

/** Wurzel der LoxBerry-Installation und alle abgeleiteten Pfade. */
function hk_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) { $home = $k; break; }
        }
    }
    $ordner = 'heimkino';
    $p = array(
        'home'    => $home ? $home : '/opt/loxberry',
        'plugin'  => $ordner,
        'config'  => ($home ? $home : '/opt/loxberry') . '/config/plugins/' . $ordner . '/heimkino.cfg',
        'auth'    => ($home ? $home : '/opt/loxberry') . '/config/plugins/' . $ordner . '/xbox_auth.json',
        'zustand' => ($home ? $home : '/opt/loxberry') . '/data/plugins/' . $ordner . '/zustand.json',
        'log'     => ($home ? $home : '/opt/loxberry') . '/log/plugins/' . $ordner . '/heimkino.log',
        'bin'     => ($home ? $home : '/opt/loxberry') . '/bin/plugins/' . $ordner,
        'general' => ($home ? $home : '/opt/loxberry') . '/config/system/general.json',
    );
    return $p;
}

function hk_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

function hk_vorgaben()
{
    return array(
        'heimkino' => array('enabled' => '1', 'intervall' => '60',
                            'themenpraefix' => 'heimkino', 'mqtt' => '1',
                            'aktionstoken' => ''),
        'beamer'   => array('aktiv' => '0', 'ip' => '', 'mac' => '',
                            'keycode' => '', 'port' => '9761', 'zeitgrenze' => '5'),
        'xbox'     => array('aktiv' => '0', 'geraete_id' => '',
                            'geheimnis_ablauf' => ''),
    );
}

function hk_config_read()
{
    $cfg = hk_vorgaben();
    $datei = hk_paths()['config'];
    if (is_readable($datei)) {
        $gelesen = @parse_ini_file($datei, true, INI_SCANNER_RAW);
        if (is_array($gelesen)) {
            foreach ($gelesen as $abschnitt => $werte) {
                if (!is_array($werte) || !isset($cfg[$abschnitt])) {
                    continue;
                }
                foreach ($werte as $schluessel => $wert) {
                    if (array_key_exists($schluessel, $cfg[$abschnitt])) {
                        $cfg[$abschnitt][$schluessel] = trim((string) $wert);
                    }
                }
            }
        }
    }
    return $cfg;
}

function hk_config_write($cfg)
{
    $datei = hk_paths()['config'];
    $ordner = dirname($datei);
    if (!is_dir($ordner)) {
        @mkdir($ordner, 0755, true);
    }
    $t  = "; Heimkino\n";
    $t .= "; Wird von der Plugin-Oberflaeche geschrieben.\n";
    $t .= "; ACHTUNG: enthaelt den Keycode des Beamers - nicht veroeffentlichen.\n\n";
    foreach ($cfg as $abschnitt => $werte) {
        $t .= '[' . $abschnitt . "]\n";
        foreach ($werte as $schluessel => $wert) {
            $t .= $schluessel . '=' . $wert . "\n";
        }
        $t .= "\n";
    }
    $vorlaeufig = $datei . '.neu';
    if (@file_put_contents($vorlaeufig, $t) === false) {
        return false;
    }
    @chmod($vorlaeufig, 0640);
    return @rename($vorlaeufig, $datei);
}

function hk_cfg($cfg, $abschnitt, $schluessel, $vorgabe = '')
{
    return isset($cfg[$abschnitt][$schluessel]) && $cfg[$abschnitt][$schluessel] !== ''
        ? $cfg[$abschnitt][$schluessel] : $vorgabe;
}

function hk_an($cfg, $abschnitt, $schluessel)
{
    return in_array(hk_cfg($cfg, $abschnitt, $schluessel, '0'),
                    array('1', 'true', 'yes', 'on'), true);
}

/** Zufallstoken fuer den Aktionsendpunkt. */
function hk_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

/* ==================================================================
 * Dienst, Zustand, Protokoll
 * ================================================================== */

function hk_dienst_pid()
{
    $aus = array();
    @exec('pgrep -f "hk_service.py" 2>/dev/null', $aus);
    foreach ($aus as $zeile) {
        $zeile = trim($zeile);
        if ($zeile !== '' && preg_match('/^[0-9]+$/', $zeile)) {
            return (int) $zeile;
        }
    }
    return 0;
}

function hk_dienst($was)
{
    $bin = hk_paths()['bin'] . '/hk_service.py';
    if ($was === 'stop' || $was === 'restart') {
        @exec('pkill -f "hk_service.py" >/dev/null 2>&1');
        usleep(400000);
    }
    if ($was === 'start' || $was === 'restart') {
        if (is_executable($bin)) {
            $log = hk_paths()['log'];
            @exec('nohup ' . escapeshellarg($bin) . ' >> '
                  . escapeshellarg($log) . ' 2>&1 &');
            usleep(700000);
        }
    }
    return hk_dienst_pid();
}

function hk_zustand()
{
    $datei = hk_paths()['zustand'];
    if (!is_readable($datei)) {
        return null;
    }
    $inhalt = json_decode((string) @file_get_contents($datei), true);
    return is_array($inhalt) ? $inhalt : null;
}

function hk_zustand_alter()
{
    $datei = hk_paths()['zustand'];
    if (!is_readable($datei)) {
        return null;
    }
    $zeit = @filemtime($datei);
    return $zeit ? (time() - $zeit) : null;
}

function hk_log_tail($max = 200)
{
    $datei = hk_paths()['log'];
    if (!is_readable($datei)) {
        return array();
    }
    $zeilen = preg_split('/\R/', (string) @file_get_contents($datei));
    $zeilen = array_values(array_filter($zeilen, function ($z) {
        return trim($z) !== '';
    }));
    return array_reverse(array_slice($zeilen, -$max));
}

function hk_mqtt_broker()
{
    $datei = hk_paths()['general'];
    if (!is_readable($datei)) {
        return null;
    }
    $alles = json_decode((string) @file_get_contents($datei), true);
    if (!is_array($alles)) {
        return null;
    }
    $mqtt = isset($alles['Mqtt']) ? $alles['Mqtt']
          : (isset($alles['mqtt']) ? $alles['mqtt'] : null);
    if (!is_array($mqtt)) {
        return null;
    }
    $host = isset($mqtt['Brokerhost']) ? $mqtt['Brokerhost']
          : (isset($mqtt['brokerhost']) ? $mqtt['brokerhost'] : '');
    if (trim((string) $host) === '') {
        return null;
    }
    $port = isset($mqtt['Brokerport']) ? $mqtt['Brokerport']
          : (isset($mqtt['brokerport']) ? $mqtt['brokerport'] : 1883);
    return array('host' => trim((string) $host), 'port' => (int) $port);
}

/** Einen Befehl von bin/hk_cmd.py ausfuehren. */
function hk_cmd($argumente)
{
    $bin = hk_paths()['bin'] . '/hk_cmd.py';
    if (!is_readable($bin)) {
        return array(1, 'hk_cmd.py nicht gefunden: ' . $bin);
    }
    $teile = array('python3', $bin);
    foreach ((array) $argumente as $a) {
        $teile[] = $a;
    }
    $befehl = '';
    foreach ($teile as $t) {
        $befehl .= escapeshellarg($t) . ' ';
    }
    $aus = array();
    $code = 0;
    @exec($befehl . '2>&1', $aus, $code);
    return array($code, implode("\n", $aus));
}

/* ==================================================================
 * MQTT-Themen
 * ================================================================== */

function hk_themen()
{
    return array(
        'service/online'    => '1 = der Dienst l&auml;uft',
        'last_error'        => 'letzte Fehlermeldung, sonst leer',
        'beamer/aktiv'      => '1 = der Beamer ist in den Einstellungen eingeschaltet',
        'beamer/erreichbar' => '1 = der Beamer antwortet auf Port 9761',
        'beamer/status'     => 'an, aus oder unbekannt',
        'beamer/an'         => '1 = der Beamer l&auml;uft',
        'beamer/app'        => 'laufende Quelle, z. B. HDMI1',
        'xbox/aktiv'        => '1 = die Xbox ist in den Einstellungen eingeschaltet',
        'xbox/status'       => 'Zustandstext der Cloud, z. B. On oder ConnectedStandby',
        'xbox/an'           => '1 = die Konsole l&auml;uft',
        'xbox/angemeldet'   => '1 = die Anmeldung bei Microsoft ist g&uuml;ltig',
        'xbox/geheimnis_ablauf' => 'Ablaufdatum des Clientgeheimnisses, JJJJ-MM-TT',
        'xbox/geheimnis_tage'   => 'Tage bis zum Ablauf; negativ = abgelaufen, leer = kein Datum hinterlegt',
    );
}

function hk_aktionen()
{
    return array(
        'beamer-aus'  => 'Beamer ausschalten',
        'beamer-wol'  => 'Beamer per Wake-on-LAN einschalten',
        'xbox-an'     => 'Xbox wecken',
        'xbox-aus'    => 'Xbox ausschalten',
    );
}

/* ==================================================================
 * Loxone-Vorlage
 *
 * Nachbau von LoxBerry::LoxoneTemplateBuilder; das Modul gibt es nur in
 * Perl. Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor den
 * Kindelementen entsprechen dem Original.
 * ================================================================== */

function hk_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function hk_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . hk_x($kopf['title']) . '" ';
    $o .= 'Comment="' . hk_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . hk_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . hk_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . hk_x($c['title']) . '" ';
        $o .= 'Comment="' . hk_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . hk_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="-2147483647" ';
        $o .= 'MaxVal="2147483647"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/** Vorlage der Statuseingaenge. Rueckgabe: array(dateiname, inhalt) */
function hk_vorlage($cfg)
{
    $praefix = hk_cfg($cfg, 'heimkino', 'themenpraefix', 'heimkino');
    $cmds = array();
    foreach (hk_themen() as $thema => $bedeutung) {
        $cmds[] = array(
            'title'   => $praefix . '_' . str_replace('/', '_', $thema),
            'comment' => $bedeutung,
            'check'   => ' ',
        );
    }
    return array('heimkino_eingaenge.xml', hk_xml_virtual_in_http(array(
        'title'   => 'Heimkino',
        'address' => 'http://localhost',
        'polling' => '604800',
        'comment' => 'Erzeugt vom LoxBerry-Plugin Heimkino (' . date('d.m.Y') . ')',
    ), $cmds));
}

/* ==================================================================
 * Xbox - Anmeldedatei
 *
 * Anwendungskennung und Token liegen getrennt von der Konfiguration in
 * xbox_auth.json mit Rechten 0600. Bewusst nicht ueber die Kommandozeile
 * uebergeben: Argumente sind in der Prozessliste sichtbar.
 * ================================================================== */

define('HK_RUECKLEITUNG', 'http://localhost/auth/callback');
define('HK_BEREICH', 'Xboxlive.signin Xboxlive.offline_access');

function hk_xbox_auth_lesen()
{
    $datei = hk_paths()['auth'];
    if (!is_readable($datei)) {
        return array();
    }
    $inhalt = json_decode((string) @file_get_contents($datei), true);
    return is_array($inhalt) ? $inhalt : array();
}

function hk_xbox_auth_schreiben($daten)
{
    $datei = hk_paths()['auth'];
    $ordner = dirname($datei);
    if (!is_dir($ordner)) {
        @mkdir($ordner, 0755, true);
    }
    $vorlaeufig = $datei . '.neu';
    if (@file_put_contents($vorlaeufig,
            json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        return false;
    }
    @chmod($vorlaeufig, 0600);
    return @rename($vorlaeufig, $datei);
}

/** Anwendungskennung hinterlegen, vorhandene Token behalten. */
function hk_xbox_app_speichern($client_id, $client_secret, $rueckleitung = '')
{
    $daten = hk_xbox_auth_lesen();
    $daten['client_id'] = trim((string) $client_id);
    // Ein leer gelassenes Feld loescht das Geheimnis nicht - sonst waere es
    // nach jedem Speichern der Seite weg.
    if (trim((string) $client_secret) !== '') {
        $daten['client_secret'] = trim((string) $client_secret);
    }
    $daten['redirect_uri'] = trim((string) $rueckleitung) !== ''
        ? trim((string) $rueckleitung) : HK_RUECKLEITUNG;
    return hk_xbox_auth_schreiben($daten);
}

/**
 * Anmeldeadresse. Muss denselben Dienst benutzen wie bin/xbox_cloud.py, sonst
 * passt der zurueckgegebene Code nicht zum Token-Endpunkt.
 */
function hk_xbox_anmeldeadresse()
{
    $daten = hk_xbox_auth_lesen();
    if (empty($daten['client_id'])) {
        return '';
    }
    $v2 = isset($daten['dienst']) && strtolower(trim($daten['dienst'])) === 'v2';
    $basis = $v2
        ? 'https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize'
        : 'https://login.live.com/oauth20_authorize.srf';
    $bereich = $v2 ? 'XboxLive.signin offline_access' : HK_BEREICH;
    return $basis . '?' . http_build_query(array(
        'client_id'       => $daten['client_id'],
        'response_type'   => 'code',
        'approval_prompt' => 'auto',
        'scope'           => $bereich,
        'redirect_uri'    => isset($daten['redirect_uri'])
                             ? $daten['redirect_uri'] : HK_RUECKLEITUNG,
    ));
}

function hk_xbox_zustand()
{
    $daten = hk_xbox_auth_lesen();
    return array(
        'eingerichtet' => !empty($daten['client_id']),
        'geheim'       => !empty($daten['client_secret']),
        'angemeldet'   => !empty($daten['refresh_token']),
        'client_id'    => isset($daten['client_id']) ? $daten['client_id'] : '',
        'rueckleitung' => isset($daten['redirect_uri'])
                          ? $daten['redirect_uri'] : HK_RUECKLEITUNG,
    );
}

/** Vollst&auml;ndige Aufrufadresse einer Aktion, wie sie in Loxone geh&ouml;rt. */
function hk_aktionsadresse($cfg, $aktion)
{
    $token = hk_cfg($cfg, 'heimkino', 'aktionstoken', '');
    $host = gethostname();
    if (!$host) { $host = 'loxberry'; }
    return '/plugins/' . hk_paths()['plugin'] . '/index.php?token='
         . rawurlencode($token) . '&aktion=' . rawurlencode($aktion);
}

/* ==================================================================
 * Form der Anmeldedaten beurteilen
 *
 * Die h&auml;ufigste Verwechslung: aus der Tabelle unter
 * "Zertifikate & Geheimnisse" wird die Spalte "Geheime ID" kopiert statt der
 * Spalte "Wert". Beides sind lange Zeichenketten, aber die Geheime ID ist eine
 * GUID mit vier Bindestrichen - das l&auml;sst sich erkennen, ohne das Geheimnis
 * anzuzeigen.
 * ================================================================== */

function hk_ist_guid($s)
{
    return (bool) preg_match(
        '/^\{?[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\}?$/',
        trim((string) $s));
}

/**
 * Beurteilt das gespeicherte Geheimnis. Gibt array(art, text) zur&uuml;ck.
 * art: 'ok' | 'guid' | 'kurz' | 'leer'
 */
function hk_geheimnis_form($wert)
{
    $wert = (string) $wert;
    if (trim($wert) === '') {
        return array('leer', 'Es ist kein Geheimnis gespeichert.');
    }
    if (hk_ist_guid($wert)) {
        return array('guid',
            'Das gespeicherte Geheimnis ist eine GUID (8-4-4-4-12 Zeichen mit vier '
            . 'Bindestrichen). So sieht die Spalte <b>Geheime ID</b> aus, nicht die '
            . 'Spalte <b>Wert</b>. Genau diese Verwechslung f&uuml;hrt zu '
            . '<span class="hk-mono">invalid_client</span>.');
    }
    $laenge = strlen($wert);
    if ($laenge < 20) {
        return array('kurz', 'Das gespeicherte Geheimnis ist nur ' . $laenge
            . ' Zeichen lang. Ein Wert aus Azure hat gut 40. Wahrscheinlich ist '
            . 'beim Kopieren etwas abgeschnitten worden.');
    }
    return array('ok', 'L&auml;nge ' . $laenge . ' Zeichen, keine GUID-Form &mdash; '
        . 'das sieht nach der Spalte <b>Wert</b> aus.');
}

/* ==================================================================
 * Restlaufzeit des Azure-Clientgeheimnisses
 *
 * Azure vergibt f&uuml;r einen geheimen Clientschl&uuml;ssel h&ouml;chstens 24 Monate.
 * L&auml;uft er ab, meldet Microsoft invalid_client und die Konsole l&auml;sst sich
 * nicht mehr aus Loxone wecken - zwei Jahre nach der Einrichtung, wenn niemand
 * mehr daran denkt. Deshalb wird das Datum hinterlegt und ausgewertet, statt
 * sich darauf zu verlassen, dass man es im Kopf beh&auml;lt.
 *
 * Gibt array(art, tage, text) zur&uuml;ck.
 * art: 'leer' | 'ok' | 'bald' | 'abgelaufen'
 * ================================================================== */

function hk_ablauf_lage($datum)
{
    $datum = trim((string) $datum);
    if ($datum === '') {
        return array('leer', null,
            'Kein Ablaufdatum hinterlegt &mdash; ohne Datum kann das Plugin nicht warnen.');
    }
    $zeit = strtotime($datum . ' 23:59:59');
    if ($zeit === false) {
        return array('leer', null, 'Das eingetragene Ablaufdatum ist unlesbar.');
    }
    $tage = (int) floor(($zeit - time()) / 86400);
    $hin  = date('d.m.Y', $zeit);
    if ($tage < 0) {
        return array('abgelaufen', $tage,
            'Das Clientgeheimnis ist am <b>' . $hin . '</b> abgelaufen. Bis ein neues '
            . 'eingetragen ist, l&auml;sst sich die Konsole nicht mehr aus Loxone wecken.');
    }
    if ($tage <= 60) {
        return array('bald', $tage,
            'Das Clientgeheimnis l&auml;uft am <b>' . $hin . '</b> ab &mdash; in ' . $tage
            . ' Tagen. Jetzt ein neues anlegen und die Anmeldung wiederholen.');
    }
    return array('ok', $tage,
        'Clientgeheimnis g&uuml;ltig bis <b>' . $hin . '</b> (' . $tage . ' Tage).');
}

/** Vorschlag fuer das Ablaufdatum: heute plus 24 Monate, das Azure-Hoechstmass. */
function hk_ablauf_vorschlag()
{
    return date('Y-m-d', strtotime('+24 months'));
}

/* ==================================================================
 * Version des Plugins
 *
 * Wird NICHT fest eingetragen. Bis 1.0.2 stand die Nummer als Text in
 * index.php - und blieb bei jedem Release stehen: die Oberflaeche zeigte
 * 1.0.0, obwohl 1.0.2 lief. Eine Versionsnummer an zwei Stellen ist eine
 * Stelle zu viel.
 *
 * Massgeblich ist die Plugindatenbank von LoxBerry. Dort steht, was bei der
 * Installation aus plugin.cfg uebernommen wurde - also genau das, was der
 * Benutzer wirklich installiert hat, und nicht das, woran jemand beim
 * Veroeffentlichen gedacht hat.
 *
 * Laesst sich die Version nicht ermitteln, wird eine leere Zeichenkette
 * zurueckgegeben und in der Oberflaeche gar keine Nummer angezeigt. Keine
 * Angabe ist besser als eine falsche.
 * ================================================================== */

function hk_version()
{
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $v = '';
    $ordner = hk_paths()['plugin'];

    // 1. Weg: das PHP-SDK von LoxBerry, sofern geladen.
    if (class_exists('LBSystem') && method_exists('LBSystem', 'plugindata')) {
        $daten = @LBSystem::plugindata($ordner);
        if (is_array($daten) && !empty($daten['PLUGINDB_VERSION'])) {
            $v = trim((string) $daten['PLUGINDB_VERSION']);
        }
    }

    // 2. Weg: die Plugindatenbank unmittelbar lesen. Greift auch dann, wenn
    // das SDK nicht eingebunden ist - etwa beim Aufruf ausserhalb der
    // LoxBerry-Oberflaeche.
    if ($v === '') {
        $db = hk_paths()['home'] . '/data/system/plugindatabase.json';
        if (is_readable($db)) {
            $j = json_decode((string) file_get_contents($db), true);
            $liste = array();
            if (is_array($j)) {
                $liste = (isset($j['plugins']) && is_array($j['plugins'])) ? $j['plugins'] : $j;
            }
            if (is_array($liste)) {
                foreach ($liste as $e) {
                    if (!is_array($e)) { continue; }
                    $f = isset($e['folder']) ? $e['folder']
                       : (isset($e['PLUGINDB_FOLDER']) ? $e['PLUGINDB_FOLDER'] : '');
                    if ($f === $ordner) {
                        $v = isset($e['version']) ? trim((string) $e['version'])
                           : (isset($e['PLUGINDB_VERSION']) ? trim((string) $e['PLUGINDB_VERSION']) : '');
                        break;
                    }
                }
            }
        }
    }
    return $v;
}
