<?php

declare(strict_types=1);

namespace Shared\Http\Middleware;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;
use Shared\Tenancy\TenantContext;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => fn (): array => [
                'user' => $this->resolveAuthUser(),
            ],
            'tenant' => function (): ?array {
                $tenantContext = TenantContext::get();

                return $tenantContext !== null ? [
                    'id' => (string) $tenantContext->id,
                    'slug' => $tenantContext->slug,
                ] : null;
            },
            'flash' => [
                'success' => fn (): mixed => $request->session()->get('success'),
                'error' => fn (): mixed => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * @return array{id: string, name: string, email: string, status: string, email_verified: bool, roles: array<int, string>, permissions: array<int, string>}|null
     */
    private function resolveAuthUser(): ?array
    {
        /** @var (Authenticatable&object{name?: string, email?: string, status?: string, email_verified_at?: mixed})|null $user */
        $user = Auth::guard('web')->user() ?? Auth::guard('super_admin')->user();

        if ($user === null) {
            return null;
        }

        /** @var array<int, string> $roles */
        $roles = [];
        if (method_exists($user, 'getRoleNames')) {
            /** @var Collection<int, string> $roleCollection */
            $roleCollection = $user->getRoleNames();
            $roles = $roleCollection->values()->all();
        }

        /** @var array<int, string> $permissions */
        $permissions = [];
        if (method_exists($user, 'getAllPermissions')) {
            /** @var Collection<int, object{name: string}> $permCollection */
            $permCollection = $user->getAllPermissions();
            $permissions = $permCollection->pluck('name')->values()->all();
        }

        if (Auth::guard('super_admin')->check() && empty($roles)) {
            $roles = ['Super Admin'];
            $permissions = ['*'];
        }

        return [
            'id' => (string) $user->getAuthIdentifier(),
            'name' => (string) ($user->name ?? ''),
            'email' => (string) ($user->email ?? ''),
            'status' => (string) ($user->status ?? 'active'),
            'email_verified' => ! empty($user->email_verified_at),
            'roles' => $roles,
            'permissions' => $permissions,
        ];
    }
}
