-- Modul Worktime: Zeitstempel-Einträge (Kommen/Gehen/Pause)
-- entry_type: 'clock_in' | 'clock_out' | 'break_start' | 'break_end'
-- (als varchar statt ENUM, damit die Tabelle auch unter PostgreSQL installierbar ist)

CREATE TABLE llx_worktime_entries(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_user integer NOT NULL,
	entry_type varchar(16) NOT NULL,
	entry_time datetime NOT NULL,
	note varchar(255) NULL,
	fk_user_creat integer NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
