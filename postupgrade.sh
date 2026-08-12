#!/bin/sh
# Heimkino - postupgrade (laeuft als Benutzer loxberry)
PDIR=$3
BASE="${5:-$LBHOMEDIR}"
PCONFIG="$LBPCONFIG/$PDIR"
[ -d "$PCONFIG" ] || PCONFIG="$BASE/config/plugins/$PDIR"
SICHER="$BASE/data/plugins/$PDIR/upgrade_sicherung"

mkdir -p "$PCONFIG" 2>/dev/null

# Wer von 1.1.1 oder frueher kommt, hat die Sicherung noch in der Ramdisk -
# damit dieses eine Update nichts verliert, wird auch dort nachgesehen.
if [ ! -f "$SICHER/heimkino.cfg" ] && [ -f /tmp/heimkino.cfg.sicherung ]; then
    mkdir -p "$SICHER" 2>/dev/null
    cp -a /tmp/heimkino.cfg.sicherung "$SICHER/heimkino.cfg" 2>/dev/null
    echo "<INFO> Sicherung am alten Ort (/tmp) gefunden und uebernommen."
fi
if [ ! -f "$SICHER/xbox_auth.json" ] && [ -f /tmp/heimkino_xbox_auth.sicherung ]; then
    mkdir -p "$SICHER" 2>/dev/null
    cp -a /tmp/heimkino_xbox_auth.sicherung "$SICHER/xbox_auth.json" 2>/dev/null
fi

if [ -f "$SICHER/heimkino.cfg" ]; then
    cp -a "$SICHER/heimkino.cfg" "$PCONFIG/heimkino.cfg"
    echo "<OK> Bestehende Einstellungen uebernommen."
else
    # Kein blinder Alarm: der Installer loescht beim Update AUCH
    # data/plugins/<ordner> und damit die Sicherung, die preupgrade.sh
    # dorthin geschrieben hat - diese Kette kann hier gar nichts finden.
    # Gerettet wird aus der Zweitschrift neben dem Ordner, und das tut
    # postinstall.sh, das VOR postupgrade laeuft. Also erst nachsehen,
    # wie es wirklich steht; eine Warnung bei heiler Konfiguration
    # erschreckt ohne Grund und entwertet die echte.
    NETZ_PRUEF="${5:-$LBHOMEDIR}/config/plugins/${3:-heimkino}/heimkino.cfg"
    if [ -s "$NETZ_PRUEF" ]; then
        echo "<OK> Die Einstellungen sind vorhanden (aus der Zweitschrift)."
    else
    echo "<WARNING> Keine gesicherten Einstellungen gefunden - IP, MAC und"
    echo "<WARNING> Keycode des Beamers muessen neu eingetragen werden."
    fi
fi
if [ -f "$SICHER/xbox_auth.json" ]; then
    cp -a "$SICHER/xbox_auth.json" "$PCONFIG/xbox_auth.json"
    echo "<OK> Bestehende Xbox-Anmeldung uebernommen."
fi

chmod 640 "$PCONFIG/heimkino.cfg" 2>/dev/null
chmod 600 "$PCONFIG/xbox_auth.json" 2>/dev/null
chown loxberry:loxberry "$PCONFIG"/* 2>/dev/null
chmod 755 "$LBPBIN/$PDIR"/*.py 2>/dev/null

# Aufraeumen - an beiden Orten.
rm -rf "$SICHER" 2>/dev/null
rm -f /tmp/heimkino.cfg.sicherung /tmp/heimkino_xbox_auth.sicherung 2>/dev/null
exit 0
