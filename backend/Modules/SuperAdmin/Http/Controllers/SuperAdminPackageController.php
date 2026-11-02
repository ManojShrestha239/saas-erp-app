<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\SuperAdmin\Http\Requests\CreatePackageRequest;
use Modules\SuperAdmin\Http\Requests\UpdatePackageRequest;
use Modules\SuperAdmin\Http\Resources\SuperAdminPackageResource;
use Modules\SuperAdmin\Services\SuperAdminPackageService;
use Shared\Responses\ApiResponse;
use Shared\Utils\HttpStatus;

final class SuperAdminPackageController
{
    public function __construct(
        private readonly SuperAdminPackageService $packageService,
    ) {}

    public function index(): JsonResponse
    {
        $packages = $this->packageService->listPackages();

        return ApiResponse::success(
            data: SuperAdminPackageResource::collection($packages),
        );
    }

    public function store(CreatePackageRequest $request): JsonResponse
    {
        $package = $this->packageService->createPackage($request->toDto());

        return ApiResponse::success(
            data: new SuperAdminPackageResource($package),
            message: 'Package created successfully.',
            status: HttpStatus::CREATED,
        );
    }

    public function show(int $id): JsonResponse
    {
        $package = $this->packageService->findPackage($id);

        return ApiResponse::success(
            data: new SuperAdminPackageResource($package),
        );
    }

    public function update(int $id, UpdatePackageRequest $request): JsonResponse
    {
        $package = $this->packageService->updatePackage($id, $request->toDto());

        return ApiResponse::success(
            data: new SuperAdminPackageResource($package),
            message: 'Package updated successfully.',
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $this->packageService->deletePackage($id);

        return ApiResponse::success(
            data: null,
            message: 'Package deleted successfully.',
        );
    }
}
