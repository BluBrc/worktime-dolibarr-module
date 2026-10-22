<?php
/* Modul Worktime – Monatsauswertung mit Zusammenfassung und kumuliertem Saldo */

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
dol_include_once('/worktime/class/worktimeabsence.class.php');

$langs->loadLangs(array('worktime@worktime'));

if (!isModEnabled('worktime')) {
	accessforbidden($langs->trans('WorktimeErrorModuleDisabled'));
}
if (!worktime_can_read($user)) {
	accessforbidden();
}

$year = GETPOSTINT('year');
$month = GETPOSTINT('month');
$curYear = (int) worktime_user_now('%Y');
if ($year < 2000 || $year > 2100) {
	$year = $curYear;
}
if ($month < 1 || $month > 12) {
	$month = (int) worktime_user_now('%m');
}
$targetUserId = GETPOSTINT('fk_user') > 0 ? GETPOSTINT('fk_user') : (int) $user->id;
if (!worktime_can_view_user($user, $targetUserId)) {
	accessforbidden();
}

$from = sprintf('%04d-%02d-01', $year, $month);
$to = date('Y-m-t', strtotime($from));
$prevMonth = date('Y-m-d', strtotime($from.' -1 month'));
$nextMonth = date('Y-m-d', strtotime($from.' +1 month'));

$wt = new Worktime($db);
$abs = new WorktimeAbsence($db);
$report = $wt->getRangeReport($targetUserId, $from, $to);
$totals = $report['totals'];
$carry = $wt->getAccumulatedBalance($targetUserId, $from);
$cumulated = $carry + $totals['balance'];
$vac = $abs->getVacationStats($targetUserId, $year);

$absLabels = array(
	'vacation' => $langs->trans('WorktimeAbsenceVacation'),
	'sick' => $langs->trans('WorktimeAbsenceSick'),
	'other' => $langs->trans('WorktimeAbsenceOther'),
);
$selfUrl = dol_buildpath('/worktime/report_monthly.php', 1);

/*
 * Ausgabe
 */
$title = $langs->trans('WorktimeMonthlyReport');
llxHeader('', $title, '', '', 0, 0, array(), array('/worktime/css/worktime.css'));
print load_fiche_titre($title.' – '.dol_escape_htmltag(worktime_user_name($targetUserId)), '', 'worktime@worktime');

// Navigation
print '<form method="GET" action="'.$selfUrl.'" class="wt-nav">';
print '<a class="butAction" href="'.$selfUrl.'?year='.substr($prevMonth, 0, 4).'&month='.(int) substr($prevMonth, 5, 2).'&fk_user='.$targetUserId.'">&laquo; '.$langs->trans('WorktimePrevMonth').'</a>';
print '<span class="wt-nav-title">'.worktime_month_name($month).' '.$year.'</span>';
print '<a class="butAction" href="'.$selfUrl.'?year='.substr($nextMonth, 0, 4).'&month='.(int) substr($nextMonth, 5, 2).'&fk_user='.$targetUserId.'">'.$langs->trans('WorktimeNextMonth').' &raquo;</a>';
print '<select name="month" class="flat" onchange="this.form.submit()">';
for ($m = 1; $m <= 12; $m++) {
	print '<option value="'.$m.'"'.($m == $month ? ' selected' : '').'>'.worktime_month_name($m).'</option>';
}
print '</select>';
print '<select name="year" class="flat" onchange="this.form.submit()">';
for ($y = $curYear - 5; $y <= $curYear + 1; $y++) {
	print '<option value="'.$y.'"'.($y == $year ? ' selected' : '').'>'.$y.'</option>';
}
print '</select>';
$sel = worktime_user_selector($user, $targetUserId);
if ($sel !== '') {
	print $langs->trans('User').': '.$sel;
} else {
	print '<input type="hidden" name="fk_user" value="'.$targetUserId.'">';
}
print '</form>';

// Zusammenfassung
$summary = array(
	array('WorktimeSumWorkdays', $totals['workdays']),
	array('WorktimeSumWorkedDays', $totals['worked_days']),
	array('WorktimeSumVacationDays', $totals['vacation_days']),
	array('WorktimeSumSickDays', $totals['sick_days']),
	array('WorktimeSumOtherDays', $totals['other_days']),
	array('WorktimeSumHolidays', $totals['holidays']),
	array('WorktimeSumActual', worktime_format_minutes($totals['actual'])),
	array('WorktimeSumTarget', worktime_format_minutes($totals['target_full']).'<div class="small opacitymedium">'.$langs->trans('WorktimeTargetToDate').': '.worktime_format_minutes($totals['target']).'</div>'),
	array('WorktimeSumMonthBalance', '<span class="'.worktime_balance_class($totals['balance']).'">'.worktime_format_minutes($totals['balance'], true).'</span>'),
	array('WorktimeSumCarry', '<span class="'.worktime_balance_class($carry).'">'.worktime_format_minutes($carry, true).'</span>'),
	array('WorktimeSumCumulated', '<span class="'.worktime_balance_class($cumulated).'">'.worktime_format_minutes($cumulated, true).'</span>'),
	array('WorktimeVacationRemaining', price2num($vac['remaining'], 1).' '.$langs->trans('WorktimeDays').' ('.$year.')'),
);
print '<div class="wt-summary">';
foreach ($summary as $s) {
	print '<div class="wt-kpi"><div class="wt-kpi-label">'.$langs->trans($s[0]).'</div><div class="wt-kpi-value">'.$s[1].'</div></div>';
}
print '</div>';
if ($totals['incomplete_days'] > 0) {
	print '<div class="warning">'.$langs->trans('WorktimeIncompleteDaysWarning', $totals['incomplete_days']).'</div>';
}

// Tagestabelle
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
print '<th class="right">'.$langs->trans('WorktimeColRunningBalance').'</th>';
print '<th>'.$langs->trans('WorktimeColRemark').'</th>';
print '</tr>';

$today = worktime_user_today();
$running = $carry;
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
	$out = $day['last_out'] ? worktime_user_time_of_ts($day['last_out']) : '';
	if ($day['open'] && $d == $today) {
		$out = '<span class="opacitymedium">'.$langs->trans('WorktimeRunning').'</span>';
	}
	$running += $day['diff'];

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
	print '<td class="right '.($day['counted'] ? worktime_balance_class($running) : '').'">'.($day['counted'] ? worktime_format_minutes($running, true) : '').'</td>';
	print '<td>'.implode(' ', $remark).'</td>';
	print '</tr>';
}

print '<tr class="liste_total">';
print '<td>'.$langs->trans('WorktimeMonthTotal').'</td><td></td><td></td>';
print '<td class="center">'.worktime_format_minutes($totals['break']).'</td>';
print '<td class="right">'.worktime_format_minutes($totals['actual']).'</td>';
print '<td class="right">'.worktime_format_minutes($totals['target_full']).'</td>';
print '<td class="right '.worktime_balance_class($totals['balance']).'"><strong>'.worktime_format_minutes($totals['balance'], true).'</strong></td>';
print '<td class="right '.worktime_balance_class($cumulated).'"><strong>'.worktime_format_minutes($cumulated, true).'</strong></td>';
print '<td></td>';
print '</tr>';
print '</table>';
print '</div>';

print '<div class="small opacitymedium margintoponly">'.$langs->trans('WorktimeBalanceHint').'</div>';

print '<div class="tabsAction">';
print '<a class="butAction" href="javascript:window.print()">'.$langs->trans('WorktimePrint').'</a>';
print '</div>';

llxFooter();
$db->close();
