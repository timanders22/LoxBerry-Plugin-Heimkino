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
# Startversuche des Waechters, die scheiterten: "<anzahl> <unixzeit>" (seit
# 1.3.15, C8). Daraus der Abstand bis zum naechsten Versuch: 1, 5, 15 min.
FEHLSTART="$PDATA/start_fehlschlaege"
# Letzter Neustart wegen eines haengenden Dienstes (seit 1.3.15, C11).
HAENGER="$PDATA/haenger_neustart"
ZUSTAND="$PDATA/zustand.json"
SKRIPT="$SELF/hk_service.py"
# Ausdruecklich aus einer Kopie: der Dienst der Anlage (siehe AUSDRUECKLICH).
if [ "$INSTALLIERT" != "1" ] && [ "$AUSDRUECKLICH" = "1" ]; then
    SKRIPT="$LBHOMEDIR/bin/plugins/$PNAME/hk_service.py"
fi
CFG="$PCONFIG/heimkino.cfg"

# Startsperre (Verbesserungsbau Heimkino-C, 02.10.2026).
#
# Bis 1.3.18 lag zwischen "laeuft schon einer?" (dienste()) und dem Start
# (nohup) nichts, was einen zweiten Aufrufer haette aufhalten koennen. Zwei
# Waechter derselben Sekunde - cron holt nach einem Uhrsprung beim Booten
# verpasste Minuten nach und startet cron.01min zweimal - oder Waechter und
# Startknopf fanden beide "laeuft nicht" und starteten je einen Dienst. Der
# Dienst sperrt selbst (hk_common.pid_belegen(), flock auf der PID-Datei),
# der zweite beendet sich also wieder - aber erst nachdem beide Startskripte
# ihre Startdatei gekappt und ihr Ergebnis gemeldet haben.
#
# Gesperrt wird auf dieses Skript selbst (flock auf Deskriptor 8), mit
# Warten bis 15 s: der zweite Aufrufer wartet, bis der erste seinen Start
# samt Nachsehen hinter sich hat, und fragt DANACH, ob schon einer laeuft.
# readlink -f, weil LoxBerry das Skript auch ueber einen Verweis unter
# system/daemons/plugins/ aufruft - gesperrt wird immer dieselbe Datei.
# Ein zweites "exec 8<" im selben Lauf wuerde den Deskriptor neu oeffnen
# und die Sperre dabei freigeben - daher der Merker HK_SPERRE_GEHALTEN
# (Waechter und restart sperren und rufen dann starten()).
# Der Dienst erbt den Deskriptor NICHT (8<&- beim Start): sonst hielte er
# die Sperre, solange er laeuft, und jeder spaetere Start wartete 15 s und
# gaebe dann auf (so gemessen an der Einspeisebremse 0.9.26, dort mit einer
# Sperre im PHP-Dienst, die sich an Kindprozesse vererbte).
# Ohne flock (kein util-linux) bleibt es beim Verhalten bis 1.3.18.
# Bauart: Bewaesserung 0.9.35, Sprachsteuerung 0.11.13 (startsperre_nehmen).
HK_SPERRE_GEHALTEN=0
startsperre_nehmen() {
    [ "$HK_SPERRE_GEHALTEN" = "1" ] && return 0
    command -v flock >/dev/null 2>&1 || return 0
    HK_SPERRDATEI=$(readlink -f "$0" 2>/dev/null)
    [ -n "$HK_SPERRDATEI" ] && [ -r "$HK_SPERRDATEI" ] || return 0
    exec 8<"$HK_SPERRDATEI"
    if flock -w 15 8; then
        HK_SPERRE_GEHALTEN=1
        return 0
    fi
    return 1
}

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
    if ! startsperre_nehmen; then
        echo "Ein anderer Start dieses Plugins laeuft seit ueber 15 Sekunden - jetzt wird nichts gestartet."
        return 0
    fi
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
    # Der Sollmerker entsteht erst NACH einem bestaetigten Start (seit 1.3.15,
    # C8; Regeln/03 "Der Sollmerker wird erst nach erfolgreicher Pruefung
    # gesetzt"). Bis 1.3.14 stand hier "touch $SOLL" vor dem Start: scheiterte
    # er, blieb der Merker liegen, und der Waechter versuchte es jede Minute
    # neu, mit einer Protokollzeile je Versuch - rund 1440 am Tag (gemessen,
    # Befund code 9). Ein Merker, der schon lag (der Dienst lief einmal),
    # bleibt liegen; dann versucht es der Waechter mit steigendem Abstand.
    # Die Ausgabe des Dienstes geht in die Startdatei, NICHT in das Protokoll:
    # dort schreibt allein der Handler des Programms. Beim Start gekappt, damit
    # sie nur die Ausgabe EINES Laufes sammelt und nicht unbegrenzt waechst.
    : > "$STARTLOG"
    # 8<&-: der Dienst erbt die Startsperre nicht (siehe startsperre_nehmen).
    nohup python3 "$SKRIPT" >> "$STARTLOG" 2>&1 8<&- &
    sleep 1
    if laeuft; then
        touch "$SOLL"
        rm -f "$FEHLSTART"
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    echo "FEHLER: Start fehlgeschlagen - siehe $STARTLOG und $LOGDATEI"
    return 1
}

# Wie viele Startversuche scheiterten zuletzt, und wann? Ausgabe "<n> <zeit>".
fehlstart_lesen() {
    hk_z=$(cat "$FEHLSTART" 2>/dev/null)
    hk_n=${hk_z%% *}
    hk_t=${hk_z#* }
    case "$hk_n" in ''|*[!0-9]*) hk_n=0 ;; esac
    case "$hk_t" in ''|*[!0-9]*) hk_t=0 ;; esac
    echo "$hk_n $hk_t"
}

fehlstart_merken() {
    # Nur aus der Installation (oder ausdruecklich) - sonst schriebe eine Kopie
    # in den Datenordner der Anlage (Muster 3 der Nachlese).
    vollzug_erlaubt >/dev/null 2>&1 || return 1
    set -- $(fehlstart_lesen)
    ordner_anlegen
    echo "$(( $1 + 1 )) $(date +%s)" > "$FEHLSTART" 2>/dev/null
}

# Darf der Waechter jetzt einen Startversuch machen? Abstand nach dem n-ten
# Fehlschlag: 0, 60, 300, danach 900 s. Springt die Uhr zurueck, gilt er.
start_erlaubt() {
    set -- $(fehlstart_lesen)
    case "$1" in
        0) hk_abstand=0 ;;
        1) hk_abstand=60 ;;
        2) hk_abstand=300 ;;
        *) hk_abstand=900 ;;
    esac
    hk_jetzt=$(date +%s 2>/dev/null)
    case "$hk_jetzt" in ''|*[!0-9]*) return 0 ;; esac
    [ "$hk_jetzt" -lt "$2" ] && return 0
    [ $(( hk_jetzt - $2 )) -ge "$hk_abstand" ]
}

# Haengt der laufende Dienst? Gemessen wird das ERZEUGNIS, nicht die
# Prozessnummer (seit 1.3.15, C11; Regeln/03): zustand.json schreibt der
# Dienst in jedem Durchgang, waehrend einer Szene frischt ein eigener Faden
# die Aenderungszeit auf. Grenze 3 x Takt + 60 s - dieselbe Rechnung wie die
# Zeile "Arbeitet er noch?" im Reiter Test (hk_test.php), damit Waechter und
# Selbstpruefung nicht auseinanderlaufen. Fehlt die Datei noch, zaehlt die
# Aenderungszeit der PID-Datei (Start des Dienstes). Bis 1.3.14 sah der
# Waechter nur, ob der Prozess da ist (Befund code 13).
haengt() {
    hk_bezug="$ZUSTAND"
    [ -f "$hk_bezug" ] || hk_bezug="$PID"
    [ -f "$hk_bezug" ] || return 1
    hk_takt=$(sed -n 's/^[[:space:]]*"takt":[[:space:]]*\([0-9][0-9]*\).*/\1/p' "$ZUSTAND" 2>/dev/null | head -1)
    case "$hk_takt" in ''|*[!0-9]*)
        hk_takt=$(sed -n 's/^[[:space:]]*intervall[[:space:]]*=[[:space:]]*\([0-9][0-9]*\).*/\1/p' "$CFG" 2>/dev/null | head -1) ;;
    esac
    case "$hk_takt" in ''|*[!0-9]*) hk_takt=60 ;; esac
    [ "$hk_takt" -lt 10 ] && hk_takt=10
    hk_grenze=$(( 3 * hk_takt + 60 ))
    hk_jetzt=$(date +%s 2>/dev/null)
    hk_mtime=$(stat -c %Y "$hk_bezug" 2>/dev/null)
    case "$hk_jetzt$hk_mtime" in ''|*[!0-9]*) return 1 ;; esac
    HK_ALTER=$(( hk_jetzt - hk_mtime ))
    HK_GRENZE=$hk_grenze
    [ "$HK_ALTER" -gt "$hk_grenze" ]
}

anhalten() {
    vollzug_erlaubt || return 1
    # Unter der Startsperre (Heimkino-C): sonst faende ein stop, der waehrend
    # eines Starts kommt, noch keinen Dienst, und der eben gestartete liefe
    # danach weiter, obwohl der Anwender ihn angehalten hat.
    if ! startsperre_nehmen; then
        echo "WARNUNG: ein anderer Start dieses Plugins haelt die Sperre seit ueber 15 Sekunden - es wird trotzdem angehalten."
    fi
    rm -f "$SOLL" "$FEHLSTART"
    prozesse_beenden
}

# Die eigenen Dienste beenden, OHNE den Sollmerker anzufassen (seit 1.3.15:
# der Waechter startet einen haengenden Dienst neu, und restart behaelt den
# Merker, damit ein gescheiterter Neustart nachgeholt wird).
prozesse_beenden() {
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
    start)
        # Scheitert der Start bei liegendem Merker (der Dienst lief schon
        # einmal), holt der Waechter ihn mit steigendem Abstand nach.
        starten || { [ -f "$SOLL" ] && fehlstart_merken; exit 1; }
        ;;
    stop)    anhalten ;;
    restart)
        # Seit 1.3.15 behaelt restart den Sollmerker: bis 1.3.14 hielt es mit
        # "anhalten" an und entfernte ihn - ein gescheiterter Neustart haette
        # jetzt (C8) keinen Merker mehr, und der Dienst bliebe aus.
        vollzug_erlaubt || exit 1
        # Anhalten und Starten unter EINER Sperre (Heimkino-C): sonst koennte
        # ein Waechter zwischen beiden einen Dienst starten und restart
        # danach einen zweiten.
        if ! startsperre_nehmen; then
            echo "Ein anderer Start dieses Plugins laeuft seit ueber 15 Sekunden - jetzt wird nichts neu gestartet."
            exit 1
        fi
        prozesse_beenden || exit 1
        sleep 1
        starten || { [ -f "$SOLL" ] && fehlstart_merken; exit 1; }
        ;;
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
        [ -f "$SOLL" ] && eingeschaltet || exit 0
        # Die ganze Frage "laeuft er? sonst starten" steht unter der
        # Startsperre (Heimkino-C). Zwei Waechter derselben Sekunde laufen
        # damit nacheinander, und der zweite findet den Dienst des ersten.
        # Bekommt ein Waechter die Sperre in 15 s nicht, tut er nichts -
        # der naechste kommt in einer Minute. Der Sollmerker wird unter der
        # Sperre noch einmal geprueft: ein stop, der waehrenddessen kam,
        # hat ihn entfernt.
        startsperre_nehmen || exit 0
        [ -f "$SOLL" ] || exit 0
        if [ -n "$(dienste)" ]; then
            # Laeuft er, arbeitet er auch? (seit 1.3.15, C11) Ein Neustart
            # wegen Haengens hoechstens alle 15 min, mit einer Protokollzeile.
            haengt || exit 0
            hk_letzt=$(cat "$HAENGER" 2>/dev/null)
            case "$hk_letzt" in ''|*[!0-9]*) hk_letzt=0 ;; esac
            hk_jetzt=$(date +%s)
            if [ "$hk_jetzt" -ge "$hk_letzt" ] && [ $(( hk_jetzt - hk_letzt )) -lt 900 ]; then
                exit 0
            fi
            echo "$hk_jetzt" > "$HAENGER" 2>/dev/null
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: zustand.json ist $HK_ALTER s alt (Grenze $HK_GRENZE s) - der Dienst haengt und wird neu gestartet." >> "$LOGDATEI"
            prozesse_beenden >> "$STARTLOG" 2>&1
            starten >> "$STARTLOG" 2>&1 || fehlstart_merken
            exit 0
        fi
        # Startversuche mit steigendem Abstand, je Versuch EINE Zeile (seit
        # 1.3.15, C8): bis 1.3.14 jede Minute ein Versuch und eine Zeile.
        start_erlaubt || exit 0
        set -- $(fehlstart_lesen)
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet (Versuch $(( $1 + 1 )))." >> "$LOGDATEI"
        if ! starten >> "$STARTLOG" 2>&1; then
            fehlstart_merken
            set -- $(fehlstart_lesen)
            case "$1" in 1) hk_naechst=1 ;; 2) hk_naechst=5 ;; *) hk_naechst=15 ;; esac
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Start fehlgeschlagen ($1. Versuch in Folge) - naechster Versuch in $hk_naechst min, siehe $STARTLOG." >> "$LOGDATEI"
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|selbsttest|waechter}"
        exit 2
        ;;
esac
