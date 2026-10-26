// Package core holds the core pages (routes/web.php and App\Core\Http\Controllers in the PHP version).
package core

import (
	"acme/internal/app"
)

// Routes registers the core pages in the order of routes/web.php.
func Routes(r app.Router) {
	// ---- Anyone: language switcher ----
	r.Get("/lang/{code}", language)

	// ---- Guests: sign in only (no sign-up) ----
	r.Group([]app.Middleware{app.Guest}, func(r app.Router) {
		r.Get("/login", showLogin)
		r.Post("/login", login)
		r.Get("/forgot-password", showForgot)
		r.Post("/forgot-password", sendReset)
		r.Get("/reset-password", showReset)
		r.Post("/reset-password", reset)
		for _, g := range guestRoutes {
			g(r)
		}
	})

	// ---- Signed in ----
	r.Group([]app.Middleware{app.Auth}, func(r app.Router) {
		r.Post("/logout", logout)
		for _, a := range authRoutes {
			a(r)
		}
	})
}

// guestRoutes / authRoutes: each core controller file adds its routes here (init), so they can be ported one by one.
var (
	guestRoutes []func(r app.Router)
	authRoutes  []func(r app.Router)
)

func guest(fn func(r app.Router)) { guestRoutes = append(guestRoutes, fn) }
func signedIn(fn func(r app.Router)) { authRoutes = append(authRoutes, fn) }

func init() {
	signedIn(func(r app.Router) { r.Post("/table-prefs", app.SaveTablePrefs) })
}
