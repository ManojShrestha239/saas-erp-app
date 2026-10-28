<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csv_imports', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('type', 32);
            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->string('status', 32)->default('pending');
            $table->string('duplicate_action', 32)->default('skip');
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('successful_rows')->default(0);
            $table->integer('failed_rows')->default(0);
            $table->jsonb('errors')->nullable();
            $table->string('user_id', 26)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_imports');
    }
};
