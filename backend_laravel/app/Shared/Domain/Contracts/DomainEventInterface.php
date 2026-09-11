<?php

declare(strict_types=1);

namespace App\Shared\Domain\Contracts;

/**
 * DomainEventInterface
 *
 * Marker interface for all domain events.
 *
 * Domain events communicate significant state changes within a module.
 * Cross-module communication uses this interface via the event bus.
 *
 * Rules:
 *   - Events are immutable value objects (readonly).
 *   - Events must not reference Eloquent models.
 *   - Events must not contain HTTP request/response objects.
 *
 * Usage example (future tasks):
 *   final readonly class OrderPlaced implements DomainEventInterface
 *   {
 *       public function __construct(
 *           public readonly string $orderId,
 *           public readonly \DateTimeImmutable $occurredAt,
 *       ) {}
 *
 *       public function occurredAt(): \DateTimeImmutable
 *       {
 *           return $this->occurredAt;
 *       }
 *   }
 */
interface DomainEventInterface
{
    /**
     * The moment at which the domain event occurred.
     *
     * Use DateTimeImmutable to guarantee event immutability.
     */
    public function occurredAt(): \DateTimeImmutable;
}
