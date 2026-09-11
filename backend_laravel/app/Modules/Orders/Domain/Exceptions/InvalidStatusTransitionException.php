<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Exceptions;

/**
 * Thrown when a requested status transition violates the PostgreSQL state
 * machine enforced by fn_validate_order_status_transition(). The database
 * trigger remains the authoritative guard; this exception mirrors the
 * adjacency map to return friendly 422 responses before hitting the DB.
 */
class InvalidStatusTransitionException extends OrderDomainException
{
    public static function between(string $from, string $to): self
    {
        return new self("Invalid status transition: cannot move an order from '{$from}' to '{$to}'.");
    }
}
