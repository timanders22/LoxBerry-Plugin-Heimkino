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
 * config/plugins, data/plugins UND config/system/general.json enthaelt. Das
 * trifft die uebliche Installation genauso wie eine an einem anderen Ort -
 * und es trifft auch den Fall, dass das Plugin noch als entpacktes Archiv
 * daliegt (dann findet es nichts und gibt einen Leerstring zurueck, was der
 * Aufrufer ohnehin abfangen muss).
 *
 * general.json ist die entscheidende Bedingung seit 1.3.14 (Regeln/06,
 * Raumklima-Vorfall): config/plugins und webfrontend hinterlaesst auch ein
 * Pruefstand auf einem Arbeitsrechner.
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
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
    /* Wurzel: erst die Umgebung (config/plugins UND data/plugins darunter),
     * dann die Suche - und DANACH NICHTS MEHR. Bis 1.3.13 stand hier als
     * weitere Stufe ein fest verdrahteter Systempfad (das Heimatverzeichnis
     * des Benutzers loxberry); ohne Wurzel wurden die Pfade ab "/"
     * zusammengesetzt (/config/plugins/heimkino/... - Muster 1 und 2 der
     * Nachlese, in WSL gemessen, Pruefung-Heimkino-1.3.14, Fall O3). */
    /* H13 (1.3.30, Pruefbericht Fremdbefunde, E2): der Ordnername ist der der eigenen Ablage, wenn diese Datei unter
     * .../htmlauth/plugins/<ordner>/ liegt; im Zweig "ausdruecklich" der aus $LBPPLUGINDIR; "heimkino" nur noch als
     * Rueckfall fuer das ausgepackte Archiv. Bis 1.3.29 stand hier fest 'heimkino' - unter plugins/heimkino01 lief
     * die Oberflaeche dann im Archivmodus (in WSL gemessen), und bin/hk_common.py zeigte mit LBPPLUGINDIR auf die
     * Konfiguration eines fremden gleichnamigen Plugins. Dieselbe Regel steht in hk_common.ORDNER (H8). */
    $lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    $eigen = basename(dirname(__DIR__)) === 'plugins' ? basename(__DIR__) : '';
    $ordner = $eigen !== '' ? $eigen : 'heimkino';
    $home = rtrim((string) getenv('LBHOMEDIR'), '/');
    if ($home === '' || !is_dir($home . '/config/plugins') || !is_dir($home . '/data/plugins')) {
        $home = lb_wurzel_ermitteln();
    }
    /* Archivmodus (seit 1.3.14). Die Anlage gilt nur, wenn diese Bibliothek
     * dort installiert liegt (<Wurzel>/webfrontend/htmlauth/plugins/<ordner>,
     * physisch verglichen) oder der Aufrufer Wurzel UND Ordner ausdruecklich
     * nennt ($LBHOMEDIR und $LBPPLUGINDIR - so arbeiten die Pruefwerkzeuge
     * mit ihrer Attrappe). Bis 1.3.13 nahm ein Archiv oder eine Kopie unter
     * einer echten Wurzel mit $LBHOMEDIR allein Konfiguration, Token und
     * Dienst der Anlage (Fall O1; Muster 3, Bauart Spotpreis-Tibber 0.9.19). */
    $archiv = '';
    if ($home !== '') {
        $soll = @realpath($home . '/webfrontend/htmlauth/plugins/' . $ordner);
        $ist = @realpath(__DIR__);
        $installiert = ($eigen !== '' && $soll !== false && $ist !== false && $soll === $ist);
        $umgebung = (string) getenv('LBHOMEDIR');
        $ausdruecklich = ($lbp !== '' && !in_array($lbp, array('.', '/', 'bin', 'html', 'plugins'), true)
            && $umgebung !== '' && @realpath($umgebung) !== false
            && @realpath($umgebung) === @realpath($home));
        if (!$installiert && $ausdruecklich) {
            $ordner = $lbp;
        }
        if (!$installiert && !$ausdruecklich) {
            $archiv = $home;
            $home = '';
        }
    }
    /* Ohne Anlage liegt alles NEBEN dem Plugin (Unterordner ohne_loxberry
     * des ausgepackten Archivs), nie an der Laufwerkswurzel. Die Knoepfe
     * rufen dann bin/dienst.sh und bin/hk_cmd.py des Archivs; beide weisen
     * ab (seit 1.3.14). */
    $basis = ($home !== '') ? $home : dirname(dirname(__DIR__)) . '/ohne_loxberry';
    $bin = ($home !== '') ? $home . '/bin/plugins/' . $ordner : '';
    // Im ausgepackten Archiv liegt bin/ neben webfrontend/ - dann greift der
    // Pfad oben nicht. Ohne diesen Zweig faende die Selbstpruefung die
    // gemeinsamen Datendateien im Pruefstand nie.
    if (($bin === '' || !is_dir($bin)) && is_dir(dirname(dirname(__DIR__)) . '/bin')) {
        $bin = dirname(dirname(__DIR__)) . '/bin';
    }
    $p = array(
        'home'     => $home,
        'archiv'   => $archiv,
        'plugin'   => $ordner,
        'config'   => $basis . '/config/plugins/' . $ordner . '/heimkino.cfg',
        'auth'     => $basis . '/config/plugins/' . $ordner . '/xbox_auth.json',
        // Der Autorisierungscode aus der Microsoft-Rueckleitung. Er wird hier
        // mit Rechten 0600 abgelegt und von hk_cmd.py gelesen und geloescht -
        // NICHT ueber die Kommandozeile uebergeben. Argumente stehen in
        // /proc/<pid>/cmdline und sind fuer jeden lokalen Benutzer lesbar;
        // zusammen mit dem Clientgeheimnis laesst sich aus dem Code ein
        // Erneuerungstoken loesen. Bis 1.2.11 ging er als Argument hinaus.
        'code'     => $basis . '/config/plugins/' . $ordner . '/xbox_code.tmp',
        'zustand'  => $basis . '/data/plugins/' . $ordner . '/zustand.json',
        'daten'    => $basis . '/data/plugins/' . $ordner,
        // Seit 1.2.12 unter data/, nicht mehr unter log/: log/plugins ist
        // eine Ramdisk, und der Dienst haelt auf dieser Datei eine echte
        // Dateisperre (flock).
        'piddatei' => $basis . '/data/plugins/' . $ordner . '/hk_service.pid',
        'soll'     => $basis . '/data/plugins/' . $ordner . '/soll_laufen',
        'probe'    => $basis . '/data/plugins/' . $ordner . '/endpunkt_probe.json',
        'log'      => $basis . '/log/plugins/' . $ordner . '/heimkino.log',
        'bin'      => $bin,
        'vorgaben' => $bin . '/hk_vorgaben.json',
        'themen'   => $bin . '/hk_themen.json',
        'general'  => $basis . '/config/system/general.json',
        // Seit 1.3.15. Die Zweitschrift liegt NEBEN dem Konfigordner (den
        // raeumt der Installer bei jedem Upgrade ab); preupgrade.sh legt sie
        // an, seit 1.3.15 zieht auch jedes Schreiben der Oberflaeche mit
        // Aktionstoken sie nach (Regeln/05). Aus ihr heilt hk_config_heilen().
        'zweit'    => $basis . '/config/plugins/' . $ordner . '.backup.heimkino.cfg',
        // Ergebnis eines POST fuer das folgende GET (PRG, Regeln/04), 0600.
        'einmal'   => $basis . '/data/plugins/' . $ordner . '/einmalmeldung.json',
        // Zwischenspeicher der Wortlisten aus lg_beamer.py --woerter (O8).
        'woerter'  => $basis . '/data/plugins/' . $ordner . '/woerter.json',
        // Bremse fuer die Protokollzeilen der Oberflaeche und des Endpunkts.
        'bremse'   => $basis . '/data/plugins/' . $ordner . '/protokoll_bremse.json',
        // Mindestabstand der Xbox-Befehle aus dem Endpunkt (C10).
        'xbox_befehl' => $basis . '/data/plugins/' . $ordner . '/xbox_befehl.json',
        // Gleichwert-Unterdrueckung der Sollwert-Befehle (X-7, B-Nachzug 01.10.2026).
        'gleichwert' => $basis . '/data/plugins/' . $ordner . '/befehl_gleichwert.json',
        // Eigener Merker der Xbox (Nachtrag): ihr Cloud-Befehl haelt die Sperre bis 90 s.
        'gleichwert_xbox' => $basis . '/data/plugins/' . $ordner . '/befehl_gleichwert_xbox.json',
        // Gruppe 9 (1.3.30, Entwurf 3.4/3.5, E4): Auftragswarteschlange fuer den Dienst - MQTT-Befehle, Szenen
        // (HTTP und MQTT) und Nachfassen; je Auftrag eine Datei <sek10><usek6>_<hex8>.json.
        'auftraege' => $basis . '/data/plugins/' . $ordner . '/auftraege',
        // Gruppe 9 (Entwurf 3.7): wann zuletzt ein schaltender Aufruf mit Token kam (nur die Zeit).
        'endpunkt_zuletzt' => $basis . '/data/plugins/' . $ordner . '/endpunkt_zuletzt',
        // Gruppe 9 (Entwurf 2.4, E8): die Abo-Datei des MQTT-Gateways, gepflegt vom Dienst ("<praefix>/#").
        'abo'      => $basis . '/config/plugins/' . $ordner . '/mqtt_subscriptions.cfg',
        'cron'     => $home !== '' ? $home . '/system/cron' : '',
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
        // Ohne Anlage NICHT ab "/" suchen (seit 1.3.14; bis 1.3.13 wurde
        // dann /templates/plugins/heimkino/lang gefragt - Muster 2).
        $ordner = (hk_paths()['home'] !== '')
                ? hk_paths()['home'] . '/templates/plugins/' . hk_paths()['plugin'] . '/lang'
                : '';
        // Im ausgepackten Archiv liegen die Sprachdateien noch an ihrem
        // Platz im Paket. Ohne diesen Zweig zeigt der Pruefstand nur
        // Schluesselnamen und man haelt jede Beschriftung fuer kaputt.
        if (($ordner === '' || !is_file($ordner . '/language_en.ini'))
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

/**
 * Schluessel der Datei, die die Vorgaben nicht kennen (seit 1.3.15, C9).
 *
 * Regeln/05: "fremd -> NENNEN, stehen lassen". Bis 1.3.14 schrieb
 * hk_config_write() nur die Vorgabenschluessel, und ein fremder Schluessel -
 * Rest einer anderen Fassung oder Vorgriff auf die naechste - verschwand
 * beim ersten Ergaenzen wortlos (in WSL gemessen, Befund code 10).
 * Rueckgabe: array(abschnitt => array(schluessel => wert)).
 */
function hk_config_fremde()
{
    $datei = hk_paths()['config'];
    $gelesen = is_readable($datei) ? @parse_ini_file($datei, true, INI_SCANNER_RAW) : false;
    if (!is_array($gelesen)) {
        return array();
    }
    $vg = hk_vorgaben();
    $fremd = array();
    foreach ($gelesen as $abschnitt => $werte) {
        if (!is_array($werte)) {
            continue;
        }
        foreach ($werte as $k => $w) {
            if (isset($vg[$abschnitt]) && array_key_exists($k, $vg[$abschnitt])) {
                continue;
            }
            if (is_array($w)) {
                continue;
            }
            $fremd[(string) $abschnitt][(string) $k] = (string) $w;
        }
    }
    return $fremd;
}

/** Die fremden Schluessel als Liste "abschnitt.schluessel" - fuer die Anzeige. */
function hk_config_fremde_namen()
{
    $namen = array();
    foreach (hk_config_fremde() as $abschnitt => $werte) {
        foreach (array_keys($werte) as $k) {
            $namen[] = $abschnitt . '.' . $k;
        }
    }
    return $namen;
}

/**
 * Traegt eine .cfg Inhalt? Lesbar, Abschnitt [heimkino] und ein Anwenderfeld
 * (keycode, ip, mac, geraete_id) - seit 1.3.30 derselbe Begriff wie hk_inhalt der
 * Hakenskripte (H14; Regeln/05 "Die Selbstheilung entscheidet nach Inhalt").
 */
function hk_cfg_hat_inhalt($datei)
{
    if (!is_file($datei) || !is_readable($datei)) {
        return false;
    }
    $g = @parse_ini_file($datei, true, INI_SCANNER_RAW);
    // H14 (1.3.30): derselbe Inhaltsbegriff wie hk_inhalt der Hakenskripte (seit 1.3.15, I4) - Abschnitt
    // [heimkino] UND ein Anwenderfeld (keycode, ip, mac, geraete_id). Das Aktionstoken zaehlt nicht mehr: es
    // entsteht beim ersten Oeffnen von selbst. Bis 1.3.29 verlangte diese Stelle das Token, und eine Zweitschrift
    // mit Feldern, aber ohne Token heilte nicht (Pruefbericht H14, Fall a).
    return is_array($g) && isset($g['heimkino']) && is_array($g['heimkino'])
        && hk_cfg_felder_zahl(@file_get_contents($datei)) > 0;
}

/** Was hk_config_heilen() in DIESEM Aufruf festgestellt hat (Regeln/05: den
 * Zustand merken, bevor die Selbstheilung ihn beseitigt). */
function hk_heilung_lage($setzen = null)
{
    static $lage = 'nichts';
    if ($setzen !== null) {
        $lage = (string) $setzen;
    }
    return $lage;
}

/**
 * Eine beschaedigte Konfiguration heilen (seit 1.3.15, C4).
 *
 * Bis 1.3.14 genuegte EIN Seitenaufruf mit einer Datei, in der nur "[beamer"
 * statt "[beamer]" stand: hk_config_read() lieferte die Vorgaben, der
 * Seitenaufbau wuerfelte ein neues Aktionstoken und schrieb die
 * Werkseinstellungen samt Token in die Datei - ohne .kaputt, ohne
 * Protokollzeile (in WSL und unter 7.4/8.5 gemessen, Befund code 5).
 *
 * Jetzt: bei "kaputt" wird NICHTS geschrieben und kein Token erzeugt. Traegt
 * die Zweitschrift Inhalt, geht die Datei als .kaputt (0600) beiseite, die
 * Zweitschrift wird einmal zurueckgeschrieben, und es gibt eine
 * Protokollzeile. Ohne heile Zweitschrift bleibt die Datei, wie sie ist -
 * hk_config_write() weist dann jedes Schreiben ab.
 *
 * Rueckgabe: array(zustand, pfad) mit zustand 'nichts' | 'geheilt' |
 * 'ohne_zweitschrift' | 'fehler'.
 */
function hk_config_heilen()
{
    $lage = hk_config_lage();
    $p = hk_paths();
    $datei = $p['config'];
    /* E5 (1.3.30, Pruefbericht H14/E5): auch eine FEHLENDE Datei und die Paketvorlage (kein Anwenderfeld, kein
     * Aktionstoken - so sieht sie zwischen dem Kopierschritt des Installers und postinstall aus) heilen, wenn die
     * Zweitschrift Inhalt traegt. Bis 1.3.29 heilte nur "kaputt": der erste Seitenaufruf wuerfelte dann ein neues
     * Token, schrieb die Vorgaben und zog die leere Datei als Zweitschrift nach - Keycode, MAC, Xbox-Kennung und
     * das alte Token waren weg (unter 7.4/8.4/8.5 gemessen). Geschrieben wird nur die Zweitschrift selbst. */
    if ($lage === 'fehlt' || ($lage === 'ok' && hk_cfg_ist_vorlage($datei))) {
        if (!hk_cfg_hat_inhalt($p['zweit'])) {
            return array('nichts', '');
        }
        $inhalt = @file_get_contents($p['zweit']);
        if ($inhalt === false || !hk_datei_ersetzen($datei, $inhalt, 0600)) {
            return array(hk_heilung_lage('fehler'), $datei);
        }
        hk_log_gebremst('cfg_geheilt_leer', 'Konfiguration ' . $datei . ($lage === 'fehlt' ? ' fehlte'
            : ' trug weder ein Anwenderfeld noch ein Aktionstoken (Paketvorlage)') . '; sie wurde aus der '
            . 'Zweitschrift ' . basename($p['zweit']) . ' wiederhergestellt.', 'WARNING', 0);
        return array(hk_heilung_lage('geheilt_leer'), $datei);
    }
    if ($lage !== 'kaputt') {
        return array('nichts', '');
    }
    if (!hk_cfg_hat_inhalt($p['zweit'])) {
        hk_log_gebremst('cfg_kaputt_ohne', 'Konfiguration ' . $datei . ' ist beschaedigt '
            . '(nicht lesbar), und es gibt keine heile Zweitschrift. Es wird nichts '
            . 'geschrieben und kein Token erzeugt - bitte die Datei von Hand berichtigen.',
            'ERROR', 3600);
        return array(hk_heilung_lage('ohne_zweitschrift'), $datei);
    }
    $inhalt = @file_get_contents($p['zweit']);
    $kaputt = $datei . '.kaputt';
    if ($inhalt === false || !@rename($datei, $kaputt)) {
        return array(hk_heilung_lage('fehler'), $datei);
    }
    @chmod($kaputt, 0600);
    if (!hk_datei_ersetzen($datei, $inhalt, 0600)) {
        @rename($kaputt, $datei);
        return array(hk_heilung_lage('fehler'), $datei);
    }
    hk_log_gebremst('cfg_geheilt', 'Konfiguration ' . $datei . ' war beschaedigt. Der Stand '
        . 'liegt als ' . basename($kaputt) . ' daneben; die Konfiguration wurde aus der '
        . 'Zweitschrift ' . basename($p['zweit']) . ' wiederhergestellt.', 'WARNING', 0);
    return array(hk_heilung_lage('geheilt'), $kaputt);
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
    // Nur eine LESBARE Datei wird ergaenzt (seit 1.3.15, C4). Bis 1.3.14
    // stand hier "!== 'keine_vorgaben'" - auch eine beschaedigte Datei wurde
    // damit beim Seitenaufbau mit den Vorgaben ueberschrieben.
    if ($fehlten && hk_config_lage() === 'ok') {
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
    // Eine beschaedigte Datei wird nicht ueberschrieben (seit 1.3.15, C4):
    // sie ist Sache von hk_config_heilen(). Sonst ersetzten die Vorgaben
    // den letzten Stand des Anwenders, ohne dass es jemand merkt.
    if (hk_config_lage() === 'kaputt') {
        return false;
    }
    // Ein Wert mit Zeilenumbruch oder Steuerzeichen erzeugte in einer
    // zeilenorientierten Datei eine zweite Zeile oder einen Abschnitt (Befund
    // oberflaeche 2d, "[heimkino]\naktionstoken=..."). Fail closed.
    foreach ($cfg as $abschnitt => $werte) {
        if (!is_array($werte)) {
            continue;
        }
        foreach ($werte as $w) {
            if (!is_scalar($w) || preg_match('/[\x00-\x1F\x7F]/', (string) $w)) {
                return false;
            }
        }
    }
    $datei = hk_paths()['config'];
    $fremd = hk_config_fremde();
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
        // Fremde Schluessel bleiben stehen (Regeln/05, seit 1.3.15).
        if (isset($fremd[$abschnitt])) {
            foreach ($fremd[$abschnitt] as $k => $w) {
                $t .= $k . '=' . $w . "\n";
            }
            unset($fremd[$abschnitt]);
        }
        $t .= "\n";
    }
    foreach ($fremd as $abschnitt => $werte) {
        $t .= '[' . $abschnitt . "]\n";
        foreach ($werte as $k => $w) {
            $t .= $k . '=' . $w . "\n";
        }
        $t .= "\n";
    }
    // 0600 seit 1.3.15 (C9): die Datei traegt Aktionstoken und Keycode des
    // Beamers - Regeln/05 "Wer das Aktionstoken in der Konfiguration fuehrt,
    // fuehrt eine 0600-Datei". Bis 1.3.14 0640 (gemessen, Befund code 10).
    if (!hk_datei_ersetzen($datei, $t, 0600)) {
        return false;
    }
    // Die Zweitschrift zieht mit - aber nur ein Stand MIT Aktionstoken
    // (Regeln/05: "Eine Wache ueberschreibt die Zweitschrift nur, wenn
    // wirklich eine Konfiguration mit Token gespeichert wird").
    // H14 (1.3.30): und nur, wenn der neue Stand mindestens so viele Anwenderfelder (keycode ip mac geraete_id)
    // traegt wie die vorhandene Zweitschrift - dieselbe Zaehlung wie hk_felder in den Hakenskripten. Bis 1.3.29
    // ersetzte ein Stand ohne Felder (fehlende cfg, Paketvorlage) die einzige heile Zweitschrift.
    $zweit = hk_paths()['zweit'];
    if (trim((string) hk_cfg($cfg, 'heimkino', 'aktionstoken', '')) !== ''
        && (!is_file($zweit) || hk_cfg_felder_zahl($t) >= hk_cfg_felder_zahl(@file_get_contents($zweit)))) {
        @hk_datei_ersetzen($zweit, $t, 0600);
    }
    return true;
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
    //
    // Seit 1.3.14 argumentweise nach Regeln/06: GENAU zwei Argumente, das
    // erste ein python-Interpreter, das zweite zeichengenau hk_service.py
    // DIESER Installation (relativ gegen das Arbeitsverzeichnis des
    // Prozesses). Bis 1.3.13 genuegte "eines der ersten drei Argumente heisst
    // hk_service.py" - ein Koeder "python3 <anderer Ordner>/hk_service.py
    // --halten" galt als Dienst (in WSL gemessen, Pruefung-Heimkino-1.3.14,
    // Fall O4; Muster 4 der Nachlese).
    if (!is_readable('/proc/' . $pid . '/cmdline')) {
        return 0;
    }
    $cmd = @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($cmd === false) {
        return 0;
    }
    $teile = array_values(array_filter(explode("\0", $cmd), 'strlen'));
    if (count($teile) !== 2 || !preg_match('/^python(3[0-9.]*)?$/', basename($teile[0]))) {
        return 0;
    }
    $pfad = $teile[1];
    if (substr($pfad, 0, 1) !== '/') {
        $cwd = @readlink('/proc/' . $pid . '/cwd');
        if ($cwd === false) { return 0; }
        $pfad = $cwd . '/' . $pfad;
    }
    $soll = @realpath(hk_paths()['bin'] . '/hk_service.py');
    $ist = @realpath($pfad);
    return ($soll !== false && $ist !== false && $soll === $ist) ? $pid : 0;
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

/**
 * Liegt eine Marke "Aktualisierung laeuft"?
 *
 * preupgrade.sh legt data/plugins/<ordner>.upgrade_laeuft mit der Unixzeit
 * an, postupgrade.sh entfernt sie als Letztes. Solange sie gilt, startet
 * bin/dienst.sh nichts (marke_gilt dort).
 *
 * Gelesen wird hier nur. Die Oberflaeche SPERRT bei liegender Marke nicht:
 * ob gesperrt wird, ist nach Regeln/06 eine Messung, keine Regel. Gemessen
 * (WSL, 18.09.2026, Pruefung-Heimkino-1.3.12, Fall C): ein Seitenaufruf in
 * der Luecke wuerfelt zwar ein Aktionstoken und schreibt es in die
 * Vorgabedatei, aber postupgrade.sh holt Einstellungen und Xbox-Anmeldung
 * ohne Inhaltspruefung aus der Sicherung zurueck - Aktionstoken,
 * Themenpraefix und Erneuerungstoken standen hinterher unveraendert da.
 * Eine Sperre ohne gemessenen Schaden nimmt dem Anwender nur die Seite.
 *
 * Rueckgabe: array(zustand, alter in Sekunden oder null).
 * zustand: 0 = keine Marke, 1 = sie liegt und gilt, 2 = sie liegt, gilt aber
 * nicht (unlesbar, mehr als 300 s aus der Zukunft oder aelter als eine Stunde).
 */
function hk_marke_lage()
{
    $datei = hk_paths()['daten'] . '.upgrade_laeuft';
    if (!is_file($datei)) {
        return array(0, null);
    }
    $roh = trim((string) @file_get_contents($datei));
    if (!preg_match('/^[0-9]+$/', $roh)) {
        return array(2, null);
    }
    $alter = time() - (int) $roh;
    // 300 s Vorlauf wie bin/dienst.sh marke_gilt (seit 1.3.14, Muster 8):
    // eine Marke wenige Minuten "aus der Zukunft" zeugt von einer
    // nachgestellten Uhr. Bis 1.3.13 hiess sie hier "gilt nicht", waehrend
    // der Startweg sie sperren soll (Fall O5).
    return array(($alter >= -300 && $alter < 3600) ? 1 : 2, $alter);
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

/**
 * Ein Python-Programm aus bin/ aufrufen.
 *
 * Seit 1.3.15 (C6) mit einer Gesamtfrist ueber "timeout -k 5": bis 1.3.14
 * lief der Aufruf so lange, wie das Geraet ihn hielt - an einer Attrappe, die
 * ein Byte je Sekunde schickt, 25,7 s bei eingestellter Zeitgrenze 2 s
 * (Befund code 7), und die Anfragen des Miniservers stapelten sich im
 * Webserver. 90 s decken den laengsten regulaeren Weg: Xbox-Befehl mit
 * Token-, Benutzer- und XSTS-Erneuerung (je 15 s) samt Ersatzweg.
 * Ohne timeout (Arbeitsplatz) wird ohne Frist aufgerufen.
 */
function hk_cmd_python($datei, $argumente = array(), $frist = 90)
{
    $bin = hk_paths()['bin'] . '/' . $datei;
    if (!is_readable($bin)) {
        return array(1, hk_tf('FEHLER.BIN_FEHLT', array('%1' => $datei, '%2' => $bin)));
    }
    $vorsatz = '';
    foreach (array('/usr/bin/timeout', '/bin/timeout') as $t) {
        if (@is_executable($t)) {
            $vorsatz = escapeshellarg($t) . ' -k 5 ' . (int) $frist . ' ';
            break;
        }
    }
    $befehl = $vorsatz . escapeshellarg('python3') . ' ' . escapeshellarg($bin) . ' ';
    foreach ((array) $argumente as $a) {
        $befehl .= escapeshellarg($a) . ' ';
    }
    $aus = array();
    $code = 0;
    @exec($befehl . '2>&1', $aus, $code);
    if ($vorsatz !== '' && ($code === 124 || $code === 137)) {
        $aus[] = hk_tf('FEHLER.FRIST_UEBERSCHRITTEN', array('%1' => (string) (int) $frist));
    }
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
                'retain'  => isset($e['retain']) && $e['retain'] === true,
                'text'    => isset($e[$sprache]) ? (string) $e[$sprache]
                             : (isset($e['en']) ? (string) $e['en'] : ''),
                // Kurze Beschriftung fuer den Comment der Vorlage, hoechstens
                // 40 Zeichen (seit 1.3.15, O5): der Comment wird beim Import
                // zum Namen der Kachel (Regeln/07). Bis 1.3.14 stand dort der
                // Satz aus der Spalte Bedeutung, bis zu 123 Zeichen lang.
                'kurz'    => isset($e['kurz_' . $sprache]) ? (string) $e['kurz_' . $sprache]
                             : (isset($e['kurz_en']) ? (string) $e['kurz_en'] : ''),
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
    /* Zwischengespeichert seit 1.3.15 (O8): bis 1.3.14 startete JEDER
     * Seitenaufruf dafuer einen Python-Prozess. Der Speicher gilt, solange
     * lg_beamer.py dieselbe Groesse und Aenderungszeit hat - die Woerter
     * stehen dort und nirgends sonst. Gespeichert wird nur ein Erfolg. */
    $quelle = hk_paths()['bin'] . '/lg_beamer.py';
    $merkmal = is_file($quelle) ? (@filesize($quelle) . ':' . @filemtime($quelle)) : '';
    $speicher = hk_paths()['woerter'];
    if ($merkmal !== '' && is_readable($speicher)) {
        $alt = json_decode((string) @file_get_contents($speicher), true);
        if (is_array($alt) && isset($alt['merkmal'], $alt['woerter'])
            && $alt['merkmal'] === $merkmal && is_array($alt['woerter']) && $alt['woerter']) {
            foreach ($alt['woerter'] as $art => $liste) {
                if (is_array($liste)) {
                    $w[(string) $art] = array_values(array_map('strval', $liste));
                }
            }
            if ($w) {
                return $w;
            }
        }
    }
    list($code, $aus) = hk_cmd_python('lg_beamer.py', array('--woerter'), 20);
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
    if ($w && $merkmal !== '' && is_dir(dirname($speicher))) {
        $js = json_encode(array('merkmal' => $merkmal, 'woerter' => $w));
        if ($js !== false) {
            @hk_datei_ersetzen($speicher, $js, 0640);
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

/**
 * Name des virtuellen Eingangs, den das MQTT-Gateway fuer ein Thema bildet.
 *
 * Das Gateway V2 ersetzt JEDES "/" im ganzen Thema durch "_" (build_vi_name,
 * mqtt_gateway_v2_geraet.py:1081-1083). Bis 1.3.14 wurde nur das Thema
 * umgeformt, nicht das Praefix: mit dem zulaessigen Praefix "haus/kino"
 * trugen 0 von 16 Titeln den Namen des Gateways (Befund mqtt 2). Seit
 * 1.3.15 EINE Stelle fuer Vorlage und Baustein-Liste.
 */
function hk_vi_name($praefix, $thema)
{
    return str_replace('/', '_', (string) $praefix . '/' . (string) $thema);
}

/** Vorlage der Steuerbefehle (Virtueller Ausgang). */
function hk_vo_vorlage($cfg)
{
    $host = hk_hostname();
    $ordner = hk_paths()['plugin'];
    $tok = hk_cfg($cfg, 'heimkino', 'aktionstoken', '');
    $n = hk_vorlagen_namen();
    $titel = hk_vo_titel();
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="' . hk_x($n['vo'][1]) . '" '
        . 'Comment="' . hk_x(hk_t('LOX.VO_KOMMENTAR')) . '" '
        . 'Address="http://' . hk_x($host) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach (hk_aktionen() as $aktion => $schluessel) {
        // Titel fest deutsch (seit 1.3.26, Sprachregel 13.10.2026); [AKTION] nur, falls eine Aktion
        // ohne festen Titel dazukaeme.
        $t = isset($titel[$aktion]) ? $titel[$aktion] : hk_t($schluessel);
        $o .= "\t" . '<VirtualOutCmd Title="' . hk_x($t) . '" Comment="" CmdOnMethod="GET" CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . hk_x(hk_aktionsadresse($cfg, $aktion)) . '" ';
        $o .= 'CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" ';
        $o .= 'Analog="false" Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return array($n['vo'][0], $o);
}

/**
 * Dateiname und Titel der drei Loxone-Vorlagen - EINE Stelle fuer die Erzeuger, den Reiter
 * "Einbindung in Loxone" und die Baustein-Listen (seit 1.3.26, Welle Bild 12 VOLL). Die Titel
 * stehen fest im Code und werden nie uebersetzt (Regeln/07, Sprachregel 13.10.2026): sie werden in
 * Config zu Namen und muessen zum Musterprojekt und zur Baustein-Liste passen.
 */
function hk_vorlagen_namen()
{
    // Gruppe 9 (1.3.30, E8): die Eingangsvorlage VI_Heimkino.xml (VirtualInHttp mit Scheinadresse und
    // polling 604800 - der Kunstgriff, den der Hausstandard fuer Gateway-Werte ausschliesst) entfaellt; die Werte
    // kommen als Virtuelle Eingaenge ueber das MQTT-Gateway (Namenstabelle). Neu die Vorlage der MQTT-Befehle.
    return array(
        'vo' => array('VQ_Heimkino.xml', 'Heimkino steuern (LoxBerry-Plugin)'),
        'bt' => array('VQ_Heimkino_Bild_und_Ton.xml', 'Heimkino Bild und Ton (LoxBerry-Plugin)'),
        'mqtt' => array('VQ_Heimkino_MQTT.xml', 'Heimkino Befehle (MQTT)'),
    );
}

/**
 * Befehlstitel der Vorlage "Heimkino steuern" - fest deutsch (seit 1.3.26, Sprachregel
 * 13.10.2026). Bis 1.3.25 kamen sie aus [AKTION] der Sprachdatei; unter englischer Oberflaeche
 * hiessen die Befehle in Config dann anders als im Musterprojekt und in der Baustein-Liste. Die
 * Texte sind die bisherigen deutschen; die Beschriftungen der Oberflaeche ([AKTION]) bleiben
 * uebersetzt. Schluessel wie hk_aktionen().
 */
function hk_vo_titel()
{
    return array(
        'beamer-aus'       => 'Beamer ausschalten',
        'beamer-wol'       => 'Beamer einschalten (Wake-on-LAN)',
        'beamer-bild-aus'  => 'Bild ausblenden (Gerät bleibt an)',
        'beamer-bild-an'   => 'Bild wieder einblenden',
        'beamer-stumm-an'  => 'Ton stumm schalten',
        'beamer-stumm-aus' => 'Ton wieder einschalten',
        'xbox-an'          => 'Xbox wecken',
        'xbox-aus'         => 'Xbox ausschalten',
        'kino-an'          => 'Kino-Szene: einschalten',
        'kino-aus'         => 'Kino-Szene: ausschalten',
    );
}

/**
 * Befehle der Vorlage "Heimkino Bild und Ton" (seit 1.3.26, Hausherr 13.10.2026): Titel (fest
 * deutsch), Aktion aus hk_aktionen_mit_wert(), Wert aus den Wortlisten von bin/lg_beamer.py
 * (EINGAENGE, BILDMODI); null = Analogbefehl mit dem Wert des Reglers (<v.0>). Reihenfolge wie
 * Musterprojekt/vorlagen/VQ_Heimkino_Bild_und_Ton.xml. Der Endpunkt nahm diese Aktionen schon
 * vorher an - neu ist nur die Vorlage.
 */
function hk_bt_befehle()
{
    return array(
        array('Lautstärke setzen', 'beamer-lautstaerke', null),
        array('Eingang HDMI 1', 'beamer-eingang', 'hdmi1'),
        array('Eingang HDMI 2', 'beamer-eingang', 'hdmi2'),
        array('Eingang HDMI 3', 'beamer-eingang', 'hdmi3'),
        array('Eingang HDMI 4', 'beamer-eingang', 'hdmi4'),
        array('Bildmodus Kino', 'beamer-bildmodus', 'cinema'),
        array('Bildmodus Filmmaker', 'beamer-bildmodus', 'filmMaker'),
        array('Bildmodus Spiel', 'beamer-bildmodus', 'game'),
        array('Bildmodus Normal', 'beamer-bildmodus', 'normal'),
        array('Bildmodus Sport', 'beamer-bildmodus', 'sports'),
        array('Bildmodus Lebhaft', 'beamer-bildmodus', 'vivid'),
        array('Bildmodus Eco', 'beamer-bildmodus', 'eco'),
    );
}

/** Vorlage "Heimkino Bild und Ton" (Virtueller Ausgang, seit 1.3.26). Rueckgabe: array(dateiname, inhalt).
 * Bauart wie hk_vo_vorlage(): eigene Datei, damit eine schon eingelesene Vorlage "Heimkino steuern"
 * beim Neueinlesen kein zweites Geraet ergibt. */
function hk_bt_vorlage($cfg)
{
    $n = hk_vorlagen_namen();
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="' . hk_x($n['bt'][1]) . '" '
        . 'Comment="' . hk_x(hk_t('LOX.BT_KOMMENTAR')) . '" '
        . 'Address="http://' . hk_x(hk_hostname()) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach (hk_bt_befehle() as $b) {
        // Analogbefehl: <v.0> setzt Loxone beim Senden ein; es darf nicht durch rawurlencode laufen.
        $adresse = $b[2] === null
            ? hk_aktionsadresse($cfg, $b[1]) . '&wert=<v.0>'
            : hk_aktionsadresse($cfg, $b[1], $b[2]);
        $o .= "\t" . '<VirtualOutCmd Title="' . hk_x($b[0]) . '" Comment="" CmdOnMethod="GET" CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . hk_x($adresse) . '" ';
        $o .= 'CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" ';
        // Analogbefehl: Skalierung 1:1 ausdruecklich, wie Config sie schreibt (seit 1.3.29,
        // wortgleich mit dem Musterprojekt); die Digitalbefehle bleiben ohne.
        $o .= 'Analog="' . ($b[2] === null ? 'true' : 'false') . '" Repeat="0" RepeatRate="0" '
            . ($b[2] === null ? 'SourceValLow="0" DestValLow="0" SourceValHigh="10" DestValHigh="10" ' : '')
            . 'HintText=""/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return array($n['bt'][0], $o);
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

/**
 * xbox_auth.json lesen, aendern und schreiben - unter EINER Dateisperre
 * (seit 1.3.15, C7).
 *
 * Dienst, Aktionsendpunkt und Oberflaeche schrieben die Datei bis 1.3.14
 * jeweils als Ganzes aus ihrem eigenen, beim Laden gelesenen Stand. Eine
 * gerade gespeicherte App-Registrierung ging dabei verloren, sobald der
 * Dienst waehrenddessen ein Token erneuerte (gemessen, Befund code 8, Fall
 * 2). Die Sperre ist dieselbe Datei, die bin/xbox_cloud.py sperrt
 * (<auth>.lock), und sie wird nur fuer Lesen-Aendern-Schreiben gehalten,
 * nie ueber einen Netzaufruf. Ohne Sperre wird nicht geschrieben.
 */
function hk_xbox_auth_aendern($aendern)
{
    $auth = hk_paths()['auth'];
    $ordner = dirname($auth);
    if (!is_dir($ordner)) {
        @mkdir($ordner, 0755, true);
    }
    $fh = @fopen($auth . '.lock', 'c');
    if ($fh === false) {
        return false;
    }
    if (!@flock($fh, LOCK_EX)) {
        fclose($fh);
        return false;
    }
    $ok = false;
    try {
        $daten = $aendern(hk_xbox_auth_lesen());
        $ok = is_array($daten) ? hk_xbox_auth_schreiben($daten) : false;
    } finally {
        @flock($fh, LOCK_UN);
        fclose($fh);
    }
    return $ok;
}

/** Anwendungskennung hinterlegen, vorhandene Token behalten. */
function hk_xbox_app_speichern($client_id, $client_secret, $rueckleitung = '')
{
    return hk_xbox_auth_aendern(function ($daten) use ($client_id, $client_secret, $rueckleitung) {
        $daten['client_id'] = trim((string) $client_id);
        // Ein leer gelassenes Feld loescht das Geheimnis nicht - sonst waere es
        // nach jedem Speichern der Seite weg.
        if (trim((string) $client_secret) !== '') {
            $daten['client_secret'] = trim((string) $client_secret);
        }
        $daten['redirect_uri'] = trim((string) $rueckleitung) !== ''
            ? trim((string) $rueckleitung) : HK_RUECKLEITUNG;
        return $daten;
    });
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
 * Schreibt der Dienst wirklich in sein Protokoll?
 *
 * Am 06.09.2026 am Geraet gemessen: der Dienst lief sieben Stunden, schrieb
 * seine Zustandsdatei weiter - und es gab keine Protokolldatei. Das
 * Verzeichnis log/plugins liegt auf einer Ramdisk und war geleert worden;
 * der bis dahin benutzte FileHandler oeffnet die Datei einmal beim Start und
 * schreibt danach in einen geloeschten Inode. Sichtbar war davon nichts -
 * genau deshalb steht die Frage jetzt als Zeile im Reiter Test.
 *
 * Die Antwort kommt aus hk_service.py --protokoll - einem EIGENEN Prozess,
 * nicht aus dem laufenden Dienst; er sieht nur die Datei. Eine fehlende Datei
 * kann er deshalb nicht beurteilen: beim WatchedFileHandler entsteht sie erst
 * mit der naechsten Meldung neu (am Geraet geeicht 17.09.2026). Bis 1.3.9
 * stand hier "Gefragt wird der Dienst" - das war nie so.
 */
function hk_protokoll_probe()
{
    list($code, $aus) = hk_cmd_python('hk_service.py', array('--protokoll'));
    $j = $code === 0 ? json_decode($aus, true) : null;
    if (!is_array($j) || !isset($j['zustand'])) {
        return array(2, '');
    }
    return array((int) $j['zustand'], (string) $j['text']);
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

/**
 * Steht der Cron-Eintrag des Waechters? (seit 1.3.15, O4)
 *
 * Gesucht wird an ALLEN Cron-Orten (Regeln/04, Raumklima 0.11.8): glob
 * system/cron/cron.*min/heimkino. Fehlt er an einer Installation, ist das ein
 * roter Befund - ohne den minuetlichen Waechter startet ein abgestuerzter
 * Dienst nie neu. Ohne Installation (Archiv, Pruefstand) nicht feststellbar.
 * Rueckgabe: array(zustand, text) - Text ist der Pfad bzw. die Pfade.
 */
function hk_cron_probe()
{
    $p = hk_paths();
    if ($p['home'] === '' || $p['cron'] === '') {
        return array(2, '');
    }
    $treffer = glob($p['cron'] . '/cron.*min/' . $p['plugin']);
    $dateien = array();
    foreach ((is_array($treffer) ? $treffer : array()) as $t) {
        if (is_file($t)) {
            $dateien[] = $t;
        }
    }
    if (!$dateien) {
        return array(0, $p['cron'] . '/cron.*min/' . $p['plugin']);
    }
    // Ein Eintrag an einem anderen Takt als cron.01min ist ein Rest einer
    // frueheren Fassung: gezeigt, aber nicht rot.
    return array(1, implode(', ', $dateien));
}

/** Sind die erzeugbaren Loxone-Vorlagen wohlgeformt? */
function hk_vorlagen_probe($cfg)
{
    if (!function_exists('simplexml_load_string')) {
        return array(2, '0');
    }
    $zahl = 0;
    foreach (array(hk_vo_vorlage($cfg), hk_bt_vorlage($cfg), hk_vorlage_mqtt_out($cfg)) as $paar) {
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
 * Wertebereich der Vorlage (seit 1.3.24; Regeln/03 "kleinster Sendewert gegen
 * MinVal"): liegt jeder Wert, den der Dienst an einen Eingang der Vorlage senden
 * kann, in [MinVal, MaxVal] - und bekommt ein digitaler Eingang (Analog false)
 * nur 0 oder 1? Ein digitaler Eingang liest jeden Wert ungleich 0 als "Ein";
 * so ging bis 1.3.22 bei abgeschaltetem Beamer beamer/an = -1 hinaus und Loxone
 * meldete "ausserhalb des Wertebereichs".
 *
 * Die Werte liefert der DIENST (hk_service.py --sendewerte): die Platzhalter aus
 * ENTFERNT und, wenn eine Zustandsdatei liegt, die Werte des letzten Durchgangs.
 * Eine zweite, von Hand gefuehrte Liste hier liefe auseinander. Die Grenzen
 * kommen aus hk_themen() - daraus entsteht auch die Vorlage (hk_vorlage).
 * Sendet nichts, schaltet nichts.
 *
 * Rueckgabe: array(zustand, daten). 1: array(werte, eingaenge, zeit des
 * Stands oder 0); 0: Liste von array(thema, wert, min, max, digital, quelle);
 * 2: nicht feststellbar.
 */
function hk_wertebereich_probe()
{
    list($code, $aus) = hk_cmd_python('hk_service.py', array('--sendewerte'));
    $j = $code === 0 ? json_decode($aus, true) : null;
    if (!is_array($j) || !isset($j['entfernt']) || !is_array($j['entfernt'])) {
        return array(2, array());
    }
    $quellen = array();
    foreach ($j['entfernt'] as $werte) {
        if (is_array($werte)) { $quellen[] = array('aus', $werte); }
    }
    $stand = (isset($j['stand']) && is_array($j['stand'])) ? $j['stand'] : null;
    if ($stand !== null) { $quellen[] = array('stand', $stand); }
    $themen = hk_themen();
    $gesehen = array();
    $eingaenge = array();
    $befunde = array();
    foreach ($quellen as $q) {
        foreach ($q[1] as $thema => $wert) {
            $thema = (string) $thema;
            if (!isset($themen[$thema]) || $themen[$thema]['art'] === 'text') { continue; }
            // Wie Melder.sende: ein Wahrheitswert geht als 1/0 hinaus.
            if (is_bool($wert)) { $wert = $wert ? 1 : 0; }
            $text = is_scalar($wert) ? (string) $wert : ($wert === null ? '-' : json_encode($wert));
            if (isset($gesehen[$thema . '=' . $text])) { continue; }
            $gesehen[$thema . '=' . $text] = true;
            $eingaenge[$thema] = true;
            $e = $themen[$thema];
            $digital = ($e['art'] === 'digital');
            $ok = is_int($wert) || is_float($wert) || (is_string($wert) && is_numeric($wert));
            if ($ok) {
                $z = (float) $wert;
                $ok = $z >= $e['min'] && $z <= $e['max']
                      && (!$digital || $z === 0.0 || $z === 1.0);
            }
            if (!$ok) {
                $befunde[] = array($thema, $text, $e['min'], $e['max'], $digital, $q[0]);
            }
        }
    }
    if (!$gesehen) {
        return array(2, array());
    }
    if ($befunde) {
        return array(0, $befunde);
    }
    return array(1, array(count($gesehen), count($eingaenge),
        $stand !== null && isset($j['stand_zeit']) ? (int) $j['stand_zeit'] : 0));
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
    if ($v === '' && hk_paths()['home'] !== '') {
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

    /* 3. Weg, NEU 1.3.9: die plugin.cfg im Auspackordner.
     *
     * Am Pruefstand gemessen (07.09.2026): installiert nennt die Funktion
     * ihre Fassung richtig - im Auspackordner gab sie eine leere
     * Zeichenkette. Dort gibt es keine plugindatabase.json, und einen
     * Dateikandidaten kannte sie nicht. Betroffen ist damit jeder Lauf im
     * Arbeitsordner: Pruefstand, Sicherungsdatei, Freigabenotiz.
     *
     * Umgekehrt gilt weiter: auf einer Installation gibt es die plugin.cfg
     * NICHT - plugininstall.pl liest sie aus dem Auspackordner und loescht
     * sie danach. Deshalb steht dieser Weg an dritter Stelle, nicht an
     * erster. */
    if ($v === '') {
        foreach (array(dirname(dirname(__DIR__)) . '/plugin.cfg',
                       dirname(dirname(dirname(__DIR__))) . '/plugin.cfg') as $k) {
            if (!is_readable($k)) { continue; }
            $roh = (string) @file_get_contents($k);
            if (preg_match('/^\s*VERSION\s*=\s*([^\r\n]+)/mi', $roh, $m)) {
                $v = trim($m[1], " \t\"'");
                break;
            }
        }
    }
    return $v;
}


/* ==================================================================
 * Sicherung und Wertpruefung (seit 1.3.15, C1/C2)
 *
 * Bis 1.3.14 stuerzte "Einstellungen sichern" ab (hk_cfg() mit 0 statt 3
 * Argumenten, HTTP 500 unter 7.4 und 8.5), und das Zurueckspielen nahm jeden
 * Abschnitt unbesehen (Bauart E): Token als Liste -> "aktionstoken=Array",
 * und der Endpunkt nahm danach token=Array an; ein Zeilenumbruch schleuste
 * einen zweiten Abschnitt [heimkino] mit fremdem Token ein; ip mit
 * "; rm -rf /", port 99999 und intervall 1 gingen durch (Befunde code 1/2,
 * oberflaeche 1/2).
 * ================================================================== */

/** Die Schluessel der Xbox-Anmeldung, die in die Sicherung gehoeren. */
function hk_sicherung_anmeldeschluessel()
{
    // Nur, was fuer einen Umzug noetig ist. Zugriffs- und XSTS-Token sind
    // Zwischenstaende; sie entstehen aus dem Erneuerungstoken neu.
    return array('client_id', 'client_secret', 'redirect_uri', 'dienst', 'refresh_token');
}

/**
 * Die Sicherungsdatei: lesbarer Kopf ("_"-Schluessel), alle Einstellungen aus
 * hk_config_read() und die Xbox-Anmeldung (Regeln/05: "Beide Dateien gehoeren
 * in die Sicherung"; xbox_auth.json ist dort ausdruecklich genannt).
 */
function hk_sicherung_bauen()
{
    $aus = array(
        '_hinweis' => hk_t('SET.SICH_KOPF'),
        '_plugin'  => 'heimkino',
        '_fassung' => hk_version(),
        '_stand'   => date('Y-m-d H:i:s'),
    );
    foreach (hk_config_read() as $abschnitt => $werte) {
        $aus[$abschnitt] = $werte;
    }
    $auth = hk_xbox_auth_lesen();
    $anm = array();
    foreach (hk_sicherung_anmeldeschluessel() as $k) {
        $anm[$k] = (isset($auth[$k]) && is_scalar($auth[$k])) ? (string) $auth[$k] : '';
    }
    $aus['xbox_anmeldung'] = $anm;
    return $aus;
}

/** Taugt ein Wert ueberhaupt fuer eine Zeile? Zeichenkette oder Zahl, ohne Steuerzeichen. */
function hk_wert_taugt($v)
{
    if (!is_string($v) && !is_int($v)) {
        return false;
    }
    $s = (string) $v;
    return strlen($s) <= 4096 && preg_match('/[\x00-\x1F\x7F]/', $s) !== 1;
}

/**
 * Ein Wert der Xbox-Anmeldung (xbox_auth.json). EINE Pruefung fuer das
 * Zurueckspielen einer Sicherung und fuer das Formular "Xbox-Anwendung"
 * (Nr. 19, B-Nachzug 01.10.2026): bis 1.3.17 stand sie nur im Zurueckspielen,
 * und das Formular speicherte Kennung und Umleitungs-URI ungeprueft.
 */
function hk_anmeldung_wert_gueltig($schluessel, $wert)
{
    if (!is_string($wert) || !hk_wert_taugt($wert)) {
        return false;
    }
    if ($schluessel === 'dienst') {
        return in_array(strtolower($wert), array('', 'live', 'v2'), true);
    }
    if ($schluessel === 'redirect_uri') {
        return $wert === '' || preg_match('#^https?://[\x21-\x7E]{1,2000}\z#', $wert) === 1;
    }
    if ($schluessel === 'client_id') {
        return $wert === '' || preg_match('/^[A-Za-z0-9{}._-]{1,128}\z/', $wert) === 1;
    }
    return true;
}

/** Ein Datum JJJJ-MM-TT, das es gibt (checkdate, seit 1.3.15, O2). */
function hk_datum_gueltig($s)
{
    if (!is_string($s) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $s, $m)) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/** Eingang oder Bildmodus gegen die Wortliste; ohne Liste nur die Form. */
function hk_wort_gueltig($art, $s)
{
    if ($s === '') {
        return true;
    }
    $w = hk_woerter();
    $liste = isset($w[$art]) ? $w[$art] : array();
    if ($liste) {
        return in_array($s, $liste, true);
    }
    return preg_match('/^[A-Za-z0-9_]{1,32}\z/', $s) === 1;
}

/**
 * Ist ein Wert fuer DIESE Einstellung zulaessig? Dieselbe Positivliste fuer
 * das Formular und das Zurueckspielen (Regeln/05). Jedes Muster endet mit
 * \z, nicht mit $ (Regeln/05). Rueckgabe: array(ok, Wert in Normalform).
 * Ein unbekannter Schluessel faellt geschlossen aus.
 */
function hk_wert_pruefen($abschnitt, $schluessel, $wert)
{
    if (!hk_wert_taugt($wert)) {
        return array(false, '');
    }
    $s = (string) $wert;
    $ganz = function ($s, $min, $max) {
        if (!preg_match('/^-?[0-9]{1,10}\z/', $s)) {
            return array(false, '');
        }
        $n = (int) $s;
        return ($n >= $min && $n <= $max) ? array(true, (string) $n) : array(false, '');
    };
    $ja = function ($ok) use ($s) {
        return $ok ? array(true, $s) : array(false, '');
    };
    switch ($abschnitt . '.' . $schluessel) {
        case 'heimkino.enabled':
        case 'heimkino.mqtt':
        case 'heimkino.nachfassen':
        // Gruppe 9 (1.3.30): HTTP-Endpunkt und die beiden Haken der Befehlsbruecke, je 0/1.
        case 'heimkino.endpunkt_ein':
        case 'heimkino.befehle_mqtt_ein':
        case 'heimkino.befehle_mqtt_steuern':
        case 'beamer.aktiv':
        case 'beamer.zusatzwerte':
        case 'xbox.aktiv':
        case 'szene.aktiv':
        case 'szene.hausereignis':
            return $ja($s === '0' || $s === '1');
        case 'heimkino.intervall':
            return $ganz($s, 10, 3600);
        case 'heimkino.themenpraefix':
            return $ja(preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*\z#', $s) === 1);
        case 'heimkino.aktionstoken':
            // is_string (Regeln/05): ein Feld wurde bis 1.3.14 zu "Array".
            // Leer ist zulaessig und heisst "kein Token gesichert".
            return $ja(is_string($wert) && preg_match('/^[A-Za-z0-9_.-]{0,64}\z/', $s) === 1);
        case 'beamer.ip':
            return $ja($s === '' || preg_match('/^[A-Za-z0-9._-]{1,253}\z/', $s) === 1);
        case 'beamer.mac':
            return $ja($s === '' || preg_match('/^[0-9A-Fa-f]{2}([:.-]?[0-9A-Fa-f]{2}){5}\z/', $s) === 1);
        case 'beamer.keycode':
            return $ja($s === '' || preg_match('/^[A-Z0-9]{8}\z/', $s) === 1);
        case 'beamer.port':
            return $ganz($s, 1, 65535);
        case 'beamer.zeitgrenze':
            return $ganz($s, 1, 60);
        case 'xbox.geraete_id':
            return $ja($s === '' || preg_match('/^[A-Za-z0-9._:-]{1,128}\z/', $s) === 1);
        case 'xbox.geheimnis_ablauf':
            return $ja($s === '' || hk_datum_gueltig($s));
        case 'szene.eingang':
            return $ja(hk_wort_gueltig('eingang', $s));
        case 'szene.bildmodus':
            return $ja(hk_wort_gueltig('bildmodus', $s));
        case 'szene.warten_beamer':
        case 'szene.warten_xbox':
            return $ganz($s, 10, 600);
    }
    return array(false, '');
}

/** Ist ein Feld eine Liste (JSON-Array) statt eines Objekts? */
function hk_ist_liste($a)
{
    return is_array($a) && $a !== array() && array_keys($a) === range(0, count($a) - 1);
}

/** Wie ein abgewiesener Wert beschrieben wird - nie roh (Regeln/03). */
function hk_wert_art($w)
{
    if (is_array($w) || is_object($w)) {
        return hk_t('SET.SICH_ART_FELD');
    }
    if (is_bool($w) || is_null($w) || is_float($w)) {
        return hk_t('SET.SICH_ART_TYP');
    }
    return sprintf(hk_t('SET.SICH_ART_ZEICHEN'), strlen((string) $w));
}

/**
 * Schluessel, die nach 1.3.15 dazukamen (Verbesserungsbau 30.09.2026). Fehlt
 * einer davon in einer Sicherung, ist das eine Sicherung aus einer frueheren
 * Fassung, kein Mangel: der geltende Wert bleibt. Sonst wiese diese Fassung
 * jede Sicherung ihrer Vorgaengerin ab (Fehlerklasse 10, umgekehrt).
 */
function hk_sicherung_spaeter()
{
    // Gruppe 9 (1.3.30, Entwurf 3.7): die drei Haken kamen mit 1.3.30. Fehlt endpunkt_ein in einer Sicherung,
    // setzt hk_sicherung_lesen() es wie der Uebergang in postupgrade.sh (1 mit Aktionstoken, sonst 0); fehlen die
    // beiden Haken der Befehlsbruecke, bleibt der geltende Wert.
    return array('szene.hausereignis', 'heimkino.endpunkt_ein', 'heimkino.befehle_mqtt_ein',
                 'heimkino.befehle_mqtt_steuern');
}

/**
 * Eine Sicherungsdatei pruefen (seit 1.3.15 je Wert, C2).
 *
 * Jeder Abschnitt muss ein Feld sein, jeder Schluessel bekannt und jeder
 * Wert zulaessig (hk_wert_pruefen). Fehlende und fremde Schluessel sind
 * Beanstandungen. ALLE Beanstandungen werden gesammelt; gibt es eine, wird
 * nichts geschrieben. Der lesbare Kopf ("_"-Schluessel) wird uebergangen.
 * Ein leeres Aktionstoken heisst "keins gesichert": das geltende bleibt.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen, Zahl der Werte,
 * Xbox-Anmeldung|null, Token leer?).
 */
function hk_sicherung_lesen($roh, &$namen = null)
{
    $mangel = array();
    // X-3: die Namen der beanstandeten Schluessel, nie ihre Werte.
    $namen = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten) || hk_ist_liste($daten)) {
        return array(null, array(hk_t('SET.SICH_KEIN_JSON')), 0, null, false);
    }
    $vg = hk_vorgaben();
    $neu = hk_config_read();
    $anzahl = 0;
    $auth = null;
    $token_leer = false;
    $fehlend = array();
    $e = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    };
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') {
            continue;
        }
        if ($k === 'xbox_anmeldung') {
            if (!is_array($w) || hk_ist_liste($w)) {
                $mangel[] = sprintf(hk_t('SET.SICH_KEIN_ABSCHNITT'), $e($k));
                continue;
            }
            $a = array();
            foreach ($w as $ak => $av) {
                $ak = (string) $ak;
                if (!in_array($ak, hk_sicherung_anmeldeschluessel(), true)) {
                    $mangel[] = sprintf(hk_t('SET.SICH_FREMD'), $e($k . '.' . $ak));
                    $namen[] = $k . '.' . $ak;
                    continue;
                }
                $ok = hk_anmeldung_wert_gueltig($ak, $av);
                if (!$ok) {
                    $mangel[] = sprintf(hk_t('SET.SICH_WERT'), $e($k . '.' . $ak), hk_wert_art($av));
                    $namen[] = $k . '.' . $ak;
                    continue;
                }
                $a[$ak] = $av;
            }
            foreach (hk_sicherung_anmeldeschluessel() as $pflicht) {
                if (!array_key_exists($pflicht, $w)) {
                    $fehlend[] = $k . '.' . $pflicht;
                }
            }
            $auth = $a;
            continue;
        }
        if (!isset($vg[$k])) {
            $mangel[] = sprintf(hk_t('SET.SICH_FREMD'), $e($k));
            continue;
        }
        if (!is_array($w) || hk_ist_liste($w)) {
            $mangel[] = sprintf(hk_t('SET.SICH_KEIN_ABSCHNITT'), $e($k));
            continue;
        }
        foreach ($w as $sk => $sw) {
            $sk = (string) $sk;
            if (!array_key_exists($sk, $vg[$k])) {
                $mangel[] = sprintf(hk_t('SET.SICH_FREMD'), $e($k . '.' . $sk));
                $namen[] = $k . '.' . $sk;
                continue;
            }
            list($ok, $norm) = hk_wert_pruefen($k, $sk, $sw);
            if (!$ok) {
                $mangel[] = sprintf(hk_t('SET.SICH_WERT'), $e($k . '.' . $sk), hk_wert_art($sw));
                $namen[] = $k . '.' . $sk;
                continue;
            }
            if ($k === 'heimkino' && $sk === 'aktionstoken' && $norm === '') {
                $token_leer = true;
                continue;
            }
            $neu[$k][$sk] = $norm;
            $anzahl++;
        }
        foreach (array_keys($vg[$k]) as $pflicht) {
            if (!array_key_exists($pflicht, $w)
                && !in_array($k . '.' . $pflicht, hk_sicherung_spaeter(), true)) {
                $fehlend[] = $k . '.' . $pflicht;
            }
        }
    }
    foreach (array_keys($vg) as $abschnitt) {
        if (!array_key_exists($abschnitt, $daten)) {
            $fehlend[] = $abschnitt;
        }
    }
    if ($fehlend) {
        $namen = array_merge($namen, $fehlend);
        $mangel[] = sprintf(hk_t('SET.SICH_FEHLEND'), count($fehlend),
            htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8'));
    }
    if ($anzahl === 0 && !$mangel) {
        $mangel[] = hk_t('SET.SICH_LEER');
    }
    // Gruppe 9 (1.3.30, Entwurf 3.7, E3): eine Sicherung aus einer frueheren Fassung kennt endpunkt_ein nicht -
    // dieselbe Regel wie der Uebergang in postupgrade.sh: 1, wenn ein Aktionstoken gesetzt ist, sonst 0. Die
    // beiden Haken der Befehlsbruecke behalten dann ihren geltenden Wert.
    if (!$mangel && (!isset($daten['heimkino']) || !is_array($daten['heimkino'])
                     || !array_key_exists('endpunkt_ein', $daten['heimkino']))) {
        $neu['heimkino']['endpunkt_ein'] = trim((string) hk_cfg($neu, 'heimkino', 'aktionstoken', '')) !== '' ? '1' : '0';
    }
    return array($mangel ? null : $neu, $mangel, $anzahl, $mangel ? null : $auth, $token_leer);
}

/**
 * X-3 (Verbesserungsbau 30.09.2026): Welche gespeicherten Werte bestuenden
 * das eigene Zurueckspielen nicht? Die Sicherung wird gebaut und durch
 * hk_sicherung_lesen() geschickt - dieselbe Pruefung wie beim Zurueckspielen.
 * Rueckgabe: Liste der NAMEN (abschnitt.schluessel), nie der Werte; leer =
 * die Sicherung liesse sich zurueckspielen.
 * Der Name traegt bewusst kein "sicherung": Werkzeuge/sicherung_pruefen.py
 * nimmt die erste Funktion *_sicherung* mit json_encode fuer die Ausfuhr und
 * meldete an dieser Pruefung "baut nicht auf der vollen Konfiguration auf"
 * (gemessen in der Kette am 30.09.2026, Scheinbefund).
 */
function hk_rueckspiel_altwerte($sicherung = null)
{
    $s = is_array($sicherung) ? $sicherung : hk_sicherung_bauen();
    $js = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        // Nicht kodierbar: der Knopf meldet das selbst (SET.SICH_SCHREIBFEHLER).
        return array();
    }
    $namen = array();
    list($neu) = hk_sicherung_lesen($js, $namen);
    if ($neu !== null) {
        return array();
    }
    return $namen ? array_values(array_unique($namen)) : array('?');
}

/**
 * Die Xbox-Anmeldung aus einer Sicherung uebernehmen - unter der Sperre.
 * Sind alle Werte leer, war keine Anmeldung gesichert: die geltende bleibt.
 * Rueckgabe: 'keine' | 'ok' | 'fehler'.
 */
function hk_xbox_anmeldung_uebernehmen($auth)
{
    if (!is_array($auth) || implode('', $auth) === '') {
        return 'keine';
    }
    $ok = hk_xbox_auth_aendern(function ($daten) use ($auth) {
        foreach (hk_sicherung_anmeldeschluessel() as $k) {
            $v = isset($auth[$k]) ? (string) $auth[$k] : '';
            if ($v === '') {
                unset($daten[$k]);
            } else {
                $daten[$k] = $v;
            }
        }
        // Die Zwischenstaende gehoeren zur alten Anmeldung.
        foreach (array('access_token', 'access_bis', 'xsts_token', 'userhash', 'xsts_bis') as $k) {
            unset($daten[$k]);
        }
        return $daten;
    });
    return $ok ? 'ok' : 'fehler';
}

/* ==================================================================
 * Einmalmeldung fuer PRG (seit 1.3.15, O1; Regeln/04 Docker NG, Raumklima)
 *
 * Jeder POST endet mit 303 auf index.php?form=<reiter>; was er zu sagen hat,
 * liegt bis zum naechsten GET in data/plugins/heimkino/einmalmeldung.json
 * (0600, hoechstens 120 s alt). Gelesen wird NUR beim GET, und die Datei wird
 * dabei geloescht, VOR der Anzeige. Zugangsdaten stehen nie darin.
 * ================================================================== */

function hk_einmal_schreiben($daten)
{
    $datei = hk_paths()['einmal'];
    if (!is_dir(dirname($datei))) {
        @mkdir(dirname($datei), 0755, true);
    }
    $daten['zeit'] = time();
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $js !== false && hk_datei_ersetzen($datei, $js, 0600);
}

/* ==================================================================
 * Eingaben nach einer Beanstandung (Verbesserungsbau 30.09.2026, X-2;
 * Regeln/04 "Nach einer Beanstandung stehen die eingetippten Werte wieder
 * im Formular")
 *
 * Nur nach einer Beanstandung, nur das eine Formular und nur seine Felder.
 * Nie Geheimnisse: Keycode, Clientgeheimnis, Code und "neues Token" stehen
 * in keiner Liste und reisen deshalb nie mit.
 * ================================================================== */

/** Die Felder je Formular: array(text => [...], haken => [...]). */
function hk_eingabe_felder($form)
{
    $felder = array(
        'settings' => array(
            'text'  => array('intervall', 'beamer_ip', 'beamer_mac', 'beamer_port',
                             'beamer_zeitgrenze', 'szene_eingang', 'szene_bildmodus',
                             'szene_warten_beamer', 'szene_warten_xbox',
                             'xbox_geraete_id', 'xbox_geheimnis_ablauf'),
            'haken' => array('enabled', 'nachfassen', 'beamer_aktiv', 'beamer_zusatzwerte',
                             'szene_aktiv', 'szene_hausereignis', 'xbox_aktiv'),
        ),
        'mqtt' => array(
            'text'  => array('themenpraefix'),
            // Gruppe 9 (1.3.30): die beiden Haken der Befehlsbruecke stehen im Formular des Reiters MQTT.
            'haken' => array('mqtt', 'befehle_mqtt_ein', 'befehle_mqtt_steuern'),
        ),
        'xbox_app' => array(
            'text'  => array('client_id', 'rueckleitung'),
            'haken' => array(),
        ),
    );
    return isset($felder[$form]) ? $felder[$form] : null;
}

/**
 * Die eingetippten Werte eines Formulars aus $_POST, fuer die Einmalmeldung.
 * Ein Wert, der kein gueltiges UTF-8 ist oder laenger als 256 Byte, reist
 * nicht mit (sonst scheiterte json_encode und mit ihm die Umleitung) - das
 * Feld zeigt dann den gespeicherten Stand.
 */
function hk_eingaben_sammeln($form, $beanstandet)
{
    $f = hk_eingabe_felder($form);
    if ($f === null || !$beanstandet) {
        return null;
    }
    $werte = array();
    foreach ($f['text'] as $feld) {
        if (isset($_POST[$feld]) && is_string($_POST[$feld]) && strlen($_POST[$feld]) <= 256
            && preg_match('//u', $_POST[$feld]) === 1) {
            $werte[$feld] = $_POST[$feld];
        }
    }
    foreach ($f['haken'] as $feld) {
        $werte[$feld] = isset($_POST[$feld]) ? '1' : '';
    }
    return array('form' => $form, 'werte' => $werte,
                 'beanstandet' => array_values(array_unique(array_map('strval', $beanstandet))));
}

/** Die Eingaben aus der Einmalmeldung annehmen (nur bekannte Felder, nur Text). */
function hk_eingaben_setzen($roh = null)
{
    static $ein = array('form' => '', 'werte' => array(), 'beanstandet' => array());
    if ($roh === null) {
        return $ein;
    }
    if (!is_array($roh) || !isset($roh['form']) || !is_string($roh['form'])
        || hk_eingabe_felder($roh['form']) === null) {
        return $ein;
    }
    $f = hk_eingabe_felder($roh['form']);
    $erlaubt = array_merge($f['text'], $f['haken'], array('beamer_keycode', 'client_secret'));
    $werte = array();
    if (isset($roh['werte']) && is_array($roh['werte'])) {
        foreach ($roh['werte'] as $k => $v) {
            if (in_array((string) $k, array_merge($f['text'], $f['haken']), true) && is_string($v)) {
                $werte[(string) $k] = $v;
            }
        }
    }
    $bean = array();
    if (isset($roh['beanstandet']) && is_array($roh['beanstandet'])) {
        foreach ($roh['beanstandet'] as $b) {
            if (is_string($b) && in_array($b, $erlaubt, true)) {
                $bean[] = $b;
            }
        }
    }
    if ($bean) {
        $ein = array('form' => $roh['form'], 'werte' => $werte, 'beanstandet' => $bean);
    }
    return $ein;
}

/** Welches Formular zeigt gerade Eingaben ('' = keines)? */
function hk_eingaben_aktiv()
{
    $ein = hk_eingaben_setzen();
    return $ein['form'];
}

/** Wert eines Textfelds: die Eingabe nach einer Beanstandung, sonst der gespeicherte. */
function hk_eingabe($form, $feld, $gespeichert)
{
    $ein = hk_eingaben_setzen();
    if ($ein['form'] === $form && array_key_exists($feld, $ein['werte'])) {
        return $ein['werte'][$feld];
    }
    return $gespeichert;
}

/** Haken: nach einer Beanstandung der abgeschickte Stand, sonst der gespeicherte. */
function hk_eingabe_an($form, $feld, $gespeichert)
{
    $ein = hk_eingaben_setzen();
    if ($ein['form'] === $form && array_key_exists($feld, $ein['werte'])) {
        return $ein['werte'][$feld] === '1';
    }
    return (bool) $gespeichert;
}

/** Das beanstandete Feld wird rot umrandet (Klasse sm-beanstandet). */
function hk_markierung($feld)
{
    $ein = hk_eingaben_setzen();
    return in_array($feld, $ein['beanstandet'], true)
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}

function hk_einmal_lesen()
{
    $datei = hk_paths()['einmal'];
    if (!is_file($datei)) {
        return array();
    }
    $roh = (string) @file_get_contents($datei);
    @unlink($datei);
    $d = json_decode($roh, true);
    if (!is_array($d) || !isset($d['zeit']) || (time() - (int) $d['zeit']) > 120
        || (time() - (int) $d['zeit']) < -5) {
        return array();
    }
    return $d;
}

/* ==================================================================
 * Protokoll aus PHP, gebremst (seit 1.3.15)
 *
 * Regeln/03: "Wer ein Protokoll anzeigt, muss es auch schreiben - gebremst";
 * die Bremse setzt sich zurueck, wenn die Protokolldatei fehlt. Geschrieben
 * wird nur, wenn der Protokollordner da ist - angelegt wird nichts.
 * ================================================================== */

function hk_log_zeile($text, $stufe = 'INFO')
{
    $datei = hk_paths()['log'];
    if (!is_dir(dirname($datei))) {
        return false;
    }
    clearstatcache(true, $datei);
    if (is_file($datei) && @filesize($datei) > 512000) {
        $rest = @file($datei, FILE_IGNORE_NEW_LINES);
        if (is_array($rest)) {
            @file_put_contents($datei, implode("\n", array_slice($rest, -200)) . "\n", LOCK_EX);
        }
    }
    $zeile = date('Y-m-d H:i:s') . ' ' . str_pad($stufe, 7) . ' '
           . str_replace(array("\r", "\n"), ' ', (string) $text) . "\n";
    return @file_put_contents($datei, $zeile, FILE_APPEND | LOCK_EX) !== false;
}

/**
 * Eine Zeile hoechstens alle $sekunden je Schluessel; unterdrueckte Zeilen
 * werden gezaehlt und mit der naechsten genannt. $sekunden = 0: immer.
 */
function hk_log_gebremst($schluessel, $text, $stufe = 'INFO', $sekunden = 3600)
{
    $p = hk_paths();
    if ($sekunden <= 0 || !is_dir(dirname($p['bremse']))) {
        return hk_log_zeile($text, $stufe);
    }
    $fh = @fopen($p['bremse'], 'c+');
    if ($fh === false) {
        return hk_log_zeile($text, $stufe);
    }
    @flock($fh, LOCK_EX);
    $d = json_decode((string) stream_get_contents($fh), true);
    if (!is_array($d)) {
        $d = array();
    }
    // Ist das Protokoll fort (Ramdisk geleert, gekappt), gilt keine Bremse.
    clearstatcache(true, $p['log']);
    if (!is_file($p['log'])) {
        $d = array();
    }
    $jetzt = time();
    $alt = isset($d[$schluessel]) && is_array($d[$schluessel]) ? $d[$schluessel] : null;
    $schreiben = true;
    if ($alt !== null && ($jetzt - (int) $alt['zeit']) < $sekunden && ($jetzt - (int) $alt['zeit']) >= 0) {
        $d[$schluessel]['still'] = (int) (isset($alt['still']) ? $alt['still'] : 0) + 1;
        $schreiben = false;
    } else {
        $still = $alt !== null && isset($alt['still']) ? (int) $alt['still'] : 0;
        if ($still > 0) {
            $text .= ' (' . $still . ' gleichartige Zeilen seit ' . date('H:i:s', (int) $alt['zeit'])
                   . ' unterdrueckt)';
        }
        $d[$schluessel] = array('zeit' => $jetzt, 'still' => 0);
    }
    // Aeltere Schluessel nicht ewig mitschleppen.
    foreach ($d as $k => $v) {
        if (!is_array($v) || !isset($v['zeit']) || ($jetzt - (int) $v['zeit']) > 86400) {
            unset($d[$k]);
        }
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string) json_encode($d));
    fflush($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);
    return $schreiben ? hk_log_zeile($text, $stufe) : true;
}

/**
 * Jeder Ausgang des Aktionsendpunkts bekommt eine Zeile mit der Adresse des
 * Anrufers (seit 1.3.15, C5; Regeln/03). Nie das Token; eine abgewiesene
 * Aktion nur mit ihrer Laenge. Gebremst je Anrufer, Antwort und Grund auf
 * eine Zeile je Minute.
 */
function hk_endpunkt_protokoll($code, $grund, $aktion = '')
{
    $adr = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $adr = preg_replace('/[^0-9A-Fa-f:.]/', '', $adr);
    if ($adr === '') {
        $adr = '?';
    }
    $akt = '';
    if ($aktion !== '') {
        $bekannt = array_merge(array_keys(hk_aktionen()), array_keys(hk_aktionen_mit_wert()));
        $akt = in_array($aktion, $bekannt, true)
            ? ' aktion=' . $aktion : ' aktion=(' . strlen($aktion) . ' Zeichen, unbekannt)';
    }
    $text = 'Endpunkt: ' . $adr . ' -> HTTP ' . (int) $code . ' ' . $grund . $akt;
    return hk_log_gebremst('ep_' . $adr . '_' . (int) $code . '_' . $grund . '_' . $akt,
                           $text, (int) $code >= 400 ? 'WARNING' : 'INFO', 60);
}

/**
 * Mindestabstand fuer xbox-an/xbox-aus aus dem Endpunkt (seit 1.3.15, C10).
 *
 * Jeder Aufruf ging bis 1.3.14 ungebremst an die Microsoft-Cloud; ein
 * flatternder Ausgang in Loxone loeste jede Sekunde einen Befehl aus
 * (Regeln/03 Paragraph 9, Befund code 12). Ein gemeinsamer Abstand fuer beide
 * Befehle: an und aus im Sekundentakt ist genau das Flattern.
 * Rueckgabe: array(erlaubt, Restsekunden). Ohne Datenordner oder Sperre
 * faellt die Bremse geschlossen aus (array(false, -1)).
 */
function hk_xbox_bremse($abstand = 10)
{
    $datei = hk_paths()['xbox_befehl'];
    if (!is_dir(dirname($datei))) {
        return array(false, -1);
    }
    $fh = @fopen($datei, 'c+');
    if ($fh === false || !@flock($fh, LOCK_EX)) {
        if ($fh !== false) {
            fclose($fh);
        }
        return array(false, -1);
    }
    $d = json_decode((string) stream_get_contents($fh), true);
    $zuletzt = (is_array($d) && isset($d['zeit'])) ? (float) $d['zeit'] : 0.0;
    $jetzt = microtime(true);
    $seit = $jetzt - $zuletzt;
    if ($seit >= 0 && $seit < $abstand) {
        @flock($fh, LOCK_UN);
        fclose($fh);
        return array(false, (int) ceil($abstand - $seit));
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string) json_encode(array('zeit' => $jetzt)));
    fflush($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);
    return array(true, 0);
}

/* ==================================================================
 * Gleichwert-Unterdrueckung des Aktionsendpunkts (X-7, B-Nachzug
 * 01.10.2026, Entscheidung Nr. 19). Vorbild: EVCC 0.9.37
 * (webfrontend/html/index.php, Befehlsbremse) und Marstek 1.1.19.
 *
 * Ein Sollwert-Befehl mit DEMSELBEN Wert geht innerhalb von 60 s nicht
 * erneut hinaus (HTTP 200, UNVERAENDERT=1). Bis 1.3.17 schickte ein
 * flatternder Loxone-Ausgang jeden Aufruf bis zum Beamer. Ein anderer Wert
 * geht sofort hinaus - ein zusaetzliches 429 gibt es nicht.
 * ================================================================== */

/**
 * Merkerschluessel eines Sollwert-Befehls, '' fuer alle anderen.
 *
 * Ein- und Ausschalten teilen sich einen Schluessel: der Sollwert ist der
 * Zustand, nicht der Befehl - beamer-aus nach beamer-wol geht hinaus.
 * Nicht dabei sind beamer-taste und beamer-app (Ereignisse, Nr. 19).
 * Bild/Ton an/aus und die Xbox seit dem Nachtrag vom 01.10.2026; die Xbox
 * behaelt zusaetzlich ihren Mindestabstand (hk_xbox_bremse).
 * beamer-wol ist ausgenommen (Entscheidung Nr. 20): Wake-on-LAN hat keine
 * Rueckmeldung, ein verlorenes Paket muss sofort wiederholt werden koennen.
 * Es leert aber den Merker (hk_gleichwert_betrifft/_nachher).
 */
function hk_gleichwert_schluessel($aktion)
{
    $s = array(
        'beamer-aus'         => 'beamer',
        'kino-an'            => 'kino',
        'kino-aus'           => 'kino',
        'beamer-lautstaerke' => 'lautstaerke',
        'beamer-eingang'     => 'eingang',
        'beamer-bildmodus'   => 'bildmodus',
        'beamer-energie'     => 'energie',
        'beamer-bild-an'     => 'bild',
        'beamer-bild-aus'    => 'bild',
        'beamer-stumm-an'    => 'stumm',
        'beamer-stumm-aus'   => 'stumm',
        'xbox-an'            => 'xbox',
        'xbox-aus'           => 'xbox',
    );
    return isset($s[$aktion]) ? $s[$aktion] : '';
}

/** Der verglichene Wert: bei Befehlen mit Wert der Wert selbst (Gross- und
 * Kleinschreibung zaehlt, wie am Geraet), sonst der Befehl. */
function hk_gleichwert_wert($aktion, $wert)
{
    return (string) $wert !== '' ? (string) $wert : (string) $aktion;
}

/** Befehle, nach denen der Merker nachgefuehrt wird: alle Sollwerte, dazu
 * beamer-taste, beamer-app und beamer-wol (Nr. 20) - sie werden nie
 * unterdrueckt, koennen aber einen gemerkten Sollwert am Geraet veraendern. */
function hk_gleichwert_betrifft($aktion)
{
    return hk_gleichwert_schluessel($aktion) !== ''
        || $aktion === 'beamer-taste' || $aktion === 'beamer-app'
        || $aktion === 'beamer-wol';
}

/**
 * Den Merker oeffnen und sperren. Rueckgabe: Dateizeiger oder false.
 *
 * Die Sperre bleibt waehrend des ganzen Befehls gehalten (wie EVCC): zwei
 * gleichzeitige gleiche Aufrufe senden so nur einmal. Deshalb "e"
 * (close-on-exec): ohne "e" erbte der Python-Prozess aus hk_cmd() die
 * gesperrte Datei (Fehlerklasse 3, "Sperre vererbt sich an Kinder").
 * Ohne Datenordner oder Sperre: false - der Endpunkt faellt dann
 * geschlossen aus (503).
 */
function hk_gleichwert_oeffnen($aktion = '')
{
    // Die Xbox hat ihren eigenen Merker (Nachtrag 01.10.2026).
    $datei = hk_paths()[hk_gleichwert_schluessel($aktion) === 'xbox' ? 'gleichwert_xbox' : 'gleichwert'];
    if (!is_dir(dirname($datei))) {
        return false;
    }
    $fh = @fopen($datei, 'c+e');
    if ($fh === false) {
        return false;
    }
    /* H15 (1.3.30, Pruefbericht, Entwurf 3.11): nicht mehr blockierend. Bis 1.3.29 wartete ein zweiter Aufruf
     * ohne Grenze, bis der erste seinen Befehl beendet hatte - beim Xbox-Merker bis 90 s (Cloud-Aufruf samt
     * Erneuerungen), und jeder Aufruf eines flatternden Ausgangs belegte so lange einen Apache-Worker (gemessen:
     * 5,0 s bei 5 s Haltezeit). Jetzt hoechstens 3 s in Schritten von 100 ms, dann 'belegt' - der Endpunkt
     * antwortet dann sofort, die Befehlsbruecke quittiert BELEGT. Die Sperre selbst bleibt (zwei gleiche Aufrufe
     * senden nur einmal). */
    $ende = microtime(true) + 3.0;
    while (!@flock($fh, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $ende) {
            fclose($fh);
            return 'belegt';
        }
        usleep(100000);
    }
    return $fh;
}

/** Den gesperrten Merker lesen; Unlesbares gilt als leer (dann geht der
 * Befehl hinaus - im Zweifel senden, nie still verschlucken). */
function hk_gleichwert_lesen($fh)
{
    rewind($fh);
    $d = json_decode((string) stream_get_contents($fh), true);
    return is_array($d) ? $d : array();
}

/** Sekunden seit DEMSELBEN Wert, -1 wenn ein anderer Wert gemerkt ist oder
 * der gemerkte aelter als das Fenster ist. */
function hk_gleichwert_seit($merker, $schluessel, $wert, $fenster = 60)
{
    if ($schluessel === '' || !isset($merker[$schluessel]) || !is_array($merker[$schluessel])) {
        return -1;
    }
    $e = $merker[$schluessel];
    if (!isset($e['w'], $e['t']) || (string) $e['w'] !== (string) $wert) {
        return -1;
    }
    $seit = time() - (int) $e['t'];
    return ($seit >= 0 && $seit < $fenster) ? $seit : -1;
}

/**
 * Der Merker nach einem ausgefuehrten Befehl.
 *
 * - Ein/Aus, Kino-Szene und Tasten koennen am Geraet alles aendern (die
 *   Szene setzt Eingang und Bildmodus, eine Taste kann die Lautstaerke
 *   stellen): der ganze Merker verfaellt.
 * - beamer-app wechselt die Quelle: der gemerkte Eingang verfaellt.
 * - Gelungen: der eigene Wert mit Zeit. Gescheitert: der eigene Eintrag
 *   faellt weg - ein Wiederholen geht dann hinaus.
 * - Eintraege ab 60 s werden nicht mitgeschleppt.
 */
function hk_gleichwert_nachher($merker, $aktion, $wert, $gelungen)
{
    if (in_array($aktion, array('beamer-wol', 'beamer-aus', 'kino-an', 'kino-aus', 'beamer-taste'), true)) {
        $merker = array();
    } elseif ($aktion === 'beamer-app') {
        unset($merker['eingang']);
    }
    $jetzt = time();
    foreach ($merker as $k => $e) {
        if (!is_array($e) || !isset($e['t']) || ($jetzt - (int) $e['t']) >= 60 || ($jetzt - (int) $e['t']) < 0) {
            unset($merker[$k]);
        }
    }
    $schl = hk_gleichwert_schluessel($aktion);
    if ($schl !== '') {
        if ($gelungen) {
            $merker[$schl] = array('w' => hk_gleichwert_wert($aktion, $wert), 't' => $jetzt);
        } else {
            unset($merker[$schl]);
        }
    }
    return $merker;
}

/** Den Merker schreiben (ausser bei null), entsperren und schliessen.
 * Erfolg nur bei vollstaendig geschriebenem Inhalt (Fehlerklasse 1). */
function hk_gleichwert_schliessen($fh, $merker)
{
    $ok = true;
    if ($merker !== null) {
        $roh = (string) json_encode($merker);
        $ok = ftruncate($fh, 0) && rewind($fh) && fwrite($fh, $roh) === strlen($roh) && fflush($fh);
        if (!$ok) {
            hk_log_gebremst('gleichwert_schreiben', 'Endpunkt: die Merkerdatei der Gleichwert-Unterdrueckung ('
                . hk_paths()['gleichwert'] . ') liess sich nicht schreiben - ein gleicher Befehl geht dann '
                . 'erneut hinaus. Pruefen: Platz und Eigentuemer (loxberry).', 'WARNING', 3600);
        }
    }
    @flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

/* ==================================================================
 * Gruppe 9 / MQTT (1.3.30): Befehlsbruecke, EINE Logik fuer HTTP und MQTT,
 * Auftragswarteschlange, HTTP-Endpunkt hinter einem Haken, Namenstabelle
 *
 * Bauvorschrift wz_bild/GRUPPE9_BAUVORSCHRIFT.md; Entwurf Musterprojekt/
 * entwurf_gruppe9_hk.txt Abschnitte 2.4, 3.2-3.9; ENTSCHEIDUNGEN_GRUPPE9_
 * HEIMKINO.md (E1-E12). Muster Matter2Lox 0.9.44 (mt_steuern, mt_bb_*).
 * ================================================================== */

/** Anwenderfelder eines .cfg-Textes: keycode, ip, mac, geraete_id mit Wert (je erste Zeile) - dieselbe Zaehlung
 * wie hk_felder() in preupgrade.sh und postupgrade.sh (H14, E5). */
function hk_cfg_felder_zahl($text)
{
    $text = is_string($text) ? $text : '';
    $n = 0;
    foreach (array('keycode', 'ip', 'mac', 'geraete_id') as $f) {
        if (preg_match('/^[ \t]*' . $f . '[ \t]*=[ \t]*(.*)$/m', $text, $m) && preg_replace('/\s+/', '', $m[1]) !== '') {
            $n++;
        }
    }
    return $n;
}

/** Ist eine lesbare .cfg die Paketvorlage - kein Anwenderfeld und kein Aktionstoken (E5)? */
function hk_cfg_ist_vorlage($datei)
{
    if (!is_file($datei) || !is_readable($datei)) {
        return false;
    }
    $g = @parse_ini_file($datei, true, INI_SCANNER_RAW);
    if (!is_array($g)) {
        return false;
    }
    $token = isset($g['heimkino']['aktionstoken']) ? trim((string) $g['heimkino']['aktionstoken']) : '';
    return $token === '' && hk_cfg_felder_zahl((string) @file_get_contents($datei)) === 0;
}

/* ------------------------------------------------------------------
 * HTTP-Endpunkt hinter einem eigenen Haken (Entscheidung 1, E3; Entwurf 3.7)
 * ------------------------------------------------------------------ */

/** Ist der HTTP-Endpunkt fuer Loxone eingeschaltet? (endpunkt_ein; neu 0, Update und Sicherung mit Token 1) */
function hk_endpunkt_an($cfg = null)
{
    if (!is_array($cfg)) {
        $cfg = hk_config_read();
    }
    return hk_an($cfg, 'heimkino', 'endpunkt_ein');
}

/** Merker: wann zuletzt ein schaltender Aufruf mit Token kam (nur die Zeit; legt keinen Ordner an). */
function hk_endpunkt_merken()
{
    $p = hk_paths();
    if (@is_dir($p['daten'])) {
        @file_put_contents($p['endpunkt_zuletzt'], (string) time());
    }
}

/** Alter des letzten schaltenden Aufrufs in Sekunden, -1 = keiner gemerkt. */
function hk_endpunkt_zuletzt()
{
    $f = hk_paths()['endpunkt_zuletzt'];
    $w = @is_file($f) ? trim((string) @file_get_contents($f)) : '';
    return ctype_digit($w) ? max(0, time() - (int) $w) : -1;
}

/* ------------------------------------------------------------------
 * EINE Logik fuer HTTP und MQTT (Entwurf 3.4, E5)
 * ------------------------------------------------------------------ */

/**
 * Eine Aktion des Endpunkts pruefen und ausfuehren - der Rumpf von webfrontend/html/index.php bis 1.3.29
 * (Aktion/Wert, Gleichwert 60 s, Xbox-Bremse 10 s, Merker) ohne $_GET, echo, exit und header. Die Antworten des
 * Endpunkts sind byte-gleich die bis 1.3.29; neu nur BELEGT (H15).
 *
 * $quelle 'http': hk_cmd() wie bisher (direkt, mit Geraetesperre, Ergebniszeile von hk_cmd.py).
 * $quelle 'mqtt': ein Auftrag fuer den Dienst in data/plugins/<ordner>/auftraege/ (E4) - kein HTTP auf sich
 * selbst, kein Kindprozess. Beim Einreihen gilt der Befehl als gelungen (Merker, E5); scheitert er im Dienst oder
 * verfaellt er, nimmt der Dienst genau diesen Eintrag zurueck ('gw' im Auftrag).
 *
 * Rueckgabe array('code' => HTTP-Status, 'text' => Antwortzeile, 'grund' => Grund fuer das Protokoll,
 * 'retry' => Sekunden fuer Retry-After oder null).
 */
function hk_steuern($aktion, $wert = null, $quelle = 'http')
{
    $aktion = is_string($aktion) ? $aktion : '';
    $r = function ($code, $text, $grund, $retry = null) {
        return array('code' => $code, 'text' => $text, 'grund' => $grund, 'retry' => $retry);
    };
    $erlaubt = array_keys(hk_aktionen());
    $mit_wert = array_keys(hk_aktionen_mit_wert());

    /* Erst die Aktion und ihren Wert pruefen, dann die Gleichwert-Unterdrueckung
     * (X-7), dann senden. Bis 1.3.17 wurde im selben Zweig geprueft und gesendet. */
    $hk_wert = '';
    if (in_array($aktion, $erlaubt, true)) {
        $argumente = array($aktion);
    } elseif (in_array($aktion, $mit_wert, true)) {
        $w = is_string($wert) ? $wert : '';
        // Nur Buchstaben, Ziffern und Unterstrich - Gross- und Kleinschreibung bleibt ERHALTEN (filmMaker);
        // \z statt $ seit 1.3.15 (C5): "volumeup%0A" bestand das Muster (Befund code 6).
        if (!preg_match('/^[A-Za-z0-9_]{1,32}\z/', $w)) {
            return $r(400, 'Der Wert enthaelt unerlaubte Zeichen.', 'wert');
        }
        $hk_wert = $w;
        $argumente = array($aktion, $w);
    } else {
        return $r(400, 'Unbekannte Aktion. Erlaubt: '
                       . implode(', ', array_merge($erlaubt, $mit_wert)), 'unbekannt');
    }

    /* Gleichwert-Unterdrueckung (X-7, Entscheidung Nr. 19): derselbe Sollwert innerhalb von 60 s geht nicht
     * erneut hinaus (HTTP 200, UNVERAENDERT=1). Der Merker bleibt waehrend des Befehls gesperrt; laesst er sich
     * nicht oeffnen, faellt es geschlossen aus (503). */
    $schl = hk_gleichwert_schluessel($aktion);
    $gw = null;
    $merker = array();
    if ($schl !== '') {
        $gw = hk_gleichwert_oeffnen($aktion);
        if ($gw === 'belegt') {
            // H15 (1.3.30): nach 3 s ohne Sperre. Die Xbox haelt ihren Merker fuer den ganzen Cloud-Aufruf.
            if ($schl === 'xbox') {
                return $r(200, 'UNVERAENDERT=1;BELEGT=1;AKTION=' . $aktion
                               . ' - derselbe Befehl laeuft gerade; es wurde nichts gesendet.', 'belegt');
            }
            return $r(429, 'Belegt: ein anderer Befehl haelt die Gleichwert-Sperre seit 3 s - der Befehl wurde '
                           . 'nicht gesendet. In 3 s wiederholen.', 'belegt', 3);
        }
        if ($gw === false) {
            return $r(503, 'Die Gleichwert-Sperre ist nicht verfuegbar (Datenordner oder Merkerdatei '
                           . 'fehlt) - es wird nichts gesendet.', 'gleichwert_fehlt');
        }
        $merker = hk_gleichwert_lesen($gw);
        $seit = hk_gleichwert_seit($merker, $schl, hk_gleichwert_wert($aktion, $hk_wert));
        if ($seit >= 0) {
            hk_gleichwert_schliessen($gw, null);
            return $r(200, 'UNVERAENDERT=1;AKTION=' . $aktion . ($hk_wert !== '' ? ';WERT=' . $hk_wert : '')
                           . ';SEIT_S=' . $seit . ' - derselbe Befehl ging vor ' . $seit
                           . ' s hinaus; es wurde nichts gesendet.', 'unveraendert');
        }
    }

    /* Mindestabstand fuer die Xbox (seit 1.3.15, C10): 10 s fuer beide Befehle zusammen, hinter der
     * Gleichwert-Unterdrueckung (Nachtrag 01.10.2026). Ohne Datenordner faellt die Bremse geschlossen aus. */
    if ($aktion === 'xbox-an' || $aktion === 'xbox-aus') {
        list($hk_frei, $hk_rest) = hk_xbox_bremse(10);
        if (!$hk_frei) {
            if (is_resource($gw)) {
                hk_gleichwert_schliessen($gw, null);
            }
            if ($hk_rest < 0) {
                return $r(503, 'Die Befehlsbremse fuer die Xbox ist nicht verfuegbar '
                               . '(Datenordner fehlt) - es wird nichts gesendet.', 'bremse_fehlt');
            }
            return $r(429, 'Zu schnell: xbox-an und xbox-aus hoechstens alle 10 s. Noch '
                           . (int) $hk_rest . ' s warten - der Befehl wurde nicht gesendet.', 'bremse', (int) $hk_rest);
        }
    }

    if ($quelle === 'mqtt') {
        // E4/E6: eingereiht, ausgefuehrt im Hauptfaden des Dienstes; Verfall 30 s (Befehl) bzw. 60 s (Szene).
        $szene = ($aktion === 'kino-an' || $aktion === 'kino-aus');
        $auftrag = array('art' => $szene ? 'szene' : 'befehl', 'aktion' => $aktion, 'wert' => $hk_wert,
                         'quelle' => 'mqtt', 'verfall_s' => $szene ? 60 : 30);
        if ($schl !== '') {
            $auftrag['gw'] = array('datei' => $schl === 'xbox' ? 'gleichwert_xbox' : 'gleichwert',
                                   'schluessel' => $schl, 'wert' => hk_gleichwert_wert($aktion, $hk_wert));
        }
        $name = hk_auftrag_einreihen($auftrag);
        $code = $name !== '' ? 0 : 1;
        $ausgabe = $name !== '' ? 'OK=2 eingereiht' : 'Der Auftrag liess sich nicht ablegen.';
    } else {
        list($code, $ausgabe) = hk_cmd($argumente);
    }

    /* Merker nachfuehren. Taste und Anwendung sperren erst jetzt - sie werden nie
     * unterdrueckt; laesst sich der Merker dann nicht oeffnen, bleibt es still,
     * denn ohne Merker fallen die Sollwerte ohnehin geschlossen aus. */
    if ($gw === null && hk_gleichwert_betrifft($aktion)) {
        $gw = hk_gleichwert_oeffnen();
        $merker = is_resource($gw) ? hk_gleichwert_lesen($gw) : array();
    }
    if (is_resource($gw)) {
        hk_gleichwert_schliessen($gw, hk_gleichwert_nachher($merker, $aktion, $hk_wert, $code === 0));
    }
    /* Die Kino-Szene schaltet auch die Xbox: danach verfaellt deren Merker
     * (Nachtrag). Laesst er sich nicht oeffnen, bleibt es still. */
    if ($aktion === 'kino-an' || $aktion === 'kino-aus') {
        $hk_gx = hk_gleichwert_oeffnen('xbox-an');
        if (is_resource($hk_gx)) {
            hk_gleichwert_schliessen($hk_gx, array());
        }
    }

    if ($code === 0) {
        return $r(200, trim($ausgabe) !== '' ? trim($ausgabe) : 'OK', $quelle === 'mqtt' ? 'eingereiht' : 'ausgefuehrt');
    }
    return $r(500, trim($ausgabe) !== '' ? trim($ausgabe) : 'Fehler', 'fehlgeschlagen');
}

/**
 * Einen Auftrag fuer den Dienst ablegen (Entwurf 3.4, E4): data/plugins/<ordner>/auftraege/<sek10><usek6>_<hex8>.json,
 * atomar ueber die Nebendatei ".<name>.tmp" (0640) und rename; der Ordner 0750. Dieselbe Form schreibt
 * hk_common.auftrag_einreihen() in Python (hk_cmd.py: Szene und Nachfassen ueber HTTP). Der Dienst nimmt nur Namen
 * dieser Form (KENNUNG). Rueckgabe: Dateiname oder '' (nichts abgelegt).
 */
function hk_auftrag_einreihen(array $a)
{
    $p = hk_paths();
    if ($p['home'] === '' || !is_dir($p['daten'])) {
        return '';
    }
    $ordner = $p['auftraege'];
    if (!is_dir($ordner)) {
        @mkdir($ordner, 0750);
        @chmod($ordner, 0750);
    }
    if (!is_dir($ordner)) {
        return '';
    }
    $jetzt = microtime(true);
    $sek = (int) floor($jetzt);
    $us = min(999999, (int) round(($jetzt - $sek) * 1000000));
    try {
        $hex = bin2hex(random_bytes(4));
    } catch (Exception $e) {
        $hex = sprintf('%08x', mt_rand(0, 0x7fffffff));
    } catch (Error $e) {
        $hex = sprintf('%08x', mt_rand(0, 0x7fffffff));
    }
    $name = sprintf('%010d%06d_%s.json', $sek, $us, $hex);
    $a['gestellt'] = $jetzt;
    $js = json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($js === false) {
        return '';
    }
    $tmp = $ordner . '/.' . $name . '.tmp';
    $fh = @fopen($tmp, 'x');
    if ($fh === false) {
        return '';
    }
    @chmod($tmp, 0640);
    $n = @fwrite($fh, $js . "\n");
    @fclose($fh);
    if ($n !== strlen($js) + 1 || !@rename($tmp, $ordner . '/' . $name)) {
        @unlink($tmp);
        return '';
    }
    return $name;
}

/* ------------------------------------------------------------------
 * Befehlsbruecke (Entwurf 3.3-3.9)
 * ------------------------------------------------------------------ */

/**
 * Die Auswahlwerte der Bruecke (F3): je Art klein -> Geraeteschreibweise. Die Bruecke schreibt 'auswahl' klein und
 * laesst in 'werte' nur a-z, 0-9 und _ zu; das Geraet erwartet "filmMaker" (lg_beamer._aus_liste haelt Gross- und
 * Kleinschreibung). Die Woerter sind die von bin/lg_beamer.py (EINGAENGE, BILDMODI, ENERGIESPAREN); die
 * Pruefzeile "Auswahlwerte der Befehlsbruecke" im Reiter Test haelt sie zeichengenau gegen lg_beamer.py --woerter.
 */
function hk_bb_woerter_geraet()
{
    return array(
        'eingang' => array('dtv', 'atv', 'cadtv', 'catv', 'avav1', 'component1', 'hdmi1', 'hdmi2', 'hdmi3', 'hdmi4'),
        'bildmodus' => array('cinema', 'eco', 'filmMaker', 'game', 'normal', 'sports', 'vivid'),
        'energiesparen' => array('auto', 'screenoff', 'maximum', 'medium', 'minimum', 'off'),
    );
}

function hk_bb_auswahl()
{
    $aus = array();
    foreach (hk_bb_woerter_geraet() as $art => $liste) {
        foreach ($liste as $w) {
            $aus[$art][strtolower($w)] = $w;
        }
    }
    return $aus;
}

/**
 * Einstellungen fuer die Zuordnung: Praefix aus [heimkino] themenpraefix; annehmen = "Befehle ueber MQTT annehmen"
 * (befehle_mqtt_ein); eingreifend = "Eingreifende Befehle ueber MQTT zulassen" (befehle_mqtt_steuern, E2);
 * mqtt_ein = "Zustand per MQTT melden" (status/befehle nur dann). Liest nur, schreibt nie.
 */
function hk_bb_einstellungen()
{
    $c = hk_config_read();
    return array('praefix' => hk_cfg($c, 'heimkino', 'themenpraefix', 'heimkino'),
                 'annehmen' => hk_an($c, 'heimkino', 'befehle_mqtt_ein'),
                 'eingreifend' => hk_an($c, 'heimkino', 'befehle_mqtt_steuern'),
                 'mqtt_ein' => hk_an($c, 'heimkino', 'mqtt'));
}

/** Protokoll der Befehlsbruecke: die Logdatei des Plugins (hk_log_zeile), mit Stufe. */
function hk_bb_protokoll($stufe, $text)
{
    hk_log_zeile((string) $text, (string) $stufe);
}

/**
 * Ein Befehl der Befehlsbruecke (tun() in bin/befehle_zuordnung.php): Name und Wert -> Aktion und Wert (Entwurf 3.2,
 * Umschrift 3.3), dann die Vorpruefungen, die nur MQTT betreffen - ueber MQTT fuehrt NUR der Dienst aus; ohne ihn
 * laege der Befehl bis zum Verfall und wirkte ueberraschend spaet (E6). Dann hk_steuern(..., 'mqtt').
 * Rueckgabe array(bool ok, string grund) fuer die Quittung.
 */
function hk_bb_tun($name, $wert)
{
    $name = (string) $name;
    $wert = (string) $wert;
    $einfach = array('beamer/aus' => 'beamer-aus', 'beamer/wol' => 'beamer-wol', 'xbox/an' => 'xbox-an',
                     'xbox/aus' => 'xbox-aus');
    $mit_wert = array('beamer/lautstaerke' => 'beamer-lautstaerke', 'beamer/eingang' => 'beamer-eingang',
                      'beamer/bildmodus' => 'beamer-bildmodus', 'beamer/energie' => 'beamer-energie',
                      'beamer/taste' => 'beamer-taste', 'beamer/app' => 'beamer-app');
    $auswahl = array('beamer/eingang' => 'eingang', 'beamer/bildmodus' => 'bildmodus', 'beamer/energie' => 'energiesparen');
    $arg = null;
    if ($name === 'kino') {
        $aktion = $wert === '1' ? 'kino-an' : 'kino-aus';
    } elseif ($name === 'beamer/bild') {
        $aktion = $wert === '1' ? 'beamer-bild-an' : 'beamer-bild-aus';
    } elseif ($name === 'beamer/stumm') {
        // 1 = stumm, wie das Wertethema beamer/stumm.
        $aktion = $wert === '1' ? 'beamer-stumm-an' : 'beamer-stumm-aus';
    } elseif (isset($einfach[$name])) {
        $aktion = $einfach[$name];
    } elseif (isset($mit_wert[$name])) {
        $aktion = $mit_wert[$name];
        $arg = $wert;
        if (isset($auswahl[$name])) {
            $tab = hk_bb_auswahl();
            $tab = isset($tab[$auswahl[$name]]) ? $tab[$auswahl[$name]] : array();
            if (!isset($tab[$wert])) {
                return array(false, 'WERT');
            }
            $arg = $tab[$wert];
        }
    } else {
        return array(false, 'UNBEKANNT');
    }
    $cfg = hk_config_read();
    if (!hk_an($cfg, 'heimkino', 'enabled')) {
        return array(false, 'ABGESCHALTET');
    }
    $marke = hk_marke_lage();
    if ($marke[0] === 1) {
        return array(false, 'AKTUALISIERUNG');
    }
    if (hk_dienst_pid() === 0) {
        return array(false, 'DIENST_LAEUFT_NICHT');
    }
    if (strpos($aktion, 'kino-') === 0 && !hk_an($cfg, 'szene', 'aktiv')) {
        return array(false, 'SZENE_AUS');
    }
    if ((strpos($aktion, 'beamer-') === 0 && !hk_an($cfg, 'beamer', 'aktiv'))
        || (strpos($aktion, 'xbox-') === 0 && !hk_an($cfg, 'xbox', 'aktiv'))) {
        return array(false, 'GERAET_AUS');
    }
    $e = hk_steuern($aktion, $arg, 'mqtt');
    switch ($e['grund']) {
        case 'eingereiht':
            return array(true, '');
        case 'unveraendert':
            hk_log_gebremst('bb_unv_' . $aktion, 'Befehlsbruecke: ' . $aktion . ($arg !== null ? ' ' . $arg : '')
                . ' unveraendert - derselbe Befehl ging binnen 60 s schon hinaus, es wurde nichts eingereiht.', 'INFO', 60);
            return array(true, '');
        case 'belegt':
            return array(false, 'BELEGT');
        case 'bremse':
            return array(false, 'BREMSE');
        case 'wert':
        case 'unbekannt':
            return array(false, 'WERT');
        case 'gleichwert_fehlt':
        case 'bremse_fehlt':
            return array(false, 'MERKER_FEHLT');
    }
    return array(false, 'AUFTRAG_FEHLT');
}

/** Wo liegt eine Datei unter bin/? Installiert bin/plugins/<ordner>/, im Archiv <wurzel>/bin/. '' = fehlt. */
function hk_bin_datei($name)
{
    foreach (array(hk_paths()['bin'] . '/' . $name, dirname(dirname(__DIR__)) . '/bin/' . $name) as $k) {
        if (@is_file($k)) {
            return $k;
        }
    }
    return '';
}

/** Die Bruecke als Bibliothek laden (BB_NUR_LADEN: kein Hauptteil). */
function hk_bruecke_laden()
{
    if (function_exists('bb_waechter')) {
        return true;
    }
    $f = hk_bin_datei('befehlsbruecke.php');
    if ($f === '') {
        return false;
    }
    if (!defined('BB_NUR_LADEN')) {
        define('BB_NUR_LADEN', true);
    }
    require_once $f;
    return function_exists('bb_waechter');
}

/** Die geladene und gepruefte Zuordnung oder null. */
function hk_bb_zuordnung()
{
    if (!hk_bruecke_laden()) {
        return null;
    }
    list($z) = bb_zuordnung_laden(hk_bin_datei('befehle_zuordnung.php'));
    if ($z === null) {
        return null;
    }
    list($f) = bb_zuordnung_pruefen($z);
    return $f ? null : $z;
}

/** Die Befehlsliste der Bruecke (bb_liste(): EINE Quelle fuer Reiter und Vorlage) oder null. */
function hk_bb_liste()
{
    $z = hk_bb_zuordnung();
    return $z === null ? null : bb_liste($z, bb_einstellungen($z));
}

/**
 * Direktstart bzw. -stopp der Bruecke beim Speichern im Reiter MQTT (Entwurf 3.9, Bauart mt/ic/cc):
 * befehlsbruecke.sh start startet nur nach --soll, nie als root, nie waehrend eines Updates, nie doppelt; der
 * Waechter in cron.01min startet hoechstens alle 600 s neu. $neustart: Praefix gewechselt (stop, dann start).
 * Rueckgabe array(''|'ein'|'aus'|'start_fehl'|'stop_fehl', Ausgabe).
 */
function hk_bruecke_schalten($neustart = false)
{
    if (DIRECTORY_SEPARATOR === '\\' || hk_paths()['home'] === '' || !hk_bruecke_laden()) {
        return array('', '');
    }
    $p = bb_pfade();
    if (!$p) {
        return array('', '');
    }
    $e = hk_bb_einstellungen();
    $laeuft = (bool) bb_dienste();
    if ($e['annehmen']) {
        if ($laeuft && !$neustart) {
            return array('', '');
        }
        if ($laeuft) {
            bb_sh($p, 'stop');
        }
        list($rc, $aus) = bb_sh($p, 'start');
        return array(($rc === 0 && bb_dienste()) ? 'ein' : 'start_fehl', $aus);
    }
    if (!$laeuft) {
        return array('', '');
    }
    list($rc, $aus) = bb_sh($p, 'stop');
    return array(($rc === 0 && !bb_dienste()) ? 'aus' : 'stop_fehl', $aus);
}

/** Ein Satz zum Ergebnis von hk_bruecke_schalten() ('' = nichts zu sagen), maskiert. */
function hk_bruecke_satz(array $r)
{
    $a = $r[1] !== '' ? $r[1] : '-';
    switch ($r[0]) {
        case 'ein':
            return hk_e(sprintf(hk_t('BEFEHL.H_GESTARTET'), $a));
        case 'aus':
            return hk_e(sprintf(hk_t('BEFEHL.H_ANGEHALTEN'), $a));
        case 'start_fehl':
            return hk_e(sprintf(hk_t('BEFEHL.H_START_FEHL'), $a));
        case 'stop_fehl':
            return hk_e(sprintf(hk_t('BEFEHL.H_STOP_FEHL'), $a));
    }
    return '';
}

/* ------------------------------------------------------------------
 * Die 22 Befehle des Musterprojekts (Entwurf 3.8, Abschnitt 6) - EINE Tabelle fuer die Vorlage MQTT-Befehle, die
 * Befehlstabelle im Reiter Einbindung und die Baustein-Liste. Titel fest deutsch "Heimkino: ..." (Sprachregel
 * 13.10.2026): mit Doppelpunkt und Leerzeichen gleicht er nie einem Gateway-Namen (heimkino_...).
 * Je Zeile: titel, name (Brueckenname), wert (Nutzlast; '<v.0>' beim Analogbefehl), analog, kachel (Comment).
 * ------------------------------------------------------------------ */
function hk_mqtt_befehle()
{
    $z = function ($titel, $name, $wert, $kachel, $analog = false) {
        return array('titel' => 'Heimkino: ' . $titel, 'name' => $name, 'wert' => $wert, 'analog' => $analog,
                     'kachel' => 'Heimkino: ' . $kachel);
    };
    return array(
        $z('Kino-Szene einschalten', 'kino', '1', 'Kino an'),
        $z('Kino-Szene ausschalten', 'kino', '0', 'Kino aus'),
        $z('Beamer einschalten (Wake-on-LAN)', 'beamer/wol', '1', 'Beamer wecken'),
        $z('Beamer ausschalten', 'beamer/aus', '1', 'Beamer aus'),
        $z("Bild ausblenden (Ger\xc3\xa4t bleibt an)", 'beamer/bild', '0', 'Bild aus'),
        $z('Bild wieder einblenden', 'beamer/bild', '1', 'Bild an'),
        $z('Ton stumm schalten', 'beamer/stumm', '1', 'Ton stumm'),
        $z('Ton wieder einschalten', 'beamer/stumm', '0', 'Ton an'),
        $z('Xbox wecken', 'xbox/an', '1', 'Xbox an'),
        $z('Xbox ausschalten', 'xbox/aus', '1', 'Xbox aus'),
        $z("Lautst\xc3\xa4rke setzen", 'beamer/lautstaerke', '<v.0>', "Lautst\xc3\xa4rke", true),
        $z('Eingang HDMI 1', 'beamer/eingang', 'hdmi1', 'HDMI 1'),
        $z('Eingang HDMI 2', 'beamer/eingang', 'hdmi2', 'HDMI 2'),
        $z('Eingang HDMI 3', 'beamer/eingang', 'hdmi3', 'HDMI 3'),
        $z('Eingang HDMI 4', 'beamer/eingang', 'hdmi4', 'HDMI 4'),
        $z('Bildmodus Kino', 'beamer/bildmodus', 'cinema', 'Bild Kino'),
        $z('Bildmodus Filmmaker', 'beamer/bildmodus', 'filmmaker', 'Bild Filmmaker'),
        $z('Bildmodus Spiel', 'beamer/bildmodus', 'game', 'Bild Spiel'),
        $z('Bildmodus Normal', 'beamer/bildmodus', 'normal', 'Bild Normal'),
        $z('Bildmodus Sport', 'beamer/bildmodus', 'sports', 'Bild Sport'),
        $z('Bildmodus Lebhaft', 'beamer/bildmodus', 'vivid', 'Bild Lebhaft'),
        $z('Bildmodus Eco', 'beamer/bildmodus', 'eco', 'Bild Eco'),
    );
}

/** Der Befehl, den ein Ausgang der Vorlage MQTT-Befehle sendet: "publish <praefix>/befehl/<name> <wert>". */
function hk_mqtt_befehl_text($praefix, $name, $wert)
{
    return 'publish ' . trim((string) $praefix, '/') . '/befehl/' . $name . ' ' . $wert;
}

/** Ist ein Brueckenname eingreifend? Aus der Zuordnung (bb_liste); ohne Bruecke nach Entwurf 3.2 (E2). */
function hk_mqtt_eingreifend($name)
{
    static $ein = null;
    if ($ein === null) {
        $ein = array();
        $l = hk_bb_liste();
        if (is_array($l)) {
            foreach ($l['befehle'] as $b) {
                $ein[$b['name']] = !empty($b['eingreifend']);
            }
        } else {
            foreach (array('kino', 'beamer/aus', 'beamer/wol', 'xbox/an', 'xbox/aus', 'beamer/taste') as $n) {
                $ein[$n] = true;
            }
        }
    }
    return !empty($ein[$name]);
}

/** Port des UDP-Eingangs des MQTT-Gateways aus general.json (Vorgabe 11884). */
function hk_udp_port()
{
    $f = hk_paths()['general'];
    $j = is_readable($f) ? json_decode((string) @file_get_contents($f), true) : null;
    $m = is_array($j) ? (isset($j['Mqtt']) ? $j['Mqtt'] : (isset($j['mqtt']) ? $j['mqtt'] : null)) : null;
    $port = is_array($m) ? (isset($m['Udpinport']) ? $m['Udpinport'] : (isset($m['udpinport']) ? $m['udpinport'] : 0)) : 0;
    $port = (int) $port;
    return ($port > 0 && $port < 65536) ? $port : 11884;
}

/** Der Rechner fuer die UDP-Adresse der Vorlage: hk_hostname() ohne den Webserver-Port. */
function hk_udp_host()
{
    $h = hk_hostname();
    return substr_count($h, ':') === 1 ? (string) preg_replace('/:[0-9]+\z/', '', $h) : $h;
}

/**
 * Vorlage "MQTT-Befehle" (Entwurf 3.8): ein Virtueller Ausgang an /dev/udp/<LoxBerry>/<UDP-Eingang des Gateways>, je
 * Zeile aus hk_mqtt_befehle() ein Befehl bei EIN "publish <praefix>/befehl/<name> <wert>" (nie retain). Lautstaerke
 * als Analogbefehl mit <v.0> und Skalierung 1:1 wie die Vorlage Bild und Ton. Rueckgabe array(Dateiname, Inhalt).
 */
function hk_vorlage_mqtt_out($cfg)
{
    $praefix = hk_cfg($cfg, 'heimkino', 'themenpraefix', 'heimkino');
    $n = hk_vorlagen_namen();
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="' . hk_x($n['mqtt'][1]) . '" '
        . 'Comment="' . hk_x(hk_t('BEFEHL.V_KOMMENTAR')) . '" '
        . 'Address="' . hk_x('/dev/udp/' . hk_udp_host() . '/' . hk_udp_port()) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach (hk_mqtt_befehle() as $b) {
        $o .= "\t" . '<VirtualOutCmd Title="' . hk_x($b['titel']) . '" Comment="' . hk_x($b['kachel']) . '" '
            . 'CmdOnMethod="GET" CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . hk_x(hk_mqtt_befehl_text($praefix, $b['name'], $b['wert'])) . '" ';
        $o .= 'CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" ';
        $o .= 'Analog="' . ($b['analog'] ? 'true' : 'false') . '" Repeat="0" RepeatRate="0" '
            . ($b['analog'] ? 'SourceValLow="0" DestValLow="0" SourceValHigh="10" DestValHigh="10" ' : '')
            . 'HintText=""/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return array($n['mqtt'][0], $o);
}

/* ------------------------------------------------------------------
 * Namenstabelle und V2-Hakenliste (Entwurf 2.4, E8)
 * ------------------------------------------------------------------ */

/** V2-Hakenliste des Musterprojekts (Praefix heimkino, 28 Haken, 4 Noncached): Thema relativ zum Praefix => N|''. */
function hk_mqtt_hakenliste_muster()
{
    return array(
        'status/ok' => 'N', 'status/zaehler' => '', 'beamer/an' => '', 'beamer/aktiv' => '',
        'beamer/erreichbar' => 'N', 'beamer/lautstaerke' => '', 'beamer/stumm' => '', 'beamer/betriebsstunden' => '',
        'beamer/laufzeit_heute' => '', 'xbox/an' => '', 'xbox/aktiv' => '', 'xbox/angemeldet' => 'N',
        'xbox/erreichbar' => 'N', 'xbox/betriebsstunden' => '', 'xbox/laufzeit_heute' => '', 'xbox/geheimnis_tage' => '',
        'xbox/geheimnis_datum_bekannt' => '', 'szene/laeuft' => '',
        'last_error' => '', 'beamer/grund' => '', 'beamer/app' => '', 'beamer/letzte_aktion' => '', 'xbox/status' => '',
        'xbox/name' => '', 'xbox/letzte_aktion' => '', 'xbox/geheimnis_ablauf' => '', 'szene/schritt' => '',
        'szene/ergebnis' => '',
    );
}

/**
 * Die Namenstabelle - EINE Quelle fuer Reiter Einbindung, Reiter MQTT und die Pruefzeile. Je Zeile: thema (relativ
 * zum Praefix), voll, name (Gateway-Name, hk_vi_name), art (zahl|text), retained, v2 (N|''), muster (steht so im
 * Musterprojekt), bedeutung. Aus bin/hk_themen.json (die drei status/-Themen des Herzschlags stehen dort seit
 * 1.3.30) und dem Lebenszeichen der Bruecke status/befehle.
 */
function hk_mqtt_namen($cfg = null)
{
    if (!is_array($cfg)) {
        $cfg = hk_config_read();
    }
    $p = hk_cfg($cfg, 'heimkino', 'themenpraefix', 'heimkino');
    $muster = hk_mqtt_hakenliste_muster();
    $zeilen = array();
    foreach (hk_themen() as $thema => $e) {
        $zeilen[] = array($thema, $e['art'] === 'text' ? 'text' : 'zahl', $e['retain'], $e['text']);
    }
    $zeilen[] = array('status/befehle', 'zahl', false, hk_t('BEFEHL.B_STATUS_BEFEHLE'));
    $aus = array();
    foreach ($zeilen as $z) {
        $aus[] = array('thema' => $z[0], 'voll' => trim($p, '/') . '/' . $z[0], 'name' => hk_vi_name($p, $z[0]),
                       'art' => $z[1], 'retained' => (bool) $z[2],
                       'v2' => isset($muster[$z[0]]) ? $muster[$z[0]] : '', 'muster' => isset($muster[$z[0]]),
                       'bedeutung' => $z[3]);
    }
    return $aus;
}

/* ------------------------------------------------------------------
 * Pruefzeilen der Umstellung (Reiter Test; Entwurf 3.9). Alle lesen nur.
 * Rueckgabe je array(zustand, text): 1 in Ordnung, 0 Befund, 2 Hinweis bzw. nicht feststellbar.
 * ------------------------------------------------------------------ */

/** Laeuft die Befehlsbruecke? Haken aus ist ein Hinweis, kein Kreuz. */
function hk_pruef_bruecke($cfg)
{
    if (!hk_bruecke_laden()) {
        return array(0, hk_t('BEFEHL.T_FEHLT'));
    }
    list($z, $fehler) = bb_zuordnung_laden(hk_bin_datei('befehle_zuordnung.php'));
    if ($z === null) {
        return array(0, sprintf(hk_t('BEFEHL.T_DEFEKT'), $fehler));
    }
    list($f) = bb_zuordnung_pruefen($z);
    if ($f) {
        return array(0, sprintf(hk_t('BEFEHL.T_DEFEKT'), $f[0]));
    }
    if (!hk_an($cfg, 'heimkino', 'befehle_mqtt_ein')) {
        return array(2, hk_t('BEFEHL.T_AUS'));
    }
    if (DIRECTORY_SEPARATOR === '\\' || hk_paths()['home'] === '' || !bb_pfade()) {
        return array(2, hk_t('BEFEHL.T_ARCHIV'));
    }
    $zu = bb_zustand_lesen();
    $alter = ($zu && isset($zu['ts'])) ? time() - (int) $zu['ts'] : null;
    if (!bb_dienste()) {
        return array(0, hk_t('BEFEHL.T_STEHT'));
    }
    if ($alter === null || $alter > BB_WACHE_GRENZE) {
        return array(0, sprintf(hk_t('BEFEHL.T_HAENGT'), (int) $alter));
    }
    if (empty($zu['abo'])) {
        return array(0, sprintf(hk_t('BEFEHL.T_OHNE_ABO'), isset($zu['fehler']) ? (string) $zu['fehler'] : ''));
    }
    $zl = (isset($zu['zaehler']) && is_array($zu['zaehler'])) ? $zu['zaehler'] : array();
    $letzte = (!empty($zu['letzter']) && is_array($zu['letzter']))
        ? (string) $zu['letzter']['name'] . ': ' . (string) $zu['letzter']['ergebnis'] . ' (vor '
          . max(0, time() - (int) $zu['letzter']['zeit']) . ' s)' : '-';
    return array(1, sprintf(hk_t('BEFEHL.T_LAEUFT'),
        date('d.m.Y H:i', isset($zu['start']) ? (int) $zu['start'] : time()),
        isset($zl['angenommen']) ? (int) $zl['angenommen'] : 0,
        isset($zl['abgelehnt']) ? (int) $zl['abgelehnt'] : 0, $letzte));
}

/** "befehlsbruecke.php --pruefen" (Programme mosquitto_sub/_pub, Zuordnung) als EINE Zeile. */
function hk_pruef_bruecke_einrichtung($cfg)
{
    if (!hk_bruecke_laden()) {
        return array(0, hk_t('BEFEHL.T_FEHLT'));
    }
    ob_start();
    bb_pruefen();
    $aus = array_values(array_filter(explode("\n", (string) ob_get_clean()), 'strlen'));
    if (!$aus) {
        return array(2, hk_t('BEFEHL.T_UNKLAR'));
    }
    $fehl = array_values(preg_grep('/^\[FEHL\]/', $aus));
    if ($fehl) {
        // Fehlende mosquitto-clients bei ausgeschaltetem Haken: ein Hinweis, kein Kreuz.
        return array(hk_an($cfg, 'heimkino', 'befehle_mqtt_ein') ? 0 : 2,
                     implode(' | ', $fehl) . ' ' . hk_t('BEFEHL.T_EINRICHTUNG'));
    }
    return array(1, implode(' | ', array($aus[0], end($aus))));
}

/** Praefix der Befehle in der Grenze der Bruecke (<= 5 Ebenen a 40 Zeichen, <= 100 Zeichen)? */
function hk_pruef_praefix($cfg)
{
    $pr = hk_cfg($cfg, 'heimkino', 'themenpraefix', 'heimkino');
    if (!hk_bruecke_laden()) {
        return array(2, hk_t('BEFEHL.T_FEHLT'));
    }
    if (bb_praefix_gueltig($pr)) {
        return array(1, sprintf(hk_t('BEFEHL.T_PRAEFIX_OK'), $pr));
    }
    return array(hk_an($cfg, 'heimkino', 'befehle_mqtt_ein') ? 0 : 2, sprintf(hk_t('BEFEHL.T_PRAEFIX'), $pr));
}

/**
 * Auswahlwerte der Bruecke = Wortlisten von lg_beamer.py --woerter, zeichengenau (F3)? $woerter fuer die Gegenprobe
 * (ein fehlendes Wort muss rot werden), sonst hk_woerter().
 */
function hk_pruef_auswahl($woerter = null)
{
    $w = $woerter === null ? hk_woerter() : $woerter;
    if (!$w) {
        return array(2, hk_t('BEFEHL.T_AUSWAHL_UNKLAR'));
    }
    $ab = array();
    $n = 0;
    foreach (hk_bb_woerter_geraet() as $art => $liste) {
        $n += count($liste);
        $ist = isset($w[$art]) ? array_values($w[$art]) : array();
        if ($ist !== array_values($liste)) {
            $ab[] = $art . ': ' . implode(' ', array_merge(array_diff($liste, $ist), array_diff($ist, $liste)));
        }
    }
    return $ab ? array(0, sprintf(hk_t('BEFEHL.T_AUSWAHL_AB'), implode('; ', $ab)))
               : array(1, sprintf(hk_t('BEFEHL.T_AUSWAHL_OK'), $n));
}

/**
 * Warteschlange data/plugins/<ordner>/auftraege/: Zahl, aeltester Auftrag, keine fremden Namen (Hausform E7). Ein
 * Auftrag, der laenger als 60 s liegt, heisst: der Dienst arbeitet nicht ab.
 */
function hk_pruef_warteschlange()
{
    $o = hk_paths()['auftraege'];
    if (hk_paths()['home'] === '') {
        return array(2, hk_t('BEFEHL.T_WS_UNKLAR'));
    }
    if (!is_dir($o)) {
        return array(1, hk_t('BEFEHL.T_WS_LEER'));
    }
    $zahl = 0;
    $aeltest = null;
    $fremd = array();
    foreach ((@scandir($o) ?: array()) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        if (preg_match('/^([0-9]{10})[0-9]{6}_[0-9a-f]{8}\.json(\.inarbeit)?\z/', $f, $m)) {
            $zahl++;
            $alter = time() - (int) $m[1];
            $aeltest = $aeltest === null ? $alter : max($aeltest, $alter);
        } elseif (!preg_match('/^\.[0-9]{16}_[0-9a-f]{8}\.json\.tmp\z/', $f)) {
            $fremd[] = $f;
        }
    }
    if ($fremd) {
        return array(0, sprintf(hk_t('BEFEHL.T_WS_FREMD'), implode(', ', $fremd)));
    }
    if ($zahl === 0) {
        return array(1, hk_t('BEFEHL.T_WS_LEER'));
    }
    return array($aeltest > 60 ? 0 : 1, sprintf(hk_t('BEFEHL.T_WS_ZAHL'), $zahl, (int) $aeltest));
}

/** HTTP-Endpunkt aus ist gewollt (grau); an nennt den letzten schaltenden Aufruf. */
function hk_pruef_endpunkt_ein($cfg)
{
    if (!hk_endpunkt_an($cfg)) {
        return array(2, hk_t('BEFEHL.T_ENDPUNKT_AUS'));
    }
    $z = hk_endpunkt_zuletzt();
    return array(1, $z < 0 ? hk_t('BEFEHL.T_ENDPUNKT_NIE')
                           : sprintf(hk_t('BEFEHL.T_ENDPUNKT_ZULETZT'), max(0, (int) floor($z / 86400))));
}

/** Herzschlag: zaehlt status/zaehler? Der Dienst frischt bei jedem Herzschlag die Aenderungszeit von zustand.json
 * auf und legt den Zaehler beim Durchgang darin ab. */
function hk_pruef_herzschlag()
{
    if (!hk_dienst_pid()) {
        return array(2, hk_t('PRUEF.HERZ_KEIN_DIENST'));
    }
    $z = hk_zustand();
    $alter = hk_zustand_alter();
    if (!is_array($z) || !isset($z['herzschlag']['zaehler']) || $alter === null) {
        return array(0, hk_t('BEFEHL.T_HERZ_NIE'));
    }
    return array($alter <= 180 ? 1 : 0, sprintf(hk_t('BEFEHL.T_HERZ'), (int) $z['herzschlag']['zaehler'], (int) $alter));
}

/**
 * Namenstabelle (Entwurf 2.4): jeder Name ist der Gateway-Name seines Themas (Gegenseite ohne hk_vi_name()), und
 * jedes Thema ist eines, das der Dienst sendet (hk_service.py --themen) oder status/befehle der Bruecke. $namen:
 * Liste array(voll, name, thema) fuer die Gegenprobe (ein verdrehter Name muss rot werden); $themen: die Themen des
 * Dienstes (sonst gefragt).
 */
function hk_pruef_namen($namen = null, $themen = null)
{
    if ($namen === null) {
        $namen = array();
        foreach (hk_mqtt_namen() as $z) {
            $namen[] = array($z['voll'], $z['name'], $z['thema']);
        }
    }
    if ($themen === null) {
        list($code, $aus) = hk_cmd_python('hk_service.py', array('--themen'));
        $themen = $code === 0 ? json_decode($aus, true) : null;
        if (!is_array($themen)) {
            return array(2, hk_t('PRUEF.NICHT_MESSBAR'));
        }
    }
    $themen[] = 'status/befehle';
    $falsch = array();
    foreach ($namen as $z) {
        if (!in_array($z[2], $themen, true) || $z[1] !== strtr($z[0], array('/' => '_'))) {
            $falsch[] = $z[1];
        }
    }
    return $falsch ? array(0, sprintf(hk_t('BEFEHL.T_NAMEN_FEHL'), implode(', ', $falsch)))
                   : array(1, sprintf(hk_t('BEFEHL.T_NAMEN_OK'), count($namen), count(hk_mqtt_hakenliste_muster())));
}

/** Abo-Datei des Gateways: der Dienst pflegt config/plugins/<ordner>/mqtt_subscriptions.cfg = "<praefix>/#". */
function hk_pruef_abo($cfg)
{
    $f = hk_paths()['abo'];
    $soll = trim(hk_cfg($cfg, 'heimkino', 'themenpraefix', 'heimkino'), '/') . '/#';
    if (!hk_an($cfg, 'heimkino', 'mqtt')) {
        return array(is_file($f) ? 0 : 2, is_file($f) ? sprintf(hk_t('BEFEHL.T_ABO_REST'), $f) : hk_t('BEFEHL.T_ABO_AUS'));
    }
    $ist = is_file($f) ? trim((string) @file_get_contents($f)) : null;
    if ($ist === $soll) {
        return array(1, sprintf(hk_t('BEFEHL.T_ABO_OK'), $soll));
    }
    if (!hk_dienst_pid()) {
        return array(2, hk_t('BEFEHL.T_ABO_KEIN_DIENST'));
    }
    return array(0, sprintf(hk_t('BEFEHL.T_ABO_FALSCH'), $ist === null ? '-' : $ist, $soll));
}
