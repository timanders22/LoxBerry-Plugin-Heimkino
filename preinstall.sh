#!/bin/bash
# Heimkino - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Neu in 1.3.15 (I1, Entscheidung 1 vom 29.09.2026), Bauform
# AudiConnect 0.9.22 / Abfahrts-Assistent 1.6.16. Der Installer ruft dieses
# Skript bei JEDEM Einbau auf, nach dem Aufraeumen der alten Fassung und VOR
# dem Kopieren von Konfiguration, Cron-Datei und Oberflaeche
# (sbin/plugininstall.pl: preupgrade :846, purge :874, preinstall :877 -
# Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich; Entscheidung 8, Frage 17). Dann tut es nichts:
# Zweitschriften und Sicherung braucht postinstall.sh bzw. postupgrade.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Liegengebliebene Zweitschriften
# (config/plugins/<ordner>.backup.heimkino.cfg und .backup.xbox_auth.json)
# und eine Upgrade-Sicherung (data/plugins/<ordner>.upgrade_sicherung) einer
# frueheren Installation gehen nach <name>.alt, der Merker .lief_vorher wird
# entfernt, gemeldet mit genau einer <WARNING>. Bis 1.3.14 spielte
# postinstall.sh sie ungefragt zurueck - Aktionstoken, Beamer-Keycode und die
# Microsoft-Anmeldung einer frueheren Installation, und die Meldung sprach
# von einer Aktualisierung (in WSL gemessen, Installer-Pruefer Faelle N2-N4).
# Die Bibliothek liest .alt nie; die Deinstallation raeumt es ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-heimkino}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Wurzelsuche wie in den uebrigen Hakenskripten: ohne config/plugins,
# data/plugins UND config/system/general.json wird nichts angefasst.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.heimkino.cfg" \
            "$BASE/config/plugins/$PFOLDER.backup.xbox_auth.json" \
            "$BASE/data/plugins/$PFOLDER.upgrade_sicherung"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
# Die beiseitegelegten Staende tragen Aktionstoken, Keycode und die
# Microsoft-Anmeldung: Rechte eng, wie am Original.
for A in "$BASE/config/plugins/$PFOLDER.backup.heimkino.cfg.alt" \
         "$BASE/config/plugins/$PFOLDER.backup.xbox_auth.json.alt"; do
    [ -f "$A" ] && [ ! -L "$A" ] && chmod 600 "$A" 2>/dev/null
done
S="$BASE/data/plugins/$PFOLDER.upgrade_sicherung.alt"
if [ -d "$S" ] && [ ! -L "$S" ]; then
    chmod 700 "$S" 2>/dev/null
    for A in "$S"/*; do
        [ -f "$A" ] && [ ! -L "$A" ] && chmod 600 "$A" 2>/dev/null
    done
fi
MERKER="$BASE/data/plugins/$PFOLDER.lief_vorher"
if [ -e "$MERKER" ]; then
    rm -f "$MERKER" && BEISEITE="$BEISEITE (Startmerker $MERKER entfernt)"
fi
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Einstellungen und Xbox-Anmeldung einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$T"
fi
exit 0
