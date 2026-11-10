<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('activity_log')) {
            Schema::connection('tenant')->create('activity_log', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('log_name')->nullable()->index();
                $table->text('description');
                $table->string('subject_type')->nullable();
                $table->string('subject_id', 36)->nullable();
                $table->string('event')->nullable()->index();
                $table->string('causer_type')->nullable();
                $table->string('causer_id', 36)->nullable();
                $table->jsonb('attribute_changes')->nullable();
                $table->jsonb('properties')->nullable();
                $table->uuid('batch_uuid')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();

                $table->index(['subject_type', 'subject_id']);
                $table->index(['causer_type', 'causer_id']);
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('activity_log');
    }
};
