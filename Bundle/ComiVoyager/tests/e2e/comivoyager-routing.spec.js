// @ts-check

/**
 * E2E test for `POST /comivoyager/optimize` (GenakerComiVoyagerBundle).
 *
 * This is the API-level counterpart of
 * `Core/Tests/Unit/RealWorldRoutingTest.php`: the same real-world US city
 * coordinates and the same independent brute-force "what is the *true*
 * shortest route?" oracle, but driven through the authenticated storefront
 * HTTP API instead of calling `ComiVoyager` directly in PHP. It exercises
 * the full stack — routing, ACL, controller, `RouteOptimizationService`,
 * and the solver — end to end.
 *
 * See ../README.md for setup and how to run this independently.
 */

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

/**
 * Parse a KEY=VALUE file and set any variables not already present in
 * `process.env`. Mirrors `tests/e2e/conftest.py`'s `_load_env_file()` at the
 * repo root.
 */
function loadEnvFile(filePath) {
    if (!fs.existsSync(filePath)) {
        return;
    }

    for (const line of fs.readFileSync(filePath, 'utf8').split('\n')) {
        const trimmed = line.trim();

        if (!trimmed || trimmed.startsWith('#') || !trimmed.includes('=')) {
            continue;
        }

        const separator = trimmed.indexOf('=');
        const key = trimmed.slice(0, separator).trim();
        const value = trimmed.slice(separator + 1).trim();

        if (!(key in process.env)) {
            process.env[key] = value;
        }
    }
}

// Repo root is six levels up from src/Genaker/Bundle/ComiVoyager/tests/e2e.
const REPO_ROOT = path.resolve(__dirname, '..', '..', '..', '..', '..', '..');
loadEnvFile(path.join(REPO_ROOT, '.env-app.local'));
loadEnvFile(path.join(REPO_ROOT, '.env-app'));

const SCHEME = process.env.ORO_TEST_HTTP_SCHEME || 'http';
const HOST = process.env.ORO_TEST_HTTP_HOST || 'localhost';
const PORT = process.env.ORO_TEST_HTTP_PORT || '8000';
const BASE_URL = `${SCHEME}://${HOST}:${PORT}`;

const EMAIL = process.env.ORO_TEST_FRONTEND_EMAIL || 'cart_integration_test@example.com';
const PASSWORD = process.env.ORO_TEST_FRONTEND_PASSWORD || 'Cart_Test_Pw1!';

/**
 * Authenticates `request`'s cookie jar as the storefront test customer, the
 * same way `tests/e2e/test_login.py` does: load the login page to get a
 * session cookie + CSRF token, then POST credentials directly to
 * `/customer/user/login-check`.
 */
async function loginAsCustomer(request) {
    const loginPage = await request.get(`${BASE_URL}/customer/user/login`);
    const html = await loginPage.text();

    const inputMatch = html.match(/<input[^>]*name="_csrf_token"[^>]*>/);
    const valueMatch = inputMatch && inputMatch[0].match(/value="([^"]*)"/);

    if (!valueMatch) {
        throw new Error('Could not find _csrf_token on the login page.');
    }

    await request.post(`${BASE_URL}/customer/user/login-check`, {
        form: {
            _username: EMAIL,
            _password: PASSWORD,
            _csrf_token: valueMatch[1],
            _target_path: '',
            _failure_path: '',
        },
    });
}

/**
 * Great-circle distance in km — same formula (and Earth radius) as
 * {@see \Genaker\Bundle\ComiVoyager\Core\Distance\HaversineDistanceMatrixProvider},
 * reimplemented independently here so the oracle below doesn't share any
 * code with the production solver.
 */
const EARTH_RADIUS_KM = 6371.0;

function haversineKm(a, b) {
    const lat1 = (a.lat * Math.PI) / 180;
    const lat2 = (b.lat * Math.PI) / 180;
    const dLat = ((b.lat - a.lat) * Math.PI) / 180;
    const dLng = ((b.lng - a.lng) * Math.PI) / 180;

    const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;

    return EARTH_RADIUS_KM * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
}

function buildMatrix(cities) {
    return cities.map((from, i) => cities.map((to, j) => (i === j ? 0 : haversineKm(from, to))));
}

/** Every ordering of `items`, generated lazily. */
function* permutations(items) {
    if (items.length <= 1) {
        yield items;
        return;
    }

    for (let i = 0; i < items.length; i++) {
        const rest = [...items.slice(0, i), ...items.slice(i + 1)];

        for (const permutation of permutations(rest)) {
            yield [items[i], ...permutation];
        }
    }
}

/** Length of the closed loop tour[0] -> tour[1] -> ... -> tour[n-1] -> tour[0]. */
function closedTourDistance(matrix, tour) {
    let distance = 0;

    for (let i = 0; i < tour.length; i++) {
        distance += matrix[tour[i]][tour[(i + 1) % tour.length]];
    }

    return distance;
}

/** Length of the open path tour[0] -> tour[1] -> ... -> tour[n-1]. */
function openTourDistance(matrix, tour) {
    let distance = 0;

    for (let i = 0; i < tour.length - 1; i++) {
        distance += matrix[tour[i]][tour[i + 1]];
    }

    return distance;
}

/** @returns {number} the true shortest distance over every ordering of `items`. */
function bruteForceMin(matrix, items, distanceFn) {
    let best = Infinity;

    for (const tour of permutations(items)) {
        const distance = distanceFn(matrix, tour);

        if (distance < best) {
            best = distance;
        }
    }

    return best;
}

const US_CITIES = [
    { label: 'New York, NY', lat: 40.7128, lng: -74.0060 },
    { label: 'Los Angeles, CA', lat: 34.0522, lng: -118.2437 },
    { label: 'Chicago, IL', lat: 41.8781, lng: -87.6298 },
    { label: 'Houston, TX', lat: 29.7604, lng: -95.3698 },
    { label: 'Phoenix, AZ', lat: 33.4484, lng: -112.0740 },
    { label: 'Philadelphia, PA', lat: 39.9526, lng: -75.1652 },
    { label: 'San Antonio, TX', lat: 29.4241, lng: -98.4936 },
    { label: 'San Diego, CA', lat: 32.7157, lng: -117.1611 },
    { label: 'Dallas, TX', lat: 32.7767, lng: -96.7970 },
];

// 1 meter — generous enough to absorb cross-language floating point
// differences while still catching a genuinely non-optimal route, whose
// distance differs by kilometers.
const DISTANCE_TOLERANCE_KM = 0.001;

test.describe('POST /comivoyager/optimize — real-world routing', () => {
    /**
     * Closed-loop scenario: a delivery truck leaves the New York depot,
     * visits the other 8 cities, and returns. Brute-forces all 8! = 40,320
     * orderings of the non-depot cities to find the true shortest loop, and
     * checks the API returns exactly that.
     */
    test('finds the true shortest closed loop for real cities', async ({ request, browserName }) => {
        test.skip(browserName !== 'chromium', 'pure API test, no need to run per-browser');

        const matrix = buildMatrix(US_CITIES);
        const rest = US_CITIES.map((_, i) => i).slice(1);
        const expected = bruteForceMin(matrix, rest, (m, perm) => closedTourDistance(m, [0, ...perm]));

        await loginAsCustomer(request);

        const response = await request.post(`${BASE_URL}/comivoyager/optimize`, {
            data: {
                addresses: US_CITIES,
                method: 'haversine',
                routes: 1,
                returnToStart: true,
                depotIndex: 0,
            },
        });

        expect(response.ok()).toBeTruthy();
        const body = await response.json();
        const best = body.routes[0];

        expect(Math.abs(best.totalDistanceKm - expected)).toBeLessThan(DISTANCE_TOLERANCE_KM);
        expect(best.stops).toHaveLength(US_CITIES.length + 1);
        expect(best.stops[0].addressLabel).toBe('New York, NY');
        expect(best.stops[0].isStart).toBe(true);
        expect(best.stops[US_CITIES.length].addressLabel).toBe('New York, NY');
        expect(best.stops[US_CITIES.length].isEnd).toBe(true);

        const visited = best.stops.slice(0, US_CITIES.length).map((stop) => stop.addressLabel).sort();
        expect(visited).toEqual(US_CITIES.map((city) => city.label).sort());
    });

    /**
     * Open-path scenario (no return to a depot): brute-forces all 8! =
     * 40,320 orderings of 8 real cities to find the true shortest path, and
     * checks the API returns exactly that.
     */
    test('finds the true shortest open path for real cities', async ({ request, browserName }) => {
        test.skip(browserName !== 'chromium', 'pure API test, no need to run per-browser');

        const cities = US_CITIES.slice(0, 8);
        const matrix = buildMatrix(cities);
        const expected = bruteForceMin(matrix, cities.map((_, i) => i), openTourDistance);

        await loginAsCustomer(request);

        const response = await request.post(`${BASE_URL}/comivoyager/optimize`, {
            data: {
                addresses: cities,
                method: 'haversine',
                routes: 1,
                returnToStart: false,
            },
        });

        expect(response.ok()).toBeTruthy();
        const body = await response.json();
        const best = body.routes[0];

        expect(Math.abs(best.totalDistanceKm - expected)).toBeLessThan(DISTANCE_TOLERANCE_KM);
        expect(best.stops).toHaveLength(cities.length);
        expect(best.stops[0].isStart).toBe(true);
        expect(best.stops[cities.length - 1].isEnd).toBe(true);

        const visited = best.stops.map((stop) => stop.addressLabel).sort();
        expect(visited).toEqual(cities.map((city) => city.label).sort());
    });
});
