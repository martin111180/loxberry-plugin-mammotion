# CLAUDE.md

LoxBerry-Plugin, das Mammotion-Mähroboter über die Cloud (pymammotion) überwacht und Status/Probleme
per MQTT an den LoxBerry-Broker schickt (→ MQTT Gateway → Loxone Miniserver).

* Sprache: Deutsch (UI, Logs, Doku, Commit-Nachrichten)
* Plugin-Inhalt liegt in `plugin/`, Release-Ablauf in `docs/entwicklung.md`
* Vor jedem Commit: `bash -n` für Shell-Skripte, `python tools/build_zip.py` muss durchlaufen
* Versionsnummer nur in `plugin/plugin.cfg` ändern; `release.cfg` pflegt der Workflow
* `[AUTHOR]` in `plugin/plugin.cfg` nie ändern (LoxBerry erkennt das Plugin daran)
* Keine festen `/opt/loxberry`-Pfade – LoxBerry-Platzhalter wie `REPLACELBPBINDIR` verwenden
* Python-Abhängigkeiten exakt pinnen; Updates kommen über Dependabot-PRs
* Texte der Weboberfläche nur in `plugin/templates/lang/language_de.ini` und `language_en.ini` – beide immer gemeinsam pflegen
* MQTT-Texte der Bridge (`mode_text`, `problem_text`) in `TEXTS`, `MODE_TEXT_DE`/`MODE_TEXT_EN` in `mammotion_bridge.py`
* Keine automatischen MQTT-Abos: welche Topics an den Miniserver gehen, entscheidet der Nutzer im MQTT Gateway
