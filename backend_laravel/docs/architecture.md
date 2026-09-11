# Architecture — Commerce Single Vendor

## 1. Modular Monolith Overview

This project follows a **Modular Monolith** architecture.

It is deployed as a single Laravel application (one process, one database) but its
internal structure is organized as independent, self-contained modules — similar to how
microservices would be separated, but without the operational overhead.

**Why not microservices?**  
Single-vendor e-commerce at this scale benefits from strong transactional consistency
(PostgreSQL), simpler deployment, and easier developer onboarding. Modular monolith
provides most of the organizational benefits of microservices without their complexity.

**Why not plain Laravel MVC?**  
Standard Laravel MVC (`app/Http/Controllers`, `app/Models`) becomes unmaintainable
at domain scale. This architecture enforces clear module boundaries and prevents
business logic from leaking into HTTP controllers.

---

## 2. Directory Structure

```
app/
├── Modules/                 — Business domain modules
│   ├── Identity/            — Authentication, sessions, password reset
│   ├── Customers/           — Customer profiles, addresses
│   ├── Catalog/             — Categories, products, attributes, variants
│   ├── Inventory/           — Stock, reservations
│   ├── Cart/                — Shopping cart
│   ├── Orders/              — Order lifecycle, events
│   ├── Shipping/            — Shipping methods, shipments
│   ├── Payments/            — Payment intents, installments, refunds
│   ├── Promotions/          — Coupons, discounts
│   ├── Reviews/             — Product reviews
│   ├── Notifications/       — Notification log
│   └── Audit/               — Audit trail
│
└── Shared/                  — Truly cross-cutting concerns only
    ├── Domain/
    │   ├── Contracts/       — DomainEventInterface, etc.
    │   ├── ValueObjects/    — Shared VOs (Money, etc.)
    │   └── Events/          — Shared event base classes
    ├── Application/
    │   ├── DTOs/            — PaginatedResult, etc.
    │   └── Contracts/       — CommandBusInterface, QueryBusInterface
    └── Infrastructure/
        ├── Database/        — DB helpers, connection utilities
        ├── Cache/           — Cache abstractions
        └── Queue/           — Queue utilities

database/
└── sql/                     — Raw PostgreSQL SQL migration files (source of truth)

routes/
└── api.php                  — Minimal entry point; module routes loaded by providers
```

---

## 3. Module Internal Structure

Each module follows the same four-layer structure:

```
{Module}/
├── Domain/               — Business rules. No Laravel, no Eloquent.
│   ├── Entities/         — Rich domain objects with identity
│   ├── ValueObjects/     — Immutable, equality-by-value objects
│   ├── Contracts/        — Repository interfaces, service contracts
│   ├── Events/           — Domain events (implement DomainEventInterface)
│   └── Exceptions/       — Domain-specific exceptions
│
├── Application/          — Use cases. Orchestrates domain objects.
│   ├── Commands/         — Write operations (CreateOrder, PlaceOrder, etc.)
│   ├── Queries/          — Read operations (GetProductList, etc.)
│   ├── DTOs/             — Data Transfer Objects for I/O
│   ├── Services/         — Application services (stateless orchestrators)
│   └── Actions/          — Single-responsibility action classes
│
├── Infrastructure/       — Laravel/Eloquent implementations of domain contracts.
│   ├── Persistence/      — Eloquent models (infrastructure detail, not domain)
│   ├── Repositories/     — Implements Domain/Contracts interfaces
│   └── Providers/        — Module ServiceProvider lives here
│
└── Presentation/         — HTTP layer. Knows about HTTP, not domain internals.
    ├── Http/
    │   ├── Controllers/  — Thin controllers; delegate to Application layer
    │   ├── Requests/     — FormRequest validation
    │   └── Resources/    — API Resources (JSON transformation)
    └── Routes/
        └── api.php       — Module-owned route file
```

---

## 4. Layer Dependency Rules

These rules are **enforced by convention** and must be respected by all developers:

| Layer | Can depend on | Cannot depend on |
|---|---|---|
| **Domain** | Nothing external. Pure PHP. | Laravel, Eloquent, HTTP, Infrastructure |
| **Application** | Domain layer, Shared contracts | Eloquent directly, HTTP, Infrastructure |
| **Infrastructure** | Domain contracts, Application, Laravel/Eloquent | Other modules' Infrastructure |
| **Presentation** | Application layer, Laravel HTTP | Domain directly (use DTOs), other modules' internals |

**Cross-module access:**
- Module A **must not** import from `App\Modules\B\Infrastructure\...`
- Module A **may** reference Shared contracts: `App\Shared\...`
- Cross-module communication happens via Domain Events or Application contracts

---

## 5. Module Registration

The registration flow on every request:

```
bootstrap/providers.php
    └── ModuleServiceProvider (App\Providers\ModuleServiceProvider)
            └── ModuleRegistry::discover()
                    └── For each module in MODULES list:
                            ↳ If {Module}ServiceProvider class exists → $app->register()
                                    └── {Module}ServiceProvider::boot()
                                            └── loadRoutesFrom(Presentation/Routes/api.php)
```

**To add a new module:**
1. Add the module name to `ModuleRegistry::MODULES` (in order).
2. Create the directory: `app/Modules/{YourModule}/`
3. Create the provider: `app/Modules/{YourModule}/Providers/{YourModule}ServiceProvider.php`
4. Create the route file: `app/Modules/{YourModule}/Presentation/Routes/api.php`
5. Done — the module is automatically discovered and registered.

No changes to `bootstrap/providers.php` are needed after the initial setup.

---

## 6. Module Routes

All module routes are loaded by their module's `ServiceProvider::boot()` method.

The provider applies:
- `middleware('api')` — standard Laravel API middleware group
- `prefix('api/v1')` — version prefix

Example from `IdentityServiceProvider`:
```php
Route::middleware('api')
    ->prefix('api/v1')
    ->group(__DIR__ . '/../Presentation/Routes/api.php');
```

The module's route file then only defines routes relative to `/api/v1`:
```php
Route::get('/identity/ping', ...)->name('identity.ping');
// Accessible at: GET /api/v1/identity/ping
```

**Version upgrade strategy:** When `v2` is needed, add a second `withRouting()` entry in
`bootstrap/app.php` pointing to a `routes/api_v2.php`, and create `v2` route files in
each module. V1 routes continue to work unchanged.

---

## 7. PostgreSQL Schema

The database schema is defined entirely in raw SQL files:

```
database/sql/
├── 001_extensions_enums.sql
├── 002_identity_auth.sql
├── ...
└── 021_seed_data.sql
```

**These files are the source of truth. Do not rewrite them as Laravel migrations.**

The schema uses PostgreSQL-specific features:
- Custom ENUM types
- Partitioned tables (range/list)
- PL/pgSQL triggers and functions
- Materialized views

**To apply the schema to a fresh database:**
```powershell
# Windows
.\database\sql\run-schema.ps1 -Database commerce -Username postgres
```
```bash
# Linux/macOS
bash database/sql/run-schema.sh
```

Laravel's `database/migrations/` directory is reserved for **future application-managed
schema changes only**, e.g., adding a column after initial deployment.

---

## 8. Creating a New Module (Step-by-Step)

```bash
# 1. Create directory structure
$module = "MyModule"
$base = "app/Modules/$module"
New-Item -ItemType Directory "$base/Domain/{Entities,ValueObjects,Contracts,Events,Exceptions}"
New-Item -ItemType Directory "$base/Application/{Commands,Queries,DTOs,Services,Actions}"
New-Item -ItemType Directory "$base/Infrastructure/{Persistence,Repositories,Providers}"
New-Item -ItemType Directory "$base/Presentation/{Http/{Controllers,Requests,Resources},Routes}"
```

```php
// 2. Create: app/Modules/MyModule/Providers/MyModuleServiceProvider.php
// Copy from app/Modules/Identity/Providers/IdentityServiceProvider.php
// Update namespace and class name.

// 3. Create: app/Modules/MyModule/Presentation/Routes/api.php
// Add module routes here.
```

```php
// 4. Add to ModuleRegistry::MODULES array:
// app/Modules/ModuleRegistry.php
public const MODULES = [
    // ... existing modules ...
    'MyModule',   // add here
];
```

That's all. The module is automatically registered on next request.
