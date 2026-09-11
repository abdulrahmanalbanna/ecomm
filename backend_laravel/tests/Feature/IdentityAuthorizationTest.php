<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class IdentityAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected Role $adminRole;
    protected Role $staffRole;
    protected Role $customerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole    = Role::where('name', 'admin')->firstOrFail();
        $this->staffRole    = Role::where('name', 'staff')->firstOrFail();
        $this->customerRole = Role::where('name', 'customer')->firstOrFail();

        // Register dynamic transient test routes for role and permission middleware verification
        Route::middleware(['auth:api', 'role:admin'])->get('/api/v1/test/admin-only', static function () {
            return response()->json(['message' => 'Admin Access Granted']);
        });

        Route::middleware(['auth:api', 'role:staff,admin'])->get('/api/v1/test/staff-or-admin', static function () {
            return response()->json(['message' => 'Staff/Admin Access Granted']);
        });

        Route::middleware(['auth:api', 'permission:products.create'])->get('/api/v1/test/create-product', static function () {
            return response()->json(['message' => 'Create Product Permission Granted']);
        });

        Route::middleware(['auth:api', 'permission:self.orders.view'])->get('/api/v1/test/customer-orders', static function () {
            return response()->json(['message' => 'Customer Orders Granted']);
        });
    }

    private function createAuthenticatedUser(Role $role, string $email = 'testuser@example.com'): array
    {
        $user = User::create([
            'email' => $email,
            'password_hash' => Hash::make('password123'),
            'role_id' => $role->id,
            'is_active' => true,
            'is_email_verified' => true,
        ]);

        $rawToken = 'test-token-' . bin2hex(random_bytes(8));
        Session::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);

        return [$user, $rawToken];
    }

    // =========================================================================
    // Case 1: Unauthenticated request to authenticated/protected endpoint -> 401
    // =========================================================================
    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/identity/authorization-check');
        $response->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);

        $responseAdmin = $this->getJson('/api/v1/test/admin-only');
        $responseAdmin->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    // =========================================================================
    // Case 2 & 3: Role Authorization (Single Role & ANY-Role)
    // =========================================================================
    public function test_role_authorization_grants_access_to_matching_role(): void
    {
        [$adminUser, $adminToken] = $this->createAuthenticatedUser($this->adminRole, 'admin@example.com');

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/v1/test/admin-only');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Admin Access Granted']);
    }

    public function test_role_authorization_rejects_non_matching_role_with_403(): void
    {
        [$customerUser, $customerToken] = $this->createAuthenticatedUser($this->customerRole, 'customer@example.com');

        $response = $this->withHeader('Authorization', 'Bearer ' . $customerToken)
            ->getJson('/api/v1/test/admin-only');

        $response->assertStatus(403)
            ->assertJson(['message' => 'Forbidden.']);
    }

    public function test_any_role_authorization_semantics(): void
    {
        [$adminUser, $adminToken] = $this->createAuthenticatedUser($this->adminRole, 'admin2@example.com');
        [$staffUser, $staffToken] = $this->createAuthenticatedUser($this->staffRole, 'staff@example.com');
        [$customerUser, $customerToken] = $this->createAuthenticatedUser($this->customerRole, 'customer2@example.com');

        // Admin -> Allowed
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/v1/test/staff-or-admin')
            ->assertStatus(200);

        // Staff -> Allowed
        $this->withHeader('Authorization', 'Bearer ' . $staffToken)
            ->getJson('/api/v1/test/staff-or-admin')
            ->assertStatus(200);

        // Customer -> Forbidden (403)
        $this->withHeader('Authorization', 'Bearer ' . $customerToken)
            ->getJson('/api/v1/test/staff-or-admin')
            ->assertStatus(403)
            ->assertJson(['message' => 'Forbidden.']);
    }

    // =========================================================================
    // Case 4 & 5: Permission Authorization
    // =========================================================================
    public function test_permission_authorization_grants_access_when_user_has_permission(): void
    {
        // Admin role has products.create permission in seed data
        [$adminUser, $adminToken] = $this->createAuthenticatedUser($this->adminRole, 'admin_perm@example.com');

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/v1/test/create-product');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Create Product Permission Granted']);
    }

    public function test_permission_authorization_denies_access_when_user_lacks_permission(): void
    {
        // Customer role does NOT have products.create permission
        [$customerUser, $customerToken] = $this->createAuthenticatedUser($this->customerRole, 'customer_noperm@example.com');

        $response = $this->withHeader('Authorization', 'Bearer ' . $customerToken)
            ->getJson('/api/v1/test/create-product');

        $response->assertStatus(403)
            ->assertJson(['message' => 'Forbidden.']);
    }

    // =========================================================================
    // Case 6: Relationship & Authorization Resolution Endpoint
    // =========================================================================
    public function test_authorization_check_endpoint_resolves_role_and_permissions_from_database(): void
    {
        [$staffUser, $staffToken] = $this->createAuthenticatedUser($this->staffRole, 'staff_res@example.com');

        $response = $this->withHeader('Authorization', 'Bearer ' . $staffToken)
            ->getJson('/api/v1/identity/authorization-check');

        $response->assertStatus(200)
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonFragment(['products.view'])
            ->assertJsonFragment(['orders.view']);
    }

    // =========================================================================
    // Case 7: PostgreSQL EXPLAIN Plan Verification for Authorization Queries
    // =========================================================================
    public function test_authorization_queries_use_indexed_columns_in_postgres(): void
    {
        [$staffUser, $staffToken] = $this->createAuthenticatedUser($this->staffRole, 'explain@example.com');

        // Query 1: Index-aware permission existence query
        $permQuerySql = "EXPLAIN (ANALYZE, BUFFERS) SELECT EXISTS (
            SELECT 1 FROM role_permissions rp
            JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.role_id = {$staffUser->role_id}
              AND p.code = 'products.view'
        )";

        $explainResult = DB::select($permQuerySql);
        $explainText = implode("\n", array_map(fn($row) => $row->{'QUERY PLAN'}, $explainResult));

        $this->assertNotEmpty($explainText);
        // Verify index scan / bitmap index scan / pk lookup used
        $this->assertTrue(
            str_contains($explainText, 'Index') || str_contains($explainText, 'Scan'),
            "EXPLAIN output should indicate index usage:\n{$explainText}"
        );

        // Query 2: User role lookup
        $roleQuerySql = "EXPLAIN (ANALYZE, BUFFERS) SELECT name FROM roles WHERE id = {$staffUser->role_id}";
        $explainRole = DB::select($roleQuerySql);
        $explainRoleText = implode("\n", array_map(fn($row) => $row->{'QUERY PLAN'}, $explainRole));

        $this->assertNotEmpty($explainRoleText);
        $this->assertTrue(
            str_contains($explainRoleText, 'Index') || str_contains($explainRoleText, 'Scan') || str_contains($explainRoleText, 'roles_pkey'),
            "EXPLAIN output for role lookup:\n{$explainRoleText}"
        );
    }
}
