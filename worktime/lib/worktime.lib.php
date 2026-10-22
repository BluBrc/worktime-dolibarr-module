<?php
/* Modul Worktime – Hilfsfunktionen (Feiertage, Formatierung, Tabs, Rechte)
 *
 * Hinweis zu Zeitzonen: Zeitstempel werden immer als UTC in der Datenbank
 * gespeichert. Für Anzeige und Tagesabgrenzung wird die Zeitzone des
 * angemeldeten Benutzers verwendet (Dolibarr-Session "dol_tz_string",
 * wie bei Dolibarr-Kernfunktionen; Fallback: MAIN_DOLIBARR_USER_TIMEZONE
 * bzw. UTC). Die PHP-Serverzeitzone (date.timezone) spielt keine Rolle.
 */

/**
 * Liste der unterstützten Feiertagsregeln (Code => Name)
 *
 * @return array
 */
function worktime_get_states()
{
        global $langs;
        return array(
                'CY'     => $langs->trans('WorktimeStateCY'),
                'MANUAL' => $langs->trans('WorktimeStateMANUAL'),
        );
}

/**
 * Orthodoxes Osterdatum (Julianischer Kalender + 13 Tage → Gregorianisch).
 * Gültig für 1900–2099.
 *
 * Überprüft: 2024 → 5. Mai, 2025 → 20. April
 *
 * @param  int    $year Jahr (4-stellig)
 * @return string       Datum JJJJ-MM-TT (Gregorianisch)
 */
function worktime_orthodox_easter_date($year)
{
        $a = $year % 4;
        $b = $year % 7;
        $c = $year % 19;
        $d = (19 * $c + 15) % 30;
        $e = (2 * $a + 4 * $b - $d + 34) % 7;
        $month = intdiv($d + $e + 114, 31);
        $day   = ($d + $e + 114) % 31 + 1;
        // Julianisches Datum + 13 Tage = Gregorianisches Datum (1900–2099)
        $dt = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
        $dt->modify('+13 days');
        return $dt->format('Y-m-d');
}

/**
 * Datum um X Tage verschieben
 *
 * @param  string $date Datum JJJJ-MM-TT
 * @param  int    $days Anzahl Tage (+/-)
 * @return string
 */
function worktime_date_add($date, $days)
{
        $dt = new DateTime($date);
        $dt->modify(($days >= 0 ? '+' : '').(int) $days.' day');
        return $dt->format('Y-m-d');
}

/**
 * Feiertage eines Jahres ermitteln (gesetzlich je Bundesland + manuelle Liste)
 *
 * @param  int    $year  Jahr
 * @param  string $state Bundesland-Code (null = aus Konfiguration)
 * @return array         Datum JJJJ-MM-TT => Bezeichnung
 */
function worktime_get_holidays($year, $state = null)
{
        global $conf, $langs;
        static $cache = array();

        $year = (int) $year;
        if ($state === null) {
                $state = getDolGlobalString('WORKTIME_HOLIDAY_STATE', 'CY');
        }
        $key = $year.'_'.$state;
        if (isset($cache[$key])) {
                return $cache[$key];
        }

        $h = array();
        if ($state !== 'MANUAL') {
                // Orthodoxes Osterdatum (Julianisch + 13 Tage → Gregorianisch)
                $easter = worktime_orthodox_easter_date($year);

                // ── Feste gesetzliche Feiertage Zyperns ──────────────────────────────
                $h[$year.'-01-01'] = $langs->trans('WorktimeHolidayNewYear');
                $h[$year.'-01-06'] = $langs->trans('WorktimeHolidayEpiphany');
                $h[$year.'-03-25'] = $langs->trans('WorktimeHolidayGreekIndependence');
                $h[$year.'-04-01'] = $langs->trans('WorktimeHolidayEokaDay');
                $h[$year.'-05-01'] = $langs->trans('WorktimeHolidayLabourDay');
                $h[$year.'-08-15'] = $langs->trans('WorktimeHolidayAssumption');
                $h[$year.'-10-01'] = $langs->trans('WorktimeHolidayCyprusIndependence');
                $h[$year.'-10-28'] = $langs->trans('WorktimeHolidayOchiDay');
                $h[$year.'-12-25'] = $langs->trans('WorktimeHolidayChristmas');
                $h[$year.'-12-26'] = $langs->trans('WorktimeHolidayBoxingDay');

                // ── Bewegliche Feiertage (Orthodoxes Ostern) ─────────────────────────
                // Kathara Deftera (Reiner Montag): 48 Tage vor Ostern
                $h[worktime_date_add($easter, -48)] = $langs->trans('WorktimeHolidayCleanMonday');
                // Orthodoxer Karfreitag
                $h[worktime_date_add($easter, -2)]  = $langs->trans('WorktimeHolidayOrthodoxGoodFriday');
                // Heiliger Samstag
                $h[worktime_date_add($easter, -1)]  = $langs->trans('WorktimeHolidayHolySaturday');
                // Ostersonntag
                $h[$easter]                          = $langs->trans('WorktimeHolidayOrthodoxEasterSunday');
                // Ostermontag
                $h[worktime_date_add($easter,  1)]  = $langs->trans('WorktimeHolidayEasterMonday');
                // Kataklysmos / Pfingstmontag: 50 Tage nach Ostern
                $h[worktime_date_add($easter, 50)]  = $langs->trans('WorktimeHolidayKataklysmos');
        }

        // Manuelle Feiertage: eine Zeile pro Eintrag, Format "JJJJ-MM-TT Bezeichnung"
        // oder "MM-TT Bezeichnung" (jährlich wiederkehrend)
        $manual = getDolGlobalString('WORKTIME_HOLIDAYS_MANUAL');
        foreach (preg_split('/\r\n|\r|\n|;/', $manual) as $line) {
                $line = trim($line);
                if ($line === '') {
                        continue;
                }
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\s*(.*)$/', $line, $m)) {
                        if ((int) $m[1] == $year && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                                $h[$m[1].'-'.$m[2].'-'.$m[3]] = ($m[4] !== '' ? $m[4] : $langs->trans('WorktimeHolidayGeneric'));
                        }
                } elseif (preg_match('/^(\d{2})-(\d{2})\s*(.*)$/', $line, $m)) {
                        if (checkdate((int) $m[1], (int) $m[2], $year)) {
                                $h[$year.'-'.$m[1].'-'.$m[2]] = ($m[3] !== '' ? $m[3] : $langs->trans('WorktimeHolidayGeneric'));
                        }
                }
        }

        ksort($h);
        $cache[$key] = $h;
        return $h;
}

/**
 * Bezeichnung des Feiertags oder leerer String
 *
 * @param  string $date Datum JJJJ-MM-TT
 * @return string
 */
function worktime_holiday_name($date)
{
        $hol = worktime_get_holidays((int) substr($date, 0, 4));
        return isset($hol[$date]) ? $hol[$date] : '';
}

/**
 * Ist das Datum ein regulärer Arbeitstag (Mo–Fr und kein Feiertag)?
 *
 * @param  string $date Datum JJJJ-MM-TT
 * @return bool
 */
function worktime_is_workday($date)
{
        $dow = (int) date('N', strtotime($date));
        if ($dow >= 6) {
                return false;
        }
        return worktime_holiday_name($date) === '';
}

/**
 * Anzahl Arbeitstage zwischen zwei Daten (inklusive)
 *
 * @param  string $from Datum JJJJ-MM-TT
 * @param  string $to   Datum JJJJ-MM-TT
 * @return int
 */
function worktime_count_workdays($from, $to)
{
        $count = 0;
        $d = $from;
        $guard = 0;
        while ($d <= $to && $guard < 3700) {
                if (worktime_is_workday($d)) {
                        $count++;
                }
                $d = worktime_date_add($d, 1);
                $guard++;
        }
        return $count;
}

/**
 * Minuten als Stunden:Minuten formatieren
 *
 * @param  int  $minutes Minuten
 * @param  bool $signed  Vorzeichen immer anzeigen
 * @return string        z. B. "7:45" oder "+1:30"
 */
function worktime_format_minutes($minutes, $signed = false)
{
        $minutes = (int) round($minutes);
        $sign = '';
        if ($minutes < 0) {
                $sign = '-';
        } elseif ($signed && $minutes > 0) {
                $sign = '+';
        }
        $abs = abs($minutes);
        return $sign.intdiv($abs, 60).':'.sprintf('%02d', $abs % 60);
}

/**
 * CSS-Klasse für einen Saldo
 *
 * @param  int    $minutes Saldo in Minuten
 * @return string
 */
function worktime_balance_class($minutes)
{
        if ($minutes > 0) {
                return 'wt-positive';
        }
        if ($minutes < 0) {
                return 'wt-negative';
        }
        return 'wt-neutral';
}

/**
 * Prüft, ob die Seite für den Benutzer zugänglich ist (Lesen)
 *
 * @param  User $user Benutzer
 * @return bool
 */
function worktime_can_read($user)
{
        return $user->hasRight('worktime', 'read') || $user->hasRight('worktime', 'admin') || !empty($user->admin);
}

/**
 * Darf der Benutzer stempeln / Anträge stellen?
 *
 * @param  User $user Benutzer
 * @return bool
 */
function worktime_can_write($user)
{
        return $user->hasRight('worktime', 'write') || $user->hasRight('worktime', 'admin') || !empty($user->admin);
}

/**
 * Ist der Benutzer Worktime-Verwalter?
 *
 * @param  User $user Benutzer
 * @return bool
 */
function worktime_is_admin($user)
{
        return $user->hasRight('worktime', 'admin') || !empty($user->admin);
}

/**
 * Darf $user die Abwesenheit von $applicantId genehmigen?
 * (Worktime-Verwalter oder direkter Vorgesetzter laut Dolibarr-Benutzerkarte)
 *
 * @param  User $user        Benutzer
 * @param  int  $applicantId Antragsteller
 * @return bool
 */
function worktime_can_approve($user, $applicantId)
{
        global $db;
        if (worktime_is_admin($user)) {
                return true;
        }
        if ((int) $applicantId == (int) $user->id) {
                return false;
        }
        $sql = "SELECT fk_user FROM ".MAIN_DB_PREFIX."user WHERE rowid = ".((int) $applicantId);
        $resql = $db->query($sql);
        if ($resql && ($obj = $db->fetch_object($resql))) {
                return ((int) $obj->fk_user == (int) $user->id);
        }
        return false;
}

/**
 * IDs der Benutzer, deren direkter Vorgesetzter $userId ist
 *
 * @param  int   $userId Benutzer-ID
 * @return int[]
 */
function worktime_get_subordinates($userId)
{
        global $db;
        $ids = array();
        // Hinweis: Die DB-Spalte in llx_user heißt auch in Dolibarr 23 weiterhin 'statut' (nur das PHP-Attribut heißt $user->status).
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."user WHERE fk_user = ".((int) $userId)." AND statut = 1";
        $resql = $db->query($sql);
        if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                        $ids[] = (int) $obj->rowid;
                }
        }
        return $ids;
}

/**
 * Montag der Woche zu einem Datum
 *
 * @param  string $date Datum JJJJ-MM-TT
 * @return string
 */
function worktime_week_start($date)
{
        $dt = new DateTime($date);
        $dow = (int) $dt->format('N');
        $dt->modify('-'.($dow - 1).' day');
        return $dt->format('Y-m-d');
}

/* ─── Zeitzonen ─────────────────────────────────────────────────────────────
 * Speicherung: immer UTC (JJJJ-MM-TT HH:MM:SS). Anzeige/Tagesgrenzen: Zeitzone
 * des angemeldeten Benutzers über die Dolibarr-Mechanik 'tzuserrel' (liest
 * $_SESSION['dol_tz_string'], die beim Login vom Browser gesetzt wird).
 */

/**
 * Aktueller Zeitpunkt als UTC-String für die Datenbank
 *
 * @return string JJJJ-MM-TT HH:MM:SS (UTC)
 */
function worktime_now_utc_sql()
{
        return gmdate('Y-m-d H:i:s', dol_now());
}

/**
 * Gespeicherten UTC-String in einen GMT-Zeitstempel wandeln
 *
 * @param  string $str UTC-Zeit JJJJ-MM-TT HH:MM(:SS)
 * @return int         GMT-Zeitstempel (0 bei ungültigem Format)
 */
function worktime_utc_str_to_ts($str)
{
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', trim((string) $str), $m)) {
                return 0;
        }
        return (int) dol_mktime((int) $m[4], (int) $m[5], (int) (isset($m[6]) ? $m[6] : 0), (int) $m[2], (int) $m[3], (int) $m[1], true);
}

/**
 * Heutiges Datum in der Zeitzone des Benutzers
 *
 * @return string JJJJ-MM-TT
 */
function worktime_user_today()
{
        return dol_print_date(dol_now(), '%Y-%m-%d', 'tzuserrel');
}

/**
 * Aktuelle Zeit in der Zeitzone des Benutzers, beliebiges Format
 *
 * @param  string $format strftime-Format (z. B. '%H:%M', '%Y', '%m')
 * @return string
 */
function worktime_user_now($format = '%H:%M')
{
        return dol_print_date(dol_now(), $format, 'tzuserrel');
}

/**
 * Uhrzeit (HH:MM) eines GMT-Zeitstempels in der Zeitzone des Benutzers
 *
 * @param  int    $ts GMT-Zeitstempel
 * @return string
 */
function worktime_user_time_of_ts($ts)
{
        return dol_print_date((int) $ts, '%H:%M', 'tzuserrel');
}

/**
 * Datum (JJJJ-MM-TT) eines GMT-Zeitstempels in der Zeitzone des Benutzers
 *
 * @param  int    $ts GMT-Zeitstempel
 * @return string
 */
function worktime_user_date_of_ts($ts)
{
        return dol_print_date((int) $ts, '%Y-%m-%d', 'tzuserrel');
}

/**
 * Vom Benutzer eingegebene lokale Zeit in einen UTC-String für die Datenbank
 *
 * @param  string      $datetime Lokale Zeit JJJJ-MM-TT HH:MM(:SS)
 * @return string|null           UTC-String JJJJ-MM-TT HH:MM:SS, null bei Fehler
 */
function worktime_user_dt_to_utc_sql($datetime)
{
        if (!is_string($datetime) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', trim($datetime), $m)) {
                return null;
        }
        $ts = dol_mktime((int) $m[4], (int) $m[5], (int) (isset($m[6]) ? $m[6] : 0), (int) $m[2], (int) $m[3], (int) $m[1], 'tzuserrel');
        if (empty($ts)) {
                return null;
        }
        return gmdate('Y-m-d H:i:s', $ts);
}

/**
 * Gültiges Datum aus Request (JJJJ-MM-TT) oder Standardwert
 *
 * @param  string $value   Eingabe
 * @param  string $default Standardwert
 * @return string
 */
function worktime_sanitize_date($value, $default)
{
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return $value;
        }
        return $default;
}

/**
 * Wochentagsname (kurz) über das Sprachsystem
 *
 * @param  string $date Datum JJJJ-MM-TT
 * @return string
 */
function worktime_weekday_short($date)
{
        global $langs;
        $keys = array(1 => 'WorktimeMon', 2 => 'WorktimeTue', 3 => 'WorktimeWed', 4 => 'WorktimeThu', 5 => 'WorktimeFri', 6 => 'WorktimeSat', 7 => 'WorktimeSun');
        return $langs->trans($keys[(int) date('N', strtotime($date))]);
}

/**
 * Datum im deutschen Format TT.MM.JJJJ
 *
 * @param  string $date Datum JJJJ-MM-TT
 * @return string
 */
function worktime_format_date($date)
{
        if (empty($date)) {
                return '';
        }
        return date('d.m.Y', strtotime($date));
}

/**
 * Monatsname über das Sprachsystem
 *
 * @param  int    $month Monat 1-12
 * @return string
 */
function worktime_month_name($month)
{
        global $langs;
        $langs->load('main');
        return $langs->trans('Month'.sprintf('%02d', (int) $month));
}

/**
 * Tabs für die Admin-Seite
 *
 * @return array
 */
function worktimeAdminPrepareHead()
{
        global $langs;
        $langs->load('worktime@worktime');

        $h = 0;
        $head = array();
        $head[$h][0] = dol_buildpath('/worktime/admin/setup.php', 1);
        $head[$h][1] = $langs->trans('Settings');
        $head[$h][2] = 'settings';
        $h++;
        $head[$h][0] = dol_buildpath('/worktime/admin/setup.php', 1).'?tab=users';
        $head[$h][1] = $langs->trans('WorktimeUserSettings');
        $head[$h][2] = 'users';
        $h++;
        $head[$h][0] = dol_buildpath('/worktime/admin/setup.php', 1).'?tab=holidays';
        $head[$h][1] = $langs->trans('WorktimeHolidays');
        $head[$h][2] = 'holidays';
        $h++;

        return $head;
}

/**
 * Benutzerauswahl (nur für Verwalter / Vorgesetzte) für die Auswertungen
 *
 * @param  User   $user       Aktueller Benutzer
 * @param  int    $selectedId Ausgewählter Benutzer
 * @param  string $name       Feldname
 * @param  bool   $autosubmit Formular bei Änderung absenden
 * @return string             HTML (leer, wenn keine Auswahl erlaubt)
 */
function worktime_user_selector($user, $selectedId, $name = 'fk_user', $autosubmit = true)
{
        global $db, $conf;

        $isAdmin = worktime_is_admin($user);
        $subs = worktime_get_subordinates($user->id);
        if (!$isAdmin && empty($subs)) {
                return '';
        }

        $sql = "SELECT rowid, firstname, lastname, login FROM ".MAIN_DB_PREFIX."user";
        // Hinweis: Die DB-Spalte in llx_user heißt auch in Dolibarr 23 weiterhin 'statut' (nur das PHP-Attribut heißt $user->status).
        $sql .= " WHERE statut = 1";
        $sql .= " AND entity IN (0, ".((int) $conf->entity).")";
        if (!$isAdmin) {
                $subs[] = (int) $user->id;
                $sql .= " AND rowid IN (".implode(',', array_map('intval', $subs)).")";
        }
        $sql .= " ORDER BY lastname, firstname";

        $out = '<select name="'.dol_escape_htmltag($name).'" class="flat minwidth200"'.($autosubmit ? ' onchange="this.form.submit()"' : '').'>';
        $resql = $db->query($sql);
        if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                        $label = trim($obj->firstname.' '.$obj->lastname);
                        if ($label === '') {
                                $label = $obj->login;
                        }
                        $out .= '<option value="'.((int) $obj->rowid).'"'.((int) $obj->rowid == (int) $selectedId ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
                }
        }
        $out .= '</select>';
        return $out;
}

/**
 * Darf $user die Daten von $targetUserId sehen?
 *
 * @param  User $user         Aktueller Benutzer
 * @param  int  $targetUserId Zielbenutzer
 * @return bool
 */
function worktime_can_view_user($user, $targetUserId)
{
        if ((int) $targetUserId == (int) $user->id) {
                return true;
        }
        if (worktime_is_admin($user)) {
                return true;
        }
        return in_array((int) $targetUserId, worktime_get_subordinates($user->id));
}

/**
 * Anzeigename eines Benutzers
 *
 * @param  int    $userId Benutzer-ID
 * @return string
 */
function worktime_user_name($userId)
{
        global $db;
        static $cache = array();
        $userId = (int) $userId;
        if (isset($cache[$userId])) {
                return $cache[$userId];
        }
        $name = '';
        $resql = $db->query("SELECT firstname, lastname, login FROM ".MAIN_DB_PREFIX."user WHERE rowid = ".$userId);
        if ($resql && ($obj = $db->fetch_object($resql))) {
                $name = trim($obj->firstname.' '.$obj->lastname);
                if ($name === '') {
                        $name = $obj->login;
                }
        }
        $cache[$userId] = $name;
        return $name;
}
