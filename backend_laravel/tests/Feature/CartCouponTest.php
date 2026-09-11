<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CartCouponTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::where('name', 'customer')->first() ?? Role::first();
        $this->user = User::create([
            'email'             => 'cart_cpn_' . bin2hex(random_bytes(4)) . '@example.com',
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

    public function test_apply_coupon_creates_cart_if_none_exists_and_saves_tentative_code(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson('/api/v1/cart/coupon', [
                'coupon_code' => 'summer10',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.coupon_code', 'SUMMER10');

        $this->assertDatabaseHas('carts', [
            'user_id'     => $this->user->id,
            'coupon_code' => 'SUMMER10',
        ]);
    }

    public function test_replace_and_remove_coupon(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson('/api/v1/cart/coupon', ['coupon_code' => 'FIRST10']);

        $replaceRes = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson('/api/v1/cart/coupon', ['coupon_code' => 'SECOND20']);

        $replaceRes->assertStatus(200)
            ->assertJsonPath('data.coupon_code', 'SECOND20');

        $removeRes = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson('/api/v1/cart/coupon');

        $removeRes->assertStatus(200)
            ->assertJsonPath('data.coupon_code', null);

        $this->assertDatabaseHas('carts', [
            'user_id'     => $this->user->id,
            'coupon_code' => null,
        ]);
    }
}
