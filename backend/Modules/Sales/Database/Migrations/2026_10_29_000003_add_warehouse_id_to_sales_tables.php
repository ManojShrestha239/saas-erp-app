<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('sales_quotations', 'warehouse_id')) {
            Schema::connection('tenant')->table('sales_quotations', function (Blueprint $table): void {
                $table->string('warehouse_id', 26)->nullable()->index()->after('customer_id');
            });
        }

        if (! Schema::connection('tenant')->hasColumn('sales_invoices', 'warehouse_id')) {
            Schema::connection('tenant')->table('sales_invoices', function (Blueprint $table): void {
                $table->string('warehouse_id', 26)->nullable()->index()->after('customer_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('sales_invoices', 'warehouse_id')) {
            Schema::connection('tenant')->table('sales_invoices', function (Blueprint $table): void {
                $table->dropIndex(['warehouse_id']);
                $table->dropColumn('warehouse_id');
            });
        }

        if (Schema::connection('tenant')->hasColumn('sales_quotations', 'warehouse_id')) {
            Schema::connection('tenant')->table('sales_quotations', function (Blueprint $table): void {
                $table->dropIndex(['warehouse_id']);
                $table->dropColumn('warehouse_id');
            });
        }
    }
};
