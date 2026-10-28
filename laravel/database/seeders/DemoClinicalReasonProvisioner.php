<?php

namespace Database\Seeders;

use App\Authorization\ClinicalReasonTenantAuthority as Tenant;
use App\Authorization\RbacCatalog;
use App\Authorization\SaasAccessAuthority;
use App\ClinicalExams\GlobalClinicalReasonManager as Catalog;
use App\Models\GlobalClinicalReason;
use App\Models\Laboratory;
use App\Models\LaboratoryUser;
use App\Models\Plan;
use App\Models\SaasCapabilityAssignment;
use App\Models\Subscription;
use App\Models\User;
use App\UserAdministration\SaasAccessManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Shared by the explicit CLI and development seeders; never used by runtime requests. */
final class DemoClinicalReasonProvisioner
{
    public function provision(): array
    {
        $this->database();
        $password = config('clinical_reason_demo.owner_password');
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Set DONQER_EXC_DEMO_OWNER_PASSWORD (at least 12 characters).');
        }
        $bootstrap = config('saas.bootstrap');
        $team = getPermissionsTeamId();
        try {
            return DB::transaction(function () use ($password): array {
                app(SaasAccessAuthority::class)->lock();

                return DemoDataset::run(function () use ($password): array {
                    // First installation must be pristine. A reused name never proves ownership.
                    $installed = DB::table('demo_dataset_records')->where('entity_kind', User::class)
                        ->where('demo_dataset_id', DB::table('demo_datasets')->where('dataset_key', DemoDataset::KEY)->value('id'))
                        ->where('entity_key', hash('sha256', json_encode(['email' => 'exc-demo-a@donqerlab.test'], JSON_THROW_ON_ERROR)))->exists();
                    if (! $installed && (User::exists() || Laboratory::exists() || GlobalClinicalReason::exists() || SaasCapabilityAssignment::exists())) {
                        throw new RuntimeException('First provisioning requires an empty, dedicated database; existing identities/catalog/SaaS access rejected.');
                    }
                    $a = $this->identity('a');
                    $b = $this->identity('b');
                    $access = app(SaasAccessManager::class);
                    config(['saas.bootstrap.enabled' => true, 'saas.bootstrap.user_id' => $a->id, 'saas.bootstrap.allow_production' => false]);
                    $initial = $access->bootstrap();
                    if ($initial->user_id !== $a->id || $initial->revoked_at !== null) {
                        throw new RuntimeException('DEMO bootstrap no longer matches; automatic repair is forbidden.');
                    }
                    foreach ([Catalog::VIEW, Catalog::CREATE, Catalog::PUBLISH, Catalog::MANAGE] as $capability) {
                        DemoDataset::remember(SaasCapabilityAssignment::class, hash('sha256', 'exc-demo:'.$capability),
                            fn ($id) => SaasCapabilityAssignment::whereKey($id)->where('user_id', $b->id)->where('granted_by', $a->id)->where('capability_code', $capability)->whereNull('revoked_at')->first(),
                            fn () => $access->grant($a, $b->id, ['capability' => $capability, 'reason' => DemoDataset::DISCLAIMER.' FE-EXC-01 DEMO A autoriza a DEMO B.']));
                    }
                    $lab = $this->laboratory($password);
                    $created = 0;
                    $versionsCreated = 0;
                    for ($n = 1; $n <= 51; $n++) {
                        $code = sprintf('demo_exc_%03d', $n);
                        $definitions = self::definitions($n);
                        DemoDataset::create(GlobalClinicalReason::class, ['code' => $code], [],
                            function () use ($b, $code, $definitions, &$created, &$versionsCreated): GlobalClinicalReason {
                                $manager = app(Catalog::class);
                                $data = $manager->create($b, ['code' => $code, 'definition' => $definitions[0]])['data'];
                                $reason = $data['reason'];
                                $previous = $data['version'];
                                foreach (array_slice($definitions, 1) as $definition) {
                                    $input = ['expected_number' => $previous->number];
                                    $data = $definition['is_active'] !== $previous->is_active
                                        ? $manager->activation($b, $reason->id, $input + ['is_active' => $definition['is_active'], 'change_reason' => $definition['change_reason']])['data']
                                        : $manager->publish($b, $reason->id, $input + ['definition' => $definition])['data'];
                                    $previous = $data['version'];
                                }
                                $created++;
                                $versionsCreated += count($definitions);

                                return $reason;
                            });
                        $reason = DemoDataset::owned(GlobalClinicalReason::class, ['code' => $code]);
                        $actual = $reason->versions()->orderBy('number')->get();
                        if ($reason->created_by !== $b->id || $actual->count() !== count($definitions)) {
                            throw new RuntimeException('DEMO history changed; no automatic overwrite: '.$code);
                        }
                        foreach ($actual as $index => $version) {
                            if ($version->created_by !== $b->id || $version->number !== $index + 1 || $version->only(array_keys($definitions[$index])) != $definitions[$index]) {
                                throw new RuntimeException('DEMO definition changed; no automatic overwrite: '.$code);
                            }
                        }
                    }

                    return ['database' => DB::connection()->getDatabaseName(), 'reasons_created' => $created, 'reasons_found' => 51 - $created,
                        'versions_created' => $versionsCreated, 'versions_found' => 56 - $versionsCreated,
                        'reasons_total' => 51, 'versions_total' => 56, 'active' => 45, 'inactive' => 6,
                        'saas_demo_a_id' => $a->id, 'saas_demo_b_id' => $b->id, 'laboratory_id' => $lab->id,
                        'owner_email' => 'exc-demo-owner@donqerlab.test',
                        'audit_events' => DB::table('saas_audit_logs')->whereIn('actor_id', [$a->id, $b->id])->orWhere('affected_user_id', $a->id)->count()];
                });
            });
        } finally {
            config(['saas.bootstrap' => $bootstrap]);
            setPermissionsTeamId($team);
        }
    }

    public function database(): string
    {
        if (! app()->environment('local', 'testing') || config('app.env') !== app()->environment() || DB::getDefaultConnection() !== 'pgsql') {
            throw new RuntimeException('DEMO provisioning requires local/testing PostgreSQL; production is forbidden.');
        }
        $expected = app()->environment('testing')
            ? ['host' => 'postgres-testing', 'database' => 'donqer_lab_testing', 'username' => 'donqer_lab_testing']
            : ['host' => 'postgres-exc-demo', 'database' => 'donqer_exc_demo', 'username' => 'donqer_exc_demo'];
        $config = config('database.connections.pgsql');
        foreach ([$config, DB::connection()->getConfig()] as $effective) {
            foreach ($expected + ['driver' => 'pgsql', 'port' => '5432'] as $key => $value) {
                if ((string) ($effective[$key] ?? '') !== $value) {
                    throw new RuntimeException('Disposable database configuration rejected: '.$key);
                }
            }
            if (! empty($effective['url']) || isset($effective['read']) || isset($effective['write'])) {
                throw new RuntimeException('Database URL/replica overrides are forbidden.');
            }
        }
        $identity = DB::selectOne('select current_database() as database, current_user as username');
        if ($identity->database !== $expected['database'] || $identity->username !== $expected['username']) {
            throw new RuntimeException('Disposable database server identity rejected.');
        }

        return $identity->database;
    }

    private function identity(string $suffix, ?string $password = null): User
    {
        $user = DemoDataset::create(User::class, ['email' => 'exc-demo-'.$suffix.'@donqerlab.test'], [
            'name' => 'DEMO FICTICIO EXC '.strtoupper($suffix), 'password' => $password ?? Str::random(64), 'is_active' => true,
        ]);
        if (! $user->is_active || $user->name !== 'DEMO FICTICIO EXC '.strtoupper($suffix)) {
            throw new RuntimeException('Synthetic identity changed; automatic repair is forbidden.');
        }

        return $user;
    }

    private function laboratory(string $password): Laboratory
    {
        $owner = $this->identity('owner', $password);
        $lab = DemoDataset::create(Laboratory::class, ['nit' => 'DEMO-EXC-01'], ['name' => 'DEMO FICTICIO EXC', 'legal_name' => 'DEMO FICTICIO EXC', 'timezone' => 'America/Guatemala', 'currency' => 'GTQ', 'is_active' => true]);
        $plan = DemoDataset::create(Plan::class, ['code' => 'EXCD'], ['name' => 'DEMO FICTICIO EXC', 'price' => 0, 'billing_period' => 'monthly', 'status' => 'active']);
        DemoDataset::create(Subscription::class, ['laboratory_id' => $lab->id, 'plan_id' => $plan->id], ['status' => 'active', 'starts_at' => '2026-01-01 00:00:00']);
        DemoDataset::create(LaboratoryUser::class, ['laboratory_id' => $lab->id, 'user_id' => $owner->id], ['is_active' => true, 'is_default' => true]);
        setPermissionsTeamId($lab->id);
        DemoDataset::create(Role::class, ['name' => 'owner', 'guard_name' => RbacCatalog::GUARD, 'laboratory_id' => $lab->id], [], function () use ($owner): Role {
            $role = Role::create(['name' => 'owner', 'guard_name' => RbacCatalog::GUARD, 'laboratory_id' => getPermissionsTeamId()]);
            foreach ([Tenant::GLOBAL_VIEW, Tenant::VIEW, Tenant::ADOPT, Tenant::UPDATE, Tenant::HISTORY] as $permission) {
                $role->givePermissionTo(Permission::findOrCreate($permission, RbacCatalog::GUARD));
            }
            $owner->assignRole($role);

            return $role;
        });

        return $lab;
    }

    /** Fixed immutable history: 51 identities, 56 versions, including a deactivate/reactivate cycle. */
    public static function definitions(int $n): array
    {
        $definition = ['name' => sprintf('DEMO FICTICIO EXC %03d', $n), 'description' => DemoDataset::DISCLAIMER,
            'classification' => $n % 2 === 0 ? 'incidence_only' : 'terminal_candidate',
            'evidence_requirements' => ['schema_version' => 1, 'requirements' => match ($n % 3) {
                0 => [], 1 => ['problem_description'], 2 => ['problem_description', 'supporting_document'],
            }], 'is_active' => $n % 10 !== 0, 'change_reason' => 'DEMO: creación sintética FE-EXC-01.'];
        $history = [$definition];
        if (in_array($n, [1, 3], true)) {
            $definition['description'] .= ' DEMO: publicación posterior.';
            $definition['change_reason'] = 'DEMO: publicación v2.';
            $history[] = $definition;
        }
        if (in_array($n, [1, 2], true)) {
            $definition['is_active'] = false;
            $definition['change_reason'] = 'DEMO: desactivación explícita.';
            $history[] = $definition;
        }
        if ($n === 1) {
            $definition['is_active'] = true;
            $definition['change_reason'] = 'DEMO: reactivación explícita.';
            $history[] = $definition;
        }

        return $history;
    }
}
