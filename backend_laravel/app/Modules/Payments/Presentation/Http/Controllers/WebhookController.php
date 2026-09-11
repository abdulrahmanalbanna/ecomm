<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Controllers;

use App\Modules\Payments\Application\Actions\ProcessPaymentWebhookAction;
use App\Modules\Payments\Application\DTOs\ProcessWebhookData;
use App\Modules\Payments\Domain\Exceptions\InvalidWebhookException;
use App\Modules\Payments\Domain\Exceptions\WebhookProcessingException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gateway webhook endpoints — NO customer authentication (gateways cannot
 * hold user tokens). Security comes from HMAC signature verification inside
 * ProcessPaymentWebhookAction, performed BEFORE anything is persisted.
 *
 * Response contract (gateway-friendly):
 *  - 200 {processed: true}            event applied (or duplicate re-delivery)
 *  - 400                              bad signature / malformed event — the
 *                                     gateway should stop retrying; NOTHING
 *                                     was persisted
 *  - 202 {processed: false}           event stored but processing failed —
 *                                     the gateway SHOULD retry; the raw event
 *                                     is preserved for replay
 */
class WebhookController
{
    private const SIGNATURE_HEADERS = [
        'tabby' => 't-signature',
        'tamara' => 'x-tamara-signature',
    ];

    public function __construct(
        private readonly ProcessPaymentWebhookAction $processWebhook,
    ) {
    }

    /**
     * POST /api/v1/webhooks/tabby
     */
    public function tabby(Request $request): JsonResponse
    {
        return $this->handle('tabby', $request);
    }

    /**
     * POST /api/v1/webhooks/tamara
     */
    public function tamara(Request $request): JsonResponse
    {
        return $this->handle('tamara', $request);
    }

    private function handle(string $gatewayCode, Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();

        $payload = json_decode($rawBody, true);

        if (! \is_array($payload)) {
            return response()->json(
                ['message' => InvalidWebhookException::malformedPayload()->getMessage()],
                400,
            );
        }

        $signatureHeader = $request->header(
            self::SIGNATURE_HEADERS[$gatewayCode] ?? 'signature',
        );

        $dto = new ProcessWebhookData(
            gatewayCode: $gatewayCode,
            rawBody: $rawBody,
            payload: $payload,
            signatureHeader: $signatureHeader !== null ? (string) $signatureHeader : null,
        );

        try {
            $result = $this->processWebhook->execute($dto);
        } catch (InvalidWebhookException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        } catch (WebhookProcessingException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'processed' => false,
            ], 202);
        }

        return response()->json([
            'message' => $result['duplicate'] ? 'Event already received' : 'Event processed',
            'processed' => $result['processed'],
            'duplicate' => $result['duplicate'],
            'effect' => $result['effect'],
            'payment_public_id' => $result['payment_public_id'],
        ]);
    }
}
