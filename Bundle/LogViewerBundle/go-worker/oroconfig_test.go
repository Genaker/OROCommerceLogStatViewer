package main

import (
	"os"
	"path/filepath"
	"testing"
)

func TestParseEnvFile(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, ".env-app")
	os.WriteFile(path, []byte(`
# comment
ORO_ENV=dev
ORO_DB_URL=postgres://user:pass@localhost:5432/mydb?sslmode=disable
ORO_DB_DSN=${ORO_DB_URL}
OTHER_VAR=hello
`), 0644)

	vars, err := ParseEnvFile(path)
	if err != nil {
		t.Fatalf("ParseEnvFile: %v", err)
	}

	assertEqual(t, "ORO_ENV", vars["ORO_ENV"], "dev")
	assertEqual(t, "ORO_DB_URL", vars["ORO_DB_URL"], "postgres://user:pass@localhost:5432/mydb?sslmode=disable")
	// Variable substitution
	assertEqual(t, "ORO_DB_DSN", vars["ORO_DB_DSN"], "postgres://user:pass@localhost:5432/mydb?sslmode=disable")
	assertEqual(t, "OTHER_VAR", vars["OTHER_VAR"], "hello")
}

func TestParseEnvFileQuotedValues(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, ".env")
	os.WriteFile(path, []byte(`
KEY1="double quoted"
KEY2='single quoted'
KEY3=unquoted
`), 0644)

	vars, err := ParseEnvFile(path)
	if err != nil {
		t.Fatal(err)
	}

	assertEqual(t, "KEY1", vars["KEY1"], "double quoted")
	assertEqual(t, "KEY2", vars["KEY2"], "single quoted")
	assertEqual(t, "KEY3", vars["KEY3"], "unquoted")
}

func TestParseEnvFileMissing(t *testing.T) {
	_, err := ParseEnvFile("/nonexistent/.env-app")
	if err == nil {
		t.Error("expected error for missing file")
	}
}

func TestNormalizeDSN(t *testing.T) {
	tests := []struct {
		in, want string
	}{
		{"postgres://u:p@h:5432/db", "postgresql://u:p@h:5432/db"},
		{"postgresql://u:p@h:5432/db", "postgresql://u:p@h:5432/db"},
		{"postgres://u:p@h:5432/db?sslmode=disable&charset=utf8&serverVersion=13.7",
			"postgresql://u:p@h:5432/db?sslmode=disable"},
		{"postgres://u:p@h:5432/db?charset=utf8", "postgresql://u:p@h:5432/db"},
	}

	for _, tt := range tests {
		got := NormalizeDSN(tt.in)
		if got != tt.want {
			t.Errorf("NormalizeDSN(%q) = %q, want %q", tt.in, got, tt.want)
		}
	}
}

func TestOroConfigDiscoveryFromEnvVar(t *testing.T) {
	os.Setenv("ORO_DB_URL", "postgres://test:test@db:5432/testdb")
	defer os.Unsetenv("ORO_DB_URL")

	d := NewOroConfigDiscovery("/tmp", "")
	dsn, err := d.DiscoverDSN()
	if err != nil {
		t.Fatal(err)
	}
	assertEqual(t, "dsn", dsn, "postgresql://test:test@db:5432/testdb")
}

func TestOroConfigDiscoveryFromFile(t *testing.T) {
	// Clear env vars
	os.Unsetenv("ORO_DB_URL")
	os.Unsetenv("ORO_DB_DSN")

	dir := t.TempDir()
	os.WriteFile(filepath.Join(dir, ".env-app"), []byte(`
ORO_ENV=dev
ORO_DB_URL=postgres://file_user:file_pass@filehost:5432/filedb
`), 0644)

	d := NewOroConfigDiscovery(dir, "")
	dsn, err := d.DiscoverDSN()
	if err != nil {
		t.Fatal(err)
	}
	if dsn != "postgresql://file_user:file_pass@filehost:5432/filedb" {
		t.Errorf("got %q", dsn)
	}
}

func TestOroConfigDiscoveryLocalOverride(t *testing.T) {
	os.Unsetenv("ORO_DB_URL")
	os.Unsetenv("ORO_DB_DSN")

	dir := t.TempDir()
	os.WriteFile(filepath.Join(dir, ".env-app"), []byte(`
ORO_DB_URL=postgres://base:base@host:5432/basedb
`), 0644)
	os.WriteFile(filepath.Join(dir, ".env-app.local"), []byte(`
ORO_DB_URL=postgres://local:local@host:5432/localdb
`), 0644)

	d := NewOroConfigDiscovery(dir, "")
	dsn, err := d.DiscoverDSN()
	if err != nil {
		t.Fatal(err)
	}
	// .env-app.local should take priority over .env-app
	if dsn != "postgresql://local:local@host:5432/localdb" {
		t.Errorf("expected local override, got %q", dsn)
	}
}

func TestOroConfigDiscoveryNoConfig(t *testing.T) {
	os.Unsetenv("ORO_DB_URL")
	os.Unsetenv("ORO_DB_DSN")

	d := NewOroConfigDiscovery("/nonexistent", "")
	_, err := d.DiscoverDSN()
	if err == nil {
		t.Error("expected error when no config found")
	}
}

func TestOroConfigDiscoveryExplicitEnvFile(t *testing.T) {
	os.Unsetenv("ORO_DB_URL")
	os.Unsetenv("ORO_DB_DSN")

	dir := t.TempDir()
	customPath := filepath.Join(dir, "custom.env")
	os.WriteFile(customPath, []byte(`
ORO_DB_URL=postgres://custom:custom@host:5432/customdb
`), 0644)

	d := NewOroConfigDiscovery("/nonexistent", customPath)
	dsn, err := d.DiscoverDSN()
	if err != nil {
		t.Fatal(err)
	}
	if dsn != "postgresql://custom:custom@host:5432/customdb" {
		t.Errorf("got %q", dsn)
	}
}
