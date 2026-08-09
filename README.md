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
  vergleicht Schlüsselableitung und fünf verschlüsselte Befehle mit Werten, die
  die Originalfassung unter Node erzeugt hat. Zusätzlich wurde gegen eine
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

| Thema | Bedeutung |
|---|---|
| `service/online` | 1 = der Dienst läuft |
| `last_error` | letzte Fehlermeldung, sonst leer |
| `beamer/aktiv` | 1 = der Beamer ist in den Einstellungen eingeschaltet |
| `beamer/erreichbar` | 1 = der Beamer antwortet auf Port 9761 |
| `beamer/status` | `an`, `aus` oder `unbekannt` |
| `beamer/an` | 1 = der Beamer läuft |
| `beamer/app` | laufende Quelle, z. B. `hdmi1` |
| `xbox/aktiv` | 1 = die Xbox ist in den Einstellungen eingeschaltet |
| `xbox/status` | Zustandstext der Cloud, z. B. `On` oder `ConnectedStandby` |
| `xbox/an` | 1 = die Konsole läuft |
| `xbox/angemeldet` | 1 = die Anmeldung bei Microsoft ist gültig |
| `xbox/geheimnis_ablauf` | Ablaufdatum des Clientgeheimnisses, `JJJJ-MM-TT` |
| `xbox/geheimnis_tage` | Tage bis zum Ablauf; negativ = abgelaufen, leer = kein Datum hinterlegt |

Der Reiter *Einbindung in Loxone* liefert eine Vorlage für die virtuellen
Eingänge zum Einlesen in Loxone Config.

### Schalten — virtuelle Ausgänge

Der Miniserver ruft eine Adresse im unangemeldeten Bereich auf. Damit das nicht
jedes Gerät im Netz kann, steckt in jeder Adresse ein **Aktionstoken**, das beim
ersten Speichern erzeugt wird. Der Reiter *Einbindung in Loxone* zeigt die
fertigen Adressen.

```
Adresse des Ausgangs:  http://<loxberry>
Befehl bei EIN:        /plugins/heimkino/index.php?token=<TOKEN>&aktion=beamer-aus
```

Erlaubte Aktionen: `beamer-aus`, `beamer-wol`, `xbox-an`, `xbox-aus` sowie
`beamer-taste` und `beamer-eingang` mit `&wert=…`. Alles andere wird abgewiesen;
der Wert darf nur Buchstaben, Ziffern und Unterstrich enthalten.

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

- Schlüsselableitung und fünf verschlüsselte Befehle gegen die Originalfassung
  unter Node — byteweise gleich.
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

Die Oberfläche ist weiterhin überwiegend deutsch: den Sprachdateien fehlen die
Texte für rund 300 fest eingetragene Stellen. Das ist bewusst **nicht**
maschinell nachgezogen worden — ein automatischer Durchlauf hat in einem anderen
Plugin dieser Sammlung Array-Schlüssel und schließende Klammern in die
Sprachdatei gezogen und dabei die englische Oberfläche zerstört. Die
Reiterbeschriftungen, die Legenden und die Statusmeldungen sind zweisprachig.

