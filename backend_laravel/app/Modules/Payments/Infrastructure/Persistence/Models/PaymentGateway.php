<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Persistence\Models;

use App\Shared\Infrastructure\Database\Casts\JsonObjectCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PaymentGateway — maps to payment_gateways (database/sql/010_payment_gateways.sql).
 *
 * SEED: 'tabby' (4 installments), 'tamara' (3 or 6 installments).
 * `config` JSONB holds NON-SENSITIVE configuration only (api_version,
 * webhook_path, installment_options, checkout_url, currency, merchant_urls).
 * API keys/secrets live exclusively in config/payments.php ← env vars.
 */
class PaymentGateway extends Model
{
    protected $table = 'payment_gateways';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'supports_installments',
        'is_active',
        'config',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supports_installments' => 'boolean',
            'is_active'             => 'boolean',
            'config'                => JsonObjectCast::class,
            'created_at'            => 'datetime',
            'updated_at'            => 'datetime',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'gateway_id');
    }

    /**
     * Installment counts this gateway supports (from non-sensitive config).
     *
     * @return list<int>
     */
    public function installmentOptions(): array
    {
        $options = $this->config['installment_options'] ?? [];

        return array_values(array_map('intval', is_array($options) ? $options : []));
    }

    public function supportsInstallmentCount(int $count): bool
    {
        return $this->supports_installments
            && $this->is_active
            && in_array($count, $this->installmentOptions(), true);
    }

    public function currency(): string
    {
        return (string) ($this->config['currency'] ?? 'SAR');
    }
}
