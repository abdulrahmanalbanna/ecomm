<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Customer\Infrastructure\Persistence\Models\Address;
use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CustomerAddressListingTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::where('name', 'customer')->first() ?? Role::first();
        $this->user = User::create([
            'email'             => 'addr_list_' . bin2hex(random_bytes(4)) . '@example.com',
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

    public function test_authenticated_user_can_list_own_addresses(): void
    {
        Address::create([
            'user_id'        => $this->user->id,
            'recipient_name' => 'John Doe',
            'line1'          => '123 Main St',
            'city'           => 'Riyadh',
            'country_code'   => 'SA',
            'is_default'     => false,
        ]);

        Address::create([
            'user_id'        => $this->user->id,
            'recipient_name' => 'Jane Doe',
            'line1'          => '456 Oak Ave',
            'city'           => 'Jeddah',
            'country_code'   => 'SA',
            'is_default'     => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/v1/customer/addresses');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_listing_excludes_other_users_addresses(): void
    {
        // Create address for authenticated user
        Address::create([
            'user_id'        => $this->user->id,
            'recipient_name' => 'My Address',
            'line1'          => '123 My St',
            'city'           => 'Riyadh',
            'country_code'   => 'SA',
        ]);

        // Create address for another user
        $otherUser = User::create([
            'email'             => 'other_list_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => Role::first()->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);

        Address::create([
            'user_id'        => $otherUser->id,
            'recipient_name' => 'Other User Address',
            'line1'          => '789 Other St',
            'city'           => 'Dammam',
            'country_code'   => 'SA',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/v1/customer/addresses');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['recipient_name' => 'My Address']);
    }

    public function test_default_address_appears_first_in_listing(): void
    {
        Address::create([
            'user_id'        => $this->user->id,
            'recipient_name' => 'Non-Default',
            'line1'          => '111 First St',
            'city'           => 'Riyadh',
            'country_code'   => 'SA',
            'is_default'     => false,
        ]);

        Address::create([
            'user_id'        => $this->user->id,
            'recipient_name' => 'Default Address',
            'line1'          => '222 Second St',
            'city'           => 'Riyadh',
            'country_code'   => 'SA',
            'is_default'     => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/v1/customer/addresses');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertTrue($data[0]['is_default']);
        $this->assertEquals('Default Address', $data[0]['recipient_name']);
    }

    public function test_empty_address_book_returns_empty_array(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/v1/customer/addresses');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_unauthenticated_listing_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/customer/addresses');

        $response->assertStatus(401);
    }
}
