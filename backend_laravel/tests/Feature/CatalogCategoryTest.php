<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;

use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

use Tests\TestCase;

final class CatalogCategoryTest extends TestCase
{
    use DatabaseTransactions;

    protected Role $adminRole;
    protected string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::where('name', 'admin')->firstOrFail();
        $user = User::create([
            'email' => 'cat_admin_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash' => Hash::make('password123'),
            'role_id' => $this->adminRole->id,
            'is_active' => true,
            'is_email_verified' => true,
        ]);

        $rawToken = 'cat-token-' . bin2hex(random_bytes(8));
        Session::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);
        $this->adminToken = $rawToken;
    }

    public function test_create_root_and_child_categories(): void
    {
        $responseRoot = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson('/api/v1/catalog/admin/categories', [
                'slug'        => 'electronics',
                'name'        => 'Electronics',
                'description' => 'Electronic items',
                'is_active'   => true,
                'sort_order'  => 1,
            ]);

        $responseRoot->assertStatus(201)
            ->assertJsonPath('data.slug', 'electronics')
            ->assertJsonPath('data.depth', 0)
            ->assertJsonPath('data.path', 'electronics');

        $rootId = $responseRoot->json('data.id');

        $responseChild = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson('/api/v1/catalog/admin/categories', [
                'parent_id'  => $rootId,
                'slug'       => 'smart-phones',
                'name'       => 'Smartphones',
                'is_active'  => true,
                'sort_order' => 1,
            ]);

        $responseChild->assertStatus(201)
            ->assertJsonPath('data.parent_id', $rootId)
            ->assertJsonPath('data.slug', 'smart-phones')
            ->assertJsonPath('data.depth', 1)
            ->assertJsonPath('data.path', 'electronics.smart_phones');
    }

    public function test_slug_format_validation(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson('/api/v1/catalog/admin/categories', [
                'slug' => 'INVALID_SLUG_WITH_UPPERCASE!',
                'name' => 'Bad Slug',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_category_tree_retrieval(): void
    {
        $root = Category::create([
            'slug'       => 'computing',
            'name'       => 'Computing',
            'is_active'  => true,
            'sort_order' => 1,
        ]);

        $child = Category::create([
            'parent_id'  => $root->id,
            'slug'       => 'laptops',
            'name'       => 'Laptops',
            'is_active'  => true,
            'sort_order' => 1,
        ]);

        $response = $this->getJson('/api/v1/catalog/categories');

        $response->assertStatus(200)
            ->assertJsonFragment(['slug' => 'computing'])
            ->assertJsonFragment(['slug' => 'laptops']);
    }

    public function test_ancestor_and_subtree_resolution(): void
    {
        $root = Category::create([
            'slug' => 'fashion',
            'name' => 'Fashion',
        ]);

        $child = Category::create([
            'parent_id' => $root->id,
            'slug'      => 'mens-clothing',
            'name'      => 'Mens Clothing',
        ]);

        $subchild = Category::create([
            'parent_id' => $child->id,
            'slug'      => 'shirts',
            'name'      => 'Shirts',
        ]);

        // Ancestors of subchild (shirts) -> should contain fashion and mens-clothing
        $ancestors = Category::ancestors($subchild->fresh()->path)->get();
        $ancestorSlugs = $ancestors->pluck('slug')->toArray();

        $this->assertContains('fashion', $ancestorSlugs);
        $this->assertContains('mens-clothing', $ancestorSlugs);

        // Subtree of root (fashion) -> should contain all 3
        $subtree = Category::subtree($root->fresh()->path)->get();
        $this->assertCount(3, $subtree);
    }

    public function test_category_deletion_restricted_when_children_exist(): void
    {
        $root = Category::create([
            'slug' => 'home-appliances',
            'name' => 'Home Appliances',
        ]);

        Category::create([
            'parent_id' => $root->id,
            'slug'      => 'refrigerators',
            'name'      => 'Refrigerators',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->deleteJson("/api/v1/catalog/admin/categories/{$root->id}");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot delete category: it has active children or assigned products.']);
    }
}
