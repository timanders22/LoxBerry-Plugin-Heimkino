#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Heimkino - gemeinsame Grundlage

Pfade, Konfiguration, Protokoll und MQTT. Enthaelt bewusst keine
Geraetelogik; die steht in lg_beamer.py und xbox_cloud.py.
"""

import configparser
import json
import logging
import os
import socket
import sys
import time

ORDNER = "heimkino"


# --------------------------------------------------------------------------
# Pfade
# --------------------------------------------------------------------------

def _lbhome():
    """Wurzel der LoxBerry-Installation ermitteln.

    Reihenfolge: Umgebungsvariable, /etc/environment, uebliche Orte. Ohne
    diese Kette laeuft nichts von der Kommandozeile aus, weil systemd die
    Variable nicht an jede Shell weitergibt.
    """
    heim = os.environ.get("LBHOMEDIR")
    if heim and os.path.isdir(heim):
        return heim
    try:
        with open("/etc/environment", "r", encoding="utf-8", errors="replace") as datei:
            for zeile in datei:
                if zeile.strip().startswith("LBHOMEDIR"):
                    wert = zeile.split("=", 1)[1].strip().strip('"').strip("'")
                    if os.path.isdir(wert):
                        return wert
    except OSError:
        pass
    for kandidat in ("/opt/loxberry", "/home/loxberry/loxberry"):
        if os.path.isdir(kandidat):
            return kandidat
    return "/opt/loxberry"


def pfade():
    heim = _lbhome()
    return {
        "home": heim,
        "config": os.path.join(heim, "config", "plugins", ORDNER, "heimkino.cfg"),
        "auth": os.path.join(heim, "config", "plugins", ORDNER, "xbox_auth.json"),
        "zustand": os.path.join(heim, "data", "plugins", ORDNER, "zustand.json"),
        "logdir": os.path.join(heim, "log", "plugins", ORDNER),
        "log": os.path.join(heim, "log", "plugins", ORDNER, "heimkino.log"),
        "general": os.path.join(heim, "config", "system", "general.json"),
    }


P = pfade()


# --------------------------------------------------------------------------
# Protokoll
# --------------------------------------------------------------------------

def protokoll_einrichten(name="heimkino", stufe=logging.INFO):
    os.makedirs(P["logdir"], exist_ok=True)
    log = logging.getLogger(name)
    if log.handlers:
        return log
    log.setLevel(stufe)
    form = logging.Formatter("%(asctime)s %(levelname)-7s %(message)s",
                             "%Y-%m-%d %H:%M:%S")
    # Nur in die Datei, nicht nach stdout. Zwei Gruende:
    #   - der Dienst wird vom Startskript ohnehin in dieselbe Datei geleitet,
    #     ein zweiter Kanal wuerde jede Zeile doppelt schreiben;
    #   - hk_cmd.py antwortet dem Aktionsendpunkt auf stdout, und dort hat
    #     eine Protokollzeile nichts zu suchen.
    # Faellt das Schreiben aus, geht es nach stderr - sonst waere man blind.
    try:
        datei = logging.FileHandler(P["log"], encoding="utf-8")
        datei.setFormatter(form)
        log.addHandler(datei)
    except OSError:
        notfall = logging.StreamHandler(sys.stderr)
        notfall.setFormatter(form)
        log.addHandler(notfall)
    return log


# --------------------------------------------------------------------------
# Konfiguration
# --------------------------------------------------------------------------

VORGABEN = {
    "heimkino": {
        "enabled": "1",
        "intervall": "60",
        "themenpraefix": "heimkino",
        "mqtt": "1",
        "aktionstoken": "",
    },
    "beamer": {
        "aktiv": "0",
        "ip": "",
        "mac": "",
        "keycode": "",
        "port": "9761",
        "zeitgrenze": "5",
    },
    "xbox": {
        "aktiv": "0",
        "geraete_id": "",
    },
}


def config_lesen():
    cfg = configparser.ConfigParser()
    cfg.optionxform = str
    for abschnitt, werte in VORGABEN.items():
        cfg[abschnitt] = dict(werte)
    try:
        cfg.read(P["config"], encoding="utf-8")
    except (OSError, configparser.Error):
        pass
    # Fehlende Abschnitte nachziehen, damit kein Zugriff scheitert.
    for abschnitt, werte in VORGABEN.items():
        if not cfg.has_section(abschnitt):
            cfg.add_section(abschnitt)
        for schluessel, vorgabe in werte.items():
            if not cfg.has_option(abschnitt, schluessel):
                cfg.set(abschnitt, schluessel, vorgabe)
    return cfg


def wert(cfg, abschnitt, schluessel, vorgabe=""):
    try:
        return cfg.get(abschnitt, schluessel).strip()
    except (configparser.Error, AttributeError):
        return vorgabe


def zahl(cfg, abschnitt, schluessel, vorgabe, mindest=None, hoechst=None):
    try:
        n = int(float(cfg.get(abschnitt, schluessel).strip()))
    except (configparser.Error, ValueError, AttributeError):
        return vorgabe
    if mindest is not None and n < mindest:
        return vorgabe
    if hoechst is not None and n > hoechst:
        return vorgabe
    return n


def ja(cfg, abschnitt, schluessel):
    return wert(cfg, abschnitt, schluessel, "0") in ("1", "true", "yes", "on")


# --------------------------------------------------------------------------
# Zustandsdatei - die Oberflaeche liest sie, statt selbst zu messen
# --------------------------------------------------------------------------

def zustand_schreiben(daten):
    os.makedirs(os.path.dirname(P["zustand"]), exist_ok=True)
    vorlaeufig = P["zustand"] + ".neu"
    try:
        with open(vorlaeufig, "w", encoding="utf-8") as datei:
            json.dump(daten, datei, ensure_ascii=False, indent=1)
        os.replace(vorlaeufig, P["zustand"])
        return True
    except OSError:
        return False


def zustand_lesen():
    try:
        with open(P["zustand"], "r", encoding="utf-8") as datei:
            return json.load(datei)
    except (OSError, ValueError):
        return None


# --------------------------------------------------------------------------
# MQTT
# --------------------------------------------------------------------------

def mqtt_zugangsdaten():
    """Broker, Port, Benutzer und Passwort aus general.json holen.

    LoxBerry 2 und neuer fuehrt die Zugangsdaten des MQTT-Gateways dort. Wer
    sie im Plugin noch einmal eintragen muesste, haette zwei Wahrheiten.
    """
    try:
        with open(P["general"], "r", encoding="utf-8") as datei:
            allgemein = json.load(datei)
    except (OSError, ValueError):
        return None
    mqtt = allgemein.get("Mqtt") or allgemein.get("mqtt") or {}
    if not mqtt:
        return None
    adresse = (mqtt.get("Brokerhost") or mqtt.get("brokerhost") or "").strip()
    if not adresse:
        return None
    port = mqtt.get("Brokerport") or mqtt.get("brokerport") or 1883
    try:
        port = int(port)
    except (TypeError, ValueError):
        port = 1883
    return {
        "host": adresse,
        "port": port,
        "user": (mqtt.get("Brokeruser") or mqtt.get("brokeruser") or "").strip(),
        "pass": (mqtt.get("Brokerpass") or mqtt.get("brokerpass") or ""),
    }


class Melder:
    """Duenne Huelle um paho-mqtt.

    Faellt still auf Nichtstun zurueck, wenn MQTT aus ist oder das Modul
    fehlt. Ein Plugin, das ohne Broker gar nicht mehr startet, waere schlimmer
    als eines, das ohne Broker nur nicht meldet.
    """

    def __init__(self, praefix, log, aktiv=True):
        self.praefix = praefix.strip("/") or "heimkino"
        self.log = log
        self.aktiv = aktiv
        self.client = None
        self._gemeldet = {}
        if aktiv:
            self._verbinden()

    def _verbinden(self):
        zugang = mqtt_zugangsdaten()
        if not zugang:
            self.log.warning("Kein MQTT-Broker in general.json gefunden - "
                             "melde nichts. Ist das MQTT-Gateway eingerichtet?")
            self.aktiv = False
            return
        try:
            import paho.mqtt.client as mqtt
        except ImportError:
            self.log.warning("python3-paho-mqtt fehlt - melde nichts.")
            self.aktiv = False
            return
        try:
            try:
                self.client = mqtt.Client(
                    mqtt.CallbackAPIVersion.VERSION1,
                    client_id="loxberry-heimkino")
            except (AttributeError, TypeError):
                # paho 1.x kennt CallbackAPIVersion nicht.
                self.client = mqtt.Client(client_id="loxberry-heimkino")
            if zugang["user"]:
                self.client.username_pw_set(zugang["user"], zugang["pass"])
            self.client.connect(zugang["host"], zugang["port"], 30)
            self.client.loop_start()
            self.log.info("MQTT verbunden mit %s:%s", zugang["host"], zugang["port"])
        except (OSError, ValueError) as fehler:
            self.log.warning("MQTT nicht erreichbar: %s", fehler)
            self.client = None
            self.aktiv = False

    def sende(self, thema, inhalt):
        if not self.aktiv or self.client is None:
            return
        if inhalt is None:
            inhalt = ""
        if isinstance(inhalt, bool):
            inhalt = "1" if inhalt else "0"
        try:
            self.client.publish("%s/%s" % (self.praefix, thema),
                                str(inhalt), qos=0, retain=True)
        except (OSError, ValueError) as fehler:
            self.log.debug("MQTT-Versand fehlgeschlagen: %s", fehler)

    def sende_viele(self, paare):
        for thema, inhalt in paare.items():
            self.sende(thema, inhalt)

    def schliessen(self):
        if self.client is not None:
            try:
                self.client.loop_stop()
                self.client.disconnect()
            except (OSError, ValueError):
                pass


# --------------------------------------------------------------------------
# Hilfen
# --------------------------------------------------------------------------

def einmal_melden(speicher, schluessel, text, log, stufe="error", wieder_nach=3600):
    """Gleiche Meldung nicht in jedem Takt wiederholen.

    Ohne diese Bremse laeuft die Logdatei bei einem dauerhaft nicht
    erreichbaren Geraet in einer Nacht auf Hunderte gleicher Zeilen zu.
    """
    jetzt = time.time()
    alt = speicher.get(schluessel)
    if alt and alt[0] == text and (jetzt - alt[1]) < wieder_nach:
        return False
    speicher[schluessel] = (text, jetzt)
    getattr(log, stufe)("%s", text)
    return True


def erreichbar(host, port, zeitgrenze=2.0):
    """Antwortet an host:port ueberhaupt etwas?"""
    if not host:
        return False
    try:
        with socket.create_connection((host, int(port)), timeout=zeitgrenze):
            return True
    except (OSError, ValueError):
        return False


def wol_senden(mac, adresse="255.255.255.255", port=9):
    """Magic Packet verschicken.

    Loxone kann Wake-on-LAN selbst (wol://), deshalb ist das hier nur fuer
    den Reiter Test gedacht - damit man die MAC pruefen kann, ohne den
    Miniserver anzufassen.
    """
    ziffern = "".join(zeichen for zeichen in mac if zeichen in "0123456789abcdefABCDEF")
    if len(ziffern) != 12:
        raise ValueError("MAC-Adresse hat nicht 12 Hexstellen: %r" % mac)
    rohmac = bytes.fromhex(ziffern)
    paket = b"\xff" * 6 + rohmac * 16
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as buchse:
        buchse.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
        buchse.sendto(paket, (adresse, port))
    return len(paket)
