<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\SuperAdmin\DTOs\UpdatePackageData;

final class UpdatePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        /** @var string|int|null $packageId */
        $packageId = $this->route('package');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', 'unique:central.packages,slug,'.$packageId],
            'max_users' => ['sometimes', 'integer', 'min:1'],
            'max_warehouses' => ['sometimes', 'integer', 'min:1'],
            'max_invoices_per_month' => ['sometimes', 'integer', 'min:1'],
            'features' => ['nullable', 'array'],
            'price_cents' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function toDto(): UpdatePackageData
    {
        /** @var array<string, mixed>|null $features */
        $features = $this->has('features') ? (array) $this->input('features') : null;

        return new UpdatePackageData(
            name: $this->has('name') ? (string) $this->input('name') : null,
            slug: $this->has('slug') ? (string) $this->input('slug') : null,
            maxUsers: $this->has('max_users') ? (int) $this->input('max_users') : null,
            maxWarehouses: $this->has('max_warehouses') ? (int) $this->input('max_warehouses') : null,
            maxInvoicesPerMonth: $this->has('max_invoices_per_month') ? (int) $this->input('max_invoices_per_month') : null,
            features: $features,
            priceCents: $this->has('price_cents') ? (int) $this->input('price_cents') : null,
        );
    }
}
