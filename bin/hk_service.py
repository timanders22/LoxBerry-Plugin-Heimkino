#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Heimkino - Dienst

Fragt in festem Takt den Zustand beider Geraete ab, schreibt ihn in eine
Zustandsdatei fuer die Oberflaeche und meldet ihn per MQTT retained an den
Miniserver. Der Dienst schaltet von sich aus nichts - das tut nur, wer
hk_cmd.py oder den Aktionsendpunkt aufruft.

Der Beamer wird bewusst zurueckhaltend befragt: er nimmt nur eine Verbindung
zur Zeit an. Ein zu kurzer Takt wuerde die Fernbedienung der App aussperren.
"""

import datetime
import os
import signal
import sys
import time

import hk_common as gemein
from hk_common import P

LAEUFT = True


def _abbruch(nummer, rahmen):        # noqa: ARG001
    global LAEUFT
    LAEUFT = False


def beamer_abfragen(cfg, log, meldungen):
    ergebnis = {"aktiv": False, "erreichbar": False, "status": "unbekannt",
                "app": "", "ip_control": "", "fehler": ""}
    if not gemein.ja(cfg, "beamer", "aktiv"):
        return ergebnis
    ergebnis["aktiv"] = True
    ip = gemein.wert(cfg, "beamer", "ip")
    port = gemein.zahl(cfg, "beamer", "port", 9761, 1, 65535)
    grenze = gemein.zahl(cfg, "beamer", "zeitgrenze", 5, 1, 60)
    ergebnis["erreichbar"] = gemein.erreichbar(ip, port, min(grenze, 3))
    if not ergebnis["erreichbar"]:
        # Kein Fehler: ein ausgeschalteter Beamer im Tiefschlaf antwortet
        # nicht. Das ist der Normalfall und keine Meldung wert.
        ergebnis["status"] = "aus"
        return ergebnis
    try:
        from lg_beamer import LgBeamer
        geraet = LgBeamer(ip, gemein.wert(cfg, "beamer", "keycode"), port, grenze)
        app = geraet.aktuelle_app()
        ergebnis["status"] = "an" if app is not None else "aus"
        ergebnis["app"] = app or ""
    except Exception as fehler:          # noqa: BLE001
        ergebnis["fehler"] = str(fehler)
        gemein.einmal_melden(meldungen, "beamer",
                             "Beamer antwortet nicht wie erwartet: %s" % fehler,
                             log, "warning")
    return ergebnis


def geheimnis_restlaufzeit(cfg):
    """Restlaufzeit des Azure-Clientgeheimnisses.

    Azure vergibt hoechstens 24 Monate. Laeuft der Schluessel ab, antwortet
    Microsoft mit invalid_client und die Konsole laesst sich nicht mehr wecken -
    zwei Jahre nach der Einrichtung, wenn niemand mehr daran denkt. Deshalb
    meldet der Dienst die Restlaufzeit, damit Loxone rechtzeitig warnen kann.

    Gibt (datum_text, tage) zurueck; ("", "") wenn kein Datum hinterlegt ist.
    """
    rohtext = (gemein.wert(cfg, "xbox", "geheimnis_ablauf", "") or "").strip()
    if not rohtext:
        return "", ""
    try:
        ziel = datetime.datetime.strptime(rohtext, "%Y-%m-%d").date()
    except ValueError:
        return "", ""
    return ziel.isoformat(), (ziel - datetime.date.today()).days


def xbox_abfragen(cfg, log, meldungen):
    ergebnis = {"aktiv": False, "status": "unbekannt", "angemeldet": False,
                "fehler": "", "quelle": ""}
    if not gemein.ja(cfg, "xbox", "aktiv"):
        return ergebnis
    ergebnis["aktiv"] = True
    kennung = gemein.wert(cfg, "xbox", "geraete_id")
    try:
        from xbox_cloud import XboxCloud
        wolke = XboxCloud(P["auth"], log)
        ergebnis["angemeldet"] = wolke.angemeldet
        if not wolke.angemeldet:
            gemein.einmal_melden(meldungen, "xbox_anmeldung",
                                 "Xbox: noch nicht angemeldet - Reiter "
                                 "Einstellungen.", log, "warning")
            return ergebnis
        if not kennung:
            gemein.einmal_melden(meldungen, "xbox_kennung",
                                 "Xbox: keine XBOX-Netzwerk-Geraeteidentitaet eingetragen.",
                                 log, "warning")
            return ergebnis
        auskunft = wolke.status(kennung)
        ergebnis["status"] = auskunft["status"]
        ergebnis["quelle"] = auskunft.get("quelle", "")
    except Exception as fehler:          # noqa: BLE001
        ergebnis["fehler"] = str(fehler)
        gemein.einmal_melden(meldungen, "xbox",
                             "Xbox-Cloud nicht erreichbar: %s" % fehler,
                             log, "warning")
    return ergebnis


def hauptteil():
    log = gemein.protokoll_einrichten("heimkino")
    signal.signal(signal.SIGTERM, _abbruch)
    signal.signal(signal.SIGINT, _abbruch)

    cfg = gemein.config_lesen()
    if not gemein.ja(cfg, "heimkino", "enabled"):
        log.info("Das Plugin ist in den Einstellungen abgeschaltet - beende.")
        return 0

    praefix = gemein.wert(cfg, "heimkino", "themenpraefix", "heimkino") or "heimkino"
    takt = gemein.zahl(cfg, "heimkino", "intervall", 60, 10, 3600)
    melder = gemein.Melder(praefix, log, gemein.ja(cfg, "heimkino", "mqtt"))
    meldungen = {}

    fassung = gemein.version()
    log.info("Heimkino%s gestartet, Takt %d s, Themenpraefix %s",
             (" " + fassung) if fassung else "", takt, praefix)
    melder.sende("service/online", 1)

    letzte_config = 0.0
    try:
        while LAEUFT:
            # Konfiguration bei jedem Durchlauf neu lesen: nach dem Speichern
            # in der Oberflaeche soll der Dienst ohne Neustart mitziehen.
            try:
                geaendert = os.path.getmtime(P["config"])
            except OSError:
                geaendert = 0.0
            if geaendert != letzte_config:
                cfg = gemein.config_lesen()
                letzte_config = geaendert
                takt = gemein.zahl(cfg, "heimkino", "intervall", 60, 10, 3600)

            beamer = beamer_abfragen(cfg, log, meldungen)
            xbox = xbox_abfragen(cfg, log, meldungen)
            ablauf_datum, ablauf_tage = geheimnis_restlaufzeit(cfg)
            if ablauf_tage != "" and ablauf_tage <= 60:
                gemein.einmal_melden(
                    meldungen, "xbox_geheimnis_ablauf",
                    ("Xbox: das Azure-Clientgeheimnis ist seit %d Tagen abgelaufen - "
                     "ein neues anlegen und die Anmeldung wiederholen."
                     % abs(ablauf_tage)) if ablauf_tage < 0 else
                    ("Xbox: das Azure-Clientgeheimnis laeuft in %d Tagen ab (%s)."
                     % (ablauf_tage, ablauf_datum)),
                    log, "warning", wieder_nach=86400)
            jetzt = time.time()

            gemein.zustand_schreiben({
                "zeit": jetzt,
                "zeit_text": time.strftime("%d.%m.%Y %H:%M:%S",
                                           time.localtime(jetzt)),
                "beamer": beamer,
                "xbox": xbox,
                "geheimnis_ablauf": ablauf_datum,
                "geheimnis_tage": ablauf_tage,
                "takt": takt,
            })

            melder.sende_viele({
                "service/online": 1,
                "beamer/aktiv": beamer["aktiv"],
                "beamer/erreichbar": beamer["erreichbar"],
                "beamer/status": beamer["status"],
                "beamer/an": 1 if beamer["status"] == "an" else 0,
                "beamer/app": beamer["app"],
                "xbox/aktiv": xbox["aktiv"],
                "xbox/status": xbox["status"],
                "xbox/an": 1 if xbox["status"] in ("On", "on") else 0,
                "xbox/angemeldet": xbox["angemeldet"],
                "xbox/geheimnis_ablauf": ablauf_datum,
                "xbox/geheimnis_tage": ablauf_tage,
                "last_error": beamer["fehler"] or xbox["fehler"] or "",
            })

            # In kleinen Schritten warten, damit ein Stopp sofort greift.
            for _ in range(takt):
                if not LAEUFT:
                    break
                time.sleep(1)
    finally:
        melder.sende("service/online", 0)
        melder.schliessen()
        log.info("Heimkino beendet.")
    return 0


if __name__ == "__main__":
    sys.exit(hauptteil())
