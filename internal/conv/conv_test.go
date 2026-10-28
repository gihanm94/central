package conv

import "testing"

func TestNumberFormat(t *testing.T) {
	cases := []struct {
		n    float64
		dec  int
		want string
	}{{1234567.891, 2, "1,234,567.89"}, {0.5, 0, "1"}, {-1234.5, 1, "-1,234.5"}, {-0.001, 2, "0.00"}, {999, 2, "999.00"}}
	for _, c := range cases {
		if got := NumberFormat(c.n, c.dec, ".", ","); got != c.want {
			t.Errorf("NumberFormat(%v,%d) = %q, want %q", c.n, c.dec, got, c.want)
		}
	}
	if got := NumberClean(1250.5); got != "1,250.5" {
		t.Errorf("NumberClean = %q", got)
	}
}

func TestCasts(t *testing.T) {
	if Int("12.7abc") != 12 || Int(nil) != 0 || Int(true) != 1 {
		t.Error("Int")
	}
	if !Bool("on") || Bool("0") || Bool("") || !Bool(1) {
		t.Error("Bool")
	}
	if !Empty("0") || !Empty("") || Empty("a") || !Empty([]any{}) {
		t.Error("Empty")
	}
	if Str(true) != "1" || Str(false) != "" || Str(3.0) != "3" {
		t.Error("Str")
	}
}

func TestDate(t *testing.T) {
	tm, ok := ParseTime("2026-10-19 14:34:05")
	if !ok || Date("d M Y H:i", tm) != "19 Oct 2026 14:34" {
		t.Errorf("Date = %q", Date("d M Y H:i", tm))
	}
	if DateOf("Y-m-d", "2026-01-31") != "2026-01-31" || StrToTime("nonsense") != 0 {
		t.Error("DateOf / StrToTime")
	}
}

func TestSubstrInitials(t *testing.T) {
	if Substr("สวัสดีครับ", 0, 3) != "สวั" || Initials("gihan m perera") != "GP" || Limit("abcdef", 4) != "abc…" {
		t.Error("string helpers")
	}
}
