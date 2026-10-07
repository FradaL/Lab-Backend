<?php

namespace Tests\Feature\Models;

use App\Models\AuditLog;
use App\Models\Laboratory;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

final class AuditLogPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_contains_only_the_approved_columns_and_indexes(): void
    {
        $this->assertSame([
            'id',
            'laboratory_id',
            'user_id',
            'event',
            'auditable_type',
            'auditable_id',
            'old_values',
            'new_values',
            'metadata',
            'created_at',
        ], Schema::getColumnListing('audit_logs'));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'deleted_at'));

        $indexes = collect(Schema::getIndexes('audit_logs'))->pluck('name');

        foreach ([
            'audit_logs_tenant_created_index',
            'audit_logs_tenant_subject_created_index',
            'audit_logs_tenant_actor_created_index',
        ] as $index) {
            $this->assertContains($index, $indexes);
        }
    }

    public function test_postgresql_uses_jsonb_and_bigint_for_audit_payloads_and_subjects(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL physical column audit.');
        }

        $columns = collect(DB::select(<<<'SQL'
            SELECT column_name, data_type, character_maximum_length, is_nullable
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'audit_logs'
            SQL))->keyBy('column_name');

        $this->assertSame('bigint', $columns['id']->data_type);
        $this->assertSame('bigint', $columns['laboratory_id']->data_type);
        $this->assertSame('bigint', $columns['user_id']->data_type);
        $this->assertSame('bigint', $columns['auditable_id']->data_type);
        $this->assertSame('character varying', $columns['event']->data_type);
        $this->assertSame(120, $columns['event']->character_maximum_length);
        $this->assertSame('character varying', $columns['auditable_type']->data_type);
        $this->assertSame(100, $columns['auditable_type']->character_maximum_length);
        $this->assertSame('jsonb', $columns['old_values']->data_type);
        $this->assertSame('jsonb', $columns['new_values']->data_type);
        $this->assertSame('jsonb', $columns['metadata']->data_type);
        $this->assertSame('YES', $columns['user_id']->is_nullable);
    }

    public function test_model_persists_relations_subject_payloads_casts_and_created_at(): void
    {
        $laboratory = Laboratory::factory()->create();
        $actor = User::factory()->create();
        $auditLog = AuditLog::factory()->create([
            'laboratory_id' => $laboratory->id,
            'user_id' => $actor->id,
            'event' => 'order.status_changed',
            'auditable_type' => 'laboratory_order',
            'auditable_id' => 123,
            'old_values' => ['status' => 'pending'],
            'new_values' => ['status' => 'in_process'],
            'metadata' => ['source' => 'test'],
        ])->fresh();

        $this->assertTrue($auditLog->laboratory->is($laboratory));
        $this->assertTrue($auditLog->actor->is($actor));
        $this->assertSame('order.status_changed', $auditLog->event);
        $this->assertSame('laboratory_order', $auditLog->auditable_type);
        $this->assertSame(123, $auditLog->auditable_id);
        $this->assertSame(['status' => 'pending'], $auditLog->old_values);
        $this->assertSame(['status' => 'in_process'], $auditLog->new_values);
        $this->assertSame(['source' => 'test'], $auditLog->metadata);
        $this->assertNotNull($auditLog->created_at);
        $this->assertArrayNotHasKey('updated_at', $auditLog->getAttributes());
    }

    public function test_nullable_actor_and_nullable_subject_are_persisted(): void
    {
        $auditLog = AuditLog::factory()->create([
            'user_id' => null,
            'auditable_type' => null,
            'auditable_id' => null,
        ])->fresh();

        $this->assertNull($auditLog->user_id);
        $this->assertNull($auditLog->actor);
        $this->assertNull($auditLog->auditable_type);
        $this->assertNull($auditLog->auditable_id);
    }

    public function test_explicit_scope_isolates_laboratories_without_a_global_scope(): void
    {
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();
        $logsA = AuditLog::factory()->count(2)->for($laboratoryA)->create();
        $logB = AuditLog::factory()->for($laboratoryB)->create();

        $this->assertEqualsCanonicalizing(
            $logsA->modelKeys(),
            AuditLog::forLaboratory($laboratoryA)->pluck('id')->all(),
        );
        $this->assertSame(
            [$logB->id],
            AuditLog::forLaboratory($laboratoryB)->pluck('id')->all(),
        );
        $this->assertSame(3, AuditLog::query()->count());
    }

    public function test_model_rejects_updates(): void
    {
        $auditLog = AuditLog::factory()->create();

        try {
            $auditLog->update(['event' => 'test.changed']);
            $this->fail('An audit log must not be updateable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('test.event_recorded', $auditLog->fresh()->event);
    }

    public function test_model_rejects_deletes(): void
    {
        $auditLog = AuditLog::factory()->create();

        try {
            $auditLog->delete();
            $this->fail('An audit log must not be deleteable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $auditLog->id]);
    }

    public function test_laboratory_delete_is_restricted_while_audit_history_exists(): void
    {
        $auditLog = AuditLog::factory()->create();

        $this->expectException(QueryException::class);
        $auditLog->laboratory->delete();
    }

    public function test_actor_delete_is_restricted_while_audit_history_exists(): void
    {
        $auditLog = AuditLog::factory()->create();

        $this->expectException(QueryException::class);
        $auditLog->actor->delete();
    }

    public function test_migration_supports_down_and_up_without_model_hooks_interfering(): void
    {
        AuditLog::factory()->create();
        $migration = $this->migration();

        $migration->down();
        $this->assertFalse(Schema::hasTable('audit_logs'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('audit_logs'));
        $this->assertTrue(AuditLog::factory()->create()->exists);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_06_120000_create_audit_logs_table.php');
    }
}
