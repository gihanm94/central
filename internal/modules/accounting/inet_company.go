package accounting

import (
	"errors"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// Our own company (Inet\Company): the seller on every e-tax document.

var companyFields = [][2]string{
	{"name_th", "Company name (Thai)"}, {"name_en", "Company name (English)"}, {"address_th", "Address (Thai)"}, {"address_en", "Address (English)"},
	{"tax_id", "Tax ID (13 digits)"}, {"branch_id", "Branch ID (00000 = head office)"}, {"tax_no", "Tax number printed on the PDF"}, {"tax_info", "Tax note"},
	{"phone", "Phone"}, {"fax", "Fax"}, {"email", "E-mail"}, {"website", "Website"}, {"service_code", "INET service code"},
	{"user_code", "INET user code"}, {"access_key", "INET access key"}, {"api_key", "INET API key"}, {"field_type", "Field type"},
	{"footer_text_th", "PDF footer (Thai)"}, {"footer_text_en", "PDF footer (English)"},
	{"payment_note_th", "Billing note: interest note (Thai)"}, {"payment_note_en", "Billing note: interest note (English)"},
	{"receipt_note_th", "Receipt note (Thai)"}, {"receipt_note_en", "Receipt note (English)"}, {"credit_note_th", "Credit note text (Thai)"}, {"credit_note_en", "Credit note text (English)"},
}

func companyPrimary() db.Row {
	return acc().First("SELECT * FROM companies WHERE is_primary ORDER BY id LIMIT 1")
}

// companyRequire: the company, or an error telling where to fill it in.
func companyRequire(c *app.Ctx) db.Row {
	co := companyPrimary()
	if co == nil || strings.TrimSpace(co.Str("tax_id")) == "" {
		panic(errors.New(c.T("Fill in the company (tax ID, name, address) under Accounting → Company first.")))
	}
	return co
}

func companySave(in func(k string) string) {
	var cols db.Cols
	for _, f := range companyFields {
		v := strings.TrimSpace(in(f[0]))
		if f[0] == "branch_id" && v == "" {
			v = "00000"
		}
		cols = append(cols, db.Col{Name: f[0], Value: v})
	}
	id := acc().Scalar("SELECT id FROM companies WHERE is_primary ORDER BY id LIMIT 1")
	if id != nil {
		acc().Update("companies", append(cols, db.Col{Name: "updated_at", Value: php.NowString()}), db.Cols{{Name: "id", Value: id}})
	} else {
		acc().Insert("companies", append(cols, db.Col{Name: "is_primary", Value: true}), "")
	}
}
