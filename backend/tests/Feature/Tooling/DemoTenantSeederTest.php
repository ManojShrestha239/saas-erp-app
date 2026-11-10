<?php

declare(strict_types=1);

use Database\Seeders\DemoTenantSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\FinancialReportService;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Models\Contact;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Modules\Payments\Models\Payment;
use Modules\Purchasing\Models\GoodsReceipt;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\SupplierBill;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesQuotation;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Tests\TestCase;

beforeEach(function (): void {
    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);
});

test('tenants:seed-demo command provisions and populates demo tenant with full operational cycle', function (): void {
    /** @var TestCase $this */
    // 1. Run seed command with --fresh
    $exitCode = Artisan::call('tenants:seed-demo', ['--fresh' => true]);
    expect($exitCode)->toBe(0);

    // 2. Central database assertions
    $tenant = Tenant::on('central')->where('id', DemoTenantSeeder::TENANT_ID)->first();
    expect($tenant)->not->toBeNull()
        ->and($tenant?->slug)->toBe(DemoTenantSeeder::SLUG)
        ->and($tenant?->database_name)->toBe(DemoTenantSeeder::DB_NAME)
        ->and($tenant?->status)->toBe('active');

    $domainExists = Tenant::on('central')
        ->getConnection()
        ->table('domains')
        ->where('tenant_id', DemoTenantSeeder::TENANT_ID)
        ->where('host', DemoTenantSeeder::PRIMARY_HOST)
        ->exists();
    expect($domainExists)->toBeTrue();

    // 3. Switch to tenant context for tenant-scoped data checks
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: DemoTenantSeeder::TENANT_ID,
        slug: DemoTenantSeeder::SLUG,
        databaseName: DemoTenantSeeder::DB_NAME,
    ));

    try {
        // Users & Roles
        $users = TenantUser::query()->with('roles')->get();
        expect($users->count())->toBe(5);

        $rolesByEmail = $users->mapWithKeys(fn (TenantUser $u): array => [
            $u->email => $u->roles->pluck('name')->first(),
        ])->all();

        expect($rolesByEmail)->toMatchArray([
            'owner@demo.nexuserp.test' => 'owner',
            'admin@demo.nexuserp.test' => 'admin',
            'accountant@demo.nexuserp.test' => 'accountant',
            'warehouse@demo.nexuserp.test' => 'warehouse_manager',
            'sales@demo.nexuserp.test' => 'sales_rep',
        ]);

        // Warehouses & Products
        expect(Warehouse::query()->count())->toBe(2);
        expect(Product::query()->count())->toBe(4);
        expect(Contact::query()->count())->toBe(4);

        // Inventory movements & GL Opening Entry
        expect(StockMovement::query()->count())->toBeGreaterThanOrEqual(4);
        expect(JournalEntry::query()->where('source_type', 'opening_balance')->exists())->toBeTrue();

        // Purchasing cycle
        $po = PurchaseOrder::query()->where('status', 'received')->first();
        expect($po)->not->toBeNull();
        expect(GoodsReceipt::query()->count())->toBeGreaterThanOrEqual(1);

        $bill = SupplierBill::query()->where('status', 'partially_paid')->first();
        expect($bill)->not->toBeNull()
            ->and($bill?->amount_paid_cents)->toBe(250000);

        // Sales cycle
        $quotation = SalesQuotation::query()->where('status', 'accepted')->first();
        expect($quotation)->not->toBeNull();

        $invoices = SalesInvoice::query()->get();
        expect($invoices->count())->toBe(2);

        $currentInv = $invoices->firstWhere('status', 'partially_paid');
        expect($currentInv)->not->toBeNull()
            ->and($currentInv?->amount_paid_cents)->toBe(200000);

        $overdueInv = $invoices->firstWhere('status', 'posted');
        expect($overdueInv)->not->toBeNull()
            ->and($overdueInv?->amount_paid_cents)->toBe(0);

        // Payments & Allocations
        $payments = Payment::query()->with('allocations')->where('status', 'posted')->get();
        expect($payments->count())->toBe(2);
        expect($payments->sum('amount_cents'))->toBe(450000); // 200,000 + 250,000

        // General Ledger Balance (Trial Balance equilibrium)
        $reportService = app(FinancialReportService::class);
        $trialBalance = $reportService->getTrialBalance();

        expect($trialBalance->isBalanced)->toBeTrue()
            ->and($trialBalance->totalDebitsCents)->toBe($trialBalance->totalCreditsCents)
            ->and($trialBalance->totalDebitsCents)->toBeGreaterThan(0);
    } finally {
        $tenantManager->reset();
    }

    // 4. Authentication via HTTP endpoint for all 5 demo personas
    $credentials = [
        'owner@demo.nexuserp.test',
        'admin@demo.nexuserp.test',
        'accountant@demo.nexuserp.test',
        'warehouse@demo.nexuserp.test',
        'sales@demo.nexuserp.test',
    ];

    foreach ($credentials as $email) {
        $response = $this->postJson('http://demo.localhost/api/v1/auth/login', [
            'email' => $email,
            'password' => DemoTenantSeeder::DEMO_PASSWORD,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', $email);
    }
});

test('tenants:seed-demo command is idempotent when run multiple times', function (): void {
    // Run once without fresh (working on top of previous state)
    $exitCode1 = Artisan::call('tenants:seed-demo');
    expect($exitCode1)->toBe(0);

    // Run second time
    $exitCode2 = Artisan::call('tenants:seed-demo');
    expect($exitCode2)->toBe(0);

    // Confirm tenant still exists and is healthy
    $tenant = Tenant::on('central')->where('id', DemoTenantSeeder::TENANT_ID)->first();
    expect($tenant)->not->toBeNull()
        ->and($tenant?->status)->toBe('active');
});

test('static scribe api documentation assets exist and are compiled', function (): void {
    $docsHtmlPath = public_path('docs/index.html');
    $openapiYamlPath = public_path('docs/openapi.yaml');
    $collectionJsonPath = public_path('docs/collection.json');

    expect(File::exists($docsHtmlPath))->toBeTrue();
    expect(File::exists($openapiYamlPath))->toBeTrue();
    expect(File::exists($collectionJsonPath))->toBeTrue();

    $html = File::get($docsHtmlPath);
    expect($html)->toContain('NexusERP API Documentation');

    $yaml = File::get($openapiYamlPath);
    expect(strlen($yaml))->toBeGreaterThan(1000);
});
