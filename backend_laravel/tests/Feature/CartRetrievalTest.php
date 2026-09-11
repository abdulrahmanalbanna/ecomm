<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Cart\Infrastructure\Persistence\Models\Cart;

use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CartRetrievalTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::where('name', 'customer')->first() ?? Role::first();
        $this->user = User::create([
            'email'             => 'cart_ret_' . bin2hex(random_bytes(4)) . '@example.com',
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

    public function test_get_cart_lazy_creates_persistent_empty_cart(): void
    {
        $this->assertDatabaseMissing('carts', ['user_id' => $this->user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/v1/cart');

        $response->assertStatus(200)
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.items_count', 0)
            ->assertJsonPath('data.total_quantity', 0)
            ->assertJsonPath('data.subtotal', '0.00');

        $this->assertDatabaseHas('carts', ['user_id' => $this->user->id]);
    }

    public function test_second_retrieval_returns_same_persistent_cart_without_duplication(): void
    {
        $response1 = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/v1/cart');
        $response1->assertStatus(200);
        $cartId1 = $response1->json('data.id');

        $response2 = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/v1/cart');
        $response2->assertStatus(200);
        $cartId2 = $response2->json('data.id');

        $this->assertEquals($cartId1, $cartId2);
        $this->assertEquals(1, Cart::where('user_id', $this->user->id)->count());
    }
}
