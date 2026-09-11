<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CustomerProfileRetrievalTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::where('name', 'customer')->first() ?? Role::first();
        $this->user = User::create([
            'email'             => 'profile_retrieval_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => $role->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);

        $rawToken = 'token-' . bin2hex(random_bytes(8));
        Session::create([
            'user_id'    => $this->user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);
        $this->token = $rawToken;
    }

    public function test_authenticated_user_can_retrieve_profile(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/v1/customer/profile');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'first_name',
                    'last_name',
                    'date_of_birth',
                    'gender',
                    'avatar_url',
                    'preferences',
                    'created_at',
                    'updated_at',
                ],
            ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/customer/profile');

        $response->assertStatus(401);
    }

    public function test_profile_is_lazily_created_if_not_exists(): void
    {
        // Ensure no profile exists yet
        $this->assertNull($this->user->customerProfile);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/v1/customer/profile');

        $response->assertStatus(200);

        // Profile should now exist in database
        $this->assertNotNull($this->user->fresh()->customerProfile);
    }

    public function test_profile_response_contains_correct_user_data(): void
    {
        // Create a profile with known data
        \App\Modules\Customer\Infrastructure\Persistence\Models\CustomerProfile::create([
            'user_id'     => $this->user->id,
            'first_name'  => 'John',
            'last_name'   => 'Doe',
            'date_of_birth' => '1990-05-15',
            'gender'      => 'male',
            'avatar_url'  => 'https://example.com/avatar.jpg',
            'preferences' => ['newsletter' => true],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/v1/customer/profile');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'first_name' => 'John',
                'last_name'  => 'Doe',
                'gender'     => 'male',
            ])
            ->assertJsonPath('data.date_of_birth', '1990-05-15');
    }
}
