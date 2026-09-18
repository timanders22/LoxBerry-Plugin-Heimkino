#!/bin/sh
# Heimkino - preupgrade (laeuft als Benutzer loxberry)
PDIR=$3
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
LBPCONFIG="${LBPCONFIG:-$5/config/plugins}"
BASE="${5:-$LBHOMEDIR}"
PCONFIG="$LBPCONFIG/$PDIR"
[ -d "$PCONFIG" ] || PCONFIG="$BASE/config/plugins/$PDIR"

# ---------- Die Marke "Aktualisierung laeuft" ZUERST ----------
#
# Zwischen dem Augenblick, in dem der Installer die neue Cron-Datei anlegt,
# und postinstall.sh liegt rund eine Minute (am Geraet 08.09.2026 gemessen:
# 03:31:32 gegen 03:32:24, Regeln/06). In dieser Luecke ist
# data/plugins/<ordner>/ geloescht und config/plugins/<ordner>/heimkino.cfg
# wieder die mitgelieferte Vorgabe.
#
# Der minuetliche Waechter startet in der Luecke NICHTS - sein Merker
# soll_laufen liegt im geloeschten Ordner (in WSL gemessen, 18.09.2026:
# 0 Prozesse). Zwei andere Wege starten sehr wohl: ein Systemstart mitten in
# der Aktualisierung (daemon/daemon) und jeder Knopf der Oberflaeche, je
# 1 Prozess - und der Dienst lief dann mit dem Vorgabe-Themenpraefix
# "heimkino" statt mit dem des Anwenders, denn das Praefix liest er nur beim
# Start. Deshalb hier als ERSTES eine Marke mit der Unixzeit: bin/dienst.sh
# startet nicht, solange sie juenger als 3600 s ist.
#
# Sie liegt NEBEN dem Datenordner. Der Punkt im Namen ist der ganze
# Unterschied: "rm -rf .../<x>/" trifft den Nachbarn "<x>.upgrade_laeuft"
# nicht.
mkdir -p "$BASE/data/plugins" 2>/dev/null
date +%s > "$BASE/data/plugins/$PDIR.upgrade_laeuft" 2>/dev/null
# Die Wirkung pruefen, nicht den Rueckgabewert: eine leere Datei waere keine
# Marke - dienst.sh laesst eine unlesbare nicht gelten.
if [ -s "$BASE/data/plugins/$PDIR.upgrade_laeuft" ]; then
    echo "<OK> Dienststart bis zum Ende der Aktualisierung gesperrt."
else
    echo "<WARNING> Die Marke $BASE/data/plugins/$PDIR.upgrade_laeuft liess sich"
    echo "<WARNING> nicht anlegen. Ein Systemstart waehrend der Aktualisierung"
    echo "<WARNING> koennte den Dienst mit der Vorgabe-Konfiguration anwerfen."
fi

# ---------- Was "Inhalt" heisst ----------
#
# Eine Groessenpruefung ("[ -s ]") beantwortet nur, ob ueberhaupt etwas
# dasteht. Eine abgeschnittene Datei besteht sie - und genau das ist der
# Schaden, der am 18.09.2026 im Bestand gemessen wurde
# (Bestand-2026-09-18/klasse-C/Ergebnis.md, Abschnitt 3a Nr. 3).
#
# Heil heisst hier zweierlei: die Datei ist LESBAR (die .cfg traegt ihren
# Abschnittskopf, das JSON laesst sich zu einem Objekt lesen) UND sie traegt
# ein GEHEIMNIS - einen Wert, den nur der Anwender liefern kann. Ohne den
# ist die Datei nichts wert, was sich zu sichern lohnte.
#
# Ist python3 nicht aufrufbar, gilt eine JSON-Datei als heil: dann verhaelt
# sich das Skript wie bis 1.3.12, statt eine Sicherung stillschweigend zu
# verweigern (Vorbild: json_heil() in GardenaSmartSystem 1.2.10).
hk_inhalt() {   # $1 Datei, $2 Art (cfg|json)
    [ -s "$1" ] || return 1
    case "$2" in
        cfg)
            grep -q '^[[:space:]]*\[heimkino\][[:space:]]*$' "$1" 2>/dev/null || return 1
            for hk_f in aktionstoken keycode ip mac geraete_id; do
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
            case $hk_rc in
                0) return 0 ;;
                1) return 1 ;;
                *) return 0 ;;
            esac
            ;;
    esac
    return 1
}

# Die Sicherung liegt BEWUSST NICHT unter /tmp.
#
# /tmp ist auf dem LoxBerry eine Ramdisk. Zwischen preupgrade und postupgrade
# liegt eine Paketinstallation; erzwingt die einen Neustart oder faellt der
# Strom aus, ist die Ramdisk leer. Betroffen waeren:
#
#   - heimkino.cfg samt Keycode des Beamers und Aktionstoken,
#   - xbox_auth.json mit den Azure-Refresh-Token. Deren Verlust bedeutet die
#     komplette Microsoft-Anmeldung von vorn - Anwendung anlegen, Geheimnis
#     erzeugen, Rueckleitungsadresse eintragen, Code kopieren.
#
# Der Vorschlag, statt dessen das Installationsverzeichnis $1 zu nehmen,
# hilft nicht: das liegt unter /tmp/uploads und damit auf DERSELBEN Ramdisk.
# Bestand hat nur, was auf der Karte liegt - also data/plugins/<Ordner>/.
# Die Sicherung liegt NEBEN dem Ordner, nicht darin. Gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026): der Installer ruft
# &purge_installation nicht nur beim Deinstallieren, sondern auch im
# Upgrade-Zweig (:886), und deren Rumpf loescht ohne jede Bedingung
# (:1629 ff.) config/plugins/<x>/, bin/plugins/<x>/, data/plugins/<x>/,
# templates/plugins/<x>/ und beide webfrontend/-Ordner. Eine Sicherung IN
# data/plugins/<x>/ wird also von genau dem Schritt vernichtet, den sie
# ueberdauern soll. Der Punkt im Namen ist der ganze Unterschied:
# "rm -rf .../<x>/" trifft den Nachbarn "<x>.upgrade_sicherung" nicht.
SICHER="$BASE/data/plugins/$PDIR.upgrade_sicherung"

# Erst die NEUE Sicherung bauen, dann die alte ablegen - nicht umgekehrt.
#
# Bis 1.3.12 stand hier "rm -rf $SICHER" VOR dem Sichern. Bricht der Lauf in
# dieser Luecke ab - Stromausfall, volle Karte, abgebrochener Installer -,
# gibt es weder die alte noch eine neue Sicherung. In WSL gemessen
# (18.09.2026, Pruefung-Heimkino-1.3.13, Fall D1): Lauf 1 legt die Sicherung
# an, purge_installation raeumt config/plugins/<x> ab, der zweite Lauf
# loescht die Sicherung und findet nichts mehr zum Kopieren - von vier
# Dateien mit dem Merkwort blieben ZWEI uebrig.
#
# Reihenfolge jetzt wie in GardenaSmartSystem 1.2.10 (preupgrade.sh um
# Z. 126, im Bestand gemessen: 0 von 11 Dateien verloren): in $SICHER.neu
# bauen -> Rueckgabewert UND Inhalt pruefen -> die alte nach $SICHER.alt
# schieben -> die neue an ihren Platz -> die alte wegwerfen. In keinem
# Augenblick gibt es keine Sicherung.
NEU="$SICHER.neu"
rm -rf "$NEU" 2>/dev/null
mkdir -p "$NEU" 2>/dev/null
chmod 0700 "$NEU" 2>/dev/null

gesichert=0
sicher_ok=1
sicher_fehlt=
# Die Wirkung pruefen, nicht den Rueckgabewert allein (CLAUDE.md 2): jede
# Datei wird nach dem Kopieren byteweise gegen das Original gehalten.
sichere_datei() {   # $1 Dateiname, $2 Rechte
    [ -f "$PCONFIG/$1" ] || return 0
    gesichert=1
    cp -a "$PCONFIG/$1" "$NEU/$1" 2>/dev/null
    chmod "$2" "$NEU/$1" 2>/dev/null
    if ! cmp -s "$PCONFIG/$1" "$NEU/$1"; then
        sicher_ok=0
        sicher_fehlt="$sicher_fehlt $1"
    fi
}
sichere_datei heimkino.cfg 0640
sichere_datei xbox_auth.json 0600

if [ "$gesichert" = "1" ] && [ "$sicher_ok" = "1" ]; then
    rm -rf "$SICHER.alt" 2>/dev/null
    if [ -d "$SICHER" ]; then mv "$SICHER" "$SICHER.alt" 2>/dev/null; fi
    if mv "$NEU" "$SICHER" 2>/dev/null; then
        rm -rf "$SICHER.alt" 2>/dev/null
        echo "<OK> Einstellungen und Xbox-Anmeldung gesichert nach $SICHER."
    else
        if [ -d "$SICHER.alt" ]; then mv "$SICHER.alt" "$SICHER" 2>/dev/null; fi
        rm -rf "$NEU" 2>/dev/null
        echo "<WARNING> Die neue Sicherung liess sich nicht an ihren Platz bringen."
        echo "<WARNING> Platz und Rechte in $BASE/data/plugins pruefen."
    fi
elif [ "$gesichert" = "1" ]; then
    rm -rf "$NEU" 2>/dev/null
    echo "<WARNING> Die Einstellungen liessen sich NICHT vollstaendig sichern"
    echo "<WARNING> (nicht in der Sicherung:$sicher_fehlt)."
    if [ -d "$SICHER" ]; then
        echo "<WARNING> Die bisherige Sicherung unter $SICHER bleibt unangetastet."
    fi
else
    rm -rf "$NEU" 2>/dev/null
    echo "<INFO> Nichts zu sichern - offenbar eine Erstinstallation."
fi

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift NEBEN den Konfigurationsordner, zusaetzlich zur bisherigen
# Sicherung. Grund: der Installer kopiert config/* aus dem Archiv ueber
# config/plugins/<ordner> (plugininstall.pl Zeile 899, cp -r ohne -n) und
# ueberschreibt dabei die Datei des Nutzers. Bisher haing die Rettung allein
# an postupgrade.sh. Laeuft das aus irgendeinem Grund nicht durch, greift
# jetzt postinstall.sh auf diese Zweitschrift zu - sie liegt ausserhalb des
# ueberschriebenen Ordners und wird vom Installer nicht angefasst.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-heimkino}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
# Nach INHALT entscheiden, nicht nach GROESSE (Regeln/05: "Merkwort
# vorhanden?", nicht "ist die Datei leer?"). Bis 1.3.12 stand hier "[ -s ]".
# Eine ABGESCHNITTENE Datei ist nicht leer: sie besteht die Groessenpruefung
# und verdraengt die heile Zweitschrift, ohne eine Zeile im Protokoll. In
# WSL gemessen (18.09.2026, Pruefung-Heimkino-1.3.13, Faelle C1 und C2):
# nach dem Lauf trug weder .backup.heimkino.cfg noch .backup.xbox_auth.json
# den heilen Stand.
#
# "Inhalt" heisst: lesbarer Kopf UND ein Wert, den nur der Anwender liefert
# (hk_inhalt weiter oben). Ein Stand OHNE Inhalt ersetzt NIE eine
# Zweitschrift MIT Inhalt. Gibt es noch gar keine Zweitschrift, wird auch
# eine beschaedigte Datei kopiert - etwas ist besser als nichts, und es geht
# nichts verloren (Fall C4).
netz_zweitschrift() {   # $1 Dateiname, $2 Pruefart, $3 Rechte
    nz_quelle="$NETZ_CFG/$1"
    nz_ziel="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$1"
    [ -s "$nz_quelle" ] || return 0
    if [ -f "$nz_ziel" ] && hk_inhalt "$nz_ziel" "$2" && ! hk_inhalt "$nz_quelle" "$2"; then
        echo "<WARNING> $1 ist unvollstaendig - die vorhandene Zweitschrift"
        echo "<WARNING> bleibt unveraendert."
        return 0
    fi
    # Rueckgabewert pruefen und nur melden, was wirklich geschah: eine
    # Erfolgsmeldung hinter einem gescheiterten cp faellt erst auf, wenn
    # Keycode, Aktionstoken und Anmeldung weg sind.
    if cp -p "$nz_quelle" "$nz_ziel" 2>/dev/null; then
        chmod "$3" "$nz_ziel" 2>/dev/null
        echo "<INFO> Zweitschrift von $1 angelegt."
    else
        echo "<WARNING> Die Zweitschrift von $1 liess sich NICHT anlegen."
        echo "<WARNING> Platz und Rechte in $NETZ_BASE/config/plugins pruefen."
    fi
}
netz_zweitschrift heimkino.cfg cfg 0600
# xbox_auth.json wird vom Archiv NIE mitgeliefert - und war deshalb bis
# 1.2.6 ungeschuetzt. Genau darin stehen Anwendungskennung, geheimer
# Schluessel, Umleitungs-URI und das Erneuerungstoken. Der Installer loescht
# beim Update den ganzen Ordner config/plugins/<x>, also auch diese Datei;
# die alte Kette legte ihre Sicherung unter data/plugins/<x> ab, und die
# loescht er ebenfalls. Ergebnis: nach jedem Update stand dort
# "Noch nicht angemeldet", und die ganze Microsoft-Registrierung war neu
# einzutragen.
#
# LEHRE: die Liste der zu sichernden Dateien darf sich NICHT danach richten,
# was das Archiv mitliefert. Gerade die Dateien, die es nie mitliefert, sind
# die wertvollen - Token und Zugangsdaten.
netz_zweitschrift xbox_auth.json json 0600

exit 0
