<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('users') && ! Schema::connection('tenant')->hasColumn('users', 'status')) {
            Schema::connection('tenant')->table('users', function (Blueprint $table): void {
                $table->string('status')->default('active')->after('email_verified_at');
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('users') && Schema::connection('tenant')->hasColumn('users', 'status')) {
            Schema::connection('tenant')->table('users', function (Blueprint $table): void {
                $table->dropColumn('status');
            });
        }
    }
};
