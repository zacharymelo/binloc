-- Copyright (C) 2026 Zachary Melo
--
-- Binloc: product location indexes and constraints
-- No FK on fk_product_lot: 0 means "no lot" and has no parent row.
-- Lot deletion cleanup is handled by the PRODUCTLOT_DELETE trigger.
--

ALTER TABLE llx_binloc_product_location ADD UNIQUE INDEX uk_binloc_prod_loc (entity, fk_product, fk_entrepot, fk_product_lot);
ALTER TABLE llx_binloc_product_location ADD INDEX idx_binloc_prod_loc_product (fk_product);
ALTER TABLE llx_binloc_product_location ADD INDEX idx_binloc_prod_loc_entrepot (fk_entrepot);
ALTER TABLE llx_binloc_product_location ADD INDEX idx_binloc_prod_loc_lot (fk_product_lot);
ALTER TABLE llx_binloc_product_location ADD CONSTRAINT fk_binloc_prod_loc_product FOREIGN KEY (fk_product) REFERENCES llx_product (rowid) ON DELETE CASCADE;
ALTER TABLE llx_binloc_product_location ADD CONSTRAINT fk_binloc_prod_loc_entrepot FOREIGN KEY (fk_entrepot) REFERENCES llx_entrepot (rowid) ON DELETE CASCADE;
