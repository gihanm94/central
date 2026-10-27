package accounting

import (
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

/*
erp_documents (Inet\Documents): one prepared row per invoice number (customer, order, VAT no, delivery note, credit flag, remark
from the order's comment, payment remark = the comment of the customer's extra field; the customer is the invoice's, else the
order's). It is refreshed right after the ERP sync, only for invoices whose invoice / order / comment rows changed since the last
refresh, so the Generate dialog is a single indexed query. Generating itself still reads the full source rows (DocumentData).

Credit note = the order says so (is_credit) OR the invoice number starts with 4.
*/

const creditSQL = `(coalesce(o.is_credit, false) OR i.invoice_number::text LIKE '4%')`

func isCreditNo(invoiceNumber string, orderIsCredit bool) bool {
	return orderIsCredit || strings.HasPrefix(invoiceNumber, "4")
}

// Payment remark of a customer (inside a GROUP BY invoice: the invoice's customer, else its order's).
const paymentRemarkSQL = `SELECT string_agg(trim(pc.raw_text), E'\n' ORDER BY ef.id) FROM erp_extra_fields ef JOIN erp_comments pc ON pc.id = ef.comment_id
                                         WHERE ef.parent_id = coalesce(max(i.customer_id), max(o.customer_id)) AND trim(coalesce(pc.raw_text, '')) <> ''`

// documentsRefresh refreshes what changed since the last time (everything the first time); returns the rows written.
func documentsRefresh(full bool) int {
	started := php.Str(acc().Scalar("SELECT now()"))
	since := ""
	if !full {
		since = sget("docs.watermark", "")
	}
	t0 := time.Now()
	cond := "NOT EXISTS (SELECT 1 FROM erp_documents d WHERE d.invoice_number = i.invoice_number)"
	if full {
		cond = "TRUE"
	}
	extra := ""
	if since != "" {
		extra = ` OR i.synced_at >= ?::timestamptz - interval '5 seconds' OR o.synced_at >= ?::timestamptz - interval '5 seconds' OR cm.synced_at >= ?::timestamptz - interval '5 seconds' OR cu.synced_at >= ?::timestamptz - interval '5 seconds' OR ef.synced_at >= ?::timestamptz - interval '5 seconds' OR pcm.synced_at >= ?::timestamptz - interval '5 seconds'`
	}
	changed := `SELECT DISTINCT i.invoice_number FROM erp_invoices i
                    LEFT JOIN erp_orders o ON o.id = i.customer_order_id LEFT JOIN erp_comments cm ON cm.id = o.external_comment_id LEFT JOIN erp_customers cu ON cu.id = coalesce(i.customer_id, o.customer_id)
                    LEFT JOIN erp_extra_fields ef ON ef.parent_id = coalesce(i.customer_id, o.customer_id) LEFT JOIN erp_comments pcm ON pcm.id = ef.comment_id
                    WHERE i.invoice_number IS NOT NULL AND (` + cond + extra + `)`
	var par []any
	if since != "" {
		for i := 0; i < 6; i++ {
			par = append(par, since)
		}
	}
	n := acc().Exec(`INSERT INTO erp_documents (invoice_number, invoice_date, customer_id, customer_code, customer_name, order_id, order_number, vat_no, delivery_no, is_credit, remark, currency_code, line_count, amount, payment_remark, seller_name, missing, search, refreshed_at)
            SELECT x.* , lower(concat_ws(' ', x.invoice_number, x.order_number, x.vat_no, x.customer_code, x.customer_name, x.delivery_no, x.remark, x.payment_remark, x.seller_name)), now() FROM (
              SELECT i.invoice_number, min(i.invoice_date) AS invoice_date, coalesce(max(i.customer_id), max(o.customer_id)) AS customer_id, coalesce(max(i.customer_code), max(c.code)) AS customer_code, max(c.name) AS customer_name,
                     max(i.customer_order_id) AS order_id, coalesce(max(i.customer_order_number), max(o.order_number)) AS order_number,
                     coalesce(nullif(max(o.vat_number), ''), max(c.vat_number)) AS vat_no,
                     coalesce((SELECT oi2.delivery_note_number::text FROM erp_order_deliveries d JOIN erp_order_invoices oi2 ON oi2.id = d.invoice_id WHERE d.id = max(i.delivery_row_id)),
                       (SELECT oi.delivery_note_number::text FROM erp_order_invoices oi WHERE oi.business_contact_order_id = max(i.customer_order_id) ORDER BY oi.id LIMIT 1)) AS delivery_no,
                     bool_or(`+creditSQL+`) AS is_credit,
                     nullif(left(trim(max(cm.raw_text)), 400), '') AS remark, max(i.currency_code) AS currency_code, count(*)::int AS line_count,
                     sum(coalesce(i.invoiced_quantity, 0) * coalesce(i.price, 0) * (1 - coalesce(i.discount, 0) / 100)) AS amount,
                     nullif(left(trim((`+paymentRemarkSQL+`)), 600), '') AS payment_remark, nullif(trim(max(coalesce(s.first_name, '') || ' ' || coalesce(s.last_name, ''))), '') AS seller_name,
                     concat_ws(',', CASE WHEN max(c.id) IS NULL THEN 'customer' END, CASE WHEN max(o.id) IS NULL THEN 'order' END, CASE WHEN nullif(trim((`+paymentRemarkSQL+`)), '') IS NULL THEN 'payment remark' END) AS missing
              FROM erp_invoices i JOIN (`+changed+`) ch ON ch.invoice_number = i.invoice_number
              LEFT JOIN erp_orders o ON o.id = i.customer_order_id LEFT JOIN erp_customers c ON c.id = coalesce(i.customer_id, o.customer_id) LEFT JOIN erp_comments cm ON cm.id = o.external_comment_id LEFT JOIN erp_sellers s ON s.id = o.seller_id
              GROUP BY i.invoice_number) x
            ON CONFLICT (invoice_number) DO UPDATE SET invoice_date = EXCLUDED.invoice_date, customer_id = EXCLUDED.customer_id, customer_code = EXCLUDED.customer_code, customer_name = EXCLUDED.customer_name,
              order_id = EXCLUDED.order_id, order_number = EXCLUDED.order_number, vat_no = EXCLUDED.vat_no, delivery_no = EXCLUDED.delivery_no, is_credit = EXCLUDED.is_credit, remark = EXCLUDED.remark,
              currency_code = EXCLUDED.currency_code, line_count = EXCLUDED.line_count, amount = EXCLUDED.amount, payment_remark = EXCLUDED.payment_remark, seller_name = EXCLUDED.seller_name, missing = EXCLUDED.missing, search = EXCLUDED.search, refreshed_at = now()`, par...)
	sput("docs.watermark", started, 0)
	if n > 0 {
		logInfo("sync", "documents: "+php.Str(n)+" invoice(s) prepared for Generate", om("ms", int(time.Since(t0).Milliseconds()), "full", since == ""))
	}
	return n
}

// documentsRefreshQuietly never lets a problem here break a sync.
func documentsRefreshQuietly() {
	if err := app.Catch(func() { documentsRefresh(false) }); err != nil {
		logException("sync", err, "documents refresh failed")
	}
}

// ---------------------------------------------------------------- SellerStamp

/*
Seller and department on every invoice line (Support\SellerStamp). Two departments: the seller's ERP department (Persons.DepartmentId →
erp_departments, copied as code and name) and the app department picked by hand on the seller. Ids and names are copied onto
erp_sellers / erp_invoices so lists and reports read them without a lookup and keep them after a rename. Called after an invoice
or seller sync and after a seller is saved; only rows that change are written.
*/
func sellerStampRun() int {
	// 1. department names on the sellers (this app's departments live in the core database)
	names := map[int]string{}
	for _, r := range db.Select("SELECT id, name FROM departments") {
		names[r.Int("id")] = r.Str("name")
	}
	for _, r := range acc().Select("SELECT DISTINCT app_department_id AS id FROM erp_sellers WHERE app_department_id IS NOT NULL") {
		var name any
		if n, ok := names[r.Int("id")]; ok {
			name = n
		}
		acc().Exec("UPDATE erp_sellers SET app_department_name = ? WHERE app_department_id = ? AND app_department_name IS DISTINCT FROM ?", name, r.Int("id"), name)
	}
	acc().Exec("UPDATE erp_sellers SET app_department_name = NULL WHERE app_department_id IS NULL AND app_department_name IS NOT NULL")

	// 2. the seller's ERP department code and name (cleared when the seller has none or it is not synced)
	acc().Exec(`UPDATE erp_sellers s SET department_code = nullif(trim(d.code), ''), department_name = coalesce(nullif(trim(d.name), ''), nullif(trim(d.description), ''))
                    FROM erp_departments d
                   WHERE d.id = s.department_id
                     AND (s.department_code IS DISTINCT FROM nullif(trim(d.code), '') OR s.department_name IS DISTINCT FROM coalesce(nullif(trim(d.name), ''), nullif(trim(d.description), '')))`)
	acc().Exec(`UPDATE erp_sellers s SET department_code = NULL, department_name = NULL
                   WHERE (s.department_code IS NOT NULL OR s.department_name IS NOT NULL)
                     AND NOT EXISTS (SELECT 1 FROM erp_departments d WHERE d.id = s.department_id)`)

	// 3. invoice lines: seller (by employee number) and the seller's departments
	return acc().Exec(`UPDATE erp_invoices i
                            SET seller_id = s.id,
                                seller_name = nullif(trim(concat_ws(' ', s.first_name, s.last_name)), ''),
                                app_department_id = s.app_department_id,
                                app_department_name = s.app_department_name,
                                erp_department_id = s.department_id,
                                department_code = s.department_code,
                                department_name = s.department_name
                           FROM erp_sellers s
                          WHERE upper(trim(i.seller)) = upper(trim(s.employee_number)) AND trim(coalesce(s.employee_number, '')) <> ''
                            AND (i.seller_id IS DISTINCT FROM s.id
                                 OR i.seller_name IS DISTINCT FROM nullif(trim(concat_ws(' ', s.first_name, s.last_name)), '')
                                 OR i.app_department_id IS DISTINCT FROM s.app_department_id
                                 OR i.app_department_name IS DISTINCT FROM s.app_department_name
                                 OR i.erp_department_id IS DISTINCT FROM s.department_id
                                 OR i.department_code IS DISTINCT FROM s.department_code
                                 OR i.department_name IS DISTINCT FROM s.department_name)`)
}

// sellerStampQuietly: a failure is logged and never stops the sync or the save that called it.
func sellerStampQuietly() {
	if err := app.Catch(func() {
		if n := sellerStampRun(); n > 0 {
			logInfo("sync", "invoice seller / department stamped on "+php.Str(n)+" line(s)")
		}
	}); err != nil {
		logException("sync", err, "invoice seller / department not stamped")
	}
}
