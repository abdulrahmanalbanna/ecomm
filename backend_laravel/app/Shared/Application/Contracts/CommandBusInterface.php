<?php

declare(strict_types=1);

namespace App\Shared\Application\Contracts;

/**
 * CommandBusInterface
 *
 * Contract for dispatching commands (write operations) in the CQRS pattern.
 *
 * This is a Shared contract because any module can dispatch commands.
 * The concrete implementation lives in Infrastructure and will be
 * bound in AppServiceProvider or a dedicated Shared provider.
 *
 * For Task 01 this is a forward-declaration — not yet bound.
 * When implementing Task 02+, bind this in AppServiceProvider:
 *
 *   $this->app->bind(CommandBusInterface::class, LaravelCommandBus::class);
 *
 * Usage in an Application Action or Controller:
 *   public function __construct(private readonly CommandBusInterface $bus) {}
 *
 *   public function store(CreateProductRequest $request): JsonResponse
 *   {
 *       $this->bus->dispatch(new CreateProductCommand($request->validated()));
 *       return response()->json([], 201);
 *   }
 */
interface CommandBusInterface
{
    /**
     * Dispatch a command and return the result (if any).
     *
     * @param  object $command  Any command object. No base class required.
     * @return mixed            Return value depends on the command handler.
     */
    public function dispatch(object $command): mixed;
}
