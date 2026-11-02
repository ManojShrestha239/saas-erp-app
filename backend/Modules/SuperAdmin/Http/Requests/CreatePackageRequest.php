<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\SuperAdmin\DTOs\CreatePackageData;

final class CreatePackageRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', 'unique:central.packages,slug'],
            'max_users' => ['required', 'integer', 'min:1'],
            'max_warehouses' => ['required', 'integer', 'min:1'],
            'max_invoices_per_month' => ['required', 'integer', 'min:1'],
            'features' => ['nullable', 'array'],
            'price_cents' => ['required', 'integer', 'min:0'],
        ];
    }

    public function toDto(): CreatePackageData
    {
        /** @var array<string, mixed> $features */
        $features = $this->input('features', []);

        return new CreatePackageData(
            name: (string) $this->input('name'),
            slug: (string) $this->input('slug'),
            maxUsers: (int) $this->input('max_users'),
            maxWarehouses: (int) $this->input('max_warehouses'),
            maxInvoicesPerMonth: (int) $this->input('max_invoices_per_month'),
            features: $features,
            priceCents: (int) $this->input('price_cents'),
        );
    }
}
