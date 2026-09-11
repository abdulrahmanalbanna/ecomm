# Task 05 — Catalog, Categories, Products, Attributes & Variants Summary

The **Catalog module** has been fully implemented in accordance with the project's **Modular Monolith** architecture and using the raw PostgreSQL baseline (`database/sql/`) as the authoritative database design.

---

## Deliverables Summary

### A. Files Created

#### 1. Shared Database Casts
- `app/Shared/Infrastructure/Database/Casts/PostgresTextArray.php`: Custom cast handling PostgreSQL native `TEXT[]` array formatting (`{item1,item2}`).
- `app/Shared/Infrastructure/Database/Casts/JsonObjectCast.php`: Custom cast handling PostgreSQL `JSONB` object formatting (`{}`).

#### 2. Infrastructure & Persistence Models
- `app/Modules/Catalog/Infrastructure/Persistence/Models/Category.php`: Hierarchical category model leveraging PostgreSQL `LTREE` (`path`, `depth` auto-maintained by DB triggers).
- `app/Modules/Catalog/Infrastructure/Persistence/Models/Product.php`: Catalog product model with UUID `public_id`, customer-visibility scoping, and full-text search (`search_vector`).
- `app/Modules/Catalog/Infrastructure/Persistence/Models/AttributeDefinition.php`: Global attribute definition model (`text`, `number`, `boolean`, `select`, `multiselect`).
- `app/Modules/Catalog/Infrastructure/Persistence/Models/AttributeOption.php`: Allowed options for select and multiselect attributes.
- `app/Modules/Catalog/Infrastructure/Persistence/Models/ProductAttributeDefinition.php`: Product attribute configuration with explicit composite primary key `[product_id, attribute_id]` handling (`setKeysForSaveQuery`).
- `app/Modules/Catalog/Infrastructure/Persistence/Models/ProductVariant.php`: Purchasable SKUs with product-scoped JSONB attribute validation.

#### 3. Application Services
- `app/Modules/Catalog/Application/Services/CategoryService.php`: Category tree retrieval, active filtering, ancestor/subtree resolution, and restricted deletion.
- `app/Modules/Catalog/Application/Services/ProductService.php`: Customer/admin product listing, full-text search, deterministic public lookup by `public_id`/`slug`, publishing/archiving, and DB-compliant soft deletion.
- `app/Modules/Catalog/Application/Services/AttributeService.php`: Definition and option management, and product attribute assignments.
- `app/Modules/Catalog/Application/Services/VariantService.php`: Variant creation, update, and soft deletion.

#### 4. Presentation Layer
- **API Resources**:
  - `app/Modules/Catalog/Presentation/Http/Resources/CategoryResource.php`
  - `app/Modules/Catalog/Presentation/Http/Resources/AttributeDefinitionResource.php`
  - `app/Modules/Catalog/Presentation/Http/Resources/AttributeOptionResource.php`
  - `app/Modules/Catalog/Presentation/Http/Resources/ProductPublicResource.php`
  - `app/Modules/Catalog/Presentation/Http/Resources/ProductAdminResource.php`
  - `app/Modules/Catalog/Presentation/Http/Resources/ProductVariantPublicResource.php` (strictly excludes internal `cost_price`)
  - `app/Modules/Catalog/Presentation/Http/Resources/ProductVariantAdminResource.php`
- **Form Requests**:
  - `app/Modules/Catalog/Presentation/Http/Requests/CategoryRequest.php`
  - `app/Modules/Catalog/Presentation/Http/Requests/ProductRequest.php`
  - `app/Modules/Catalog/Presentation/Http/Requests/AttributeDefinitionRequest.php`
  - `app/Modules/Catalog/Presentation/Http/Requests/AttributeOptionRequest.php`
  - `app/Modules/Catalog/Presentation/Http/Requests/ProductAttributeRequest.php`
  - `app/Modules/Catalog/Presentation/Http/Requests/ProductVariantRequest.php`
- **Controllers**:
  - `app/Modules/Catalog/Presentation/Http/Controllers/CategoryPublicController.php`
  - `app/Modules/Catalog/Presentation/Http/Controllers/CategoryAdminController.php`
  - `app/Modules/Catalog/Presentation/Http/Controllers/ProductPublicController.php`
  - `app/Modules/Catalog/Presentation/Http/Controllers/ProductAdminController.php`
  - `app/Modules/Catalog/Presentation/Http/Controllers/AttributeAdminController.php`
  - `app/Modules/Catalog/Presentation/Http/Controllers/VariantAdminController.php`
- **Module Routes & Provider**:
  - `app/Modules/Catalog/Providers/CatalogServiceProvider.php`
  - `app/Modules/Catalog/Presentation/Routes/api.php`

#### 5. PostgreSQL-Backed Feature Test Suite
- `tests/Feature/CatalogCategoryTest.php`
- `tests/Feature/CatalogProductTest.php`
- `tests/Feature/CatalogAttributeTest.php`
- `tests/Feature/CatalogVariantTest.php`
- `tests/Feature/CatalogAuthorizationTest.php`
- `tests/Feature/CatalogQueryIndexTest.php`

---

### B. Catalog Architecture Overview

```text
HTTP Request
    ↓
Authentication (auth:api Guard)
    ↓
Permission Middleware (permission:code)
    ↓
Catalog Controller
    ↓
Application Service (CategoryService / ProductService / AttributeService / VariantService)
    ↓
Eloquent / PostgreSQL (Raw baseline, ltree, DB Triggers & Deferred Constraints)
    ↓
API Resource (ProductPublicResource / ProductAdminResource / CategoryResource)
```

---

### C. Category Architecture

```text
Electronics (path: 'electronics', depth: 0)
└── Phones (path: 'electronics.phones', depth: 1)
    └── Smartphones (path: 'electronics.phones.smartphones', depth: 2)
```

- **Hierarchy Maintenance**: Managed exclusively by PostgreSQL triggers (`trg_categories_path_before`, `trg_categories_path_after`). Direct SQL mutations of `path` and `depth` are blocked by `trg_categories_guard_derived_cols`.
- **Query Patterns**:
  - Ancestors: PostgreSQL `path @> ?::ltree` operator.
  - Subtree: PostgreSQL `path <@ ?::ltree` operator.
  - Category deletion: Protected by `ON DELETE RESTRICT` foreign key constraint.

---

### D. Product & Variant Architecture

```text
Product (category_id, public_id UUID)
├── ProductAttributeDefinitions (product_id, attribute_id, is_required)
│     ↓
│   AttributeDefinitions (name, type, options)
└── ProductVariants (sku, price, attributes JSONB)
```

- Every product belongs to **exactly one** category.
- `public_id` UUID provides deterministic customer-facing product identification.
- `product_attribute_definitions` composite PK `[product_id, attribute_id]` determines allowed/required attributes for a product's variants.
- Database trigger `trg_product_variants_02_attribute_validation` validates JSONB variant attributes against configured product attribute definitions and active options.

---

### E. Product Lifecycle

```text
Draft
  ↓ (publish: requires at least 1 active sellable variant with price > 0)
Published
  ↓ (archive: transitions status to 'archived')
Archived
  ↓ (delete: sets status to 'archived', is_active = false, then applies soft-delete timestamp)
Soft-Deleted (deleted_at IS NOT NULL)
```

- **Sellability Guard**: Deferred constraint triggers (`trg_products_sellability_deferred` and `trg_variants_sellability_deferred`) enforce that a published active product must have at least one active, non-deleted sellable variant (`price > 0`) at transaction commit.
- **Soft Deletion Guard**: PostgreSQL constraints (`products_deleted_published_check` and `products_deleted_state_check`) restrict soft deletion to inactive, non-published products.

---

### F. Authorization Matrix

| Permission | Route(s) | Description |
|---|---|---|
| `categories.view` | `GET /api/v1/catalog/admin/categories`, `GET /api/v1/catalog/admin/categories/{id}` | Read category management tree |
| `categories.manage` | `POST /PUT /DELETE /api/v1/catalog/admin/categories` | Create, update, and delete categories |
| `products.view` | `GET /api/v1/catalog/admin/products`, `GET /api/v1/catalog/admin/attributes`, `GET /api/v1/catalog/admin/products/{id}/variants` | View draft/archived products and attributes |
| `products.create` | `POST /api/v1/catalog/admin/products` | Create draft products |
| `products.edit` | `PUT /api/v1/catalog/admin/products/{id}`, Attributes & Variant CRUD | Edit products, variants, and attribute configuration |
| `products.publish` | `POST /api/v1/catalog/admin/products/{id}/publish`, `POST /.../archive` | Publish or archive products |
| `products.delete` | `DELETE /api/v1/catalog/admin/products/{id}` | Soft-delete products |

---

### G. API Routes Summary

#### Public Customer Catalog Routes
- `GET /api/v1/catalog/categories`: Public category tree (`is_active = true`).
- `GET /api/v1/catalog/categories/{slug}`: Category detail by slug.
- `GET /api/v1/catalog/products`: Paginated public catalog (supports `category_id`, `category_slug`, `featured`, `search`, `sort`).
- `GET /api/v1/catalog/products/{publicId}`: Customer product detail by `public_id` or `slug` (excludes `cost_price`).

#### Protected Administration Routes (`auth:api` & `permission:...`)
- Category Management: `GET|POST|PUT|DELETE /api/v1/catalog/admin/categories`
- Product Management: `GET|POST|PUT|DELETE /api/v1/catalog/admin/products`
- Product Status: `POST /api/v1/catalog/admin/products/{id}/publish`, `POST /api/v1/catalog/admin/products/{id}/archive`
- Attribute Management: `GET|POST|PUT /api/v1/catalog/admin/attributes`, `POST|PUT /api/v1/catalog/admin/attributes/{id}/options`
- Product Attributes: `POST|DELETE /api/v1/catalog/admin/products/{productId}/attributes`
- Product Variants: `GET|POST|PUT|DELETE /api/v1/catalog/admin/products/{productId}/variants`

---

### H. Query and Index Alignment

| Query Purpose | Tables Involved | Filter Columns | Relevant Indexes Used |
|---|---|---|---|
| Category Children | `categories` | `parent_id` | `idx_categories_parent_id` |
| Active Category Tree | `categories` | `parent_id`, `is_active`, `sort_order` | `idx_categories_active_sorted` |
| Subtree Resolution | `categories` | `path` | `idx_categories_path_gist` (GiST) |
| Products by Category | `products` | `category_id`, `is_active`, `status`, `deleted_at` | `idx_products_category_cover` / `idx_products_category_id` |
| Featured Products | `products` | `is_featured`, `is_active`, `status`, `deleted_at` | `idx_products_featured` (Partial Index) |
| Product FTS Search | `products` | `search_vector` | `idx_products_fts` (GIN Index) |
| Variant SKU Lookup | `product_variants` | `sku` | `product_variants_sku_key` (UNIQUE Index) |
| Active Variants Scan | `product_variants` | `product_id`, `is_active`, `deleted_at` | `idx_variants_active_by_product` |

---

### I. Verification Results

#### 1. Catalog Test Suite
```text
   PASS  Tests\Feature\CatalogAttributeTest (2 passed)
   PASS  Tests\Feature\CatalogAuthorizationTest (3 passed)
   PASS  Tests\Feature\CatalogCategoryTest (5 passed)
   PASS  Tests\Feature\CatalogProductTest (4 passed)
   PASS  Tests\Feature\CatalogQueryIndexTest (1 passed)
   PASS  Tests\Feature\CatalogVariantTest (2 passed)

  Tests:    17 passed (63 assertions)
  Duration: 14.24s
```

#### 2. Full Feature Test Suite
```text
  Tests:    47 passed (168 assertions)
  Duration: 25.29s
```

#### 3. Database & Application Safety
- No Laravel migrations recreated baseline tables.
- `database/sql/` remained the authoritative schema baseline.
- All tests executed against PostgreSQL `testing` database without modifying development data.
