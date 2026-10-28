package app

import (
	"net/mail"
	"regexp"
	"strconv"
	"strings"

	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
)

// Rule is one field's rules, e.g. Rule{"email", "required|email|max:190|unique:users,email,5"}.
type Rule struct {
	Field string
	Rules string
	Label string // "" = the field name with spaces
}

// Clean is validated input in field order: strings, nil, bool (boolean rule) or the raw value (lists).
type Clean struct {
	Keys []string
	Vals map[string]any
}

func NewClean() *Clean { return &Clean{Vals: map[string]any{}} }

func (c *Clean) Set(k string, v any) {
	if _, ok := c.Vals[k]; !ok {
		c.Keys = append(c.Keys, k)
	}
	c.Vals[k] = v
}

func (c *Clean) Has(k string) bool   { _, ok := c.Vals[k]; return ok }
func (c *Clean) Get(k string) any    { return c.Vals[k] }
func (c *Clean) Str(k string) string { return conv.Str(c.Vals[k]) }
func (c *Clean) Int(k string) int    { return conv.Int(c.Vals[k]) }
func (c *Clean) Bool(k string) bool  { return conv.Bool(c.Vals[k]) }

func (c *Clean) Delete(k string) {
	if _, ok := c.Vals[k]; !ok {
		return
	}
	delete(c.Vals, k)
	for i, x := range c.Keys {
		if x == k {
			c.Keys = append(c.Keys[:i], c.Keys[i+1:]...)
			break
		}
	}
}

// Map is the values as a plain map.
func (c *Clean) Map() map[string]any {
	out := make(map[string]any, len(c.Vals))
	for k, v := range c.Vals {
		out[k] = v
	}
	return out
}

var strongPw = regexp.MustCompile(`[a-z]`)
var upperPw = regexp.MustCompile(`[A-Z]`)
var digitPw = regexp.MustCompile(`\d`)
var numericRe = regexp.MustCompile(`^\s*[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\s*$`)
var intRe = regexp.MustCompile(`^[+-]?\d+$`)

// IsNumeric is PHP is_numeric for strings.
func IsNumeric(v any) bool {
	switch x := v.(type) {
	case int, float64:
		return true
	case string:
		return numericRe.MatchString(x)
	}
	return false
}

// Validate is Validator::validate($data, $rules, $labels): stops with a ValidationError when something is wrong.
func (c *Ctx) Validate(data *config.Map, rules []Rule) *Clean {
	errs := NewErrors()
	clean := NewClean()
	for _, r := range rules {
		list := strings.Split(r.Rules, "|")
		raw, _ := data.Get(r.Field)
		var value any = raw
		if s, ok := raw.(string); ok {
			value = strings.TrimSpace(s)
		}
		label := r.Label
		if label == "" {
			label = strings.ReplaceAll(r.Field, "_", " ")
		}
		empty := value == nil || value == "" || (isList(value) && len(InList(value)) == 0)
		if conv.InList("boolean", list...) {
			clean.Set(r.Field, conv.Bool(value))
			continue
		}
		if empty {
			if conv.InList("required", list...) {
				errs.Add(r.Field, c.T(":Field is required.", "Field", conv.Ucfirst(c.T(label))))
			} else {
				clean.Set(r.Field, nil)
			}
			continue
		}
		sv := conv.Str(value)
		if !isScalar(value) {
			sv = ""
		}
		for _, rule := range list {
			name, arg, _ := strings.Cut(rule, ":")
			F := conv.Ucfirst(c.T(label))
			msg := ""
			switch name {
			case "email":
				if _, err := mail.ParseAddress(sv); err != nil || !strings.Contains(sv, "@") || strings.ContainsAny(sv, " <>") {
					msg = c.T("Enter a valid e-mail address.")
				}
			case "min":
				if conv.Len(sv) < conv.Int(arg) {
					msg = c.T(":Field must be at least :n characters.", "Field", F, "n", arg)
				}
			case "max":
				if conv.Len(sv) > conv.Int(arg) {
					msg = c.T(":Field must be :n characters or fewer.", "Field", F, "n", arg)
				}
			case "numeric":
				if !IsNumeric(sv) {
					msg = c.T(":Field must be a number.", "Field", F)
				}
			case "integer":
				if !intRe.MatchString(strings.TrimSpace(sv)) {
					msg = c.T(":Field must be a whole number.", "Field", F)
				}
			case "date":
				if conv.StrToTime(sv) == 0 {
					msg = c.T(":Field must be a date.", "Field", F)
				}
			case "in":
				if !conv.InList(sv, strings.Split(arg, ",")...) {
					msg = c.T("Pick :field from the list.", "field", c.T(label))
				}
			case "same":
				other, _ := data.Get(arg)
				if sv != conv.Str(other) {
					msg = c.T(":Field does not match.", "Field", F)
				}
			case "password":
				if len([]rune(sv)) < 8 || !strongPw.MatchString(sv) || !upperPw.MatchString(sv) || !digitPw.MatchString(sv) {
					msg = c.T("Use at least 8 characters with upper and lower case letters and a number.")
				}
			case "unique":
				if !ruleUnique(sv, arg) {
					msg = c.T("This :field is already used.", "field", c.T(label))
				}
			case "exists":
				if !ruleExists(sv, arg) {
					msg = c.T("Pick :field from the list.", "field", c.T(label))
				}
			}
			if msg != "" {
				errs.Add(r.Field, msg)
				break
			}
		}
		clean.Set(r.Field, value)
	}
	if errs.Len() > 0 {
		InvalidErrors(errs)
	}
	return clean
}

// ValidateInput validates Request::all().
func (c *Ctx) ValidateInput(rules ...Rule) *Clean { return c.Validate(c.All(), rules) }

func isList(v any) bool {
	switch v.(type) {
	case []any, *config.Map:
		return true
	}
	return false
}

func isScalar(v any) bool { return !isList(v) }

var identRe = regexp.MustCompile(`^[a-z_][a-z0-9_.]*$`)

func ruleUnique(value, arg string) bool {
	p := strings.Split(arg, ",")
	for len(p) < 4 {
		p = append(p, "")
	}
	table, column, ignore, idCol := p[0], p[1], p[2], p[3]
	if idCol == "" {
		idCol = "id"
	}
	if !identRe.MatchString(table) || !identRe.MatchString(column) || !identRe.MatchString(idCol) {
		return false
	}
	sql := "SELECT 1 FROM " + table + " WHERE lower(" + column + "::text) = lower(?)"
	args := []any{value}
	if ignore != "" {
		sql += " AND " + idCol + " <> ?"
		n, _ := strconv.Atoi(ignore)
		args = append(args, n)
	}
	return db.Scalar(sql, args...) == nil
}

func ruleExists(value, arg string) bool {
	table, column, _ := strings.Cut(arg, ",")
	if column == "" {
		column = "id"
	}
	if !identRe.MatchString(table) || !identRe.MatchString(column) {
		return false
	}
	return db.Scalar("SELECT 1 FROM "+table+" WHERE "+column+" = ?", value) != nil
}
