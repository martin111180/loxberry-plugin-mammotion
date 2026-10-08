<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";

$cfgfile    = LBPCONFIGDIR . "/mammotion.json";
$subsfile   = LBPCONFIGDIR . "/mqtt_subscriptions.cfg";
$statusfile = LBPDATADIR . "/status.json";
$logfile    = LBPLOGDIR . "/mammotion.log";
$service    = LBPBINDIR . "/service.sh";

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function read_config($file) {
	$cfg = json_decode(@file_get_contents($file), true);
	return is_array($cfg) ? $cfg : [];
}

function service($cmd) {
	global $service;
	return trim(shell_exec(escapeshellarg($service) . " " . escapeshellarg($cmd) . " </dev/null 2>&1"));
}

$cfg = read_config($cfgfile);
$message = "";

// ---------------------------------------------------------------- Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';
	if ($action === 'save') {
		$cfg['enabled']  = isset($_POST['enabled']);
		$cfg['account']  = trim($_POST['account'] ?? '');
		if (($_POST['password'] ?? '') !== '') {
			$cfg['password'] = $_POST['password'];
		}
		$cfg['language'] = preg_replace('/[^a-z]/', '', $_POST['language'] ?? 'de') ?: 'de';
		$topic = trim(preg_replace('#[^A-Za-z0-9_/\-]#', '', $_POST['topic'] ?? 'mammotion'), '/');
		$cfg['topic'] = $topic !== '' ? $topic : 'mammotion';
		$modes = array_filter(array_map('trim', explode(',', $_POST['problem_modes'] ?? '')), 'is_numeric');
		$cfg['problem_modes'] = array_values(array_map('intval', $modes));
		foreach (['error_hold_minutes', 'offline_problem_minutes', 'refresh_seconds', 'republish_seconds'] as $k) {
			$cfg[$k] = max(0, intval($_POST[$k] ?? 0));
		}
		$cfg['loglevel'] = in_array($_POST['loglevel'] ?? '', ['DEBUG', 'INFO', 'WARNING', 'ERROR']) ? $_POST['loglevel'] : 'INFO';
		$mqtt = $cfg['mqtt'] ?? [];
		$mqtt['use_loxberry'] = isset($_POST['mqtt_use_loxberry']);
		$mqtt['host'] = trim($_POST['mqtt_host'] ?? 'localhost');
		$mqtt['port'] = intval($_POST['mqtt_port'] ?? 1883) ?: 1883;
		$mqtt['user'] = trim($_POST['mqtt_user'] ?? '');
		if (($_POST['mqtt_password'] ?? '') !== '') {
			$mqtt['password'] = $_POST['mqtt_password'];
		}
		$cfg['mqtt'] = $mqtt;

		$ok = file_put_contents($cfgfile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
		@chmod($cfgfile, 0600);
		// Abonnement für das LoxBerry MQTT Gateway
		file_put_contents($subsfile, $cfg['topic'] . "/#\n");
		$message = $ok ? "Gespeichert. " . h(service('restart')) : "Fehler: Konfiguration konnte nicht gespeichert werden!";
	} elseif (in_array($action, ['start', 'stop', 'restart'])) {
		$message = h(service($action));
	}
}

$running = strpos(service('status'), 'running') === 0;
$status  = json_decode(@file_get_contents($statusfile), true);
$topic   = $cfg['topic'] ?? 'mammotion';
$mqtt    = $cfg['mqtt'] ?? [];

// ---------------------------------------------------------------- Seite
$navbar[1]['Name'] = "Einstellungen";
$navbar[1]['URL'] = 'index.php';
$navbar[1]['active'] = true;
$navbar[2]['Name'] = "Log";
$navbar[2]['URL'] = '/admin/system/tools/logfile.cgi?logfile=' . urlencode($logfile) . '&header=html&format=template';
$navbar[2]['target'] = '_blank';

LBWeb::lbheader("Mammotion Mähroboter", "", "");
?>
<style>
	.mm-ok { color: #2e7d32; font-weight: bold; }
	.mm-bad { color: #c62828; font-weight: bold; }
	.mm-table { border-collapse: collapse; width: 100%; margin-bottom: 1em; }
	.mm-table td, .mm-table th { border-bottom: 1px solid #ddd; padding: 4px 8px; text-align: left; vertical-align: top; }
	.mm-table code { font-size: 90%; }
	.mm-hint { font-size: 90%; color: #666; }
	.mm-msg { padding: 8px; background: #fff8e1; border: 1px solid #ffe082; margin-bottom: 1em; }
	.mm-log { max-height: 300px; overflow: auto; background: #222; color: #ddd; padding: 8px; font-size: 80%; white-space: pre-wrap; }
</style>

<?php if ($message): ?><div class="mm-msg"><?= $message ?></div><?php endif; ?>

<h2>Status</h2>
<table class="mm-table">
	<tr><td>Bridge-Dienst</td><td><?= $running ? '<span class="mm-ok">läuft</span>' : '<span class="mm-bad">gestoppt</span>' ?></td></tr>
	<?php if ($status): ?>
	<tr><td>Mammotion-Cloud</td><td><?= !empty($status['connected']) ? '<span class="mm-ok">verbunden</span>' : '<span class="mm-bad">nicht verbunden</span>' ?>
		<?= !empty($status['bridge_problem']) ? '<br>' . h($status['bridge_problem']) : '' ?></td></tr>
	<tr><td>Letzte Aktualisierung</td><td><?= h(date('d.m.Y H:i:s', $status['updated'] ?? 0)) ?></td></tr>
	<?php endif; ?>
</table>
<form method="post" data-ajax="false" style="display:inline">
	<button type="submit" name="action" value="restart" data-inline="true" data-mini="true">Neu starten</button>
	<button type="submit" name="action" value="stop" data-inline="true" data-mini="true">Stoppen</button>
</form>

<?php if (!empty($status['devices'])): foreach ($status['devices'] as $name => $dev): $v = $dev['values'] ?? []; ?>
<h3><?= h($name) ?></h3>
<table class="mm-table">
	<tr><th>Wert</th><th>Inhalt</th><th>MQTT-Topic</th><th>Loxone-Eingang (MQTT Gateway)</th></tr>
	<?php foreach ($v as $k => $val):
		$t = "$topic/{$dev['key']}/$k";
		if (is_bool($val)) $val = $val ? 1 : 0;
		$cls = ($k === 'problem') ? ($val ? 'mm-bad' : 'mm-ok') : '';
	?>
	<tr><td><?= h($k) ?></td><td class="<?= $cls ?>"><?= h($val) ?></td><td><code><?= h($t) ?></code></td><td><code><?= h(str_replace('/', '_', $t)) ?></code></td></tr>
	<?php endforeach; ?>
</table>
<?php endforeach; endif; ?>

<p class="mm-hint">
	Sammelmeldung für alle Geräte: <code><?= h($topic) ?>/problem</code> (0/1) und <code><?= h($topic) ?>/problem_text</code>.<br>
	Fehler quittieren: MQTT-Publish auf <code><?= h($topic) ?>/cmd/reset</code> (alle) bzw. <code><?= h($topic) ?>/&lt;gerät&gt;/cmd/reset</code>.
</p>

<h2>Einstellungen</h2>
<form method="post" data-ajax="false">
	<input type="hidden" name="action" value="save">

	<label><input type="checkbox" name="enabled" <?= !empty($cfg['enabled']) ? 'checked' : '' ?>> Bridge aktiviert</label>

	<h3>Mammotion-Konto</h3>
	<label for="account">E-Mail / Konto der Mammotion-App</label>
	<input type="text" id="account" name="account" value="<?= h($cfg['account'] ?? '') ?>" autocomplete="off">
	<label for="password">Passwort <?= !empty($cfg['password']) ? '(gespeichert – leer lassen, um es beizubehalten)' : '' ?></label>
	<input type="password" id="password" name="password" value="" autocomplete="new-password">
	<p class="mm-hint">Tipp: Lege in der Mammotion-App ein zweites Konto an und teile den Mäher damit.
		So wird dein Haupt-Login in der App nicht durch die Bridge abgemeldet.</p>

	<h3>Problemerkennung</h3>
	<label for="problem_modes">Gerätestatus, die als Problem gelten (Komma-getrennt)</label>
	<input type="text" id="problem_modes" name="problem_modes" value="<?= h(implode(',', $cfg['problem_modes'] ?? [])) ?>">
	<p class="mm-hint">17 = gesperrt, 18 = Systemfehler, 19 = pausiert, 23 = Update fehlgeschlagen, 37 = Positionsfehler, 38 = Grenzüberschreitung</p>
	<label for="error_hold_minutes">Gemeldeten Fehler so lange als Problem halten (Minuten, 0 = bis Quittierung)</label>
	<input type="number" id="error_hold_minutes" name="error_hold_minutes" min="0" value="<?= h($cfg['error_hold_minutes'] ?? 60) ?>">
	<label for="offline_problem_minutes">Offline länger als … Minuten = Problem (0 = aus)</label>
	<input type="number" id="offline_problem_minutes" name="offline_problem_minutes" min="0" value="<?= h($cfg['offline_problem_minutes'] ?? 30) ?>">
	<label for="refresh_seconds">Status spätestens alle … Sekunden anfordern (0 = nur automatisch)</label>
	<input type="number" id="refresh_seconds" name="refresh_seconds" min="0" value="<?= h($cfg['refresh_seconds'] ?? 300) ?>">
	<label for="language">Sprache der Fehlertexte</label>
	<select id="language" name="language">
		<?php foreach (['de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'it' => 'Italiano', 'nl' => 'Nederlands'] as $code => $label): ?>
		<option value="<?= $code ?>" <?= ($cfg['language'] ?? 'de') === $code ? 'selected' : '' ?>><?= $label ?></option>
		<?php endforeach; ?>
	</select>

	<h3>MQTT</h3>
	<label for="topic">Basis-Topic</label>
	<input type="text" id="topic" name="topic" value="<?= h($topic) ?>">
	<label for="republish_seconds">Alle Werte erneut senden alle … Sekunden</label>
	<input type="number" id="republish_seconds" name="republish_seconds" min="30" value="<?= h($cfg['republish_seconds'] ?? 300) ?>">
	<label><input type="checkbox" name="mqtt_use_loxberry" <?= !isset($mqtt['use_loxberry']) || $mqtt['use_loxberry'] ? 'checked' : '' ?>> Broker-Zugangsdaten des LoxBerry verwenden (empfohlen)</label>
	<p class="mm-hint">Nur ohne diese Option werden die folgenden Felder verwendet:</p>
	<label for="mqtt_host">Broker-Host</label>
	<input type="text" id="mqtt_host" name="mqtt_host" value="<?= h($mqtt['host'] ?? 'localhost') ?>">
	<label for="mqtt_port">Port</label>
	<input type="number" id="mqtt_port" name="mqtt_port" value="<?= h($mqtt['port'] ?? 1883) ?>">
	<label for="mqtt_user">Benutzer</label>
	<input type="text" id="mqtt_user" name="mqtt_user" value="<?= h($mqtt['user'] ?? '') ?>" autocomplete="off">
	<label for="mqtt_password">Passwort <?= !empty($mqtt['password']) ? '(gespeichert)' : '' ?></label>
	<input type="password" id="mqtt_password" name="mqtt_password" value="" autocomplete="new-password">

	<h3>Protokoll</h3>
	<select id="loglevel" name="loglevel">
		<?php foreach (['ERROR', 'WARNING', 'INFO', 'DEBUG'] as $lvl): ?>
		<option value="<?= $lvl ?>" <?= ($cfg['loglevel'] ?? 'INFO') === $lvl ? 'selected' : '' ?>><?= $lvl ?></option>
		<?php endforeach; ?>
	</select>

	<button type="submit" data-icon="check">Speichern und Bridge neu starten</button>
</form>

<h2>Letzte Logeinträge</h2>
<div class="mm-log"><?php
	$lines = @file($logfile);
	echo $lines ? h(implode('', array_slice($lines, -60))) : 'Noch kein Log vorhanden.';
?></div>

<?php
LBWeb::lbfooter();
