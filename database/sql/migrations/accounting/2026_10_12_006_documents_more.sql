-- More of what Generate needs, prepared once per invoice: the payment remark (the customer's own ERP comment), the seller and what is missing.
ALTER TABLE erp_documents ADD COLUMN IF NOT EXISTS payment_remark TEXT;
ALTER TABLE erp_documents ADD COLUMN IF NOT EXISTS seller_name TEXT;
ALTER TABLE erp_documents ADD COLUMN IF NOT EXISTS missing TEXT NOT NULL DEFAULT '';
TRUNCATE erp_documents;                    -- rebuilt from the copy on the next sync / first Generate
