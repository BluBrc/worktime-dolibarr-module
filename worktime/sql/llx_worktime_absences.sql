-- Modul Worktime: Abwesenheiten (Urlaub/Krankheit/Sonstiges)
-- absence_type: 'vacation' | 'sick' | 'other'
-- status:       'pending' | 'approved' | 'rejected'

CREATE TABLE llx_worktime_absences(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_user integer NOT NULL,
	absence_type varchar(16) NOT NULL,
	date_start date NOT NULL,
	date_end date NOT NULL,
	status varchar(16) DEFAULT 'pending' NOT NULL,
	approved_by integer NULL,
	date_approval datetime NULL,
	note text NULL,
	document_required tinyint DEFAULT 0 NOT NULL,
	document_received tinyint DEFAULT 0 NOT NULL,
	datec datetime NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
