<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Sales Quotations
        Schema::connection('tenant')->create('sales_quotations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('quotation_number', 50)->unique();
            $table->string('customer_id', 26)->index();
            $table->string('status', 32)->default('draft')->index();
            $table->date('issue_date');
            $table->date('expiry_date')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->text('notes')->nullable();
            $table->string('created_by_user_id', 26)->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('converted_to_invoice_id', 26)->nullable()->index();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('created_at');
        });

        // 2. Sales Quotation Items
        Schema::connection('tenant')->create('sales_quotation_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('sales_quotation_id', 26)->index();
            $table->string('product_id', 26)->index();
            $table->string('description', 255)->nullable();
            $table->integer('quantity');
            $table->bigInteger('unit_price_cents');
            $table->integer('tax_rate_percent')->default(0);
            $table->string('tax_category', 32)->nullable();
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('sales_quotation_id')
                ->references('id')
                ->on('sales_quotations')
                ->cascadeOnDelete();
        });

        // 3. Sales Invoices
        Schema::connection('tenant')->create('sales_invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('invoice_number', 50)->unique();
            $table->string('sales_quotation_id', 26)->nullable()->index();
            $table->string('customer_id', 26)->index();
            $table->string('status', 32)->default('draft')->index();
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('currency', 3)->default('USD');
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('amount_paid_cents')->default(0);
            $table->bigInteger('amount_due_cents')->default(0);
            $table->text('notes')->nullable();
            $table->string('created_by_user_id', 26)->nullable()->index();
            $table->string('posted_by_user_id', 26)->nullable()->index();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('created_at');
        });

        // 4. Sales Invoice Items
        Schema::connection('tenant')->create('sales_invoice_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('sales_invoice_id', 26)->index();
            $table->string('product_id', 26)->index();
            $table->string('description', 255)->nullable();
            $table->integer('quantity');
            $table->bigInteger('unit_price_cents');
            $table->integer('tax_rate_percent')->default(0);
            $table->string('tax_category', 32)->nullable();
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('sales_invoice_id')
                ->references('id')
                ->on('sales_invoices')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('sales_invoice_items');
        Schema::connection('tenant')->dropIfExists('sales_invoices');
        Schema::connection('tenant')->dropIfExists('sales_quotation_items');
        Schema::connection('tenant')->dropIfExists('sales_quotations');
    }
};
