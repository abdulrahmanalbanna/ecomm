<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CustomerAddressCreationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::where('name', 'customer')->first() ?? Role::first();
        $this->user = User::create([
            'email'             => 'addr_create_' . bin2hex(random_bytes(4)) . '@example.com',
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

    public function test_address_can_be_created_with_valid_data(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/customer/addresses', [
                'recipient_name' => 'John Doe',
                'line1'          => '123 Main St',
                'city'           => 'Riyadh',
                'country_code'   => 'SA',
                'phone'          => '+966500000000',
                'label'          => 'Home',
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'recipient_name' => 'John Doe',
                'line1'          => '123 Main St',
                'city'           => 'Riyadh',
                'country_code'   => 'SA',
            ]);

        $this->assertDatabaseHas('addresses', [
            'user_id'        => $this->user->id,
            'recipient_name' => 'John Doe',
            'country_code'   => 'SA',
        ]);
    }

    public function test_country_code_is_normalized_to_uppercase(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/customer/addresses', [
                'recipient_name' => 'Jane Doe',
                'line1'          => '456 Oak Ave',
                'city'           => 'Jeddah',
                'country_code'   => 'sa',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.country_code', 'SA');

        $this->assertDatabaseHas('addresses', [
            'user_id'      => $this->user->id,
            'country_code' => 'SA',
        ]);
    }

    public function test_missing_required_fields_returns_validation_error(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/customer/addresses', [
                'label' => 'Incomplete',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['recipient_name', 'line1', 'city', 'country_code']);
    }

    public function test_invalid_country_code_length_is_rejected(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/customer/addresses', [
                'recipient_name' => 'Test User',
                'line1'          => '789 Test Rd',
                'city'           => 'Dammam',
                'country_code'   => 'USA',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('country_code');
    }

    public function test_user_id_cannot_be_set_by_client(): void
    {
        $otherUser = User::create([
            'email'             => 'other_addr_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => Role::first()->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/customer/addresses', [
                'recipient_name' => 'Hacker',
                'line1'          => 'Malicious St',
                'city'           => 'Hack City',
                'country_code'   => 'US',
                'user_id'        => $otherUser->id,
            ]);

        // Address should belong to authenticated user, not the injected user_id
        $this->assertDatabaseHas('addresses', [
            'user_id'        => $this->user->id,
            'recipient_name' => 'Hacker',
        ]);
        $this->assertDatabaseMissing('addresses', [
            'user_id' => $otherUser->id,
        ]);
    }

    public function test_unauthenticated_creation_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/customer/addresses', [
            'recipient_name' => 'Unauthorized',
            'line1'          => 'No Auth St',
            'city'           => 'Denied',
            'country_code'   => 'US',
        ]);

        $response->assertStatus(401);
    }
}
