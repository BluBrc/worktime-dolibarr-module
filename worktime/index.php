<?php
/* Modul Worktime – Hauptseite: Stempeluhr (Kommen/Gehen/Pause) und Tagesübersicht */

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
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
dol_include_once('/worktime/class/worktime.class.php');
dol_include_once('/worktime/class/worktimeabsence.class.php');

$langs->loadLangs(array('worktime@worktime'));

if (!isModEnabled('worktime')) {
	accessforbidden($langs->trans('WorktimeErrorModuleDisabled'));
}
if (!worktime_can_read($user)) {
	accessforbidden();
}

$wt = new Worktime($db);
$abs = new WorktimeAbsence($db);
$userId = (int) $user->id;
$today = worktime_user_today();

$status = $wt->getCurrentStatus($userId);
$allowed = Worktime::getAllowedActions($status);
$entries = $wt->getDayEntries($userId, $today);
$calc = $wt->computeDay($entries, $today);
$week = $wt->getWeekBalance($userId, worktime_week_start($today));
$month = $wt->getMonthBalance($userId, (int) worktime_user_now('%Y'), (int) worktime_user_now('%m'));
$accumulated = $wt->getAccumulatedBalance($userId);
$total = $accumulated + $month['balance'];
$vac = $abs->getVacationStats($userId, (int) worktime_user_now('%Y'));
$canWrite = worktime_can_write($user);

$statusLabels = array(
	'off' => $langs->transnoentitiesnoconv('WorktimeStatusOff'),
	'clock_in' => $langs->transnoentitiesnoconv('WorktimeStatusWorking'),
	'break' => $langs->transnoentitiesnoconv('WorktimeStatusBreak'),
	'clock_out' => $langs->transnoentitiesnoconv('WorktimeStatusClockedOut'),
);
$typeLabels = array(
	'clock_in' => $langs->trans('WorktimeClockIn'),
	'clock_out' => $langs->trans('WorktimeClockOut'),
	'break_start' => $langs->trans('WorktimeBreakStart'),
	'break_end' => $langs->trans('WorktimeBreakEnd'),
);

// Offene Anträge für Genehmigende
$pendingCount = 0;
if (worktime_is_admin($user)) {
	$pendingCount = count($abs->fetchList(0, 'pending'));
} else {
	$subs = worktime_get_subordinates($userId);
	if (!empty($subs)) {
		$pendingCount = count($abs->fetchList($subs, 'pending'));
	}
}

/*
 * Ausgabe
 */
$title = $langs->trans('WorktimeTimeclock');
llxHeader('', $title, '', '', 0, 0, array('/worktime/js/worktime.js'), array('/worktime/css/worktime.css'));

print load_fiche_titre($title, '', 'worktime@worktime');

if ($pendingCount > 0) {
	print '<div class="info">'.$langs->trans('WorktimePendingRequestsInfo', $pendingCount).' <a href="'.dol_buildpath('/worktime/absence.php', 1).'?mode=manage">'.$langs->trans('WorktimeGoToRequests').'</a></div>';
}

$dayNames = array(1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday');

print '<div class="wt-clock-box">';

// Linke Spalte: Stempeluhr
print '<div class="wt-clock-panel" id="wt-clock"';
print ' data-url="'.dol_escape_htmltag(dol_buildpath('/worktime/ajax/timeclock.php', 1)).'"';
print ' data-token="'.dol_escape_htmltag(newToken()).'"';
print ' data-status="'.dol_escape_htmltag($status).'"';
print ' data-status-labels="'.dol_escape_htmltag(json_encode($statusLabels)).'"';
print ' data-no-entries="'.dol_escape_htmltag($langs->transnoentitiesnoconv('WorktimeNoEntriesToday')).'"';
print ' data-err-generic="'.dol_escape_htmltag($langs->transnoentitiesnoconv('WorktimeErrorGeneric')).'"';
print '>';
print '<div class="wt-clock-now" id="wt-now">'.worktime_user_now('%H:%M:%S').'</div>';
print '<div class="wt-clock-date">'.$langs->trans($dayNames[(int) date('N', strtotime($today))]).', '.worktime_format_date($today).'</div>';
print '<div id="wt-status" class="wt-status wt-status-'.dol_escape_htmltag($status).'">'.dol_escape_htmltag($statusLabels[$status]).'</div>';

if ($canWrite) {
	print '<div class="wt-buttons">';
	foreach (Worktime::getTypes() as $type) {
		$hidden = in_array($type, $allowed, true) ? '' : ' wt-hidden';
		print '<button type="button" class="wt-btn wt-btn-'.$type.$hidden.'" data-action="'.$type.'">'.dol_escape_htmltag($langs->trans('WorktimeButton_'.$type)).'</button>';
	}
	print '</div>';
	print '<div class="margintoponly"><input type="text" id="wt-note" class="flat minwidth300" maxlength="250" placeholder="'.dol_escape_htmltag($langs->trans('WorktimeNoteOptional')).'"></div>';
	print '<div id="wt-message" class="wt-message"></div>';
} else {
	print '<div class="opacitymedium">'.$langs->trans('WorktimeNoWritePermission').'</div>';
}
print '</div>';

// Rechte Spalte: Kennzahlen
print '<div class="wt-clock-panel">';
print '<div class="wt-kpis">';
$kpis = array(
	array('WorktimeTodayWorked', '<span id="wt-today-work">'.worktime_format_minutes($calc['work']).'</span> <span class="opacitymedium small">/ '.worktime_format_minutes($wt->getDailyTargetMinutes($userId)).'</span>'),
	array('WorktimeTodayBreak', '<span id="wt-today-break">'.worktime_format_minutes($calc['break']).'</span>'),
	array('WorktimeWeekBalance', '<span id="wt-week-balance" class="'.worktime_balance_class($week['balance']).'">'.worktime_format_minutes($week['balance'], true).'</span><div class="small opacitymedium">'.$langs->trans('WorktimeActual').': <span id="wt-week-actual">'.worktime_format_minutes($week['actual']).'</span> / '.$langs->trans('WorktimeTargetWeek').': '.worktime_format_minutes($week['target_full']).'</div>'),
	array('WorktimeMonthBalance', '<span id="wt-month-balance" class="'.worktime_balance_class($month['balance']).'">'.worktime_format_minutes($month['balance'], true).'</span><div class="small opacitymedium">'.$langs->trans('WorktimeActual').': <span id="wt-month-actual">'.worktime_format_minutes($month['actual']).'</span></div>'),
	array('WorktimeAccountUntilLastMonth', '<span class="'.worktime_balance_class($accumulated).'">'.worktime_format_minutes($accumulated, true).'</span>'),
	array('WorktimeAccountTotal', '<span class="'.worktime_balance_class($total).'">'.worktime_format_minutes($total, true).'</span>'),
	array('WorktimeVacationRemaining', price2num($vac['remaining'], 1).' '.$langs->trans('WorktimeDays').'<div class="small opacitymedium">'.$langs->trans('WorktimeVacationPending').': '.$vac['pending'].'</div>'),
);
foreach ($kpis as $k) {
	print '<div class="wt-kpi"><div class="wt-kpi-label">'.$langs->trans($k[0]).'</div><div class="wt-kpi-value">'.$k[1].'</div></div>';
}
print '</div>';
print '<div class="small opacitymedium margintoponly">'.$langs->trans('WorktimeBalanceHint').'</div>';
print '</div>';

print '</div>'; // .wt-clock-box

// Tagesübersicht als Timeline
print load_fiche_titre($langs->trans('WorktimeTodayTimeline'), '', '');
print '<div class="wt-clock-panel" style="text-align:left; max-width: 600px;">';
print '<ul class="wt-timeline" id="wt-timeline">';
if (empty($entries)) {
	print '<li class="opacitymedium">'.$langs->trans('WorktimeNoEntriesToday').'</li>';
} else {
	foreach ($entries as $e) {
		print '<li class="wt-tl-'.dol_escape_htmltag($e['type']).'"><span class="wt-tl-time">'.worktime_user_time_of_ts($e['ts']).'</span> ';
		print dol_escape_htmltag(isset($typeLabels[$e['type']]) ? $typeLabels[$e['type']] : $e['type']);
		if ($e['note'] !== '') {
			print ' <span class="wt-tl-note">– '.dol_escape_htmltag($e['note']).'</span>';
		}
		print '</li>';
	}
}
print '</ul>';
print '</div>';

print '<div class="tabsAction">';
print '<a class="butAction" href="'.dol_buildpath('/worktime/report_weekly.php', 1).'">'.$langs->trans('WorktimeWeeklyReport').'</a>';
print '<a class="butAction" href="'.dol_buildpath('/worktime/report_monthly.php', 1).'">'.$langs->trans('WorktimeMonthlyReport').'</a>';
print '<a class="butAction" href="'.dol_buildpath('/worktime/absence.php', 1).'">'.$langs->trans('WorktimeAbsences').'</a>';
print '</div>';

llxFooter();
$db->close();
