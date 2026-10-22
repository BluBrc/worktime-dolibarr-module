-- Indizes für llx_worktime_entries

ALTER TABLE llx_worktime_entries ADD INDEX idx_worktime_entries_user_time (fk_user, entry_time);
ALTER TABLE llx_worktime_entries ADD INDEX idx_worktime_entries_entity (entity);
