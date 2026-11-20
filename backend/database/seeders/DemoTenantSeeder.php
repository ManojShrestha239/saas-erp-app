<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Database\Seeders\ChartOfAccountsSeeder;
use Modules\Accounting\DTOs\GenerateFiscalYearData;
use Modules\Accounting\DTOs\JournalEntryLineData;
use Modules\Accounting\DTOs\PostJournalEntryData;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\FiscalPeriodService;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Enums\ContactType;
use Modules\Contacts\Models\Contact;
use Modules\Inventory\DTOs\RecordStockMovementData;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\UnitOfMeasure;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockService;
use Modules\Payments\DTOs\CreatePaymentData;
use Modules\Payments\Services\PaymentService;
use Modules\Purchasing\DTOs\CreatePurchaseOrderData;
use Modules\Purchasing\DTOs\CreateSupplierBillData;
use Modules\Purchasing\DTOs\GoodsReceiptItemData;
use Modules\Purchasing\DTOs\PurchaseOrderItemData;
use Modules\Purchasing\DTOs\RecordGoodsReceiptData;
use Modules\Purchasing\DTOs\SupplierBillItemData;
use Modules\Purchasing\Models\PurchaseOrderItem;
use Modules\Purchasing\Models\SupplierBill;
use Modules\Purchasing\Services\GoodsReceiptService;
use Modules\Purchasing\Services\PurchaseOrderService;
use Modules\Purchasing\Services\SupplierBillService;
use Modules\Sales\DTOs\CreateSalesInvoiceData;
use Modules\Sales\DTOs\CreateSalesQuotationData;
use Modules\Sales\DTOs\SalesQuotationItemData;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Services\SalesInvoiceService;
use Modules\Sales\Services\SalesQuotationService;
use Modules\Tenancy\Models\Tenant;
use PDO;
use Shared\Http\Middleware\IdentifyTenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

final class DemoTenantSeeder extends Seeder
{
    public const TENANT_ID = '01M5WERPRYA9W846KK8ANQASJ5';

    public const SLUG = 'demo';

    public const DB_NAME = 'tenant_demo';

    public const PRIMARY_HOST = 'demo.nexuserp.test';

    public const LOCAL_HOST = 'demo.localhost';

    public const DEMO_PASSWORD = 'Demo12345!';

    public function run(): void
    {
        $this->seed(isFresh: false);
    }

    public function seed(bool $isFresh = false): void
    {
        // 1. Central Tenant & Domain setup
        $this->seedCentralRecords();

        if ($isFresh) {
            $this->dropTenantDatabase();
        }

        $this->ensureTenantDatabaseExists();

        $tenantManager = app(TenantManager::class);
        $context = new TenantContext(
            id: self::TENANT_ID,
            slug: self::SLUG,
            databaseName: self::DB_NAME,
        );

        try {
            $tenantManager->switchTo($context);

            // 2. Migrations
            $this->runTenantMigrations();

            // 3. Roles, Permissions, Settings, Chart of Accounts, Fiscal Periods
            (new TenantRolePermissionSeeder)->run();
            $this->seedSettings();
            (new ChartOfAccountsSeeder)->run();
            $this->seedFiscalPeriods();

            // 4. Tenant Users
            $users = $this->seedUsers();

            // 5. Inventory Catalog (Categories, UoMs, Warehouses, Products)
            $catalog = $this->seedCatalog();

            // 6. Opening Inventory & Ledger Initial Balances
            $this->seedOpeningStockAndLedger($catalog, $users['owner']);

            // 7. Commercial Partners (Customers & Suppliers)
            $contacts = $this->seedContacts();

            // 8. Purchasing Engine Flow (PO -> GRN -> Posted Supplier Bill)
            $purchasing = $this->seedPurchasingFlow($catalog, $contacts, $users['owner']);

            // 9. Sales Engine Flow (Quotation -> Accepted -> Posted Sales Invoices)
            $sales = $this->seedSalesFlow($catalog, $contacts, $users['owner']);

            // 10. Cash Settlements (Customer Inbound Receipt & Supplier Outbound Payment)
            $this->seedPaymentsFlow($contacts, $sales, $purchasing, $users['owner']);
        } finally {
            $tenantManager->reset();
        }
    }

    private function seedCentralRecords(): void
    {
        if (! Schema::connection('central')->hasTable('tenants')) {
            Artisan::call('migrate', [
                '--database' => 'central',
                '--path' => 'database/migrations/central',
                '--force' => true,
            ]);
        }

        Tenant::on('central')->updateOrInsert(
            ['id' => self::TENANT_ID],
            [
                'name' => 'NexusERP Demo Corp',
                'slug' => self::SLUG,
                'database_name' => self::DB_NAME,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::connection('central')->table('domains')->updateOrInsert(
            ['host' => self::PRIMARY_HOST],
            [
                'tenant_id' => self::TENANT_ID,
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::connection('central')->table('domains')->updateOrInsert(
            ['host' => self::LOCAL_HOST],
            [
                'tenant_id' => self::TENANT_ID,
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        IdentifyTenant::invalidateCache(self::SLUG);
        IdentifyTenant::invalidateCache(self::LOCAL_HOST);
        IdentifyTenant::invalidateCache(self::PRIMARY_HOST);
    }

    private function dropTenantDatabase(): void
    {
        try {
            $config = config('database.connections.central');
            /** @var array{driver?: string, host: string, port: int|string, username: string, password: string} $config */
            $driver = $config['driver'] ?? 'mysql';

            if ($driver === 'pgsql') {
                $pdo = new PDO(
                    dsn: sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
                    username: (string) $config['username'],
                    password: (string) $config['password'],
                );
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $pdo->exec('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '.
                    $pdo->quote(self::DB_NAME).' AND pid <> pg_backend_pid()');

                $quotedName = '"'.str_replace('"', '""', self::DB_NAME).'"';
                $pdo->exec("DROP DATABASE IF EXISTS {$quotedName}");
            } else {
                $dsn = sprintf('mysql:host=%s;port=%s', $config['host'], $config['port']);
                $pdo = new PDO(
                    dsn: $dsn,
                    username: (string) $config['username'],
                    password: (string) $config['password'],
                );
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $quotedName = '`'.str_replace('`', '``', self::DB_NAME).'`';
                $pdo->exec("DROP DATABASE IF EXISTS {$quotedName}");
            }
        } catch (Throwable) {
            // Best effort
        }
    }

    private function ensureTenantDatabaseExists(): void
    {
        $config = config('database.connections.central');
        /** @var array{driver?: string, host: string, port: int|string, username: string, password: string} $config */
        $driver = $config['driver'] ?? 'mysql';

        if ($driver === 'pgsql') {
            $pdo = new PDO(
                dsn: sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
                username: (string) $config['username'],
                password: (string) $config['password'],
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->query('SELECT COUNT(*) FROM pg_database WHERE datname = '.$pdo->quote(self::DB_NAME));
            $exists = $stmt !== false && ((int) $stmt->fetchColumn()) > 0;

            if (! $exists) {
                $quotedName = '"'.str_replace('"', '""', self::DB_NAME).'"';
                $pdo->exec("CREATE DATABASE {$quotedName}");
            }
        } else {
            $dsn = sprintf('mysql:host=%s;port=%s', $config['host'], $config['port']);
            $pdo = new PDO(
                dsn: $dsn,
                username: (string) $config['username'],
                password: (string) $config['password'],
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :dbname');
            $stmt->execute(['dbname' => self::DB_NAME]);
            $exists = ((int) $stmt->fetchColumn()) > 0;

            if (! $exists) {
                $quotedName = '`'.str_replace('`', '``', self::DB_NAME).'`';
                $pdo->exec("CREATE DATABASE {$quotedName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        }
    }

    private function runTenantMigrations(): void
    {
        $pattern = base_path('Modules/*/Database/Migrations');
        $modulePaths = glob($pattern) ?: [];

        $tenancyPath = base_path('Modules/Tenancy/Database/Migrations');
        $otherModulePaths = array_values(array_filter(
            $modulePaths,
            static fn (string $p): bool => $p !== $tenancyPath
        ));

        $paths = [];
        if (is_dir($tenancyPath)) {
            $paths[] = $tenancyPath;
        }
        $tenantPath = base_path('database/migrations/tenant');
        if (is_dir($tenantPath)) {
            $paths[] = $tenantPath;
        }
        foreach ($otherModulePaths as $p) {
            $paths[] = $p;
        }

        /** @var list<string> $validPaths */
        $validPaths = array_values(array_filter(array_unique($paths), 'is_dir'));

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => $validPaths,
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    private function seedSettings(): void
    {
        $settings = [
            ['key' => 'currency', 'value' => 'USD', 'group' => 'finance'],
            ['key' => 'timezone', 'value' => 'UTC', 'group' => 'general'],
            ['key' => 'allow_negative_stock', 'value' => 'false', 'group' => 'inventory'],
            ['key' => 'date_format', 'value' => 'Y-m-d', 'group' => 'general'],
            ['key' => 'invoice_prefix', 'value' => 'INV', 'group' => 'documents'],
            ['key' => 'bill_prefix', 'value' => 'BILL', 'group' => 'documents'],
        ];

        foreach ($settings as $setting) {
            DB::connection('tenant')->table('settings')->updateOrInsert(
                ['key' => $setting['key']],
                [
                    ...$setting,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function seedFiscalPeriods(): void
    {
        $service = app(FiscalPeriodService::class);
        $service->generateYear(new GenerateFiscalYearData(fiscalYear: (int) date('Y')));
    }

    /**
     * @return array{owner: TenantUser, admin: TenantUser, accountant: TenantUser, warehouse: TenantUser, sales: TenantUser}
     */
    private function seedUsers(): array
    {
        $usersConfig = [
            'owner' => ['name' => 'Demo Owner', 'email' => 'owner@demo.nexuserp.test', 'role' => TenantRole::Owner->value],
            'admin' => ['name' => 'Demo Admin', 'email' => 'admin@demo.nexuserp.test', 'role' => TenantRole::Admin->value],
            'accountant' => ['name' => 'Demo Accountant', 'email' => 'accountant@demo.nexuserp.test', 'role' => TenantRole::Accountant->value],
            'warehouse' => ['name' => 'Demo Warehouse Mgr', 'email' => 'warehouse@demo.nexuserp.test', 'role' => TenantRole::WarehouseManager->value],
            'sales' => ['name' => 'Demo Sales Rep', 'email' => 'sales@demo.nexuserp.test', 'role' => TenantRole::SalesRep->value],
        ];

        $users = [];
        foreach ($usersConfig as $key => $conf) {
            /** @var TenantUser $user */
            $user = TenantUser::updateOrCreate(
                ['email' => $conf['email']],
                [
                    'name' => $conf['name'],
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );
            $user->syncRoles([$conf['role']]);
            $users[$key] = $user;
        }

        return $users;
    }

    /**
     * @return array{warehouses: array<string, Warehouse>, products: array<string, Product>}
     */
    private function seedCatalog(): array
    {
        // Categories
        $catRenewable = Category::firstOrCreate(['slug' => 'renewable-energy'], ['name' => 'Renewable Energy', 'is_active' => true]);
        $catElectronics = Category::firstOrCreate(['slug' => 'electronics'], ['name' => 'Industrial Electronics', 'is_active' => true]);
        $catOffice = Category::firstOrCreate(['slug' => 'office'], ['name' => 'Office Equipment', 'is_active' => true]);

        // Units of Measure
        $uomPcs = UnitOfMeasure::firstOrCreate(['name' => 'Pieces'], ['symbol' => 'pcs', 'is_active' => true]);
        $uomMeters = UnitOfMeasure::firstOrCreate(['name' => 'Meters'], ['symbol' => 'm', 'is_active' => true]);

        // Warehouses
        $whMain = Warehouse::firstOrCreate(
            ['code' => 'WH-MAIN'],
            ['name' => 'Main Distribution Hub', 'is_default' => true, 'is_active' => true]
        );
        $whEast = Warehouse::firstOrCreate(
            ['code' => 'WH-EAST'],
            ['name' => 'East Coast Regional Storage', 'is_default' => false, 'is_active' => true]
        );

        // Products
        $p1 = Product::firstOrCreate(
            ['sku' => 'SOLAR-INV-5K'],
            [
                'name' => 'Solar Hybrid Inverter 5kW',
                'category_id' => $catRenewable->id,
                'unit_of_measure_id' => $uomPcs->id,
                'cost_price_cents' => 45000,
                'selling_price_cents' => 69900,
                'tax_category' => 'standard',
                'low_stock_threshold' => 10,
                'is_active' => true,
            ]
        );

        $p2 = Product::firstOrCreate(
            ['sku' => 'SOLAR-BAT-10K'],
            [
                'name' => 'Lithium Battery Storage 10kWh',
                'category_id' => $catRenewable->id,
                'unit_of_measure_id' => $uomPcs->id,
                'cost_price_cents' => 95000,
                'selling_price_cents' => 145000,
                'tax_category' => 'standard',
                'low_stock_threshold' => 5,
                'is_active' => true,
            ]
        );

        $p3 = Product::firstOrCreate(
            ['sku' => 'IND-CABLE-100M'],
            [
                'name' => '3-Phase Heavy Duty Armored Cable',
                'category_id' => $catElectronics->id,
                'unit_of_measure_id' => $uomMeters->id,
                'cost_price_cents' => 8500,
                'selling_price_cents' => 14000,
                'tax_category' => 'standard',
                'low_stock_threshold' => 20,
                'is_active' => true,
            ]
        );

        $p4 = Product::firstOrCreate(
            ['sku' => 'SMART-SENSOR-X'],
            [
                'name' => 'Industrial Environmental IoT Sensor',
                'category_id' => $catElectronics->id,
                'unit_of_measure_id' => $uomPcs->id,
                'cost_price_cents' => 3000,
                'selling_price_cents' => 5500,
                'tax_category' => 'standard',
                'low_stock_threshold' => 50,
                'is_active' => true,
            ]
        );

        return [
            'warehouses' => ['main' => $whMain, 'east' => $whEast],
            'products' => ['inverter' => $p1, 'battery' => $p2, 'cable' => $p3, 'sensor' => $p4],
        ];
    }

    /**
     * @param  array{warehouses: array<string, Warehouse>, products: array<string, Product>}  $catalog
     */
    private function seedOpeningStockAndLedger(array $catalog, TenantUser $owner): void
    {
        $stockService = app(StockService::class);
        $wh = $catalog['warehouses']['main'];

        $openings = [
            ['product' => $catalog['products']['inverter'], 'qty' => 50, 'cost' => 45000],   // 2,250,000 cents
            ['product' => $catalog['products']['battery'], 'qty' => 30, 'cost' => 95000],    // 2,850,000 cents
            ['product' => $catalog['products']['cable'], 'qty' => 100, 'cost' => 8500],      // 850,000 cents
            ['product' => $catalog['products']['sensor'], 'qty' => 5, 'cost' => 3000],        // 15,000 cents (triggers low stock: 5 < 50 threshold)
        ];

        $totalOpeningCents = 0;
        foreach ($openings as $item) {
            $stockService->recordMovement(new RecordStockMovementData(
                warehouseId: (string) $wh->id,
                productId: (string) $item['product']->id,
                type: StockMovementType::OpeningBalance,
                quantity: $item['qty'],
                unitCostCents: $item['cost'],
                sourceType: 'opening_balance',
                sourceId: 'seed',
                userId: (string) $owner->id,
                notes: 'Demo opening inventory balance',
            ));
            $totalOpeningCents += $item['qty'] * $item['cost'];
        }

        // Post balanced General Ledger opening journal: Debit Inventory Asset (1150) / Credit Owner's Equity (3100)
        $inventoryAccount = Account::where('code', '1150')->firstOrFail();
        $equityAccount = Account::where('code', '3100')->firstOrFail();

        $journalService = app(JournalEntryService::class);
        $journalService->post(new PostJournalEntryData(
            entryDate: Carbon::now()->startOfYear(),
            description: 'Opening inventory asset valuation',
            lines: [
                new JournalEntryLineData(
                    accountId: (string) $inventoryAccount->id,
                    debitCents: $totalOpeningCents,
                    creditCents: 0,
                    description: 'Opening inventory on hand',
                ),
                new JournalEntryLineData(
                    accountId: (string) $equityAccount->id,
                    debitCents: 0,
                    creditCents: $totalOpeningCents,
                    description: 'Owner initial contributed equity',
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 'seed',
            currency: 'USD',
            userId: (string) $owner->id,
        ));
    }

    /**
     * @return array{custAcme: Contact, custEverest: Contact, suppPv: Contact, suppCable: Contact}
     */
    private function seedContacts(): array
    {
        $c1 = Contact::firstOrCreate(
            ['code' => 'CUST-000001'],
            [
                'type' => ContactType::Customer,
                'name' => 'Acme Industrial Systems Ltd',
                'tax_id' => 'PAN-99881122',
                'email' => 'billing@acmeindustrial.test',
                'phone' => '+1-555-0199',
                'payment_terms_days' => 30,
                'credit_limit_cents' => 50000000,
                'is_active' => true,
            ]
        );

        $c2 = Contact::firstOrCreate(
            ['code' => 'CUST-000002'],
            [
                'type' => ContactType::Customer,
                'name' => 'Everest Green Energy Co.',
                'tax_id' => 'PAN-88772233',
                'email' => 'accounts@everestgreen.test',
                'phone' => '+1-555-0288',
                'payment_terms_days' => 15,
                'credit_limit_cents' => 80000000,
                'is_active' => true,
            ]
        );

        $s1 = Contact::firstOrCreate(
            ['code' => 'SUPP-000001'],
            [
                'type' => ContactType::Supplier,
                'name' => 'Global PV & Power Systems Inc.',
                'tax_id' => 'PAN-11223344',
                'email' => 'orders@globalpv.test',
                'phone' => '+1-555-0377',
                'payment_terms_days' => 30,
                'is_active' => true,
            ]
        );

        $s2 = Contact::firstOrCreate(
            ['code' => 'SUPP-000002'],
            [
                'type' => ContactType::Supplier,
                'name' => 'Precision Industrial Cable Co.',
                'tax_id' => 'PAN-55667788',
                'email' => 'sales@precisioncable.test',
                'phone' => '+1-555-0466',
                'payment_terms_days' => 30,
                'is_active' => true,
            ]
        );

        return [
            'custAcme' => $c1,
            'custEverest' => $c2,
            'suppPv' => $s1,
            'suppCable' => $s2,
        ];
    }

    /**
     * @param  array{warehouses: array<string, Warehouse>, products: array<string, Product>}  $catalog
     * @param  array{custAcme: Contact, custEverest: Contact, suppPv: Contact, suppCable: Contact}  $contacts
     * @return array{bill: SupplierBill}
     */
    private function seedPurchasingFlow(array $catalog, array $contacts, TenantUser $owner): array
    {
        $poService = app(PurchaseOrderService::class);
        $grnService = app(GoodsReceiptService::class);
        $billService = app(SupplierBillService::class);

        $wh = $catalog['warehouses']['main'];
        $supp = $contacts['suppPv'];
        $product = $catalog['products']['inverter'];

        // 1. Purchase Order (10x Inverter @ 45,000 cents)
        $po = $poService->create(new CreatePurchaseOrderData(
            supplierId: (string) $supp->id,
            warehouseId: (string) $wh->id,
            orderDate: Carbon::now()->subDays(10)->toDateString(),
            expectedDeliveryDate: Carbon::now()->subDays(5)->toDateString(),
            notes: 'Demo Purchase Order for Inverters',
            items: [
                new PurchaseOrderItemData(
                    productId: (string) $product->id,
                    quantityOrdered: 10,
                    unitCostCents: 45000,
                    taxRatePercent: 13,
                    taxCategory: 'standard',
                ),
            ],
        ));

        $poService->approve((string) $po->id, (string) $owner->id);

        // 2. Goods Receipt (10 received)
        /** @var PurchaseOrderItem $poItem */
        $poItem = $po->items()->firstOrFail();
        $grnService->recordReceipt(new RecordGoodsReceiptData(
            purchaseOrderId: (string) $po->id,
            receivedDate: Carbon::now()->subDays(4)->toDateString(),
            notes: 'Goods received in warehouse in good condition',
            userId: (string) $owner->id,
            items: [
                new GoodsReceiptItemData(
                    purchaseOrderItemId: (string) $poItem->id,
                    quantityReceived: 10,
                ),
            ],
        ));

        // 3. Supplier Bill (posted to GL)
        $bill = $billService->create(new CreateSupplierBillData(
            supplierId: (string) $supp->id,
            billDate: Carbon::now()->subDays(4)->toDateString(),
            dueDate: Carbon::now()->addDays(26)->toDateString(),
            supplierInvoiceNumber: 'GPV-INV-9021',
            purchaseOrderId: (string) $po->id,
            currency: 'USD',
            notes: 'Supplier bill for PO-2026-000001',
            userId: (string) $owner->id,
            items: [
                new SupplierBillItemData(
                    description: 'Solar Hybrid Inverter 5kW',
                    quantity: 10,
                    unitCostCents: 45000,
                    taxRatePercent: 13,
                    purchaseOrderItemId: (string) $poItem->id,
                    productId: (string) $product->id,
                ),
            ],
        ));

        $billService->post((string) $bill->id, (string) $owner->id);

        return ['bill' => $bill];
    }

    /**
     * @param  array{warehouses: array<string, Warehouse>, products: array<string, Product>}  $catalog
     * @param  array{custAcme: Contact, custEverest: Contact, suppPv: Contact, suppCable: Contact}  $contacts
     * @return array{invCurrent: SalesInvoice, invOverdue: SalesInvoice}
     */
    private function seedSalesFlow(array $catalog, array $contacts, TenantUser $owner): array
    {
        $quoteService = app(SalesQuotationService::class);
        $invoiceService = app(SalesInvoiceService::class);

        $wh = $catalog['warehouses']['main'];
        $customer = $contacts['custAcme'];
        $custOverdue = $contacts['custEverest'];
        $productInverter = $catalog['products']['inverter'];
        $productBattery = $catalog['products']['battery'];

        // 1. Sales Quotation
        $quote = $quoteService->create(new CreateSalesQuotationData(
            customerId: (string) $customer->id,
            warehouseId: (string) $wh->id,
            issueDate: Carbon::now()->subDays(8)->toDateString(),
            expiryDate: Carbon::now()->addDays(22)->toDateString(),
            notes: 'Quotation for solar energy package',
            items: [
                new SalesQuotationItemData(
                    productId: (string) $productInverter->id,
                    quantity: 5,
                    unitPriceCents: 69900,
                    taxRatePercent: 13,
                    taxCategory: 'standard',
                    description: 'Solar Hybrid Inverter 5kW',
                ),
            ],
        ), (string) $owner->id);

        $quoteService->markAsAccepted((string) $quote->id, (string) $owner->id);

        // 2. Sales Invoice 1 (Current, Posted)
        $inv1 = $invoiceService->create(CreateSalesInvoiceData::fromArray([
            'customer_id' => (string) $customer->id,
            'warehouse_id' => (string) $wh->id,
            'quotation_id' => (string) $quote->id,
            'issue_date' => Carbon::now()->subDays(5)->toDateString(),
            'due_date' => Carbon::now()->addDays(25)->toDateString(),
            'notes' => 'Commercial sales invoice against QUO-2026-000001',
            'items' => [
                [
                    'product_id' => (string) $productInverter->id,
                    'quantity' => 5,
                    'unit_price_cents' => 69900,
                    'tax_rate_percent' => 13,
                    'description' => 'Solar Hybrid Inverter 5kW',
                ],
            ],
        ]), (string) $owner->id);

        $invoiceService->post((string) $inv1->id, (string) $owner->id);

        // 3. Sales Invoice 2 (Overdue, Posted for testing alerts & aging buckets)
        $inv2 = $invoiceService->create(CreateSalesInvoiceData::fromArray([
            'customer_id' => (string) $custOverdue->id,
            'warehouse_id' => (string) $wh->id,
            'issue_date' => Carbon::now()->subDays(45)->toDateString(),
            'due_date' => Carbon::now()->subDays(15)->toDateString(), // Overdue by 15 days!
            'notes' => 'Commercial sales invoice for battery energy storage',
            'items' => [
                [
                    'product_id' => (string) $productBattery->id,
                    'quantity' => 2,
                    'unit_price_cents' => 145000,
                    'tax_rate_percent' => 13,
                    'description' => 'Lithium Battery Storage 10kWh',
                ],
            ],
        ]), (string) $owner->id);

        $invoiceService->post((string) $inv2->id, (string) $owner->id);

        return ['invCurrent' => $inv1, 'invOverdue' => $inv2];
    }

    /**
     * @param  array{custAcme: Contact, custEverest: Contact, suppPv: Contact, suppCable: Contact}  $contacts
     * @param  array{invCurrent: SalesInvoice, invOverdue: SalesInvoice}  $sales
     * @param  array{bill: SupplierBill}  $purchasing
     */
    private function seedPaymentsFlow(array $contacts, array $sales, array $purchasing, TenantUser $owner): void
    {
        $paymentService = app(PaymentService::class);

        // 1. Inbound Customer Receipt: $2,000 (200,000 cents) against Invoice 1
        $paymentService->create(CreatePaymentData::fromArray([
            'type' => 'inbound',
            'contact_id' => (string) $contacts['custAcme']->id,
            'amount_cents' => 200000,
            'payment_method' => 'bank_transfer',
            'payment_date' => Carbon::now()->subDays(2)->toDateString(),
            'reference_number' => 'WIRE-ACME-001',
            'notes' => 'Partial settlement for INV-2026-000001',
            'post_immediately' => true,
            'allocations' => [
                [
                    'payable_type' => 'invoice',
                    'payable_id' => (string) $sales['invCurrent']->id,
                    'amount_cents' => 200000,
                ],
            ],
        ]), (string) $owner->id);

        // 2. Outbound Supplier Payment: $2,500 (250,000 cents) against Supplier Bill
        $paymentService->create(CreatePaymentData::fromArray([
            'type' => 'outbound',
            'contact_id' => (string) $contacts['suppPv']->id,
            'amount_cents' => 250000,
            'payment_method' => 'bank_transfer',
            'payment_date' => Carbon::now()->subDays(1)->toDateString(),
            'reference_number' => 'SWIFT-GPV-001',
            'notes' => 'Partial disbursement for BILL-2026-000001',
            'post_immediately' => true,
            'allocations' => [
                [
                    'payable_type' => 'bill',
                    'payable_id' => (string) $purchasing['bill']->id,
                    'amount_cents' => 250000,
                ],
            ],
        ]), (string) $owner->id);
    }
}
