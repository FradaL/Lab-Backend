<?php

namespace Tests\Feature\UserAdministration;

use App\Models\Laboratory;
use App\Models\LaboratoryUserInvitation;
use App\Models\User;
use App\UserAdministration\LaboratoryUserManager;
use App\UserAdministration\UserAdministrationConflict;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

class LaboratoryUserInvitationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_only_one_concurrent_acceptance_creates_the_identity_and_membership(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertTrue(function_exists('pcntl_fork'));

        $laboratory = Laboratory::factory()->create();
        $inviter = User::factory()->create();
        $this->seed(RolePermissionSeeder::class);
        $token = str_repeat('A', 64);
        LaboratoryUserInvitation::query()->create([
            'laboratory_id' => $laboratory->id,
            'email' => 'race@example.test',
            'name' => 'Concurrente',
            'role' => 'viewer',
            'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addHour(),
        ]);

        $results = $this->race([
            fn () => app(LaboratoryUserManager::class)->accept($token, [
                'name' => 'Concurrente',
                'password' => 'a-secure-password',
            ], null),
            fn () => app(LaboratoryUserManager::class)->accept($token, [
                'name' => 'Concurrente',
                'password' => 'a-secure-password',
            ], null),
        ]);

        sort($results);
        $this->assertSame(['laboratory_user_invitation_invalid', 'ok'], $results);
        $user = User::query()->where('email', 'race@example.test')->sole();
        $this->assertDatabaseHas('laboratory_user', [
            'laboratory_id' => $laboratory->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);
        $this->assertSame(1, DB::table('laboratory_user')
            ->where('laboratory_id', $laboratory->id)
            ->where('user_id', $user->id)
            ->count());
        $this->assertNotNull(LaboratoryUserInvitation::query()->sole()->accepted_at);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'laboratory_user.identity_created')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'laboratory_user.membership_accepted')->count());
    }

    /** @param array<int, callable(): mixed> $jobs
     * @return list<string>
     */
    private function race(array $jobs): array
    {
        $directory = sys_get_temp_dir().'/laboratory-user-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect();

        try {
            foreach ($jobs as $index => $job) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    $deadline = microtime(true) + 10;
                    while (! file_exists($directory.'/go') && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    DB::reconnect();
                    DB::statement("SET statement_timeout = '15s'");

                    try {
                        $job();
                        $result = 'ok';
                    } catch (UserAdministrationConflict $exception) {
                        $result = $exception->errorCode;
                    } catch (Throwable $exception) {
                        $result = 'error:'.$exception::class.':'.$exception->getMessage();
                    }

                    file_put_contents($directory.'/'.$index, $result);
                    DB::disconnect();
                    exit(0);
                }
                $children[] = $pid;
            }

            touch($directory.'/go');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            return array_map(fn (int $index): string => (string) file_get_contents($directory.'/'.$index), array_keys($jobs));
        } finally {
            DB::reconnect();
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
