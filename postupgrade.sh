#!/bin/sh
# Heimkino - postupgrade (laeuft als Benutzer loxberry)
PDIR=$3
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
LBPCONFIG="${LBPCONFIG:-$5/config/plugins}"
LBPBIN="${LBPBIN:-$5/bin/plugins}"
BASE="${5:-$LBHOMEDIR}"
PCONFIG="$LBPCONFIG/$PDIR"
[ -d "$PCONFIG" ] || PCONFIG="$BASE/config/plugins/$PDIR"
SICHER="$BASE/data/plugins/$PDIR.upgrade_sicherung"
PDATA="$BASE/data/plugins/$PDIR"
MARKE="$BASE/data/plugins/$PDIR.upgrade_laeuft"

# Die Marke aus preupgrade.sh faellt ueber einen trap, nicht am Dateiende.
#
# Dieses Skript ist das LETZTE Hakenskript dieser Linie - es gibt kein
# postroot.sh (Reihenfolge nach Regeln/06: preroot, preinstall, preupgrade,
# postinstall, postupgrade, postroot). Bleibt die Marke liegen, weist jeder
# Startweg den Dienst eine Stunde lang ab, ohne dass irgendwo stuende, warum.
# Ein trap traegt auch dann, wenn spaeter einmal ein frueher Ausstieg
# dazukommt (Regeln/06, Nachtrag 17.09.2026).
trap 'rm -f "$MARKE" 2>/dev/null' EXIT

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
chmod 755 "$LBPBIN/$PDIR/dienst.sh" 2>/dev/null
chmod 644 "$LBPBIN/$PDIR/hk_vorgaben.json" "$LBPBIN/$PDIR/hk_themen.json" 2>/dev/null

# Die PID-Datei liegt seit 1.2.12 unter data/plugins statt unter log/plugins.
# Eine liegengebliebene alte Datei wuerde die Oberflaeche einen Dienst
# anzeigen lassen, den es nicht mehr gibt.
rm -f "$BASE/log/plugins/$PDIR/hk_service.pid" 2>/dev/null

# Der Sollmerker sagt dem minuetlichen Waechter, dass der Dienst laufen soll.
# Wer von 1.2.11 kommt, hat ihn noch nicht - ohne ihn bliebe der Waechter
# nach dem Update fuer immer untaetig.
mkdir -p "$BASE/data/plugins/$PDIR" 2>/dev/null
touch "$BASE/data/plugins/$PDIR/soll_laufen" 2>/dev/null
chown loxberry:loxberry "$BASE/data/plugins/$PDIR/soll_laufen" 2>/dev/null

# Aufraeumen - an beiden Orten.
rm -rf "$SICHER" 2>/dev/null
rm -f /tmp/heimkino.cfg.sicherung /tmp/heimkino_xbox_auth.sicherung 2>/dev/null

# ==========================================================================
# Einen Dienst aus der Zeit VOR der Aktualisierung beenden
# ==========================================================================
#
# preupgrade.sh haelt den Dienst nicht an, und purge_installation loescht
# data/plugins/<ordner>/ samt PID-Datei. Ein Dienst, der die Aktualisierung
# ueberlebt hat, ist danach unsichtbar: er haelt seine Dateisperre auf einem
# geloeschten Inode, "dienst.sh status" meldet gestoppt, und der naechste
# Waechterlauf startet einen ZWEITEN. In WSL gemessen (18.09.2026,
# Pruefung-Heimkino-1.3.12, Fall D): 2 Prozesse. Der Beamer nimmt nur EINE
# Verbindung zur Zeit an (bin/hk_common.py, pid_belegen).
#
# Gesucht wird ARGUMENTWEISE ueber /proc, nie mit "pgrep -f": ein Treffer hat
# GENAU zwei Argumente - einen python-Interpreter und zeichengenau den
# eigenen Dienstpfad - und gehoert dem Dienstbenutzer. Ein Editor auf der
# Datei, ein "tail" auf einen Pfad mit diesem Namen und der Einmallauf
# "hk_service.py --themen" aus dem Reiter Test treffen damit nicht.
HK_SKRIPT="$LBPBIN/$PDIR/hk_service.py"
HK_UID=$(id -u loxberry 2>/dev/null)
case "$HK_UID" in ''|*[!0-9]*) HK_UID=$(id -u) ;; esac

hk_dienste_finden() {
    for hk_d in /proc/[0-9]*; do
        grep -qaF "hk_service.py" "$hk_d/cmdline" 2>/dev/null || continue
        [ "$(stat -c %u "$hk_d" 2>/dev/null)" = "$HK_UID" ] || continue
        hk_n=0
        hk_treffer=0
        while IFS= read -r hk_arg; do
            hk_n=$((hk_n + 1))
            if [ "$hk_n" = 1 ]; then
                case "${hk_arg##*/}" in
                    python|python3|python3.*) ;;
                    *) break ;;
                esac
            elif [ "$hk_n" = 2 ]; then
                case "$hk_arg" in
                    /*) hk_voll="$hk_arg" ;;
                    # Bei relativem Start steht in cmdline nur der Name; er
                    # wird gegen das Arbeitsverzeichnis des Prozesses
                    # aufgeloest, nicht gegen das eigene.
                    *)  hk_voll=$(readlink -f "$(readlink -f "$hk_d/cwd" 2>/dev/null)/$hk_arg" 2>/dev/null) ;;
                esac
                [ "$hk_voll" = "$HK_SKRIPT" ] && hk_treffer=1
            fi
        done <<HK_ARGUMENTE
$(tr '\0' '\n' < "$hk_d/cmdline" 2>/dev/null)
HK_ARGUMENTE
        if [ "$hk_treffer" = 1 ] && [ "$hk_n" = 2 ]; then
            echo "${hk_d#/proc/}"
        fi
    done
}

HK_GEFUNDEN=0
HK_UEBRIG=""
for hk_p in $(hk_dienste_finden); do
    HK_GEFUNDEN=$((HK_GEFUNDEN + 1))
    # SIGTERM, nicht SIGKILL: der Dienst meldet beim Beenden noch
    # service/online = 0 per MQTT, damit Loxone den Ausfall sieht.
    kill "$hk_p" 2>/dev/null
done
if [ "$HK_GEFUNDEN" != 0 ]; then
    hk_i=0
    while [ $hk_i -lt 20 ] && [ -n "$(hk_dienste_finden)" ]; do
        sleep 0.25
        hk_i=$((hk_i + 1))
    done
    for hk_p in $(hk_dienste_finden); do
        kill -9 "$hk_p" 2>/dev/null
    done
    sleep 1
    # Die Wirkung nachsehen, nicht den Rueckgabewert von kill.
    HK_UEBRIG=$(hk_dienste_finden | tr '\n' ' ')
    if [ -n "$HK_UEBRIG" ]; then
        echo "<WARNING> Ein Dienst aus der Zeit vor der Aktualisierung laesst sich"
        echo "<WARNING> nicht beenden (PID $HK_UEBRIG). Es wird kein zweiter"
        echo "<WARNING> gestartet - sonst sprechen zwei Dienste mit dem Beamer."
    else
        echo "<OK> $HK_GEFUNDEN Dienst(e) aus der Zeit vor der Aktualisierung beendet."
    fi
fi
# Eine PID-Datei aus der Zeit davor gibt es nach purge_installation nicht
# mehr; eine aus der Luecke koennte liegen und auf einen toten Prozess zeigen.
rm -f "$PDATA/hk_service.pid" 2>/dev/null

# ==========================================================================
# Den Dienst starten - VOR dem Entfernen der Marke
# ==========================================================================
#
# HK_START_TROTZ_MARKE=1 setzt ausschliesslich diese Stelle: hier ist die
# Marke die eigene, und dies ist der letzte Schritt der Aktualisierung.
#
# Die Reihenfolge ist gemessen, nicht geraten. Zwischen "Marke weg" und
# "Dienst da" saehe ein Waechterlauf weder die Marke noch einen laufenden
# Dienst und startete einen eigenen; an Chromecast4lox 1.3.10 ist genau das
# gemessen (Regeln/06). Solange die Marke liegt, weist sie jeden anderen
# Starter ab.
#
# Geht die Umgebungsvariable verloren - etwa weil dieses Skript wider
# Erwarten als root laeuft und dienst.sh sich per "su" heruntersetzt -, dann
# startet hier nichts, und der minuetliche Waechter holt es nach, sobald der
# trap unten die Marke entfernt hat. Das ist der geschlossene Ausfall.
if [ -z "$HK_UEBRIG" ] && [ -f "$LBPBIN/$PDIR/dienst.sh" ]; then
    HK_START_TROTZ_MARKE=1 /bin/bash "$LBPBIN/$PDIR/dienst.sh" start
fi

# Die Marke selbst entfernt der trap oben - auch dann, wenn der Start
# unterblieb. Sonst sperrte sie den Waechter eine Stunde lang.
exit 0
