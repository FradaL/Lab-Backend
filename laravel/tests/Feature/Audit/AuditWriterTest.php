<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Models\Laboratory;
use App\Models\User;
use App\Tenancy\CurrentLaboratory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Tests\TestCase;

final class AuditWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_writer_records_trusted_tenant_actor_subject_and_explicit_payloads(): void
    {
        $laboratory = Laboratory::factory()->create();
        $actor = User::factory()->create();
        $occurredAt = CarbonImmutable::parse('2026-10-06T12:34:56+00:00');

        $auditLog = $this->writer()->record(
            $laboratory,
            $actor,
            new AuditEvent(
                event: 'order.status_changed',
                subjectType: 'laboratory_order',
                subjectId: '123',
                oldValues: ['status' => 'pending'],
                newValues: ['status' => 'in_process'],
                metadata: ['source' => 'test', 'occurred_at' => $occurredAt],
            ),
        )->fresh();

        $this->assertSame($laboratory->id, $auditLog->laboratory_id);
        $this->assertSame($actor->id, $auditLog->user_id);
        $this->assertSame('order.status_changed', $auditLog->event);
        $this->assertSame('laboratory_order', $auditLog->auditable_type);
        $this->assertSame(123, $auditLog->auditable_id);
        $this->assertSame(['status' => 'pending'], $auditLog->old_values);
        $this->assertSame(['status' => 'in_process'], $auditLog->new_values);
        $this->assertSame([
            'source' => 'test',
            'occurred_at' => '2026-10-06T12:34:56+00:00',
        ], $auditLog->metadata);
    }

    public function test_writer_supports_system_actor_and_no_subject(): void
    {
        $laboratory = Laboratory::factory()->create();

        $auditLog = $this->writer()->record(
            $laboratory,
            null,
            new AuditEvent('maintenance.completed'),
        );

        $this->assertNull($auditLog->user_id);
        $this->assertNull($auditLog->auditable_type);
        $this->assertNull($auditLog->auditable_id);
    }

    public function test_writer_works_without_http_or_current_laboratory_context(): void
    {
        $laboratory = Laboratory::factory()->create();
        $currentLaboratory = $this->app->make(CurrentLaboratory::class);

        $this->assertFalse($currentLaboratory->has());
        $auditLog = $this->writer()->record($laboratory, null, new AuditEvent('command.completed'));
        $this->assertSame($laboratory->id, $auditLog->laboratory_id);
        $this->assertFalse($currentLaboratory->has());
    }

    #[DataProvider('partialSubjectProvider')]
    public function test_partial_subject_is_rejected(?string $type, int|string|null $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuditEvent('test.recorded', $type, $id);
    }

    /** @return array<string, array{?string, int|string|null}> */
    public static function partialSubjectProvider(): array
    {
        return [
            'type only' => ['laboratory_order', null],
            'id only' => [null, 1],
        ];
    }

    #[DataProvider('invalidEventProvider')]
    public function test_invalid_event_is_rejected(string $event): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuditEvent($event);
    }

    /** @return array<string, array{string}> */
    public static function invalidEventProvider(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'uppercase' => ['Order.Created'],
            'spaces' => ['order created'],
            'too long' => [str_repeat('a', 121)],
        ];
    }

    #[DataProvider('invalidSubjectProvider')]
    public function test_invalid_subject_is_rejected(string $type, int|string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuditEvent('test.recorded', $type, $id);
    }

    /** @return array<string, array{string, int|string}> */
    public static function invalidSubjectProvider(): array
    {
        return [
            'fqcn' => ['App\\Models\\LaboratoryOrder', 1],
            'uppercase' => ['LaboratoryOrder', 1],
            'zero id' => ['laboratory_order', 0],
            'negative id' => ['laboratory_order', -1],
            'non canonical numeric string' => ['laboratory_order', '01'],
        ];
    }

    public function test_unpersisted_laboratory_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->writer()->record(new Laboratory, null, new AuditEvent('test.recorded'));
    }

    public function test_unpersisted_actor_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->writer()->record(
            Laboratory::factory()->create(),
            new User,
            new AuditEvent('test.recorded'),
        );
    }

    public function test_writer_participates_in_an_external_transaction_rollback(): void
    {
        $laboratory = Laboratory::factory()->create();

        try {
            DB::transaction(function () use ($laboratory): void {
                $this->writer()->record($laboratory, null, new AuditEvent('test.rolled_back'));

                throw new RuntimeException('Force the outer transaction to roll back.');
            });
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseMissing('audit_logs', ['event' => 'test.rolled_back']);
    }

    #[DataProvider('forbiddenPayloadProvider')]
    public function test_forbidden_sensitive_keys_are_rejected(array $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuditEvent('test.recorded', oldValues: $payload);
    }

    /** @return array<string, array{array<array-key, mixed>}> */
    public static function forbiddenPayloadProvider(): array
    {
        return [
            'password' => [['password' => 'secret']],
            'normalized access token' => [['Access-Token' => 'secret']],
            'nested authorization' => [['request' => ['authorization' => 'Bearer secret']]],
            'session id' => [['session_id' => 'secret']],
        ];
    }

    public function test_unsupported_payload_values_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuditEvent('test.recorded', metadata: ['request' => new stdClass]);
    }

    private function writer(): AuditWriter
    {
        return $this->app->make(AuditWriter::class);
    }
}
