-- Indizes für llx_worktime_settings

ALTER TABLE llx_worktime_settings ADD UNIQUE INDEX uk_worktime_settings_user (entity, fk_user);
