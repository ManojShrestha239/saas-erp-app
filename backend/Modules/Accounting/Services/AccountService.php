<?php

declare(strict_types=1);

namespace Modules\Accounting\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\DTOs\CreateAccountData;
use Modules\Accounting\DTOs\UpdateAccountData;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\SystemAccount;
use Modules\Accounting\Models\Account;
use Shared\Exceptions\ApiException;

final class AccountService
{
    public function create(CreateAccountData $data): Account
    {
        return DB::connection('tenant')->transaction(function () use ($data): Account {
            // Check code uniqueness
            $existing = Account::where('code', $data->code)->first();
            if ($existing !== null) {
                throw ApiException::badRequest(
                    message: "Account with code '{$data->code}' already exists.",
                    errorCode: 'DUPLICATE_ACCOUNT_CODE'
                );
            }

            // If parent specified, validate parent
            if ($data->parentId !== null) {
                /** @var Account|null $parent */
                $parent = Account::find($data->parentId);
                if ($parent === null) {
                    throw ApiException::notFound(
                        message: "Parent account with ID '{$data->parentId}' not found.",
                        errorCode: 'PARENT_ACCOUNT_NOT_FOUND'
                    );
                }

                if ($parent->type !== $data->type) {
                    throw ApiException::badRequest(
                        message: "Child account type '{$data->type->value}' must match parent account type '{$parent->type->value}'.",
                        errorCode: 'ACCOUNT_TYPE_MISMATCH'
                    );
                }
            }

            /** @var Account $account */
            $account = Account::create([
                'code' => $data->code,
                'name' => $data->name,
                'type' => $data->type->value,
                'parent_id' => $data->parentId,
                'is_system' => $data->isSystem,
                'system_name' => $data->systemName,
                'is_active' => $data->isActive,
                'currency' => $data->currency,
                'description' => $data->description,
            ]);

            return $account;
        }, 3);
    }

    public function update(string $id, UpdateAccountData $data): Account
    {
        return DB::connection('tenant')->transaction(function () use ($id, $data): Account {
            /** @var Account|null $account */
            $account = Account::lockForUpdate()->find($id);
            if ($account === null) {
                throw ApiException::notFound(
                    message: "Account with ID '{$id}' not found.",
                    errorCode: 'ACCOUNT_NOT_FOUND'
                );
            }

            // System account protections
            if ($account->is_system) {
                if ($data->type !== null && $data->type !== $account->type) {
                    throw ApiException::badRequest(
                        message: 'Cannot change the account type of a system account.',
                        errorCode: 'SYSTEM_ACCOUNT_PROTECTED'
                    );
                }
                if ($data->code !== null && $data->code !== $account->code) {
                    throw ApiException::badRequest(
                        message: 'Cannot change the code of a system account.',
                        errorCode: 'SYSTEM_ACCOUNT_PROTECTED'
                    );
                }
            }

            // Check code uniqueness if changing
            if ($data->code !== null && $data->code !== $account->code) {
                $codeExists = Account::where('code', $data->code)
                    ->where('id', '!=', $id)
                    ->exists();

                if ($codeExists) {
                    throw ApiException::badRequest(
                        message: "Account with code '{$data->code}' already exists.",
                        errorCode: 'DUPLICATE_ACCOUNT_CODE'
                    );
                }
                $account->code = $data->code;
            }

            if ($data->name !== null) {
                $account->name = $data->name;
            }

            $targetType = $data->type ?? $account->type;
            if ($data->type !== null) {
                $account->type = $data->type;
            }

            // Hierarchy validations
            if ($data->parentId !== null) {
                if ($data->parentId === $account->id) {
                    throw ApiException::badRequest(
                        message: 'An account cannot be its own parent.',
                        errorCode: 'ACCOUNT_CANNOT_BE_OWN_PARENT'
                    );
                }

                /** @var Account|null $parent */
                $parent = Account::find($data->parentId);
                if ($parent === null) {
                    throw ApiException::notFound(
                        message: "Parent account with ID '{$data->parentId}' not found.",
                        errorCode: 'PARENT_ACCOUNT_NOT_FOUND'
                    );
                }

                if ($parent->type !== $targetType) {
                    throw ApiException::badRequest(
                        message: "Account type '{$targetType->value}' must match parent account type '{$parent->type->value}'.",
                        errorCode: 'ACCOUNT_TYPE_MISMATCH'
                    );
                }

                if ($this->isDescendant($data->parentId, $account->id)) {
                    throw ApiException::badRequest(
                        message: 'Cyclic hierarchy detected: an account cannot have one of its descendants as its parent.',
                        errorCode: 'ACCOUNT_CYCLIC_HIERARCHY'
                    );
                }

                $account->parent_id = $data->parentId;
            }

            if ($data->isActive !== null) {
                $account->is_active = $data->isActive;
            }

            if ($data->description !== null) {
                $account->description = $data->description;
            }

            $account->save();

            return $account;
        }, 3);
    }

    public function delete(string $id): void
    {
        DB::connection('tenant')->transaction(function () use ($id): void {
            /** @var Account|null $account */
            $account = Account::lockForUpdate()->find($id);
            if ($account === null) {
                throw ApiException::notFound(
                    message: "Account with ID '{$id}' not found.",
                    errorCode: 'ACCOUNT_NOT_FOUND'
                );
            }

            if ($account->is_system) {
                throw ApiException::badRequest(
                    message: 'System accounts cannot be deleted.',
                    errorCode: 'SYSTEM_ACCOUNT_CANNOT_BE_DELETED'
                );
            }

            $hasChildren = Account::where('parent_id', $id)->exists();
            if ($hasChildren) {
                throw ApiException::badRequest(
                    message: 'Cannot delete account with existing child accounts.',
                    errorCode: 'ACCOUNT_HAS_CHILDREN'
                );
            }

            $account->delete();
        }, 3);
    }

    public function find(string $id): Account
    {
        /** @var Account|null $account */
        $account = Account::with(['parent', 'children'])->find($id);

        if ($account === null) {
            throw ApiException::notFound(
                message: "Account with ID '{$id}' not found.",
                errorCode: 'ACCOUNT_NOT_FOUND'
            );
        }

        return $account;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Account>
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Account::query()->with('parent');

        if (! empty($filters['type']) && is_string($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['search']) && is_string($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($search): void {
                $q->where('code', 'ILIKE', $search)
                    ->orWhere('name', 'ILIKE', $search);
            });
        }

        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 50;
        $perPage = min(max($perPage, 1), 100);

        return $query->orderBy('code')->paginate($perPage);
    }

    /**
     * @return Collection<int, Account>
     */
    public function getTree(?AccountType $type = null): Collection
    {
        $query = Account::query()
            ->whereNull('parent_id')
            ->with(['children.children.children'])
            ->orderBy('code');

        if ($type !== null) {
            $query->where('type', $type->value);
        }

        return $query->get();
    }

    public function getSystemAccount(SystemAccount $systemAccount): Account
    {
        /** @var Account|null $account */
        $account = Account::where('system_name', $systemAccount->value)->first();

        if ($account === null) {
            throw ApiException::notFound(
                message: "System account '{$systemAccount->value}' ({$systemAccount->label()}) not found.",
                errorCode: 'SYSTEM_ACCOUNT_NOT_FOUND'
            );
        }

        return $account;
    }

    private function isDescendant(string $potentialDescendantId, string $ancestorId): bool
    {
        $current = Account::find($potentialDescendantId);
        while ($current !== null && $current->parent_id !== null) {
            if ($current->parent_id === $ancestorId) {
                return true;
            }
            $current = Account::find($current->parent_id);
        }

        return false;
    }
}
