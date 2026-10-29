<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('payment_number', 32)->unique();
            $table->string('type', 20)->index();
            $table->ulid('contact_id')->index();
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('allocated_amount_cents')->default(0);
            $table->unsignedBigInteger('unallocated_amount_cents');
            $table->string('currency', 3)->default('USD');
            $table->string('payment_method', 30);
            $table->date('payment_date');
            $table->string('reference_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->string('created_by_user_id', 36)->nullable()->index();
            $table->string('posted_by_user_id', 36)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'type', 'status']);
            $table->index(['payment_date', 'status']);
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('payment_id');
            $table->string('payable_type', 50);
            $table->ulid('payable_id')->index();
            $table->unsignedBigInteger('amount_cents');
            $table->timestamp('allocated_at')->useCurrent();
            $table->string('allocated_by_user_id', 36)->nullable();
            $table->timestamps();

            $table->foreign('payment_id')
                ->references('id')
                ->on('payments')
                ->cascadeOnDelete();

            $table->index(['payment_id', 'payable_type', 'payable_id'], 'idx_payment_payable_alloc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
