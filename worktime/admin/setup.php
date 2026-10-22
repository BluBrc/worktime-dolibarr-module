<?php
/* Modul Worktime – Admin-Konfiguration
 *  - Standard-Wochenstunden, Standard-Urlaubstage, Attestpflicht, Resturlaub-Stichtag
 *  - Individuelle Einstellungen pro Benutzer
 *  - Feiertage (Bundesland + manuelle Liste)
 */

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
        die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/worktime/class/worktime.class.php');

$langs->loadLangs(array('admin', 'worktime@worktime'));

if (!worktime_is_admin($user)) {
        accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$tab = GETPOST('tab', 'aZ09');
if (!in_array($tab, array('settings', 'users', 'holidays'))) {
        $tab = 'settings';
}
$selfUrl = dol_buildpath('/worktime/admin/setup.php', 1);
$wt = new Worktime($db);

/*
 * Aktionen (POST, CSRF-Token wird von main.inc.php geprüft)
 */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $error = 0;
        if ($action == 'savesettings') {
                $weekly = price2num(GETPOST('WORKTIME_DEFAULT_WEEKLY_HOURS', 'alphanohtml'));
                $vac = GETPOSTINT('WORKTIME_DEFAULT_VACATION_DAYS');
                $cert = GETPOSTINT('WORKTIME_SICK_CERT_DAYS');
                $carry = GETPOST('WORKTIME_VACATION_CARRYOVER_DATE', 'alphanohtml');
                if (!is_numeric($weekly) || $weekly < 0 || $weekly > 99) {
                        $error++;
                        setEventMessages($langs->trans('WorktimeErrorWeeklyHours'), null, 'errors');
                }
                if ($vac < 0 || $vac > 365) {
                        $error++;
                        setEventMessages($langs->trans('WorktimeErrorVacationDays'), null, 'errors');
                }
                if (!preg_match('/^(\d{2})-(\d{2})$/', $carry, $m) || !checkdate((int) $m[1], (int) $m[2], 2001)) {
                        $error++;
                        setEventMessages($langs->trans('WorktimeErrorCarryoverDate'), null, 'errors');
                }
                if (!$error) {
                        dolibarr_set_const($db, 'WORKTIME_DEFAULT_WEEKLY_HOURS', $weekly, 'chaine', 0, '', $conf->entity);
                        dolibarr_set_const($db, 'WORKTIME_DEFAULT_VACATION_DAYS', $vac, 'chaine', 0, '', $conf->entity);
                        dolibarr_set_const($db, 'WORKTIME_SICK_CERT_DAYS', max(0, $cert), 'chaine', 0, '', $conf->entity);
                        dolibarr_set_const($db, 'WORKTIME_VACATION_CARRYOVER_DATE', $carry, 'chaine', 0, '', $conf->entity);
                        setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
                }
        } elseif ($action == 'saveusers') {
                $weeklyArr = GETPOST('weekly_hours', 'array');
                $vacArr = GETPOST('vacation_days', 'array');
                $adjArr = GETPOST('vacation_adjust', 'array');
                $startArr = GETPOST('start_date', 'array');
                $customArr = GETPOST('custom', 'array');
                $saved = 0;
                if (is_array($weeklyArr)) {
                        foreach ($weeklyArr as $uid => $w) {
                                $uid = (int) $uid;
                                if ($uid <= 0) {
                                        continue;
                                }
                                if (empty($customArr[$uid])) {
                                        $wt->deleteUserSettings($uid);
                                        continue;
                                }
                                $w = price2num($w);
                                $v = isset($vacArr[$uid]) ? (int) $vacArr[$uid] : 30;
                                $adj = isset($adjArr[$uid]) ? price2num($adjArr[$uid]) : 0;
                                $sd = isset($startArr[$uid]) ? worktime_sanitize_date($startArr[$uid], '') : '';
                                if (!is_numeric($w) || $w < 0 || $w > 99) {
                                        $error++;
                                        setEventMessages($langs->trans('WorktimeErrorWeeklyHours').' ('.dol_escape_htmltag(worktime_user_name($uid)).')', null, 'errors');
                                        continue;
                                }
                                if ($wt->saveUserSettings($uid, (float) $w, $v, (float) (is_numeric($adj) ? $adj : 0), $sd !== '' ? $sd : null) > 0) {
                                        $saved++;
                                } else {
                                        $error++;
                                }
                        }
                }
                if (!$error) {
                        setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
                }
        } elseif ($action == 'saveholidays') {
                $state = GETPOST('WORKTIME_HOLIDAY_STATE', 'aZ09');
                if (!array_key_exists($state, worktime_get_states())) {
                        $state = 'CY';
                }
                $manual = GETPOST('WORKTIME_HOLIDAYS_MANUAL', 'restricthtml');
                $lines = array();
                foreach (preg_split('/\r\n|\r|\n/', (string) $manual) as $line) {
                        $line = trim(strip_tags($line));
                        if ($line !== '') {
                                $lines[] = dol_trunc($line, 120, 'right', 'UTF-8', 1);
                        }
                }
                dolibarr_set_const($db, 'WORKTIME_HOLIDAY_STATE', $state, 'chaine', 0, '', $conf->entity);
                dolibarr_set_const($db, 'WORKTIME_HOLIDAYS_MANUAL', implode("\n", $lines), 'chaine', 0, '', $conf->entity);
                setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
        }
        header('Location: '.$selfUrl.'?tab='.$tab);
        exit;
}

/*
 * Ausgabe
 */
$title = $langs->trans('WorktimeSetupPage');
llxHeader('', $title, '', '', 0, 0, array(), array('/worktime/css/worktime.css'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = worktimeAdminPrepareHead();
print dol_get_fiche_head($head, $tab, '', -1, 'worktime@worktime');

if ($tab == 'settings') {
        print '<form method="POST" action="'.$selfUrl.'">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="savesettings">';
        print '<input type="hidden" name="tab" value="settings">';
        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre"><th>'.$langs->trans('Parameter').'</th><th>'.$langs->trans('Value').'</th></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('WorktimeDefaultWeeklyHours').'<div class="small opacitymedium">'.$langs->trans('WorktimeDefaultWeeklyHoursHelp').'</div></td>';
        print '<td><input type="number" step="0.25" min="0" max="99" name="WORKTIME_DEFAULT_WEEKLY_HOURS" class="flat width75" value="'.dol_escape_htmltag(getDolGlobalString('WORKTIME_DEFAULT_WEEKLY_HOURS', '40')).'"> h</td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('WorktimeDefaultVacationDays').'</td>';
        print '<td><input type="number" step="1" min="0" max="365" name="WORKTIME_DEFAULT_VACATION_DAYS" class="flat width75" value="'.dol_escape_htmltag(getDolGlobalString('WORKTIME_DEFAULT_VACATION_DAYS', '30')).'"> '.$langs->trans('WorktimeDays').'</td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('WorktimeSickCertDays').'<div class="small opacitymedium">'.$langs->trans('WorktimeSickCertDaysHelp').'</div></td>';
        print '<td><input type="number" step="1" min="0" max="30" name="WORKTIME_SICK_CERT_DAYS" class="flat width75" value="'.dol_escape_htmltag(getDolGlobalString('WORKTIME_SICK_CERT_DAYS', '3')).'"> '.$langs->trans('WorktimeDays').'</td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('WorktimeCarryoverDate').'<div class="small opacitymedium">'.$langs->trans('WorktimeCarryoverDateHelp').'</div></td>';
        print '<td><input type="text" pattern="\d{2}-\d{2}" name="WORKTIME_VACATION_CARRYOVER_DATE" class="flat width75" value="'.dol_escape_htmltag(getDolGlobalString('WORKTIME_VACATION_CARRYOVER_DATE', '03-31')).'"> (MM-TT)</td></tr>';
        print '</table>';
        print '<div class="center margintoponly"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
        print '</form>';

        print '<br><div class="info">'.$langs->trans('WorktimeRightsInfo').'</div>';
} elseif ($tab == 'users') {
        print '<div class="opacitymedium">'.$langs->trans('WorktimeUserSettingsHelp').'</div><br>';
        print '<form method="POST" action="'.$selfUrl.'">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="saveusers">';
        print '<input type="hidden" name="tab" value="users">';
        print '<div class="div-table-responsive">';
        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<th>'.$langs->trans('User').'</th>';
        print '<th class="center">'.$langs->trans('WorktimeIndividual').'</th>';
        print '<th>'.$langs->trans('WorktimeWeeklyHours').'</th>';
        print '<th>'.$langs->trans('WorktimeVacationDaysYear').'</th>';
        print '<th>'.$langs->trans('WorktimeVacationAdjustment').'</th>';
        print '<th>'.$langs->trans('WorktimeBalanceStart').'</th>';
        print '<th class="right">'.$langs->trans('WorktimeAccountTotal').'</th>';
        print '</tr>';

        $sql = "SELECT rowid, firstname, lastname, login FROM ".MAIN_DB_PREFIX."user";
        // Hinweis: Die DB-Spalte in llx_user heißt auch in Dolibarr 23 weiterhin 'statut' (nur das PHP-Attribut heißt $user->status).
        $sql .= " WHERE statut = 1 AND entity IN (0, ".((int) $conf->entity).")";
        $sql .= " ORDER BY lastname, firstname";
        $resql = $db->query($sql);
        if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                        $uid = (int) $obj->rowid;
                        $s = $wt->getUserSettings($uid);
                        $name = trim($obj->firstname.' '.$obj->lastname);
                        $balance = $wt->getAccumulatedBalance($uid, worktime_date_add(date('Y-m-d'), 1));
                        print '<tr class="oddeven">';
                        print '<td>'.dol_escape_htmltag($name !== '' ? $name : $obj->login).' <span class="opacitymedium small">('.dol_escape_htmltag($obj->login).')</span></td>';
                        print '<td class="center"><input type="checkbox" name="custom['.$uid.']" value="1"'.($s['custom'] ? ' checked' : '').'></td>';
                        print '<td><input type="number" step="0.25" min="0" max="99" class="flat width75" name="weekly_hours['.$uid.']" value="'.dol_escape_htmltag($s['weekly_hours']).'"> h</td>';
                        print '<td><input type="number" step="1" min="0" max="365" class="flat width75" name="vacation_days['.$uid.']" value="'.dol_escape_htmltag($s['vacation_days_year']).'"></td>';
                        print '<td><input type="number" step="0.5" min="-365" max="365" class="flat width75" name="vacation_adjust['.$uid.']" value="'.dol_escape_htmltag($s['vacation_carryover']).'"></td>';
                        print '<td><input type="date" class="flat" name="start_date['.$uid.']" value="'.dol_escape_htmltag((string) $s['balance_start_date']).'"></td>';
                        print '<td class="right '.worktime_balance_class($balance).'">'.worktime_format_minutes($balance, true).'</td>';
                        print '</tr>';
                }
        } else {
                dol_print_error($db);
        }
        print '</table>';
        print '</div>';
        print '<div class="center margintoponly"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
        print '</form>';
} else {
        $state = getDolGlobalString('WORKTIME_HOLIDAY_STATE', 'CY');
        $manual = getDolGlobalString('WORKTIME_HOLIDAYS_MANUAL');
        print '<form method="POST" action="'.$selfUrl.'">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="saveholidays">';
        print '<input type="hidden" name="tab" value="holidays">';
        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre"><th>'.$langs->trans('Parameter').'</th><th>'.$langs->trans('Value').'</th></tr>';
        print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('WorktimeHolidayState').'</td><td><select name="WORKTIME_HOLIDAY_STATE" class="flat minwidth300">';
        foreach (worktime_get_states() as $code => $label) {
                print '<option value="'.$code.'"'.($code == $state ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
        }
        print '</select></td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('WorktimeHolidaysManual').'<div class="small opacitymedium">'.$langs->trans('WorktimeHolidaysManualHelp').'</div></td>';
        print '<td><textarea name="WORKTIME_HOLIDAYS_MANUAL" rows="8" class="flat quatrevingtpercent">'.dol_escape_htmltag($manual).'</textarea></td></tr>';
        print '</table>';
        print '<div class="center margintoponly"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
        print '</form>';

        // Vorschau
        $pyear = GETPOSTINT('pyear');
        if ($pyear < 2000 || $pyear > 2100) {
                $pyear = (int) worktime_user_now('%Y');
        }
        print '<br>';
        print load_fiche_titre($langs->trans('WorktimeHolidayPreview').' '.$pyear, '<a href="'.$selfUrl.'?tab=holidays&pyear='.($pyear - 1).'">&laquo; '.($pyear - 1).'</a> | <a href="'.$selfUrl.'?tab=holidays&pyear='.($pyear + 1).'">'.($pyear + 1).' &raquo;</a>', '');
        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre"><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('WorktimeHoliday').'</th></tr>';
        $hol = worktime_get_holidays($pyear);
        if (empty($hol)) {
                print '<tr class="oddeven"><td colspan="2" class="opacitymedium">'.$langs->trans('WorktimeNoHolidays').'</td></tr>';
        }
        foreach ($hol as $d => $name) {
                print '<tr class="oddeven"><td>'.worktime_weekday_short($d).', '.worktime_format_date($d).'</td><td>'.dol_escape_htmltag($name).'</td></tr>';
        }
        print '</table>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
