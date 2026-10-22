<?php
/* Modul Worktime – Abwesenheiten: Urlaub beantragen, Krankheit melden, Anträge genehmigen */

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

$action = GETPOST('action', 'aZ09');
$mode = GETPOST('mode', 'aZ09');
$year = GETPOSTINT('year');
if ($year < 2000 || $year > 2100) {
	$year = (int) worktime_user_now('%Y');
}
$filterStatus = GETPOST('filter_status', 'aZ09');
if ($filterStatus === '' && $mode == 'manage' && !isset($_GET['filter_status'])) {
	$filterStatus = 'pending';
}
if ($filterStatus == 'all') {
	$filterStatus = '';
}

$isAdmin = worktime_is_admin($user);
$subordinates = worktime_get_subordinates($user->id);
$canManage = ($isAdmin || !empty($subordinates));
if ($mode == 'manage' && !$canManage) {
	accessforbidden();
}

$selfUrl = dol_buildpath('/worktime/absence.php', 1);
$backParams = 'mode='.urlencode($mode).'&year='.$year.'&filter_status='.urlencode($filterStatus === '' ? 'all' : $filterStatus);

$absence = new WorktimeAbsence($db);

/*
 * Aktionen (alle per POST; CSRF-Token wird von main.inc.php geprüft)
 */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action != '') {
	if ($action == 'add' && worktime_can_write($user)) {
		$forUser = (int) $user->id;
		if ($isAdmin && GETPOSTINT('fk_user') > 0) {
			$forUser = GETPOSTINT('fk_user');
		}
		$a = new WorktimeAbsence($db);
		$a->fk_user = $forUser;
		$a->absence_type = GETPOST('absence_type', 'aZ09');
		$a->date_start = GETPOST('date_start', 'alphanohtml');
		$a->date_end = GETPOST('date_end', 'alphanohtml');
		$a->note = trim(GETPOST('note', 'restricthtml'));
		$r = $a->create($user, $isAdmin);
		if ($r > 0) {
			if ($a->absence_type == 'sick') {
				$msg = $langs->trans('WorktimeSickRecorded');
				if ($a->document_required) {
					$msg .= ' '.$langs->trans('WorktimeCertificateRequiredInfo');
				}
				setEventMessages($msg, null, $a->document_required ? 'warnings' : 'mesgs');
			} else {
				setEventMessages($langs->trans('WorktimeRequestSubmitted'), null, 'mesgs');
			}
		} else {
			setEventMessages($langs->trans($a->error), null, 'errors');
		}
	} elseif (in_array($action, array('approve', 'reject', 'delete', 'docreceived', 'docmissing'))) {
		$id = GETPOSTINT('id');
		if ($absence->fetch($id) > 0) {
			$ok = false;
			if (($action == 'approve' || $action == 'reject') && worktime_can_approve($user, $absence->fk_user)) {
				$ok = ($absence->setStatus($user, $action == 'approve' ? 'approved' : 'rejected') > 0);
				$okMsg = $action == 'approve' ? 'WorktimeRequestApproved' : 'WorktimeRequestRejected';
			} elseif ($action == 'delete' && ($isAdmin || ($absence->fk_user == (int) $user->id && $absence->status == 'pending'))) {
				$ok = ($absence->delete() > 0);
				$okMsg = 'WorktimeAbsenceDeleted';
			} elseif (($action == 'docreceived' || $action == 'docmissing') && worktime_can_approve($user, $absence->fk_user)) {
				$ok = ($absence->setDocumentReceived($action == 'docreceived' ? 1 : 0) > 0);
				$okMsg = 'WorktimeSaved';
			} else {
				$absence->error = 'WorktimeErrorNoPermission';
			}
			if ($ok) {
				setEventMessages($langs->trans($okMsg), null, 'mesgs');
			} else {
				setEventMessages($langs->trans($absence->error ? $absence->error : 'WorktimeErrorGeneric'), null, 'errors');
			}
		} else {
			setEventMessages($langs->trans('WorktimeErrorNotFound'), null, 'errors');
		}
	}
	header('Location: '.$selfUrl.'?'.$backParams);
	exit;
}

$typeLabels = array(
	'vacation' => $langs->trans('WorktimeAbsenceVacation'),
	'sick' => $langs->trans('WorktimeAbsenceSick'),
	'other' => $langs->trans('WorktimeAbsenceOther'),
);
$statusLabels = array(
	'pending' => $langs->trans('WorktimeStatusPending'),
	'approved' => $langs->trans('WorktimeStatusApproved'),
	'rejected' => $langs->trans('WorktimeStatusRejected'),
);

/**
 * Kleiner POST-Button für eine Aktion
 *
 * @param  string $action  Aktion
 * @param  int    $id      Abwesenheits-ID
 * @param  string $label   Beschriftung
 * @param  string $class   CSS-Klasse
 * @param  string $confirm Bestätigungstext (optional)
 * @param  string $back    Rücksprung-Parameter
 * @return string
 */
function worktime_action_button($action, $id, $label, $class, $confirm, $back)
{
	parse_str($back, $params);
	$out = '<form method="POST" action="'.dol_buildpath('/worktime/absence.php', 1).'" style="display:inline"';
	if ($confirm !== '') {
		$out .= ' onsubmit="return confirm(\''.dol_escape_js($confirm).'\');"';
	}
	$out .= '>';
	$out .= '<input type="hidden" name="token" value="'.newToken().'">';
	$out .= '<input type="hidden" name="action" value="'.dol_escape_htmltag($action).'">';
	$out .= '<input type="hidden" name="id" value="'.((int) $id).'">';
	foreach ($params as $k => $v) {
		$out .= '<input type="hidden" name="'.dol_escape_htmltag($k).'" value="'.dol_escape_htmltag($v).'">';
	}
	$out .= '<button type="submit" class="button small '.$class.'">'.dol_escape_htmltag($label).'</button>';
	$out .= '</form> ';
	return $out;
}

/*
 * Ausgabe
 */
$title = $langs->trans('WorktimeAbsences');
llxHeader('', $title, '', '', 0, 0, array(), array('/worktime/css/worktime.css'));
print load_fiche_titre($title, '', 'worktime@worktime');

// Tabs
$head = array();
$head[0] = array($selfUrl.'?mode=own', $langs->trans('WorktimeMyAbsences'), 'own');
if ($canManage) {
	$head[1] = array($selfUrl.'?mode=manage', $langs->trans('WorktimeManageRequests'), 'manage');
}
print dol_get_fiche_head($head, ($mode == 'manage' ? 'manage' : 'own'), '', -1, '');

if ($mode != 'manage') {
	// Urlaubskonto
	$stats = $absence->getVacationStats((int) $user->id, $year);
	print '<div class="wt-summary">';
	$items = array(
		array('WorktimeVacationEntitlement', $stats['entitlement']),
		array('WorktimeVacationCarryover', $stats['carryover'].($stats['carryover'] > 0 ? '<div class="small opacitymedium">'.$langs->trans('WorktimeValidUntil', worktime_format_date($stats['deadline'])).'</div>' : '')),
		array('WorktimeVacationAdjustment', price2num($stats['adjustment'], 1)),
		array('WorktimeVacationExpired', $stats['carryover_expired']),
		array('WorktimeVacationTaken', $stats['taken']),
		array('WorktimeVacationPending', $stats['pending']),
		array('WorktimeVacationRemaining', '<strong>'.price2num($stats['remaining'], 1).'</strong>'),
	);
	foreach ($items as $it) {
		print '<div class="wt-kpi"><div class="wt-kpi-label">'.$langs->trans($it[0]).' ('.$year.')</div><div class="wt-kpi-value">'.$it[1].'</div></div>';
	}
	print '</div>';

	// Formular
	if (worktime_can_write($user)) {
		print load_fiche_titre($langs->trans('WorktimeNewAbsence'), '', '');
		print '<form method="POST" action="'.$selfUrl.'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="add">';
		print '<input type="hidden" name="mode" value="own">';
		print '<input type="hidden" name="year" value="'.$year.'">';
		print '<table class="border centpercent tableforfieldcreate">';
		if ($isAdmin) {
			print '<tr><td class="titlefieldcreate">'.$langs->trans('User').'</td><td>'.worktime_user_selector($user, (int) $user->id, 'fk_user', false).'</td></tr>';
		}
		print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('WorktimeAbsenceType').'</td><td>';
		print '<select name="absence_type" class="flat minwidth200" id="wt-abs-type">';
		foreach ($typeLabels as $k => $v) {
			print '<option value="'.$k.'">'.dol_escape_htmltag($v).'</option>';
		}
		print '</select>';
		print ' <span class="small opacitymedium" id="wt-abs-hint">'.$langs->trans('WorktimeHintVacation').'</span>';
		print '</td></tr>';
		print '<tr><td class="fieldrequired">'.$langs->trans('WorktimeDateFrom').'</td><td><input type="date" name="date_start" class="flat" required value="'.worktime_user_today().'"></td></tr>';
		print '<tr><td class="fieldrequired">'.$langs->trans('WorktimeDateTo').'</td><td><input type="date" name="date_end" class="flat" required value="'.worktime_user_today().'"></td></tr>';
		print '<tr><td>'.$langs->trans('WorktimeNote').'</td><td><textarea name="note" class="flat quatrevingtpercent" rows="2" maxlength="2000"></textarea></td></tr>';
		print '</table>';
		print '<div class="center margintoponly"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('WorktimeSubmit')).'"></div>';
		print '</form>';
		$hints = array(
			'vacation' => $langs->transnoentitiesnoconv('WorktimeHintVacation'),
			'sick' => $langs->transnoentitiesnoconv('WorktimeHintSick'),
			'other' => $langs->transnoentitiesnoconv('WorktimeHintOther'),
		);
		print '<script>(function(){var s=document.getElementById("wt-abs-type"),h=document.getElementById("wt-abs-hint"),t='.json_encode($hints).';if(s&&h){s.addEventListener("change",function(){h.textContent=t[s.value]||"";});}})();</script>';
	}
}

// Filter
print '<form method="GET" action="'.$selfUrl.'" class="wt-nav">';
print '<input type="hidden" name="mode" value="'.dol_escape_htmltag($mode == 'manage' ? 'manage' : 'own').'">';
print $langs->trans('Year').': <select name="year" class="flat" onchange="this.form.submit()">';
$curYear = (int) worktime_user_now('%Y');
for ($y = $curYear - 5; $y <= $curYear + 1; $y++) {
	print '<option value="'.$y.'"'.($y == $year ? ' selected' : '').'>'.$y.'</option>';
}
print '</select> ';
print $langs->trans('Status').': <select name="filter_status" class="flat" onchange="this.form.submit()">';
print '<option value="all"'.($filterStatus === '' ? ' selected' : '').'>'.$langs->trans('All').'</option>';
foreach ($statusLabels as $k => $v) {
	print '<option value="'.$k.'"'.($filterStatus === $k ? ' selected' : '').'>'.dol_escape_htmltag($v).'</option>';
}
print '</select>';
print '</form>';

// Liste
if ($mode == 'manage') {
	$list = $absence->fetchList($isAdmin ? 0 : $subordinates, $filterStatus, $filterStatus == 'pending' ? 0 : $year);
} else {
	$list = $absence->fetchList((int) $user->id, $filterStatus, $year);
}

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
if ($mode == 'manage') {
	print '<th>'.$langs->trans('User').'</th>';
}
print '<th>'.$langs->trans('WorktimeAbsenceType').'</th>';
print '<th>'.$langs->trans('WorktimeDateFrom').'</th>';
print '<th>'.$langs->trans('WorktimeDateTo').'</th>';
print '<th class="center">'.$langs->trans('WorktimeWorkdays').'</th>';
print '<th>'.$langs->trans('Status').'</th>';
print '<th>'.$langs->trans('WorktimeCertificate').'</th>';
print '<th>'.$langs->trans('WorktimeNote').'</th>';
print '<th>'.$langs->trans('WorktimeApprovedBy').'</th>';
print '<th class="right">'.$langs->trans('Action').'</th>';
print '</tr>';

if (empty($list)) {
	print '<tr class="oddeven"><td colspan="'.($mode == 'manage' ? 10 : 9).'" class="opacitymedium">'.$langs->trans('WorktimeNoAbsences').'</td></tr>';
}
foreach ($list as $a) {
	print '<tr class="oddeven">';
	if ($mode == 'manage') {
		print '<td>'.dol_escape_htmltag(worktime_user_name($a->fk_user)).'</td>';
	}
	print '<td><span class="wt-badge wt-badge-'.$a->absence_type.'">'.$typeLabels[$a->absence_type].'</span></td>';
	print '<td>'.worktime_format_date($a->date_start).'</td>';
	print '<td>'.worktime_format_date($a->date_end).'</td>';
	print '<td class="center">'.$a->getWorkdays().'</td>';
	print '<td><span class="wt-badge wt-badge-'.$a->status.'">'.$statusLabels[$a->status].'</span></td>';
	print '<td>';
	if ($a->document_required) {
		print $a->document_received ? '<span class="wt-badge wt-badge-approved">'.$langs->trans('WorktimeCertificateReceived').'</span>' : '<span class="wt-badge wt-badge-warning">'.$langs->trans('WorktimeCertificateRequired').'</span>';
	}
	print '</td>';
	print '<td>'.dol_escape_htmltag(dol_trunc((string) $a->note, 60)).'</td>';
	print '<td>'.($a->approved_by > 0 && $a->status != 'pending' ? dol_escape_htmltag(worktime_user_name($a->approved_by)) : '').'</td>';
	print '<td class="right nowraponall">';
	$canApprove = worktime_can_approve($user, $a->fk_user);
	if ($canApprove && $a->status == 'pending') {
		print worktime_action_button('approve', $a->id, $langs->trans('WorktimeApprove'), '', '', $backParams);
		print worktime_action_button('reject', $a->id, $langs->trans('WorktimeReject'), 'buttonDelete', $langs->transnoentitiesnoconv('WorktimeConfirmReject'), $backParams);
	}
	if ($canApprove && $a->document_required) {
		if ($a->document_received) {
			print worktime_action_button('docmissing', $a->id, $langs->trans('WorktimeMarkCertificateMissing'), '', '', $backParams);
		} else {
			print worktime_action_button('docreceived', $a->id, $langs->trans('WorktimeMarkCertificateReceived'), '', '', $backParams);
		}
	}
	if ($isAdmin || ($a->fk_user == (int) $user->id && $a->status == 'pending')) {
		print worktime_action_button('delete', $a->id, $langs->trans('Delete'), 'buttonDelete', $langs->transnoentitiesnoconv('WorktimeConfirmDeleteAbsence'), $backParams);
	}
	print '</td>';
	print '</tr>';
}
print '</table>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
