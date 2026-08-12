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
netz_zurueck() {
    datei=$1; soll=$2
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    [ -f "$zweit" ] || return 0
    verloren=0
    if [ ! -f "$ziel" ] || [ ! -s "$ziel" ]; then
        verloren=1
    else
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    fi
    if [ "$verloren" = "1" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    fi
}
netz_zurueck "heimkino.cfg" "279a0e0f89591b0823f655ac9cafcc366d177d056035cfa066edd163db4701d0"
# xbox_auth.json: nicht mitgeliefert, also keine Vorgabe, mit der man
# vergleichen koennte. Zurueckgespielt wird deshalb genau dann, wenn die
# Datei fehlt oder leer ist - eine vorhandene Anmeldung wird nie ueberschrieben.
netz_xbox="$NETZ_CFG/xbox_auth.json"
netz_xbox_zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.xbox_auth.json"
if [ -f "$netz_xbox_zweit" ] && [ ! -s "$netz_xbox" ]; then
    if cp -p "$netz_xbox_zweit" "$netz_xbox" 2>/dev/null; then
        chmod 0600 "$netz_xbox" 2>/dev/null
        echo "<OK> Xbox-Anmeldung aus der Zweitschrift wiederhergestellt."
    else
        echo "<WARNING> xbox_auth.json liess sich nicht zurueckspielen."
        echo "<WARNING> Die Sicherung liegt unter $netz_xbox_zweit"
    fi
fi

exit 0
