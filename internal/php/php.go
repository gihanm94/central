// Package php has the small PHP behaviours the ported code relies on: loose casts ((int), (string),
// filter_var(..., FILTER_VALIDATE_BOOL)), empty(), number_format(), date() / strtotime(), mb_substr() …
// They keep the Go code reading like the PHP it came from and give the same output.
package php

import (
	"fmt"
	"math"
	"strconv"
	"strings"
	"time"
	"unicode"
	"unicode/utf8"
)

// Int is (int) $v.
func Int(v any) int {
	switch x := v.(type) {
	case nil:
		return 0
	case int:
		return x
	case int64:
		return int(x)
	case int32:
		return int(x)
	case float64:
		return int(x)
	case float32:
		return int(x)
	case bool:
		if x {
			return 1
		}
		return 0
	case string:
		s := strings.TrimSpace(x)
		end := 0
		for end < len(s) && (s[end] >= '0' && s[end] <= '9' || (end == 0 && (s[end] == '-' || s[end] == '+'))) {
			end++
		}
		if end < len(s) && s[end] == '.' { // "12.7" → 12
			if f, err := strconv.ParseFloat(leadingFloat(s), 64); err == nil {
				return int(f)
			}
		}
		i, _ := strconv.Atoi(s[:end])
		return i
	}
	return 0
}

func leadingFloat(s string) string {
	end := 0
	dot := false
	for end < len(s) {
		c := s[end]
		if c >= '0' && c <= '9' || (end == 0 && (c == '-' || c == '+')) || (c == '.' && !dot) {
			dot = dot || c == '.'
			end++
			continue
		}
		break
	}
	return s[:end]
}

// IntPtr is a nullable int: nil / "" → nil.
func IntPtr(v any) any {
	if v == nil {
		return nil
	}
	if s, ok := v.(string); ok && strings.TrimSpace(s) == "" {
		return nil
	}
	return Int(v)
}

// Float is (float) $v.
func Float(v any) float64 {
	switch x := v.(type) {
	case nil:
		return 0
	case float64:
		return x
	case float32:
		return float64(x)
	case int:
		return float64(x)
	case int64:
		return float64(x)
	case bool:
		if x {
			return 1
		}
		return 0
	case string:
		f, _ := strconv.ParseFloat(leadingFloat(strings.TrimSpace(x)), 64)
		return f
	}
	return 0
}

// Str is (string) $v (true → "1", false/nil → "", floats without needless zeros).
func Str(v any) string {
	switch x := v.(type) {
	case nil:
		return ""
	case string:
		return x
	case int:
		return strconv.Itoa(x)
	case int64:
		return strconv.FormatInt(x, 10)
	case float64:
		if x == math.Trunc(x) && math.Abs(x) < 1e15 {
			return strconv.FormatInt(int64(x), 10)
		}
		return strconv.FormatFloat(x, 'G', 14, 64)
	case bool:
		if x {
			return "1"
		}
		return ""
	case []byte:
		return string(x)
	case fmt.Stringer:
		return x.String()
	}
	return fmt.Sprint(v)
}

// Bool is filter_var($v, FILTER_VALIDATE_BOOL): true for true, 1, "1", "true", "on", "yes", "t".
func Bool(v any) bool {
	switch x := v.(type) {
	case nil:
		return false
	case bool:
		return x
	case int:
		return x == 1
	case float64:
		return x == 1
	case string:
		switch strings.ToLower(strings.TrimSpace(x)) {
		case "1", "true", "on", "yes", "t":
			return true
		}
	}
	return false
}

// Truthy is PHP's (bool) $v / if ($v).
func Truthy(v any) bool { return !Empty(v) }

// Empty is empty($v).
func Empty(v any) bool {
	switch x := v.(type) {
	case nil:
		return true
	case bool:
		return !x
	case int:
		return x == 0
	case int64:
		return x == 0
	case float64:
		return x == 0
	case string:
		return x == "" || x == "0"
	case []any:
		return len(x) == 0
	case []string:
		return len(x) == 0
	case map[string]any:
		return len(x) == 0
	}
	return false
}

// Blank is trim((string) $v) === ”.
func Blank(v any) bool { return strings.TrimSpace(Str(v)) == "" }

// Coalesce returns the first value that is not nil (PHP ??).
func Coalesce(vals ...any) any {
	for _, v := range vals {
		if v != nil {
			return v
		}
	}
	return nil
}

// Or returns the first truthy value (PHP ?:), else the last one.
func Or(vals ...any) any {
	for _, v := range vals {
		if Truthy(v) {
			return v
		}
	}
	if len(vals) == 0 {
		return nil
	}
	return vals[len(vals)-1]
}

// OrStr is ?: for strings.
func OrStr(vals ...string) string {
	for _, v := range vals {
		if v != "" && v != "0" {
			return v
		}
	}
	if len(vals) == 0 {
		return ""
	}
	return vals[len(vals)-1]
}

// NumberFormat is number_format($n, $decimals, $dec, $thousands) (half away from zero, like PHP).
func NumberFormat(n float64, decimals int, dec, sep string) string {
	if decimals < 0 {
		decimals = 0
	}
	p := math.Pow(10, float64(decimals))
	r := math.Round(math.Abs(n)*p) / p
	s := strconv.FormatFloat(r, 'f', decimals, 64)
	intPart, frac := s, ""
	if i := strings.IndexByte(s, '.'); i >= 0 {
		intPart, frac = s[:i], s[i+1:]
	}
	var b strings.Builder
	for i, c := range intPart {
		if i > 0 && (len(intPart)-i)%3 == 0 {
			b.WriteString(sep)
		}
		b.WriteRune(c)
	}
	out := b.String()
	if decimals > 0 {
		out += dec + frac
	}
	if n < 0 && r != 0 {
		out = "-" + out
	}
	return out
}

// NF is number_format($n, $decimals) with "." and ",".
func NF(n any, decimals int) string { return NumberFormat(Float(n), decimals, ".", ",") }

// NumberClean is number_clean(): 2 decimals without trailing zeros.
func NumberClean(n any) string {
	s := NumberFormat(Float(n), 2, ".", ",")
	s = strings.TrimRight(s, "0")
	return strings.TrimRight(s, ".")
}

// Substr is mb_substr($s, $start, $length) (length < 0 = to the end).
func Substr(s string, start, length int) string {
	r := []rune(s)
	if start < 0 {
		start = len(r) + start
		if start < 0 {
			start = 0
		}
	}
	if start > len(r) {
		return ""
	}
	end := len(r)
	if length >= 0 && start+length < end {
		end = start + length
	}
	return string(r[start:end])
}

// Len is mb_strlen.
func Len(s string) int { return utf8.RuneCountInString(s) }

// Limit is str_limit(): cut at $limit characters with an ellipsis.
func Limit(s string, limit int) string {
	if Len(s) > limit {
		return strings.TrimRightFunc(Substr(s, 0, limit-1), unicode.IsSpace) + "…"
	}
	return s
}

// Ucfirst is ucfirst().
func Ucfirst(s string) string {
	if s == "" {
		return s
	}
	r, n := utf8.DecodeRuneInString(s)
	return string(unicode.ToUpper(r)) + s[n:]
}

// Initials is initials(): first letters of the first and last word.
func Initials(name string) string {
	parts := strings.Fields(name)
	if len(parts) == 0 {
		return ""
	}
	out := Substr(parts[0], 0, 1)
	if len(parts) > 1 {
		out += Substr(parts[len(parts)-1], 0, 1)
	}
	return strings.ToUpper(out)
}

// InList is in_array($v, $list, true) for strings.
func InList(v string, list ...string) bool {
	for _, x := range list {
		if x == v {
			return true
		}
	}
	return false
}

// ---------------------------------------------------------------- time

// Loc is the company time zone (app.timezone), set at boot.
var Loc = time.UTC

// Now is time() in the company zone.
func Now() time.Time { return time.Now().In(Loc) }

// NowString is now(): "Y-m-d H:i:s".
func NowString() string { return Now().Format("2006-01-02 15:04:05") }

var layouts = []string{
	"2006-01-02 15:04:05.999999999Z07:00", "2006-01-02 15:04:05.999999999Z07", "2006-01-02 15:04:05Z07:00", "2006-01-02 15:04:05Z07",
	"2006-01-02T15:04:05.999999999Z07:00", "2006-01-02T15:04:05Z07:00", "2006-01-02T15:04:05.999999999", "2006-01-02T15:04:05", "2006-01-02T15:04",
	"2006-01-02 15:04:05.999999999", "2006-01-02 15:04:05", "2006-01-02 15:04", "2006-01-02", "15:04:05", "15:04",
	"02/01/2006", "02-01-2006", time.RFC1123Z, time.RFC1123,
}

// ParseTime reads the date / timestamp strings PostgreSQL and the forms use ("" or unreadable → false).
func ParseTime(s string) (time.Time, bool) {
	s = strings.TrimSpace(s)
	if s == "" {
		return time.Time{}, false
	}
	switch strings.ToLower(s) {
	case "now":
		return Now(), true
	case "today":
		n := Now()
		return time.Date(n.Year(), n.Month(), n.Day(), 0, 0, 0, 0, Loc), true
	case "tomorrow":
		n := Now().AddDate(0, 0, 1)
		return time.Date(n.Year(), n.Month(), n.Day(), 0, 0, 0, 0, Loc), true
	case "yesterday":
		n := Now().AddDate(0, 0, -1)
		return time.Date(n.Year(), n.Month(), n.Day(), 0, 0, 0, 0, Loc), true
	}
	for _, l := range layouts {
		if t, err := time.ParseInLocation(l, s, Loc); err == nil {
			if l == "15:04:05" || l == "15:04" {
				n := Now()
				t = time.Date(n.Year(), n.Month(), n.Day(), t.Hour(), t.Minute(), t.Second(), 0, Loc)
			}
			return t.In(Loc), true
		}
	}
	if i, err := strconv.ParseInt(s, 10, 64); err == nil && len(s) >= 9 {
		return time.Unix(i, 0).In(Loc), true
	}
	return time.Time{}, false
}

// StrToTime is strtotime(): unix seconds, or 0 when unreadable.
func StrToTime(v any) int64 {
	switch x := v.(type) {
	case time.Time:
		return x.Unix()
	case nil:
		return 0
	}
	t, ok := ParseTime(Str(v))
	if !ok {
		return 0
	}
	return t.Unix()
}

// Date is date($format, $ts) for the format letters this app uses.
func Date(format string, t time.Time) string {
	t = t.In(Loc)
	var b strings.Builder
	for i := 0; i < len(format); i++ {
		c := format[i]
		switch c {
		case 'd':
			fmt.Fprintf(&b, "%02d", t.Day())
		case 'j':
			b.WriteString(strconv.Itoa(t.Day()))
		case 'D':
			b.WriteString(t.Weekday().String()[:3])
		case 'l':
			b.WriteString(t.Weekday().String())
		case 'N':
			wd := int(t.Weekday())
			if wd == 0 {
				wd = 7
			}
			b.WriteString(strconv.Itoa(wd))
		case 'w':
			b.WriteString(strconv.Itoa(int(t.Weekday())))
		case 'm':
			fmt.Fprintf(&b, "%02d", int(t.Month()))
		case 'n':
			b.WriteString(strconv.Itoa(int(t.Month())))
		case 'M':
			b.WriteString(t.Month().String()[:3])
		case 'F':
			b.WriteString(t.Month().String())
		case 't':
			b.WriteString(strconv.Itoa(time.Date(t.Year(), t.Month()+1, 0, 0, 0, 0, 0, Loc).Day()))
		case 'Y':
			b.WriteString(strconv.Itoa(t.Year()))
		case 'y':
			fmt.Fprintf(&b, "%02d", t.Year()%100)
		case 'H':
			fmt.Fprintf(&b, "%02d", t.Hour())
		case 'G':
			b.WriteString(strconv.Itoa(t.Hour()))
		case 'h':
			h := t.Hour() % 12
			if h == 0 {
				h = 12
			}
			fmt.Fprintf(&b, "%02d", h)
		case 'g':
			h := t.Hour() % 12
			if h == 0 {
				h = 12
			}
			b.WriteString(strconv.Itoa(h))
		case 'i':
			fmt.Fprintf(&b, "%02d", t.Minute())
		case 's':
			fmt.Fprintf(&b, "%02d", t.Second())
		case 'A':
			if t.Hour() < 12 {
				b.WriteString("AM")
			} else {
				b.WriteString("PM")
			}
		case 'a':
			if t.Hour() < 12 {
				b.WriteString("am")
			} else {
				b.WriteString("pm")
			}
		case 'U':
			b.WriteString(strconv.FormatInt(t.Unix(), 10))
		case 'c':
			b.WriteString(t.Format("2006-01-02T15:04:05-07:00"))
		case 'P':
			b.WriteString(t.Format("-07:00"))
		case 'W':
			_, w := t.ISOWeek()
			fmt.Fprintf(&b, "%02d", w)
		case 'o':
			y, _ := t.ISOWeek()
			b.WriteString(strconv.Itoa(y))
		case 'z':
			b.WriteString(strconv.Itoa(t.YearDay() - 1))
		case '\\':
			if i+1 < len(format) {
				i++
				b.WriteByte(format[i])
			}
		default:
			b.WriteByte(c)
		}
	}
	return b.String()
}

// DateOf is date($format, strtotime($value)) ("" when unreadable).
func DateOf(format string, v any) string {
	if t, ok := v.(time.Time); ok {
		return Date(format, t)
	}
	t, ok := ParseTime(Str(v))
	if !ok {
		return ""
	}
	return Date(format, t)
}

// Today is date('Y-m-d').
func Today() string { return Date("Y-m-d", Now()) }

// Unix is a time from unix seconds in the company zone.
func Unix(s int64) time.Time { return time.Unix(s, 0).In(Loc) }
