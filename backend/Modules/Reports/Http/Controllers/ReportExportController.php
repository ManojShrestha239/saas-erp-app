<?php

declare(strict_types=1);

namespace Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Modules\Reports\Enums\ReportExportStatus;
use Modules\Reports\Enums\ReportFormat;
use Modules\Reports\Enums\ReportType;
use Modules\Reports\Jobs\GenerateReportExportJob;
use Modules\Reports\Models\ReportExport;
use Shared\Responses\ApiResponse;
use Shared\Tenancy\TenantContext;
use Shared\Utils\HttpStatus;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportExportController extends Controller
{
    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report_type' => ['required', Rule::enum(ReportType::class)],
            'format' => ['required', Rule::enum(ReportFormat::class)],
            'filters' => ['nullable', 'array'],
        ]);

        $reportType = ReportType::from((string) $validated['report_type']);
        $format = ReportFormat::from((string) $validated['format']);

        $user = $request->user();
        if ($user === null || ! $user->can($reportType->requiredPermission())) {
            abort(403, "You do not have permission to export {$reportType->title()}.");
        }

        $tenantContext = TenantContext::get();
        if ($tenantContext === null) {
            abort(500, 'Tenant context not available.');
        }

        /** @var ReportExport $export */
        $export = ReportExport::query()->create([
            'user_id' => (string) $user->id,
            'report_type' => $reportType,
            'format' => $format,
            'status' => ReportExportStatus::Queued,
            'filters' => $validated['filters'] ?? [],
        ]);

        GenerateReportExportJob::dispatch(
            exportId: $export->id,
            tenantId: (string) $tenantContext->id,
            databaseName: $tenantContext->databaseName,
        )->onQueue('reports');

        return ApiResponse::success(
            data: [
                'id' => $export->id,
                'report_type' => $export->report_type->value,
                'format' => $export->format->value,
                'status' => $export->status->value,
                'created_at' => $export->created_at?->toIso8601String(),
            ],
            message: 'Report export queued successfully.',
            status: HttpStatus::ACCEPTED,
        );
    }

    public function status(Request $request, string $id): JsonResponse
    {
        /** @var ReportExport $export */
        $export = ReportExport::query()->findOrFail($id);

        $user = $request->user();
        if ($user !== null && ! $user->can($export->report_type->requiredPermission())) {
            abort(403, "You do not have permission to access {$export->report_type->title()} exports.");
        }

        $downloadUrl = null;
        if ($export->status === ReportExportStatus::Completed && $export->file_path !== null) {
            $downloadUrl = URL::temporarySignedRoute(
                'reports.exports.download',
                now()->addMinutes(60),
                ['id' => $export->id],
            );
        }

        return ApiResponse::success(
            data: [
                'id' => $export->id,
                'report_type' => $export->report_type->value,
                'format' => $export->format->value,
                'status' => $export->status->value,
                'file_size_bytes' => $export->file_size_bytes,
                'error_message' => $export->error_message,
                'download_url' => $downloadUrl,
                'created_at' => $export->created_at?->toIso8601String(),
                'updated_at' => $export->updated_at?->toIso8601String(),
            ],
            message: 'Report export status retrieved successfully.'
        );
    }

    public function download(Request $request, string $id): StreamedResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired signature.');
        }

        $tenantContext = TenantContext::get();
        if ($tenantContext === null) {
            abort(403, 'Tenant context required.');
        }

        /** @var ReportExport $export */
        $export = ReportExport::query()->findOrFail($id);

        // Defense-in-depth: Verify storage path matches current tenant
        $expectedPrefix = "tenants/{$tenantContext->id}/exports/";
        if ($export->file_path === null || ! str_starts_with($export->file_path, $expectedPrefix)) {
            Log::warning('Cross-tenant export download attempt detected', [
                'tenant_id' => $tenantContext->id,
                'export_id' => $id,
                'file_path' => $export->file_path,
                'ip' => $request->ip(),
            ]);

            abort(403, 'Unauthorized access to report export file.');
        }

        if (! Storage::disk('local')->exists($export->file_path)) {
            abort(404, 'Export file not found.');
        }

        $shortId = substr($export->id, -8);
        $filename = sprintf('%s-%s.%s', $export->report_type->value, $shortId, $export->format->value);

        return Storage::disk('local')->download($export->file_path, $filename);
    }
}
