# LoxBerry-Plugin: Mammotion Mähroboter → MQTT → Miniserver

Überwacht Mammotion-Mähroboter (Luba, Luba 2, Yuka, …) über die Mammotion-Cloud
und meldet Status und **Probleme** per MQTT. Das MQTT Gateway des LoxBerry
schickt die Werte an den Loxone Miniserver.

## Voraussetzungen

* LoxBerry **3.x** mit aktivem MQTT (MQTT Gateway)
* Internetzugang am LoxBerry (das Plugin lädt bei der Installation Python 3.13 + Pakete)
* **Empfohlen: 64-Bit-LoxBerry** (aarch64 oder x86_64). Unter 32-Bit-ARM (armv7l) gibt es für
  einige Pakete keine fertigen Builds – die Installation kann dort sehr lange dauern oder scheitern.
* Ein Mammotion-Konto. **Tipp:** Lege ein zweites Konto an und teile den Mäher in der App damit.
  Dann stören sich App und Plugin nicht gegenseitig.

> ⚠️ Das Plugin nutzt die inoffizielle Bibliothek [pymammotion](https://github.com/mikey0000/PyMammotion)
> (Basis der Home-Assistant-Integration). Mammotion untersagt laut AGB inoffiziellen API-Zugriff;
> theoretisch ist eine Kontosperre möglich. Das Plugin meldet sich deshalb sparsam an
> (Login-Wiederholungen mit wachsender Wartezeit bis 1 h). Nutzung auf eigenes Risiko.

## Installation

1. LoxBerry → *Plugin-Verwaltung* → diese URL eintragen und installieren (dauert einige Minuten):

   ```
   https://github.com/martin111180/loxberry-plugin-mammotion/releases/latest/download/loxberry-plugin-mammotion.zip
   ```

   Der Link zeigt immer auf die neueste freigegebene Version.
2. Alternativ das ZIP unter [Releases](https://github.com/martin111180/loxberry-plugin-mammotion/releases/latest)
   herunterladen und in der Plugin-Verwaltung hochladen.
3. Plugin *Mammotion Mähroboter* öffnen, Konto + Passwort eintragen, **Speichern**.
4. Im Statusbereich erscheinen nach kurzer Zeit die Mäher mit allen Werten, Topics und Loxone-Eingangsnamen.

## MQTT-Topics

`<dev>` ist der Gerätename in Kleinbuchstaben mit `_` (z. B. `Luba-VSABC123` → `luba_vsabc123`).

| Topic | Inhalt |
|---|---|
| `mammotion/problem` | **1 = irgendein Problem** (alle Mäher + Cloud-Verbindung) |
| `mammotion/problem_text` | Beschreibung, sonst `OK` |
| `mammotion/bridge/status` | `online` / `offline` (Last Will – Plugin abgestürzt/gestoppt) |
| `mammotion/bridge/connected` | 1 = Cloud-Login ok |
| `mammotion/<dev>/problem` | 1 = Problem bei diesem Mäher |
| `mammotion/<dev>/problem_text` | z. B. `Status: Gesperrt; Fehler 1301: …` |
| `mammotion/<dev>/error_code` | letzter neuer Fehlercode (0 = keiner) |
| `mammotion/<dev>/error_text` / `error_solution` | Fehlertext / Lösungsvorschlag |
| `mammotion/<dev>/error_time` | Unix-Zeit des Fehlers |
| `mammotion/<dev>/online` | 1/0 |
| `mammotion/<dev>/battery` | Akku in % |
| `mammotion/<dev>/charging` | 1 = lädt |
| `mammotion/<dev>/mode` / `mode_text` | Gerätestatus (13 mäht, 15 lädt, 17 gesperrt, 18 Systemfehler, 19 Pause …) |
| `mammotion/<dev>/progress` | Mähfortschritt in % |
| `mammotion/<dev>/last_report` | Unix-Zeit der letzten Statusmeldung |

Alle Werte werden *retained* gesendet.

**Befehle** (z. B. vom Miniserver über das MQTT Gateway):

* `mammotion/cmd/reset` – gespeicherte Fehler aller Mäher quittieren
* `mammotion/<dev>/cmd/reset` – Fehler eines Mähers quittieren
* `mammotion/cmd/refresh` – Status sofort anfordern

## Wann gilt etwas als „Problem“?

* Gerätestatus ist in der Liste *Problem-Status* (Standard: 17 gesperrt, 18 Systemfehler,
  23 Update fehlgeschlagen, 37 Positionsfehler, 38 Grenzüberschreitung; 19 *Pause* kann ergänzt werden)
* der Mäher meldet einen **neuen** Fehlercode (Warn-Ereignis oder neuer Eintrag in der Fehlerliste) –
  bleibt für die eingestellte Haltezeit aktiv, bis er quittiert wird oder der Mäher wieder mäht
* der Mäher ist länger als X Minuten offline (einstellbar, 0 = aus)
* die Cloud-Anmeldung funktioniert nicht

## Loxone-Konfiguration

Das Plugin legt `config/plugins/mammotion/mqtt_subscriptions.cfg` mit `mammotion/#` an – das
MQTT Gateway abonniert das Topic dadurch automatisch. (Falls nicht: im MQTT Gateway unter
*Subscriptions* `mammotion/#` eintragen.)

Im Gateway (*Incoming Overview*) erscheinen die Werte, z. B. `mammotion_problem`,
`mammotion_luba_vsabc123_error_text`. In Loxone Config:

* **Zahlen** (problem, battery, mode, …) → *Virtueller UDP-Eingang* mit Befehl
  `MQTT:\imammotion_problem=\i\v` (UDP-Port wie im MQTT Gateway eingestellt), **oder** als
  *Virtueller Eingang* über HTTP (Gateway-Option *Use HTTP*), Name exakt wie im Gateway angezeigt.
* **Texte** (problem_text, error_text, mode_text) → *Virtueller Texteingang* mit dem Namen aus dem Gateway
  (Texte werden immer per HTTP übertragen).

Beispiel: `mammotion_problem` → Baustein *Meldung/Benachrichtigung* bzw. Push an die App mit
`mammotion_problem_text` als Text.

## Updates

Neue Versionen erkennt der LoxBerry automatisch (Plugin-Verwaltung → Auto-Update je Plugin einstellbar).
Wie ein Release erstellt wird, steht in [docs/entwicklung.md](docs/entwicklung.md).

## Dateien

| Pfad im Repo | Zweck |
|---|---|
| `plugin/plugin.cfg` | Plugin-Beschreibung |
| `plugin/bin/mammotion_bridge.py` | eigentliche Bridge (Python 3.13, pymammotion, paho-mqtt) |
| `plugin/bin/service.sh` | start/stop/restart/status/watchdog (läuft als `loxberry`) |
| `plugin/daemon/daemon` | Start beim Booten |
| `plugin/cron/cron.05min` | Watchdog – startet die Bridge neu, falls abgestürzt |
| `plugin/webfrontend/htmlauth/index.php` | Weboberfläche |
| `plugin/config/` | Standardkonfiguration, Python-Requirements, MQTT-Gateway-Abo |
| `plugin/postinstall.sh` | installiert `uv`, Python 3.13 und die Pakete nach `data/plugins/mammotion` |
| `plugin/preupgrade.sh` / `postupgrade.sh` | sichern/wiederherstellen der Konfiguration bei Updates |
| `tools/build_zip.py` | baut das installierbare ZIP nach `dist/` |
| `release.cfg` | Versionsinfo für das LoxBerry-Auto-Update (pflegt der Release-Workflow) |
| `tools/make_icons.py` | erzeugt die Plugin-Icons in `plugin/icons/` |
| `.github/workflows/release.yml` | prüft jeden Push, baut bei Tag `v*` das Release |


Log auf dem LoxBerry: `log/plugins/mammotion/mammotion.log` (auch über die Weboberfläche erreichbar).
