<?php
/* Modul Worktime – Wochenauswertung (inkl. Korrektur von Einträgen für Verwalter) */

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

dol_include_once('/worktime/class/worktime.class.php');

$langs->loadLangs(array('worktime@worktime'));

if (!isModEnabled('worktime')) {
	accessforbidden($langs->trans('WorktimeErrorModuleDisabled'));
}
if (!worktime_can_read($user)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$week = worktime_sanitize_date(GETPOST('week', 'alphanohtml'), worktime_user_today());
$weekStart = worktime_week_start($week);
$weekEnd = worktime_date_add($weekStart, 6);
$targetUserId = GETPOSTINT('fk_user') > 0 ? GETPOSTINT('fk_user') : (int) $user->id;
if (!worktime_can_view_user($user, $targetUserId)) {
	accessforbidden();
}
$isAdmin = worktime_is_admin($user);
$wt = new Worktime($db);

$selfUrl = dol_buildpath('/worktime/report_weekly.php', 1);

/*
 * Aktionen (nur Verwalter): Einträge nachtragen / löschen
 * Das CSRF-Token wird bei POST von Dolibarr (main.inc.php) geprüft.
 */
if ($isAdmin && $action == 'addentry' && $_SERVER['REQUEST_METHOD'] == 'POST') {
	$eDate = worktime_sanitize_date(GETPOST('entry_date', 'alphanohtml'), '');
	$eTime = GETPOST('entry_time', 'alphanohtml');
	$eType = GETPOST('entry_type', 'aZ09');
	$eNote = trim(GETPOST('entry_note', 'alphanohtml'));
	if ($eDate === '' || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $eTime)) {
		setEventMessages($langs->trans('WorktimeErrorInvalidDate'), null, 'errors');
	} else {
		if ($eNote === '') {
			$eNote = $langs->transnoentitiesnoconv('WorktimeCorrectionBy', $user->login);
		}
		$r = $wt->addEntry($targetUserId, $eType, $eDate.' '.$eTime.':00', $eNote, (int) $user->id);
		if ($r > 0) {
			setEventMessages($langs->trans('WorktimeEntryAdded'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans($wt->error), null, 'errors');
		}
	}
	header('Location: '.$selfUrl.'?week='.urlencode($weekStart).'&fk_user='.$targetUserId);
	exit;
}
if ($isAdmin && $action == 'deleteentry' && $_SERVER['REQUEST_METHOD'] == 'POST') {
	$entry = $wt->fetchEntry(GETPOSTINT('entry_id'));
	if ($entry && (int) $entry['fk_user'] == $targetUserId && $wt->deleteEntry($entry['id']) > 0) {
		setEventMessages($langs->trans('WorktimeEntryDeleted'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('WorktimeErrorGeneric'), null, 'errors');
	}
	header('Location: '.$selfUrl.'?week='.urlencode($weekStart).'&fk_user='.$targetUserId);
	exit;
}

$report = $wt->getRangeReport($targetUserId, $weekStart, $weekEnd);
$totals = $report['totals'];
$typeLabels = array(
	'clock_in' => $langs->trans('WorktimeClockIn'),
	'clock_out' => $langs->trans('WorktimeClockOut'),
	'break_start' => $langs->trans('WorktimeBreakStart'),
	'break_end' => $langs->trans('WorktimeBreakEnd'),
);
$absLabels = array(
	'vacation' => $langs->trans('WorktimeAbsenceVacation'),
	'sick' => $langs->trans('WorktimeAbsenceSick'),
	'other' => $langs->trans('WorktimeAbsenceOther'),
);

/*
 * Ausgabe
 */
$title = $langs->trans('WorktimeWeeklyReport');
llxHeader('', $title, '', '', 0, 0, array(), array('/worktime/css/worktime.css'));
print load_fiche_titre($title.' – '.dol_escape_htmltag(worktime_user_name($targetUserId)), '', 'worktime@worktime');

// Navigation
$kw = (int) date('W', strtotime($weekStart));
print '<form method="GET" action="'.$selfUrl.'" class="wt-nav">';
print '<a class="butAction" href="'.$selfUrl.'?week='.worktime_date_add($weekStart, -7).'&fk_user='.$targetUserId.'">&laquo; '.$langs->trans('WorktimePrevWeek').'</a>';
print '<span class="wt-nav-title">'.$langs->trans('WorktimeCalendarWeek').' '.$kw.' / '.date('o', strtotime($weekStart)).'<br><span class="small opacitymedium">'.worktime_format_date($weekStart).' – '.worktime_format_date($weekEnd).'</span></span>';
print '<a class="butAction" href="'.$selfUrl.'?week='.worktime_date_add($weekStart, 7).'&fk_user='.$targetUserId.'">'.$langs->trans('WorktimeNextWeek').' &raquo;</a>';
print '<a class="butAction" href="'.$selfUrl.'?fk_user='.$targetUserId.'">'.$langs->trans('WorktimeToday').'</a>';
print '<input type="date" name="week" class="flat" value="'.dol_escape_htmltag($weekStart).'" onchange="this.form.submit()">';
$sel = worktime_user_selector($user, $targetUserId);
if ($sel !== '') {
	print $langs->trans('User').': '.$sel;
} else {
	print '<input type="hidden" name="fk_user" value="'.$targetUserId.'">';
}
print '</form>';

// Tabelle
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Date').'</th>';
print '<th class="center">'.$langs->trans('WorktimeColIn').'</th>';
print '<th class="center">'.$langs->trans('WorktimeColOut').'</th>';
print '<th class="center">'.$langs->trans('WorktimeColBreaks').'</th>';
print '<th class="right">'.$langs->trans('WorktimeColActual').'</th>';
print '<th class="right">'.$langs->trans('WorktimeColTarget').'</th>';
print '<th class="right">'.$langs->trans('WorktimeColDiff').'</th>';
print '<th>'.$langs->trans('WorktimeColRemark').'</th>';
print '</tr>';

$today = worktime_user_today();
foreach ($report['days'] as $d => $day) {
	$rowClass = 'oddeven';
	$remark = array();
	if ($day['absence']) {
		$a = $day['absence'];
		if ($a['status'] == 'approved') {
			$rowClass .= ' wt-row-'.$a['type'];
		}
		$remark[] = '<span class="wt-badge wt-badge-'.$a['type'].'">'.$absLabels[$a['type']].'</span>'.($a['status'] == 'pending' ? ' <span class="wt-badge wt-badge-pending">'.$langs->trans('WorktimeStatusPending').'</span>' : '');
	}
	if ($day['holiday'] !== '') {
		$rowClass .= ' wt-row-holiday';
		$remark[] = '<span class="wt-badge wt-badge-holiday">'.dol_escape_htmltag($day['holiday']).'</span>';
	} elseif ($day['weekend']) {
		$rowClass .= ' wt-row-weekend';
	}
	if ($d == $today) {
		$rowClass .= ' wt-row-today';
	}
	if ($day['incomplete']) {
		$remark[] = '<span class="wt-badge wt-badge-warning">'.$langs->trans('WorktimeMissingClockOut').'</span>';
	}

	$out = '';
	if ($day['last_out']) {
		$out = worktime_user_time_of_ts($day['last_out']);
	}
	if ($day['open'] && $d == $today) {
		$out = '<span class="opacitymedium">'.$langs->trans('WorktimeRunning').'</span>';
	}

	print '<tr class="'.$rowClass.'">';
	print '<td class="nowraponall">'.worktime_weekday_short($d).', '.worktime_format_date($d).'</td>';
	print '<td class="center">'.($day['first_in'] ? worktime_user_time_of_ts($day['first_in']) : '').'</td>';
	print '<td class="center">'.$out.'</td>';
	print '<td class="center">'.($day['break'] > 0 ? worktime_format_minutes($day['break']) : '').'</td>';
	print '<td class="right">'.($day['work'] > 0 ? worktime_format_minutes($day['work']) : '').'</td>';
	print '<td class="right">'.($day['target'] > 0 ? worktime_format_minutes($day['target']) : '').'</td>';
	if ($day['counted'] && ($day['diff'] != 0 || $day['target_counted'] > 0)) {
		print '<td class="right '.worktime_balance_class($day['diff']).'"><strong>'.worktime_format_minutes($day['diff'], true).'</strong></td>';
	} else {
		print '<td class="right"></td>';
	}
	print '<td>'.implode(' ', $remark).'</td>';
	print '</tr>';
}

print '<tr class="liste_total">';
print '<td>'.$langs->trans('WorktimeWeekTotal').'</td>';
print '<td></td><td></td>';
print '<td class="center">'.worktime_format_minutes($totals['break']).'</td>';
print '<td class="right">'.worktime_format_minutes($totals['actual']).'</td>';
print '<td class="right">'.worktime_format_minutes($totals['target_full']).'</td>';
print '<td class="right '.worktime_balance_class($totals['balance']).'"><strong>'.worktime_format_minutes($totals['balance'], true).'</strong></td>';
print '<td class="small opacitymedium">'.$langs->trans('WorktimeTargetToDate').': '.worktime_format_minutes($totals['target']).'</td>';
print '</tr>';
print '</table>';
print '</div>';

print '<div class="small opacitymedium margintoponly">'.$langs->trans('WorktimeBalanceHint').'</div>';

// Einzelbuchungen und Korrektur (Verwalter)
if ($isAdmin) {
	print '<br>';
	print load_fiche_titre($langs->trans('WorktimeEntriesAndCorrections'), '', '');

	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('WorktimeTime').'</th><th>'.$langs->trans('Type').'</th><th>'.$langs->trans('WorktimeNote').'</th><th class="right"></th></tr>';
	$hasEntries = false;
	foreach ($report['days'] as $d => $day) {
		foreach ($day['entries'] as $e) {
			$hasEntries = true;
			print '<tr class="oddeven">';
			print '<td>'.worktime_weekday_short($d).', '.worktime_format_date($d).'</td>';
			print '<td>'.worktime_user_time_of_ts($e['ts']).'</td>';
			print '<td>'.dol_escape_htmltag($typeLabels[$e['type']]).'</td>';
			print '<td>'.dol_escape_htmltag($e['note']).'</td>';
			print '<td class="right">';
			print '<form method="POST" action="'.$selfUrl.'" style="display:inline" onsubmit="return confirm(\''.dol_escape_js($langs->transnoentitiesnoconv('WorktimeConfirmDeleteEntry')).'\');">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="deleteentry">';
			print '<input type="hidden" name="entry_id" value="'.$e['id'].'">';
			print '<input type="hidden" name="week" value="'.$weekStart.'">';
			print '<input type="hidden" name="fk_user" value="'.$targetUserId.'">';
			print '<button type="submit" class="button buttonDelete small">'.$langs->trans('Delete').'</button>';
			print '</form>';
			print '</td>';
			print '</tr>';
		}
	}
	if (!$hasEntries) {
		print '<tr class="oddeven"><td colspan="5" class="opacitymedium">'.$langs->trans('WorktimeNoEntries').'</td></tr>';
	}
	print '</table>';

	print '<form method="POST" action="'.$selfUrl.'" class="margintoponly">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="addentry">';
	print '<input type="hidden" name="week" value="'.$weekStart.'">';
	print '<input type="hidden" name="fk_user" value="'.$targetUserId.'">';
	print '<strong>'.$langs->trans('WorktimeAddEntry').':</strong> ';
	print '<input type="date" name="entry_date" class="flat" value="'.dol_escape_htmltag(min(max($today, $weekStart), $weekEnd)).'" min="'.$weekStart.'" max="'.$weekEnd.'" required> ';
	print '<input type="time" name="entry_time" class="flat" required> ';
	print '<select name="entry_type" class="flat">';
	foreach ($typeLabels as $k => $v) {
		print '<option value="'.$k.'">'.dol_escape_htmltag($v).'</option>';
	}
	print '</select> ';
	print '<input type="text" name="entry_note" class="flat minwidth200" maxlength="250" placeholder="'.dol_escape_htmltag($langs->trans('WorktimeNoteOptional')).'"> ';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('Add')).'">';
	print '</form>';
}

llxFooter();
$db->close();
