-- The seller's ERP department (Persons.DepartmentId → erp_departments) by code and name, on the seller and on each
-- invoice line of that seller. Filled by Support\SellerStamp after a seller, invoice or department sync and a seller save.
ALTER TABLE erp_sellers  ADD COLUMN IF NOT EXISTS department_code TEXT;
ALTER TABLE erp_sellers  ADD COLUMN IF NOT EXISTS department_name TEXT;
ALTER TABLE erp_invoices ADD COLUMN IF NOT EXISTS erp_department_id BIGINT;
ALTER TABLE erp_invoices ADD COLUMN IF NOT EXISTS department_code TEXT;
ALTER TABLE erp_invoices ADD COLUMN IF NOT EXISTS department_name TEXT;
CREATE INDEX IF NOT EXISTS erp_invoices_department_code_idx ON erp_invoices (department_code);
