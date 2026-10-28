package app

import (
	"fmt"

	"acme/internal/conv"
)

// HTTPError is abort($status, $message) / HttpException.
type HTTPError struct {
	Status  int
	Message string
}

func (e *HTTPError) Error() string {
	if e.Message != "" {
		return e.Message
	}
	return fmt.Sprintf("Error %d", e.Status)
}

// Errors are validation messages by field, in the order they were found (ValidationException::$errors).
type Errors struct {
	Keys []string
	Msgs map[string]string
}

func NewErrors() *Errors { return &Errors{Msgs: map[string]string{}} }

// Add records a message (the first one per field wins, like the PHP validator).
func (e *Errors) Add(field, msg string) *Errors {
	if _, ok := e.Msgs[field]; !ok {
		e.Keys = append(e.Keys, field)
	}
	e.Msgs[field] = msg
	return e
}

func (e *Errors) Has(field string) bool   { _, ok := e.Msgs[field]; return ok }
func (e *Errors) Get(field string) string { return e.Msgs[field] }
func (e *Errors) Len() int {
	if e == nil {
		return 0
	}
	return len(e.Keys)
}

// First is reset($errors).
func (e *Errors) First() string {
	if e.Len() == 0 {
		return ""
	}
	return e.Msgs[e.Keys[0]]
}

// List is the messages in order.
func (e *Errors) List() []string {
	out := make([]string, 0, e.Len())
	for _, k := range e.Keys {
		out = append(out, e.Msgs[k])
	}
	return out
}

func (e *Errors) toSession() []any {
	out := make([]any, 0, len(e.Keys))
	for _, k := range e.Keys {
		out = append(out, []any{k, e.Msgs[k]})
	}
	return out
}

func errorsFrom(v any) *Errors {
	e := NewErrors()
	list, _ := v.([]any)
	for _, p := range list {
		if pair, ok := p.([]any); ok && len(pair) == 2 {
			e.Add(conv.Str(pair[0]), conv.Str(pair[1]))
		}
	}
	return e
}

// ValidationError is ValidationException: the form goes back with the messages and the old input.
type ValidationError struct{ Errors *Errors }

func (v *ValidationError) Error() string { return v.Errors.First() }

// Invalid stops the request with validation errors: app.Invalid("email", "Enter a valid e-mail address.").
func Invalid(pairs ...string) {
	e := NewErrors()
	for i := 0; i+1 < len(pairs); i += 2 {
		e.Add(pairs[i], pairs[i+1])
	}
	panic(&ValidationError{Errors: e})
}

// InvalidErrors stops the request with an Errors set.
func InvalidErrors(e *Errors) { panic(&ValidationError{Errors: e}) }

// Abort stops the request with an HTTP error (abort()).
func Abort(status int, message ...string) {
	m := ""
	if len(message) > 0 {
		m = message[0]
	}
	panic(&HTTPError{Status: status, Message: m})
}

// halt ends a request after its response was written (PHP exit after redirect / json_response).
type halt struct{}

// Catch runs fn and returns what it threw (validation, HTTP or other errors) instead of stopping the request;
// a response that was already written (redirect, JSON …) still stops it. PHP: try { … } catch (\Throwable $e).
func Catch(fn func()) (err error) {
	defer func() {
		if r := recover(); r != nil {
			switch x := r.(type) {
			case halt:
				panic(x)
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
