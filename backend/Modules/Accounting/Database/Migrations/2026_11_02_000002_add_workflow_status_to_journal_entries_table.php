<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('journal_entries', function (Blueprint $table): void {
            $table->string('entry_number', 32)->nullable()->change();
            $table->timestamp('posting_date')->nullable()->change();
            $table->string('submitted_by_user_id', 36)->nullable()->index();
            $table->timestamp('submitted_at')->nullable();
            $table->string('approved_by_user_id', 36)->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_by_user_id', 36)->nullable()->index();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('journal_entries', function (Blueprint $table): void {
            $table->dropColumn([
                'submitted_by_user_id',
                'submitted_at',
                'approved_by_user_id',
                'approved_at',
                'rejected_by_user_id',
                'rejected_at',
                'rejection_reason',
            ]);
        });
    }
};
