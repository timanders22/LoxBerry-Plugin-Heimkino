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

fehlt=""
python3 -c "import cryptography" >/dev/null 2>&1 || fehlt="$fehlt python3-cryptography"
python3 -c "import paho.mqtt.client" >/dev/null 2>&1 || fehlt="$fehlt python3-paho-mqtt"
python3 -c "import requests" >/dev/null 2>&1 || fehlt="$fehlt python3-requests"

if [ -n "$fehlt" ]; then
    echo "<WARNING> Es fehlen Python-Module:$fehlt"
    echo "<WARNING> Nachinstallieren: sudo apt-get install -y$fehlt"
else
    echo "<OK> Alle Python-Module vorhanden."
fi

echo "<INFO> Naechster Schritt: Reiter Einstellungen - IP, MAC und Keycode des"
echo "<INFO> Beamers eintragen. Den Keycode erzeugt der Beamer selbst im"
echo "<INFO> versteckten Menue unter Netzwerk-IP-Steuerung."

exit 0
