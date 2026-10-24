<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterTenantRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:63', 'unique:central.tenants,slug'],
            'owner_email' => ['required', 'email:rfc,dns', 'max:255'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
