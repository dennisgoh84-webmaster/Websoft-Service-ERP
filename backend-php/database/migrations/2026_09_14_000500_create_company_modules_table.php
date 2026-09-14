<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/licensing.py's CompanyModule.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('module_key', 50);
            $table->boolean('enabled')->default(false);
            $table->string('license_type', 20)->default('included'); // included|add_on|trial
            $table->text('notes')->nullable();
            $table->timestampTz('enabled_at')->nullable();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('module_key')->references('key')->on('modules');
            $table->unique(['company_id', 'module_key'], 'uq_company_module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_modules');
    }
};
