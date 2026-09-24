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
        Schema::create('laboratories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('legal_name', 150)->nullable();
            $table->string('nit', 12)->nullable()->unique();
            $table->string('phone', 16)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('address', 150)->nullable();
            $table->string('timezone', 100);
            $table->string('currency', 45);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('laboratories');
    }
};
