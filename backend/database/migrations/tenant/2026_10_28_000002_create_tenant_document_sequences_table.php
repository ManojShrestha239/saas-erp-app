<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('type', 50);
            $table->string('prefix', 20);
            $table->smallInteger('year')->default(0);
            $table->bigInteger('current_number')->default(0);
            $table->smallInteger('padding')->default(6);
            $table->timestamps();

            $table->unique(['type', 'year'], 'document_sequences_type_year_unique');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
