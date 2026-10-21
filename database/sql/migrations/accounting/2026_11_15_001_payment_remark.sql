-- Payment remark = the comment of the customer's extra field (erp_extra_fields.parent_id = customer id → comment_id → erp_comments.raw_text).
-- Kept on the generated document (inets.payment_remark) as it was when generated.
ALTER TABLE inets ADD COLUMN IF NOT EXISTS payment_remark TEXT;
CREATE INDEX IF NOT EXISTS erp_extra_fields_parent_idx ON erp_extra_fields (parent_id);
-- erp_documents is only the list prepared for Generate (made from the ERP copy): empty it so the Generate window
-- builds it again the new way the next time it opens
TRUNCATE erp_documents;
UPDATE erp_settings SET value = NULL WHERE key = 'docs.watermark';
