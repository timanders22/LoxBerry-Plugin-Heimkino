#!/bin/sh

COMMAND=$0
PTEMPDIR=$1
PSHNAME=$2
PVERSION=$4

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
    1) echo "<FAIL> Unzulaessiger Ordnername '$PFOLDER' - es wurde nichts angelegt; das Plugin ist nicht lauffaehig."
       exit 1 ;;
    2) echo "<FAIL> Der LoxBerry-Wurzelordner liess sich nicht bestimmen."
       echo "<FAIL> Ohne ihn wuerden Ordner im Wurzelverzeichnis des Systems"
       echo "<FAIL> angelegt. Es wurde nichts angelegt; das Plugin ist nicht lauffaehig."
       exit 1 ;;
esac
PDIR=$PFOLDER
# Alle Pfade aus BASE/PFOLDER (H1, seit 1.3.30) - bis 1.3.29 aus $LBPLOG, $LBPCONFIG, $LBPBIN, $LBPDATA.
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"

mkdir -p "$PLOG"
touch "$PLOG/$PSHNAME.log"
chown loxberry:loxberry "$PLOG/$PSHNAME.log"

chmod 755 "$PBIN"/*.py 2>/dev/null
# dienst.sh wird vom Daemon, von der Oberflaeche UND vom minuetlichen
# Waechter aufgerufen. Ohne Ausfuehrungsrecht schluege der Waechter jede
# Minute lautlos fehl. Seit 1.3.30 ebenso befehlsbruecke.sh (Waechter der Befehlsbruecke).
chmod 755 "$PBIN/dienst.sh" "$PBIN/befehlsbruecke.sh" 2>/dev/null
# Die beiden Datendateien sind nur zum Lesen da - sie tragen die Vorgaben
# und die MQTT-Themen fuer BEIDE Sprachen, Python wie PHP.
chmod 644 "$PBIN/hk_vorgaben.json" "$PBIN/hk_themen.json" 2>/dev/null

# data/plugins/<ordner> traegt seit 1.2.12 die PID-Datei und den Sollmerker
# des Waechters. Bis 1.2.11 lag die PID-Datei unter log/plugins - also auf
# einer Ramdisk, die bei jedem Neustart leer ist.
mkdir -p "$PDATA" 2>/dev/null
chown loxberry:loxberry "$PDATA" 2>/dev/null
# Seit 1.3.30 (Entwurf 3.9): die Auftragswarteschlange des Dienstes (Befehle ueber MQTT, Szenen,
# Nachfassen); der Dienst legt sie beim Start ebenso an.
mkdir -p "$PDATA/auftraege" 2>/dev/null
chown loxberry:loxberry "$PDATA/auftraege" 2>/dev/null
chmod 750 "$PDATA/auftraege" 2>/dev/null

# Die Konfiguration enthaelt nach dem Einrichten den Keycode des Beamers und
# das Aktionstoken: 0600 seit 1.3.15 (C9; Regeln/05, Ultraschall 1.2.6 - drei
# Stellen, nicht eine: PHP, Python und der Installer).
chmod 600 "$PCONFIG/heimkino.cfg" 2>/dev/null
chown loxberry:loxberry "$PCONFIG/heimkino.cfg" 2>/dev/null

# NACHKONTROLLE, keine Installationsanweisung.
#
# Installiert werden die drei Pakete von LoxBerry selbst - sie stehen
# zeilenweise in dpkg/apt (python3-cryptography, python3-paho-mqtt,
# python3-requests), und das ist auch der richtige Ort dafuer. Hier wird nur
# NACHGESEHEN, ob es geklappt hat: eine fehlende Paketquelle oder ein
# abgebrochener apt-Lauf faellt sonst erst Wochen spaeter auf, wenn der
# Beamer nicht schaltet.
#
# Die Formulierung war bis 1.1.1 missverstaendlich - sie las sich, als
# muesste der Anwender die Pakete von Hand nachziehen. Das ist nicht der
# Normalfall, sondern die Ausnahme.
fehlt=""
python3 -c "import cryptography" >/dev/null 2>&1 || fehlt="$fehlt python3-cryptography"
python3 -c "import paho.mqtt.client" >/dev/null 2>&1 || fehlt="$fehlt python3-paho-mqtt"
python3 -c "import requests" >/dev/null 2>&1 || fehlt="$fehlt python3-requests"

if [ -n "$fehlt" ]; then
    echo "<WARNING> Nachkontrolle: diese Module sind nicht ladbar:$fehlt"
    echo "<WARNING> Sie stehen in dpkg/apt und haetten von LoxBerry mitinstalliert"
    echo "<WARNING> werden sollen. Offenbar ist der apt-Lauf gescheitert - meist"
    echo "<WARNING> ein veralteter Paketindex. Abhilfe am LoxBerry (SSH):"
    echo "<WARNING>   sudo apt-get update && sudo apt-get install -y$fehlt"
    echo "<INFO> Ohne cryptography schaltet der Beamer nicht, ohne paho-mqtt"
    echo "<INFO> meldet das Plugin nichts, ohne requests bleibt die Xbox aussen vor."
else
    echo "<OK> Nachkontrolle: alle drei Python-Module vorhanden."
fi



# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
# Seit 1.3.30 (H1) aus dem Wurzelblock oben.
NETZ_BASE="$BASE"
NETZ_PDIR="$PFOLDER"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
# Vier Lagen werden unterschieden, nicht zwei:
#   fehlt / leer                    -> verloren
#   zeichengenau die Vorgabe        -> verloren (der Kopierschritt des
#                                      Installers sieht genau so aus)
#   vorhanden, aber OHNE Inhalt     -> verloren UND beschaedigt: der Stand
#                                      wird als <datei>.kaputt (0600)
#                                      beiseitegelegt, nicht weggeworfen
#   vorhanden und mit Inhalt        -> unberuehrt, immer
#
# Geheilt wird nur aus einer Zweitschrift, die SELBST Inhalt traegt. Eine
# Sicherung, die echte Einstellungen ersetzt, waere schlimmer als gar keine.
# ---------- Was "Inhalt" heisst ----------
#
# Wortgleich zu preupgrade.sh. Eine Groessenpruefung ("[ ! -s ]")
# beantwortet nur, ob ueberhaupt etwas dasteht; eine ABGESCHNITTENE Datei
# besteht sie und gilt damit als "vorhanden" - die heile Zweitschrift wird
# dann nicht geholt. Gemessen am 18.09.2026
# (Bestand-2026-09-18/klasse-C/Ergebnis.md, Abschnitt 3a Nr. 3; in WSL
# nachgestellt in Pruefung-Heimkino-1.3.13, Faelle C5 und C6).
#
# Heil heisst: die Datei ist LESBAR und traegt ein GEHEIMNIS - einen Wert,
# den nur der Anwender liefern kann. Ist python3 nicht aufrufbar, gilt eine
# JSON-Datei als heil; dann verhaelt sich das Skript wie bis 1.3.12.
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

netz_zurueck() {   # $1 Datei, $2 Art (cfg|json), $3 Rechte, $4 Pruefsumme der Vorgabe
    datei=$1; art=$2; rechte=$3; soll=$4
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    [ -f "$zweit" ] || return 0
    hk_inhalt "$zweit" "$art" || return 0
    verloren=0
    kaputt=0
    if [ ! -f "$ziel" ] || [ ! -s "$ziel" ]; then
        verloren=1
    elif [ -n "$soll" ]; then
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    fi
    if [ "$verloren" = "0" ] && ! hk_inhalt "$ziel" "$art"; then
        verloren=1
        kaputt=1
    fi
    [ "$verloren" = "1" ] || return 0
    if [ "$kaputt" = "1" ]; then
        # Der verdraengte Stand geht nicht verloren, er wird nur
        # beiseitegelegt - mit denselben engen Rechten, denn er kann
        # Keycode, Aktionstoken oder die halbe Anmeldung enthalten.
        if mv "$ziel" "$ziel.kaputt" 2>/dev/null; then
            chmod 0600 "$ziel.kaputt" 2>/dev/null
            echo "<WARNING> $datei war unvollstaendig. Der Stand liegt jetzt als"
            echo "<WARNING> $ziel.kaputt daneben."
        fi
    fi
    # H2 (1.3.30): die Wirkung pruefen, nicht den Rueckgabewert allein.
    if cp -p "$zweit" "$ziel" 2>/dev/null && cmp -s "$zweit" "$ziel"; then
        chmod "$rechte" "$ziel" 2>/dev/null
        echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
    else
        echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
        echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
    fi
}
# Die Pruefsumme ist die der MITGELIEFERTEN config/heimkino.cfg. Sie wird
# beim Anheben der Fassung nachgezogen, wenn sich die Vorgabedatei aendert -
# zuletzt 1.3.30 (H12: die 8 fehlenden Schluessel und die drei Haken der
# Umstellung, im selben Bau).
#
# Nur bei einer Aktualisierung (Entscheidung Nr. 1, Nachtrag Heimkino-C,
# 02.10.2026): die Marke data/plugins/<ordner>.upgrade_laeuft aus
# preupgrade.sh liegt - ohne Altersvergleich. Bei einer Neuinstallation legt
# preinstall.sh liegengebliebene Zweitschriften nach .alt; scheitert dort das
# Verschieben, meldet preinstall "werden NICHT eingespielt". Bis 1.3.18
# spielte postinstall sie dann trotzdem ein. Jetzt bleibt es dabei, und die
# liegenden Zweitschriften werden genannt. Bauart: Robonect 1.1.14,
# VolkswagenID 0.9.27.
if [ -f "$NETZ_BASE/data/plugins/$NETZ_PDIR.upgrade_laeuft" ]; then
    netz_zurueck "heimkino.cfg" cfg 0600 \
        "83b9231a6f6f042acafc946f2cf382aca933b84a58b334bf4c2ac9cec40f62ec"
    # xbox_auth.json liefert das Archiv nie mit - es gibt also keine Vorgabe,
    # mit der man vergleichen koennte. Der leere vierte Wert sagt das aus.
    netz_zurueck "xbox_auth.json" json 0600 ""
else
    hk_liegt=""
    for hk_z in "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.heimkino.cfg" \
                "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.xbox_auth.json"; do
        [ -e "$hk_z" ] && hk_liegt="$hk_liegt $hk_z"
    done
    if [ -n "$hk_liegt" ]; then
        echo "<WARNING> Neuinstallation: Zweitschriften einer frueheren Installation werden NICHT"
        echo "<WARNING> eingespielt; bitte von Hand entfernen:$hk_liegt"
    fi
fi

# ---------- Erstanleitung nur, wenn noch nichts eingerichtet ist ----------
#
# postinstall.sh laeuft bei der Erstinstallation UND bei jedem Upgrade
# (plugininstall.pl uebergibt kein Kennzeichen). Bis 1.3.13 stand die
# Anleitung "IP, MAC und Keycode eintragen" unbedingt da - nach einem
# gelungenen Upgrade sieht das aus, als seien die Einstellungen verloren
# (in WSL gemessen, Pruefung-Heimkino-1.3.14, Fall H3; gemeinsamer Auftrag
# vom 24.09.2026). Entschieden wird am INHALT nach dem Zurueckspielen:
# eingerichtet ist, wer eine Beamer-Adresse, einen Keycode oder eine
# Xbox-Geraetekennung eingetragen hat. Das Aktionstoken zaehlt NICHT - es
# entsteht beim ersten Oeffnen der Oberflaeche von selbst.
hk_eingerichtet() {   # $1 Datei
    [ -s "$1" ] || return 1
    for hk_f in ip keycode mac geraete_id; do
        hk_w=$(sed -n "s/^[[:space:]]*$hk_f[[:space:]]*=[[:space:]]*//p" "$1" 2>/dev/null | head -1)
        hk_w=$(printf '%s' "$hk_w" | tr -d '[:space:]')
        [ -n "$hk_w" ] && return 0
    done
    return 1
}
# Von einer Aktualisierung ist nur die Rede, wenn die Marke aus preupgrade.sh
# liegt (seit 1.3.15, I1). Bis 1.3.14 meldete eine Neuinstallation mit
# liegengebliebener Zweitschrift "die Aktualisierung hat sie uebernommen"
# (Befund installer 1, Fall N2/N4).
HK_MARKE="$NETZ_BASE/data/plugins/$NETZ_PDIR.upgrade_laeuft"
if [ -f "$HK_MARKE" ] && hk_eingerichtet "$NETZ_CFG/heimkino.cfg"; then
    echo "<OK> Einstellungen vorhanden - die Aktualisierung hat sie uebernommen."
elif [ -f "$HK_MARKE" ] && hk_eingerichtet "$NETZ_BASE/data/plugins/$NETZ_PDIR.upgrade_sicherung/heimkino.cfg"; then
    echo "<INFO> Die Einstellungen liegen in der Sicherung dieser Aktualisierung;"
    echo "<INFO> postupgrade.sh spielt sie gleich zurueck."
else
    echo "<INFO> Naechster Schritt: Reiter Einstellungen - IP, MAC und Keycode des"
    echo "<INFO> Beamers eintragen. Den Keycode erzeugt der Beamer selbst im"
    echo "<INFO> versteckten Menue unter Netzwerk-IP-Steuerung."
fi

exit 0
