<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\DTOs\CreateWarehouseData;

final class StoreWarehouseRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:50', Rule::unique('tenant.warehouses', 'code')],
            'address' => ['nullable', 'string'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function toDto(): CreateWarehouseData
    {
        $validated = $this->validated();

        return new CreateWarehouseData(
            name: (string) $validated['name'],
            code: (string) $validated['code'],
            address: array_key_exists('address', $validated) && $validated['address'] !== null ? (string) $validated['address'] : null,
            isDefault: isset($validated['is_default']) ? (bool) $validated['is_default'] : false,
            isActive: isset($validated['is_active']) ? (bool) $validated['is_active'] : true,
        );
    }
}
