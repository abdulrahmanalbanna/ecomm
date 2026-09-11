<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions\Concerns;

use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;

/**
 * MatchesWebhookPayload — shared payload helpers for webhook actions.
 *
 * Gateway payloads differ (Tabby: payment.payment_id / checkout_id;
 * Tamara: paymentId / checkoutId), so we probe a list of dot-paths and match
 * the first reference that resolves to a local payment.
 */
trait MatchesWebhookPayload
{
    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $paths dot-separated candidate paths
     */
    protected function extractString(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $cursor = $payload;

            foreach (explode('.', $path) as $segment) {
                if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                    $cursor = null;
                    break;
                }

                $cursor = $cursor[$segment];
            }

            if (is_scalar($cursor) && (string) $cursor !== '') {
                return (string) $cursor;
            }
        }

        return null;
    }

    /**
     * Resolve the local payment a webhook payload refers to.
     *
     * @param array<string, mixed> $payload
     */
    protected function matchPayment(array $payload): ?Payment
    {
        $candidates = array_values(array_filter([
            $this->extractString($payload, ['payment.id', 'payment_id', 'paymentId']),
            $this->extractString($payload, ['checkout.id', 'checkout_id', 'checkoutId']),
            $this->extractString($payload, ['payment.reference_code', 'reference_code', 'referenceCode', 'merchant_reference', 'order.number']),
        ]));

        foreach ($candidates as $reference) {
            /** @var Payment|null $payment */
            $payment = Payment::where('gateway_payment_id', $reference)->first();

            if ($payment !== null) {
                return $payment;
            }

            // public_id is a UUID column — comparing a gateway session string
            // against it raises 22P02 in PostgreSQL. Only UUID-shaped
            // references may match our own public id.
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $reference) === 1) {
                /** @var Payment|null $byPublicId */
                $byPublicId = Payment::where('public_id', $reference)->first();

                if ($byPublicId !== null) {
                    return $byPublicId;
                }
            }
        }

        return null;
    }
}
