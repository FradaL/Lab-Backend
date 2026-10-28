<?php

namespace App\Console\Commands;

use Database\Seeders\DemoClinicalReasonProvisioner;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ProvisionDemoClinicalReasons extends Command
{
    protected $signature = 'demo:clinical-reasons {--confirm-database= : Exact disposable database name} {--yes : Explicitly authorize writes without a prompt}';

    protected $description = 'Provision the isolated FE-EXC-01 DEMO catalog through authorized business services';

    public function handle(DemoClinicalReasonProvisioner $provisioner): int
    {
        try {
            $database = $provisioner->database();
            if ($this->option('confirm-database') !== $database) {
                throw new RuntimeException('Supply --confirm-database='.$database.' to identify the disposable database.');
            }
            if (! $this->option('yes') && ! $this->confirm('Write synthetic SaaS identities and DEMO catalog to '.$database.'?', false)) {
                throw new RuntimeException('Explicit confirmation is required; no data written.');
            }
            $this->line(json_encode($provisioner->provision(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    public static function definitions(int $n): array
    {
        return DemoClinicalReasonProvisioner::definitions($n);
    }
}
