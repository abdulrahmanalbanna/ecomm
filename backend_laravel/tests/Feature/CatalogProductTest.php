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

final class CatalogProductTest extends TestCase
{
    use DatabaseTransactions;

    protected Role $adminRole;
    protected string $adminToken;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::where('name', 'admin')->firstOrFail();
        $user = User::create([
            'email' => 'prod_admin_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash' => Hash::make('password123'),
            'role_id' => $this->adminRole->id,
            'is_active' => true,
            'is_email_verified' => true,
        ]);

        $rawToken = 'prod-token-' . bin2hex(random_bytes(8));
        Session::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);
        $this->adminToken = $rawToken;

        $this->category = Category::create([
            'slug' => 'test-category-' . bin2hex(random_bytes(4)),
            'name' => 'Test Category',
        ]);
    }

    public function test_product_creation_and_public_identity(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson('/api/v1/catalog/admin/products', [
                'category_id' => $this->category->id,
                'slug'        => 'super-gadget',
                'name'        => 'Super Gadget',
                'description' => 'A great gadget',
                'status'      => 'draft',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.slug', 'super-gadget')
            ->assertJsonPath('data.status', 'draft');

        $this->assertNotEmpty($response->json('data.public_id'));
    }

    public function test_customer_facing_product_visibility_scopes(): void
    {
        // 1. Draft product -> Hidden from public API
        $draftProduct = Product::create([
            'category_id' => $this->category->id,
            'slug'        => 'draft-item',
            'name'        => 'Draft Item',
            'status'      => 'draft',
            'is_active'   => true,
        ]);

        $resDraft = $this->getJson("/api/v1/catalog/products/{$draftProduct->public_id}");
        $resDraft->assertStatus(404);

        // 2. Create variant for sellability
        $publishedProduct = Product::create([
            'category_id' => $this->category->id,
            'slug'        => 'published-item',
            'name'        => 'Published Item',
            'status'      => 'draft',
            'is_active'   => true,
        ]);

        ProductVariant::create([
            'product_id' => $publishedProduct->id,
            'sku'        => 'PUB-ITEM-01',
            'price'      => 99.99,
            'is_active'  => true,
        ]);

        // Publish product
        $publishedProduct->status = 'published';
        $publishedProduct->save();

        // Accessible via public_id and slug
        $resPublicUuid = $this->getJson("/api/v1/catalog/products/{$publishedProduct->public_id}");
        $resPublicUuid->assertStatus(200)->assertJsonPath('data.slug', 'published-item');

        $resPublicSlug = $this->getJson('/api/v1/catalog/products/published-item');
        $resPublicSlug->assertStatus(200)->assertJsonPath('data.public_id', $publishedProduct->public_id);
    }

    public function test_featured_products_listing(): void
    {
        $featuredProduct = Product::create([
            'category_id' => $this->category->id,
            'slug'        => 'featured-item',
            'name'        => 'Featured Item',
            'status'      => 'draft',
            'is_active'   => true,
            'is_featured' => true,
        ]);

        ProductVariant::create([
            'product_id' => $featuredProduct->id,
            'sku'        => 'FEAT-01',
            'price'      => 150.00,
            'is_active'  => true,
        ]);

        $featuredProduct->status = 'published';
        $featuredProduct->save();

        $response = $this->getJson('/api/v1/catalog/products?featured=1');
        $response->assertStatus(200)
            ->assertJsonFragment(['slug' => 'featured-item']);
    }

    public function test_product_soft_delete_workflow(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'slug'        => 'delete-me',
            'name'        => 'Delete Me',
            'status'      => 'draft',
            'is_active'   => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->deleteJson("/api/v1/catalog/admin/products/{$product->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }
}
