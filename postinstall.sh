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

exit 0
