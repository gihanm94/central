package db

import "testing"

func TestRebind(t *testing.T) {
	cases := map[string]string{
		"SELECT * FROM t WHERE a = ? AND b = ?":          "SELECT * FROM t WHERE a = $1 AND b = $2",
		"SELECT '?' , x FROM t WHERE y = ?":              "SELECT '?' , x FROM t WHERE y = $1",
		"SELECT data ?? 'k' FROM t WHERE z = ?":          "SELECT data ? 'k' FROM t WHERE z = $1",
		"SELECT \"a?b\" FROM t":                          "SELECT \"a?b\" FROM t",
		"INSERT INTO t (a,b) VALUES (?, ?) RETURNING id": "INSERT INTO t (a,b) VALUES ($1, $2) RETURNING id",
	}
	for in, want := range cases {
		if got := Rebind(in); got != want {
			t.Errorf("Rebind(%q) = %q, want %q", in, got, want)
		}
	}
}
