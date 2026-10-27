package core

import (
	"acme/internal/api"

	"github.com/go-chi/chi/v5"
)

// API registers /api/v1 (ported from App\Core\Api; see package api).
func API(r chi.Router) { api.Mount(r) }
