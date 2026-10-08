#!/usr/bin/env python3
"""Mammotion -> MQTT Bridge für LoxBerry.

Meldet sich mit dem Mammotion-Konto in der Cloud an (über pymammotion),
überwacht alle Mähroboter des Kontos und veröffentlicht Status und Probleme
per MQTT auf dem LoxBerry-Broker. Das MQTT Gateway des LoxBerry leitet die
Werte an den Miniserver weiter.

Topics (Basis-Topic standardmäßig "mammotion", <dev> = bereinigter Gerätename):
    <base>/problem                    1 = irgendein Problem (Sammelmeldung)
    <base>/problem_text               Text der Sammelmeldung
    <base>/bridge/status              online / offline (Last Will)
    <base>/bridge/connected           1 = Cloud-Login OK
    <base>/<dev>/online               1/0
    <base>/<dev>/battery              Akkustand in %
    <base>/<dev>/charging             1 = lädt
    <base>/<dev>/mode                 numerischer Gerätestatus (WorkMode)
    <base>/<dev>/mode_text            Gerätestatus als Text
    <base>/<dev>/progress             Mähfortschritt in %
    <base>/<dev>/problem              1 = Problem
    <base>/<dev>/problem_text         Beschreibung des Problems
    <base>/<dev>/error_code           letzter Fehlercode (0 = keiner)
    <base>/<dev>/error_text           Text zum letzten Fehlercode
    <base>/<dev>/error_solution       Lösungsvorschlag (falls bekannt)
    <base>/<dev>/error_time           Unix-Zeit des letzten Fehlers
    <base>/<dev>/last_report          Unix-Zeit der letzten Statusmeldung

Befehle (Payload egal bzw. "reset"):
    <base>/cmd/reset                  alle gespeicherten Fehler quittieren
    <base>/<dev>/cmd/reset            Fehler eines Geräts quittieren
    <base>/cmd/refresh                Status bei allen Geräten anfordern
"""

from __future__ import annotations

import argparse
import asyncio
import json
import logging
from logging.handlers import RotatingFileHandler
import os
import re
import signal
import socket
import sys
import time
from typing import Any

import paho.mqtt.client as mqtt

from pymammotion.client import MammotionClient
from pymammotion.data.error_codes import describe, solution
from pymammotion.utility.constant.display import device_mode
from pymammotion.utility.device_type import DeviceType

PLUGIN = "mammotion"
LBHOMEDIR = os.environ.get("LBHOMEDIR", "/opt/loxberry")

DEFAULT_CONFIG: dict[str, Any] = {
    "enabled": True,
    "account": "",
    "password": "",
    "language": "de",
    "topic": "mammotion",
    # WorkMode-Werte, die als Problem gelten:
    # 17 gesperrt, 18 Systemfehler, 23 Update fehlgeschlagen,
    # 37 Positionsfehler, 38 Grenzüberschreitung
    "problem_modes": [17, 18, 23, 37, 38],
    "error_hold_minutes": 60,
    "offline_problem_minutes": 30,
    "republish_seconds": 300,
    "refresh_seconds": 300,
    "loglevel": "INFO",
    "mqtt": {
        "use_loxberry": True,
        "host": "localhost",
        "port": 1883,
        "user": "",
        "password": "",
    },
}

MODE_TEXT_DE = {
    0: "Inaktiv",
    1: "Online",
    2: "Offline",
    3: "Ausgeschaltet",
    8: "Deaktiviert",
    10: "Initialisierung",
    11: "Bereit",
    12: "Nicht verbunden",
    13: "Mäht",
    14: "Fährt zur Station",
    15: "Lädt",
    16: "Update läuft",
    17: "Gesperrt",
    18: "Systemfehler",
    19: "Pausiert",
    20: "Manuelles Mähen",
    22: "Update erfolgreich",
    23: "Update fehlgeschlagen",
    31: "Karte zeichnen",
    32: "Hindernis zeichnen",
    34: "Kanal zeichnen",
    36: "Grenze bearbeiten",
    37: "Positionsfehler",
    38: "Grenzüberschreitung",
    39: "Ladepause",
    45: "Automatische Kartierung",
    46: "Kartierung pausiert",
    47: "Kartierung: Rückkehr",
    48: "Schlafmodus",
    50: "Rückkehr zum Laden",
    51: "Setzt zurück",
    52: "Wiederherstellung",
}

# Modi, in denen der Mäher offensichtlich wieder normal arbeitet -> gespeicherter Fehler wird quittiert
RECOVERED_MODES = {13, 20}

log = logging.getLogger("mammotion_bridge")


# ----------------------------------------------------------------------------
# Hilfsfunktionen
# ----------------------------------------------------------------------------


def deep_merge(base: dict, override: dict) -> dict:
    result = dict(base)
    for key, value in (override or {}).items():
        if isinstance(value, dict) and isinstance(result.get(key), dict):
            result[key] = deep_merge(result[key], value)
        else:
            result[key] = value
    return result


def load_config(path: str) -> dict:
    try:
        with open(path, encoding="utf-8") as fh:
            return deep_merge(DEFAULT_CONFIG, json.load(fh))
    except FileNotFoundError:
        log.warning("Konfiguration %s nicht gefunden – verwende Standardwerte", path)
        return dict(DEFAULT_CONFIG)


def loxberry_mqtt_settings() -> dict:
    """Broker-Zugangsdaten des LoxBerry (ab LB 3.0 in general.json, sonst MQTT-Gateway-Plugin)."""
    general = f"{LBHOMEDIR}/config/system/general.json"
    try:
        with open(general, encoding="utf-8") as fh:
            m = json.load(fh).get("Mqtt") or {}
        if m.get("Brokerhost"):
            return {
                "host": m.get("Brokerhost", "localhost"),
                "port": int(m.get("Brokerport") or 1883),
                "user": m.get("Brokeruser", ""),
                "password": m.get("Brokerpass", ""),
            }
    except (OSError, ValueError):
        pass
    # Altes MQTT-Gateway-Plugin (LoxBerry 2.x)
    try:
        gw_dir = f"{LBHOMEDIR}/config/plugins/mqttgateway"
        with open(f"{gw_dir}/mqtt.json", encoding="utf-8") as fh:
            addr = json.load(fh)["Main"]["brokeraddress"]
        with open(f"{gw_dir}/cred.json", encoding="utf-8") as fh:
            cred = json.load(fh)["Credentials"]
        host, _, port = addr.partition(":")
        return {
            "host": host or "localhost",
            "port": int(port or 1883),
            "user": cred.get("brokeruser", ""),
            "password": cred.get("brokerpass", ""),
        }
    except (OSError, ValueError, KeyError):
        pass
    log.warning("Keine LoxBerry-MQTT-Zugangsdaten gefunden – verwende localhost:1883 ohne Login")
    return {"host": "localhost", "port": 1883, "user": "", "password": ""}


def topic_key(name: str) -> str:
    """Gerätename -> Topic-tauglicher Schlüssel (MQTT Gateway macht daraus Loxone-Eingangsnamen)."""
    return re.sub(r"[^a-z0-9]+", "_", name.lower()).strip("_") or "device"


# ----------------------------------------------------------------------------
# MQTT
# ----------------------------------------------------------------------------


class MqttPublisher:
    def __init__(self, cfg: dict, base: str, loop: asyncio.AbstractEventLoop, on_command) -> None:
        self.base = base
        self.loop = loop
        self.on_command = on_command
        self._cache: dict[str, str] = {}
        settings = loxberry_mqtt_settings() if cfg["mqtt"].get("use_loxberry", True) else cfg["mqtt"]
        self.host = settings.get("host") or "localhost"
        self.port = int(settings.get("port") or 1883)
        self.client = mqtt.Client(
            mqtt.CallbackAPIVersion.VERSION2,
            client_id=f"loxberry-mammotion-{socket.gethostname()}",
        )
        if settings.get("user"):
            self.client.username_pw_set(settings["user"], settings.get("password") or None)
        self.client.will_set(f"{base}/bridge/status", "offline", qos=1, retain=True)
        self.client.on_connect = self._on_connect
        self.client.on_disconnect = self._on_disconnect
        self.client.on_message = self._on_message
        self.client.reconnect_delay_set(min_delay=2, max_delay=60)

    def start(self) -> None:
        log.info("Verbinde mit MQTT-Broker %s:%s", self.host, self.port)
        self.client.connect_async(self.host, self.port, keepalive=60)
        self.client.loop_start()

    def stop(self) -> None:
        try:
            self.client.publish(f"{self.base}/bridge/status", "offline", qos=1, retain=True).wait_for_publish(3)
        except Exception:  # noqa: BLE001
            pass
        self.client.disconnect()
        self.client.loop_stop()

    def _on_connect(self, client, userdata, flags, reason_code, properties) -> None:
        if reason_code.is_failure:
            log.error("MQTT-Verbindung abgelehnt: %s", reason_code)
            return
        log.info("MQTT verbunden")
        client.publish(f"{self.base}/bridge/status", "online", qos=1, retain=True)
        client.subscribe([(f"{self.base}/cmd/#", 0), (f"{self.base}/+/cmd/#", 0)])
        # Nach (Re-)Connect alles neu senden
        for topic, payload in list(self._cache.items()):
            client.publish(topic, payload, qos=0, retain=True)

    def _on_disconnect(self, client, userdata, flags, reason_code, properties) -> None:
        if reason_code != 0:
            log.warning("MQTT-Verbindung getrennt (%s) – versuche Reconnect", reason_code)

    def _on_message(self, client, userdata, msg) -> None:
        rel = msg.topic[len(self.base) + 1 :] if msg.topic.startswith(self.base + "/") else msg.topic
        payload = msg.payload.decode("utf-8", "replace").strip()
        self.loop.call_soon_threadsafe(self.on_command, rel, payload)

    def publish(self, sub_topic: str, value: Any, force: bool = False) -> None:
        topic = f"{self.base}/{sub_topic}"
        if isinstance(value, bool):
            payload = "1" if value else "0"
        elif value is None:
            payload = ""
        else:
            payload = str(value)
        if not force and self._cache.get(topic) == payload:
            return
        self._cache[topic] = payload
        log.debug("MQTT %s = %s", topic, payload)
        self.client.publish(topic, payload, qos=0, retain=True)

    def republish_all(self) -> None:
        for topic, payload in list(self._cache.items()):
            self.client.publish(topic, payload, qos=0, retain=True)


# ----------------------------------------------------------------------------
# Gerätezustand
# ----------------------------------------------------------------------------


class MowerState:
    def __init__(self, name: str) -> None:
        self.name = name
        self.key = topic_key(name)
        self.baseline_error: tuple[int, int] | None = None  # (code, zeitstempel) beim Start
        self.error_code = 0
        self.error_time = 0
        self.error_source = ""
        self.offline_since: float | None = None
        self.last_values: dict[str, Any] = {}
        self.subscriptions: list[Any] = []

    def set_error(self, code: int, when: float, source: str) -> None:
        self.error_code = abs(int(code))
        self.error_time = int(when)
        self.error_source = source
        log.warning("%s: Fehler %s (%s) – %s", self.name, self.error_code, source, describe(self.error_code, "de"))

    def clear_error(self, reason: str) -> None:
        if self.error_code:
            log.info("%s: Fehler %s quittiert (%s)", self.name, self.error_code, reason)
        self.error_code = 0
        self.error_time = 0
        self.error_source = ""


class Bridge:
    def __init__(self, cfg: dict, status_file: str) -> None:
        self.cfg = cfg
        self.status_file = status_file
        self.base = cfg["topic"].strip("/") or "mammotion"
        self.lang = cfg.get("language", "de")
        self.problem_modes = {int(m) for m in cfg.get("problem_modes", [])}
        self.loop = asyncio.get_running_loop()
        self.mqtt = MqttPublisher(cfg, self.base, self.loop, self.handle_command)
        self.client: MammotionClient | None = None
        self.mowers: dict[str, MowerState] = {}
        self.connected = False
        self.bridge_problem = ""
        self.relogin_requested = asyncio.Event()
        self.stop_event = asyncio.Event()
        self.last_refresh = 0.0
        self.last_republish = time.time()

    # --- Lebenszyklus ---------------------------------------------------------

    async def run(self) -> None:
        self.mqtt.start()
        self.publish_bridge()
        backoff = 60
        while not self.stop_event.is_set():
            try:
                await self.login()
                backoff = 60
                await self.watch()
            except asyncio.CancelledError:
                raise
            except Exception as exc:  # noqa: BLE001
                log.exception("Cloud-Verbindung fehlgeschlagen")
                self.connected = False
                self.bridge_problem = f"Mammotion-Cloud nicht erreichbar: {exc}"[:200]
                self.publish_bridge()
                self.write_status()
            await self.shutdown_client()
            if self.stop_event.is_set():
                break
            log.info("Neuer Login-Versuch in %s s", backoff)
            try:
                await asyncio.wait_for(self.stop_event.wait(), timeout=backoff)
            except TimeoutError:
                pass
            # Vorsichtiger Backoff – zu viele Logins können zur Kontosperre führen
            backoff = min(backoff * 2, 3600)
        await self.shutdown_client()
        self.connected = False
        self.publish_bridge()
        self.mqtt.stop()

    async def login(self) -> None:
        account, password = self.cfg.get("account"), self.cfg.get("password")
        if not account or not password:
            raise RuntimeError("Mammotion-Konto oder Passwort nicht konfiguriert")
        log.info("Login bei der Mammotion-Cloud als %s", account)
        self.client = MammotionClient()
        self.client.on_unrecoverable_auth_error = self._on_auth_error
        self.client.on_account_in_use_changed = self._on_account_in_use
        await self.client.login_and_initiate_cloud(account, password)
        self.connected = True
        self.bridge_problem = ""
        self.relogin_requested.clear()
        self.attach_devices()
        if not self.mowers:
            log.warning("Keine Mähroboter im Konto gefunden")
        self.publish_bridge()

    async def shutdown_client(self) -> None:
        for state in self.mowers.values():
            for sub in state.subscriptions:
                try:
                    sub.cancel()
                except Exception:  # noqa: BLE001
                    pass
            state.subscriptions.clear()
        if self.client is not None:
            try:
                await asyncio.wait_for(self.client.stop(), timeout=20)
            except Exception:  # noqa: BLE001
                log.debug("Fehler beim Stoppen des Clients", exc_info=True)
            self.client = None

    async def _on_auth_error(self, account_id: str, transport_type: Any, exc: Exception) -> None:
        log.error("Authentifizierung dauerhaft fehlgeschlagen (%s): %s", transport_type, exc)
        self.connected = False
        self.bridge_problem = "Mammotion-Login abgelaufen/abgelehnt"
        self.publish_bridge()
        self.relogin_requested.set()

    async def _on_account_in_use(self, account_id: str, held: bool) -> None:
        if held:
            log.info("Mammotion-App hält gerade die Cloud-Verbindung (Konto in Verwendung)")
        else:
            log.info("Mammotion-App hat die Cloud-Verbindung freigegeben")

    def attach_devices(self) -> None:
        assert self.client is not None
        registry = getattr(self.client, "_device_registry", None)
        handles = list(getattr(registry, "all_devices", []) or [])
        for handle in handles:
            name = handle.device_name
            raw = handle.snapshot.raw
            if DeviceType.is_rtk(name) or not hasattr(raw, "report_data"):
                log.info("Überspringe %s (kein Mähroboter)", name)
                continue
            state = self.mowers.get(name) or MowerState(name)
            self.mowers[name] = state
            state.subscriptions.append(handle.subscribe_state_changed(self._make_state_handler(name)))
            state.subscriptions.append(handle.subscribe_device_event(self._make_event_handler(name)))
            log.info("Überwache %s (Topic %s/%s)", name, self.base, state.key)

    def _make_state_handler(self, name: str):
        async def _handler(_snapshot) -> None:
            self.evaluate(name)

        return _handler

    def _make_event_handler(self, name: str):
        async def _handler(event) -> None:
            params = getattr(event, "params", None)
            if isinstance(params, dict):
                identifier, value = params.get("identifier"), params.get("value")
            else:
                identifier, value = getattr(params, "identifier", None), getattr(params, "value", None)
            if identifier in ("device_warning_event", "device_warning_code_event"):
                code = value.get("code") if isinstance(value, dict) else getattr(value, "code", None)
                if code:
                    self.mowers[name].set_error(code, time.time(), identifier)
                    self.evaluate(name)

        return _handler

    async def watch(self) -> None:
        """Läuft solange der Login gültig ist."""
        while not self.stop_event.is_set() and not self.relogin_requested.is_set():
            now = time.time()
            refresh = int(self.cfg.get("refresh_seconds") or 0)
            if refresh > 0 and now - self.last_refresh >= refresh:
                self.last_refresh = now
                await self.request_refresh(max_age=refresh)
            for name in list(self.mowers):
                self.evaluate(name)
            if now - self.last_republish >= int(self.cfg.get("republish_seconds") or 300):
                self.last_republish = now
                self.mqtt.republish_all()
            self.write_status()
            try:
                await asyncio.wait_for(self.relogin_requested.wait(), timeout=30)
            except TimeoutError:
                pass
        if self.relogin_requested.is_set() and not self.stop_event.is_set():
            raise RuntimeError("Neuanmeldung erforderlich")

    async def request_refresh(self, max_age: float = 0) -> None:
        if self.client is None:
            return
        for name in list(self.mowers):
            try:
                await self.client.ensure_fresh_state(name, max_age_s=max_age)
            except Exception as exc:  # noqa: BLE001
                log.debug("Statusabfrage %s fehlgeschlagen: %s", name, exc)

    # --- Auswertung -----------------------------------------------------------

    def evaluate(self, name: str) -> None:
        if self.client is None:
            return
        handle = self.client.mower(name)
        state = self.mowers.get(name)
        if handle is None or state is None:
            return
        snap = handle.snapshot
        raw = snap.raw
        now = time.time()
        dev = raw.report_data.dev
        mode = int(dev.sys_status or 0)
        online = bool(snap.online)
        hold = int(self.cfg.get("error_hold_minutes") or 0) * 60

        # Fehlerhistorie des Geräts (neuester Eintrag zuerst) – nur NEUE Einträge melden
        errors = getattr(raw, "errors", None)
        codes = list(getattr(errors, "err_code_list", []) or [])
        stamps = list(getattr(errors, "err_code_list_time", []) or [])
        if codes and codes[0]:
            head = (int(codes[0]), int(stamps[0]) if stamps else 0)
            if state.baseline_error is None:
                state.baseline_error = head
            elif head != state.baseline_error:
                state.baseline_error = head
                state.set_error(head[0], now, "Fehlerliste")
        elif state.baseline_error is None and codes:
            state.baseline_error = (0, 0)

        # Gespeicherten Fehler automatisch quittieren
        if state.error_code:
            if hold and now - state.error_time > hold:
                state.clear_error("Haltezeit abgelaufen")
            elif mode in RECOVERED_MODES and now - state.error_time > 120:
                state.clear_error("Mäher arbeitet wieder")

        # Offline-Überwachung
        if online:
            state.offline_since = None
        elif state.offline_since is None:
            state.offline_since = now

        reasons = []
        mode_text = self.mode_text(mode)
        if mode in self.problem_modes:
            reasons.append(f"Status: {mode_text}")
        if state.error_code:
            reasons.append(f"Fehler {state.error_code}: {describe(state.error_code, self.lang)}")
        offline_limit = int(self.cfg.get("offline_problem_minutes") or 0) * 60
        if offline_limit and state.offline_since and now - state.offline_since >= offline_limit:
            reasons.append(f"Offline seit {int((now - state.offline_since) // 60)} min")

        work = raw.report_data.work
        values = {
            "online": online,
            "battery": int(snap.battery_level or dev.battery_val or 0),
            "charging": bool(dev.charge_state),
            "mode": mode,
            "mode_text": mode_text,
            "progress": int(getattr(work, "mow_percent", 0) or 0),
            "problem": bool(reasons),
            "problem_text": "; ".join(reasons) if reasons else "OK",
            "error_code": state.error_code,
            "error_text": describe(state.error_code, self.lang) if state.error_code else "",
            "error_solution": solution(state.error_code, self.lang) if state.error_code else "",
            "error_time": state.error_time,
            "last_report": int(now - handle.report_data_age) if handle.last_report_data_at else 0,
        }
        if values.get("problem") != state.last_values.get("problem"):
            if values["problem"]:
                log.warning("%s: PROBLEM – %s", name, values["problem_text"])
            elif state.last_values:
                log.info("%s: Problem behoben", name)
        state.last_values = values
        for key, value in values.items():
            self.mqtt.publish(f"{state.key}/{key}", value)
        self.publish_summary()

    def mode_text(self, mode: int) -> str:
        if self.lang == "de" and mode in MODE_TEXT_DE:
            return MODE_TEXT_DE[mode]
        return device_mode(mode)

    def publish_bridge(self) -> None:
        self.mqtt.publish("bridge/connected", self.connected)
        self.mqtt.publish("bridge/problem", bool(self.bridge_problem))
        self.mqtt.publish("bridge/problem_text", self.bridge_problem or "OK")
        self.publish_summary()

    def publish_summary(self) -> None:
        texts = []
        if self.bridge_problem:
            texts.append(self.bridge_problem)
        for state in self.mowers.values():
            if state.last_values.get("problem"):
                texts.append(f"{state.name}: {state.last_values['problem_text']}")
        self.mqtt.publish("problem", bool(texts))
        self.mqtt.publish("problem_text", " | ".join(texts) if texts else "OK")

    # --- Befehle --------------------------------------------------------------

    def handle_command(self, rel_topic: str, payload: str) -> None:
        parts = rel_topic.split("/")
        if parts[:2] == ["cmd", "reset"]:
            for state in self.mowers.values():
                state.clear_error("MQTT-Befehl")
        elif parts[:2] == ["cmd", "refresh"]:
            self.loop.create_task(self.request_refresh())
        elif len(parts) >= 3 and parts[1:3] == ["cmd", "reset"]:
            for state in self.mowers.values():
                if state.key == parts[0]:
                    state.clear_error("MQTT-Befehl")
        else:
            log.debug("Unbekannter Befehl %s (%s)", rel_topic, payload)
            return
        for name in list(self.mowers):
            self.evaluate(name)

    # --- Status für Weboberfläche --------------------------------------------

    def write_status(self) -> None:
        data = {
            "updated": int(time.time()),
            "base_topic": self.base,
            "connected": self.connected,
            "bridge_problem": self.bridge_problem,
            "devices": {
                s.name: {"key": s.key, "values": s.last_values} for s in self.mowers.values()
            },
        }
        tmp = self.status_file + ".tmp"
        try:
            with open(tmp, "w", encoding="utf-8") as fh:
                json.dump(data, fh, ensure_ascii=False, indent=1)
            os.replace(tmp, self.status_file)
        except OSError:
            log.debug("Statusdatei konnte nicht geschrieben werden", exc_info=True)


# ----------------------------------------------------------------------------
# main
# ----------------------------------------------------------------------------


def setup_logging(logfile: str, level: str) -> None:
    os.makedirs(os.path.dirname(logfile), exist_ok=True)
    handler = RotatingFileHandler(logfile, maxBytes=1_000_000, backupCount=3, encoding="utf-8")
    handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)-7s %(name)s: %(message)s"))
    root = logging.getLogger()
    root.handlers[:] = [handler]
    root.setLevel(logging.WARNING)
    log.setLevel(getattr(logging, level.upper(), logging.INFO))
    # pymammotion nur bei DEBUG gesprächig
    logging.getLogger("pymammotion").setLevel(logging.DEBUG if level.upper() == "DEBUG" else logging.WARNING)


async def amain(args: argparse.Namespace) -> int:
    cfg = load_config(args.config)
    setup_logging(args.logfile, cfg.get("loglevel", "INFO"))
    log.info("Mammotion-Bridge startet (pid %s)", os.getpid())
    bridge = Bridge(cfg, args.status)
    loop = asyncio.get_running_loop()
    def _request_stop() -> None:
        bridge.stop_event.set()
        bridge.relogin_requested.set()  # weckt die Überwachungsschleife sofort auf

    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, _request_stop)
    bridge_task = asyncio.create_task(bridge.run())
    await bridge.stop_event.wait()
    log.info("Beende Mammotion-Bridge")
    try:
        await asyncio.wait_for(bridge_task, timeout=30)
    except (TimeoutError, asyncio.CancelledError):
        bridge_task.cancel()
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--config", default=f"{LBHOMEDIR}/config/plugins/{PLUGIN}/mammotion.json")
    parser.add_argument("--status", default=f"{LBHOMEDIR}/data/plugins/{PLUGIN}/status.json")
    parser.add_argument("--logfile", default=f"{LBHOMEDIR}/log/plugins/{PLUGIN}/mammotion.log")
    args = parser.parse_args()
    return asyncio.run(amain(args))


if __name__ == "__main__":
    sys.exit(main())
