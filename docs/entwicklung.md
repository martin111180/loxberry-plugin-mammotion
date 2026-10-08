# Entwicklung und Releases

## Aufbau

* `plugin/` – genau der Inhalt, der ins LoxBerry-Plugin-ZIP kommt (Ordner `mammotion/` im ZIP)
* `tools/build_zip.py` – baut `dist/loxberry-plugin-mammotion-<version>.zip`
* `release.cfg` – wird vom LoxBerry über `RELEASECFG` in `plugin/plugin.cfg` abgefragt
* `.github/workflows/release.yml` – prüft jeden Push (Shell-Syntax, PHP-Syntax, Python-Import mit
  echtem pymammotion, ZIP-Build) und erstellt bei einem Tag `v*` das GitHub-Release

Alle Textdateien brauchen LF-Zeilenenden (`.gitattributes` erzwingt das; `build_zip.py` bricht bei CRLF ab).

## Neue Version veröffentlichen

1. Änderungen committen.
2. In `plugin/plugin.cfg` `VERSION=` erhöhen (z. B. `0.1.1`) und committen.
3. Tag setzen und pushen:

   ```bash
   git tag v0.1.1
   git push origin main --tags
   ```

4. Der Workflow prüft, dass Tag und `VERSION` übereinstimmen, baut das ZIP, legt das Release an und
   setzt `release.cfg` auf `main` auf die neue Version. Ab dann bieten LoxBerrys das Update an.

Jedes Release enthält das ZIP zweimal: mit Versionsnummer (für `release.cfg`) und als
`loxberry-plugin-mammotion.zip` für den festen Link
`https://github.com/martin111180/loxberry-plugin-mammotion/releases/latest/download/loxberry-plugin-mammotion.zip`.
Der Job `fester-link` hängt diese Datei bei jedem Push auf `main` nachträglich an, falls sie am neuesten
Release fehlt.

## Lokal testen

```bash
python tools/build_zip.py
```

Das ZIP aus `dist/` in der LoxBerry-Plugin-Verwaltung installieren. Log am LoxBerry:
`/opt/loxberry/log/plugins/mammotion/mammotion.log`, Bridge steuern mit
`/opt/loxberry/bin/plugins/mammotion/service.sh {start|stop|restart|status}`.

## Abhängigkeiten (Dependabot)

`plugin/config/requirements.txt` enthält **exakte** Versionen. Dependabot prüft jeden Montag, ob es neue
Versionen gibt, und öffnet dafür einen Pull Request. Der Workflow testet den PR (Installation, Import,
ZIP-Build). Ist er grün und läuft das Plugin damit, den PR übernehmen und ein neues Release taggen –
erst dann kommt die neue Library-Version auf die LoxBerrys.

Der Test prüft nicht den Login bei Mammotion – dafür vor dem Release kurz am echten LoxBerry testen.

## Pfade

Nie `/opt/loxberry` fest eintragen (der Installer warnt sonst). Stattdessen die Platzhalter verwenden,
die der LoxBerry bei der Installation in allen Textdateien ersetzt, z. B. `REPLACELBHOMEDIR`,
`REPLACELBPBINDIR`, `REPLACELBPCONFIGDIR`, `REPLACELBPDATADIR`, `REPLACELBPLOGDIR`.

## Hinweise zu pymammotion

* braucht Python ≥ 3.13 – deshalb installiert `postinstall.sh` per `uv` ein eigenes Python
* deklariert `packaging` nicht als Abhängigkeit (Stand 0.10.9) – steht deshalb in `plugin/config/requirements.txt`
* auf LoxBerry 4 (Debian Trixie) nutzt `uv` das vorhandene System-Python 3.13, auf älteren Systemen lädt es eines herunter
* die Geräteliste kommt aus dem internen `client._device_registry.all_devices` (keine öffentliche API) –
  bei pymammotion-Updates prüfen
