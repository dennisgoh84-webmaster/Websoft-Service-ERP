<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/groups.py's GroupModuleAuthority.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_module_authorities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('group_id');
            $table->string('module_key', 50);
            $table->string('access_level', 20)->default('none'); // none|view|edit|full
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();
            $table->foreign('module_key')->references('key')->on('modules');
            $table->unique(['group_id', 'module_key'], 'uq_group_module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_module_authorities');
    }
};
