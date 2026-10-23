<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Heimkino.
 *
 * Gruppe 9 / MQTT (1.3.30; Bauvorschrift wz_bild/GRUPPE9_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe9_hk.txt
 * Abschnitte 3.2-3.4, ENTSCHEIDUNGEN_GRUPPE9_HEIMKINO.md E1-E12; Muster vb_g8_mt_bau/bin/befehle_zuordnung.php). Die
 * Bruecke selbst (bin/befehlsbruecke.php und .sh) ist byte-gleich die Stammfassung Werkzeuge/gemeinsam/ - linieneigen
 * ist nur diese Datei.
 *
 * 13 Namen fuer die 16 Aktionen des HTTP-Endpunkts (E1), je Geraet eine Ebene - symmetrisch zu den Werten
 * <praefix>/beamer/..., <praefix>/xbox/...: kino 1/0, beamer/aus, beamer/wol, beamer/bild 1/0, beamer/stumm 1/0,
 * beamer/lautstaerke, beamer/eingang, beamer/bildmodus, beamer/energie, beamer/taste, beamer/app, xbox/an, xbox/aus.
 * Lese- und Anmeldebefehle (xbox-code, beamer-status ...) gibt es ueber MQTT nicht - die Werte kommen ueber das
 * Gateway, die Anmeldung braucht Geheimnisse.
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 * 'tun' ruft hk_bb_tun() in hk_lib.php: Plugin abgeschaltet, Upgrade-Marke, kein Dienst, Szene oder Geraet
 * abgeschaltet - dann hk_steuern(), DIESELBE Funktion wie der HTTP-Endpunkt (Aktion und Wert, Gleichwert 60 s,
 * Xbox-Bremse 10 s, Merker). Ueber MQTT reiht hk_steuern() einen Auftrag in data/plugins/<ordner>/auftraege/ ein;
 * den fuehrt der Dienst im Hauptfaden aus (F1, E4). Kein HTTP auf sich selbst, kein Kindprozess. Quittung
 * "uebergeben"; das Ergebnis meldet der Dienst in beamer/letzte_aktion bzw. xbox/letzte_aktion.
 *
 * Haken (E2): "Befehle ueber MQTT annehmen" (befehle_mqtt_ein, ab Werk aus) schaltet die Bruecke; eingreifende
 * Befehle - Kino-Szene, Beamer aus/WoL, Xbox an/aus und Tasten (KEY_ACTION kennt auch "power") - brauchen
 * zusaetzlich "Eingreifende Befehle ueber MQTT zulassen" (befehle_mqtt_steuern, ab Werk aus).
 *
 * Schreibweise (eigener Fund F3): die Bruecke schreibt 'auswahl' klein (bb_wert_pruefen) und laesst in 'werte' nur
 * a-z, 0-9 und _ zu. Die Geraeteschreibweise (Bildmodus filmMaker) setzt hk_bb_tun() ueber hk_bb_auswahl() ein;
 * dieselbe Tabelle liefert hier die Woerter. Die Pruefzeile im Reiter Test haelt sie gegen lg_beamer.py --woerter.
 */
if (!function_exists('hk_bb_tun')) {
    $hk_bb_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $hk_bb_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/hk_lib.php';
    }
    $hk_bb_kand[] = dirname(__DIR__) . '/webfrontend/htmlauth/hk_lib.php';
    foreach ($hk_bb_kand as $hk_bb_k) {
        if (is_file($hk_bb_k)) { require_once $hk_bb_k; break; }
    }
}
$hk_bb_grund = 'Heimkino prueft selbst und an EINER Stelle fuer HTTP und MQTT (hk_steuern): Gleichwert-Unterdrueckung 60 s '
    . 'je Zustand (Bild an/aus, Ton an/aus, Ein/Aus und Szene teilen sich je einen Schluessel - ein anderer Wert geht '
    . 'sofort hinaus), Xbox-Bremse 10 s; Tasten, Anwendungen und Wake-on-LAN gehen immer. Eine Brueckensperre je Name '
    . 'und Wert verschluckte Bild aus-an-aus binnen einer Minute.';
$hk_bb_woerter = function_exists('hk_bb_auswahl') ? hk_bb_auswahl() : array();
$hk_bb_werte = function ($art) use ($hk_bb_woerter) {
    return isset($hk_bb_woerter[$art]) ? array_keys($hk_bb_woerter[$art]) : array();
};
/* Ein Eintrag: Art, eingreifend, Titel, Kachel, Zusatz; Sperrminute 0 mit EINER Begruendung (Entwurf 3.3). */
$hk_bb_e = function ($name, $art, $eingreifend, $titel, $kachel, $extra = array()) use ($hk_bb_grund) {
    return array_merge(array('art' => $art, 'eingreifend' => $eingreifend, 'titel' => 'Heimkino ' . $titel . ' (MQTT)',
        'kachel' => $kachel, 'quittung' => 'uebergeben', 'sperre_s' => 0, 'begruendung' => $hk_bb_grund,
        'tun' => function ($w, $t) use ($name) {
            return hk_bb_tun($name, (string) $w);
        }), $extra);
};
return array(
    'fassung' => 1,
    'linie'   => 'heimkino',
    'plugin'  => 'Heimkino',
    'geladen' => function_exists('hk_bb_tun') && function_exists('hk_config_read')
                 && function_exists('hk_bb_einstellungen') && function_exists('hk_bb_protokoll')
                 && function_exists('hk_bb_auswahl'),
    'zweiter_haken' => 'befehle_mqtt_steuern',
    'einstellungen' => function () {
        return hk_bb_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        hk_bb_protokoll($stufe, $text);
    },
    'befehle' => array(
        'kino'               => $hk_bb_e('kino', 'schalter', true, 'Kino-Szene', 'BEFEHL.K_KINO'),
        'beamer/aus'         => $hk_bb_e('beamer/aus', 'puls', true, 'Beamer aus', 'BEFEHL.K_BEAMER_AUS'),
        'beamer/wol'         => $hk_bb_e('beamer/wol', 'puls', true, 'Beamer wecken', 'BEFEHL.K_BEAMER_WOL'),
        'beamer/bild'        => $hk_bb_e('beamer/bild', 'schalter', false, 'Bild', 'BEFEHL.K_BILD'),
        'beamer/stumm'       => $hk_bb_e('beamer/stumm', 'schalter', false, 'Ton stumm', 'BEFEHL.K_STUMM'),
        'beamer/lautstaerke' => $hk_bb_e('beamer/lautstaerke', 'ganz', false, 'Lautstärke', 'BEFEHL.K_LAUTSTAERKE',
                                         array('min' => 0, 'max' => 100)),
        'beamer/eingang'     => $hk_bb_e('beamer/eingang', 'auswahl', false, 'Eingang', 'BEFEHL.K_EINGANG',
                                         array('werte' => $hk_bb_werte('eingang'))),
        'beamer/bildmodus'   => $hk_bb_e('beamer/bildmodus', 'auswahl', false, 'Bildmodus', 'BEFEHL.K_BILDMODUS',
                                         array('werte' => $hk_bb_werte('bildmodus'))),
        'beamer/energie'     => $hk_bb_e('beamer/energie', 'auswahl', false, 'Energiesparstufe', 'BEFEHL.K_ENERGIE',
                                         array('werte' => $hk_bb_werte('energiesparen'))),
        'beamer/taste'       => $hk_bb_e('beamer/taste', 'text', true, 'Taste', 'BEFEHL.K_TASTE',
                                         array('max_byte' => 32)),
        'beamer/app'         => $hk_bb_e('beamer/app', 'text', false, 'Anwendung', 'BEFEHL.K_APP',
                                         array('max_byte' => 32)),
        'xbox/an'            => $hk_bb_e('xbox/an', 'puls', true, 'Xbox an', 'BEFEHL.K_XBOX_AN'),
        'xbox/aus'           => $hk_bb_e('xbox/aus', 'puls', true, 'Xbox aus', 'BEFEHL.K_XBOX_AUS'),
    ),
);
