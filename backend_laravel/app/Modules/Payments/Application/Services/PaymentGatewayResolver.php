<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Services;

use App\Modules\Payments\Domain\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\Domain\Exceptions\PaymentGatewayException;
use App\Modules\Payments\Domain\Exceptions\PaymentGatewayUnavailableException;
use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Payments\Infrastructure\Gateways\PaymentGatewayRegistry;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentGateway;

/**
 * PaymentGatewayResolver — reusable, stateless resolution of a gateway code
 * into BOTH its DB row (payment_gateways) and its executable processor
 * (Infrastructure/Gateways).
 *
 * Validation performed here (friendly 422s):
 *  - the code exists in payment_gateways          → PaymentGatewayException
 *  - the row is active                            → PaymentGatewayUnavailableException
 *  - the gateway supports the payment method      → PaymentGatewayUnavailableException
 *  - installment counts are within the gateway's
 *    configured options (3/6 for Tamara, 4 for Tabby)
 *  - the currency matches the gateway config
 *
 * The DB row is the business/config boundary; the processor is the transport
 * boundary. Actions depend only on this service + the registry contract.
 */
final class PaymentGatewayResolver
{
    public function __construct(
        private readonly PaymentGatewayRegistry $registry,
    ) {
    }

    /**
     * Resolve the DB row for a gateway code.
     *
     * @throws PaymentGatewayException
     * @throws PaymentGatewayUnavailableException
     */
    public function resolveRow(string $code): PaymentGateway
    {
        /** @var PaymentGateway|null $row */
        $row = PaymentGateway::where('code', $code)->first();

        if ($row === null) {
            throw PaymentGatewayException::unknown($code);
        }

        if (! $row->is_active) {
            throw PaymentGatewayUnavailableException::inactive($code);
        }

        return $row;
    }

    /**
     * Resolve the executable processor for a gateway code.
     *
     * @throws PaymentGatewayException when no processor is registered
     */
    public function resolveProcessor(string $code): PaymentGatewayInterface
    {
        return $this->registry->resolve($code);
    }

    /**
     * Full resolution: DB row + processor, with method/installment/currency
     * validation against the row's configuration.
     *
     * @return array{row: PaymentGateway, processor: PaymentGatewayInterface}
     *
     * @throws PaymentGatewayException
     * @throws PaymentGatewayUnavailableException
     */
    public function resolveForPayment(
        string $code,
        string $paymentMethod,
        ?int $numberOfInstallments = null,
        ?string $currency = null,
    ): array {
        $row = $this->resolveRow($code);

        if (! PaymentMethod::isKnown($paymentMethod)) {
            throw PaymentGatewayUnavailableException::unsupportedMethod($code, $paymentMethod);
        }

        // 'full' is universally supported; 'installment' requires the gateway
        // row to advertise it (payment_gateways.supports_installments).
        if ($paymentMethod === PaymentMethod::INSTALLMENT && ! $row->supports_installments) {
            throw PaymentGatewayUnavailableException::unsupportedMethod($code, $paymentMethod);
        }

        if ($paymentMethod === PaymentMethod::INSTALLMENT) {
            if ($numberOfInstallments === null) {
                throw PaymentGatewayUnavailableException::unsupportedInstallments($code, 0);
            }

            if (! $row->supportsInstallmentCount($numberOfInstallments)) {
                throw PaymentGatewayUnavailableException::unsupportedInstallments($code, $numberOfInstallments);
            }
        }

        if ($currency !== null && ! hash_equals($row->currency(), $currency)) {
            throw PaymentGatewayUnavailableException::unsupportedCurrency($code, $currency);
        }

        return ['row' => $row, 'processor' => $this->resolveProcessor($code)];
    }
}
