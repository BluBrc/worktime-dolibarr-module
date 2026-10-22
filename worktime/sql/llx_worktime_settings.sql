-- Modul Worktime: individuelle Einstellungen pro Benutzer

CREATE TABLE llx_worktime_settings(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_user integer NOT NULL,
	weekly_hours decimal(5,2) DEFAULT 40 NOT NULL,
	vacation_days_year integer DEFAULT 30 NOT NULL,
	vacation_carryover decimal(5,1) DEFAULT 0 NOT NULL,
	balance_start_date date NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
