#!/bin/sh

COMMAND=$0
PTEMPDIR=$1
PSHNAME=$2
PDIR=$3
PVERSION=$4

PLOG=$LBPLOG/$PDIR
PCONFIG=$LBPCONFIG/$PDIR

mkdir -p $PLOG
touch $PLOG/$PSHNAME.log
chown loxberry:loxberry $PLOG/$PSHNAME.log

chmod 755 "$LBPBIN/$PDIR"/*.py 2>/dev/null
# dienst.sh wird vom Daemon, von der Oberflaeche UND vom minuetlichen
# Waechter aufgerufen. Ohne Ausfuehrungsrecht schluege der Waechter jede
# Minute lautlos fehl.
chmod 755 "$LBPBIN/$PDIR/dienst.sh" 2>/dev/null
# Die beiden Datendateien sind nur zum Lesen da - sie tragen die Vorgaben
# und die MQTT-Themen fuer BEIDE Sprachen, Python wie PHP.
chmod 644 "$LBPBIN/$PDIR/hk_vorgaben.json" "$LBPBIN/$PDIR/hk_themen.json" 2>/dev/null

# data/plugins/<ordner> traegt seit 1.2.12 die PID-Datei und den Sollmerker
# des Waechters. Bis 1.2.11 lag die PID-Datei unter log/plugins - also auf
# einer Ramdisk, die bei jedem Neustart leer ist.
mkdir -p "$LBPDATA/$PDIR" 2>/dev/null
chown loxberry:loxberry "$LBPDATA/$PDIR" 2>/dev/null

# Die Konfiguration enthaelt nach dem Einrichten den Keycode des Beamers.
chmod 640 "$PCONFIG/heimkino.cfg" 2>/dev/null
chown loxberry:loxberry "$PCONFIG/heimkino.cfg" 2>/dev/null

# NACHKONTROLLE, keine Installationsanweisung.
#
# Installiert werden die drei Pakete von LoxBerry selbst - sie stehen
# zeilenweise in dpkg/apt (python3-cryptography, python3-paho-mqtt,
# python3-requests), und das ist auch der richtige Ort dafuer. Hier wird nur
# NACHGESEHEN, ob es geklappt hat: eine fehlende Paketquelle oder ein
# abgebrochener apt-Lauf faellt sonst erst Wochen spaeter auf, wenn der
# Beamer nicht schaltet.
#
# Die Formulierung war bis 1.1.1 missverstaendlich - sie las sich, als
# muesste der Anwender die Pakete von Hand nachziehen. Das ist nicht der
# Normalfall, sondern die Ausnahme.
fehlt=""
python3 -c "import cryptography" >/dev/null 2>&1 || fehlt="$fehlt python3-cryptography"
python3 -c "import paho.mqtt.client" >/dev/null 2>&1 || fehlt="$fehlt python3-paho-mqtt"
python3 -c "import requests" >/dev/null 2>&1 || fehlt="$fehlt python3-requests"

if [ -n "$fehlt" ]; then
    echo "<WARNING> Nachkontrolle: diese Module sind nicht ladbar:$fehlt"
    echo "<WARNING> Sie stehen in dpkg/apt und haetten von LoxBerry mitinstalliert"
    echo "<WARNING> werden sollen. Offenbar ist der apt-Lauf gescheitert - meist"
    echo "<WARNING> ein veralteter Paketindex. Abhilfe am LoxBerry (SSH):"
    echo "<WARNING>   sudo apt-get update && sudo apt-get install -y$fehlt"
    echo "<INFO> Ohne cryptography schaltet der Beamer nicht, ohne paho-mqtt"
    echo "<INFO> meldet das Plugin nichts, ohne requests bleibt die Xbox aussen vor."
else
    echo "<OK> Nachkontrolle: alle drei Python-Module vorhanden."
fi

echo "<INFO> Naechster Schritt: Reiter Einstellungen - IP, MAC und Keycode des"
echo "<INFO> Beamers eintragen. Den Keycode erzeugt der Beamer selbst im"
echo "<INFO> versteckten Menue unter Netzwerk-IP-Steuerung."


# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-heimkino}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
# Vier Lagen werden unterschieden, nicht zwei:
#   fehlt / leer                    -> verloren
#   zeichengenau die Vorgabe        -> verloren (der Kopierschritt des
#                                      Installers sieht genau so aus)
#   vorhanden, aber OHNE Inhalt     -> verloren UND beschaedigt: der Stand
#                                      wird als <datei>.kaputt (0600)
#                                      beiseitegelegt, nicht weggeworfen
#   vorhanden und mit Inhalt        -> unberuehrt, immer
#
# Geheilt wird nur aus einer Zweitschrift, die SELBST Inhalt traegt. Eine
# Sicherung, die echte Einstellungen ersetzt, waere schlimmer als gar keine.
# ---------- Was "Inhalt" heisst ----------
#
# Wortgleich zu preupgrade.sh. Eine Groessenpruefung ("[ ! -s ]")
# beantwortet nur, ob ueberhaupt etwas dasteht; eine ABGESCHNITTENE Datei
# besteht sie und gilt damit als "vorhanden" - die heile Zweitschrift wird
# dann nicht geholt. Gemessen am 18.09.2026
# (Bestand-2026-09-18/klasse-C/Ergebnis.md, Abschnitt 3a Nr. 3; in WSL
# nachgestellt in Pruefung-Heimkino-1.3.13, Faelle C5 und C6).
#
# Heil heisst: die Datei ist LESBAR und traegt ein GEHEIMNIS - einen Wert,
# den nur der Anwender liefern kann. Ist python3 nicht aufrufbar, gilt eine
# JSON-Datei als heil; dann verhaelt sich das Skript wie bis 1.3.12.
hk_inhalt() {   # $1 Datei, $2 Art (cfg|json)
    [ -s "$1" ] || return 1
    case "$2" in
        cfg)
            grep -q '^[[:space:]]*\[heimkino\][[:space:]]*$' "$1" 2>/dev/null || return 1
            for hk_f in aktionstoken keycode ip mac geraete_id; do
                hk_w=$(sed -n "s/^[[:space:]]*$hk_f[[:space:]]*=[[:space:]]*//p" "$1" 2>/dev/null | head -1)
                hk_w=$(printf '%s' "$hk_w" | tr -d '[:space:]')
                [ -n "$hk_w" ] && return 0
            done
            return 1
            ;;
        json)
            command -v python3 >/dev/null 2>&1 || return 0
            python3 -c 'import json, sys
try:
    d = json.load(open(sys.argv[1], encoding="utf-8"))
except Exception:
    sys.exit(1)
if not isinstance(d, dict):
    sys.exit(1)
for k in ("refresh_token", "client_id"):
    if str(d.get(k, "")).strip():
        sys.exit(0)
sys.exit(1)' "$1" 2>/dev/null
            hk_rc=$?
            case $hk_rc in
                0) return 0 ;;
                1) return 1 ;;
                *) return 0 ;;
            esac
            ;;
    esac
    return 1
}

netz_zurueck() {   # $1 Datei, $2 Art (cfg|json), $3 Rechte, $4 Pruefsumme der Vorgabe
    datei=$1; art=$2; rechte=$3; soll=$4
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    [ -f "$zweit" ] || return 0
    hk_inhalt "$zweit" "$art" || return 0
    verloren=0
    kaputt=0
    if [ ! -f "$ziel" ] || [ ! -s "$ziel" ]; then
        verloren=1
    elif [ -n "$soll" ]; then
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    fi
    if [ "$verloren" = "0" ] && ! hk_inhalt "$ziel" "$art"; then
        verloren=1
        kaputt=1
    fi
    [ "$verloren" = "1" ] || return 0
    if [ "$kaputt" = "1" ]; then
        # Der verdraengte Stand geht nicht verloren, er wird nur
        # beiseitegelegt - mit denselben engen Rechten, denn er kann
        # Keycode, Aktionstoken oder die halbe Anmeldung enthalten.
        if mv "$ziel" "$ziel.kaputt" 2>/dev/null; then
            chmod 0600 "$ziel.kaputt" 2>/dev/null
            echo "<WARNING> $datei war unvollstaendig. Der Stand liegt jetzt als"
            echo "<WARNING> $ziel.kaputt daneben."
        fi
    fi
    if cp -p "$zweit" "$ziel" 2>/dev/null; then
        chmod "$rechte" "$ziel" 2>/dev/null
        echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
    else
        echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
        echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
    fi
}
# Die Pruefsumme ist die der MITGELIEFERTEN config/heimkino.cfg. Sie wird
# beim Anheben der Fassung nachgezogen, wenn sich die Vorgabedatei aendert.
netz_zurueck "heimkino.cfg" cfg 0640 \
    "279a0e0f89591b0823f655ac9cafcc366d177d056035cfa066edd163db4701d0"
# xbox_auth.json liefert das Archiv nie mit - es gibt also keine Vorgabe,
# mit der man vergleichen koennte. Der leere vierte Wert sagt das aus.
netz_zurueck "xbox_auth.json" json 0600 ""

exit 0
