<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class InventoryAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $customerUser;
    protected string $customerToken;
    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $customerRole = Role::where('name', 'customer')->firstOrFail();
        $this->customerUser = User::create([
            'email'             => 'cust_inv_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => $customerRole->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);

        $rawToken = 'token-' . bin2hex(random_bytes(8));
        Session::create([
            'user_id'    => $this->customerUser->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);
        $this->customerToken = $rawToken;

        $category = Category::create([
            'slug' => 'cat-auth-' . bin2hex(random_bytes(4)),
            'name' => 'Auth Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'slug'        => 'prod-auth-' . bin2hex(random_bytes(4)),
            'name'        => 'Auth Product',
            'status'      => 'draft',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-AUTH-' . strtoupper(bin2hex(random_bytes(3))),
            'price'      => 99.99,
            'is_active'  => true,
        ]);
    }

    public function test_customer_role_is_denied_access_to_inventory_admin_endpoints(): void
    {
        // View endpoint
        $responseView = $this->withHeader('Authorization', 'Bearer ' . $this->customerToken)
            ->getJson('/api/v1/admin/inventory');

        $responseView->assertStatus(403);

        // Adjust endpoint
        $responseAdjust = $this->withHeader('Authorization', 'Bearer ' . $this->customerToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/receive", [
                'quantity' => 10,
            ]);

        $responseAdjust->assertStatus(403);
    }
}
