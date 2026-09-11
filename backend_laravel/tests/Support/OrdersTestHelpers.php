<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use App\Modules\Customer\Infrastructure\Persistence\Models\Address;
use App\Modules\Identity\Infrastructure\Persistence\Models\Permission;
use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use App\Modules\Inventory\Infrastructure\Persistence\Models\Inventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Shared fixtures for Orders (Task 09) feature tests.
 *
 * PostgreSQL `testing` database + DatabaseTransactions only. No factories,
 * no migrations — everything is created against the authoritative baseline.
 */
trait OrdersTestHelpers
{
    protected function makeCustomer(?string $email = null): User
    {
        $role = Role::where('name', 'customer')->firstOrFail();

        return User::create([
            'email'             => $email ?? 'orders_cust_' . bin2hex(random_bytes(5)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => $role->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);
    }

    /**
     * Create a staff/admin user, ensuring the given permission codes are
     * granted to its role (idempotent within the test transaction).
     *
     * @param list<string> $permissions
     */
    protected function makeStaff(string $roleName = 'staff', array $permissions = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();

        foreach ($permissions as $code) {
            $permission = Permission::firstOrCreate(['code' => $code], ['description' => $code]);

            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'permission_id' => $permission->id],
            );
        }

        return User::create([
            'email'             => 'orders_' . $roleName . '_' . bin2hex(random_bytes(5)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => $role->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);
    }

    protected function issueToken(User $user): string
    {
        $rawToken = 'ord-' . bin2hex(random_bytes(10));

        Session::create([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);

        return $rawToken;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function makeAddress(User $user, array $overrides = []): Address
    {
        return Address::create(array_merge([
            'user_id'        => $user->id,
            'label'          => 'Home',
            'recipient_name' => 'Test Recipient',
            'phone'          => '+966500000000',
            'line1'          => 'King Fahd Road',
            'line2'          => 'Apt 1',
            'city'           => 'Riyadh',
            'state'          => 'Riyadh Province',
            'postal_code'    => '12345',
            'country_code'   => 'SA',
            'is_default'     => false,
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $variantOverrides
     * @param array<string, mixed> $productOverrides
     */
    protected function makeSellableVariant(
        string $price,
        int $onHand = 100,
        array $variantOverrides = [],
        array $productOverrides = [],
    ): ProductVariant {
        $suffix = bin2hex(random_bytes(4));

        $category = Category::create([
            'slug' => 'ord-cat-' . $suffix,
            'name' => 'Orders Category ' . $suffix,
        ]);

        $product = Product::create(array_merge([
            'category_id' => $category->id,
            'slug'        => 'ord-prod-' . $suffix,
            'name'        => 'Orders Product ' . $suffix,
            'status'      => 'published',
            'is_active'   => true,
        ], $productOverrides));

        $variant = ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku'        => 'ORD-SKU-' . strtoupper($suffix),
            'name'       => 'Orders Variant ' . $suffix,
            'price'      => $price,
            'is_active'  => true,
        ], $variantOverrides));

        Inventory::create([
            'variant_id'        => $variant->id,
            'quantity_on_hand'  => $onHand,
            'quantity_reserved' => 0,
            'allow_backorder'   => false,
        ]);

        return $variant->fresh();
    }

    /**
     * Insert cart rows directly (bypasses the Cart module so tests can
     * simulate cart states precisely, including stale/removed variants).
     *
     * @param array<int, array{variant_id: int, quantity: int}> $items
     */
    protected function seedCart(User $user, array $items, ?string $couponCode = null): int
    {
        // carts.user_id is UNIQUE, so reuse the existing cart row when the
        // test places several orders for the same user (checkout clears
        // cart_items but keeps the cart).
        $existing = DB::table('carts')->where('user_id', $user->id)->first();

        if ($existing !== null) {
            $cartId = (int) $existing->id;
            DB::table('cart_items')->where('cart_id', $cartId)->delete();
            if ($couponCode !== null) {
                DB::table('carts')->where('id', $cartId)->update(['coupon_code' => $couponCode]);
            }
        } else {
            $cartId = (int) DB::table('carts')->insertGetId([
                'user_id'     => $user->id,
                'coupon_code' => $couponCode,
                'created_at'  => now(),
                'updated_at'  => now(),
            ], 'id');
        }

        foreach ($items as $item) {
            DB::table('cart_items')->insert([
                'cart_id'    => $cartId,
                'variant_id' => $item['variant_id'],
                'quantity'   => $item['quantity'],
                'added_at'   => now(),
            ]);
        }

        return (int) $cartId;
    }

    protected function authToken(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * Seed a cart and run checkout through the API; returns the public_id.
     *
     * @param array<int, array{variant_id: int, quantity: int}> $items
     */
    protected function placeOrder(User $user, string $token, array $items, ?Address $address = null): string
    {
        $address ??= $this->makeAddress($user);
        $this->seedCart($user, $items);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);
        $response->assertStatus(201);

        return (string) $response->json('data.public_id');
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function adminTransition(string $token, string $publicId, string $status, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->authToken($token)->postJson(
            "/api/v1/admin/orders/{$publicId}/transition",
            array_merge(['status' => $status], $extra),
        );
    }

    /**
     * @return array{quantity_on_hand: int, quantity_reserved: int, quantity_available: int}
     */
    protected function inventoryRow(int $variantId): array
    {
        $row = DB::table('inventory')->where('variant_id', $variantId)->first();

        return [
            'quantity_on_hand'  => (int) $row->quantity_on_hand,
            'quantity_reserved' => (int) $row->quantity_reserved,
            'quantity_available' => (int) $row->quantity_available,
        ];
    }
}
