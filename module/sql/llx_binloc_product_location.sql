-- Copyright (C) 2026 Zachary Melo
--
-- Binloc: product location assignment header (one row per product per warehouse,
-- or per product-lot per warehouse for serialized/batch products).
-- Level values live in llx_binloc_location_value (v2 normalized schema).
--
-- fk_product_lot uses 0 (not NULL) to mean "no lot" so the unique index below
-- can enforce one row per (product, warehouse, lot) — MySQL treats NULL as
-- distinct in unique indexes, which would defeat the constraint.
--

CREATE TABLE IF NOT EXISTS llx_binloc_product_location (
	rowid           INTEGER         NOT NULL AUTO_INCREMENT PRIMARY KEY,
	entity          INTEGER         NOT NULL DEFAULT 1,
	fk_product      INTEGER         NOT NULL,
	fk_entrepot     INTEGER         NOT NULL,
	fk_product_lot  INTEGER         NOT NULL DEFAULT 0,
	note            VARCHAR(255)    DEFAULT NULL,
	date_creation   DATETIME        NOT NULL,
	tms             TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat   INTEGER         NOT NULL,
	fk_user_modif   INTEGER         DEFAULT NULL
) ENGINE=InnoDB;
