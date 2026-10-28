<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Enums\DocumentType;
use Shared\Services\DocumentNumberingService;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropDocSeqTestTenantDatabase')) {
    function dropDocSeqTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_docseq_alpha', 'tenant_docseq_beta'], true)) {
            return;
        }

        try {
            $config = config('database.connections.central');
            /** @var array{host: string, port: int|string, username: string, password: string} $config */
            $pdo = new PDO(
                dsn: sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
                username: (string) $config['username'],
                password: (string) $config['password'],
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $pdo->exec('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '.
                $pdo->quote($dbName).' AND pid <> pg_backend_pid()');

            $quotedName = '"'.str_replace('"', '""', $dbName).'"';
            $pdo->exec("DROP DATABASE IF EXISTS {$quotedName}");
        } catch (Throwable) {
            // Best-effort cleanup
        }
    }
}

beforeEach(function (): void {
    app('cache')->flush();
    if (extension_loaded('redis')) {
        try {
            Cache::store('redis')->flush();
        } catch (Throwable) {
        }
    }

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    dropDocSeqTestTenantDatabase('tenant_docseq_alpha');
    dropDocSeqTestTenantDatabase('tenant_docseq_beta');

    Domain::query()->whereIn('host', ['docseqalpha.nexuserp.test', 'docseqbeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['docseqalpha', 'docseqbeta'])->delete();

    // 1. Provision Alpha Tenant
    $alphaId = (string) Str::ulid();
    $this->alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'DocSeq Alpha Corp',
        'slug' => 'docseqalpha',
        'database_name' => 'tenant_docseq_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'docseqalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@docseqalpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision Beta Tenant
    $betaId = (string) Str::ulid();
    $this->betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'DocSeq Beta Corp',
        'slug' => 'docseqbeta',
        'database_name' => 'tenant_docseq_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'docseqbeta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@docseqbeta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    /** @var TenantManager $tenantManager */
    $this->tenantManager = app(TenantManager::class);
    $this->service = app(DocumentNumberingService::class);
});

afterEach(function (): void {
    dropDocSeqTestTenantDatabase('tenant_docseq_alpha');
    dropDocSeqTestTenantDatabase('tenant_docseq_beta');
});

test('generates gap-free sequential document numbers for a given year', function (): void {
    $this->tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $num1 = $this->service->generateNext(DocumentType::Invoice, 2026);
    $num2 = $this->service->generateNext(DocumentType::Invoice, 2026);
    $num3 = $this->service->generateNext(DocumentType::Invoice, 2026);

    expect($num1)->toBe('INV-2026-000001')
        ->and($num2)->toBe('INV-2026-000002')
        ->and($num3)->toBe('INV-2026-000003');

    expect($this->service->getCurrentNumber(DocumentType::Invoice, 2026))->toBe(3);
});

test('peekNext previews the next number without consuming or incrementing sequence', function (): void {
    $this->tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    expect($this->service->getCurrentNumber(DocumentType::Quotation, 2026))->toBe(0);

    $peek1 = $this->service->peekNext(DocumentType::Quotation, 2026);
    $peek2 = $this->service->peekNext(DocumentType::Quotation, 2026);

    expect($peek1)->toBe('QUO-2026-000001')
        ->and($peek2)->toBe('QUO-2026-000001');

    // Sequence remains unconsumed
    expect($this->service->getCurrentNumber(DocumentType::Quotation, 2026))->toBe(0);

    // Consuming returns the predicted number
    $generated = $this->service->generateNext(DocumentType::Quotation, 2026);
    expect($generated)->toBe('QUO-2026-000001');
    expect($this->service->getCurrentNumber(DocumentType::Quotation, 2026))->toBe(1);

    // Next peek now previews 000002
    expect($this->service->peekNext(DocumentType::Quotation, 2026))->toBe('QUO-2026-000002');
});

test('different document types maintain independent sequences', function (): void {
    $this->tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $inv = $this->service->generateNext(DocumentType::Invoice, 2026);
    $po = $this->service->generateNext(DocumentType::PurchaseOrder, 2026);
    $adj = $this->service->generateNext(DocumentType::StockAdjustment, 2026);
    $bill = $this->service->generateNext(DocumentType::SupplierBill, 2026);

    expect($inv)->toBe('INV-2026-000001')
        ->and($po)->toBe('PO-2026-000001')
        ->and($adj)->toBe('ADJ-2026-000001')
        ->and($bill)->toBe('BILL-2026-000001');
});

test('sequences reset and track independently per year', function (): void {
    $this->tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $inv2026_1 = $this->service->generateNext(DocumentType::Invoice, 2026);
    $inv2026_2 = $this->service->generateNext(DocumentType::Invoice, 2026);

    $inv2027_1 = $this->service->generateNext(DocumentType::Invoice, 2027);

    expect($inv2026_1)->toBe('INV-2026-000001')
        ->and($inv2026_2)->toBe('INV-2026-000002')
        ->and($inv2027_1)->toBe('INV-2027-000001');

    expect($this->service->getCurrentNumber(DocumentType::Invoice, 2026))->toBe(2)
        ->and($this->service->getCurrentNumber(DocumentType::Invoice, 2027))->toBe(1);
});

test('supports custom prefix, padding, and string document types', function (): void {
    $this->tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $custom = $this->service->generateNext(
        type: 'custom_doc',
        year: 2026,
        customPrefix: 'CDOC',
        customPadding: 4,
    );

    expect($custom)->toBe('CDOC-2026-0001');
});

test('setNextNumber jumps sequence forward accurately', function (): void {
    $this->tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $this->service->setNextNumber(
        type: DocumentType::SupplierBill,
        nextNumber: 500,
        year: 2026,
    );

    expect($this->service->peekNext(DocumentType::SupplierBill, 2026))->toBe('BILL-2026-000500');

    $generated = $this->service->generateNext(DocumentType::SupplierBill, 2026);
    expect($generated)->toBe('BILL-2026-000500');

    $next = $this->service->generateNext(DocumentType::SupplierBill, 2026);
    expect($next)->toBe('BILL-2026-000501');
});

test('maintains strict multi-tenant sequence isolation across tenants', function (): void {
    // 1. Alpha Tenant generates two numbers
    $this->tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $alpha1 = $this->service->generateNext(DocumentType::Invoice, 2026);
    $alpha2 = $this->service->generateNext(DocumentType::Invoice, 2026);

    expect($alpha1)->toBe('INV-2026-000001')
        ->and($alpha2)->toBe('INV-2026-000002');

    // 2. Beta Tenant generates number
    $this->tenantManager->switchTo(new TenantContext(
        id: $this->betaTenant->id,
        slug: $this->betaTenant->slug,
        databaseName: $this->betaTenant->database_name,
    ));

    $beta1 = $this->service->generateNext(DocumentType::Invoice, 2026);
    expect($beta1)->toBe('INV-2026-000001');

    // Verify DB counts in respective tenant databases
    expect($this->service->getCurrentNumber(DocumentType::Invoice, 2026))->toBe(1);

    $this->tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    expect($this->service->getCurrentNumber(DocumentType::Invoice, 2026))->toBe(2);
});

test('transaction rollback prevents gap creation in document sequence', function (): void {
    $this->tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    // First successful invoice
    $num1 = $this->service->generateNext(DocumentType::Invoice, 2026);
    expect($num1)->toBe('INV-2026-000001');

    // Transaction that attempts to generate number 2, but rolls back
    try {
        DB::connection('tenant')->transaction(function (): void {
            $num2 = $this->service->generateNext(DocumentType::Invoice, 2026);
            expect($num2)->toBe('INV-2026-000002');
            throw new RuntimeException('Intentional rollback due to business logic validation failure.');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Intentional rollback due to business logic validation failure.');
    }

    // Sequence counter rolled back to 1
    expect($this->service->getCurrentNumber(DocumentType::Invoice, 2026))->toBe(1);

    // Next transaction receives INV-2026-000002 without leaving a gap
    $numNext = $this->service->generateNext(DocumentType::Invoice, 2026);
    expect($numNext)->toBe('INV-2026-000002');
});
