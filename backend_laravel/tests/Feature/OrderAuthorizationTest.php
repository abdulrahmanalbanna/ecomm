<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\OrdersTestHelpers;
use Tests\TestCase;

/**
 * RBAC + ownership for the Orders module.
 *
 *  - auth:api on every route (401 unauthenticated)
 *  - permission: middleware (403 when the role lacks the permission)
 *  - customer ownership enforced at the application layer via
 *    where('user_id', $user->id) — RLS is not part of this baseline.
 */
final class OrderAuthorizationTest extends TestCase
{
    use DatabaseTransactions;
    use OrdersTestHelpers;

    public function test_all_order_routes_require_authentication(): void
    {
        $routes = [
            ['post', '/api/v1/customer/orders/checkout'],
            ['get', '/api/v1/customer/orders'],
            ['get', '/api/v1/customer/orders/' . \Illuminate\Support\Str::uuid()->toString()],
            ['post', '/api/v1/customer/orders/' . \Illuminate\Support\Str::uuid()->toString() . '/cancel'],
            ['get', '/api/v1/customer/orders/' . \Illuminate\Support\Str::uuid()->toString() . '/events'],
            ['get', '/api/v1/admin/orders'],
            ['get', '/api/v1/admin/orders/' . \Illuminate\Support\Str::uuid()->toString()],
            ['get', '/api/v1/admin/orders/' . \Illuminate\Support\Str::uuid()->toString() . '/events'],
            ['post', '/api/v1/admin/orders/' . \Illuminate\Support\Str::uuid()->toString() . '/transition'],
            ['post', '/api/v1/admin/orders/' . \Illuminate\Support\Str::uuid()->toString() . '/cancel'],
        ];

        foreach ($routes as [$verb, $uri]) {
            $this->json($verb, $uri)->assertStatus(401, "Unauthenticated {$verb} {$uri} must be 401");
        }
    }

    public function test_staff_without_orders_permissions_is_forbidden(): void
    {
        // A role with zero orders permissions (temporary, rolls back).
        $role = Role::create(['name' => 'authz_probe_' . bin2hex(random_bytes(4))]);
        $user = User::create([
            'email'             => 'authz_probe_' . bin2hex(random_bytes(5)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => $role->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);
        $rawToken = 'authz-' . bin2hex(random_bytes(10));
        Session::create([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);

        $this->authToken($rawToken)->getJson('/api/v1/admin/orders')->assertStatus(403);
        $this->authToken($rawToken)->getJson('/api/v1/customer/orders')->assertStatus(403);
        $this->authToken($rawToken)
            ->postJson('/api/v1/customer/orders/checkout', ['shipping_address_id' => 1])
            ->assertStatus(403);
    }

    public function test_customer_cannot_access_admin_order_routes(): void
    {
        $customer = $this->makeCustomer();
        $token = $this->issueToken($customer);

        $this->authToken($token)->getJson('/api/v1/admin/orders')->assertStatus(403);
        $this->authToken($token)
            ->postJson('/api/v1/admin/orders/' . \Illuminate\Support\Str::uuid()->toString() . '/transition', ['status' => 'confirmed'])
            ->assertStatus(403);
        $this->authToken($token)
            ->postJson('/api/v1/admin/orders/' . \Illuminate\Support\Str::uuid()->toString() . '/cancel')
            ->assertStatus(403);
    }

    public function test_staff_without_cancel_permission_cannot_cancel_but_can_view(): void
    {
        $staff = $this->makeStaff('staff', ['orders.view']);
        $token = $this->issueToken($staff);

        // Remove orders.cancel/process from the staff role for this test only.
        DB::table('role_permissions')
            ->where('role_id', $staff->role_id)
            ->whereIn('permission_id', DB::table('permissions')->whereIn('code', ['orders.cancel', 'orders.process'])->pluck('id'))
            ->delete();

        $user = $this->makeCustomer();
        $userToken = $this->issueToken($user);
        $variant = $this->makeSellableVariant('10.00');
        $publicId = $this->placeOrder($user, $userToken, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // View is allowed.
        $this->authToken($token)->getJson('/api/v1/admin/orders')->assertStatus(200);
        $this->authToken($token)->getJson("/api/v1/admin/orders/{$publicId}")->assertStatus(200);

        // Cancel and transition are forbidden.
        $this->authToken($token)->postJson("/api/v1/admin/orders/{$publicId}/cancel")->assertStatus(403);
        $this->adminTransition($token, $publicId, 'payment_pending')->assertStatus(403);

        $this->assertSame('pending', Order::where('public_id', $publicId)->value('status'));
    }

    public function test_customer_cannot_read_or_cancel_another_customers_order(): void
    {
        $owner = $this->makeCustomer();
        $ownerToken = $this->issueToken($owner);
        $intruder = $this->makeCustomer();
        $intruderToken = $this->issueToken($intruder);

        $variant = $this->makeSellableVariant('10.00');
        $publicId = $this->placeOrder($owner, $ownerToken, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->authToken($intruderToken)->getJson("/api/v1/customer/orders/{$publicId}")->assertStatus(404);
        $this->authToken($intruderToken)->getJson("/api/v1/customer/orders/{$publicId}/events")->assertStatus(404);
        $this->authToken($intruderToken)->postJson("/api/v1/customer/orders/{$publicId}/cancel")->assertStatus(404);

        // Listing never surfaces the other customer's order.
        $ids = array_column(
            $this->authToken($intruderToken)->getJson('/api/v1/customer/orders')->assertStatus(200)->json('data'),
            'public_id'
        );
        $this->assertNotContains($publicId, $ids);
    }

    public function test_admin_can_view_any_customer_order(): void
    {
        $staffToken = $this->issueToken($this->makeStaff('staff'));
        $user = $this->makeCustomer();
        $userToken = $this->issueToken($user);

        $variant = $this->makeSellableVariant('10.00');
        $publicId = $this->placeOrder($user, $userToken, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->authToken($staffToken)
            ->getJson("/api/v1/admin/orders/{$publicId}")
            ->assertStatus(200)
            ->assertJsonPath('data.user.email', $user->email);
    }
}
