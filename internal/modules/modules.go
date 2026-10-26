// Package modules links every module into the program (each registers itself in init).
package modules

import (
	_ "acme/internal/modules/accounting"
	_ "acme/internal/modules/admin"
	_ "acme/internal/modules/crm"
	_ "acme/internal/modules/machines"
	_ "acme/internal/modules/management"
	_ "acme/internal/modules/sheets"
)
