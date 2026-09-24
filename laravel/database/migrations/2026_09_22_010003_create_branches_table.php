<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('phone', 16)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('address', 150)->nullable();
            $table->boolean('is_main')->default(false);
            $table->string('status', 20);
            $table->timestamps();

            $table->unique(['laboratory_id', 'code']);
            $table->index(['laboratory_id', 'is_main']);
            $table->index(['laboratory_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
