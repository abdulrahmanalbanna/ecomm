# Task 10 — Payment & Financial Transactions Module Summary

The **Payment & Financial Transactions Module** has been fully implemented in accordance with the project's **Modular Monolith** architecture and using the raw PostgreSQL baseline ([`database/sql/010_payment_gateways.sql`](database/sql/010_payment_gateways.sql:1), [`database/sql/011_payments.sql`](database/sql/011_payments.sql:1), [`database/sql/012_installments_refunds.sql`](database/sql/012_installments_refunds.sql:1), [`database/sql/018_functions_triggers.sql`](database/sql/018_functions_triggers.sql:1)) as the authoritative database design. **No Laravel migrations were created or run** — the module maps exclusively onto the existing `payments`, `payment_attempts`, `payment_transactions`, `payment_webhook_events`, `installment_plans`, `installments`, and `refunds` tables.

The module follows the established **Orders module pattern**: per-action Action classes with constructor dependency injection in controllers, and domain exceptions mapped locally inside the controllers via the [`MapsPaymentExceptions`](app/Modules/Payments/Presentation/Http/Concerns/MapsPaymentExceptions.php:1) trait (no global render handlers in [`bootstrap/app.php`](bootstrap/app.php:1)).

> **Hard constraints honored:** `database/sql/` is the ONLY schema source (no migrations, no `RefreshDatabase`); DB tests run against the PostgreSQL `testing` database with `DatabaseTransactions`; **triggers and constraints are the final enforcement boundary** — the PHP state machine mirrors `fn_validate_payment_status_transition` only to produce friendly 422s before a write is attempted, and a dedicated test class proves raw SQL bypasses are still rejected (P0005).

---

## Summary of Deliverables

### A. Files Created

#### 1. Domain Layer

- [`app/Modules/Payments/Domain/PaymentStatus.php`](app/Modules/Payments/Domain/PaymentStatus.php:1): The 10 status constants (`pending, processing, authorized, paid, partially_paid, partially_refunded, refunded, refund_pending, failed, cancelled`) plus the legal-transition adjacency map **mirroring** `fn_validate_payment_status_transition()`. Also derives `refundableStatuses()` (paid, partially_paid, partially_refunded) and `settledStatuses()` (paid, partially_paid, partially_refunded, refunded).
- [`app/Modules/Payments/Domain/PaymentMethod.php`](app/Modules/Payments/Domain/PaymentMethod.php:1): `full` / `installment`.
- [`GatewayResult`](app/Modules/Payments/Domain/Contracts/GatewayResult.php:1): the normalized gateway outcome value object (`STATUS_SUCCESS|PENDING|FAILED`, transactionId, redirectUrl, failureCode/Message, redacted `raw`).
- [`PaymentGatewayInterface`](app/Modules/Payments/Domain/Contracts/PaymentGatewayInterface.php:1): `code`, `createCheckout`, `retryCheckout`, `authorize`, `capture`, `cancel`, `refund`, `queryPaymentStatus`, `queryInstallmentStatus`, `verifyWebhookSignature`.
- **Domain Exceptions** (all extend [`PaymentDomainException`](app/Modules/Payments/Domain/Exceptions/PaymentDomainException.php:1), mapped to HTTP by [`MapsPaymentExceptions`](app/Modules/Payments/Presentation/Http/Concerns/MapsPaymentExceptions.php:33)):
  - `PaymentNotFoundException`, `OrderNotFoundException` (shared) → 404 (also used for other users' payments — no existence leakage).
  - `InvalidWebhookException` → 400 (bad signature, missing id/type, malformed body, unknown event type).
  - `WebhookProcessingException` → 202 (event persisted for replay; processing deferred).
  - `PaymentAlreadyExistsException`, `DuplicatePaymentException` → 409.
  - `RefundProcessingException` → 502 (gateway rejected the refund; reservation released).
  - Default **422**: `PaymentGatewayException` (unknown code), `PaymentGatewayUnavailableException` (inactive / unsupported method / installment count / currency), `PaymentProcessingException` (declined / transport failure), `InvalidPaymentStateException` (state machine), `InvalidRefundException` / `RefundExceedsPaymentException` (balance rules), `OrderNotPayableException`, `InstallmentException` (requires-installment-method, invalid count, count mismatch, number exceeds plan, already exists, not found, already settled, out of sequence).

#### 2. Infrastructure — Gateways

- [`AbstractGateway.php`](app/Modules/Payments/Infrastructure/Gateways/AbstractGateway.php:1): shared HTTP plumbing — env-sourced credentials from [`config/payments.php`](config/payments.php:20) (**never from the DB**), explicit timeouts, `ConnectionException` → `PaymentProcessingException::transport()` so transport failures never corrupt payment state, **PCI-DSS `redact()`** (recursive strip of credential/card-like keys — exact + multi-char substring lists with normalization so `api_key`/`API-KEY`/`apiKey` all match while benign look-alikes like `plan_id`/`shipping_address` survive), and constant-time HMAC-SHA256 `verifyWebhookSignature()` over the raw body.
- [`PaymentGatewayRegistry.php`](app/Modules/Payments/Infrastructure/Gateways/PaymentGatewayRegistry.php:1): maps codes → processors (`tabby`, `tamara` built-in; `register()` for additions). The only place gateway classes are referenced outside the gateways themselves.
- [`TabbyGateway.php`](app/Modules/Payments/Infrastructure/Gateways/Tabby/TabbyGateway.php:1): `POST /checkout` (with `installments.number_of_installments` for BNPL), `POST /refunds`, `GET /checkout/{session}` for reconciliation; statuses `INIT/PENDING/CREATED` → pending, `PAID/SETTLED/CAPTURED/AUTHORIZED` → success, `CANCELLED/EXPIRED/FAILED/PAYMENT_FAILED` → failed.
- [`TamaraGateway.php`](app/Modules/Payments/Infrastructure/Gateways/Tamara/TamaraGateway.php:1): `POST /checkout`, `POST /payments/{id}/capture`, `POST /refunds`, `GET /payment-status/{ref}`; `isSuccessful:"false"` short-circuits to failed; `VerificationPending/Authorized` → pending, `Captured/Settled/Paid/Refunded/PartiallyRefunded` → success, `Cancelled/Expired/Declined/Failed` → failed.

#### 3. Infrastructure — Persistence Models (8)

[`Payment`](app/Modules/Payments/Infrastructure/Persistence/Models/Payment.php:1) (UUID `public_id` auto-generated in `booted()`, `scopeForUser`, relations to order/gateway/attempts/transactions/webhookEvents/installmentPlan/refunds), [`PaymentAttempt`](app/Modules/Payments/Infrastructure/Persistence/Models/PaymentAttempt.php:1), [`PaymentTransaction`](app/Modules/Payments/Infrastructure/Persistence/Models/PaymentTransaction.php:1) (immutable ledger: `charge|refund|settlement` types), [`PaymentWebhookEvent`](app/Modules/Payments/Infrastructure/Persistence/Models/PaymentWebhookEvent.php:1), [`PaymentGateway`](app/Modules/Payments/Infrastructure/Persistence/Models/PaymentGateway.php:1) (`installmentOptions()` / `supportsInstallmentCount()` from the non-sensitive JSONB `config`), [`InstallmentPlan`](app/Modules/Payments/Infrastructure/Persistence/Models/InstallmentPlan.php:1), [`Installment`](app/Modules/Payments/Infrastructure/Persistence/Models/Installment.php:1), [`Refund`](app/Modules/Payments/Infrastructure/Persistence/Models/Refund.php:1). **No float casts on money columns** — NUMERIC values stay exact decimal strings.

#### 4. Application Services (8)

- [`PaymentAmountService.php`](app/Modules/Payments/Application/Services/PaymentAmountService.php:1): bcmath scale-2 money math; `payableAmount(Order)` is the **server-side source of truth** (client amounts are never read).
- [`PaymentGatewayResolver.php`](app/Modules/Payments/Application/Services/PaymentGatewayResolver.php:1): DB row + processor resolution with active/method/installment-count/currency validation.
- [`PaymentIdempotencyService.php`](app/Modules/Payments/Application/Services/PaymentIdempotencyService.php:1): key generation, replay/conflict resolution for intents, and insert-if-absent guards for transactions and webhook events (UNIQUE-violation-safe).
- [`PaymentStateTransitionService.php`](app/Modules/Payments/Application/Services/PaymentStateTransitionService.php:1): the **single status write path** — PHP adjacency check, then the DB trigger remains authoritative (P0005 surfaces as `InvalidPaymentStateException`).
- [`OrderPaymentSyncService.php`](app/Modules/Payments/Application/Services/OrderPaymentSyncService.php:1): payment→order lifecycle sync (`markPaymentPending`, `markPaid` → confirmed, `markFailed` → failed), each appending its order event through the Orders transition action.
- [`RefundBalanceService.php`](app/Modules/Payments/Application/Services/RefundBalanceService.php:1): settled-charge lookup, refundable assertions, balance math (`remaining = settled_charge − settled_refunds − active_reservations`).
- [`InstallmentCalculationService.php`](app/Modules/Payments/Application/Services/InstallmentCalculationService.php:1): count validation against gateway capabilities and **exact decimal splits** (remainder distributed to the last installments so the plan sums to the payment amount to the cent).
- [`PaymentReconciliationService.php`](app/Modules/Payments/Application/Services/PaymentReconciliationService.php:1): builds the reconciliation report (local vs gateway ledger sums, expected status, discrepancies, unprocessed events).

#### 5. Application Actions (11) + DTOs (9)

- [`CreatePaymentIntentAction.php`](app/Modules/Payments/Application/Actions/CreatePaymentIntentAction.php:1): order lookup (404-safe), idempotent replay, **short txn A** (persist intent + attempt #1) → **gateway HTTP outside any transaction** → outcome recorded; declined gateway persists the failed attempt and leaves the intent `pending` (retryable); success/pending path is **short txn B** (`pending → processing`, order → `payment_pending`).
- [`RetryPaymentAction.php`](app/Modules/Payments/Application/Actions/RetryPaymentAction.php:1): new attempt number (UNIQUE `(payment_id, attempt_number)`), `failed/cancelled → processing` reopen, gateway `retryCheckout`.
- [`ProcessPaymentWebhookAction.php`](app/Modules/Payments/Application/Actions/ProcessPaymentWebhookAction.php:1): **persist-before-process** (event row first; duplicate `gateway_event_id` → `ignored_duplicate`), payment matching by session/checkout id → payment public id → order id, event routing (refund → installment → success → failure), settled-charge booking via `SettlePaymentTransactionAction`, order sync, unmatched references persisted and answered **202** for replay.
- [`ProcessRefundWebhookAction.php`](app/Modules/Payments/Application/Actions/ProcessRefundWebhookAction.php:1): async refund completion/failure (refund row + ledger + payment status).
- [`SettlePaymentTransactionAction.php`](app/Modules/Payments/Application/Actions/SettlePaymentTransactionAction.php:1): idempotent ledger writes (gateway transaction id UNIQUE → duplicate returns null).
- [`CreateInstallmentPlanAction.php`](app/Modules/Payments/Application/Actions/CreateInstallmentPlanAction.php:1): admin plan creation on installment payments (replay on same count, conflict on different count).
- [`SettleInstallmentAction.php`](app/Modules/Payments/Application/Actions/SettleInstallmentAction.php:1): sequential settlement enforcement (out-of-sequence 422, same-txn replay 200, different-txn 422), per-installment charge txn, plan completion, payment `partially_paid → paid`, and **order sync to confirmed** on full settlement.
- [`CreateRefundAction.php`](app/Modules/Payments/Application/Actions/CreateRefundAction.php:1): refundable-status + balance assertions, **reservation-first** (refund row `processing` before the gateway call), gateway success → settle (`refunded`/`partially_refunded`), gateway rejection → release reservation and keep the payment refundable.
- [`ReconcilePaymentAction.php`](app/Modules/Payments/Application/Actions/ReconcilePaymentAction.php:1): gateway snapshot → `settleMissingCharge` (books only the missing portion, never guesses without a gateway reference) → report → **shortest legal path** correction (BFS over the adjacency map; terminal contradictions reported `uncorrectable_status_mismatch`, never forced) → order sync.
- [`ListPaymentsAction.php`](app/Modules/Payments/Application/Actions/ListPaymentsAction.php:1) / [`GetPaymentAction.php`](app/Modules/Payments/Application/Actions/GetPaymentAction.php:1): filtered pagination (status/gateway/payment_method/date range; unknown status ignored) and eager-loaded detail.

#### 6. Presentation Layer

- **Form Requests**: [`CreatePaymentIntentRequest`](app/Modules/Payments/Presentation/Http/Requests/CreatePaymentIntentRequest.php:1), [`RetryPaymentRequest`](app/Modules/Payments/Presentation/Http/Requests/RetryPaymentRequest.php:1), [`CreateRefundRequest`](app/Modules/Payments/Presentation/Http/Requests/CreateRefundRequest.php:1) (exact-decimal positive amounts), [`CreateInstallmentPlanRequest`](app/Modules/Payments/Presentation/Http/Requests/CreateInstallmentPlanRequest.php:1), [`SettleInstallmentRequest`](app/Modules/Payments/Presentation/Http/Requests/SettleInstallmentRequest.php:1).
- **API Resources**: [`PaymentResource`](app/Modules/Payments/Presentation/Http/Resources/PaymentResource.php:1) (**customer-safe**: no internal BIGINT id, no `gateway_payment_id`, no idempotency key, no ledger), [`PaymentAdminResource`](app/Modules/Payments/Presentation/Http/Resources/PaymentAdminResource.php:1) (full ledger: attempts + transactions), `PaymentAttemptResource`, `PaymentTransactionResource`, `RefundResource`, `InstallmentPlanResource`, `InstallmentResource`. Money serialized as exact decimal strings.
- **Controllers**: [`CustomerPaymentController`](app/Modules/Payments/Presentation/Http/Controllers/CustomerPaymentController.php:1), [`AdminPaymentController`](app/Modules/Payments/Presentation/Http/Controllers/AdminPaymentController.php:1), [`WebhookController`](app/Modules/Payments/Presentation/Http/Controllers/WebhookController.php:1) (public, signature-gated).
- **Routes & Provider**: [`app/Modules/Payments/Routes/api.php`](app/Modules/Payments/Routes/api.php:1) loaded by [`PaymentsServiceProvider`](app/Modules/Payments/Providers/PaymentsServiceProvider.php:1) (auto-discovered by [`ModuleRegistry`](app/Modules/ModuleRegistry.php:36)). All 11 routes verified via `php artisan route:list`.

---

### B. Files Modified (production fix during testing)

- [`SettleInstallmentAction.php`](app/Modules/Payments/Application/Actions/SettleInstallmentAction.php:1): full installment settlement now syncs the order to **confirmed** via `OrderPaymentSyncService::markPaid()` — previously a fully settled plan left the order stuck in `payment_pending`. Proven by `InstallmentSettlementTest`.

---

## Payment Lifecycle (DB-authoritative)

```text
pending ──► processing ──► authorized ──► paid ──► refund_pending ──► partially_refunded ──► refunded
                │               │           ▲            │                        ▲
                ├──► cancelled  └──► cancelled│           └──► partially_refunded ─┘
                │                            │
processing ──► partially_paid ──► paid / failed
failed ──► processing (retry) / cancelled      (cancelled, refunded = terminal)
```

- **DB-authoritative**: `trg_payments_validate_status_transition` rejects anything outside the map with **P0005** `'Invalid payment status transition: % → %'` (no-op when status is unchanged). [`PaymentDbConstraintsTest`](tests/Feature/PaymentDbConstraintsTest.php:1) proves even raw `DB::table('payments')->update()` bypasses are rejected — deliberately-failing writes run inside `DB::transaction()` savepoints so the outer `DatabaseTransactions` wrapper survives.
- **Ledger integrity**: `payment_transactions.gateway_transaction_id` UNIQUE (no double-booking), `refunds` composite FK to the settled charge, `chk_payments_gateway_id_on_paid` (settled statuses require a gateway reference), `fn_validate_refund_total` (refund aggregate ≤ payment amount, counting pending/processing/processed), `payments` UNIQUE(order_id) (one intent per order), `uq_payment_attempt_number`, `payment_webhook_events.gateway_event_id` UNIQUE (webhook dedupe at the storage layer), installment plan/number constraints (`uq_installment_number_per_plan`, installment `gateway_transaction_id` UNIQUE, plan requires an installment payment).

## Webhook Security

- Public `POST /api/v1/webhooks/{tabby|tamara}`; **HMAC-SHA256 over the exact raw body** against the env-sourced secret (`t-signature` / `x-tamara-signature` headers). Unsigned, tampered, missing-signature, or malformed deliveries → **400 with nothing persisted**; an empty configured secret rejects everything.
- **Persist-before-process**: valid events are stored first; duplicate `gateway_event_id` → `ignored_duplicate` (no reprocessing). Events that cannot be applied (unmatched reference, illegal transition) are persisted for replay and answered **202**.

## RBAC

- `payments.view` (admin listing/detail), `payments.refund` (refunds), `payments.reconcile` (reconciliation), `installments.settle` via admin routes; customer routes scoped by `self.*` + ownership (`Payment::forUser` → foreign payments 404, never 403-leak). Webhook routes are public but signature-gated. The seeded `staff` role holds `payments.view` + `payments.reconcile` but **not** `payments.refund` — proven by [`PaymentAuthorizationTest`](tests/Feature/PaymentAuthorizationTest.php:1).

## API Routes Summary (11)

```text
POST    /api/v1/customer/payments                      (auth:api) create intent
GET     /api/v1/customer/payments                      (auth:api) own history (paginated, filters)
GET     /api/v1/customer/payments/{publicId}           (auth:api, whereUuid) customer-safe detail
POST    /api/v1/customer/payments/{publicId}/retry     (auth:api, whereUuid) reopen failed/cancelled

GET     /api/v1/admin/payments                         (auth:api + payments.view)
GET     /api/v1/admin/payments/{publicId}              (auth:api + payments.view) full ledger
POST    /api/v1/admin/payments/{publicId}/refunds      (auth:api + payments.refund)
POST    /api/v1/admin/payments/{publicId}/reconcile    (auth:api + payments.reconcile)
POST    /api/v1/admin/payments/{publicId}/installments (auth:api + payments.view) create plan

POST    /api/v1/webhooks/tabby                         (public, HMAC t-signature)
POST    /api/v1/webhooks/tamara                        (public, HMAC x-tamara-signature)
```

## PostgreSQL-Backed Feature Test Suite (13 files, 89 tests)

| File | Tests | Focus |
|---|---|---|
| [`PaymentIntentCreationTest.php`](tests/Feature/PaymentIntentCreationTest.php:1) | 11 | Tabby/Tamara intents, customer-safe response + redirect, server amounts, Bearer auth on the wire, declined gateway keeps intent pending, client cannot inject amount, validation, installment capability guard, auth, cancelled order not payable |
| [`PaymentIdempotencyTest.php`](tests/Feature/PaymentIdempotencyTest.php:1) | 4 | Replay same key+order, key conflict 409, second intent per order 409, DB UNIQUE rejects duplicate keys |
| [`PaymentRetryTest.php`](tests/Feature/PaymentRetryTest.php:1) | 4 | Attempt #2 + reopened checkout, paid retry rejected, auth+ownership, retry after gateway failure syncs order |
| [`PaymentWebhookSecurityTest.php`](tests/Feature/PaymentWebhookSecurityTest.php:1) | 8 | Unsigned/tampered/missing-sig/malformed/missing-id/missing-type rejected 400 nothing persisted, per-gateway headers, empty secret rejects all |
| [`PaymentWebhookProcessingTest.php`](tests/Feature/PaymentWebhookProcessingTest.php:1) | 8 | paid → settle + order confirmed, duplicate ignored, failed/cancelled paths, unmatched 202 persisted, tamara checkout-id match, uuid match, illegal transition persisted for replay |
| [`PaymentRefundTest.php`](tests/Feature/PaymentRefundTest.php:1) | 8 | Partial → partially_refunded, full → refunded, webhook completion reaches refunded, gateway rejection releases reservation, settled-only guard, amount validation, async refund webhook, failed refund webhook |
| [`InstallmentPlanTest.php`](tests/Feature/InstallmentPlanTest.php:1) | 8 | Tabby 4x exact split, uneven split sums to the cent, installment-method requirement, capability guard, customer surface, replay/conflict, DB rejects invalid counts/numbers |
| [`InstallmentSettlementTest.php`](tests/Feature/InstallmentSettlementTest.php:1) | 9 | Sequential settlement, out-of-sequence 422, same-txn replay 200, different-txn 422, full plan → paid/completed + order confirmed (production fix), installment webhooks (with/without number), RBAC, not-found 422 |
| [`PaymentReconciliationTest.php`](tests/Feature/PaymentReconciliationTest.php:1) | 8 | Missing charge settled + order confirmed, partial top-up only, no-gateway-reference reported not guessed, terminal state uncorrectable, consistent no-op, pending snapshot no corrections, permission, 404 |
| [`PaymentAuthorizationTest.php`](tests/Feature/PaymentAuthorizationTest.php:1) | 7 | Admin full access, seeded staff lacks refund (403), customer 401/403 matrix, cross-customer 404, webhook public-but-gated |
| [`PaymentListingTest.php`](tests/Feature/PaymentListingTest.php:1) | 6 | Customer scoping + meta, status filter + pagination, unknown filter ignored, admin gateway/method filters, admin vs customer detail shape |
| [`PaymentDbConstraintsTest.php`](tests/Feature/PaymentDbConstraintsTest.php:1) | 11 | P0005 trigger, trigger no-op, settled-needs-reference CHECK, one-intent-per-order, positive amount, attempt uniqueness, txn double-booking, webhook event dedupe, refund aggregate cap, plan-requires-installment, installment gateway-txn uniqueness |
| [`GatewayBehaviorTest.php`](tests/Feature/GatewayBehaviorTest.php:1) | 8 | Registry resolution/unknown code, recursive redaction + benign look-alikes, HMAC verification matrix, transport failure keeps intent pending, inactive gateway, unsupported currency |

Shared fixtures live in [`tests/Support/PaymentsTestHelpers.php`](tests/Support/PaymentsTestHelpers.php:1) (gateway config injection, `fakeGatewayHttp()` with a fresh `Http` factory per call to defeat stub merging, exact gateway response shapes, signed-webhook delivery, intent/payment fixtures, staff-with-permissions). All tests use `DatabaseTransactions` against the PostgreSQL `testing` database — no `RefreshDatabase`, no SQLite, no factories.

## Verification Results

- **Full suite**: `php artisan test` → **315 passed (1546 assertions)** — the entire Task 01–09 baseline plus all 89 payment tests green.
- **Routes**: `php artisan route:list --path=payments` → 9 routes; `--path=webhook` → 2 routes.
- **App boot**: `php artisan about` → Laravel 12.68.0 / PHP 8.4.24 / pgsql.
- **Autoload**: `composer dump-autoload -o` → no warnings.
- **Schema**: `database/sql/verify-schema.sql` → **FAIL = 0** (110 PASS, 1 non-critical pre-existing WARN about `pg_stat_statements` preload).
