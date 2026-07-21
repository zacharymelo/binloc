-- Copyright (C) 2026 Zachary Melo
--
-- Binloc: one row per level value of a location assignment.
-- Exactly one of fk_option (list levels) / value (text and number levels)
-- is set; enforced in business logic.
--

CREATE TABLE IF NOT EXISTS llx_binloc_location_value (
	rowid           INTEGER         NOT NULL AUTO_INCREMENT PRIMARY KEY,
	fk_location     INTEGER         NOT NULL,
	fk_level        INTEGER         NOT NULL,
	fk_option       INTEGER         DEFAULT NULL,
	value           VARCHAR(64)     DEFAULT NULL,
	tms             TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
