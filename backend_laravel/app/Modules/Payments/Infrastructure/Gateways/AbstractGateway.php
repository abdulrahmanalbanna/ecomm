<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Gateways;

use App\Modules\Payments\Domain\Contracts\GatewayResult;
use App\Modules\Payments\Domain\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\Domain\Exceptions\PaymentProcessingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * AbstractGateway — shared HTTP plumbing for concrete gateway processors.
 *
 * Responsibilities:
 *  - env-sourced credentials (config/payments.php) — never from the DB
 *  - explicit timeouts and transport-error handling (ConnectionException →
 *    PaymentProcessingException so callers never corrupt payment state)
 *  - PCI-DSS redaction of gateway responses BEFORE persistence: any
 *    credential/card-like key is stripped from the JSONB snapshot
 *  - HMAC-SHA256 webhook signature verification over the raw request body
 */
abstract class AbstractGateway implements PaymentGatewayInterface
{
    /**
     * Normalized key names that must NEVER be persisted in gateway_response
     * JSONB. Keys are normalized (lowercased, non-alphanumerics stripped)
     * before comparison, so 'api_key', 'apiKey' and 'API-KEY' all match.
     *
     * Exact matches:
     * @var list<string>
     */
    private const REDACT_EXACT_KEYS = [
        'apikey', 'publickey', 'privatekey', 'secret', 'token', 'accesstoken',
        'refreshtoken', 'authorization', 'password', 'passwd', 'cvv', 'cvc',
        'cid', 'pan', 'pin', 'jwt', 'bearer', 'cryptogram', 'expiry',
        'expirationdate', 'cardnumber', 'cardholder', 'accountnumber',
        'securitycode', 'verificationcode',
    ];

    /**
     * Substring matches (multi-char only, to avoid false positives such as
     * 'plan_id' containing 'pan' or 'shipping' containing 'pin').
     *
     * @var list<string>
     */
    private const REDACT_SUBSTRINGS = [
        'apikey', 'secret', 'token', 'privatekey', 'publickey', 'cardnumber',
        'cardholder', 'accountnumber', 'cvv', 'cvc', 'cryptogram',
        'authorization', 'password', 'securitycode', 'verificationcode',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = config("payments.gateways.{$this->code()}", []);

        return $cfg;
    }

    protected function baseUrl(): string
    {
        return rtrim((string) ($this->config()['base_url'] ?? ''), '/');
    }

    protected function timeout(): int
    {
        return max(1, (int) ($this->config()['timeout'] ?? 15));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    protected function post(string $path, array $payload, array $headers = []): Response
    {
        return $this->send('post', $path, $payload, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    protected function get(string $path, array $headers = []): Response
    {
        return $this->send('get', $path, [], $headers);
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    private function send(string $verb, string $path, array $payload, array $headers): Response
    {
        $request = Http::timeout($this->timeout())
            ->acceptJson()
            ->withHeaders(array_merge($this->defaultHeaders(), $headers));

        try {
            $response = $verb === 'get'
                ? $request->get($this->baseUrl() . $path)
                : $request->post($this->baseUrl() . $path, $payload);
        } catch (ConnectionException $e) {
            throw PaymentProcessingException::transport($this->code());
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . (string) ($this->config()['api_key'] ?? ''),
        ];
    }

    /**
     * Normalize a gateway JSON body into a sanitized GatewayResult.
     *
     * @param array<string, mixed> $data
     */
    protected function normalize(array $data, bool $success, ?string $transactionId, ?string $redirectUrl, ?string $failureCode = null, ?string $failureMessage = null): GatewayResult
    {
        return new GatewayResult(
            status: $success ? GatewayResult::STATUS_SUCCESS : GatewayResult::STATUS_FAILED,
            transactionId: $transactionId,
            redirectUrl: $redirectUrl,
            failureCode: $failureCode,
            failureMessage: $failureMessage,
            raw: static::redact($data),
        );
    }

    /**
     * Recursively strip credential/card-like keys from a gateway payload
     * before it is persisted to JSONB (PCI-DSS hygiene, §36).
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (static::isSensitiveKey((string) $key)) {
                $out[$key] = '[REDACTED]';
                continue;
            }

            $out[$key] = is_array($value) ? static::redact($value) : $value;
        }

        return $out;
    }

    /**
     * True when a gateway response key looks like a credential or card field.
     */
    public static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?? '');

        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, self::REDACT_EXACT_KEYS, true)) {
            return true;
        }

        foreach (self::REDACT_SUBSTRINGS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Constant-time HMAC-SHA256 verification of an inbound webhook body
     * against the gateway's env-sourced webhook secret.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = (string) ($this->config()['webhook_secret'] ?? '');

        if ($secret === '' || $signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($signatureHeader));
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function stringAt(array $data, string $path): ?string
    {
        $cursor = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return is_scalar($cursor) ? (string) $cursor : null;
    }
}
