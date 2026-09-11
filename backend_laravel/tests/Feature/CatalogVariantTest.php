<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Infrastructure\Persistence\Models\AttributeDefinition;
use App\Modules\Catalog\Infrastructure\Persistence\Models\AttributeOption;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductAttributeDefinition;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CatalogVariantTest extends TestCase
{
    use DatabaseTransactions;

    protected Role $adminRole;
    protected string $adminToken;
    protected Category $category;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::where('name', 'admin')->firstOrFail();
        $user = User::create([
            'email' => 'var_admin_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash' => Hash::make('password123'),
            'role_id' => $this->adminRole->id,
            'is_active' => true,
            'is_email_verified' => true,
        ]);

        $rawToken = 'var-token-' . bin2hex(random_bytes(8));
        Session::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);
        $this->adminToken = $rawToken;

        $this->category = Category::create([
            'slug' => 'var-category-' . bin2hex(random_bytes(4)),
            'name' => 'Var Category',
        ]);

        $this->product = Product::create([
            'category_id' => $this->category->id,
            'slug'        => 'var-product-' . bin2hex(random_bytes(4)),
            'name'        => 'Var Product',
            'status'      => 'draft',
        ]);
    }

    public function test_variant_creation_and_sku_uniqueness(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/catalog/admin/products/{$this->product->id}/variants", [
                'sku'        => 'SKU-RED-XL',
                'name'       => 'Red XL Variant',
                'price'      => 49.99,
                'cost_price' => 20.00,
                'is_active'  => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.sku', 'SKU-RED-XL')
            ->assertJsonPath('data.cost_price', 20);

        // Duplicate SKU should be rejected
        $responseDuplicate = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/catalog/admin/products/{$this->product->id}/variants", [
                'sku'   => 'SKU-RED-XL',
                'price' => 59.99,
            ]);

        $responseDuplicate->assertStatus(422)
            ->assertJsonValidationErrors(['sku']);
    }

    public function test_customer_facing_variant_api_does_not_expose_cost_price(): void
    {
        ProductVariant::create([
            'product_id' => $this->product->id,
            'sku'        => 'SKU-SECRET-COST',
            'price'      => 100.00,
            'cost_price' => 35.50,
            'is_active'  => true,
        ]);

        $this->product->status = 'published';
        $this->product->save();

        $response = $this->getJson("/api/v1/catalog/products/{$this->product->public_id}");

        $response->assertStatus(200);
        $variantData = $response->json('data.variants.0');

        $this->assertNotNull($variantData);
        $this->assertArrayNotHasKey('cost_price', $variantData);
    }
}
