# LoxBerry-Plugin Heimkino

Schaltet einen **LG-Beamer** (oder Fernseher ab Baujahr 2018) und eine
**Xbox** vom Loxone Miniserver aus und meldet ihren Zustand per MQTT zurück.

Beide Geräte lassen sich mit Loxone allein nicht vollständig bedienen — dieses
Plugin füllt genau die beiden Lücken, nicht mehr:

| Aufgabe | Weg |
|---|---|
| Beamer **einschalten** | **braucht dieses Plugin nicht.** Loxone kann Wake-on-LAN selbst: virtueller Ausgang mit der Adresse `wol://`, als Befehl die MAC ohne Trennzeichen. |
| Beamer **ausschalten** | TCP 9761, verschlüsselt. Ohne Keycode nimmt das Gerät keinen Befehl an, und Loxone kann nicht verschlüsseln. **Dieses Plugin.** |
| Xbox **einschalten** | über den Cloud-Dienst von Microsoft. **Dieses Plugin.** |
| Xbox **ausschalten** | ebenso. |

## Neu in 1.3.0

Funktionen. 1.2.12 war eine reine Fehlerbehebung; was dort steht, gilt weiter
und ist hier enthalten.

### Der Beamer kann mehr, als das Plugin bisher gefragt hat

Angebunden waren fünf Befehle. Die Vorlage kennt mehr, und drei davon sind für
einen Beamer der eigentliche Gewinn:

| Neu | Wozu |
|---|---|
| **Bild ausblenden** (`beamer-bild-aus` / `beamer-bild-an`) | Pause, Türklingel, Licht an — das Gerät bleibt an. Bisher blieb nur Ausschalten, und das kostet den Anlauf und Lampenlebensdauer. |
| **Bildmodus** (`beamer-bildmodus`) | „Film" = `cinema`, „Xbox an" = `game`. Genau das, was Loxone allein nicht kann. |
| **MAC am Gerät gegenprüfen** (Reiter *Test*) | Bisher merkte niemand, wenn die eingetragene MAC falsch war: Wake-on-LAN verpufft dann lautlos, und in Loxone sieht es aus, als ginge der Beamer nicht an. |

Dazu Lautstärke setzen und lesen, Stummschaltung, Energiesparstufe und
Anwendung starten. Alle Werte werden gegen die Wortliste der Vorlage gehalten
und **abgewiesen**, wenn sie nicht darin stehen — ein Wert, den das Gerät nicht
kennt, käme sonst als unlesbare Antwort zurück statt als Fehler. Die
Schreibweise zählt dabei: der Bildmodus heißt `filmMaker`, nicht `FILMMAKER`.

**Lautstärke und Stummschaltung sind ab Werk abgeschaltet.** Die beiden Befehle
sind an einem LG-*Fernseher* belegt; ob ein Beamer sie kennt, ist hier nicht
gemessen. Wer sie einschaltet, bekommt sie mitgelesen — und ein Fehlschlag
setzt ausdrücklich **nicht** `last_error`, sonst machte eine Bequemlichkeit die
Störungsmeldung unbrauchbar.

### Der Dienst fasst nach

Bis 1.2.12 galt ein Befehl als gelungen, sobald das Gerät „OK" gesagt hatte —
das ist die Annahme der Wirkung, nicht die Wirkung. Jetzt hinterlegt ein
Schaltbefehl einen Auftrag, und der Dienst prüft, ob der erwartete Zustand
wirklich eintritt:

```
beamer/letzte_aktion   beamer-aus gewirkt nach 14 s
                       beamer-aus OHNE WIRKUNG nach 90 s
                       beamer-aus nicht feststellbar nach 90 s
```

Die dritte Zeile ist kein Wortspiel: „nicht feststellbar" heißt, dass der
Beamer gar nicht antwortet. Das als Fehlschlag auszugeben wäre eine Behauptung.

Solange eine Prüfung läuft, fragt der Dienst alle fünf Sekunden statt im
eingestellten Takt — danach wieder wie zuvor. Abschaltbar im Reiter
*Einstellungen*.

### Eine Kino-Szene, die auf echte Bedingungen wartet

Neue Aktionen `kino-an` und `kino-aus`. Der Ablauf:

```
kino-an   Wake-on-LAN → warten, bis der Steuerport WIRKLICH antwortet
          → Eingang → Bildmodus → Xbox wecken
          → warten, bis die Konsole WIRKLICH On meldet
kino-aus  erst die Konsole, dann der Beamer
```

**Das ist der Unterschied zur Nachbildung in Loxone:** dort ließen sich die
Wartebedingungen nur mit Zeitgliedern *raten*. Leere Felder werden
übersprungen.

Ausgeführt wird die Szene im **Dienst**, nicht im Endpunkt — er ist die einzige
Stelle, die den Beamer befragen darf, weil das Gerät nur eine Verbindung zur
Zeit annimmt. Und ein virtueller Ausgang in Loxone hat ein Zeitlimit, das
kürzer ist als der Ablauf. Der Aufruf kehrt deshalb sofort zurück; wo die Szene
steht, sagt `szene/schritt`, wie sie ausging, `szene/ergebnis`.

### Dienst und Einzelbefehl kommen sich nicht mehr in die Quere

Das Gerät nimmt auf Port 9761 **genau eine Verbindung zur Zeit** an. Am Beamer
arbeiteten aber drei voneinander unabhängige Prozesse, und keiner wusste vom
anderen:

| Wer | Wann |
|---|---|
| `hk_service.py` | alle 60 s von selbst |
| `hk_cmd.py` | frisch gestartet, wenn Loxone den Endpunkt ruft oder man im Reiter *Test* klickt |
| `hk_test.php` | beim Klick auf *Beamer erreichbar?* |

Die vorhandene Sperre beantwortete eine andere Frage — ob schon ein *Dienst*
läuft. Über `hk_cmd` sagte sie nichts. Traf beides zusammen, verlor einer von
beiden:

- **Der Befehl verliert.** Der Endpunkt antwortet HTTP 500 — und ein virtueller
  Ausgang in Loxone wertet die Antwort **nicht** aus. Der Beamer bleibt an, und
  niemand erfährt warum.
- **Die Abfrage verliert.** Der Dienst meldet `unbekannt` und setzt
  `last_error` — eine Störungsmeldung für eine Störung, die es nicht gibt.

Neu ist `bin/hk_sperre.py`: eine Dateisperre, die **alle drei** nehmen. Vier
Festlegungen:

1. Sie sitzt an **einer** Stelle — in `LgBeamer.befehl()` und in
   `erreichbarkeit()`. Wer sie um jeden Aufruf von Hand legen müsste, vergisst
   sie beim nächsten Befehl.
2. Sie umschließt den **ganzen Wortwechsel**, nicht nur das Verbinden: das
   Gerät ist erst wieder frei, wenn die Antwort gelesen ist.
3. Im selben Prozess ist sie **wiedereintrittsfähig**. Der Dienst legt sie um
   einen ganzen Abfragedurchgang und ruft darin mehrere Befehle — ohne das
   spränge er sich selbst in die Falle.
4. **Der Befehl gewinnt, die Abfrage weicht.** `hk_cmd` wartet bis zu zehn
   Sekunden; der Dienst nimmt sie ohne Warten und **überspringt** den
   Durchgang, wenn sie besetzt ist.

Der vierte Punkt hat eine Folge, die wichtiger ist als die Sperre selbst: ein
übersprungener Durchgang ist **kein Ausfall**. Die zuletzt gemeldeten Werte
bleiben unverändert stehen; `status` wird nicht auf `unbekannt` gesetzt und
`last_error` nicht gefüllt. Sonst wäre eine stille Falschaussage nur durch eine
laute ersetzt worden.

`hk_test.php` fragt für *Beamer erreichbar?* jetzt über `hk_cmd` statt selbst
eine Verbindung zu öffnen. Und der Reiter *Test* hat eine Zeile **Wirkt die
Gerätesperre?** — ohne Dateisperre im System wird nicht gesperrt, und dann soll
das dastehen statt angenommen zu werden.

**Gemessen, in beide Richtungen.** Gegen eine Attrappe, die jede Verbindung
eine Sekunde hält und mitschreibt, wann sie offen war:

```
ohne Sperre, 2 Prozesse gleichzeitig   1 Ueberlappung   <- der Aufbau sieht den Fehler
mit  Sperre, 2 Prozesse gleichzeitig   0 Ueberlappungen
mit  Sperre, 4 Prozesse gleichzeitig   0 Ueberlappungen
```

Die erste Zeile ist die wichtige: ohne sie wäre nicht belegt, dass der Aufbau
eine Überlappung überhaupt erkennen kann. Dazu `hk_sperre.py --selbsttest`,
der gegen einen zweiten Prozess misst — abgewiesen ohne Warten, abgewiesen nach
kurzer Frist, genommen nach langer.

**Eine Einschränkung, die dazugehört:** auf diesem Rechner lief die Messung
über `msvcrt`, auf dem LoxBerry läuft sie über `fcntl`. Beide gehen durch
dieselbe Stelle, aber **gemessen ist hier nur der Windows-Weg.** Die Zeile im
Reiter *Test* sagt, welcher Unterbau auf der Anlage wirklich trägt.

### Betriebsstunden

`beamer/betriebsstunden` und `beamer/laufzeit_heute`, dasselbe für die Konsole.
Der Dienst fragt ohnehin jede Minute ab, also kostet das nichts.

**Ausdrücklich eine Schätzung.** Gezählt wird die Zeit zwischen zwei Abfragen,
wenn das Gerät bei der zweiten lief; bei einem Takt von 60 s liegt der Fehler
je Schaltvorgang bei bis zu einer Minute. Das Gerät selbst liefert diese Zahl
nicht und wird auch nicht danach gefragt. Ein Zeitsprung oder eine lange Pause
wird nicht mitgezählt.

### Xbox

`xbox/name` — der Name der Konsole kam schon immer mit und wurde weggeworfen.

Neuer Knopf **Antwort der Cloud im Original**: das Plugin liest von der Antwort
nur `powerState` und `name`. Was sonst darin steht, weiß niemand, der es nicht
angesehen hat — und aus einer Vermutung ein MQTT-Thema zu machen wäre die
falsche Reihenfolge. Der Knopf zeigt beide Wege nebeneinander, den direkten und
die Konsolenliste, damit sichtbar ist, welcher gerade trägt.

Und `xbox_cloud.py --selbsttest`: die Anmeldekette ließ sich bisher ohne echtes
Microsoft-Konto überhaupt nicht prüfen. Geprüft wird jetzt, was ohne Gegenstelle
prüfbar **ist** — Kopfzeilen, die Einordnung fremder Antworten (HTML vom Gateway
gegen JSON von der Schnittstelle, XErr-Codes), der Umgang mit der
Rückleitungsadresse, das unteilbare Schreiben der Tokendatei und die
Sitzungskennung.

### Was dabei gemessen wurde

`bin/lg_beamer.py --selbsttest` vergleicht jetzt **neunzehn** Befehle byteweise
mit der Originalfassung statt fünf. Die Prüfwerte sind mit dem npm-Paket
`lgtv-ip-control` 4.4.0 erzeugt, nicht mit diesem Nachbau — und die fünf alten
haben sich dabei zeichengleich reproduziert, was zugleich die Gegenprobe ist,
dass der Erzeuger richtig angesetzt war.

Dazu prüft der Selbsttest die Gegenrichtung: neun unzulässige Werte müssen
abgewiesen werden (`FILMMAKER` statt `filmMaker`, Lautstärke 101, ein Eingang,
den es nicht gibt), ein kleingeschriebener Keycode ebenso, und ohne Keycode
darf nichts unverschlüsselt hinausgehen.

**Nicht gemessen: an einem Gerät.** Diese Fassung ist an Beamer und Konsole
nicht gelaufen. Von den neuen Befehlen ist belegt, dass sie zeichengleich mit
der Vorlage verschlüsselt werden — nicht, dass ein Beamer jeden davon annimmt.

## Änderungen in 1.2.12

Eine Durchsicht Zeile für Zeile, alle Befunde behoben. Die wichtigsten:

**Ein Netzfehler sah aus wie „Beamer ist aus".** Der Vorabtest auf
Erreichbarkeit fing jeden Betriebssystemfehler ab und lieferte nur wahr oder
falsch. Damit landeten eine falsche IP, ein unauflösbarer Name, eine
schweigende Firewall und „das Gerät ist aus" auf demselben Wert — der Dienst
meldete daraufhin `beamer/status=aus`, `beamer/an=0` und einen **leeren**
`last_error`. In Loxone sah ein Defekt damit genau aus wie der Normalzustand.
Die Unterscheidung gab es sogar schon in `lg_beamer._verbindungsfehler()`; sie
wurde nur nie erreicht, weil der Vorabtest sie abschnitt. Neu ist das Thema
`beamer/grund` mit `ok`, `aus`, `abgewiesen`, `kein_weg`,
`zeitueberschreitung`, `name_unbekannt` oder `keine_adresse`.

**`service/online` blieb nach einem harten Ausfall auf 1 stehen.** Der Wert
wurde nur beim *geordneten* Ende auf 0 gesetzt. Bei SIGKILL, Speichermangel
oder Stromausfall blieb die zurückbehaltene 1 dauerhaft stehen, und virtuelle
Eingänge behalten ohnehin ihren letzten Wert: ein toter Dienst war in Loxone
nicht von einem laufenden zu unterscheiden. Jetzt trägt die MQTT-Verbindung
einen **letzten Willen**; der Broker setzt den Wert selbst. Dazu kommt
`service/zeitstempel` — die Unix-Zeit der letzten Abfrage, damit Loxone einen
stehengebliebenen Dienst selbst erkennen kann.

**Es gab keinen Wächter.** Der Dienst wurde ausschließlich beim Systemstart
angeworfen. Starb er an einer unbehandelten Ausnahme, lief er bis zum nächsten
Neustart des Rechners nicht wieder an. Neu sind `bin/dienst.sh`
(start/stop/restart/status/waechter) und `cron/cron.01min`. Der Wächter tut
nichts, wenn das Plugin abgeschaltet ist oder der Dienst bewusst angehalten
wurde.

**Die Zweitschriften mit allen Geheimnissen überlebten die Deinstallation.**
`preupgrade.sh` legt Keycode, Aktionstoken, Anwendungskennung,
Clientgeheimnis und die Azure-Refresh-Token eine Ebene **über** dem Ordner ab,
den die Deinstallation entfernt. Beide Dateien blieben dort im Klartext liegen
— und die Selbstheilung in `postinstall.sh` holt sie von selbst zurück: eine
„saubere" Neuinstallation brachte das alte Aktionstoken wieder mit. Dazu lag
das Skript unter `uninstall` statt unter `uninstall/uninstall`, wie es alle
übrigen Linien führen.

**Der Autorisierungscode ging über die Kommandozeile.** Argumente stehen in
`/proc/<pid>/cmdline` und sind für jeden lokalen Benutzer lesbar; zusammen mit
dem Clientgeheimnis lässt sich daraus ein Erneuerungstoken lösen. Er geht
jetzt über eine Datei mit Rechten 0600, die nach dem Lesen gelöscht wird.

**Ein abgelaufenes Erneuerungstoken wurde nie erkannt.** `invalid_grant` fiel
in den allgemeinen Fehlerzweig, das tote Token blieb stehen, und
`xbox/angemeldet` meldete dauerhaft 1, während jeder Befehl scheiterte. Jetzt
wird die Anmeldung verworfen und der Grund genannt.

**Formulare tragen ein Merkmal gegen fremde Absender.** Der angemeldete
Bereich schützt gegen den unangemeldeten Aufruf, nicht dagegen, dass der
Browser eines angemeldeten Bedieners ein Formular abschickt, das auf einer
fremden Seite steht. Ohne dieses Merkmal ließ sich von außen „Neues Token
erzeugen" auslösen — danach beantwortet der Endpunkt jeden virtuellen Ausgang
mit 403, und ein virtueller Ausgang wertet die Antwort nicht aus: der Ausfall
bliebe still.

**Der Selbsttest hielt seinen festgelegten Wortlaut nicht ein.** Bei falschem
Token kam „Token falsch." statt `SELFTEST;OK=0;ERR=TOKEN`, ohne eingerichtetes
Token ein ganzer Ratschlag statt `SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET`.
Eine maschinelle Prüfung bekam gerade im Fehlerfall nichts Verwertbares. Dazu
schlug `?selftest=0` ebenfalls an, und bei abgeschaltetem Plugin ließ sich das
Token gar nicht mehr prüfen — also genau die Frage, für die der Selbsttest da
ist.

**Ein eingefügtes Zeichen konnte ein Feld löschen, ohne dass es auffiel.** Die
Eingabesäuberung lief mit `/u`; bei ungültigem UTF-8 gibt `preg_replace` dann
`NULL` zurück, und die Seite meldete „gespeichert", während IP, MAC, Keycode
oder Geräteidentität leer waren. Und der Themenpräfix wurde hart gefiltert:
aus `wohnzimmer kino#1` wurde wortlos `wohnzimmerkino1`, was in einem Zug alle
MQTT-Themen, das einzutragende Abo und alle Titel der virtuellen Eingänge
ändert. Beides wird jetzt **abgewiesen und gemeldet**; alle übrigen Felder
werden trotzdem gespeichert.

**Der Keycode wird nicht mehr stillschweigend großgeschrieben.** Aus genau
diesen acht Zeichen leitet PBKDF2 den Schlüssel ab — eine Umschrift wäre eine
Umschrift des Schlüssels. Die Vorlage prüft `[A-Z0-9]{8}` und wandelt
ebenfalls nichts. Ein bereits gespeicherter kleingeschriebener Eintrag wird
**einmalig und mit Protokolleintrag** nachgezogen; wirksam war auch bisher die
große Fassung.

**Zwei Wahrheiten zusammengeführt.** Vorgabewerte und MQTT-Themen standen
zweimal — je einmal in Python und einmal in PHP. Sie stehen jetzt in
`bin/hk_vorgaben.json` und `bin/hk_themen.json`, die beide Seiten lesen. Der
Reiter *Test* hält sie gegeneinander und fragt den Dienst dabei über
`hk_service.py --themen` und `--vorgaben`, nicht die Datei.

**Textthemen standen in der Loxone-Vorlage.** `last_error`, `beamer/status`,
`beamer/app`, `xbox/status` und das Ablaufdatum landeten mit `Analog="true"`
und Zahlengrenzen in der Importdatei; das nachgebaute Format ist nur für
Zahlenwerte belegt. Sie bleiben jetzt draußen und stehen stattdessen in der
Tabelle. Dazu tragen die Einträge realistische Grenzen statt ±2147483647 und
eine Einheit; `xbox/geheimnis_tage` bekommt ein negatives `MinVal`, sonst
stünde bei „abgelaufen" eine 0 in der Visualisierung — und 0 heißt dort
„heute".

**Doppelt maskierte Umlaute.** Zeichenketten mit HTML-Entitäten liefen noch
einmal durch die Maskierfunktion — auf dem Bildschirm stand wörtlich
`l&auml;uft`, und dasselbe im `Comment` **jedes** virtuellen Eingangs der
erzeugten Loxone-Vorlage.

**Die Oberfläche ist zweisprachig.** Bis 1.2.11 waren rund 300 Stellen fest
deutsch eingetragen, `hk_test.php` vollständig. Alle Texte stehen jetzt in
`templates/lang/language_de.ini` und `language_en.ini`.

**Der Reiter MQTT hat jetzt Eingabefelder** (Haken und Themenpräfix, mit
eigenem Formular und eigenem Speicher-Handler), der Reiter *Einstellungen*
Knöpfe für Start, Neustart und Anhalten, und der Reiter *Test* eine stehende
Selbstprüfung mit Häkchen — darunter ein **echter Aufruf des eigenen
Endpunkts** auf 127.0.0.1. Ausgerechnet dieses Plugin war der Anlass für diese
Regel: bis 1.2.10 antwortete der Endpunkt immer mit einem leeren HTTP 500.

**Der Satz „Ohne diesen Eintrag kommt am Miniserver nichts an" gilt nur für
Gateway-Fassung 1.** Er stand bisher unbedingt da. Unter Fassung 2 sind die
Eingabefelder auf der Abonnement-Seite abgeschaltet — der Satz schickte jeden
V2-Anwender zu einem Feld, das es nicht mehr gibt. Ist die Fassung nicht
lesbar, stehen beide Sätze da.

**Und eine Behauptung, die nicht stimmte:** der Reiter MQTT verwies auf einen
UDP-Ausweichweg im Reiter *Einbindung in Loxone*. Einen solchen Weg gibt es in
diesem Plugin nicht und hat es nie gegeben. Der Satz ist weg.

**Weiteres.** Die Protokolldatei wird gekappt (sie liegt auf einer Ramdisk);
`xbox_auth.json` wird unteilbar geschrieben, mit Rechten 0600 schon beim
Anlegen und mit `fsync`; die Einfachlauf-Sperre hält eine echte Dateisperre
(`flock`) statt einer Prüfung mit einer Lücke dazwischen; Netzfehler zur
Xbox-Cloud werden übersetzt statt roh weitergereicht; ein fehlender Keycode
führt nicht mehr zu unverschlüsselt gesendeten Befehlen, sondern zu einer
Meldung; die Sitzungskennung wird gespeichert statt bei jedem Aufruf neu
gewürfelt; die angezeigte und die in die Vorlage geschriebene Adresse kommen
aus einer Quelle; die Vorlagendateien heißen jetzt `VI_Heimkino.xml` und
`VQ_Heimkino.xml`.

### Nicht behoben

Der Abfragedienst und ein Befehl aus dem Aktionsendpunkt öffnen **weiterhin
unabhängig voneinander** eine Verbindung zum Beamer. Das Gerät nimmt nur eine
zur Zeit an; treffen beide zusammen, wird eine davon abgewiesen. Aufgefallen
ist das bei der Durchsicht, nicht im Betrieb — eine gemeinsame Sperre über
beide Prozesse ist ein Eingriff in den Ablauf und gehört in eine eigene
Fassung, nicht in eine Fehlerbehebung.

> **Erledigt mit 1.3.0**, siehe oben unter *Dienst und Einzelbefehl kommen
> sich nicht mehr in die Quere*.

### Wie geprüft wurde

Ohne Gerät, gegen die SDK-Attrappe des Arbeitsordners, unter **PHP 7.4.33 und
8.4.24**: jeder Reiter einzeln gerendert (und einer mit erfundenem Namen —
dann muss *Einstellungen* offen sein), das Formular unverändert abgesendet,
der Aktionsendpunkt in neun Richtungen gefahren (Selbsttest richtig, falsch,
ohne eingerichtetes Token, `selftest=0`, ohne Token, falsches Token,
unbekannte Aktion, eingeschleuster Shell-Befehl im Wert, Token als Feld), und
das Formularmerkmal in drei Richtungen — ohne, mit falschem und mit richtigem,
jedes Mal mit der Gegenprobe am gespeicherten Wert. `lg_beamer.py
--selbsttest` vergleicht weiterhin Schlüsselableitung und fünf verschlüsselte
Befehle byteweise mit Werten der Originalfassung.

**Nicht geprüft: an einem echten Gerät.** Diese Fassung ist an Beamer und
Konsole noch nicht gelaufen.

## Änderungen in 1.2.11

**Token pruefbar, ohne etwas auszuloesen.** Neuer Aufruf
`?selftest=1&token=…` — antwortet `SELFTEST;OK=1;TOKEN=OK` beziehungsweise
HTTP 403 mit `SELFTEST;OK=0;ERR=TOKEN`. Es wird dabei nichts geschaltet und
nichts angefahren. Hausstandard fuer alle Aktionsendpunkte.

**Der Aktionsendpunkt lief immer in einen leeren HTTP 500.** `html/index.php`
band die Bibliothek mit `__DIR__ . '/../htmlauth/hk_lib.php'` ein. Das stimmt
im ausgepackten Archiv, wo `html/` und `htmlauth/` nebeneinanderliegen — auf
einem installierten LoxBerry aber nicht: dort werden beide in **getrennte
Bäume** gelegt (`webfrontend/html/plugins/<ordner>/` und
`webfrontend/htmlauth/plugins/<ordner>/`). Der Pfad zeigte ins Leere, PHP brach
mit einem schweren Fehler ab, und der Miniserver bekam eine leere Antwort ohne
jeden Hinweis.

Damit haben die beiden Loxone-Ausgänge **Beamer aus** und **Xbox wecken** seit
jeher nichts bewirkt. Aufgefallen ist es am 15.08.2026 bei einer Prüfung des
Endpunkts mit gültigem und mit falschem Token — beide Male HTTP 500, obwohl bei
falschem Token 403 hätte kommen müssen.

Der Endpunkt sucht die Bibliothek jetzt an den drei möglichen Stellen, wie es
das Intercom-Plugin aus demselben Grund seit längerem tut, und sagt im
Fehlerfall, **welche Datei wo fehlt**, statt mit einem leeren 500 zu enden.

## Warum die Xbox über die Cloud und nicht über das Netz vor Ort

Xbox-One-Konsolen ließen sich mit einem unauthentifizierten Weckpaket auf
UDP 5050 einschalten. Neuere Firmwarestände tun das nicht mehr. Nachgemessen an
einer Series X mit Firmware 10.0.26100:

| geprüft | Ergebnis |
|---|---|
| Paketinhalt | 25 Byte, am sendenden Gerät mitgeschnitten, byteweise korrekt |
| Zieladresse | per ARP gegen die MAC bestätigt |
| Erreichbarkeit im Ruhezustand | Ping antwortet |
| Energieoption | Ruhezustand aktiv |
| Remote-Features | eingeschaltet |
| Gerätekennung | die von der Konsole selbst angezeigte |
| fünffacher Versand | wirkungslos |

Dieselbe Konsole weckt die Xbox-App ohne Weiteres — und die geht über die
Cloud. Deshalb geht dieses Plugin denselben Weg.

**Nicht verschwiegen:** damit hängt das Wecken der Konsole an einer
Internetverbindung und an einer eigenen App-Registrierung bei Microsoft. Das
ist unschön, aber es ist der einzige Weg, der auf aktueller Firmware trägt —
am Gerät bestätigt, siehe *Am Gerät erprobt*.
Wer die Konsole ohnehin mit dem Controller weckt, braucht den Xbox-Teil nicht
und kann ihn abgeschaltet lassen.

Ein Hinweis für Leser, die auf Anleitungen mit `pip3 install openxbox` stoßen:
das Paket gibt es nicht. Die echte Bibliothek heißt `xbox-smartglass-core`, und
ihr `Console.power_on()` schickt **genau dasselbe UDP-Paket** — auf einer
aktuellen Konsole ändert sie also nichts.

## Herkunft

- Die Verschlüsselung der LG-IP-Steuerung ist ein Nachbau von
  [WesSouza/lgtv-ip-control](https://github.com/WesSouza/lgtv-ip-control) (MIT).
  Die Vorlage ist JavaScript; hier steht dieselbe Rechnung in Python.
  **Die Übereinstimmung ist nachgemessen:** `bin/lg_beamer.py --selbsttest`
  vergleicht Schlüsselableitung und **neunzehn** verschlüsselte Befehle mit
  Werten, die die Originalfassung (npm-Paket `lgtv-ip-control` 4.4.0) unter
  Node erzeugt hat — byteweise. Zusätzlich wurde gegen eine
  Attrappe geprüft, die die Originalfassung als Gegenstelle benutzt — und
  seit dem 30.07.2026 gegen ein echtes Gerät.
- Die Anmeldekette zu Xbox Live folgt
  [OpenXbox/xbox-webapi-python](https://github.com/OpenXbox/xbox-webapi-python) (MIT).

## Einrichtung

### Einstellungen am LG-Beamer

#### Das versteckte Menü — der Zugang, den man kennen muss

Mit der Fernbedienung *Alle Einstellungen → Allgemein → **Netzwerk*** ansteuern,
sodass *Netzwerk* markiert ist — und **nicht öffnen, nichts anklicken**. Dann
zügig die Ziffern **8 2 8 8 8** tippen. Es klappt ein Menü auf, das im normalen
Betrieb nicht sichtbar ist.

Klappt es nicht, liegt es fast immer an einem von zwei Dingen: zu langsam
getippt, oder man war schon eine Ebene tiefer. Einmal ganz aus dem Menü heraus
und neu ansteuern. Bei älteren Geräten heißt der Punkt *Netzwerkverbindung*
statt *Netzwerk*.

#### Die vier Einstellungen, ohne die das Plugin wirkungslos bleibt

| Einstellung | Wo | Warum |
|---|---|---|
| **Netzwerk-IP-Steuerung** ein, **Keycode erzeugen** (8 Zeichen) | verstecktes Menü (82888) | Ohne Keycode nimmt das Gerät keinen Befehl an. Ein neuer Keycode macht den alten ungültig. |
| **Schnellstart+** ein | *Allgemein → Geräte → Zusätzliche Einstellungen* | Ohne Schnellstart+ trennt das Gerät im Standby die Netzwerkschnittstelle. Dann antwortet Port 9761 nicht mehr — und Wake-on-LAN kommt gar nicht erst an. |
| **LAN statt WLAN** | *Netzwerk* | Kabel ist im Standby zuverlässiger. |
| **Feste IP** | Fritz!Box | Wandert die Adresse, schlägt alles fehl, ohne dass die Ursache sichtbar würde. |

Danach IP, MAC und Keycode im Reiter *Einstellungen* eintragen und im Reiter
*Test* → *Beamer erreichbar?* und *Beamer: IP-Steuerung* prüfen.

#### Zwei Automatiken, die Störungen vortäuschen

Unter *Allgemein → System → Zusätzliche Einstellungen*: die
**Ausschaltautomatik** schaltet nach vier Stunden ohne Bedienung ab, dazu der
**Bildschirmschoner**. Wer sich fragt, warum der Beamer „von selbst" ausgeht,
findet die Antwort meist hier und nicht in Loxone.

### Einstellungen an der Xbox

| Einstellung | Wo | Warum |
|---|---|---|
| **Energiesparmodus: Ruhezustand** | *Profil & System → Einstellungen → Allgemein → Energiesparmodus* | Steht dort *Energiesparen*, ist die Konsole im Aus wirklich aus und durch nichts zu wecken — auch nicht über die Cloud. |
| **Remote-Features aktivieren** | *Geräte & Verbindungen → Remote-Features* | Derselbe Schalter, den die Xbox-App braucht. |
| **HDMI-CEC** ein, *Konsole schaltet andere Geräte aus* | *Allgemein → TV- & A/V-Energieoptionen → HDMI-CEC* | Fürs Ausschalten des Verstärkers. Zum **Wecken** taugt CEC nicht, siehe unten. |
| **Feste IP**, möglichst LAN | Fritz!Box | Im Ruhezustand verlängert WLAN die Weckzeit merklich. |

Die **XBOX-Netzwerk-Geräteidentität** steht unter *System → Konsoleninfo* —
16 Zeichen. Nicht die *Konsolen-ID*, nicht die *Globale Geräte-ID*, nicht die
*Seriennummer*, die auf derselben Seite daneben stehen.

### Was HDMI-CEC bei der Xbox kann — und was nicht

Unter *Allgemein → TV- & A/V-Energieoptionen → HDMI-CEC* kennt die Konsole genau
diese Richtungen:

- Konsole schaltet andere Geräte **ein**
- Konsole schaltet andere Geräte **aus**
- Andere Geräte können die Konsole **deaktivieren**

Eine Zeile *andere Geräte können die Konsole einschalten* gibt es nicht. Von
außen nimmt die Konsole über CEC nur den Ausschaltbefehl an.

**Damit kann CEC die Xbox ausschalten, aber nicht wecken** — unabhängig von
Kabel und Verstärker. Zwei praktische Folgen:

- `xbox-aus` aus diesem Plugin ist oft überflüssig: schaltet der Verstärker ab,
  geht die Konsole über CEC mit.
- Für das **Wecken** bleiben der Controller oder der Cloud-Weg. Wer die Konsole
  ohnehin mit dem Controller weckt — ein Griff, den man beim Spielen macht —
  braucht den Xbox-Teil dieses Plugins nicht und lässt ihn abgeschaltet.

### Xbox über die Cloud

**Voraussetzung, an der die meisten scheitern: die Registrierung braucht ein
Verzeichnis (Mandant).** Ein persönliches Microsoft-Konto hat keines. Unter
*App-Registrierungen* erscheint dann der Hinweis „diesem Konto zugeordnet, jedoch
in keinem Verzeichnis enthalten", und *Neue Registrierung* öffnet kein Formular,
sondern ein Fenster mit einem einzigen Knopf: *Abbrechen*. Am Bildschirm
nachgesehen, nicht vermutet.

Ein Verzeichnis bekommt man über eine **Azure-Registrierung** (kostenloses Konto,
verlangt Zahlungsdaten zur Identitätsprüfung) oder das
**M365-Entwicklerprogramm** (kostenlos, Zugang eingeschränkt).

> **Nach der Azure-Registrierung abmelden und neu anmelden.** Die Portalsitzung
> trägt die Verzeichniszugehörigkeit in sich; eine Sitzung von vor der
> Registrierung kennt das neue Verzeichnis nicht. Symptome: das Portal meldet
> `AADSTS16000 … does not exist in tenant 'Microsoft Services'`, unter
> *Portaleinstellungen → Verzeichnisse + Abonnements* steht „Keine Verzeichnisse
> gefunden", und *Abonnements* ist leer. Das sieht nach einer fehlgeschlagenen
> Registrierung aus, ist aber oft nur die veraltete Sitzung.

0. **Prüfen, ob das Verzeichnis da ist.** Oben rechts auf den Kontonamen →
   *Verzeichnis wechseln*. Unter *Alle Verzeichnisse* muss eines aufgelistet und
   ausgewählt sein. Steht dort „Keine Verzeichnisse gefunden", ist alles Weitere
   zwecklos.
1. **Anwendung registrieren.** *App-Registrierungen → Neue Registrierung*:
   - **Name:** frei, z. B. `LoxBerry Heimkino`
   - **Kontotypen:** **Nur persönliche Microsoft-Konten** — die Konsole hängt an
     einem persönlichen Konto. Das Verzeichnis wird nur gebraucht, um die
     Anwendung anlegen zu dürfen.
   - **Umleitungs-URI:** Typ **Web**, Adresse genau
     `http://localhost/auth/callback`
2. **Anwendungs-ID (Client)** von der Übersichtsseite abschreiben. Kein
   Geheimnis. Nicht mit der Verzeichnis-ID oder der Objekt-ID verwechseln, die
   dort daneben stehen.
3. **Geheimnis erzeugen.** *Zertifikate & Geheimnisse → Geheime
   Clientschlüssel → Neuer geheimer Clientschlüssel*. Die Tabelle zeigt danach
   vier Spalten: *Beschreibung*, *Ablaufdatum*, **Wert** und *Geheime ID*.

   > **Gebraucht wird die Spalte „Wert".** Die Spalte **„Geheime ID" ist es
   > nicht** — sie sieht aus wie eine Kennung und wird deshalb regelmäßig
   > verwechselt. Und „Wert" ist **nur dieses eine Mal** sichtbar: wer die Seite
   > verlässt, sieht sie nie wieder und muss ein neues Geheimnis anlegen.

4. Kennung und Geheimnis im Reiter *Einstellungen* hinterlegen.

   > **Der geheime Clientschlüssel hält höchstens 24 Monate.** Azure vergibt
   > keine längere Frist — auch nicht mit eigenem Datum. Läuft er ab, antwortet
   > Microsoft mit `invalid_client` und die Konsole lässt sich nicht mehr aus
   > Loxone wecken. Das passiert zwei Jahre nach der Einrichtung, wenn niemand
   > mehr daran denkt.
   >
   > Deshalb steht in den Einstellungen das Feld **Clientgeheimnis gültig bis**.
   > Beim Speichern eines neuen Geheimnisses trägt das Plugin von sich aus
   > 24 Monate ein; wer in Azure kürzer gewählt hat, korrigiert das Datum. Das
   > Plugin zeigt die Restlaufzeit im Reiter *Test*, schreibt ab 60 Tagen eine
   > Warnung ins Protokoll und meldet Datum und Resttage per MQTT — damit Loxone
   > rechtzeitig eine Nachricht schicken kann, statt dass es beim nächsten
   > Filmabend auffällt.
   >
   > **Erneuern:** in Azure unter *Zertifikate & Geheimnisse* ein neues
   > Geheimnis anlegen, die Spalte *Wert* eintragen, die Anmeldung wiederholen,
   > das alte Geheimnis löschen. Anwendungskennung und Geräteidentität bleiben
   > unverändert.
5. *Anmeldeseite öffnen*, mit dem Microsoft-Konto anmelden, an dem die Konsole
   hängt. Der Browser landet auf einer Seite, die nicht lädt — das ist richtig.
   Die Adresszeile enthält `?code=…`; die ganze Adresse zurück in das Feld.
6. Reiter *Test* → *Konsolen suchen*. Die Kennung der gewünschten Konsole in
   die Einstellungen eintragen.

**Die XBOX-Netzwerk-Geräteidentität steht nicht in Azure.** Azure kennt nur die
Anwendung, nicht die Konsole. Zwei Quellen: *Test → Konsolen suchen* holt die
Liste aller Konsolen des Kontos samt Identität, oder man liest sie an der
Konsole ab (*System → Konsoleninfo*, siehe oben). In das Feld gehört die
**Geräteidentität**, nicht der **Name** der Konsole.

### Wenn ein Aufruf mit „403 Forbidden" und einer HTML-Seite antwortet

Vor der Schnittstelle `xccs.xboxlive.com` sitzt ein Azure Application Gateway.
Es weist Anfragen ohne brauchbare Kopfzeilen ab — und zwar mit einer
**HTML-Seite**, nicht mit einer Fehlermeldung der Schnittstelle. Der
Unterscheidungspunkt: kommt HTML zurück, hat das Gateway geantwortet; kommt JSON
zurück, die Schnittstelle.

Insbesondere genügt die Vorgabe von `python-requests` als `User-Agent` nicht.
Dieses Plugin setzt deshalb `User-Agent`, `Accept`, `Accept-Language` und
`Accept-Encoding` an **jeder** Anfrage — auch an denen zur Anmeldung.

**Wichtig zum Verständnis:** Ein solcher 403 heißt nicht, dass die Anmeldung
schiefging. Sie war bereits erfolgreich, sonst wäre der Aufruf nie so weit
gekommen.

## Einbindung in Loxone

### Zustand lesen — MQTT

Alle Themen liegen unter dem eingestellten Präfix (Vorgabe `heimkino`) und sind
**retained**.

| Thema | Art | Bedeutung |
|---|---|---|
| `service/online` | digital | 1 = der Dienst läuft. Wird beim Verbindungsabbruch vom Broker selbst auf 0 gesetzt (Last Will). |
| `service/zeitstempel` | analog | Unix-Zeit der letzten Abfrage. Für Loxone 1230768000 abziehen. Bleibt der Wert stehen, arbeitet der Dienst nicht mehr. |
| `last_error` | Text | letzte Fehlermeldung mit vorangestellter Quelle, sonst ein Bindestrich |
| `beamer/aktiv` | digital | 1 = der Beamer ist in den Einstellungen eingeschaltet |
| `beamer/erreichbar` | digital | 1 = der Beamer antwortet auf seinem Steuerport |
| `beamer/grund` | Text | warum der Beamer nicht antwortet: ok, aus, abgewiesen, kein_weg, zeitueberschreitung, name_unbekannt, keine_adresse |
| `beamer/status` | Text | an, aus oder unbekannt |
| `beamer/an` | digital | 1 = der Beamer läuft |
| `beamer/app` | Text | laufende Quelle, zum Beispiel hdmi1 |
| `beamer/lautstaerke` | analog | Lautstärke 0 bis 100; -1 = nicht gelesen. Nur wenn die Zusatzwerte eingeschaltet sind und das Gerät den Befehl kennt. |
| `beamer/stumm` | analog | 1 = stumm, 0 = nicht stumm, -1 = nicht gelesen |
| `beamer/betriebsstunden` | analog | geschätzte Betriebsstunden seit dem ersten Lauf dieser Fassung. Aus dem Abfragetakt hochgerechnet, keine Angabe des Geräts. |
| `beamer/laufzeit_heute` | analog | geschätzte Laufzeit des heutigen Tages in Minuten |
| `beamer/letzte_aktion` | Text | Ergebnis des letzten Schaltbefehls am Beamer, zum Beispiel beamer-aus gewirkt nach 14 s |
| `xbox/aktiv` | digital | 1 = die Xbox ist in den Einstellungen eingeschaltet |
| `xbox/status` | Text | Zustandstext der Cloud, zum Beispiel On oder ConnectedStandby |
| `xbox/an` | digital | 1 = die Konsole läuft |
| `xbox/name` | Text | Name der Konsole, wie ihn das Microsoft-Konto führt |
| `xbox/angemeldet` | digital | 1 = die Anmeldung bei Microsoft trägt noch |
| `xbox/betriebsstunden` | analog | geschätzte Betriebsstunden der Konsole seit dem ersten Lauf dieser Fassung |
| `xbox/laufzeit_heute` | analog | geschätzte Laufzeit der Konsole am heutigen Tag in Minuten |
| `xbox/letzte_aktion` | Text | Ergebnis des letzten Schaltbefehls an der Konsole |
| `xbox/geheimnis_ablauf` | Text | Ablaufdatum des Clientgeheimnisses, JJJJ-MM-TT |
| `xbox/geheimnis_tage` | analog | Tage bis zum Ablauf des Clientgeheimnisses; negativ = abgelaufen |
| `szene/laeuft` | digital | 1 = eine Kino-Szene läuft gerade ab |
| `szene/schritt` | Text | der Schritt, bei dem die Szene gerade steht |
| `szene/ergebnis` | Text | Ergebnis der zuletzt gelaufenen Szene samt Dauer |

Der Reiter *Einbindung in Loxone* liefert eine Vorlage für die virtuellen
Eingänge zum Einlesen in Loxone Config. **Die Textthemen bleiben in der
Vorlage außen vor** — das nachgebaute Dateiformat ist nur für Zahlenwerte
belegt. Sie stehen in der Tabelle oben und werden bei Bedarf von Hand
angelegt.

Die Themenliste steht nur an **einer** Stelle: `bin/hk_themen.json`. Der
Dienst sendet daraus, die Oberfläche zeigt die Tabelle daraus, und die
Loxone-Vorlage entsteht daraus. Diese Tabelle hier ist daraus erzeugt.

### Schalten — virtuelle Ausgänge

Der Miniserver ruft eine Adresse im unangemeldeten Bereich auf. Damit das nicht
jedes Gerät im Netz kann, steckt in jeder Adresse ein **Aktionstoken**, das beim
ersten Speichern erzeugt wird. Der Reiter *Einbindung in Loxone* zeigt die
fertigen Adressen.

```
Adresse des Ausgangs:  http://<loxberry>
Befehl bei EIN:        /plugins/heimkino/index.php?token=<TOKEN>&aktion=beamer-aus
```

Erlaubte Aktionen ohne Wert: `beamer-aus`, `beamer-wol`, `beamer-bild-aus`,
`beamer-bild-an`, `beamer-stumm-an`, `beamer-stumm-aus`, `xbox-an`, `xbox-aus`,
`kino-an`, `kino-aus`.

Mit `&wert=…`: `beamer-taste`, `beamer-eingang`, `beamer-lautstaerke`,
`beamer-bildmodus`, `beamer-energie`, `beamer-app`.

Alles andere wird abgewiesen; der Wert darf nur Buchstaben, Ziffern und
Unterstrich enthalten. **Groß- und Kleinschreibung bleibt dabei erhalten** —
der Bildmodus heißt `filmMaker`, und ein Kleinschreiben zerstörte einen
gültigen Wert.

Ein virtueller Ausgangsbefehl feuert bei der Flanke 0→1, nicht dauerhaft.

## Der Abfragetakt

Ein LG-Gerät nimmt **nur eine Verbindung zur Zeit** an. Ein zu kurzer Takt
sperrt die Fernbedienung der App aus. 60 Sekunden sind die Vorgabe; wer schneller
abfragen will, sollte wissen, was er dafür aufgibt. Der Dienst öffnet die
Verbindung deshalb je Befehl und schließt sie wieder, statt sie zu halten.

## Sicherheit

- Der Keycode steht in `config/plugins/heimkino/heimkino.cfg` (Rechte 0640).
- Anwendungskennung, Clientgeheimnis und die Token liegen getrennt in
  `xbox_auth.json` mit Rechten 0600 und werden nie über die Kommandozeile
  übergeben — Argumente sind in der Prozessliste sichtbar.
- Das Aktionstoken wird mit `hash_equals` verglichen, also in gleichbleibender
  Zeit. Ein einfaches `==` ließe sich über die Antwortzeit Zeichen für Zeichen
  erraten.
- Der Dienst läuft als `loxberry`, nicht als `root`. Das Plugin verändert keine
  Systemdatei.
- **Keine dieser Dateien gehört in ein öffentliches Repository.**

## Stand der Prüfung

Geprüft wurden:

- Schlüsselableitung und **neunzehn** verschlüsselte Befehle gegen die
  Originalfassung unter Node — byteweise gleich. Dazu neun unzulässige Werte,
  die abgewiesen werden müssen, ein kleingeschriebener Keycode und der Fall
  „kein Keycode eingetragen“, der nichts unverschlüsselt hinausgehen lässt.
- Der vollständige Ablauf gegen eine Attrappe, die die **Originalfassung** als
  Gegenstelle benutzt: `GET_IPCONTROL_STATE`, `CURRENT_APP`, `POWER off`,
  `KEY_ACTION`. Alle Antworten richtig gelesen.
- Ein **falscher Keycode** führt zu einem Fehler und nicht zu einer stillen
  Falschaussage. Das war zuerst anders: die Abfrage der IP-Steuerung meldete
  `aus`, obwohl die Antwort nur unlesbar war. Der Fehler ist behoben und der
  Selbsttest prüft ihn.
- Ein vollständiger Dienstlauf mit Zustandsdatei und geordnetem Abbruch.
- Die Oberfläche unter PHP 8.1: Erstaufruf, Speichern, und dass ungültige
  Eingaben abgewiesen werden — eine IP mit `;` und einem Shell-Befehl, eine zu
  kurze MAC, ein zu kurzer Keycode, Werte außerhalb der Grenzen.
- Der Aktionsendpunkt: ohne Token, mit falschem Token, mit unbekannter Aktion
  und mit einem eingeschleusten Shell-Befehl im Wert — alles abgewiesen.
- Die Loxone-Vorlage auf CRLF, Tabulatoren und Attributreihenfolge.

### Am Gerät erprobt — 30.07.2026

Bis Version 1.0.1 war die Gegenstelle in allen Prüfungen eine Attrappe. Das ist
vorbei: die Fassung läuft an einem **echten LG-Beamer** und einer **echten
Xbox Series X** im Zusammenspiel mit einem Loxone Miniserver. Erprobt sind alle
vier Schaltwege und die Rückmeldung:

| Weg | Ergebnis |
|---|---|
| Beamer **aus** über die verschlüsselte IP-Steuerung (TCP 9761) | läuft |
| Beamer **ein** per Wake-on-LAN, ausgelöst von Loxone selbst | läuft |
| Xbox **wecken** über den Cloud-Dienst von Microsoft | läuft |
| Xbox **ausschalten** | läuft |
| Zustandsmeldungen per MQTT an den Miniserver | kommen richtig an |

Damit ist auch belegt, was vorher nur begründet war: dass das nachgebaute
Verschlüsselungsverfahren von einem echten Gerät angenommen wird, und dass der
Cloud-Weg eine Konsole weckt, die auf das alte Weckpaket auf UDP 5050 nicht mehr
reagiert.

## Installation

Über *Plugin-Verwaltung → Plugin installieren* das ZIP oder die
Release-Adresse angeben. Danach Reiter *Test → Umgebung prüfen*.

## Lizenz

MIT. Siehe `LICENSE`.

## Änderungen in 1.2.0

**Sicherung beim Update.** `preupgrade.sh` legte `heimkino.cfg` und
`xbox_auth.json` unter `/tmp` ab — auf dem LoxBerry eine Ramdisk. Erzwingt die
Paketinstallation dazwischen einen Neustart oder fällt der Strom aus, ist beides
weg: der Keycode des Beamers, das Aktionstoken und vor allem die
Azure-Refresh-Token, deren Verlust die komplette Microsoft-Anmeldung von vorn
bedeutet. Gesichert wird jetzt nach `data/plugins/<Ordner>/upgrade_sicherung`.
Der alte Ort wird beim Update von 1.1.1 noch mitgelesen.

*Nicht übernommen* wurde der Vorschlag, statt dessen das Installationsverzeichnis
`$1` zu nehmen: das liegt unter `/tmp/uploads` und damit auf **derselben**
Ramdisk. Der Vorschlag hebt sich selbst auf — Bestand hat nur, was auf der Karte
liegt.

**Stiller Verlust der Xbox-Anmeldung.** `hk_xbox_auth_schreiben()` prüfte nur
`file_put_contents(...) === false`. `json_encode` liefert bei ungültigem UTF-8
aber `false`, und `file_put_contents($pfad, false)` schreibt 0 Bytes und gibt 0
zurück — nicht `false`. Die Prüfung hätte das für einen Erfolg gehalten und
`rename()` die geleerte Datei über die gültige gezogen. Dieselbe Datenverlust-
Folge wie oben, nur ohne Stromausfall.

**Prozesserkennung.** `pgrep -f "hk_service.py"` und `pkill -f` trafen jeden
Prozess, in dessen Kommandozeile die Zeichenkette irgendwo vorkam. Jetzt legt
der Dienst eine PID-Datei an, und geprüft wird über `/proc/<pid>/cmdline` —
**argumentweise**, nicht als Teilzeichenkette. Beim Erproben trat der Fehlerfall
prompt auf: die Kommandozeile eines fremden Prozesses enthielt den Namen, weil
er dort als Text vorkam. Von sieben geprüften Fällen liegt die alte Suche in
vier daneben, die neue in keinem.

**MQTT.**

- Der Dienst startet beim Systemstart, der Broker ist dann oft noch nicht so
  weit. Scheiterte der erste `connect()`, wurde `aktiv` dauerhaft auf `False`
  gesetzt — das Plugin meldete bis zum nächsten Neustart nichts, ohne dass
  irgendwo etwas dazu stand. Jetzt `connect_async` mit `reconnect_delay_set`,
  und nach jeder Wiederkehr werden **alle** Werte erneut gesendet: beim Neustart
  des Brokers sind die zurückbehaltenen Werte weg.
- Eine leere Nutzlast mit `retain` **löscht** den zurückbehaltenen Wert (MQTT
  3.1.1, Abschnitt 3.3.1.3). Betroffen waren `beamer/app` (leer, solange keine
  App läuft), `last_error` (fast immer leer) und `xbox/geheimnis_tage`. Wer sich
  später mit dem Broker verband, sah für diese Themen gar nichts. Jetzt steht
  dort ein Bindestrich.
- Der Themenpräfix wird gefiltert: ein `#` oder `+` daraus wäre ein
  MQTT-Platzhalter und im Thema unzulässig. Und er wird zur Laufzeit
  nachgezogen — bisher sendete der Dienst nach einer Änderung weiter unter dem
  alten Präfix, während die Oberfläche den neuen anzeigte.

**Weiteres.** `random_int()` kann eine Ausnahme werfen; abgefangen wurde sie
nicht, und die Oberfläche brach dann mitten im Speichern ab. Ein Rückfall auf
`mt_rand` wurde bewusst **nicht** eingebaut — dieses Token schützt den einzigen
schaltenden Endpunkt. Zwischendateien beim Schreiben tragen jetzt die
Prozessnummer statt eines festen `.neu`, und die Zustandsdatei wird mit `fsync`
abgesichert.

**Oberfläche.** Reiter als echte Verweise mit serverseitig gesetztem
`sm-active` — bis 1.1.1 setzte das ausschließlich JavaScript, und ohne
JavaScript stand jeder Bereich auf `display:none`: die Seite war leer.
37 Bedienelemente haben `data-role="none"` bekommen.

### Nicht bestätigt

- **Python-Pakete gehören in `dpkg/apt`.** Sie stehen dort bereits, seit dem
  ersten Release: `python3-cryptography`, `python3-paho-mqtt`,
  `python3-requests`. Der Block in `postinstall.sh` ist keine
  Installationsanweisung, sondern eine **Nachkontrolle** — ein gescheiterter
  apt-Lauf fällt sonst erst Wochen später auf. Die Formulierung war allerdings
  missverständlich und liest sich jetzt eindeutig als Ausnahmefall.

### Offen

Nichts aus dieser Liste — die Zweisprachigkeit, die hier bis 1.2.11 als offen
stand, ist mit 1.2.12 erledigt. Was bleibt, steht oben unter *Nicht behoben*:
die fehlende gemeinsame Sperre zwischen Dienst und Aktionsendpunkt.
