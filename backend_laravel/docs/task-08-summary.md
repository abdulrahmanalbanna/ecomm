# Task 08 — Customer Profile & Address Management Module Summary

The **Customer Profile & Address Management Module** has been fully implemented in accordance with the project's **Modular Monolith** architecture and using the raw PostgreSQL baseline ([`database/sql/003_customers_addresses.sql`](database/sql/003_customers_addresses.sql:1)) as the authoritative database design.

The module follows the **Identity module pattern**: per-action Action classes with method-level dependency injection in controllers, and domain exceptions handled locally inside the controllers via `try-catch` blocks (no global render handlers in [`bootstrap/app.php`](bootstrap/app.php:1)).

---

## Summary of Deliverables

### A. Files Created

#### 1. Domain Layer Exceptions
- [`app/Modules/Customer/Domain/Exceptions/CustomerProfileNotFoundException.php`](app/Modules/Customer/Domain/Exceptions/CustomerProfileNotFoundException.php:1): Thrown when a customer profile cannot be resolved for the authenticated user.
- [`app/Modules/Customer/Domain/Exceptions/AddressNotFoundException.php`](app/Modules/Customer/Domain/Exceptions/AddressNotFoundException.php:1): Thrown when an address does not exist or is not owned by the authenticated user (enforces ownership scoping at the query level).
- [`app/Modules/Customer/Domain/Exceptions/AddressAccessDeniedException.php`](app/Modules/Customer/Domain/Exceptions/AddressAccessDeniedException.php:1): Reserved for future explicit ownership-denial signalling.

#### 2. Infrastructure & Persistence Models
- [`app/Modules/Customer/Infrastructure/Persistence/Models/CustomerProfile.php`](app/Modules/Customer/Infrastructure/Persistence/Models/CustomerProfile.php:1): Persistent profile model mapped to the PostgreSQL `customer_profiles` table. Casts `preferences` as JSON object; exposes `user()` belongs-to relationship.
- [`app/Modules/Customer/Infrastructure/Persistence/Models/Address.php`](app/Modules/Customer/Infrastructure/Persistence/Models/Address.php:1): Persistent address model mapped to the PostgreSQL `addresses` table. Casts `is_default` as boolean; exposes `user()` belongs-to relationship.

#### 3. Application DTOs & Actions
- **DTOs** (immutable, application-layer data transport):
  - [`app/Modules/Customer/Application/DTOs/UpdateCustomerProfileData.php`](app/Modules/Customer/Application/DTOs/UpdateCustomerProfileData.php:1)
  - [`app/Modules/Customer/Application/DTOs/CreateAddressData.php`](app/Modules/Customer/Application/DTOs/CreateAddressData.php:1)
  - [`app/Modules/Customer/Application/DTOs/UpdateAddressData.php`](app/Modules/Customer/Application/DTOs/UpdateAddressData.php:1)
- **Actions** (per-use-case orchestration, mirroring [`LoginAction`](app/Modules/Identity/Application/Actions/LoginAction.php:15)):
  - [`app/Modules/Customer/Application/Actions/GetCustomerProfileAction.php`](app/Modules/Customer/Application/Actions/GetCustomerProfileAction.php:1) — lazy-creates a default profile if none exists.
  - [`app/Modules/Customer/Application/Actions/UpdateCustomerProfileAction.php`](app/Modules/Customer/Application/Actions/UpdateCustomerProfileAction.php:1) — partial-update aware.
  - [`app/Modules/Customer/Application/Actions/ListAddressesAction.php`](app/Modules/Customer/Application/Actions/ListAddressesAction.php:1) — default-first ordering.
  - [`app/Modules/Customer/Application/Actions/CreateAddressAction.php`](app/Modules/Customer/Application/Actions/CreateAddressAction.php:1) — server-controlled `user_id`, uppercase `country_code`.
  - [`app/Modules/Customer/Application/Actions/GetAddressAction.php`](app/Modules/Customer/Application/Actions/GetAddressAction.php:1) — ownership-scoped; throws `AddressNotFoundException`.
  - [`app/Modules/Customer/Application/Actions/UpdateAddressAction.php`](app/Modules/Customer/Application/Actions/UpdateAddressAction.php:1) — partial-update aware.
  - [`app/Modules/Customer/Application/Actions/DeleteAddressAction.php`](app/Modules/Customer/Application/Actions/DeleteAddressAction.php:1) — hard delete (orders store independent JSON snapshots).
  - [`app/Modules/Customer/Application/Actions/SetDefaultAddressAction.php`](app/Modules/Customer/Application/Actions/SetDefaultAddressAction.php:1) — relies on the PostgreSQL trigger + partial unique index to clear the previous default.

#### 4. Presentation Layer
- **Form Requests** (each exposes a `toDTO()` method for application-layer consumption):
  - [`app/Modules/Customer/Presentation/Http/Requests/UpdateCustomerProfileRequest.php`](app/Modules/Customer/Presentation/Http/Requests/UpdateCustomerProfileRequest.php:1)
  - [`app/Modules/Customer/Presentation/Http/Requests/CreateAddressRequest.php`](app/Modules/Customer/Presentation/Http/Requests/CreateAddressRequest.php:1)
  - [`app/Modules/Customer/Presentation/Http/Requests/UpdateAddressRequest.php`](app/Modules/Customer/Presentation/Http/Requests/UpdateAddressRequest.php:1)
- **API Resources**:
  - [`app/Modules/Customer/Presentation/Http/Resources/CustomerProfileResource.php`](app/Modules/Customer/Presentation/Http/Resources/CustomerProfileResource.php:1)
  - [`app/Modules/Customer/Presentation/Http/Resources/CustomerAddressResource.php`](app/Modules/Customer/Presentation/Http/Resources/CustomerAddressResource.php:1)
- **Controllers** (no constructor; method-level dependency injection, mirroring [`IdentityController`](app/Modules/Identity/Presentation/Http/Controllers/IdentityController.php:19)):
  - [`app/Modules/Customer/Presentation/Http/Controllers/CustomerProfileController.php`](app/Modules/Customer/Presentation/Http/Controllers/CustomerProfileController.php:13) — `show`, `update`.
  - [`app/Modules/Customer/Presentation/Http/Controllers/CustomerAddressController.php`](app/Modules/Customer/Presentation/Http/Controllers/CustomerAddressController.php:19) — `index`, `store`, `show`, `update`, `destroy`, `setDefault`. Catches [`AddressNotFoundException`](app/Modules/Customer/Domain/Exceptions/AddressNotFoundException.php:1) → HTTP 404 in `show`/`update`/`destroy`/`setDefault`.
- **Module Routes & Provider**:
  - [`app/Modules/Customer/Routes/api.php`](app/Modules/Customer/Routes/api.php:1) — registered under `auth:api` + `prefix('customer')`.
  - [`app/Modules/Customer/Providers/CustomerServiceProvider.php`](app/Modules/Customer/Providers/CustomerServiceProvider.php:1) — auto-discovered by [`ModuleRegistry`](app/Modules/ModuleRegistry.php:36).

#### 5. PostgreSQL-Backed Feature Test Suite
- [`tests/Feature/CustomerProfileRetrievalTest.php`](tests/Feature/CustomerProfileRetrievalTest.php:1)
- [`tests/Feature/CustomerProfileUpdateTest.php`](tests/Feature/CustomerProfileUpdateTest.php:1)
- [`tests/Feature/CustomerAddressCreationTest.php`](tests/Feature/CustomerAddressCreationTest.php:1)
- [`tests/Feature/CustomerAddressListingTest.php`](tests/Feature/CustomerAddressListingTest.php:1)
- [`tests/Feature/CustomerAddressRetrievalTest.php`](tests/Feature/CustomerAddressRetrievalTest.php:1)
- [`tests/Feature/CustomerAddressUpdateTest.php`](tests/Feature/CustomerAddressUpdateTest.php:1)
- [`tests/Feature/CustomerAddressDeletionTest.php`](tests/Feature/CustomerAddressDeletionTest.php:1)
- [`tests/Feature/CustomerDefaultAddressTest.php`](tests/Feature/CustomerDefaultAddressTest.php:1)

---

### B. Files Modified

- [`app/Modules/Identity/Infrastructure/Persistence/Models/User.php`](app/Modules/Identity/Infrastructure/Persistence/Models/User.php:119): Added `addresses(): HasMany` relationship method.
- [`app/Modules/ModuleRegistry.php`](app/Modules/ModuleRegistry.php:38): Added `'Customer'` to the canonical module list (positioned right after `'Identity'` since it depends on Identity's `User`).
- [`tests/Feature/ModuleRegistrationTest.php`](tests/Feature/ModuleRegistrationTest.php:1): Updated to expect `'Customer'` (singular) in the registered module names list.

---

## Customer Module Architecture Overview

```text
HTTP Request
    ↓
Authentication (auth:api Guard - Identity Token)
    ↓
Route Prefix: /api/v1/customer (auth:api)
    ↓
Form Request Validation (UpdateCustomerProfileRequest, CreateAddressRequest, UpdateAddressRequest)
    ↓
CustomerProfileController / CustomerAddressController
(method-level Action injection, controller try-catch for AddressNotFoundException → 404)
    ↓
Application Action (GetCustomerProfileAction, CreateAddressAction, etc.)
    ↓
Ownership-scoped queries (never load globally then authorize)
    ↓
PostgreSQL Baseline Tables (customer_profiles, addresses)
    ↓
API Resource (CustomerProfileResource / CustomerAddressResource)
```

---

## Customer Operations & Mechanics

| Endpoint | HTTP Method | Description | Auth | Response |
|---|---|---|---|---|
| `/api/v1/customer/profile` | `GET` | Retrieve authenticated user's profile (lazily creates default if missing). | Required | `200` profile data |
| `/api/v1/customer/profile` | `PUT` | Update authenticated user's profile (partial update, user_id immutable). | Required | `200` updated profile |
| `/api/v1/customer/addresses` | `GET` | List user's addresses (default address first). | Required | `200` addresses array |
| `/api/v1/customer/addresses` | `POST` | Create a new address (`user_id` server-controlled, country code uppercased). | Required | `201` new address |
| `/api/v1/customer/addresses/{address}` | `GET` | Retrieve a single address (ownership-scoped). | Required | `200` address / `404` |
| `/api/v1/customer/addresses/{address}` | `PUT` | Update an address (partial update, ownership-scoped). | Required | `200` / `404` |
| `/api/v1/customer/addresses/{address}` | `DELETE` | Hard-delete an address (ownership-scoped). | Required | `204` / `404` |
| `/api/v1/customer/addresses/{address}/default` | `PATCH` | Mark an address as default (PostgreSQL trigger clears previous default). | Required | `200` / `404` |

---

## API Routes Summary

```text
GET     /api/v1/customer/profile
PUT     /api/v1/customer/profile

GET     /api/v1/customer/addresses
POST    /api/v1/customer/addresses
GET     /api/v1/customer/addresses/{address}
PUT     /api/v1/customer/addresses/{address}
DELETE  /api/v1/customer/addresses/{address}
PATCH   /api/v1/customer/addresses/{address}/default
```

All routes are protected by the `auth:api` middleware.

---

## Key Architectural Decisions

### 1. Per-Action Pattern (mirrors Identity)
Each use case lives in its own Action class with a single `execute()` method. Controllers inject Actions directly into their method signatures (no constructor). This keeps controllers thin, makes each use case individually testable, and matches the conventions established by [`IdentityController`](app/Modules/Identity/Presentation/Http/Controllers/IdentityController.php:19) and [`LoginAction`](app/Modules/Identity/Application/Actions/LoginAction.php:15).

### 2. Controller-Level Exception Handling
Domain exceptions ([`AddressNotFoundException`](app/Modules/Customer/Domain/Exceptions/AddressNotFoundException.php:1)) are caught in the relevant controller methods and translated to HTTP responses locally. [`bootstrap/app.php`](bootstrap/app.php:21) contains no Customer exception render callbacks, preserving a clean separation between HTTP and application layers.

### 3. Ownership Scoping at the Query Level
Every read of a single address is performed with a combined `user_id` + `id` predicate in a single query. The application never loads an address globally and then checks ownership — this prevents accidental information disclosure via timing or response differences.

### 4. Server-Controlled `user_id`
Address creation/update DTOs do not expose `user_id`. The authenticated user is resolved from the request token via `$request->user()`, and the Action binds it directly to the Eloquent `create()`/`update()` call. Form Requests reject any client-supplied `user_id` field via validation.

### 5. PostgreSQL Trigger + Partial Unique Index for Default Address Enforcement
The `setDefault` flow simply sets `is_default = true`. The PostgreSQL trigger defined in [`database/sql/018_functions_triggers.sql`](database/sql/018_functions_triggers.sql:1) atomically clears the previous default in the same transaction. A partial unique index on `(user_id) WHERE is_default` guarantees the invariant "at most one default address per user" at the database level, independent of application logic.

### 6. Hard Delete for Addresses
The `addresses` table has no `deleted_at` column. Orders store an independent JSON snapshot of the shipping address (`shipping_address` JSONB on `orders`), so historical orders remain immutable even after a customer deletes or modifies an address.

### 7. ISO 3166-1 Alpha-2 Normalization
The `country_code` is normalized to uppercase in the Form Request `prepareForValidation()` hook before validation runs, ensuring consistent storage regardless of client casing.

---

## Verification Results

### 1. Customer Test Suite
```text
   PASS  Tests\Feature\CustomerProfileRetrievalTest (4 passed)
   PASS  Tests\Feature\CustomerProfileUpdateTest (5 passed)
   PASS  Tests\Feature\CustomerAddressCreationTest (6 passed)
   PASS  Tests\Feature\CustomerAddressListingTest (5 passed)
   PASS  Tests\Feature\CustomerAddressRetrievalTest (4 passed)
   PASS  Tests\Feature\CustomerAddressUpdateTest (5 passed)
   PASS  Tests\Feature\CustomerAddressDeletionTest (4 passed)
   PASS  Tests\Feature\CustomerDefaultAddressTest (6 passed)

  Tests:    43 passed (115 assertions)
  Duration: 22.80s
```

### 2. Full Application Test Suite
```text
  Tests:    130 passed (436 assertions)
  Duration: 52.81s
```

### 3. Database Integrity & Safety Compliance
- Zero Laravel migrations created for baseline tables (`customer_profiles`, `addresses`).
- [`database/sql/`](database/sql/003_customers_addresses.sql:1) baseline schema preserved without alteration.
- All tests executed against PostgreSQL `testing` database using the `DatabaseTransactions` trait.
- Ownership scoping enforced at the query level (no global load + authorize pattern).
- PostgreSQL default-address trigger verified by `CustomerDefaultAddressTest` (at most one default per user invariant).
- Customer domain exceptions handled locally in controllers; no global render handlers in [`bootstrap/app.php`](bootstrap/app.php:21).