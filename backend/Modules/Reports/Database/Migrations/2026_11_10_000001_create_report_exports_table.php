<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations on the tenant connection.
     */
    public function up(): void
    {
        Schema::connection('tenant')->create('report_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('user_id');
            $table->string('report_type');
            $table->string('format', 10);
            $table->string('status', 20)->default('queued');
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('filters')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('report_exports');
    }
};
