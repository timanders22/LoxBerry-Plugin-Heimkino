#!/bin/sh
PCONFIG=$LBPCONFIG/$3
if [ -f /tmp/heimkino.cfg.sicherung ]; then
    cp -a /tmp/heimkino.cfg.sicherung "$PCONFIG/heimkino.cfg"
    rm -f /tmp/heimkino.cfg.sicherung
    echo "<OK> Bestehende Einstellungen uebernommen."
fi
if [ -f /tmp/heimkino_xbox_auth.sicherung ]; then
    cp -a /tmp/heimkino_xbox_auth.sicherung "$PCONFIG/xbox_auth.json"
    rm -f /tmp/heimkino_xbox_auth.sicherung
    echo "<OK> Bestehende Xbox-Anmeldung uebernommen."
fi
chmod 640 "$PCONFIG/heimkino.cfg" 2>/dev/null
chmod 600 "$PCONFIG/xbox_auth.json" 2>/dev/null
chown loxberry:loxberry "$PCONFIG"/* 2>/dev/null
chmod 755 "$LBPBIN/$3"/*.py 2>/dev/null
exit 0
