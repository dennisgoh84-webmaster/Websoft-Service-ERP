<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/licensing.py's Module -- fixed catalog of
// business-area modules, keyed by a short string (not a UUID).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_built')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
