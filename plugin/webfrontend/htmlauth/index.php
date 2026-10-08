<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";

$mm_cfgfile    = LBPCONFIGDIR . "/mammotion.json";
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

// Texte aus templates/lang/language_<sprache>.ini (Sprache des LoxBerry, Englisch als Rückfall)
$mm_L = LBSystem::readlanguage("language.ini");

// Übersetzter Text ohne Escaping (für Meldungen, die später escaped werden)
function tr($mm_key, ...$mm_args) {
	global $mm_L;
	$mm_text = $mm_L[$mm_key] ?? $mm_key;
	return $mm_args ? vsprintf($mm_text, $mm_args) : $mm_text;
}

// Übersetzter Text, HTML-escaped; %s-Argumente werden unverändert eingesetzt (vorher selbst escapen)
function t($mm_key, ...$mm_args) {
	$mm_text = h(tr($mm_key));
	return $mm_args ? vsprintf($mm_text, $mm_args) : $mm_text;
}

function date_fmt($mm_ts) {
	return date(tr('UI.DATE_FORMAT'), (int)$mm_ts);
}

// Alle Gerätestatus (WorkMode) – wie MODE_TEXT_DE/MODE_TEXT_EN in mammotion_bridge.py
$mm_mode_codes = [0, 1, 2, 3, 8, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 22, 23, 31, 32, 34, 36, 37, 38, 39, 45, 46, 47, 48, 50, 51, 52];

function mode_text($mm_code) {
	global $mm_L;
	return $mm_L["MODES.M$mm_code"] ?? tr('UI.UNKNOWN_MODE');
}

// Topics mit festen Werten (Spalte "Bedeutung"); Texte in [VALUES] als TOPIC_WERT
$mm_value_choices = ['online' => [1, 0], 'charging' => [1, 0], 'problem' => [1, 0]];

// Bedeutung eines Werts als HTML: alle Möglichkeiten, der aktuelle Wert fett
function value_meaning($mm_key, $mm_val, $mm_dev_key = '') {
	global $mm_value_choices, $mm_mode_codes;
	if (isset($mm_value_choices[$mm_key])) {
		$mm_parts = [];
		foreach ($mm_value_choices[$mm_key] as $mm_code) {
			$mm_item = h("$mm_code = " . tr('VALUES.' . strtoupper($mm_key) . "_$mm_code"));
			$mm_parts[] = ((string)$mm_code === (string)$mm_val) ? "<b>$mm_item</b>" : $mm_item;
		}
		return implode('<br>', $mm_parts);
	}
	if ($mm_key === 'mode') {
		$mm_rows = '';
		foreach ($mm_mode_codes as $mm_code) {
			$mm_item = h("$mm_code = " . mode_text($mm_code));
			$mm_rows .= ((string)$mm_code === (string)$mm_val) ? "<b>$mm_item</b><br>" : "$mm_item<br>";
		}
		return t('VALUES.MODE') . '<br><b>' . h("$mm_val = " . mode_text((int)$mm_val)) . '</b><details data-key="' . h($mm_dev_key . '/' . $mm_key) . '"><summary>' . t('UI.ALL_VALUES') . "</summary>$mm_rows</details>";
	}
	$mm_text = t('VALUES.' . strtoupper($mm_key));
	if (in_array($mm_key, ['error_time', 'last_report']) && (int)$mm_val > 0) {
		$mm_text .= '<br><b>' . h(date_fmt($mm_val)) . '</b>';
	}
	return $mm_text;
}

// Gerätestatus, die als Problem markiert werden können (Reihenfolge der Checkboxen)
$mm_problem_modes = [17, 18, 37, 38, 23, 19, 39, 12, 3];

function is_running() {
	return strpos(service('status'), 'running') === 0;
}

// Ergebnis einer Aktion als [ok, Meldung]; den Grund für "läuft nicht" liefert die Konfiguration
function action_message($mm_action) {
	global $mm_cfg;
	$mm_up = is_running();
	if ($mm_action === 'stop') {
		return [!$mm_up, tr($mm_up ? 'MSG.STOP_FAILED' : 'MSG.STOPPED')];
	}
	$mm_prefix = $mm_action === 'save' ? tr('MSG.SAVED') . ' ' : '';
	if ($mm_up) {
		return [true, $mm_prefix . tr($mm_action === 'start' ? 'MSG.STARTED' : 'MSG.RESTARTED')];
	}
	if (empty($mm_cfg['enabled'])) {
		$mm_reason = tr('MSG.REASON_DISABLED');
	} elseif (empty($mm_cfg['account']) || empty($mm_cfg['password'])) {
		$mm_reason = tr('MSG.REASON_NO_ACCOUNT');
	} else {
		$mm_reason = tr('MSG.REASON_UNKNOWN');
	}
	return [false, $mm_prefix . tr('MSG.NOT_RUNNING') . ' ' . $mm_reason];
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
		if ($mm_ok) {
			service('restart');
			[$mm_message_ok, $mm_message] = action_message('save');
		} else {
			[$mm_message_ok, $mm_message] = [false, tr('MSG.SAVE_FAILED')];
		}
	} elseif (in_array($mm_action, ['start', 'stop', 'restart'])) {
		service($mm_action);
		[$mm_message_ok, $mm_message] = action_message($mm_action);
	}
}

$mm_running = is_running();
$mm_status  = json_decode(@file_get_contents($mm_statusfile), true);
$mm_topic   = $mm_cfg['topic'] ?? 'mammotion';
$mm_mqtt    = $mm_cfg['mqtt'] ?? [];

// ---------------------------------------------------------------- Live-Bereich
// Status-Tabelle und Gerätetabellen: beim Seitenaufbau gerendert und per ?ajax=live nachgeladen

function render_status_table() {
	global $mm_running, $mm_status;
?>
<table class="mm-table">
	<tr><td><?= t('UI.BRIDGE_SERVICE') ?></td><td><?= $mm_running ? '<span class="mm-ok">' . t('UI.RUNNING') . '</span>' : '<span class="mm-bad">' . t('UI.STOPPED') . '</span>' ?></td></tr>
	<?php if ($mm_status): ?>
	<tr><td><?= t('UI.CLOUD') ?></td><td><?= !empty($mm_status['connected']) ? '<span class="mm-ok">' . t('UI.CONNECTED') . '</span>' : '<span class="mm-bad">' . t('UI.NOT_CONNECTED') . '</span>' ?>
		<?= !empty($mm_status['bridge_problem']) ? '<br>' . h($mm_status['bridge_problem']) : '' ?></td></tr>
	<tr><td><?= t('UI.LAST_UPDATE') ?></td><td><?= h(date_fmt($mm_status['updated'] ?? 0)) ?></td></tr>
	<?php endif; ?>
</table>
<?php
}

function render_devices() {
	global $mm_status, $mm_topic;
?>
<?php if (!empty($mm_status['devices'])): foreach ($mm_status['devices'] as $mm_name => $mm_dev): $mm_v = $mm_dev['values'] ?? []; ?>
<h3><?= h($mm_name) ?></h3>
<?php $mm_wait = (int)($mm_dev['waiting_seconds'] ?? 0);
if (!empty($mm_dev['waiting']) || $mm_wait > 0 || !$mm_v): ?>
<div class="mm-msg mm-msg-wait"><?= t('UI.WAITING') ?><?= $mm_wait > 0 ? ' ' . t('UI.WAITING_SINCE', $mm_wait < 120 ? $mm_wait . ' s' : intdiv($mm_wait, 60) . ' min') : '' ?>.
	<?= $mm_v ? t('UI.WAITING_OLD_VALUES') : t('UI.WAITING_NO_VALUES') ?></div>
<?php endif; ?>
<?php if ($mm_v): ?>
<table class="mm-table">
	<tr><th><?= t('UI.COL_VALUE') ?></th><th><?= t('UI.COL_CONTENT') ?></th><th><?= t('UI.COL_MEANING') ?></th><th><?= t('UI.COL_TOPIC') ?></th></tr>
	<?php foreach ($mm_v as $mm_k => $mm_val):
		$mm_t = "$mm_topic/{$mm_dev['key']}/$mm_k";
		if (is_bool($mm_val)) $mm_val = $mm_val ? 1 : 0;
		$mm_cls = ($mm_k === 'problem') ? ($mm_val ? 'mm-bad' : 'mm-ok') : '';
	?>
	<tr><td><?= h($mm_k) ?></td><td class="<?= $mm_cls ?>"><?= h($mm_val) ?></td><td class="mm-meaning"><?= value_meaning($mm_k, $mm_val, $mm_dev['key']) ?></td><td><code><?= h($mm_t) ?></code></td></tr>
	<?php endforeach; ?>
</table>
<?php endif; ?>
<?php endforeach; endif; ?>
<?php
}

// Schneller nachladen, solange noch auf eine Statusmeldung gewartet wird
function live_waiting() {
	global $mm_status;
	if (empty($mm_status['devices'])) {
		return true;
	}
	foreach ($mm_status['devices'] as $mm_dev) {
		if (!empty($mm_dev['waiting']) || empty($mm_dev['values'])) {
			return true;
		}
	}
	return false;
}

function capture($mm_fn) {
	ob_start();
	$mm_fn();
	return ob_get_clean();
}

if (($_GET['ajax'] ?? '') === 'live') {
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode([
		'status'  => capture('render_status_table'),
		'devices' => capture('render_devices'),
		'waiting' => live_waiting(),
	]);
	exit;
}

// ---------------------------------------------------------------- Seite
$navbar[1]['Name'] = tr('UI.NAV_SETTINGS');
$navbar[1]['URL'] = 'index.php';
$navbar[1]['active'] = true;
$navbar[2]['Name'] = tr('UI.NAV_LOG');
$navbar[2]['URL'] = '/admin/system/tools/logfile.cgi?logfile=' . urlencode($mm_logfile) . '&header=html&format=template';
$navbar[2]['target'] = '_blank';

LBWeb::lbheader(tr('UI.TITLE'), "", "");
?>
<style>
	.mm-ok { color: #2e7d32; font-weight: bold; }
	.mm-bad { color: #c62828; font-weight: bold; }
	.mm-table { border-collapse: collapse; width: 100%; margin-bottom: 1em; }
	.mm-table td, .mm-table th { border-bottom: 1px solid #ddd; padding: 4px 8px; text-align: left; vertical-align: top; }
	.mm-table code { font-size: 90%; }
	.mm-hint { font-size: 90%; color: #666; }
	.mm-meaning { font-size: 90%; color: #555; }
	.mm-meaning b { color: #222; }
	.mm-meaning summary { cursor: pointer; color: #6b9e1f; margin-top: 2px; }
	.mm-msg { padding: 8px 12px; margin-bottom: 1em; border-radius: 4px; }
	.mm-msg-ok { background: #e8f5e9; border: 1px solid #a5d6a7; color: #1b5e20; }
	.mm-msg-bad { background: #fdecea; border: 1px solid #f5c6cb; color: #8a1c1c; }
	.mm-msg-wait { background: #fff8e1; border: 1px solid #ffe082; color: #5d4300; }
</style>

<?php if ($mm_message): ?><div class="mm-msg <?= $mm_message_ok ? 'mm-msg-ok' : 'mm-msg-bad' ?>"><?= h($mm_message) ?></div><?php endif; ?>

<h2><?= t('UI.STATUS') ?></h2>
<div id="mm-live-status"><?php render_status_table(); ?></div>
<form method="post" data-ajax="false" style="display:inline">
	<button type="submit" name="action" value="restart" data-inline="true" data-mini="true"><?= t('UI.BTN_RESTART') ?></button>
	<button type="submit" name="action" value="stop" data-inline="true" data-mini="true"><?= t('UI.BTN_STOP') ?></button>
</form>

<div id="mm-live-devices"><?php render_devices(); ?></div>

<p class="mm-hint">
	<?= t('UI.HINT_SUMMARY', '<code>' . h($mm_topic) . '/problem</code>', '<code>' . h($mm_topic) . '/problem_text</code>') ?><br>
	<?= t('UI.HINT_RESET', '<code>' . h($mm_topic) . '/cmd/reset</code>', '<code>' . h($mm_topic . '/' . tr('UI.DEVICE_PLACEHOLDER') . '/cmd/reset') . '</code>') ?>
</p>

<h2><?= t('UI.SETTINGS') ?></h2>
<form method="post" data-ajax="false">
	<input type="hidden" name="action" value="save">

	<label><input type="checkbox" name="enabled" <?= !empty($mm_cfg['enabled']) ? 'checked' : '' ?>> <?= t('UI.ENABLED') ?></label>

	<h3><?= t('UI.ACCOUNT') ?></h3>
	<label for="account"><?= t('UI.ACCOUNT_EMAIL') ?></label>
	<input type="text" id="account" name="account" value="<?= h($mm_cfg['account'] ?? '') ?>" autocomplete="off">
	<label for="password"><?= t('UI.PASSWORD') ?> <?= !empty($mm_cfg['password']) ? t('UI.PASSWORD_SAVED') : '' ?></label>
	<input type="password" id="password" name="password" value="" autocomplete="new-password">
	<p class="mm-hint"><?= t('UI.ACCOUNT_TIP') ?></p>

	<h3><?= t('UI.PROBLEMS') ?></h3>
	<fieldset data-role="controlgroup">
		<legend><?= t('UI.PROBLEM_MODES') ?></legend>
		<?php $mm_selected = array_map('intval', $mm_cfg['problem_modes'] ?? []);
		foreach ($mm_problem_modes as $mm_code): ?>
		<label><input type="checkbox" name="problem_modes[]" value="<?= $mm_code ?>" <?= in_array($mm_code, $mm_selected, true) ? 'checked' : '' ?>> <?= h(mode_text($mm_code) . " ($mm_code)") ?></label>
		<?php endforeach;
		// Von Hand eingetragene Codes, die hier nicht aufgelistet sind, beibehalten
		foreach (array_diff($mm_selected, $mm_problem_modes) as $mm_code): ?>
		<input type="hidden" name="problem_modes[]" value="<?= (int)$mm_code ?>">
		<?php endforeach; ?>
	</fieldset>
	<p class="mm-hint"><?= t('UI.PROBLEM_MODES_HINT') ?></p>
	<label for="error_hold_minutes"><?= t('UI.ERROR_HOLD') ?></label>
	<input type="number" id="error_hold_minutes" name="error_hold_minutes" min="0" value="<?= h($mm_cfg['error_hold_minutes'] ?? 60) ?>">
	<label for="offline_problem_minutes"><?= t('UI.OFFLINE_LIMIT') ?></label>
	<input type="number" id="offline_problem_minutes" name="offline_problem_minutes" min="0" value="<?= h($mm_cfg['offline_problem_minutes'] ?? 30) ?>">
	<label for="refresh_seconds"><?= t('UI.REFRESH') ?></label>
	<input type="number" id="refresh_seconds" name="refresh_seconds" min="0" value="<?= h($mm_cfg['refresh_seconds'] ?? 300) ?>">
	<label for="language"><?= t('UI.LANGUAGE') ?></label>
	<select id="language" name="language">
		<?php foreach (['de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'it' => 'Italiano', 'nl' => 'Nederlands'] as $mm_code => $mm_label): ?>
		<option value="<?= $mm_code ?>" <?= ($mm_cfg['language'] ?? 'de') === $mm_code ? 'selected' : '' ?>><?= $mm_label ?></option>
		<?php endforeach; ?>
	</select>
	<p class="mm-hint"><?= t('UI.LANGUAGE_HINT') ?></p>

	<h3><?= t('UI.MQTT') ?></h3>
	<label for="topic"><?= t('UI.BASE_TOPIC') ?></label>
	<input type="text" id="topic" name="topic" value="<?= h($mm_topic) ?>">
	<label for="republish_seconds"><?= t('UI.REPUBLISH') ?></label>
	<input type="number" id="republish_seconds" name="republish_seconds" min="30" value="<?= h($mm_cfg['republish_seconds'] ?? 300) ?>">
	<?php $mm_use_lb = !isset($mm_mqtt['use_loxberry']) || $mm_mqtt['use_loxberry']; ?>
	<label><input type="checkbox" id="mqtt_use_loxberry" name="mqtt_use_loxberry" <?= $mm_use_lb ? 'checked' : '' ?>> <?= t('UI.USE_LOXBERRY') ?></label>
	<div id="mm-mqtt-manual"<?= $mm_use_lb ? ' style="display:none"' : '' ?>>
	<label for="mqtt_host"><?= t('UI.BROKER_HOST') ?></label>
	<input type="text" id="mqtt_host" name="mqtt_host" value="<?= h($mm_mqtt['host'] ?? 'localhost') ?>">
	<label for="mqtt_port"><?= t('UI.BROKER_PORT') ?></label>
	<input type="number" id="mqtt_port" name="mqtt_port" value="<?= h($mm_mqtt['port'] ?? 1883) ?>">
	<label for="mqtt_user"><?= t('UI.BROKER_USER') ?></label>
	<input type="text" id="mqtt_user" name="mqtt_user" value="<?= h($mm_mqtt['user'] ?? '') ?>" autocomplete="off">
	<label for="mqtt_password"><?= t('UI.BROKER_PASSWORD') ?> <?= !empty($mm_mqtt['password']) ? t('UI.BROKER_PASSWORD_SAVED') : '' ?></label>
	<input type="password" id="mqtt_password" name="mqtt_password" value="" autocomplete="new-password">
	</div>

	<h3><?= t('UI.LOGGING') ?></h3>
	<select id="loglevel" name="loglevel">
		<?php foreach (['ERROR', 'WARNING', 'INFO', 'DEBUG'] as $mm_lvl): ?>
		<option value="<?= $mm_lvl ?>" <?= ($mm_cfg['loglevel'] ?? 'INFO') === $mm_lvl ? 'selected' : '' ?>><?= $mm_lvl ?></option>
		<?php endforeach; ?>
	</select>

	<button type="submit" data-icon="check"><?= t('UI.SAVE') ?></button>
</form>

<script>
// Status und Gerätewerte automatisch aktualisieren (Formular bleibt unberührt):
// alle 5 s, solange auf eine Statusmeldung gewartet wird, sonst alle 30 s; pausiert im Hintergrund-Tab
(function () {
	var statusBox = document.getElementById('mm-live-status');
	var devicesBox = document.getElementById('mm-live-devices');
	if (!statusBox || !devicesBox || !window.fetch) return;
	var waiting = <?= live_waiting() ? 'true' : 'false' ?>;
	var timer = null;

	function schedule() {
		clearTimeout(timer);
		timer = setTimeout(refresh, waiting ? 5000 : 30000);
	}

	function refresh() {
		if (document.hidden) { schedule(); return; }
		fetch('index.php?ajax=live', { credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
			.then(function (data) {
				// aufgeklappte "alle möglichen Werte" merken und wieder öffnen
				var open = [];
				devicesBox.querySelectorAll('details[open]').forEach(function (d) { open.push(d.getAttribute('data-key')); });
				statusBox.innerHTML = data.status;
				devicesBox.innerHTML = data.devices;
				open.forEach(function (key) {
					var d = devicesBox.querySelector('details[data-key="' + key + '"]');
					if (d) d.open = true;
				});
				waiting = !!data.waiting;
			})
			.catch(function () { /* nächster Versuch beim nächsten Intervall */ })
			.then(schedule);
	}

	document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
	schedule();
})();

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
