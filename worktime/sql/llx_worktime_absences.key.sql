-- Indizes für llx_worktime_absences

ALTER TABLE llx_worktime_absences ADD INDEX idx_worktime_absences_user (fk_user, date_start, date_end);
ALTER TABLE llx_worktime_absences ADD INDEX idx_worktime_absences_status (status);
