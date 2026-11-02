<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('vat_returns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('return_number', 32)->unique();
            $table->string('fiscal_period_id', 26)->nullable()->index();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('status', 20)->default('filed')->index();
            $table->bigInteger('sales_taxable_cents')->default(0);
            $table->bigInteger('output_vat_cents')->default(0);
            $table->bigInteger('purchases_taxable_cents')->default(0);
            $table->bigInteger('input_vat_cents')->default(0);
            $table->bigInteger('net_vat_cents')->default(0);
            $table->jsonb('rate_breakdown')->default('[]');
            $table->boolean('is_reconciled')->default(true);
            $table->bigInteger('gl_output_vat_cents')->default(0);
            $table->bigInteger('gl_input_vat_cents')->default(0);
            $table->bigInteger('gl_discrepancy_cents')->default(0);
            $table->string('filed_by_user_id', 26)->nullable()->index();
            $table->timestamp('filed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['from_date', 'to_date']);
            $table->foreign('fiscal_period_id')
                ->references('id')
                ->on('fiscal_periods')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('vat_returns');
    }
};
