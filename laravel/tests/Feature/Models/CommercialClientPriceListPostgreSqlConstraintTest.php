<?php

namespace Tests\Feature\Models;

use App\Models\CommercialClient;
use App\Models\CommercialClientPriceList;
use App\Models\Laboratory;
use App\Models\PriceList;
use App\Models\Subscription;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

class CommercialClientPriceListPostgreSqlConstraintTest extends TestCase
{
    use DatabaseMigrations;

    public function test_postgresql_exclusion_constraint_enforces_active_inclusive_period_contract(): void
    {
        $this->requirePostgreSql();
        $laboratory = Laboratory::factory()->create();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $otherClient = CommercialClient::factory()->for($laboratory)->create();
        $sameDayClient = CommercialClient::factory()->for($laboratory)->create();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $otherPriceList = PriceList::factory()->for($laboratory)->create();
        $base = $this->attributes($laboratory, $client, $priceList, '2026-01-01', '2026-12-31');
        DB::table('commercial_client_price_lists')->insert($base);

        $this->assertExclusionViolation(fn () => DB::table('commercial_client_price_lists')->insert(
            $this->attributes($laboratory, $client, $otherPriceList, '2026-12-31', '2027-01-10'),
        ));
        DB::table('commercial_client_price_lists')->insert(
            $this->attributes($laboratory, $client, $otherPriceList, '2027-01-01', null),
        );
        DB::table('commercial_client_price_lists')->insert(
            $this->attributes($laboratory, $otherClient, $priceList, '2026-01-01', null),
        );
        DB::table('commercial_client_price_lists')->insert(
            $this->attributes($laboratory, $sameDayClient, $priceList, '2026-06-15', '2026-06-15'),
        );
        $this->assertExclusionViolation(fn () => DB::table('commercial_client_price_lists')->insert(
            $this->attributes($laboratory, $sameDayClient, $otherPriceList, '2026-06-15', '2026-06-15'),
        ));
        DB::table('commercial_client_price_lists')->insert(
            $this->attributes($laboratory, $client, $otherPriceList, '2026-06-15', '2026-06-15', CommercialClientPriceList::STATUS_INACTIVE),
        );

        $otherLaboratory = Laboratory::factory()->create();
        $foreignClient = CommercialClient::factory()->for($otherLaboratory)->create();
        $foreignPriceList = PriceList::factory()->for($otherLaboratory)->create();
        DB::table('commercial_client_price_lists')->insert(
            $this->attributes($otherLaboratory, $foreignClient, $foreignPriceList, '2026-01-01', null),
        );

        $constraint = DB::selectOne(<<<'SQL'
            SELECT pg_get_constraintdef(oid) AS definition
            FROM pg_constraint
            WHERE conrelid = 'commercial_client_price_lists'::regclass
              AND conname = 'ccpl_no_active_period_overlap'
            SQL);
        $this->assertNotNull($constraint);
        $this->assertStringContainsString('EXCLUDE USING gist', $constraint->definition);
        $this->assertStringContainsString('daterange(starts_at, ends_at,', $constraint->definition);
        $this->assertStringContainsString("WHERE (((status)::text = 'active'::text))", $constraint->definition);
        $this->assertDatabaseCount('commercial_client_price_lists', 6);
    }

    public function test_two_concurrent_overlapping_inserts_allow_at_most_one(): void
    {
        $this->requirePostgreSql();
        $laboratory = Laboratory::factory()->create();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceLists = PriceList::factory()->count(2)->for($laboratory)->create();
        $connection = config('database.connections.pgsql');
        $results = [];
        $children = [];

        DB::disconnect();
        foreach ([0, 1] as $index) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $this->assertNotFalse($pair);
            $processId = pcntl_fork();
            $this->assertNotSame(-1, $processId);

            if ($processId === 0) {
                fclose($pair[0]);
                $pdo = new PDO(
                    "pgsql:host={$connection['host']};port={$connection['port']};dbname={$connection['database']}",
                    $connection['username'],
                    $connection['password'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                );
                $pdo->beginTransaction();
                $statement = $pdo->prepare('SELECT COUNT(*) FROM commercial_client_price_lists WHERE laboratory_id = ? AND commercial_client_id = ? AND status = ?');
                $statement->execute([$laboratory->id, $client->id, CommercialClientPriceList::STATUS_ACTIVE]);
                fwrite($pair[1], "ready\n");
                fgets($pair[1]);

                try {
                    $statement = $pdo->prepare(<<<'SQL'
                        INSERT INTO commercial_client_price_lists
                            (laboratory_id, commercial_client_id, price_list_id, starts_at, ends_at, status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        SQL);
                    $statement->execute([
                        $laboratory->id,
                        $client->id,
                        $priceLists[$index]->id,
                        $index === 0 ? '2026-01-01' : '2026-06-01',
                        $index === 0 ? '2026-12-31' : '2026-06-30',
                        CommercialClientPriceList::STATUS_ACTIVE,
                    ]);
                    if ($index === 0) {
                        fwrite($pair[1], "pending\n");
                        fgets($pair[1]);
                    }
                    $pdo->commit();
                    fwrite($pair[1], "inserted\n");
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    fwrite($pair[1], $exception->getCode()."\n");
                }

                fclose($pair[1]);
                exit(0);
            }

            fclose($pair[1]);
            $children[] = ['pid' => $processId, 'socket' => $pair[0]];
        }

        foreach ($children as $child) {
            $this->assertSame("ready\n", fgets($child['socket']));
        }
        fwrite($children[0]['socket'], "go\n");
        $this->assertSame("pending\n", fgets($children[0]['socket']));
        fwrite($children[1]['socket'], "go\n");
        usleep(100000);
        fwrite($children[0]['socket'], "commit\n");
        foreach ($children as $child) {
            $results[] = trim((string) fgets($child['socket']));
            fclose($child['socket']);
            pcntl_waitpid($child['pid'], $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        DB::reconnect();

        sort($results);
        $this->assertSame(['23P01', 'inserted'], $results);
        $this->assertDatabaseCount('commercial_client_price_lists', 1);
    }

    public function test_postgresql_exclusion_constraint_rejects_an_overlapping_update_with_exact_name(): void
    {
        $this->requirePostgreSql();
        $laboratory = Laboratory::factory()->create();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceLists = PriceList::factory()->count(2)->for($laboratory)->create();
        $firstId = DB::table('commercial_client_price_lists')->insertGetId(
            $this->attributes($laboratory, $client, $priceLists[0], '2026-01-01', '2026-06-30'),
        );
        DB::table('commercial_client_price_lists')->insert(
            $this->attributes($laboratory, $client, $priceLists[1], '2026-07-01', '2026-12-31'),
        );

        $this->assertExclusionViolation(fn () => DB::table('commercial_client_price_lists')
            ->where('id', $firstId)
            ->update(['ends_at' => '2026-07-01']));

        $this->assertDatabaseHas('commercial_client_price_lists', [
            'id' => $firstId,
            'ends_at' => '2026-06-30',
        ]);
        $this->assertDatabaseCount('commercial_client_price_lists', 2);
    }

    public function test_two_concurrent_updates_that_pass_prechecks_allow_at_most_one_conflicting_final_state(): void
    {
        $this->requirePostgreSql();
        $laboratory = Laboratory::factory()->create();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceLists = PriceList::factory()->count(2)->for($laboratory)->create();
        $assignmentIds = [
            DB::table('commercial_client_price_lists')->insertGetId(
                $this->attributes($laboratory, $client, $priceLists[0], '2026-01-01', '2026-01-31'),
            ),
            DB::table('commercial_client_price_lists')->insertGetId(
                $this->attributes($laboratory, $client, $priceLists[1], '2026-12-01', '2026-12-31'),
            ),
        ];
        $periods = [
            ['2026-06-01', '2026-06-30'],
            ['2026-06-15', '2026-07-15'],
        ];
        $connection = config('database.connections.pgsql');
        $results = [];
        $children = [];

        DB::disconnect();
        foreach ([0, 1] as $index) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $this->assertNotFalse($pair);
            $processId = pcntl_fork();
            $this->assertNotSame(-1, $processId);

            if ($processId === 0) {
                fclose($pair[0]);
                $pdo = new PDO(
                    "pgsql:host={$connection['host']};port={$connection['port']};dbname={$connection['database']}",
                    $connection['username'],
                    $connection['password'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                );
                $pdo->beginTransaction();
                $precheck = $pdo->prepare(<<<'SQL'
                    SELECT COUNT(*)
                    FROM commercial_client_price_lists
                    WHERE laboratory_id = ?
                      AND commercial_client_id = ?
                      AND status = ?
                      AND id <> ?
                      AND (ends_at IS NULL OR ends_at >= ?)
                      AND starts_at <= ?
                    SQL);
                $precheck->execute([
                    $laboratory->id,
                    $client->id,
                    CommercialClientPriceList::STATUS_ACTIVE,
                    $assignmentIds[$index],
                    $periods[$index][0],
                    $periods[$index][1],
                ]);
                fwrite($pair[1], $precheck->fetchColumn()."\n");
                fgets($pair[1]);

                try {
                    $update = $pdo->prepare(<<<'SQL'
                        UPDATE commercial_client_price_lists
                        SET starts_at = ?, ends_at = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                        SQL);
                    $update->execute([$periods[$index][0], $periods[$index][1], $assignmentIds[$index]]);
                    if ($index === 0) {
                        fwrite($pair[1], "pending\n");
                        fgets($pair[1]);
                    }
                    $pdo->commit();
                    fwrite($pair[1], "updated\n");
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    fwrite($pair[1], $exception->getCode()."\n");
                }

                fclose($pair[1]);
                exit(0);
            }

            fclose($pair[1]);
            $children[] = ['pid' => $processId, 'socket' => $pair[0]];
        }

        foreach ($children as $child) {
            $this->assertSame("0\n", fgets($child['socket']));
        }
        fwrite($children[0]['socket'], "go\n");
        $this->assertSame("pending\n", fgets($children[0]['socket']));
        fwrite($children[1]['socket'], "go\n");
        usleep(100000);
        fwrite($children[0]['socket'], "commit\n");
        foreach ($children as $child) {
            $results[] = trim((string) fgets($child['socket']));
            fclose($child['socket']);
            pcntl_waitpid($child['pid'], $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        DB::reconnect();

        sort($results);
        $this->assertSame(['23P01', 'updated'], $results);
        $this->assertDatabaseCount('commercial_client_price_lists', 2);
    }

    public function test_postgresql_unique_constraint_rejects_update_collision_with_exact_name(): void
    {
        $this->requirePostgreSql();
        $laboratory = Laboratory::factory()->create();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceLists = PriceList::factory()->count(2)->for($laboratory)->create();
        $firstId = DB::table('commercial_client_price_lists')->insertGetId(
            $this->attributes($laboratory, $client, $priceLists[0], '2026-01-01', null, CommercialClientPriceList::STATUS_INACTIVE),
        );
        DB::table('commercial_client_price_lists')->insert(
            $this->attributes($laboratory, $client, $priceLists[1], '2026-01-01', null, CommercialClientPriceList::STATUS_INACTIVE),
        );

        try {
            DB::table('commercial_client_price_lists')->where('id', $firstId)->update([
                'price_list_id' => $priceLists[1]->id,
            ]);
            $this->fail('The duplicate update did not violate the unique constraint.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
            $this->assertStringContainsString(
                'ccpl_laboratory_client_list_starts_unique',
                (string) ($exception->errorInfo[2] ?? ''),
            );
        }

        $this->assertDatabaseHas('commercial_client_price_lists', [
            'id' => $firstId,
            'price_list_id' => $priceLists[0]->id,
        ]);
        $this->assertDatabaseCount('commercial_client_price_lists', 2);
    }

    public function test_database_race_violation_is_translated_to_http_422(): void
    {
        $this->requirePostgreSql();
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->assignDirectLaboratoryPermission(
            $user,
            $laboratory,
            'commercial_price_assignments.manage',
        );
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
        $client = CommercialClient::factory()->for($laboratory)->create();
        $requestedPriceList = PriceList::factory()->for($laboratory)->create();
        $racingPriceList = PriceList::factory()->for($laboratory)->create();
        $injected = false;
        CommercialClientPriceList::creating(function () use (&$injected, $laboratory, $client, $racingPriceList): void {
            if ($injected) {
                return;
            }
            $injected = true;
            DB::table('commercial_client_price_lists')->insert(
                $this->attributes($laboratory, $client, $racingPriceList, '2026-01-01', null),
            );
        });

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson("/api/v1/commercial-clients/{$client->id}/price-list-assignments", [
                'price_list_id' => $requestedPriceList->id,
                'starts_at' => '2026-06-01',
                'ends_at' => '2026-06-30',
            ])->assertUnprocessable()->assertJsonValidationErrors(['period']);

        $this->assertDatabaseCount('commercial_client_price_lists', 0);
        $this->assertDatabaseMissing('commercial_client_price_lists', ['price_list_id' => $racingPriceList->id]);
    }

    public function test_update_database_race_and_unique_collision_are_translated_to_http_422(): void
    {
        $this->requirePostgreSql();
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->assignDirectLaboratoryPermission(
            $user,
            $laboratory,
            'commercial_price_assignments.manage',
        );
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceLists = PriceList::factory()->count(3)->for($laboratory)->create();
        $assignment = CommercialClientPriceList::factory()->for($laboratory)->create([
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceLists[0]->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-31',
        ]);
        $injected = false;
        CommercialClientPriceList::updating(function () use (&$injected, $laboratory, $client, $priceLists): void {
            if ($injected) {
                return;
            }
            $injected = true;
            DB::table('commercial_client_price_lists')->insert(
                $this->attributes($laboratory, $client, $priceLists[1], '2026-06-15', '2026-07-15'),
            );
        });

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/commercial-clients/{$client->id}/price-list-assignments/{$assignment->id}", [
                'starts_at' => '2026-06-01',
                'ends_at' => '2026-06-30',
            ])->assertUnprocessable()->assertJsonValidationErrors(['period']);

        $this->assertSame('2026-01-01', $assignment->fresh()->starts_at->toDateString());
        $duplicate = CommercialClientPriceList::factory()->inactive()->for($laboratory)->create([
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceLists[2]->id,
            'starts_at' => '2026-01-01',
            'ends_at' => null,
        ]);
        $uniqueSource = CommercialClientPriceList::factory()->inactive()->for($laboratory)->create([
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceLists[1]->id,
            'starts_at' => '2026-01-01',
            'ends_at' => null,
        ]);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/commercial-clients/{$client->id}/price-list-assignments/{$duplicate->id}", [
                'price_list_id' => $priceLists[1]->id,
            ])->assertUnprocessable()->assertJsonValidationErrors(['period']);

        $this->assertSame($priceLists[2]->id, $duplicate->fresh()->price_list_id);
        $this->assertSame($priceLists[1]->id, $uniqueSource->fresh()->price_list_id);
    }

    public function test_postgresql_exclusion_constraint_rejects_direct_activation_with_exact_name(): void
    {
        $this->requirePostgreSql();
        $laboratory = Laboratory::factory()->create();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceLists = PriceList::factory()->count(2)->for($laboratory)->create();
        DB::table('commercial_client_price_lists')->insert(
            $this->attributes($laboratory, $client, $priceLists[0], '2026-01-01', '2026-12-31'),
        );
        $inactiveId = DB::table('commercial_client_price_lists')->insertGetId(
            $this->attributes(
                $laboratory,
                $client,
                $priceLists[1],
                '2026-06-01',
                '2026-06-30',
                CommercialClientPriceList::STATUS_INACTIVE,
            ),
        );

        $this->assertExclusionViolation(fn () => DB::table('commercial_client_price_lists')
            ->where('id', $inactiveId)
            ->update(['status' => CommercialClientPriceList::STATUS_ACTIVE]));

        $this->assertDatabaseHas('commercial_client_price_lists', [
            'id' => $inactiveId,
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
        ]);
        $this->assertDatabaseCount('commercial_client_price_lists', 2);
    }

    public function test_two_concurrent_activations_that_pass_prechecks_allow_at_most_one_active_assignment(): void
    {
        $this->requirePostgreSql();
        $laboratory = Laboratory::factory()->create();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceLists = PriceList::factory()->count(2)->for($laboratory)->create();
        $assignmentIds = [
            DB::table('commercial_client_price_lists')->insertGetId(
                $this->attributes(
                    $laboratory,
                    $client,
                    $priceLists[0],
                    '2026-01-01',
                    '2026-12-31',
                    CommercialClientPriceList::STATUS_INACTIVE,
                ),
            ),
            DB::table('commercial_client_price_lists')->insertGetId(
                $this->attributes(
                    $laboratory,
                    $client,
                    $priceLists[1],
                    '2026-06-01',
                    '2026-06-30',
                    CommercialClientPriceList::STATUS_INACTIVE,
                ),
            ),
        ];
        $connection = config('database.connections.pgsql');
        $results = [];
        $children = [];

        DB::disconnect();
        foreach ([0, 1] as $index) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $this->assertNotFalse($pair);
            $processId = pcntl_fork();
            $this->assertNotSame(-1, $processId);

            if ($processId === 0) {
                fclose($pair[0]);
                $pdo = new PDO(
                    "pgsql:host={$connection['host']};port={$connection['port']};dbname={$connection['database']}",
                    $connection['username'],
                    $connection['password'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                );
                $pdo->beginTransaction();
                $precheck = $pdo->prepare(<<<'SQL'
                    SELECT COUNT(*)
                    FROM commercial_client_price_lists
                    WHERE laboratory_id = ?
                      AND commercial_client_id = ?
                      AND status = ?
                      AND id <> ?
                      AND (ends_at IS NULL OR ends_at >= ?)
                      AND starts_at <= ?
                    SQL);
                $precheck->execute([
                    $laboratory->id,
                    $client->id,
                    CommercialClientPriceList::STATUS_ACTIVE,
                    $assignmentIds[$index],
                    $index === 0 ? '2026-01-01' : '2026-06-01',
                    $index === 0 ? '2026-12-31' : '2026-06-30',
                ]);
                fwrite($pair[1], $precheck->fetchColumn()."\n");
                fgets($pair[1]);

                try {
                    $update = $pdo->prepare(<<<'SQL'
                        UPDATE commercial_client_price_lists
                        SET status = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                        SQL);
                    $update->execute([CommercialClientPriceList::STATUS_ACTIVE, $assignmentIds[$index]]);
                    if ($index === 0) {
                        fwrite($pair[1], "pending\n");
                        fgets($pair[1]);
                    }
                    $pdo->commit();
                    fwrite($pair[1], "updated\n");
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    fwrite($pair[1], $exception->getCode()."\n");
                }

                fclose($pair[1]);
                exit(0);
            }

            fclose($pair[1]);
            $children[] = ['pid' => $processId, 'socket' => $pair[0]];
        }

        foreach ($children as $child) {
            $this->assertSame("0\n", fgets($child['socket']));
        }
        fwrite($children[0]['socket'], "go\n");
        $this->assertSame("pending\n", fgets($children[0]['socket']));
        fwrite($children[1]['socket'], "go\n");
        usleep(100000);
        fwrite($children[0]['socket'], "commit\n");
        foreach ($children as $child) {
            $results[] = trim((string) fgets($child['socket']));
            fclose($child['socket']);
            pcntl_waitpid($child['pid'], $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        DB::reconnect();

        sort($results);
        $this->assertSame(['23P01', 'updated'], $results);
        $this->assertSame(
            1,
            DB::table('commercial_client_price_lists')
                ->where('status', CommercialClientPriceList::STATUS_ACTIVE)
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('commercial_client_price_lists')
                ->where('status', CommercialClientPriceList::STATUS_INACTIVE)
                ->count(),
        );
    }

    public function test_status_activation_database_race_is_translated_to_http_422(): void
    {
        $this->requirePostgreSql();
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->assignDirectLaboratoryPermission(
            $user,
            $laboratory,
            'commercial_price_assignments.manage',
        );
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceLists = PriceList::factory()->count(2)->for($laboratory)->create();
        $assignment = CommercialClientPriceList::factory()->inactive()->for($laboratory)->create([
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceLists[0]->id,
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-30',
        ]);
        $injected = false;
        CommercialClientPriceList::updating(function () use (&$injected, $laboratory, $client, $priceLists): void {
            if ($injected) {
                return;
            }
            $injected = true;
            DB::table('commercial_client_price_lists')->insert(
                $this->attributes($laboratory, $client, $priceLists[1], '2026-06-15', '2026-07-15'),
            );
        });

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/commercial-clients/{$client->id}/price-list-assignments/{$assignment->id}/status", [
                'status' => CommercialClientPriceList::STATUS_ACTIVE,
            ])->assertUnprocessable()->assertJsonValidationErrors(['period']);

        $this->assertSame(CommercialClientPriceList::STATUS_INACTIVE, $assignment->fresh()->status);
        $this->assertDatabaseMissing('commercial_client_price_lists', [
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceLists[1]->id,
            'status' => CommercialClientPriceList::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseCount('commercial_client_price_lists', 1);
    }

    private function requirePostgreSql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This physical constraint test requires PostgreSQL.');
        }
    }

    private function assertExclusionViolation(Closure $callback): void
    {
        try {
            $callback();
            $this->fail('The overlapping insert did not violate the exclusion constraint.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->getCode());
            $this->assertStringContainsString('ccpl_no_active_period_overlap', (string) ($exception->errorInfo[2] ?? ''));
        }
    }

    /** @return array<string, mixed> */
    private function attributes(
        Laboratory $laboratory,
        CommercialClient $client,
        PriceList $priceList,
        string $startsAt,
        ?string $endsAt,
        string $status = CommercialClientPriceList::STATUS_ACTIVE,
    ): array {
        return [
            'laboratory_id' => $laboratory->id,
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];

    }
}
