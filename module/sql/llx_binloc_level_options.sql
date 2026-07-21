-- Copyright (C) 2026 Zachary Melo
--
-- Binloc: predefined values for levels with datatype='list'.
-- Keyed by level rowid; location values reference options by rowid so a
-- rename here propagates to every assignment automatically.
--

CREATE TABLE IF NOT EXISTS llx_binloc_level_options (
	rowid           INTEGER         NOT NULL AUTO_INCREMENT PRIMARY KEY,
	entity          INTEGER         NOT NULL DEFAULT 1,
	fk_level        INTEGER         NOT NULL,
	value           VARCHAR(64)     NOT NULL,
	position        INTEGER         NOT NULL DEFAULT 0,
	active          TINYINT         NOT NULL DEFAULT 1,
	date_creation   DATETIME        NOT NULL,
	tms             TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat   INTEGER         NOT NULL,
	fk_user_modif   INTEGER         DEFAULT NULL
) ENGINE=InnoDB;
