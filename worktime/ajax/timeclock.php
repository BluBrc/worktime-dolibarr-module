<?php
/* Modul Worktime – AJAX-Endpunkt der Stempeluhr
 *
 * POST-Parameter:
 *   action = clock_in | clock_out | break_start | break_end | status
 *   token  = Dolibarr-CSRF-Token (newToken())
 *   note   = optionale Notiz
 *
 * Antwort (JSON):
 *   {success: true, new_status: '...', time: 'HH:MM', allowed: [...], today: {...}}
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1'); // Token nicht erneuern, damit die Seite weiter gültig bleibt
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1'); // CSRF-Prüfung erfolgt unten selbst und liefert JSON statt HTML-Fehlerseite
}

// Dolibarr-Umgebung laden
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	header('Content-Type: application/json; charset=UTF-8');
	http_response_code(500);
	echo json_encode(array('success' => false, 'error' => 'Include of main fails'));
	exit;
}

require_once __DIR__.'/../class/worktime.class.php';

$langs->loadLangs(array('worktime@worktime'));

/**
 * JSON-Antwort senden und beenden
 *
 * @param  array $data       Daten
 * @param  int   $httpStatus HTTP-Status
 * @return void
 */
function worktime_json_response($data, $httpStatus = 200)
{
	global $db;
	if (!headers_sent()) {
		header('Content-Type: application/json; charset=UTF-8');
		header('Cache-Control: no-cache, no-store, must-revalidate');
	}
	http_response_code($httpStatus);
	echo json_encode($data);
	if (is_object($db)) {
		$db->close();
	}
	exit;
}

// Nur angemeldete Benutzer mit Modul aktiv
if (!isModEnabled('worktime')) {
	worktime_json_response(array('success' => false, 'error' => $langs->transnoentitiesnoconv('WorktimeErrorModuleDisabled')), 403);
}
if (empty($user->id) || !worktime_can_read($user)) {
	worktime_json_response(array('success' => false, 'error' => $langs->transnoentitiesnoconv('WorktimeErrorNoPermission')), 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	worktime_json_response(array('success' => false, 'error' => $langs->transnoentitiesnoconv('WorktimeErrorPostOnly')), 405);
}

// CSRF-Token prüfen
$token = GETPOST('token', 'alpha');
$validToken = false;
foreach (array('newtoken', 'token') as $sessKey) {
	if (!empty($_SESSION[$sessKey]) && is_string($token) && $token !== '' && hash_equals((string) $_SESSION[$sessKey], $token)) {
		$validToken = true;
		break;
	}
}
if (!$validToken) {
	worktime_json_response(array('success' => false, 'error' => $langs->transnoentitiesnoconv('WorktimeErrorInvalidToken')), 403);
}

$action = GETPOST('action', 'aZ09');
$note = trim(GETPOST('note', 'alphanohtml'));

$wt = new Worktime($db);
$userId = (int) $user->id;

/**
 * Heutige Übersicht für die Oberfläche aufbereiten
 *
 * @param  Worktime  $wt     Objekt
 * @param  int       $userId Benutzer
 * @param  Translate $langs  Sprachobjekt
 * @return array
 */
function worktime_today_payload($wt, $userId, $langs)
{
	$today = worktime_user_today();
	$entries = $wt->getDayEntries($userId, $today);
	$calc = $wt->computeDay($entries, $today);
	$labels = array(
		'clock_in' => $langs->transnoentitiesnoconv('WorktimeClockIn'),
		'clock_out' => $langs->transnoentitiesnoconv('WorktimeClockOut'),
		'break_start' => $langs->transnoentitiesnoconv('WorktimeBreakStart'),
		'break_end' => $langs->transnoentitiesnoconv('WorktimeBreakEnd'),
	);
	$list = array();
	foreach ($entries as $e) {
		$list[] = array(
			'type' => $e['type'],
			'label' => isset($labels[$e['type']]) ? $labels[$e['type']] : $e['type'],
			'time' => worktime_user_time_of_ts($e['ts']),
			'note' => $e['note'],
		);
	}
	$week = $wt->getWeekBalance($userId, worktime_week_start($today));
	$month = $wt->getMonthBalance($userId, (int) worktime_user_now('%Y'), (int) worktime_user_now('%m'));
	return array(
		'entries' => $list,
		'work_minutes' => $calc['work'],
		'break_minutes' => $calc['break'],
		'work_formatted' => worktime_format_minutes($calc['work']),
		'break_formatted' => worktime_format_minutes($calc['break']),
		'target_formatted' => worktime_format_minutes($wt->getDailyTargetMinutes($userId)),
		'week_balance' => worktime_format_minutes($week['balance'], true),
		'week_balance_class' => worktime_balance_class($week['balance']),
		'week_actual' => worktime_format_minutes($week['actual']),
		'month_balance' => worktime_format_minutes($month['balance'], true),
		'month_balance_class' => worktime_balance_class($month['balance']),
		'month_actual' => worktime_format_minutes($month['actual']),
	);
}

$status = $wt->getCurrentStatus($userId);

if ($action === 'status') {
	worktime_json_response(array(
		'success' => true,
		'new_status' => $status,
		'allowed' => Worktime::getAllowedActions($status),
		'time' => worktime_user_now(),
		'today' => worktime_today_payload($wt, $userId, $langs),
	));
}

if (!in_array($action, Worktime::getTypes(), true)) {
	worktime_json_response(array('success' => false, 'error' => $langs->transnoentitiesnoconv('WorktimeErrorInvalidAction')), 400);
}
if (!worktime_can_write($user)) {
	worktime_json_response(array('success' => false, 'error' => $langs->transnoentitiesnoconv('WorktimeErrorNoPermission')), 403);
}

// Zustandsprüfung: verhindert z. B. doppeltes Einstempeln
$allowed = Worktime::getAllowedActions($status);
if (!in_array($action, $allowed, true)) {
	worktime_json_response(array(
		'success' => false,
		'error' => $langs->transnoentitiesnoconv('WorktimeErrorActionNotAllowed'),
		'new_status' => $status,
		'allowed' => $allowed,
		'today' => worktime_today_payload($wt, $userId, $langs),
	), 409);
}

$id = $wt->addEntry($userId, $action, null, $note);
if ($id <= 0) {
	dol_syslog('worktime timeclock error: '.$wt->error, LOG_ERR);
	worktime_json_response(array('success' => false, 'error' => $langs->transnoentitiesnoconv('WorktimeErrorSave')), 500);
}

$timeLocal = worktime_user_now();
$newStatus = Worktime::statusAfter($action);
worktime_json_response(array(
	'success' => true,
	'new_status' => $newStatus,
	'allowed' => Worktime::getAllowedActions($newStatus),
	'time' => $timeLocal,
	'message' => $langs->transnoentitiesnoconv('WorktimeSaved_'.$action, $timeLocal),
	'today' => worktime_today_payload($wt, $userId, $langs),
));
