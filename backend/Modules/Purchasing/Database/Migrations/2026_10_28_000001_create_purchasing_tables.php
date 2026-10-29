<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Purchase Orders
        Schema::connection('tenant')->create('purchase_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('order_number', 50)->unique();
            $table->string('supplier_id', 26)->index();
            $table->string('warehouse_id', 26)->index();
            $table->string('status', 32)->default('draft')->index();
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->text('notes')->nullable();
            $table->string('created_by_user_id', 26)->nullable()->index();
            $table->string('approved_by_user_id', 26)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index('created_at');
        });

        // 2. Purchase Order Items
        Schema::connection('tenant')->create('purchase_order_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('purchase_order_id', 26)->index();
            $table->string('product_id', 26)->index();
            $table->integer('quantity_ordered');
            $table->integer('quantity_received')->default(0);
            $table->bigInteger('unit_cost_cents');
            $table->integer('tax_rate_percent')->default(0);
            $table->string('tax_category', 32)->nullable();
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('purchase_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->cascadeOnDelete();
        });

        // 3. Goods Receipts
        Schema::connection('tenant')->create('goods_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('receipt_number', 50)->unique();
            $table->string('purchase_order_id', 26)->index();
            $table->string('supplier_id', 26)->index();
            $table->string('warehouse_id', 26)->index();
            $table->date('received_date');
            $table->text('notes')->nullable();
            $table->string('received_by_user_id', 26)->nullable()->index();
            $table->timestamps();

            $table->foreign('purchase_order_id')
                ->references('id')
                ->on('purchase_orders');
        });

        // 4. Goods Receipt Items
        Schema::connection('tenant')->create('goods_receipt_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('goods_receipt_id', 26)->index();
            $table->string('purchase_order_item_id', 26)->index();
            $table->string('product_id', 26)->index();
            $table->integer('quantity_received');
            $table->bigInteger('unit_cost_cents');
            $table->timestamps();

            $table->foreign('goods_receipt_id')
                ->references('id')
                ->on('goods_receipts')
                ->cascadeOnDelete();

            $table->foreign('purchase_order_item_id')
                ->references('id')
                ->on('purchase_order_items');
        });

        // 5. Supplier Bills
        Schema::connection('tenant')->create('supplier_bills', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('bill_number', 50)->unique();
            $table->string('supplier_invoice_number', 100)->nullable()->index();
            $table->string('supplier_id', 26)->index();
            $table->string('purchase_order_id', 26)->nullable()->index();
            $table->date('bill_date');
            $table->date('due_date');
            $table->string('currency', 3)->default('USD');
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('amount_due_cents')->default(0);
            $table->bigInteger('amount_paid_cents')->default(0);
            $table->string('status', 32)->default('draft')->index();
            $table->timestamp('posted_at')->nullable();
            $table->string('posted_by_user_id', 26)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index('created_at');
        });

        // 6. Supplier Bill Items
        Schema::connection('tenant')->create('supplier_bill_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('supplier_bill_id', 26)->index();
            $table->string('purchase_order_item_id', 26)->nullable()->index();
            $table->string('product_id', 26)->nullable()->index();
            $table->string('description', 255);
            $table->integer('quantity')->default(1);
            $table->bigInteger('unit_cost_cents');
            $table->integer('tax_rate_percent')->default(0);
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->timestamps();

            $table->foreign('supplier_bill_id')
                ->references('id')
                ->on('supplier_bills')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('supplier_bill_items');
        Schema::connection('tenant')->dropIfExists('supplier_bills');
        Schema::connection('tenant')->dropIfExists('goods_receipt_items');
        Schema::connection('tenant')->dropIfExists('goods_receipts');
        Schema::connection('tenant')->dropIfExists('purchase_order_items');
        Schema::connection('tenant')->dropIfExists('purchase_orders');
    }
};
