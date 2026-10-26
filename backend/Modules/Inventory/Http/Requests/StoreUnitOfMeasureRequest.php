<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\DTOs\CreateUnitOfMeasureData;

final class StoreUnitOfMeasureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'symbol' => ['required', 'string', 'max:15', Rule::unique('tenant.units_of_measure', 'symbol')],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function toDto(): CreateUnitOfMeasureData
    {
        $validated = $this->validated();

        return new CreateUnitOfMeasureData(
            name: (string) $validated['name'],
            symbol: (string) $validated['symbol'],
            isActive: isset($validated['is_active']) ? (bool) $validated['is_active'] : true,
        );
    }
}
