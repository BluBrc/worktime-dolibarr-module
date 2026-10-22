<?php
/* Modul Worktime – Klasse für Zeitstempel und Arbeitszeitberechnung
 *
 * Regeln für die Saldenberechnung:
 *  - Soll pro Arbeitstag = Wochenstunden / 5 (Mo–Fr)
 *  - Wochenenden, Feiertage und genehmigte Abwesenheiten haben Soll 0
 *  - Tage vor dem Beginn der Zeiterfassung (Startdatum) haben Soll 0
 *  - Zukünftige Tage fließen nicht in den Saldo ein
 *  - Heute: solange der Benutzer nicht "gegangen" ist, zählt nur
 *    Mehrarbeit über das Tagessoll hinaus (kein Minus während des Arbeitstags)
 */

require_once __DIR__.'/../lib/worktime.lib.php';

/**
 * Zeiterfassung
 */
class Worktime
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();

	const TYPE_CLOCK_IN = 'clock_in';
	const TYPE_CLOCK_OUT = 'clock_out';
	const TYPE_BREAK_START = 'break_start';
	const TYPE_BREAK_END = 'break_end';

	/** @var array Cache der Benutzereinstellungen */
	private $settingsCache = array();

	/**
	 * Konstruktor
	 *
	 * @param DoliDB $db Datenbank-Handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Gültige Eintragstypen
	 *
	 * @return string[]
	 */
	public static function getTypes()
	{
		return array(self::TYPE_CLOCK_IN, self::TYPE_CLOCK_OUT, self::TYPE_BREAK_START, self::TYPE_BREAK_END);
	}

	/**
	 * Erlaubte Aktionen je Status (Zustandsautomat)
	 *
	 * @param  string   $status Status aus getCurrentStatus()
	 * @return string[]
	 */
	public static function getAllowedActions($status)
	{
		switch ($status) {
			case 'clock_in':
				return array(self::TYPE_CLOCK_OUT, self::TYPE_BREAK_START);
			case 'break':
				return array(self::TYPE_BREAK_END);
			default: // off, clock_out
				return array(self::TYPE_CLOCK_IN);
		}
	}

	/**
	 * Status nach einer Aktion
	 *
	 * @param  string $type Eintragstyp
	 * @return string
	 */
	public static function statusAfter($type)
	{
		switch ($type) {
			case self::TYPE_CLOCK_IN:
			case self::TYPE_BREAK_END:
				return 'clock_in';
			case self::TYPE_BREAK_START:
				return 'break';
			default:
				return 'clock_out';
		}
	}

	/**
	 * Eintrag speichern
	 *
	 * Gespeichert wird immer UTC. Ein übergebenes $datetime wird als lokale
	 * Zeit des angemeldeten Benutzers interpretiert (Nachtrag durch Verwalter),
	 * null bedeutet "jetzt".
	 *
	 * @param  int         $userId    Benutzer
	 * @param  string      $type      Eintragstyp
	 * @param  string|null $datetime  Lokale Zeit JJJJ-MM-TT HH:MM(:SS), null = jetzt
	 * @param  string      $note      Notiz
	 * @param  int         $creatorId Ersteller (bei Nachträgen durch Verwalter)
	 * @return int                    ID des Eintrags oder <0 bei Fehler
	 */
	public function addEntry($userId, $type, $datetime = null, $note = '', $creatorId = 0)
	{
		global $conf;

		if (!in_array($type, self::getTypes(), true)) {
			$this->error = 'WorktimeErrorInvalidAction';
			return -1;
		}
		$datetime = ($datetime === null) ? worktime_now_utc_sql() : worktime_user_dt_to_utc_sql($datetime);
		if ($datetime === null) {
			$this->error = 'WorktimeErrorInvalidDate';
			return -2;
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."worktime_entries (entity, fk_user, entry_type, entry_time, note, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $userId).", '".$this->db->escape($type)."',";
		$sql .= " '".$this->db->escape($datetime)."', ".($note !== '' ? "'".$this->db->escape(dol_trunc($note, 250, 'right', 'UTF-8', 1))."'" : "NULL").",";
		$sql .= " ".($creatorId > 0 ? (int) $creatorId : (int) $userId).")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -3;
		}
		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX."worktime_entries");
	}

	/**
	 * Eintrag löschen
	 *
	 * @param  int $id Eintrags-ID
	 * @return int     1 OK, <0 Fehler
	 */
	public function deleteEntry($id)
	{
		global $conf;
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."worktime_entries WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Einen einzelnen Eintrag laden
	 *
	 * @param  int        $id Eintrags-ID
	 * @return array|null
	 */
	public function fetchEntry($id)
	{
		global $conf;
		$sql = "SELECT rowid, fk_user, entry_type, entry_time, note FROM ".MAIN_DB_PREFIX."worktime_entries";
		$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return array('id' => (int) $obj->rowid, 'fk_user' => (int) $obj->fk_user, 'type' => $obj->entry_type, 'time' => $obj->entry_time, 'note' => $obj->note);
		}
		return null;
	}

	/**
	 * Einträge in einem Zeitraum, gruppiert nach lokalem Tag des Benutzers
	 *
	 * Gespeichert ist UTC; die Tageszuordnung erfolgt in der Zeitzone des
	 * angemeldeten Benutzers. Deshalb wird die SQL-Abfrage um je einen
	 * Kalendertag erweitert und die genaue Zuordnung in PHP vorgenommen.
	 *
	 * @param  int    $userId Benutzer
	 * @param  string $from   Datum JJJJ-MM-TT
	 * @param  string $to     Datum JJJJ-MM-TT (inklusive)
	 * @return array          JJJJ-MM-TT => Liste von Einträgen
	 */
	public function getEntriesRange($userId, $from, $to)
	{
		global $conf;

		$result = array();
		$sql = "SELECT rowid, entry_type, entry_time, note FROM ".MAIN_DB_PREFIX."worktime_entries";
		$sql .= " WHERE fk_user = ".((int) $userId);
		$sql .= " AND entity = ".((int) $conf->entity);
		$sql .= " AND entry_time >= '".$this->db->escape(worktime_date_add($from, -1))." 00:00:00'";
		$sql .= " AND entry_time <= '".$this->db->escape(worktime_date_add($to, 1))." 23:59:59'";
		$sql .= " ORDER BY entry_time ASC, rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $result;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$ts = worktime_utc_str_to_ts((string) $obj->entry_time);
			$day = worktime_user_date_of_ts($ts);
			if ($day < $from || $day > $to) {
				continue;
			}
			$result[$day][] = array(
				'id' => (int) $obj->rowid,
				'type' => $obj->entry_type,
				'time' => (string) $obj->entry_time,
				'ts' => $ts,
				'note' => (string) $obj->note,
			);
		}
		return $result;
	}

	/**
	 * Alle Einträge eines Tages
	 *
	 * @param  int    $userId Benutzer
	 * @param  string $date   Datum JJJJ-MM-TT
	 * @return array
	 */
	public function getDayEntries($userId, $date)
	{
		$all = $this->getEntriesRange($userId, $date, $date);
		return isset($all[$date]) ? $all[$date] : array();
	}

	/**
	 * Tageswerte aus einer Liste von Einträgen berechnen
	 *
	 * @param  array  $entries Einträge eines Tages (chronologisch)
	 * @param  string $date    Datum JJJJ-MM-TT
	 * @return array           work, break (Minuten), first_in, last_out, open, incomplete
	 */
	public function computeDay($entries, $date)
	{
		$isToday = ($date === worktime_user_today());
		$now = time();

		$workSec = 0;
		$breakSec = 0;
		$state = 'off';
		$since = null;      // Beginn des laufenden Arbeitsintervalls
		$breakSince = null; // Beginn der laufenden Pause
		$firstIn = null;
		$lastOut = null;
		$incomplete = false;

		foreach ($entries as $e) {
			$ts = $e['ts'];
			switch ($e['type']) {
				case self::TYPE_CLOCK_IN:
					if ($state == 'off') {
						$state = 'work';
						$since = $ts;
						if ($firstIn === null) {
							$firstIn = $ts;
						}
					}
					break;
				case self::TYPE_BREAK_START:
					if ($state == 'work') {
						$workSec += max(0, $ts - $since);
						$state = 'break';
						$breakSince = $ts;
					}
					break;
				case self::TYPE_BREAK_END:
					if ($state == 'break') {
						$breakSec += max(0, $ts - $breakSince);
						$state = 'work';
						$since = $ts;
					}
					break;
				case self::TYPE_CLOCK_OUT:
					if ($state == 'work') {
						$workSec += max(0, $ts - $since);
					} elseif ($state == 'break') {
						// Gehen während der Pause: Pause endet mit dem Gehen
						$breakSec += max(0, $ts - $breakSince);
					}
					if ($state != 'off') {
						$lastOut = $ts;
					}
					$state = 'off';
					break;
			}
		}

		$open = ($state != 'off');
		if ($open) {
			if ($isToday) {
				if ($state == 'work') {
					$workSec += max(0, $now - $since);
				} else {
					$breakSec += max(0, $now - $breakSince);
				}
			} else {
				// Vergessenes Ausstempeln an einem vergangenen Tag: offenes Intervall wird nicht gezählt
				$incomplete = true;
			}
		}

		return array(
			'work' => (int) floor($workSec / 60),
			'break' => (int) floor($breakSec / 60),
			'first_in' => $firstIn,
			'last_out' => $lastOut,
			'open' => $open,
			'state' => $state,
			'incomplete' => $incomplete,
		);
	}

	/**
	 * Netto-Arbeitszeit eines Tages (Pausen abgezogen)
	 *
	 * @param  int    $userId Benutzer
	 * @param  string $date   Datum JJJJ-MM-TT
	 * @return int            Minuten
	 */
	public function calculateDayHours($userId, $date)
	{
		$res = $this->computeDay($this->getDayEntries($userId, $date), $date);
		return $res['work'];
	}

	/**
	 * Aktueller Status des Benutzers (bezogen auf den heutigen Tag)
	 *
	 * @param  int    $userId Benutzer
	 * @return string         clock_in | break | clock_out | off
	 */
	public function getCurrentStatus($userId)
	{
		$today = worktime_user_today();
		$entries = $this->getDayEntries($userId, $today);
		if (empty($entries)) {
			return 'off';
		}
		$res = $this->computeDay($entries, $today);
		if ($res['state'] == 'work') {
			return 'clock_in';
		}
		if ($res['state'] == 'break') {
			return 'break';
		}
		return 'clock_out';
	}

	/**
	 * Einstellungen eines Benutzers (mit globalen Standardwerten als Fallback)
	 *
	 * @param  int   $userId Benutzer
	 * @return array weekly_hours, vacation_days_year, vacation_carryover, balance_start_date, custom
	 */
	public function getUserSettings($userId)
	{
		global $conf;

		$userId = (int) $userId;
		if (isset($this->settingsCache[$userId])) {
			return $this->settingsCache[$userId];
		}

		$settings = array(
			'weekly_hours' => (float) (getDolGlobalString('WORKTIME_DEFAULT_WEEKLY_HOURS') !== '' ? price2num(getDolGlobalString('WORKTIME_DEFAULT_WEEKLY_HOURS')) : 40),
			'vacation_days_year' => getDolGlobalInt('WORKTIME_DEFAULT_VACATION_DAYS', 30),
			'vacation_carryover' => 0.0,
			'balance_start_date' => null,
			'custom' => false,
		);

		$sql = "SELECT weekly_hours, vacation_days_year, vacation_carryover, balance_start_date FROM ".MAIN_DB_PREFIX."worktime_settings";
		$sql .= " WHERE fk_user = ".$userId." AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$settings['weekly_hours'] = (float) $obj->weekly_hours;
			$settings['vacation_days_year'] = (int) $obj->vacation_days_year;
			$settings['vacation_carryover'] = (float) $obj->vacation_carryover;
			$settings['balance_start_date'] = !empty($obj->balance_start_date) ? substr($obj->balance_start_date, 0, 10) : null;
			$settings['custom'] = true;
		}

		$this->settingsCache[$userId] = $settings;
		return $settings;
	}

	/**
	 * Individuelle Einstellungen speichern (Insert oder Update)
	 *
	 * @param  int         $userId       Benutzer
	 * @param  float       $weeklyHours  Wochenstunden
	 * @param  int         $vacationDays Urlaubstage/Jahr
	 * @param  float       $carryover    Urlaubskorrektur (Tage, +/-)
	 * @param  string|null $startDate    Beginn Arbeitszeitkonto JJJJ-MM-TT
	 * @return int                       1 OK, <0 Fehler
	 */
	public function saveUserSettings($userId, $weeklyHours, $vacationDays, $carryover = 0, $startDate = null)
	{
		global $conf;

		$userId = (int) $userId;
		$weeklyHours = max(0, min(99, (float) $weeklyHours));
		$vacationDays = max(0, min(365, (int) $vacationDays));
		$carryover = max(-365, min(365, (float) $carryover));
		$startSql = ($startDate ? "'".$this->db->escape($startDate)."'" : "NULL");

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."worktime_settings WHERE fk_user = ".$userId." AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($obj = $this->db->fetch_object($resql)) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."worktime_settings SET";
			$sql .= " weekly_hours = ".price2num($weeklyHours).",";
			$sql .= " vacation_days_year = ".$vacationDays.",";
			$sql .= " vacation_carryover = ".price2num($carryover).",";
			$sql .= " balance_start_date = ".$startSql;
			$sql .= " WHERE rowid = ".((int) $obj->rowid);
		} else {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."worktime_settings (entity, fk_user, weekly_hours, vacation_days_year, vacation_carryover, balance_start_date)";
			$sql .= " VALUES (".((int) $conf->entity).", ".$userId.", ".price2num($weeklyHours).", ".$vacationDays.", ".price2num($carryover).", ".$startSql.")";
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -2;
		}
		unset($this->settingsCache[$userId]);
		return 1;
	}

	/**
	 * Individuelle Einstellungen löschen (zurück auf Standardwerte)
	 *
	 * @param  int $userId Benutzer
	 * @return int
	 */
	public function deleteUserSettings($userId)
	{
		global $conf;
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."worktime_settings WHERE fk_user = ".((int) $userId)." AND entity = ".((int) $conf->entity);
		unset($this->settingsCache[(int) $userId]);
		return $this->db->query($sql) ? 1 : -1;
	}

	/**
	 * Tagessoll in Minuten (Wochenstunden / 5)
	 *
	 * @param  int $userId Benutzer
	 * @return int
	 */
	public function getDailyTargetMinutes($userId)
	{
		$s = $this->getUserSettings($userId);
		return (int) round($s['weekly_hours'] * 60 / 5);
	}

	/**
	 * Beginn des Arbeitszeitkontos: Einstellung oder erster Stempel-Eintrag
	 *
	 * @param  int         $userId Benutzer
	 * @return string|null         JJJJ-MM-TT oder null, wenn noch keine Daten
	 */
	public function getBalanceStartDate($userId)
	{
		global $conf;

		$s = $this->getUserSettings($userId);
		if (!empty($s['balance_start_date'])) {
			return $s['balance_start_date'];
		}
		$sql = "SELECT MIN(entry_time) as first_time FROM ".MAIN_DB_PREFIX."worktime_entries";
		$sql .= " WHERE fk_user = ".((int) $userId)." AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql)) && !empty($obj->first_time)) {
			return worktime_user_date_of_ts(worktime_utc_str_to_ts($obj->first_time));
		}
		return null;
	}

	/**
	 * Genehmigte (und beantragte) Abwesenheiten je Tag im Zeitraum
	 *
	 * @param  int    $userId Benutzer
	 * @param  string $from   JJJJ-MM-TT
	 * @param  string $to     JJJJ-MM-TT
	 * @return array          JJJJ-MM-TT => array(type, status, id)
	 */
	public function getAbsenceMap($userId, $from, $to)
	{
		global $conf;

		$map = array();
		$sql = "SELECT rowid, absence_type, date_start, date_end, status FROM ".MAIN_DB_PREFIX."worktime_absences";
		$sql .= " WHERE fk_user = ".((int) $userId)." AND entity = ".((int) $conf->entity);
		$sql .= " AND status IN ('approved', 'pending')";
		$sql .= " AND date_start <= '".$this->db->escape($to)."' AND date_end >= '".$this->db->escape($from)."'";
		$sql .= " ORDER BY date_start";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return $map;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$d = max(substr($obj->date_start, 0, 10), $from);
			$end = min(substr($obj->date_end, 0, 10), $to);
			while ($d <= $end) {
				// Genehmigte Einträge haben Vorrang vor beantragten
				if (!isset($map[$d]) || ($map[$d]['status'] != 'approved' && $obj->status == 'approved')) {
					$map[$d] = array('type' => $obj->absence_type, 'status' => $obj->status, 'id' => (int) $obj->rowid);
				}
				$d = worktime_date_add($d, 1);
			}
		}
		return $map;
	}

	/**
	 * Detaillierte Auswertung für einen Zeitraum (Basis aller Berichte und Salden)
	 *
	 * @param  int    $userId Benutzer
	 * @param  string $from   JJJJ-MM-TT
	 * @param  string $to     JJJJ-MM-TT (inklusive)
	 * @return array          days => [...], totals => [...]
	 */
	public function getRangeReport($userId, $from, $to)
	{
		$today = worktime_user_today();
		$dailyTarget = $this->getDailyTargetMinutes($userId);
		$startDate = $this->getBalanceStartDate($userId);
		$entries = $this->getEntriesRange($userId, $from, $to);
		$absences = $this->getAbsenceMap($userId, $from, $to);
		$todayStatus = null;

		$totals = array(
			'actual' => 0, 'target' => 0, 'balance' => 0, 'break' => 0,
			'target_full' => 0, 'workdays' => 0, 'worked_days' => 0,
			'vacation_days' => 0, 'sick_days' => 0, 'other_days' => 0, 'holidays' => 0,
			'incomplete_days' => 0,
		);
		$days = array();

		$d = $from;
		$guard = 0;
		while ($d <= $to && $guard < 3700) {
			$guard++;
			$dayEntries = isset($entries[$d]) ? $entries[$d] : array();
			$calc = $this->computeDay($dayEntries, $d);
			$dow = (int) date('N', strtotime($d));
			$holiday = worktime_holiday_name($d);
			$isWeekend = ($dow >= 6);
			$absence = isset($absences[$d]) ? $absences[$d] : null;
			$absenceApproved = ($absence && $absence['status'] == 'approved');
			$isWorkday = (!$isWeekend && $holiday === '');
			$beforeStart = ($startDate === null || $d < $startDate);

			$target = 0;
			if ($isWorkday && !$absenceApproved) {
				$target = $dailyTarget;
			}
			$targetFull = $beforeStart ? 0 : $target;

			// Saldo-relevantes Soll
			$counted = true;
			$targetCounted = $targetFull;
			if ($d > $today) {
				$counted = false;
				$targetCounted = 0;
			} elseif ($d == $today) {
				if ($todayStatus === null) {
					$todayStatus = $this->getCurrentStatus($userId);
				}
				if ($todayStatus != 'clock_out' && $todayStatus != 'off') {
					$targetCounted = min($targetFull, $calc['work']);
				} elseif ($todayStatus == 'off') {
					// Heute noch nicht eingestempelt: Tag noch nicht bewerten
					$targetCounted = 0;
				}
			}
			$actual = $counted ? $calc['work'] : 0;
			$diff = $actual - $targetCounted;

			$days[$d] = array(
				'date' => $d,
				'dow' => $dow,
				'entries' => $dayEntries,
				'first_in' => $calc['first_in'],
				'last_out' => $calc['last_out'],
				'open' => $calc['open'],
				'incomplete' => $calc['incomplete'],
				'work' => $calc['work'],
				'break' => $calc['break'],
				'target' => $targetFull,
				'target_counted' => $targetCounted,
				'diff' => $diff,
				'counted' => $counted,
				'holiday' => $holiday,
				'weekend' => $isWeekend,
				'workday' => $isWorkday,
				'absence' => $absence,
				'before_start' => $beforeStart,
			);

			$totals['actual'] += $actual;
			$totals['break'] += $counted ? $calc['break'] : 0;
			$totals['target'] += $targetCounted;
			$totals['target_full'] += $targetFull;
			$totals['balance'] += $diff;
			if ($isWorkday) {
				$totals['workdays']++;
			}
			if ($holiday !== '' && !$isWeekend) {
				$totals['holidays']++;
			}
			if ($calc['work'] > 0) {
				$totals['worked_days']++;
			}
			if ($calc['incomplete']) {
				$totals['incomplete_days']++;
			}
			if ($absenceApproved && $isWorkday) {
				if ($absence['type'] == 'vacation') {
					$totals['vacation_days']++;
				} elseif ($absence['type'] == 'sick') {
					$totals['sick_days']++;
				} else {
					$totals['other_days']++;
				}
			}

			$d = worktime_date_add($d, 1);
		}

		return array('days' => $days, 'totals' => $totals);
	}

	/**
	 * Wochensaldo: Ist vs. Soll (Soll = Wochenstunden/5 × Arbeitstage)
	 *
	 * @param  int    $userId    Benutzer
	 * @param  string $weekStart Montag der Woche JJJJ-MM-TT
	 * @return array             actual, target, balance, target_full (Minuten)
	 */
	public function getWeekBalance($userId, $weekStart)
	{
		$weekStart = worktime_week_start($weekStart);
		$rep = $this->getRangeReport($userId, $weekStart, worktime_date_add($weekStart, 6));
		return array(
			'actual' => $rep['totals']['actual'],
			'target' => $rep['totals']['target'],
			'target_full' => $rep['totals']['target_full'],
			'balance' => $rep['totals']['balance'],
		);
	}

	/**
	 * Monatssaldo
	 *
	 * @param  int   $userId Benutzer
	 * @param  int   $year   Jahr
	 * @param  int   $month  Monat
	 * @return array         actual, target, balance, target_full (Minuten)
	 */
	public function getMonthBalance($userId, $year, $month)
	{
		$from = sprintf('%04d-%02d-01', $year, $month);
		$to = date('Y-m-t', strtotime($from));
		$rep = $this->getRangeReport($userId, $from, $to);
		return array(
			'actual' => $rep['totals']['actual'],
			'target' => $rep['totals']['target'],
			'target_full' => $rep['totals']['target_full'],
			'balance' => $rep['totals']['balance'],
		);
	}

	/**
	 * Arbeitszeitkonto: Summe aller Salden vor einem Stichtag
	 * (Standard: alle vergangenen Monate, d. h. bis zum Ende des Vormonats)
	 *
	 * @param  int         $userId     Benutzer
	 * @param  string|null $beforeDate Stichtag (exklusiv) JJJJ-MM-TT
	 * @return int                     Saldo in Minuten
	 */
	public function getAccumulatedBalance($userId, $beforeDate = null)
	{
		if ($beforeDate === null) {
			$beforeDate = substr(worktime_user_today(), 0, 7).'-01';
		}
		$start = $this->getBalanceStartDate($userId);
		if ($start === null || $start >= $beforeDate) {
			return 0;
		}
		$rep = $this->getRangeReport($userId, $start, worktime_date_add($beforeDate, -1));
		return (int) $rep['totals']['balance'];
	}
}
