#!/bin/bash
# Läuft als loxberry nach einem Update: gesicherte Konfiguration zurückspielen.
# (postinstall.sh lief bereits mit der Standardkonfiguration; Bridge danach neu starten)
PFOLDER=$3
LBHOMEDIR=$5
BACKUP=/tmp/${1##*/}_upgrade
PCONFIG=$LBHOMEDIR/config/plugins/$PFOLDER

if [ -f "$BACKUP/config/mammotion.json" ]; then
	cp -p -v "$BACKUP/config/mammotion.json" "$PCONFIG/mammotion.json"
	chmod 600 "$PCONFIG/mammotion.json"
	echo "<OK> Konfiguration wiederhergestellt"
fi
rm -rf "$BACKUP"

"$LBHOMEDIR/bin/plugins/$PFOLDER/service.sh" restart
exit 0
