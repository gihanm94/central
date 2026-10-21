-- Invoices carry their seller and department. The invoice log's seller is the seller's employee number (ERP EmployeeNumber);
-- the department is the one picked on the seller (Accounting → Sellers → Department (this app)). Names are copied too, so an
-- invoice still shows them when the seller or department is renamed or removed later. Filled by Support\SellerStamp after
-- every invoice / seller sync and every seller save — never by the sync itself.
ALTER TABLE erp_sellers  ADD COLUMN IF NOT EXISTS app_department_name TEXT;
ALTER TABLE erp_invoices ADD COLUMN IF NOT EXISTS seller_id BIGINT;
ALTER TABLE erp_invoices ADD COLUMN IF NOT EXISTS seller_name TEXT;
ALTER TABLE erp_invoices ADD COLUMN IF NOT EXISTS app_department_id BIGINT;
ALTER TABLE erp_invoices ADD COLUMN IF NOT EXISTS app_department_name TEXT;
CREATE INDEX IF NOT EXISTS erp_sellers_employee_number_idx ON erp_sellers (upper(trim(employee_number)));
CREATE INDEX IF NOT EXISTS erp_invoices_seller_idx ON erp_invoices (upper(trim(seller)));
CREATE INDEX IF NOT EXISTS erp_invoices_app_department_idx ON erp_invoices (app_department_id);
