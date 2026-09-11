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

final class CustomerDefaultAddressTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected string $token;
    protected Address $address1;
    protected Address $address2;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::where('name', 'customer')->first() ?? Role::first();
        $this->user = User::create([
            'email'             => 'addr_default_' . bin2hex(random_bytes(4)) . '@example.com',
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

        $this->address1 = Address::create([
            'user_id'        => $this->user->id,
            'recipient_name' => 'Address One',
            'line1'          => '1 First St',
            'city'           => 'Riyadh',
            'country_code'   => 'SA',
            'is_default'     => false,
        ]);

        $this->address2 = Address::create([
            'user_id'        => $this->user->id,
            'recipient_name' => 'Address Two',
            'line1'          => '2 Second St',
            'city'           => 'Jeddah',
            'country_code'   => 'SA',
            'is_default'     => false,
        ]);
    }

    public function test_set_default_address_marks_it_as_default(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/v1/customer/addresses/{$this->address1->id}/default");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_default', true);

        $this->assertTrue($this->address1->fresh()->is_default);
    }

    public function test_setting_new_default_clears_previous_default(): void
    {
        // Set address1 as default first
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/v1/customer/addresses/{$this->address1->id}/default")
            ->assertStatus(200);

        $this->assertTrue($this->address1->fresh()->is_default);
        $this->assertFalse($this->address2->fresh()->is_default);

        // Now set address2 as default — trigger should clear address1
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/v1/customer/addresses/{$this->address2->id}/default")
            ->assertStatus(200);

        $this->assertFalse($this->address1->fresh()->is_default);
        $this->assertTrue($this->address2->fresh()->is_default);
    }

    public function test_at_most_one_default_per_user(): void
    {
        // Set both as default via API sequentially
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/v1/customer/addresses/{$this->address1->id}/default");

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/v1/customer/addresses/{$this->address2->id}/default");

        $defaultCount = Address::where('user_id', $this->user->id)
            ->where('is_default', true)
            ->count();

        $this->assertSame(1, $defaultCount);
    }

    public function test_set_default_on_nonexistent_address_returns_404(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson('/api/v1/customer/addresses/999999/default');

        $response->assertStatus(404);
    }

    public function test_cross_user_set_default_is_denied(): void
    {
        $otherUser = User::create([
            'email'             => 'other_def_' . bin2hex(random_bytes(4)) . '@example.com',
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
            ->patchJson("/api/v1/customer/addresses/{$this->address1->id}/default");

        $response->assertStatus(404);
        $this->assertFalse($this->address1->fresh()->is_default);
    }

    public function test_unauthenticated_set_default_is_rejected(): void
    {
        $response = $this->patchJson("/api/v1/customer/addresses/{$this->address1->id}/default");

        $response->assertStatus(401);
    }
}
