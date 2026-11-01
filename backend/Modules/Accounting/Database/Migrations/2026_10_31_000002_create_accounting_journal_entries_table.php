<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('journal_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('entry_number', 32)->unique();
            $table->date('entry_date')->index();
            $table->timestamp('posting_date')->index();
            $table->string('status', 32)->default('posted')->index();
            $table->text('description')->nullable();
            $table->string('source_type', 64)->nullable()->index();
            $table->string('source_id', 36)->nullable()->index();
            $table->unsignedBigInteger('total_debit_cents');
            $table->unsignedBigInteger('total_credit_cents');
            $table->string('currency', 3)->default('USD');
            $table->string('created_by_user_id', 36)->nullable()->index();
            $table->ulid('reversed_by_id')->nullable()->index();
            $table->ulid('reversal_of_id')->nullable()->index();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['status', 'entry_date']);
        });

        Schema::connection('tenant')->table('journal_entries', function (Blueprint $table): void {
            $table->foreign('reversed_by_id')
                ->references('id')
                ->on('journal_entries')
                ->onDelete('set null');

            $table->foreign('reversal_of_id')
                ->references('id')
                ->on('journal_entries')
                ->onDelete('set null');
        });

        Schema::connection('tenant')->create('journal_entry_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('journal_entry_id')->index();
            $table->ulid('account_id')->index();
            $table->integer('line_number');
            $table->unsignedBigInteger('debit_cents')->default(0);
            $table->unsignedBigInteger('credit_cents')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
        });

        Schema::connection('tenant')->table('journal_entry_lines', function (Blueprint $table): void {
            $table->foreign('journal_entry_id')
                ->references('id')
                ->on('journal_entries')
                ->onDelete('cascade');

            $table->foreign('account_id')
                ->references('id')
                ->on('accounts')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('journal_entry_lines')) {
            Schema::connection('tenant')->table('journal_entry_lines', function (Blueprint $table): void {
                $table->dropForeign(['journal_entry_id']);
                $table->dropForeign(['account_id']);
            });

            Schema::connection('tenant')->dropIfExists('journal_entry_lines');
        }

        if (Schema::connection('tenant')->hasTable('journal_entries')) {
            Schema::connection('tenant')->table('journal_entries', function (Blueprint $table): void {
                $table->dropForeign(['reversed_by_id']);
                $table->dropForeign(['reversal_of_id']);
            });

            Schema::connection('tenant')->dropIfExists('journal_entries');
        }
    }
};
