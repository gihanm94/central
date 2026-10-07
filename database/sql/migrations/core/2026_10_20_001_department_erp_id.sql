-- The department's id in the ERP (erp_departments.id). Revenue of that ERP department is the department's revenue in the CRM targets.
ALTER TABLE departments ADD COLUMN IF NOT EXISTS erp_department_id BIGINT;
