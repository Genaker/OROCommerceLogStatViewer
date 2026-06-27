# Build stage — CGO disabled for production (no SQLite, smaller binary)
FROM golang:1.21-alpine AS builder

RUN apk --no-cache add ca-certificates

WORKDIR /app
COPY go.mod go.sum ./
RUN go mod download

COPY *.go ./
RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-s -w" -o /oro-log-worker .

# Runtime stage
FROM alpine:3.19

RUN apk --no-cache add ca-certificates tzdata
COPY --from=builder /oro-log-worker /usr/local/bin/oro-log-worker
COPY config.yaml /etc/oro-log-worker/config.yaml

ENTRYPOINT ["oro-log-worker"]
CMD ["-config", "/etc/oro-log-worker/config.yaml"]
