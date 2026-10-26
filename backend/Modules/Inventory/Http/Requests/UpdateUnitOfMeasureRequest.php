<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\DTOs\UpdateUnitOfMeasureData;

final class UpdateUnitOfMeasureRequest extends FormRequest
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
        /** @var string|null $uomId */
        $uomId = $this->route('units_of_measure') ?? $this->route('unit_of_measure') ?? $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:50'],
            'symbol' => [
                'sometimes',
                'required',
                'string',
                'max:15',
                Rule::unique('tenant.units_of_measure', 'symbol')->ignore($uomId),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function toDto(): UpdateUnitOfMeasureData
    {
        $validated = $this->validated();

        return new UpdateUnitOfMeasureData(
            name: isset($validated['name']) ? (string) $validated['name'] : null,
            symbol: isset($validated['symbol']) ? (string) $validated['symbol'] : null,
            isActive: isset($validated['is_active']) ? (bool) $validated['is_active'] : null,
        );
    }
}
