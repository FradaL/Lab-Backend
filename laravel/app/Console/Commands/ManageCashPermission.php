<?php

namespace App\Console\Commands;

use App\Cash\CashPermissionManager;
use App\Models\Laboratory;
use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class ManageCashPermission extends Command
{
    protected $signature = 'cash:permission {laboratory} {actor} {user} {permission} {--revoke : Remove the direct assignment} {--reason= : Required justification}';

    protected $description = 'Conceder o revocar un permiso directo de Caja con autorización y auditoría';

    public function handle(CashPermissionManager $manager): int
    {
        $previous = getPermissionsTeamId();
        try {
            $lab = Laboratory::findOrFail($this->argument('laboratory'));
            setPermissionsTeamId($lab->id);
            $manager->set($lab, User::findOrFail($this->argument('actor')), User::findOrFail($this->argument('user')),
                $this->argument('permission'), ! $this->option('revoke'), (string) $this->option('reason'));
            $this->info('Asignación directa actualizada. Los permisos heredados se administran en la matriz del rol.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            setPermissionsTeamId($previous);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
