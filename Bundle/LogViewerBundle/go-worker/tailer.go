package main

import (
	"context"
	"log"
	"os"
	"path/filepath"

	"github.com/nxadm/tail"
)

type Tailer struct {
	path   string
	cfg    *Config
}

func NewTailer(path string, cfg *Config) *Tailer {
	return &Tailer{path: path, cfg: cfg}
}

func (t *Tailer) Run(ctx context.Context, out chan<- *LogEntry) {
	tailCfg := tail.Config{
		Follow:    true,
		ReOpen:    true, // handle log rotation
		MustExist: false,
		Poll:      true, // works inside Docker
		Location: &tail.SeekInfo{
			Offset: 0,
			Whence: os.SEEK_END,
		},
	}

	if !t.cfg.TailFromEnd {
		tailCfg.Location = nil // read from beginning
	}

	tf, err := tail.TailFile(t.path, tailCfg)
	if err != nil {
		log.Printf("[tailer] failed to tail %s: %v", t.path, err)
		return
	}

	log.Printf("[tailer] watching %s", t.path)

	for {
		select {
		case <-ctx.Done():
			tf.Stop()
			tf.Cleanup()
			return
		case line, ok := <-tf.Lines:
			if !ok {
				return
			}
			if line.Err != nil {
				log.Printf("[tailer] %s read error: %v", t.path, line.Err)
				continue
			}

			entry := ParseLine(line.Text)
			if entry == nil {
				continue
			}

			if !IsLevelAtOrAbove(entry.LevelName, t.cfg.MinLevel) {
				continue
			}

			select {
			case out <- entry:
			case <-ctx.Done():
				return
			}
		}
	}
}

func resolveGlob(pattern string) ([]string, error) {
	matches, err := filepath.Glob(pattern)
	if err != nil {
		return nil, err
	}
	if len(matches) == 0 {
		// No match yet — return the literal path so the tailer can wait for it
		return []string{pattern}, nil
	}
	return matches, nil
}
