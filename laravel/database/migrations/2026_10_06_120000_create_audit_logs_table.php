<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id');
            $table->foreignId('user_id')->nullable();
            $table->string('event', 120);
            $table->string('auditable_type', 100)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('laboratory_id', 'audit_logs_laboratory_foreign')
                ->references('id')
                ->on('laboratories')
                ->restrictOnDelete();
            $table->foreign('user_id', 'audit_logs_user_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->index(
                ['laboratory_id', 'created_at'],
                'audit_logs_tenant_created_index',
            );
            $table->index(
                ['laboratory_id', 'auditable_type', 'auditable_id', 'created_at'],
                'audit_logs_tenant_subject_created_index',
            );
            $table->index(
                ['laboratory_id', 'user_id', 'created_at'],
                'audit_logs_tenant_actor_created_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
