<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Infrastructure\Persistence\Models\AttributeDefinition;
use App\Modules\Catalog\Infrastructure\Persistence\Models\AttributeOption;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CatalogAttributeTest extends TestCase
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
            'email' => 'attr_admin_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash' => Hash::make('password123'),
            'role_id' => $this->adminRole->id,
            'is_active' => true,
            'is_email_verified' => true,
        ]);

        $rawToken = 'attr-token-' . bin2hex(random_bytes(8));
        Session::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);
        $this->adminToken = $rawToken;

        $this->category = Category::create([
            'slug' => 'attr-category-' . bin2hex(random_bytes(4)),
            'name' => 'Attr Category',
        ]);
    }

    public function test_attribute_definition_and_option_creation(): void
    {
        $responseAttr = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson('/api/v1/catalog/admin/attributes', [
                'name'          => 'material',
                'display_name'  => 'Material',
                'type'          => 'select',
                'is_filterable' => true,
            ]);

        $responseAttr->assertStatus(201)
            ->assertJsonPath('data.name', 'material');

        $attrId = $responseAttr->json('data.id');

        $responseOpt = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/catalog/admin/attributes/{$attrId}/options", [
                'value'        => 'cotton',
                'display_name' => '100% Cotton',
            ]);

        $responseOpt->assertStatus(201)
            ->assertJsonPath('data.value', 'cotton');
    }

    public function test_product_attribute_assignment(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'slug'        => 'attr-product',
            'name'        => 'Attr Product',
            'status'      => 'draft',
        ]);

        $attr = AttributeDefinition::create([
            'name'         => 'ram_size',
            'display_name' => 'RAM Size',
            'type'         => 'text',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/catalog/admin/products/{$product->id}/attributes", [
                'attribute_id' => $attr->id,
                'is_required'  => true,
                'sort_order'   => 1,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_required', true);
    }
}
