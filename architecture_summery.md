# Repository Architecture Map

## Audit basis

This map is derived from the source tree, manifests, route files, SQL schema, model/service implementations, environment templates, and repository-wide grep results. The archive root contains two applications: `backend/` and `frondend/` (the directory is spelled `frondend` in the repository). There is no exact `FutureHome` symbol in the repository; the current equivalent is the Next.js homepage at `frondend/src/features/home/components/HomePage.tsx` and its section components.

## Repository tree

```text
.
├── backend/                         Laravel 12 modular monolith
│   ├── app/
│   │   ├── Modules/
│   │   │   ├── Settings/            public business settings
│   │   │   ├── Catalog/             categories, products, variants
│   │   │   ├── Identity/            auth, roles, permissions, middleware
│   │   │   ├── Customer/
│   │   │   ├── Cart/
│   │   │   ├── Inventory/
│   │   │   ├── Orders/
│   │   │   ├── Shipping/
│   │   │   ├── Payments/
│   │   │   ├── Promotions/
│   │   │   ├── Reviews/
│   │   │   ├── Notifications/
│   │   │   └── Audit/
│   │   ├── Providers/                ModuleServiceProvider
│   │   └── Shared/
│   ├── bootstrap/                    app and provider registration
│   ├── config/                       database, cors, auth, cache, etc.
│   ├── database/
│   │   ├── migrations/               only Laravel framework migrations
│   │   ├── factories/
│   │   ├── seeders/
│   │   └── sql/                      source-of-truth PostgreSQL schema/seeds
│   ├── routes/                       health, web, console, API entrypoint
│   ├── tests/                        Feature, Unit, Support
│   ├── compose.yaml                  Laravel Sail-style app/PostgreSQL/Redis/Mailpit
│   ├── composer.json
│   └── .env.example
└── frondend/                         Next.js storefront
    ├── src/app/                      App Router, locale routes
    ├── src/features/home/            homepage/FutureHome equivalent
    │   ├── components/
    │   ├── api.ts
    │   ├── catalog.ts
    │   ├── use-catalog.ts
    │   └── use-settings.ts
    ├── src/features/products/
    ├── src/lib/api/client.ts         fetch wrapper and Laravel envelopes
    ├── src/stores/cart.ts             Zustand persisted cart
    ├── src/messages/                  ar, en, fr translations
    ├── public/                       branding and local image assets
    ├── next.config.ts
    ├── package.json
    └── .env.example / .env.local
```

## Frontend

### Framework and version

`frondend/package.json` declares Next.js `^16.0.0`, React `^19.0.0`, TypeScript `^5.7.2`, and `next-intl ^4.3.0`. The installed build reported Next.js `16.3.4`. Tailwind is configured through PostCSS and the project uses `lucide-react`, `framer-motion`, `@tanstack/react-query` as a dependency, and Zustand `^5.0.2`.

### Router and exact homepage location

The project uses the **Next.js App Router**, not the Pages Router. Evidence is the `src/app/` tree and the absence of `src/pages/`:

- `frondend/src/app/page.tsx` is the root redirect/entry.
- `frondend/src/app/[locale]/layout.tsx` supplies locale layout.
- `frondend/src/app/[locale]/(store)/page.tsx` renders `HomePage`.
- `frondend/src/features/home/components/HomePage.tsx` is the current FutureHome equivalent.
- `HomePage` composes `Header`, `Hero`, `CategoryTiles`, one `ProductRail` per category, `ProjectsGrid`, `TabsSection`, `BrandsMarquee`, `WhyUs`, `CtaBand`, and Chrome/cart widgets.

`FutureHome` does not occur as an identifier, filename, or component name anywhere in the repository.

### HTTP client and endpoint adapter

The frontend uses a small native `fetch` wrapper in `frondend/src/lib/api/client.ts`; it is not Axios. The base URL is `NEXT_PUBLIC_API_URL`, defaulted in `.env.example` to `http://localhost:8000/api`. The wrapper exposes `apiClient.get/post/put/delete` and `extractData()` for Laravel `{ data, message?, meta? }` responses.

Homepage API functions are in `frondend/src/features/home/api.ts`:

| Frontend function | Request | Backend response shape consumed |
|---|---|---|
| `getPublicSettings()` | `GET /v1/settings` | `{ data: settings }` |
| `getCategories()` | `GET /v1/catalog/categories` | `{ data: CategoryResource[] }` |
| `getProducts()` | `GET /v1/catalog/products` with query filters/pagination | `{ data: ProductPublicResource[], meta: pagination }` |
| `getFeaturedProducts()` | `GET /v1/catalog/products?featured=1` | defined but not used by current homepage path |
| `getBanners()` | `GET /banners` | TODO in source; no matching backend public route found |

`use-catalog.ts` coalesces category/product requests, adapts Laravel resources to frontend domain types, and builds `byId` and `byCategory` maps. The cart uses Laravel `public_id` values as line IDs.

### State management

The cart is persisted through Zustand’s `persist` middleware in `frondend/src/stores/cart.ts` under `tagahayeez-cart-v2`. Homepage catalog/settings state is managed by lightweight module-level request caches plus React hooks (`useCatalog` and `useShopSettings`), not React Query or a context provider. `@tanstack/react-query` is installed but no homepage usage was found in the audited files.

### Mock and duplicated frontend logic

The following static data remains in `frondend/src/features/home/catalog.ts`:

- complete offline product catalog and category data;
- local category image/tint mappings;
- static projects and brands arrays;
- `newArrivals` and `bestSellers` ID arrays;
- cities and presentation copy.

The current API-only catalog integration no longer substitutes `staticCategories` or `staticProducts` inside the homepage category/product path. However, the cart still imports `staticProducts` to resolve stale persisted cart lines that are not present in the live catalog. `use-settings.ts` has an empty-shaped fallback for hydration/offline rendering, while `HomeSections.tsx` still has presentation-only static `projects` and `brands` because no corresponding public Laravel resources were found. `api.ts` retains local image/tint fallbacks for missing media, which are presentation fallbacks rather than backend data.

## Backend

### Laravel version and architecture

`backend/composer.json` declares PHP `^8.2` and Laravel Framework `^12.0`. The description identifies the application as a modular monolith. `bootstrap/providers.php` registers `AppServiceProvider` and `ModuleServiceProvider`. `ModuleServiceProvider` delegates discovery to `App\Modules\ModuleRegistry`, whose explicit module list is:

`Settings`, `Identity`, `Customer`, `Catalog`, `Inventory`, `Cart`, `Orders`, `Shipping`, `Payments`, `Promotions`, `Reviews`, `Notifications`, and `Audit`.

Each module with a provider loads its own route file under `api` middleware and the `api/v1` prefix. Module namespaces follow `App\Modules\{Module}\{Application|Domain|Infrastructure|Presentation}`.

### Route prefixes and API conventions

The global `backend/routes/api.php` contains only `GET /api/health`. Module providers register routes under `Route::middleware('api')->prefix('api/v1')`.

Public catalog routes in `backend/app/Modules/Catalog/Presentation/Routes/api.php`:

| Route | Controller | Auth |
|---|---|---|
| `GET /api/v1/catalog/categories` | `CategoryPublicController@index` | public |
| `GET /api/v1/catalog/categories/{slug}` | `CategoryPublicController@show` | public |
| `GET /api/v1/catalog/products` | `ProductPublicController@index` | public |
| `GET /api/v1/catalog/products/{publicId}` | `ProductPublicController@show` | public |

Settings routes in `backend/app/Modules/Settings/Presentation/Routes/api.php`:

| Route | Controller | Auth |
|---|---|---|
| `GET /api/v1/settings` | `SettingsPublicController@index` | public |
| `GET /api/v1/settings/{key}` | `SettingsPublicController@show` | public; key restricted by service allowlist |

Customer and administrative routes use `auth:api`; administrative routes additionally use `permission:{code}`. Examples include `auth:api` plus `self.orders.view`, `orders.view`, `orders.process`, `payments.view`, and similar permission middleware. Webhook routes are public but use gateway signature verification inside application actions.

### Controllers, services, models, resources

The public settings chain is:

```text
SettingsPublicController
  -> SettingsService
      -> BusinessSetting Eloquent model
  -> PublicSettingsResource
```

The public catalog chains are:

```text
CategoryPublicController -> CategoryService -> Category model -> CategoryResource
ProductPublicController  -> ProductService  -> Product model and relations -> ProductPublicResource
ProductPublicResource    -> CategoryResource, ProductVariantPublicResource,
                            AttributeDefinitionResource when relations are loaded
```

Models are under `Infrastructure/Persistence/Models`; HTTP resources/controllers/routes are under `Presentation/Http` and `Presentation/Routes`; orchestration/filtering is under `Application/Services` and actions.

No `*Policy.php` files were found in the backend tree. Authorization is implemented through `auth:api`, Identity middleware aliases registered in `bootstrap/app.php`, permission middleware, and application-layer ownership checks rather than Laravel policy classes.

## Infrastructure

### Compose services

`backend/compose.yaml` is a Laravel Sail-style Compose file with:

- `laravel.test`: app image `sail-8.4/app`, exposed through the configured app port (the file uses environment-driven port mapping);
- `pgsql`: `postgres:16-alpine`, with `pg_stat_statements` preloaded and a host port controlled by `FORWARD_DB_PORT`;
- `redis`: `redis:alpine`, controlled by `FORWARD_REDIS_PORT`;
- `mailpit`: `axllent/mailpit:latest`, with SMTP/dashboard ports controlled by `FORWARD_MAILPIT_PORT` and `FORWARD_MAILPIT_DASHBOARD_PORT`.

No standalone frontend Dockerfile or frontend service was found; the Next.js app is run from `frondend/` with npm.

### Environment variables

Backend `.env.example` defines application variables (`APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_URL`, locale/debug/logging), PostgreSQL variables (`DB_CONNECTION=pgsql`, `DB_HOST=pgsql`, `DB_PORT=5432`, `DB_DATABASE=ecommerce`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SCHEMA=public`, `DB_SSLMODE=prefer`, `FORWARD_DB_PORT=5433`), session/cache/queue/filesystem variables, Redis variables, Mail variables, payment gateway secrets/configuration, AWS storage variables, and `VITE_APP_NAME`.

Frontend `.env.example` defines:

- `NEXT_PUBLIC_API_URL=http://localhost:8000/api`;
- `NEXT_PUBLIC_SITE_URL=http://localhost:3000`.

The frontend environment file explicitly states that Laravel secrets must not be placed in `NEXT_PUBLIC_*` variables.

### CORS

`backend/config/cors.php` allows `api/*` and `sanctum/csrf-cookie`, all methods/headers, localhost and `127.0.0.1` on port 3000, plus comma-separated `CORS_ALLOWED_ORIGINS`, `FRONTEND_URL`, and `NEXT_PUBLIC_SITE_URL`. Credentials are disabled (`supports_credentials=false`).

### Local development commands

Frontend README:

```bash
cd frondend
npm install
cp .env.example .env.local
npm run dev
```

Backend Composer defines a `dev` script that concurrently runs `php artisan serve`, queue listener, Pail logs, and Vite. The database README documents:

```bash
cd backend
docker compose up -d
bash database/sql/run-schema.sh
bash database/sql/verify-schema.sh
```

The SQL README explicitly states that the PostgreSQL baseline SQL is authoritative and warns not to run `php artisan migrate` against that PostgreSQL database. Existing Laravel migrations are only the framework-generated users/cache/jobs set and conflict with the baseline schema.

## Data layer: `business_settings`

### Exact table definition

`backend/database/sql/002_identity_auth.sql` defines:

```sql
CREATE TABLE business_settings (
    id          BIGSERIAL PRIMARY KEY,
    key         VARCHAR(100) UNIQUE NOT NULL,
    value       TEXT,
    type        VARCHAR(30) NOT NULL DEFAULT 'string',
    is_public   BOOLEAN NOT NULL DEFAULT FALSE,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    description TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

The type constraint allows `string`, `number`, `boolean`, `json`, or `array`; the JSON payload is therefore stored in the **TEXT** `value` column, not PostgreSQL JSONB. Keys are lowercase dot/snake-case, for example `store.name` and `homepage.header`. A partial index exists on active keys.

### Model and repository/service behavior

`BusinessSetting` explicitly maps to `business_settings`, uses `key`, `value`, `type`, `is_public`, `is_active`, and `description`, and exposes a `public()` scope that filters public and active records. No repository class was found; `SettingsService` queries the model directly with Eloquent and caches the resulting public map using `Cache::remember('settings.public.v1', 300, ...)`.

`SettingsService` reads raw `value` text and casts it according to `type`:

- `number`: numeric scalar;
- `boolean`: recognized truthy strings;
- `json`/`array`: `json_decode(..., JSON_THROW_ON_ERROR)` with `[]` on invalid/empty JSON;
- otherwise: string.

It whitelists `store.*`, `system.maintenance_mode`, `shipping.free_shipping_threshold`, `features`, `stats`, `ticker_items`, `homepage.header`, and `homepage.footer` before shaping the public response.

### Existing homepage records

`backend/database/sql/021_seed_data.sql` upserts these homepage-related public rows:

- `features`: JSON array of four feature objects with `id`, `title`, `desc`, `icon`, `is_active`;
- `stats`: JSON array of statistic objects with `value`, `suffix`, `label`, `is_active`;
- `ticker_items`: JSON array of `{text, is_active}` objects;
- `homepage.header`: JSON object containing bilingual store names/taglines, logo paths, phone/WhatsApp links, search and navigation copy;
- `homepage.footer`: JSON object containing bilingual descriptions/addresses, hours, links, categories, newsletter copy, payment methods, copyright, and tax info.

The service flattens `ticker_items` to active strings and returns the normalized envelope:

```json
{
  "data": {
    "store": {},
    "header": {},
    "footer": {},
    "features": [],
    "stats": [],
    "ticker_items": [],
    "free_shipping_threshold": 0,
    "currency": "SAR",
    "maintenance_mode": false
  }
}
```

`PublicSettingsResource` is a thin `JsonResource`; the controller returns its response directly to avoid a `data.data` nesting bug.

## Grep findings

| Search | Finding |
|---|---|
| `FutureHome` | No matches. The homepage is named `HomePage` under `features/home/components`. |
| `business_settings` | SQL DDL/seed/verification, `BusinessSetting` model, `SettingsService`, and settings documentation/tests. |
| `features`, `stats`, `ticker_items` | Seeded in `021_seed_data.sql`, whitelisted/cast by `SettingsService`, typed/adapted by frontend API/settings hooks, rendered in `WhyUs`/`Header` and related homepage sections. |
| homepage API calls | Frontend `api.ts` calls `/v1/settings`, `/v1/catalog/categories`, and `/v1/catalog/products`; backend modules provide `/api/v1/...` through provider prefixing. |
| mock/dummy/fallback | Static catalog arrays remain in `catalog.ts`; cart has a stale-line static lookup fallback; settings has an empty fallback object; static projects/brands remain in `HomeSections`; API adapters have local image/tint fallbacks. No backend `FutureHome` endpoint exists. |
| duplicated business logic | Homepage data is split between backend `business_settings` JSON and frontend `catalog.ts`/translation/static arrays. Product/category API normalization is centralized in `api.ts`, but cart independently resolves stale products from the static catalog. Header/footer also retain localized UI fallback arrays when backend content is absent. |
| banners | A `banners` SQL table exists, but `getBanners()` is marked TODO and no public banner controller/route/model was found in the current PHP module tree. |

## Derived conventions and risks

1. **Route prefix is provider-owned:** module route files use relative paths; the effective public prefix is `/api/v1`, not `/v1` by itself. The frontend correctly supplies `/api` as its base URL and `/v1/...` as the path.
2. **Public settings are allowlisted twice conceptually:** SQL flags rows with `is_public/is_active`, and `SettingsService::PUBLIC_KEYS` restricts which keys can be returned.
3. **TEXT-vs-JSONB matters:** application casting is required because `business_settings.value` is TEXT. Any code treating the Eloquent value as an already-decoded array risks a 500 unless it respects the model/service casting path.
4. **Schema authority is SQL:** the Laravel migration directory is not the complete application schema. Use `database/sql/run-schema.sh` and its verification script for PostgreSQL setup.
5. **No policy classes:** authorization changes should follow existing `auth:api` plus permission middleware/application ownership conventions unless the architecture is intentionally changed.
6. **Homepage coverage is incomplete on the backend:** categories, products, settings/features/stats/ticker/header/footer are available; projects, brands, and banners do not have equivalent public endpoints in the inspected code.
