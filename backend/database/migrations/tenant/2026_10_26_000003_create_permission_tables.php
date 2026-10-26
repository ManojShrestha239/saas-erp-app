<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Ensure 'roles' table has 'guard_name' column
        if (Schema::connection('tenant')->hasTable('roles')) {
            if (! Schema::connection('tenant')->hasColumn('roles', 'guard_name')) {
                Schema::connection('tenant')->table('roles', function (Blueprint $table): void {
                    $table->string('guard_name')->default('web')->after('name');
                    $table->index(['name', 'guard_name']);
                });
            }
        } else {
            Schema::connection('tenant')->create('roles', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name')->default('web');
                $table->string('display_name')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique(['name', 'guard_name']);
            });
        }

        // 2. Create 'permissions' table
        if (! Schema::connection('tenant')->hasTable('permissions')) {
            Schema::connection('tenant')->create('permissions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name')->default('web');
                $table->timestamps();

                $table->unique(['name', 'guard_name']);
            });
        }

        // 3. Create 'model_has_permissions' table
        if (! Schema::connection('tenant')->hasTable('model_has_permissions')) {
            Schema::connection('tenant')->create('model_has_permissions', function (Blueprint $table): void {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->string('model_id', 36);

                $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
                $table->foreign('permission_id')
                    ->references('id')
                    ->on('permissions')
                    ->cascadeOnDelete();

                $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_primary');
            });
        }

        // 4. Create 'model_has_roles' table
        if (! Schema::connection('tenant')->hasTable('model_has_roles')) {
            Schema::connection('tenant')->create('model_has_roles', function (Blueprint $table): void {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->string('model_id', 36);

                $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
                $table->foreign('role_id')
                    ->references('id')
                    ->on('roles')
                    ->cascadeOnDelete();

                $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_primary');
            });
        }

        // 5. Create 'role_has_permissions' table
        if (! Schema::connection('tenant')->hasTable('role_has_permissions')) {
            Schema::connection('tenant')->create('role_has_permissions', function (Blueprint $table): void {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');

                $table->foreign('permission_id')
                    ->references('id')
                    ->on('permissions')
                    ->cascadeOnDelete();

                $table->foreign('role_id')
                    ->references('id')
                    ->on('roles')
                    ->cascadeOnDelete();

                $table->primary(['permission_id', 'role_id'], 'role_has_permissions_primary');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('role_has_permissions');
        Schema::connection('tenant')->dropIfExists('model_has_roles');
        Schema::connection('tenant')->dropIfExists('model_has_permissions');
        Schema::connection('tenant')->dropIfExists('permissions');

        if (Schema::connection('tenant')->hasTable('roles') && Schema::connection('tenant')->hasColumn('roles', 'guard_name')) {
            Schema::connection('tenant')->table('roles', function (Blueprint $table): void {
                $table->dropColumn('guard_name');
            });
        }
    }
};
