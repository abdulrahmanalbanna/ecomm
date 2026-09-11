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

final class InventoryLowStockTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected string $adminToken;
    protected ProductVariant $variantLow;
    protected ProductVariant $variantHigh;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->adminUser = User::create([
            'email'             => 'low_stk_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => $adminRole->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);

        $rawToken = 'token-' . bin2hex(random_bytes(8));
        Session::create([
            'user_id'    => $this->adminUser->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);
        $this->adminToken = $rawToken;

        $category = Category::create([
            'slug' => 'cat-low-' . bin2hex(random_bytes(4)),
            'name' => 'Low Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'slug'        => 'prod-low-' . bin2hex(random_bytes(4)),
            'name'        => 'Low Product',
            'status'      => 'draft',
        ]);

        $this->variantLow = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-LOW-' . strtoupper(bin2hex(random_bytes(3))),
            'price'      => 49.99,
            'is_active'  => true,
        ]);

        $this->variantHigh = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-HIGH-' . strtoupper(bin2hex(random_bytes(3))),
            'price'      => 89.99,
            'is_active'  => true,
        ]);

        // Variant low: 3 units (reorder_point = 5 -> low stock)
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variantLow->id}/receive", ['quantity' => 3]);

        // Variant high: 20 units (reorder_point = 5 -> not low stock)
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variantHigh->id}/receive", ['quantity' => 20]);
    }

    public function test_low_stock_query_returns_only_low_stock_variants(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->getJson('/api/v1/admin/inventory/low-stock');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('variant_id')->all();

        $this->assertContains($this->variantLow->id, $ids);
        $this->assertNotContains($this->variantHigh->id, $ids);
    }
}
