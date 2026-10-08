<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";

$mm_cfgfile    = LBPCONFIGDIR . "/mammotion.json";
$mm_subsfile   = LBPCONFIGDIR . "/mqtt_subscriptions.cfg";
$mm_statusfile = LBPDATADIR . "/status.json";
$mm_logfile    = LBPLOGDIR . "/mammotion.log";
$mm_service    = LBPBINDIR . "/service.sh";

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function read_config($mm_file) {
	$mm_cfg = json_decode(@file_get_contents($mm_file), true);
	return is_array($mm_cfg) ? $mm_cfg : [];
}

function service($mm_cmd) {
	global $mm_service;
	return trim((string)shell_exec(escapeshellarg($mm_service) . " " . escapeshellarg($mm_cmd) . " </dev/null 2>&1"));
}

// Gerätestatus (WorkMode), die als Problem markiert werden können – Texte wie in mammotion_bridge.py
$mm_problem_modes = [
	17 => 'Gesperrt',
	18 => 'Systemfehler',
	37 => 'Positionsfehler',
	38 => 'Grenzüberschreitung',
	23 => 'Update fehlgeschlagen',
	19 => 'Pausiert',
	39 => 'Ladepause',
	12 => 'Nicht verbunden',
	3  => 'Ausgeschaltet',
];

function is_running() {
	return strpos(service('status'), 'running') === 0;
}

// Ausgabe von service.sh in eine verständliche Meldung übersetzen
function action_message($mm_action, $mm_output) {
	$mm_lines = array_values(array_filter(array_map('trim', explode("\n", $mm_output))));
	$mm_reason = $mm_lines ? end($mm_lines) : '';
	$mm_up = is_running();
	if ($mm_action === 'stop') {
		return [!$mm_up, $mm_up ? "Die Bridge konnte nicht gestoppt werden. $mm_reason" : "Die Bridge wurde gestoppt."];
	}
	$mm_prefix = $mm_action === 'save' ? "Einstellungen gespeichert. " : "";
	if ($mm_up) {
		return [true, $mm_prefix . ($mm_action === 'start' ? "Die Bridge wurde gestartet." : "Die Bridge wurde neu gestartet.")];
	}
	return [false, $mm_prefix . "Die Bridge läuft nicht: $mm_reason"];
}

$mm_cfg = read_config($mm_cfgfile);
$mm_message = "";
$mm_message_ok = true;

// ---------------------------------------------------------------- Aktionen
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	$mm_action = $_POST['action'] ?? '';
	if ($mm_action === 'save') {
		$mm_cfg['enabled']  = isset($_POST['enabled']);
		$mm_cfg['account']  = trim($_POST['account'] ?? '');
		if (($_POST['password'] ?? '') !== '') {
			$mm_cfg['password'] = $_POST['password'];
		}
		$mm_cfg['language'] = preg_replace('/[^a-z]/', '', $_POST['language'] ?? 'de') ?: 'de';
		$mm_topic = trim(preg_replace('#[^A-Za-z0-9_/\-]#', '', $_POST['topic'] ?? 'mammotion'), '/');
		$mm_cfg['topic'] = $mm_topic !== '' ? $mm_topic : 'mammotion';
		$mm_modes = array_filter((array)($_POST['problem_modes'] ?? []), 'is_numeric');
		$mm_cfg['problem_modes'] = array_values(array_unique(array_map('intval', $mm_modes)));
		foreach (['error_hold_minutes', 'offline_problem_minutes', 'refresh_seconds', 'republish_seconds'] as $mm_k) {
			$mm_cfg[$mm_k] = max(0, intval($_POST[$mm_k] ?? 0));
		}
		$mm_cfg['loglevel'] = in_array($_POST['loglevel'] ?? '', ['DEBUG', 'INFO', 'WARNING', 'ERROR']) ? $_POST['loglevel'] : 'INFO';
		$mm_mqtt = $mm_cfg['mqtt'] ?? [];
		$mm_mqtt['use_loxberry'] = isset($_POST['mqtt_use_loxberry']);
		$mm_mqtt['host'] = trim($_POST['mqtt_host'] ?? 'localhost');
		$mm_mqtt['port'] = intval($_POST['mqtt_port'] ?? 1883) ?: 1883;
		$mm_mqtt['user'] = trim($_POST['mqtt_user'] ?? '');
		if (($_POST['mqtt_password'] ?? '') !== '') {
			$mm_mqtt['password'] = $_POST['mqtt_password'];
		}
		$mm_cfg['mqtt'] = $mm_mqtt;

		$mm_ok = file_put_contents($mm_cfgfile, json_encode($mm_cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
		@chmod($mm_cfgfile, 0600);
		// Abonnement für das LoxBerry MQTT Gateway
		file_put_contents($mm_subsfile, $mm_cfg['topic'] . "/#\n");
		if ($mm_ok) {
			[$mm_message_ok, $mm_message] = action_message('save', service('restart'));
		} else {
			[$mm_message_ok, $mm_message] = [false, "Fehler: Die Einstellungen konnten nicht gespeichert werden!"];
		}
	} elseif (in_array($mm_action, ['start', 'stop', 'restart'])) {
		[$mm_message_ok, $mm_message] = action_message($mm_action, service($mm_action));
	}
}

$mm_running = is_running();
$mm_status  = json_decode(@file_get_contents($mm_statusfile), true);
$mm_topic   = $mm_cfg['topic'] ?? 'mammotion';
$mm_mqtt    = $mm_cfg['mqtt'] ?? [];

// ---------------------------------------------------------------- Seite
$navbar[1]['Name'] = "Einstellungen";
$navbar[1]['URL'] = 'index.php';
$navbar[1]['active'] = true;
$navbar[2]['Name'] = "Log";
$navbar[2]['URL'] = '/admin/system/tools/logfile.cgi?logfile=' . urlencode($mm_logfile) . '&header=html&format=template';
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
	.mm-msg { padding: 8px 12px; margin-bottom: 1em; border-radius: 4px; }
	.mm-msg-ok { background: #e8f5e9; border: 1px solid #a5d6a7; color: #1b5e20; }
	.mm-msg-bad { background: #fdecea; border: 1px solid #f5c6cb; color: #8a1c1c; }
	.mm-log { max-height: 300px; overflow: auto; background: #222; color: #ddd; padding: 8px; font-size: 80%; white-space: pre-wrap; }
</style>

<?php if ($mm_message): ?><div class="mm-msg <?= $mm_message_ok ? 'mm-msg-ok' : 'mm-msg-bad' ?>"><?= h($mm_message) ?></div><?php endif; ?>

<h2>Status</h2>
<table class="mm-table">
	<tr><td>Bridge-Dienst</td><td><?= $mm_running ? '<span class="mm-ok">läuft</span>' : '<span class="mm-bad">gestoppt</span>' ?></td></tr>
	<?php if ($mm_status): ?>
	<tr><td>Mammotion-Cloud</td><td><?= !empty($mm_status['connected']) ? '<span class="mm-ok">verbunden</span>' : '<span class="mm-bad">nicht verbunden</span>' ?>
		<?= !empty($mm_status['bridge_problem']) ? '<br>' . h($mm_status['bridge_problem']) : '' ?></td></tr>
	<tr><td>Letzte Aktualisierung</td><td><?= h(date('d.m.Y H:i:s', $mm_status['updated'] ?? 0)) ?></td></tr>
	<?php endif; ?>
</table>
<form method="post" data-ajax="false" style="display:inline">
	<button type="submit" name="action" value="restart" data-inline="true" data-mini="true">Neu starten</button>
	<button type="submit" name="action" value="stop" data-inline="true" data-mini="true">Stoppen</button>
</form>

<?php if (!empty($mm_status['devices'])): foreach ($mm_status['devices'] as $mm_name => $mm_dev): $mm_v = $mm_dev['values'] ?? []; ?>
<h3><?= h($mm_name) ?></h3>
<table class="mm-table">
	<tr><th>Wert</th><th>Inhalt</th><th>MQTT-Topic</th><th>Loxone-Eingang (MQTT Gateway)</th></tr>
	<?php foreach ($mm_v as $mm_k => $mm_val):
		$mm_t = "$mm_topic/{$mm_dev['key']}/$mm_k";
		if (is_bool($mm_val)) $mm_val = $mm_val ? 1 : 0;
		$mm_cls = ($mm_k === 'problem') ? ($mm_val ? 'mm-bad' : 'mm-ok') : '';
	?>
	<tr><td><?= h($mm_k) ?></td><td class="<?= $mm_cls ?>"><?= h($mm_val) ?></td><td><code><?= h($mm_t) ?></code></td><td><code><?= h(str_replace('/', '_', $mm_t)) ?></code></td></tr>
	<?php endforeach; ?>
</table>
<?php endforeach; endif; ?>

<p class="mm-hint">
	Sammelmeldung für alle Geräte: <code><?= h($mm_topic) ?>/problem</code> (0/1) und <code><?= h($mm_topic) ?>/problem_text</code>.<br>
	Fehler quittieren: MQTT-Publish auf <code><?= h($mm_topic) ?>/cmd/reset</code> (alle) bzw. <code><?= h($mm_topic) ?>/&lt;gerät&gt;/cmd/reset</code>.
</p>

<h2>Einstellungen</h2>
<form method="post" data-ajax="false">
	<input type="hidden" name="action" value="save">

	<label><input type="checkbox" name="enabled" <?= !empty($mm_cfg['enabled']) ? 'checked' : '' ?>> Bridge aktiviert</label>

	<h3>Mammotion-Konto</h3>
	<label for="account">E-Mail / Konto der Mammotion-App</label>
	<input type="text" id="account" name="account" value="<?= h($mm_cfg['account'] ?? '') ?>" autocomplete="off">
	<label for="password">Passwort <?= !empty($mm_cfg['password']) ? '(gespeichert – leer lassen, um es beizubehalten)' : '' ?></label>
	<input type="password" id="password" name="password" value="" autocomplete="new-password">
	<p class="mm-hint">Tipp: Lege in der Mammotion-App ein zweites Konto an und teile den Mäher damit.
		So wird dein Haupt-Login in der App nicht durch die Bridge abgemeldet.</p>

	<h3>Problemerkennung</h3>
	<fieldset data-role="controlgroup">
		<legend>Diese Gerätestatus als Problem melden:</legend>
		<?php $mm_selected = array_map('intval', $mm_cfg['problem_modes'] ?? []);
		foreach ($mm_problem_modes as $mm_code => $mm_label): ?>
		<label><input type="checkbox" name="problem_modes[]" value="<?= $mm_code ?>" <?= in_array($mm_code, $mm_selected, true) ? 'checked' : '' ?>> <?= h($mm_label) ?></label>
		<?php endforeach;
		// Von Hand eingetragene Codes, die hier nicht aufgelistet sind, beibehalten
		foreach (array_diff($mm_selected, array_keys($mm_problem_modes)) as $mm_code): ?>
		<input type="hidden" name="problem_modes[]" value="<?= (int)$mm_code ?>">
		<?php endforeach; ?>
	</fieldset>
	<label for="error_hold_minutes">Gemeldeten Fehler so lange als Problem halten (Minuten, 0 = bis Quittierung)</label>
	<input type="number" id="error_hold_minutes" name="error_hold_minutes" min="0" value="<?= h($mm_cfg['error_hold_minutes'] ?? 60) ?>">
	<label for="offline_problem_minutes">Offline länger als … Minuten = Problem (0 = aus)</label>
	<input type="number" id="offline_problem_minutes" name="offline_problem_minutes" min="0" value="<?= h($mm_cfg['offline_problem_minutes'] ?? 30) ?>">
	<label for="refresh_seconds">Status spätestens alle … Sekunden anfordern (0 = nur automatisch)</label>
	<input type="number" id="refresh_seconds" name="refresh_seconds" min="0" value="<?= h($mm_cfg['refresh_seconds'] ?? 300) ?>">
	<label for="language">Sprache der Fehlertexte</label>
	<select id="language" name="language">
		<?php foreach (['de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'it' => 'Italiano', 'nl' => 'Nederlands'] as $mm_code => $mm_label): ?>
		<option value="<?= $mm_code ?>" <?= ($mm_cfg['language'] ?? 'de') === $mm_code ? 'selected' : '' ?>><?= $mm_label ?></option>
		<?php endforeach; ?>
	</select>

	<h3>MQTT</h3>
	<label for="topic">Basis-Topic</label>
	<input type="text" id="topic" name="topic" value="<?= h($mm_topic) ?>">
	<label for="republish_seconds">Alle Werte erneut senden alle … Sekunden</label>
	<input type="number" id="republish_seconds" name="republish_seconds" min="30" value="<?= h($mm_cfg['republish_seconds'] ?? 300) ?>">
	<?php $mm_use_lb = !isset($mm_mqtt['use_loxberry']) || $mm_mqtt['use_loxberry']; ?>
	<label><input type="checkbox" id="mqtt_use_loxberry" name="mqtt_use_loxberry" <?= $mm_use_lb ? 'checked' : '' ?>> Broker-Zugangsdaten des LoxBerry verwenden (empfohlen)</label>
	<div id="mm-mqtt-manual"<?= $mm_use_lb ? ' style="display:none"' : '' ?>>
	<label for="mqtt_host">Broker-Host</label>
	<input type="text" id="mqtt_host" name="mqtt_host" value="<?= h($mm_mqtt['host'] ?? 'localhost') ?>">
	<label for="mqtt_port">Port</label>
	<input type="number" id="mqtt_port" name="mqtt_port" value="<?= h($mm_mqtt['port'] ?? 1883) ?>">
	<label for="mqtt_user">Benutzer</label>
	<input type="text" id="mqtt_user" name="mqtt_user" value="<?= h($mm_mqtt['user'] ?? '') ?>" autocomplete="off">
	<label for="mqtt_password">Passwort <?= !empty($mm_mqtt['password']) ? '(gespeichert)' : '' ?></label>
	<input type="password" id="mqtt_password" name="mqtt_password" value="" autocomplete="new-password">
	</div>

	<h3>Protokoll</h3>
	<select id="loglevel" name="loglevel">
		<?php foreach (['ERROR', 'WARNING', 'INFO', 'DEBUG'] as $mm_lvl): ?>
		<option value="<?= $mm_lvl ?>" <?= ($mm_cfg['loglevel'] ?? 'INFO') === $mm_lvl ? 'selected' : '' ?>><?= $mm_lvl ?></option>
		<?php endforeach; ?>
	</select>

	<button type="submit" data-icon="check">Speichern und Bridge neu starten</button>
</form>

<h2>Letzte Logeinträge</h2>
<div class="mm-log"><?php
	$mm_lines = @file($mm_logfile);
	echo $mm_lines ? h(implode('', array_slice($mm_lines, -60))) : 'Noch kein Log vorhanden.';
?></div>

<script>
// Eigene Broker-Felder nur zeigen, wenn die LoxBerry-Zugangsdaten nicht verwendet werden
(function () {
	var cb = document.getElementById('mqtt_use_loxberry');
	var box = document.getElementById('mm-mqtt-manual');
	if (!cb || !box) return;
	function update() { box.style.display = cb.checked ? 'none' : ''; }
	cb.addEventListener('change', update);
	cb.addEventListener('click', function () { setTimeout(update, 0); });
	if (window.jQuery) { window.jQuery(cb).on('change', update); }  // jQuery Mobile löst change über jQuery aus
	update();
})();
</script>

<?php
LBWeb::lbfooter();
