<?php

declare(strict_types=1);

namespace Modules\Tenancy\Commands;

use Database\Seeders\DemoTenantSeeder;
use Illuminate\Console\Command;

final class SeedDemoTenantCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:seed-demo
        {--fresh : Drop and recreate the demo tenant database before seeding}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision and seed a rich demo tenant with operational ERP data (demo.nexuserp.test)';

    public function handle(DemoTenantSeeder $seeder): int
    {
        $this->info('Seeding demo tenant [demo.nexuserp.test]...');

        $fresh = (bool) $this->option('fresh');
        $seeder->seed(isFresh: $fresh);

        $this->newLine();
        $this->info('Demo tenant seeded successfully!');
        $this->line('  - Domain:   http://demo.nexuserp.test');
        $this->line('  - Owner:    owner@demo.nexuserp.test (Password: Demo12345!)');
        $this->line('  - Admin:    admin@demo.nexuserp.test (Password: Demo12345!)');
        $this->line('  - Staff:    accountant@ / warehouse@ / sales@ (Password: Demo12345!)');
        $this->line('  - API Docs: http://demo.nexuserp.test/docs');

        return self::SUCCESS;
    }
}
