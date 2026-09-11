<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class PostgresTextArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '' || $value === '{}') {
            return [];
        }

        if (is_array($value)) {
            return array_values($value);
        }

        $trimmed = trim((string) $value, '{}');
        if ($trimmed === '') {
            return [];
        }

        return str_getcsv($trimmed);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value === null || $value === [] || $value === '') {
            return '{}';
        }

        if (is_string($value) && str_starts_with($value, '{') && str_ends_with($value, '}')) {
            return $value;
        }

        $items = is_array($value) ? array_values($value) : [$value];
        $escaped = array_map(fn ($item) => '"' . str_replace('"', '\"', (string) $item) . '"', $items);

        return '{' . implode(',', $escaped) . '}';
    }
}
