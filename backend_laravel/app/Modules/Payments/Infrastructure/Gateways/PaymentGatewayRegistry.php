<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Gateways;

use App\Modules\Payments\Domain\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\Domain\Exceptions\PaymentGatewayException;
use App\Modules\Payments\Infrastructure\Gateways\Tabby\TabbyGateway;
use App\Modules\Payments\Infrastructure\Gateways\Tamara\TamaraGateway;

/**
 * PaymentGatewayRegistry — maps gateway codes ('tabby', 'tamara') to their
 * concrete processor implementations.
 *
 * This is the ONLY place where gateway-specific classes are referenced
 * outside the gateways themselves. Application services resolve processors
 * through this registry, keeping the domain free of infrastructure coupling.
 * Tests may bind a fake PaymentGatewayInterface into the container or
 * swap registry entries via Http::fake() at the transport level.
 */
final class PaymentGatewayRegistry
{
    /**
     * @var array<string, PaymentGatewayInterface>
     */
    private array $gateways = [];

    public function __construct()
    {
        // Built-in processors. Additional providers register here (or via
        // register()) without touching application code.
        $this->register(new TabbyGateway());
        $this->register(new TamaraGateway());
    }

    public function register(PaymentGatewayInterface $gateway): void
    {
        $this->gateways[$gateway->code()] = $gateway;
    }

    public function has(string $code): bool
    {
        return isset($this->gateways[$code]);
    }

    /**
     * Resolve a processor by code.
     *
     * @throws PaymentGatewayException when the code has no processor
     */
    public function resolve(string $code): PaymentGatewayInterface
    {
        if (! isset($this->gateways[$code])) {
            throw PaymentGatewayException::unknown($code);
        }

        return $this->gateways[$code];
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->gateways);
    }
}
