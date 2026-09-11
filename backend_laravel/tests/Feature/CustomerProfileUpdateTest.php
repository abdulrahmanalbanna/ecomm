<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Customer\Infrastructure\Persistence\Models\CustomerProfile;
use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CustomerProfileUpdateTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::where('name', 'customer')->first() ?? Role::first();
        $this->user = User::create([
            'email'             => 'profile_update_' . bin2hex(random_bytes(4)) . '@example.com',
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

        // Create initial profile
        CustomerProfile::create([
            'user_id'       => $this->user->id,
            'first_name'    => 'OldFirst',
            'last_name'     => 'OldLast',
            'date_of_birth' => '1990-01-01',
            'gender'        => 'male',
        ]);
    }

    public function test_profile_can_be_updated_with_valid_data(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson('/api/v1/customer/profile', [
                'first_name' => 'NewFirst',
                'last_name'  => 'NewLast',
                'gender'     => 'female',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'first_name' => 'NewFirst',
                'last_name'  => 'NewLast',
                'gender'     => 'female',
            ]);

        $this->assertDatabaseHas('customer_profiles', [
            'user_id'    => $this->user->id,
            'first_name' => 'NewFirst',
            'last_name'  => 'NewLast',
        ]);
    }

    public function test_partial_update_preserves_unmodified_fields(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson('/api/v1/customer/profile', [
                'first_name' => 'PartialFirst',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'first_name' => 'PartialFirst',
                'last_name'  => 'OldLast',
            ]);
    }

    public function test_user_id_field_is_rejected_in_update(): void
    {
        $otherUser = User::create([
            'email'             => 'other_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => Role::first()->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson('/api/v1/customer/profile', [
                'first_name' => 'Hacked',
                'user_id'    => $otherUser->id,
            ]);

        // user_id should be ignored or rejected
        $profile = CustomerProfile::where('user_id', $this->user->id)->first();
        $this->assertEquals($this->user->id, $profile->user_id);
    }

    public function test_invalid_date_of_birth_is_rejected(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson('/api/v1/customer/profile', [
                'date_of_birth' => 'not-a-date',
            ]);

        $response->assertStatus(422);
    }

    public function test_unauthenticated_update_is_rejected(): void
    {
        $response = $this->putJson('/api/v1/customer/profile', [
            'first_name' => 'Unauthorized',
        ]);

        $response->assertStatus(401);
    }
}
