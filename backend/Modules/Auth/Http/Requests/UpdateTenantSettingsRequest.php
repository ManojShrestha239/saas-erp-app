<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Auth\Enums\TenantPermission;

final class UpdateTenantSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(TenantPermission::SettingsManage->value);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['nullable', 'string', 'max:255'],
            'company_tax_id' => ['nullable', 'string', 'max:50'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'company_address' => ['nullable', 'string', 'max:500'],
            'company_email' => ['nullable', 'email', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'string', 'timezone'],
            'date_format' => ['nullable', 'string', 'in:Y-m-d,d/m/Y,m/d/Y,Y/m/d'],
            'fiscal_year_start' => ['nullable', 'string', 'regex:/^\d{2}-\d{2}$/'],
            'allow_negative_stock' => ['nullable', 'boolean'],
            'bank_details' => ['nullable', 'string', 'max:1000'],
            'invoice_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Z0-9_-]+$/'],
            'bill_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Z0-9_-]+$/'],
            'quotation_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Z0-9_-]+$/'],
            'po_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Z0-9_-]+$/'],
        ];
    }
}
