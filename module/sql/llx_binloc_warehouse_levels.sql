-- Copyright (C) 2026 Zachary Melo
--
-- Binloc: per-warehouse level configuration.
-- Levels have stable identity (rowid) referenced by llx_binloc_location_value;
-- display order is `position`. `level_num` is kept only as the v1 migration
-- matching key and is not used for identity by v2 code.
-- Allowed values for datatype='list' live in llx_binloc_level_options.
--

CREATE TABLE IF NOT EXISTS llx_binloc_warehouse_levels (
	rowid           INTEGER         NOT NULL AUTO_INCREMENT PRIMARY KEY,
	entity          INTEGER         NOT NULL DEFAULT 1,
	fk_entrepot     INTEGER         NOT NULL,
	level_num       SMALLINT        NOT NULL DEFAULT 0,
	label           VARCHAR(64)     NOT NULL,
	datatype        VARCHAR(16)     NOT NULL DEFAULT 'text',
	position        INTEGER         NOT NULL DEFAULT 0,
	active          TINYINT         NOT NULL DEFAULT 1,
	date_creation   DATETIME        NOT NULL,
	tms             TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat   INTEGER         NOT NULL,
	fk_user_modif   INTEGER         DEFAULT NULL
) ENGINE=InnoDB;
