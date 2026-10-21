-- Opportunity product lines: a product of Accounting (copied from the ERP: erp_product_id) or a new product typed by hand,
-- with its product number and unit.
ALTER TABLE opportunity_products ADD COLUMN IF NOT EXISTS erp_product_id BIGINT;
ALTER TABLE opportunity_products ADD COLUMN IF NOT EXISTS part_number VARCHAR(80);
ALTER TABLE opportunity_products ADD COLUMN IF NOT EXISTS unit VARCHAR(30);
