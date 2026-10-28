<?php

namespace Database\Seeders;

use App\Authorization\SaasAccessAuthority;
use App\ClinicalExams\GlobalClinicalReasonImpactManager as Impact;
use App\ClinicalExams\GlobalClinicalReasonManager as Catalog;
use App\Models\SaasCapabilityAssignment;
use App\Models\User;
use App\UserAdministration\SaasAccessManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class DemoSaasUsersSeeder extends Seeder
{
    /** Explicit capabilities: no wildcard, inheritance or access administration. */
    public static function profiles(): array
    {
        $full = [Catalog::VIEW, Catalog::CREATE, Catalog::PUBLISH, Catalog::MANAGE];

        return [
            'exc-demo-owner' => [],
            'demo-saas-view' => [Catalog::VIEW],
            'demo-saas-create' => [Catalog::CREATE],
            'demo-saas-publish' => [Catalog::PUBLISH],
            'demo-saas-manage' => [Catalog::MANAGE],
            'demo-saas-full' => $full,
            'demo-saas-mixed' => $full,
            'demo-no-access' => [],
            'demo-saas-impact' => [Impact::CAPABILITY, Catalog::VIEW],
        ];
    }

    public function run(): void
    {
        app(DemoClinicalReasonProvisioner::class)->database();
        $password = config('clinical_reason_demo.owner_password');
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Set DONQER_EXC_DEMO_OWNER_PASSWORD (at least 12 characters).');
        }
        DB::transaction(function () use ($password): void {
            app(SaasAccessAuthority::class)->lock();
            DemoDataset::run(function () use ($password): void {
                $actor = DemoDataset::owned(User::class, ['email' => 'exc-demo-a@donqerlab.test']);
                foreach (self::profiles() as $profile => $capabilities) {
                    $email = $profile.'@donqerlab.test';
                    $user = $profile === 'exc-demo-owner'
                        ? DemoDataset::owned(User::class, ['email' => $email])
                        : DemoDataset::create(User::class, ['email' => $email], [
                            'name' => 'DEMO FICTICIO '.$profile, 'password' => $password, 'is_active' => true,
                        ]);
                    if (! Hash::check($password, $user->password)) {
                        throw new RuntimeException('DEMO credential mismatch; use the original secret. Passwords are never reset.');
                    }
                    if (! $user->is_active || ($profile !== 'exc-demo-owner' && $user->name !== 'DEMO FICTICIO '.$profile)) {
                        throw new RuntimeException('DEMO identity changed; automatic repair is forbidden.');
                    }
                    foreach ($capabilities as $capability) {
                        DemoDataset::remember(SaasCapabilityAssignment::class, hash('sha256', 'demo-development:'.$email.':'.$capability),
                            fn ($id) => SaasCapabilityAssignment::whereKey($id)->where('user_id', $user->id)
                                ->where('granted_by', $actor->id)->where('capability_code', $capability)->whereNull('revoked_at')->first(),
                            fn () => app(SaasAccessManager::class)->grant($actor, $user->id, [
                                'capability' => $capability, 'reason' => DemoDataset::DISCLAIMER.' Perfil DEMO de integración frontend.',
                            ]));
                    }
                    sort($capabilities);
                    if (app(SaasAccessAuthority::class)->capabilitiesFor($user) !== $capabilities) {
                        throw new RuntimeException('DEMO capabilities changed; automatic repair is forbidden.');
                    }
                }
            });
        });
    }
}
