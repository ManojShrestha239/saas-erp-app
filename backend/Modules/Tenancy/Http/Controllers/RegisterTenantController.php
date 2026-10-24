<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Modules\Tenancy\Http\Requests\RegisterTenantRequest;
use Modules\Tenancy\Http\Resources\TenantResource;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Responses\ApiResponse;

final class RegisterTenantController
{
    public function __invoke(RegisterTenantRequest $request): JsonResponse
    {
        $tenantId = (string) Str::ulid();
        $slug = $request->validated('slug');
        /** @var string $slug */
        $databaseName = 'tenant_'.$slug;

        $tenant = Tenant::create([
            'id' => $tenantId,
            'name' => $request->validated('name'),
            'slug' => $slug,
            'database_name' => $databaseName,
            'status' => 'provisioning',
        ]);

        Domain::create([
            'tenant_id' => $tenantId,
            'host' => $slug.'.'.config('app.domain', 'nexuserp.test'),
        ]);

        ProvisionTenantDatabase::dispatch(
            tenantId: $tenantId,
            ownerEmail: (string) $request->validated('owner_email'),
            ownerName: (string) $request->validated('name'),
        );

        return ApiResponse::success(
            data: new TenantResource($tenant),
            message: 'Tenant registration accepted. Provisioning in progress.',
            status: 202,
        );
    }
}
