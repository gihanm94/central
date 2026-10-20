-- CRM leads look up ERP customers and invoices by tax ID (digits only), and quotes by customer.
CREATE INDEX IF NOT EXISTS erp_customers_vat_digits_idx ON erp_customers ((regexp_replace(coalesce(vat_number, ''), '\D', '', 'g')));
CREATE INDEX IF NOT EXISTS erp_documents_vat_digits_idx ON erp_documents ((regexp_replace(coalesce(vat_no, ''), '\D', '', 'g')));
CREATE INDEX IF NOT EXISTS erp_documents_customer_idx ON erp_documents (customer_id);
CREATE INDEX IF NOT EXISTS erp_quotes_customer_idx ON erp_quotes (customer_id);
