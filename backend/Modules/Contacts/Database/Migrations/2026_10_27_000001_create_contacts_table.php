<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('contacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('type', 20)->index();
            $table->string('code', 50)->unique();
            $table->string('name', 255)->index();
            $table->string('tax_id', 50)->nullable()->index();
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->integer('payment_terms_days')->default(0);
            $table->bigInteger('credit_limit_cents')->default(0);
            $table->bigInteger('opening_balance_cents')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->jsonb('billing_address')->nullable();
            $table->jsonb('shipping_address')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('contacts');
    }
};
