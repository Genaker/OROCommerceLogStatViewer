# Why ComiVoyager Exists: The Problem, the Fix, and the E-Commerce Case

This document explains the underlying problem the **ComiVoyager** bundle
solves, how it solves it, and why an OroCommerce store — particularly a
B2B/distribution storefront — benefits from having it. It's the "big
picture" companion to [ALGORITHMS.md](ALGORITHMS.md) (how the solver works
internally) and [DISTANCE_PROVIDERS.md](DISTANCE_PROVIDERS.md) (how distances
are measured).

---

## The problem: "in what order should I visit these addresses?"

Take any list of addresses — delivery stops for today's truck, customers a
field sales rep needs to visit, pickup points for a multi-location order —
and ask: **what order minimizes total travel distance (or time)?**

This is the **Traveling Salesman Problem (TSP)**, one of the most famous
problems in computer science, and it is **NP-hard**: there is no known
shortcut formula. The number of possible visiting orders for `n` stops is
`(n-1)!` (or `n!` if the starting point is also free to choose) — it
explodes combinatorially:

| Stops (`n`) | Possible orders |
|---|---|
| 5 | 24 |
| 10 | 362,880 |
| 15 | 87 billion |
| 20 | 60 quintillion |

Nobody plans routes by trying every order, of course — in practice, routes
get sequenced **by hand**, in the order orders were placed, or by whatever
order a CSV happened to list addresses in. All three are essentially random
with respect to actual driving distance. The result is the everyday "issue"
ComiVoyager exists to fix:

- **Wasted mileage and driver time.** A driver crisscrossing the same
  neighborhood twice because two nearby stops were sequenced far apart in
  the list.
- **Inaccurate delivery ETAs.** If stop order is arbitrary, "you're 4th in
  line" tells the customer almost nothing about *when* the truck will
  arrive.
- **No visibility into alternatives.** Even a dispatcher who manually
  re-orders a route by eye has no way to know whether a *meaningfully
  better* order exists, or how close their route is to optimal.
- **Inconsistent results across staff.** Route quality depends entirely on
  whoever happens to be sequencing it that day.

None of this requires anything exotic — it's the same problem behind every
"delivery route planner" or "multi-stop directions" feature in consumer map
apps, just applied to a merchant's own order/customer data.

---

## How ComiVoyager resolves it

ComiVoyager is a self-contained bundle that, given a set of addresses
(with coordinates or free-text addresses to geocode), computes the
**shortest visiting order** — and, unlike a simple "optimize this route"
button, returns the **top-N distinct routes** ranked by distance, so a
dispatcher can see *how much* better the best route is and what the
realistic alternatives look like.

The resolution has three layers, each independently swappable:

1. **Distance measurement** (how "far apart" are two stops?) — five
   pluggable providers from free pure-math estimates (Haversine, Vincenty)
   to real road-network distances (OSRM, Google Distance Matrix, PostGIS).
   See [DISTANCE_PROVIDERS.md](DISTANCE_PROVIDERS.md) and the per-provider
   deep dives in [distance-algorithms/](distance-algorithms/).
2. **Route search** (given those distances, what's the best order?) — a
   size-aware solver (`TopNRouteSolver`) that uses **exact** algorithms for
   small stop counts and **heuristics** for larger ones, always returning
   the top-N candidates. See [ALGORITHMS.md](ALGORITHMS.md).
3. **Integration** — a CLI command (`comivoyager:optimize`) for batch/cron
   use, a storefront HTTP API (`POST /comivoyager/optimize`) for
   integrating into checkout/dispatch UIs, and an Oro System Configuration
   screen so admins pick the distance provider/geocoder without touching
   code. See [API.md](API.md), [CONFIGURATION.md](CONFIGURATION.md), and
   [INSTALLATION.md](INSTALLATION.md).

Crucially, the **core TSP engine** (`Core/`) has zero Symfony/Oro
dependencies — it's a standalone library that takes addresses + a distance
matrix and returns ranked routes. Everything Oro-specific (config, ACLs,
controllers, geocoding cache) is a thin integration layer around it.

---

## Why this matters for an e-commerce / OroCommerce storefront

ComiVoyager isn't a generic routing toy bolted onto Oro — it targets
problems that show up specifically in commerce operations:

### 1. Last-mile delivery route planning (B2B distribution)

A distributor (e.g. a building-materials or industrial-supplies business —
the kind of multi-stop, high-order-volume operation this codebase's sample
data, `eGerdau_Deliveries.csv` etc., reflects) ships dozens of orders per
day from a depot to customer sites. Sequencing those stops well directly
reduces:

- **Fuel and vehicle wear** (fewer miles driven).
- **Driver labor cost** (shorter shifts for the same number of stops).
- **Number of trucks/trips needed** for a given delivery window.

A `depotIndex` (the warehouse) plus `returnToStart: true` models exactly
this: "start at the warehouse, visit every customer, return to the
warehouse" — and the Held-Karp/permutation paths guarantee the *true*
optimum for typical daily route sizes (≤15 stops), with a documented
heuristic fallback for larger routes.

### 2. Field sales / account-visit planning

Sales reps visiting a list of customer accounts face the same problem at a
different scale (a week's worth of visits instead of a day's deliveries).
`returnToStart: false` (an open path, e.g. "start from home, end near the
hotel") models a multi-day trip without forcing a return leg.

### 3. Delivery ETA accuracy and customer communication

Once stops are in their actual driving order, **per-leg distances and
cumulative totals** (`Route::legs`, `Stop::legFromPrevious`,
`cumulativeDistanceKm`) give a real basis for ETAs — "Customer C is stop 4
of 9, ~38km of driving before the truck reaches them" — instead of a
meaningless queue position.

### 4. Comparing "good enough" vs. "actually optimal"

Returning the **top-N** routes (not just one) lets a dispatcher see, e.g.,
"the current manual order is route #1, but the optimizer's #1 saves 12km —
here's the difference" (`deltaFromBestKm`). This builds trust in the
optimizer incrementally rather than asking staff to blindly accept a
black-box re-ordering.

### 5. No forced infrastructure for stores that don't need it

Because Haversine (pure PHP, no setup) is the default provider, a store can
adopt route optimization with **zero external dependencies**, and only
graduate to real road-network distances (OSRM/Google/PostGIS — see
[INSTALLATION.md](INSTALLATION.md)) once the business case justifies the
extra infrastructure.

---

## Summary

| Question | Answer |
|---|---|
| **What problem?** | Sequencing N delivery/visit addresses is NP-hard; naive (manual/arbitrary) ordering wastes mileage, driver time, and gives no ETA basis. |
| **How is it solved?** | `TopNRouteSolver` (exact for n≤15, heuristic + local search for larger n) over a pluggable `DistanceMatrix`, returning ranked top-N routes with full per-leg breakdowns. |
| **Why in e-commerce?** | Directly cuts delivery cost (fuel, driver-hours, fleet size) for distributors, supports field-sales route planning, and gives a real per-stop ETA basis — all configurable from Oro's admin without code changes. |

For the algorithm internals and a discussion of solver performance and
possible future strategies, see [ALGORITHMS.md](ALGORITHMS.md).
