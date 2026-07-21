-- Copyright (C) 2026 Zachary Melo
--
-- Binloc: warehouse levels indexes and constraints
--

ALTER TABLE llx_binloc_warehouse_levels ADD INDEX idx_binloc_wh_level_pos (fk_entrepot, position);
ALTER TABLE llx_binloc_warehouse_levels ADD INDEX idx_binloc_wh_level_entrepot (fk_entrepot);
ALTER TABLE llx_binloc_warehouse_levels ADD CONSTRAINT fk_binloc_wh_level_entrepot FOREIGN KEY (fk_entrepot) REFERENCES llx_entrepot (rowid) ON DELETE CASCADE;
