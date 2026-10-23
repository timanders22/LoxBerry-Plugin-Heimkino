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
# ---- Wurzelblock (H1, seit 1.3.30; in preinstall, postinstall, preupgrade und postupgrade woertlich gleich) ----
# Bauform Matter2Lox 0.9.44 (Wurzelblock M3), Alexa NG 1.0.6 und Intercom 2.2.23. Ordner aus $3 mit Schranke
# ('' . .. und alles mit / oder ..); Wurzel aus $5, sonst LBHOMEDIR, sonst vom Ablageort aufwaerts gesucht
# (Regeln/06). Eine Wurzel gilt nur mit config/plugins, data/plugins UND config/system/general.json - ein Rest alter
# Prueflaeufe hat die dritte nie. Bis 1.3.29 setzte postinstall.sh $LBPLOG/$LBPCONFIG/$LBPBIN/$LBPDATA ohne
# Rueckfall (mit leerer Umgebung: mkdir -p /heimkino), preupgrade und postupgrade nahmen BASE=${5:-$LBHOMEDIR}
# ohne Pruefung (Pruefbericht H1, mit env -i gemessen).
# hk_wurzel setzt PFOLDER und BASE; Rueckgabe 0 = erkannt, 1 = Ordnername unzulaessig, 2 = keine LoxBerry-Wurzel.
hk_ist_wurzel() {
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ] && [ -f "$1/config/system/general.json" ]
}
hk_wurzel_suchen() {
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if hk_ist_wurzel "$v"; then printf '%s\n' "$v"; return 0; fi
        v=$(dirname "$v"); i=$((i + 1))
    done
    return 1
}
hk_wurzel() {
    PFOLDER="${1:-heimkino}"
    case "$PFOLDER" in
        ''|.|..|*/*|*..*) return 1 ;;
    esac
    BASE=""
    for HK_K in "$2" "$LBHOMEDIR"; do
        if hk_ist_wurzel "$HK_K"; then BASE="$HK_K"; break; fi
    done
    [ -n "$BASE" ] || BASE=$(hk_wurzel_suchen)
    [ -n "$BASE" ] || return 2
    return 0
}
# ---- Ende Wurzelblock ----
hk_wurzel "$3" "$5"
case $? in
    1) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
    2) echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('${5:-$LBHOMEDIR}') - nichts beiseitegelegt."; exit 0 ;;
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
