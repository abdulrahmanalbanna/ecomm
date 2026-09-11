<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CatalogAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected Role $adminRole;
    protected Role $customerRole;
    protected string $adminToken;
    protected string $customerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole    = Role::where('name', 'admin')->firstOrFail();
        $this->customerRole = Role::where('name', 'customer')->firstOrFail();

        // Admin User
        $adminUser = User::create([
            'email' => 'auth_admin_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash' => Hash::make('password123'),
            'role_id' => $this->adminRole->id,
            'is_active' => true,
            'is_email_verified' => true,
        ]);
        $rawAdminToken = 'auth-admin-' . bin2hex(random_bytes(8));
        Session::create([
            'user_id' => $adminUser->id,
            'token_hash' => hash('sha256', $rawAdminToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);
        $this->adminToken = $rawAdminToken;

        // Customer User (lacks catalog admin permissions)
        $customerUser = User::create([
            'email' => 'auth_cust_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash' => Hash::make('password123'),
            'role_id' => $this->customerRole->id,
            'is_active' => true,
            'is_email_verified' => true,
        ]);
        $rawCustomerToken = 'auth-cust-' . bin2hex(random_bytes(8));
        Session::create([
            'user_id' => $customerUser->id,
            'token_hash' => hash('sha256', $rawCustomerToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);
        $this->customerToken = $rawCustomerToken;
    }

    public function test_unauthenticated_requests_return_401(): void
    {
        $this->getJson('/api/v1/catalog/admin/categories')->assertStatus(401);
        $this->getJson('/api/v1/catalog/admin/products')->assertStatus(401);
        $this->postJson('/api/v1/catalog/admin/categories', [])->assertStatus(401);
        $this->postJson('/api/v1/catalog/admin/products', [])->assertStatus(401);
    }

    public function test_customer_user_returns_403_for_catalog_admin_endpoints(): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->customerToken];

        $this->withHeaders($headers)->getJson('/api/v1/catalog/admin/categories')->assertStatus(403);
        $this->withHeaders($headers)->postJson('/api/v1/catalog/admin/categories', [])->assertStatus(403);
        $this->withHeaders($headers)->getJson('/api/v1/catalog/admin/products')->assertStatus(403);
        $this->withHeaders($headers)->postJson('/api/v1/catalog/admin/products', [])->assertStatus(403);
        $this->withHeaders($headers)->postJson('/api/v1/catalog/admin/products/1/publish', [])->assertStatus(403);
    }

    public function test_admin_user_with_permissions_is_authorized(): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->adminToken];

        $this->withHeaders($headers)->getJson('/api/v1/catalog/admin/categories')->assertStatus(200);
        $this->withHeaders($headers)->getJson('/api/v1/catalog/admin/products')->assertStatus(200);
    }
}
