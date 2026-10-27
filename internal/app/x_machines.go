package app

// JSONRaw sends an already encoded JSON body (json_response when the encoding must be PHP's own,
// e.g. "\/" for slashes: Ctx.JSON uses Go's encoder).
func (c *Ctx) JSONRaw(body string, status ...int) {
	if len(status) > 0 {
		c.status = status[0]
	}
	c.write("application/json", []byte(body))
}
