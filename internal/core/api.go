package core

import (
	"net/http"

	"github.com/go-chi/chi/v5"
)

// API registers /api/v1 (ported from App\Core\Api).
func API(r chi.Router) {}

// Installer is the first-run installer (/install); nil until it is ported.
func Installer() http.Handler { return nil }
