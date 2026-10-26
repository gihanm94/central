// Command acme runs the company system.
//
//	acme                       serve on :8000 (or -addr)
//	acme migrate               apply new SQL migrations and exit
package main

import (
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/core"
	"acme/internal/db"
	"acme/internal/i18n"
	"acme/internal/php"
	_ "acme/internal/modules"
)

func main() {
	addr := flag.String("addr", envOr("ACME_ADDR", ":8000"), "address to listen on")
	base := flag.String("base", envOr("ACME_BASE", "."), "project folder (config, lang, public, storage, web)")
	flag.Parse()

	abs, err := filepath.Abs(*base)
	if err != nil {
		log.Fatal(err)
	}
	app.BasePath = abs
	boot()

	switch flag.Arg(0) {
	case "migrate":
		db.CLI = true
		ran, _, err := app.RunMigrations(nil, true)
		if err != nil {
			log.Fatal(err)
		}
		for _, r := range ran {
			fmt.Println("ran", r)
		}
		return
	case "", "serve":
	default:
		if job, ok := app.Commands[flag.Arg(0)]; ok {
			db.CLI = true
			os.Exit(job(flag.Args()[1:]))
		}
		log.Fatalf("unknown command %q", flag.Arg(0))
	}

	if err := app.LoadViews(); err != nil {
		log.Fatal(err)
	}
	srv := &http.Server{
		Addr:              *addr,
		Handler:           app.NewServer(core.Routes, core.API, core.Installer()),
		ReadHeaderTimeout: 20 * time.Second,
		IdleTimeout:       120 * time.Second,
	}
	log.Printf("listening on %s (folder %s)", *addr, abs)
	log.Fatal(srv.ListenAndServe())
}

func boot() {
	if err := config.Load(filepath.Join(app.BasePath, "config")); err != nil {
		log.Fatal(err)
	}
	i18n.Load(filepath.Join(app.BasePath, "lang"))
	if loc, err := time.LoadLocation(config.String("app.timezone", "UTC")); err == nil {
		php.Loc = loc
	}
	app.BootModules()
}

func envOr(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
}
