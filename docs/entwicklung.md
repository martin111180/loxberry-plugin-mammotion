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

## Lokal testen

```bash
python tools/build_zip.py
```

Das ZIP aus `dist/` in der LoxBerry-Plugin-Verwaltung installieren. Log am LoxBerry:
`/opt/loxberry/log/plugins/mammotion/mammotion.log`, Bridge steuern mit
`/opt/loxberry/bin/plugins/mammotion/service.sh {start|stop|restart|status}`.

## Hinweise zu pymammotion

* braucht Python ≥ 3.13 – deshalb installiert `postinstall.sh` per `uv` ein eigenes Python
* deklariert `packaging` nicht als Abhängigkeit (Stand 0.10.9) – steht deshalb in `plugin/config/requirements.txt`
* die Geräteliste kommt aus dem internen `client._device_registry.all_devices` (keine öffentliche API) –
  bei pymammotion-Updates prüfen
