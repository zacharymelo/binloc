-- Copyright (C) 2026 Zachary Melo

ALTER TABLE llx_wareloc_hierarchy_meta ADD UNIQUE INDEX uk_wareloc_meta_depth (entity, fk_entrepot, depth);
ALTER TABLE llx_wareloc_hierarchy_meta ADD INDEX idx_wareloc_meta_entrepot (fk_entrepot);
