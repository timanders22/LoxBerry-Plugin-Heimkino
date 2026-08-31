#!/bin/bash
# Heimkino - Start, Stopp und Waechter des Abfragedienstes.
#
# Bis 1.2.11 gab es diese Datei nicht. Der Dienst wurde ausschliesslich beim
# Systemstart aus daemon/daemon heraus angeworfen; starb er an einer
# unbehandelten Ausnahme, lief er bis zum naechsten Neustart des Rechners
# nicht wieder an. Und weil alle MQTT-Werte zurueckbehalten sind, sah in
# Loxone bis dahin alles normal aus - virtuelle Eingaenge behalten ihren
# letzten Wert.
#
# Die Pfade werden aus dem EIGENEN Ablageort abgeleitet, nicht ueber
# LoxBerry::System und nicht mit einer festen Zahl von "..". LoxBerry legt
# die Cron-Datei drei Ebenen unter der Wurzel ab; eine Rechnung mit ".."
# landet daneben, und der Waechter sucht dann an einer Stelle, an der nichts
# liegt (am Geraet gemessen, 18.08.2026).

# readlink -f loest Symlinks auf, BEVOR das Verzeichnis bestimmt wird.
# LoxBerry legt Daemons als Symlink unter system/daemons/plugins/ ab; von
# dort aufgerufen ergaebe dirname "$0" den Pfad .../system/daemons/plugins,
# der Pluginname waere buchstaeblich "plugins", und PID-Datei, Sollmerker
# und Logdatei landeten neben dem eigenen Ordner statt darin.
# Als loxberry laufen, nicht als root.
#
# Der minuetliche Waechter kommt aus dem Cron. Laeuft der als root - und je
# nach Ablage des Cronjobs tut er das -, dann gehoerten PID-Datei, Sollmerker
# und Protokoll danach root. Die Oberflaeche laeuft als loxberry und koennte
# den Dienst anschliessend weder anhalten noch neu starten: sie darf die
# Dateien nicht mehr schreiben. Schlimmer noch, 'dienst.sh stop' meldet dann
# Erfolg - das kill scheitert, aber das rm der PID-Datei gelingt, weil das
# Verzeichnis loxberry gehoert. Der Dienst laeuft weiter und ist nur noch
# ueber die Prozessliste zu finden.
#
# Deshalb setzt sich das Skript selbst herunter, EINMAL und bevor es
# irgendetwas anlegt. exec, damit kein zusaetzlicher Prozess stehen bleibt.
# '-s /bin/bash' ausdruecklich: ohne das nimmt su die Login-Shell aus
# /etc/passwd. Steht dort nologin oder /bin/false, endet dieses Skript hier
# still und ohne Meldung - und weil es 'exec' ist, kaeme nicht einmal ein
# Rueckgabewert zurueck. Auf einem regulaeren LoxBerry ist der Zweig ohnehin
# unerreichbar (der Cron laeuft bereits als loxberry); er greift nur, wenn
# jemand von Hand mit sudo aufruft.
#
# Woertlich uebernommen aus LoxBerry-Plugin-Dashboard-0.9.12, dort seit dem
# 16.08.2026 in Betrieb. Ueber den Bestand gezaehlt am 31.08.2026: 15 von 17
# dienst.sh hatten den Abstieg nicht, obwohl REGELN_2 ihn seit langem
# verlangt.
if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    exec su -s /bin/bash loxberry -c "$(printf '%q ' "$0" "$@")"
fi

SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)          # <home>/bin/plugins/<ordner>
PNAME=$(basename "$SELF")
LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/hk_service.pid"
SOLL="$PDATA/soll_laufen"
LOGDATEI="$PLOG/heimkino.log"
SKRIPT="$SELF/hk_service.py"
CFG="$PCONFIG/heimkino.cfg"

mkdir -p "$PDATA" "$PLOG" 2>/dev/null

# Ist das Plugin in den Einstellungen ueberhaupt eingeschaltet? Ein Waechter,
# der gegen den Willen des Anwenders arbeitet, ist schlimmer als keiner.
# Fehlt die Datei oder der Schluessel, gilt "eingeschaltet" - das ist die
# Vorgabe in bin/hk_vorgaben.json.
eingeschaltet() {
    [ -f "$CFG" ] || return 0
    WERT=$(sed -n 's/^[[:space:]]*enabled[[:space:]]*=[[:space:]]*\([^[:space:];]*\).*/\1/p' "$CFG" | head -1)
    case "$WERT" in
        0|false|no|off) return 1 ;;
        *)              return 0 ;;
    esac
}

laeuft() {
    [ -f "$PID" ] || return 1
    P=$(cat "$PID" 2>/dev/null)
    [ -n "$P" ] || return 1
    kill -0 "$P" 2>/dev/null || return 1
    # Nummernrecycling ausschliessen: der Prozess muss unser Skript sein.
    #
    # Argumentweise pruefen, nicht die ganze Befehlszeile durchsuchen:
    # /proc/<pid>/cmdline trennt die Argumente mit Nullbytes, und ein grep
    # darueber traefe auch einen Editor mit geoeffneter hk_service.py. Beim
    # Erproben der Python-Fassung ist genau dieser Fall aufgetreten.
    # Jede Zeile ist EIN Argument; sed schneidet den Pfad ab, grep -x
    # vergleicht die ganze Zeile. Damit trifft es "hk_service.py" nur als
    # eigenes Argument, nicht als Teilzeichenkette irgendwo.
    NAMEN=$(tr '\0' '\n' < "/proc/$P/cmdline" 2>/dev/null | sed -n '1,3p' | sed 's#.*/##')
    echo "$NAMEN" | grep -qx 'hk_service.py' || return 1
    return 0
}

starten() {
    if laeuft; then
        echo "laeuft bereits (PID $(cat "$PID"))"
        return 0
    fi
    if ! eingeschaltet; then
        echo "Das Plugin ist in den Einstellungen abgeschaltet - es wird nichts gestartet."
        return 0
    fi
    if ! command -v python3 >/dev/null 2>&1; then
        echo "FEHLER: python3 nicht gefunden - ohne Python laeuft der Dienst nicht."
        return 1
    fi
    if [ ! -f "$SKRIPT" ]; then
        echo "FEHLER: $SKRIPT fehlt. Plugin neu installieren."
        return 1
    fi
    touch "$SOLL"
    # Die Ausgabe geht in die Logdatei. Das Python-Programm protokolliert
    # deshalb NICHT zusaetzlich nach stdout - sonst stuende jede Zeile
    # doppelt darin.
    nohup python3 "$SKRIPT" >> "$LOGDATEI" 2>&1 &
    sleep 1
    if laeuft; then
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    echo "FEHLER: Start fehlgeschlagen - siehe $LOGDATEI"
    return 1
}

anhalten() {
    rm -f "$SOLL"
    if ! laeuft; then
        echo "laeuft nicht"
        return 0
    fi
    P=$(cat "$PID")
    # SIGTERM, nicht SIGKILL: der Dienst meldet beim Beenden noch
    # service/online = 0 per MQTT, damit Loxone den Ausfall sieht.
    kill "$P" 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        laeuft || break
        sleep 1
    done
    if laeuft; then
        kill -9 "$P" 2>/dev/null
        sleep 1
    fi
    echo "angehalten"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart) anhalten; sleep 1; starten ;;
    status)
        if laeuft; then
            echo "laeuft $(cat "$PID")"
            exit 0
        fi
        echo "gestoppt"
        exit 1
        ;;
    selbsttest)
        python3 "$SELF/lg_beamer.py" --selbsttest
        exit $?
        ;;
    waechter)
        # Nur neu starten, wenn der Dienst laufen SOLL und das Plugin
        # eingeschaltet ist. Ein bewusst angehaltener Dienst bleibt
        # angehalten.
        if [ -f "$SOLL" ] && eingeschaltet && ! laeuft; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet." >> "$LOGDATEI"
            starten >> "$LOGDATEI" 2>&1
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|selbsttest|waechter}"
        exit 2
        ;;
esac
