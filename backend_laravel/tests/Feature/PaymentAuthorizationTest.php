<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaymentsTestHelpers;
use Tests\TestCase;

/**
 * Authorization boundaries for the Payments module:
 *  - RBAC permissions gate each admin lifecycle surface independently
 *    (payments.view / payments.refund / payments.reconcile)
 *  - customer surfaces are ownership-scoped (another customer's payment is a
 *    404, not a 403, so existence is never leaked)
 *  - webhook routes are public but signature-verified (covered in
 *    PaymentWebhookSecurityTest; here we only assert they need no auth)
 */
final class PaymentAuthorizationTest extends TestCase
{
    use DatabaseTransactions;
    use PaymentsTestHelpers;

    public function test_admin_with_full_permissions_can_view_refund_and_reconcile(): void
    {
        $fixture = $this->makePaidPayment('tabby', '100.00');
        $payment = $fixture['payment'];
        $admin = $this->makeStaff('admin');
        $adminToken = $this->issueToken($admin);

        $this->fakeGatewayHttp([
            'api.tabby.ai/*' => Http::response([
                'id' => 'tabby_ref_admin_ok',
                'status' => 'CREATED',
            ]),
        ]);

        $this->authToken($adminToken)
            ->getJson("/api/v1/admin/payments/{$payment->public_id}")
            ->assertStatus(200)
            ->assertJsonPath('data.public_id', (string) $payment->public_id);

        $this->authToken($adminToken)
            ->postJson("/api/v1/admin/payments/{$payment->public_id}/refunds", [
                'amount' => '40.00',
                'reason' => 'admin test refund',
            ])
            ->assertStatus(201);

        $this->authToken($adminToken)
            ->postJson("/api/v1/admin/payments/{$payment->public_id}/reconcile")
            ->assertStatus(200);
    }

    public function test_staff_without_payments_refund_cannot_initiate_refunds(): void
    {
        $fixture = $this->makePaidPayment('tabby', '100.00');
        $payment = $fixture['payment'];

        // Seeded staff role has payments.view + payments.reconcile but NOT
        // payments.refund.
        [, $staffToken] = $this->makePaymentStaff([]);

        $this->authToken($staffToken)
            ->getJson("/api/v1/admin/payments/{$payment->public_id}")
            ->assertStatus(200);

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$payment->public_id}/refunds", [
                'amount' => '10.00',
            ])
            ->assertStatus(403);

        // The refund was never created.
        $this->assertSame(
            0,
            \Illuminate\Support\Facades\DB::table('refunds')->where('payment_id', $payment->id)->count(),
        );
    }

    public function test_customer_cannot_reach_admin_payment_routes(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00');
        $payment = $fixture['payment'];

        // Customer token → 403 on every admin surface.
        $customerToken = $fixture['token'];

        $this->authToken($customerToken)
            ->getJson('/api/v1/admin/payments')
            ->assertStatus(403);

        $this->authToken($customerToken)
            ->postJson("/api/v1/admin/payments/{$payment->public_id}/refunds", ['amount' => '10.00'])
            ->assertStatus(403);

        $this->authToken($customerToken)
            ->postJson("/api/v1/admin/payments/{$payment->public_id}/reconcile")
            ->assertStatus(403);
    }

    public function test_admin_routes_require_authentication(): void
    {
        $this->flushHeaders();

        $this->getJson('/api/v1/admin/payments')->assertStatus(401);
        $this->postJson('/api/v1/admin/payments/' . \Illuminate\Support\Str::uuid() . '/refunds', ['amount' => '1.00'])
            ->assertStatus(401);
    }

    public function test_customer_cannot_view_or_retry_another_customers_payment(): void
    {
        $owner = $this->makeProcessingPayment('tabby', '100.00');
        $intruder = $this->makeCustomer();
        $intruderToken = $this->issueToken($intruder);

        $publicId = (string) $owner['payment']->public_id;

        // Ownership is enforced at the application layer → 404 (no leak).
        $this->authToken($intruderToken)
            ->getJson("/api/v1/customer/payments/{$publicId}")
            ->assertStatus(404);

        $this->authToken($intruderToken)
            ->postJson("/api/v1/customer/payments/{$publicId}/retry")
            ->assertStatus(404);

        // The owner still sees their own payment.
        $this->flushHeaders();
        $this->authToken($owner['token'])
            ->getJson("/api/v1/customer/payments/{$publicId}")
            ->assertStatus(200);
    }

    public function test_customer_payment_routes_require_authentication(): void
    {
        $this->flushHeaders();

        $this->getJson('/api/v1/customer/payments')->assertStatus(401);
        $this->postJson('/api/v1/customer/payments', [
            'order_public_id' => \Illuminate\Support\Str::uuid()->toString(),
            'gateway' => 'tabby',
        ])->assertStatus(401);
    }

    public function test_webhook_routes_are_public_but_signature_protected(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00');

        // No auth header needed…
        // …but an unsigned/invalid delivery is rejected with 400 and changes
        // nothing (full matrix in PaymentWebhookSecurityTest).
        $this->postJson('/api/v1/webhooks/tabby', [
            'id' => $this->uniqueEventId('noauth'),
            'event' => 'payment.paid',
            'payment_id' => $fixture['session_id'],
        ])->assertStatus(400);

        $this->assertSame('processing', (string) $fixture['payment']->fresh()->status);
    }
}
