<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\DTOs\CreateAccountData;
use Modules\Accounting\Enums\AccountType;
use Modules\Auth\Enums\TenantPermission;

final class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->can(TenantPermission::AccountingManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', Rule::unique('tenant.accounts', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'parent_id' => ['nullable', 'string', Rule::exists('tenant.accounts', 'id')],
            'is_active' => ['nullable', 'boolean'],
            'currency' => ['nullable', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function toDto(): CreateAccountData
    {
        $validated = $this->validated();

        /** @var AccountType $accountType */
        $accountType = AccountType::from((string) $validated['type']);

        return new CreateAccountData(
            code: (string) $validated['code'],
            name: (string) $validated['name'],
            type: $accountType,
            parentId: isset($validated['parent_id']) ? (string) $validated['parent_id'] : null,
            isSystem: false,
            systemName: null,
            isActive: isset($validated['is_active']) ? (bool) $validated['is_active'] : true,
            currency: isset($validated['currency']) ? (string) $validated['currency'] : 'USD',
            description: isset($validated['description']) ? (string) $validated['description'] : null,
        );
    }
}
