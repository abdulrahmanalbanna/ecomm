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

final class CustomerAddressRetrievalTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::where('name', 'customer')->first() ?? Role::first();
        $this->user = User::create([
            'email'             => 'addr_get_' . bin2hex(random_bytes(4)) . '@example.com',
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

    public function test_user_can_retrieve_own_address(): void
    {
        $address = Address::create([
            'user_id'        => $this->user->id,
            'recipient_name' => 'John Doe',
            'line1'          => '123 Main St',
            'city'           => 'Riyadh',
            'country_code'   => 'SA',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/v1/customer/addresses/{$address->id}");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id'             => $address->id,
                'recipient_name' => 'John Doe',
                'line1'          => '123 Main St',
            ]);
    }

    public function test_cross_user_access_is_denied(): void
    {
        $otherUser = User::create([
            'email'             => 'other_get_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => Role::first()->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);

        $otherAddress = Address::create([
            'user_id'        => $otherUser->id,
            'recipient_name' => 'Other User',
            'line1'          => '789 Other St',
            'city'           => 'Jeddah',
            'country_code'   => 'SA',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/v1/customer/addresses/{$otherAddress->id}");

        $response->assertStatus(404);
    }

    public function test_nonexistent_address_returns_404(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/v1/customer/addresses/999999');

        $response->assertStatus(404);
    }

    public function test_unauthenticated_retrieval_is_rejected(): void
    {
        $address = Address::create([
            'user_id'        => $this->user->id,
            'recipient_name' => 'Test',
            'line1'          => '123 Test',
            'city'           => 'Riyadh',
            'country_code'   => 'SA',
        ]);

        $response = $this->getJson("/api/v1/customer/addresses/{$address->id}");

        $response->assertStatus(401);
    }
}
