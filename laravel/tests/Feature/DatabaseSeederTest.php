<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->clearPermissionTeam();
        parent::tearDown();
    }

    public function test_default_seeding_loads_only_the_requested_basic_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['users' => 2, 'laboratories' => 1, 'branches' => 2, 'roles' => 6,
            'doctors' => 20, 'patients' => 20, 'laboratory_areas' => 8, 'sample_types' => 4] as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        foreach (['laboratory_orders', 'laboratory_exams', 'price_lists', 'commercial_clients',
            'laboratory_samples', 'laboratory_result_validations', 'laboratory_report_issuances',
            'report_deliveries', 'laboratory_order_cash_movements', 'global_clinical_reasons',
            'laboratory_clinical_reasons', 'saas_capability_assignments', 'model_has_permissions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_basic_seeding_is_repeatable_and_preserves_manual_user_changes(): void
    {
        $this->seed(DatabaseSeeder::class);
        User::where('email', 'admin@donqerlab.test')->firstOrFail()->update([
            'name' => 'Nombre sintético actualizado', 'password' => Hash::make('synthetic-changed-password'),
        ]);
        $before = $this->snapshot();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_default_seeding_refuses_production_without_writing_data(): void
    {
        $previous = app()->environment();
        app()->instance('env', 'production');
        try {
            (new DatabaseSeeder)->run();
            $this->fail('Demo data must refuse production.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('restricted', $error->getMessage());
        } finally {
            app()->instance('env', $previous);
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('demo_datasets', 0);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $entry) {
            $rows = DB::table($entry->tablename)->get()->map(fn ($row) => (array) $row)->all();
            usort($rows, fn ($left, $right) => strcmp(json_encode($left), json_encode($right)));
            $snapshot[$entry->tablename] = $rows;
        }

        return $snapshot;
    }
}
