#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Heimkino - gemeinsame Grundlage

Pfade, Konfiguration, Protokoll und MQTT. Enthaelt bewusst keine
Geraetelogik; die steht in lg_beamer.py und xbox_cloud.py.
"""

import configparser
import errno
import json
import logging
import os
import socket
import sys
import time


def lb_wurzel_ermitteln():
    """Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.

    Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
    config/plugins UND webfrontend enthaelt. Trifft die uebliche
    Installation genauso wie eine an einem anderen Ort.
    """
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "webfrontend")):
            return d
        eltern = os.path.dirname(d)
        if eltern == d:
            break
        d = eltern
    return ""


ORDNER = "heimkino"

# Groesse, ab der die Protokolldatei gekappt wird, und was danach stehen
# bleibt. log/plugins liegt auf einem LoxBerry im Arbeitsspeicher (tmpfs) -
# eine Datei, die dort unbegrenzt waechst, frisst keinen Plattenplatz,
# sondern RAM. Bis 1.2.11 gab es gar keine Kappung.
LOG_HOECHSTGROESSE = 512 * 1024
LOG_BEHALTEN = 256 * 1024


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
                    wert_roh = zeile.split("=", 1)[1].strip().strip('"').strip("'")
                    if os.path.isdir(wert_roh):
                        return wert_roh
    except OSError:
        pass
    for kandidat in (lb_wurzel_ermitteln(), "/home/loxberry/loxberry"):
        if os.path.isdir(kandidat):
            return kandidat
    return lb_wurzel_ermitteln()


def pfade():
    heim = _lbhome()
    eigen = os.path.dirname(os.path.abspath(__file__))
    return {
        "home": heim,
        "bin": eigen,
        "config": os.path.join(heim, "config", "plugins", ORDNER, "heimkino.cfg"),
        "auth": os.path.join(heim, "config", "plugins", ORDNER, "xbox_auth.json"),
        # Der Code aus der Microsoft-Rueckleitung wird hier abgelegt, statt
        # ihn ueber die Kommandozeile zu uebergeben - Argumente stehen in der
        # Prozessliste und sind fuer jeden lokalen Benutzer lesbar.
        "code": os.path.join(heim, "config", "plugins", ORDNER, "xbox_code.tmp"),
        "zustand": os.path.join(heim, "data", "plugins", ORDNER, "zustand.json"),
        # Auftraege des Aktionsendpunkts: was zuletzt geschaltet wurde und
        # welcher Zustand daraufhin erwartet wird. Der Dienst fasst nach -
        # er ist die einzige Stelle, die den Beamer befragen darf.
        "auftrag": os.path.join(heim, "data", "plugins", ORDNER, "auftrag.json"),
        # Betriebszaehler. Neustartfest, deshalb unter data/ und nicht
        # unter log/ - log/plugins ist eine Ramdisk.
        "betrieb": os.path.join(heim, "data", "plugins", ORDNER, "betrieb.json"),
        # Geraetesperre: Dienst UND Einzelbefehl nehmen sie, damit nicht zwei
        # Prozesse gleichzeitig mit dem Beamer sprechen. Siehe hk_sperre.py.
        "sperre": os.path.join(heim, "data", "plugins", ORDNER, "beamer.lock"),
        "soll": os.path.join(heim, "data", "plugins", ORDNER, "soll_laufen"),
        "logdir": os.path.join(heim, "log", "plugins", ORDNER),
        "log": os.path.join(heim, "log", "plugins", ORDNER, "heimkino.log"),
        "pid": os.path.join(heim, "data", "plugins", ORDNER, "hk_service.pid"),
        "general": os.path.join(heim, "config", "system", "general.json"),
        "vorgaben": os.path.join(eigen, "hk_vorgaben.json"),
        "themen": os.path.join(eigen, "hk_themen.json"),
    }


P = pfade()


# --------------------------------------------------------------------------
# Protokoll
# --------------------------------------------------------------------------

def log_kappen(pfad=None):
    """Die Protokolldatei kappen, bevor sie den Arbeitsspeicher auffrisst.

    Es wird nicht rotiert, sondern die zweite Haelfte behalten und der Rest
    verworfen - ein zweiter Dateiname waere ein zweiter Ort, an dem jemand
    suchen muss. Der Schnitt liegt auf einem Zeilenanfang.
    """
    pfad = pfad or P["log"]
    try:
        if os.path.getsize(pfad) <= LOG_HOECHSTGROESSE:
            return False
    except OSError:
        return False
    try:
        with open(pfad, "rb") as datei:
            datei.seek(-LOG_BEHALTEN, os.SEEK_END)
            rest = datei.read()
        schnitt = rest.find(b"\n")
        if 0 <= schnitt < len(rest) - 1:
            rest = rest[schnitt + 1:]
        vorlaeufig = "%s.%d.kappen" % (pfad, os.getpid())
        with open(vorlaeufig, "wb") as datei:
            datei.write(b"# ... aeltere Zeilen wurden gekappt ...\n")
            datei.write(rest)
            datei.flush()
            os.fsync(datei.fileno())
        os.replace(vorlaeufig, pfad)
        return True
    except OSError:
        return False


def protokoll_einrichten(name="heimkino", stufe=logging.INFO):
    os.makedirs(P["logdir"], exist_ok=True)
    log_kappen()
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
#
# Die Vorgaben stehen NICHT hier, sondern in bin/hk_vorgaben.json - derselben
# Datei, die auch die Oberflaeche liest. Bis 1.2.11 gab es zwei getrennt
# gepflegte Listen; die eine kannte geheimnis_ablauf, die andere nicht.
# --------------------------------------------------------------------------

def _vorgaben_datei():
    try:
        with open(P["vorgaben"], "r", encoding="utf-8") as datei:
            inhalt = json.load(datei)
    except (OSError, ValueError):
        return None
    abschnitte = inhalt.get("abschnitte") if isinstance(inhalt, dict) else None
    if not isinstance(abschnitte, list):
        return None
    ergebnis = {}
    for abschnitt in abschnitte:
        if not isinstance(abschnitt, dict):
            continue
        name = str(abschnitt.get("name", "")).strip()
        if not name:
            continue
        werte = {}
        for schluessel in abschnitt.get("schluessel", []):
            if isinstance(schluessel, dict) and schluessel.get("name"):
                werte[str(schluessel["name"])] = str(schluessel.get("vorgabe", ""))
        ergebnis[name] = werte
    return ergebnis or None


def vorgaben():
    """Vorgabewerte, aus der gemeinsamen Datei gelesen.

    Faellt die Datei aus, gibt es keinen Rueckfall auf eine zweite Liste im
    Quelltext - genau die waere die zweite Wahrheit, die hier abgeschafft
    wurde. Stattdessen ein leeres Ergebnis; der Aufrufer merkt es und meldet
    es, statt still mit erfundenen Vorgaben weiterzulaufen.
    """
    zwischen = getattr(vorgaben, "_zwischen", None)
    if zwischen is None:
        zwischen = _vorgaben_datei() or {}
        vorgaben._zwischen = zwischen
    return dict((a, dict(w)) for a, w in zwischen.items())


def themen():
    """MQTT-Themen aus der gemeinsamen Datei. Liste von Woerterbuechern."""
    zwischen = getattr(themen, "_zwischen", None)
    if zwischen is None:
        try:
            with open(P["themen"], "r", encoding="utf-8") as datei:
                inhalt = json.load(datei)
            zwischen = [t for t in inhalt.get("themen", []) if isinstance(t, dict)]
        except (OSError, ValueError):
            zwischen = []
        themen._zwischen = zwischen
    return list(zwischen)


def config_lesen(log=None):
    """Konfiguration lesen. Gibt (cfg, lage) zurueck.

    lage: "ok" | "fehlt" | "kaputt" | "keine_vorgaben"

    Bis 1.2.11 wurde ein Lesefehler mit "pass" verschluckt. Eine kaputte
    Datei - ein doppelter Schluessel genuegt - liess den Dienst mit
    aktiv=0 weiterlaufen und melden, beide Geraete seien abgeschaltet.
    Ohne eine Zeile im Protokoll.
    """
    vg = vorgaben()
    cfg = configparser.ConfigParser()
    cfg.optionxform = str
    for abschnitt, werte in vg.items():
        cfg[abschnitt] = dict(werte)
    if not vg:
        if log:
            log.error("bin/hk_vorgaben.json fehlt oder ist unlesbar (%s). "
                      "Ohne Vorgaben kann der Dienst nicht beurteilen, was "
                      "eingestellt ist - Plugin neu installieren.", P["vorgaben"])
        return cfg, "keine_vorgaben"
    if not os.path.isfile(P["config"]):
        if log:
            log.warning("Konfiguration %s fehlt - es gelten die Vorgaben.",
                        P["config"])
        return cfg, "fehlt"
    try:
        cfg.read(P["config"], encoding="utf-8")
    except (OSError, configparser.Error) as fehler:
        if log:
            log.error("Konfiguration %s ist unlesbar (%s). Es gelten die "
                      "Vorgaben - beide Geraete gelten damit als abgeschaltet, "
                      "obwohl sie es vielleicht nicht sind.",
                      P["config"], fehler)
        return cfg, "kaputt"
    # Fehlende Abschnitte nachziehen, damit kein Zugriff scheitert.
    for abschnitt, werte in vg.items():
        if not cfg.has_section(abschnitt):
            cfg.add_section(abschnitt)
        for schluessel, vorgabe in werte.items():
            if not cfg.has_option(abschnitt, schluessel):
                cfg.set(abschnitt, schluessel, vorgabe)
    return cfg, "ok"


def config_fehlende():
    """Welche Schluessel fehlen in der Datei? Liste "abschnitt.schluessel"."""
    gelesen = configparser.ConfigParser()
    gelesen.optionxform = str
    try:
        gelesen.read(P["config"], encoding="utf-8")
    except (OSError, configparser.Error):
        return []
    fehlen = []
    for abschnitt, werte in vorgaben().items():
        for schluessel in werte:
            if not gelesen.has_option(abschnitt, schluessel):
                fehlen.append("%s.%s" % (abschnitt, schluessel))
    return fehlen


def config_schreiben(cfg):
    """Konfiguration unteilbar schreiben, Rechte 0640 VOR dem Inhalt.

    os.open mit dem Rechtemuster legt die Datei gleich geschuetzt an. Ein
    chmod NACH dem Schreiben laesst sie fuer die Dauer des Schreibens mit
    den Vorgaben der umask dastehen - in dieser Datei steht der Keycode des
    Beamers und das Aktionstoken.
    """
    ziel = P["config"]
    os.makedirs(os.path.dirname(ziel), exist_ok=True)
    text = ("; Heimkino\n"
            "; Wird von der Plugin-Oberflaeche und vom Dienst geschrieben.\n"
            "; ACHTUNG: enthaelt den Keycode des Beamers - nicht veroeffentlichen.\n\n")
    for abschnitt, werte in vorgaben().items():
        text += "[%s]\n" % abschnitt
        for schluessel in werte:
            text += "%s=%s\n" % (schluessel, wert(cfg, abschnitt, schluessel, ""))
        text += "\n"
    vorlaeufig = "%s.%d.neu" % (ziel, os.getpid())
    try:
        kennung = os.open(vorlaeufig, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o640)
        with os.fdopen(kennung, "w", encoding="utf-8") as datei:
            datei.write(text)
            datei.flush()
            os.fsync(datei.fileno())
        os.replace(vorlaeufig, ziel)
        return True
    except OSError:
        try:
            os.unlink(vorlaeufig)
        except OSError:
            pass
        return False


def config_vervollstaendigen(cfg, log=None):
    """Fehlende Schluessel EINMAL in die Datei schreiben.

    Ergaenzen beim Lesen genuegt nicht: die Datei bleibt dann lueckenhaft,
    und "fehlt" ist von "steht auf dem Vorgabewert" nicht zu unterscheiden.
    Geschrieben wird nur, wenn wirklich etwas gefehlt hat - sonst aendert
    sich die Datei bei jedem Lauf ohne Anlass.
    """
    fehlten = config_fehlende()
    if fehlten and config_schreiben(cfg) and log:
        log.info("Konfiguration ergaenzt: %s", ", ".join(fehlten))
    return fehlten


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
# Kleine JSON-Ablagen: Auftraege und Betriebszaehler
#
# Beide werden mit demselben unteilbaren Schreibweg abgelegt wie die
# Zustandsdatei - Zwischenname mit Prozessnummer, fsync, dann umbenennen.
# --------------------------------------------------------------------------

def json_lesen(pfad, vorgabe=None):
    try:
        with open(pfad, "r", encoding="utf-8") as datei:
            inhalt = json.load(datei)
    except (OSError, ValueError):
        return vorgabe
    return inhalt if isinstance(inhalt, dict) else vorgabe


def json_schreiben(pfad, daten, rechte=0o640):
    os.makedirs(os.path.dirname(pfad), exist_ok=True)
    vorlaeufig = "%s.%d.neu" % (pfad, os.getpid())
    try:
        text = json.dumps(daten, ensure_ascii=False, indent=1)
    except (TypeError, ValueError):
        return False
    try:
        kennung = os.open(vorlaeufig, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, rechte)
        with os.fdopen(kennung, "w", encoding="utf-8") as datei:
            datei.write(text)
            datei.flush()
            os.fsync(datei.fileno())
        os.replace(vorlaeufig, pfad)
        return True
    except OSError:
        try:
            os.unlink(vorlaeufig)
        except OSError:
            pass
        return False


def auftrag_stellen(aktion, ziel, frist, wert=""):
    """Einen Auftrag fuer den Dienst hinterlegen.

    Aufgerufen von hk_cmd.py, also vom Aktionsendpunkt. Der Endpunkt selbst
    wartet NICHT auf die Wirkung: ein virtueller Ausgang in Loxone hat ein
    Zeitlimit, und der Beamer nimmt ohnehin nur eine Verbindung zur Zeit an.
    Nachgefasst wird deshalb im Dienst.
    """
    return json_schreiben(P["auftrag"], {
        "aktion": aktion,
        "wert": wert,
        "ziel": ziel,              # erwarteter Zustand, z. B. "aus" oder "On"
        "gestellt": time.time(),
        "frist": float(frist),
    })


def auftrag_lesen():
    return json_lesen(P["auftrag"])


def auftrag_loeschen():
    try:
        os.unlink(P["auftrag"])
        return True
    except OSError:
        return False


def _heute():
    return time.strftime("%Y-%m-%d", time.localtime())


def betrieb_fortschreiben(laeuft, vergangen, geraet):
    """Betriebszeit fortschreiben. Gibt (stunden, minuten_heute) zurueck.

    AUSDRUECKLICH eine Schaetzung: gezaehlt wird die Zeit zwischen zwei
    Abfragen, wenn das Geraet bei der zweiten lief. Bei einem Takt von 60 s
    liegt der Fehler je Ein- und Ausschaltvorgang bei bis zu einer Minute.
    Das Geraet selbst wird nicht gefragt - es liefert diese Zahl nicht.

    Ein Sprung in der Systemzeit oder eine lange Pause wird nicht mitgezaehlt:
    was laenger als das Dreifache eines langen Takts her ist, gilt als Luecke.
    """
    daten = json_lesen(P["betrieb"], {}) or {}
    eintrag = daten.get(geraet)
    if not isinstance(eintrag, dict):
        eintrag = {"sekunden": 0.0, "tag": _heute(), "tag_sekunden": 0.0}
    if eintrag.get("tag") != _heute():
        eintrag["tag"] = _heute()
        eintrag["tag_sekunden"] = 0.0
    if laeuft and 0 < vergangen <= 3 * 3600:
        eintrag["sekunden"] = float(eintrag.get("sekunden", 0.0)) + vergangen
        eintrag["tag_sekunden"] = float(eintrag.get("tag_sekunden", 0.0)) + vergangen
    daten[geraet] = eintrag
    json_schreiben(P["betrieb"], daten)
    return (round(eintrag["sekunden"] / 3600.0, 1),
            int(eintrag["tag_sekunden"] / 60.0))


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


def praefix_saeubern(roh):
    """Themenpraefix auf zulaessige Zeichen bringen.

    Muss zeichengleich zu dem sein, was die Oberflaeche zulaesst - sonst
    vergleicht der Dienst spaeter einen gesaeuberten mit einem rohen Wert
    und baut die MQTT-Verbindung bei jeder Konfigurationsaenderung ohne Not
    neu auf. Genau das war bis 1.2.11 der Fall: die Oberflaeche ENTFERNTE
    unerlaubte Zeichen, der Dienst ERSETZTE sie durch einen Unterstrich.

    Ein # oder + waere ein MQTT-Platzhalter und im Thema unzulaessig; der
    Broker wiese die Nachricht ab.
    """
    sauber = "".join(z if (z.isalnum() or z in "_-/") else "_"
                     for z in (roh or "").strip())
    while "//" in sauber:
        sauber = sauber.replace("//", "/")
    return sauber.strip("/") or "heimkino"


class Melder:
    """Duenne Huelle um paho-mqtt.

    Faellt still auf Nichtstun zurueck, wenn MQTT aus ist oder das Modul
    fehlt. Ein Plugin, das ohne Broker gar nicht mehr startet, waere schlimmer
    als eines, das ohne Broker nur nicht meldet.
    """

    def __init__(self, praefix, log, aktiv=True):
        self.praefix = praefix_saeubern(praefix)
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
            # Letzter Wille. Bis 1.2.11 wurde service/online nur beim
            # GEORDNETEN Ende auf 0 gesetzt. Bei SIGKILL, Speichermangel oder
            # Stromausfall blieb die zurueckbehaltene 1 dauerhaft stehen - in
            # Loxone sah ein toter Dienst aus wie ein laufender, und
            # virtuelle Eingaenge behalten ihren letzten Wert. Der Broker
            # setzt sie jetzt selbst, sobald die Verbindung wegbricht.
            self.client.will_set("%s/service/online" % self.praefix, "0",
                                 qos=0, retain=True)
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
            auskunft = self.client.publish("%s/%s" % (self.praefix, thema),
                                           inhalt, qos=0, retain=True)
        except (OSError, ValueError) as fehler:
            self.log.debug("MQTT-Versand fehlgeschlagen: %s", fehler)
            return
        # publish() wirft bei getrennter Verbindung KEINE Ausnahme, sondern
        # liefert rc = MQTT_ERR_NO_CONN; bei QoS 0 ist die Nachricht damit
        # verworfen. Bis 1.2.11 stand deshalb nie etwas im Protokoll, wenn
        # nichts ankam - wer nachsah, warum, fand keine Spur. Die Meldung
        # laeuft ueber die Bremse, sonst schreibt sie jeden Takt.
        rc = getattr(auskunft, "rc", 0)
        if rc:
            einmal_melden(self._gemeldet, "mqtt_kein_versand",
                          "MQTT: Nachrichten werden verworfen (Code %s) - "
                          "die Verbindung zum Broker steht nicht." % rc,
                          self.log, "warning")

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


# Warum eine Verbindung nicht zustande kam. Diese Woerter gehen als
# beamer/grund an Loxone und stehen so in bin/hk_themen.json.
GRUND_TEXT = {
    "ok": "Das Geraet antwortet.",
    "aus": "Das Geraet ist in den Einstellungen abgeschaltet.",
    "keine_adresse": "Es ist keine Adresse des Beamers eingetragen.",
    "abgewiesen": "Das Geraet ist im Netz erreichbar, aber auf diesem Port "
                  "hoert nichts. Haeufigster Grund und voellig harmlos: der "
                  "Beamer ist aus - die IP-Steuerung laeuft nur im "
                  "eingeschalteten Zustand. Ist er an, steht die "
                  "Netzwerk-IP-Steuerung am Geraet auf aus.",
    "kein_weg": "Kein Weg zu dieser Adresse. Stimmt die IP noch? Ohne feste "
                "Adresse in der Fritz!Box wandert sie irgendwann.",
    "zeitueberschreitung": "Niemand antwortet innerhalb der Zeitgrenze. Das "
                          "Geraet ist vom Netz, oder eine Firewall verwirft "
                          "die Pakete stillschweigend.",
    "name_unbekannt": "Der Rechnername laesst sich nicht aufloesen. Steht dort "
                      "ein Name statt einer IP-Adresse?",
    "besetzt": "Ein anderer Vorgang sprach gerade mit dem Geraet. Der "
               "Durchgang wurde uebersprungen - das ist kein Ausfall, und "
               "die zuletzt gemeldeten Werte bleiben stehen.",
    "fehler": "Die Verbindung kam aus einem anderen Grund nicht zustande.",
}


def erreichbarkeit(host, port, zeitgrenze=2.0, sperre=None, warten=10.0):
    """Antwortet an host:port etwas - und wenn nicht, WARUM nicht?

    Gibt (erreichbar, grund, text) zurueck.

    Bis 1.2.11 gab es hier nur True/False. Damit landeten eine falsche IP,
    ein unaufloesbarer Name, eine schweigende Firewall und "das Geraet ist
    aus" auf demselben Wert - und der Dienst meldete daraufhin
    beamer/status = aus, beamer/an = 0 und einen LEEREN last_error. In
    Loxone sah ein Defekt damit genau aus wie der Normalzustand. Das ist
    die stille Falschaussage, die der Hausstandard als schwerste
    Fehlerklasse fuehrt.

    Die Unterscheidung selbst gab es sogar schon - in
    lg_beamer._verbindungsfehler(). Sie wurde nur nie erreicht, weil dieser
    Vorabtest sie abschnitt.
    """
    if not host:
        return False, "keine_adresse", GRUND_TEXT["keine_adresse"]
    # Auch dieser Griff geht an denselben Port und muss deshalb dieselbe
    # Sperre nehmen. Sie ist im selben Prozess wiedereintrittsfaehig, der
    # Dienst darf sie also schon halten.
    from hk_sperre import Sperre, SperreBesetzt
    try:
        with Sperre(sperre, warten=warten), \
                socket.create_connection((host, int(port)), timeout=zeitgrenze):
            return True, "ok", GRUND_TEXT["ok"]
    except SperreBesetzt:
        return False, "besetzt", GRUND_TEXT["besetzt"]
    except socket.gaierror:
        return False, "name_unbekannt", GRUND_TEXT["name_unbekannt"]
    except socket.timeout:
        return False, "zeitueberschreitung", GRUND_TEXT["zeitueberschreitung"]
    except ValueError:
        return False, "keine_adresse", GRUND_TEXT["keine_adresse"]
    except OSError as fehler:
        nummer = getattr(fehler, "errno", None)
        if nummer == errno.ECONNREFUSED:
            return False, "abgewiesen", GRUND_TEXT["abgewiesen"]
        if nummer in (errno.EHOSTUNREACH, errno.ENETUNREACH, errno.EHOSTDOWN):
            return False, "kein_weg", GRUND_TEXT["kein_weg"]
        if nummer == errno.ETIMEDOUT:
            return False, "zeitueberschreitung", GRUND_TEXT["zeitueberschreitung"]
        return False, "fehler", "%s (%s)" % (GRUND_TEXT["fehler"], fehler)


def erreichbar(host, port, zeitgrenze=2.0):
    """Nur die Ja-Nein-Frage. Wer den Grund braucht, nimmt erreichbarkeit()."""
    return erreichbarkeit(host, port, zeitgrenze)[0]


def wol_senden(mac, adresse="255.255.255.255", port=9):
    """Magic Packet verschicken.

    Loxone kann Wake-on-LAN selbst (wol://). Dieser Weg steht trotzdem als
    Aktion bereit, damit sich die MAC pruefen laesst, ohne den Miniserver
    anzufassen.
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

_SPERRE = None      # bleibt offen, solange der Prozess laeuft


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

    Die Sperre haelt jetzt eine echte Dateisperre (flock). Bis 1.2.11 lagen
    zwischen dem Lesen und dem Schreiben der PID-Datei mehrere Anweisungen
    ohne jede Unteilbarkeit: starteten der Systemstart und der Startknopf
    der Oberflaeche gleichzeitig, sahen BEIDE "keine PID da", schrieben
    beide und liefen beide weiter. Der Beamer nimmt aber nur EINE Verbindung
    zur Zeit an - zwei Dienste im Wechsel sperren die Fernbedienung der App
    aus. Das ist genau der Fall, den der alte Kommentar zu verhindern
    behauptete.

    Faellt flock aus (kein fcntl, Dateisystem ohne Sperren), wird auf die
    alte Pruefung ueber /proc zurueckgefallen - und das steht dann im
    Protokoll, statt so auszusehen, als sei gesperrt worden.

    Rueckgabe: True, wenn dieser Prozess weitermachen darf.
    """
    global _SPERRE
    pfad = P["pid"]
    os.makedirs(os.path.dirname(pfad), exist_ok=True)
    try:
        import fcntl
    except ImportError:
        fcntl = None

    if fcntl is not None:
        try:
            _SPERRE = open(pfad, "a+", encoding="utf-8")
            try:
                fcntl.flock(_SPERRE.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            except OSError:
                _SPERRE.seek(0)
                alt = _SPERRE.read().strip()
                _SPERRE.close()
                _SPERRE = None
                log.warning("Es laeuft bereits ein Dienst (PID %s) - beende mich.",
                            alt or "unbekannt")
                return False
            _SPERRE.seek(0)
            _SPERRE.truncate(0)
            _SPERRE.write("%d\n" % os.getpid())
            _SPERRE.flush()
            return True
        except OSError as fehler:
            log.warning("PID-Datei %s nicht sperrbar (%s) - es gilt die "
                        "schwaechere Pruefung ueber /proc.", pfad, fehler)
            if _SPERRE is not None:
                try:
                    _SPERRE.close()
                except OSError:
                    pass
                _SPERRE = None
    else:
        log.warning("fcntl steht nicht zur Verfuegung - es gilt die schwaechere "
                    "Pruefung ueber /proc.")

    try:
        with open(pfad, "r", encoding="utf-8") as datei:
            alt = datei.read().strip()
    except OSError:
        alt = ""
    if alt.isdigit() and int(alt) != os.getpid() and _ist_unser_dienst(alt):
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
    global _SPERRE
    if _SPERRE is not None:
        try:
            _SPERRE.close()
        except OSError:
            pass
        _SPERRE = None
    try:
        with open(P["pid"], "r", encoding="utf-8") as datei:
            if datei.read().strip() == str(os.getpid()):
                os.unlink(P["pid"])
    except OSError:
        pass
