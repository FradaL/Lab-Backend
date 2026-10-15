<?php

namespace App\ReportDelivery;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalReports\ReportAuthority;
use App\Models\Laboratory;
use App\Models\ReportDeliveryPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReportDeliveryPolicyService
{
    public const string DEFAULT_MODE = 'full_payment_required';

    public const array MODES = ['unrestricted', self::DEFAULT_MODE, 'payment_or_credit'];

    public function __construct(private ReportAuthority $authority, private AuditWriter $audit) {}

    public function read(Laboratory $lab, User $actor): array
    {
        return DB::transaction(function () use ($lab, $actor) {
            $this->authority->check($lab, $actor, 'reports.delivery_policy.view');

            return $this->snapshot($lab);
        });
    }

    public function snapshot(Laboratory $lab, bool $write = false): array
    {
        $query = ReportDeliveryPolicy::forLaboratory($lab);
        $row = ($write ? $query->lockForUpdate() : $query->sharedLock())->first();

        return $row === null
            ? ['revision' => 0, 'mode' => self::DEFAULT_MODE, 'source' => 'conservative_default', 'updated_by' => null, 'updated_at' => null]
            : ['revision' => $row->revision, 'mode' => $row->mode, 'source' => 'configured', 'updated_by' => $row->updated_by, 'updated_at' => $row->updated_at->toISOString()];
    }

    public function update(Laboratory $lab, User $actor, array $input): array
    {
        $this->validate($input);

        return DB::transaction(function () use ($lab, $actor, $input) {
            $currentLab = Laboratory::whereKey($lab->id)->lockForUpdate()->firstOrFail();
            $this->authority->check($currentLab, $actor, 'reports.delivery_policy.manage');
            $old = $this->snapshot($currentLab, true);
            if ($old['revision'] !== $input['revision']) {
                throw new ClinicalConflict('report_delivery_policy_revision_conflict', 'Reload the current delivery policy.');
            }
            if ($old['mode'] === $input['mode']) {
                return $old;
            }
            $revision = $old['revision'] + 1;
            $changedAt = now();
            if ($old['updated_at'] !== null && $changedAt->lte(CarbonImmutable::parse($old['updated_at']))) {
                $changedAt = CarbonImmutable::parse($old['updated_at'])->addMicrosecond();
            }
            ReportDeliveryPolicy::updateOrCreate(['laboratory_id' => $lab->id], ['revision' => $revision, 'mode' => $input['mode'], 'updated_by' => $actor->id, 'updated_at' => $changedAt]);
            DB::table('report_delivery_policy_changes')->insert(['laboratory_id' => $lab->id, 'revision' => $revision, 'previous_revision' => $old['revision'],
                'previous_mode' => $old['mode'], 'new_mode' => $input['mode'], 'reason' => trim($input['reason']), 'changed_by' => $actor->id, 'changed_at' => $changedAt]);
            $this->audit->record($lab, $actor, new AuditEvent('order_report.delivery_policy_changed', 'laboratory', $lab->id,
                oldValues: ['revision' => $old['revision'], 'mode' => $old['mode']], newValues: ['revision' => $revision, 'mode' => $input['mode']], metadata: ['reason' => trim($input['reason'])]));

            return ['revision' => $revision, 'mode' => $input['mode'], 'source' => 'configured', 'updated_by' => $actor->id, 'updated_at' => $changedAt->toISOString()];
        });
    }

    private function validate(array $input): void
    {
        if (array_diff(array_keys($input), ['revision', 'mode', 'reason']) !== []) {
            throw ValidationException::withMessages(['input' => ['Unknown delivery policy fields.']]);
        }
        Validator::make($input, ['revision' => ['required', fn ($a, $v, $f) => is_int($v) && $v >= 0 ?: $f('A nonnegative JSON integer is required.')],
            'mode' => ['required', 'string', 'in:'.implode(',', self::MODES)], 'reason' => ['required', 'string', 'max:1000', fn ($a, $v, $f) => trim($v) !== '' ?: $f('A nonempty reason is required.')]])->validate();
    }
}
