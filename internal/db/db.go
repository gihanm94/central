// Package db is the thin database layer, the Go side of App\Core\Support\DB.
//
//	db.Select("SELECT … WHERE id = ?", id)            → core (user_db)
//	db.On("crm").Select("SELECT …")                    → crm_db
//
// Placeholders are "?" like the PHP code. Rows are maps typed the way PDO gave them to PHP:
// integer columns → int, booleans → bool, float4/float8 → float64, everything else (numeric, dates,
// timestamps, json, text) → string, NULL → nil.
//
// A failing query panics with *Error (the PHP code threw a PDOException); the web layer turns it into the
// "Something went wrong" page. Use Try when a failure must be caught.
package db

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"

	"acme/internal/config"
	"acme/internal/php"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgxpool"
)

// Row is one result row.
type Row map[string]any

// Str is (string) $row[$k].
func (r Row) Str(k string) string { return php.Str(r[k]) }

// Int is (int) $row[$k].
func (r Row) Int(k string) int { return php.Int(r[k]) }

// Float is (float) $row[$k].
func (r Row) Float(k string) float64 { return php.Float(r[k]) }

// Bool is filter_var($row[$k], FILTER_VALIDATE_BOOL).
func (r Row) Bool(k string) bool { return php.Bool(r[k]) }

// Has is array_key_exists.
func (r Row) Has(k string) bool { _, ok := r[k]; return ok }

// Nil is $row[$k] === null.
func (r Row) Nil(k string) bool { return r[k] == nil }

// Copy is a shallow copy.
func (r Row) Copy() Row {
	out := make(Row, len(r))
	for k, v := range r {
		out[k] = v
	}
	return out
}

// Error is a failed query.
type Error struct {
	Err  error
	SQL  string
	Conn string
}

func (e *Error) Error() string { return e.Err.Error() }
func (e *Error) Unwrap() error { return e.Err }

// Code is the SQLSTATE of a database error ("" when there is none).
func (e *Error) Code() string {
	var pe *pgconn.PgError
	if errors.As(e.Err, &pe) {
		return pe.Code
	}
	return ""
}

var (
	mu     sync.Mutex
	pools  = map[string]*pgxpool.Pool{}
	failed = map[string]error{}
	// CLI is true for command-line jobs: no statement timeout, longer connect timeout.
	CLI bool
)

// Q runs queries on one database (a pool) or inside one transaction.
type Q struct {
	name string
	tx   pgx.Tx
}

// On picks a database by its connection name (core, crm, accounting …).
func On(name string) Q { return Q{name: name} }

// Core is user_db.
var Core = On("core")

func (q Q) Name() string { return q.name }

// DSN builds the connection string of a connection name from config db.*.
func DSN(name string) string {
	dbname := config.String("db.databases."+name, name)
	user := config.String("db.credentials."+name+".username", config.String("db.username", ""))
	pass := config.String("db.credentials."+name+".password", config.String("db.password", ""))
	return DSNFor(config.String("db.host", "127.0.0.1"), config.Int("db.port", 5432), dbname, user, pass)
}

// DSNFor is a connection string for explicit details (installer).
func DSNFor(host string, port int, dbname, user, pass string) string {
	timeout := 3
	if CLI {
		timeout = 15
	}
	u := url.URL{Scheme: "postgres", User: url.UserPassword(user, pass), Host: host + ":" + strconv.Itoa(port), Path: "/" + dbname}
	v := url.Values{}
	v.Set("connect_timeout", strconv.Itoa(timeout))
	v.Set("sslmode", "prefer")
	u.RawQuery = v.Encode()
	return u.String()
}

var tzOK = regexp.MustCompile(`^[A-Za-z0-9_+\-/]+$`)

// StatementTimeoutMS is the web request limit (db.statement_timeout_seconds, default 60); 0 for CLI jobs.
func StatementTimeoutMS() int {
	if CLI {
		return 0
	}
	s := config.Int("db.statement_timeout_seconds", 60)
	if s < 0 {
		s = 0
	}
	return s * 1000
}

func pool(name string) *pgxpool.Pool {
	mu.Lock()
	defer mu.Unlock()
	if p, ok := pools[name]; ok {
		return p
	}
	if err, ok := failed[name]; ok {
		panic(&Error{Err: err, Conn: name})
	}
	cfg, err := pgxpool.ParseConfig(DSN(name))
	if err != nil {
		failed[name] = err
		panic(&Error{Err: err, Conn: name})
	}
	cfg.ConnConfig.DefaultQueryExecMode = pgx.QueryExecModeSimpleProtocol
	if tz := config.String("app.timezone", ""); tzOK.MatchString(tz) {
		cfg.ConnConfig.RuntimeParams["timezone"] = tz
	}
	cfg.ConnConfig.RuntimeParams["statement_timeout"] = strconv.Itoa(StatementTimeoutMS())
	cfg.MaxConns = int32(config.Int("db.pool_size", 10))
	cfg.MaxConnIdleTime = 5 * time.Minute
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	p, err := pgxpool.NewWithConfig(ctx, cfg)
	if err == nil {
		err = p.Ping(ctx)
	}
	if err != nil {
		if p != nil {
			p.Close()
		}
		// an unreachable database is not retried for 30 seconds, so pages do not each wait for it
		failed[name] = err
		go func() { time.Sleep(30 * time.Second); mu.Lock(); delete(failed, name); mu.Unlock() }()
		panic(&Error{Err: err, Conn: name})
	}
	pools[name] = p
	return p
}

// Close closes every pool (tests, installer).
func Close() {
	mu.Lock()
	defer mu.Unlock()
	for k, p := range pools {
		p.Close()
		delete(pools, k)
	}
}

// Reset forgets a connection (after the config changed).
func Reset(name string) {
	mu.Lock()
	defer mu.Unlock()
	if p, ok := pools[name]; ok {
		p.Close()
		delete(pools, name)
	}
	delete(failed, name)
}

// Rebind turns "?" into $1, $2 … (outside quotes; "??" is a literal "?").
func Rebind(sql string) string {
	if !strings.Contains(sql, "?") {
		return sql
	}
	var b strings.Builder
	n := 0
	var quote byte
	for i := 0; i < len(sql); i++ {
		ch := sql[i]
		if quote != 0 {
			b.WriteByte(ch)
			if ch == quote {
				quote = 0
			}
			continue
		}
		switch ch {
		case '\'', '"':
			quote = ch
			b.WriteByte(ch)
		case '?':
			if i+1 < len(sql) && sql[i+1] == '?' {
				b.WriteByte('?')
				i++
				continue
			}
			n++
			b.WriteString("$" + strconv.Itoa(n))
		default:
			b.WriteByte(ch)
		}
	}
	return b.String()
}

// Arg normalises a parameter: maps and slices become JSON (like the PHP layer), "" stays "".
func Arg(v any) any {
	switch x := v.(type) {
	case nil, string, bool, int, int32, int64, float64, float32, time.Time, []byte:
		return x
	case *string:
		if x == nil {
			return nil
		}
		return *x
	case *int:
		if x == nil {
			return nil
		}
		return *x
	case json.RawMessage:
		return string(x)
	case fmt.Stringer:
		return x.String()
	default:
		b, err := json.Marshal(x)
		if err != nil {
			return fmt.Sprint(x)
		}
		return string(b)
	}
}

func args(in []any) []any {
	out := make([]any, len(in))
	for i, a := range in {
		out[i] = Arg(a)
	}
	return out
}

func ctx() context.Context { return context.Background() }

func (q Q) query(sql string, params []any) (pgx.Rows, error) {
	if q.tx != nil {
		return q.tx.Query(ctx(), Rebind(sql), args(params)...)
	}
	return pool(q.name).Query(ctx(), Rebind(sql), args(params)...)
}

func (q Q) fail(err error, sql string) { panic(&Error{Err: err, SQL: sql, Conn: q.name}) }

// Select returns every row.
func (q Q) Select(sql string, params ...any) []Row {
	rows, err := q.query(sql, params)
	if err != nil {
		q.fail(err, sql)
	}
	defer rows.Close()
	out := []Row{}
	fields := rows.FieldDescriptions()
	for rows.Next() {
		raw := rows.RawValues()
		r := make(Row, len(fields))
		for i, f := range fields {
			r[f.Name] = decode(f.DataTypeOID, raw[i])
		}
		out = append(out, r)
	}
	if err := rows.Err(); err != nil {
		q.fail(err, sql)
	}
	return out
}

// First returns the first row or nil.
func (q Q) First(sql string, params ...any) Row {
	rows := q.Select(sql, params...)
	if len(rows) == 0 {
		return nil
	}
	return rows[0]
}

// Scalar returns the first column of the first row, or nil.
func (q Q) Scalar(sql string, params ...any) any {
	rows, err := q.query(sql, params)
	if err != nil {
		q.fail(err, sql)
	}
	defer rows.Close()
	var v any
	if rows.Next() {
		raw := rows.RawValues()
		if len(raw) > 0 {
			v = decode(rows.FieldDescriptions()[0].DataTypeOID, raw[0])
		}
	}
	if err := rows.Err(); err != nil {
		q.fail(err, sql)
	}
	return v
}

// Column returns the first column of every row.
func (q Q) Column(sql string, params ...any) []any {
	out := []any{}
	for _, r := range q.SelectOrdered(sql, params...) {
		if len(r.Vals) > 0 {
			out = append(out, r.Vals[0])
		}
	}
	return out
}

// OrderedRow keeps the column order (exports).
type OrderedRow struct {
	Cols []string
	Vals []any
}

// SelectOrdered is Select keeping the column order.
func (q Q) SelectOrdered(sql string, params ...any) []OrderedRow {
	rows, err := q.query(sql, params)
	if err != nil {
		q.fail(err, sql)
	}
	defer rows.Close()
	fields := rows.FieldDescriptions()
	cols := make([]string, len(fields))
	for i, f := range fields {
		cols[i] = f.Name
	}
	out := []OrderedRow{}
	for rows.Next() {
		raw := rows.RawValues()
		vals := make([]any, len(fields))
		for i, f := range fields {
			vals[i] = decode(f.DataTypeOID, raw[i])
		}
		out = append(out, OrderedRow{Cols: cols, Vals: vals})
	}
	if err := rows.Err(); err != nil {
		q.fail(err, sql)
	}
	return out
}

// Exec runs a statement and returns the number of rows it touched.
func (q Q) Exec(sql string, params ...any) int {
	var tag pgconn.CommandTag
	var err error
	if q.tx != nil {
		tag, err = q.tx.Exec(ctx(), Rebind(sql), args(params)...)
	} else {
		tag, err = pool(q.name).Exec(ctx(), Rebind(sql), args(params)...)
	}
	if err != nil {
		q.fail(err, sql)
	}
	return int(tag.RowsAffected())
}

// ExecScript runs several statements at once (migrations). No parameters.
func (q Q) ExecScript(sql string) {
	var err error
	if q.tx != nil {
		_, err = q.tx.Conn().PgConn().Exec(ctx(), sql).ReadAll()
	} else {
		c, aerr := pool(q.name).Acquire(ctx())
		if aerr != nil {
			q.fail(aerr, sql)
		}
		defer c.Release()
		_, err = c.Conn().PgConn().Exec(ctx(), sql).ReadAll()
	}
	if err != nil {
		q.fail(err, sql)
	}
}

// Cols is an insert/update column list that keeps its order: db.Cols{{"name", n}, {"email", e}}.
type Cols []Col

type Col struct {
	Name  string
	Value any
}

// FromMap orders a map's columns by name (stable SQL).
func FromMap(m map[string]any) Cols {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sortStrings(keys)
	out := make(Cols, 0, len(keys))
	for _, k := range keys {
		out = append(out, Col{k, m[k]})
	}
	return out
}

// Insert adds a row and returns its id (returning "" gives nil).
func (q Q) Insert(table string, data Cols, returning ...string) any {
	names := make([]string, len(data))
	marks := make([]string, len(data))
	vals := make([]any, len(data))
	for i, c := range data {
		names[i] = c.Name
		marks[i] = "?"
		vals[i] = c.Value
	}
	ret := "id"
	if len(returning) > 0 {
		ret = returning[0]
	}
	sql := "INSERT INTO " + table + " (" + strings.Join(names, ", ") + ") VALUES (" + strings.Join(marks, ", ") + ")"
	if ret == "" {
		q.Exec(sql, vals...)
		return nil
	}
	return q.Scalar(sql+" RETURNING "+ret, vals...)
}

// InsertID is Insert returning the id as an int.
func (q Q) InsertID(table string, data Cols) int { return php.Int(q.Insert(table, data)) }

// Update sets columns where every where-column matches.
func (q Q) Update(table string, data Cols, where Cols) int {
	sets := make([]string, len(data))
	vals := []any{}
	for i, c := range data {
		sets[i] = c.Name + " = ?"
		vals = append(vals, c.Value)
	}
	ws := make([]string, len(where))
	for i, c := range where {
		ws[i] = c.Name + " = ?"
		vals = append(vals, c.Value)
	}
	return q.Exec("UPDATE "+table+" SET "+strings.Join(sets, ", ")+" WHERE "+strings.Join(ws, " AND "), vals...)
}

// Transaction runs fn inside one transaction; fn must use the Q it receives. A panic rolls back and goes on.
func (q Q) Transaction(fn func(tx Q)) {
	if q.tx != nil {
		fn(q)
		return
	}
	tx, err := pool(q.name).Begin(ctx())
	if err != nil {
		q.fail(err, "BEGIN")
	}
	done := false
	defer func() {
		if !done {
			_ = tx.Rollback(ctx())
		}
	}()
	fn(Q{name: q.name, tx: tx})
	if err := tx.Commit(ctx()); err != nil {
		q.fail(err, "COMMIT")
	}
	done = true
}

// Package-level shortcuts on core.
func Select(sql string, params ...any) []Row { return Core.Select(sql, params...) }
func First(sql string, params ...any) Row    { return Core.First(sql, params...) }
func Scalar(sql string, params ...any) any   { return Core.Scalar(sql, params...) }
func Exec(sql string, params ...any) int     { return Core.Exec(sql, params...) }
func Insert(table string, data Cols, returning ...string) any {
	return Core.Insert(table, data, returning...)
}
func Update(table string, data Cols, where Cols) int { return Core.Update(table, data, where) }

// Try runs fn and returns a database (or any) panic as an error.
func Try(fn func()) (err error) {
	defer func() {
		if r := recover(); r != nil {
			switch x := r.(type) {
			case error:
				err = x
			default:
				err = fmt.Errorf("%v", x)
			}
		}
	}()
	fn()
	return nil
}

// Ping checks a connection can be opened.
func Ping(dsn string) error {
	c, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	conn, err := pgx.Connect(c, dsn)
	if err != nil {
		return err
	}
	defer conn.Close(c)
	return conn.Ping(c)
}

const (
	oidBool   = 16
	oidInt8   = 20
	oidInt2   = 21
	oidInt4   = 23
	oidOID    = 26
	oidFloat4 = 700
	oidFloat8 = 701
)

func decode(oid uint32, raw []byte) any {
	if raw == nil {
		return nil
	}
	s := string(raw)
	switch oid {
	case oidBool:
		return s == "t" || s == "true"
	case oidInt2, oidInt4, oidInt8, oidOID:
		if i, err := strconv.ParseInt(s, 10, 64); err == nil {
			return int(i)
		}
	case oidFloat4, oidFloat8:
		if f, err := strconv.ParseFloat(s, 64); err == nil {
			return f
		}
	}
	return s
}

func sortStrings(s []string) {
	for i := 1; i < len(s); i++ {
		for j := i; j > 0 && s[j] < s[j-1]; j-- {
			s[j], s[j-1] = s[j-1], s[j]
		}
	}
}
