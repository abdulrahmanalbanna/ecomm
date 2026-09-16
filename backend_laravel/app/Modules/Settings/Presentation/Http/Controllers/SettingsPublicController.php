<?php

declare(strict_types=1);

namespace App\Modules\Settings\Presentation\Http\Controllers;

use App\Modules\Settings\Application\Services\SettingsService;
use App\Modules\Settings\Presentation\Http\Resources\HomepageResource;
use App\Modules\Settings\Presentation\Http\Resources\PublicSettingsResource;
use Illuminate\Http\JsonResponse;

/**
 * SettingsPublicController
 *
 * Public (unauthenticated) storefront settings endpoints.
 *
 *   GET /api/v1/settings       → all whitelisted public settings
 *   GET /api/v1/settings/{key} → single setting (dots allowed, e.g. store.name)
 */
class SettingsPublicController
{
    public function __construct(
        private readonly SettingsService $settings
    ) {}

    public function index(): JsonResponse
    {
        $payload = $this->settings->toPublicPayload($this->settings->all());

        // PublicSettingsResource already wraps the payload in a `data`
        // envelope, so return it directly instead of nesting it inside
        // another `data` key (which would produce data.data.*).
        return (new PublicSettingsResource($payload))->response();
    }

    /**
     * GET /api/v1/homepage
     *
     * The service owns visibility, decoding, activation, and normalization.
     */
    public function homepage(): JsonResponse
    {
        $payload = $this->settings->toHomepagePayload($this->settings->all());

        return (new HomepageResource($payload))->response();
    }

    public function show(string $key): JsonResponse
    {
        // Route uses ->where('key', '.*') so dotted keys arrive intact.
        $value = $this->settings->get($key);

        if ($value === null && ! array_key_exists($key, $this->settings->all())) {
            return response()->json(['message' => 'Setting not found'], 404);
        }

        return response()->json([
            'data' => [
                'key'   => $key,
                'value' => $value,
            ],
        ]);
    }
}
