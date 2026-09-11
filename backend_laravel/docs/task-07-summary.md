# Task 07 — Shopping Cart Module Summary

The **Shopping Cart Module** has been fully implemented in accordance with the project's **Modular Monolith** architecture and using the raw PostgreSQL baseline (`database/sql/007_cart.sql`, `005_products_attributes_variants.sql`, `006_inventory.sql`) as the authoritative database design.

---

## Summary of Deliverables

### A. Files Created

#### 1. Domain Layer Exceptions
- `app/Modules/Cart/Domain/Exceptions/CartDomainException.php`: Base domain exception class for the Cart module.
- `app/Modules/Cart/Domain/Exceptions/CartItemNotFoundException.php`: Thrown when a requested cart item does not exist or does not belong to the authenticated user.
- `app/Modules/Cart/Domain/Exceptions/InvalidCartQuantityException.php`: Thrown when an invalid cart quantity (<= 0) is specified.
- `app/Modules/Cart/Domain/Exceptions/CartItemNotAvailableException.php`: Thrown when item stock availability check fails.
- `app/Modules/Cart/Domain/Exceptions/CartItemNotSellableException.php`: Thrown when a product or variant fails customer sellability checks.
- `app/Modules/Cart/Domain/Exceptions/CartInventoryUnavailableException.php`: Thrown when requested item stock exceeds available stock and backorders are disabled.

#### 2. Infrastructure & Persistence Models
- `app/Modules/Cart/Infrastructure/Persistence/Models/Cart.php`: Persistent shopping cart model mapped to PostgreSQL `carts` table with helper attributes (`items_count`, `total_quantity`, `subtotal`).
- `app/Modules/Cart/Infrastructure/Persistence/Models/CartItem.php`: Cart line item model mapped to PostgreSQL `cart_items` table with helper attributes (`unit_price`, `line_subtotal`).

#### 3. Application Services & DTOs
- `app/Modules/Cart/Application/DTOs/AddCartItemData.php`: DTO for adding a product variant to the cart.
- `app/Modules/Cart/Application/DTOs/UpdateCartItemData.php`: DTO for updating a cart line item's absolute quantity.
- `app/Modules/Cart/Application/DTOs/ApplyCouponData.php`: DTO for applying a tentative coupon code.
- `app/Modules/Cart/Application/Services/CartService.php`: Primary use-case orchestration service handling lazy cart creation, sellability validation, optimistic stock checks, additive/absolute quantity mutations, item deletion, idempotent clearing, tentative coupon management, and database race condition recovery.

#### 4. Presentation Layer
- **Form Requests**:
  - `app/Modules/Cart/Presentation/Http/Requests/AddCartItemRequest.php`
  - `app/Modules/Cart/Presentation/Http/Requests/UpdateCartItemRequest.php`
  - `app/Modules/Cart/Presentation/Http/Requests/ApplyCartCouponRequest.php`
- **API Resources**:
  - `app/Modules/Cart/Presentation/Http/Resources/CartItemResource.php`
  - `app/Modules/Cart/Presentation/Http/Resources/CartResource.php`
- **Controller**:
  - `app/Modules/Cart/Presentation/Http/Controllers/CartController.php`
- **Module Routes & Provider**:
  - `app/Modules/Cart/Providers/CartServiceProvider.php`
  - `app/Modules/Cart/Presentation/Routes/api.php`
- **Configuration**:
  - `config/cart.php`: Cart module settings (`expiration_days => 30`).

#### 5. PostgreSQL-Backed Feature Test Suite
- `tests/Feature/CartRetrievalTest.php`
- `tests/Feature/CartAddItemTest.php`
- `tests/Feature/CartUpdateItemTest.php`
- `tests/Feature/CartRemoveItemTest.php`
- `tests/Feature/CartClearTest.php`
- `tests/Feature/CartCouponTest.php`
- `tests/Feature/CartAuthorizationTest.php`
- `tests/Feature/CartInventoryAvailabilityTest.php`
- `tests/Feature/CartSellabilityTest.php`
- `tests/Feature/CartConcurrencyTest.php`

---

### B. Files Modified

- `app/Modules/Identity/Infrastructure/Persistence/Models/User.php`: Added `cart(): HasOne` relationship method (`hasOne(Cart::class, 'user_id')`).

---

## Shopping Cart Architecture Overview

```text
HTTP Request
    ↓
Authentication (auth:api Guard - Identity Token)
    ↓
Form Request Validation (AddCartItemRequest, etc.)
    ↓
CartController
    ↓
Application Service (CartService)
    ↓
Catalog / Inventory Read Validation (Product, ProductVariant, Inventory)
    ↓
DB Transaction & PostgreSQL Concurrency Race Recovery
    ↓
PostgreSQL Baseline Tables (carts, cart_items)
    ↓
API Resource (CartResource / CartItemResource)
```

---

## Cart Operations & Mechanics

| Endpoint | HTTP Method | Quantity Logic | Lazy Cart Creation? | Inventory Impact | Description |
|---|---|---|---|---|---|
| `/api/v1/cart` | `GET` | Read-only | Yes | None | Retrieves persistent cart summary and line items. |
| `/api/v1/cart/items` | `POST` | Additive (`qty + new`) | Yes | Optimistic check | Adds variant or increases line item quantity. |
| `/api/v1/cart/items/{cartItem}` | `PATCH` | Absolute (`qty = new`) | No | Optimistic check | Updates exact line item quantity. |
| `/api/v1/cart/items/{cartItem}` | `DELETE` | Removes item | No | None | Deletes individual line item from cart. |
| `/api/v1/cart/items` | `DELETE` | Clears all items | No (Idempotent) | None | Clears all line items while retaining cart record. |
| `/api/v1/cart/coupon` | `PUT` | Stores tentative code | Yes | None | Stores tentative coupon code on cart. |
| `/api/v1/cart/coupon` | `DELETE` | Removes coupon (`NULL`) | Yes | None | Clears tentative coupon code from cart. |

---

## API Routes Summary

```text
GET     /api/v1/cart

POST    /api/v1/cart/items
PATCH   /api/v1/cart/items/{cartItem}
DELETE  /api/v1/cart/items/{cartItem}
DELETE  /api/v1/cart/items

PUT     /api/v1/cart/coupon
DELETE  /api/v1/cart/coupon
```

---

## Verification Results

### 1. Cart Test Suite
```text
   PASS  Tests\Feature\CartAddItemTest (3 passed)
   PASS  Tests\Feature\CartAuthorizationTest (2 passed)
   PASS  Tests\Feature\CartClearTest (2 passed)
   PASS  Tests\Feature\CartConcurrencyTest (3 passed)
   PASS  Tests\Feature\CartCouponTest (2 passed)
   PASS  Tests\Feature\CartInventoryAvailabilityTest (2 passed)
   PASS  Tests\Feature\CartRemoveItemTest (2 passed)
   PASS  Tests\Feature\CartRetrievalTest (2 passed)
   PASS  Tests\Feature\CartSellabilityTest (2 passed)
   PASS  Tests\Feature\CartUpdateItemTest (3 passed)

  Tests:    23 passed (76 assertions)
  Duration: 32.40s
```

### 2. Full Application Test Suite
```text
  Tests:    91 passed (335 assertions)
  Duration: 122.21s
```

### 3. Database Integrity & Safety Compliance
- Zero Laravel migrations created for baseline tables (`carts`, `cart_items`).
- `database/sql/` baseline schema preserved without alteration.
- All tests executed against PostgreSQL `testing` database using `DatabaseTransactions`.
- Inventory read-only isolation maintained (no reservation or movement creation).
