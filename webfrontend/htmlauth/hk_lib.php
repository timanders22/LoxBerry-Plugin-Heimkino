<?php
/**
 * Heimkino - gemeinsame Funktionen der Oberflaeche
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * Grundsatz dieser Datei seit 1.2.12: sie enthaelt KEINEN anzuzeigenden
 * Text mehr. Alles, was auf dem Bildschirm landet, kommt aus
 * templates/lang/language_*.ini oder aus bin/hk_themen.json. Bis 1.2.11
 * standen hier deutsche Saetze - teils sogar mit HTML-Entitaeten darin, die
 * anschliessend noch einmal durch hk_e() liefen und dann woertlich als
 * "l&auml;uft" auf dem Bildschirm standen.
 */

/** Wurzel der LoxBerry-Installation und alle abgeleiteten Pfade. */

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function hk_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) { $home = $k; break; }
        }
    }
    if (!$home) { $home = lb_wurzel_ermitteln(); }
    $ordner = 'heimkino';
    $bin = $home . '/bin/plugins/' . $ordner;
    // Im ausgepackten Archiv liegt bin/ neben webfrontend/ - dann greift der
    // Pfad oben nicht. Ohne diesen Zweig faende die Selbstpruefung die
    // gemeinsamen Datendateien im Pruefstand nie.
    if (!is_dir($bin) && is_dir(dirname(dirname(__DIR__)) . '/bin')) {
        $bin = dirname(dirname(__DIR__)) . '/bin';
    }
    $p = array(
        'home'     => $home,
        'plugin'   => $ordner,
        'config'   => $home . '/config/plugins/' . $ordner . '/heimkino.cfg',
        'auth'     => $home . '/config/plugins/' . $ordner . '/xbox_auth.json',
        // Der Autorisierungscode aus der Microsoft-Rueckleitung. Er wird hier
        // mit Rechten 0600 abgelegt und von hk_cmd.py gelesen und geloescht -
        // NICHT ueber die Kommandozeile uebergeben. Argumente stehen in
        // /proc/<pid>/cmdline und sind fuer jeden lokalen Benutzer lesbar;
        // zusammen mit dem Clientgeheimnis laesst sich aus dem Code ein
        // Erneuerungstoken loesen. Bis 1.2.11 ging er als Argument hinaus.
        'code'     => $home . '/config/plugins/' . $ordner . '/xbox_code.tmp',
        'zustand'  => $home . '/data/plugins/' . $ordner . '/zustand.json',
        'daten'    => $home . '/data/plugins/' . $ordner,
        // Seit 1.2.12 unter data/, nicht mehr unter log/: log/plugins ist
        // eine Ramdisk, und der Dienst haelt auf dieser Datei eine echte
        // Dateisperre (flock).
        'piddatei' => $home . '/data/plugins/' . $ordner . '/hk_service.pid',
        'soll'     => $home . '/data/plugins/' . $ordner . '/soll_laufen',
        'probe'    => $home . '/data/plugins/' . $ordner . '/endpunkt_probe.json',
        'log'      => $home . '/log/plugins/' . $ordner . '/heimkino.log',
        'bin'      => $bin,
        'vorgaben' => $bin . '/hk_vorgaben.json',
        'themen'   => $bin . '/hk_themen.json',
        'general'  => $home . '/config/system/general.json',
    );
    return $p;
}

function hk_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 * ================================================================== */

function hk_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    // Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
    // eingestellt hat, versteht eher Englisch.
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/** Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL". */
function hk_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $ordner = hk_paths()['home'] . '/templates/plugins/'
                . hk_paths()['plugin'] . '/lang';
        // Im ausgepackten Archiv liegen die Sprachdateien noch an ihrem
        // Platz im Paket. Ohne diesen Zweig zeigt der Pruefstand nur
        // Schluesselnamen und man haelt jede Beschriftung fuer kaputt.
        if (!is_file($ordner . '/language_en.ini')
            && is_file(dirname(dirname(__DIR__)) . '/templates/lang/language_en.ini')) {
            $ordner = dirname(dirname(__DIR__)) . '/templates/lang';
        }
        $texte = @parse_ini_file($ordner . '/language_' . hk_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($ordner . '/language_en.ini', true,
                                 INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}

/** Beschriftung: maskiert. Fuer Fliesstext mit Auszeichnung hk_t() roh nehmen. */
function hk_te($schluessel)
{
    return hk_e(hk_t($schluessel));
}

/** Einen Text mit Platzhaltern fuellen: hk_tf('X.Y', array('%1' => 'a')). */
function hk_tf($schluessel, $werte)
{
    return str_replace(array_keys($werte), array_values($werte), hk_t($schluessel));
}

/* ==================================================================
 * Konfiguration
 *
 * Die Vorgaben stehen in bin/hk_vorgaben.json - derselben Datei, die auch
 * bin/hk_common.py liest. Bis 1.2.11 gab es zwei getrennt gepflegte Listen
 * in zwei Sprachen; bei Gardena hat genau diese Bauart dazu gefuehrt, dass
 * ein fehlender Schluessel in der Oberflaeche "an" und im Dienst "aus"
 * bedeutete.
 * ================================================================== */

function hk_vorgaben()
{
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $v = array();
    $roh = @file_get_contents(hk_paths()['vorgaben']);
    $j = $roh === false ? null : json_decode($roh, true);
    if (is_array($j) && isset($j['abschnitte']) && is_array($j['abschnitte'])) {
        foreach ($j['abschnitte'] as $abschnitt) {
            if (!is_array($abschnitt) || empty($abschnitt['name'])) { continue; }
            $werte = array();
            foreach ((isset($abschnitt['schluessel']) ? $abschnitt['schluessel'] : array()) as $s) {
                if (is_array($s) && isset($s['name'])) {
                    $werte[(string) $s['name']] = isset($s['vorgabe']) ? (string) $s['vorgabe'] : '';
                }
            }
            $v[(string) $abschnitt['name']] = $werte;
        }
    }
    return $v;
}

/** Zustand der Konfigurationsdatei: ok | fehlt | kaputt | keine_vorgaben */
function hk_config_lage()
{
    if (!hk_vorgaben()) {
        return 'keine_vorgaben';
    }
    $datei = hk_paths()['config'];
    if (!is_file($datei)) {
        return 'fehlt';
    }
    if (!is_readable($datei)) {
        return 'kaputt';
    }
    return @parse_ini_file($datei, true, INI_SCANNER_RAW) === false ? 'kaputt' : 'ok';
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

/** Welche Schluessel fehlen wirklich in der Datei? "abschnitt.schluessel" */
function hk_cfg_fehlende()
{
    $datei = hk_paths()['config'];
    $gelesen = is_readable($datei) ? @parse_ini_file($datei, true, INI_SCANNER_RAW) : array();
    if (!is_array($gelesen)) { $gelesen = array(); }
    $fehlen = array();
    foreach (hk_vorgaben() as $abschnitt => $werte) {
        foreach ($werte as $schluessel => $vorgabe) {
            // array_key_exists, NICHT isset: isset haelt einen leeren Wert
            // fuer nicht vorhanden und wuerde eine bewusst geleerte Angabe
            // bei jedem Lauf zurueckschreiben.
            if (!isset($gelesen[$abschnitt]) || !is_array($gelesen[$abschnitt])
                || !array_key_exists($schluessel, $gelesen[$abschnitt])) {
                $fehlen[] = $abschnitt . '.' . $schluessel;
            }
        }
    }
    return $fehlen;
}

/**
 * Fehlende Schluessel EINMAL in die Datei schreiben.
 *
 * Ergaenzen beim Lesen genuegt nicht: die Datei bliebe lueckenhaft, und
 * "fehlt" waere von "steht auf dem Vorgabewert" nicht zu unterscheiden.
 * Geschrieben wird nur, wenn wirklich etwas gefehlt hat.
 */
function hk_cfg_vervollstaendigen(&$cfg)
{
    $fehlten = hk_cfg_fehlende();
    if ($fehlten && hk_config_lage() !== 'keine_vorgaben') {
        hk_config_write($cfg);
    }
    return $fehlten;
}

/**
 * Eine Datei unteilbar ersetzen.
 *
 * Drei Punkte, die bis 1.2.11 anders waren:
 *
 * 1. Der Zwischenname traegt Prozessnummer und Zufall - sonst zerlegen zwei
 *    gleichzeitige Schreiber einander die Nebendatei.
 * 2. Die Rechte stehen am ANLEGEN, nicht dahinter. "Schreiben, dann chmod"
 *    laesst die Datei fuer die Dauer des Schreibens mit den Vorgaben der
 *    umask stehen; in xbox_auth.json stehen die Azure-Refresh-Token.
 * 3. Verglichen wird mit der LAENGE, nicht mit === false. Bricht das
 *    Schreiben auf voller Karte nach der Haelfte ab, liefert
 *    file_put_contents die geschriebene Zahl - nicht false - und rename
 *    zoege eine abgeschnittene Datei ueber die gueltige.
 */
function hk_datei_ersetzen($datei, $inhalt, $modus = 0640)
{
    if (!is_string($inhalt)) {
        return false;
    }
    $ordner = dirname($datei);
    if (!is_dir($ordner)) {
        @mkdir($ordner, 0755, true);
    }
    $vorlaeufig = $datei . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.neu';
    $fh = @fopen($vorlaeufig, 'c');
    if ($fh === false) {
        return false;
    }
    @chmod($vorlaeufig, $modus);
    $ok = @ftruncate($fh, 0);
    if ($ok) {
        $geschrieben = @fwrite($fh, $inhalt);
        $ok = ($geschrieben !== false && $geschrieben === strlen($inhalt));
    }
    @fflush($fh);
    @fclose($fh);
    if (!$ok) {
        @unlink($vorlaeufig);
        return false;
    }
    if (!@rename($vorlaeufig, $datei)) {
        @unlink($vorlaeufig);
        return false;
    }
    return true;
}

function hk_config_write($cfg)
{
    $datei = hk_paths()['config'];
    $t  = "; Heimkino\n";
    $t .= "; Wird von der Plugin-Oberflaeche und vom Dienst geschrieben.\n";
    $t .= "; ACHTUNG: enthaelt den Keycode des Beamers - nicht veroeffentlichen.\n\n";
    // Die Reihenfolge kommt aus den Vorgaben, nicht aus dem uebergebenen
    // Feld: sonst haengt der Aufbau der Datei davon ab, wer sie schreibt.
    foreach (hk_vorgaben() as $abschnitt => $werte) {
        $t .= '[' . $abschnitt . "]\n";
        foreach ($werte as $schluessel => $vorgabe) {
            $t .= $schluessel . '='
                . (isset($cfg[$abschnitt][$schluessel])
                   ? $cfg[$abschnitt][$schluessel] : $vorgabe) . "\n";
        }
        $t .= "\n";
    }
    return hk_datei_ersetzen($datei, $t, 0640);
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

/**
 * Zufallstoken fuer den Aktionsendpunkt.
 *
 * random_int() wirft eine Ausnahme, wenn das Betriebssystem keine sichere
 * Zufallsquelle anbietet. Bis 1.1.1 wurde sie nicht abgefangen - die
 * Oberflaeche brach dann beim Speichern mit einem toedlichen Fehler ab, und
 * zwar an einer Stelle, an der niemand ihn vermutet.
 *
 * BEWUSST KEIN Rueckfall auf mt_rand oder uniqid. Dieses Token ist das
 * Einzige, was den Aktionsendpunkt schuetzt; ein erratbares waere schlimmer
 * als gar keines. Wird keines erzeugt, weist der Endpunkt konsequent alles
 * ab - das ist die richtige Antwort auf ein System ohne Zufallsquelle.
 *
 * Der Zeichenvorrat laesst l, I, O und 0 bewusst aus: das Token wird von
 * Hand in Loxone uebertragen.
 */
function hk_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $t = '';
    try {
        for ($i = 0; $i < $laenge; $i++) {
            $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
        }
    } catch (Exception $e) {
        throw new RuntimeException($e->getMessage());
    } catch (Error $e) {
        throw new RuntimeException($e->getMessage());
    }
    return $t;
}

/* ==================================================================
 * Merkmal gegen fremde Absender (CSRF)
 *
 * htmlauth/ schuetzt gegen den unangemeldeten Aufruf - NICHT dagegen, dass
 * der Browser eines ANGEMELDETEN Bedieners ein Formular abschickt, das auf
 * einer fremden Seite steht: die Anmeldung schickt er automatisch mit,
 * SameSite greift nicht. Ohne dieses Merkmal liesse sich von aussen
 * "Neues Token erzeugen" ausloesen; danach beantwortet der Endpunkt jeden
 * virtuellen Ausgang mit 403 - und ein virtueller Ausgang wertet die
 * Antwort nicht aus, der Ausfall bliebe still.
 *
 * Das Merkmal wird ABGELEITET, nicht gespeichert: es gibt damit keinen
 * zweiten Wert, der verlorengehen kann, und es wechselt automatisch mit,
 * wenn das Aktionstoken neu gewuerfelt wird.
 * ================================================================== */

function hk_formtoken($cfg = null)
{
    if ($cfg === null) {
        $cfg = hk_config_read();
    }
    $grund = trim((string) hk_cfg($cfg, 'heimkino', 'aktionstoken', ''));
    // Fail closed: ohne Aktionstoken gibt es kein Merkmal. Ein aus dem
    // Leerstring abgeleiteter Wert waere fuer jeden ausrechenbar - also
    // kein Schutz, sondern die Behauptung eines Schutzes.
    if ($grund === '') {
        return '';
    }
    return hash_hmac('sha256', 'formular-v1', $grund);
}

function hk_formtoken_ok($cfg = null)
{
    $soll = hk_formtoken($cfg);
    $ist = isset($_POST['fmt']) && is_string($_POST['fmt']) ? (string) $_POST['fmt'] : '';
    return ($soll !== '' && hash_equals($soll, $ist));
}

/* ==================================================================
 * Dienst, Zustand, Protokoll
 * ================================================================== */

/**
 * Laeuft der Dienst? Rueckgabe: Prozessnummer oder 0.
 *
 * Gelesen wird die PID-Datei, die der Dienst selbst anlegt, und
 * gegengeprueft ueber /proc/<pid>/cmdline.
 *
 * Bis 1.1.1 stand hier pgrep -f "hk_service.py". Das trifft JEDEN Prozess,
 * in dessen Kommandozeile die Zeichenkette vorkommt - einen Editor mit der
 * geoeffneten Datei, ein tail auf einen Pfad mit diesem Namen, unter
 * Umstaenden die aufrufende Shell selbst. Die Oberflaeche meldete dann
 * "Dienst laeuft", obwohl er stand.
 */
function hk_dienst_pid()
{
    $datei = hk_paths()['piddatei'];
    $roh = is_readable($datei) ? trim((string) @file_get_contents($datei)) : '';
    if ($roh === '' || !preg_match('/^[0-9]+$/', $roh)) {
        return 0;
    }
    $pid = (int) $roh;
    if ($pid < 2) {
        return 0;
    }
    // Gegenprobe: gehoert die Prozessnummer wirklich zu unserem Dienst?
    // Prozessnummern werden wiederverwendet - ohne diese Pruefung koennte
    // die Oberflaeche einen fremden Prozess fuer den Dienst halten.
    //
    // Verglichen werden die EINZELNEN Argumente, nicht die Kommandozeile als
    // Zeichenkette. Beim Erproben trat der Fall tatsaechlich auf: die
    // Kommandozeile eines fremden Prozesses enthielt "hk_service.py", weil
    // dieser Pfad irgendwo als Text darin vorkam.
    $cmd = @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($cmd === false) {
        return 0;
    }
    $treffer = false;
    $teile = array_slice(array_values(array_filter(explode("\0", $cmd), 'strlen')), 0, 3);
    foreach ($teile as $teil) {
        if (basename($teil) === 'hk_service.py') { $treffer = true; break; }
    }
    return $treffer ? $pid : 0;
}

/**
 * Dienst starten, anhalten oder neu starten - ueber bin/dienst.sh.
 *
 * Bis 1.2.11 rief die Oberflaeche selbst nohup auf und schickte selbst
 * Signale. Damit gab es drei Startstellen (Daemon, Oberflaeche, keine
 * Wacht) mit je eigenen Annahmen; der Sollmerker fuer den minuetlichen
 * Waechter wurde dabei gar nicht gesetzt. Jetzt gibt es EINE Stelle.
 */
function hk_dienst($was)
{
    $skript = hk_paths()['bin'] . '/dienst.sh';
    if (!in_array($was, array('start', 'stop', 'restart'), true)) {
        return hk_dienst_pid();
    }
    if (is_file($skript)) {
        $aus = array(); $code = 0;
        @exec('/bin/bash ' . escapeshellarg($skript) . ' ' . escapeshellarg($was)
              . ' 2>&1', $aus, $code);
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

/**
 * Die letzten Zeilen der Protokolldatei - rueckwaerts mit fseek.
 *
 * NICHT die ganze Datei einlesen und NICHT exec("tail"). An 12.000 Zeilen
 * (610 kB) gemessen, je 20 Durchlaeufe:
 *
 *     file() + array_reverse    0,37 ms   zusaetzlich 2048 kB
 *     exec("tail -n 400")       2,17 ms   zusaetzlich    0 kB
 *     rueckwaerts mit fseek     0,05 ms   zusaetzlich    0 kB
 *
 * Bis 1.2.11 stand hier file_get_contents ueber die ganze Datei.
 *
 * Erst fragen, dann oeffnen: ein @fopen() auf eine fehlende Datei ist stumm,
 * aber nicht folgenlos - ein gesetzter Fehlerbehandler sieht die Warnung
 * trotzdem. Die Protokolldatei fehlt regelmaessig, naemlich vor dem ersten
 * Start.
 */
function hk_log_ende($anzahl = 200, $block = 8192)
{
    $datei = hk_paths()['log'];
    if (!is_file($datei)) {
        return array();
    }
    $fp = @fopen($datei, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

/* ==================================================================
 * MQTT-Gateway
 * ================================================================== */

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
    // Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein
    // Plugin. general.json.default setzt ab Werk Brokerhost localhost,
    // Brokerport 1883, Uselocalbroker 1 und Gatewayautostart 1.
    $hole = function ($gross, $klein, $vorgabe) use ($mqtt) {
        if (isset($mqtt[$gross])) { return $mqtt[$gross]; }
        if (isset($mqtt[$klein])) { return $mqtt[$klein]; }
        return $vorgabe;
    };
    return array(
        'host'      => trim((string) $host),
        'port'      => (int) $port,
        'lokal'     => (int) $hole('Uselocalbroker', 'uselocalbroker', 1) ? true : false,
        'autostart' => (int) $hole('Gatewayautostart', 'gatewayautostart', 1) ? true : false,
        'benutzer'  => trim((string) $hole('Brokeruser', 'brokeruser', '')),
        // 0 = nicht lesbar. NICHT auf 1 vorbelegen: "unbekannt" und
        // "Fassung 1" sind verschiedene Aussagen, und die Oberflaeche
        // behandelt sie verschieden.
        'fassung'   => isset($mqtt['Gatewayversion']) ? (int) $mqtt['Gatewayversion'] : 0,
    );
}

/**
 * Fassung des MQTT-Gateways.
 *
 * Der Satz "Ohne diesen Eintrag kommt am Miniserver nichts an" gilt NUR fuer
 * Gateway V1. Gemessen am LoxBerry-Kern (mqtt-gateway.cgi): unter V2
 * schaltet der Kern auf der Abonnement-Seite die Knoepfe ab - von Hand
 * eintragen kann man dort nichts mehr. Der unbedingte Satz schickte bis
 * 1.2.11 jeden V2-Anwender zu einem Eingabefeld, das es nicht mehr gibt.
 *
 * Rueckgabe: 0 = nicht lesbar, sonst die Fassungsnummer.
 */
function hk_mqtt_fassung()
{
    $b = hk_mqtt_broker();
    return $b === null ? 0 : (int) $b['fassung'];
}

/* ==================================================================
 * Aufrufe nach bin/
 * ================================================================== */

/** Einen Befehl von bin/hk_cmd.py ausfuehren. */
function hk_cmd($argumente)
{
    return hk_cmd_python('hk_cmd.py', (array) $argumente);
}

/** Ein Python-Programm aus bin/ aufrufen. */
function hk_cmd_python($datei, $argumente = array())
{
    $bin = hk_paths()['bin'] . '/' . $datei;
    if (!is_readable($bin)) {
        return array(1, hk_tf('FEHLER.BIN_FEHLT', array('%1' => $datei, '%2' => $bin)));
    }
    $befehl = escapeshellarg('python3') . ' ' . escapeshellarg($bin) . ' ';
    foreach ((array) $argumente as $a) {
        $befehl .= escapeshellarg($a) . ' ';
    }
    $aus = array();
    $code = 0;
    @exec($befehl . '2>&1', $aus, $code);
    return array($code, implode("\n", $aus));
}

/* ==================================================================
 * MQTT-Themen und Aktionen - aus bin/hk_themen.json
 *
 * EINE Quelle fuer den Dienst, die Themen-Tabelle und die Loxone-Vorlage.
 * Bis 1.2.11 standen die Themen zweimal: als Woerterbuch in hk_service.py
 * und als Feld in dieser Datei.
 * ================================================================== */

function hk_themen()
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $t = array();
    $roh = @file_get_contents(hk_paths()['themen']);
    $j = $roh === false ? null : json_decode($roh, true);
    $sprache = hk_sprache();
    if (is_array($j) && isset($j['themen']) && is_array($j['themen'])) {
        foreach ($j['themen'] as $e) {
            if (!is_array($e) || empty($e['thema'])) { continue; }
            $t[(string) $e['thema']] = array(
                'art'     => isset($e['art']) ? (string) $e['art'] : 'text',
                'min'     => isset($e['min']) ? (int) $e['min'] : 0,
                'max'     => isset($e['max']) ? (int) $e['max'] : 0,
                'einheit' => isset($e['einheit']) ? (string) $e['einheit'] : '',
                'text'    => isset($e[$sprache]) ? (string) $e[$sprache]
                             : (isset($e['en']) ? (string) $e['en'] : ''),
            );
        }
    }
    return $t;
}

/** Aktionen des Endpunkts: Name => Sprachschluessel der Beschriftung.
 *
 * Diese Liste ist zugleich die Weissliste des Aktionsendpunkts und die
 * Quelle der Loxone-Vorlage fuer die virtuellen Ausgaenge. Was hier nicht
 * steht, wird abgewiesen.
 */
function hk_aktionen()
{
    return array(
        'beamer-aus'       => 'AKTION.BEAMER_AUS',
        'beamer-wol'       => 'AKTION.BEAMER_WOL',
        // Bild aus, ohne das Geraet auszuschalten - fuer eine Pause, die
        // Tuerklingel oder Licht an. Bei einem Beamer der Unterschied
        // zwischen einer Sekunde und einem Lampenanlauf.
        'beamer-bild-aus'  => 'AKTION.BEAMER_BILD_AUS',
        'beamer-bild-an'   => 'AKTION.BEAMER_BILD_AN',
        'beamer-stumm-an'  => 'AKTION.BEAMER_STUMM_AN',
        'beamer-stumm-aus' => 'AKTION.BEAMER_STUMM_AUS',
        'xbox-an'          => 'AKTION.XBOX_AN',
        'xbox-aus'         => 'AKTION.XBOX_AUS',
        // Die Szene laeuft im Dienst ab, nicht im Endpunkt: der Beamer nimmt
        // nur eine Verbindung zur Zeit an, und ein virtueller Ausgang in
        // Loxone hat ein Zeitlimit, das kuerzer ist als der Ablauf.
        'kino-an'          => 'AKTION.KINO_AN',
        'kino-aus'         => 'AKTION.KINO_AUS',
    );
}

/** Aktionen mit Wert - hier steht kein fertiger Befehl, sondern ein Muster. */
function hk_aktionen_mit_wert()
{
    return array(
        'beamer-taste'       => 'AKTION.BEAMER_TASTE',
        'beamer-eingang'     => 'AKTION.BEAMER_EINGANG',
        'beamer-lautstaerke' => 'AKTION.BEAMER_LAUTSTAERKE',
        'beamer-bildmodus'   => 'AKTION.BEAMER_BILDMODUS',
        'beamer-energie'     => 'AKTION.BEAMER_ENERGIE',
        'beamer-app'         => 'AKTION.BEAMER_APP',
    );
}

/**
 * Die zulaessigen Werte fuer Eingang, Bildmodus und Energiesparstufe.
 *
 * Gefragt wird bin/lg_beamer.py --woerter, NICHT eine zweite Liste hier.
 * Die Woerter stammen aus src/constants/TV.ts der Vorlage; sie ein zweites
 * Mal zu fuehren waere die naechste zweite Wahrheit.
 *
 * Antwortet das Programm nicht, gibt es ein leeres Feld - dann zeigt die
 * Oberflaeche ein Textfeld statt einer Auswahl und sagt das auch. Eine
 * geratene Liste waere schlechter als keine.
 */
function hk_woerter()
{
    static $w = null;
    if ($w !== null) {
        return $w;
    }
    $w = array();
    list($code, $aus) = hk_cmd_python('lg_beamer.py', array('--woerter'));
    if ($code === 0) {
        $j = json_decode($aus, true);
        if (is_array($j)) {
            foreach ($j as $art => $liste) {
                if (is_array($liste)) {
                    $w[(string) $art] = array_values(array_map('strval', $liste));
                }
            }
        }
    }
    return $w;
}

/* ==================================================================
 * Loxone-Vorlage
 *
 * Nachbau von LoxBerry::LoxoneTemplateBuilder; das Modul gibt es nur in
 * Perl. Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor den
 * Kindelementen entsprechen den massgeblichen Ausfuhren aus Loxone Config.
 * ================================================================== */

function hk_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Rechnername des LoxBerry - EINE Stelle fuer Anzeige und Vorlage. */
function hk_hostname()
{
    // Bis 1.2.11 nahm die Anzeige gethostname() und die Vorlage
    // $_SERVER['HTTP_HOST'] - zwei Quellen fuer dieselbe Adresse, die hinter
    // einem Reverse Proxy oder bei abweichendem Port auseinanderlaufen.
    // Massgeblich ist der Name, unter dem der Bediener die Seite gerade
    // aufgerufen hat; er ist der einzige, von dem belegt ist, dass er
    // funktioniert. Nur was gar nicht da ist, faellt auf gethostname zurueck.
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '';
    // Nicht filtern, sondern abweisen: ein Host-Kopf, der nicht auf das
    // Muster passt, ist keiner - dann lieber der eigene Rechnername.
    if ($host !== '' && preg_match('/^[A-Za-z0-9._-]+(:[0-9]{1,5})?$/', $host)) {
        return $host;
    }
    $eigen = gethostname();
    return $eigen ? $eigen : 'loxberry';
}

function hk_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" ';
    $o .= 'Title="' . hk_x($kopf['title']) . '" ';
    $o .= 'Comment="' . hk_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . hk_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . hk_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . hk_x($c['title']) . '" ';
        $o .= 'Comment="' . hk_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . hk_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="' . ($c['analog'] ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        // Grenzen NICHT pauschal auf +-2147483647: Loxone zieht daraus die
        // Reglergrenzen und die Plausibilitaetspruefung. Und ein Feld, das
        // negativ werden kann (Tage bis zum Ablauf), braucht ein negatives
        // MinVal - sonst steht in der Visualisierung 0, und 0 heisst dort
        // "heute" statt "abgelaufen".
        $o .= 'MinVal="' . (int) $c['min'] . '" ';
        $o .= 'MaxVal="' . (int) $c['max'] . '" ';
        $o .= 'Unit="' . hk_x($c['unit']) . '" ';
        $o .= 'HintText=""';
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
    foreach (hk_themen() as $thema => $e) {
        // Textthemen bleiben draussen. Das nachgebaute Format ist nur fuer
        // ZAHLENwerte belegt; bis 1.2.11 landeten last_error, beamer/status,
        // beamer/app, xbox/status und das Ablaufdatum mit Analog="true" und
        // Zahlengrenzen in der Datei.
        if ($e['art'] === 'text') { continue; }
        $cmds[] = array(
            'title'   => $praefix . '_' . str_replace('/', '_', $thema),
            'comment' => $e['text'],
            'check'   => ' ',
            'analog'  => ($e['art'] === 'analog'),
            'min'     => $e['min'],
            'max'     => $e['max'],
            'unit'    => $e['einheit'] !== '' ? $e['einheit'] : '<v.1>',
        );
    }
    return array('VI_Heimkino.xml', hk_xml_virtual_in_http(array(
        'title'   => 'Heimkino (LoxBerry-Plugin)',
        'address' => 'http://' . hk_hostname(),
        'polling' => '604800',
        'comment' => hk_tf('LOX.VORLAGE_KOMMENTAR', array('%1' => date('d.m.Y'))),
    ), $cmds));
}

/** Vorlage der Steuerbefehle (Virtueller Ausgang). */
function hk_vo_vorlage($cfg)
{
    $host = hk_hostname();
    $ordner = hk_paths()['plugin'];
    $tok = hk_cfg($cfg, 'heimkino', 'aktionstoken', '');
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="Heimkino steuern (LoxBerry-Plugin)" '
        . 'Comment="' . hk_x(hk_t('LOX.VO_KOMMENTAR')) . '" '
        . 'Address="http://' . hk_x($host) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach (hk_aktionen() as $aktion => $schluessel) {
        $o .= "\t" . '<VirtualOutCmd Title="' . hk_x(hk_t($schluessel)) . '" Comment="" CmdOnMethod="GET" CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . hk_x(hk_aktionsadresse($cfg, $aktion)) . '" ';
        $o .= 'CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" ';
        $o .= 'Analog="false" Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return array('VQ_Heimkino.xml', $o);
}

/** Vollstaendige Aufrufadresse einer Aktion, wie sie in Loxone gehoert. */
function hk_aktionsadresse($cfg, $aktion, $wert = null)
{
    $token = hk_cfg($cfg, 'heimkino', 'aktionstoken', '');
    $a = '/plugins/' . hk_paths()['plugin'] . '/index.php?token='
       . rawurlencode($token) . '&aktion=' . rawurlencode($aktion);
    if ($wert !== null) {
        $a .= '&wert=' . rawurlencode($wert);
    }
    return $a;
}

/** Die Selbsttest-Adresse - dieselbe Bauart, damit sie nie abweicht. */
function hk_selftestadresse($cfg)
{
    $token = hk_cfg($cfg, 'heimkino', 'aktionstoken', '');
    return '/plugins/' . hk_paths()['plugin'] . '/index.php?selftest=1&token='
         . rawurlencode($token);
}

/* ==================================================================
 * Xbox - Anmeldedatei
 *
 * Anwendungskennung und Token liegen getrennt von der Konfiguration in
 * xbox_auth.json mit Rechten 0600.
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
    // json_encode liefert bei ungueltigem UTF-8 FALSE, und
    // file_put_contents($pfad, false) schreibt daraufhin 0 Bytes - und gibt
    // 0 zurueck, nicht false. Deshalb wird das Ergebnis der Kodierung HIER
    // geprueft, vor dem Schreiben; sonst zoege rename() die geleerte Datei
    // ueber die gueltige und die Azure-Refresh-Token waeren weg.
    $js = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                              | JSON_UNESCAPED_UNICODE);
    if ($js === false) {
        return false;
    }
    return hk_datei_ersetzen(hk_paths()['auth'], $js, 0600);
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
 * Den Autorisierungscode fuer hk_cmd.py hinterlegen.
 *
 * Rechte 0600, und die Datei wird von hk_cmd.py nach dem Lesen geloescht.
 * Bis 1.2.11 ging der Code als Argument ueber die Kommandozeile hinaus und
 * stand damit in der Prozessliste.
 */
function hk_xbox_code_hinterlegen($code)
{
    return hk_datei_ersetzen(hk_paths()['code'], (string) $code, 0600);
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
        'dienst'       => isset($daten['dienst']) ? (string) $daten['dienst'] : 'live',
        'rueckleitung' => isset($daten['redirect_uri'])
                          ? $daten['redirect_uri'] : HK_RUECKLEITUNG,
    );
}

/* ==================================================================
 * Form der Anmeldedaten beurteilen
 *
 * Die haeufigste Verwechslung: aus der Tabelle unter "Zertifikate &
 * Geheimnisse" wird die Spalte "Geheime ID" kopiert statt der Spalte "Wert".
 * Beides sind lange Zeichenketten, aber die Geheime ID ist eine GUID mit
 * vier Bindestrichen - das laesst sich erkennen, ohne das Geheimnis
 * anzuzeigen.
 *
 * Diese Funktionen geben nur noch die BEURTEILUNG zurueck, keinen Text.
 * Den Satz dazu holt die Oberflaeche aus der Sprachdatei.
 * ================================================================== */

function hk_ist_guid($s)
{
    return (bool) preg_match(
        '/^\{?[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\}?$/',
        trim((string) $s));
}

/**
 * Beurteilt das gespeicherte Geheimnis. Gibt array(art, laenge) zurueck.
 * art: 'ok' | 'guid' | 'kurz' | 'leer'
 */
function hk_geheimnis_form($wert)
{
    $wert = (string) $wert;
    if (trim($wert) === '') {
        return array('leer', 0);
    }
    if (hk_ist_guid($wert)) {
        return array('guid', strlen($wert));
    }
    $laenge = strlen($wert);
    return array($laenge < 20 ? 'kurz' : 'ok', $laenge);
}

/**
 * Restlaufzeit des Azure-Clientgeheimnisses. Gibt array(art, tage, datum).
 * art: 'leer' | 'unlesbar' | 'ok' | 'bald' | 'abgelaufen'
 */
function hk_ablauf_lage($datum)
{
    $datum = trim((string) $datum);
    if ($datum === '') {
        return array('leer', null, '');
    }
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $datum)) {
        return array('unlesbar', null, $datum);
    }
    $zeit = strtotime($datum . ' 23:59:59');
    if ($zeit === false) {
        return array('unlesbar', null, $datum);
    }
    $tage = (int) floor(($zeit - time()) / 86400);
    $hin = date('d.m.Y', $zeit);
    if ($tage < 0) {
        return array('abgelaufen', $tage, $hin);
    }
    if ($tage <= 60) {
        return array('bald', $tage, $hin);
    }
    return array('ok', $tage, $hin);
}

/** Vorschlag fuer das Ablaufdatum: heute plus 24 Monate, das Azure-Hoechstmass. */
function hk_ablauf_vorschlag()
{
    return date('Y-m-d', strtotime('+24 months'));
}

/* ==================================================================
 * Selbstpruefung - Zeilen, die etwas ueber das PLUGIN aussagen
 *
 * Jede gibt array(zustand, text) zurueck:
 *   1 = in Ordnung, 0 = Befund, 2 = nicht feststellbar.
 *
 * "Nicht feststellbar" ist ausdruecklich ein eigener Zustand. Ein "ich kann
 * es nicht messen" darf nicht aussehen wie "in Ordnung".
 *
 * Jede Zeile, die eine MENGE beurteilt, nennt die Zahl der angesehenen
 * Stellen. Eine Null ist dann kein Haken, sondern der Hinweis, dass nichts
 * gemessen wurde.
 * ================================================================== */

/**
 * Setzt der Server das sm-active - an der Leiste UND an den Bereichen?
 *
 * Ohne das ist die Seite ohne JavaScript vollstaendig leer, denn .sm-seite
 * steht auf display:none. Und: die zusammengesetzte Klasse macht
 * hausstandard_pruefen.py blind ("nicht pruefbar"), was sich beim
 * Ueberfliegen wie ein Haken einsammelt. Wer eine Pruefung blind macht,
 * ERSETZT sie.
 */
function hk_smactive_probe()
{
    $datei = __DIR__ . '/index.php';
    if (!is_file($datei)) {
        return array(2, '0');
    }
    $s = (string) @file_get_contents($datei);
    $anzahl = preg_match_all('/data-ziel="tab-([a-z]+)"/', $s, $y);
    $leiste = preg_match_all('/class="sm-tab<\?=[^>]*sm-active/', $s);
    $bereiche = preg_match_all('/class="sm-seite<\?=[^>]*sm-active/', $s);
    if ($anzahl > 0 && $leiste >= $anzahl && $bereiche >= $anzahl) {
        return array(1, $anzahl . '/' . $anzahl);
    }
    return array(0, $leiste . ' / ' . $bereiche . ' / ' . $anzahl);
}

/** Tragen alle Formulare das Merkmal gegen fremde Absender? */
function hk_formularprobe()
{
    $datei = __DIR__ . '/index.php';
    if (!is_file($datei)) {
        return array(2, '0/0');
    }
    $s = (string) @file_get_contents($datei);
    $gesamt = 0;
    $ohne = 0;
    if (preg_match_all('/<form\s/', $s, $y, PREG_OFFSET_CAPTURE)) {
        foreach ($y[0] as $f) {
            $gesamt++;
            $ende = strpos($s, '</form>', $f[1]);
            $blk = substr($s, $f[1], ($ende === false ? 400 : $ende - $f[1]));
            if (strpos($blk, 'name="fmt"') === false) { $ohne++; }
        }
    }
    // Die leere Menge zuerst: "alle 0 von 0 sind in Ordnung" ist kein Haken.
    if ($gesamt === 0) {
        return array(0, '0/0');
    }
    return array($ohne > 0 ? 0 : 1, ($gesamt - $ohne) . '/' . $gesamt);
}

/** Passen Reiterleiste, Bereiche und Positivliste zusammen? */
function hk_kongruenz_probe()
{
    $datei = __DIR__ . '/index.php';
    if (!is_file($datei)) {
        return array(2, '0');
    }
    $s = (string) @file_get_contents($datei);
    preg_match_all('/data-ziel="(tab-[a-z]+)"/', $s, $leiste);
    preg_match_all('/id="(tab-[a-z]+)"/', $s, $bereiche);
    $liste = array();
    if (preg_match('/\$hk_reiter\s*=\s*array\(([^)]*)\)/', $s, $m)) {
        preg_match_all("/'(tab-[a-z]+)'/", $m[1], $x);
        $liste = $x[1];
    }
    $a = $leiste[1];
    $b = $bereiche[1];
    if (!$liste || !$a || !$b) {
        return array(0, count($liste) . ' / ' . count($a) . ' / ' . count($b));
    }
    $gleich = ($liste === $a && $a === $b);
    return array($gleich ? 1 : 0,
                 count($liste) . ' / ' . count($a) . ' / ' . count($b));
}

/**
 * Nennt die Themen-Tabelle genau das, was der Dienst wirklich sendet?
 *
 * Gefragt wird der DIENST (--themen), nicht die Datei - sonst prueft die
 * Zeile die Quelle gegen sich selbst.
 */
function hk_themen_probe()
{
    list($code, $aus) = hk_cmd_python('hk_service.py', array('--themen'));
    $gesendet = $code === 0 ? json_decode($aus, true) : null;
    if (!is_array($gesendet)) {
        return array(2, '0');
    }
    $gezeigt = array_keys(hk_themen());
    sort($gezeigt);
    sort($gesendet);
    if ($gezeigt === $gesendet) {
        return array(1, (string) count($gesendet));
    }
    $fehlt = array_diff($gesendet, $gezeigt);
    $zuviel = array_diff($gezeigt, $gesendet);
    return array(0, count($gezeigt) . '/' . count($gesendet) . ' '
                 . implode(' ', array_merge($fehlt, $zuviel)));
}

/** Kennen Oberflaeche und Dienst dieselben Vorgaben? */
function hk_vorgaben_probe()
{
    list($code, $aus) = hk_cmd_python('hk_service.py', array('--vorgaben'));
    $dienst = $code === 0 ? json_decode($aus, true) : null;
    if (!is_array($dienst)) {
        return array(2, '0');
    }
    $hier = hk_vorgaben();
    $zahl = 0;
    foreach ($hier as $werte) { $zahl += count($werte); }
    return array($hier == $dienst ? 1 : 0, (string) $zahl);
}

/** Ist die Konfiguration vollstaendig? */
function hk_vollstaendig_probe()
{
    $vorgaben = hk_vorgaben();
    $gesamt = 0;
    foreach ($vorgaben as $werte) { $gesamt += count($werte); }
    if ($gesamt === 0) {
        return array(2, '0');
    }
    $fehlen = hk_cfg_fehlende();
    if (!$fehlen) {
        return array(1, $gesamt . '/' . $gesamt);
    }
    return array(0, ($gesamt - count($fehlen)) . '/' . $gesamt . ': '
                 . implode(', ', $fehlen));
}

/**
 * Wirkt die Geraetesperre?
 *
 * Sie verhindert, dass Dienst und Einzelbefehl gleichzeitig mit dem Beamer
 * sprechen - das Geraet nimmt nur EINE Verbindung zur Zeit an. Ohne einen
 * Unterbau (fcntl auf dem LoxBerry) wird nicht gesperrt, und dann soll das
 * hier stehen statt angenommen zu werden.
 */
function hk_sperre_probe()
{
    list($code, $aus) = hk_cmd_python('hk_sperre.py', array('--unterbau'));
    $u = trim($aus);
    if ($code !== 0) {
        return array(2, '');
    }
    return array($u !== '' ? 1 : 0, $u);
}

/** Sind die erzeugbaren Loxone-Vorlagen wohlgeformt? */
function hk_vorlagen_probe($cfg)
{
    if (!function_exists('simplexml_load_string')) {
        return array(2, '0');
    }
    $zahl = 0;
    foreach (array(hk_vorlage($cfg), hk_vo_vorlage($cfg)) as $paar) {
        $zahl++;
        $vorher = libxml_use_internal_errors(true);
        $ok = simplexml_load_string($paar[1]);
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);
        if ($ok === false) {
            return array(0, $paar[0]);
        }
    }
    return array(1, (string) $zahl);
}

/**
 * fsockopen, ohne dass ein gesetzter Fehlerbehandler etwas zu sehen bekommt.
 *
 * Ein @ unterdrueckt nur die AUSGABE. Wer einen eigenen Fehlerbehandler
 * gesetzt hat - jeder Pruefstand tut das -, bekommt die Warnung trotzdem und
 * meldet sie als Befund. Dabei ist ein Ziel, das nicht antwortet, hier der
 * erwartete Fall: der Beamer ist im Tiefschlaf, oder auf dem Pruefstand
 * horcht kein Webserver. Deshalb bekommt der Aufruf seinen eigenen
 * Behandler, der schweigt, und gibt danach den alten zurueck.
 */
function hk_socket($host, $port, &$nr, &$txt, $zeitgrenze)
{
    set_error_handler(function () { return true; });
    $fp = fsockopen($host, $port, $nr, $txt, $zeitgrenze);
    restore_error_handler();
    return $fp;
}

/**
 * Antwortet der eigene Endpunkt?
 *
 * Ein ECHTER Aufruf auf 127.0.0.1 - nur der findet die getrennten Baeume,
 * die keine Leseprobe sieht. Genau dieses Plugin war der Anlass der Regel:
 * bis 1.2.10 antwortete der Endpunkt IMMER mit einem leeren HTTP 500, und
 * beide Loxone-Ausgaenge hatten seit jeher nichts bewirkt.
 *
 * Das Ergebnis wird 300 s zwischengespeichert, sonst ruft sich der
 * Webserver bei jedem Klick selbst auf - und alle Reiter werden dabei
 * mitgerendert. Die Zeitgrenze ist bewusst kurz: der Bediener soll nicht
 * vor einer leeren Seite warten, gerade wenn etwas nicht stimmt.
 */
function hk_endpunkt_probe($cfg, $hoechstalter = 300)
{
    $speicher = hk_paths()['probe'];
    if (is_readable($speicher)) {
        $alt = json_decode((string) @file_get_contents($speicher), true);
        if (is_array($alt) && isset($alt['zeit'])
            && (time() - (int) $alt['zeit']) < $hoechstalter) {
            return array((int) $alt['zustand'], (string) $alt['text']);
        }
    }
    $token = hk_cfg($cfg, 'heimkino', 'aktionstoken', '');
    if ($token === '') {
        return array(2, 'KEIN_TOKEN');
    }
    $port = isset($_SERVER['SERVER_PORT']) ? (int) $_SERVER['SERVER_PORT'] : 80;
    if ($port < 1 || $port > 65535) { $port = 80; }
    $pfad = hk_selftestadresse($cfg);
    $ergebnis = array(2, 'KEINE_ANTWORT');
    $fp = hk_socket('127.0.0.1', $port, $nr, $txt, 3);
    if ($fp) {
        stream_set_timeout($fp, 3);
        fwrite($fp, "GET " . $pfad . " HTTP/1.0\r\n"
                  . "Host: 127.0.0.1\r\n"
                  . "Connection: close\r\n\r\n");
        $antwort = '';
        while (!feof($fp) && strlen($antwort) < 8192) {
            $stueck = fread($fp, 2048);
            if ($stueck === false || $stueck === '') { break; }
            $antwort .= $stueck;
        }
        fclose($fp);
        if ($antwort !== '') {
            $ergebnis = (strpos($antwort, 'SELFTEST;OK=1;TOKEN=OK') !== false)
                ? array(1, 'OK')
                : array(0, trim(substr(strrchr($antwort, "\n"), 0, 120)));
        }
    }
    @hk_datei_ersetzen($speicher, json_encode(array(
        'zeit' => time(), 'zustand' => $ergebnis[0], 'text' => $ergebnis[1])), 0640);
    return $ergebnis;
}

/* ==================================================================
 * Version des Plugins
 *
 * Wird NICHT fest eingetragen. Bis 1.0.2 stand die Nummer als Text in
 * index.php - und blieb bei jedem Release stehen: die Oberflaeche zeigte
 * 1.0.0, obwohl 1.0.2 lief. Eine Versionsnummer an zwei Stellen ist eine
 * Stelle zu viel.
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
    // das SDK nicht eingebunden ist.
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


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function hk_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(hk_t('SET.SICH_KEIN_JSON')), 0);
    }
    $neu = hk_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(hk_t('SET.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = hk_t('SET.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}
