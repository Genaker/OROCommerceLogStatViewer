# Geocoding

A **geocoder** turns free-text addresses (e.g. `"10 Downing St, London"`)
into a `Coordinate` (lat/lng), so they can be fed into a distance provider.
This is only needed when the caller supplies an `"address"` string instead
of `"lat"`/`"lng"` — see [API.md](API.md).

All geocoders implement:

```php
interface GeocoderInterface
{
    public function geocode(string $address): ?Coordinate; // null = not found
    public function getName(): string;
}
```

Selection happens via `GeocoderRegistry::get(?string $name)`: explicit
`$name` (HTTP `geocoder` field) wins, otherwise
`genaker_comi_voyager.geocoder` (System Configuration), defaulting to
`nominatim`. The registry also wraps the resolved geocoder in
`CachingGeocoder` if `genaker_comi_voyager.enable_geocode_cache` is enabled
(default: **on**).

---

## Summary comparison

| | `nominatim` (default) | `google` |
|---|---|---|
| **File** | `Geocoder/NominatimGeocoder.php` | `Geocoder/GoogleGeocoder.php` |
| **Cost** | Free | Paid per request |
| **API key** | None | `genaker_comi_voyager.google_api_key` |
| **Data source** | OpenStreetMap | Google Maps |
| **Rate limit** | ~1 req/sec (Nominatim usage policy) | Google Cloud quota (billed) |
| **Hit rate on messy/partial addresses** | Lower | Generally higher |
| **Failure behavior** | Returns `null` on no result or transport error (logged) | Returns `null` on missing key, no result, or transport error (logged as warning/error) |

---

## `nominatim` — OpenStreetMap Nominatim (default)

Calls `GET https://nominatim.openstreetmap.org/search?q={address}&format=json&limit=1`
with a `User-Agent: ComiVoyager/1.0` header (required by Nominatim's usage
policy) and a 10s timeout.

### Pros
- **Completely free**, no account/API key/billing setup.
- Backed by OpenStreetMap — global coverage, community-maintained.
- Good enough for well-formed addresses ("123 Main St, Springfield, IL").

### Cons
- **Usage policy limits ~1 request/second** for the public instance — bursty
  geocoding of many new addresses at once can get throttled. The geocode
  cache (below) mitigates repeat lookups but not first-time bursts.
- **Lower hit rate on messy input** — abbreviations, typos, or
  non-standard formats are more likely to return no result than with
  Google's geocoder.
- No SLA — the public instance can be slow or briefly unavailable.

### When to use
- Default choice for most use cases — free and globally available.
- When address quality is reasonably good (e.g. validated against a
  shipping-address form) so the lower fuzzy-matching tolerance doesn't
  matter.

---

## `google` — Google Geocoding API

Calls `GET https://maps.googleapis.com/maps/api/geocode/json?address={address}&key={key}`
with a 10s timeout. Requires `genaker_comi_voyager.google_api_key` (shared
with the `google` distance provider, see [DISTANCE_PROVIDERS.md](DISTANCE_PROVIDERS.md)).

If the key is empty, logs a warning and returns `null` immediately (no HTTP
call). If the response `status` isn't `"OK"` or has no
`results[0].geometry.location`, logs a warning and returns `null`.

### Pros
- **Higher hit rate** on partial, messy, or non-English addresses — Google's
  geocoder is generally considered best-in-class for fuzzy matching.
- High rate limits (subject to billing/quota), suitable for bursty bulk
  geocoding.
- Same provider/account as the `google` distance provider — one API key to
  manage for both.

### Cons
- **Costs money per request** (separate from Distance Matrix billing).
- Requires API key setup, quota monitoring, and adherence to Google's terms
  (e.g. restrictions on storing/caching geocoding results long-term — review
  Google's ToS before relying heavily on `CachingGeocoder` with this
  provider).
- An empty/misconfigured key fails *silently* (returns `null`, logged as a
  warning) rather than raising — a request with text addresses will surface
  this as a `GeocodingFailedException` from `RouteOptimizationService`
  ("Could not geocode address...").

### When to use
- When address quality is poor/varied and the higher hit rate justifies the
  cost.
- When already using the `google` distance provider (one less integration to
  manage).

---

## `CachingGeocoder` — DB-backed cache decorator

**File:** `Geocoder/CachingGeocoder.php`

Wraps any `GeocoderInterface`. On `geocode($address)`:

1. Computes `sha256(strtolower(trim($address)))`.
2. Looks up `genaker_comivoyager_geocode_cache` for a row with that hash
   **and** `created_at` within `geocode_cache_ttl_days` (default: 30 days)
   via `GeocodeCacheRepository::findFreshByHash()`.
3. If found: returns the cached `Coordinate` — **no external call**.
4. If not found: delegates to the inner geocoder. On success, persists a new
   `GeocodeCache` row (hash, original text, lat/lng, provider name,
   `created_at`) and flushes immediately.
5. On geocode failure (`null`), nothing is cached — the next request will
   retry the external geocoder.

Controlled by two System Configuration fields:

- `genaker_comi_voyager.enable_geocode_cache` (boolean, default `true`)
- `genaker_comi_voyager.geocode_cache_ttl_days` (integer 1–365, default `30`)

### Pros
- **Eliminates repeat external calls** for the same address text — critical
  for `nominatim`'s ~1 req/s limit and for reducing `google` billing.
- **Hash-based key, normalized** (lowercase + trim) — minor formatting
  differences (`"Main St"` vs `"main st"`) still hit the cache.
- TTL-based expiry allows stale geocodes to be refreshed (e.g. if a road is
  renamed/renumbered) without manual cache invalidation.
- Stores **which provider** produced each cached coordinate (`provider`
  column) — useful for auditing/debugging accuracy issues.

### Cons
- **Exact-hash matching only** — `"123 Main St"` and `"123 Main Street"`
  are different hashes and will both be geocoded/cached separately. No fuzzy
  dedup.
- Persists immediately on every cache miss (`flush()` per address) — for a
  request with many uncached text addresses, this means many small DB
  writes (one per address), not a single batch.
- Cached coordinates are only as accurate as the underlying geocoder at the
  time of the original lookup — a TTL of 30 days means stale data can
  persist for up to a month even if the source data improves sooner.
- If using the `google` geocoder, review Google's terms regarding
  caching/storing geocoding results before relying on long TTLs.

### When to use
- Almost always leave **enabled** (default) — the cost of a cache table is
  negligible compared to repeated external calls.
- Increase `geocode_cache_ttl_days` for static addresses (e.g. warehouse/
  store locations that never move).
- Decrease it (or disable caching) only if testing geocoder behavior directly
  or addresses change frequently and staleness is unacceptable.

---

## Address resolution flow (`RouteOptimizationService`)

For each address in the request:

```
has "lat" and "lng"?
├── Yes → use directly, no geocoding
└── No  → has non-empty "address" text?
          ├── No  → throw InvalidArgumentException (bad request)
          └── Yes → GeocoderRegistry::get($geocoder)->geocode($text)
                     ├── Coordinate → use it
                     └── null       → throw GeocodingFailedException
                                       (HTTP 422 Unprocessable Entity)
```

Mixing `lat`/`lng` and text `"address"` entries in the **same** request is
supported — each address is resolved independently.
