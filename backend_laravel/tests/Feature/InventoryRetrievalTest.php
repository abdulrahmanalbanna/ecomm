<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use App\Modules\Inventory\Infrastructure\Persistence\Models\Inventory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class InventoryRetrievalTest extends TestCase
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
            'email'             => 'inv_ret_' . bin2hex(random_bytes(4)) . '@example.com',
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
            'slug' => 'cat-inv-' . bin2hex(random_bytes(4)),
            'name' => 'Inv Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'slug'        => 'prod-inv-' . bin2hex(random_bytes(4)),
            'name'        => 'Inv Product',
            'status'      => 'draft',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-INV-' . strtoupper(bin2hex(random_bytes(3))),
            'price'      => 99.99,
            'is_active'  => true,
        ]);
    }

    public function test_authorized_admin_can_view_inventory(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->getJson("/api/v1/admin/inventory/variants/{$this->variant->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.variant_id', $this->variant->id)
            ->assertJsonPath('data.quantity_on_hand', 0)
            ->assertJsonPath('data.quantity_reserved', 0)
            ->assertJsonPath('data.quantity_available', 0)
            ->assertJsonPath('data.reorder_point', 5)
            ->assertJsonPath('data.reorder_quantity', 20)
            ->assertJsonPath('data.allow_backorder', false);
    }

    public function test_admin_can_list_inventory(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->getJson('/api/v1/admin/inventory');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'current_page', 'total']);
    }

    public function test_nonexistent_variant_returns_404(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->getJson('/api/v1/admin/inventory/variants/999999');

        $response->assertStatus(404);
    }

    public function test_unauthorized_user_is_denied(): void
    {
        $response = $this->getJson("/api/v1/admin/inventory/variants/{$this->variant->id}");

        $response->assertStatus(401);
    }
}
