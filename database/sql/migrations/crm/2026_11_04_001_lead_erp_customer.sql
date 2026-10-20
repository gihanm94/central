-- A lead can be connected to its ERP customer (found by tax ID) so its quotes show on the lead page.
ALTER TABLE leads ADD COLUMN IF NOT EXISTS erp_customer_id   BIGINT;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS erp_customer_name TEXT;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS erp_connected_at  TIMESTAMPTZ;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS erp_connected_by  BIGINT;
CREATE INDEX IF NOT EXISTS leads_tax_digits_idx ON leads ((regexp_replace(coalesce(tax_id, ''), '\D', '', 'g')));
