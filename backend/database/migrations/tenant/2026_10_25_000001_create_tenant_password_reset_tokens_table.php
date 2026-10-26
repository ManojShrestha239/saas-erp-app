<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email');
            $table->string('token');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->index('email');
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('password_reset_tokens');
    }
};
