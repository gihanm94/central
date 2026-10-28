package config

import (
	"encoding/json"
	"testing"
)

func TestImportLegacy(t *testing.T) {
	src := `<?php
// Written by the installer
return array (
  'app' => 
  array (
    'name' => 'Acme\'s',
    'hosts' => 
    array (
    ),
    'list' => 
    array (
      0 => 'a',
      1 => 'b',
    ),
  ),
  'db' => 
  array (
    'port' => 5432,
    'ratio' => 1.5,
    'on' => true,
    'none' => NULL,
  ),
);`
	v, err := ImportLegacy(src)
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(v)
	want := `{"app":{"name":"Acme's","hosts":[],"list":["a","b"]},"db":{"port":5432,"ratio":1.5,"on":true,"none":null}}`
	if string(b) != want {
		t.Errorf("got  %s\nwant %s", b, want)
	}
	if _, err := ImportLegacy("<?php echo 1;"); err == nil {
		t.Error("expected an error for a file without return")
	}
}
