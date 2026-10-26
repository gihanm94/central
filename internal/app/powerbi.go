package app

import (
	"html"
	"net/url"
	"regexp"
	"strings"
)

// The Power BI report shown on Management → Power BI → Overview (App\Core\Support\PowerBi). Set by an administrator
// under Company settings → Power BI; only the https link to a Power BI host is stored, never pasted HTML.

const PowerBiKey = "powerbi_overview_url"

func PowerBiURL() string { return Setting(PowerBiKey, "") }

var (
	iframeSrc = regexp.MustCompile(`(?is)<iframe\b[^>]*\bsrc\s*=\s*(["'])(.*?)["']`)
	pbiHost   = regexp.MustCompile(`(^|\.)(powerbi\.com|powerbigov\.us|powerbi\.cn|analysis\.windows\.net)$`)
	pbiBad    = regexp.MustCompile(`[\s"'<>]`)
)

// PowerBiLink reads the report link from pasted embed code (<iframe src="…">) or a plain link; "" clears it.
func PowerBiLink(c *Ctx, input string) string {
	input = strings.TrimSpace(input)
	if input == "" {
		return ""
	}
	if m := iframeSrc.FindStringSubmatch(input); m != nil {
		input = m[2]
	} else if strings.Contains(input, "<") {
		Invalid("embed", c.T("Paste the Power BI embed code (<iframe …>) or the report link."))
	}
	u := strings.TrimSpace(html.UnescapeString(input))
	p, err := url.Parse(u)
	if err != nil || p.Scheme != "https" || !pbiHost.MatchString(strings.ToLower(p.Hostname())) || pbiBad.MatchString(u) {
		Invalid("embed", c.T("Use a Power BI report link (https://app.powerbi.com/…)."))
	}
	return u
}
