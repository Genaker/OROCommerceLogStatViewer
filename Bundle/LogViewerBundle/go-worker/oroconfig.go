package main

import (
	"bufio"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

// OroConfigDiscovery locates and parses Oro application config files
// to extract the database connection DSN.
//
// Discovery order:
//  1. Explicit db_dsn in worker config
//  2. ORO_DB_URL environment variable
//  3. .env-app.local (uncommitted local overrides)
//  4. .env-app.$ORO_ENV.local
//  5. .env-app.$ORO_ENV
//  6. .env-app (default)
type OroConfigDiscovery struct {
	projectRoot string
	envFile     string // explicit path override
}

func NewOroConfigDiscovery(projectRoot, envFile string) *OroConfigDiscovery {
	return &OroConfigDiscovery{
		projectRoot: projectRoot,
		envFile:     envFile,
	}
}

// DiscoverDSN returns the PostgreSQL DSN by checking all sources in priority order.
func (d *OroConfigDiscovery) DiscoverDSN() (string, error) {
	// 1. Environment variable (highest priority, matches Oro behavior)
	if dsn := os.Getenv("ORO_DB_URL"); dsn != "" {
		return NormalizeDSN(dsn), nil
	}
	if dsn := os.Getenv("ORO_DB_DSN"); dsn != "" {
		return NormalizeDSN(dsn), nil
	}

	// 2. Explicit env file path
	if d.envFile != "" {
		if dsn, err := d.parseFileForDSN(d.envFile); err == nil && dsn != "" {
			return NormalizeDSN(dsn), nil
		}
	}

	// 3. Discovery chain (Symfony/Oro precedence, highest-priority first)
	oroEnv := d.resolveOroEnv()
	candidates := []string{
		".env-app.local",
	}
	if oroEnv != "" {
		candidates = append(candidates,
			fmt.Sprintf(".env-app.%s.local", oroEnv),
			fmt.Sprintf(".env-app.%s", oroEnv),
		)
	}
	candidates = append(candidates, ".env-app")

	for _, name := range candidates {
		path := filepath.Join(d.projectRoot, name)
		if dsn, err := d.parseFileForDSN(path); err == nil && dsn != "" {
			return NormalizeDSN(dsn), nil
		}
	}

	return "", fmt.Errorf("no DB DSN found: set ORO_DB_URL env var, or ensure .env-app exists in %s", d.projectRoot)
}

func (d *OroConfigDiscovery) resolveOroEnv() string {
	if env := os.Getenv("ORO_ENV"); env != "" {
		return env
	}
	// Try to read from .env-app
	path := filepath.Join(d.projectRoot, ".env-app")
	vars, err := ParseEnvFile(path)
	if err != nil {
		return ""
	}
	return vars["ORO_ENV"]
}

func (d *OroConfigDiscovery) parseFileForDSN(path string) (string, error) {
	vars, err := ParseEnvFile(path)
	if err != nil {
		return "", err
	}

	// ORO_DB_URL takes priority, then ORO_DB_DSN
	if dsn, ok := vars["ORO_DB_URL"]; ok && dsn != "" {
		return dsn, nil
	}
	if dsn, ok := vars["ORO_DB_DSN"]; ok && dsn != "" {
		return dsn, nil
	}

	return "", nil
}

// ParseEnvFile reads a .env file and returns key-value pairs.
// Supports ${VAR} variable substitution within the same file.
func ParseEnvFile(path string) (map[string]string, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	vars := make(map[string]string)
	scanner := bufio.NewScanner(f)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		parts := strings.SplitN(line, "=", 2)
		if len(parts) != 2 {
			continue
		}
		k := strings.TrimSpace(parts[0])
		v := strings.TrimSpace(parts[1])
		v = strings.Trim(v, `"'`)

		// Resolve ${VAR} references
		for refKey, refVal := range vars {
			v = strings.ReplaceAll(v, "${"+refKey+"}", refVal)
		}
		vars[k] = v
	}

	return vars, scanner.Err()
}

// NormalizeDSN converts postgres:// to postgresql:// (pgx requirement)
// and strips Oro-specific query params that pgx doesn't understand.
func NormalizeDSN(dsn string) string {
	if strings.HasPrefix(dsn, "postgres://") {
		dsn = "postgresql://" + dsn[len("postgres://"):]
	}
	// Strip charset and serverVersion params (Oro-specific, not valid for pgx)
	for _, param := range []string{"charset=utf8", "serverVersion=", "charset="} {
		if idx := strings.Index(dsn, param); idx > 0 {
			end := strings.IndexByte(dsn[idx:], '&')
			if end < 0 {
				// Last param — remove including preceding & or ?
				if dsn[idx-1] == '&' {
					dsn = dsn[:idx-1]
				} else if dsn[idx-1] == '?' {
					dsn = dsn[:idx-1]
				}
			} else {
				dsn = dsn[:idx] + dsn[idx+end+1:]
			}
		}
	}
	return dsn
}
