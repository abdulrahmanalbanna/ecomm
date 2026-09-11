<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Controllers;

use App\Modules\Payments\Application\Actions\CreatePaymentIntentAction;
use App\Modules\Payments\Application\Actions\GetPaymentAction;
use App\Modules\Payments\Application\Actions\ListPaymentsAction;
use App\Modules\Payments\Application\Actions\RetryPaymentAction;
use App\Modules\Payments\Application\DTOs\CreatePaymentIntentData;
use App\Modules\Payments\Application\DTOs\PaymentListFiltersData;
use App\Modules\Payments\Application\DTOs\RetryPaymentData;
use App\Modules\Payments\Presentation\Http\Concerns\MapsPaymentExceptions;
use App\Modules\Payments\Presentation\Http\Requests\CreatePaymentIntentRequest;
use App\Modules\Payments\Presentation\Http\Requests\RetryPaymentRequest;
use App\Modules\Payments\Presentation\Http\Resources\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Customer payment endpoints.
 *
 * All access is ownership-scoped at the application layer (Payment::forUser),
 * so another customer's payment resolves to 404 rather than 403. Responses
 * use the customer-safe PaymentResource: no internal ids, no idempotency
 * keys, no raw gateway payloads, no ledger detail.
 */
class CustomerPaymentController
{
    use MapsPaymentExceptions;

    public function __construct(
        private readonly CreatePaymentIntentAction $createIntent,
        private readonly RetryPaymentAction $retryPayment,
        private readonly GetPaymentAction $getPayment,
        private readonly ListPaymentsAction $listPayments,
    ) {
    }

    /**
     * POST /api/v1/customer/payments
     */
    public function store(CreatePaymentIntentRequest $request): JsonResponse
    {
        $dto = new CreatePaymentIntentData(
            orderPublicId: (string) $request->input('order_public_id'),
            userId: (int) $request->user()->id,
            gatewayCode: (string) $request->input('gateway'),
            paymentMethod: (string) $request->input('payment_method'),
            numberOfInstallments: $request->input('number_of_installments') !== null
                ? (int) $request->input('number_of_installments')
                : null,
            idempotencyKey: $request->input('idempotency_key'),
        );

        try {
            $payment = $this->createIntent->execute($dto);
        } catch (Throwable $e) {
            return $this->paymentErrorResponse($e) ?? throw $e;
        }

        return response()->json([
            'message' => 'Payment intent created',
            'data' => new PaymentResource($payment),
        ], 201);
    }

    /**
     * GET /api/v1/customer/payments
     */
    public function index(Request $request): JsonResponse
    {
        $filters = new PaymentListFiltersData(
            userId: (int) $request->user()->id,
            status: $request->query('status'),
            gatewayCode: $request->query('gateway'),
            paymentMethod: $request->query('payment_method'),
            dateFrom: $request->query('date_from'),
            dateTo: $request->query('date_to'),
            page: max(1, (int) $request->query('page', '1')),
            perPage: min(100, max(1, (int) $request->query('per_page', '15'))),
        );

        $paginator = $this->listPayments->execute($filters);

        return response()->json([
            'data' => PaymentResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/customer/payments/{publicId}
     */
    public function show(Request $request, string $publicId): JsonResponse
    {
        try {
            $payment = $this->getPayment->forUser($publicId, (int) $request->user()->id);
        } catch (Throwable $e) {
            return $this->paymentErrorResponse($e) ?? throw $e;
        }

        return response()->json(['data' => new PaymentResource($payment)]);
    }

    /**
     * POST /api/v1/customer/payments/{publicId}/retry
     */
    public function retry(RetryPaymentRequest $request, string $publicId): JsonResponse
    {
        $dto = new RetryPaymentData(
            paymentPublicId: $publicId,
            userId: (int) $request->user()->id,
            idempotencyKey: $request->input('idempotency_key'),
        );

        try {
            $payment = $this->retryPayment->execute($dto);
        } catch (Throwable $e) {
            return $this->paymentErrorResponse($e) ?? throw $e;
        }

        return response()->json([
            'message' => 'Payment retry initiated',
            'data' => new PaymentResource($payment),
        ]);
    }
}
