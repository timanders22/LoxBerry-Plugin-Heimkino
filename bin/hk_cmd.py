#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Heimkino - Einzelbefehle von der Kommandozeile

Wird von der Oberflaeche und vom Aktionsendpunkt aufgerufen. Gibt eine Zeile
aus und liefert 0 bei Erfolg, sonst 1 - damit laesst sich das Ergebnis auch
ohne Textauswertung erkennen.

  hk_cmd.py beamer-aus
  hk_cmd.py beamer-status
  hk_cmd.py beamer-ipcontrol
  hk_cmd.py beamer-wol
  hk_cmd.py beamer-taste <name>
  hk_cmd.py beamer-eingang <name>
  hk_cmd.py xbox-an
  hk_cmd.py xbox-aus
  hk_cmd.py xbox-status
  hk_cmd.py xbox-konsolen
  hk_cmd.py xbox-code <code oder zurueckgeleitete Adresse>
  hk_cmd.py xbox-vergessen
  hk_cmd.py xbox-anmeldestatus
"""

import sys

import hk_common as gemein
from hk_common import P


def _beamer(cfg):
    from lg_beamer import LgBeamer
    return LgBeamer(
        gemein.wert(cfg, "beamer", "ip"),
        gemein.wert(cfg, "beamer", "keycode"),
        gemein.zahl(cfg, "beamer", "port", 9761, 1, 65535),
        gemein.zahl(cfg, "beamer", "zeitgrenze", 5, 1, 60),
    )


def _xbox(cfg, log):
    from xbox_cloud import XboxCloud
    return XboxCloud(P["auth"], log)


def hauptteil(argumente):
    log = gemein.protokoll_einrichten("heimkino-cmd")
    cfg = gemein.config_lesen()
    if not argumente:
        print("Kein Befehl angegeben.")
        return 1
    befehl = argumente[0]
    rest = argumente[1:]

    try:
        if befehl == "beamer-aus":
            _beamer(cfg).aus()
            log.info("Beamer ausgeschaltet.")
            print("Beamer ausgeschaltet.")

        elif befehl == "beamer-status":
            print(_beamer(cfg).status())

        elif befehl == "beamer-ipcontrol":
            print("ein" if _beamer(cfg).ip_steuerung() else "aus")

        elif befehl == "beamer-wol":
            mac = gemein.wert(cfg, "beamer", "mac")
            anzahl = gemein.wol_senden(mac)
            print("Magic Packet gesendet (%d Byte)." % anzahl)

        elif befehl == "beamer-taste":
            if not rest:
                print("Es fehlt der Tastenname.")
                return 1
            _beamer(cfg).taste(rest[0])
            print("Taste %s gesendet." % rest[0])

        elif befehl == "beamer-eingang":
            if not rest:
                print("Es fehlt der Eingangsname.")
                return 1
            _beamer(cfg).eingang(rest[0])
            print("Eingang %s gewaehlt." % rest[0])

        elif befehl == "xbox-an":
            kennung = gemein.wert(cfg, "xbox", "geraete_id")
            _xbox(cfg, log).wecken(kennung)
            log.info("Weckbefehl an die Xbox gesendet.")
            print("Weckbefehl gesendet.")

        elif befehl == "xbox-aus":
            kennung = gemein.wert(cfg, "xbox", "geraete_id")
            _xbox(cfg, log).ausschalten(kennung)
            log.info("Ausschaltbefehl an die Xbox gesendet.")
            print("Ausschaltbefehl gesendet.")

        elif befehl == "xbox-status":
            kennung = gemein.wert(cfg, "xbox", "geraete_id")
            print(_xbox(cfg, log).status(kennung)["status"])

        elif befehl == "xbox-code":
            if not rest:
                print("Es fehlt der Code oder die zurueckgeleitete Adresse.")
                return 1
            wolke = _xbox(cfg, log)
            wolke.code_einloesen(rest[0])
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
