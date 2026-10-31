<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Http\Requests\StoreAccountRequest;
use Modules\Accounting\Http\Requests\UpdateAccountRequest;
use Modules\Accounting\Http\Resources\AccountResource;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\AccountService;
use Shared\Responses\ApiResponse;

final class AccountController extends Controller
{
    public function __construct(
        private readonly AccountService $accountService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Account::class);

        if ($request->boolean('tree')) {
            $type = $request->filled('type') && is_string($request->input('type'))
                ? AccountType::tryFrom((string) $request->input('type'))
                : null;

            $tree = $this->accountService->getTree($type);

            return ApiResponse::success(
                data: AccountResource::collection($tree),
                message: 'Chart of accounts tree retrieved successfully.'
            );
        }

        /** @var array<string, mixed> $filters */
        $filters = $request->only(['type', 'is_active', 'search', 'per_page', 'page']);
        $paginator = $this->accountService->list($filters);

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        return ApiResponse::success(
            data: AccountResource::collection($paginator->items()),
            message: 'Accounts retrieved successfully.',
            meta: $meta,
        );
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        Gate::authorize('create', Account::class);

        $account = $this->accountService->create($request->toDto());

        return ApiResponse::created(
            data: new AccountResource($account),
            message: 'Account created successfully.'
        );
    }

    public function show(string $id): JsonResponse
    {
        $account = $this->accountService->find($id);

        Gate::authorize('view', $account);

        return ApiResponse::success(
            data: new AccountResource($account),
            message: 'Account retrieved successfully.'
        );
    }

    public function update(string $id, UpdateAccountRequest $request): JsonResponse
    {
        $account = $this->accountService->find($id);

        Gate::authorize('update', $account);

        $updated = $this->accountService->update($id, $request->toDto());

        return ApiResponse::success(
            data: new AccountResource($updated),
            message: 'Account updated successfully.'
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $account = $this->accountService->find($id);

        Gate::authorize('delete', $account);

        $this->accountService->delete($id);

        return ApiResponse::success(
            data: null,
            message: 'Account deleted successfully.'
        );
    }
}
