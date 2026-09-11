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

final class CustomerAddressUpdateTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected string $token;
    protected Address $address;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::where('name', 'customer')->first() ?? Role::first();
        $this->user = User::create([
            'email'             => 'addr_upd_' . bin2hex(random_bytes(4)) . '@example.com',
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

        $this->address = Address::create([
            'user_id'        => $this->user->id,
            'recipient_name' => 'Original Name',
            'line1'          => '123 Original St',
            'city'           => 'Riyadh',
            'country_code'   => 'SA',
            'phone'          => '+966500000000',
        ]);
    }

    public function test_user_can_update_own_address(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/v1/customer/addresses/{$this->address->id}", [
                'recipient_name' => 'Updated Name',
                'city'           => 'Jeddah',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'recipient_name' => 'Updated Name',
                'city'           => 'Jeddah',
            ]);

        $this->assertDatabaseHas('addresses', [
            'id'             => $this->address->id,
            'recipient_name' => 'Updated Name',
            'city'           => 'Jeddah',
            'line1'          => '123 Original St', // unchanged
        ]);
    }

    public function test_cross_user_update_is_denied(): void
    {
        $otherUser = User::create([
            'email'             => 'other_upd_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => Role::first()->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);

        $otherAddress = Address::create([
            'user_id'        => $otherUser->id,
            'recipient_name' => 'Other User',
            'line1'          => '789 Other St',
            'city'           => 'Dammam',
            'country_code'   => 'SA',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/v1/customer/addresses/{$otherAddress->id}", [
                'recipient_name' => 'Hacked',
            ]);

        $response->assertStatus(404);

        // Verify original data is unchanged
        $this->assertDatabaseHas('addresses', [
            'id'             => $otherAddress->id,
            'recipient_name' => 'Other User',
        ]);
    }

    public function test_country_code_is_normalized_on_update(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/v1/customer/addresses/{$this->address->id}", [
                'country_code' => 'ae',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.country_code', 'AE');

        $this->assertDatabaseHas('addresses', [
            'id'           => $this->address->id,
            'country_code' => 'AE',
        ]);
    }

    public function test_user_id_cannot_be_changed_via_update(): void
    {
        $otherUser = User::create([
            'email'             => 'hijack_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => Role::first()->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/v1/customer/addresses/{$this->address->id}", [
                'user_id' => $otherUser->id,
                'city'    => 'Hijacked',
            ]);

        // user_id should remain unchanged
        $this->assertDatabaseHas('addresses', [
            'id'      => $this->address->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_unauthenticated_update_is_rejected(): void
    {
        $response = $this->putJson("/api/v1/customer/addresses/{$this->address->id}", [
            'recipient_name' => 'Unauthorized',
        ]);

        $response->assertStatus(401);
    }
}
