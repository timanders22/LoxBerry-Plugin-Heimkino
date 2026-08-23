#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Heimkino - IP-Steuerung fuer LG-Geraete ab 2018 (Beamer und Fernseher)

Nachbau des Verfahrens aus WesSouza/lgtv-ip-control (MIT). Die Vorlage ist
JavaScript; hier steht dieselbe Rechnung in Python. Die Uebereinstimmung ist
nachgemessen: `--selbsttest` vergleicht mit Werten, die die Originalfassung
unter Node erzeugt hat - seit 1.3.0 fuer ALLE neunzehn angebundenen Befehle,
nicht nur fuer fuenf.

Ablauf eines Befehls
--------------------
1. Schluessel: PBKDF2-HMAC-SHA256 ueber den achtstelligen Keycode,
   festes 16-Byte-Salz, 16384 Runden, 16 Byte Ergebnis (AES-128).
2. Nachricht: Befehl + Wagenruecklauf, dann auffuellen auf ein Vielfaches
   von 16. Ist die Laenge schon ein Vielfaches, kommt erst ein Leerzeichen
   dazu - sonst waere nicht erkennbar, wo die Fuellung beginnt.
3. Zufaelliger 16-Byte-Startwert (IV).
4. Gesendet wird: der Startwert, mit AES-128-ECB verschluesselt, dahinter
   die Nachricht mit AES-128-CBC und diesem Startwert.
5. Die Antwort ist genauso gebaut und endet auf einen Zeilenvorschub.

Warum ECB fuer den Startwert: das Geraet muss ihn entschluesseln koennen,
bevor es einen Startwert hat. Ein Huhn-Ei-Problem, das die Vorlage so loest.

Die Wortlisten weiter unten (Eingaenge, Bildmodi, ...) stammen aus
src/constants/TV.ts der Vorlage. Ein Wert, der nicht darin steht, wird
ABGEWIESEN und nicht ans Geraet geschickt: die Vorlage prueft ebenso, und ein
falscher Wert kaeme als unlesbare Antwort zurueck statt als Fehler.
"""

import errno
import hashlib
import os
import re
import socket
import sys

# Die Geraetesperre. Ohne sie laeuft alles wie vor 1.3.0 - das haelt den
# Selbsttest dieser Datei eigenstaendig, auch auf einem Rechner ohne
# LoxBerry-Umgebung.
try:
    from hk_sperre import Sperre, SperreBesetzt
except ImportError:                      # pragma: keine Sperre verfuegbar
    class SperreBesetzt(Exception):
        pass

    class Sperre:                        # noqa: D101 - Ersatz ohne Wirkung
        def __init__(self, pfad, warten=0.0):
            pass

        def __enter__(self):
            return self

        def __exit__(self, art, wert, spur):
            return False

SALZ = bytes([0x63, 0x61, 0xb8, 0x0e, 0x9b, 0xdc, 0xa6, 0x63,
              0x8d, 0x07, 0x20, 0xf2, 0xcc, 0x56, 0x8f, 0xb9])
RUNDEN = 1 << 14
SCHLUESSELLAENGE = 16
BLOCK = 16
ABSCHLUSS = "\r"
ANTWORT_ENDE = "\n"
PORT_VORGABE = 9761


class BeamerFehler(Exception):
    pass


def _aes():
    try:
        from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
    except ImportError as fehler:
        raise BeamerFehler(
            "python3-cryptography fehlt. Nachinstallieren: "
            "sudo apt-get install -y python3-cryptography") from fehler
    return Cipher, algorithms, modes


class LgBeamer:
    """Eine Sitzung mit einem LG-Geraet.

    Absichtlich ohne Dauerverbindung: das Geraet nimmt nur eine Verbindung
    zur Zeit an, und ein Dienst, der sie dauerhaft haelt, sperrt jede andere
    Steuerung aus. Jeder Befehl oeffnet und schliesst.
    """

    KEYCODE_ZEICHEN = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"

    # Woerter aus src/constants/TV.ts der Vorlage, Zweig main.
    EINGAENGE = ("dtv", "atv", "cadtv", "catv", "avav1", "component1",
                 "hdmi1", "hdmi2", "hdmi3", "hdmi4")
    BILDMODI = ("cinema", "eco", "filmMaker", "game", "normal", "sports",
                "vivid")
    ENERGIESPAREN = ("auto", "screenoff", "maximum", "medium", "minimum", "off")
    BILDSTUMM = ("screenmuteon", "videomuteon", "allmuteoff")
    MAC_ARTEN = ("wired", "wifi")

    def __init__(self, ip, keycode="", port=PORT_VORGABE, zeitgrenze=5.0,
                 unverschluesselt=False, sperre=None, sperre_warten=10.0):
        self.ip = (ip or "").strip()
        # KEIN .upper(). Bis 1.2.11 stand hier eines - eine stille Umschrift
        # genau der acht Zeichen, aus denen der Schluessel abgeleitet wird.
        # Die Vorlage (DefaultSettings.keycodeFormat) prueft /[A-Z0-9]{8}/
        # und wandelt nichts; ein kleingeschriebener Keycode ist dort ein
        # Fehler und keine Eingabe zum Zurechtbiegen.
        self.keycode = (keycode or "").strip()
        self.port = int(port or PORT_VORGABE)
        self.zeitgrenze = float(zeitgrenze or 5.0)
        self.unverschluesselt = bool(unverschluesselt)
        # Ein LG-Geraet nimmt genau EINE Verbindung zur Zeit an, und am Beamer
        # arbeiten drei voneinander unabhaengige Prozesse (Dienst,
        # Einzelbefehl aus dem Aktionsendpunkt, Knopf im Reiter Test). Ohne
        # gemeinsame Sperre verliert bei einem Zusammentreffen einer von
        # beiden - und ein virtueller Ausgang in Loxone wertet die Antwort
        # nicht aus, der Ausfall bliebe still. Siehe hk_sperre.py.
        self.sperre = sperre or None
        self.sperre_warten = float(sperre_warten)
        self._schluessel = None
        if self.keycode:
            self._schluessel = self.schluessel_ableiten(self.keycode)

    # ---------------- Verschluesselung ----------------

    @staticmethod
    def keycode_gueltig(keycode):
        code = (keycode or "").strip()
        return len(code) == 8 and all(z in LgBeamer.KEYCODE_ZEICHEN for z in code)

    @staticmethod
    def schluessel_ableiten(keycode):
        code = (keycode or "").strip()
        if not LgBeamer.keycode_gueltig(code):
            raise BeamerFehler(
                "Der Keycode muss aus genau 8 Zeichen A-Z und 0-9 in "
                "Grossschreibung bestehen; kleingeschrieben ergibt er einen "
                "anderen Schluessel und das Geraet antwortet unlesbar. Er wird "
                "am Geraet unter Netzwerk-IP-Steuerung erzeugt und steht dort "
                "gross.")
        return hashlib.pbkdf2_hmac("sha256", code.encode("ascii"), SALZ,
                                   RUNDEN, SCHLUESSELLAENGE)

    @staticmethod
    def auffuellen(text):
        neu = text
        if len(neu) % BLOCK == 0:
            neu += " "
        rest = len(neu) % BLOCK
        if rest:
            fuell = BLOCK - rest
            neu += chr(fuell) * fuell
        return neu

    def kodieren(self, befehl, iv=None):
        # Erst pruefen, dann zusammensetzen.
        if ABSCHLUSS in befehl:
            raise BeamerFehler("Der Befehl darf keinen Wagenruecklauf enthalten.")
        nachricht = befehl + ABSCHLUSS
        if self._schluessel is None:
            return nachricht.encode("ascii")
        Cipher, algorithms, modes = _aes()
        if iv is None:
            iv = os.urandom(BLOCK)
        daten = self.auffuellen(nachricht).encode("ascii")
        ecb = Cipher(algorithms.AES(self._schluessel), modes.ECB()).encryptor()
        iv_enc = ecb.update(iv) + ecb.finalize()
        cbc = Cipher(algorithms.AES(self._schluessel), modes.CBC(iv)).encryptor()
        return iv_enc + cbc.update(daten) + cbc.finalize()

    def dekodieren(self, roh, streng=True):
        """Antwort entschluesseln.

        Die Antwort endet auf einen Zeilenvorschub. Fehlt der, ist der
        Klartext kein Klartext - dann stimmt der Keycode nicht, oder es ist
        erst ein Teil angekommen. Mit streng=True gibt es dafuer einen
        Fehler, mit streng=False ein None; letzteres braucht die Leseschleife,
        um zu erkennen, dass noch etwas fehlt.

        Diese Unterscheidung ist nicht kosmetisch: ohne sie liefert ein
        falscher Keycode stillschweigend ein leeres Ergebnis, und
        ip_steuerung() antwortet dann "aus", obwohl niemand gefragt wurde.
        """
        if self._schluessel is None:
            text = roh.decode("latin-1")
        else:
            if len(roh) < 2 * BLOCK or len(roh) % BLOCK:
                if streng:
                    raise BeamerFehler(
                        "Antwort hat eine unbrauchbare Laenge (%d Byte)." % len(roh))
                return None
            Cipher, algorithms, modes = _aes()
            ecb = Cipher(algorithms.AES(self._schluessel), modes.ECB()).decryptor()
            iv = ecb.update(roh[:BLOCK]) + ecb.finalize()
            cbc = Cipher(algorithms.AES(self._schluessel), modes.CBC(iv)).decryptor()
            klar = cbc.update(roh[BLOCK:]) + cbc.finalize()
            text = klar.decode("latin-1")
        if ANTWORT_ENDE in text:
            return text.split(ANTWORT_ENDE)[0]
        if streng:
            raise BeamerFehler(
                "Die Antwort liess sich nicht lesen. Der haeufigste Grund ist "
                "ein falscher Keycode - er wird am Gerät neu erzeugt und "
                "muss danach hier eingetragen werden.")
        return None

    # ---------------- Verbindung ----------------

    def befehl(self, text):
        """Einen Befehl schicken und die Antwort im Klartext zurueckgeben."""
        if not self.ip:
            raise BeamerFehler("Keine IP-Adresse des Beamers eingetragen.")
        # Ohne Keycode wuerde unverschluesselt gesendet. Ein LG-Geraet ab
        # Baujahr 2018 antwortet darauf nicht brauchbar; die Antwort waere
        # Zeichensalat und landete als "laufende Quelle" in Loxone oder
        # ergaebe ein falsches "an". Bis 1.2.11 geschah genau das still,
        # sobald das Feld leer war.
        if self._schluessel is None and not self.unverschluesselt:
            raise BeamerFehler(
                "Es ist kein Keycode eingetragen. Ohne ihn nimmt ein LG-Geraet "
                "ab Baujahr 2018 keinen Befehl an. Der Keycode wird am Geraet "
                "im versteckten Menue erzeugt (Alle Einstellungen, Allgemein, "
                "Netzwerk ansteuern - nicht oeffnen - dann zuegig 82888 "
                "tippen) und gehoert in den Reiter Einstellungen.")
        anfrage = self.kodieren(text)
        try:
            # Die Sperre umschliesst den GANZEN Wortwechsel, nicht nur das
            # Verbinden: das Geraet ist erst wieder frei, wenn die Antwort
            # gelesen und die Verbindung geschlossen ist.
            with Sperre(self.sperre, warten=self.sperre_warten), \
                    socket.create_connection((self.ip, self.port),
                                             timeout=self.zeitgrenze) as buchse:
                buchse.settimeout(self.zeitgrenze)
                buchse.sendall(anfrage)
                stueck = b""
                # Bis zum Zeilenvorschub lesen. Verschluesselt ist der nicht
                # sichtbar, deshalb wird nach jedem Block versucht zu
                # entschluesseln, bis es aufgeht.
                while len(stueck) < 4096:
                    teil = buchse.recv(1024)
                    if not teil:
                        break
                    stueck += teil
                    if self._schluessel is None:
                        if ANTWORT_ENDE.encode() in stueck:
                            break
                    elif len(stueck) >= 2 * BLOCK and len(stueck) % BLOCK == 0:
                        fertig = self.dekodieren(stueck, streng=False)
                        if fertig is not None:
                            return fertig
        except SperreBesetzt as fehler:
            raise BeamerFehler(str(fehler)) from fehler
        except socket.timeout as fehler:
            raise BeamerFehler(
                "Keine Antwort von %s:%d innerhalb von %.0f Sekunden. Steht die "
                "Netzwerk-IP-Steuerung am Gerät auf ein?"
                % (self.ip, self.port, self.zeitgrenze)) from fehler
        except OSError as fehler:
            raise BeamerFehler(self._verbindungsfehler(fehler)) from fehler
        if not stueck:
            raise BeamerFehler("Das Gerät hat die Verbindung ohne Antwort beendet.")
        return self.dekodieren(stueck)

    def _verbindungsfehler(self, fehler):
        """Aus einem Betriebssystemfehler eine brauchbare Erklaerung machen.

        "Connection refused" und "Timeout" bedeuten voellig Verschiedenes, und
        der reine Errno-Text hilft niemandem weiter.
        """
        nummer = getattr(fehler, "errno", None)
        wo = "%s:%d" % (self.ip, self.port)

        if nummer == errno.ECONNREFUSED:
            return (
                "%s weist die Verbindung ab. Das Gerät ist im Netz erreichbar, "
                "aber auf diesem Port hoert nichts.\n"
                "Der haeufigste Grund ist voellig harmlos: der Beamer ist aus. "
                "Die IP-Steuerung läuft nur im eingeschalteten Zustand - was für "
                "einen Ausschaltbefehl auch genuegt.\n"
                "Ist der Beamer an und es kommt dennoch diese Meldung, steht die "
                "Netzwerk-IP-Steuerung am Gerät auf aus. Sie liegt im versteckten "
                "Menue: Alle Einstellungen, Allgemein, Netzwerk ansteuern - ohne zu "
                "oeffnen - dann zuegig 82888 tippen." % wo)

        if nummer in (errno.EHOSTUNREACH, errno.ENETUNREACH):
            return ("%s ist nicht erreichbar - kein Weg dorthin. Stimmt die "
                    "IP-Adresse noch? Ohne feste Adresse in der Fritz!Box wandert "
                    "sie irgendwann." % wo)

        if nummer == errno.EHOSTDOWN:
            return "%s antwortet nicht - das Gerät scheint vom Netz zu sein." % wo

        return "Verbindung zu %s nicht möglich: %s" % (wo, fehler)

    # ---------------- Befehle ----------------

    def _ok(self, text, was):
        """Einen schaltenden Befehl senden und auf OK bestehen.

        Die Vorlage macht dasselbe (throwIfNotOK): alles ausser genau "OK"
        ist ein Fehler. Ein Geraet, das etwas anderes antwortet, hat den
        Befehl nicht ausgefuehrt - das darf nicht als Erfolg durchgehen.
        """
        antwort = self.befehl(text)
        if antwort.strip() != "OK":
            raise BeamerFehler("%s: das Gerät hat nicht mit OK geantwortet, "
                               "sondern mit: %r" % (was, antwort))
        return True

    @staticmethod
    def _aus_liste(wert, liste, was):
        """Einen Wert gegen die Wortliste der Vorlage halten - oder abweisen.

        Gross- und Kleinschreibung bleibt dabei erhalten: der Bildmodus
        heisst in der Vorlage woertlich filmMaker.
        """
        w = (wert or "").strip()
        if w not in liste:
            raise BeamerFehler(
                "%s: %r ist kein zulaessiger Wert. Erlaubt sind: %s"
                % (was, w, ", ".join(liste)))
        return w

    def aus(self):
        return self._ok("POWER off", "Ausschalten")

    def ip_steuerung(self):
        return self.befehl("GET_IPCONTROL_STATE").strip().upper() == "ON"

    def aktuelle_app(self):
        """Laufende Anwendung oder None, wenn das Geraet aus ist.

        Ein ausgeschaltetes Geraet antwortet leer, obwohl es noch am Netz
        haengt - daran laesst sich der Betriebszustand erkennen.
        """
        antwort = self.befehl("CURRENT_APP").strip()
        if not antwort:
            return None
        for teil in antwort.split():
            if teil.upper().startswith("APP:"):
                return teil.split(":", 1)[1]
        return antwort

    def status(self):
        try:
            return "an" if self.aktuelle_app() is not None else "aus"
        except BeamerFehler:
            return "unbekannt"

    def taste(self, name):
        return self._ok("KEY_ACTION %s" % name, "Tastenbefehl")

    def eingang(self, name):
        return self._ok("INPUT_SELECT %s"
                        % self._aus_liste(name, self.EINGAENGE, "Eingangswahl"),
                        "Eingangswahl")

    # ---- ab 1.3.0 ----

    def lautstaerke(self):
        """Aktuelle Lautstaerke 0..100.

        Antwortform an der Vorlage gemessen: VOL:<zahl>, nichts anderes.
        Ein anderer Text ist ein Fehler, kein Wert - sonst stuende in Loxone
        eine 0, wo in Wahrheit niemand geantwortet hat.
        """
        antwort = self.befehl("CURRENT_VOL").strip()
        treffer = re.match(r"^VOL:(\d+)$", antwort)
        if not treffer:
            raise BeamerFehler("Lautstaerke: unerwartete Antwort %r "
                               "(erwartet wird VOL:<Zahl>)." % antwort)
        return int(treffer.group(1))

    def lautstaerke_setzen(self, wert):
        try:
            n = int(str(wert).strip())
        except (TypeError, ValueError):
            raise BeamerFehler("Lautstaerke: %r ist keine ganze Zahl." % wert) from None
        if not 0 <= n <= 100:
            raise BeamerFehler("Lautstaerke: %d liegt ausserhalb von 0 bis 100." % n)
        return self._ok("VOLUME_CONTROL %d" % n, "Lautstaerke")

    def stumm(self):
        """Ist der Ton stumm? Antwortform gemessen: MUTE:on oder MUTE:off."""
        antwort = self.befehl("MUTE_STATE").strip()
        treffer = re.match(r"^MUTE:(on|off)$", antwort)
        if not treffer:
            raise BeamerFehler("Stummschaltung: unerwartete Antwort %r "
                               "(erwartet wird MUTE:on oder MUTE:off)." % antwort)
        return treffer.group(1) == "on"

    def stumm_setzen(self, an):
        return self._ok("VOLUME_MUTE %s" % ("on" if an else "off"),
                        "Stummschaltung")

    def bild_stumm(self, modus):
        """Bild aus, Geraet bleibt an.

        Fuer einen Beamer der eigentlich interessante Befehl: Pause, Klingel
        oder Licht an, ohne die Lampe herunter- und wieder hochzufahren.
        screenmuteon = Bild und Ton stumm, videomuteon = nur Bild,
        allmuteoff = wieder normal.
        """
        return self._ok("SCREEN_MUTE %s"
                        % self._aus_liste(modus, self.BILDSTUMM, "Bild stumm"),
                        "Bild stumm")

    def energiesparen(self, stufe):
        return self._ok("ENERGY_SAVING %s"
                        % self._aus_liste(stufe, self.ENERGIESPAREN, "Energiesparen"),
                        "Energiesparen")

    def bildmodus(self, modus):
        return self._ok("PICTURE_MODE %s"
                        % self._aus_liste(modus, self.BILDMODI, "Bildmodus"),
                        "Bildmodus")

    def app_starten(self, name):
        """Eine Anwendung starten.

        Hier gibt es KEINE Wortliste: die Vorlage kennt zwar eine Aufzaehlung
        gaengiger Namen, laesst aber ausdruecklich auch beliebige zu - welche
        Anwendungen ein Geraet hat, weiss nur das Geraet. Geprueft wird
        deshalb nur die Form.
        """
        w = (name or "").strip()
        if not re.match(r"^[A-Za-z0-9._-]{1,64}$", w):
            raise BeamerFehler(
                "Anwendungsname: %r enthaelt Zeichen, die dort nicht "
                "vorkommen (erlaubt sind Buchstaben, Ziffern, Punkt, "
                "Bindestrich und Unterstrich)." % w)
        return self._ok("APP_LAUNCH %s" % w, "Anwendung starten")

    def mac_lesen(self, art="wired"):
        """Die MAC-Adresse AM GERAET auslesen.

        Damit laesst sich die eingetragene MAC gegenpruefen. Bisher merkte
        niemand, wenn sie falsch war: Wake-on-LAN verpufft dann lautlos, und
        in Loxone sieht es aus, als ginge der Beamer nicht an.
        """
        return self.befehl("GET_MACADDRESS %s"
                           % self._aus_liste(art, self.MAC_ARTEN,
                                             "MAC-Abfrage")).strip()


# --------------------------------------------------------------------------
# Selbsttest
# --------------------------------------------------------------------------

# ERZEUGT MIT DER ORIGINALFASSUNG, nicht mit diesem Nachbau.
#
#   npm install lgtv-ip-control          (gemessen an 4.4.0)
#   new LGEncryption('ABCD1234'), generateRandomIv festgenagelt auf
#   000102030405060708090a0b0c0d0e0f, dann encode() je Befehl.
#
# Die ersten sechs Zeilen sind seit 1.0.1 unveraendert und haben sich bei der
# Neuerzeugung am 23.08.2026 zeichengleich reproduziert - das ist zugleich die
# Gegenprobe, dass der Erzeuger richtig angesetzt war. Die uebrigen kamen mit
# 1.3.0 dazu.
#
# Weicht hier etwas ab, stimmt die Rechnung nicht mehr mit der Vorlage
# ueberein, und kein Geraet wird die Befehle annehmen.
_PRUEFWERTE = {
    "schluessel": "9396e78f24ec53e27f03faf1b0ca7ce3",
    "POWER off":
        "802e522521b20b0a74bda0aeee95a70d"
        "dcc293b74d54be84eddba402713e11fe",
    "GET_IPCONTROL_STATE":
        "802e522521b20b0a74bda0aeee95a70d"
        "5f343e10e283bb9b96743442dd844ef5fe749456b6221efb42e2d10e43d54109",
    "CURRENT_APP":
        "802e522521b20b0a74bda0aeee95a70d"
        "ac771fc848c99430b70b085ac58f7fde",
    "KEY_ACTION menu":
        "802e522521b20b0a74bda0aeee95a70d"
        "641f3a4c402a7846d6556364d1e95de4b1a3148a9b21096a52fc63031cc7276d",
    "0123456789abcde":
        "802e522521b20b0a74bda0aeee95a70d"
        "b02be553e033754bbb101d924dd17878176d09cc40a34187a1f886c56b1df4da",
    "INPUT_SELECT hdmi1":
        "802e522521b20b0a74bda0aeee95a70d"
        "ac5abfe6bb0b1d4d711a4bf91692861161d91ca9919963dfc9a1b9048a785ee6",
    "CURRENT_VOL":
        "802e522521b20b0a74bda0aeee95a70d"
        "c5b60fc11b710be4f0b9cc3b3be901c8",
    "VOLUME_CONTROL 25":
        "802e522521b20b0a74bda0aeee95a70d"
        "50e93893091a2571d610c10c5f39647682c488f7c75f35054810424ca5397967",
    "VOLUME_MUTE on":
        "802e522521b20b0a74bda0aeee95a70d"
        "769684af313a1f76f72d71ab29382792",
    "VOLUME_MUTE off":
        "802e522521b20b0a74bda0aeee95a70d"
        "1466e63908478a0f3793e489bcf8ecccf5458749a64fc269b74c53095a48f538",
    "MUTE_STATE":
        "802e522521b20b0a74bda0aeee95a70d"
        "08c1bbddf3a8fbed821eb794cc26704a",
    "SCREEN_MUTE screenmuteon":
        "802e522521b20b0a74bda0aeee95a70d"
        "fed3ca1ccad526a0df3380b719e0ea509c60a4ba69bb0e99d62b19b53d724149",
    "SCREEN_MUTE videomuteon":
        "802e522521b20b0a74bda0aeee95a70d"
        "430661fe8d9e10d0cb93f28401cc3f20fd96996fbb8b0eb06861bf6dcfa9f7c8",
    "SCREEN_MUTE allmuteoff":
        "802e522521b20b0a74bda0aeee95a70d"
        "29ca97c3731465f53fa2c4eff905d9b5f78fe305c2520e7111b478629e47cfff",
    "ENERGY_SAVING screenoff":
        "802e522521b20b0a74bda0aeee95a70d"
        "593c455baf20d451d57bcee07345108385a0babd9a72789626ae2fd1a549e936",
    "PICTURE_MODE filmMaker":
        "802e522521b20b0a74bda0aeee95a70d"
        "c674c8ec29011e04377f3908502a60c1160d354d3db83b47bd4749a3b38e1017",
    "PICTURE_MODE cinema":
        "802e522521b20b0a74bda0aeee95a70d"
        "ad71242ebdc7bb1471e78adb2565176bb7f526dee4e517a44119f517fc948a7f",
    "APP_LAUNCH netflix":
        "802e522521b20b0a74bda0aeee95a70d"
        "ac68c4b19a4de6c04a7e8316e574f8dae5825829ad439bbd3c2b7e3c668a903f",
    "GET_MACADDRESS wired":
        "802e522521b20b0a74bda0aeee95a70d"
        "c9de12d21c1f35913d2313e9a60bdd503e809e41a5dcf11794a6a8dbadbae4bc",
}


def _abweisungen(geraet):
    """Gegenprobe: unzulaessige Werte muessen ABGEWIESEN werden.

    Ohne diese Richtung prueft der Selbsttest nur, dass richtige Werte
    durchgehen - und eine Pruefung, die nie ausgeloest hat, ist keine.
    """
    faelle = (
        ("Eingang gruenerkaese", lambda: geraet.eingang("gruenerkaese")),
        ("Bildmodus FILMMAKER (falsch geschrieben)",
         lambda: geraet.bildmodus("FILMMAKER")),
        ("Bild stumm halbaus", lambda: geraet.bild_stumm("halbaus")),
        ("Energiesparen turbo", lambda: geraet.energiesparen("turbo")),
        ("Lautstaerke 101", lambda: geraet.lautstaerke_setzen(101)),
        ("Lautstaerke -1", lambda: geraet.lautstaerke_setzen(-1)),
        ("Lautstaerke laut", lambda: geraet.lautstaerke_setzen("laut")),
        ("MAC-Art powerline", lambda: geraet.mac_lesen("powerline")),
        ("Anwendung net flix", lambda: geraet.app_starten("net flix")),
    )
    fehler = 0
    for name, tun in faelle:
        try:
            tun()
        except BeamerFehler:
            print("ok     abgewiesen: %s" % name)
            continue
        except OSError:
            # Bis zum Netzaufruf duerfte es gar nicht kommen.
            pass
        print("FEHLER nicht abgewiesen: %s" % name)
        fehler += 1
    return fehler


def selbsttest():
    geraet = LgBeamer("127.0.0.1", "ABCD1234")
    iv = bytes.fromhex("000102030405060708090a0b0c0d0e0f")
    fehler = 0
    if geraet._schluessel.hex() != _PRUEFWERTE["schluessel"]:
        print("FEHLER Schluesselableitung: %s statt %s"
              % (geraet._schluessel.hex(), _PRUEFWERTE["schluessel"]))
        fehler += 1
    else:
        print("ok     Schluesselableitung (PBKDF2, 16384 Runden)")
    for befehl, erwartet in _PRUEFWERTE.items():
        if befehl == "schluessel":
            continue
        ist = geraet.kodieren(befehl, iv).hex()
        if ist != erwartet:
            print("FEHLER %r\n       ist      %s\n       erwartet %s"
                  % (befehl, ist, erwartet))
            fehler += 1
        else:
            print("ok     %r" % befehl)
    # Hin und zurueck: was verschluesselt wurde, muss wieder lesbar werden.
    # Eine echte Antwort endet auf Zeilenvorschub und muss lesbar sein.
    antwort = geraet.kodieren("OK" + ANTWORT_ENDE.join(["", ""]), iv)
    if geraet.dekodieren(antwort) != "OK":
        print("FEHLER Rundlauf: 'OK' kam nicht zurück")
        fehler += 1
    else:
        print("ok     Rundlauf einer Antwort")
    # Mit falschem Schluessel muss es einen Fehler geben, kein leeres Ergebnis.
    fremd = LgBeamer("127.0.0.1", "ZZZZ9999")
    try:
        fremd.dekodieren(antwort)
        print("FEHLER Falscher Keycode wurde nicht erkannt")
        fehler += 1
    except BeamerFehler:
        print("ok     Falscher Keycode wird erkannt")
    # Kleingeschriebener Keycode: abweisen, nicht umschreiben.
    try:
        LgBeamer("127.0.0.1", "abcd1234")
        print("FEHLER Kleingeschriebener Keycode wurde angenommen")
        fehler += 1
    except BeamerFehler:
        print("ok     Kleingeschriebener Keycode wird abgewiesen")
    # Ohne Keycode darf NICHT unverschluesselt gesendet werden.
    ohne = LgBeamer("127.0.0.1", "")
    try:
        ohne.befehl("CURRENT_APP")
        print("FEHLER Ohne Keycode wurde unverschluesselt gesendet")
        fehler += 1
    except BeamerFehler:
        print("ok     Ohne Keycode wird gemeldet statt unverschluesselt gesendet")

    print()
    fehler += _abweisungen(geraet)

    if fehler:
        print("\n%d Abweichung(en) - der Nachbau stimmt NICHT mit der Vorlage ueberein." % fehler)
        return 1
    print("\nAlle %d Werte stimmen mit der Originalfassung ueberein."
          % (len(_PRUEFWERTE) - 1))
    return 0


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "--selbsttest":
        sys.exit(selbsttest())
    if len(sys.argv) > 1 and sys.argv[1] == "--woerter":
        # Die zulaessigen Werte, damit die Oberflaeche sie anzeigen kann,
        # ohne sie ein zweites Mal zu fuehren.
        import json
        print(json.dumps({
            "eingang": list(LgBeamer.EINGAENGE),
            "bildmodus": list(LgBeamer.BILDMODI),
            "energiesparen": list(LgBeamer.ENERGIESPAREN),
            "bild_stumm": list(LgBeamer.BILDSTUMM),
        }, indent=1))
        sys.exit(0)
    print("Aufruf: lg_beamer.py --selbsttest | --woerter")
    sys.exit(2)
