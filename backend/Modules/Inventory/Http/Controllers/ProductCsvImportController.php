<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Modules\Inventory\Http\Requests\ImportProductCsvRequest;
use Modules\Inventory\Jobs\ProcessProductCsvImportJob;
use Modules\Inventory\Services\ProductCsvImportService;
use Shared\Enums\CsvDuplicateAction;
use Shared\Enums\CsvImportStatus;
use Shared\Enums\CsvImportType;
use Shared\Exceptions\ApiException;
use Shared\Http\Resources\CsvImportResource;
use Shared\Models\CsvImport;
use Shared\Responses\ApiResponse;
use Shared\Tenancy\TenantContext;
use Shared\Utils\HttpStatus;

final class ProductCsvImportController
{
    public function store(
        ImportProductCsvRequest $request,
        ProductCsvImportService $importService
    ): JsonResponse {
        $context = TenantContext::get();
        if ($context === null) {
            throw ApiException::forbidden('Tenant context not identified.', 'NO_TENANT_CONTEXT');
        }

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $duplicateAction = CsvDuplicateAction::tryFrom((string) $request->input('duplicate_action', 'skip'))
            ?? CsvDuplicateAction::Skip;

        $tenantId = (string) $context->id;
        $storedPath = $file->store("imports/{$tenantId}/products", 'local');

        if (! is_string($storedPath)) {
            throw ApiException::internal('Failed to store uploaded import file.', 'FILE_STORE_FAILED');
        }

        $import = CsvImport::query()->create([
            'type' => CsvImportType::Products,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'status' => CsvImportStatus::Pending,
            'duplicate_action' => $duplicateAction,
            'total_rows' => 0,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]);

        ProcessProductCsvImportJob::dispatch($import, $tenantId, $context->databaseName);

        return ApiResponse::success(
            data: (new CsvImportResource($import))->resolve($request),
            message: 'Product import initiated successfully.',
            status: HttpStatus::ACCEPTED,
        );
    }

    public function show(string $id, Request $request): JsonResponse
    {
        if ($request->user() === null || ! $request->user()->can('products.view')) {
            throw ApiException::forbidden('You do not have permission to view product imports.', 'FORBIDDEN');
        }

        /** @var CsvImport|null $import */
        $import = CsvImport::query()
            ->where('id', $id)
            ->where('type', CsvImportType::Products)
            ->first();

        if ($import === null) {
            throw ApiException::notFound('Product import not found.', 'IMPORT_NOT_FOUND');
        }

        return ApiResponse::success(
            data: (new CsvImportResource($import))->resolve($request),
        );
    }

    public function template(ProductCsvImportService $service, Request $request): Response
    {
        if ($request->user() === null || ! $request->user()->can('products.view')) {
            throw ApiException::forbidden('You do not have permission to download import templates.', 'FORBIDDEN');
        }

        $csv = $service->generateTemplateCsv();

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="products_import_template.csv"',
        ]);
    }
}
