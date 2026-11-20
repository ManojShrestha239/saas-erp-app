<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Reports\Enums\ReportExportStatus;
use Modules\Reports\Enums\ReportFormat;
use Modules\Reports\Enums\ReportType;
use Modules\Reports\Jobs\GenerateReportExportJob;
use Modules\Reports\Models\ReportExport;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

if (! function_exists('dropReportExportsTestTenantDatabase')) {
    function dropReportExportsTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_exports_alpha', 'tenant_exports_beta'], true)) {
            return;
        }

        TestCase::dropTestTenantDatabase($dbName);
    }
}

if (! function_exists('loginAsExportUser')) {
    function loginAsExportUser(mixed $testCase, string $host, string $email, string $password = 'Password12345!'): string
    {
        $testCase->flushSession();

        $response = $testCase->postJson("http://{$host}/api/v1/auth/login", [
            'email' => $email,
            'password' => $password,
        ]);

        $response->assertStatus(200);

        $cookie = $response->getCookie(config('session.cookie'));
        expect($cookie)->not->toBeNull();

        return $cookie->getValue();
    }
}

beforeEach(function (): void {
    if (extension_loaded('redis')) {
        try {
            Cache::store('redis')->flush();
        } catch (Throwable) {
        }
    }

    Storage::fake('local');

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    dropReportExportsTestTenantDatabase('tenant_exports_alpha');
    dropReportExportsTestTenantDatabase('tenant_exports_beta');

    Domain::query()->whereIn('host', ['exportalpha.nexuserp.test', 'exportbeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['exportalpha', 'exportbeta'])->delete();

    // 1. Provision Alpha Tenant
    $alphaId = (string) Str::ulid();
    $this->alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Export Alpha Corp',
        'slug' => 'exportalpha',
        'database_name' => 'tenant_exports_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'exportalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@exportalpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision Beta Tenant
    $betaId = (string) Str::ulid();
    $this->betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Export Beta Corp',
        'slug' => 'exportbeta',
        'database_name' => 'tenant_exports_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'exportbeta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@exportbeta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    // Set Owner Passwords
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    TenantUser::where('email', 'owner@exportalpha.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    // Create Accountant User with reports.financial permission
    $this->accountant = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Accountant',
        'email' => 'accountant@exportalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'is_active' => true,
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->accountant->assignRole(TenantRole::Accountant->value);

    // Create Warehouse User with reports.stock permission
    $this->warehouseUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Warehouse',
        'email' => 'warehouse@exportalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'is_active' => true,
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->warehouseUser->assignRole(TenantRole::WarehouseManager->value);

    // Beta Owner Password
    $tenantManager->switchTo(new TenantContext(
        id: $this->betaTenant->id,
        slug: $this->betaTenant->slug,
        databaseName: $this->betaTenant->database_name,
    ));

    TenantUser::where('email', 'owner@exportbeta.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    // Switch back to Alpha
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));
});

afterEach(function (): void {
    dropReportExportsTestTenantDatabase('tenant_exports_alpha');
    dropReportExportsTestTenantDatabase('tenant_exports_beta');
});

it('blocks unauthenticated requests to export', function (): void {
    $response = $this->withHeader('Host', 'exportalpha.nexuserp.test')
        ->postJson('/api/v1/reports/exports', [
            'report_type' => 'trial-balance',
            'format' => 'pdf',
        ]);

    $response->assertStatus(401);
});

it('enforces RBAC permission check when creating exports', function (): void {
    // Warehouse staff does NOT have reports.financial -> cannot export trial balance
    $whCookie = loginAsExportUser($this, 'exportalpha.nexuserp.test', 'warehouse@exportalpha.nexuserp.test');

    $forbiddenRes = $this->withHeader('Host', 'exportalpha.nexuserp.test')
        ->withCookie(config('session.cookie'), $whCookie)
        ->postJson('/api/v1/reports/exports', [
            'report_type' => 'trial-balance',
            'format' => 'pdf',
        ]);

    $forbiddenRes->assertStatus(403);

    // Accountant has reports.financial -> can export trial balance
    $accCookie = loginAsExportUser($this, 'exportalpha.nexuserp.test', 'accountant@exportalpha.nexuserp.test');

    $allowedRes = $this->withHeader('Host', 'exportalpha.nexuserp.test')
        ->withCookie(config('session.cookie'), $accCookie)
        ->postJson('/api/v1/reports/exports', [
            'report_type' => 'trial-balance',
            'format' => 'pdf',
            'filters' => [
                'as_of_date' => '2026-11-10',
            ],
        ]);

    $allowedRes->assertStatus(202);
    $allowedRes->assertJsonPath('success', true);
    $allowedRes->assertJsonPath('data.report_type', 'trial-balance');
    $allowedRes->assertJsonPath('data.format', 'pdf');
    $allowedRes->assertJsonPath('data.status', 'queued');
});

it('dispatches export job on the dedicated reports queue', function (): void {
    Queue::fake();

    $accCookie = loginAsExportUser($this, 'exportalpha.nexuserp.test', 'accountant@exportalpha.nexuserp.test');

    $res = $this->withHeader('Host', 'exportalpha.nexuserp.test')
        ->withCookie(config('session.cookie'), $accCookie)
        ->postJson('/api/v1/reports/exports', [
            'report_type' => 'trial-balance',
            'format' => 'xlsx',
        ]);

    $res->assertStatus(202);
    $exportId = (string) $res->json('data.id');

    Queue::assertPushedOn('reports', GenerateReportExportJob::class, function (GenerateReportExportJob $job) use ($exportId) {
        return $job->exportId === $exportId && $job->tenantId === $this->alphaTenant->id;
    });
});

it('processes queued PDF export job and produces tenant-isolated PDF file', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $export = ReportExport::create([
        'user_id' => (string) $this->accountant->id,
        'report_type' => ReportType::TrialBalance,
        'format' => ReportFormat::Pdf,
        'status' => ReportExportStatus::Queued,
        'filters' => ['as_of_date' => '2026-11-10'],
    ]);

    // Execute job synchronously
    $job = new GenerateReportExportJob(
        exportId: $export->id,
        tenantId: $this->alphaTenant->id,
        databaseName: $this->alphaTenant->database_name,
    );
    app()->call([$job, 'handle']);

    $export->refresh();
    expect($export->status)->toBe(ReportExportStatus::Completed);
    expect($export->file_path)->toStartWith("tenants/{$this->alphaTenant->id}/exports/");
    expect($export->file_path)->toEndWith('.pdf');
    expect($export->file_size_bytes)->toBeGreaterThan(0);
    expect(Storage::disk('local')->exists((string) $export->file_path))->toBeTrue();

    // Verify PDF binary header
    $content = Storage::disk('local')->get((string) $export->file_path);
    expect($content)->toStartWith('%PDF');
});

it('processes queued Excel export job and produces tenant-isolated XLSX file', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $export = ReportExport::create([
        'user_id' => (string) $this->accountant->id,
        'report_type' => ReportType::ProfitAndLoss,
        'format' => ReportFormat::Xlsx,
        'status' => ReportExportStatus::Queued,
        'filters' => [
            'start_date' => '2026-01-01',
            'end_date' => '2026-11-10',
        ],
    ]);

    $job = new GenerateReportExportJob(
        exportId: $export->id,
        tenantId: $this->alphaTenant->id,
        databaseName: $this->alphaTenant->database_name,
    );
    app()->call([$job, 'handle']);

    $export->refresh();
    expect($export->status)->toBe(ReportExportStatus::Completed);
    expect($export->file_path)->toStartWith("tenants/{$this->alphaTenant->id}/exports/");
    expect($export->file_path)->toEndWith('.xlsx');
    expect($export->file_size_bytes)->toBeGreaterThan(0);
    expect(Storage::disk('local')->exists((string) $export->file_path))->toBeTrue();
});

it('processes operational stock and aging exports (PDF and Excel)', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    // 1. Stock Valuation Excel
    $valExport = ReportExport::create([
        'user_id' => (string) $this->warehouseUser->id,
        'report_type' => ReportType::StockValuation,
        'format' => ReportFormat::Xlsx,
        'status' => ReportExportStatus::Queued,
        'filters' => [],
    ]);
    app()->call([new GenerateReportExportJob($valExport->id, $this->alphaTenant->id, $this->alphaTenant->database_name), 'handle']);
    $valExport->refresh();
    expect($valExport->status)->toBe(ReportExportStatus::Completed);
    expect(Storage::disk('local')->exists((string) $valExport->file_path))->toBeTrue();

    // 2. AR Aging PDF
    $arExport = ReportExport::create([
        'user_id' => (string) $this->accountant->id,
        'report_type' => ReportType::ArAging,
        'format' => ReportFormat::Pdf,
        'status' => ReportExportStatus::Queued,
        'filters' => ['as_of_date' => '2026-11-10'],
    ]);
    app()->call([new GenerateReportExportJob($arExport->id, $this->alphaTenant->id, $this->alphaTenant->database_name), 'handle']);
    $arExport->refresh();
    expect($arExport->status)->toBe(ReportExportStatus::Completed);
    expect(Storage::disk('local')->get((string) $arExport->file_path))->toStartWith('%PDF');
});

it('generates temporary signed download URL on status endpoint and downloads file', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $filePath = "tenants/{$this->alphaTenant->id}/exports/dummy-export.pdf";
    Storage::disk('local')->put($filePath, '%PDF-1.4 Dummy PDF Content');

    $export = ReportExport::create([
        'user_id' => (string) $this->accountant->id,
        'report_type' => ReportType::BalanceSheet,
        'format' => ReportFormat::Pdf,
        'status' => ReportExportStatus::Completed,
        'file_path' => $filePath,
        'file_size_bytes' => 28,
        'filters' => [],
    ]);

    $accCookie = loginAsExportUser($this, 'exportalpha.nexuserp.test', 'accountant@exportalpha.nexuserp.test');

    $statusRes = $this->withHeader('Host', 'exportalpha.nexuserp.test')
        ->withCookie(config('session.cookie'), $accCookie)
        ->getJson("/api/v1/reports/exports/{$export->id}/status");

    $statusRes->assertStatus(200);
    $downloadUrl = (string) $statusRes->json('data.download_url');
    expect($downloadUrl)->toContain('/api/v1/reports/exports/'.$export->id.'/download');
    expect($downloadUrl)->toContain('signature=');
    expect($downloadUrl)->toContain('expires=');

    // Perform download using the signed URL
    $parsed = parse_url($downloadUrl);
    $uri = ($parsed['path'] ?? '').'?'.($parsed['query'] ?? '');

    $downloadRes = $this->withHeader('Host', 'exportalpha.nexuserp.test')
        ->get($uri);

    $downloadRes->assertStatus(200);
    $downloadRes->assertHeader('Content-Disposition');
    expect($downloadRes->headers->get('Content-Disposition'))->toContain('attachment;');
    expect($downloadRes->headers->get('Content-Disposition'))->toContain('balance-sheet-');
    expect($downloadRes->streamedContent())->toBe('%PDF-1.4 Dummy PDF Content');
});

it('rejects tampered or expired signed download URLs', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $filePath = "tenants/{$this->alphaTenant->id}/exports/tamper-test.pdf";
    Storage::disk('local')->put($filePath, '%PDF-1.4 Tamper');

    $export = ReportExport::create([
        'user_id' => (string) $this->accountant->id,
        'report_type' => ReportType::TrialBalance,
        'format' => ReportFormat::Pdf,
        'status' => ReportExportStatus::Completed,
        'file_path' => $filePath,
        'file_size_bytes' => 15,
        'filters' => [],
    ]);

    // 1. Tampered signature
    $tamperedUrl = "/api/v1/reports/exports/{$export->id}/download?expires=".now()->addMinutes(10)->timestamp.'&signature=invalid_signature';

    $tamperedRes = $this->withHeader('Host', 'exportalpha.nexuserp.test')
        ->get($tamperedUrl);

    $tamperedRes->assertStatus(403);

    // 2. Expired signature
    $expiredUrl = URL::temporarySignedRoute(
        'reports.exports.download',
        now()->subMinutes(5),
        ['id' => $export->id]
    );
    $parsedExpired = parse_url($expiredUrl);
    $expiredUri = ($parsedExpired['path'] ?? '').'?'.($parsedExpired['query'] ?? '');

    $expiredRes = $this->withHeader('Host', 'exportalpha.nexuserp.test')
        ->get($expiredUri);

    $expiredRes->assertStatus(403);
});

it('strictly blocks cross-tenant export downloads (defense-in-depth boundary)', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    // Alpha Export
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $alphaFilePath = "tenants/{$this->alphaTenant->id}/exports/alpha-secret.pdf";
    Storage::disk('local')->put($alphaFilePath, '%PDF-1.4 Alpha Financials');

    $alphaExport = ReportExport::create([
        'user_id' => (string) $this->accountant->id,
        'report_type' => ReportType::ProfitAndLoss,
        'format' => ReportFormat::Pdf,
        'status' => ReportExportStatus::Completed,
        'file_path' => $alphaFilePath,
        'file_size_bytes' => 25,
        'filters' => [],
    ]);

    // Beta tenant tries to download Alpha's export using Beta host and validly generated Beta signed route
    // 1. Beta tenant attempts cross-tenant download with Alpha's signed URL -> 403 Forbidden (signature host mismatch)
    $alphaSignedUrl = URL::temporarySignedRoute(
        'reports.exports.download',
        now()->addMinutes(60),
        ['id' => $alphaExport->id]
    );
    $parsedAlpha = parse_url($alphaSignedUrl);
    $alphaUri = ($parsedAlpha['path'] ?? '').'?'.($parsedAlpha['query'] ?? '');

    $crossRes = $this->get('http://exportbeta.nexuserp.test'.$alphaUri);
    $crossRes->assertStatus(403);

    // 2. Beta tenant crafts a mathematically valid signed URL for Beta domain targeting Alpha export ID
    URL::forceRootUrl('http://exportbeta.nexuserp.test');
    $betaSignedUrl = URL::temporarySignedRoute(
        'reports.exports.download',
        now()->addMinutes(60),
        ['id' => $alphaExport->id]
    );
    URL::forceRootUrl(null);

    $crossBetaRes = $this->get($betaSignedUrl);
    // Alpha export does not exist in Beta's tenant database -> 404 Not Found
    $crossBetaRes->assertStatus(404);
});
