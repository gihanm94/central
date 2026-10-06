-- Sellers (ERP persons): columns the ERP does not have, filled in by hand. A sync never writes to them.
ALTER TABLE erp_sellers ADD COLUMN IF NOT EXISTS email TEXT;
ALTER TABLE erp_sellers ADD COLUMN IF NOT EXISTS user_id BIGINT;
ALTER TABLE erp_sellers ADD COLUMN IF NOT EXISTS lark_id TEXT;
ALTER TABLE erp_sellers ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ;
UPDATE erp_sellers SET email = email_address WHERE email IS NULL AND email_address IS NOT NULL AND email_address <> '';
