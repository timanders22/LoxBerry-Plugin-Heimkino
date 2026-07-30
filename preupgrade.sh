#!/bin/sh
PCONFIG=$LBPCONFIG/$3
# Die Konfiguration ueberdauert die Aktualisierung.
if [ -f "$PCONFIG/heimkino.cfg" ]; then
    cp -a "$PCONFIG/heimkino.cfg" /tmp/heimkino.cfg.sicherung 2>/dev/null
fi
if [ -f "$PCONFIG/xbox_auth.json" ]; then
    cp -a "$PCONFIG/xbox_auth.json" /tmp/heimkino_xbox_auth.sicherung 2>/dev/null
fi
exit 0
