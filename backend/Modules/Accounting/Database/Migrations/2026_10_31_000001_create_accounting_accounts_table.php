<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name', 255);
            $table->string('type', 32)->index();
            $table->ulid('parent_id')->nullable()->index();
            $table->boolean('is_system')->default(false)->index();
            $table->string('system_name', 64)->nullable()->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->string('currency', 3)->default('USD');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index('code');
        });

        Schema::connection('tenant')->table('accounts', function (Blueprint $table): void {
            $table->foreign('parent_id')
                ->references('id')
                ->on('accounts')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('accounts')) {
            Schema::connection('tenant')->table('accounts', function (Blueprint $table): void {
                $table->dropForeign(['parent_id']);
            });

            Schema::connection('tenant')->dropIfExists('accounts');
        }
    }
};
