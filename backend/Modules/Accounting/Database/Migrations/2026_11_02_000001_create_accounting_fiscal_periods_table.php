<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('fiscal_periods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 64);
            $table->integer('fiscal_year')->index();
            $table->integer('period_number');
            $table->date('start_date')->index();
            $table->date('end_date')->index();
            $table->string('status', 32)->default('open')->index();
            $table->timestamp('locked_at')->nullable();
            $table->string('locked_by_user_id', 36)->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by_user_id', 36)->nullable()->index();
            $table->timestamps();

            $table->unique(['fiscal_year', 'period_number']);
            $table->index(['start_date', 'end_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('fiscal_periods');
    }
};
