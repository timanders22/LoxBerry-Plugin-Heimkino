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


def _kopf(*zusatz):
    """Kopfzeilen zusammensetzen: Grundlage plus das Uebergebene."""
    kopf = dict(KOPF_BASIS)
    for teil in zusatz:
        if teil:
            kopf.update(teil)
    return kopf


class XboxFehler(Exception):
    pass


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
    return "%s (%d): %s" % (was, antwort.status_code, text[:300])


def _requests():
    try:
        import requests
    except ImportError as fehler:
        raise XboxFehler(
            "python3-requests fehlt. Nachinstallieren: "
            "sudo apt-get install -y python3-requests") from fehler
    return requests


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
        os.makedirs(os.path.dirname(self.pfad), exist_ok=True)
        vorlaeufig = self.pfad + ".neu"
        with open(vorlaeufig, "w", encoding="utf-8") as datei:
            json.dump(self.daten, datei, indent=1)
        os.chmod(vorlaeufig, 0o600)
        os.replace(vorlaeufig, self.pfad)

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
        """
        text = (text or "").strip()
        if not text:
            raise XboxFehler("Keine Adresse und kein Code angegeben.")
        if "code=" not in text:
            return text  # war offenbar schon der Code selbst
        zerlegt = urllib.parse.urlparse(text)
        felder = urllib.parse.parse_qs(zerlegt.query or zerlegt.fragment or "")
        if "error" in felder:
            raise XboxFehler("Microsoft hat die Anmeldung abgelehnt: %s"
                             % felder.get("error_description", felder["error"])[0])
        if "code" not in felder:
            raise XboxFehler("In der Adresse steckt kein Code.")
        return felder["code"][0]

    def code_einloesen(self, code_oder_adresse):
        requests = _requests()
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
        antwort = requests.post(self.dienst["token"], data=daten,
                                headers=_kopf(),
                                timeout=self.zeitgrenze)
        self._token_uebernehmen(antwort)
        return True

    def _erneuern(self):
        requests = _requests()
        if not self.daten.get("refresh_token"):
            raise XboxFehler("Noch nicht angemeldet.")
        daten = {
            "client_id": self.daten["client_id"],
            "refresh_token": self.daten["refresh_token"],
            "grant_type": "refresh_token",
            "scope": self.bereich,
        }
        if self.daten.get("client_secret"):
            daten["client_secret"] = self.daten["client_secret"]
        antwort = requests.post(self.dienst["token"], data=daten,
                                headers=_kopf(),
                                timeout=self.zeitgrenze)
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
            raise XboxFehler("Microsoft antwortete mit %d: %s"
                             % (antwort.status_code, text))
        try:
            inhalt = antwort.json()
        except ValueError as fehler:
            raise XboxFehler("Antwort von Microsoft war kein JSON.") from fehler
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
        requests = _requests()
        zugriff = self._zugriffstoken()

        benutzer = requests.post(
            USER_AUTH_URL,
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
            raise XboxFehler("Benutzertoken abgelehnt (%d): %s"
                             % (benutzer.status_code, benutzer.text[:300]))
        benutzertoken = benutzer.json()["Token"]

        xsts = requests.post(
            XSTS_URL,
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
            raise XboxFehler("XSTS-Token abgelehnt (%d): %s"
                             % (xsts.status_code, xsts.text[:300]))
        inhalt = xsts.json()
        self.daten["xsts_token"] = inhalt["Token"]
        self.daten["userhash"] = inhalt["DisplayClaims"]["xui"][0]["uhs"]
        # Gueltigkeit steht in der Antwort, wird aber sicherheitshalber
        # auf hoechstens acht Stunden begrenzt.
        self.daten["xsts_bis"] = time.time() + 8 * 3600
        self._speichern()
        return "XBL3.0 x=%s;%s" % (self.daten["userhash"], self.daten["xsts_token"])

    # ---------------- Schritt 4: Befehle ----------------

    def _kopfzeilen(self):
        return _kopf(KOPF_SG, {"Authorization": self._xbl_kopf()})

    def konsolen(self):
        """Alle mit dem Konto verbundenen Konsolen auflisten."""
        requests = _requests()
        antwort = requests.get(
            XCCS_URL + "/lists/devices",
            params={"queryCurrentDevice": "false", "includeStorageDevices": "false"},
            headers=self._kopfzeilen(), timeout=self.zeitgrenze)
        if antwort.status_code != 200:
            raise XboxFehler(_fehlertext(antwort, "Konsolenliste abgelehnt"))
        ergebnis = []
        for geraet in antwort.json().get("result", []):
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
        requests = _requests()
        try:
            antwort = requests.get(
                "%s/consoles/%s" % (XCCS_URL, urllib.parse.quote(geraete_id)),
                headers=self._kopfzeilen(), timeout=self.zeitgrenze)
            if antwort.status_code != 200:
                raise XboxFehler(_fehlertext(antwort, "Statusabfrage abgelehnt"))
            inhalt = antwort.json()
            return {
                "status": inhalt.get("powerState", "Unknown"),
                "name": inhalt.get("name", ""),
                "erreichbar": inhalt.get("powerState") not in (None, "", "Unknown"),
                "quelle": "consoles",
            }
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

    def _befehl(self, geraete_id, art, befehl, parameter=None):
        requests = _requests()
        if not geraete_id:
            raise XboxFehler("Keine XBOX-Netzwerk-Geraeteidentitaet eingetragen. "
                             "Sie steht an der Konsole unter Einstellungen, "
                             "System, Konsoleninfo - oder der Reiter Test "
                             "ermittelt sie ueber Konsolen suchen.")
        koerper = {
            "destination": "Xbox",
            "type": art,
            "command": befehl,
            "sessionId": self.daten.setdefault("session_id", _neue_session()),
            "sourceId": "com.microsoft.smartglass",
            "parameters": parameter or [{}],
            "linkedXboxId": geraete_id,
        }
        antwort = requests.post(XCCS_URL + "/commands", json=koerper,
                                headers=self._kopfzeilen(),
                                timeout=self.zeitgrenze)
        if antwort.status_code not in (200, 202):
            raise XboxFehler(_fehlertext(
                antwort, "Befehl %s/%s abgelehnt" % (art, befehl)))
        return True

    def wecken(self, geraete_id):
        return self._befehl(geraete_id, "Power", "WakeUp")

    def ausschalten(self, geraete_id):
        return self._befehl(geraete_id, "Power", "TurnOff")


def _neue_session():
    import uuid
    return str(uuid.uuid4())
