package main

import (
	"fmt"
	"os"
	"path/filepath"

	"gopkg.in/yaml.v3"
)

type Config struct {
	DBDSN            string   `yaml:"db_dsn"`
	OroEnvFile       string   `yaml:"oro_env_file"`
	OroProjectRoot   string   `yaml:"oro_project_root"`
	LogFiles         []string `yaml:"log_files"`
	MinLevel         string   `yaml:"min_level"`
	BatchSize        int      `yaml:"batch_size"`
	FlushIntervalMs  int      `yaml:"flush_interval_ms"`
	GroupingEnabled  bool     `yaml:"grouping_enabled"`
	GroupingKeyLen   int      `yaml:"grouping_key_length"`
	TailFromEnd      bool     `yaml:"tail_from_end"`
	Workers          int      `yaml:"workers"`
}

func LoadConfig(path string) (*Config, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("read config: %w", err)
	}

	cfg := &Config{
		MinLevel:        "WARNING",
		BatchSize:       100,
		FlushIntervalMs: 2000,
		GroupingEnabled: true,
		GroupingKeyLen:  30,
		TailFromEnd:     true,
		Workers:         2,
	}

	if err := yaml.Unmarshal(data, cfg); err != nil {
		return nil, fmt.Errorf("parse config: %w", err)
	}

	if cfg.BatchSize < 1 {
		cfg.BatchSize = 1
	}
	if cfg.Workers < 1 {
		cfg.Workers = 1
	}
	if cfg.GroupingKeyLen < 10 {
		cfg.GroupingKeyLen = 10
	}

	// Auto-detect project root from config file location
	if cfg.OroProjectRoot == "" {
		// Default: 5 levels up from go-worker config
		// go-worker/ -> LogViewerBundle/ -> Bundle/ -> Genaker/ -> src/ -> project_root/
		absPath, _ := filepath.Abs(path)
		cfg.OroProjectRoot = filepath.Dir(filepath.Dir(filepath.Dir(filepath.Dir(filepath.Dir(filepath.Dir(absPath))))))
	}

	return cfg, nil
}

func (c *Config) ResolveDSN() (string, error) {
	// Explicit DSN in config takes top priority
	if c.DBDSN != "" {
		return NormalizeDSN(c.DBDSN), nil
	}

	discovery := NewOroConfigDiscovery(c.OroProjectRoot, c.OroEnvFile)
	return discovery.DiscoverDSN()
}
