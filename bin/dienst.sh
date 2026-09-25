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

SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd -P)       # <home>/bin/plugins/<ordner>

# Die Wurzel wird GELESEN, nicht geraten (Regeln/03, Stufe 1 ist
# $LBHOMEDIR; Bestand-2026-09-18/klasse-H/Ergebnis.md, Bauart H1).
#
# Bis 1.3.12 stand hier "LBHOMEDIR=$(cd "$SELF/../../.." && pwd)" - das
# UEBERSCHRIEB ein gesetztes $LBHOMEDIR mit einer Rechnung aus dem
# Ablageort. In WSL gemessen (18.09.2026, Pruefung-Heimkino-1.3.13,
# Fall H2): dasselbe dienst.sh aus einem ausgepackten Archiv meldete
# "gestoppt" (rc 1), obwohl der Dienst der Anlage lief - es sah in
# <archiv>/data/plugins/bin nach.
#
# Zwei Stufen, in dieser Reihenfolge - und DANACH NICHTS MEHR:
#   1. $LBHOMEDIR aus der Umgebung, wenn es eine Wurzel bezeichnet,
#   2. aufwaerts suchen, bis ein Verzeichnis config/plugins, data/plugins
#      UND config/system/general.json traegt (der dritte Nachweis seit dem
#      Raumklima-Vorfall, Regeln/06).
# Bis 1.3.13 gab es eine dritte Stufe, "drei Ebenen ueber dem Ablageort". Sie
# machte die Suche wirkungslos: in einem fremden Baum ohne general.json
# loeschte "stop" dort einen fremden soll_laufen (in WSL gemessen,
# Pruefung-Heimkino-1.3.14, Fall D3; Muster 1 der Nachlese). Ohne Wurzel
# wird jetzt gewarnt statt vollzogen.
hk_wurzel_suchen() {
    hk_v="$SELF"
    hk_i=0
    while [ -n "$hk_v" ] && [ "$hk_v" != "/" ] && [ "$hk_i" -lt 8 ]; do
        if [ -d "$hk_v/config/plugins" ] && [ -d "$hk_v/data/plugins" ] \
           && [ -f "$hk_v/config/system/general.json" ]; then
            echo "$hk_v"
            return 0
        fi
        hk_v=$(dirname "$hk_v")
        hk_i=$((hk_i + 1))
    done
    return 1
}
HK_AUS_UMGEBUNG=0
if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] \
   && [ -d "$LBHOMEDIR/data/plugins" ]; then
    LBHOMEDIR=$(cd "$LBHOMEDIR" && pwd -P)
    HK_AUS_UMGEBUNG=1
else
    LBHOMEDIR=$(hk_wurzel_suchen) || LBHOMEDIR=""
fi

# Der Ordnername kommt aus $LBPPLUGINDIR, sonst aus dem Ablageort. Am Geraet
# steht $LBPPLUGINDIR in einer Cron-Schale nie (Regeln/03, 43 Linien) - dann
# traegt der Ablageort, und das ist bei einer regulaeren Installation genau
# richtig.
PNAME="${LBPPLUGINDIR:-}"
[ -n "$PNAME" ] || PNAME=$(basename "$SELF")

# Laeuft dieses Skript wirklich AUS der Installation?
#
# Regeln/06 sieht fuer die Pruefung am Geraet ein Pruefarchiv unter
# ~/pruefung/ vor - und das liegt genau drei Ebenen unter der Wurzel. Bis
# 1.3.12 hiess der Ordner dann "bin", und schon ein "status" legte in der
# LAUFENDEN Anlage data/plugins/bin und log/plugins/bin an (in WSL gemessen,
# 18.09.2026, Faelle H3 und H5; dieselbe Klasse wie der Raumklima-Vorfall
# vom 05.09.2026). Deshalb wird der Ablageort gegengeprueft, und was
# schreibt, faellt geschlossen aus.
INSTALLIERT=0
[ -n "$LBHOMEDIR" ] && [ "$SELF" = "$LBHOMEDIR/bin/plugins/$PNAME" ] && INSTALLIERT=1

# Ausdruecklich: Wurzel UND Ordner kommen aus der Umgebung ($LBHOMEDIR und
# $LBPPLUGINDIR - so arbeiten die Pruefwerkzeuge, und so verwaltet ein
# ausgepacktes Archiv die Anlage, wenn man es ausdruecklich will; Muster 3,
# Bauart Spotpreis-Tibber 0.9.19). Dann gilt der Dienst DER ANLAGE
# (<Wurzel>/bin/plugins/<ordner>/hk_service.py), nicht die Kopie daneben.
AUSDRUECKLICH=0
if [ "$HK_AUS_UMGEBUNG" = "1" ] && [ -n "${LBPPLUGINDIR:-}" ]; then
    case "$PNAME" in
        .|/|bin|html|plugins|*/*) ;;
        *) AUSDRUECKLICH=1 ;;
    esac
fi

# Was schaltet oder schreibt, faellt ohne Wurzel und ausserhalb der
# Installation geschlossen aus - auch stop, restart und der Waechter, nicht
# nur start (seit 1.3.14). Bis 1.3.13 prueften nur start; eine Kopie von
# bin/plugins/heimkino an anderer Stelle unter der Wurzel hielt mit "stop"
# und "restart" den Dienst der Anlage an, schon mit $LBHOMEDIR allein, wie
# es am Geraet in /etc/environment steht (in WSL gemessen,
# Pruefung-Heimkino-1.3.14, Faelle D1 und D2; Muster 3 der Nachlese).
vollzug_erlaubt() {
    if [ -z "$LBHOMEDIR" ]; then
        echo "WARNUNG: keine LoxBerry-Wurzel gefunden (\$LBHOMEDIR nicht brauchbar, und"
        echo "WARNUNG: oberhalb von $SELF traegt kein Verzeichnis config/plugins,"
        echo "WARNUNG: data/plugins und config/system/general.json) - es wird nichts getan."
        return 1
    fi
    if [ "$INSTALLIERT" != "1" ] && [ "$AUSDRUECKLICH" != "1" ]; then
        echo "FEHLER: dieses Skript liegt nicht unter"
        echo "FEHLER: $LBHOMEDIR/bin/plugins/$PNAME - aus einem ausgepackten"
        echo "FEHLER: Archiv oder einer Kopie wird nichts gestartet oder angehalten"
        echo "FEHLER: (ausser mit LBHOMEDIR UND LBPPLUGINDIR in der Umgebung)."
        return 1
    fi
    return 0
}

PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/hk_service.pid"
SOLL="$PDATA/soll_laufen"
# Die Marke "Aktualisierung laeuft": von preupgrade.sh als Erstes angelegt,
# von postupgrade.sh als Letztes entfernt. Sie liegt NEBEN dem Datenordner,
# weil purge_installation den Ordner selbst bedingungslos loescht
# (plugininstall.pl :886 -> :1629 ff.) - eine Marke darin waere von genau dem
# Schritt vernichtet, den sie ueberdauern soll.
MARKE="$LBHOMEDIR/data/plugins/$PNAME.upgrade_laeuft"
LOGDATEI="$PLOG/heimkino.log"
# Eigene Datei fuer alles, was NEBEN dem Protokoll anfaellt: Meldungen des
# Starts und alles, was hk_service.py nach stderr schreibt, bevor sein
# Protokoll steht (Syntaxfehler, fehlende Bibliothek, Abbruch im Importpfad).
#
# Bis 1.3.7 ging diese Ausgabe mit ">> $LOGDATEI" in DIESELBE Datei, die
# hk_service.py mit einem WatchedFileHandler fuehrt. Das haelt einen zweiten,
# anhaengenden Deskriptor auf diese Datei offen: verschwindet sie (Ramdisk
# geleert, log_maint), faengt der Handler das ab - dieser Deskriptor nicht.
# Am Geraet gemessen (06.09.2026): PID 893 hielt heimkino.log auf den
# Deskriptoren 1, 2 UND 3 offen, alle drei auf der geloeschten Datei.
# Regel: genau einer schreibt in eine Protokolldatei.
STARTLOG="$PLOG/heimkino_start.log"
SKRIPT="$SELF/hk_service.py"
# Ausdruecklich aus einer Kopie: der Dienst der Anlage (siehe AUSDRUECKLICH).
if [ "$INSTALLIERT" != "1" ] && [ "$AUSDRUECKLICH" = "1" ]; then
    SKRIPT="$LBHOMEDIR/bin/plugins/$PNAME/hk_service.py"
fi
CFG="$PCONFIG/heimkino.cfg"

# Angelegt wird erst beim START, nicht bei jedem Aufruf.
#
# Bis 1.3.12 stand hier "mkdir -p" auf oberster Ebene - auch "status" und
# "stop" legten damit Ordner an. Zwei Folgen, beide in WSL gemessen
# (18.09.2026, Faelle H3 und H4): ein Aufruf aus einem Pruefarchiv legte in
# der laufenden Anlage einen fremden Ordner an, und in der Upgrade-Luecke
# legte schon ein "status" data/plugins/<ordner> wieder an - "der Ordner ist
# da" sagte dort nichts mehr ueber eine gelungene Ruecksicherung aus.
ordner_anlegen() {
    mkdir -p "$PDATA" "$PLOG" 2>/dev/null
}

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

# Ist diese Prozessnummer UNSER Dienst? Argumentweise nach Regeln/06:
# GENAU zwei Argumente, argv[0] ein python-Interpreter, argv[1] zeichengenau
# DIESES hk_service.py (ein relativer Start wird gegen das Arbeitsverzeichnis
# DES PROZESSES aufgeloest), und der Prozess gehoert diesem Benutzer.
#
# Bis 1.3.13 genuegte "eines der ersten drei Argumente heisst hk_service.py".
# Ein Koeder "python3 <anderer Ordner>/hk_service.py --halten", auf den die
# PID-Datei zeigte, galt damit als Dienst: "status" meldete "laeuft", und
# "stop" beendete ihn (in WSL gemessen, Pruefung-Heimkino-1.3.14, Faelle D4
# und D5; Muster 4 der Nachlese). Bauart wie hk_dienste_finden() in
# uninstall/uninstall und postupgrade.sh.
ist_dienst() {
    hk_p="$1"
    case "$hk_p" in ''|*[!0-9]*) return 1 ;; esac
    [ -r "/proc/$hk_p/cmdline" ] || return 1
    [ "$(stat -c %u "/proc/$hk_p" 2>/dev/null)" = "$(id -u)" ] || return 1
    hk_n=0
    hk_treffer=0
    while IFS= read -r hk_arg; do
        hk_n=$((hk_n + 1))
        if [ "$hk_n" = 1 ]; then
            case "${hk_arg##*/}" in
                python|python3|python3.*) ;;
                *) return 1 ;;
            esac
        elif [ "$hk_n" = 2 ]; then
            case "$hk_arg" in
                /*) hk_voll=$(readlink -f "$hk_arg" 2>/dev/null) ;;
                *)  hk_voll=$(readlink -f "$(readlink -f "/proc/$hk_p/cwd" 2>/dev/null)/$hk_arg" 2>/dev/null) ;;
            esac
            [ -n "$hk_voll" ] && [ "$hk_voll" = "$(readlink -f "$SKRIPT")" ] && hk_treffer=1
        fi
    done <<HK_ARGUMENTE
$(tr '\0' '\n' < "/proc/$hk_p/cmdline" 2>/dev/null)
HK_ARGUMENTE
    [ "$hk_treffer" = 1 ] && [ "$hk_n" = 2 ]
}

# Alle eigenen Dienste, auch einer ohne PID-Datei (Waise: purge_installation
# loescht data/plugins/<ordner> samt PID-Datei, der Prozess laeuft weiter).
# Bis 1.3.13 hielt "stop" nur den Prozess aus der PID-Datei an; eine Waise
# lief weiter (in WSL gemessen, Pruefung-Heimkino-1.3.14, Fall D6).
dienste() {
    for hk_d in /proc/[0-9]*; do
        grep -qaF "hk_service.py" "$hk_d/cmdline" 2>/dev/null || continue
        ist_dienst "${hk_d#/proc/}" && echo "${hk_d#/proc/}"
    done
}

laeuft() {
    [ -f "$PID" ] || return 1
    P=$(cat "$PID" 2>/dev/null)
    ist_dienst "$P"
}

# Laeuft gerade eine Aktualisierung dieses Plugins?
#
# Der Installer legt die Cron-Datei rund eine Minute VOR postinstall.sh neu an
# (am Geraet 08.09.2026 gemessen: 03:31:32 gegen 03:32:24, Regeln/06). In
# dieser Luecke ist data/plugins/<ordner>/ geloescht und die Konfiguration die
# mitgelieferte Vorgabe. Der minuetliche Waechter kommt dort nicht zum Zuge -
# sein Merker soll_laufen liegt im geloeschten Ordner -, wohl aber der
# Systemstart ueber daemon/daemon und jeder Knopf der Oberflaeche. In WSL
# gemessen (18.09.2026, Pruefung-Heimkino-1.3.12): je 1 Prozess, und er lief
# mit dem Vorgabe-Themenpraefix "heimkino" statt mit dem des Anwenders.
#
# Nur eine Marke, die hoechstens eine Stunde alt ist, zaehlt. Aelter, aus der
# Zukunft oder unlesbar: sie gilt nicht - eine abgebrochene Installation darf
# den Dienst nicht fuer immer stilllegen.
#
# HK_START_TROTZ_MARKE=1 setzt ausschliesslich postupgrade.sh. Dort ist die
# Marke die eigene, und der Start ist der letzte Schritt der Aktualisierung.
marke_gilt() {
    [ "${HK_START_TROTZ_MARKE:-0}" = "1" ] && return 1
    [ -f "$MARKE" ] || return 1
    # Die Uhr wird gemessen, nicht angenommen: liefert "date" nichts - unter
    # Last kann ein fork scheitern -, dann rechnete die Schale mit einer leeren
    # Zeichenkette, das Alter wuerde negativ, die Bedingung fiele durch, und
    # der Dienst startete mitten in der Aktualisierung. Ein Schutz faellt
    # geschlossen aus (CLAUDE.md 4): ohne lesbare Uhr gilt eine LIEGENDE
    # Marke - auch eine unlesbare. Die Uhr wird deshalb VOR dem Inhalt
    # geprueft; bis 1.3.13 stand es umgekehrt, und ohne Uhr liess eine
    # unlesbare Marke den Start durch (in WSL gemessen,
    # Pruefung-Heimkino-1.3.14, Fall M3; Bauart Sprachsteuerung 0.11.9).
    JETZT=$(date +%s 2>/dev/null)
    case "$JETZT" in ''|*[!0-9]*) return 0 ;; esac
    SEIT=$(cat "$MARKE" 2>/dev/null)
    case "$SEIT" in ''|*[!0-9]*) return 1 ;; esac
    ALTER=$(( JETZT - SEIT ))
    # 300 s Vorlauf: eine Marke, die ein paar Minuten "aus der Zukunft"
    # stammt, zeugt von einer nachgestellten Uhr, nicht von einem fremden
    # Vorgang. Bis 1.3.13 galt sie dann nicht, und der Dienst startete mitten
    # in der Aktualisierung (Fall M1; Muster 8 der Nachlese).
    [ "$ALTER" -ge -300 ] && [ "$ALTER" -lt 3600 ]
}

starten() {
    vollzug_erlaubt || return 1
    # Auch eine Waise ohne PID-Datei zaehlt als laufender Dienst - sonst
    # liefen danach zwei (seit 1.3.14, siehe dienste()).
    HK_LAUFEND=$(dienste | tr '\n' ' ')
    if [ -n "$HK_LAUFEND" ]; then
        echo "laeuft bereits (PID ${HK_LAUFEND% })"
        return 0
    fi
    # VOR dem Sollmerker und vor jeder anderen Datei im Datenordner: was in
    # der Luecke gestartet wuerde, liest die Vorgabe-Konfiguration und
    # schreibt in einen Ordner, den der Installer gerade abgeraeumt hat.
    if marke_gilt; then
        echo "Eine Aktualisierung dieses Plugins laeuft - der Dienst wird danach gestartet."
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
    ordner_anlegen
    touch "$SOLL"
    # Die Ausgabe des Dienstes geht in die Startdatei, NICHT in das Protokoll:
    # dort schreibt allein der Handler des Programms. Beim Start gekappt, damit
    # sie nur die Ausgabe EINES Laufes sammelt und nicht unbegrenzt waechst.
    : > "$STARTLOG"
    nohup python3 "$SKRIPT" >> "$STARTLOG" 2>&1 &
    sleep 1
    if laeuft; then
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    echo "FEHLER: Start fehlgeschlagen - siehe $STARTLOG und $LOGDATEI"
    return 1
}

anhalten() {
    vollzug_erlaubt || return 1
    rm -f "$SOLL"
    HK_ZIELE=$(dienste)
    if [ -z "$HK_ZIELE" ]; then
        echo "laeuft nicht"
        return 0
    fi
    # SIGTERM, nicht SIGKILL: der Dienst meldet beim Beenden noch
    # service/online = 0 per MQTT, damit Loxone den Ausfall sieht.
    for P in $HK_ZIELE; do
        kill "$P" 2>/dev/null
    done
    for i in 1 2 3 4 5 6 7 8 9 10; do
        [ -n "$(dienste)" ] || break
        sleep 1
    done
    # Vor JEDEM Signal wird geprueft, auch vor kill -9: dienste() liefert
    # nur, was argumentweise noch unser Dienst ist - nie nach blossem kill -0.
    for P in $(dienste); do
        kill -9 "$P" 2>/dev/null
    done
    sleep 1
    HK_UEBRIG=$(dienste | tr '\n' ' ')
    if [ -n "$HK_UEBRIG" ]; then
        echo "FEHLER: laesst sich nicht anhalten (PID ${HK_UEBRIG% })"
        return 1
    fi
    echo "angehalten"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart) anhalten || exit 1; sleep 1; starten ;;
    status)
        if [ -z "$LBHOMEDIR" ]; then
            echo "unbekannt - keine LoxBerry-Wurzel gefunden"
            exit 1
        fi
        if laeuft; then
            echo "laeuft $(cat "$PID")"
            exit 0
        fi
        HK_LAUFEND=$(dienste | tr '\n' ' ')
        if [ -n "$HK_LAUFEND" ]; then
            echo "laeuft ${HK_LAUFEND% } (ohne PID-Datei)"
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
        vollzug_erlaubt >&2 || exit 1
        if [ -f "$SOLL" ] && eingeschaltet && [ -z "$(dienste)" ]; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet." >> "$LOGDATEI"
            starten >> "$STARTLOG" 2>&1
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|selbsttest|waechter}"
        exit 2
        ;;
esac
