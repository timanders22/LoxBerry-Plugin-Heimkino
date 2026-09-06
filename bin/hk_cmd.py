#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Heimkino - Einzelbefehle von der Kommandozeile

Wird von der Oberflaeche und vom Aktionsendpunkt aufgerufen. Gibt eine Zeile
aus und liefert 0 bei Erfolg, sonst 1 - damit laesst sich das Ergebnis auch
ohne Textauswertung erkennen.

  hk_cmd.py beamer-aus | beamer-wol | beamer-status | beamer-ipcontrol
  hk_cmd.py beamer-taste <name>
  hk_cmd.py beamer-eingang <name>          dtv atv cadtv catv avav1
                                           component1 hdmi1..hdmi4
  hk_cmd.py beamer-bild-aus | beamer-bild-an
  hk_cmd.py beamer-lautstaerke <0..100> | beamer-lautstaerke-lesen
  hk_cmd.py beamer-stumm-an | beamer-stumm-aus | beamer-stumm-lesen
  hk_cmd.py beamer-bildmodus <cinema|eco|filmMaker|game|normal|sports|vivid>
  hk_cmd.py beamer-energie <auto|screenoff|maximum|medium|minimum|off>
  hk_cmd.py beamer-app <name>
  hk_cmd.py beamer-mac [wired|wifi]        MAC AM GERAET auslesen
  hk_cmd.py kino-an | kino-aus             Szene, wird vom Dienst ausgefuehrt
  hk_cmd.py xbox-an | xbox-aus | xbox-status | xbox-konsolen | xbox-roh
  hk_cmd.py xbox-code                      liest den Code aus der Uebergabedatei
  hk_cmd.py xbox-vergessen | xbox-anmeldestatus

Zu xbox-code: der Autorisierungscode wird NICHT als Argument uebergeben.
Argumente stehen in /proc/<pid>/cmdline und sind fuer jeden lokalen Benutzer
lesbar; zusammen mit dem Clientgeheimnis laesst sich aus dem Code ein
Erneuerungstoken loesen.

Zu kino-an und kino-aus: hier wird NICHTS geschaltet, sondern nur ein Auftrag
hinterlegt. Den Ablauf fuehrt der Dienst aus - er ist die einzige Stelle, die
den Beamer befragen darf, weil das Geraet nur eine Verbindung zur Zeit
annimmt. Ausserdem hat ein virtueller Ausgang in Loxone ein Zeitlimit, und
eine Szene dauert laenger als das.
"""

import os
import sys
import time

import hk_common as gemein
from hk_common import P

# Wie lange ein Einzelbefehl auf die Geraetesperre wartet. Der Befehl kommt
# von Loxone oder vom Bediener und gewinnt gegen die Abfrage des Dienstes -
# die weicht ihrerseits ohne Warten aus. Zehn Sekunden sind reichlich: ein
# Abfragedurchgang dauert Bruchteile davon.
SPERRE_WARTEN = 10

# Erwarteter Zustand je Schaltbefehl und wie lange darauf gewartet wird.
# Der Dienst prueft nach und meldet das Ergebnis als <geraet>/letzte_aktion.
ZIELE = {
    "beamer-aus": ("beamer_aus", 90),
    "beamer-wol": ("beamer_an", 180),
    "xbox-an":    ("xbox_an", 120),
    "xbox-aus":   ("xbox_aus", 120),
}


def _beamer(cfg):
    from lg_beamer import LgBeamer
    return LgBeamer(
        gemein.wert(cfg, "beamer", "ip"),
        gemein.wert(cfg, "beamer", "keycode"),
        gemein.zahl(cfg, "beamer", "port", 9761, 1, 65535),
        gemein.zahl(cfg, "beamer", "zeitgrenze", 5, 1, 60),
        sperre=P["sperre"], sperre_warten=SPERRE_WARTEN,
    )


def _xbox(cfg, log):
    from xbox_cloud import XboxCloud
    return XboxCloud(P["auth"], log)


def _code_holen():
    """Den Autorisierungscode aus der Uebergabedatei holen und sie loeschen."""
    pfad = P["code"]
    try:
        with open(pfad, "r", encoding="utf-8") as datei:
            code = datei.read().strip()
    except OSError:
        return ""
    finally:
        try:
            os.unlink(pfad)
        except OSError:
            pass
    return code


def _auftrag(cfg, aktion, wert=""):
    """Den Dienst nachfassen lassen - sofern eingeschaltet."""
    if aktion not in ZIELE or not gemein.ja(cfg, "heimkino", "nachfassen"):
        return
    ziel, frist = ZIELE[aktion]
    gemein.auftrag_stellen(aktion, ziel, frist, wert)


def _wert(rest, was):
    if not rest:
        raise ValueError("Es fehlt der Wert fuer %s." % was)
    return rest[0]


def hauptteil(argumente):
    log = gemein.protokoll_einrichten("heimkino-cmd")
    cfg, lage = gemein.config_lesen(log)
    if lage == "keine_vorgaben":
        print("Fehler: bin/hk_vorgaben.json fehlt. Plugin neu installieren.")
        return 1
    if not argumente:
        print("Kein Befehl angegeben.")
        return 1
    befehl = argumente[0]
    rest = argumente[1:]

    try:
        if befehl == "beamer-aus":
            _beamer(cfg).aus()
            _auftrag(cfg, befehl)
            log.info("Beamer ausgeschaltet.")
            print("Beamer ausgeschaltet.")

        elif befehl == "beamer-erreichbar":
            # Auch dieser Griff geht an Port 9761 und nimmt deshalb dieselbe
            # Sperre. Bis 1.3.0 oeffnete die Oberflaeche hier selbst eine
            # Verbindung - ein dritter Prozess am Geraet, von dem die beiden
            # anderen nichts wussten.
            ip = gemein.wert(cfg, "beamer", "ip")
            port = gemein.zahl(cfg, "beamer", "port", 9761, 1, 65535)
            anfang = time.time()
            da, grund, text = gemein.erreichbarkeit(
                ip, port, 3, P["sperre"], SPERRE_WARTEN)
            print("%s:%d %s" % (ip or "(keine Adresse)", port,
                                "antwortet" if da else "antwortet nicht"))
            print("Grund: %s" % grund)
            print("%.0f ms" % ((time.time() - anfang) * 1000))
            print(text)
            if not da:
                return 1

        elif befehl == "beamer-status":
            print(_beamer(cfg).status())

        elif befehl == "beamer-ipcontrol":
            print("ein" if _beamer(cfg).ip_steuerung() else "aus")

        elif befehl == "beamer-wol":
            mac = gemein.wert(cfg, "beamer", "mac")
            anzahl = gemein.wol_senden(mac)
            _auftrag(cfg, befehl)
            print("Magic Packet gesendet (%d Byte)." % anzahl)

        elif befehl == "beamer-taste":
            _beamer(cfg).taste(_wert(rest, "beamer-taste"))
            print("Taste %s gesendet." % rest[0])

        elif befehl == "beamer-eingang":
            _beamer(cfg).eingang(_wert(rest, "beamer-eingang"))
            print("Eingang %s gewaehlt." % rest[0])

        # ---- ab 1.3.0 ----

        elif befehl == "beamer-bild-aus":
            # Bild aus, Geraet bleibt an: fuer eine Pause, die Tuerklingel
            # oder Licht an - ohne die Lampe herunter- und wieder hochzufahren.
            _beamer(cfg).bild_stumm("screenmuteon")
            print("Bild ausgeblendet (Geraet bleibt an).")

        elif befehl == "beamer-bild-an":
            _beamer(cfg).bild_stumm("allmuteoff")
            print("Bild wieder eingeblendet.")

        elif befehl == "beamer-lautstaerke":
            _beamer(cfg).lautstaerke_setzen(_wert(rest, "beamer-lautstaerke"))
            print("Lautstaerke auf %s gesetzt." % rest[0])

        elif befehl == "beamer-lautstaerke-lesen":
            print(_beamer(cfg).lautstaerke())

        elif befehl == "beamer-stumm-an":
            _beamer(cfg).stumm_setzen(True)
            print("Ton stumm.")

        elif befehl == "beamer-stumm-aus":
            _beamer(cfg).stumm_setzen(False)
            print("Ton wieder an.")

        elif befehl == "beamer-stumm-lesen":
            print("stumm" if _beamer(cfg).stumm() else "nicht stumm")

        elif befehl == "beamer-bildmodus":
            _beamer(cfg).bildmodus(_wert(rest, "beamer-bildmodus"))
            print("Bildmodus %s gesetzt." % rest[0])

        elif befehl == "beamer-energie":
            _beamer(cfg).energiesparen(_wert(rest, "beamer-energie"))
            print("Energiesparstufe %s gesetzt." % rest[0])

        elif befehl == "beamer-app":
            _beamer(cfg).app_starten(_wert(rest, "beamer-app"))
            print("Anwendung %s gestartet." % rest[0])

        elif befehl == "beamer-mac":
            art = rest[0] if rest else "wired"
            gelesen = _beamer(cfg).mac_lesen(art)
            eingetragen = gemein.wert(cfg, "beamer", "mac")
            def nackt(s):
                return "".join(z for z in (s or "").lower()
                               if z in "0123456789abcdef")
            print("Am Geraet (%s): %s" % (art, gelesen))
            print("Eingetragen    : %s" % (eingetragen or "(nichts)"))
            if not eingetragen:
                print("Vergleich      : nichts eingetragen - Wake-on-LAN kann "
                      "nicht wirken.")
                return 1
            if nackt(gelesen) == nackt(eingetragen):
                print("Vergleich      : gleich.")
            else:
                print("Vergleich      : ABWEICHUNG. Wake-on-LAN geht damit ins "
                      "Leere, ohne dass es auffaellt.")
                return 1

        elif befehl in ("kino-an", "kino-aus"):
            if not gemein.ja(cfg, "szene", "aktiv"):
                print("Die Kino-Szene ist in den Einstellungen abgeschaltet.")
                return 1
            # Nur den Auftrag hinterlegen - siehe Kopf dieser Datei.
            if not gemein.auftrag_stellen(befehl, "szene", 600):
                print("Der Auftrag liess sich nicht ablegen (%s)." % P["auftrag"])
                return 1
            log.info("Szene %s angenommen.", befehl)
            print("Szene %s angenommen - der Dienst fuehrt sie aus. Der Ablauf "
                  "steht in szene/schritt, das Ergebnis in szene/ergebnis."
                  % befehl)

        elif befehl == "xbox-an":
            kennung = gemein.wert(cfg, "xbox", "geraete_id")
            _xbox(cfg, log).wecken(kennung)
            _auftrag(cfg, befehl)
            log.info("Weckbefehl an die Xbox gesendet.")
            print("Weckbefehl gesendet.")

        elif befehl == "xbox-aus":
            kennung = gemein.wert(cfg, "xbox", "geraete_id")
            _xbox(cfg, log).ausschalten(kennung)
            _auftrag(cfg, befehl)
            log.info("Ausschaltbefehl an die Xbox gesendet.")
            print("Ausschaltbefehl gesendet.")

        elif befehl == "xbox-status":
            kennung = gemein.wert(cfg, "xbox", "geraete_id")
            print(_xbox(cfg, log).status(kennung)["status"])

        elif befehl == "xbox-roh":
            # Die Antwort der Cloud im Original. Erst messen, dann festlegen,
            # was daraus als eigenes Thema hinausgeht - alles andere waere
            # geraten.
            import json
            kennung = gemein.wert(cfg, "xbox", "geraete_id")
            print(json.dumps(_xbox(cfg, log).roh(kennung),
                             ensure_ascii=False, indent=1))

        elif befehl == "xbox-code":
            code = _code_holen()
            if not code:
                print("Es lag kein Code in der Uebergabedatei (%s). Wurde die "
                      "Adresse im Reiter Einstellungen eingetragen?" % P["code"])
                return 1
            _xbox(cfg, log).code_einloesen(code)
            log.info("Anmeldung bei Microsoft erfolgreich.")
            print("Anmeldung erfolgreich.")

        elif befehl == "xbox-vergessen":
            _xbox(cfg, log).vergessen()
            log.info("Xbox-Anmeldung geloescht.")
            print("Anmeldung geloescht.")

        elif befehl == "xbox-anmeldestatus":
            wolke = _xbox(cfg, log)
            print("eingerichtet=%d angemeldet=%d"
                  % (1 if wolke.eingerichtet else 0,
                     1 if wolke.angemeldet else 0))

        elif befehl == "xbox-konsolen":
            for eintrag in _xbox(cfg, log).konsolen():
                print("%s\t%s\t%s\t%s" % (eintrag["id"], eintrag["name"],
                                          eintrag["typ"], eintrag["status"]))

        else:
            print("Unbekannter Befehl: %s" % befehl)
            return 1

    except Exception as fehler:            # noqa: BLE001 - eine Zeile fuer alles
        log.error("%s: %s", befehl, fehler)
        print("Fehler: %s" % fehler)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(hauptteil(sys.argv[1:]))
