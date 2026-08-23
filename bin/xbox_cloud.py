#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Heimkino - Xbox ueber den Cloud-Dienst von Microsoft wecken

Warum nicht ueber das Netz vor Ort: das unauthentifizierte Weckpaket auf
UDP 5050, das Xbox-One-Konsolen annahmen, wird von neueren Firmwarestaenden
ignoriert. Nachgemessen an einer Series X - Paketinhalt, Zieladresse und
Geraetekennung waren nachweislich richtig, die Konsole reagierte nicht. Die
Xbox-App weckt dieselbe Konsole ohne Weiteres, und die geht ueber die Cloud.
Also geht dieses Plugin denselben Weg.

Der Preis: es braucht eine eigene App-Registrierung bei Microsoft, eine
einmalige Anmeldung und eine Internetverbindung. Ohne Netz nach draussen
laesst sich die Konsole nicht wecken - eine unangenehme, aber ehrliche
Eigenschaft dieses Wegs.

Anmeldekette (wie bei OpenXbox/xbox-webapi-python)
--------------------------------------------------
1. OAuth2 bei login.live.com  -> Zugriffs- und Erneuerungstoken
2. user.auth.xboxlive.com     -> Benutzertoken
3. xsts.auth.xboxlive.com     -> XSTS-Token und Benutzerkennung
4. xccs.xboxlive.com/commands -> der eigentliche Befehl

Schritt 2 und 3 werden bei jedem Ablauf wiederholt; Schritt 1 nur, wenn das
Erneuerungstoken nicht mehr zieht - dann ist eine neue Anmeldung faellig.
"""

import json
import os
import time
import urllib.parse

# Zwei Anmeldedienste. Welcher zieht, haengt daran, wie die Anwendung
# registriert wurde:
#   live - die alten Endpunkte. Nehmen Registrierungen aus dem
#          Live-App-Portal an und in vielen Faellen auch Entra-Registrierungen
#          mit dem Kontotyp "nur persoenliche Microsoft-Konten".
#   v2   - die Endpunkte von Microsoft Entra. Manche Registrierungen
#          akzeptieren ihr Geheimnis nur hier; bei den anderen antwortet
#          login.live.com mit invalid_client, obwohl das Geheimnis stimmt.
# Umschaltbar ueber den Eintrag "dienst" in xbox_auth.json.
DIENSTE = {
    "live": {
        "anmelden": "https://login.live.com/oauth20_authorize.srf",
        "token": "https://login.live.com/oauth20_token.srf",
        "bereich": "Xboxlive.signin Xboxlive.offline_access",
    },
    "v2": {
        "anmelden": "https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize",
        "token": "https://login.microsoftonline.com/consumers/oauth2/v2.0/token",
        "bereich": "XboxLive.signin offline_access",
    },
}

ANMELDE_URL = DIENSTE["live"]["anmelden"]
TOKEN_URL = DIENSTE["live"]["token"]
USER_AUTH_URL = "https://user.auth.xboxlive.com/user/authenticate"
XSTS_URL = "https://xsts.auth.xboxlive.com/xsts/authorize"
XCCS_URL = "https://xccs.xboxlive.com"
BEREICH = "Xboxlive.signin Xboxlive.offline_access"
RUECKLEITUNG_VORGABE = "http://localhost/auth/callback"

# Vor der eigentlichen Schnittstelle sitzt ein Azure Application Gateway. Es
# weist Anfragen ohne brauchbare Kopfzeilen ab - und zwar mit einer
# HTML-Seite "403 Forbidden", nicht mit einer Fehlermeldung der Schnittstelle.
# Insbesondere reicht die Vorgabe von python-requests als User-Agent nicht.
# Diese Kopfzeilen gehen deshalb an jede Anfrage.
KOPF_BASIS = {
    "User-Agent": ("Mozilla/5.0 (XboxReplay; XboxLiveAPI/3.1) "
                   "AppleWebKit/537.36 (KHTML, like Gecko) "
                   "Chrome/71.0.3578.98 Safari/537.36"),
    "Accept": "application/json",
    "Accept-Language": "de-DE, de, en-US, en",
    "Accept-Encoding": "gzip, deflate",
}

KOPF_SG = {
    "x-xbl-contract-version": "4",
    "skillplatform": "RemoteManagement",
}

# Die haeufigsten XSTS-Abweisungen im Klartext. Der nackte Zahlencode im
# Antwortrumpf sagt niemandem etwas, und die Ursache liegt jedes Mal am
# Konto - nicht an der Anmeldung, die bis dahin funktioniert hat.
XERR_TEXT = {
    "2148916233": "Zu diesem Microsoft-Konto gehoert kein Xbox-Profil. Einmal "
                  "auf xbox.com anmelden und ein Profil anlegen, dann hier "
                  "erneut anmelden.",
    "2148916235": "Xbox Live ist im Land dieses Kontos nicht verfuegbar.",
    "2148916236": "Das Konto verlangt eine zusaetzliche Bestaetigung "
                  "(Erwachsenenpruefung).",
    "2148916238": "Das Konto gehoert einem Kind und muss einer Familie "
                  "zugeordnet sein.",
}


def _kopf(*zusatz):
    """Kopfzeilen zusammensetzen: Grundlage plus das Uebergebene."""
    kopf = dict(KOPF_BASIS)
    for teil in zusatz:
        if teil:
            kopf.update(teil)
    return kopf


class XboxFehler(Exception):
    pass


class XboxAnmeldungAbgelaufen(XboxFehler):
    """Das Erneuerungstoken zieht nicht mehr - es ist eine neue Anmeldung faellig."""


def _fehlertext(antwort, was):
    """Eine Fehlermeldung, die sagt, wer geantwortet hat.

    Kommt HTML zurueck, hat nicht die Xbox-Schnittstelle geantwortet, sondern
    das Gateway davor. Das ist ein anderer Fehler und braucht eine andere
    Suche - deshalb wird es unterschieden.
    """
    text = (antwort.text or "").strip()
    if text[:1] == "<" or "<html" in text[:200].lower():
        return ("%s: Das Gateway vor der Xbox-Schnittstelle hat die Anfrage "
                "abgewiesen (HTTP %d) und eine HTML-Seite geschickt statt einer "
                "Antwort der Schnittstelle. Das liegt nicht an der Anmeldung - "
                "die hat funktioniert, sonst waeren wir nicht so weit gekommen - "
                "sondern an den Kopfzeilen der Anfrage oder an einer Sperre. "
                "Wenn dieses Plugin aktuell ist, sind die Kopfzeilen gesetzt; "
                "dann hilft es, es spaeter noch einmal zu versuchen."
                % (was, antwort.status_code))
    for code, satz in XERR_TEXT.items():
        if code in text:
            return "%s (%d): %s (XErr %s)" % (was, antwort.status_code, satz, code)
    return "%s (%d): %s" % (was, antwort.status_code, text[:300])


def _requests():
    try:
        import requests
    except ImportError as fehler:
        raise XboxFehler(
            "python3-requests fehlt. Nachinstallieren: "
            "sudo apt-get install -y python3-requests") from fehler
    return requests


def _ruf(methode, url, was, **argumente):
    """Eine HTTP-Anfrage mit uebersetzten Netzfehlern.

    Bis 1.2.11 wurde hier gar nichts abgefangen. Ohne Internetverbindung
    lautete die Meldung woertlich "HTTPSConnectionPool(host='login.live.com',
    port=443): Max retries exceeded ... Temporary failure in name
    resolution" - und diese Zeile ging als HTTP-Antwort an den Miniserver.
    Die Regel "eine Meldung muss sagen, WER nicht geantwortet hat" war fuer
    den Beamer vorbildlich umgesetzt und fuer die Xbox gar nicht.
    """
    requests = _requests()
    ziel = urllib.parse.urlparse(url).netloc or url
    try:
        return getattr(requests, methode)(url, **argumente)
    except requests.exceptions.SSLError as fehler:
        raise XboxFehler(
            "%s: Die verschluesselte Verbindung zu %s kam nicht zustande (%s). "
            "Haeufigste Ursache auf einem LoxBerry: die Uhr geht falsch - ein "
            "Zertifikat gilt dann als noch nicht gueltig." % (was, ziel, fehler)
        ) from fehler
    except requests.exceptions.ConnectTimeout as fehler:
        raise XboxFehler(
            "%s: %s hat innerhalb der Zeitgrenze nicht geantwortet. Steht die "
            "Internetverbindung?" % (was, ziel)) from fehler
    except requests.exceptions.ReadTimeout as fehler:
        raise XboxFehler(
            "%s: %s hat die Verbindung angenommen, aber nicht geantwortet."
            % (was, ziel)) from fehler
    except requests.exceptions.ConnectionError as fehler:
        text = str(fehler)
        if "Name or service not known" in text or "Temporary failure in name" in text:
            grund = ("Der Name %s laesst sich nicht aufloesen. Der LoxBerry "
                     "erreicht keinen Namensdienst - haeufigste Ursache ist "
                     "eine fehlende Internetverbindung." % ziel)
        else:
            grund = "Zu %s kam keine Verbindung zustande (%s)." % (ziel, text[:200])
        raise XboxFehler("%s: %s" % (was, grund)) from fehler
    except requests.exceptions.RequestException as fehler:
        raise XboxFehler("%s: Anfrage an %s fehlgeschlagen (%s)."
                         % (was, ziel, str(fehler)[:200])) from fehler


def _json_oder_fehler(antwort, was):
    """JSON aus einer Antwort holen - oder sagen, was stattdessen kam."""
    try:
        return antwort.json()
    except ValueError as fehler:
        text = (antwort.text or "").strip()
        if text[:1] == "<" or "<html" in text[:200].lower():
            raise XboxFehler(
                "%s: Es kam eine HTML-Seite zurueck, keine Antwort der "
                "Schnittstelle - davor sitzt ein Gateway. Die Anmeldung selbst "
                "ist damit nicht das Problem." % was) from fehler
        raise XboxFehler("%s: Die Antwort war kein JSON (%s)."
                         % (was, text[:200])) from fehler


class XboxCloud:
    """Zugang zum Fernsteuerungsdienst von Xbox Live.

    Alle Token liegen in einer eigenen Datei mit Rechten 0600 - nicht in der
    Plugin-Konfiguration, die die Oberflaeche anzeigt.
    """

    def __init__(self, auth_pfad, log=None, zeitgrenze=15.0):
        self.pfad = auth_pfad
        self.log = log
        self.zeitgrenze = zeitgrenze
        self.daten = self._laden()

    # ---------------- Ablage ----------------

    def _laden(self):
        try:
            with open(self.pfad, "r", encoding="utf-8") as datei:
                return json.load(datei)
        except (OSError, ValueError):
            return {}

    def _speichern(self):
        """Die Tokendatei unteilbar und von Anfang an geschuetzt schreiben.

        Drei Aenderungen gegenueber 1.2.11, alle an derselben Datei - der
        wertvollsten des Plugins, denn ihr Verlust bedeutet die komplette
        Microsoft-Anmeldung von vorn:

        1. Der Nebenname traegt die Prozessnummer. Fest ".neu" hiess: der
           Dienst (XSTS-Erneuerung) und ein hk_cmd-Aufruf aus dem
           Aktionsendpunkt konnten gleichzeitig schreiben, und os.replace zog
           eine Mischung ueber die gueltige Datei. In hk_common war genau das
           schon behoben, hier nicht.
        2. Die Rechte 0600 stehen am ANLEGEN, nicht dahinter. Ein chmod nach
           dem Schreiben laesst client_secret und refresh_token fuer die
           Dauer des Schreibens mit den Vorgaben der umask dastehen.
        3. flush + fsync vor dem Umbenennen. Ohne sie steht nach einem
           Stromausfall der Name schon da und der Inhalt noch im Puffer.
        """
        ordner = os.path.dirname(self.pfad)
        if ordner:
            os.makedirs(ordner, exist_ok=True)
        vorlaeufig = "%s.%d.neu" % (self.pfad, os.getpid())
        try:
            text = json.dumps(self.daten, indent=1)
        except (TypeError, ValueError) as fehler:
            raise XboxFehler("Die Anmeldedaten liessen sich nicht in JSON "
                             "umsetzen (%s) - es wurde NICHTS geschrieben, "
                             "die bisherige Datei bleibt unangetastet."
                             % fehler) from fehler
        try:
            kennung = os.open(vorlaeufig,
                              os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
            with os.fdopen(kennung, "w", encoding="utf-8") as datei:
                datei.write(text)
                datei.flush()
                os.fsync(datei.fileno())
            os.replace(vorlaeufig, self.pfad)
        except OSError as fehler:
            try:
                os.unlink(vorlaeufig)
            except OSError:
                pass
            raise XboxFehler("Die Anmeldedatei %s liess sich nicht schreiben "
                             "(%s)." % (self.pfad, fehler)) from fehler

    @property
    def dienst(self):
        name = str(self.daten.get("dienst", "live")).strip().lower()
        return DIENSTE.get(name, DIENSTE["live"])

    @property
    def bereich(self):
        return self.dienst["bereich"]

    @property
    def eingerichtet(self):
        return bool(self.daten.get("client_id"))

    @property
    def angemeldet(self):
        return bool(self.daten.get("refresh_token"))

    def app_hinterlegen(self, client_id, client_secret, rueckleitung=None):
        client_id = (client_id or "").strip()
        if not client_id:
            raise XboxFehler("Ohne Anwendungskennung (Client-ID) geht es nicht.")
        self.daten["client_id"] = client_id
        self.daten["client_secret"] = (client_secret or "").strip()
        self.daten["redirect_uri"] = (rueckleitung or "").strip() or RUECKLEITUNG_VORGABE
        self._speichern()

    def vergessen(self):
        """Anmeldung loeschen, App-Registrierung behalten."""
        for schluessel in ("refresh_token", "access_token", "access_bis",
                           "xsts_token", "userhash", "xsts_bis"):
            self.daten.pop(schluessel, None)
        self._speichern()

    # ---------------- Schritt 1: OAuth2 ----------------

    def anmeldeadresse(self):
        if not self.eingerichtet:
            raise XboxFehler("Erst die Anwendungskennung hinterlegen.")
        werte = {
            "client_id": self.daten["client_id"],
            "response_type": "code",
            "approval_prompt": "auto",
            "scope": self.bereich,
            "redirect_uri": self.daten.get("redirect_uri", RUECKLEITUNG_VORGABE),
        }
        return self.dienst["anmelden"] + "?" + urllib.parse.urlencode(werte)

    @staticmethod
    def code_aus_adresse(text):
        """Den Code aus der zurueckgeleiteten Adresse holen.

        Bequemlichkeit mit Absicht: die Adresse laesst sich aus der
        Adresszeile kopieren, der Code allein muesste herausgesucht werden.

        Die Fehlerpruefung steht VOR der Abkuerzung "kein code= darin, also
        war es wohl schon der Code". Bis 1.2.11 war es umgekehrt: wer die
        Abbruch-Rueckleitung mit ?error=access_denied einsetzte, bekam nicht
        "Microsoft hat die Anmeldung abgelehnt", sondern die ganze Adresse
        wurde als Code verschickt - und darauf ein nichtssagendes
        invalid_grant.
        """
        text = (text or "").strip()
        if not text:
            raise XboxFehler("Keine Adresse und kein Code angegeben.")
        zerlegt = urllib.parse.urlparse(text)
        felder = urllib.parse.parse_qs(zerlegt.query or zerlegt.fragment or "")
        if "error" in felder:
            beschreibung = felder.get("error_description") or felder["error"]
            raise XboxFehler("Microsoft hat die Anmeldung abgelehnt: %s"
                             % beschreibung[0])
        if "code" in felder:
            return felder["code"][0]
        if "code=" in text:
            raise XboxFehler("In der Adresse steckt kein lesbarer Code.")
        return text  # war offenbar schon der Code selbst

    def code_einloesen(self, code_oder_adresse):
        code = self.code_aus_adresse(code_oder_adresse)
        daten = {
            "client_id": self.daten["client_id"],
            "code": code,
            "grant_type": "authorization_code",
            "redirect_uri": self.daten.get("redirect_uri", RUECKLEITUNG_VORGABE),
            "scope": self.bereich,
        }
        if self.daten.get("client_secret"):
            daten["client_secret"] = self.daten["client_secret"]
        antwort = _ruf("post", self.dienst["token"], "Anmeldung", data=daten,
                       headers=_kopf(), timeout=self.zeitgrenze)
        self._token_uebernehmen(antwort)
        return True

    def _erneuern(self):
        if not self.daten.get("refresh_token"):
            raise XboxAnmeldungAbgelaufen("Noch nicht angemeldet.")
        daten = {
            "client_id": self.daten["client_id"],
            "refresh_token": self.daten["refresh_token"],
            "grant_type": "refresh_token",
            "scope": self.bereich,
        }
        if self.daten.get("client_secret"):
            daten["client_secret"] = self.daten["client_secret"]
        antwort = _ruf("post", self.dienst["token"], "Token erneuern", data=daten,
                       headers=_kopf(), timeout=self.zeitgrenze)
        self._token_uebernehmen(antwort)

    def _token_uebernehmen(self, antwort):
        if antwort.status_code != 200:
            text = antwort.text[:400]
            if "invalid_client" in text:
                raise XboxFehler(
                    "Microsoft weist die Anwendung ab (invalid_client). Das "
                    "Geheimnis stimmt nicht. Haeufigste Ursache: aus der Tabelle "
                    "unter 'Zertifikate & Geheimnisse' wurde die Spalte "
                    "'Geheime ID' kopiert statt der Spalte 'Wert'. Zweite "
                    "Ursache: das Geheimnis ist abgelaufen (Spalte 'Gueltig "
                    "bis'). Der Reiter Test, Knopf 'Anmeldedaten pruefen', sagt, "
                    "welche Form das gespeicherte Geheimnis hat. Bleibt es dabei, "
                    "in xbox_auth.json \"dienst\": \"v2\" setzen und die "
                    "Anmeldung wiederholen. Antwort im Original: %s" % text)
            if "invalid_grant" in text:
                # Bis 1.2.11 fiel dieser Fall in den allgemeinen Zweig. Das
                # tote Erneuerungstoken blieb stehen, angemeldet lieferte
                # weiter True, und xbox/angemeldet meldete dauerhaft 1 -
                # waehrend jeder Befehl scheiterte. Jetzt wird es verworfen,
                # damit die Oberflaeche und Loxone die Wahrheit sehen.
                self.vergessen()
                raise XboxAnmeldungAbgelaufen(
                    "Microsoft nimmt das Erneuerungstoken nicht mehr an "
                    "(invalid_grant). Das passiert nach einem Passwortwechsel, "
                    "nach dem Entzug der Zustimmung oder nach langer "
                    "Untaetigkeit. Die gespeicherte Anmeldung wurde deshalb "
                    "verworfen - im Reiter Einstellungen die Anmeldeseite "
                    "erneut oeffnen und den Code eintragen. Anwendungskennung "
                    "und Geraeteidentitaet bleiben unveraendert.")
            raise XboxFehler("Microsoft antwortete mit %d: %s"
                             % (antwort.status_code, text))
        inhalt = _json_oder_fehler(antwort, "Antwort von Microsoft")
        if "access_token" not in inhalt:
            raise XboxFehler("In der Antwort fehlt das Zugriffstoken: %s"
                             % str(inhalt)[:300])
        self.daten["access_token"] = inhalt["access_token"]
        self.daten["access_bis"] = time.time() + int(inhalt.get("expires_in", 3600)) - 120
        if inhalt.get("refresh_token"):
            self.daten["refresh_token"] = inhalt["refresh_token"]
        # Die XSTS-Kette haengt am Zugriffstoken und wird ungueltig.
        self.daten.pop("xsts_token", None)
        self.daten.pop("xsts_bis", None)
        self._speichern()

    def _zugriffstoken(self):
        if not self.daten.get("access_token") or \
                time.time() >= float(self.daten.get("access_bis", 0)):
            self._erneuern()
        return self.daten["access_token"]

    # ---------------- Schritt 2 und 3: Xbox Live ----------------

    def _xbl_kopf(self):
        if self.daten.get("xsts_token") and \
                time.time() < float(self.daten.get("xsts_bis", 0)):
            return "XBL3.0 x=%s;%s" % (self.daten["userhash"],
                                       self.daten["xsts_token"])
        zugriff = self._zugriffstoken()

        benutzer = _ruf(
            "post", USER_AUTH_URL, "Benutzertoken",
            json={
                "RelyingParty": "http://auth.xboxlive.com",
                "TokenType": "JWT",
                "Properties": {
                    "AuthMethod": "RPS",
                    "SiteName": "user.auth.xboxlive.com",
                    "RpsTicket": "d=" + zugriff,
                },
            },
            headers=_kopf({"x-xbl-contract-version": "1"}),
            timeout=self.zeitgrenze)
        if benutzer.status_code != 200:
            # _fehlertext statt des rohen Rumpfes: sonst wandert im Ernstfall
            # eine halbe HTML-Seite in eine Meldung, die bis nach Loxone geht.
            raise XboxFehler(_fehlertext(benutzer, "Benutzertoken abgelehnt"))
        inhalt = _json_oder_fehler(benutzer, "Benutzertoken")
        if not isinstance(inhalt, dict) or not inhalt.get("Token"):
            raise XboxFehler("In der Antwort des Benutzertokens fehlt das Feld "
                             "Token: %s" % str(inhalt)[:200])
        benutzertoken = inhalt["Token"]

        xsts = _ruf(
            "post", XSTS_URL, "XSTS-Token",
            json={
                "RelyingParty": "http://xboxlive.com",
                "TokenType": "JWT",
                "Properties": {
                    "UserTokens": [benutzertoken],
                    "SandboxId": "RETAIL",
                },
            },
            headers=_kopf({"x-xbl-contract-version": "1"}),
            timeout=self.zeitgrenze)
        if xsts.status_code != 200:
            raise XboxFehler(_fehlertext(xsts, "XSTS-Token abgelehnt"))
        inhalt = _json_oder_fehler(xsts, "XSTS-Token")
        try:
            token = inhalt["Token"]
            userhash = inhalt["DisplayClaims"]["xui"][0]["uhs"]
        except (TypeError, KeyError, IndexError) as fehler:
            raise XboxFehler(
                "Die XSTS-Antwort hat nicht den erwarteten Aufbau - es fehlt "
                "Token oder DisplayClaims.xui[0].uhs: %s"
                % str(inhalt)[:200]) from fehler
        self.daten["xsts_token"] = token
        self.daten["userhash"] = userhash
        # Gueltigkeit steht in der Antwort, wird aber sicherheitshalber
        # auf hoechstens acht Stunden begrenzt.
        self.daten["xsts_bis"] = time.time() + 8 * 3600
        self._speichern()
        return "XBL3.0 x=%s;%s" % (self.daten["userhash"], self.daten["xsts_token"])

    # ---------------- Schritt 4: Befehle ----------------

    def _kopfzeilen(self):
        return _kopf(KOPF_SG, {"Authorization": self._xbl_kopf()})

    def _sitzung(self):
        """Sitzungskennung - einmal erzeugt und dann behalten.

        Bis 1.2.11 stand hier setdefault(...) ohne anschliessendes Speichern.
        Da jeder hk_cmd-Aufruf ein eigener Prozess ist, schickte praktisch
        jeder Befehl eine neue sessionId; gespeichert wurde sie nur zufaellig.
        """
        vorhanden = str(self.daten.get("session_id", "")).strip()
        if vorhanden:
            return vorhanden
        import uuid
        neu = str(uuid.uuid4())
        self.daten["session_id"] = neu
        self._speichern()
        return neu

    def konsolen(self):
        """Alle mit dem Konto verbundenen Konsolen auflisten."""
        antwort = _ruf(
            "get", XCCS_URL + "/lists/devices", "Konsolenliste",
            params={"queryCurrentDevice": "false", "includeStorageDevices": "false"},
            headers=self._kopfzeilen(), timeout=self.zeitgrenze)
        if antwort.status_code != 200:
            raise XboxFehler(_fehlertext(antwort, "Konsolenliste abgelehnt"))
        inhalt = _json_oder_fehler(antwort, "Konsolenliste")
        ergebnis = []
        for geraet in (inhalt.get("result") or []):
            if not isinstance(geraet, dict):
                continue
            ergebnis.append({
                "id": geraet.get("id", ""),
                "name": geraet.get("name", ""),
                "typ": geraet.get("consoleType", ""),
                "status": geraet.get("powerState", ""),
            })
        return ergebnis

    def status(self, geraete_id):
        """Zustand der Konsole.

        Erst der direkte Weg ueber /consoles/<id>. Weist das Gateway ihn ab -
        beobachtet mit 403 und einer HTML-Seite, waehrend Befehle und
        Konsolenliste einwandfrei durchgingen -, wird der Zustand aus der
        Konsolenliste gelesen. Sie enthaelt powerState fuer jede Konsole,
        liefert also dieselbe Auskunft auf einem anderen Weg.
        """
        try:
            antwort = _ruf(
                "get", "%s/consoles/%s" % (XCCS_URL, urllib.parse.quote(geraete_id)),
                "Statusabfrage",
                headers=self._kopfzeilen(), timeout=self.zeitgrenze)
            if antwort.status_code != 200:
                raise XboxFehler(_fehlertext(antwort, "Statusabfrage abgelehnt"))
            inhalt = _json_oder_fehler(antwort, "Statusabfrage")
            return {
                "status": inhalt.get("powerState", "Unknown"),
                "name": inhalt.get("name", ""),
                "erreichbar": inhalt.get("powerState") not in (None, "", "Unknown"),
                "quelle": "consoles",
            }
        except XboxAnmeldungAbgelaufen:
            # Eine abgelaufene Anmeldung ist kein Fall fuer den Ersatzweg -
            # der scheitert an derselben Stelle. Sonst wuerde aus dem
            # Ersatzweg unbemerkt der Normalfall.
            raise
        except XboxFehler as erster:
            return self._status_aus_liste(geraete_id, erster)

    def _status_aus_liste(self, geraete_id, erster):
        """Ersatzweg: den Zustand aus der Konsolenliste herausziehen."""
        try:
            liste = self.konsolen()
        except XboxFehler:
            raise erster from None
        gesucht = (geraete_id or "").strip().lower()
        for eintrag in liste:
            if (eintrag.get("id") or "").strip().lower() == gesucht:
                zustand = eintrag.get("status") or "Unknown"
                if self.log is not None:
                    self.log.info("Xbox: die direkte Statusabfrage wurde "
                                  "abgewiesen, der Zustand kommt aus der "
                                  "Konsolenliste (Ersatzweg).")
                return {
                    "status": zustand,
                    "name": eintrag.get("name", ""),
                    "erreichbar": zustand not in (None, "", "Unknown"),
                    "quelle": "liste",
                }
        namen = ", ".join(e.get("id", "?") for e in liste) or "keine"
        raise XboxFehler(
            "Die Konsole %s steht nicht in der Liste des Kontos. Vorhanden: %s. "
            "Stimmt die XBOX-Netzwerk-Geraeteidentitaet?" % (geraete_id, namen))

    def roh(self, geraete_id):
        """Die Antwort der Cloud im Original.

        Das Plugin liest davon bisher nur powerState und name. Was sonst
        drinsteht, weiss niemand, der es nicht angesehen hat - und aus einer
        Vermutung ein MQTT-Thema zu machen waere die falsche Reihenfolge.
        Dieser Knopf ist der ehrliche erste Schritt: erst messen, dann
        festlegen, was hinausgeht.

        Gibt beide Wege zurueck, den direkten und die Konsolenliste, damit
        sichtbar ist, welcher gerade traegt.
        """
        aus = {"consoles": None, "consoles_fehler": None,
               "liste": None, "liste_fehler": None}
        try:
            antwort = _ruf(
                "get", "%s/consoles/%s" % (XCCS_URL, urllib.parse.quote(geraete_id)),
                "Statusabfrage", headers=self._kopfzeilen(), timeout=self.zeitgrenze)
            if antwort.status_code != 200:
                aus["consoles_fehler"] = _fehlertext(antwort, "Statusabfrage abgelehnt")
            else:
                aus["consoles"] = _json_oder_fehler(antwort, "Statusabfrage")
        except XboxFehler as fehler:
            aus["consoles_fehler"] = str(fehler)
        try:
            antwort = _ruf(
                "get", XCCS_URL + "/lists/devices", "Konsolenliste",
                params={"queryCurrentDevice": "false",
                        "includeStorageDevices": "false"},
                headers=self._kopfzeilen(), timeout=self.zeitgrenze)
            if antwort.status_code != 200:
                aus["liste_fehler"] = _fehlertext(antwort, "Konsolenliste abgelehnt")
            else:
                aus["liste"] = _json_oder_fehler(antwort, "Konsolenliste")
        except XboxFehler as fehler:
            aus["liste_fehler"] = str(fehler)
        return aus

    def _befehl(self, geraete_id, art, befehl, parameter=None):
        if not geraete_id:
            raise XboxFehler("Keine XBOX-Netzwerk-Geraeteidentitaet eingetragen. "
                             "Sie steht an der Konsole unter Einstellungen, "
                             "System, Konsoleninfo - oder der Reiter Test "
                             "ermittelt sie ueber Konsolen suchen.")
        koerper = {
            "destination": "Xbox",
            "type": art,
            "command": befehl,
            "sessionId": self._sitzung(),
            "sourceId": "com.microsoft.smartglass",
            "parameters": parameter or [{}],
            "linkedXboxId": geraete_id,
        }
        antwort = _ruf("post", XCCS_URL + "/commands",
                       "Befehl %s/%s" % (art, befehl), json=koerper,
                       headers=self._kopfzeilen(), timeout=self.zeitgrenze)
        if antwort.status_code not in (200, 202):
            raise XboxFehler(_fehlertext(
                antwort, "Befehl %s/%s abgelehnt" % (art, befehl)))
        return True

    def wecken(self, geraete_id):
        return self._befehl(geraete_id, "Power", "WakeUp")

    def ausschalten(self, geraete_id):
        return self._befehl(geraete_id, "Power", "TurnOff")


# --------------------------------------------------------------------------
# Selbsttest - ohne Konto, ohne Netz
#
# Bis 1.2.12 gab es fuer die Anmeldekette gar keinen. Der LG-Teil hat einen
# seit dem ersten Release; die Xbox-Seite liess sich ohne echtes
# Microsoft-Konto ueberhaupt nicht pruefen. Geprueft wird hier, was ohne
# Gegenstelle pruefbar IST: die Kopfzeilen, die Einordnung fremder Antworten
# und der Umgang mit der Rueckleitungsadresse.
# --------------------------------------------------------------------------

class _Antwort:
    """Eine Antwort nachstellen, ohne requests zu brauchen."""

    def __init__(self, code, text, json_daten=None):
        self.status_code = code
        self.text = text
        self._json = json_daten

    def json(self):
        if self._json is None:
            raise ValueError("kein JSON")
        return self._json


def selbsttest():
    fehler = 0

    def pruefe(name, bedingung, zusatz=""):
        nonlocal fehler
        if bedingung:
            print("ok     %s" % name)
        else:
            print("FEHLER %s %s" % (name, zusatz))
            fehler += 1

    # 1. Kopfzeilen. Vor der Schnittstelle sitzt ein Gateway, das die Vorgabe
    #    von python-requests als User-Agent abweist - und zwar mit einer
    #    HTML-Seite, nicht mit einer Fehlermeldung.
    kopf = _kopf(KOPF_SG, {"Authorization": "XBL3.0 x=1;2"})
    for name in ("User-Agent", "Accept", "Accept-Language", "Accept-Encoding"):
        pruefe("Kopfzeile %s gesetzt" % name, name in kopf)
    pruefe("User-Agent ist nicht die Vorgabe von requests",
           "python-requests" not in kopf["User-Agent"])
    pruefe("Zusatzkopf x-xbl-contract-version durchgereicht",
           kopf.get("x-xbl-contract-version") == "4")
    pruefe("Authorization durchgereicht", kopf.get("Authorization") == "XBL3.0 x=1;2")
    pruefe("_kopf veraendert die Grundlage nicht",
           "Authorization" not in KOPF_BASIS)

    # 2. Einordnung fremder Antworten.
    html = _fehlertext(_Antwort(403, "<html><body>403 Forbidden</body></html>"), "X")
    pruefe("HTML wird als Gateway-Antwort erkannt", "Gateway" in html)
    pruefe("und die Anmeldung ausdruecklich entlastet", "nicht an der Anmeldung" in html)
    json_text = _fehlertext(_Antwort(400, '{"code":123}'), "X")
    pruefe("JSON wird NICHT als Gateway-Antwort erkannt", "Gateway" not in json_text)
    xerr = _fehlertext(_Antwort(401, '{"XErr":2148916233}'), "X")
    pruefe("XErr 2148916233 wird uebersetzt", "Xbox-Profil" in xerr)

    # 3. Rueckleitungsadresse.
    try:
        XboxCloud.code_aus_adresse("http://localhost/auth/callback?code=M.C1_BAY.2.abc")
        p = XboxCloud.code_aus_adresse(
            "http://localhost/auth/callback?code=M.C1_BAY.2.abc") == "M.C1_BAY.2.abc"
    except XboxFehler:
        p = False
    pruefe("Code aus der Adresse geholt", p)
    pruefe("blanker Code bleibt unveraendert",
           XboxCloud.code_aus_adresse("M.C1_BAY.2.abc") == "M.C1_BAY.2.abc")
    for text, was in (("", "leere Eingabe"),
                      ("http://localhost/auth/callback?error=access_denied"
                       "&error_description=Der+Benutzer+hat+abgebrochen",
                       "Abbruch durch den Benutzer")):
        try:
            XboxCloud.code_aus_adresse(text)
            pruefe("abgewiesen: %s" % was, False)
        except XboxFehler:
            pruefe("abgewiesen: %s" % was, True)

    # 4. Unteilbares Schreiben mit Rechten 0600 SCHON BEIM ANLEGEN.
    import tempfile
    with tempfile.TemporaryDirectory() as ordner:
        pfad = os.path.join(ordner, "xbox_auth.json")
        wolke = XboxCloud(pfad)
        wolke.daten = {"client_id": "abc", "refresh_token": "geheim"}
        wolke._speichern()
        pruefe("Anmeldedatei angelegt", os.path.isfile(pfad))
        if os.name == "posix":
            rechte = os.stat(pfad).st_mode & 0o777
            pruefe("Rechte 0600", rechte == 0o600, "(ist %o)" % rechte)
        else:
            print("grau   Rechte 0600 - unter Windows nicht pruefbar")
        pruefe("keine Zwischendatei liegengeblieben",
               os.listdir(ordner) == ["xbox_auth.json"])
        pruefe("Inhalt wieder lesbar",
               XboxCloud(pfad).daten.get("refresh_token") == "geheim")
        # Nicht kodierbare Daten duerfen die gueltige Datei NICHT anfassen.
        kaputt = XboxCloud(pfad)
        kaputt.daten = {"x": object()}
        try:
            kaputt._speichern()
            pruefe("nicht kodierbare Daten abgewiesen", False)
        except XboxFehler:
            pruefe("nicht kodierbare Daten abgewiesen", True)
        pruefe("die gueltige Datei blieb dabei unangetastet",
               XboxCloud(pfad).daten.get("refresh_token") == "geheim")

    # 5. Sitzungskennung: einmal erzeugt, dann behalten.
    with tempfile.TemporaryDirectory() as ordner:
        pfad = os.path.join(ordner, "xbox_auth.json")
        wolke = XboxCloud(pfad)
        eins = wolke._sitzung()
        zwei = wolke._sitzung()
        pruefe("Sitzungskennung bleibt im selben Lauf gleich", eins == zwei)
        pruefe("Sitzungskennung ueberlebt den Prozess",
               XboxCloud(pfad)._sitzung() == eins)

    if fehler:
        print("\n%d Abweichung(en)." % fehler)
        return 1
    print("\nAlle Pruefungen bestanden (ohne Konto, ohne Netz).")
    return 0


if __name__ == "__main__":
    import sys
    if len(sys.argv) > 1 and sys.argv[1] == "--selbsttest":
        sys.exit(selbsttest())
    print("Aufruf: xbox_cloud.py --selbsttest")
    sys.exit(2)
