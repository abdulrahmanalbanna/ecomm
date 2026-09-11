<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Exceptions;

/**
 * Thrown when checkout is attempted with an empty (or already cleared) cart.
 * Also serves as the duplicate-checkout guard: a concurrent second checkout
 * sees the cart cleared by the first and receives this error.
 */
class EmptyCartCheckoutException extends OrderDomainException
{
    public function __construct(string $message = 'Your cart is empty. Add items before checking out.')
    {
        parent::__construct($message);
    }
}
