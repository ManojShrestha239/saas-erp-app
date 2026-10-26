<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Http\Requests\InviteUserRequest;
use Modules\Auth\Http\Requests\UpdateUserRoleRequest;
use Modules\Auth\Http\Requests\UpdateUserStatusRequest;
use Modules\Auth\Http\Resources\UserResource;
use Modules\Auth\Models\TenantUser;
use Modules\Auth\Services\EnforceUserLimitService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;
use Shared\Tenancy\TenantContext;

final class TenantUserController
{
    public function __construct(
        private readonly EnforceUserLimitService $enforceUserLimitService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', TenantUser::class);

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        $paginator = TenantUser::query()
            ->with('roles')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        return ApiResponse::success(
            data: UserResource::collection($paginator->items()),
            message: 'Users retrieved successfully.',
            meta: $meta,
        );
    }

    public function invite(InviteUserRequest $request): JsonResponse
    {
        Gate::authorize('create', TenantUser::class);

        $tenantId = TenantContext::get()?->id;
        if ($tenantId !== null) {
            $this->enforceUserLimitService->ensureCanInvite($tenantId);
        }

        $validated = $request->validated();
        /** @var string $name */
        $name = $validated['name'];
        $email = strtolower((string) $validated['email']);
        /** @var string $roleName */
        $roleName = $validated['role'];

        $roleEnum = TenantRole::from($roleName);

        $user = TenantUser::create([
            'id' => (string) Str::ulid(),
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::random(32)),
            'status' => 'active',
        ]);

        $user->assignRole($roleEnum);

        return ApiResponse::created(
            data: new UserResource($user),
            message: 'User invited successfully.',
        );
    }

    public function updateRole(UpdateUserRoleRequest $request, string $id): JsonResponse
    {
        /** @var TenantUser $targetUser */
        $targetUser = TenantUser::query()->with('roles')->findOrFail($id);

        Gate::authorize('updateRole', [TenantUser::class, $targetUser]);

        $validated = $request->validated();
        /** @var string $roleName */
        $roleName = $validated['role'];
        $roleEnum = TenantRole::from($roleName);

        // Prevent demoting the sole active owner
        if ($targetUser->hasRole(TenantRole::Owner) && $roleEnum !== TenantRole::Owner) {
            $ownerCount = TenantUser::role(TenantRole::Owner->value)
                ->where('status', 'active')
                ->count();

            if ($ownerCount <= 1) {
                throw ApiException::badRequest(
                    'Cannot change role of the sole active tenant owner.',
                    'CANNOT_DEMOTE_SOLE_OWNER'
                );
            }
        }

        $targetUser->syncRoles([$roleEnum]);

        return ApiResponse::success(
            data: new UserResource($targetUser->fresh('roles')),
            message: 'User role updated successfully.',
        );
    }

    public function updateStatus(UpdateUserStatusRequest $request, string $id): JsonResponse
    {
        /** @var TenantUser $targetUser */
        $targetUser = TenantUser::query()->with('roles')->findOrFail($id);

        Gate::authorize('updateStatus', [TenantUser::class, $targetUser]);

        $validated = $request->validated();
        /** @var string $status */
        $status = $validated['status'];

        // Prevent deactivating the sole active owner
        if ($status === 'deactivated' && $targetUser->hasRole(TenantRole::Owner)) {
            $activeOwnerCount = TenantUser::role(TenantRole::Owner->value)
                ->where('status', 'active')
                ->count();

            if ($activeOwnerCount <= 1) {
                throw ApiException::badRequest(
                    'Cannot deactivate the sole active tenant owner.',
                    'CANNOT_DEACTIVATE_SOLE_OWNER'
                );
            }
        }

        $targetUser->update(['status' => $status]);

        return ApiResponse::success(
            data: new UserResource($targetUser->fresh('roles')),
            message: 'User status updated successfully.',
        );
    }
}
