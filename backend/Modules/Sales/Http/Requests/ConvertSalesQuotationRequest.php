<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConvertSalesQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->can('sales.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'due_date' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'string', Rule::exists('tenant.warehouses', 'id')],
            'post_immediately' => ['nullable', 'boolean'],
        ];
    }
}
