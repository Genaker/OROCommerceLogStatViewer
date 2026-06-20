.PHONY: build run test bench clean docker

BINARY=oro-log-worker

build:
	CGO_ENABLED=1 go build -ldflags="-s -w" -o $(BINARY) .

run: build
	./$(BINARY) -config config.yaml

test:
	CGO_ENABLED=1 go test -v -count=1 -race ./...

bench:
	CGO_ENABLED=1 go test -bench=. -benchmem ./...

clean:
	rm -f $(BINARY) $(BINARY)-linux

docker:
	docker build -t oro-log-worker .

# Production build (no CGO, no SQLite — smaller binary)
build-prod:
	CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -ldflags="-s -w" -tags nosqlite -o $(BINARY)-linux .
