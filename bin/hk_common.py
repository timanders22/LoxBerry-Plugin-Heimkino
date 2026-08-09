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
        "pid": os.path.join(heim, "log", "plugins", ORDNER, "hk_service.pid"),
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

def version():
    """Version aus der Plugindatenbank von LoxBerry.

    Bewusst nicht fest eingetragen: eine Versionsnummer im Quelltext bleibt
    beim naechsten Release stehen. Bis 1.0.2 ist genau das passiert - die
    Oberflaeche zeigte 1.0.0, obwohl 1.0.2 lief. Massgeblich ist, was LoxBerry
    bei der Installation aus plugin.cfg uebernommen hat.

    Gibt "" zurueck, wenn sich die Version nicht ermitteln laesst. Dann steht
    im Protokoll keine Nummer - besser als eine falsche.
    """
    datei = os.path.join(_lbhome(), "data", "system", "plugindatabase.json")
    try:
        with open(datei, "r", encoding="utf-8") as f:
            inhalt = json.load(f)
    except (OSError, ValueError):
        return ""
    liste = inhalt.get("plugins", inhalt) if isinstance(inhalt, dict) else inhalt
    if isinstance(liste, dict):
        liste = list(liste.values())
    if not isinstance(liste, list):
        return ""
    for eintrag in liste:
        if not isinstance(eintrag, dict):
            continue
        if eintrag.get("folder") == ORDNER or eintrag.get("PLUGINDB_FOLDER") == ORDNER:
            return str(eintrag.get("version")
                       or eintrag.get("PLUGINDB_VERSION") or "").strip()
    return ""


def zustand_schreiben(daten):
    """Zustand unteilbar schreiben.

    Der Zwischenname enthaelt die Prozessnummer. Bis 1.1.1 hiess er fest
    ".neu" - schrieben der Dienst und ein hk_cmd-Aufruf gleichzeitig, zog
    einer dem anderen die Datei unter den Fuessen weg, und os.replace lief
    auf eine halb geschriebene Datei.

    flush + fsync vor dem Umbenennen: ohne sie steht der Name nach einem
    Stromausfall schon da, der Inhalt aber noch im Puffer - die Oberflaeche
    liest dann eine leere Datei.
    """
    os.makedirs(os.path.dirname(P["zustand"]), exist_ok=True)
    vorlaeufig = "%s.%d.neu" % (P["zustand"], os.getpid())
    try:
        with open(vorlaeufig, "w", encoding="utf-8") as datei:
            json.dump(daten, datei, ensure_ascii=False, indent=1)
            datei.flush()
            os.fsync(datei.fileno())
        os.replace(vorlaeufig, P["zustand"])
        return True
    except OSError:
        try:
            os.unlink(vorlaeufig)
        except OSError:
            pass
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
        # Das Thema wird gefiltert. Der Praefix kommt aus der Oberflaeche;
        # ein # oder + darin waere ein MQTT-Platzhalter und nicht als
        # Themenbestandteil zulaessig - der Broker wiese die Nachricht ab.
        sauber = "".join(z if (z.isalnum() or z in "_-/") else "_"
                         for z in (praefix or "").strip())
        while "//" in sauber:
            sauber = sauber.replace("//", "/")
        self.praefix = sauber.strip("/") or "heimkino"
        self.log = log
        self.aktiv = aktiv
        self.client = None
        self._gemeldet = {}
        self._letzte = {}          # fuer das erneute Melden nach Wiederkehr
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
            self.client.on_connect = self._bei_verbindung
            self.client.reconnect_delay_set(min_delay=1, max_delay=60)
            # connect_async statt connect, und loop_start IMMER.
            #
            # Bis 1.1.1 wurde blockierend verbunden; scheiterte das, wurde
            # aktiv auf False gesetzt - und zwar dauerhaft. Genau dieser Fall
            # ist der wahrscheinlichste ueberhaupt: der Dienst startet beim
            # Systemstart, und der MQTT-Broker ist dann oft noch nicht so
            # weit. Das Plugin meldete danach bis zum naechsten Neustart
            # nichts mehr, ohne dass irgendwo etwas dazu stand.
            #
            # Mit connect_async kuemmert sich die Netzschleife von paho um
            # den Verbindungsaufbau UND um jedes spaetere Wiederverbinden.
            self.client.connect_async(zugang["host"], zugang["port"], 30)
            self.client.loop_start()
            self.log.info("MQTT: Verbindung zu %s:%s wird aufgebaut.",
                          zugang["host"], zugang["port"])
        except (OSError, ValueError) as fehler:
            self.log.warning("MQTT nicht einzurichten: %s", fehler)
            self.client = None
            self.aktiv = False

    def _bei_verbindung(self, client, benutzerdaten, kennzeichen, ergebnis, *rest):
        # noqa: ARG002
        if ergebnis != 0:
            self.log.warning("MQTT abgewiesen (Code %s) - Benutzer und Passwort "
                             "im MQTT-Gateway pruefen.", ergebnis)
            return
        self.log.info("MQTT verbunden.")
        # ALLES noch einmal senden.
        #
        # Beim Neustart des Brokers sind die zurueckbehaltenen Werte weg. Wer
        # sich darauf verlaesst, dass sie schon einmal gesendet wurden,
        # bekommt sie nie wieder - bis sich der Wert von sich aus aendert.
        # Bei "beamer/an" kann das Tage dauern.
        for thema, inhalt in list(self._letzte.items()):
            try:
                client.publish("%s/%s" % (self.praefix, thema), inhalt,
                               qos=0, retain=True)
            except (OSError, ValueError):
                pass

    def sende(self, thema, inhalt):
        if not self.aktiv or self.client is None:
            return
        if isinstance(inhalt, bool):
            inhalt = "1" if inhalt else "0"
        inhalt = "" if inhalt is None else str(inhalt)
        # Eine LEERE Nutzlast mit retain LOESCHT den zurueckbehaltenen Wert.
        #
        # Das steht so in der MQTT-Festlegung (3.1.1, Abschnitt 3.3.1.3): der
        # Broker verwirft bei einer Nachricht ohne Inhalt die zuvor
        # zurueckbehaltene. Bis 1.1.1 traf das mehrere Themen regelmaessig -
        # "beamer/app" ist leer, solange keine App laeuft, "last_error" fast
        # immer, "xbox/geheimnis_tage" wenn kein Ablaufdatum eingetragen ist.
        # Wer sich spaeter mit dem Broker verband, sah fuer diese Themen
        # ueberhaupt nichts, statt eines leeren Wertes.
        #
        # Deshalb ein Bindestrich statt nichts. Loxone liest ihn bei einem
        # Analogeingang als 0 und zeigt ihn bei einem Texteingang an - beides
        # ist besser als ein Thema, das es scheinbar gar nicht gibt.
        if inhalt == "":
            inhalt = "-"
        # Umbrueche raus: bei paho waeren sie zwar zulaessig, aber die Werte
        # gehen weiter an Loxone, und dort wird zeilenweise ausgewertet.
        inhalt = inhalt.replace("\r\n", " ").replace("\r", " ").replace("\n", " ")
        self._letzte[thema] = inhalt
        try:
            self.client.publish("%s/%s" % (self.praefix, thema),
                                inhalt, qos=0, retain=True)
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


# --------------------------------------------------------------------------
# Nur ein Dienst gleichzeitig
# --------------------------------------------------------------------------

def _ist_unser_dienst(pid):
    """Gehoert diese Prozessnummer wirklich zu hk_service.py?

    Verglichen werden die EINZELNEN Argumente, nicht die Kommandozeile als
    Zeichenkette. Eine Teilzeichenkettensuche ist hier untauglich, und das
    ist keine Theorie: beim Erproben dieser Funktion enthielt die
    Kommandozeile von Prozess 1 den Namen "hk_service.py" - weil das
    Pruefskript selbst mit diesem Pfad gestartet worden war. Die Pruefung
    haette den Dienst also fuer laufend gehalten und sich beendet.

    Genau dieselbe Schwaeche hatte pgrep -f, nur unbegrenzt: dort traf sie
    jeden Prozess im System.
    """
    try:
        with open("/proc/%s/cmdline" % pid, "rb") as datei:
            roh = datei.read()
    except OSError:
        return False       # Prozessnummer gibt es nicht mehr - verwaiste Datei
    teile = [t for t in roh.decode("utf-8", "replace").split("\0") if t]
    # Erwartet wird ".../hk_service.py" als eigenes Argument - entweder als
    # aufgerufenes Programm oder als Argument hinter dem Python-Programm.
    for teil in teile[:3]:
        if os.path.basename(teil) == "hk_service.py":
            return True
    return False


def pid_belegen(log):
    """Prozessnummer hinterlegen - und pruefen, ob schon einer laeuft.

    Ohne diese Sperre kann der Dienst zweimal laufen: das Startskript nutzt
    nohup ohne jede Pruefung, und die Oberflaeche hat einen Startknopf. Zwei
    Dienste befragen den Beamer im Wechsel - der nimmt aber nur EINE
    Verbindung zur Zeit an, und die Fernbedienung der App bleibt dann
    ausgesperrt.

    Erkannt wird ein Vorgaenger ueber /proc/<pid>/cmdline, nicht ueber
    pgrep -f: pgrep traefe auch einen Editor, in dem hk_service.py geoeffnet
    ist.

    Rueckgabe: True, wenn dieser Prozess weitermachen darf.
    """
    pfad = P["pid"]
    os.makedirs(os.path.dirname(pfad), exist_ok=True)
    try:
        with open(pfad, "r", encoding="utf-8") as datei:
            alt = datei.read().strip()
    except OSError:
        alt = ""
    if alt.isdigit() and int(alt) != os.getpid():
        if _ist_unser_dienst(alt):
            log.warning("Es laeuft bereits ein Dienst (PID %s) - beende mich.", alt)
            return False
    try:
        with open(pfad, "w", encoding="utf-8") as datei:
            datei.write("%d\n" % os.getpid())
        return True
    except OSError as fehler:
        # Kein Grund abzubrechen: ohne PID-Datei laeuft der Dienst, nur das
        # Beenden aus der Oberflaeche wird ungenauer.
        log.warning("PID-Datei %s nicht schreibbar (%s).", pfad, fehler)
        return True


def pid_freigeben():
    try:
        with open(P["pid"], "r", encoding="utf-8") as datei:
            if datei.read().strip() == str(os.getpid()):
                os.unlink(P["pid"])
    except OSError:
        pass
