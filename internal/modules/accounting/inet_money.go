package accounting

import (
	"math"
	"strings"
)

// Amount helpers (Inet\Money): round half up to 2 places, written without thousands separators.

func moneyR(v float64, p int) float64 {
	f := math.Pow(10, float64(p))
	if v < 0 {
		v -= 1e-9
	} else {
		v += 1e-9
	}
	return roundHalf(v*f, 0) / f
}

func r2(v float64) float64 { return moneyR(v, 2) }

func moneyS(v float64, p int) string { return nf(moneyR(v, p), p, ".", "") }

func s2(v float64) string { return moneyS(v, 2) }

// moneyFmt is Money::fmt: 1,234.50 for the PDF.
func moneyFmt(v float64) string { return nf(v, 2, ".", ",") }

// ---------------------------------------------------------------- amount in words (AmountTextUtil)

var moneyCur = map[string][4]string{
	"THB": {"บาท", "สตางค์", "Baht", "Satang"}, "USD": {"ดอลลาร์", "เซนต์", "Dollar", "Cent"}, "EUR": {"ยูโร", "เซนต์", "Euro", "Cent"},
	"GBP": {"ปอนด์", "เพนนี", "Pound", "Penny"}, "CNY": {"หยวน", "เฟิน", "Yuan", "Fen"}, "VND": {"ด่ง", "เฮ่า", "Dong", "Hao"},
}
var thOnes = []string{"", "หนึ่ง", "สอง", "สาม", "สี่", "ห้า", "หก", "เจ็ด", "แปด", "เก้า"}
var thPos = []string{"", "สิบ", "ร้อย", "พัน", "หมื่น", "แสน"}

func moneyWords(amount float64, cur string, thai bool) string {
	c, ok := moneyCur[strings.ToUpper(cur)]
	if !ok {
		c = moneyCur["THB"]
	}
	abs := math.Abs(r2(amount))
	main := int(math.Floor(abs))
	sub := int(roundHalf((abs-float64(main))*100, 0))
	if thai {
		if main == 0 && sub == 0 {
			return "ศูนย์" + c[0] + "ถ้วน"
		}
		out := ""
		if amount < 0 {
			out = "ลบ"
		}
		if main != 0 {
			out += thNumber(main) + c[0]
		}
		if sub != 0 {
			out += thNumber(sub) + c[1]
		}
		return out + "ถ้วน"
	}
	if main == 0 && sub == 0 {
		return "Zero " + c[2] + "s Only"
	}
	t := ""
	if amount < 0 {
		t = "Negative "
	}
	if main != 0 {
		t += enNumber(main) + " " + c[2]
	}
	if main != 0 && sub != 0 {
		t += " and "
	}
	if sub != 0 {
		unit := c[3] + "s"
		if sub == 1 {
			unit = c[3]
		}
		t += enNumber(sub) + " " + unit
	}
	return t + " Only"
}

func thNumber(n int) string {
	if n == 0 {
		return "ศูนย์"
	}
	if n >= 1000000 {
		r := n % 1000000
		s := thNumber(n/1000000) + "ล้าน"
		if r != 0 {
			s += thUnder(r)
		}
		return s
	}
	return thUnder(n)
}

func thUnder(n int) string {
	d := strings.Repeat("0", max(0, 6-len(itoa(n)))) + itoa(n)
	out := ""
	for i := 0; i < 6; i++ {
		digit := int(d[i] - '0')
		pos := 5 - i
		if digit == 0 {
			continue
		}
		switch {
		case pos == 1 && digit == 2:
			out += "ยี่" + thPos[1]
		case pos == 1 && digit == 1:
			out += thPos[1]
		case pos == 0 && digit == 1 && n > 10 && d[4] != '0':
			out += "เอ็ด"
		default:
			out += thOnes[digit] + thPos[pos]
		}
	}
	return out
}

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	s := ""
	for n > 0 {
		s = string(rune('0'+n%10)) + s
		n /= 10
	}
	return s
}

func enNumber(n int) string {
	o := []string{"", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine", "Ten", "Eleven", "Twelve", "Thirteen", "Fourteen", "Fifteen", "Sixteen", "Seventeen", "Eighteen", "Nineteen"}
	t := []string{"", "", "Twenty", "Thirty", "Forty", "Fifty", "Sixty", "Seventy", "Eighty", "Ninety"}
	if n == 0 {
		return "Zero"
	}
	var parts []string
	for _, d := range []struct {
		div  int
		name string
	}{{1000000000, "Billion"}, {1000000, "Million"}, {1000, "Thousand"}} {
		if n >= d.div {
			parts = append(parts, enNumber(n/d.div)+" "+d.name)
			n %= d.div
		}
	}
	if n >= 100 {
		parts = append(parts, o[n/100]+" Hundred")
		n %= 100
	}
	if n >= 20 {
		s := t[n/10]
		if n%10 != 0 {
			s += "-" + o[n%10]
		}
		parts = append(parts, s)
	} else if n > 0 {
		parts = append(parts, o[n])
	}
	return strings.Join(parts, " ")
}
