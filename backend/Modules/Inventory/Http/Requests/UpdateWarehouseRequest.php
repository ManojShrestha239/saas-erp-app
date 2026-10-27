<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\DTOs\UpdateWarehouseData;

final class UpdateWarehouseRequest extends FormRequest
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
        /** @var string|null $warehouseId */
        $warehouseId = $this->route('warehouse') ?? $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('tenant.warehouses', 'code')->ignore($warehouseId),
            ],
            'address' => ['nullable', 'string'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function toDto(): UpdateWarehouseData
    {
        $validated = $this->validated();

        return new UpdateWarehouseData(
            name: isset($validated['name']) ? (string) $validated['name'] : null,
            code: isset($validated['code']) ? (string) $validated['code'] : null,
            address: array_key_exists('address', $validated) ? ($validated['address'] !== null ? (string) $validated['address'] : null) : null,
            isDefault: isset($validated['is_default']) ? (bool) $validated['is_default'] : null,
            isActive: isset($validated['is_active']) ? (bool) $validated['is_active'] : null,
        );
    }
}
