<?php
/**
 * Heimkino - Aktionen des Reiters Test
 *
 * Gibt je Aufruf array(titel, html) zurueck. Alles, was das Ergebnis
 * beeinflussen kann, wird mit angezeigt - ein Test, der nur "hat nicht
 * geklappt" sagt, hilft niemandem weiter.
 */

require_once __DIR__ . '/hk_lib.php';

function hk_block($text)
{
    return '<pre class="hk-log">' . hk_e($text) . '</pre>';
}

function hk_test_ausfuehren($was)
{
    $cfg = hk_config_read();
    $p = hk_paths();

    switch ($was) {

        case 'umgebung':
            $z = array();
            $z[] = 'LoxBerry-Wurzel   : ' . $p['home'];
            $z[] = 'Konfiguration     : ' . $p['config']
                 . (is_readable($p['config']) ? '  (lesbar)' : '  (FEHLT)');
            $z[] = 'Protokolldatei    : ' . $p['log']
                 . (is_writable(dirname($p['log'])) ? '  (beschreibbar)' : '  (NICHT beschreibbar)');
            $z[] = 'Programmordner    : ' . $p['bin'];
            $z[] = '';
            foreach (array('cryptography' => 'python3-cryptography',
                           'paho.mqtt.client' => 'python3-paho-mqtt',
                           'requests' => 'python3-requests') as $modul => $paket) {
                $aus = array(); $code = 0;
                @exec('python3 -c ' . escapeshellarg('import ' . $modul) . ' 2>&1', $aus, $code);
                $z[] = sprintf('%-22s %s', $paket,
                    $code === 0 ? 'vorhanden' : 'FEHLT  (sudo apt-get install -y ' . $paket . ')');
            }
            $z[] = '';
            $broker = hk_mqtt_broker();
            $z[] = 'MQTT-Broker       : ' . ($broker
                ? $broker['host'] . ':' . $broker['port']
                : 'nicht in general.json gefunden - MQTT-Gateway eingerichtet?');
            $pid = hk_dienst_pid();
            $z[] = 'Dienst            : ' . ($pid ? 'laeuft (PID ' . $pid . ')' : 'laeuft nicht');
            return array('Umgebung', hk_block(implode("\n", $z)));

        case 'krypto':
            list($code, $aus) = hk_cmd_python('lg_beamer.py', array('--selbsttest'));
            return array('Verschluesselung gegen die Vorlage gepruefte Werte',
                hk_block($aus)
                . ($code === 0
                    ? '<p class="hk-ok-text">Der Nachbau stimmt mit der '
                      . 'Originalfassung ueberein.</p>'
                    : '<p class="hk-err-text">Abweichung - bitte melden.</p>'));

        case 'beamer_erreichbar':
            $ip = hk_cfg($cfg, 'beamer', 'ip', '');
            $port = (int) hk_cfg($cfg, 'beamer', 'port', '9761');
            if ($ip === '') {
                return array('Beamer erreichbar?',
                    '<p class="hk-err-text">Es ist keine IP-Adresse eingetragen.</p>');
            }
            $anfang = microtime(true);
            $verbindung = @fsockopen($ip, $port, $nr, $txt, 3);
            $dauer = round((microtime(true) - $anfang) * 1000);
            if ($verbindung) {
                fclose($verbindung);
                return array('Beamer erreichbar?', hk_block(
                    $ip . ':' . $port . " antwortet (nach " . $dauer . " ms).\n\n"
                    . "Das heisst: die Netzwerk-IP-Steuerung ist eingeschaltet\n"
                    . "und der Beamer ist nicht im Tiefschlaf."));
            }
            return array('Beamer erreichbar?', hk_block(
                $ip . ':' . $port . " antwortet nicht.\n"
                . "Meldung: " . $txt . " (" . $nr . ")\n\n"
                . "Moegliche Gruende:\n"
                . " - Der Beamer ist aus und im Tiefschlaf. Das ist normal.\n"
                . " - Netzwerk-IP-Steuerung steht am Geraet auf aus.\n"
                . " - Die IP-Adresse stimmt nicht mehr (feste Adresse in der\n"
                . "   Fritz!Box vergeben?)."));

        case 'beamer_ipcontrol':
            list($code, $aus) = hk_cmd(array('beamer-ipcontrol'));
            return array('Netzwerk-IP-Steuerung abfragen', hk_block($aus));

        case 'beamer_status':
            list($code, $aus) = hk_cmd(array('beamer-status'));
            return array('Betriebszustand des Beamers', hk_block(
                $aus . "\n\n"
                . "an        = der Beamer laeuft\n"
                . "aus       = er antwortet, laeuft aber nicht\n"
                . "unbekannt = keine brauchbare Antwort"));

        case 'beamer_aus':
            list($code, $aus) = hk_cmd(array('beamer-aus'));
            return array('Beamer ausschalten', hk_block($aus));

        case 'beamer_wol':
            list($code, $aus) = hk_cmd(array('beamer-wol'));
            return array('Wake-on-LAN an den Beamer', hk_block(
                $aus . "\n\n"
                . "Loxone kann Wake-on-LAN selbst (Adresse wol://, Befehl ist\n"
                . "die MAC ohne Trennzeichen). Dieser Knopf dient nur dazu, die\n"
                . "MAC zu pruefen, ohne den Miniserver anzufassen."));

        case 'anmeldedaten':
            $a = hk_xbox_auth_lesen();
            $z = array();
            $z[] = 'Anwendungs-ID (Client) : '
                 . (empty($a['client_id']) ? 'FEHLT'
                    : $a['client_id'] . (hk_ist_guid($a['client_id'])
                       ? '   (GUID-Form, richtig)'
                       : '   ACHTUNG: keine GUID-Form - ist das wirklich die Anwendungs-ID?'));
            $z[] = 'Umleitungs-URI         : '
                 . (isset($a['redirect_uri']) ? $a['redirect_uri'] : '(Vorgabe)');
            $z[] = 'Anmeldedienst          : '
                 . (isset($a['dienst']) && $a['dienst'] === 'v2'
                    ? 'login.microsoftonline.com (v2.0)' : 'login.live.com (Standard)');
            $z[] = 'Angemeldet             : '
                 . (empty($a['refresh_token']) ? 'nein' : 'ja');
            $txt = hk_block(implode("\n", $z));
            list($art, $satz) = hk_geheimnis_form(
                isset($a['client_secret']) ? $a['client_secret'] : '');
            $klasse = $art === 'ok' ? 'hk-ok' : ($art === 'leer' ? 'hk-info' : 'hk-err');
            $txt .= '<div class="hk-alert ' . $klasse . '"><b>Geheimer '
                  . 'Clientschl&uuml;ssel:</b> ' . $satz . '</div>';
            $txt .= '<p class="hk-small">Das Geheimnis selbst wird nicht '
                  . 'angezeigt &mdash; nur seine L&auml;nge und Form.</p>';
            return array('Anmeldedaten prüfen', $txt);

        case 'xbox_konsolen':
            list($code, $aus) = hk_cmd(array('xbox-konsolen'));
            if ($code !== 0) {
                return array('Konsolen suchen', hk_block($aus));
            }
            $zeilen = preg_split('/\R/', trim($aus));
            $t = '<table class="hk-tbl"><tr><th>Kennung</th><th>Name</th>'
               . '<th>Typ</th><th>Zustand</th></tr>';
            foreach ($zeilen as $zeile) {
                if (trim($zeile) === '') { continue; }
                $teile = explode("\t", $zeile);
                $t .= '<tr>';
                for ($i = 0; $i < 4; $i++) {
                    $t .= '<td>' . hk_e(isset($teile[$i]) ? $teile[$i] : '') . '</td>';
                }
                $t .= '</tr>';
            }
            $t .= '</table><p class="hk-small">Die Kennung der gewuenschten '
                . 'Konsole in den Einstellungen unter XBOX-Netzwerk-Ger&auml;teidentit&auml;t '
                . 'eintragen.</p>';
            return array('Konsolen suchen', $t);

        case 'xbox_status':
            list($code, $aus) = hk_cmd(array('xbox-status'));
            return array('Zustand der Konsole', hk_block($aus));

        case 'xbox_an':
            list($code, $aus) = hk_cmd(array('xbox-an'));
            return array('Xbox wecken', hk_block($aus));

        case 'xbox_aus':
            list($code, $aus) = hk_cmd(array('xbox-aus'));
            return array('Xbox ausschalten', hk_block($aus));

        case 'dienst_neu':
            $pid = hk_dienst('restart');
            return array('Dienst neu starten', hk_block($pid
                ? 'Der Dienst laeuft (PID ' . $pid . ').'
                : 'Der Dienst laeuft nicht - siehe Reiter Logdateien.'));
    }
    return array('', '');
}

/** Ein anderes Python-Programm aus bin/ aufrufen. */
function hk_cmd_python($datei, $argumente)
{
    $bin = hk_paths()['bin'] . '/' . $datei;
    if (!is_readable($bin)) {
        return array(1, $datei . ' nicht gefunden: ' . $bin);
    }
    $befehl = escapeshellarg('python3') . ' ' . escapeshellarg($bin) . ' ';
    foreach ((array) $argumente as $a) {
        $befehl .= escapeshellarg($a) . ' ';
    }
    $aus = array(); $code = 0;
    @exec($befehl . '2>&1', $aus, $code);
    return array($code, implode("\n", $aus));
}
