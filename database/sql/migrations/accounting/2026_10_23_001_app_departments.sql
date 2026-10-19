-- The department in this app (user_db departments.id) a seller belongs to, picked by hand on the seller
-- (never touched by the ERP sync), and copied onto each Inet when it is generated. ERP → Invoices
-- shows people only the stamped invoices of their own department.
ALTER TABLE erp_sellers ADD COLUMN IF NOT EXISTS app_department_id BIGINT;
ALTER TABLE inets       ADD COLUMN IF NOT EXISTS app_department_id BIGINT;
CREATE INDEX IF NOT EXISTS inets_app_department ON inets (app_department_id);
