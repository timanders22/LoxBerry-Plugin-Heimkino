#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Heimkino - Dienst

Fragt in festem Takt den Zustand beider Geraete ab, schreibt ihn in eine
Zustandsdatei fuer die Oberflaeche und meldet ihn per MQTT retained an den
Miniserver.

Seit 1.3.0 tut er zwei Dinge mehr, und beide aus demselben Grund: er ist die
EINZIGE Stelle, die den Beamer befragen darf, weil das Geraet nur eine
Verbindung zur Zeit annimmt.

  - Er fasst nach. Ein Schaltbefehl aus dem Aktionsendpunkt hinterlegt einen
    Auftrag; der Dienst prueft, ob der erwartete Zustand wirklich eintritt,
    und meldet das Ergebnis. Bis 1.2.12 galt ein Befehl als gelungen, sobald
    das Geraet "OK" gesagt hatte - das ist die Annahme der Wirkung, nicht die
    Wirkung.
  - Er fuehrt die Kino-Szene aus. Die Wartebedingungen ("bis der Beamer
    wirklich antwortet") liessen sich in Loxone nur mit geratenen
    Zeitgliedern nachbauen.

Der Beamer wird bewusst zurueckhaltend befragt: ein zu kurzer Takt sperrt die
Fernbedienung der App aus. Nur solange ein Auftrag offen ist, wird der Takt
voruebergehend verkuerzt.

Aufrufe von aussen:
  hk_service.py               Dienst starten
  hk_service.py --vorgaben    Vorgabeliste als JSON (fuer die Selbstpruefung)
  hk_service.py --themen      gesendete Themen als JSON (fuer die Selbstpruefung)
"""

import datetime
import json
import os
import signal
import sys
import time

import hk_common as gemein
from hk_common import P
from hk_sperre import Sperre, SperreBesetzt

LAEUFT = True

# Takt, solange ein Auftrag offen ist oder eine Szene laeuft. Kurz genug,
# damit die Rueckmeldung brauchbar ist, lang genug, dass der Beamer nicht in
# Dauerbefragung geraet.
TAKT_NACHFASSEN = 5


def _abbruch(nummer, rahmen):        # noqa: ARG001
    global LAEUFT
    LAEUFT = False


def _warten(sekunden):
    """In Ein-Sekunden-Schritten warten, damit ein Stopp sofort greift."""
    ende = time.time() + sekunden
    while LAEUFT and time.time() < ende:
        time.sleep(min(1.0, max(0.0, ende - time.time())))
    return LAEUFT


# --------------------------------------------------------------------------
# Abfragen
# --------------------------------------------------------------------------

def beamer_abfragen(cfg, log, meldungen, vorher=None):
    ergebnis = {"aktiv": False, "erreichbar": False, "status": "unbekannt",
                "app": "", "grund": "aus", "grund_text": "", "fehler": "",
                "lautstaerke": -1, "stumm": -1}
    if not gemein.ja(cfg, "beamer", "aktiv"):
        ergebnis["grund_text"] = gemein.GRUND_TEXT["aus"]
        return ergebnis
    ergebnis["aktiv"] = True
    ip = gemein.wert(cfg, "beamer", "ip")
    port = gemein.zahl(cfg, "beamer", "port", 9761, 1, 65535)
    grenze = gemein.zahl(cfg, "beamer", "zeitgrenze", 5, 1, 60)

    # DER BEFEHL GEWINNT, DIE ABFRAGE WEICHT.
    #
    # Die Sperre wird OHNE Warten genommen: spricht gerade ein Einzelbefehl
    # aus dem Aktionsendpunkt mit dem Geraet, wird dieser Durchgang
    # uebersprungen. Ein Einzelbefehl kommt von Loxone oder vom Bediener und
    # wiegt schwerer als eine Abfrage, die in sechzig Sekunden ohnehin
    # wiederkommt.
    #
    # Und ein uebersprungener Durchgang ist KEIN Ausfall: die zuletzt
    # gemeldeten Werte bleiben unveraendert stehen. Ihn als "unbekannt" zu
    # melden oder last_error zu setzen hiesse, eine stille Falschaussage
    # durch eine laute zu ersetzen.
    try:
        sperre = Sperre(P["sperre"], warten=0)
        sperre.__enter__()
    except SperreBesetzt:
        gemein.einmal_melden(
            meldungen, "beamer_besetzt",
            "Beamer: ein anderer Vorgang sprach gerade mit dem Geraet, der "
            "Durchgang wurde uebersprungen. Die zuletzt gemeldeten Werte "
            "bleiben stehen.", log, "info", wieder_nach=600)
        if isinstance(vorher, dict) and vorher.get("aktiv"):
            return dict(vorher)
        ergebnis["grund"] = "besetzt"
        ergebnis["grund_text"] = gemein.GRUND_TEXT["besetzt"]
        return ergebnis
    try:
        return _beamer_abfragen_gesperrt(cfg, log, meldungen, ergebnis,
                                         ip, port, grenze)
    finally:
        sperre.__exit__(None, None, None)


def _beamer_abfragen_gesperrt(cfg, log, meldungen, ergebnis, ip, port, grenze):
    """Der eigentliche Durchgang - laeuft nur mit gehaltener Sperre.

    Die Sperre ist im selben Prozess wiedereintrittsfaehig; die Aufrufe
    weiter unten duerfen sie also erneut nehmen.
    """

    # Bis 1.2.11 stand hier ein blosses True/False. Jeder Fehler - falsche
    # IP, unaufloesbarer Name, schweigende Firewall - wurde damit zu
    # status "aus", an = 0 und einem LEEREN last_error. In Loxone sah ein
    # Defekt aus wie der Normalzustand. Jetzt sagt der Grund, WER nicht
    # geantwortet hat.
    da, grund, grundtext = gemein.erreichbarkeit(ip, port, min(grenze, 3),
                                                 P["sperre"], 0)
    ergebnis["erreichbar"] = da
    ergebnis["grund"] = grund
    ergebnis["grund_text"] = grundtext
    if not da:
        if grund == "abgewiesen":
            # Der einzige harmlose Fall: ein ausgeschalteter Beamer weist die
            # Verbindung ab. Das ist der Normalfall und keine Meldung wert.
            ergebnis["status"] = "aus"
        else:
            ergebnis["status"] = "unbekannt"
            ergebnis["fehler"] = grundtext
            gemein.einmal_melden(meldungen, "beamer_erreichbarkeit",
                                 "Beamer %s:%d - %s" % (ip or "(keine Adresse)",
                                                        port, grundtext),
                                 log, "warning")
        return ergebnis
    try:
        from lg_beamer import LgBeamer
        geraet = LgBeamer(ip, gemein.wert(cfg, "beamer", "keycode"), port,
                          grenze, sperre=P["sperre"], sperre_warten=0)
        app = geraet.aktuelle_app()
        ergebnis["status"] = "an" if app is not None else "aus"
        ergebnis["app"] = app or ""
    except Exception as fehler:          # noqa: BLE001
        ergebnis["status"] = "unbekannt"
        ergebnis["fehler"] = str(fehler)
        ergebnis["grund"] = "fehler"
        ergebnis["grund_text"] = str(fehler)
        gemein.einmal_melden(meldungen, "beamer",
                             "Beamer antwortet nicht wie erwartet: %s" % fehler,
                             log, "warning")
        return ergebnis

    # Zusatzwerte sind AUSDRUECKLICH abschaltbar und ab Werk aus.
    #
    # CURRENT_VOL und MUTE_STATE sind an einem LG-FERNSEHER belegt. Ob ein
    # Beamer sie kennt, ist hier nicht gemessen - er koennte etwas anderes
    # antworten. Deshalb: nur auf Wunsch, nur wenn das Geraet laeuft, und ein
    # Fehlschlag setzt NICHT last_error. Sonst machte eine Bequemlichkeit die
    # Stoerungsmeldung unbrauchbar.
    if ergebnis["status"] == "an" and gemein.ja(cfg, "beamer", "zusatzwerte"):
        try:
            ergebnis["lautstaerke"] = geraet.lautstaerke()
            ergebnis["stumm"] = 1 if geraet.stumm() else 0
        except Exception as fehler:      # noqa: BLE001
            gemein.einmal_melden(
                meldungen, "beamer_zusatz",
                "Beamer: Lautstaerke und Stummschaltung liessen sich nicht "
                "lesen (%s). Das ist kein Ausfall - viele Beamer kennen diese "
                "Befehle nicht. In den Einstellungen abschaltbar." % fehler,
                log, "info", wieder_nach=86400)
    return ergebnis


def geheimnis_restlaufzeit(cfg, log=None, meldungen=None):
    """Restlaufzeit des Azure-Clientgeheimnisses.

    Azure vergibt hoechstens 24 Monate. Laeuft der Schluessel ab, antwortet
    Microsoft mit invalid_client und die Konsole laesst sich nicht mehr wecken -
    zwei Jahre nach der Einrichtung, wenn niemand mehr daran denkt.

    Gibt (datum_text, tage) zurueck; ("", "") wenn kein Datum hinterlegt ist.
    """
    rohtext = (gemein.wert(cfg, "xbox", "geheimnis_ablauf", "") or "").strip()
    if not rohtext:
        return "", ""
    try:
        ziel = datetime.datetime.strptime(rohtext, "%Y-%m-%d").date()
    except ValueError:
        # Bis 1.2.11 verschwand die Warnung hier lautlos - also genau die
        # Aufgabe, fuer die dieses Feld angelegt wurde.
        if log is not None and meldungen is not None:
            gemein.einmal_melden(
                meldungen, "xbox_ablauf_unlesbar",
                "Xbox: das eingetragene Ablaufdatum %r ist unlesbar (erwartet "
                "wird JJJJ-MM-TT). Es wird deshalb NICHT vor dem Ablauf des "
                "Clientgeheimnisses gewarnt." % rohtext, log, "warning",
                wieder_nach=86400)
        return "", ""
    return ziel.isoformat(), (ziel - datetime.date.today()).days


def xbox_abfragen(cfg, log, meldungen):
    ergebnis = {"aktiv": False, "status": "unbekannt", "angemeldet": False,
                "fehler": "", "quelle": "", "name": ""}
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
                                 "Xbox: keine XBOX-Netzwerk-Geraeteidentitaet "
                                 "eingetragen.", log, "warning")
            return ergebnis
        auskunft = wolke.status(kennung)
        ergebnis["status"] = auskunft["status"]
        ergebnis["quelle"] = auskunft.get("quelle", "")
        # Der Name kam schon bisher mit und wurde weggeworfen.
        ergebnis["name"] = auskunft.get("name", "")
    except Exception as fehler:          # noqa: BLE001
        ergebnis["fehler"] = str(fehler)
        # Ein abgelaufenes Erneuerungstoken hat die Anmeldung bereits
        # verworfen (siehe xbox_cloud._token_uebernehmen). Dann darf hier
        # nicht weiter "angemeldet" stehen.
        try:
            from xbox_cloud import XboxCloud as _X
            ergebnis["angemeldet"] = _X(P["auth"], log).angemeldet
        except Exception:                # noqa: BLE001
            pass
        gemein.einmal_melden(meldungen, "xbox",
                             "Xbox-Cloud: %s" % fehler, log, "warning")
    return ergebnis


# --------------------------------------------------------------------------
# Nachfassen: hat der Befehl gewirkt?
# --------------------------------------------------------------------------

def auftrag_erfuellt(ziel, beamer, xbox):
    """Ist der erwartete Zustand eingetreten? True, False oder None.

    None heisst "noch nicht feststellbar" - etwa wenn der Beamer gerade gar
    nicht antwortet. Das ist etwas anderes als "nicht erfuellt" und darf
    nicht als Fehlschlag durchgehen.
    """
    if ziel == "beamer_aus":
        if beamer["status"] == "aus":
            return True
        return None if beamer["status"] == "unbekannt" else False
    if ziel == "beamer_an":
        if beamer["erreichbar"]:
            return True
        return False if beamer["grund"] == "abgewiesen" else None
    if ziel == "xbox_an":
        if beamer is not None and xbox["status"] in ("On", "on"):
            return True
        return None if xbox["status"] == "unbekannt" else False
    if ziel == "xbox_aus":
        if xbox["status"] == "unbekannt":
            return None
        return xbox["status"] not in ("On", "on")
    return None


def nachfassen(auftrag, beamer, xbox, melder, log):
    """Einen offenen Auftrag beurteilen. Gibt True, wenn er erledigt ist."""
    ziel = str(auftrag.get("ziel", ""))
    aktion = str(auftrag.get("aktion", ""))
    seit = time.time() - float(auftrag.get("gestellt", 0))
    frist = float(auftrag.get("frist", 120))
    thema = "xbox/letzte_aktion" if ziel.startswith("xbox") else "beamer/letzte_aktion"

    stand = auftrag_erfuellt(ziel, beamer, xbox)
    if stand is True:
        melder.sende(thema, "%s gewirkt nach %d s" % (aktion, int(seit)))
        log.info("%s: gewirkt nach %d s.", aktion, int(seit))
        return True
    if seit >= frist:
        text = ("%s OHNE WIRKUNG nach %d s" % (aktion, int(seit))
                if stand is False else
                "%s nicht feststellbar nach %d s" % (aktion, int(seit)))
        melder.sende(thema, text)
        log.warning("%s", text)
        return True
    return False


# --------------------------------------------------------------------------
# Kino-Szene
# --------------------------------------------------------------------------

def _beamer_objekt(cfg):
    from lg_beamer import LgBeamer
    # Die Szene laeuft im Dienst und darf warten: sie ist eine Bedienhandlung
    # wie ein Einzelbefehl, keine Abfrage.
    return LgBeamer(gemein.wert(cfg, "beamer", "ip"),
                    gemein.wert(cfg, "beamer", "keycode"),
                    gemein.zahl(cfg, "beamer", "port", 9761, 1, 65535),
                    gemein.zahl(cfg, "beamer", "zeitgrenze", 5, 1, 60),
                    sperre=P["sperre"], sperre_warten=15)


def _warten_auf_beamer(cfg, sekunden):
    """Warten, bis der Steuerport antwortet. Gibt die Dauer oder None zurueck."""
    ip = gemein.wert(cfg, "beamer", "ip")
    port = gemein.zahl(cfg, "beamer", "port", 9761, 1, 65535)
    anfang = time.time()
    while LAEUFT and (time.time() - anfang) < sekunden:
        if gemein.erreichbarkeit(ip, port, 2, P["sperre"], 5)[0]:
            return time.time() - anfang
        if not _warten(3):
            break
    return None


def _warten_auf_xbox(cfg, log, sekunden):
    kennung = gemein.wert(cfg, "xbox", "geraete_id")
    anfang = time.time()
    while LAEUFT and (time.time() - anfang) < sekunden:
        try:
            from xbox_cloud import XboxCloud
            if XboxCloud(P["auth"], log).status(kennung)["status"] in ("On", "on"):
                return time.time() - anfang
        except Exception:                # noqa: BLE001
            pass
        if not _warten(5):
            break
    return None


def szene_ausfuehren(aktion, cfg, melder, log):
    """Kino an oder aus - Schritt fuer Schritt, mit echten Wartebedingungen.

    Der Gewinn gegenueber einer Nachbildung in Loxone: dort liessen sich die
    Bedingungen nur mit Zeitgliedern RATEN. Hier wird gewartet, bis der Port
    wirklich antwortet und die Konsole wirklich On meldet.

    Jeder Schritt geht als szene/schritt hinaus, damit in der App sichtbar
    ist, wo es klemmt.
    """
    anfang = time.time()
    beamer_an = gemein.ja(cfg, "beamer", "aktiv")
    xbox_an = gemein.ja(cfg, "xbox", "aktiv")
    w_beamer = gemein.zahl(cfg, "szene", "warten_beamer", 120, 10, 600)
    w_xbox = gemein.zahl(cfg, "szene", "warten_xbox", 90, 10, 600)
    fehler = []

    melder.sende("szene/laeuft", 1)
    melder.sende("szene/ergebnis", "-")

    def schritt(text):
        melder.sende("szene/schritt", text)
        log.info("Szene %s: %s", aktion, text)

    try:
        if aktion == "kino-an":
            if beamer_an:
                mac = gemein.wert(cfg, "beamer", "mac")
                if mac:
                    schritt("Beamer wecken (Wake-on-LAN)")
                    try:
                        gemein.wol_senden(mac)
                    except ValueError as f:
                        fehler.append("WoL: %s" % f)
                schritt("warten, bis der Beamer antwortet")
                dauer = _warten_auf_beamer(cfg, w_beamer)
                if dauer is None:
                    fehler.append("Beamer kam innerhalb von %d s nicht hoch" % w_beamer)
                else:
                    schritt("Beamer antwortet nach %d s" % int(dauer))
                    geraet = _beamer_objekt(cfg)
                    eingang = gemein.wert(cfg, "szene", "eingang")
                    if eingang:
                        schritt("Eingang waehlen: %s" % eingang)
                        try:
                            geraet.eingang(eingang)
                        except Exception as f:      # noqa: BLE001
                            fehler.append("Eingang: %s" % f)
                    modus = gemein.wert(cfg, "szene", "bildmodus")
                    if modus:
                        schritt("Bildmodus setzen: %s" % modus)
                        try:
                            geraet.bildmodus(modus)
                        except Exception as f:      # noqa: BLE001
                            fehler.append("Bildmodus: %s" % f)
            if xbox_an and LAEUFT:
                schritt("Xbox wecken")
                try:
                    from xbox_cloud import XboxCloud
                    XboxCloud(P["auth"], log).wecken(
                        gemein.wert(cfg, "xbox", "geraete_id"))
                except Exception as f:              # noqa: BLE001
                    fehler.append("Xbox wecken: %s" % f)
                else:
                    schritt("warten, bis die Konsole On meldet")
                    dauer = _warten_auf_xbox(cfg, log, w_xbox)
                    if dauer is None:
                        fehler.append("Konsole meldete innerhalb von %d s kein On" % w_xbox)
                    else:
                        schritt("Konsole ist an nach %d s" % int(dauer))

        elif aktion == "kino-aus":
            # Erst die Konsole, dann der Beamer: haengt die Konsole per CEC am
            # Verstaerker, ist die Reihenfolge die freundlichere.
            if xbox_an:
                schritt("Xbox ausschalten")
                try:
                    from xbox_cloud import XboxCloud
                    XboxCloud(P["auth"], log).ausschalten(
                        gemein.wert(cfg, "xbox", "geraete_id"))
                except Exception as f:              # noqa: BLE001
                    fehler.append("Xbox ausschalten: %s" % f)
            if beamer_an and LAEUFT:
                schritt("Beamer ausschalten")
                try:
                    _beamer_objekt(cfg).aus()
                except Exception as f:              # noqa: BLE001
                    fehler.append("Beamer ausschalten: %s" % f)
        else:
            fehler.append("unbekannte Szene %r" % aktion)
    finally:
        dauer = int(time.time() - anfang)
        if not LAEUFT:
            ergebnis = "%s abgebrochen - der Dienst wurde beendet" % aktion
        elif fehler:
            ergebnis = "%s mit Beanstandung nach %d s: %s" % (
                aktion, dauer, "; ".join(fehler))
        else:
            ergebnis = "%s vollstaendig nach %d s" % (aktion, dauer)
        melder.sende("szene/schritt", "-")
        melder.sende("szene/ergebnis", ergebnis)
        melder.sende("szene/laeuft", 0)
        (log.warning if fehler else log.info)("Szene: %s", ergebnis)
    return not fehler


# --------------------------------------------------------------------------
# Werte
# --------------------------------------------------------------------------

def mqtt_werte(beamer, xbox, ablauf_datum, ablauf_tage, jetzt,
               b_stunden=0.0, b_heute=0, x_stunden=0.0, x_heute=0):
    """Die zu sendenden Werte - EINE Stelle, aus der auch --themen kommt.

    Die Themennamen selbst stehen in bin/hk_themen.json und werden von der
    Oberflaeche aus derselben Datei angezeigt.

    Nicht enthalten sind die Themen, die nur bei einem Ereignis hinausgehen:
    beamer/letzte_aktion, xbox/letzte_aktion und die drei szene/-Themen. Sie
    stehen in der Themenliste und werden von --themen mitgezaehlt.
    """
    fehler = []
    if beamer["fehler"]:
        fehler.append("beamer: " + beamer["fehler"])
    if xbox["fehler"]:
        fehler.append("xbox: " + xbox["fehler"])
    return {
        "service/online": 1,
        "service/zeitstempel": int(jetzt),
        "last_error": " | ".join(fehler),
        "beamer/aktiv": beamer["aktiv"],
        "beamer/erreichbar": beamer["erreichbar"],
        "beamer/grund": beamer["grund"],
        "beamer/status": beamer["status"],
        "beamer/an": 1 if beamer["status"] == "an" else 0,
        "beamer/app": beamer["app"],
        "beamer/lautstaerke": beamer.get("lautstaerke", -1),
        "beamer/stumm": beamer.get("stumm", -1),
        "beamer/betriebsstunden": b_stunden,
        "beamer/laufzeit_heute": b_heute,
        "xbox/aktiv": xbox["aktiv"],
        "xbox/status": xbox["status"],
        "xbox/an": 1 if xbox["status"] in ("On", "on") else 0,
        "xbox/name": xbox.get("name", ""),
        "xbox/angemeldet": xbox["angemeldet"],
        "xbox/betriebsstunden": x_stunden,
        "xbox/laufzeit_heute": x_heute,
        "xbox/geheimnis_ablauf": ablauf_datum,
        "xbox/geheimnis_tage": ablauf_tage,
    }


# Themen, die nur bei einem Ereignis hinausgehen und deshalb nicht in
# mqtt_werte stehen. Sie gehoeren trotzdem in --themen, sonst meldet die
# Pruefzeile im Reiter Test eine Abweichung gegen die Themenliste.
EREIGNIS_THEMEN = ("beamer/letzte_aktion", "xbox/letzte_aktion",
                   "szene/laeuft", "szene/schritt", "szene/ergebnis")


def _alle_themen():
    leer_beamer = {"aktiv": False, "erreichbar": False, "status": "unbekannt",
                   "app": "", "grund": "aus", "grund_text": "", "fehler": "",
                   "lautstaerke": -1, "stumm": -1}
    leer_xbox = {"aktiv": False, "status": "unbekannt", "angemeldet": False,
                 "fehler": "", "quelle": "", "name": ""}
    return sorted(list(mqtt_werte(leer_beamer, leer_xbox, "", "", 0).keys())
                  + list(EREIGNIS_THEMEN))


def keycode_nachziehen(cfg, log):
    """Einen kleingeschriebenen Keycode EINMAL gross in die Datei schreiben.

    Bis 1.2.11 hat lg_beamer den Keycode bei jedem Gebrauch stillschweigend
    grossgeschrieben. Wirksam war also immer die grosse Fassung, in der
    Datei stand womoeglich eine kleine. Seit 1.2.12 wandelt die Bibliothek
    nichts mehr - ohne diesen einmaligen Nachzug wuerde eine bestehende
    Anlage mit kleingeschriebenem Eintrag von einem Tag auf den anderen
    einen ANDEREN Schluessel ableiten und das Geraet unlesbar antworten.

    Der Nachzug ist angekuendigt, nicht still: er steht im Protokoll.
    """
    alt = gemein.wert(cfg, "beamer", "keycode", "")
    if not alt or alt == alt.upper():
        return False
    neu = alt.upper()
    from lg_beamer import LgBeamer
    if not LgBeamer.keycode_gueltig(neu):
        log.warning("Der eingetragene Keycode passt nicht auf acht Zeichen "
                    "A-Z und 0-9. Der Beamer wird jeden Befehl ablehnen - "
                    "bitte im Reiter Einstellungen berichtigen.")
        return False
    cfg.set("beamer", "keycode", neu)
    if gemein.config_schreiben(cfg):
        log.info("Der Keycode stand kleingeschrieben in der Konfiguration und "
                 "wurde einmalig in Grossbuchstaben nachgezogen. Wirksam war "
                 "auch bisher die grosse Fassung; ab 1.2.12 wandelt das "
                 "Plugin nichts mehr still um.")
        return True
    return False


# --------------------------------------------------------------------------
# Hauptschleife
# --------------------------------------------------------------------------

def hauptteil():
    log = gemein.protokoll_einrichten("heimkino")
    signal.signal(signal.SIGTERM, _abbruch)
    signal.signal(signal.SIGINT, _abbruch)

    cfg, lage = gemein.config_lesen(log)
    if not gemein.ja(cfg, "heimkino", "enabled"):
        log.info("Das Plugin ist in den Einstellungen abgeschaltet - beende.")
        return 0

    # Erst hier sperren. Bis 1.2.11 stand pid_belegen() VOR dieser Pruefung -
    # bei abgeschaltetem Plugin wurde die eigene Prozessnummer also in die
    # Datei geschrieben und beim Verlassen nie geloescht.
    if not gemein.pid_belegen(log):
        return 0

    melder = None
    try:
        if lage == "ok":
            gemein.config_vervollstaendigen(cfg, log)
            keycode_nachziehen(cfg, log)

        praefix = gemein.wert(cfg, "heimkino", "themenpraefix", "heimkino") or "heimkino"
        takt = gemein.zahl(cfg, "heimkino", "intervall", 60, 10, 3600)
        melder = gemein.Melder(praefix, log, gemein.ja(cfg, "heimkino", "mqtt"))
        meldungen = {}

        fassung = gemein.version()
        log.info("Heimkino%s gestartet, Takt %d s, Themenpraefix %s",
                 (" " + fassung) if fassung else "", takt, melder.praefix)
        melder.sende("service/online", 1)
        melder.sende("szene/laeuft", 0)

        letzte_config = 0.0
        letzte_runde = time.time()
        # Wird ein Durchgang wegen besetzter Sperre uebersprungen, bleiben
        # diese Werte stehen - siehe beamer_abfragen().
        letzter_beamer = None
        while LAEUFT:
            # Konfiguration bei jedem Durchlauf neu lesen: nach dem Speichern
            # in der Oberflaeche soll der Dienst ohne Neustart mitziehen.
            try:
                geaendert = os.path.getmtime(P["config"])
            except OSError:
                geaendert = 0.0
            if geaendert != letzte_config:
                cfg, lage = gemein.config_lesen(log)
                letzte_config = geaendert
                takt = gemein.zahl(cfg, "heimkino", "intervall", 60, 10, 3600)
                # Verglichen wird der GESAEUBERTE neue Wert mit dem
                # gesaeuberten alten - sonst meldet der Dienst bei jeder
                # Konfigurationsaenderung eine Umstellung, die keine ist.
                neuer = gemein.praefix_saeubern(
                    gemein.wert(cfg, "heimkino", "themenpraefix", "heimkino"))
                if melder.aktiv and neuer != melder.praefix:
                    log.info("Themenpraefix geaendert: %s -> %s. Die alten "
                             "zurueckbehaltenen Werte bleiben beim Broker "
                             "stehen und muessen dort von Hand geloescht "
                             "werden.", melder.praefix, neuer)
                    melder.schliessen()
                    melder = gemein.Melder(neuer, log,
                                           gemein.ja(cfg, "heimkino", "mqtt"))

            # --- Auftrag: Szene sofort ausfuehren, sonst spaeter nachfassen.
            auftrag = gemein.auftrag_lesen()
            if auftrag and str(auftrag.get("aktion", "")).startswith("kino-"):
                gemein.auftrag_loeschen()
                szene_ausfuehren(str(auftrag["aktion"]), cfg, melder, log)
                auftrag = None

            beamer = beamer_abfragen(cfg, log, meldungen, letzter_beamer)
            letzter_beamer = beamer
            xbox = xbox_abfragen(cfg, log, meldungen)
            ablauf_datum, ablauf_tage = geheimnis_restlaufzeit(cfg, log, meldungen)
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
            vergangen = jetzt - letzte_runde
            letzte_runde = jetzt

            # Betriebszeit fortschreiben - eine Schaetzung aus dem Abtastraster,
            # keine Angabe des Geraets. Steht so in der Themenliste.
            b_stunden, b_heute = gemein.betrieb_fortschreiben(
                beamer["status"] == "an", vergangen, "beamer")
            x_stunden, x_heute = gemein.betrieb_fortschreiben(
                xbox["status"] in ("On", "on"), vergangen, "xbox")

            if auftrag and gemein.ja(cfg, "heimkino", "nachfassen"):
                if nachfassen(auftrag, beamer, xbox, melder, log):
                    gemein.auftrag_loeschen()
                    auftrag = None
            elif auftrag:
                gemein.auftrag_loeschen()
                auftrag = None

            gemein.zustand_schreiben({
                "zeit": jetzt,
                "zeit_text": time.strftime("%d.%m.%Y %H:%M:%S",
                                           time.localtime(jetzt)),
                "config_lage": lage,
                "beamer": beamer,
                "xbox": xbox,
                "geheimnis_ablauf": ablauf_datum,
                "geheimnis_tage": ablauf_tage,
                "betrieb": {"beamer_h": b_stunden, "beamer_heute": b_heute,
                            "xbox_h": x_stunden, "xbox_heute": x_heute},
                "auftrag_offen": bool(auftrag),
                "takt": takt,
            })

            melder.sende_viele(mqtt_werte(beamer, xbox, ablauf_datum,
                                          ablauf_tage, jetzt,
                                          b_stunden, b_heute,
                                          x_stunden, x_heute))

            # Die Protokolldatei liegt auf einer Ramdisk. Ohne Kappung frisst
            # sie Arbeitsspeicher, bis nichts mehr geht.
            gemein.log_kappen()

            # Solange ein Auftrag offen ist, kuerzer warten - sonst dauerte
            # die Rueckmeldung bis zu einem vollen Takt.
            _warten(TAKT_NACHFASSEN if auftrag else takt)
    finally:
        if melder is not None:
            melder.sende("service/online", 0)
            # Kurz warten, damit die letzte Meldung den Broker noch erreicht -
            # loop_stop() unmittelbar danach wuerde sie sonst verschlucken.
            time.sleep(0.3)
            melder.schliessen()
        gemein.pid_freigeben()
        log.info("Heimkino beendet.")
    return 0


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "--vorgaben":
        print(json.dumps(gemein.vorgaben(), ensure_ascii=False, indent=1))
        sys.exit(0)
    if len(sys.argv) > 1 and sys.argv[1] == "--themen":
        # Die WIRKLICH gesendeten Themen, nicht die Datei. Nur so beantwortet
        # die Pruefzeile im Reiter Test die Frage, ob die angezeigte Tabelle
        # zum Sendecode passt.
        print(json.dumps(_alle_themen(), ensure_ascii=False, indent=1))
        sys.exit(0)
    sys.exit(hauptteil())
