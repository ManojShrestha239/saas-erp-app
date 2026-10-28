<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\SuperAdmin\Models\SuperAdmin;

final class SuperAdminSeeder extends Seeder
{
    public const DEFAULT_EMAIL = 'admin@nexuserp.test';

    public const DEFAULT_NAME = 'System Super Admin';

    public const DEFAULT_PASSWORD = 'SuperAdminSecret123!';

    public function run(): void
    {
        SuperAdmin::on('central')->updateOrCreate(
            ['email' => self::DEFAULT_EMAIL],
            [
                'name' => self::DEFAULT_NAME,
                'password' => Hash::make(self::DEFAULT_PASSWORD),
                'email_verified_at' => now(),
            ]
        );

        $this->command?->info(sprintf(
            'Super Admin seeded: email [%s] | password [%s]',
            self::DEFAULT_EMAIL,
            self::DEFAULT_PASSWORD
        ));
    }
}
