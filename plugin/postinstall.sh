#!/bin/bash
# Läuft als Benutzer loxberry nach dem Kopieren der Dateien.
# Argumente: $1 Temp-Ordner, $2 Plugin-Name, $3 Plugin-Ordner, $4 Version, $5 LoxBerry-Basisordner
PFOLDER=$3
LBHOMEDIR=$5
PDATA=$LBHOMEDIR/data/plugins/$PFOLDER
PCONFIG=$LBHOMEDIR/config/plugins/$PFOLDER
PBIN=$LBHOMEDIR/bin/plugins/$PFOLDER

# pymammotion benötigt Python >= 3.13. Das System-Python des LoxBerry ist älter,
# deshalb wird mit "uv" ein eigenes Python 3.13 in den Plugin-Datenordner installiert.
UVDIR=$PDATA/uv
export UV_PYTHON_INSTALL_DIR=$PDATA/python
export UV_CACHE_DIR=$PDATA/.uv-cache

chmod +x "$PBIN"/*.sh "$PBIN"/*.py

ARCH=$(uname -m)
echo "<INFO> Architektur: $ARCH"
if [ "$ARCH" = "armv7l" ] || [ "$ARCH" = "armv6l" ]; then
	echo "<WARNING> 32-Bit-ARM erkannt. Für Python 3.13 gibt es dort nicht für alle Pakete (numpy, shapely, pillow)"
	echo "<WARNING> fertige Pakete – die Installation kann sehr lange dauern oder fehlschlagen."
	echo "<WARNING> Empfohlen: 64-Bit-LoxBerry (aarch64) oder x86_64."
fi

if [ ! -x "$UVDIR/uv" ]; then
	echo "<INFO> Installiere uv nach $UVDIR ..."
	mkdir -p "$UVDIR"
	if ! curl -LsSf https://astral.sh/uv/install.sh | env UV_UNMANAGED_INSTALL="$UVDIR" INSTALLER_NO_MODIFY_PATH=1 sh; then
		echo "<FAIL> uv konnte nicht installiert werden (Internetverbindung?)"
		exit 2
	fi
fi
UV=$UVDIR/uv
echo "<OK> $($UV --version)"

echo "<INFO> Erzeuge Python-3.13-Umgebung ..."
if ! "$UV" venv --python 3.13 --allow-existing "$PDATA/venv"; then
	echo "<FAIL> Python 3.13 konnte nicht bereitgestellt werden"
	exit 2
fi

echo "<INFO> Installiere pymammotion und paho-mqtt (kann einige Minuten dauern) ..."
if ! "$UV" pip install --python "$PDATA/venv/bin/python" --upgrade -r "$PCONFIG/requirements.txt"; then
	echo "<FAIL> Installation der Python-Pakete fehlgeschlagen"
	exit 2
fi
echo "<OK> $("$PDATA/venv/bin/python" -c 'import importlib.metadata as m; print("pymammotion", m.version("pymammotion"))')"

# Konfiguration nur mit Leserechten für loxberry (enthält das Mammotion-Passwort)
chmod 600 "$PCONFIG/mammotion.json"

# Bridge (neu) starten – läuft nur, wenn bereits Zugangsdaten eingetragen sind
"$PBIN/service.sh" restart

exit 0
