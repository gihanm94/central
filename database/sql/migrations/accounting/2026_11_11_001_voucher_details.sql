-- Vouchers: the rest of the ERP voucher fields, and every voucher / row / payable kept as it came from the ERP (raw),
-- so fields not mapped to a column are still there. The voucher sync expands Comment, Rows and AccountsPayable.
CREATE TABLE IF NOT EXISTS erp_vouchers (id BIGINT PRIMARY KEY, synced_at TIMESTAMPTZ NOT NULL DEFAULT now());
ALTER TABLE erp_vouchers ADD COLUMN IF NOT EXISTS series_id BIGINT;
ALTER TABLE erp_vouchers ADD COLUMN IF NOT EXISTS number TEXT;
ALTER TABLE erp_vouchers ADD COLUMN IF NOT EXISTS text TEXT;
ALTER TABLE erp_vouchers ADD COLUMN IF NOT EXISTS voucher_date TIMESTAMPTZ;
ALTER TABLE erp_vouchers ADD COLUMN IF NOT EXISTS connected_voucher_id BIGINT;
ALTER TABLE erp_vouchers ADD COLUMN IF NOT EXISTS connection_type TEXT;
ALTER TABLE erp_vouchers ADD COLUMN IF NOT EXISTS is_preliminary BOOLEAN;
ALTER TABLE erp_vouchers ADD COLUMN IF NOT EXISTS bundle_number BIGINT;
ALTER TABLE erp_vouchers ADD COLUMN IF NOT EXISTS comment_id BIGINT;
ALTER TABLE erp_vouchers ADD COLUMN IF NOT EXISTS accounts_payable_id BIGINT;
ALTER TABLE erp_vouchers ADD COLUMN IF NOT EXISTS accounting_year_id BIGINT;
ALTER TABLE erp_vouchers ADD COLUMN IF NOT EXISTS reverse_connection_voucher_id BIGINT;
ALTER TABLE erp_vouchers ADD COLUMN IF NOT EXISTS reverse_connection_type TEXT;
ALTER TABLE erp_vouchers ADD COLUMN IF NOT EXISTS invoice_log_ledger_id BIGINT;
ALTER TABLE erp_vouchers ADD COLUMN IF NOT EXISTS accounts_receivable_id BIGINT;
ALTER TABLE erp_vouchers ADD COLUMN IF NOT EXISTS raw JSONB;
ALTER TABLE erp_voucher_rows ADD COLUMN IF NOT EXISTS raw JSONB;
ALTER TABLE erp_payables ADD COLUMN IF NOT EXISTS raw JSONB;
CREATE INDEX IF NOT EXISTS erp_vouchers_voucher_date_idx ON erp_vouchers (voucher_date);
CREATE INDEX IF NOT EXISTS erp_vouchers_accounts_payable_id_idx ON erp_vouchers (accounts_payable_id);
CREATE INDEX IF NOT EXISTS erp_voucher_rows_voucher_id_idx ON erp_voucher_rows (voucher_id);
