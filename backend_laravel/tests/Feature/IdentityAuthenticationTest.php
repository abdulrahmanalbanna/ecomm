<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Persistence\Models\CustomerProfile;
use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class IdentityAuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    protected Role $adminRole;
    protected Role $customerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabaseSchema();
    }

    private function setUpDatabaseSchema(): void
    {
        // Roles are seeded by 021_seed_data.sql into the PostgreSQL `testing` database.
        // Each test runs inside a transaction (DatabaseTransactions) that is rolled back
        // after the test, so no data persists between tests.
        $this->adminRole    = Role::where('name', 'admin')->firstOrFail();
        $this->customerRole = Role::where('name', 'customer')->firstOrFail();
    }

    public function test_login_with_valid_credentials_returns_token_and_safe_identity(): void
    {
        $user = User::create([
            'email' => 'user@example.com',
            'password_hash' => Hash::make('password123'),
            'role_id' => $this->customerRole->id,
            'is_active' => true,
            'is_email_verified' => true,
        ]);

        CustomerProfile::create([
            'user_id' => $user->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        $response = $this->postJson('/api/v1/identity/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'token_type',
                    'expires_at',
                    'user' => [
                        'public_id',
                        'email',
                        'phone',
                        'role',
                        'is_email_verified',
                        'created_at',
                        'profile' => ['first_name', 'last_name', 'avatar_url'],
                    ],
                ],
            ])
            ->assertJsonMissing(['password_hash', 'token_hash', 'id']);

        $this->assertDatabaseHas('sessions', [
            'user_id' => $user->id,
            'revoked_at' => null,
        ]);
    }

    public function test_login_with_invalid_password_returns_401(): void
    {
        User::create([
            'email' => 'user@example.com',
            'password_hash' => Hash::make('password123'),
            'role_id' => $this->customerRole->id,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/identity/login', [
            'email' => 'user@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid email or password.']);
    }

    public function test_login_with_nonexistent_email_returns_401(): void
    {
        $response = $this->postJson('/api/v1/identity/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid email or password.']);
    }

    public function test_login_fails_validation_if_fields_missing(): void
    {
        $response = $this->postJson('/api/v1/identity/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_rejects_inactive_account_with_403(): void
    {
        User::create([
            'email' => 'inactive@example.com',
            'password_hash' => Hash::make('password123'),
            'role_id' => $this->customerRole->id,
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/identity/login', [
            'email' => 'inactive@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Account is inactive or disabled.']);
    }

    public function test_authenticated_me_endpoint_returns_safe_user_identity(): void
    {
        $user = User::create([
            'email' => 'member@example.com',
            'password_hash' => Hash::make('password123'),
            'role_id' => $this->adminRole->id,
            'is_active' => true,
        ]);

        $rawToken = 'test-raw-bearer-token-12345';
        Session::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $rawToken)
            ->getJson('/api/v1/identity/me');

        $response->assertStatus(200)
            ->assertJsonPath('data.email', 'member@example.com')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonMissing(['password_hash', 'token_hash']);
    }

    public function test_unauthenticated_me_endpoint_returns_401(): void
    {
        $response = $this->getJson('/api/v1/identity/me');

        $response->assertStatus(401);
    }

    public function test_logout_revokes_strictly_current_session(): void
    {
        $user = User::create([
            'email' => 'logout@example.com',
            'password_hash' => Hash::make('password123'),
            'role_id' => $this->customerRole->id,
            'is_active' => true,
        ]);

        $token1 = 'session-1-raw-token';
        $token2 = 'session-2-raw-token';

        $session1 = Session::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token1),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);

        $session2 = Session::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token2),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token1)
            ->postJson('/api/v1/identity/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Successfully logged out.']);

        $this->assertNotNull($session1->fresh()->revoked_at);
        $this->assertNull($session2->fresh()->revoked_at);

        // Session 1 is now rejected
        $meResponse = $this->withHeader('Authorization', 'Bearer ' . $token1)
            ->getJson('/api/v1/identity/me');
        $meResponse->assertStatus(401);

        // Session 2 is still valid
        $meResponse2 = $this->withHeader('Authorization', 'Bearer ' . $token2)
            ->getJson('/api/v1/identity/me');
        $meResponse2->assertStatus(200);
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::create([
            'email' => 'expired@example.com',
            'password_hash' => Hash::make('password123'),
            'role_id' => $this->customerRole->id,
            'is_active' => true,
        ]);

        $token = 'expired-raw-token';

        Session::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->subMinutes(5), // Expired in past
            'created_at' => now()->subDays(1),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/identity/me');

        $response->assertStatus(401);
    }

    public function test_token_is_rejected_if_user_becomes_inactive_or_deleted(): void
    {
        $user = User::create([
            'email' => 'deactivated@example.com',
            'password_hash' => Hash::make('password123'),
            'role_id' => $this->customerRole->id,
            'is_active' => true,
        ]);

        $token = 'valid-token-deactivated-user';

        Session::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);

        // Deactivate user after issuing token
        $user->is_active = false;
        $user->save();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/identity/me');

        $response->assertStatus(401);
    }
}
