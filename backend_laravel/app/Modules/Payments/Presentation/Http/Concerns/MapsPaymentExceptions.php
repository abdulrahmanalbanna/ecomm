<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Concerns;

use App\Modules\Orders\Domain\Exceptions\OrderNotFoundException;
use App\Modules\Payments\Domain\Exceptions\DuplicatePaymentException;
use App\Modules\Payments\Domain\Exceptions\InvalidWebhookException;
use App\Modules\Payments\Domain\Exceptions\PaymentAlreadyExistsException;
use App\Modules\Payments\Domain\Exceptions\PaymentDomainException;
use App\Modules\Payments\Domain\Exceptions\PaymentNotFoundException;
use App\Modules\Payments\Domain\Exceptions\RefundProcessingException;
use App\Modules\Payments\Domain\Exceptions\WebhookProcessingException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Maps Payments domain exceptions to HTTP responses (mirroring the Orders
 * module pattern — controllers render their own errors; no global handler).
 *
 * Status rationale:
 *  - 400 invalid webhook (signature/structure — nothing was persisted)
 *  - 202 webhook persisted but processing failed (gateway should retry)
 *  - 404 not found (also used for cross-customer access: no existence leak)
 *  - 409 conflicts (duplicate intent, idempotency key reuse)
 *  - 422 business-rule rejections (state machine, gateway config, refund
 *    balance, installment rules, declined payment)
 *  - 502 gateway rejected an async operation (refund submission)
 */
trait MapsPaymentExceptions
{
    protected function paymentErrorResponse(Throwable $e): ?JsonResponse
    {
        // Orders lookups can fail inside payment actions too.
        if ($e instanceof OrderNotFoundException) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        if (! $e instanceof PaymentDomainException) {
            return null; // let the framework handle it
        }

        $status = match (true) {
            $e instanceof PaymentNotFoundException => 404,
            $e instanceof InvalidWebhookException => 400,
            $e instanceof WebhookProcessingException => 202,
            $e instanceof PaymentAlreadyExistsException,
            $e instanceof DuplicatePaymentException => 409,
            $e instanceof RefundProcessingException => 502,
            default => 422, // state machine, gateway config, refund balance,
                            // installment rules, declined payments
        };

        return response()->json(['message' => $e->getMessage()], $status);
    }
}
