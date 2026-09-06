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

rm -rf "$SICHER" 2>/dev/null
mkdir -p "$SICHER" 2>/dev/null
chmod 0700 "$SICHER" 2>/dev/null

gesichert=0
if [ -f "$PCONFIG/heimkino.cfg" ]; then
    cp -a "$PCONFIG/heimkino.cfg" "$SICHER/heimkino.cfg" 2>/dev/null
    chmod 0640 "$SICHER/heimkino.cfg" 2>/dev/null
    gesichert=1
fi
if [ -f "$PCONFIG/xbox_auth.json" ]; then
    cp -a "$PCONFIG/xbox_auth.json" "$SICHER/xbox_auth.json" 2>/dev/null
    chmod 0600 "$SICHER/xbox_auth.json" 2>/dev/null
    gesichert=1
fi

if [ "$gesichert" = "1" ]; then
    echo "<OK> Einstellungen und Xbox-Anmeldung gesichert nach $SICHER."
else
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
if [ -s "$NETZ_CFG/heimkino.cfg" ]; then
    cp -p "$NETZ_CFG/heimkino.cfg" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.heimkino.cfg" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.heimkino.cfg" 2>/dev/null
fi
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
if [ -s "$NETZ_CFG/xbox_auth.json" ]; then
    cp -p "$NETZ_CFG/xbox_auth.json" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.xbox_auth.json" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.xbox_auth.json" 2>/dev/null
fi
echo "<INFO> Zweitschrift der Einstellungen angelegt."

exit 0
