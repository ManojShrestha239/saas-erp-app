<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\DTOs\UpdateAccountData;
use Modules\Accounting\Enums\AccountType;
use Modules\Auth\Enums\TenantPermission;

final class UpdateAccountRequest extends FormRequest
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
        /** @var string|null $accountId */
        $accountId = $this->route('account') ?? $this->route('id');

        return [
            'code' => ['nullable', 'string', 'max:32', Rule::unique('tenant.accounts', 'code')->ignore($accountId)],
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::enum(AccountType::class)],
            'parent_id' => ['nullable', 'string', Rule::exists('tenant.accounts', 'id')],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function toDto(): UpdateAccountData
    {
        $validated = $this->validated();

        $accountType = isset($validated['type'])
            ? AccountType::from((string) $validated['type'])
            : null;

        return new UpdateAccountData(
            code: isset($validated['code']) ? (string) $validated['code'] : null,
            name: isset($validated['name']) ? (string) $validated['name'] : null,
            type: $accountType,
            parentId: array_key_exists('parent_id', $validated) ? ($validated['parent_id'] !== null ? (string) $validated['parent_id'] : null) : null,
            isActive: isset($validated['is_active']) ? (bool) $validated['is_active'] : null,
            description: array_key_exists('description', $validated) ? ($validated['description'] !== null ? (string) $validated['description'] : null) : null,
        );
    }
}
