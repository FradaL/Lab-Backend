<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_datasets', function (Blueprint $table): void {
            $table->id();
            $table->string('dataset_key')->unique();
            $table->timestampTz('created_at');
        });
        Schema::create('demo_dataset_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('demo_dataset_id')->constrained()->restrictOnDelete();
            $table->string('entity_kind', 120);
            $table->string('entity_key', 64);
            $table->unsignedBigInteger('entity_id');
            $table->timestampTz('created_at');
            $table->unique(['demo_dataset_id', 'entity_kind', 'entity_key'], 'demo_record_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_dataset_records');
        Schema::dropIfExists('demo_datasets');
    }
};
