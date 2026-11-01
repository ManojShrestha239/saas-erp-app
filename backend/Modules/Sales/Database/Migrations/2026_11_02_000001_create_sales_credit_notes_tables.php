<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('credit_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('credit_note_number', 50)->unique();
            $table->string('customer_id', 26)->index();
            $table->string('sales_invoice_id', 26)->nullable()->index();
            $table->string('warehouse_id', 26)->index();
            $table->string('status', 32)->default('draft')->index();
            $table->date('issue_date');
            $table->string('currency', 3)->default('USD');
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->boolean('restock')->default(true);
            $table->string('reason', 64)->default('customer_return');
            $table->text('notes')->nullable();
            $table->string('created_by_user_id', 26)->nullable()->index();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('created_at');
        });

        Schema::connection('tenant')->create('credit_note_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('credit_note_id', 26)->index();
            $table->string('product_id', 26)->index();
            $table->string('description', 255)->nullable();
            $table->integer('quantity');
            $table->bigInteger('unit_price_cents');
            $table->integer('tax_rate_percent')->default(0);
            $table->string('tax_category', 32)->nullable();
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('cogs_unit_cost_cents')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('credit_note_id')
                ->references('id')
                ->on('credit_notes')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('credit_note_items');
        Schema::connection('tenant')->dropIfExists('credit_notes');
    }
};
