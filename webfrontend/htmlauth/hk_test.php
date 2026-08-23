<?php
/**
 * Heimkino - Reiter Test
 *
 * Zwei Teile:
 *
 *  1. hk_test_zeilen()  - die stehende Selbstpruefung. Je Zeile eine Frage
 *     mit Haken, Kreuz oder Fragezeichen. Sie laeuft bei jedem Oeffnen und
 *     kostet keine Netzverbindung ausser dem einen, zwischengespeicherten
 *     Aufruf des eigenen Endpunkts.
 *
 *  2. hk_test_ausfuehren() - die Knoepfe. Geben array(titel, html) zurueck.
 *
 * Kein deutscher Text mehr in dieser Datei: alles kommt aus
 * templates/lang/language_*.ini. Bis 1.2.11 standen hier rund 190 Zeilen
 * fest eingetragenes Deutsch, teils mit HTML-Entitaeten, die anschliessend
 * noch einmal durch hk_e() liefen und dann woertlich auf dem Bildschirm
 * standen.
 */

require_once __DIR__ . '/hk_lib.php';

function hk_block($text)
{
    return '<pre class="sm-pre">' . hk_e($text) . '</pre>';
}

/**
 * Die stehende Selbstpruefung.
 *
 * Rueckgabe: Liste von array(zustand, frage, antwort).
 * zustand: 1 = in Ordnung, 0 = Befund, 2 = nicht feststellbar.
 *
 * "Nicht feststellbar" ist ein eigener Zustand und sieht auch so aus - ein
 * "ich kann es nicht messen" darf nicht wie ein Haken wirken.
 */
function hk_test_zeilen($cfg)
{
    $zeilen = array();
    $p = hk_paths();

    // --- Laeuft der Dienst? Die Grundfrage.
    $pid = hk_dienst_pid();
    $zeilen[] = array($pid ? 1 : 0, hk_t('PRUEF.DIENST'),
        $pid ? hk_tf('PRUEF.DIENST_JA', array('%1' => (string) $pid))
             : hk_t('PRUEF.DIENST_NEIN'));

    // --- Arbeitet er noch? Ein Prozess kann dastehen und nichts tun.
    // Ueber einen Dienst, der gar nicht laeuft, wird kein Herzschlag
    // beurteilt - sonst steht dort zweimal derselbe Befund.
    $alter = hk_zustand_alter();
    $takt = 60;
    $z = hk_zustand();
    if (is_array($z) && isset($z['takt'])) { $takt = max(10, (int) $z['takt']); }
    $grenze = 3 * $takt + 60;
    if (!$pid) {
        $zeilen[] = array(2, hk_t('PRUEF.HERZ'), hk_t('PRUEF.HERZ_KEIN_DIENST'));
    } elseif ($alter === null) {
        $zeilen[] = array(0, hk_t('PRUEF.HERZ'), hk_t('PRUEF.HERZ_NIE'));
    } else {
        $zeilen[] = array($alter <= $grenze ? 1 : 0, hk_t('PRUEF.HERZ'),
            hk_tf('PRUEF.HERZ_ALTER', array('%1' => (string) (int) $alter,
                                            '%2' => (string) $grenze)));
    }

    // --- Ist die Konfiguration heil? Jeder Zustand, den der Code erzeugen
    // kann, braucht seinen eigenen Satz.
    $lage = hk_config_lage();
    $zeilen[] = array($lage === 'ok' ? 1 : 0, hk_t('PRUEF.CFG'),
        hk_t('PRUEF.CFG_' . strtoupper($lage)));

    // --- Ist sie vollstaendig? "fehlt" darf nicht dasselbe sein wie
    // "steht auf dem Vorgabewert".
    list($zu, $tx) = hk_vollstaendig_probe();
    $zeilen[] = array($zu, hk_t('PRUEF.CFG_VOLL'), $tx);

    // --- Kennen Oberflaeche und Dienst dieselben Vorgaben?
    list($zu, $tx) = hk_vorgaben_probe();
    $zeilen[] = array($zu, hk_t('PRUEF.VORGABEN'),
        $zu === 2 ? hk_t('PRUEF.NICHT_MESSBAR') : hk_tf('PRUEF.ANZAHL', array('%1' => $tx)));

    // --- Antwortet der eigene Endpunkt? Der echte Aufruf auf 127.0.0.1 -
    // nur er findet die getrennten Baeume, die keine Leseprobe sieht.
    list($zu, $tx) = hk_endpunkt_probe($cfg);
    $zeilen[] = array($zu, hk_t('PRUEF.ENDPUNKT'),
        $zu === 1 ? hk_t('PRUEF.ENDPUNKT_OK')
        : ($zu === 2 ? ($tx === 'KEIN_TOKEN' ? hk_t('PRUEF.ENDPUNKT_KEIN_TOKEN')
                                             : hk_t('PRUEF.ENDPUNKT_UNKLAR'))
        : hk_tf('PRUEF.ENDPUNKT_FALSCH', array('%1' => $tx))));

    // --- Nennt die Themen-Tabelle, was der Dienst wirklich sendet?
    list($zu, $tx) = hk_themen_probe();
    $zeilen[] = array($zu, hk_t('PRUEF.THEMEN'),
        $zu === 1 ? hk_tf('PRUEF.ANZAHL', array('%1' => $tx))
        : ($zu === 2 ? hk_t('PRUEF.NICHT_MESSBAR')
                     : hk_tf('PRUEF.THEMEN_ABW', array('%1' => $tx))));

    // --- Setzt der Server das sm-active? Ohne das ist die Seite ohne
    // JavaScript leer.
    list($zu, $tx) = hk_smactive_probe();
    $zeilen[] = array($zu, hk_t('PRUEF.SMACTIVE'),
        $zu === 1 ? hk_tf('PRUEF.REITER_ZAHL', array('%1' => $tx))
                  : hk_tf('PRUEF.SMACTIVE_NEIN', array('%1' => $tx)));

    // --- Passen Reiterleiste, Bereiche und Positivliste zusammen?
    list($zu, $tx) = hk_kongruenz_probe();
    $zeilen[] = array($zu, hk_t('PRUEF.KONGRUENZ'),
        hk_tf('PRUEF.KONGRUENZ_ZAHL', array('%1' => $tx)));

    // --- Tragen alle Formulare das Merkmal gegen fremde Absender?
    list($zu, $tx) = hk_formularprobe();
    $zeilen[] = array($zu, hk_t('PRUEF.FORMULARE'),
        hk_tf('PRUEF.FORMULARE_ZAHL', array('%1' => $tx)));

    // --- Wirkt die Geraetesperre? Ohne sie koennen Dienst und
    // Einzelbefehl gleichzeitig mit dem Beamer sprechen, und das Geraet
    // nimmt nur eine Verbindung zur Zeit an.
    list($zu, $tx) = hk_sperre_probe();
    $zeilen[] = array($zu, hk_t('PRUEF.SPERRE'),
        $zu === 1 ? hk_tf('PRUEF.SPERRE_JA', array('%1' => $tx))
        : ($zu === 2 ? hk_t('PRUEF.NICHT_MESSBAR') : hk_t('PRUEF.SPERRE_NEIN')));

    // --- Sind die erzeugbaren Loxone-Vorlagen wohlgeformt?
    list($zu, $tx) = hk_vorlagen_probe($cfg);
    $zeilen[] = array($zu, hk_t('PRUEF.VORLAGEN'),
        $zu === 1 ? hk_tf('PRUEF.ANZAHL', array('%1' => $tx))
        : ($zu === 2 ? hk_t('PRUEF.NICHT_MESSBAR')
                     : hk_tf('PRUEF.VORLAGEN_KAPUTT', array('%1' => $tx))));

    // --- Zustand des MQTT-Gateways.
    $broker = hk_mqtt_broker();
    $zeilen[] = array($broker ? 1 : 0, hk_t('PRUEF.BROKER'),
        $broker ? ($broker['host'] . ':' . $broker['port']
                   . ($broker['autostart'] ? '' : ' - ' . hk_t('MQTT.AUTOSTART_NEIN')))
                : hk_t('PRUEF.BROKER_KEIN'));

    // --- Pflichtfelder je eingeschaltetem Geraet. Ueber eine leere Menge
    // wird nicht geurteilt: ist nichts eingeschaltet, gibt es nichts zu
    // pruefen, und das steht dann auch da.
    if (!hk_an($cfg, 'beamer', 'aktiv') && !hk_an($cfg, 'xbox', 'aktiv')) {
        $zeilen[] = array(2, hk_t('PRUEF.FELDER'), hk_t('PRUEF.FELDER_NICHTS'));
    } else {
        $fehlt = array();
        if (hk_an($cfg, 'beamer', 'aktiv')) {
            if (hk_cfg($cfg, 'beamer', 'ip', '') === '') { $fehlt[] = hk_t('FELD.BEAMER_IP'); }
            if (hk_cfg($cfg, 'beamer', 'keycode', '') === '') { $fehlt[] = hk_t('FELD.BEAMER_KEYCODE'); }
        }
        if (hk_an($cfg, 'xbox', 'aktiv')) {
            $xb = hk_xbox_zustand();
            if (hk_cfg($cfg, 'xbox', 'geraete_id', '') === '') { $fehlt[] = hk_t('FELD.XBOX_ID'); }
            if (!$xb['eingerichtet']) { $fehlt[] = hk_t('FELD.XBOX_APP'); }
            if (!$xb['angemeldet']) { $fehlt[] = hk_t('FELD.XBOX_ANMELDUNG'); }
        }
        $zeilen[] = array($fehlt ? 0 : 1, hk_t('PRUEF.FELDER'),
            $fehlt ? implode(', ', $fehlt) : hk_t('PRUEF.FELDER_OK'));
    }

    // --- Frist des Clientgeheimnisses.
    if (!hk_an($cfg, 'xbox', 'aktiv')) {
        $zeilen[] = array(2, hk_t('PRUEF.FRIST'), hk_t('PRUEF.FRIST_XBOX_AUS'));
    } else {
        list($art, $tage, $hin) = hk_ablauf_lage(hk_cfg($cfg, 'xbox', 'geheimnis_ablauf', ''));
        $zeilen[] = array(
            $art === 'ok' ? 1 : ($art === 'leer' ? 2 : 0),
            hk_t('PRUEF.FRIST'),
            hk_tf('FRIST.' . strtoupper($art), array(
                '%1' => $hin, '%2' => (string) (int) abs((int) $tage))));
    }

    return $zeilen;
}

function hk_test_ausfuehren($was)
{
    $cfg = hk_config_read();
    $p = hk_paths();

    switch ($was) {

        case 'umgebung':
            $z = array();
            $z[] = hk_t('UMG.WURZEL') . ': ' . $p['home'];
            $z[] = hk_t('UMG.CONFIG') . ': ' . $p['config']
                 . '  (' . hk_t(is_readable($p['config']) ? 'UMG.LESBAR' : 'UMG.FEHLT') . ')';
            $z[] = hk_t('UMG.LOG') . ': ' . $p['log']
                 . '  (' . hk_t(is_writable(dirname($p['log'])) ? 'UMG.SCHREIBBAR' : 'UMG.NICHT_SCHREIBBAR') . ')';
            $z[] = hk_t('UMG.BIN') . ': ' . $p['bin'];
            $z[] = hk_t('UMG.WAECHTER') . ': '
                 . ($p['bin'] !== '' && is_file($p['bin'] . '/dienst.sh')
                    ? hk_t('UMG.VORHANDEN') : hk_t('UMG.FEHLT'));
            $z[] = hk_t('UMG.SOLL') . ': '
                 . hk_t(is_file($p['soll']) ? 'ALLGEMEIN.JA' : 'ALLGEMEIN.NEIN');
            $z[] = '';
            foreach (array('cryptography' => 'python3-cryptography',
                           'paho.mqtt.client' => 'python3-paho-mqtt',
                           'requests' => 'python3-requests') as $modul => $paket) {
                $aus = array(); $code = 0;
                @exec('python3 -c ' . escapeshellarg('import ' . $modul) . ' 2>&1', $aus, $code);
                $z[] = sprintf('%-22s %s', $paket,
                    $code === 0 ? hk_t('UMG.VORHANDEN')
                                : hk_t('UMG.FEHLT') . '  (sudo apt-get install -y ' . $paket . ')');
            }
            $z[] = '';
            $broker = hk_mqtt_broker();
            $z[] = hk_t('UMG.BROKER') . ': ' . ($broker
                ? $broker['host'] . ':' . $broker['port']
                  . '  (' . hk_t('MQTT.FASSUNG') . ' '
                  . ($broker['fassung'] > 0 ? (string) $broker['fassung']
                                            : hk_t('MQTT.FASSUNG_UNBEKANNT')) . ')'
                : hk_t('PRUEF.BROKER_KEIN'));
            $pid = hk_dienst_pid();
            $z[] = hk_t('UMG.DIENST') . ': ' . ($pid
                ? hk_tf('PRUEF.DIENST_JA', array('%1' => (string) $pid))
                : hk_t('PRUEF.DIENST_NEIN'));
            return array(hk_t('TEST.T_UMGEBUNG'), hk_block(implode("\n", $z)));

        case 'krypto':
            list($code, $aus) = hk_cmd_python('lg_beamer.py', array('--selbsttest'));
            return array(hk_t('TEST.T_KRYPTO'),
                hk_block($aus)
                . ($code === 0
                    ? '<div class="sm-hinweis">' . hk_t('TEST.KRYPTO_OK') . '</div>'
                    : '<div class="sm-warnung">' . hk_t('TEST.KRYPTO_ABW') . '</div>'));

        case 'beamer_erreichbar':
            // Ueber hk_cmd, NICHT mit einem eigenen fsockopen: dieser Griff
            // geht an denselben Port 9761 und muss deshalb dieselbe
            // Geraetesperre nehmen wie Dienst und Einzelbefehl. Bis 1.3.0
            // war die Oberflaeche hier ein dritter Prozess am Geraet, von dem
            // die beiden anderen nichts wussten.
            if (hk_cfg($cfg, 'beamer', 'ip', '') === '') {
                return array(hk_t('TEST.T_ERREICHBAR'),
                    '<div class="sm-warnung">' . hk_t('TEST.KEINE_IP') . '</div>');
            }
            list($code, $aus) = hk_cmd(array('beamer-erreichbar'));
            return array(hk_t('TEST.T_ERREICHBAR'), hk_block($aus)
                . ($code === 0 ? ''
                   : '<p class="sm-hilfe">' . hk_t('TEST.ANTWORTET_NICHT_GRUENDE')
                     . '</p>'));

        case 'beamer_ipcontrol':
            list($code, $aus) = hk_cmd(array('beamer-ipcontrol'));
            return array(hk_t('TEST.T_IPCONTROL'), hk_block($aus));

        case 'beamer_status':
            list($code, $aus) = hk_cmd(array('beamer-status'));
            // Lautstaerke und Stummschaltung nur mitfragen, wenn das Geraet
            // laeuft - und ein Fehlschlag bleibt eine Zeile, kein Befund:
            // ob ein Beamer diese Befehle kennt, ist nicht gemessen.
            $zusatz = '';
            if (trim($aus) === 'an') {
                list($c1, $a1) = hk_cmd(array('beamer-lautstaerke-lesen'));
                list($c2, $a2) = hk_cmd(array('beamer-stumm-lesen'));
                $zusatz = "\n\n" . hk_t('TEST.LAUTSTAERKE') . ': '
                        . ($c1 === 0 ? trim($a1) : hk_t('PRUEF.NICHT_MESSBAR'))
                        . "\n" . hk_t('TEST.STUMM') . ': '
                        . ($c2 === 0 ? trim($a2) : hk_t('PRUEF.NICHT_MESSBAR'));
            }
            return array(hk_t('TEST.T_STATUS'),
                hk_block($aus . $zusatz . "\n\n" . hk_t('TEST.STATUS_LEGENDE')));

        case 'beamer_aus':
            list($code, $aus) = hk_cmd(array('beamer-aus'));
            return array(hk_t('AKTION.BEAMER_AUS'), hk_block($aus));

        case 'beamer_wol':
            list($code, $aus) = hk_cmd(array('beamer-wol'));
            return array(hk_t('AKTION.BEAMER_WOL'),
                hk_block($aus . "\n\n" . hk_t('TEST.WOL_HINWEIS')));

        case 'beamer_mac':
            // Die MAC AM GERAET auslesen und mit der eingetragenen
            // vergleichen. Bisher merkte niemand, wenn sie falsch war:
            // Wake-on-LAN verpufft dann lautlos, und in Loxone sieht es aus,
            // als ginge der Beamer nicht an.
            list($code, $aus) = hk_cmd(array('beamer-mac', 'wired'));
            return array(hk_t('TEST.T_MAC'), hk_block($aus)
                . ($code === 0
                    ? '<div class="sm-hinweis">' . hk_t('TEST.MAC_GLEICH') . '</div>'
                    : '<div class="sm-warnung">' . hk_t('TEST.MAC_ABWEICHUNG') . '</div>'));

        case 'beamer_bild_aus':
            list($code, $aus) = hk_cmd(array('beamer-bild-aus'));
            return array(hk_t('AKTION.BEAMER_BILD_AUS'), hk_block($aus));

        case 'beamer_bild_an':
            list($code, $aus) = hk_cmd(array('beamer-bild-an'));
            return array(hk_t('AKTION.BEAMER_BILD_AN'), hk_block($aus));

        case 'kino_an':
            list($code, $aus) = hk_cmd(array('kino-an'));
            return array(hk_t('AKTION.KINO_AN'), hk_block($aus)
                . '<div class="sm-hinweis">' . hk_t('TEST.SZENE_HINWEIS') . '</div>');

        case 'kino_aus':
            list($code, $aus) = hk_cmd(array('kino-aus'));
            return array(hk_t('AKTION.KINO_AUS'), hk_block($aus)
                . '<div class="sm-hinweis">' . hk_t('TEST.SZENE_HINWEIS') . '</div>');

        case 'xbox_roh':
            // Erst messen, dann festlegen, was als eigenes Thema hinausgeht.
            list($code, $aus) = hk_cmd(array('xbox-roh'));
            return array(hk_t('TEST.T_XBOX_ROH'), hk_block($aus)
                . '<p class="sm-hilfe">' . hk_t('TEST.XBOX_ROH_HINWEIS') . '</p>');

        case 'anmeldedaten':
            $a = hk_xbox_auth_lesen();
            $z = array();
            $z[] = hk_t('ANM.CLIENT_ID') . ': '
                 . (empty($a['client_id']) ? hk_t('UMG.FEHLT')
                    : $a['client_id'] . '   ('
                      . hk_t(hk_ist_guid($a['client_id']) ? 'ANM.GUID_OK' : 'ANM.GUID_NEIN') . ')');
            $z[] = hk_t('ANM.RUECKLEITUNG') . ': '
                 . (isset($a['redirect_uri']) ? $a['redirect_uri'] : hk_t('ANM.VORGABE'));
            $z[] = hk_t('ANM.DIENST') . ': '
                 . (isset($a['dienst']) && $a['dienst'] === 'v2'
                    ? 'login.microsoftonline.com (v2.0)' : 'login.live.com');
            $z[] = hk_t('ANM.ANGEMELDET') . ': '
                 . hk_t(empty($a['refresh_token']) ? 'ALLGEMEIN.NEIN' : 'ALLGEMEIN.JA');
            $txt = hk_block(implode("\n", $z));
            list($art, $laenge) = hk_geheimnis_form(
                isset($a['client_secret']) ? $a['client_secret'] : '');
            $klasse = $art === 'ok' ? 'sm-hinweis' : 'sm-warnung';
            $txt .= '<div class="' . $klasse . '"><b>' . hk_te('ANM.GEHEIMNIS') . ':</b> '
                  . hk_tf('GEHEIM.' . strtoupper($art),
                          array('%1' => (string) $laenge)) . '</div>';
            $txt .= '<p class="sm-hilfe">' . hk_t('ANM.NICHT_ANGEZEIGT') . '</p>';
            return array(hk_t('TEST.T_ANMELDEDATEN'), $txt);

        case 'xbox_konsolen':
            list($code, $aus) = hk_cmd(array('xbox-konsolen'));
            if ($code !== 0) {
                return array(hk_t('TEST.T_KONSOLEN'), hk_block($aus));
            }
            $zeilen = preg_split('/\R/', trim($aus));
            $t = '<div class="sm-breit"><table class="sm-tbl"><tr>'
               . '<th>' . hk_te('KONSOLE.KENNUNG') . '</th>'
               . '<th>' . hk_te('KONSOLE.NAME') . '</th>'
               . '<th>' . hk_te('KONSOLE.TYP') . '</th>'
               . '<th>' . hk_te('KONSOLE.ZUSTAND') . '</th></tr>';
            $gefunden = 0;
            foreach ($zeilen as $zeile) {
                if (trim($zeile) === '') { continue; }
                $gefunden++;
                $teile = explode("\t", $zeile);
                $t .= '<tr>';
                for ($i = 0; $i < 4; $i++) {
                    $t .= '<td>' . hk_e(isset($teile[$i]) ? $teile[$i] : '') . '</td>';
                }
                $t .= '</tr>';
            }
            $t .= '</table></div>';
            // Die leere Menge zuerst: eine Tabelle ohne Zeilen sieht aus wie
            // ein Ergebnis und ist keines.
            if ($gefunden === 0) {
                return array(hk_t('TEST.T_KONSOLEN'),
                    '<div class="sm-warnung">' . hk_t('KONSOLE.KEINE') . '</div>');
            }
            $t .= '<p class="sm-hilfe">' . hk_t('KONSOLE.HINWEIS') . '</p>';
            return array(hk_t('TEST.T_KONSOLEN'), $t);

        case 'xbox_status':
            list($code, $aus) = hk_cmd(array('xbox-status'));
            return array(hk_t('TEST.T_XBOX_STATUS'), hk_block($aus));

        case 'xbox_an':
            list($code, $aus) = hk_cmd(array('xbox-an'));
            return array(hk_t('AKTION.XBOX_AN'), hk_block($aus));

        case 'xbox_aus':
            list($code, $aus) = hk_cmd(array('xbox-aus'));
            return array(hk_t('AKTION.XBOX_AUS'), hk_block($aus));

        case 'dienst_start':
            $pid = hk_dienst('start');
            return array(hk_t('ALLGEMEIN.K_DIENST_START'), hk_block($pid
                ? hk_tf('PRUEF.DIENST_JA', array('%1' => (string) $pid))
                : hk_t('PRUEF.DIENST_NEIN')));

        case 'dienst_stop':
            hk_dienst('stop');
            $pid = hk_dienst_pid();
            return array(hk_t('ALLGEMEIN.K_DIENST_STOP'), hk_block($pid
                ? hk_tf('PRUEF.DIENST_JA', array('%1' => (string) $pid))
                : hk_t('PRUEF.DIENST_NEIN')));

        case 'dienst_neu':
            $pid = hk_dienst('restart');
            return array(hk_t('ALLGEMEIN.K_DIENST_NEU'), hk_block($pid
                ? hk_tf('PRUEF.DIENST_JA', array('%1' => (string) $pid))
                : hk_t('PRUEF.DIENST_NEIN')));
    }
    return array('', '');
}
