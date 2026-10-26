package core

import (
	"strings"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
)

func randHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func sha256Hex(s string) string {
	h := sha256.Sum256([]byte(s))
	return hex.EncodeToString(h[:])
}

func toUpper(s string) string { return strings.ToUpper(s) }
