#!/bin/sh
# Heimkino - postupgrade (laeuft als Benutzer loxberry)
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
if ! hk_wurzel "$3" "$5"; then
    echo "<FAIL> Kein LoxBerry-Wurzelverzeichnis erkannt oder Ordnername unzulaessig - es wurde nichts"
    echo "<FAIL> zurueckgespielt und nichts gestartet. Einstellungen und Xbox-Anmeldung liegen weiter in der"
    echo "<FAIL> Sicherung neben dem Datenordner bzw. in der Zweitschrift. Bitte melden."
    exit 1
fi
PDIR=$PFOLDER
# Alle Pfade aus BASE/PFOLDER (H1, seit 1.3.30).
LBPBIN="$BASE/bin/plugins"
PCONFIG="$BASE/config/plugins/$PFOLDER"
SICHER="$BASE/data/plugins/$PDIR.upgrade_sicherung"
PDATA="$BASE/data/plugins/$PDIR"
MARKE="$BASE/data/plugins/$PDIR.upgrade_laeuft"
# Merker aus preupgrade.sh: der Dienst lief vor der Aktualisierung (I3).
LIEF_VORHER="$BASE/data/plugins/$PDIR.lief_vorher"

# Die Marke aus preupgrade.sh faellt ueber einen trap, nicht am Dateiende.
#
# Dieses Skript ist das LETZTE Hakenskript dieser Linie - es gibt kein
# postroot.sh (Reihenfolge nach Regeln/06: preroot, preinstall, preupgrade,
# postinstall, postupgrade, postroot). Bleibt die Marke liegen, weist jeder
# Startweg den Dienst eine Stunde lang ab, ohne dass irgendwo stuende, warum.
# Ein trap traegt auch dann, wenn spaeter einmal ein frueher Ausstieg
# dazukommt (Regeln/06, Nachtrag 17.09.2026).
trap 'rm -f "$MARKE" "$LIEF_VORHER" 2>/dev/null' EXIT

# ---------- Was "Inhalt" heisst ----------
#
# Wortgleich zu preupgrade.sh und postinstall.sh. Bis 1.3.12 entschied hier
# ein blosses "[ -f ]": eine ABGESCHNITTENE Sicherung wurde ueber die
# Konfiguration kopiert, die postinstall.sh eine Zeile vorher aus der heilen
# Zweitschrift geholt hatte. In WSL gemessen (18.09.2026,
# Pruefung-Heimkino-1.3.13, Fall C8): der Anwenderstand war danach weg.
#
# Ist python3 nicht aufrufbar, gilt eine JSON-Datei als heil; dann verhaelt
# sich das Skript wie bis 1.3.12.
hk_inhalt() {   # $1 Datei, $2 Art (cfg|json)
    [ -s "$1" ] || return 1
    case "$2" in
        cfg)
            grep -q '^[[:space:]]*\[heimkino\][[:space:]]*$' "$1" 2>/dev/null || return 1
            # Das Aktionstoken zaehlt NICHT (seit 1.3.15, I4): es entsteht beim
            # ersten Oeffnen der Oberflaeche von selbst. Bis 1.3.14 galt eine
            # nach der Token-Zeile abgeschnittene Datei als "mit Inhalt" und
            # verdraengte die einzige heile Zweitschrift (Befund installer 4).
            for hk_f in keycode ip mac geraete_id; do
                hk_w=$(sed -n "s/^[[:space:]]*$hk_f[[:space:]]*=[[:space:]]*//p" "$1" 2>/dev/null | head -1)
                hk_w=$(printf '%s' "$hk_w" | tr -d '[:space:]')
                [ -n "$hk_w" ] && return 0
            done
            return 1
            ;;
        json)
            command -v python3 >/dev/null 2>&1 || return 0
            python3 -c 'import json, sys
try:
    d = json.load(open(sys.argv[1], encoding="utf-8"))
except Exception:
    sys.exit(1)
if not isinstance(d, dict):
    sys.exit(1)
for k in ("refresh_token", "client_id"):
    if str(d.get(k, "")).strip():
        sys.exit(0)
sys.exit(1)' "$1" 2>/dev/null
            hk_rc=$?
            # H3 (1.3.30): ein python3, das abbricht (Rueckgabe 2, 137 ...), heisst "nicht heil" - bis 1.3.29
            # galt die Datei dann als heil und verdraengte eine heile Zweitschrift. Nur ein FEHLENDES python3
            # (command -v oben) laesst eine JSON-Datei weiter als heil gelten.
            case $hk_rc in
                0) return 0 ;;
                1) return 1 ;;
                *) return 1 ;;
            esac
            ;;
    esac
    return 1
}

# Wie viele Anwenderfelder traegt eine Datei? Wortgleich zu preupgrade.sh (seit 1.3.30, H2).
hk_felder() {   # $1 Datei, $2 Art (cfg|json)
    [ -s "$1" ] || { echo 0; return; }
    case "$2" in
        cfg)
            hk_z=0
            for hk_f in keycode ip mac geraete_id; do
                hk_w=$(sed -n "s/^[[:space:]]*$hk_f[[:space:]]*=[[:space:]]*//p" "$1" 2>/dev/null | head -1)
                hk_w=$(printf '%s' "$hk_w" | tr -d '[:space:]')
                [ -n "$hk_w" ] && hk_z=$((hk_z + 1))
            done
            echo "$hk_z"
            ;;
        json)
            command -v python3 >/dev/null 2>&1 || { echo 0; return; }
            python3 -c 'import json, sys
try:
    d = json.load(open(sys.argv[1], encoding="utf-8"))
except Exception:
    d = {}
if not isinstance(d, dict):
    d = {}
print(sum(1 for k in ("client_id", "client_secret", "refresh_token") if str(d.get(k, "")).strip()))' "$1" 2>/dev/null || echo 0
            ;;
        *) echo 0 ;;
    esac
}

# Aus der Sicherung zurueckstellen - nach Inhalt, nicht nach Vorhandensein.
#
#   Sicherung MIT Inhalt                 -> uebernehmen
#   Sicherung OHNE Inhalt, Ziel OHNE     -> uebernehmen (etwas ist besser
#                                           als nichts, es geht nichts
#                                           verloren)
#   Sicherung OHNE Inhalt, Ziel MIT      -> stehen lassen und sagen, warum
#
# Die Wirkung wird geprueft, nicht der Rueckgabewert allein (seit 1.3.14):
# nach dem Kopieren muss das Ziel byteweise der Sicherung gleichen. Bis
# 1.3.13 stand "uebernommen" auch hinter einem gescheiterten cp, und die
# Sicherung wurde danach bedingungslos geloescht - Keycode, Aktionstoken und
# Xbox-Anmeldung waren weg (in WSL gemessen, Pruefung-Heimkino-1.3.14, Fall
# H1; Muster 9 der Nachlese). SZ_FEHLER haelt fest, ob die Sicherung
# gebraucht wird; dann bleibt sie liegen.
SZ_FEHLER=0
sz_kopieren() {   # $1 Quelle, $2 Ziel
    cp -a "$1" "$2" 2>/dev/null && cmp -s "$1" "$2"
}
sicher_zurueck() {   # $1 Dateiname, $2 Art (cfg|json), $3 Klartext fuer die Meldung
    sz_quelle="$SICHER/$1"
    sz_ziel="$PCONFIG/$1"
    [ -f "$sz_quelle" ] || return 1
    if hk_inhalt "$sz_quelle" "$2"; then
        # H2 mit E4 (1.3.30, Pruefbericht): postinstall.sh hat das Ziel womoeglich eben aus der Zweitschrift
        # geheilt (preupgrade hatte erkannt, dass die laufende Datei weniger Anwenderfelder traegt). Traegt das
        # Ziel Inhalt und MEHR Felder als die Sicherung, bleibt es - bis 1.3.29 kopierte diese Stelle die
        # schlechtere Sicherung darueber und meldete <OK>. SZ_FEHLER=1: die Sicherung bleibt liegen.
        if hk_inhalt "$sz_ziel" "$2" && [ "$(hk_felder "$sz_ziel" "$2")" -gt "$(hk_felder "$sz_quelle" "$2")" ]; then
            SZ_FEHLER=1
            echo "<WARNING> Die gesicherte $1 traegt weniger Anwenderfelder als die geheilte - sie bleibt unter $SICHER"
            return 0
        fi
        if sz_kopieren "$sz_quelle" "$sz_ziel"; then
            echo "<OK> $3 uebernommen."
        else
            SZ_FEHLER=1
            echo "<WARNING> $3 liessen sich NICHT zurueckspielen. Die Sicherung"
            echo "<WARNING> bleibt unter $SICHER liegen und kann von Hand"
            echo "<WARNING> nach $PCONFIG kopiert werden."
        fi
        return 0
    fi
    if ! hk_inhalt "$sz_ziel" "$2"; then
        if sz_kopieren "$sz_quelle" "$sz_ziel"; then
            echo "<WARNING> Die gesicherte $1 ist unvollstaendig. Sie wurde trotzdem"
            echo "<WARNING> uebernommen - eine andere Quelle gibt es nicht."
        else
            SZ_FEHLER=1
            echo "<WARNING> Die gesicherte $1 ist unvollstaendig und liess sich nicht"
            echo "<WARNING> zurueckspielen. Sie bleibt unter $SICHER liegen."
        fi
        return 0
    fi
    echo "<WARNING> Die gesicherte $1 ist unvollstaendig. Die vorhandene"
    echo "<WARNING> Konfiguration bleibt unveraendert."
    return 0
}

mkdir -p "$PCONFIG" 2>/dev/null

# Der Rueckfall auf eine Sicherung unter /tmp (Anlagen bis 1.1.1) ist seit
# 1.3.15 gestrichen (I7): er uebernahm eine Datei aus dem allgemein
# beschreibbaren /tmp als Konfiguration samt Aktionstoken und Xbox-Anmeldung
# (Befund installer 7). Ein Sprung von 1.1.1 auf 1.3.15 hat ohnehin keinen
# gemeinsamen Sicherungsweg.

if sicher_zurueck heimkino.cfg cfg "Bestehende Einstellungen"; then
    :
else
    # Kein blinder Alarm: der Installer loescht beim Update AUCH
    # data/plugins/<ordner> und damit die Sicherung, die preupgrade.sh
    # dorthin geschrieben hat - diese Kette kann hier gar nichts finden.
    # Gerettet wird aus der Zweitschrift neben dem Ordner, und das tut
    # postinstall.sh, das VOR postupgrade laeuft. Also erst nachsehen,
    # wie es wirklich steht; eine Warnung bei heiler Konfiguration
    # erschreckt ohne Grund und entwertet die echte.
    # Nach INHALT, nicht nach Groesse (seit 1.3.14): eine abgeschnittene
    # Datei ist nicht leer, traegt aber nichts - bis 1.3.13 hiess sie hier
    # "vorhanden" (in WSL gemessen, Pruefung-Heimkino-1.3.14, Fall H2).
    NETZ_PRUEF="$BASE/config/plugins/$PFOLDER/heimkino.cfg"
    if hk_inhalt "$NETZ_PRUEF" cfg; then
        echo "<OK> Die Einstellungen sind vorhanden (aus der Zweitschrift)."
    else
    echo "<WARNING> Keine gesicherten Einstellungen gefunden - IP, MAC und"
    echo "<WARNING> Keycode des Beamers muessen neu eingetragen werden."
    fi
fi
sicher_zurueck xbox_auth.json json "Bestehende Xbox-Anmeldung" || true

# ---------- Haken der Umstellung fuer bestehende Anlagen (seit 1.3.30, Gruppe 9) ----------
#
# Entscheidung 1 und E3 (Entwurf 3.7): der HTTP-Endpunkt haengt seit 1.3.30 am Haken endpunkt_ein (Vorgabe 0).
# Eine bestehende Anlage mit Aktionstoken behaelt ihn: fehlt der Schluessel in [heimkino], gilt endpunkt_ein=1, wenn
# ein Aktionstoken gesetzt ist, sonst 0 (ohne Token konnte niemand den Endpunkt nutzen). Die beiden Haken der
# Befehlsbruecke (befehle_mqtt_ein, befehle_mqtt_steuern) kommen als 0 dazu. Hier, NACH dem Zurueckspielen der
# Einstellungen (sicher_zurueck - anders als bei Matter2Lox, wo postinstall zuletzt schreibt) und VOR dem
# Dienststart: der Dienst und die Oberflaeche ergaenzten fehlende Schluessel sonst mit der Vorgabe 0. Geschrieben
# ueber eine Nebendatei, geprueft (Abschnitt, Laenge, jeder Schluessel), dann mv und 0600; die Zweitschrift neben
# dem Konfigordner bekommt dieselbe Regel (mit ihrem eigenen Token). Eine Neuinstallation hat die Schluessel schon
# (Paketvorlage) - dann geschieht hier nichts.
hk_haken_wert() {   # $1 Datei, $2 Schluessel in [heimkino]; Ausgabe Wert, Rueckgabe 1 = fehlt
    awk -v k="$2" '
        /^[[:space:]]*\[/ { im = ($0 ~ /^[[:space:]]*\[heimkino\][[:space:]]*$/); next }
        im && $0 ~ ("^[[:space:]]*" k "[[:space:]]*=") { sub(/^[^=]*=[[:space:]]*/, ""); sub(/[[:space:]]*$/, ""); print; f = 1; exit }
        END { exit (f ? 0 : 1) }' "$1" 2>/dev/null
}
hk_haken_ergaenzen() {   # $1 Datei, $2 Klartext fuer die Meldung
    hk_d="$1"
    [ -f "$hk_d" ] || return 0
    grep -q '^[[:space:]]*\[heimkino\][[:space:]]*$' "$hk_d" 2>/dev/null || return 0
    hk_neu=""
    hk_info=""
    if ! hk_haken_wert "$hk_d" endpunkt_ein >/dev/null; then
        if [ -n "$(hk_haken_wert "$hk_d" aktionstoken)" ]; then
            hk_neu="endpunkt_ein=1"
            hk_info=" (bestehende Anlage mit Aktionstoken - der HTTP-Weg bleibt an)"
        else
            hk_neu="endpunkt_ein=0"
        fi
    fi
    for hk_k in befehle_mqtt_ein befehle_mqtt_steuern; do
        hk_haken_wert "$hk_d" "$hk_k" >/dev/null || hk_neu="$hk_neu${hk_neu:+ }$hk_k=0"
    done
    [ -n "$hk_neu" ] || return 0
    hk_tmp="$hk_d.$$.haken"
    if awk -v zeilen="$hk_neu" '
            { print }
            !fertig && $0 ~ /^[[:space:]]*\[heimkino\][[:space:]]*$/ {
                n = split(zeilen, z, " "); for (i = 1; i <= n; i++) print z[i]; fertig = 1 }
        ' "$hk_d" > "$hk_tmp" 2>/dev/null \
       && [ "$(wc -c < "$hk_tmp")" -gt "$(wc -c < "$hk_d")" ] \
       && grep -q '^[[:space:]]*\[heimkino\][[:space:]]*$' "$hk_tmp" \
       && hk_haken_wert "$hk_tmp" endpunkt_ein >/dev/null \
       && hk_haken_wert "$hk_tmp" befehle_mqtt_ein >/dev/null \
       && hk_haken_wert "$hk_tmp" befehle_mqtt_steuern >/dev/null \
       && chmod 600 "$hk_tmp" 2>/dev/null && mv -f "$hk_tmp" "$hk_d" 2>/dev/null; then
        chmod 600 "$hk_d" 2>/dev/null
        chown loxberry:loxberry "$hk_d" 2>/dev/null
        echo "<INFO> $2 ergaenzt: $hk_neu$hk_info."
    else
        rm -f "$hk_tmp" 2>/dev/null
        echo "<WARNING> $2 liess sich nicht ergaenzen ($hk_neu). Den HTTP-Endpunkt schaltet man im Reiter"
        echo "<WARNING> Einbindung in Loxone ein (Haken 'HTTP-Endpunkt fuer Loxone annehmen')."
    fi
}
hk_haken_ergaenzen "$PCONFIG/heimkino.cfg" "Konfiguration"
hk_haken_ergaenzen "$BASE/config/plugins/$PFOLDER.backup.heimkino.cfg" "Zweitschrift"


chmod 600 "$PCONFIG/heimkino.cfg" 2>/dev/null
chmod 600 "$PCONFIG/xbox_auth.json" 2>/dev/null
chown loxberry:loxberry "$PCONFIG"/* 2>/dev/null
chmod 755 "$LBPBIN/$PDIR"/*.py 2>/dev/null
chmod 755 "$LBPBIN/$PDIR/dienst.sh" 2>/dev/null
chmod 644 "$LBPBIN/$PDIR/hk_vorgaben.json" "$LBPBIN/$PDIR/hk_themen.json" 2>/dev/null

# Die PID-Datei liegt seit 1.2.12 unter data/plugins statt unter log/plugins.
# Eine liegengebliebene alte Datei wuerde die Oberflaeche einen Dienst
# anzeigen lassen, den es nicht mehr gibt.
rm -f "$BASE/log/plugins/$PDIR/hk_service.pid" 2>/dev/null

# Der Sollmerker wird hier NICHT mehr gesetzt (seit 1.3.15, I3/C8): bis
# 1.3.14 stand hier ein "touch soll_laufen" bei jedem Upgrade, und ein bewusst
# angehaltener Dienst lief danach wieder (Befund installer 3). Den Merker
# setzt dienst.sh nach einem bestaetigten Start, und gestartet wird unten nur,
# wenn preupgrade.sh .lief_vorher gelegt hat.
mkdir -p "$BASE/data/plugins/$PDIR" 2>/dev/null

# Aufraeumen - an beiden Orten, aber nur, wenn das Zurueckspielen gelang
# (seit 1.3.14, siehe sicher_zurueck). Sonst ist die Sicherung die einzige
# Quelle und bleibt liegen; uninstall raeumt sie spaeter weg.
if [ "$SZ_FEHLER" = "0" ]; then
    rm -rf "$SICHER" 2>/dev/null
else
    echo "<WARNING> Die Sicherung unter $SICHER wurde NICHT geloescht."
fi

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
# Nur mit dem Merker aus preupgrade.sh (seit 1.3.15, I3): ein bewusst
# angehaltener Dienst bleibt angehalten. Den Merker raeumt der trap ab.
if [ ! -f "$LIEF_VORHER" ]; then
    echo "<INFO> Der Dienst war vor der Aktualisierung angehalten und bleibt es."
    echo "<INFO> Starten: Reiter Einstellungen, Knopf \"Dienst starten\"."
elif [ -z "$HK_UEBRIG" ] && [ -f "$LBPBIN/$PDIR/dienst.sh" ]; then
    HK_START_TROTZ_MARKE=1 /bin/bash "$LBPBIN/$PDIR/dienst.sh" start
fi

# Die Marke selbst entfernt der trap oben - auch dann, wenn der Start
# unterblieb. Sonst sperrte sie den Waechter eine Stunde lang.
exit 0
