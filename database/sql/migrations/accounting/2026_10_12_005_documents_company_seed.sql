-- One prepared row per invoice number, refreshed after the ERP sync (see Accounting\Inet\Documents).
-- The Generate dialog reads this instead of joining invoices, orders, customers, comments and deliveries on every search.
CREATE TABLE IF NOT EXISTS erp_documents (
    invoice_number  BIGINT PRIMARY KEY,
    invoice_date    TIMESTAMPTZ,
    customer_id     BIGINT,
    customer_code   TEXT,
    customer_name   TEXT,
    order_id        BIGINT,
    order_number    TEXT,
    vat_no          TEXT,
    delivery_no     TEXT,
    is_credit       BOOLEAN NOT NULL DEFAULT FALSE,
    remark          TEXT,
    currency_code   TEXT,
    line_count      INTEGER NOT NULL DEFAULT 0,
    amount          NUMERIC(24,6) NOT NULL DEFAULT 0,
    search          TEXT NOT NULL DEFAULT '',
    refreshed_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS erp_documents_credit_idx ON erp_documents (is_credit, invoice_number DESC);
CREATE INDEX IF NOT EXISTS erp_invoices_synced_at_idx ON erp_invoices (synced_at);
CREATE INDEX IF NOT EXISTS erp_orders_synced_at_idx ON erp_orders (synced_at);
CREATE INDEX IF NOT EXISTS inets_no_invoice_idx ON inets (no_invoice);

-- Our company, filled in once (change it on Accounting → Company). Secrets (INET user code / access key / API key) are not seeded.
INSERT INTO companies (name_en, name_th, address_en, address_th, tax_info, tax_no, phone, fax, email, website, tax_id, branch_id, service_code, field_type,
                       payment_note_en, payment_note_th, receipt_note_en, receipt_note_th, credit_note_en, credit_note_th, batch_text_en, batch_text_th, footer_text_en, footer_text_th, is_primary)
SELECT 'ACME INTERNATIONAL (THAILAND) LIMITED', 'บริษัท แอ็ดมี่ อินเตอร์เนชั่นแนล (ประเทศไทย) จำกัด',
       '630 Onnuj 54, Onnuj Subdistrict, Suanluang District, Bangkok 10250', '630 ซอยอ่อนนุช 54 แขวงอ่อนนุช เขตสวนหลวง กรุงเทพมหานคร 10250',
       'เลขประจำตัวผู้เสียภาษีอากร 0105526049042 (สำนักงานใหญ่)', '0105526049042', '0 2320 5200', '0 2320 5208', 'info@acme-inter.com', 'www.acme-inter.com',
       '0105526049042', '00000', 'S06', '5',
       '***The company reserves the right to charge interest on any outstanding balance at a rate of 1.50% per month until full payment is received.***',
       '***บริษัทฯ สงวนสิทธิ์ที่จะคิดดอกเบี้ยจากเงินที่ค้างชำระในอัตราร้อยละ 1.50 ต่อเดือน ตลอดไปจนกว่าจะได้รับการชำระเสร็จสิ้น***',
       'The receipt will be complete only when the company has received payment as per the documents.',
       'ใบเสร็จจะสมบูรณ์ก็ต่อเมื่อบริษัทฯ ได้รับชำระตามเอกสารเรียบร้อยแล้ว', '', '', 'Batch number', 'หมายเลขแบทช์',
       '** This document has been prepared and submitted to the Revenue Department electronically **',
       '** เอกสารนี้ได้จัดทำและส่งข้อมูลให้แก่กรมสรรพากรด้วยวิธีการทางอิเล็กทรอนิกส์ **', TRUE
WHERE NOT EXISTS (SELECT 1 FROM companies WHERE is_primary);
