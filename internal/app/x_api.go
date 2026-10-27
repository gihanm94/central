package app

import "net/http"

// NeedsConfig is set by the installer (package core): while the system is installed but config/config.json is
// missing and no database login works, it answers the request itself (web → /install, API → 503 JSON) and
// returns true. PHP: App\Core\Support\AutoConfig::ensure() at the start of every request.
var NeedsConfig func(w http.ResponseWriter, r *http.Request) bool
