<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id')->constrained()->restrictOnDelete();
            $table->string('first_names', 125);
            $table->string('last_names', 125);
            $table->string('specialty', 125)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('license_number', 30)->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['laboratory_id', 'status']);
            $table->index(['laboratory_id', 'last_names']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
