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
