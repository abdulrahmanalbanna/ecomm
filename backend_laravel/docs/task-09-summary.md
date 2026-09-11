# Task 09 — Checkout & Order Management Module Summary

The **Checkout & Order Management Module** has been fully implemented in accordance with the project's **Modular Monolith** architecture and using the raw PostgreSQL baseline ([`database/sql/008_orders.sql`](database/sql/008_orders.sql:1), [`database/sql/018_functions_triggers.sql`](database/sql/018_functions_triggers.sql:1)) as the authoritative database design. **No Laravel migrations were created or run** — the module maps exclusively onto the existing `orders`, `order_items`, and `order_events` tables.

The module follows the established **Customer/Cart module pattern**: per-action Action classes with constructor dependency injection in controllers, and domain exceptions handled locally inside the controllers via `try-catch` blocks (no global render handlers in [`bootstrap/app.php`](bootstrap/app.php:1)).

> **User directive honored:** Row-Level Security (RLS) has been **removed** from the current baseline (SQL files `020`/`022` no longer exist). Customer/order ownership is enforced **at the application layer** using authenticated-user scoping — every customer query is filtered with `where('user_id', $user->id)` via [`Order::scopeForUser()`](app/Modules/Orders/Infrastructure/Persistence/Models/Order.php:1) — exactly following the Customer and Cart module architecture. RLS was **not** recreated anywhere.

---

## Summary of Deliverables

### A. Files Created

#### 1. Domain Layer

- [`app/Modules/Orders/Domain/OrderStatus.php`](app/Modules/Orders/Domain/OrderStatus.php:1): The 11 status constants plus the legal-transition adjacency map **mirroring** `fn_validate_order_status_transition()`. The PostgreSQL trigger `trg_orders_status_transition` remains the authoritative guard; the PHP map exists only to produce friendly 422s before a write is attempted. Also derives `cancellableStatuses()` (pending, payment_pending, confirmed, processing) and `customerCancellableStatuses()` (pending, payment_pending).
- **Domain Exceptions** (all extend [`OrderDomainException`](app/Modules/Orders/Domain/Exceptions/OrderDomainException.php:1)):
  - [`OrderNotFoundException`](app/Modules/Orders/Domain/Exceptions/OrderNotFoundException.php:1) → 404 (also used for other users' orders — no existence leakage).
  - [`EmptyCartCheckoutException`](app/Modules/Orders/Domain/Exceptions/EmptyCartCheckoutException.php:1) → 422.
  - [`CheckoutItemNotSellableException`](app/Modules/Orders/Domain/Exceptions/CheckoutItemNotSellableException.php:1) → 422 (inactive/unsellable variant or unpublished product).
  - [`InvalidStatusTransitionException`](app/Modules/Orders/Domain/Exceptions/InvalidStatusTransitionException.php:1) → 422.
  - [`OrderNotCancellableException`](app/Modules/Orders/Domain/Exceptions/OrderNotCancellableException.php:1) → 422 (window closed).
  - [`AddressNotOwnedForCheckoutException`](app/Modules/Orders/Domain/Exceptions/AddressNotOwnedForCheckoutException.php:1) → 422.

#### 2. Infrastructure & Persistence Models

- [`Order.php`](app/Modules/Orders/Infrastructure/Persistence/Models/Order.php:1): Maps to `orders`. JSONB casts (`shipping_address`, `billing_address`, `metadata`) via [`JsonObjectCast`](app/Shared/Infrastructure/Database/Casts/JsonObjectCast.php:1). **No float casts on money columns** — NUMERIC values stay exact strings. `scopeForUser()` implements application-layer ownership scoping.
- [`OrderItem.php`](app/Modules/Orders/Infrastructure/Persistence/Models/OrderItem.php:1): Immutable line items with `product_snapshot` JSONB; `UPDATED_AT = null` (rows are never updated).
- [`OrderEvent.php`](app/Modules/Orders/Infrastructure/Persistence/Models/OrderEvent.php:1): Append-only lifecycle events. `UPDATED_AT = null`, non-incrementing PK sourced from `order_events_id_seq` (the table is RANGE-partitioned; all access goes through the **partition parent** only).

#### 3. Application DTOs & Actions

- **DTOs**: [`CheckoutData`](app/Modules/Orders/Application/DTOs/CheckoutData.php:1), [`OrderListFiltersData`](app/Modules/Orders/Application/DTOs/OrderListFiltersData.php:1).
- **Actions**:
  - [`CheckoutAction.php`](app/Modules/Orders/Application/Actions/CheckoutAction.php:1) — the transactional checkout (detailed below).
  - [`TransitionOrderStatusAction.php`](app/Modules/Orders/Application/Actions/TransitionOrderStatusAction.php:1) — the **single lifecycle entry point**: row lock, PHP adjacency check, DB-trigger-authoritative write (P0001 mapped to the domain exception), inventory side effects (release on →cancelled/failed, deduct on →delivered), one appended event. Lifecycle timestamps are set by the DB trigger, never by application code.
  - [`ListCustomerOrdersAction.php`](app/Modules/Orders/Application/Actions/ListCustomerOrdersAction.php:1) / [`GetCustomerOrderAction.php`](app/Modules/Orders/Application/Actions/GetCustomerOrderAction.php:1) — ownership-scoped history and detail.
  - [`CancelCustomerOrderAction.php`](app/Modules/Orders/Application/Actions/CancelCustomerOrderAction.php:1) — pre-fulfillment window (pending/payment_pending), delegates to the transition action.
  - [`CancelAdminOrderAction.php`](app/Modules/Orders/Application/Actions/CancelAdminOrderAction.php:1) — full DB cancellable set.
  - [`ListAdminOrdersAction.php`](app/Modules/Orders/Application/Actions/ListAdminOrdersAction.php:1) / [`GetAdminOrderAction.php`](app/Modules/Orders/Application/Actions/GetAdminOrderAction.php:1) — cross-customer queue with status/date/search filters (public_id, buyer email, SKU).
  - [`ListOrderEventsAction.php`](app/Modules/Orders/Application/Actions/ListOrderEventsAction.php:1) — chronological event trail.

#### 4. Presentation Layer

- **Form Requests**: [`CheckoutRequest`](app/Modules/Orders/Presentation/Http/Requests/CheckoutRequest.php:1) (address ownership validated against the authenticated user), [`TransitionOrderStatusRequest`](app/Modules/Orders/Presentation/Http/Requests/TransitionOrderStatusRequest.php:1) (`Rule::in(OrderStatus::all())`), [`CancelOrderRequest`](app/Modules/Orders/Presentation/Http/Requests/CancelOrderRequest.php:1).
- **API Resources**: [`OrderCustomerResource`](app/Modules/Orders/Presentation/Http/Resources/OrderCustomerResource.php:1) (never exposes the internal BIGINT id; money as exact decimal strings), [`OrderAdminResource`](app/Modules/Orders/Presentation/Http/Resources/OrderAdminResource.php:1) (internal id + buyer), [`OrderItemResource`](app/Modules/Orders/Presentation/Http/Resources/OrderItemResource.php:1), [`OrderEventResource`](app/Modules/Orders/Presentation/Http/Resources/OrderEventResource.php:1).
- **Controllers**: [`CustomerOrderController`](app/Modules/Orders/Presentation/Http/Controllers/CustomerOrderController.php:1), [`AdminOrderController`](app/Modules/Orders/Presentation/Http/Controllers/AdminOrderController.php:1).
- **Routes & Provider**: [`app/Modules/Orders/Routes/api.php`](app/Modules/Orders/Routes/api.php:1) loaded by [`OrdersServiceProvider`](app/Modules/Orders/Providers/OrdersServiceProvider.php:1) (auto-discovered by [`ModuleRegistry`](app/Modules/ModuleRegistry.php:36), which already listed `'Orders'`). All 10 routes verified via `php artisan route:list --path=orders`.

#### 5. Inventory Module Addition (single reservation mechanism — no second mechanism)

- [`StockBatchReservationData`](app/Modules/Inventory/Application/DTOs/StockBatchReservationData.php:1) + [`InventoryService::reserveStockBatch()`](app/Modules/Inventory/Application/Services/InventoryService.php:269): wraps the deadlock-safe `fn_reserve_inventory_batch(BIGINT[], INT[], BIGINT)` (locks rows ASC by `variant_id`). After the function call, the freshly written `reservation` movements are linked to the order via a lock → snapshot `max(id)` → `UPDATE ... WHERE id > mark` pattern (MVCC-safe). P0002/P0003/P0004 are translated to the existing `InventoryNotFoundException` / `InsufficientInventoryException` / `InvalidReservationException`.

#### 6. PostgreSQL-Backed Feature Test Suite (12 files, 85 tests)

| File | Focus |
|---|---|
| [`CheckoutTest.php`](tests/Feature/CheckoutTest.php:1) | Happy path, server-computed totals, snapshot immutability, coupon, large-quantity constraint satisfaction |
| [`CheckoutValidationTest.php`](tests/Feature/CheckoutValidationTest.php:1) | Auth, required fields, empty cart, inactive/unpublished/soft-deleted/zero-price items, retry-after-fix |
| [`CheckoutInventoryTest.php`](tests/Feature/CheckoutInventoryTest.php:1) | 409 + full rollback, reservation accounting, multi-item batch, one-short-aborts-all, backorder |
| [`CheckoutAddressTest.php`](tests/Feature/CheckoutAddressTest.php:1) | Billing fallback, separate billing, foreign/nonexistent addresses, snapshot completeness & survival |
| [`CheckoutConcurrencyTest.php`](tests/Feature/CheckoutConcurrencyTest.php:1) | Duplicate checkout, inventory race, partial race, cart modification during checkout |
| [`CustomerOrderListingTest.php`](tests/Feature/CustomerOrderListingTest.php:1) | Ownership scoping, ordering, status/date filters, pagination, decimal-string shape |
| [`CustomerOrderRetrievalTest.php`](tests/Feature/CustomerOrderRetrievalTest.php:1) | Detail data, 404-not-403 for foreign orders, UUID route constraints |
| [`CustomerOrderCancellationTest.php`](tests/Feature/CustomerOrderCancellationTest.php:1) | Cancellation window, stock release, double-cancel guard, POST-only |
| [`OrderStatusTransitionTest.php`](tests/Feature/OrderStatusTransitionTest.php:1) | Full lifecycle, illegal/terminal rejections, failed-release, DB trigger rejects raw bypass |
| [`OrderEventTest.php`](tests/Feature/OrderEventTest.php:1) | Initial NULL event, chronology, actor/metadata, immutability (405s), partition-parent reads |
| [`OrderAuthorizationTest.php`](tests/Feature/OrderAuthorizationTest.php:1) | 401 on all routes, 403 without permissions, cross-customer isolation |
| [`AdminOrderManagementTest.php`](tests/Feature/AdminOrderManagementTest.php:1) | Queue, search, admin detail shape, admin cancel window, delivered deduction |

Shared fixtures live in [`tests/Support/OrdersTestHelpers.php`](tests/Support/OrdersTestHelpers.php:1) (users, tokens, addresses, sellable variants, direct cart seeding). All tests use `DatabaseTransactions` against the PostgreSQL `testing` database — no `RefreshDatabase`, no SQLite, no factories.

---

### B. Files Modified

- [`app/Modules/Inventory/Application/Services/InventoryService.php`](app/Modules/Inventory/Application/Services/InventoryService.php:269): Added `reserveStockBatch()` (the only Orders-driven inventory write path).
- [`tests/Support/OrdersTestHelpers.php`](tests/Support/OrdersTestHelpers.php:1): `seedCart()` now reuses the existing cart row (carts.user_id is UNIQUE) so a customer can place multiple orders per test; added `placeOrder()` / `adminTransition()` conveniences.

---

## Checkout Mechanics (CheckoutAction)

Everything happens inside **one `DB::transaction`** — any failure rolls back the order, items, events, reservations, and movements atomically:

1. **Cart lock**: `SELECT ... FOR UPDATE` on the user's cart row — serializes duplicate checkouts of the same cart (second request sees an empty cart → 422).
2. **Sellability re-validation**: variants loaded `withTrashed()`; rejects soft-deleted/inactive variants, missing/inactive/unpublished products, and non-positive prices.
3. **Server-computed totals**: prices read from the Catalog via `getRawOriginal('price')` (raw NUMERIC strings) and computed with **bcmath at scale 2** (`bcmul`/`bcadd`/`bcsub`). Client-submitted prices are never read. `discount/shipping/tax = 0.00` (payments/shipping/promotions are out of scope for Task 09), so `total = subtotal`. The deferred DB constraints (`orders.subtotal = SUM(order_items.total_price)`, `chk_orders_total_formula`, `order_items.total_price = quantity × unit_price`) validate at COMMIT — proven by the 99999 × 0.01 = 999.99 test.
4. **Immutable snapshots**: each `order_items` row stores a `product_snapshot` JSONB (product slug/name/brand/category, variant SKU/name/price/currency/attributes/weight/media, `captured_at`) and the order stores full `shipping_address`/`billing_address` JSONB snapshots (billing falls back to the shipping snapshot). Deleting the address or editing the catalog afterwards provably cannot alter the order.
5. **Inventory reservation**: one `reserveStockBatch()` call for all line items (deadlock-safe, backorder-aware). Insufficient stock → `InsufficientInventoryException` → HTTP 409 with full rollback.
6. **Append-only event**: `NULL → pending` event written with the buyer as actor.
7. **Cart cleared**: `cart_items` deleted (the cart row persists for reuse).

## Order Lifecycle

```text
pending ──► payment_pending ──► confirmed ──► processing ──► shipped ──► delivered ──► refund_requested ──► refunded / refund_rejected
   │                │                │              │                                      (refund_* terminal)
   └──► cancelled / failed ◄─────────┴──────────────┴── (admin cancel window)
        (terminal; reservations released)
```

- **DB-authoritative**: `trg_orders_status_transition` rejects anything outside the map with P0001 (a test proves even a raw `DB::table('orders')->update()` bypass is rejected).
- **Inventory side effects** (via the Inventory module only): →cancelled/failed releases reservations (`fn_release_inventory`); →delivered deducts stock (`fn_deduct_inventory`, per the function comment "application code must call this at the delivered transition"). No physical deduction ever happens before delivery, so cancellations never need restocking.
- **Timestamps**: `confirmed_at`/`shipped_at`/`delivered_at`/`cancelled_at` are set by the DB trigger.
- **Events**: exactly one `order_events` row per successful transition; rejected transitions write nothing.

## API Routes Summary

```text
POST    /api/v1/customer/orders/checkout          (auth:api + permission:self.orders.view)
GET     /api/v1/customer/orders                   (auth:api + permission:self.orders.view)
GET     /api/v1/customer/orders/{publicId}        (auth:api + permission:self.orders.view, whereUuid)
POST    /api/v1/customer/orders/{publicId}/cancel (auth:api + permission:self.orders.view, whereUuid)
GET     /api/v1/customer/orders/{publicId}/events (auth:api + permission:self.orders.view, whereUuid)

GET     /api/v1/admin/orders                      (auth:api + permission:orders.view)
GET     /api/v1/admin/orders/{publicId}           (auth:api + permission:orders.view)
GET     /api/v1/admin/orders/{publicId}/events    (auth:api + permission:orders.view)
POST    /api/v1/admin/orders/{publicId}/transition(auth:api + permission:orders.process)
POST    /api/v1/admin/orders/{publicId}/cancel    (auth:api + permission:orders.cancel)
```

| Endpoint | Method | Success | Errors |
|---|---|---|---|
| `/api/v1/customer/orders/checkout` | `POST` | `201` order | `401/403`, `422` (validation, empty cart, unsellable, foreign address), `409` (insufficient stock) |
| `/api/v1/customer/orders` | `GET` | `200` paginated | `401/403` |
| `/api/v1/customer/orders/{publicId}` | `GET` | `200` detail | `401/403/404` |
| `/api/v1/customer/orders/{publicId}/cancel` | `POST` | `200` cancelled | `401/403/404/422` (window closed) |
| `/api/v1/customer/orders/{publicId}/events` | `GET` | `200` events | `401/403/404` |
| `/api/v1/admin/orders` | `GET` | `200` paginated + filters | `401/403` |
| `/api/v1/admin/orders/{publicId}` | `GET` | `200` detail | `401/403/404` |
| `/api/v1/admin/orders/{publicId}/events` | `GET` | `200` events | `401/403/404` |
| `/api/v1/admin/orders/{publicId}/transition` | `POST` | `200` updated | `401/403/404/422` (illegal), `409` (inventory bounds) |
| `/api/v1/admin/orders/{publicId}/cancel` | `POST` | `200` cancelled | `401/403/404/422` |

No `PUT`/`PATCH`/`DELETE` routes exist for orders or events (verified with 405 assertions).

## Verification Results

| Check | Result |
|---|---|
| `php artisan route:list --path=orders` | 10 routes registered |
| `php artisan test --filter Checkout` | **34 passed** (157 assertions) |
| `php artisan test --filter Order` | **72 passed** across Orders + incidental Cart/Inventory matches |
| `php artisan test` (full suite) | **215 passed** (910 assertions), 0 failures |
| `database/sql/verify-schema.ps1` | **110 PASS / 0 FAIL** — "All required schema checks PASSED" |
| `database/migrations` | 3 pre-existing framework default files only — **zero new migrations**, `migrate` never run |

## Compliance Checklist

- [x] SQL baseline authoritative; no Laravel migrations for orders tables; never ran `migrate`.
- [x] Tests on PostgreSQL `testing` DB with `DatabaseTransactions` (no `RefreshDatabase`, no SQLite).
- [x] No schema drift (verify-schema FAIL=0).
- [x] `order_events` accessed only via the partition parent.
- [x] Immutable JSONB product + address snapshots.
- [x] Server-computed decimal-safe totals (bcmath, raw NUMERIC strings, never client prices).
- [x] Inventory reservation exclusively through the Inventory module (`fn_reserve_inventory_batch` / `fn_release_inventory` / `fn_deduct_inventory`).
- [x] Lifecycle respects `fn_validate_order_status_transition`; DB trigger treated as authoritative.
- [x] Append-only events; no update/delete endpoints.
- [x] RBAC via existing seeded permissions (`orders.view/process/cancel`, `self.orders.view`).
- [x] Ownership enforced at the application layer (`where('user_id', ...)`) — **no RLS**, per user directive.
- [x] Concurrency-safe checkout (cart row lock, batch reservation, race tests).
- [x] No over-implementation: payments, shipping fulfillment, promotions, refunds, notifications untouched.

## Baseline Discrepancies Documented

1. **RLS removed from baseline** (`020`/`022` absent): ownership scoping moved to the application layer per explicit user direction; this summary and every Orders file header note it.
2. **Stale header comment** in [`008_orders.sql`](database/sql/008_orders.sql:3) mentions "orders (RANGE-partitioned monthly)" — in the actual baseline `orders` is a **normal table** (only `order_events` is partitioned). Verified via `verify-schema` (3 partitioned parents: `audit_logs`, `inventory_movements`, `order_events`).
3. **Cart stores no prices** — the Catalog is the sole price authority at checkout (client values ignored by design).
4. **No idempotency-key column** on `orders` in the baseline — duplicate-checkout protection is achieved with the cart row `FOR UPDATE` lock plus cart-clear semantics (second concurrent checkout finds an empty cart).
5. **`fn_reserve_inventory_batch` has no `order_id` parameter** — movement→order linkage is done in `InventoryService::reserveStockBatch()` with a lock-then-snapshot-max-id update, keeping the ledger correct without altering the baseline function.
