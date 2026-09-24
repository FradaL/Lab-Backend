<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['laboratory_id', 'code']);
            $table->unique(['laboratory_id', 'name']);
            $table->index(['laboratory_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_areas');
    }
};
