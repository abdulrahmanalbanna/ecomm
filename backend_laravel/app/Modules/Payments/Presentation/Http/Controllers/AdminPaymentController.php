<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Controllers;

use App\Modules\Payments\Application\Actions\CreateInstallmentPlanAction;
use App\Modules\Payments\Application\Actions\CreateRefundAction;
use App\Modules\Payments\Application\Actions\GetPaymentAction;
use App\Modules\Payments\Application\Actions\ListPaymentsAction;
use App\Modules\Payments\Application\Actions\ReconcilePaymentAction;
use App\Modules\Payments\Application\Actions\SettleInstallmentAction;
use App\Modules\Payments\Application\DTOs\CreateInstallmentPlanData;
use App\Modules\Payments\Application\DTOs\CreateRefundData;
use App\Modules\Payments\Application\DTOs\PaymentListFiltersData;
use App\Modules\Payments\Application\DTOs\ReconcilePaymentData;
use App\Modules\Payments\Application\DTOs\SettleInstallmentData;
use App\Modules\Payments\Presentation\Http\Concerns\MapsPaymentExceptions;
use App\Modules\Payments\Presentation\Http\Requests\CreateInstallmentPlanRequest;
use App\Modules\Payments\Presentation\Http\Requests\CreateRefundRequest;
use App\Modules\Payments\Presentation\Http\Requests\SettleInstallmentRequest;
use App\Modules\Payments\Presentation\Http\Resources\InstallmentResource;
use App\Modules\Payments\Presentation\Http\Resources\PaymentAdminResource;
use App\Modules\Payments\Presentation\Http\Resources\RefundResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Admin payment endpoints (RBAC via payments.* permissions).
 *
 * Installment plan management is an operational ledger task and is gated by
 * payments.view (the seeded staff role); refunds require payments.refund and
 * reconciliation requires payments.reconcile.
 */
class AdminPaymentController
{
    use MapsPaymentExceptions;

    public function __construct(
        private readonly GetPaymentAction $getPayment,
        private readonly ListPaymentsAction $listPayments,
        private readonly CreateRefundAction $createRefund,
        private readonly ReconcilePaymentAction $reconcile,
        private readonly CreateInstallmentPlanAction $createPlan,
        private readonly SettleInstallmentAction $settleInstallment,
    ) {
    }

    /**
     * GET /api/v1/admin/payments
     */
    public function index(Request $request): JsonResponse
    {
        $filters = new PaymentListFiltersData(
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
            'data' => PaymentAdminResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/payments/{publicId}
     */
    public function show(string $publicId): JsonResponse
    {
        try {
            $payment = $this->getPayment->forAdmin($publicId);
        } catch (Throwable $e) {
            return $this->paymentErrorResponse($e) ?? throw $e;
        }

        return response()->json(['data' => new PaymentAdminResource($payment)]);
    }

    /**
     * POST /api/v1/admin/payments/{publicId}/refunds
     */
    public function refund(CreateRefundRequest $request, string $publicId): JsonResponse
    {
        $dto = new CreateRefundData(
            paymentPublicId: $publicId,
            amount: (string) $request->input('amount'),
            reason: $request->input('reason'),
            initiatedBy: (int) $request->user()->id,
            idempotencyKey: $request->input('idempotency_key'),
        );

        try {
            $refund = $this->createRefund->execute($dto);
        } catch (Throwable $e) {
            return $this->paymentErrorResponse($e) ?? throw $e;
        }

        return response()->json([
            'message' => 'Refund initiated',
            'data' => new RefundResource($refund->load('payment')),
        ], 201);
    }

    /**
     * POST /api/v1/admin/payments/{publicId}/reconcile
     *
     * Queries the gateway, reports discrepancies, and applies conservative
     * corrections (missing charge settlement, legal status path).
     */
    public function reconcile(Request $request, string $publicId): JsonResponse
    {
        $dto = new ReconcilePaymentData(
            paymentPublicId: $publicId,
            performedBy: (int) $request->user()->id,
        );

        try {
            $result = $this->reconcile->execute($dto);
        } catch (Throwable $e) {
            return $this->paymentErrorResponse($e) ?? throw $e;
        }

        return response()->json([
            'data' => [
                'report' => $result['report'],
                'corrections' => $result['corrections'],
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/payments/{publicId}/installments
     */
    public function storeInstallmentPlan(CreateInstallmentPlanRequest $request, string $publicId): JsonResponse
    {
        $dto = new CreateInstallmentPlanData(
            paymentPublicId: $publicId,
            numberOfInstallments: (int) $request->input('number_of_installments'),
            createdBy: (int) $request->user()->id,
        );

        try {
            $plan = $this->createPlan->execute($dto);
        } catch (Throwable $e) {
            return $this->paymentErrorResponse($e) ?? throw $e;
        }

        return response()->json([
            'message' => 'Installment plan created',
            'data' => [
                'number_of_installments' => (int) $plan->number_of_installments,
                'installment_amount' => (string) $plan->installment_amount,
                'frequency' => $plan->frequency,
                'first_payment_date' => $plan->first_payment_date?->toDateString(),
                'status' => $plan->status,
                'installments' => InstallmentResource::collection(
                    $plan->installments()->orderBy('installment_number')->get(),
                ),
            ],
        ], 201);
    }

    /**
     * POST /api/v1/admin/installments/{id}/settle
     */
    public function settleInstallment(SettleInstallmentRequest $request, int $id): JsonResponse
    {
        $dto = new SettleInstallmentData(
            installmentId: $id,
            gatewayTransactionId: (string) $request->input('gateway_transaction_id'),
            settledAt: $request->input('settled_at'),
            metadata: (array) $request->input('metadata', []),
        );

        try {
            $installment = $this->settleInstallment->execute($dto);
        } catch (Throwable $e) {
            return $this->paymentErrorResponse($e) ?? throw $e;
        }

        return response()->json([
            'message' => 'Installment settled',
            'data' => new InstallmentResource($installment),
        ]);
    }
}
