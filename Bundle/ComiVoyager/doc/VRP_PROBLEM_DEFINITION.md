# Vehicle Routing Problem (VRP) — Problem Definition for OroCommerce

## The Business Problem

A steel distributor (or any B2B/B2C merchant) has a warehouse with **ready-to-ship orders** for the day. Each order has a delivery address. The company owns (or contracts) **K delivery trucks**. The question:

> **How do you split N delivery addresses across K trucks so that each truck drives the shortest possible route, covering nearby addresses together?**

This is the **Vehicle Routing Problem (VRP)** — a generalization of TSP where instead of one salesman visiting all stops, you have multiple vehicles each visiting a subset.

---

## B2B vs B2C: How the Problem Differs

### B2B (Steel/Industrial Distribution — Gerdau/Egerdau use case)

| Factor | B2B Reality |
|---|---|
| **Order size** | Heavy: 5,000–40,000 lbs per order (steel beams, rebar, plate) |
| **Truck capacity** | Limited by weight, not count. A truck carries 1-5 orders max. |
| **Delivery windows** | Customers specify receiving hours (e.g., 7am-3pm, forklift available) |
| **Addresses** | Fewer stops per day (10-50), but spread across large geography |
| **Loading order** | Last delivery loaded first (LIFO). Route affects warehouse picking. |
| **Repeat customers** | Same addresses week after week — route patterns can be cached |
| **Split shipments** | One order may ship across multiple trucks (pegging/scheduling) |
| **Priority** | Hot items, expedited orders must go first regardless of route efficiency |

### B2C (eCommerce, last-mile delivery)

| Factor | B2C Reality |
|---|---|
| **Order size** | Small packages, 1-50 lbs |
| **Truck capacity** | Limited by volume/count. A van carries 50-200 packages. |
| **Delivery windows** | Tight: same-day, next-day, 2-hour slots |
| **Addresses** | Many stops per day (50-200), dense urban areas |
| **Loading order** | Less critical — packages are small and sortable |
| **Repeat customers** | Lower repeat rate, more address diversity |
| **Priority** | Premium/express customers first |

### The Common Core

Despite differences, both B2B and B2C need the same algorithmic solution:

1. **Cluster** addresses into geographic groups (one per vehicle)
2. **Route** each cluster in optimal visiting order (TSP per cluster)
3. **Respect constraints** (capacity, time windows, priorities)

---

## Problem Formulation

### Input

```
Warehouse:
  - location: {lat, lng}          # depot / starting point

Orders (ready to ship):
  - id: order_id
  - address: {lat, lng}           # delivery address (geocoded)
  - weight_lbs: float             # for capacity constraint
  - volume_cuft: float            # optional
  - priority: normal|high|urgent  # delivery priority
  - time_window: {start, end}     # optional delivery window
  - customer_id: string           # for grouping same-customer orders

Vehicles / Drivers:
  - count: K                      # number of trucks / delivery drivers
  - capacity_lbs: float           # weight limit per truck
  - capacity_cuft: float          # optional volume limit
  - max_stops: int                # optional max deliveries per driver
  - max_distance_miles: float     # optional driving-distance cap per shift
  - start: {lat, lng}             # driver's home / start point (defaults to depot)
  - end: {lat, lng}               # optional explicit end point
  - return_to_start: bool         # round trip back to start?
  - avg_speed_mph: float          # driving speed, to convert miles → hours
  - service_time_minutes: float   # unload time per stop
  - max_work_hours: float         # shift length; route trimmed to fit
```

Each vehicle entry represents one **driver + truck unit** ("delivery guy").
Fleets can be homogeneous (one shared config × N drivers) or heterogeneous
(an explicit `drivers` array where each driver has their own home, shift
length, speed, and capacity).

### How time is budgeted

```
driving_hours = total_distance_miles / avg_speed_mph
service_hours = stop_count * service_time_minutes / 60
shift_hours   = driving_hours + service_hours
```

If `shift_hours` exceeds `max_work_hours` (or distance exceeds
`max_distance_miles`), the solver drops the farthest tail stops from that
driver's route into `unassigned` and re-sequences, until the route fits the
budget.

### Output

```
Routes (one per vehicle):
  - vehicle_id: int
  - stops: [order_id, order_id, ...]  # in optimized visiting order
  - total_distance_miles: float
  - total_weight_lbs: float
  - estimated_duration_hours: float
  - legs: [{from, to, distance, duration}, ...]

Summary:
  - total_distance_all_vehicles: float
  - max_single_vehicle_distance: float  # balance metric
  - unassigned_orders: [order_id, ...]  # orders that don't fit
```

### Constraints

| Constraint | Type | Description |
|---|---|---|
| **Delivery radius** | Hard | Orders beyond max radius (default: 100 miles) from depot are rejected / flagged for separate carrier. Configurable. |
| Capacity | Hard | Total weight per vehicle ≤ truck capacity |
| Volume | Soft | Total volume per vehicle ≤ truck volume (if tracked) |
| Max stops | Soft | Don't overload a driver with too many stops |
| Time windows | Soft/Hard | Deliver within customer's receiving hours |
| Priority | Hard | Urgent orders assigned first, to nearest vehicle |
| Return to depot | Config | Vehicle must return to warehouse (round trip) |
| Same-customer grouping | Soft | Multiple orders for same customer on same truck |

### Local Delivery Assumption

This solver targets **local/regional delivery** — all addresses within a configurable radius of the depot (default: **100 miles**). This is the typical B2B steel distribution model: one warehouse serves a metro area or region.

**Why this matters algorithmically:**
- All addresses are geographically close → K-Means clustering produces tight, non-overlapping zones
- Round-trip distances are short → driver can do multiple stops and return same day
- No need for overnight routes or multi-day planning
- Orders beyond the radius are flagged as "out of range" and excluded from local routing (handled by LTL/freight carrier instead)

**Configuration:**
```yaml
# System Configuration → ComiVoyager
max_delivery_radius_miles: 100    # orders beyond this are excluded
```

### Optimization Objectives (ranked)

1. **Minimize total distance across all vehicles** (fuel cost)
2. **Balance workload** — don't send one truck 200 miles while another does 20
3. **Respect all hard constraints** (capacity, priority)
4. **Maximize time window compliance** (deliver on time)

---

## Algorithm Strategy

### Phase 1: Clustering (Assign orders to vehicles)

Split N addresses into K geographic clusters. Each cluster becomes one vehicle's route.

**Approach: K-Means Clustering on Coordinates**

1. Run K-Means (K = number of vehicles) on the lat/lng coordinates of all delivery addresses
2. Each cluster = one vehicle's stops
3. Post-process: move orders between clusters to satisfy capacity constraints

**Why K-Means?**
- O(n × K × iterations) — fast even for 1000 addresses
- Naturally creates geographic groups (nearby addresses cluster together)
- Easy to implement, well-understood
- Works as a starting point before constraint-based refinement

**Alternatives considered:**
- **DBSCAN**: density-based, good for irregular shapes but doesn't guarantee K clusters
- **Sweep algorithm**: polar-angle sweep from depot, classic VRP heuristic, simple but ignores geography
- **Capacitated clustering**: K-Means variant that enforces capacity during clustering, more complex

### Phase 2: Routing (Optimize each cluster)

For each cluster, solve TSP to find the optimal visiting order. This is exactly what ComiVoyager already does with `TopNRouteSolver`.

### Phase 3: Refinement (Optional)

After initial clustering + routing:
- **Inter-route swap**: try moving border stops between adjacent clusters to reduce total distance
- **Rebalancing**: if one vehicle is overloaded or has much longer distance, redistribute stops

---

## Integration with OroCommerce

### Data Source: Ready-to-Ship Orders

The VRP solver needs to pull orders from OroCommerce that are:
- Status: "ready to ship" / "processing" / "awaiting shipment"
- Have a resolved delivery address (geocoded lat/lng)
- Have weight/volume data (from order line items)

In the Egerdau/Gerdau context, this maps to:
- `egerdau_shipping_cart` + `egerdau_shipping_cart_line_item` tables
- Pegging data from MuleSoft (confirmed availability)
- Ship-to addresses from customer accounts

### Admin UI Flow

1. Dispatcher opens **"Route Planner"** in admin
2. System shows today's ready-to-ship orders on a map
3. Dispatcher sets: number of trucks, capacity per truck
4. Clicks **"Optimize Routes"**
5. System runs VRP solver → shows K colored route clusters on the map
6. Dispatcher can drag-drop orders between trucks to adjust
7. Clicks **"Confirm & Print"** → generates route sheets per driver

### API Flow

```
POST /comivoyager/vrp/optimize
{
  "depot": {"lat": 40.7128, "lng": -74.0060},
  "vehicles": {"count": 3, "capacity_lbs": 40000},
  "orders": [
    {"id": "ORD-001", "lat": 40.73, "lng": -73.99, "weight_lbs": 8000},
    {"id": "ORD-002", "lat": 40.75, "lng": -73.97, "weight_lbs": 12000},
    ...
  ]
}
```

Response:
```json
{
  "routes": [
    {
      "vehicle": 1,
      "stops": ["ORD-001", "ORD-003", "ORD-007"],
      "total_distance_miles": 45.2,
      "total_weight_lbs": 35000
    },
    {
      "vehicle": 2,
      "stops": ["ORD-002", "ORD-005", "ORD-009"],
      "total_distance_miles": 38.7,
      "total_weight_lbs": 32000
    },
    ...
  ],
  "summary": {
    "total_distance": 128.4,
    "vehicles_used": 3,
    "orders_assigned": 15,
    "orders_unassigned": 0
  }
}
```

---

## Implementation Plan

### What Already Exists (ComiVoyager Core)

- ✅ Distance matrix providers (Haversine, Vincenty, OSRM, Google, PostGIS)
- ✅ Geocoding (Nominatim, Google, DB cache)
- ✅ TSP solver (HeldKarp exact, NearestNeighbor heuristic, TwoOpt/OrOpt optimizers)
- ✅ Top-N route ranking
- ✅ OroCommerce system configuration
- ✅ REST API + CLI command
- ✅ 156 unit tests

### What Needs to Be Built

| Component | Description |
|---|---|
| `Core/Clustering/KMeansClusterer.php` | K-Means on lat/lng coordinates |
| `Core/Clustering/CapacityAdjuster.php` | Post-process clusters to respect weight/volume limits |
| `Core/Model/Vehicle.php` | Vehicle with capacity, max stops, depot |
| `Core/Model/VRPSolution.php` | Collection of routes (one per vehicle) with summary |
| `Core/Solver/VRPSolver.php` | Orchestrates: cluster → route per cluster → refine |
| `Controller/VRPController.php` | `POST /comivoyager/vrp/optimize` endpoint |
| `Command/VRPOptimizeCommand.php` | CLI: `bin/console comivoyager:vrp:optimize` |
| Tests | Unit tests for clustering, capacity adjustment, VRP solver |

### Phase 1 Scope (MVP)

- K-Means clustering with capacity constraints
- TSP per cluster using existing solver
- REST API endpoint
- No time windows (phase 2)
- No map UI (phase 2)
- No inter-route optimization (phase 2)

---

## References

- **Traveling Salesman Problem (TSP)**: visit N stops in shortest order (1 vehicle)
- **Vehicle Routing Problem (VRP)**: split N stops across K vehicles, each in shortest order
- **Capacitated VRP (CVRP)**: VRP with vehicle capacity constraints
- **VRP with Time Windows (VRPTW)**: CVRP + delivery time windows
- **Clarke-Wright Savings Algorithm**: classic VRP heuristic (merge routes that save distance)
- **Google OR-Tools**: industrial-strength VRP solver (Python/C++, not PHP)
