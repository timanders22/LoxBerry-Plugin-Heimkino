#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Heimkino - Geraetesperre ueber Prozessgrenzen hinweg

WOZU
Ein LG-Geraet nimmt auf Port 9761 genau EINE Verbindung zur Zeit an. Am
Beamer arbeiten aber drei voneinander unabhaengige Prozesse:

  hk_service.py   alle 60 s von selbst
  hk_cmd.py       je Aufruf frisch gestartet, aus dem Aktionsendpunkt
                  (Loxone) oder aus dem Reiter Test
  hk_test.php     beim Klick auf "Beamer erreichbar?"

Die vorhandene Sperre in hk_common.pid_belegen() beantwortet eine andere
Frage - naemlich, ob schon ein DIENST laeuft. Ueber hk_cmd sagt sie nichts.

Treffen zwei zusammen, gibt es zwei schlechte Ausgaenge:

  - Der Befehl verliert. Der Endpunkt antwortet HTTP 500, und ein virtueller
    Ausgang in Loxone wertet die Antwort NICHT aus: der Beamer bleibt an, und
    niemand erfaehrt warum.
  - Die Abfrage verliert. Der Dienst meldet dann "unbekannt" und setzt
    last_error - eine Stoerungsmeldung fuer eine Stoerung, die es nicht gibt.

DESHALB EINE DATEISPERRE, die beide Seiten nehmen.

Drei Festlegungen, die den Ausschlag geben:

1. Die Sperre liegt an EINER Stelle - in LgBeamer.befehl() und in
   hk_common.erreichbarkeit(). Wer sie von Hand um jeden Aufruf legen
   muesste, vergisst sie beim naechsten Befehl.
2. Sie ist im selben Prozess WIEDEREINTRITTSFAEHIG. Der Dienst legt sie um
   einen ganzen Abfragedurchgang, und darin ruft er mehrere Befehle. Ohne
   Wiedereintritt spraenge er sich selbst in die Falle.
3. Der Befehl gewinnt, die Abfrage weicht. hk_cmd wartet begrenzt; der
   Dienst nimmt sie ohne Warten und ueberspringt den Durchgang, wenn sie
   besetzt ist. Ein uebersprungener Durchgang ist KEIN Fehler und darf
   weder den Zustand auf "unbekannt" setzen noch last_error fuellen - sonst
   waere eine stille Falschaussage nur durch eine laute ersetzt.

Ohne Sperrpfad verhaelt sich alles wie vorher. Das haelt lg_beamer.py
eigenstaendig pruefbar.
"""

import os
import sys
import time


class SperreBesetzt(Exception):
    """Ein anderer Prozess spricht gerade mit dem Geraet."""


# --------------------------------------------------------------------------
# Unterbau. Auf dem LoxBerry ist es fcntl; msvcrt gibt es nur, damit sich die
# Sperre auf dem Entwicklungsrechner unter Windows ueberhaupt MESSEN laesst.
# Eine Sperre, die man nie hat greifen sehen, ist eine Behauptung.
# --------------------------------------------------------------------------

try:
    import fcntl as _fcntl
except ImportError:
    _fcntl = None

try:
    import msvcrt as _msvcrt
except ImportError:
    _msvcrt = None


def unterbau():
    """Welcher Unterbau traegt? "fcntl", "msvcrt" oder "" (keiner)."""
    if _fcntl is not None:
        return "fcntl"
    if _msvcrt is not None:
        return "msvcrt"
    return ""


def wirksam():
    return unterbau() != ""


def _versuchen(datei):
    """Einmal ohne Warten zugreifen. True bei Erfolg."""
    if _fcntl is not None:
        try:
            _fcntl.flock(datei.fileno(), _fcntl.LOCK_EX | _fcntl.LOCK_NB)
            return True
        except OSError:
            return False
    if _msvcrt is not None:
        try:
            datei.seek(0)
            _msvcrt.locking(datei.fileno(), _msvcrt.LK_NBLCK, 1)
            return True
        except OSError:
            return False
    # Kein Unterbau: es wird nicht gesperrt, und das steht so in der
    # Selbstpruefung. Lieber ohne Sperre weiterarbeiten als gar nicht -
    # aber niemandem erzaehlen, es sei gesperrt.
    return True


def _freigeben(datei):
    if _fcntl is not None:
        try:
            _fcntl.flock(datei.fileno(), _fcntl.LOCK_UN)
        except OSError:
            pass
    elif _msvcrt is not None:
        try:
            datei.seek(0)
            _msvcrt.locking(datei.fileno(), _msvcrt.LK_UNLCK, 1)
        except OSError:
            pass


class Sperre:
    """Kontextverwalter um einen Wortwechsel mit dem Geraet.

        with Sperre(pfad, warten=10):
            ...

    warten=0 heisst: sofort aufgeben, wenn besetzt (SperreBesetzt).
    pfad=None heisst: gar nicht sperren - dann verhaelt sich alles wie vor
    1.3.0. Das braucht der Selbsttest von lg_beamer.py, der ohne LoxBerry
    laufen koennen muss.
    """

    # Je Prozess und Pfad: [Datei, Zaehler]. Der Zaehler macht die Sperre
    # wiedereintrittsfaehig - siehe Festlegung 2 im Kopf dieser Datei.
    _offen = {}

    def __init__(self, pfad, warten=10.0):
        self.pfad = pfad or None
        self.warten = float(warten)
        self._genommen = False

    def __enter__(self):
        if self.pfad is None:
            return self
        schluessel = os.path.abspath(self.pfad)
        eintrag = Sperre._offen.get(schluessel)
        if eintrag is not None:
            # Schon von diesem Prozess gehalten: nur mitzaehlen.
            eintrag[1] += 1
            self._genommen = True
            return self

        ordner = os.path.dirname(schluessel)
        if ordner:
            try:
                os.makedirs(ordner, exist_ok=True)
            except OSError:
                pass
        try:
            datei = open(schluessel, "a+", encoding="utf-8")
        except OSError:
            # Kein Sperrort verfuegbar. Weiterarbeiten, aber nicht behaupten,
            # es sei gesperrt.
            return self

        ende = time.time() + max(0.0, self.warten)
        while True:
            if _versuchen(datei):
                Sperre._offen[schluessel] = [datei, 1]
                self._genommen = True
                return self
            if time.time() >= ende:
                datei.close()
                raise SperreBesetzt(
                    "Ein anderer Vorgang spricht gerade mit dem Geraet "
                    "(Sperre %s, %.0f s gewartet). Das Geraet nimmt nur eine "
                    "Verbindung zur Zeit an." % (schluessel, self.warten))
            time.sleep(0.05)

    def __exit__(self, art, wert, spur):
        if not self._genommen or self.pfad is None:
            return False
        schluessel = os.path.abspath(self.pfad)
        eintrag = Sperre._offen.get(schluessel)
        if eintrag is None:
            return False
        eintrag[1] -= 1
        if eintrag[1] <= 0:
            _freigeben(eintrag[0])
            try:
                eintrag[0].close()
            except OSError:
                pass
            Sperre._offen.pop(schluessel, None)
        return False


# --------------------------------------------------------------------------
# Selbsttest
# --------------------------------------------------------------------------

def _halten(pfad, sekunden):
    """Hilfsbetrieb fuer den Selbsttest: die Sperre halten und es sagen."""
    with Sperre(pfad, warten=5):
        sys.stdout.write("gehalten\n")
        sys.stdout.flush()
        time.sleep(float(sekunden))
    return 0


def selbsttest():
    import subprocess
    import tempfile

    fehler = 0

    def sage(name, ok, zusatz=""):
        nonlocal fehler
        print("  %-52s %s %s" % (name, "ok" if ok else "FEHLER", zusatz))
        if not ok:
            fehler += 1

    print("Unterbau: %s" % (unterbau() or "KEINER"))
    if not wirksam():
        print("\nAuf diesem System gibt es weder fcntl noch msvcrt. Die Sperre")
        print("wirkt dann NICHT, und dieser Selbsttest kann sie auch nicht")
        print("messen. Das ist ein Befund, kein Haken.")
        return 1

    ordner = tempfile.mkdtemp(prefix="hk_sperre_")
    pfad = os.path.join(ordner, "beamer.lock")

    print("\n1. Ohne Pfad wird gar nicht gesperrt (Selbsttest von lg_beamer)")
    with Sperre(None, warten=0):
        sage("kein Pfad, kein Sperren", True)

    print("\n2. Im selben Prozess wiedereintrittsfaehig")
    try:
        with Sperre(pfad, warten=0):
            with Sperre(pfad, warten=0):
                with Sperre(pfad, warten=0):
                    sage("dreifach geschachtelt ohne Selbstblockade", True)
        sage("nach dem Verlassen wieder frei", not Sperre._offen)
    except SperreBesetzt:
        sage("dreifach geschachtelt ohne Selbstblockade", False,
             "hat sich selbst blockiert")

    print("\n3. Gegen einen ZWEITEN Prozess - der eigentliche Fall")
    kind = subprocess.Popen(
        [sys.executable, os.path.abspath(__file__), "--halten", pfad, "3"],
        stdout=subprocess.PIPE, text=True)
    try:
        zeile = kind.stdout.readline().strip()
        sage("Kindprozess hat die Sperre", zeile == "gehalten", zeile)

        anfang = time.time()
        try:
            with Sperre(pfad, warten=0):
                sage("besetzte Sperre wird ohne Warten abgewiesen", False,
                     "sie wurde genommen, obwohl das Kind sie haelt")
        except SperreBesetzt:
            sage("besetzte Sperre wird ohne Warten abgewiesen",
                 time.time() - anfang < 0.5,
                 "nach %.2f s" % (time.time() - anfang))

        anfang = time.time()
        try:
            with Sperre(pfad, warten=1):
                sage("mit kurzer Frist ebenfalls abgewiesen", False)
        except SperreBesetzt:
            dauer = time.time() - anfang
            sage("mit kurzer Frist ebenfalls abgewiesen", 0.9 <= dauer <= 2.0,
                 "nach %.2f s gewartet" % dauer)

        anfang = time.time()
        try:
            with Sperre(pfad, warten=10):
                dauer = time.time() - anfang
                sage("mit langer Frist wird gewartet und dann genommen",
                     dauer > 0.5, "nach %.2f s" % dauer)
        except SperreBesetzt:
            sage("mit langer Frist wird gewartet und dann genommen", False,
                 "wurde abgewiesen, obwohl das Kind laengst fertig war")
    finally:
        kind.wait(timeout=15)
        kind.stdout.close()

    print("\n4. Nach allem ist nichts liegengeblieben")
    sage("keine offene Sperre im Prozess", not Sperre._offen)
    with Sperre(pfad, warten=0):
        pass
    sage("die Sperre ist wieder frei nehmbar", True)

    try:
        os.unlink(pfad)
        os.rmdir(ordner)
    except OSError:
        pass

    if fehler:
        print("\n%d Abweichung(en)." % fehler)
        return 1
    print("\nDie Sperre greift - gemessen gegen einen zweiten Prozess.")
    return 0


if __name__ == "__main__":
    if len(sys.argv) > 3 and sys.argv[1] == "--halten":
        sys.exit(_halten(sys.argv[2], sys.argv[3]))
    if len(sys.argv) > 1 and sys.argv[1] == "--unterbau":
        # Schnelle Auskunft fuer die Pruefzeile im Reiter Test. Der volle
        # Selbsttest startet einen zweiten Prozess und dauert Sekunden - das
        # gehoert nicht in jeden Seitenaufbau.
        print(unterbau())
        sys.exit(0)
    if len(sys.argv) > 1 and sys.argv[1] == "--selbsttest":
        sys.exit(selbsttest())
    print("Aufruf: hk_sperre.py --selbsttest | --unterbau")
    sys.exit(2)
