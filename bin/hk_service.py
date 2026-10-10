#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Heimkino - Dienst

Fragt in festem Takt den Zustand beider Geraete ab, schreibt ihn in eine
Zustandsdatei fuer die Oberflaeche und meldet ihn per MQTT (retained je Thema) an den
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
  hk_service.py --protokoll   Lage der Protokolldatei als JSON
"""

import datetime
import json
import os
import signal
import sys
import threading
import time

import hk_common as gemein
from hk_common import P
from hk_sperre import Sperre, SperreBesetzt

LAEUFT = True

# Takt, solange ein Auftrag offen ist oder eine Szene laeuft. Kurz genug,
# damit die Rueckmeldung brauchbar ist, lang genug, dass der Beamer nicht in
# Dauerbefragung geraet.
TAKT_NACHFASSEN = 5

# Ein Auftrag, der beim Dienststart aelter ist, wird verworfen (Muster 5).
AUFTRAG_VERALTET = 60


def _abbruch(nummer, rahmen):        # noqa: ARG001
    global LAEUFT
    LAEUFT = False


def _warten(sekunden):
    """In Ein-Sekunden-Schritten warten, damit ein Stopp sofort greift.

    Mit time.monotonic() seit 1.3.15 (C11): bis 1.3.14 rechnete die Schleife
    mit time.time(), und ein Uhrsprung rueckwaerts verlaengerte die Wartezeit
    um den Sprung (Befund code 13).
    """
    ende = time.monotonic() + sekunden
    while LAEUFT and time.monotonic() < ende:
        time.sleep(min(1.0, max(0.0, ende - time.monotonic())))
    return LAEUFT


class Lebenszeichen:
    """service/zeitstempel weiter senden, solange eine Szene laeuft (seit 1.3.15, M7).

    Die Kino-Szene laeuft im Hauptdurchgang und wartet auf den Beamer (bis
    600 s) und die Konsole (bis 600 s). Bis 1.3.14 stand das Lebenszeichen
    in dieser Zeit still; gemessen: 13,5 s Luecke bei einem Takt von 1,5 s
    (Befund mqtt 7). Die Themenliste sagt aber "Bleibt der Wert stehen,
    arbeitet der Dienst nicht mehr" - eine Ueberwachung in Loxone meldete bei
    jedem "Kino an" einen toten Dienst. Ein eigener Faden sendet deshalb
    waehrend der Szene alle Takt/2 Sekunden (hoechstens 30) den Zeitstempel
    und frischt die Aenderungszeit von zustand.json auf, an der der Waechter
    einen haengenden Dienst erkennt (C11). Er haengt an keinem Geraeteaufruf.
    """

    def __init__(self, melder, takt):
        self.melder = melder
        self.abstand = max(0.5, min(30.0, float(takt) / 2.0))
        self._halt = threading.Event()
        self._faden = None

    def _lauf(self):
        while not self._halt.wait(self.abstand):
            try:
                self.melder.sende("service/zeitstempel", int(time.time()))
            except Exception:            # noqa: BLE001 - das Lebenszeichen darf nie stoeren
                pass
            try:
                os.utime(P["zustand"], None)
            except OSError:
                pass

    def __enter__(self):
        self._faden = threading.Thread(target=self._lauf, name="lebenszeichen", daemon=True)
        self._faden.start()
        return self

    def __exit__(self, art, wert, spur):
        self._halt.set()
        if self._faden is not None:
            self._faden.join(5)
        return False


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
    # "erreichbar" seit 1.3.15 (M3): ist die Abfrage bei der Cloud gelungen?
    # Bis 1.3.14 gab es fuer die Xbox keinen Merker, an dem Loxone einen
    # Ausfall erkennen konnte - nur den Text last_error (Befund mqtt 3).
    ergebnis = {"aktiv": False, "status": "unbekannt", "angemeldet": False,
                "fehler": "", "quelle": "", "name": "", "erreichbar": False}
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
        ergebnis["erreichbar"] = True
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
    anfang = time.monotonic()
    while LAEUFT and (time.monotonic() - anfang) < sekunden:
        if gemein.erreichbarkeit(ip, port, 2, P["sperre"], 5)[0]:
            return time.monotonic() - anfang
        if not _warten(3):
            break
    return None


def _warten_auf_xbox(cfg, log, sekunden):
    kennung = gemein.wert(cfg, "xbox", "geraete_id")
    anfang = time.monotonic()
    while LAEUFT and (time.monotonic() - anfang) < sekunden:
        try:
            from xbox_cloud import XboxCloud
            if XboxCloud(P["auth"], log).status(kennung)["status"] in ("On", "on"):
                return time.monotonic() - anfang
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
    anfang = time.monotonic()
    beamer_an = gemein.ja(cfg, "beamer", "aktiv")
    xbox_an = gemein.ja(cfg, "xbox", "aktiv")
    w_beamer = gemein.zahl(cfg, "szene", "warten_beamer", 120, 10, 600)
    w_xbox = gemein.zahl(cfg, "szene", "warten_xbox", 90, 10, 600)
    fehler = []

    melder.sende("szene/laeuft", 1)
    melder.sende("szene/ergebnis", "-")
    hausereignis_melden(aktion, cfg, melder, log)

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
        dauer = int(time.monotonic() - anfang)
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
    # Ohne eingetragenes Ablaufdatum: 9999 statt "-" (seit 1.3.15, M5).
    # Loxone liest "-" an einem Analogeingang als 0, und 0 hiesse "laeuft
    # heute ab" - eine Warnlogik schlug bei jedem Anwender ohne Datum an
    # (Befund mqtt 5). Ob ein Datum eingetragen ist, sagt daneben
    # xbox/geheimnis_datum_bekannt (0/1). Das Thema bleibt fluechtig: es
    # zaehlt taeglich herunter.
    datum_bekannt = 0 if ablauf_tage == "" else 1
    if ablauf_tage == "":
        ablauf_tage = GEHEIMNIS_OHNE_DATUM
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
        "xbox/erreichbar": 1 if xbox.get("erreichbar") else 0,
        "xbox/status": xbox["status"],
        "xbox/an": 1 if xbox["status"] in ("On", "on") else 0,
        "xbox/name": xbox.get("name", ""),
        "xbox/angemeldet": xbox["angemeldet"],
        "xbox/betriebsstunden": x_stunden,
        "xbox/laufzeit_heute": x_heute,
        "xbox/geheimnis_ablauf": ablauf_datum,
        "xbox/geheimnis_tage": ablauf_tage,
        "xbox/geheimnis_datum_bekannt": datum_bekannt,
    }


# Themen, die nur bei einem Ereignis hinausgehen und deshalb nicht in
# mqtt_werte stehen. Sie gehoeren trotzdem in --themen, sonst meldet die
# Pruefzeile im Reiter Test eine Abweichung gegen die Themenliste.
EREIGNIS_THEMEN = ("beamer/letzte_aktion", "xbox/letzte_aktion",
                   "szene/laeuft", "szene/schritt", "szene/ergebnis")

# Hausereignis Kino-Szene (Verbesserungsbau 30.09.2026, Kino-1). Das Thema
# steht NICHT in bin/hk_themen.json und nicht in --themen: es liegt
# ausserhalb des Praefixes, und die Themen-Tabelle zeigt <praefix>/<thema>.
# Der Stand fuer den Reiter Test geht mit zustand.json hinaus.
HAUS_STAND = {"wert": "", "zeit": 0}


def hausereignis_wirksam(cfg):
    """Geht haus/szene/kino hinaus? Nur mit Einstellung, Kino-Szene und MQTT."""
    return (gemein.ja(cfg, "szene", "hausereignis")
            and gemein.ja(cfg, "szene", "aktiv")
            and gemein.ja(cfg, "heimkino", "mqtt"))


def hausereignis_melden(aktion, cfg, melder, log):
    """1 bei kino-an, 0 bei kino-aus - retained, beim Start der Szene."""
    if not hausereignis_wirksam(cfg) or aktion not in ("kino-an", "kino-aus"):
        return
    wert = "1" if aktion == "kino-an" else "0"
    if not melder.aktiv:
        log.warning("Hausereignis %s = %s nicht gesendet: MQTT ist nicht "
                    "eingerichtet (siehe Protokoll beim Start).", gemein.HAUS_KINO, wert)
        return
    angenommen = melder.sende_haus(gemein.HAUS_KINO, wert)
    gemein.hausereignis_merken(wert)
    HAUS_STAND.update(wert=wert, zeit=int(time.time()))
    if angenommen:
        log.info("Hausereignis %s = %s (retained) gesendet.", gemein.HAUS_KINO, wert)
    else:
        log.warning("Hausereignis %s = %s: der Broker ist gerade nicht verbunden - "
                    "es geht beim naechsten Verbinden hinaus.", gemein.HAUS_KINO, wert)


def hausereignis_abraeumen(log):
    """Liegt der Merker, das Thema abraeumen (mit Nachlesen)."""
    if gemein.hausereignis_gemerkt() is None:
        return
    erg = gemein.hausereignis_leeren()
    HAUS_STAND.update(wert="", zeit=0)
    if erg["rc"] == 0:
        log.info("Hausereignis %s ist abgeschaltet: am Broker %s und nachgelesen.",
                 gemein.HAUS_KINO, "geloescht" if erg["geleert"] else "stand nichts behalten")
    else:
        log.warning("Hausereignis %s ist abgeschaltet, liess sich am Broker aber nicht "
                    "abraeumen (%s) - naechster Versuch beim naechsten Start.",
                    gemein.HAUS_KINO,
                    erg["grund"] or ("noch da: " + ", ".join(erg["rest"])))


def hausereignis_start(cfg, log):
    """Beim Start: wirksam -> den gemerkten Wert fuer das Verbinden vormerken;
    sonst einen liegenden Merker abraeumen. Rueckgabe fuer Melder(haus=...)."""
    if not hausereignis_wirksam(cfg):
        hausereignis_abraeumen(log)
        return {}
    alt = gemein.hausereignis_gemerkt()
    if alt in ("0", "1"):
        HAUS_STAND.update(wert=alt, zeit=0)
        return {gemein.HAUS_KINO: alt}
    return {}


# Ohne eingetragenes Ablaufdatum geht xbox/geheimnis_tage als 9999 hinaus
# (seit 1.3.15, M5); daneben xbox/geheimnis_datum_bekannt = 0.
GEHEIMNIS_OHNE_DATUM = 9999

# Einmal "-" (Text) bzw. -1 (Zahl) retained fuer die Zustaende eines
# abgeschalteten Geraets (Entscheidung 5 und 8, seit 1.3.15, M4). Danach
# gehen fuer dieses Geraet keine Themen mehr hinaus ausser <geraet>/aktiv.
# Ausnahme seit 1.3.22 (Entscheidung 10.10.2026): beamer/an und xbox/an
# gehen als 0 hinaus. Beide sind in der Vorlage digitale Eingaenge (Min 0,
# Max 1); Loxone meldete -1 als "ausserhalb des Wertebereichs" und setzte
# auf 0 zurueck, und ein digitaler Eingang wertet jeden Wert ungleich 0 als
# "Ein". Dass das Geraet abgeschaltet ist, zeigt <geraet>/aktiv = 0. Die
# Betriebsstunden bleiben -1; dafuer steht ihr min in hk_themen.json auf -1.
# Ebenso seit 1.3.23 (Entscheidung 11.10.2026): xbox/geheimnis_datum_bekannt
# geht als 0 hinaus. Auch das ist in der Vorlage ein digitaler Eingang; -1
# lag zwar im Wertebereich (min war -1), Loxone wertete es aber als "Ein" =
# "Datum eingetragen". Sein min in hk_themen.json ist jetzt 0.
ENTFERNT = {
    "beamer": {"beamer/status": "-",
               "beamer/an": 0,  # digitaler Eingang (Min 0), Entscheidung 10.10.2026
               "beamer/app": "-",
               "beamer/lautstaerke": -1, "beamer/stumm": -1,
               "beamer/betriebsstunden": -1},
    "xbox": {"xbox/status": "-",
             "xbox/an": 0,  # digitaler Eingang (Min 0), Entscheidung 10.10.2026
             "xbox/name": "-",
             "xbox/betriebsstunden": -1, "xbox/geheimnis_ablauf": "-",
             # digitaler Eingang (Min 0), Entscheidung 11.10.2026
             "xbox/geheimnis_datum_bekannt": 0},
}


def ausfall_themen(beamer, xbox):
    """Die Zustaende, die in diesem Durchgang NICHT hinausgehen (seit 1.3.15, M3).

    Entscheidung 8 (30.09.2026, gilt fuer alle Linien): faellt ein Geraet
    zeitweise aus, bleiben die retained Zustaende stehen, und nur
    erreichbar/ok geht auf 0. Bis 1.3.14 gingen stattdessen Platzhalter
    hinaus - "unbekannt", beamer/an 0, xbox/an 0 - zwar ohne Retain, aber an
    jeden Abonnenten: Loxone bekam bei jedem Ausfall "Beamer aus" und
    "Konsole aus", nach einem Neustart des Gateways wieder die behaltene 1
    (gemessen, Befund mqtt 3). Jetzt wird im Ausfall gar nicht gesendet; in
    Loxone gilt ein Zustand nur zusammen mit beamer/erreichbar bzw.
    xbox/erreichbar.

    "aus" nach einer ABGEWIESENEN Verbindung ist kein Ausfall: der Beamer
    weist sie ab, weil er aus ist - das sagt das Geraet. Lautstaerke und
    Stummschaltung gehen dann als -1 retained hinaus: das Feld liefert der
    gelungene Abruf nicht (Entscheidung 8).
    """
    weg = set()
    if beamer.get("aktiv") and beamer.get("status") == "unbekannt":
        weg.update(("beamer/status", "beamer/an", "beamer/app",
                    "beamer/lautstaerke", "beamer/stumm"))
    if xbox.get("aktiv") and xbox.get("status") == "unbekannt":
        weg.update(("xbox/status", "xbox/an", "xbox/name"))
    return weg


def werte_fuer_versand(werte, beamer, xbox, entfernt_gemeldet):
    """Aus den Werten eines Durchgangs das machen, was hinausgeht.

    entfernt_gemeldet: Menge der Geraete, fuer die ENTFERNT schon gesendet ist
    (sie lebt im Dienst; nach einem Neustart geht es genau einmal wieder).
    """
    aus = dict(werte)
    for thema in ausfall_themen(beamer, xbox):
        aus.pop(thema, None)
    for geraet, daten in (("beamer", beamer), ("xbox", xbox)):
        if daten.get("aktiv"):
            entfernt_gemeldet.discard(geraet)
            continue
        for thema in list(aus):
            if thema.startswith(geraet + "/") and thema != geraet + "/aktiv":
                del aus[thema]
        if geraet not in entfernt_gemeldet:
            aus.update(ENTFERNT[geraet])
            entfernt_gemeldet.add(geraet)
    return aus


def altwerte_abraeumen(praefix, log):
    """Behaltene Altwerte der Themen OHNE retain abraeumen - nur was wirklich
    da ist, mit Nachlesen, einmal je Prozess (seit 1.3.15, M6).

    Bis 1.3.14 gingen dafuer bei jeder Verbindung leere retained Nutzlasten
    hinaus (siehe Melder._bei_verbindung). Ein Broker, der nicht zu fragen
    ist, heisst nicht "nichts belegt" - dann steht es im Protokoll.
    """
    behalten = gemein.retain_themen()
    namen = [str(t.get("thema")) for t in gemein.themen()
             if t.get("thema") and str(t.get("thema")) not in behalten]
    erg = gemein.broker_leeren(praefix, namen)
    if erg["rc"] == 0:
        if erg["geleert"]:
            log.info("MQTT: %d behaltene Altwerte ohne Retain unter %s/ abgeraeumt "
                     "und nachgelesen: %s", len(erg["geleert"]), praefix,
                     ", ".join(erg["geleert"]))
    else:
        log.info("MQTT: behaltene Altwerte unter %s/ nicht abgeraeumt (%s).", praefix,
                 erg["grund"] or ("noch da: " + ", ".join(erg["rest"])))
    return erg


def praefixe_aufraeumen(aktuell, log):
    """Jedes gemerkte Praefix ausser dem aktuellen abraeumen (seit 1.3.15, M1).

    Vergessen wird ein Praefix erst, wenn das Abraeumen samt Nachlesen
    gelungen ist; sonst versucht es der naechste Start wieder.
    """
    namen = [str(t.get("thema")) for t in gemein.themen() if t.get("thema")]
    for alt in gemein.praefixe_lesen():
        if alt == aktuell:
            continue
        erg = gemein.broker_leeren(alt, namen)
        if erg["rc"] == 0:
            gemein.praefix_vergessen(alt)
            log.info("Themenpraefix %s gilt nicht mehr: %d behaltene Themen "
                     "geloescht und nachgelesen.", alt, len(erg["geleert"]))
        else:
            log.warning("Themenpraefix %s gilt nicht mehr, die behaltenen Werte "
                        "liessen sich noch nicht abraeumen (%s) - naechster "
                        "Versuch beim naechsten Start.", alt,
                        erg["grund"] or ("noch da: " + ", ".join(erg["rest"])))
    gemein.praefix_merken(aktuell)


def _alle_themen():
    leer_beamer = {"aktiv": False, "erreichbar": False, "status": "unbekannt",
                   "app": "", "grund": "aus", "grund_text": "", "fehler": "",
                   "lautstaerke": -1, "stumm": -1}
    leer_xbox = {"aktiv": False, "status": "unbekannt", "angemeldet": False,
                 "fehler": "", "quelle": "", "name": "", "erreichbar": False}
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
    # Ohne Anlage nichts anlegen und nichts schalten (Muster 1-3): VOR dem
    # Protokoll, der PID-Datei und MQTT.
    if not gemein.wurzel_oder_abbruch("hk_service.py"):
        return 1
    log = gemein.protokoll_einrichten("heimkino")
    signal.signal(signal.SIGTERM, _abbruch)
    signal.signal(signal.SIGINT, _abbruch)

    cfg, lage = gemein.config_lesen(log)
    if not gemein.ja(cfg, "heimkino", "enabled"):
        # Ein gesendetes Hausereignis bliebe sonst als "Kino laeuft" stehen
        # (Kino-1). Nur mit Merker - sonst fragt der Start keinen Broker.
        hausereignis_abraeumen(log)
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
        melder = gemein.Melder(praefix, log, gemein.ja(cfg, "heimkino", "mqtt"),
                               haus=hausereignis_start(cfg, log))
        meldungen = {}

        fassung = gemein.version()
        log.info("Heimkino%s gestartet, Takt %d s, Themenpraefix %s",
                 (" " + fassung) if fassung else "", takt, melder.praefix)
        melder.sende("service/online", 1)
        melder.sende("szene/laeuft", 0)
        if melder.aktiv:
            praefixe_aufraeumen(melder.praefix, log)
            altwerte_abraeumen(melder.praefix, log)
        entfernt_gemeldet = set()

        # Ein Auftrag, der beim Start schon laenger als AUFTRAG_VERALTET
        # liegt, wurde gestellt, als kein Dienst lief - er wird verworfen,
        # nicht ausgefuehrt. Bis 1.3.13 lief eine Kino-Szene, die jemand am
        # Abend ohne laufenden Dienst gedrueckt hatte, beim naechsten Start
        # ab, womoeglich Stunden spaeter (Muster 5 der Nachlese; Bauart
        # ZendureSolarFlow 0.9.26). hk_cmd.py legt ohne Dienst seit 1.3.14
        # gar keinen mehr ab; dieser Zweig faengt die Reste ab.
        alt_auftrag = gemein.auftrag_lesen()
        if alt_auftrag:
            try:
                alter = time.time() - float(alt_auftrag.get("gestellt", 0))
            except (TypeError, ValueError):
                alter = float("inf")
            if not (0 <= alter <= AUFTRAG_VERALTET):
                gemein.auftrag_loeschen()
                log.warning("Auftrag %s vom Start verworfen: er lag %s, als kein "
                            "Dienst lief.", alt_auftrag.get("aktion", "?"),
                            ("seit %d s" % alter) if alter != float("inf")
                            else "ohne lesbare Zeit")

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
                # Hausereignis abgeschaltet (Einstellung, Szene oder MQTT):
                # nicht mehr wiederholen und ein gesendetes abraeumen (Kino-1).
                if not hausereignis_wirksam(cfg):
                    melder.haus_vergessen()
                    hausereignis_abraeumen(log)
                # Verglichen wird der GESAEUBERTE neue Wert mit dem
                # gesaeuberten alten - sonst meldet der Dienst bei jeder
                # Konfigurationsaenderung eine Umstellung, die keine ist.
                neuer = gemein.praefix_saeubern(
                    gemein.wert(cfg, "heimkino", "themenpraefix", "heimkino"))
                if melder.aktiv and neuer != melder.praefix:
                    alter_praefix = melder.praefix
                    haus_werte = melder.haus_werte()
                    melder.schliessen()
                    # Das alte Praefix abraeumen und nachlesen (seit 1.3.14).
                    # Bis 1.3.13 blieben die behaltenen Werte dort stehen -
                    # auch service/online=1, denn ein sauberes Trennen loest
                    # den Letzten Willen nicht aus. Ein Teilnehmer, der noch
                    # auf das alte Praefix hoerte, sah einen lebenden Dienst.
                    leer = gemein.broker_leeren(
                        alter_praefix,
                        [str(t.get("thema")) for t in gemein.themen()])
                    if leer["rc"] == 0:
                        gemein.praefix_vergessen(alter_praefix)
                        log.info("Themenpraefix geaendert: %s -> %s. Unter %s/ "
                                 "sind %d behaltene Themen geloescht und "
                                 "nachgelesen.", alter_praefix, neuer,
                                 alter_praefix, len(leer["geleert"]))
                    else:
                        log.warning("Themenpraefix geaendert: %s -> %s. Die "
                                    "behaltenen Werte unter %s/ liessen sich "
                                    "nicht abraeumen (%s) - von Hand loeschen.",
                                    alter_praefix, neuer, alter_praefix,
                                    leer["grund"] or ("noch da: " + ", ".join(leer["rest"])))
                    melder = gemein.Melder(neuer, log,
                                           gemein.ja(cfg, "heimkino", "mqtt"),
                                           haus=haus_werte)
                    if melder.aktiv:
                        gemein.praefix_merken(melder.praefix)
                    entfernt_gemeldet = set()

            # --- Auftrag: Szene sofort ausfuehren, sonst spaeter nachfassen.
            auftrag = gemein.auftrag_lesen()
            if auftrag and str(auftrag.get("aktion", "")).startswith("kino-"):
                gemein.auftrag_loeschen()
                with Lebenszeichen(melder, takt):
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
                "hausereignis": dict(HAUS_STAND, wirksam=hausereignis_wirksam(cfg)),
            })

            melder.sende_viele(werte_fuer_versand(
                mqtt_werte(beamer, xbox, ablauf_datum, ablauf_tage, jetzt,
                           b_stunden, b_heute, x_stunden, x_heute),
                beamer, xbox, entfernt_gemeldet))

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


def mqtt_leeren():
    """Fuer uninstall/uninstall: die behaltenen Themen dieser Linie am Broker
    abraeumen und nachlesen (seit 1.3.14).

    Entschieden am 18.09.2026 (Regeln/07, Abschnitt 3): der Letzte Wille
    service/online darf retained sein, weil die Deinstallation das Thema
    abraeumt - sonst bliebe die 0 eines entfernten Plugins fuer immer stehen.
    Geraeumt werden ALLE Namen aus bin/hk_themen.json unter dem Praefix der
    Konfiguration, auch Altlasten aus Vorfassungen, die damals retained
    gingen. Ein fremdes Thema unter demselben Praefix bleibt stehen.

    Ausgabe in der Form der Installationsmeldungen; Rueckgabe 0 erledigt,
    1 Reste, 2 nicht moeglich. Ein Broker, der nicht zu fragen ist, heisst
    nie "nichts belegt" (Muster 11).
    """
    if not gemein.wurzel_oder_abbruch("hk_service.py --mqtt-leeren"):
        print("<INFO> MQTT: ohne LoxBerry-Wurzel wurden keine behaltenen Themen geleert.")
        return 2
    namen = [str(t.get("thema")) for t in gemein.themen() if t.get("thema")]
    if not namen:
        print("<INFO> MQTT: bin/hk_themen.json fehlt - behaltene Themen wurden "
              "nicht geleert. Sie sind von Hand zu loeschen.")
        return 2
    cfg, _lage = gemein.config_lesen()
    praefix = gemein.praefix_saeubern(
        gemein.wert(cfg, "heimkino", "themenpraefix", "heimkino"))
    # Seit 1.3.15 (M1) auch jedes gemerkte Praefix, unter dem der Dienst
    # einmal gesendet hat - bis 1.3.14 erreichte die Deinstallation ein altes
    # Praefix nie (Befund mqtt 1, Fall U2).
    alle = [praefix] + [p for p in gemein.praefixe_lesen() if p != praefix]
    schlimmster = 0
    for p in alle:
        erg = gemein.broker_leeren(p, namen)
        if erg["rc"] == 2:
            print("<WARNING> MQTT: behaltene Themen unter %s/ nicht geleert - %s. "
                  "Der Broker war nicht zu fragen; ob dort noch etwas steht, ist "
                  "unbekannt. Von Hand: mosquitto_pub -r -n -t %s/<thema>"
                  % (p, erg["grund"], p))
            schlimmster = 2
            continue
        if erg["rest"]:
            print("<WARNING> MQTT: %d behaltene Themen stehen nach dem Loeschen noch "
                  "im Broker: %s" % (len(erg["rest"]), ", ".join(erg["rest"])))
            schlimmster = max(schlimmster, 1)
            continue
        gemein.praefix_vergessen(p)
        if erg["geleert"]:
            print("<OK> MQTT: %d behaltene Themen unter %s/ geloescht und nachgelesen "
                  "(%s)." % (len(erg["geleert"]), p, ", ".join(erg["geleert"])))
        else:
            print("<OK> MQTT: unter %s/ stand nichts behalten (nachgelesen)." % p)
    # Hausereignis (Kino-1): nur, wenn es laut Merker gesendet wurde - ein
    # fremder Absender desselben Themas bleibt sonst unberuehrt.
    if gemein.hausereignis_gemerkt() is not None:
        erg = gemein.hausereignis_leeren()
        if erg["rc"] == 0:
            print("<OK> MQTT: Hausereignis %s %s (nachgelesen)."
                  % (gemein.HAUS_KINO, "geloescht" if erg["geleert"] else "stand nicht behalten"))
        else:
            print("<WARNING> MQTT: Hausereignis %s nicht abgeraeumt - %s. Von Hand: "
                  "mosquitto_pub -r -n -t %s" % (gemein.HAUS_KINO, erg["grund"]
                  or ("noch da: " + ", ".join(erg["rest"])), gemein.HAUS_KINO))
            schlimmster = max(schlimmster, 1 if erg["rc"] == 1 else 2)
    return schlimmster


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "--vorgaben":
        print(json.dumps(gemein.vorgaben(), ensure_ascii=False, indent=1))
        sys.exit(0)
    if len(sys.argv) > 1 and sys.argv[1] == "--protokoll":
        # Fuer die Pruefzeile im Reiter Test. Gefragt wird die Bibliothek,
        # nicht ein zweites Mal die Datei - sonst gaebe es zwei Wahrheiten
        # darueber, was "in Ordnung" heisst.
        zustand, text = gemein.log_lage()
        print(json.dumps({"zustand": zustand, "text": text},
                         ensure_ascii=False))
        sys.exit(0)
    if sys.argv[1:] == ["--mqtt-leeren"]:
        sys.exit(mqtt_leeren())
    if len(sys.argv) > 1 and sys.argv[1] == "--themen":
        # Die WIRKLICH gesendeten Themen, nicht die Datei. Nur so beantwortet
        # die Pruefzeile im Reiter Test die Frage, ob die angezeigte Tabelle
        # zum Sendecode passt.
        print(json.dumps(_alle_themen(), ensure_ascii=False, indent=1))
        sys.exit(0)
    # Ein unbekannter Schalter startet KEINEN Dienst (seit 1.3.14). Ein Dienst
    # hat genau zwei Argumente - so erkennen ihn dienst.sh, postupgrade.sh und
    # uninstall. Ein Vertipper wie "--mqtt-leren" liefe sonst als Dienst, den
    # keiner dieser Wege als solchen sieht und keiner anhaelt.
    if len(sys.argv) > 1:
        sys.stderr.write("hk_service.py: unbekannter Aufruf %r - erlaubt sind "
                         "kein Argument (Dienst), --vorgaben, --protokoll, "
                         "--themen, --mqtt-leeren.\n" % sys.argv[1:])
        sys.exit(2)
    sys.exit(hauptteil())
