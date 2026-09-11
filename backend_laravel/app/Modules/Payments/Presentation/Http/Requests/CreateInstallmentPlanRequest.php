<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Installment plan creation validation (admin). The schedule amounts are
 * derived server-side by InstallmentCalculationService — never accepted here.
 */
class CreateInstallmentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'number_of_installments' => ['required', 'integer', Rule::in([3, 4, 6])],
        ];
    }
}
