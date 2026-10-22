<?php
/* Modul Worktime – Abwesenheiten (Urlaub, Krankheit, Sonstiges)
 *
 * Regeln:
 *  - Urlaub / Sonstiges: Status "pending" (beantragt), Genehmigung durch
 *    Worktime-Verwalter oder direkten Vorgesetzten
 *  - Krankheit: sofort "approved"; Attest erforderlich ab X Kalendertagen
 *    (Konstante WORKTIME_SICK_CERT_DAYS, Standard 3)
 *  - Urlaubsanspruch: Jahresurlaub + Korrektur + Resturlaub aus dem Vorjahr,
 *    der Resturlaub verfällt nach dem Stichtag (WORKTIME_VACATION_CARRYOVER_DATE)
 */

require_once __DIR__.'/../lib/worktime.lib.php';
require_once __DIR__.'/worktime.class.php';

/**
 * Abwesenheit
 */
class WorktimeAbsence
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';

	public $id;
	public $fk_user;
	public $absence_type;
	public $date_start;
	public $date_end;
	public $status;
	public $approved_by;
	public $date_approval;
	public $note;
	public $document_required = 0;
	public $document_received = 0;
	public $datec;

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
	 * Gültige Abwesenheitstypen
	 *
	 * @return string[]
	 */
	public static function getTypes()
	{
		return array('vacation', 'sick', 'other');
	}

	/**
	 * Gültige Status
	 *
	 * @return string[]
	 */
	public static function getStatuses()
	{
		return array('pending', 'approved', 'rejected');
	}

	/**
	 * Anzahl Arbeitstage der Abwesenheit
	 *
	 * @return int
	 */
	public function getWorkdays()
	{
		return worktime_count_workdays($this->date_start, $this->date_end);
	}

	/**
	 * Anzahl Kalendertage der Abwesenheit
	 *
	 * @return int
	 */
	public function getCalendarDays()
	{
		$a = new DateTime($this->date_start);
		$b = new DateTime($this->date_end);
		return (int) $a->diff($b)->days + 1;
	}

	/**
	 * Abwesenheit anlegen
	 *
	 * @param  User $user        Ausführender Benutzer
	 * @param  bool $skipQuota   Urlaubsanspruch nicht prüfen (Verwalter)
	 * @return int               ID oder <0 bei Fehler ($this->error enthält den Sprachschlüssel)
	 */
	public function create($user, $skipQuota = false)
	{
		global $conf;

		if (!in_array($this->absence_type, self::getTypes(), true)) {
			$this->error = 'WorktimeErrorInvalidType';
			return -1;
		}
		$this->date_start = worktime_sanitize_date($this->date_start, '');
		$this->date_end = worktime_sanitize_date($this->date_end, '');
		if ($this->date_start === '' || $this->date_end === '') {
			$this->error = 'WorktimeErrorInvalidDate';
			return -2;
		}
		if ($this->date_end < $this->date_start) {
			$this->error = 'WorktimeErrorEndBeforeStart';
			return -3;
		}
		if ($this->getCalendarDays() > 366) {
			$this->error = 'WorktimeErrorRangeTooLong';
			return -4;
		}
		if ($this->hasOverlap()) {
			$this->error = 'WorktimeErrorOverlap';
			return -5;
		}

		if ($this->absence_type == 'vacation') {
			if ($this->getWorkdays() <= 0) {
				$this->error = 'WorktimeErrorNoWorkdays';
				return -6;
			}
			if (!$skipQuota) {
				// Urlaubsanspruch je betroffenem Jahr prüfen
				$y1 = (int) substr($this->date_start, 0, 4);
				$y2 = (int) substr($this->date_end, 0, 4);
				for ($y = $y1; $y <= $y2; $y++) {
					$from = max($this->date_start, $y.'-01-01');
					$to = min($this->date_end, $y.'-12-31');
					$need = worktime_count_workdays($from, $to);
					$stats = $this->getVacationStats($this->fk_user, $y);
					if ($need > ($stats['remaining'] - $stats['pending'])) {
						$this->error = 'WorktimeErrorNotEnoughVacation';
						return -7;
					}
				}
			}
			$this->status = 'pending';
			$this->document_required = 0;
		} elseif ($this->absence_type == 'sick') {
			$this->status = 'approved';
			$certDays = getDolGlobalInt('WORKTIME_SICK_CERT_DAYS', 3);
			$this->document_required = ($certDays > 0 && $this->getCalendarDays() >= $certDays) ? 1 : 0;
		} else {
			$this->status = 'pending';
			$this->document_required = 0;
		}

		$now = worktime_now_utc_sql();
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."worktime_absences";
		$sql .= " (entity, fk_user, absence_type, date_start, date_end, status, approved_by, date_approval, note, document_required, document_received, datec)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $this->fk_user).", '".$this->db->escape($this->absence_type)."',";
		$sql .= " '".$this->db->escape($this->date_start)."', '".$this->db->escape($this->date_end)."',";
		$sql .= " '".$this->db->escape($this->status)."',";
		$sql .= " ".($this->status == 'approved' ? (int) $user->id : "NULL").",";
		$sql .= " ".($this->status == 'approved' ? "'".$this->db->escape($now)."'" : "NULL").",";
		$sql .= " ".($this->note !== null && $this->note !== '' ? "'".$this->db->escape($this->note)."'" : "NULL").",";
		$sql .= " ".((int) $this->document_required).", 0, '".$this->db->escape($now)."')";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -10;
		}
		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX."worktime_absences");
		return $this->id;
	}

	/**
	 * Prüft Überschneidung mit vorhandenen (nicht abgelehnten) Abwesenheiten
	 *
	 * @return bool
	 */
	public function hasOverlap()
	{
		global $conf;
		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."worktime_absences";
		$sql .= " WHERE fk_user = ".((int) $this->fk_user)." AND entity = ".((int) $conf->entity);
		$sql .= " AND status <> 'rejected'";
		$sql .= " AND date_start <= '".$this->db->escape($this->date_end)."' AND date_end >= '".$this->db->escape($this->date_start)."'";
		if (!empty($this->id)) {
			$sql .= " AND rowid <> ".((int) $this->id);
		}
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return ((int) $obj->nb > 0);
		}
		return false;
	}

	/**
	 * Abwesenheit laden
	 *
	 * @param  int $id ID
	 * @return int     1 gefunden, 0 nicht gefunden, <0 Fehler
	 */
	public function fetch($id)
	{
		global $conf;
		$sql = "SELECT rowid, fk_user, absence_type, date_start, date_end, status, approved_by, date_approval, note, document_required, document_received, datec";
		$sql .= " FROM ".MAIN_DB_PREFIX."worktime_absences WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return 0;
		}
		$this->setFromObject($obj);
		return 1;
	}

	/**
	 * Eigenschaften aus DB-Zeile übernehmen
	 *
	 * @param  object $obj DB-Zeile
	 * @return void
	 */
	private function setFromObject($obj)
	{
		$this->id = (int) $obj->rowid;
		$this->fk_user = (int) $obj->fk_user;
		$this->absence_type = $obj->absence_type;
		$this->date_start = substr($obj->date_start, 0, 10);
		$this->date_end = substr($obj->date_end, 0, 10);
		$this->status = $obj->status;
		$this->approved_by = (int) $obj->approved_by;
		$this->date_approval = $obj->date_approval;
		$this->note = $obj->note;
		$this->document_required = (int) $obj->document_required;
		$this->document_received = (int) $obj->document_received;
		$this->datec = $obj->datec;
	}

	/**
	 * Status setzen (Genehmigen / Ablehnen)
	 *
	 * @param  User   $user   Genehmigender Benutzer
	 * @param  string $status approved | rejected
	 * @return int            1 OK, <0 Fehler
	 */
	public function setStatus($user, $status)
	{
		if (!in_array($status, array('approved', 'rejected'), true)) {
			$this->error = 'WorktimeErrorInvalidStatus';
			return -1;
		}
		if ($status == 'approved' && $this->hasOverlapApproved()) {
			$this->error = 'WorktimeErrorOverlap';
			return -2;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."worktime_absences SET status = '".$this->db->escape($status)."',";
		$sql .= " approved_by = ".((int) $user->id).", date_approval = '".$this->db->escape(worktime_now_utc_sql())."'";
		$sql .= " WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -3;
		}
		$this->status = $status;
		$this->approved_by = (int) $user->id;
		return 1;
	}

	/**
	 * Überschneidung mit bereits genehmigten Abwesenheiten?
	 *
	 * @return bool
	 */
	private function hasOverlapApproved()
	{
		global $conf;
		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."worktime_absences";
		$sql .= " WHERE fk_user = ".((int) $this->fk_user)." AND entity = ".((int) $conf->entity);
		$sql .= " AND status = 'approved' AND rowid <> ".((int) $this->id);
		$sql .= " AND date_start <= '".$this->db->escape($this->date_end)."' AND date_end >= '".$this->db->escape($this->date_start)."'";
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return ((int) $obj->nb > 0);
		}
		return false;
	}

	/**
	 * Attest als eingegangen markieren / zurücksetzen
	 *
	 * @param  int $received 1 oder 0
	 * @return int
	 */
	public function setDocumentReceived($received)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."worktime_absences SET document_received = ".($received ? 1 : 0)." WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->document_received = $received ? 1 : 0;
		return 1;
	}

	/**
	 * Löschen
	 *
	 * @return int
	 */
	public function delete()
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."worktime_absences WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Liste von Abwesenheiten
	 *
	 * @param  int[]|int $userIds Benutzer-ID(s); 0 = alle
	 * @param  string    $status  Statusfilter ('' = alle)
	 * @param  int       $year    Jahresfilter (0 = alle)
	 * @param  int       $limit   Maximale Anzahl
	 * @return WorktimeAbsence[]
	 */
	public function fetchList($userIds = 0, $status = '', $year = 0, $limit = 500)
	{
		global $conf;

		$list = array();
		$sql = "SELECT rowid, fk_user, absence_type, date_start, date_end, status, approved_by, date_approval, note, document_required, document_received, datec";
		$sql .= " FROM ".MAIN_DB_PREFIX."worktime_absences WHERE entity = ".((int) $conf->entity);
		if (is_array($userIds)) {
			if (empty($userIds)) {
				return $list;
			}
			$sql .= " AND fk_user IN (".implode(',', array_map('intval', $userIds)).")";
		} elseif ((int) $userIds > 0) {
			$sql .= " AND fk_user = ".((int) $userIds);
		}
		if ($status !== '' && in_array($status, self::getStatuses(), true)) {
			$sql .= " AND status = '".$this->db->escape($status)."'";
		}
		if ($year > 0) {
			$sql .= " AND date_start <= '".((int) $year)."-12-31' AND date_end >= '".((int) $year)."-01-01'";
		}
		$sql .= " ORDER BY date_start DESC, rowid DESC";
		$sql .= $this->db->plimit((int) $limit, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $list;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$a = new WorktimeAbsence($this->db);
			$a->setFromObject($obj);
			$list[] = $a;
		}
		return $list;
	}

	/**
	 * Urlaubstage (Arbeitstage) eines Jahres mit gegebenem Status
	 *
	 * @param  int    $userId   Benutzer
	 * @param  int    $year     Jahr
	 * @param  string $status   approved | pending
	 * @param  string $until    Nur Tage bis einschließlich dieses Datums (optional)
	 * @return int
	 */
	public function countVacationDays($userId, $year, $status = 'approved', $until = '')
	{
		global $conf;

		$yStart = sprintf('%04d-01-01', $year);
		$yEnd = sprintf('%04d-12-31', $year);
		if ($until !== '' && $until < $yEnd) {
			$yEnd = $until;
		}
		if ($yEnd < $yStart) {
			return 0;
		}
		$sql = "SELECT date_start, date_end FROM ".MAIN_DB_PREFIX."worktime_absences";
		$sql .= " WHERE fk_user = ".((int) $userId)." AND entity = ".((int) $conf->entity);
		$sql .= " AND absence_type = 'vacation' AND status = '".$this->db->escape($status)."'";
		$sql .= " AND date_start <= '".$this->db->escape($yEnd)."' AND date_end >= '".$this->db->escape($yStart)."'";
		$total = 0;
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$from = max(substr($obj->date_start, 0, 10), $yStart);
				$to = min(substr($obj->date_end, 0, 10), $yEnd);
				$total += worktime_count_workdays($from, $to);
			}
		}
		return $total;
	}

	/**
	 * Urlaubskonto eines Jahres
	 *
	 * @param  int   $userId Benutzer
	 * @param  int   $year   Jahr
	 * @return array entitlement, adjustment, carryover, carryover_expired, taken, pending, remaining, deadline
	 */
	public function getVacationStats($userId, $year)
	{
		global $conf;

		$wt = new Worktime($this->db);
		$settings = $wt->getUserSettings($userId);
		$entitlement = (int) $settings['vacation_days_year'];
		$adjustment = (float) $settings['vacation_carryover'];

		// Stichtag für den Resturlaub aus dem Vorjahr (MM-TT)
		$md = getDolGlobalString('WORKTIME_VACATION_CARRYOVER_DATE', '03-31');
		if (!preg_match('/^(\d{2})-(\d{2})$/', $md, $m) || !checkdate((int) $m[1], (int) $m[2], (int) $year)) {
			$md = '03-31';
		}
		$deadline = sprintf('%04d', $year).'-'.$md;

		// Automatischer Übertrag nur, wenn die Zeiterfassung vor diesem Jahr begonnen hat
		$carryover = 0;
		$start = $wt->getBalanceStartDate($userId);
		if ($start !== null && (int) substr($start, 0, 4) < (int) $year) {
			$prevTaken = $this->countVacationDays($userId, $year - 1, 'approved');
			$carryover = max(0, $entitlement - $prevTaken);
		}

		$taken = $this->countVacationDays($userId, $year, 'approved');
		$pending = $this->countVacationDays($userId, $year, 'pending');
		$takenBeforeDeadline = $this->countVacationDays($userId, $year, 'approved', $deadline);

		$expired = 0;
		if ($carryover > 0 && worktime_user_today() > $deadline) {
			$expired = max(0, $carryover - $takenBeforeDeadline);
		}

		$remaining = $entitlement + $adjustment + $carryover - $expired - $taken;

		return array(
			'entitlement' => $entitlement,
			'adjustment' => $adjustment,
			'carryover' => $carryover,
			'carryover_expired' => $expired,
			'taken' => $taken,
			'pending' => $pending,
			'remaining' => $remaining,
			'deadline' => $deadline,
		);
	}
}
