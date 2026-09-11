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

final class CustomerAddressDeletionTest extends TestCase
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
            'email'             => 'addr_del_' . bin2hex(random_bytes(4)) . '@example.com',
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
            'recipient_name' => 'Delete Me',
            'line1'          => '456 Remove Ave',
            'city'           => 'Jeddah',
            'country_code'   => 'SA',
        ]);
    }

    public function test_owner_can_delete_address(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/v1/customer/addresses/{$this->address->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('addresses', ['id' => $this->address->id]);
    }

    public function test_cross_user_deletion_is_denied(): void
    {
        $otherUser = User::create([
            'email'             => 'other_del_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => $this->user->role_id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);

        $otherRawToken = 'token-' . bin2hex(random_bytes(8));
        Session::create([
            'user_id'    => $otherUser->id,
            'token_hash' => hash('sha256', $otherRawToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $otherRawToken)
            ->deleteJson("/api/v1/customer/addresses/{$this->address->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('addresses', ['id' => $this->address->id]);
    }

    public function test_deleting_nonexistent_address_returns_404(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson('/api/v1/customer/addresses/999999');

        $response->assertStatus(404);
    }

    public function test_unauthenticated_deletion_is_rejected(): void
    {
        $response = $this->deleteJson("/api/v1/customer/addresses/{$this->address->id}");

        $response->assertStatus(401);
    }
}
