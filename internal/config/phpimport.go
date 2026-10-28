package config

import (
	"fmt"
	"strconv"
	"strings"
)

// ImportPHP reads the settings file the PHP version wrote (config/config.json: `return array ( … );`, var_export style)
// and returns it as config values (ordered maps, lists, strings, numbers, booleans, nil), ready for JSON.
// It exists only to move an existing server over: `acme import-config config/config.json`.
func ImportPHP(src string) (any, error) {
	i := strings.Index(src, "return")
	if i < 0 {
		return nil, fmt.Errorf("no \"return\" found: this is not a PHP settings file")
	}
	p := &phpParser{s: src, i: i + len("return")}
	v, err := p.value()
	if err != nil {
		return nil, err
	}
	return v, nil
}

type phpParser struct {
	s string
	i int
}

func (p *phpParser) skip() {
	for p.i < len(p.s) {
		switch {
		case strings.ContainsRune(" \t\r\n", rune(p.s[p.i])):
			p.i++
		case strings.HasPrefix(p.s[p.i:], "//") || p.s[p.i] == '#':
			for p.i < len(p.s) && p.s[p.i] != '\n' {
				p.i++
			}
		case strings.HasPrefix(p.s[p.i:], "/*"):
			j := strings.Index(p.s[p.i:], "*/")
			if j < 0 {
				p.i = len(p.s)
			} else {
				p.i += j + 2
			}
		default:
			return
		}
	}
}

func (p *phpParser) fail(msg string) error {
	line := 1 + strings.Count(p.s[:min(p.i, len(p.s))], "\n")
	return fmt.Errorf("line %d: %s", line, msg)
}

func (p *phpParser) value() (any, error) {
	p.skip()
	if p.i >= len(p.s) {
		return nil, p.fail("unexpected end")
	}
	rest := p.s[p.i:]
	switch {
	case strings.HasPrefix(rest, "array"):
		p.i += len("array")
		p.skip()
		if p.i >= len(p.s) || p.s[p.i] != '(' {
			return nil, p.fail("expected ( after array")
		}
		p.i++
		return p.array(')')
	case rest[0] == '[':
		p.i++
		return p.array(']')
	case rest[0] == '\'' || rest[0] == '"':
		return p.str()
	}
	end := p.i
	for end < len(p.s) && !strings.ContainsRune(",;) ]\t\r\n", rune(p.s[end])) {
		end++
	}
	tok := p.s[p.i:end]
	p.i = end
	switch strings.ToLower(tok) {
	case "true":
		return true, nil
	case "false":
		return false, nil
	case "null":
		return nil, nil
	}
	if n, err := strconv.Atoi(tok); err == nil {
		return n, nil
	}
	if f, err := strconv.ParseFloat(tok, 64); err == nil {
		return f, nil
	}
	return nil, p.fail("cannot read value " + strconv.Quote(tok))
}

func (p *phpParser) str() (any, error) {
	q := p.s[p.i]
	p.i++
	var b strings.Builder
	for p.i < len(p.s) {
		c := p.s[p.i]
		if c == '\\' && p.i+1 < len(p.s) {
			n := p.s[p.i+1]
			if n == q || n == '\\' {
				b.WriteByte(n)
				p.i += 2
				continue
			}
			if q == '"' {
				switch n {
				case 'n':
					b.WriteByte('\n')
				case 't':
					b.WriteByte('\t')
				case 'r':
					b.WriteByte('\r')
				case '$':
					b.WriteByte('$')
				default:
					b.WriteByte('\\')
					b.WriteByte(n)
				}
				p.i += 2
				continue
			}
		}
		if c == q {
			p.i++
			return b.String(), nil
		}
		b.WriteByte(c)
		p.i++
	}
	return nil, p.fail("unterminated string")
}

func (p *phpParser) array(close byte) (any, error) {
	m := NewMap()
	seq := true
	next := 0
	for {
		p.skip()
		if p.i >= len(p.s) {
			return nil, p.fail("unterminated array")
		}
		if p.s[p.i] == close {
			p.i++
			break
		}
		v, err := p.value()
		if err != nil {
			return nil, err
		}
		p.skip()
		key := ""
		if strings.HasPrefix(p.s[p.i:], "=>") {
			p.i += 2
			switch k := v.(type) {
			case string:
				key = k
				seq = false
			case int:
				key = strconv.Itoa(k)
				if k != next {
					seq = false
				}
			default:
				return nil, p.fail("array keys must be text or numbers")
			}
			if v, err = p.value(); err != nil {
				return nil, err
			}
		} else {
			key = strconv.Itoa(next)
		}
		if n, err := strconv.Atoi(key); err == nil && n == next {
			next++
		}
		m.Set(key, v)
		p.skip()
		if p.i < len(p.s) && p.s[p.i] == ',' {
			p.i++
		}
	}
	if seq {
		list := make([]any, 0, m.Len())
		for _, k := range m.Keys {
			list = append(list, m.Vals[k])
		}
		return list, nil
	}
	return m, nil
}
