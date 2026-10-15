-- One customer and one Inet with a stored tax-invoice PDF (account_db), for the PDF download flow.
-- The PDF file itself must exist at storage/inet/demo/1001_388.pdf (run-flows.ts --seed-hint prints how).
INSERT INTO erp_customers (id, code, name, vat_number) VALUES (9001, 'C-9001', 'Demo Customer Co., Ltd.', '0105551234567')
ON CONFLICT (id) DO NOTHING;
INSERT INTO inets (no_invoice, invoice_date, is_credit, is_manual, is_international, is_issue, is_correction, customer_id, customer_code, customer_name,
                   currency_code, amount, tax_amount, total_amount, product_count,
                   is_388_generate, is_t01_generate, is_81_generate, is_388_send, is_t01_send, is_81_send,
                   is_388_fetch, is_t01_fetch, is_81_fetch, is_388_download, is_t01_download, is_81_download, auto_pdf, pdf_388)
SELECT 1001, now(), false, false, false, false, false, 9001, 'C-9001', 'Demo Customer Co., Ltd.', 'THB', 1000, 70, 1070, 1,
       true, false, false, true, false, false, false, false, false, false, false, false, false, 'inet/demo/1001_388.pdf'
WHERE NOT EXISTS (SELECT 1 FROM inets WHERE no_invoice = 1001);
