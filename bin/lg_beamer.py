#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Heimkino - IP-Steuerung fuer LG-Geraete ab 2018 (Beamer und Fernseher)

Nachbau des Verfahrens aus WesSouza/lgtv-ip-control (MIT). Die Vorlage ist
JavaScript; hier steht dieselbe Rechnung in Python. Die Uebereinstimmung ist
nachgemessen: `--selbsttest` vergleicht mit Werten, die die Originalfassung
unter Node erzeugt hat.

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
"""

import errno
import hashlib
import os
import socket
import sys

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

    def __init__(self, ip, keycode="", port=PORT_VORGABE, zeitgrenze=5.0):
        self.ip = (ip or "").strip()
        self.keycode = (keycode or "").strip().upper()
        self.port = int(port or PORT_VORGABE)
        self.zeitgrenze = float(zeitgrenze or 5.0)
        self._schluessel = None
        if self.keycode:
            self._schluessel = self.schluessel_ableiten(self.keycode)

    # ---------------- Verschluesselung ----------------

    @staticmethod
    def schluessel_ableiten(keycode):
        code = keycode.strip().upper()
        if len(code) != 8 or not all(z in
                "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789" for z in code):
            raise BeamerFehler(
                "Der Keycode muss aus genau 8 Zeichen A-Z und 0-9 bestehen. "
                "Er wird am Gerät unter Netzwerk-IP-Steuerung erzeugt.")
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
        nachricht = befehl + ABSCHLUSS
        if ABSCHLUSS in befehl:
            raise BeamerFehler("Der Befehl darf keinen Wagenruecklauf enthalten.")
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
        anfrage = self.kodieren(text)
        try:
            with socket.create_connection((self.ip, self.port),
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

    def aus(self):
        antwort = self.befehl("POWER off")
        if antwort.strip() != "OK":
            raise BeamerFehler("Das Gerät hat nicht mit OK geantwortet, "
                               "sondern mit: %r" % antwort)
        return True

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
        antwort = self.befehl("KEY_ACTION %s" % name)
        if antwort.strip() != "OK":
            raise BeamerFehler("Tastenbefehl abgelehnt: %r" % antwort)
        return True

    def eingang(self, name):
        antwort = self.befehl("INPUT_SELECT %s" % name)
        if antwort.strip() != "OK":
            raise BeamerFehler("Eingangswahl abgelehnt: %r" % antwort)
        return True


# --------------------------------------------------------------------------
# Selbsttest
# --------------------------------------------------------------------------

# Erzeugt mit der Originalfassung (Node, lgtv-ip-control) fuer den Keycode
# ABCD1234 und den festen Startwert 000102...0f. Weicht der Nachbau ab,
# stimmt die Rechnung nicht mehr.
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
}


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
    if fehler:
        print("\n%d Abweichung(en) - der Nachbau stimmt NICHT mit der Vorlage ueberein." % fehler)
        return 1
    print("\nAlle Werte stimmen mit der Originalfassung ueberein.")
    return 0


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "--selbsttest":
        sys.exit(selbsttest())
    print("Aufruf: lg_beamer.py --selbsttest")
    sys.exit(2)
