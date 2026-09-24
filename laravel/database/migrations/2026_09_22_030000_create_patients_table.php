<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id')->constrained()->restrictOnDelete();
            $table->string('first_names', 75);
            $table->string('last_names', 75);
            $table->date('birth_date')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->string('affiliation_number', 50)->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->decimal('height', 5, 2)->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['laboratory_id', 'status']);
            $table->index(['laboratory_id', 'last_names']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
