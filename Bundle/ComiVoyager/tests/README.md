# ComiVoyager Tests

| Suite | Location | What it covers |
|---|---|---|
| Unit | [`../Core/Tests/Unit/`](../Core/Tests/Unit/) | Pure-PHP `Core/` engine (models, distance providers, solvers) |
| Unit | [`../Tests/Unit/`](../Tests/Unit/) | Symfony/Oro-aware code (registries, geocoders, command) |
| E2E | [`e2e/`](e2e/) | `POST /comivoyager/optimize` HTTP API, against a running app |

## Running the e2e suite

The e2e suite is a **standalone** Playwright project — it has its own
`package.json`/`playwright.config.js` and doesn't depend on the repo-root
`playwright.config.js` or `package.json`.

### Prerequisites

- The app is running and reachable (default `http://localhost:8000`, see
  [`../doc/INSTALLATION.md`](../doc/INSTALLATION.md)).
- A storefront customer test account exists. By default the suite reads
  credentials from the repo root's `.env-app.local`
  (`ORO_TEST_FRONTEND_EMAIL` / `ORO_TEST_FRONTEND_PASSWORD`), the same
  variables used by `tests/e2e/test_login.py`. You can override any of these
  via real environment variables:
  - `ORO_TEST_HTTP_SCHEME` / `ORO_TEST_HTTP_HOST` / `ORO_TEST_HTTP_PORT`
  - `ORO_TEST_FRONTEND_EMAIL` / `ORO_TEST_FRONTEND_PASSWORD`
- The `genaker_comivoyager_optimize` ACL must be granted to that customer's
  role (frontend, group `commerce`).

### Run it

```bash
cd src/Genaker/Bundle/ComiVoyager/tests/e2e
npm install
npx playwright test
```

To target a different host/port:

```bash
ORO_TEST_HTTP_HOST=oro.local ORO_TEST_HTTP_PORT=8080 npx playwright test
```

### What it checks

[`e2e/comivoyager-routing.spec.js`](e2e/comivoyager-routing.spec.js) is the
HTTP-API counterpart of
[`../Core/Tests/Unit/RealWorldRoutingTest.php`](../Core/Tests/Unit/RealWorldRoutingTest.php):
it logs in as the storefront test customer, posts real US-city coordinates to
`POST /comivoyager/optimize`, and compares the returned route's
`totalDistanceKm` against an independent brute-force search (own
permutation + haversine implementation in the test, sharing no code with
`Core/`) over every possible visiting order — for both a closed loop
(`returnToStart: true`, fixed depot) and an open path (free start).

A regression that makes the solver return a non-optimal route for a
small (`n <= 10`, exact) input will fail this test even though the oracle and
the production code share nothing but the haversine formula itself.
