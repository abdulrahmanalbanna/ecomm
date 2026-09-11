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

final class InventoryMovementHistoryTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected string $adminToken;
    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->adminUser = User::create([
            'email'             => 'mvt_his_' . bin2hex(random_bytes(4)) . '@example.com',
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
            'slug' => 'cat-mvt-' . bin2hex(random_bytes(4)),
            'name' => 'Mvt Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'slug'        => 'prod-mvt-' . bin2hex(random_bytes(4)),
            'name'        => 'Mvt Product',
            'status'      => 'draft',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-MVT-' . strtoupper(bin2hex(random_bytes(3))),
            'price'      => 99.99,
            'is_active'  => true,
        ]);

        // Perform stock operations: receive 30, adjust +5, reserve 10
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/receive", ['quantity' => 30]);

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/adjust", ['quantity_delta' => 5]);

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/reserve", ['quantity' => 10]);
    }

    public function test_movement_history_retrieval_and_filtering(): void
    {
        // Variant history
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->getJson("/api/v1/admin/inventory/variants/{$this->variant->id}/movements");

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));

        // Filter by movement_type = 'purchase'
        $responseFilter = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->getJson("/api/v1/admin/inventory/variants/{$this->variant->id}/movements?movement_type=purchase");

        $responseFilter->assertStatus(200);
        $this->assertCount(1, $responseFilter->json('data'));
        $this->assertEquals('purchase', $responseFilter->json('data.0.movement_type'));
    }

    public function test_global_movement_history_endpoint(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->getJson('/api/v1/admin/inventory/movements');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'current_page', 'total']);
    }
}
